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

		// Wenn alle Spiele done → status finished
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
		SDT_DB::set_winner( $mid, 0 );
		wp_send_json_success();
	}
}
