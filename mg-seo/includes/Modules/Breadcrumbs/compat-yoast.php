<?php
/**
 * Yoast breadcrumb function shim (loaded only when Yoast SEO is inactive).
 *
 * @package MGSEO
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'yoast_breadcrumb' ) ) {
	/**
	 * Breadcrumbs (theme compatibility).
	 *
	 * @param string $before  HTML before.
	 * @param string $after   HTML after.
	 * @param bool   $display Echo.
	 * @return string
	 */
	function yoast_breadcrumb( $before = '', $after = '', $display = true ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- intentional compatibility shim.
		$html = MGSEO\Modules\Breadcrumbs\Module::html(
			array(
				'wrap_before' => wp_kses_post( (string) $before ),
				'wrap_after'  => wp_kses_post( (string) $after ),
			)
		);
		if ( $display ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
		}
		return $html;
	}
}
