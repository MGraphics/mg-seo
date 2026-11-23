<?php
/**
 * Activation, upgrades and capabilities.
 *
 * @package MGSEO
 */

namespace MGSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Installer.
 */
final class Installer {

	public const DB_VERSION = '1';

	/**
	 * Capabilities and the roles that get them by default.
	 *
	 * @return array<string, array{label: string, roles: string[]}>
	 */
	public static function capabilities(): array {
		return array(
			'mgseo_manage_settings' => array(
				'label' => __( 'Manage MG SEO settings', 'mg-seo' ),
				'roles' => array( 'administrator' ),
			),
			'mgseo_edit_meta'       => array(
				'label' => __( 'Edit SEO data of posts and terms', 'mg-seo' ),
				'roles' => array( 'administrator', 'editor', 'author' ),
			),
			'mgseo_edit_advanced'   => array(
				'label' => __( 'Edit advanced SEO data (robots, canonical, schema)', 'mg-seo' ),
				'roles' => array( 'administrator', 'editor' ),
			),
			'mgseo_redirections'    => array(
				'label' => __( 'Manage redirections and the 404 log', 'mg-seo' ),
				'roles' => array( 'administrator', 'editor' ),
			),
			'mgseo_view_reports'    => array(
				'label' => __( 'View Search Console and SEO reports', 'mg-seo' ),
				'roles' => array( 'administrator', 'editor' ),
			),
			'mgseo_bulk_edit'       => array(
				'label' => __( 'Bulk edit SEO data', 'mg-seo' ),
				'roles' => array( 'administrator', 'editor' ),
			),
		);
	}

	/**
	 * Activation hook.
	 */
	public static function activate(): void {
		self::add_capabilities();
		foreach ( Plugin::registry() as $id => $def ) {
			if ( Plugin::instance()->is_module_active( $id ) && class_exists( $def['class'] ) ) {
				( new $def['class']() )->activate();
			}
		}
		update_option( 'mgseo_db_version', self::DB_VERSION );
		update_option( 'mgseo_version', MGSEO_VERSION );
		update_option( 'mgseo_flush_rewrite', 1 );
	}

	/**
	 * Deactivation hook.
	 */
	public static function deactivate(): void {
		wp_clear_scheduled_hook( 'mgseo_daily' );
		wp_clear_scheduled_hook( 'mgseo_scores_daily' );
		wp_clear_scheduled_hook( 'mgseo_scores_batch' );
		flush_rewrite_rules( false );
	}

	/**
	 * Run upgrade routines and deferred rewrite flushes.
	 */
	public static function maybe_upgrade(): void {
		if ( get_option( 'mgseo_db_version' ) !== self::DB_VERSION ) {
			self::activate();
		}
		if ( get_option( 'mgseo_version' ) !== MGSEO_VERSION ) {
			// New plugin version (also an update without reactivation): rebuild rewrite rules (e.g. new sitemap
			// addresses in 0.3.2) and drop cached sitemaps that may hold old URLs.
			update_option( 'mgseo_version', MGSEO_VERSION );
			update_option( 'mgseo_flush_rewrite', 1 );
			Modules\Sitemap\Cache::bump();
		}
		if ( get_option( 'mgseo_flush_rewrite' ) ) {
			add_action(
				'init',
				static function (): void {
					delete_option( 'mgseo_flush_rewrite' );
					flush_rewrite_rules( false );
				},
				99
			);
		}
	}

	/**
	 * Grant default capabilities (only those not yet configured by the role manager).
	 */
	public static function add_capabilities(): void {
		$configured = get_option( 'mgseo_caps_configured', array() );
		$configured = is_array( $configured ) ? $configured : array();
		foreach ( self::capabilities() as $cap => $def ) {
			if ( in_array( $cap, $configured, true ) ) {
				continue;
			}
			foreach ( $def['roles'] as $role_name ) {
				$role = get_role( $role_name );
				if ( $role ) {
					$role->add_cap( $cap );
				}
			}
			$configured[] = $cap;
		}
		update_option( 'mgseo_caps_configured', $configured );
	}

	/**
	 * Remove all MG SEO capabilities from every role.
	 */
	public static function remove_capabilities(): void {
		foreach ( wp_roles()->role_objects as $role ) {
			foreach ( array_keys( self::capabilities() ) as $cap ) {
				$role->remove_cap( $cap );
			}
		}
		delete_option( 'mgseo_caps_configured' );
	}
}
