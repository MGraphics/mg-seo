<?php
/**
 * <head> output.
 *
 * @package MGSEO
 */

namespace MGSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Single entry point for front-end head tags. Modules hook `mgseo/head` (priorities:
 * 5 verification, 10 description, 20 canonical, 25 pagination links, 30 Open Graph, 40 Twitter, 90 JSON-LD).
 * No HTML comments or plugin-identifying attributes are printed.
 */
final class Head {

	/**
	 * Instance.
	 *
	 * @var Head|null
	 */
	private static ?Head $instance = null;

	/**
	 * Get instance.
	 */
	public static function instance(): Head {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'wp_head', array( $this, 'output' ), 1 );
	}

	/**
	 * Print all head tags.
	 */
	public function output(): void {
		if ( is_admin() || is_feed() ) {
			return;
		}
		/**
		 * Print MG SEO head tags.
		 */
		do_action( 'mgseo/head' );
	}

	/**
	 * Render head output into a string (tests, previews).
	 */
	public static function render(): string {
		ob_start();
		do_action( 'mgseo/head' );
		return (string) ob_get_clean();
	}

	/**
	 * Print <meta name="" content="">.
	 *
	 * @param string $name    Name attribute.
	 * @param string $content Content.
	 */
	public static function meta( string $name, string $content ): void {
		if ( '' === trim( $content ) ) {
			return;
		}
		printf( '<meta name="%s" content="%s" />' . "\n", esc_attr( $name ), esc_attr( $content ) );
	}

	/**
	 * Print <meta property="" content="">.
	 *
	 * @param string $property Property attribute.
	 * @param string $content  Content.
	 */
	public static function property( string $property, string $content ): void {
		if ( '' === trim( $content ) ) {
			return;
		}
		printf( '<meta property="%s" content="%s" />' . "\n", esc_attr( $property ), esc_attr( $content ) );
	}

	/**
	 * Print <link rel="" href="">.
	 *
	 * @param string $rel  Rel.
	 * @param string $href URL.
	 */
	public static function link( string $rel, string $href ): void {
		if ( '' === $href ) {
			return;
		}
		printf( '<link rel="%s" href="%s" />' . "\n", esc_attr( $rel ), esc_url( $href ) );
	}

	/**
	 * Print a JSON-LD script.
	 *
	 * @param array<string, mixed> $data Data.
	 */
	public static function json_ld( array $data ): void {
		if ( ! $data ) {
			return;
		}
		echo '<script type="application/ld+json">' . Helpers::json_ld( $data ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON encoded with HEX_TAG.
	}
}
