<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SDT_Ajax {

	public static function init() {
		$actions = array(
			'sdt_add_player',
			'sdt_delete_player',
			'sdt_assign_player',
			'sdt_generate_plan',
			'sdt_set_winner',
			'sdt_reset_match',
			'sdt_demo_players',
			'sdt_delete_all_players',
			'sdt_import_registration',
			'sdt_simulate_groups',
			'sdt_simulate_brackets',
			'sdt_generate_brackets',
			'sdt_reset_brackets',
		);
		foreach ( $actions as $a ) {
			add_action( "wp_ajax_$a", array( __CLASS__, $a ) );
		}
	}

	private static function check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'no permission' ), 403 );
		}
		check_ajax_referer( 'sdt_ajax', 'nonce' );
	}

	public static function sdt_add_player() {
		self::check();
		$tid  = (int) ( $_POST['tournament_id'] ?? 0 );
		$name = sanitize_text_field( wp_unslash( $_POST['name'] ?? '' ) );
		if ( $tid <= 0 || $name === '' ) {
			wp_send_json_error( array( 'message' => 'invalid input' ) );
		}
		$pid = SDT_DB::add_player( $tid, $name );
		wp_send_json_success( array( 'id' => $pid, 'name' => $name ) );
	}

	public static function sdt_delete_player() {
		self::check();
		$pid = (int) ( $_POST['player_id'] ?? 0 );
		SDT_DB::delete_player( $pid );
		wp_send_json_success();
	}

	public static function sdt_assign_player() {
		self::check();
		$pid   = (int) ( $_POST['player_id'] ?? 0 );
		$label = sanitize_text_field( wp_unslash( $_POST['group_label'] ?? '' ) );
		$pos   = (int) ( $_POST['position'] ?? 0 );
		if ( $label === '' || $label === 'unassigned' ) {
			$label = '';
		}
		SDT_DB::assign_player( $pid, $label, $pos );
		wp_send_json_success();
	}

	public static function sdt_generate_plan() {
		self::check();
		$tid = (int) ( $_POST['tournament_id'] ?? 0 );
		SDT_Scheduler::generate_matches( $tid );
		SDT_DB::update_tournament( $tid, array( 'status' => 'running' ) );
		wp_send_json_success();
	}

	public static function sdt_set_winner() {
		self::check();
		$mid       = (int) ( $_POST['match_id'] ?? 0 );
		$winner_id = (int) ( $_POST['winner_id'] ?? 0 );
		SDT_DB::set_winner( $mid, $winner_id );
		SDT_Scheduler::advance_winner( $mid );

		// Status nachziehen
		$m = SDT_DB::get_match( $mid );
		if ( $m ) {
			$all      = SDT_DB::get_matches( $m->tournament_id );
			$all_done = ! empty( $all );
			foreach ( $all as $x ) {
				if ( $x->status !== 'done' ) { $all_done = false; break; }
			}
			if ( $all_done ) {
				SDT_DB::update_tournament( $m->tournament_id, array( 'status' => 'finished' ) );
			} else {
				$t = SDT_DB::get_tournament( $m->tournament_id );
				if ( $t && $t->status === 'finished' ) {
					SDT_DB::update_tournament( $m->tournament_id, array( 'status' => 'running' ) );
				}
			}
		}
		wp_send_json_success();
	}

	public static function sdt_reset_match() {
		self::check();
		$mid = (int) ( $_POST['match_id'] ?? 0 );
		$m   = SDT_DB::get_match( $mid );
		SDT_DB::set_winner( $mid, 0 );
		// Bei Bracket-Matches: Downstream-Slots leeren und Bye-Auflösung neu rechnen
		if ( $m && $m->phase !== 'group' ) {
			SDT_Scheduler::reset_bracket_cascade( $mid );
		}
		wp_send_json_success();
	}

	public static function sdt_demo_players() {
		self::check();
		$tid = (int) ( $_POST['tournament_id'] ?? 0 );
		$t   = SDT_DB::get_tournament( $tid );
		if ( ! $t || $t->status !== 'setup' ) {
			wp_send_json_error( array( 'message' => 'nur im Setup-Modus möglich' ) );
		}
		$names = array(
			'Max Müller', 'Lisa Schmidt', 'Tobias Wagner', 'Nina Becker', 'Stefan Hofmann',
			'Anna Bauer', 'Lukas Richter', 'Mia Schulz', 'Jonas Weber', 'Lena Koch',
			'Felix Klein', 'Sophie Wolf', 'Paul Neumann', 'Hannah Vogel', 'Daniel Hartmann',
			'Emma Lange', 'Ben Krüger', 'Lara Schwarz', 'Tim Brandt', 'Mila Werner',
			'Jan Hoffmann', 'Klara Lehmann', 'Finn Köhler', 'Marie Otto', 'Niklas Berger',
			'Laura Frank', 'David Sommer', 'Pia Beck', 'Moritz Engel', 'Emily Sauer',
		);
		foreach ( $names as $n ) {
			SDT_DB::add_player( $tid, $n );
		}
		wp_send_json_success( array( 'count' => count( $names ) ) );
	}

	public static function sdt_import_registration() {
		self::check();
		$tid   = (int) ( $_POST['tournament_id'] ?? 0 );
		$rid   = (int) ( $_POST['registration_id'] ?? 0 );
		$label = sanitize_text_field( wp_unslash( $_POST['group_label'] ?? '' ) );
		$pos   = (int) ( $_POST['position'] ?? 0 );

		global $wpdb;
		$reg = $wpdb->get_row( $wpdb->prepare(
			"SELECT first_name, last_name FROM {$wpdb->prefix}sda_registrations WHERE id = %d",
			$rid
		) );
		if ( ! $reg ) {
			wp_send_json_error( array( 'message' => 'Anmeldung nicht gefunden' ) );
		}

		$name = trim( $reg->first_name . ' ' . $reg->last_name );
		$pid  = SDT_DB::add_player( $tid, $name, $rid );
		if ( $label !== '' ) {
			SDT_DB::assign_player( $pid, $label, $pos );
		}

		wp_send_json_success( array( 'id' => $pid, 'name' => $name ) );
	}

	public static function sdt_simulate_groups() {
		self::check();
		$tid = (int) ( $_POST['tournament_id'] ?? 0 );
		SDT_Scheduler::simulate_group_phase( $tid );
		// Wenn alle Gruppenspiele fertig, Brackets generieren
		if ( SDT_Scheduler::is_group_phase_done( $tid ) && ! SDT_Scheduler::has_bracket_matches( $tid ) ) {
			SDT_Scheduler::generate_brackets( $tid );
		}
		wp_send_json_success();
	}

	public static function sdt_simulate_brackets() {
		self::check();
		$tid = (int) ( $_POST['tournament_id'] ?? 0 );
		SDT_Scheduler::simulate_bracket_phase( $tid );
		wp_send_json_success();
	}

	public static function sdt_generate_brackets() {
		self::check();
		$tid = (int) ( $_POST['tournament_id'] ?? 0 );
		if ( ! SDT_Scheduler::is_group_phase_done( $tid ) ) {
			wp_send_json_error( array( 'message' => 'Vorrunde noch nicht fertig.' ) );
		}
		SDT_Scheduler::generate_brackets( $tid );
		wp_send_json_success();
	}

	public static function sdt_reset_brackets() {
		self::check();
		$tid = (int) ( $_POST['tournament_id'] ?? 0 );
		SDT_DB::delete_bracket_matches( $tid );
		wp_send_json_success();
	}

	public static function sdt_delete_all_players() {
		self::check();
		$tid = (int) ( $_POST['tournament_id'] ?? 0 );
		$t   = SDT_DB::get_tournament( $tid );
		if ( ! $t || $t->status !== 'setup' ) {
			wp_send_json_error( array( 'message' => 'nur im Setup-Modus möglich' ) );
		}
		global $wpdb;
		$wpdb->delete( SDT_DB::t_players(), array( 'tournament_id' => $tid ), array( '%d' ) );
		SDT_DB::delete_matches( $tid );
		wp_send_json_success();
	}
}
