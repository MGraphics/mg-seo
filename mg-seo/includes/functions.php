<?php
/**
 * Public template functions.
 *
 * @package MGSEO
 */

defined( 'ABSPATH' ) || exit;

if ( ! function_exists( 'mgseo_breadcrumbs' ) ) {
	/**
	 * Print (or return) the breadcrumb trail.
	 *
	 * @param array $args Arguments: echo (bool), wrap_before, wrap_after, separator.
	 * @return string
	 */
	function mgseo_breadcrumbs( array $args = array() ): string {
		if ( ! class_exists( 'MGSEO\\Modules\\Breadcrumbs\\Module' ) || ! MGSEO\Plugin::instance()->module( 'breadcrumbs' ) ) {
			return '';
		}
		$html = MGSEO\Modules\Breadcrumbs\Module::html( $args );
		if ( ! isset( $args['echo'] ) || $args['echo'] ) {
			echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped while built.
		}
		return $html;
	}
}

if ( ! function_exists( 'mgseo_get_option' ) ) {
	/**
	 * Read an MG SEO setting.
	 *
	 * @param string $group    Module id.
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	function mgseo_get_option( string $group, string $key, mixed $fallback = null ): mixed {
		return MGSEO\Options::get( $group, $key, $fallback );
	}
}

if ( ! function_exists( 'mgseo_get_title' ) ) {
	/**
	 * SEO title for the current request.
	 */
	function mgseo_get_title(): string {
		return MGSEO\Modules\Titles\Module::document_title();
	}
}
