<?php
/**
 * Virtual robots.txt editor.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Tools;

use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces WordPress' virtual robots.txt with custom content (filter priority 5, re-applied at 19).
 * The Sitemap module appends its "Sitemap:" line later (priority 20).
 */
final class Robots {

	/**
	 * Register hooks.
	 */
	public static function boot(): void {
		add_filter( 'robots_txt', array( self::class, 'filter' ), 5, 2 );
		// Re-assert after other plugins (e.g. WooCommerce at 10) so custom content stays exactly as entered;
		// the Sitemap module still appends its line at 20.
		add_filter( 'robots_txt', array( self::class, 'filter' ), 19, 2 );
	}

	/**
	 * Replace the output when custom content is set and the site is public.
	 *
	 * @param string     $output Core output.
	 * @param string|int $is_public Value of blog_public.
	 */
	public static function filter( $output, $is_public ): string {
		$output = (string) $output;
		if ( ! $is_public ) {
			return $output;
		}
		$custom = trim( (string) Options::get( 'tools', 'robots_txt', '' ) );
		if ( '' === $custom ) {
			return $output;
		}
		return $custom . "\n";
	}

	/**
	 * Sanitize robots.txt content: plain text, normalised line endings.
	 *
	 * @param mixed $value Raw value.
	 */
	public static function sanitize( mixed $value ): string {
		$value = wp_check_invalid_utf8( (string) $value );
		$value = wp_strip_all_tags( str_replace( array( "\r\n", "\r" ), "\n", $value ) );
		$lines = array_map( 'rtrim', explode( "\n", $value ) );
		return trim( implode( "\n", $lines ) );
	}

	/**
	 * Paths of physical robots.txt files that would bypass the virtual one.
	 *
	 * @return string[]
	 */
	public static function physical_files(): array {
		$candidates = array( ABSPATH . 'robots.txt' );
		if ( ! function_exists( 'get_home_path' ) && is_admin() ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}
		if ( function_exists( 'get_home_path' ) ) {
			$candidates[] = trailingslashit( get_home_path() ) . 'robots.txt';
		}
		return array_values( array_unique( array_filter( $candidates, 'file_exists' ) ) );
	}

	/**
	 * Suggested default content.
	 */
	public static function suggestion(): string {
		$lines = array(
			'User-agent: *',
			'Disallow: /wp-admin/',
			'Allow: /wp-admin/admin-ajax.php',
			'Disallow: /?s=',
			'Disallow: /search/',
		);
		/**
		 * Filter the suggested robots.txt content.
		 *
		 * @param string $content Suggested content.
		 */
		return (string) apply_filters( 'mgseo/tools/robots_suggestion', implode( "\n", $lines ) );
	}

	/**
	 * Settings field: status notices and the suggestion.
	 */
	public static function render_status(): void {
		foreach ( self::physical_files() as $file ) {
			/* translators: %s: file path */
			echo '<div class="notice notice-warning inline"><p>' . esc_html( sprintf( __( 'A physical robots.txt file exists (%s). The web server serves it directly, so the content below is not used until you delete or rename that file.', 'mg-seo' ), $file ) ) . '</p></div>';
		}
		if ( ! get_option( 'blog_public' ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( '"Discourage search engines from indexing this site" is on (Settings → Reading), so WordPress serves "Disallow: /" and the content below is not used.', 'mg-seo' ) . '</p></div>';
		}
		echo '<p><a href="' . esc_url( home_url( '/robots.txt' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'View current robots.txt', 'mg-seo' ) . '</a></p>';
		echo '<p class="description">' . esc_html__( 'Suggested content:', 'mg-seo' ) . '</p>';
		echo '<pre class="mgseo-tools-suggestion">' . esc_html( self::suggestion() ) . '</pre>';
		echo '<button type="button" class="button mgseo-tools-use-suggestion" data-target="mgseo-robots-txt">' . esc_html__( 'Use suggestion', 'mg-seo' ) . '</button>';
	}
}
