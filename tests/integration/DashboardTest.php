<?php
/**
 * Dashboard module cards tests.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Admin\Admin;
use MGSEO\Admin\ModuleIcons;
use MGSEO\Modules\Bulk\Module as Bulk;
use MGSEO\Plugin;

/**
 * @covers \MGSEO\Admin\Admin::dashboard
 * @covers \MGSEO\Admin\ModuleIcons
 */
class DashboardTest extends TestCase {

	/**
	 * Render the dashboard as an administrator.
	 *
	 * @return array<string, string> Card markup keyed by module id.
	 */
	private function cards(): array {
		require_once ABSPATH . 'wp-admin/includes/template.php';
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		Admin::instance()->dashboard();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'name="action" value="mgseo_modules"', $html );
		$this->assertStringContainsString( 'name="_wpnonce"', $html );
		preg_match_all( '#<div class="mgseo-module [^"]*" data-module="([a-z]+)">(.*?)</label></div></div>#s', $html, $m );
		return array_combine( $m[1], $m[2] );
	}

	public function test_every_module_has_a_card_with_icon_and_switch(): void {
		$cards = $this->cards();
		$this->assertSame( array_keys( Plugin::registry() ), array_keys( $cards ) );
		foreach ( $cards as $id => $card ) {
			$this->assertStringContainsString( '<svg', $card, $id );
			$this->assertStringContainsString( 'role="switch"', $card, $id );
			$this->assertStringContainsString( 'name="modules[' . $id . ']"', $card, $id );
			$this->assertStringContainsString( 'aria-labelledby="mgseo-module-' . $id . '-title"', $card, $id );
		}
	}

	public function test_switch_states_and_links(): void {
		$cards = $this->cards();

		// Required module: on, locked, still links to its settings.
		$this->assertMatchesRegularExpression( '#checked=\'checked\'\s*disabled=\'disabled\'#', $cards['titles'] );
		$this->assertStringContainsString( 'page=mg-seo-titles', $cards['titles'] );

		// Off by default: no link.
		$this->assertFalse( Plugin::instance()->is_module_active( 'local' ) );
		$this->assertStringNotContainsString( "checked='checked'", $cards['local'] );
		$this->assertStringNotContainsString( 'mgseo-module-link', $cards['local'] );

		// On with settings: settings link. On without settings: link to its screen.
		$this->assertStringContainsString( 'page=mg-seo-social', $cards['social'] );
		$this->assertStringContainsString( 'page=' . Bulk::PAGE, $cards['bulk'] );
	}

	public function test_icon_filter_is_sanitized(): void {
		add_filter( 'mgseo/module_icon', static fn() => '<circle cx="12" cy="12" r="4"/><script>alert(1)</script>' );
		$svg = ModuleIcons::svg( 'titles' );
		remove_all_filters( 'mgseo/module_icon' );
		$this->assertStringContainsString( '<circle cx="12" cy="12" r="4"', $svg );
		$this->assertStringNotContainsString( '<script', $svg );
		$this->assertStringContainsString( 'viewBox="0 0 24 24"', $svg );
	}
}
