<?php
/**
 * Grouped admin menu and screen shell tests.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Admin\Admin;
use MGSEO\Admin\Navigation;
use MGSEO\Admin\Settings;
use MGSEO\Plugin;

/**
 * @covers \MGSEO\Admin\Admin::menu
 * @covers \MGSEO\Admin\Navigation
 * @covers \MGSEO\Admin\Settings::render_page
 */
class NavigationTest extends TestCase {

	/**
	 * Build the menu as a user with the given role and return the visible submenu after hiding grouped screens.
	 *
	 * @param string $role Role.
	 * @return array<string, string> Visible slug => menu title.
	 */
	private function build_menu( string $role = 'administrator' ): array {
		global $submenu, $menu, $_registered_pages, $_parent_pages, $admin_page_hooks;
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
		require_once ABSPATH . 'wp-admin/includes/template.php';
		$submenu           = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- isolated menu per test.
		$menu              = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- isolated menu per test.
		$_registered_pages = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- isolated menu per test.
		$_parent_pages     = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- isolated menu per test.
		$admin_page_hooks  = array(); // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- isolated menu per test.
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
		Admin::instance()->menu();
		Admin::instance()->hide_grouped();
		$visible = array();
		foreach ( $submenu[ Admin::MENU_SLUG ] ?? array() as $item ) {
			$visible[ $item[2] ] = $item[0];
		}
		return $visible;
	}

	public function test_submenu_lists_one_entry_per_group(): void {
		$visible = $this->build_menu();
		$this->assertSame(
			array(
				Admin::MENU_SLUG    => 'Dashboard',
				'mg-seo-analysis'   => 'General Settings',
				'mg-seo-titles'     => 'Titles & Meta',
				'mg-seo-sitemap'    => 'Sitemaps',
				'mg-seo-redirects'  => 'Redirections',
				'mg-seo-bulk'       => 'Tools',
			),
			$visible
		);
	}

	public function test_every_screen_stays_registered_under_its_old_slug(): void {
		global $_registered_pages;
		$this->build_menu();
		$slugs = array( 'mg-seo-overview', 'mg-seo-links-report', 'mg-seo-breadcrumbs', 'mg-seo-links', 'mg-seo-tools', 'mg-seo-social', 'mg-seo-schema', 'mg-seo-404-monitor', 'mg-seo-redirections', 'mg-seo-import', 'mg-seo-roles', 'mg-seo-utilities' );
		foreach ( $slugs as $slug ) {
			$this->assertArrayHasKey( 'mg-seo_page_' . $slug, $_registered_pages, $slug );
			$this->assertContains( $slug, Admin::instance()->hidden_pages(), $slug );
		}
		$this->assertSame( 'mg-seo_page_mg-seo-breadcrumbs', get_plugin_page_hookname( 'mg-seo-breadcrumbs', Admin::MENU_SLUG ) );
	}

	public function test_group_entry_is_highlighted_on_its_screens(): void {
		global $plugin_page;
		$this->build_menu();
		$plugin_page = 'mg-seo-404-monitor'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulate the current screen.
		$this->assertSame( 'mg-seo-redirects', Admin::instance()->current_entry( null ) );
		$plugin_page = 'mg-seo-schema'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulate the current screen.
		$this->assertSame( 'mg-seo-titles', Admin::instance()->current_entry( null ) );
		$plugin_page = 'some-other-page'; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- simulate the current screen.
		$this->assertSame( 'x', Admin::instance()->current_entry( 'x' ) );
		$plugin_page = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reset.
	}

	public function test_disabled_modules_have_no_sections(): void {
		$this->build_menu();
		$this->assertFalse( Plugin::instance()->is_module_active( 'local' ) );
		$slugs = array_column( Navigation::entries( 'general' ), 'slug' );
		$this->assertNotContains( 'mg-seo-local', $slugs );
		$this->assertContains( 'mg-seo-breadcrumbs', $slugs );
		$this->assertNotContains( 'mg-seo-local', Navigation::members( 'general' ) );
	}

	public function test_settings_screen_has_rail_and_posts_the_current_tab(): void {
		$this->build_menu();
		$_GET['tab'] = 'home';
		ob_start();
		Settings::render_page( Plugin::instance()->module( 'titles' ) );
		$html = (string) ob_get_clean();
		unset( $_GET['tab'] );

		$this->assertStringContainsString( '<h1>Titles &amp; Meta</h1>', $html );
		$this->assertStringContainsString( 'class="mgseo-rail"', $html );
		$this->assertMatchesRegularExpression( '#<a class="mgseo-rail-link is-current" href="[^"]*page=mg-seo-titles&\#038;tab=home" aria-current="page">#', $html );
		$this->assertSame( 1, substr_count( $html, 'aria-current="page"' ) );
		// Other screens of the group are reachable from the rail.
		$this->assertStringContainsString( 'page=mg-seo-social', $html );
		$this->assertStringContainsString( 'page=mg-seo-schema', $html );
		// The same form as before: module, tab, nonce, save action.
		$this->assertStringContainsString( 'name="action" value="mgseo_save_settings"', $html );
		$this->assertStringContainsString( 'name="module" value="titles"', $html );
		$this->assertStringContainsString( 'name="tab" value="home"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		// Narrow screens: a section picker with the same entries.
		$this->assertStringContainsString( 'class="mgseo-section-picker"', $html );
		$this->assertSame( 1, preg_match_all( '#<div class="wrap #', $html ) );
	}

	public function test_unknown_tab_falls_back_to_the_first(): void {
		$this->build_menu();
		$_GET['tab'] = 'nope';
		ob_start();
		Settings::render_page( Plugin::instance()->module( 'sitemap' ) );
		$html = (string) ob_get_clean();
		unset( $_GET['tab'] );
		$this->assertStringContainsString( 'name="tab" value="general"', $html );
	}

	public function test_nested_tabs_open_under_their_page(): void {
		$this->build_menu();
		ob_start();
		Navigation::open( 'mg-seo-tools', 'llms' );
		Navigation::close();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'mgseo-rail-sub', $html );
		$this->assertMatchesRegularExpression( '#<a class="mgseo-rail-sublink is-current" href="[^"]*page=mg-seo-tools&\#038;tab=llms" aria-current="page">llms.txt</a>#', $html );
		$this->assertStringContainsString( 'Crawlers &amp; indexing', $html );
	}

	public function test_screens_with_tables_get_top_tabs_and_one_h1(): void {
		$this->build_menu();
		ob_start();
		Navigation::render(
			'mg-seo-404-monitor',
			static function (): void {
				echo '<div class="wrap"><h1 class="wp-heading-inline">404 Monitor</h1><hr class="wp-header-end" /><p>log</p></div>';
			}
		);
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'class="mgseo-topnav"', $html );
		$this->assertStringContainsString( '<h1>Redirections</h1>', $html );
		$this->assertStringContainsString( '<h2 class="wp-heading-inline">404 Monitor</h2>', $html );
		$this->assertSame( 1, substr_count( $html, '<h1' ) );
		$this->assertSame( 1, substr_count( $html, 'wp-header-end' ) );
		$this->assertMatchesRegularExpression( '#href="[^"]*page=mg-seo-404-monitor" aria-current="page"#', $html );
		$this->assertStringContainsString( 'page=mg-seo-redirections', $html );
	}

	public function test_reports_only_user_gets_the_first_screen_they_may_open(): void {
		$role = add_role( 'mgseo_reader', 'Reader', array( 'read' => true, 'mgseo_view_reports' => true ) );
		$this->assertNotNull( $role );
		$visible = $this->build_menu( 'mgseo_reader' );
		remove_role( 'mgseo_reader' );
		$this->assertSame( array( 'mg-seo-overview' => 'Dashboard' ), $visible );
	}
}
