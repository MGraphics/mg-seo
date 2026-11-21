<?php
/**
 * Uninstall cleanup test.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Meta;
use MGSEO\Options;
use MGSEO\Plugin;

/**
 * @coversNothing
 */
class UninstallTest extends TestCase {

	private function table_exists( string $name ): bool {
		global $wpdb;
		$wpdb->suppress_errors( true );
		$result = $wpdb->get_var( "SELECT COUNT(*) FROM {$wpdb->prefix}{$name}" ); // phpcs:ignore WordPress.DB
		$wpdb->suppress_errors( false );
		return null !== $result;
	}

	public function test_uninstall_removes_all_plugin_data(): void {
		foreach ( Plugin::registry() as $def ) {
			if ( class_exists( $def['class'] ) ) {
				( new $def['class']() )->activate();
			}
		}
		$post = self::factory()->post->create();
		$term = self::factory()->category->create();
		$user = self::factory()->user->create();
		Meta::set_post( $post, 'title', 'X' );
		Meta::set_term( $term, 'title', 'Y' );
		Meta::set_user( $user, 'title', 'Z' );
		Options::set( 'titles', 'separator', '|' );
		set_transient( 'mgseo_test', 1 );
		$this->assertTrue( $this->table_exists( 'mgseo_redirections' ) );
		$this->assertTrue( get_role( 'administrator' )->has_cap( 'mgseo_manage_settings' ) );

		if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
			define( 'WP_UNINSTALL_PLUGIN', 'mg-seo/mg-seo.php' );
		}
		include dirname( __DIR__, 2 ) . '/mg-seo/uninstall.php';

		global $wpdb;
		foreach ( array( $wpdb->postmeta, $wpdb->termmeta, $wpdb->usermeta ) as $table ) {
			$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE meta_key LIKE %s", '\_mgseo\_%' ) ), $table ); // phpcs:ignore WordPress.DB
		}
		$this->assertSame( '0', $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", 'mgseo\_%', '\_transient\_mgseo\_%' ) ) ); // phpcs:ignore WordPress.DB
		// Table drops are verified with a real uninstall (the WP test suite turns DROP TABLE into DROP TEMPORARY TABLE).
		wp_roles()->for_site();
		$this->assertFalse( get_role( 'administrator' )->has_cap( 'mgseo_manage_settings' ) );
		$this->assertFalse( wp_next_scheduled( 'mgseo_daily' ) );
	}

	public function tear_down(): void {
		\MGSEO\Installer::activate();
		parent::tear_down();
	}
}
