<?php
/**
 * Generator for llms.txt.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Tools;

use MGSEO\Helpers;
use MGSEO\Meta;
use MGSEO\Modules\Titles\Module as Titles;
use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Serves /llms.txt (https://llmstxt.org format) built from the site name, tagline and selected post types.
 */
final class Llms {

	public const CACHE = 'mgseo_llms_txt';

	/**
	 * Register hooks.
	 */
	public static function boot(): void {
		add_action( 'parse_request', array( self::class, 'serve' ), 0 );
		add_action( 'save_post', array( self::class, 'flush' ) );
		add_action( 'deleted_post', array( self::class, 'flush' ) );
		add_action( 'mgseo/settings_saved', array( self::class, 'flush' ) );
	}

	/**
	 * Clear the cached file.
	 */
	public static function flush(): void {
		delete_transient( self::CACHE );
	}

	/**
	 * Output /llms.txt when enabled.
	 */
	public static function serve(): void {
		if ( ! Options::get( 'tools', 'llms_enabled', false ) || 'llms.txt' !== Helpers::request_path() ) {
			return;
		}
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex, follow' );
		}
		echo self::cached(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- plain text file.
		exit;
	}

	/**
	 * Cached content.
	 */
	public static function cached(): string {
		$cached = get_transient( self::CACHE );
		if ( is_string( $cached ) && '' !== $cached ) {
			return $cached;
		}
		$content = self::content();
		set_transient( self::CACHE, $content, 12 * HOUR_IN_SECONDS );
		return $content;
	}

	/**
	 * Build the file.
	 */
	public static function content(): string {
		$name = wp_strip_all_tags( html_entity_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ) );
		$desc = wp_strip_all_tags( html_entity_decode( (string) get_bloginfo( 'description' ), ENT_QUOTES, 'UTF-8' ) );
		$out  = '# ' . self::line( '' !== $name ? $name : home_url( '/' ) ) . "\n";
		if ( '' !== $desc ) {
			$out .= "\n> " . self::line( $desc ) . "\n";
		}
		$intro = trim( (string) Options::get( 'tools', 'llms_intro', '' ) );
		if ( '' !== $intro ) {
			$out .= "\n" . $intro . "\n";
		}
		$limit = max( 1, (int) Options::get( 'tools', 'llms_limit', 100 ) );
		foreach ( (array) Options::get( 'tools', 'llms_post_types', array( 'page', 'post' ) ) as $post_type ) {
			$object = get_post_type_object( (string) $post_type );
			if ( ! $object || ! is_post_type_viewable( $object ) || 'attachment' === $object->name ) {
				continue;
			}
			$posts = get_posts(
				array(
					'post_type'        => $object->name,
					'post_status'      => 'publish',
					'numberposts'      => $limit,
					'has_password'     => false,
					'orderby'          => $object->hierarchical ? 'menu_order title' : 'date',
					'order'            => $object->hierarchical ? 'ASC' : 'DESC',
					'suppress_filters' => false,
				)
			);
			$lines = array();
			foreach ( $posts as $post ) {
				if ( self::is_noindex( $post ) ) {
					continue;
				}
				$title       = self::line( Titles::post_title( $post ) );
				$description = self::line( Titles::post_description( $post ) );
				$lines[]     = '- [' . str_replace( array( '[', ']' ), array( '(', ')' ), $title ) . '](' . esc_url_raw( (string) get_permalink( $post ) ) . ')' . ( '' !== $description ? ': ' . $description : '' );
			}
			if ( $lines ) {
				$out .= "\n## " . self::line( (string) $object->labels->name ) . "\n\n" . implode( "\n", $lines ) . "\n";
			}
		}
		/**
		 * Filter the llms.txt content.
		 *
		 * @param string $out Content.
		 */
		return (string) apply_filters( 'mgseo/tools/llms_txt', $out );
	}

	/**
	 * Single-line plain text.
	 *
	 * @param string $text Text.
	 */
	private static function line( string $text ): string {
		$text = wp_strip_all_tags( html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Whether a post is excluded from indexing.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function is_noindex( \WP_Post $post ): bool {
		$own = (array) Meta::post( $post->ID, 'robots', array() );
		if ( in_array( 'index', $own, true ) ) {
			return false;
		}
		if ( in_array( 'noindex', $own, true ) ) {
			return true;
		}
		return in_array( 'noindex', (array) Options::get( 'titles', 'pt_' . $post->post_type . '_robots', array() ), true );
	}

	/**
	 * Settings field: link to the file.
	 */
	public static function render_status(): void {
		if ( file_exists( ABSPATH . 'llms.txt' ) ) {
			echo '<div class="notice notice-warning inline"><p>' . esc_html__( 'A physical llms.txt file exists in the site root; the web server serves it instead of the generated one.', 'mg-seo' ) . '</p></div>';
		}
		if ( Options::get( 'tools', 'llms_enabled', false ) ) {
			echo '<p><a href="' . esc_url( home_url( '/llms.txt' ) ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( home_url( '/llms.txt' ) ) . '</a></p>';
		} else {
			echo '<p class="description">' . esc_html__( 'Turn the file on and save to publish it at /llms.txt.', 'mg-seo' ) . '</p>';
		}
	}
}
