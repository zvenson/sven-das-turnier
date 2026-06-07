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

		$firsts  = array();
		$seconds = array();
		foreach ( $standings as $label => $rows ) {
			if ( isset( $rows[0] ) ) $firsts[]  = $rows[0];
			if ( isset( $rows[1] ) ) $seconds[] = $rows[1];
		}

		SDT_DB::delete_bracket_matches( $tournament_id );

		if ( count( $firsts ) >= 2 ) {
			self::build_bracket( $tournament_id, 'gold', $firsts );
		}
		if ( count( $seconds ) >= 2 ) {
			self::build_bracket( $tournament_id, 'silber', $seconds );
		}
	}

	private static function build_bracket( $tournament_id, $phase, $seeds ) {
		$n = count( $seeds );
		$size = 1;
		while ( $size < $n ) $size *= 2;

		$slots         = array_fill( 0, $size, 0 );
		$seeding_order = self::seeding_order( $size );
		for ( $i = 0; $i < $n; $i++ ) {
			$slots[ $seeding_order[ $i ] ] = (int) $seeds[ $i ]['id'];
		}

		$rounds_count       = (int) log( $size, 2 );
		$matches_by_round   = array();

		// Matches erzeugen, von Runde 1 (alle Paarungen) bis zum Finale (1 Match)
		for ( $r = 1; $r <= $rounds_count; $r++ ) {
			$num = $size / pow( 2, $r );
			for ( $p = 0; $p < $num; $p++ ) {
				$p1 = $p2 = 0;
				if ( $r === 1 ) {
					$p1 = $slots[ $p * 2 ];
					$p2 = $slots[ $p * 2 + 1 ];
				}
				$mid = SDT_DB::insert_match( array(
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
				) );
				$matches_by_round[ $r ][ $p ] = $mid;
			}
		}

		// Feeds verdrahten: Match (r, p) → Match (r+1, p/2), Slot p%2+1
		for ( $r = 1; $r < $rounds_count; $r++ ) {
			foreach ( $matches_by_round[ $r ] as $p => $mid ) {
				$target = $matches_by_round[ $r + 1 ][ intdiv( $p, 2 ) ];
				$slot   = ( $p % 2 ) + 1;
				SDT_DB::set_match_feeds( $mid, $target, $slot );
			}
		}

		// Byes in R1 auto-advancen
		foreach ( $matches_by_round[1] as $p => $mid ) {
			$m = SDT_DB::get_match( $mid );
			if ( ! $m ) continue;
			$winner = 0;
			if ( (int) $m->player1_id > 0 && (int) $m->player2_id === 0 ) {
				$winner = (int) $m->player1_id;
			} elseif ( (int) $m->player2_id > 0 && (int) $m->player1_id === 0 ) {
				$winner = (int) $m->player2_id;
			}
			if ( $winner ) {
				SDT_DB::set_winner( $mid, $winner );
				self::advance_winner( $mid );
			}
		}
	}

	public static function advance_winner( $match_id ) {
		$m = SDT_DB::get_match( $match_id );
		if ( ! $m || ! $m->winner_id || ! $m->feeds_match_id || ! $m->feeds_slot ) {
			return;
		}
		SDT_DB::set_match_player( $m->feeds_match_id, $m->feeds_slot, (int) $m->winner_id );
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

			if ( $group_done && count( $rows ) >= 1 ) {
				$rows[0]['qualified'] = 1;
				if ( isset( $rows[1] ) ) {
					$rows[1]['qualified'] = 2;
				}
			}

			$by_group[ $label ] = $rows;
		}

		ksort( $by_group );
		return $by_group;
	}
}
