<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SDT_Admin {

	const PAGE = 'sdt-tournaments';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_init', array( __CLASS__, 'handle_post' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
	}

	public static function menu() {
		add_menu_page(
			'Turnier',
			'Turnier',
			'manage_options',
			self::PAGE,
			array( __CLASS__, 'render' ),
			'dashicons-awards',
			31
		);
	}

	public static function assets( $hook ) {
		if ( strpos( (string) $hook, self::PAGE ) === false ) {
			return;
		}
		wp_enqueue_script( 'jquery-ui-sortable' );
		wp_enqueue_style( 'sdt-admin', SDT_URL . 'assets/admin.css', array(), SDT_VERSION );
		wp_enqueue_script( 'sdt-admin', SDT_URL . 'assets/admin.js', array( 'jquery', 'jquery-ui-sortable' ), SDT_VERSION, true );
		wp_localize_script( 'sdt-admin', 'SDT', array(
			'ajaxurl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'sdt_ajax' ),
		) );
	}

	public static function handle_post() {
		if ( ! current_user_can( 'manage_options' ) ) return;
		if ( empty( $_GET['page'] ) || $_GET['page'] !== self::PAGE ) return;

		if ( isset( $_POST['sdt_create'] ) && check_admin_referer( 'sdt_create' ) ) {
			$name        = sanitize_text_field( wp_unslash( $_POST['name'] ?? 'Turnier' ) );
			$num_groups  = max( 1, min( 12, (int) ( $_POST['num_groups'] ?? 4 ) ) );
			$tables      = max( 1, min( 20, (int) ( $_POST['tables_count'] ?? 2 ) ) );
			$id          = SDT_DB::create_tournament( $name, $num_groups, $tables );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&action=edit&id=' . $id ) );
			exit;
		}

		if ( isset( $_POST['sdt_update_settings'] ) && check_admin_referer( 'sdt_update' ) ) {
			$id          = (int) ( $_POST['id'] ?? 0 );
			$name        = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
			$num_groups  = max( 1, min( 12, (int) ( $_POST['num_groups'] ?? 4 ) ) );
			$tables      = max( 1, min( 20, (int) ( $_POST['tables_count'] ?? 2 ) ) );
			SDT_DB::update_tournament( $id, array(
				'name'         => $name,
				'num_groups'   => $num_groups,
				'tables_count' => $tables,
			) );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&action=edit&id=' . $id . '&updated=1' ) );
			exit;
		}

		if ( isset( $_POST['sdt_delete'] ) && check_admin_referer( 'sdt_delete' ) ) {
			SDT_DB::delete_tournament( (int) $_POST['id'] );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE ) );
			exit;
		}

		if ( isset( $_POST['sdt_reset_to_setup'] ) && check_admin_referer( 'sdt_reset' ) ) {
			$id = (int) $_POST['id'];
			SDT_DB::delete_matches( $id );
			SDT_DB::update_tournament( $id, array( 'status' => 'setup' ) );
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE . '&action=edit&id=' . $id ) );
			exit;
		}
	}

	public static function render() {
		$action = isset( $_GET['action'] ) ? sanitize_key( $_GET['action'] ) : '';
		if ( $action === 'new' ) {
			self::render_new();
			return;
		}
		if ( $action === 'edit' && isset( $_GET['id'] ) ) {
			self::render_edit( (int) $_GET['id'] );
			return;
		}
		self::render_list();
	}

	private static function render_list() {
		$rows = SDT_DB::all_tournaments();
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline">Turniere</h1>
			<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&action=new' ) ); ?>" class="page-title-action">Neues Turnier</a>
			<hr class="wp-header-end">
			<table class="wp-list-table widefat fixed striped">
				<thead><tr>
					<th>Name</th><th>Status</th><th>Gruppen</th><th>Tische</th><th>Datum</th><th>Aktion</th>
				</tr></thead>
				<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6">Noch keine Turniere.</td></tr>
				<?php else : foreach ( $rows as $t ) : ?>
					<tr>
						<td><strong><a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE . '&action=edit&id=' . $t->id ) ); ?>"><?php echo esc_html( $t->name ); ?></a></strong></td>
						<td><?php echo esc_html( self::status_label( $t->status ) ); ?></td>
						<td><?php echo (int) $t->num_groups; ?></td>
						<td><?php echo (int) $t->tables_count; ?></td>
						<td><?php echo esc_html( mysql2date( 'd.m.Y H:i', $t->created_at ) ); ?></td>
						<td>
							<form method="post" style="display:inline" onsubmit="return confirm('Turnier wirklich löschen?');">
								<?php wp_nonce_field( 'sdt_delete' ); ?>
								<input type="hidden" name="id" value="<?php echo (int) $t->id; ?>">
								<button type="submit" name="sdt_delete" class="button button-small button-link-delete">Löschen</button>
							</form>
						</td>
					</tr>
				<?php endforeach; endif; ?>
				</tbody>
			</table>
			<p class="description" style="margin-top:1.5em;">
				Frontend-Shortcode: <code>[sdt_turnier]</code> zeigt das aktuelle Turnier, oder <code>[sdt_turnier id="3"]</code> für ein bestimmtes.
			</p>
		</div>
		<?php
	}

	private static function render_new() {
		?>
		<div class="wrap">
			<h1>Neues Turnier anlegen</h1>
			<form method="post">
				<?php wp_nonce_field( 'sdt_create' ); ?>
				<table class="form-table">
					<tr>
						<th><label for="name">Name</label></th>
						<td><input id="name" type="text" name="name" required class="regular-text" value="Weller Open 2026"></td>
					</tr>
					<tr>
						<th><label for="num_groups">Anzahl Gruppen</label></th>
						<td><input id="num_groups" type="number" name="num_groups" min="1" max="12" value="4" class="small-text"> <span class="description">(typisch 4–7)</span></td>
					</tr>
					<tr>
						<th><label for="tables_count">Anzahl Tische (parallele Spiele)</label></th>
						<td><input id="tables_count" type="number" name="tables_count" min="1" max="20" value="2" class="small-text"></td>
					</tr>
				</table>
				<p class="submit">
					<button type="submit" name="sdt_create" class="button button-primary">Anlegen</button>
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ); ?>" class="button">Abbrechen</a>
				</p>
			</form>
		</div>
		<?php
	}

	private static function render_edit( $id ) {
		$t = SDT_DB::get_tournament( $id );
		if ( ! $t ) {
			echo '<div class="wrap"><h1>Turnier nicht gefunden</h1></div>';
			return;
		}
		?>
		<div class="wrap sdt-wrap" data-tid="<?php echo (int) $t->id; ?>">
			<h1>
				<?php echo esc_html( $t->name ); ?>
				<span class="sdt-status sdt-status-<?php echo esc_attr( $t->status ); ?>"><?php echo esc_html( self::status_label( $t->status ) ); ?></span>
			</h1>

			<?php if ( isset( $_GET['updated'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Gespeichert.</p></div>
			<?php endif; ?>

			<details class="sdt-settings" <?php echo $t->status === 'setup' ? 'open' : ''; ?>>
				<summary>Einstellungen</summary>
				<form method="post" style="padding:12px 0;">
					<?php wp_nonce_field( 'sdt_update' ); ?>
					<input type="hidden" name="id" value="<?php echo (int) $t->id; ?>">
					<label>Name <input type="text" name="name" value="<?php echo esc_attr( $t->name ); ?>"></label>
					&nbsp;
					<label>Gruppen <input type="number" name="num_groups" min="1" max="12" value="<?php echo (int) $t->num_groups; ?>" class="small-text"></label>
					&nbsp;
					<label>Tische <input type="number" name="tables_count" min="1" max="20" value="<?php echo (int) $t->tables_count; ?>" class="small-text"></label>
					&nbsp;
					<button type="submit" name="sdt_update_settings" class="button">Speichern</button>
				</form>
			</details>

			<?php
			if ( $t->status === 'setup' ) {
				self::render_setup( $t );
			} else {
				self::render_running( $t );
			}
			?>
		</div>
		<?php
	}

	private static function render_setup( $t ) {
		$players = SDT_DB::get_players( $t->id );
		$by_group = array( '' => array() );
		for ( $i = 0; $i < (int) $t->num_groups; $i++ ) {
			$by_group[ chr( 65 + $i ) ] = array();
		}
		foreach ( $players as $p ) {
			$key = $p->group_label ?: '';
			if ( ! isset( $by_group[ $key ] ) ) {
				$by_group[ $key ] = array();
			}
			$by_group[ $key ][] = $p;
		}
		?>
		<h2>Spieler &amp; Gruppen</h2>
		<p class="description">Spieler unten hinzufügen, dann per Drag-and-Drop in Gruppen ziehen. Max. 4 Spieler pro Gruppe ist üblich.</p>

		<div class="sdt-setup">
			<div class="sdt-unassigned">
				<h3>Unzugeordnet (<span class="sdt-count"><?php echo count( $by_group[''] ); ?></span>)</h3>
				<ul class="sdt-droplist sdt-connected" data-group="">
					<?php foreach ( $by_group[''] as $p ) : ?>
						<li class="sdt-player" data-pid="<?php echo (int) $p->id; ?>">
							<span class="sdt-handle">⋮⋮</span>
							<span class="sdt-name"><?php echo esc_html( $p->name ); ?></span>
							<button type="button" class="sdt-del-player" title="Spieler löschen">×</button>
						</li>
					<?php endforeach; ?>
				</ul>
				<form class="sdt-add-player-form">
					<input type="text" placeholder="Neuer Spieler-Name" class="sdt-new-name">
					<button type="submit" class="button">Hinzufügen</button>
				</form>
			</div>

			<div class="sdt-groups">
				<?php foreach ( $by_group as $label => $list ) : if ( $label === '' ) continue; ?>
					<div class="sdt-group">
						<h3>Gruppe <?php echo esc_html( $label ); ?> (<span class="sdt-count"><?php echo count( $list ); ?></span>)</h3>
						<ul class="sdt-droplist sdt-connected" data-group="<?php echo esc_attr( $label ); ?>">
							<?php foreach ( $list as $p ) : ?>
								<li class="sdt-player" data-pid="<?php echo (int) $p->id; ?>">
									<span class="sdt-handle">⋮⋮</span>
									<span class="sdt-name"><?php echo esc_html( $p->name ); ?></span>
									<button type="button" class="sdt-del-player" title="Spieler löschen">×</button>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endforeach; ?>
			</div>
		</div>

		<p style="margin-top:24px;">
			<button class="button button-primary button-hero sdt-generate" <?php disabled( count( $players ) < 2 ); ?>>Plan generieren &amp; Turnier starten</button>
		</p>
		<?php
	}

	private static function render_running( $t ) {
		$matches   = SDT_DB::get_matches( $t->id );
		$next      = SDT_Scheduler::next_matches( $t->id, $t->tables_count );
		$standings = SDT_Scheduler::standings( $t->id );
		$players   = array();
		foreach ( SDT_DB::get_players( $t->id ) as $p ) {
			$players[ $p->id ] = $p->name;
		}

		$done    = count( array_filter( $matches, function ( $m ) { return $m->status === 'done'; } ) );
		$total   = count( $matches );
		?>
		<h2>Nächste Spiele (<?php echo count( $next ); ?>/<?php echo (int) $t->tables_count; ?> Tische)</h2>
		<?php if ( empty( $next ) ) : ?>
			<p><?php echo $done === $total ? '<strong>Alle Spiele abgeschlossen!</strong>' : 'Keine spielbaren Matches.'; ?></p>
		<?php else : ?>
			<table class="wp-list-table widefat striped sdt-next">
				<thead><tr><th>Gruppe</th><th>Runde</th><th>Spieler 1</th><th></th><th>Spieler 2</th><th>Aktion</th></tr></thead>
				<tbody>
				<?php foreach ( $next as $m ) : ?>
					<tr data-mid="<?php echo (int) $m->id; ?>">
						<td><strong><?php echo esc_html( $m->group_label ); ?></strong></td>
						<td><?php echo (int) $m->round; ?></td>
						<td><?php echo esc_html( $players[ $m->player1_id ] ?? '?' ); ?></td>
						<td>vs.</td>
						<td><?php echo esc_html( $players[ $m->player2_id ] ?? '?' ); ?></td>
						<td>
							<button class="button button-primary sdt-win" data-winner="<?php echo (int) $m->player1_id; ?>">Sieg <?php echo esc_html( $players[ $m->player1_id ] ?? '?' ); ?></button>
							<button class="button button-primary sdt-win" data-winner="<?php echo (int) $m->player2_id; ?>">Sieg <?php echo esc_html( $players[ $m->player2_id ] ?? '?' ); ?></button>
						</td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<h2>Tabellen-Stand <span class="description">(<?php echo (int) $done; ?>/<?php echo (int) $total; ?> Spiele abgeschlossen)</span></h2>
		<div class="sdt-standings">
			<?php foreach ( $standings as $label => $rows ) : ?>
				<div class="sdt-standing-group">
					<h3>Gruppe <?php echo esc_html( $label ); ?></h3>
					<table class="wp-list-table widefat striped">
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

		<h2 style="margin-top:30px;">Alle Spiele</h2>
		<table class="wp-list-table widefat striped sdt-all">
			<thead><tr><th>Gruppe</th><th>Runde</th><th>Begegnung</th><th>Ergebnis</th><th>Aktion</th></tr></thead>
			<tbody>
			<?php foreach ( $matches as $m ) : ?>
				<tr data-mid="<?php echo (int) $m->id; ?>">
					<td><strong><?php echo esc_html( $m->group_label ); ?></strong></td>
					<td><?php echo (int) $m->round; ?></td>
					<td>
						<?php echo esc_html( $players[ $m->player1_id ] ?? '?' ); ?>
						vs.
						<?php echo esc_html( $players[ $m->player2_id ] ?? '?' ); ?>
					</td>
					<td>
						<?php if ( $m->status === 'done' && $m->winner_id ) : ?>
							🏆 <strong><?php echo esc_html( $players[ $m->winner_id ] ?? '?' ); ?></strong>
						<?php else : ?>
							<em>offen</em>
						<?php endif; ?>
					</td>
					<td>
						<?php if ( $m->status === 'done' ) : ?>
							<button class="button button-small sdt-reset">Zurücksetzen</button>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<form method="post" style="margin-top:30px;" onsubmit="return confirm('Wirklich zurück auf Setup? Alle Spiele werden gelöscht!');">
			<?php wp_nonce_field( 'sdt_reset' ); ?>
			<input type="hidden" name="id" value="<?php echo (int) $t->id; ?>">
			<button type="submit" name="sdt_reset_to_setup" class="button">Zurück zum Setup</button>
		</form>
		<?php
	}

	private static function status_label( $s ) {
		return array(
			'setup'    => 'Setup',
			'running'  => 'Läuft',
			'finished' => 'Abgeschlossen',
		)[ $s ] ?? $s;
	}
}
