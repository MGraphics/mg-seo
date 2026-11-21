<?php
/**
 * Uninstall: remove MG SEO data when "Delete data on uninstall" is enabled (Tools settings, on by default).
 *
 * @package MGSEO
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

$mgseo_settings = get_option( 'mgseo_settings', array() );
$mgseo_delete   = ! isset( $mgseo_settings['tools']['delete_data'] ) || (bool) $mgseo_settings['tools']['delete_data'];
if ( ! $mgseo_delete ) {
	return;
}

define( 'MGSEO_DIR', plugin_dir_path( __FILE__ ) );
define( 'MGSEO_FILE', MGSEO_DIR . 'mg-seo.php' );
define( 'MGSEO_URL', plugin_dir_url( __FILE__ ) );
define( 'MGSEO_VERSION', 'uninstall' );

spl_autoload_register(
	static function ( string $class_name ): void {
		if ( str_starts_with( $class_name, 'MGSEO\\' ) ) {
			$file = MGSEO_DIR . 'includes/' . str_replace( '\\', '/', substr( $class_name, 6 ) ) . '.php';
			if ( is_readable( $file ) ) {
				require_once $file;
			}
		}
	}
);

/**
 * Remove data for the current site.
 */
function mgseo_uninstall_site(): void {
	global $wpdb;
	foreach ( MGSEO\Plugin::registry() as $mgseo_def ) {
		if ( class_exists( $mgseo_def['class'] ) ) {
			( new $mgseo_def['class']() )->uninstall();
		}
	}
	MGSEO\Installer::remove_capabilities();
	// phpcs:disable WordPress.DB.DirectDatabaseQuery
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_mgseo_' ) . '%' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->termmeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_mgseo_' ) . '%' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->usermeta} WHERE meta_key LIKE %s", $wpdb->esc_like( '_mgseo_' ) . '%' ) );
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( 'mgseo_' ) . '%', $wpdb->esc_like( '_transient_mgseo_' ) . '%', $wpdb->esc_like( '_transient_timeout_mgseo_' ) . '%' ) );
	// phpcs:enable
	wp_clear_scheduled_hook( 'mgseo_daily' );
}

if ( is_multisite() ) {
	foreach ( get_sites( array( 'fields' => 'ids' ) ) as $mgseo_site_id ) {
		switch_to_blog( (int) $mgseo_site_id );
		mgseo_uninstall_site();
		restore_current_blog();
	}
} else {
	mgseo_uninstall_site();
}
