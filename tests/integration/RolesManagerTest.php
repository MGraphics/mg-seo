<?php
/**
 * Role Manager tests.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Installer;
use MGSEO\Modules\Roles\Module as Roles;

/**
 * @covers \MGSEO\Modules\Roles\Module
 */
class RolesManagerTest extends TestCase {

	/**
	 * Restore default caps after each test.
	 */
	public function tear_down(): void {
		Roles::apply( Roles::defaults() );
		parent::tear_down();
	}

	public function test_defaults_match_installer(): void {
		Roles::apply( Roles::defaults() );
		$matrix = Roles::matrix();
		$this->assertContains( 'mgseo_bulk_edit', $matrix['editor'] );
		$this->assertNotContains( 'mgseo_manage_settings', $matrix['editor'] );
		$this->assertContains( 'mgseo_edit_meta', $matrix['author'] );
		$this->assertSame( array(), $matrix['subscriber'] );
	}

	public function test_apply_adds_and_removes_caps(): void {
		Roles::apply(
			array(
				'administrator' => array_keys( Installer::capabilities() ),
				'editor'        => array( 'mgseo_edit_meta' ),
				'author'        => array( 'mgseo_edit_meta', 'mgseo_view_reports', 'not_a_mgseo_cap' ),
			)
		);
		$editor = get_role( 'editor' );
		$this->assertTrue( $editor->has_cap( 'mgseo_edit_meta' ) );
		$this->assertFalse( $editor->has_cap( 'mgseo_bulk_edit' ) );
		$this->assertFalse( $editor->has_cap( 'mgseo_redirections' ) );
		$author = get_role( 'author' );
		$this->assertTrue( $author->has_cap( 'mgseo_view_reports' ) );
		$this->assertFalse( $author->has_cap( 'not_a_mgseo_cap' ), 'Only MG SEO capabilities are managed.' );
		$this->assertSame( array_keys( Installer::capabilities() ), get_option( 'mgseo_caps_configured' ) );

		$user = self::factory()->user->create( array( 'role' => 'editor' ) );
		$this->assertFalse( user_can( $user, 'mgseo_bulk_edit' ) );
	}

	public function test_administrator_keeps_settings_capability(): void {
		Roles::apply( array( 'administrator' => array() ) );
		$admin = get_role( 'administrator' );
		$this->assertTrue( $admin->has_cap( 'mgseo_manage_settings' ) );
		$this->assertFalse( $admin->has_cap( 'mgseo_bulk_edit' ) );
	}

	public function test_installer_does_not_readd_removed_caps(): void {
		Roles::apply( array( 'administrator' => array( 'mgseo_manage_settings' ) ) );
		Installer::add_capabilities();
		$this->assertFalse( get_role( 'editor' )->has_cap( 'mgseo_edit_meta' ) );
	}

	public function test_save_handler_requires_capability_and_nonce(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->expectException( \WPDieException::class );
		( new Roles() )->handle_save();
	}
}
