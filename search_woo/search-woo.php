<?php
/**
 * Plugin Name: search_woo
 * Description: Buscador AJAX general para WooCommerce con índice propio, sinónimos y reportes en la zona horaria del sitio.
 * Version: 1.0.0
 * Author: Jonathan Diaz
 * Author URI: https://www.soydiaz.com
 * Text Domain: search-woo
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * WC requires at least: 9.0
 */

defined( 'ABSPATH' ) || exit;

define( 'SEARCH_WOO_VERSION', '1.0.0' );
define( 'SEARCH_WOO_FILE', __FILE__ );
define( 'SEARCH_WOO_DIR', plugin_dir_path( __FILE__ ) );
define( 'SEARCH_WOO_URL', plugin_dir_url( __FILE__ ) );

require_once SEARCH_WOO_DIR . 'includes/class-search-woo.php';

register_activation_hook( __FILE__, array( 'Search_Woo', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'Search_Woo', 'deactivate' ) );

Search_Woo::instance();
