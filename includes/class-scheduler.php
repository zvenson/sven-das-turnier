<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SDT_Scheduler {

	/**
	 * Round-robin per Circle-Method.
	 * Bei ungerader Spielerzahl wird ein Bye eingefügt (Spieler hat in der Runde frei).
	 * Liefert array of rounds, jede Runde = array of [p1_id, p2_id].
	 */
	public static function round_robin( array $player_ids ) {
		$ids = array_values( $player_ids );
		$n   = count( $ids );
		if ( $n < 2 ) {
			return array();
		}

		$has_bye = false;
		if ( $n % 2 === 1 ) {
			$ids[]   = null;
			$has_bye = true;
			$n++;
		}

		$rounds = array();
		$half   = $n / 2;
		$list   = $ids;

		for ( $r = 0; $r < $n - 1; $r++ ) {
			$round = array();
			for ( $i = 0; $i < $half; $i++ ) {
				$a = $list[ $i ];
				$b = $list[ $n - 1 - $i ];
				if ( $a !== null && $b !== null ) {
					$round[] = array( $a, $b );
				}
			}
			$rounds[] = $round;

			// rotate: fix first, rotate rest by one position clockwise
			$fixed   = $list[0];
			$rest    = array_slice( $list, 1 );
			$last    = array_pop( $rest );
			array_unshift( $rest, $last );
			$list    = array_merge( array( $fixed ), $rest );
		}

		return $rounds;
	}

	/**
	 * Standard-Bracket-Seeding-Reihenfolge für Power-of-2-Größe.
	 * Liefert array von Slot-Indizes in Seeding-Reihenfolge (Seed 1 zuerst).
	 * size=4 → [0,3,1,2], size=8 → [0,7,3,4,1,6,2,5]
	 */
	private static function seeding_order( $size ) {
		$order = array( 1 );
		while ( count( $order ) < $size ) {
			$new   = array();
			$total = count( $order ) * 2 + 1;
			foreach ( $order as $s ) {
				$new[] = $s;
				$new[] = $total - $s;
			}
			$order = $new;
		}
		return array_map( function ( $s ) { return $s - 1; }, $order );
	}

	/**
	 * Erzeugt alle Spiele für ein Turnier und schreibt sie in die DB.
	 */
	public static function generate_matches( $tournament_id ) {
		$players = SDT_DB::get_players( $tournament_id );

		// gruppieren
		$by_group = array();
		foreach ( $players as $p ) {
			if ( $p->group_label ) {
				$by_group[ $p->group_label ][] = $p;
			}
		}

		SDT_DB::delete_matches( $tournament_id );

		foreach ( $by_group as $label => $grp_players ) {
			// nach position sortieren
			usort( $grp_players, function ( $a, $b ) {
				return ( (int) $a->position ) <=> ( (int) $b->position );
			} );
			$ids    = array_map( function ( $p ) { return (int) $p->id; }, $grp_players );
			$rounds = self::round_robin( $ids );

			foreach ( $rounds as $r_idx => $round ) {
				foreach ( $round as $pos => $pair ) {
					SDT_DB::insert_match( array(
						'tournament_id' => (int) $tournament_id,
						'group_label'   => $label,
						'round'         => $r_idx + 1,
						'position'      => $pos,
						'player1_id'    => (int) $pair[0],
						'player2_id'    => (int) $pair[1],
						'status'        => 'pending',
						'phase'         => 'group',
					) );
				}
			}
		}
	}

	/**
	 * True, wenn alle Spiele der Gruppenphase fertig sind.
	 */
	public static function is_group_phase_done( $tournament_id ) {
		$matches = SDT_DB::get_matches( $tournament_id );
		$has     = false;
		foreach ( $matches as $m ) {
			if ( $m->phase !== 'group' ) continue;
			$has = true;
			if ( $m->status !== 'done' ) return false;
		}
		return $has;
	}

	public static function has_bracket_matches( $tournament_id ) {
		foreach ( SDT_DB::get_matches( $tournament_id ) as $m ) {
			if ( $m->phase !== 'group' ) return true;
		}
		return false;
	}

	/**
	 * Erzeugt Gold- und Silber-Bracket aus 1./2. Plätzen der Gruppenphase.
	 */
	public static function generate_brackets( $tournament_id ) {
		$standings = self::standings( $tournament_id );

		// Gold: alle 1.- und 2.-Plätze. Silber: alle ab Platz 3.
		// Seeding-Reihenfolge: erst alle 1., dann 2., dann 3., dann 4. — gruppen-alphabetisch
		$tiers = array();
		foreach ( $standings as $label => $rows ) {
			foreach ( $rows as $idx => $r ) {
				$tiers[ $idx ][] = $r;
			}
		}

		$gold_seeds   = array();
		$silber_seeds = array();
		foreach ( $tiers as $idx => $rows ) {
			if ( $idx < 2 ) {
				foreach ( $rows as $r ) $gold_seeds[] = $r;
			} else {
				foreach ( $rows as $r ) $silber_seeds[] = $r;
			}
		}

		SDT_DB::delete_bracket_matches( $tournament_id );

		if ( count( $gold_seeds ) >= 2 ) {
			self::build_bracket( $tournament_id, 'gold', $gold_seeds );
		}
		if ( count( $silber_seeds ) >= 2 ) {
			self::build_bracket( $tournament_id, 'silber', $silber_seeds );
		}
	}

	/**
	 * Baut ein Doppel-KO-Bracket (Winner + Loser + Grand Final, ohne Bracket-Reset).
	 * Grand Final = endgültig (Sieger=1., Verlierer=2.).
	 * Verlierer im L-Final = 3. Platz.
	 */
	private static function build_bracket( $tournament_id, $phase, $seeds ) {
		$n = count( $seeds );
		if ( $n < 2 ) {
			return;
		}
		$size = 1;
		while ( $size < $n ) {
			$size *= 2;
		}
		$k = (int) log( $size, 2 ); // W-Runden-Anzahl

		// Seeds in Standard-Reihenfolge auf Slots verteilen
		$slots         = array_fill( 0, $size, 0 );
		$seeding_order = self::seeding_order( $size );
		for ( $i = 0; $i < $n; $i++ ) {
			$slots[ $seeding_order[ $i ] ] = (int) $seeds[ $i ]['id'];
		}

		// --- Winner-Bracket erstellen ---
		$w = array(); // [round][position] = match_id
		for ( $r = 1; $r <= $k; $r++ ) {
			$num = $size / pow( 2, $r );
			for ( $p = 0; $p < $num; $p++ ) {
				$p1 = $p2 = 0;
				if ( $r === 1 ) {
					$p1 = $slots[ $p * 2 ];
					$p2 = $slots[ $p * 2 + 1 ];
				}
				$w[ $r ][ $p ] = SDT_DB::insert_match( array(
					'tournament_id'    => (int) $tournament_id,
					'group_label'      => '',
					'round'            => 0,
					'position'         => $p,
					'player1_id'       => $p1,
					'player2_id'       => $p2,
					'status'           => 'pending',
					'phase'            => $phase,
					'bracket_round'    => $r,
					'bracket_position' => $p,
					'bracket_side'     => 'winner',
				) );
			}
		}

		// --- Trostrunde erstellen (nur wenn k >= 2) ---
		// Letztes Match = Spiel um Platz 3. Kein Loser-Final mit W-Final-Verlierer.
		$l = array();
		if ( $k >= 2 ) {
			$l_rounds = 2 * ( $k - 1 ) - 1; // letzte Runde = "Spiel um Platz 3"
			for ( $j = 1; $j <= $l_rounds; $j++ ) {
				// Anzahl Matches in L-Rj:
				//   j ungerade: r = (j+1)/2  → 2^(k-1-r) Matches
				//   j gerade:   r =  j/2     → 2^(k-1-r) Matches
				$r_step = ( $j % 2 === 1 ) ? ( ( $j + 1 ) / 2 ) : ( $j / 2 );
				$num    = (int) pow( 2, $k - 1 - $r_step );
				if ( $num < 1 ) $num = 1;
				for ( $p = 0; $p < $num; $p++ ) {
					$l[ $j ][ $p ] = SDT_DB::insert_match( array(
						'tournament_id'    => (int) $tournament_id,
						'group_label'      => '',
						'round'            => 0,
						'position'         => $p,
						'player1_id'       => 0,
						'player2_id'       => 0,
						'status'           => 'pending',
						'phase'            => $phase,
						'bracket_round'    => $j,
						'bracket_position' => $p,
						'bracket_side'     => 'loser',
					) );
				}
			}
		}

		// Kein Grand Final, kein Trostrunden-Finale mit W-Final-Verlierer.

		// --- Sieger-Feeds Hauptrunde ---
		for ( $r = 1; $r < $k; $r++ ) {
			foreach ( $w[ $r ] as $p => $mid ) {
				$target = $w[ $r + 1 ][ intdiv( $p, 2 ) ];
				$slot   = ( $p % 2 ) + 1;
				SDT_DB::set_match_feeds( $mid, $target, $slot );
			}
		}
		// W-Final hat KEIN Sieger-Feed mehr (Sieger = 1., Verlierer = 2.)

		// --- Verlierer-Feeds Hauptrunde → Trostrunde ---
		if ( $k >= 2 ) {
			// W-R1 Verlierer: direktbenachbart paaren in L-R1
			foreach ( $w[1] as $p => $mid ) {
				$target = $l[1][ intdiv( $p, 2 ) ];
				$slot   = ( $p % 2 ) + 1;
				SDT_DB::set_match_loser_feeds( $mid, $target, $slot );
			}
			// W-R_r (r>=2, r < k) Verlierer → L-R(2r-2), Slot 2, gespiegelt
			// W-Final (r=k) hat KEINEN Verlierer-Feed — sein Verlierer wird 2.
			for ( $r = 2; $r < $k; $r++ ) {
				$j         = 2 * ( $r - 1 );
				if ( ! isset( $l[ $j ] ) ) continue;
				$num_in_lj = count( $l[ $j ] );
				foreach ( $w[ $r ] as $p => $mid ) {
					$target_pos = $num_in_lj - 1 - $p;
					if ( $target_pos < 0 ) $target_pos = 0;
					SDT_DB::set_match_loser_feeds( $mid, $l[ $j ][ $target_pos ], 2 );
				}
			}

			// --- Sieger-Feeds Trostrunde ---
			for ( $j = 1; $j < $l_rounds; $j++ ) {
				$next_is_even = ( ( $j + 1 ) % 2 === 0 );
				foreach ( $l[ $j ] as $p => $mid ) {
					if ( $next_is_even ) {
						$target = $l[ $j + 1 ][ $p ];
						$slot   = 1;
					} else {
						$target = $l[ $j + 1 ][ intdiv( $p, 2 ) ];
						$slot   = ( $p % 2 ) + 1;
					}
					SDT_DB::set_match_feeds( $mid, $target, $slot );
				}
			}
			// Letztes L-Match (Spiel um Platz 3) hat KEIN Sieger-Feed
		}

		// --- Byes auflösen ---
		self::resolve_byes( $tournament_id, $phase );
	}

	/**
	 * Reset eines Bracket-Matches: Sieger/Verlierer aus downstream-Slots entfernen,
	 * ggf. tote downstream-Matches re-öffnen, und resolve_byes neu laufen lassen.
	 */
	public static function reset_bracket_cascade( $match_id ) {
		$m = SDT_DB::get_match( $match_id );
		if ( ! $m ) return;
		global $wpdb;
		// Sieger-Feed-Slot in downstream-Match leeren
		if ( $m->feeds_match_id && $m->feeds_slot ) {
			self::clear_downstream_slot( (int) $m->feeds_match_id, (int) $m->feeds_slot );
		}
		// Verlierer-Feed-Slot in downstream-Match leeren
		if ( $m->loser_feeds_match_id && $m->loser_feeds_slot ) {
			self::clear_downstream_slot( (int) $m->loser_feeds_match_id, (int) $m->loser_feeds_slot );
		}
		// Bye-Auflösung neu rechnen
		self::resolve_byes( (int) $m->tournament_id, $m->phase );
	}

	/**
	 * Räumt einen Slot eines downstream-Matches: Spieler-ID auf 0 setzen.
	 * Wenn das downstream-Match bereits 'done' war (auto-advanced oder tot), wird es re-öffnet
	 * und sein Sieger/Verlierer rekursiv weiter zurückgenommen.
	 */
	private static function clear_downstream_slot( $match_id, $slot ) {
		global $wpdb;
		$dm = SDT_DB::get_match( $match_id );
		if ( ! $dm ) return;
		$col = ( (int) $slot === 1 ) ? 'player1_id' : 'player2_id';

		$was_done = $dm->status === 'done';
		$had_winner = (int) $dm->winner_id > 0;

		// Slot leeren + ggf. Match wieder öffnen
		$update = array( $col => 0 );
		if ( $was_done ) {
			$update['status']      = 'pending';
			$update['winner_id']   = null;
			$update['finished_at'] = null;
		}
		$wpdb->update(
			SDT_DB::t_matches(),
			$update,
			array( 'id' => (int) $match_id ),
			null,
			array( '%d' )
		);

		// Wenn das downstream-Match vorher schon einen Sieger/Verlierer weitergegeben hatte → kaskadieren
		if ( $was_done && $had_winner ) {
			if ( $dm->feeds_match_id && $dm->feeds_slot ) {
				self::clear_downstream_slot( (int) $dm->feeds_match_id, (int) $dm->feeds_slot );
			}
			if ( $dm->loser_feeds_match_id && $dm->loser_feeds_slot ) {
				self::clear_downstream_slot( (int) $dm->loser_feeds_match_id, (int) $dm->loser_feeds_slot );
			}
		}
		// Tote Matches ohne Sieger werden auch re-öffnet (status=pending), brauchen aber keine Kaskade,
		// weil sie nie einen Sieger weitergegeben haben.
	}

	/**
	 * Sieger nach vorne weiterleiten — und Verlierer ins Loser-Bracket (falls verdrahtet).
	 */
	public static function advance_winner( $match_id ) {
		$m = SDT_DB::get_match( $match_id );
		if ( ! $m || ! $m->winner_id ) {
			return;
		}
		if ( $m->feeds_match_id && $m->feeds_slot ) {
			SDT_DB::set_match_player( $m->feeds_match_id, $m->feeds_slot, (int) $m->winner_id );
		}
		if ( $m->loser_feeds_match_id && $m->loser_feeds_slot ) {
			$loser = (int) $m->winner_id === (int) $m->player1_id ? (int) $m->player2_id : (int) $m->player1_id;
			SDT_DB::set_match_player( $m->loser_feeds_match_id, $m->loser_feeds_slot, $loser );
		}
		// Bye-Kaskaden nach jedem Advance auflösen (für L-Bracket/Final)
		self::resolve_byes( (int) $m->tournament_id, $m->phase );
	}

	/**
	 * Iteriert durch alle Bracket-Matches eines Phase und löst Byes automatisch auf:
	 * Match hat genau einen echten Spieler UND der andere Slot wird von keinem
	 * (noch pending) upstream-Match befüllt → der echte Spieler ist Auto-Sieger.
	 */
	private static function resolve_byes( $tournament_id, $phase ) {
		for ( $safety = 0; $safety < 64; $safety++ ) {
			$changed = false;
			$matches = SDT_DB::get_matches( $tournament_id );
			// Indizes
			$by_id = array();
			foreach ( $matches as $x ) {
				$by_id[ (int) $x->id ] = $x;
			}
			// Reverse-Lookup: welches upstream-Match füllt Slot S in Target T?
			$fills = array(); // [target_id][slot] = upstream match object
			foreach ( $matches as $x ) {
				if ( $x->feeds_match_id && $x->feeds_slot ) {
					$fills[ (int) $x->feeds_match_id ][ (int) $x->feeds_slot ] = $x;
				}
				if ( $x->loser_feeds_match_id && $x->loser_feeds_slot ) {
					$fills[ (int) $x->loser_feeds_match_id ][ (int) $x->loser_feeds_slot ] = $x;
				}
			}

			foreach ( $matches as $m ) {
				if ( $m->phase !== $phase ) continue;
				if ( $m->status === 'done' ) continue;
				$p1 = (int) $m->player1_id;
				$p2 = (int) $m->player2_id;

				// Beide echt: spielen lassen
				if ( $p1 > 0 && $p2 > 0 ) continue;

				// Slot-Status prüfen: ist der Slot "definitiv leer" (kein upstream mehr offen)?
				$slot1_dead = self::slot_is_permanently_empty( $m, 1, $fills );
				$slot2_dead = self::slot_is_permanently_empty( $m, 2, $fills );

				if ( $p1 > 0 && $p2 === 0 && $slot2_dead ) {
					self::auto_advance( $m, $p1 );
					$changed = true;
				} elseif ( $p2 > 0 && $p1 === 0 && $slot1_dead ) {
					self::auto_advance( $m, $p2 );
					$changed = true;
				} elseif ( $p1 === 0 && $p2 === 0 && $slot1_dead && $slot2_dead ) {
					// Totes Match — kein Spieler, propagiere 0 nach downstream
					global $wpdb;
					$wpdb->update(
						SDT_DB::t_matches(),
						array( 'status' => 'done', 'winner_id' => null, 'finished_at' => current_time( 'mysql' ) ),
						array( 'id' => (int) $m->id ),
						array( '%s', '%d', '%s' ),
						array( '%d' )
					);
					if ( $m->feeds_match_id && $m->feeds_slot ) {
						SDT_DB::set_match_player( $m->feeds_match_id, $m->feeds_slot, 0 );
					}
					if ( $m->loser_feeds_match_id && $m->loser_feeds_slot ) {
						SDT_DB::set_match_player( $m->loser_feeds_match_id, $m->loser_feeds_slot, 0 );
					}
					$changed = true;
				}
			}
			if ( ! $changed ) break;
		}
	}

	private static function slot_is_permanently_empty( $match, $slot, $fills ) {
		$upstream = $fills[ (int) $match->id ][ $slot ] ?? null;
		if ( ! $upstream ) {
			// Kein upstream-Match → R1-Quelle → wenn Slot leer, dann permanent leer
			return true;
		}
		if ( $upstream->status !== 'done' ) {
			return false;
		}
		// Upstream done — wird unser Slot vom Sieger oder vom Verlierer gefüttert?
		if ( (int) $upstream->feeds_match_id === (int) $match->id && (int) $upstream->feeds_slot === $slot ) {
			// Sieger-Feed: leer nur, wenn winner_id NULL/0 (totes upstream)
			return ! (int) $upstream->winner_id;
		}
		if ( (int) $upstream->loser_feeds_match_id === (int) $match->id && (int) $upstream->loser_feeds_slot === $slot ) {
			// Verlierer-Feed: leer wenn upstream-Verlierer 0 ist
			if ( ! (int) $upstream->winner_id ) return true; // upstream tot
			$upstream_loser = (int) $upstream->winner_id === (int) $upstream->player1_id
				? (int) $upstream->player2_id : (int) $upstream->player1_id;
			return $upstream_loser === 0;
		}
		return false;
	}

	private static function auto_advance( $m, $winner_id ) {
		SDT_DB::set_winner( (int) $m->id, (int) $winner_id );
		$fresh = SDT_DB::get_match( (int) $m->id );
		if ( ! $fresh ) return;
		if ( $fresh->feeds_match_id && $fresh->feeds_slot ) {
			SDT_DB::set_match_player( $fresh->feeds_match_id, $fresh->feeds_slot, (int) $fresh->winner_id );
		}
		if ( $fresh->loser_feeds_match_id && $fresh->loser_feeds_slot ) {
			// Auto-Sieger ohne echten Gegner → Verlierer ist 0
			SDT_DB::set_match_player( $fresh->loser_feeds_match_id, $fresh->loser_feeds_slot, 0 );
		}
	}

	/**
	 * Füllt alle spielbaren Bracket-Matches iterativ mit Zufalls-Siegern, bis nichts mehr spielbar ist.
	 */
	public static function simulate_bracket_phase( $tournament_id ) {
		for ( $safety = 0; $safety < 500; $safety++ ) {
			$changed = false;
			$matches = SDT_DB::get_matches( $tournament_id );
			foreach ( $matches as $m ) {
				if ( $m->phase === 'group' ) continue;
				if ( $m->status !== 'pending' ) continue;
				if ( (int) $m->player1_id <= 0 || (int) $m->player2_id <= 0 ) continue;
				$winner = ( wp_rand( 0, 1 ) === 0 ) ? (int) $m->player1_id : (int) $m->player2_id;
				SDT_DB::set_winner( $m->id, $winner );
				self::advance_winner( $m->id );
				$changed = true;
			}
			if ( ! $changed ) break;
		}
	}

	/**
	 * Füllt alle offenen Vorrunden-Spiele mit Zufalls-Siegern (zum Testen).
	 */
	public static function simulate_group_phase( $tournament_id ) {
		foreach ( SDT_DB::get_matches( $tournament_id ) as $m ) {
			if ( $m->phase !== 'group' ) continue;
			if ( $m->status === 'done' ) continue;
			$winner = ( wp_rand( 0, 1 ) === 0 ) ? (int) $m->player1_id : (int) $m->player2_id;
			SDT_DB::set_winner( $m->id, $winner );
		}
	}

	/**
	 * Wählt die nächsten spielbaren Matches:
	 * - pending matches
	 * - sortiert nach round ASC (alle Spieler kommen erst zur nächsten Runde, wenn aktuelle abgearbeitet werden)
	 * - max. {tables_count} parallel, kein Spieler doppelt
	 */
	public static function next_matches( $tournament_id, $tables_count ) {
		$matches = SDT_DB::get_matches( $tournament_id );
		$pending = array_filter( $matches, function ( $m ) {
			return $m->status === 'pending' && $m->phase === 'group';
		} );
		usort( $pending, function ( $a, $b ) {
			return array( (int) $a->round, $a->group_label, (int) $a->position )
				<=> array( (int) $b->round, $b->group_label, (int) $b->position );
		} );

		$picked      = array();
		$used        = array();
		$tables_count = max( 1, (int) $tables_count );
		foreach ( $pending as $m ) {
			if ( count( $picked ) >= $tables_count ) {
				break;
			}
			if ( isset( $used[ $m->player1_id ] ) || isset( $used[ $m->player2_id ] ) ) {
				continue;
			}
			$picked[] = $m;
			$used[ $m->player1_id ] = true;
			$used[ $m->player2_id ] = true;
		}
		return $picked;
	}

	/**
	 * Wählt die nächsten spielbaren Bracket-Matches (Hauptrunde, Trostrunde, Finale).
	 * Reihenfolge: Hauptrunde vor Trostrunde, dann nach Bracket-Runde aufsteigend, dann nach Position.
	 */
	public static function next_bracket_matches( $tournament_id, $tables_count ) {
		$matches = SDT_DB::get_matches( $tournament_id );
		$pending = array_filter( $matches, function ( $m ) {
			return $m->status === 'pending'
				&& $m->phase !== 'group'
				&& (int) $m->player1_id > 0
				&& (int) $m->player2_id > 0;
		} );
		usort( $pending, array( __CLASS__, 'cmp_bracket_priority' ) );
		$picked       = array();
		$used         = array();
		$tables_count = max( 1, (int) $tables_count );
		foreach ( $pending as $m ) {
			if ( count( $picked ) >= $tables_count ) break;
			if ( isset( $used[ $m->player1_id ] ) || isset( $used[ $m->player2_id ] ) ) continue;
			$picked[] = $m;
			$used[ $m->player1_id ] = true;
			$used[ $m->player2_id ] = true;
		}
		return $picked;
	}

	public static function upcoming_bracket_after( $tournament_id, array $exclude_ids, $count ) {
		$matches = SDT_DB::get_matches( $tournament_id );
		$pending = array_filter( $matches, function ( $m ) use ( $exclude_ids ) {
			return $m->status === 'pending'
				&& $m->phase !== 'group'
				&& (int) $m->player1_id > 0
				&& (int) $m->player2_id > 0
				&& ! in_array( (int) $m->id, $exclude_ids, true );
		} );
		usort( $pending, array( __CLASS__, 'cmp_bracket_priority' ) );
		return array_slice( array_values( $pending ), 0, max( 0, (int) $count ) );
	}

	/**
	 * Lesbares Label für ein Match. Beispiele:
	 *   "Gruppe A · Runde 2"
	 *   "Goldrunde · 1/8-Finale"
	 *   "Silberrunde · Trostrunde R3"
	 *   "Goldrunde · Spiel um Platz 3"
	 */
	public static function match_label( $match, $all_matches = null ) {
		if ( $match->phase === 'group' ) {
			return 'Gruppe ' . $match->group_label . ' · Runde ' . (int) $match->round;
		}
		$side    = $match->bracket_side ?: 'winner';
		$phase   = $match->phase;
		$round   = (int) $match->bracket_round;
		$phase_l = $phase === 'gold' ? 'Goldrunde' : ( $phase === 'silber' ? 'Silberrunde' : ucfirst( $phase ) );

		// Max-Runde pro Phase+Side ermitteln
		if ( $all_matches === null ) {
			$all_matches = SDT_DB::get_matches( (int) $match->tournament_id );
		}
		$max_round = 0;
		foreach ( $all_matches as $x ) {
			if ( $x->phase !== $phase ) continue;
			if ( ( $x->bracket_side ?: 'winner' ) !== $side ) continue;
			if ( (int) $x->bracket_round > $max_round ) $max_round = (int) $x->bracket_round;
		}

		if ( $side === 'final' ) return $phase_l . ' · Finale';
		if ( $side === 'loser' ) {
			if ( $round === $max_round ) return $phase_l . ' · Spiel um Platz 3';
			return $phase_l . ' · Trostrunde R' . $round;
		}
		// winner
		$rounds_left = $max_round - $round;
		if ( $rounds_left === 0 ) return $phase_l . ' · Hauptrunden-Finale';
		if ( $rounds_left === 1 ) return $phase_l . ' · Halbfinale';
		if ( $rounds_left === 2 ) return $phase_l . ' · Viertelfinale';
		$n = (int) pow( 2, $rounds_left );
		return $phase_l . ' · 1/' . $n . '-Finale';
	}

	private static function cmp_bracket_priority( $a, $b ) {
		// Primär: nach Bracket-Runde aufsteigend — frühe Runden überall zuerst,
		// damit Trost-Runden nicht ans Ende geschoben werden und alles ungefähr parallel läuft.
		$bra = (int) $a->bracket_round;
		$brb = (int) $b->bracket_round;
		if ( $bra !== $brb ) return $bra - $brb;
		// Sekundär: innerhalb der gleichen Bracket-Runde Hauptrunde vor Trostrunde vor Finale
		$rank = function ( $m ) {
			$side = $m->bracket_side ?: 'winner';
			if ( $side === 'winner' ) return 0;
			if ( $side === 'final' )  return 2;
			return 1;
		};
		$ra = $rank( $a );
		$rb = $rank( $b );
		if ( $ra !== $rb ) return $ra - $rb;
		// Tertiär: Goldrunde vor Silberrunde
		if ( $a->phase !== $b->phase ) {
			return $a->phase === 'gold' ? -1 : 1;
		}
		return (int) $a->bracket_position - (int) $b->bracket_position;
	}

	/**
	 * Nächste pending Matches nach den "spielbaren" — informativer Vorschau-Feed.
	 * Schließt die übergebenen Match-IDs aus.
	 */
	public static function upcoming_after( $tournament_id, array $exclude_ids, $count ) {
		$matches = SDT_DB::get_matches( $tournament_id );
		$pending = array_filter( $matches, function ( $m ) use ( $exclude_ids ) {
			return $m->status === 'pending' && $m->phase === 'group' && ! in_array( (int) $m->id, $exclude_ids, true );
		} );
		usort( $pending, function ( $a, $b ) {
			return array( (int) $a->round, $a->group_label, (int) $a->position )
				<=> array( (int) $b->round, $b->group_label, (int) $b->position );
		} );
		return array_slice( array_values( $pending ), 0, max( 0, (int) $count ) );
	}

	/**
	 * Tabellen-Stand pro Gruppe. Liefert array: group_label => array of standings
	 * Sortierung: Siege DESC, dann direkter Vergleich bei 2er-Gleichstand, sonst Name.
	 * Markiert qualified=1/2, wenn alle Spiele der Gruppe abgeschlossen sind.
	 */
	public static function standings( $tournament_id ) {
		$players = SDT_DB::get_players( $tournament_id );
		$matches = SDT_DB::get_matches( $tournament_id );

		$by_group     = array();
		$players_by_g = array();
		foreach ( $players as $p ) {
			if ( ! $p->group_label ) continue;
			$players_by_g[ $p->group_label ][ $p->id ] = $p;
		}

		foreach ( $players_by_g as $label => $grp_players ) {
			$rows = array();
			foreach ( $grp_players as $pid => $p ) {
				$rows[ $pid ] = array(
					'id'        => (int) $pid,
					'name'      => $p->name,
					'played'    => 0,
					'wins'      => 0,
					'losses'    => 0,
					'qualified' => 0,
				);
			}

			$group_matches      = array_filter( $matches, function ( $m ) use ( $label ) {
				return $m->group_label === $label && $m->phase === 'group';
			} );
			$group_done         = true;
			$h2h                = array(); // [winner_id][loser_id] = true
			foreach ( $group_matches as $m ) {
				if ( $m->status !== 'done' || ! $m->winner_id ) {
					$group_done = false;
					continue;
				}
				$w = (int) $m->winner_id;
				$l = $w === (int) $m->player1_id ? (int) $m->player2_id : (int) $m->player1_id;
				if ( isset( $rows[ $w ] ) ) {
					$rows[ $w ]['wins']++;
					$rows[ $w ]['played']++;
				}
				if ( isset( $rows[ $l ] ) ) {
					$rows[ $l ]['losses']++;
					$rows[ $l ]['played']++;
				}
				$h2h[ $w ][ $l ] = true;
			}

			$rows = array_values( $rows );
			usort( $rows, function ( $a, $b ) use ( $h2h ) {
				if ( $a['wins'] !== $b['wins'] ) {
					return $b['wins'] <=> $a['wins'];
				}
				// direkter Vergleich
				if ( ! empty( $h2h[ $a['id'] ][ $b['id'] ] ) ) return -1;
				if ( ! empty( $h2h[ $b['id'] ][ $a['id'] ] ) ) return 1;
				return strcmp( $a['name'], $b['name'] );
			} );

			if ( $group_done ) {
				foreach ( $rows as $i => &$r ) {
					$r['qualified'] = $i < 2 ? 1 : 2; // 1 = Gold, 2 = Silber
				}
				unset( $r );
			}

			$by_group[ $label ] = $rows;
		}

		ksort( $by_group );
		return $by_group;
	}
}
