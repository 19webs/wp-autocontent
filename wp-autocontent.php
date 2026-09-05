<?php
/**
 * Plugin Name:       WP autocontent
 * Plugin URI:        https://19webs.com
 * Description:       Reemplaza automáticamente textos e imágenes de relleno (Lorem Ipsum) en páginas maquetadas con Elementor usando Web Scraping o las APIs de Google Gemini 1.5 Flash y Pexels.
 * Version:     1.0.6
 * Author:            19webs
 * Author URI:        https://19webs.com
 * Text Domain:       wp-autocontent
 * Domain Path:       /languages
 * Requires at least: 6.0
 * Requires PHP:      8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit; // Evitar acceso directo.
}

// Definición de constantes del plugin
define( 'WP_AUTOCONTENT_VERSION', '1.0.6' );
define( 'WP_AUTOCONTENT_FILE', __FILE__ );
define( 'WP_AUTOCONTENT_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_AUTOCONTENT_URL', plugin_dir_url( __FILE__ ) );

/**
 * Carga modular de las clases del plugin.
 */
require_once WP_AUTOCONTENT_PATH . 'includes/class-scraper.php';
require_once WP_AUTOCONTENT_PATH . 'includes/class-gemini-client.php';
require_once WP_AUTOCONTENT_PATH . 'includes/class-media-importer.php';
require_once WP_AUTOCONTENT_PATH . 'includes/class-elementor-mutator.php';
require_once WP_AUTOCONTENT_PATH . 'includes/class-admin-settings.php';
require_once WP_AUTOCONTENT_PATH . 'includes/class-updater.php';

/**
 * Inicialización del plugin.
 */
function wp_autocontent_init() {
	if ( is_admin() ) {
		new WP_Autocontent_Admin_Settings();
		new WP_Autocontent_Updater( WP_AUTOCONTENT_FILE, WP_AUTOCONTENT_VERSION );
	}
}
add_action( 'plugins_loaded', 'wp_autocontent_init' );
