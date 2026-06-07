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

		$matches   = SDT_DB::get_matches( $t->id );
		$next      = SDT_Scheduler::next_matches( $t->id, $t->tables_count );
		$standings = SDT_Scheduler::standings( $t->id );
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

			<h3>Tabellen</h3>
			<div class="sdt-fe-standings">
			<?php foreach ( $standings as $label => $rows ) : ?>
				<div class="sdt-fe-group">
					<h4>Gruppe <?php echo esc_html( $label ); ?></h4>
					<table class="sdt-fe-table">
						<thead><tr><th></th><th>Spieler</th><th>Sp</th><th>S</th><th>N</th></tr></thead>
						<tbody>
						<?php foreach ( $rows as $i => $r ) : ?>
							<tr class="sdt-q-<?php echo (int) $r['qualified']; ?>">
								<td><?php
									if ( $r['qualified'] === 1 ) echo '🥇';
									elseif ( $r['qualified'] === 2 ) echo '🥈';
									else echo (int) ( $i + 1 ) . '.';
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
}
