<?php
/**
 * Base test case.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Context;
use MGSEO\Head;
use MGSEO\Options;
use MGSEO\Variables;

/**
 * Shared helpers for MG SEO integration tests.
 */
abstract class TestCase extends \WP_UnitTestCase {

	/**
	 * Reset caches between tests.
	 */
	public function set_up(): void {
		parent::set_up();
		Options::flush_cache();
		Context::reset();
		Variables::reset();
		$this->set_permalink_structure( '/%postname%/' );
		create_initial_taxonomies();
		flush_rewrite_rules( false );
	}

	/**
	 * Visit a URL and return MG SEO head output.
	 *
	 * @param string $url URL.
	 */
	protected function head_for( string $url ): string {
		$this->go_to( $url );
		Context::reset();
		return Head::render();
	}

	/**
	 * Update a setting.
	 *
	 * @param string $group Group.
	 * @param string $key   Key.
	 * @param mixed  $value Value.
	 */
	protected function set_option( string $group, string $key, mixed $value ): void {
		Options::set( $group, $key, $value );
		Options::flush_cache();
	}

	/**
	 * Extract decoded JSON-LD blocks from HTML.
	 *
	 * @param string $html HTML.
	 * @return array<int, array>
	 */
	protected function json_ld( string $html ): array {
		preg_match_all( '#<script type="application/ld\+json">(.*?)</script>#s', $html, $m );
		return array_map( static fn( $json ) => json_decode( $json, true ), $m[1] );
	}
}
