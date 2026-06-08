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

		if ( ! empty( $_GET['action'] ) && $_GET['action'] === 'export' && ! empty( $_GET['id'] ) ) {
			check_admin_referer( 'sdt_export' );
			self::export_json( (int) $_GET['id'] );
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

		<div class="sdt-testtools">
			<strong>Test-Tools:</strong>
			<button type="button" class="button sdt-demo">30 Demo-Spieler anlegen</button>
			<button type="button" class="button sdt-delete-all">Alle Spieler löschen</button>
		</div>

		<?php $regs = SDT_DB::get_unimported_registrations( $t->id ); ?>

		<div class="sdt-setup<?php echo ! empty( $regs ) ? ' sdt-setup-with-regs' : ''; ?>">

			<?php if ( ! empty( $regs ) ) : ?>
				<div class="sdt-registrations">
					<h3>Aus Anmeldungen (<?php echo count( $regs ); ?>)</h3>
					<p class="description">Per Drag in eine Gruppe oder „Unzugeordnet" ziehen — wird dann als Spieler übernommen.</p>
					<ul class="sdt-droplist sdt-connected sdt-reg-list" data-group="__reg__">
						<?php foreach ( $regs as $r ) : ?>
							<li class="sdt-reg-item" data-rid="<?php echo (int) $r->id; ?>">
								<span class="sdt-handle">⋮⋮</span>
								<span class="sdt-name"><?php echo esc_html( trim( $r->first_name . ' ' . $r->last_name ) ); ?></span>
								<?php if ( $r->status === 'waitlist' ) : ?>
									<span class="sdt-reg-tag" title="Warteliste">W</span>
								<?php endif; ?>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>

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
		$matches      = SDT_DB::get_matches( $t->id );
		$group_matches = array_filter( $matches, function ( $m ) { return $m->phase === 'group'; } );
		$group_done   = SDT_Scheduler::is_group_phase_done( $t->id );
		$has_brackets = SDT_Scheduler::has_bracket_matches( $t->id );
		if ( $has_brackets ) {
			$next  = SDT_Scheduler::next_bracket_matches( $t->id, $t->tables_count );
			$queue = SDT_Scheduler::upcoming_bracket_after( $t->id, array_map( function ( $m ) { return (int) $m->id; }, $next ), 999 );
		} else {
			$next       = SDT_Scheduler::next_matches( $t->id, $t->tables_count );
			$queue_size = max( 0, 6 - count( $next ) );
			$queue      = SDT_Scheduler::upcoming_after( $t->id, array_map( function ( $m ) { return (int) $m->id; }, $next ), $queue_size );
		}
		$standings = SDT_Scheduler::standings( $t->id );
		$players   = array();
		foreach ( SDT_DB::get_players( $t->id ) as $p ) {
			$players[ $p->id ] = $p->name;
		}

		$done    = count( array_filter( $group_matches, function ( $m ) { return $m->status === 'done'; } ) );
		$total   = count( $group_matches );
		$has_gold        = (bool) array_filter( $matches, function ( $m ) { return $m->phase === 'gold'; } );
		$has_silber      = (bool) array_filter( $matches, function ( $m ) { return $m->phase === 'silber'; } );
		$has_gold_trost  = (bool) array_filter( $matches, function ( $m ) { return $m->phase === 'gold'   && ( $m->bracket_side ?: 'winner' ) === 'loser'; } );
		$has_silber_trost = (bool) array_filter( $matches, function ( $m ) { return $m->phase === 'silber' && ( $m->bracket_side ?: 'winner' ) === 'loser'; } );
		?>

		<nav class="sdt-admin-nav">
			<?php if ( ! empty( $next ) ) : ?><a href="#sdt-a-next">⏭ Nächste Spiele</a><?php endif; ?>
			<?php if ( $has_gold ) : ?><a href="#sdt-a-gold-winner">🏆 Goldrunde</a><?php endif; ?>
			<?php if ( $has_gold_trost ) : ?><a href="#sdt-a-gold-loser">🥉 Trostrunde Gold</a><?php endif; ?>
			<?php if ( $has_silber ) : ?><a href="#sdt-a-silber-winner">🥈 Silberrunde</a><?php endif; ?>
			<?php if ( $has_silber_trost ) : ?><a href="#sdt-a-silber-loser">🥉 Trostrunde Silber</a><?php endif; ?>
			<a href="#sdt-a-vorrunden">📋 Vorrunden</a>
			<a href="#sdt-a-alle">📜 Alle Vorrunden-Spiele</a>
		</nav>

		<div class="sdt-testtools">
			<strong>Test-Tools:</strong>
			<?php if ( ! $group_done ) : ?>
				<button type="button" class="button sdt-simulate-groups">🎲 Vorrunde simulieren (Zufalls-Ergebnisse)</button>
			<?php endif; ?>
			<?php if ( $has_brackets ) : ?>
				<button type="button" class="button sdt-simulate-brackets">🎲 Brackets simulieren (Zufalls-Ergebnisse)</button>
				<button type="button" class="button sdt-reset-brackets">Brackets zurücksetzen</button>
			<?php endif; ?>
		</div>

		<?php if ( $group_done && ! $has_brackets ) : ?>
			<div class="notice notice-success inline" style="margin:14px 0; padding:10px 14px;">
				<p><strong>Vorrunde abgeschlossen!</strong> Jetzt die Brackets generieren, dann geht's in die KO-Phase.</p>
				<p><button type="button" class="button button-primary sdt-generate-brackets">🏆 Gold- und Silberrunde generieren</button></p>
			</div>
		<?php endif; ?>

		<h2 id="sdt-a-next">Nächste Spiele (<?php echo count( $next ); ?>/<?php echo (int) $t->tables_count; ?> Tische)</h2>
		<?php if ( empty( $next ) ) : ?>
			<p>
				<?php
				if ( $has_brackets ) {
					echo 'Keine spielbaren Bracket-Matches — alles wartet auf vorausgehende Spiele oder ist abgeschlossen.';
				} else {
					echo $done === $total ? '<strong>Alle Vorrunden-Spiele abgeschlossen!</strong>' : 'Keine spielbaren Matches.';
				}
				?>
			</p>
		<?php else : ?>
			<table class="wp-list-table widefat striped sdt-next">
				<thead><tr><th>Bereich</th><th>Spieler 1</th><th></th><th>Spieler 2</th><th>Aktion</th></tr></thead>
				<tbody>
				<?php foreach ( $next as $m ) : ?>
					<tr data-mid="<?php echo (int) $m->id; ?>">
						<td><strong><?php echo esc_html( SDT_Scheduler::match_label( $m, $matches ) ); ?></strong></td>
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

		<?php if ( ! empty( $queue ) ) : ?>
			<h3>Danach in der Warteschlange</h3>
			<table class="wp-list-table widefat striped sdt-queue">
				<thead><tr><th>Bereich</th><th>Spieler 1</th><th></th><th>Spieler 2</th></tr></thead>
				<tbody>
				<?php foreach ( $queue as $m ) : ?>
					<tr>
						<td><strong><?php echo esc_html( SDT_Scheduler::match_label( $m, $matches ) ); ?></strong></td>
						<td><?php echo esc_html( $players[ $m->player1_id ] ?? '?' ); ?></td>
						<td>vs.</td>
						<td><?php echo esc_html( $players[ $m->player2_id ] ?? '?' ); ?></td>
					</tr>
				<?php endforeach; ?>
				</tbody>
			</table>
		<?php endif; ?>

		<?php if ( $has_brackets ) : self::render_brackets( $t, $matches, $players ); endif; ?>

		<h2 id="sdt-a-vorrunden">Vorrunden-Tabellen <span class="description">(<?php echo (int) $done; ?>/<?php echo (int) $total; ?> Spiele abgeschlossen)</span></h2>
		<div class="sdt-standings">
			<?php foreach ( $standings as $label => $rows ) : ?>
				<div class="sdt-standing-group">
					<h3>Gruppe <?php echo esc_html( $label ); ?></h3>
					<table class="wp-list-table widefat striped">
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

		<h2 id="sdt-a-alle" style="margin-top:30px;">Alle Vorrunden-Spiele</h2>
		<table class="wp-list-table widefat striped sdt-all">
			<thead><tr><th>Gruppe</th><th>Runde</th><th>Begegnung</th><th>Ergebnis</th><th>Aktion</th></tr></thead>
			<tbody>
			<?php foreach ( $group_matches as $m ) : ?>
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
						<?php if ( $m->status === 'done' && ! $has_brackets ) : ?>
							<button class="button button-small sdt-reset">Zurücksetzen</button>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>

		<p style="margin-top:24px;">
			<a href="<?php echo esc_url( wp_nonce_url( admin_url( 'admin.php?page=' . self::PAGE . '&action=export&id=' . (int) $t->id ), 'sdt_export' ) ); ?>" class="button">💾 Backup herunterladen (JSON)</a>
			<span class="description" style="margin-left:8px;">Aktueller Stand zum Sichern.</span>
		</p>

		<form method="post" style="margin-top:30px;" onsubmit="return confirm('Wirklich zurück auf Setup? Alle Spiele werden gelöscht!');">
			<?php wp_nonce_field( 'sdt_reset' ); ?>
			<input type="hidden" name="id" value="<?php echo (int) $t->id; ?>">
			<button type="submit" name="sdt_reset_to_setup" class="button">Zurück zum Setup</button>
		</form>
		<?php
	}

	private static function render_brackets( $t, $matches, $players ) {
		// Gruppieren nach phase und bracket_side
		$brackets = array(
			'gold'   => array( 'winner' => array(), 'loser' => array(), 'final' => null ),
			'silber' => array( 'winner' => array(), 'loser' => array(), 'final' => null ),
		);
		foreach ( $matches as $m ) {
			if ( $m->phase !== 'gold' && $m->phase !== 'silber' ) continue;
			$side = $m->bracket_side ?: 'winner'; // Fallback für Alt-Daten
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
			<h2 id="sdt-a-<?php echo esc_attr( $phase ); ?>" style="margin-top:30px;"><?php echo esc_html( $labels[ $phase ] ); ?></h2>
			<?php
			self::render_final_summary( $phase, $data, $players );
			self::render_subbracket( $data['winner'], 'winner', $phase, true, $players );
			if ( ! empty( $data['loser'] ) ) {
				self::render_subbracket( $data['loser'], 'loser', $phase, false, $players );
			}
			if ( $data['final'] ) {
				self::render_grand_final( $data['final'], $phase, $players );
			}
		}
	}

	private static function render_final_summary( $phase, $data, $players ) {
		$grand = $data['final'];
		// Fallback für Alt-Daten ohne bracket_side: letztes Winner-Bracket-Match als Finale
		if ( ! $grand && ! empty( $data['winner'] ) ) {
			$last_w = max( array_keys( $data['winner'] ) );
			$grand  = $data['winner'][ $last_w ][0] ?? null;
		}
		if ( ! $grand || $grand->status !== 'done' || ! $grand->winner_id ) return;
		$first  = $players[ $grand->winner_id ] ?? '?';
		$second_id = (int) $grand->winner_id === (int) $grand->player1_id ? $grand->player2_id : $grand->player1_id;
		$second = $players[ $second_id ] ?? '?';
		// Platz 3 = Sieger des letzten Trostrunden-Matches (Spiel um Platz 3)
		$third = '';
		if ( ! empty( $data['loser'] ) ) {
			$last_round_num = max( array_keys( $data['loser'] ) );
			$l_final = $data['loser'][ $last_round_num ][0] ?? null;
			if ( $l_final && $l_final->status === 'done' && $l_final->winner_id ) {
				$third = $players[ (int) $l_final->winner_id ] ?? '';
			}
		}
		?>
		<div class="sdt-final-result sdt-final-<?php echo esc_attr( $phase ); ?>">
			<strong>🥇 1.</strong> <?php echo esc_html( $first ); ?>
			&nbsp;·&nbsp;<strong>🥈 2.</strong> <?php echo esc_html( $second ); ?>
			<?php if ( $third ) : ?>&nbsp;·&nbsp;<strong>🥉 3.</strong> <?php echo esc_html( $third ); endif; ?>
		</div>
		<?php
	}

	private static function render_subbracket( $rounds_data, $side, $phase, $is_winner, $players ) {
		ksort( $rounds_data );
		$rounds_count = count( $rounds_data );
		$title_top    = $is_winner ? 'Hauptrunde' : 'Trostrunde';
		$cls_extra    = $is_winner ? 'sdt-bracket-winner' : 'sdt-bracket-loser';
		$anchor       = 'sdt-a-' . $phase . '-' . ( $is_winner ? 'winner' : 'loser' );
		?>
		<h3 id="<?php echo esc_attr( $anchor ); ?>" style="margin-top:18px;"><?php echo esc_html( $title_top ); ?></h3>
		<div class="sdt-bracket sdt-bracket-<?php echo esc_attr( $phase ); ?> <?php echo esc_attr( $cls_extra ); ?>">
			<?php foreach ( $rounds_data as $round_num => $matches_in_round ) :
				ksort( $matches_in_round );
				if ( $is_winner ) {
					$round_title = $round_num === $rounds_count ? 'Hauptrunden-Finale (Plätze 1 + 2)' : ( $round_num === $rounds_count - 1 ? 'Halbfinale' : 'Runde ' . $round_num );
				} else {
					$round_title = $round_num === $rounds_count ? 'Spiel um Platz 3' : 'Trostrunde ' . $round_num;
				}
				?>
				<div class="sdt-bracket-round">
					<div class="sdt-bracket-round-title"><?php echo esc_html( $round_title ); ?></div>
					<?php foreach ( $matches_in_round as $m ) {
						self::render_bracket_match( $m, $players );
					} ?>
				</div>
			<?php endforeach; ?>
		</div>
		<?php
	}

	private static function render_grand_final( $m, $phase, $players ) {
		?>
		<h3 style="margin-top:18px;">Finale</h3>
		<div class="sdt-bracket sdt-bracket-<?php echo esc_attr( $phase ); ?> sdt-bracket-final">
			<div class="sdt-bracket-round">
				<div class="sdt-bracket-round-title">Finale (endgültig)</div>
				<?php self::render_bracket_match( $m, $players ); ?>
			</div>
		</div>
		<?php
	}

	private static function render_bracket_match( $m, $players ) {
		$p1_id = (int) $m->player1_id;
		$p2_id = (int) $m->player2_id;
		$ready = $m->status === 'pending' && $p1_id > 0 && $p2_id > 0;
		$done  = $m->status === 'done' && $m->winner_id;
		$cls   = $done ? 'done' : ( $ready ? 'ready' : 'waiting' );
		$p1    = $p1_id ? ( $players[ $p1_id ] ?? '' ) : '';
		$p2    = $p2_id ? ( $players[ $p2_id ] ?? '' ) : '';
		?>
		<div class="sdt-bracket-match sdt-bm-<?php echo esc_attr( $cls ); ?>" data-mid="<?php echo (int) $m->id; ?>">
			<div class="sdt-bm-slot <?php echo ( $done && (int) $m->winner_id === $p1_id ) ? 'sdt-bm-winner' : ''; ?>">
				<span class="sdt-bm-name"><?php echo esc_html( $p1 ?: '–' ); ?></span>
				<?php if ( $ready ) : ?>
					<button class="button button-small sdt-win" data-winner="<?php echo $p1_id; ?>">Sieg</button>
				<?php endif; ?>
			</div>
			<div class="sdt-bm-slot <?php echo ( $done && (int) $m->winner_id === $p2_id ) ? 'sdt-bm-winner' : ''; ?>">
				<span class="sdt-bm-name"><?php echo esc_html( $p2 ?: '–' ); ?></span>
				<?php if ( $ready ) : ?>
					<button class="button button-small sdt-win" data-winner="<?php echo $p2_id; ?>">Sieg</button>
				<?php endif; ?>
			</div>
			<?php if ( $done ) : ?>
				<button class="button button-small sdt-reset sdt-bm-reset">↻</button>
			<?php endif; ?>
		</div>
		<?php
	}

	private static function export_json( $tid ) {
		$t       = SDT_DB::get_tournament( $tid );
		$players = SDT_DB::get_players( $tid );
		$matches = SDT_DB::get_matches( $tid );
		$data = array(
			'export_version' => 1,
			'exported_at'    => current_time( 'mysql' ),
			'tournament'     => $t,
			'players'        => $players,
			'matches'        => $matches,
		);
		$json = wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE );
		$file = 'turnier-' . (int) $tid . '-' . date( 'Y-m-d-His' ) . '.json';
		nocache_headers();
		header( 'Content-Type: application/json; charset=UTF-8' );
		header( 'Content-Disposition: attachment; filename="' . $file . '"' );
		echo $json;
		exit;
	}

	private static function status_label( $s ) {
		return array(
			'setup'    => 'Setup',
			'running'  => 'Läuft',
			'finished' => 'Abgeschlossen',
		)[ $s ] ?? $s;
	}
}
