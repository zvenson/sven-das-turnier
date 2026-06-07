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
					) );
				}
			}
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
		$pending = array_filter( $matches, function ( $m ) { return $m->status === 'pending'; } );
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
				return $m->group_label === $label;
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
