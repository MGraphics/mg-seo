<?php
/**
 * Plugin Name:       MG SEO
 * Plugin URI:        https://github.com/MGraphics/mg-seo
 * Description:       SEO toolkit for MGraphics sites: titles and meta, schema, sitemaps, redirections, local SEO, WooCommerce and more.
 * Version:           0.3.0
 * Requires at least: 6.5
 * Requires PHP:      8.2
 * Author:            MGraphics
 * Author URI:        https://mgraphics.com.pl
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Update URI:        https://github.com/MGraphics/mg-seo
 * Text Domain:       mg-seo
 * Domain Path:       /languages
 *
 * @package MGSEO
 */

defined( 'ABSPATH' ) || exit;

define( 'MGSEO_VERSION', '0.3.0' );
define( 'MGSEO_FILE', __FILE__ );
define( 'MGSEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'MGSEO_URL', plugin_dir_url( __FILE__ ) );

/*
 * Self-hosted updates from GitHub Releases of the public MGraphics/mg-seo repository (no token needed).
 * Define MGSEO_UPDATE_REPO as an empty string in wp-config.php to turn update checks off.
 */
if ( ! defined( 'MGSEO_UPDATE_REPO' ) ) {
	define( 'MGSEO_UPDATE_REPO', 'https://github.com/MGraphics/mg-seo' );
}

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( ! str_starts_with( $class_name, 'MGSEO\\' ) ) {
			return;
		}
		$relative = str_replace( '\\', '/', substr( $class_name, 6 ) );
		$file     = MGSEO_DIR . 'includes/' . $relative . '.php';
		if ( is_readable( $file ) ) {
			require_once $file;
		}
	}
);

require_once MGSEO_DIR . 'includes/functions.php';

register_activation_hook( __FILE__, array( 'MGSEO\\Installer', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'MGSEO\\Installer', 'deactivate' ) );

add_action( 'plugins_loaded', array( MGSEO\Plugin::instance(), 'boot' ), 5 );
