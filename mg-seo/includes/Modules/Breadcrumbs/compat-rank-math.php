<?php
/**
 * Rank Math breadcrumb function shims (loaded only when Rank Math is inactive).
 *
 * @package MGSEO
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'rank_math_the_breadcrumbs' ) ) {
	/**
	 * Print breadcrumbs (theme compatibility).
	 *
	 * @param array $args Arguments.
	 */
	function rank_math_the_breadcrumbs( $args = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- intentional compatibility shim.
		echo MGSEO\Modules\Breadcrumbs\Module::html( is_array( $args ) ? array_intersect_key( $args, array_flip( array( 'wrap_before', 'wrap_after', 'separator' ) ) ) : array() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
	}
}
if ( ! function_exists( 'rank_math_get_breadcrumbs' ) ) {
	/**
	 * Return breadcrumbs (theme compatibility).
	 *
	 * @param array $args Arguments.
	 * @return string
	 */
	function rank_math_get_breadcrumbs( $args = array() ) { // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedFunctionFound -- intentional compatibility shim.
		return MGSEO\Modules\Breadcrumbs\Module::html( is_array( $args ) ? array_intersect_key( $args, array_flip( array( 'wrap_before', 'wrap_after', 'separator' ) ) ) : array() );
	}
}
