<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class SDT_DB {

	public static function t_tournaments() { global $wpdb; return $wpdb->prefix . 'sdt_tournaments'; }
	public static function t_players()     { global $wpdb; return $wpdb->prefix . 'sdt_players'; }
	public static function t_matches()     { global $wpdb; return $wpdb->prefix . 'sdt_matches'; }

	public static function install() {
		global $wpdb;
		$charset = $wpdb->get_charset_collate();

		$sql_t = "CREATE TABLE " . self::t_tournaments() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(190) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'setup',
			num_groups TINYINT UNSIGNED NOT NULL DEFAULT 4,
			tables_count TINYINT UNSIGNED NOT NULL DEFAULT 2,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id)
		) $charset;";

		$sql_p = "CREATE TABLE " . self::t_players() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tournament_id BIGINT UNSIGNED NOT NULL,
			name VARCHAR(190) NOT NULL,
			group_label VARCHAR(5) NULL,
			position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			registration_id BIGINT UNSIGNED NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY tournament_id (tournament_id),
			KEY group_label (group_label),
			KEY registration_id (registration_id)
		) $charset;";

		$sql_m = "CREATE TABLE " . self::t_matches() . " (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			tournament_id BIGINT UNSIGNED NOT NULL,
			group_label VARCHAR(5) NOT NULL,
			round TINYINT UNSIGNED NOT NULL,
			position SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			player1_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			player2_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			winner_id BIGINT UNSIGNED NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			finished_at DATETIME NULL,
			phase VARCHAR(20) NOT NULL DEFAULT 'group',
			bracket_round TINYINT UNSIGNED NULL,
			bracket_position SMALLINT UNSIGNED NULL,
			bracket_side VARCHAR(10) NULL,
			feeds_match_id BIGINT UNSIGNED NULL,
			feeds_slot TINYINT UNSIGNED NULL,
			loser_feeds_match_id BIGINT UNSIGNED NULL,
			loser_feeds_slot TINYINT UNSIGNED NULL,
			PRIMARY KEY  (id),
			KEY tournament_id (tournament_id),
			KEY group_label (tournament_id, group_label),
			KEY phase (tournament_id, phase),
			KEY status (status)
		) $charset;";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql_t );
		dbDelta( $sql_p );
		dbDelta( $sql_m );
	}

	/* ---------- tournaments ---------- */

	public static function create_tournament( $name, $num_groups, $tables_count ) {
		global $wpdb;
		$wpdb->insert( self::t_tournaments(), array(
			'name'         => $name,
			'status'       => 'setup',
			'num_groups'   => $num_groups,
			'tables_count' => $tables_count,
			'created_at'   => current_time( 'mysql' ),
		), array( '%s', '%s', '%d', '%d', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	public static function update_tournament( $id, $data ) {
		global $wpdb;
		$format = array();
		foreach ( $data as $k => $v ) {
			$format[] = is_int( $v ) ? '%d' : '%s';
		}
		return $wpdb->update( self::t_tournaments(), $data, array( 'id' => (int) $id ), $format, array( '%d' ) );
	}

	public static function delete_tournament( $id ) {
		global $wpdb;
		$id = (int) $id;
		$wpdb->delete( self::t_matches(),     array( 'tournament_id' => $id ), array( '%d' ) );
		$wpdb->delete( self::t_players(),     array( 'tournament_id' => $id ), array( '%d' ) );
		$wpdb->delete( self::t_tournaments(), array( 'id'            => $id ), array( '%d' ) );
	}

	public static function get_tournament( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::t_tournaments() . " WHERE id = %d", $id ) );
	}

	public static function all_tournaments() {
		global $wpdb;
		return $wpdb->get_results( "SELECT * FROM " . self::t_tournaments() . " ORDER BY created_at DESC" );
	}

	public static function latest_active_tournament() {
		global $wpdb;
		return $wpdb->get_row(
			"SELECT * FROM " . self::t_tournaments()
			. " WHERE status IN ('running','finished') ORDER BY created_at DESC LIMIT 1"
		);
	}

	/* ---------- players ---------- */

	public static function add_player( $tournament_id, $name, $registration_id = null ) {
		global $wpdb;
		$wpdb->insert( self::t_players(), array(
			'tournament_id'   => (int) $tournament_id,
			'name'            => $name,
			'group_label'     => null,
			'position'        => 0,
			'registration_id' => $registration_id ? (int) $registration_id : null,
			'created_at'      => current_time( 'mysql' ),
		), array( '%d', '%s', '%s', '%d', '%d', '%s' ) );
		return (int) $wpdb->insert_id;
	}

	public static function get_unimported_registrations( $tournament_id ) {
		global $wpdb;
		$reg_table = $wpdb->prefix . 'sda_registrations';
		$exists    = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $reg_table ) );
		if ( ! $exists ) {
			return array();
		}
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT r.id, r.first_name, r.last_name, r.email, r.status
			 FROM $reg_table r
			 WHERE r.id NOT IN (
				SELECT COALESCE(registration_id, 0)
				FROM " . self::t_players() . "
				WHERE tournament_id = %d AND registration_id IS NOT NULL
			 )
			 ORDER BY r.status, r.first_name, r.last_name",
			$tournament_id
		) );
	}

	public static function delete_player( $id ) {
		global $wpdb;
		return $wpdb->delete( self::t_players(), array( 'id' => (int) $id ), array( '%d' ) );
	}

	public static function get_players( $tournament_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::t_players() . " WHERE tournament_id = %d ORDER BY group_label IS NULL DESC, group_label, position, name",
			$tournament_id
		) );
	}

	public static function get_player( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::t_players() . " WHERE id = %d", $id ) );
	}

	public static function assign_player( $player_id, $group_label, $position ) {
		global $wpdb;
		return $wpdb->update(
			self::t_players(),
			array( 'group_label' => $group_label ?: null, 'position' => (int) $position ),
			array( 'id' => (int) $player_id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/* ---------- matches ---------- */

	public static function insert_match( $data ) {
		global $wpdb;
		$types = array(
			'tournament_id'        => '%d',
			'group_label'          => '%s',
			'round'                => '%d',
			'position'             => '%d',
			'player1_id'           => '%d',
			'player2_id'           => '%d',
			'winner_id'            => '%d',
			'status'               => '%s',
			'finished_at'          => '%s',
			'phase'                => '%s',
			'bracket_round'        => '%d',
			'bracket_position'     => '%d',
			'bracket_side'         => '%s',
			'feeds_match_id'       => '%d',
			'feeds_slot'           => '%d',
			'loser_feeds_match_id' => '%d',
			'loser_feeds_slot'     => '%d',
		);
		$format = array();
		foreach ( array_keys( $data ) as $k ) {
			$format[] = $types[ $k ] ?? '%s';
		}
		$wpdb->insert( self::t_matches(), $data, $format );
		return (int) $wpdb->insert_id;
	}

	public static function set_match_feeds( $match_id, $target_match_id, $slot ) {
		global $wpdb;
		return $wpdb->update(
			self::t_matches(),
			array( 'feeds_match_id' => (int) $target_match_id, 'feeds_slot' => (int) $slot ),
			array( 'id' => (int) $match_id ),
			array( '%d', '%d' ),
			array( '%d' )
		);
	}

	public static function set_match_loser_feeds( $match_id, $target_match_id, $slot ) {
		global $wpdb;
		return $wpdb->update(
			self::t_matches(),
			array( 'loser_feeds_match_id' => (int) $target_match_id, 'loser_feeds_slot' => (int) $slot ),
			array( 'id' => (int) $match_id ),
			array( '%d', '%d' ),
			array( '%d' )
		);
	}

	public static function set_match_player( $match_id, $slot, $player_id ) {
		global $wpdb;
		$col = ( (int) $slot === 1 ) ? 'player1_id' : 'player2_id';
		// Schutz: ein bereits als 'done' markiertes Match (insb. totes Match) nicht mehr verändern.
		$current = self::get_match( (int) $match_id );
		if ( ! $current ) return false;
		if ( $current->status === 'done' ) return false;
		return $wpdb->update(
			self::t_matches(),
			array( $col => (int) $player_id ),
			array( 'id' => (int) $match_id ),
			array( '%d' ),
			array( '%d' )
		);
	}

	public static function delete_bracket_matches( $tournament_id ) {
		global $wpdb;
		return $wpdb->query( $wpdb->prepare(
			"DELETE FROM " . self::t_matches() . " WHERE tournament_id = %d AND phase != 'group'",
			$tournament_id
		) );
	}

	public static function delete_matches( $tournament_id ) {
		global $wpdb;
		return $wpdb->delete( self::t_matches(), array( 'tournament_id' => (int) $tournament_id ), array( '%d' ) );
	}

	public static function get_matches( $tournament_id ) {
		global $wpdb;
		return $wpdb->get_results( $wpdb->prepare(
			"SELECT * FROM " . self::t_matches() . " WHERE tournament_id = %d ORDER BY round, group_label, position",
			$tournament_id
		) );
	}

	public static function get_match( $id ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM " . self::t_matches() . " WHERE id = %d", $id ) );
	}

	public static function set_winner( $match_id, $winner_id ) {
		global $wpdb;
		return $wpdb->update(
			self::t_matches(),
			array(
				'winner_id'   => $winner_id ? (int) $winner_id : null,
				'status'      => $winner_id ? 'done' : 'pending',
				'finished_at' => $winner_id ? current_time( 'mysql' ) : null,
			),
			array( 'id' => (int) $match_id ),
			array( '%d', '%s', '%s' ),
			array( '%d' )
		);
	}
}
