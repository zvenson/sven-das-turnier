<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SDT_Frontend {

	public static function init() {
		add_shortcode( 'sdt_turnier', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function assets() {
		wp_register_style( 'sdt-frontend', SDT_URL . 'assets/frontend.css', array(), SDT_VERSION );
	}

	public static function render( $atts ) {
		wp_enqueue_style( 'sdt-frontend' );
		$atts = shortcode_atts( array( 'id' => 0, 'refresh' => 30 ), $atts );
		$refresh = max( 0, (int) $atts['refresh'] );

		$t = $atts['id']
			? SDT_DB::get_tournament( (int) $atts['id'] )
			: SDT_DB::latest_active_tournament();

		if ( ! $t ) {
			return '<p class="sdt-fe-empty">Aktuell läuft kein Turnier.</p>';
		}

		$matches       = SDT_DB::get_matches( $t->id );
		$bracket_phase = SDT_Scheduler::has_bracket_matches( $t->id );
		$is_tennis     = ( $t->mode ?? 'simple' ) === 'tennis';
		$group_only    = ( $t->format ?? 'group_ko' ) === 'group_only';

		if ( $bracket_phase ) {
			$next  = SDT_Scheduler::next_bracket_matches( $t->id, $t->tables_count );
			$queue = SDT_Scheduler::upcoming_bracket_after( $t->id, array_map( function ( $m ) { return (int) $m->id; }, $next ), 999 );
		} else {
			$next = SDT_Scheduler::next_matches( $t->id, $t->tables_count );
			$queue_size = max( 0, 6 - count( $next ) );
			$queue = SDT_Scheduler::upcoming_after( $t->id, array_map( function ( $m ) { return (int) $m->id; }, $next ), $queue_size );
		}
		$standings = SDT_Scheduler::standings( $t->id );
		$players   = array();
		foreach ( SDT_DB::get_players( $t->id ) as $p ) {
			$players[ $p->id ] = $p->name;
		}

		ob_start();
		?>
		<div class="sdt-fe">
			<?php if ( $refresh > 0 ) : ?>
				<script>setTimeout(function(){ location.reload(); }, <?php echo (int) $refresh * 1000; ?>);</script>
			<?php endif; ?>
			<h2 class="sdt-fe-title"><?php echo esc_html( $t->name ); ?></h2>

			<?php
			$bracket_matches = array_filter( $matches, function ( $m ) { return $m->phase !== 'group'; } );
			$has_gold        = (bool) array_filter( $bracket_matches, function ( $m ) { return $m->phase === 'gold'; } );
			$has_silber      = (bool) array_filter( $bracket_matches, function ( $m ) { return $m->phase === 'silber'; } );
			$has_gold_trost  = (bool) array_filter( $bracket_matches, function ( $m ) { return $m->phase === 'gold'   && ( $m->bracket_side ?: 'winner' ) === 'loser'; } );
			$has_silber_trost = (bool) array_filter( $bracket_matches, function ( $m ) { return $m->phase === 'silber' && ( $m->bracket_side ?: 'winner' ) === 'loser'; } );
			$group_phase_done = SDT_Scheduler::is_group_phase_done( $t->id );
			$has_podium      = $group_only
				? $group_phase_done
				: ( self::compute_podium( 'gold', $matches ) || self::compute_podium( 'silber', $matches ) );
			?>
			<nav class="sdt-fe-nav">
				<?php if ( ! empty( $next ) ) : ?><a href="#sdt-naechste">⏭ Nächste Spiele</a><?php endif; ?>
				<?php if ( $has_gold ) : ?><a href="#sdt-gold-winner">🏆 Goldrunde</a><?php endif; ?>
				<?php if ( $has_gold_trost ) : ?><a href="#sdt-gold-loser">🥉 Trostrunde Gold</a><?php endif; ?>
				<?php if ( $has_silber ) : ?><a href="#sdt-silber-winner">🥈 Silberrunde</a><?php endif; ?>
				<?php if ( $has_silber_trost ) : ?><a href="#sdt-silber-loser">🥉 Trostrunde Silber</a><?php endif; ?>
				<a href="#sdt-vorrunden">📋 <?php echo $group_only ? 'Tabellen' : 'Vorrunden'; ?></a>
				<?php if ( $has_podium ) : ?><a href="#sdt-podium">🏅 Endergebnis</a><?php endif; ?>
			</nav>

			<?php
			if ( $group_only ) {
				if ( $group_phase_done ) {
					self::render_podiums_group_only( $standings );
				}
			} else {
				self::render_podiums( $matches, $players );
			}
			?>

			<?php if ( ! empty( $next ) ) : ?>
				<div id="sdt-naechste" class="sdt-fe-section sdt-fe-section-next">
					<h3>Nächste Spiele</h3>
					<?php self::render_match_table( $next, $players, $bracket_phase, 'sdt-fe-next' ); ?>

					<?php if ( ! empty( $queue ) ) : ?>
						<h4 class="sdt-fe-subhead">Danach</h4>
						<?php self::render_match_table( $queue, $players, $bracket_phase, 'sdt-fe-queue' ); ?>
					<?php endif; ?>
				</div>
			<?php endif; ?>

			<?php
			if ( ! empty( $bracket_matches ) ) :
				self::render_brackets_frontend( $bracket_matches, $players );
			endif;
			?>

			<h3 id="sdt-vorrunden"><?php echo $group_only ? 'Tabellen' : 'Vorrunden-Tabellen'; ?></h3>
			<div class="sdt-fe-standings">
			<?php foreach ( $standings as $label => $rows ) : ?>
				<div class="sdt-fe-group">
					<h4>Gruppe <?php echo esc_html( $label ); ?></h4>
					<table class="sdt-fe-table">
						<thead><tr><th></th><th>Spieler</th><th>Sp</th><th>S</th><th>N</th><?php if ( $is_tennis ) : ?><th>Sätze</th><?php endif; ?></tr></thead>
						<tbody>
						<?php foreach ( $rows as $i => $r ) : $pos = $i + 1; ?>
							<tr class="sdt-q-<?php echo (int) $r['qualified']; ?>">
								<td><?php
									$icon = '';
									if ( $r['qualified'] === 1 ) $icon = '🥇';
									elseif ( $r['qualified'] === 2 ) $icon = '🥈';
									echo $icon . ' ' . $pos . '.';
								?></td>
								<td><?php echo esc_html( $r['name'] ); ?></td>
								<td><?php echo (int) $r['played']; ?></td>
								<td><strong><?php echo (int) $r['wins']; ?></strong></td>
								<td><?php echo (int) $r['losses']; ?></td>
								<?php if ( $is_tennis ) : ?>
									<td><?php echo (int) $r['sets_won']; ?>:<?php echo (int) $r['sets_lost']; ?></td>
								<?php endif; ?>
							</tr>
						<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endforeach; ?>
			</div>

		</div>
		<?php
		return ob_get_clean();
	}

	private static function render_podiums( $matches, $players ) {
		$podiums = array();
		foreach ( array( 'gold' => '🏆 Goldrunde', 'silber' => '🥈 Silberrunde' ) as $phase => $title ) {
			$p = self::compute_podium( $phase, $matches );
			if ( $p ) {
				$podiums[ $phase ] = array( 'title' => $title, 'p' => $p );
			}
		}
		if ( empty( $podiums ) ) return;
		?>
		<h3 id="sdt-podium" class="sdt-fe-podium-title">Endergebnis</h3>
		<div class="sdt-fe-podiums">
			<?php foreach ( $podiums as $phase => $d ) :
				$p = $d['p'];
				$first  = $players[ $p['first'] ]  ?? '?';
				$second = $players[ $p['second'] ] ?? '?';
				$third  = $p['third'] ? ( $players[ $p['third'] ] ?? '?' ) : '';
				?>
				<div class="sdt-fe-podium sdt-fe-podium-<?php echo esc_attr( $phase ); ?>">
					<div class="sdt-fe-podium-heading"><?php echo esc_html( $d['title'] ); ?></div>
					<div class="sdt-fe-podium-stage">
						<div class="sdt-fe-podium-col sdt-fe-podium-2">
							<div class="sdt-fe-podium-name"><?php echo esc_html( $second ); ?></div>
							<div class="sdt-fe-podium-block">
								<span class="sdt-fe-podium-medal">🥈</span>
								<span class="sdt-fe-podium-rank">2</span>
							</div>
						</div>
						<div class="sdt-fe-podium-col sdt-fe-podium-1">
							<div class="sdt-fe-podium-name"><?php echo esc_html( $first ); ?></div>
							<div class="sdt-fe-podium-block">
								<span class="sdt-fe-podium-medal">🥇</span>
								<span class="sdt-fe-podium-rank">1</span>
							</div>
						</div>
						<div class="sdt-fe-podium-col sdt-fe-podium-3">
							<div class="sdt-fe-podium-name"><?php echo $third ? esc_html( $third ) : '&nbsp;'; ?></div>
							<div class="sdt-fe-podium-block">
								<span class="sdt-fe-podium-medal">🥉</span>
								<span class="sdt-fe-podium-rank">3</span>
							</div>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Podium für "Nur Gruppenphase": Top 3 jeder Gruppe, sobald alle Spiele durch sind.
	 */
	private static function render_podiums_group_only( $standings ) {
		if ( empty( $standings ) ) return;
		$multi = count( $standings ) > 1;
		?>
		<h3 id="sdt-podium" class="sdt-fe-podium-title">Endergebnis</h3>
		<div class="sdt-fe-podiums">
			<?php foreach ( $standings as $label => $rows ) :
				$first  = $rows[0]['name'] ?? '';
				$second = $rows[1]['name'] ?? '';
				$third  = $rows[2]['name'] ?? '';
				if ( $first === '' ) continue;
				?>
				<div class="sdt-fe-podium sdt-fe-podium-gold">
					<div class="sdt-fe-podium-heading"><?php echo $multi ? '🏆 Gruppe ' . esc_html( $label ) : '🏆 Endstand'; ?></div>
					<div class="sdt-fe-podium-stage">
						<div class="sdt-fe-podium-col sdt-fe-podium-2">
							<div class="sdt-fe-podium-name"><?php echo $second !== '' ? esc_html( $second ) : '&nbsp;'; ?></div>
							<div class="sdt-fe-podium-block">
								<span class="sdt-fe-podium-medal">🥈</span>
								<span class="sdt-fe-podium-rank">2</span>
							</div>
						</div>
						<div class="sdt-fe-podium-col sdt-fe-podium-1">
							<div class="sdt-fe-podium-name"><?php echo esc_html( $first ); ?></div>
							<div class="sdt-fe-podium-block">
								<span class="sdt-fe-podium-medal">🥇</span>
								<span class="sdt-fe-podium-rank">1</span>
							</div>
						</div>
						<div class="sdt-fe-podium-col sdt-fe-podium-3">
							<div class="sdt-fe-podium-name"><?php echo $third !== '' ? esc_html( $third ) : '&nbsp;'; ?></div>
							<div class="sdt-fe-podium-block">
								<span class="sdt-fe-podium-medal">🥉</span>
								<span class="sdt-fe-podium-rank">3</span>
							</div>
						</div>
					</div>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	/**
	 * Liefert die Sieger-IDs für eine Phase, sobald Hauptrunden-Finale durch ist.
	 * 3. Platz nur wenn auch das letzte Trostrunden-Match abgeschlossen ist.
	 */
	private static function compute_podium( $phase, $matches ) {
		// Hauptrunden-Finale = höchste bracket_round in side=winner
		$winner_matches = array_filter( $matches, function ( $m ) use ( $phase ) {
			return $m->phase === $phase && ( $m->bracket_side ?: 'winner' ) === 'winner';
		} );
		if ( empty( $winner_matches ) ) return null;
		$max_round = 0;
		$final     = null;
		foreach ( $winner_matches as $m ) {
			if ( (int) $m->bracket_round > $max_round ) {
				$max_round = (int) $m->bracket_round;
				$final     = $m;
			}
		}
		if ( ! $final || $final->status !== 'done' || ! $final->winner_id ) return null;
		$first  = (int) $final->winner_id;
		$second = $first === (int) $final->player1_id ? (int) $final->player2_id : (int) $final->player1_id;

		// 3. Platz = Sieger des letzten Trostrunden-Matches
		$loser_matches = array_filter( $matches, function ( $m ) use ( $phase ) {
			return $m->phase === $phase && ( $m->bracket_side ?: 'winner' ) === 'loser';
		} );
		$third = 0;
		if ( ! empty( $loser_matches ) ) {
			$max_lr = 0;
			$l_final = null;
			foreach ( $loser_matches as $m ) {
				if ( (int) $m->bracket_round > $max_lr ) {
					$max_lr  = (int) $m->bracket_round;
					$l_final = $m;
				}
			}
			if ( $l_final && $l_final->status === 'done' && $l_final->winner_id ) {
				$third = (int) $l_final->winner_id;
			}
		}
		return array( 'first' => $first, 'second' => $second, 'third' => $third );
	}

	private static function render_match_table( $matches, $players, $is_bracket, $extra_class ) {
		$all_matches = null; // wird vom Scheduler bei Bedarf nachgeladen
		?>
		<table class="sdt-fe-table <?php echo esc_attr( $extra_class ); ?>">
			<thead><tr>
				<th>Bereich</th>
				<th>Spieler 1</th><th></th><th>Spieler 2</th>
			</tr></thead>
			<tbody>
			<?php foreach ( $matches as $m ) :
				$label = SDT_Scheduler::match_label( $m );
				$badge = self::badge_class( $m, $is_bracket );
				?>
				<tr>
					<td><span class="sdt-fe-badge sdt-fe-badge-<?php echo esc_attr( $badge ); ?>"><?php echo esc_html( $label ); ?></span></td>
					<td><?php echo esc_html( $players[ $m->player1_id ] ?? '?' ); ?></td>
					<td class="vs">vs.</td>
					<td><?php echo esc_html( $players[ $m->player2_id ] ?? '?' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	private static function badge_class( $m, $is_bracket ) {
		if ( ! $is_bracket ) return 'group';
		$side = $m->bracket_side ?: 'winner';
		if ( $side === 'loser' ) return 'loser';
		if ( $side === 'final' ) return 'final';
		return 'winner';
	}

	private static function render_brackets_frontend( $matches, $players ) {
		$brackets = array(
			'gold'   => array( 'winner' => array(), 'loser' => array(), 'final' => null ),
			'silber' => array( 'winner' => array(), 'loser' => array(), 'final' => null ),
		);
		foreach ( $matches as $m ) {
			if ( ! isset( $brackets[ $m->phase ] ) ) continue;
			$side = $m->bracket_side ?: 'winner';
			if ( $side === 'final' ) {
				$brackets[ $m->phase ]['final'] = $m;
			} else {
				$brackets[ $m->phase ][ $side ][ (int) $m->bracket_round ][ (int) $m->bracket_position ] = $m;
			}
		}
		$labels = array(
			'gold'   => '🏆 Goldrunde (Plätze 1 + 2)',
			'silber' => '🥈 Silberrunde (ab Platz 3)',
		);
		foreach ( $brackets as $phase => $data ) {
			if ( empty( $data['winner'] ) ) continue;
			?>
			<h3 id="sdt-<?php echo esc_attr( $phase ); ?>"><?php echo esc_html( $labels[ $phase ] ); ?></h3>
			<?php
			$grand = $data['final'];
			if ( ! $grand && ! empty( $data['winner'] ) ) {
				$last_w = max( array_keys( $data['winner'] ) );
				$grand  = $data['winner'][ $last_w ][0] ?? null;
			}
			if ( $grand && $grand->status === 'done' && $grand->winner_id ) {
				$second_id = (int) $grand->winner_id === (int) $grand->player1_id ? $grand->player2_id : $grand->player1_id;
				$third_id  = 0;
				if ( ! empty( $data['loser'] ) ) {
					$last = max( array_keys( $data['loser'] ) );
					$lf   = $data['loser'][ $last ][0] ?? null;
					if ( $lf && $lf->status === 'done' && $lf->winner_id ) {
						$third_id = (int) $lf->winner_id;
					}
				}
				?>
				<p class="sdt-fe-final">
					<strong>🥇 <?php echo esc_html( $players[ $grand->winner_id ] ?? '?' ); ?></strong>
					&nbsp;·&nbsp;🥈 <?php echo esc_html( $players[ $second_id ] ?? '?' ); ?>
					<?php if ( $third_id ) : ?>&nbsp;·&nbsp;🥉 <?php echo esc_html( $players[ $third_id ] ?? '?' ); endif; ?>
				</p>
				<?php
			}
			self::render_fe_subbracket( $data['winner'], $phase, true, $players );
			if ( ! empty( $data['loser'] ) ) {
				self::render_fe_subbracket( $data['loser'], $phase, false, $players );
			}
			if ( $data['final'] ) {
				?>
				<h4 style="margin-top:10px;">Finale</h4>
				<div class="sdt-fe-bracket sdt-fe-bracket-<?php echo esc_attr( $phase ); ?> sdt-fe-bracket-final">
					<div class="sdt-fe-bracket-round">
						<div class="sdt-fe-bracket-title">Finale (endgültig)</div>
						<?php self::render_fe_match( $data['final'], $players ); ?>
					</div>
				</div>
				<?php
			}
		}
	}

	private static function render_fe_subbracket( $rounds_data, $phase, $is_winner, $players ) {
		ksort( $rounds_data );
		$rounds_count = count( $rounds_data );
		$title_top    = $is_winner ? 'Hauptrunde' : 'Trostrunde';
		$cls_extra    = $is_winner ? 'sdt-fe-bracket-winner' : 'sdt-fe-bracket-loser';
		$anchor       = 'sdt-' . $phase . '-' . ( $is_winner ? 'winner' : 'loser' );
		?>
		<h4 id="<?php echo esc_attr( $anchor ); ?>" style="margin-top:10px;"><?php echo esc_html( $title_top ); ?></h4>
		<div class="sdt-fe-bracket sdt-fe-bracket-<?php echo esc_attr( $phase ); ?> <?php echo esc_attr( $cls_extra ); ?>">
			<?php foreach ( $rounds_data as $round_num => $matches_in_round ) :
				ksort( $matches_in_round );
				if ( $is_winner ) {
					$title = $round_num === $rounds_count ? 'Hauptrunden-Finale (Plätze 1 + 2)' : ( $round_num === $rounds_count - 1 ? 'Halbfinale' : 'Runde ' . $round_num );
				} else {
					$title = $round_num === $rounds_count ? 'Spiel um Platz 3' : 'Trostrunde ' . $round_num;
				}
				?>
				<div class="sdt-fe-bracket-round">
					<div class="sdt-fe-bracket-title"><?php echo esc_html( $title ); ?></div>
					<?php foreach ( $matches_in_round as $m ) {
						self::render_fe_match( $m, $players );
					} ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function render_fe_match( $m, $players ) {
		$done  = $m->status === 'done' && $m->winner_id;
		$score = '';
		if ( $done ) {
			if ( ! empty( $m->walkover ) ) {
				$score = 'w.o.';
			} elseif ( ! empty( $m->score ) ) {
				$score = $m->score;
			}
		}
		?>
		<div class="sdt-fe-bracket-match">
			<div class="<?php echo $done && (int) $m->winner_id === (int) $m->player1_id ? 'sdt-fe-bm-w' : ''; ?>">
				<?php echo esc_html( $players[ $m->player1_id ] ?? '–' ); ?>
			</div>
			<div class="<?php echo $done && (int) $m->winner_id === (int) $m->player2_id ? 'sdt-fe-bm-w' : ''; ?>">
				<?php echo esc_html( $players[ $m->player2_id ] ?? '–' ); ?>
			</div>
			<?php if ( $score !== '' ) : ?>
				<div class="sdt-fe-bm-score"><?php echo esc_html( $score ); ?></div>
			<?php endif; ?>
		</div>
		<?php
	}
}
