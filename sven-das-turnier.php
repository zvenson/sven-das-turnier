<?php
/**
 * Plugin Name: Sven Das Turnier
 * Plugin URI:  https://webdesignhamburg.net/
 * Description: Turniersoftware mit Gruppen, Round-Robin, KO-Runden, Tennis-Modus und Live-Tabellen.
 * Version:     2.0.1
 * Author:      Sven Trogus
 * Author URI:  https://webdesignhamburg.net/
 * Text Domain: sven-das-turnier
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'SDT_VERSION', '2.0.1' );
define( 'SDT_FILE', __FILE__ );
define( 'SDT_PATH', plugin_dir_path( __FILE__ ) );
define( 'SDT_URL', plugin_dir_url( __FILE__ ) );

require_once SDT_PATH . 'includes/class-db.php';
require_once SDT_PATH . 'includes/class-scheduler.php';
require_once SDT_PATH . 'includes/class-ajax.php';
require_once SDT_PATH . 'includes/class-admin.php';
require_once SDT_PATH . 'includes/class-frontend.php';

register_activation_hook( __FILE__, array( 'SDT_DB', 'install' ) );

add_action( 'plugins_loaded', function () {
	if ( get_option( 'sdt_db_version' ) !== SDT_VERSION ) {
		SDT_DB::install();
		update_option( 'sdt_db_version', SDT_VERSION );
	}
	SDT_Admin::init();
	SDT_Ajax::init();
	SDT_Frontend::init();
} );
