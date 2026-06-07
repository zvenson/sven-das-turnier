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
		$atts = shortcode_atts( array( 'id' => 0 ), $atts );

		$t = $atts['id']
			? SDT_DB::get_tournament( (int) $atts['id'] )
			: SDT_DB::latest_active_tournament();

		if ( ! $t ) {
			return '<p class="sdt-fe-empty">Aktuell läuft kein Turnier.</p>';
		}

		$matches    = SDT_DB::get_matches( $t->id );
		$next       = SDT_Scheduler::next_matches( $t->id, $t->tables_count );
		$queue_size = max( 0, 6 - count( $next ) );
		$queue      = SDT_Scheduler::upcoming_after( $t->id, array_map( function ( $m ) { return (int) $m->id; }, $next ), $queue_size );
		$standings  = SDT_Scheduler::standings( $t->id );
		$players   = array();
		foreach ( SDT_DB::get_players( $t->id ) as $p ) {
			$players[ $p->id ] = $p->name;
		}

		ob_start();
		?>
		<div class="sdt-fe">
			<h2 class="sdt-fe-title"><?php echo esc_html( $t->name ); ?></h2>

			<?php if ( ! empty( $next ) ) : ?>
				<h3>Nächste Spiele</h3>
				<table class="sdt-fe-table sdt-fe-next">
					<thead><tr><th>Gruppe</th><th>Spieler 1</th><th></th><th>Spieler 2</th></tr></thead>
					<tbody>
					<?php foreach ( $next as $m ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $m->group_label ); ?></strong></td>
							<td><?php echo esc_html( $players[ $m->player1_id ] ?? '?' ); ?></td>
							<td class="vs">vs.</td>
							<td><?php echo esc_html( $players[ $m->player2_id ] ?? '?' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php if ( ! empty( $queue ) ) : ?>
				<h4 style="margin-top:0.6em;">Danach</h4>
				<table class="sdt-fe-table sdt-fe-queue">
					<thead><tr><th>Gruppe</th><th>Spieler 1</th><th></th><th>Spieler 2</th></tr></thead>
					<tbody>
					<?php foreach ( $queue as $m ) : ?>
						<tr>
							<td><strong><?php echo esc_html( $m->group_label ); ?></strong></td>
							<td><?php echo esc_html( $players[ $m->player1_id ] ?? '?' ); ?></td>
							<td class="vs">vs.</td>
							<td><?php echo esc_html( $players[ $m->player2_id ] ?? '?' ); ?></td>
						</tr>
					<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>

			<?php
			$bracket_matches = array_filter( $matches, function ( $m ) { return $m->phase !== 'group'; } );
			if ( ! empty( $bracket_matches ) ) :
				self::render_brackets_frontend( $bracket_matches, $players );
			endif;
			?>

			<h3>Vorrunden-Tabellen</h3>
			<div class="sdt-fe-standings">
			<?php foreach ( $standings as $label => $rows ) : ?>
				<div class="sdt-fe-group">
					<h4>Gruppe <?php echo esc_html( $label ); ?></h4>
					<table class="sdt-fe-table">
						<thead><tr><th></th><th>Spieler</th><th>Sp</th><th>S</th><th>N</th></tr></thead>
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

	private static function render_brackets_frontend( $matches, $players ) {
		$brackets = array( 'gold' => array(), 'silber' => array() );
		foreach ( $matches as $m ) {
			if ( isset( $brackets[ $m->phase ] ) ) {
				$brackets[ $m->phase ][ (int) $m->bracket_round ][ (int) $m->bracket_position ] = $m;
			}
		}
		$labels = array(
			'gold'   => '🏆 Goldrunde (Plätze 1 + 2)',
			'silber' => '🥈 Silberrunde (ab Platz 3)',
		);
		foreach ( $brackets as $phase => $rounds_data ) {
			if ( empty( $rounds_data ) ) continue;
			ksort( $rounds_data );
			$rounds_count = count( $rounds_data );
			$last_round   = max( array_keys( $rounds_data ) );
			$final_match  = $rounds_data[ $last_round ][0] ?? null;
			?>
			<h3><?php echo esc_html( $labels[ $phase ] ); ?></h3>
			<?php if ( $final_match && $final_match->status === 'done' && $final_match->winner_id ) :
				$loser_id   = (int) $final_match->winner_id === (int) $final_match->player1_id ? $final_match->player2_id : $final_match->player1_id;
				?>
				<p class="sdt-fe-final">
					<strong>🥇 <?php echo esc_html( $players[ $final_match->winner_id ] ?? '?' ); ?></strong>
					&nbsp;·&nbsp;
					2. <?php echo esc_html( $players[ $loser_id ] ?? '?' ); ?>
				</p>
			<?php endif; ?>
			<div class="sdt-fe-bracket sdt-fe-bracket-<?php echo esc_attr( $phase ); ?>">
				<?php foreach ( $rounds_data as $round_num => $matches_in_round ) :
					ksort( $matches_in_round );
					$title = $round_num === $rounds_count ? 'Finale' : ( $round_num === $rounds_count - 1 ? 'Halbfinale' : 'Runde ' . $round_num );
					?>
					<div class="sdt-fe-bracket-round">
						<div class="sdt-fe-bracket-title"><?php echo esc_html( $title ); ?></div>
						<?php foreach ( $matches_in_round as $m ) :
							$done = $m->status === 'done' && $m->winner_id;
							?>
							<div class="sdt-fe-bracket-match">
								<div class="<?php echo $done && (int) $m->winner_id === (int) $m->player1_id ? 'sdt-fe-bm-w' : ''; ?>">
									<?php echo esc_html( $players[ $m->player1_id ] ?? '–' ); ?>
								</div>
								<div class="<?php echo $done && (int) $m->winner_id === (int) $m->player2_id ? 'sdt-fe-bm-w' : ''; ?>">
									<?php echo esc_html( $players[ $m->player2_id ] ?? '–' ); ?>
								</div>
							</div>
						<?php endforeach; ?>
					</div>
				<?php endforeach; ?>
			</div>
			<?php
		}
	}
}
