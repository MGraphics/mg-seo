<?php
/**
 * Shared helpers.
 *
 * @package MGSEO
 */

namespace MGSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Static helper functions.
 */
final class Helpers {

	/**
	 * Plain-text excerpt from post content.
	 *
	 * @param string $content Content (may contain blocks/shortcodes).
	 * @param int    $length  Max characters.
	 */
	public static function excerpt_from_content( string $content, int $length = 160 ): string {
		$content = excerpt_remove_blocks( $content );
		$content = strip_shortcodes( $content );
		$content = (string) preg_replace( '/<!--.*?-->/s', ' ', $content );
		$content = wp_strip_all_tags( str_replace( '<', ' <', $content ), true );
		$content = trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $content, ENT_QUOTES, 'UTF-8' ) ) );
		return self::truncate( $content, $length );
	}

	/**
	 * Truncate on a word boundary without adding an ellipsis.
	 *
	 * @param string $text   Text.
	 * @param int    $length Max characters.
	 */
	public static function truncate( string $text, int $length ): string {
		if ( mb_strlen( $text ) <= $length ) {
			return $text;
		}
		$cut   = mb_substr( $text, 0, $length + 1 );
		$space = mb_strrpos( $cut, ' ' );
		if ( false !== $space && $space > $length * 0.6 ) {
			$cut = mb_substr( $cut, 0, $space );
		} else {
			$cut = mb_substr( $cut, 0, $length );
		}
		return rtrim( $cut, " \t\n\r\0\x0B,;:-" );
	}

	/**
	 * Primary term of a post for a taxonomy (explicit choice or first term).
	 *
	 * @param \WP_Post $post     Post.
	 * @param string   $taxonomy Taxonomy.
	 * @param bool     $fallback Fall back to the first assigned term.
	 */
	public static function primary_term( \WP_Post $post, string $taxonomy, bool $fallback = false ): ?\WP_Term {
		$id = (int) Meta::post( $post->ID, 'primary_' . $taxonomy, 0 );
		if ( $id && has_term( $id, $taxonomy, $post ) ) {
			$term = get_term( $id, $taxonomy );
			if ( $term instanceof \WP_Term ) {
				return $term;
			}
		}
		if ( $fallback ) {
			$terms = get_the_terms( $post, $taxonomy );
			if ( is_array( $terms ) && $terms ) {
				return reset( $terms );
			}
		}
		return null;
	}

	/**
	 * Human readable name from a file path ("my-photo_2.jpg" → "My photo 2").
	 *
	 * @param string $path File path.
	 */
	public static function humanize_filename( string $path ): string {
		$name = pathinfo( $path, PATHINFO_FILENAME );
		$name = preg_replace( '/-\d+x\d+$/', '', (string) $name );
		$name = preg_replace( '/-scaled$/', '', (string) $name );
		$name = str_replace( array( '-', '_', '.' ), ' ', (string) $name );
		$name = trim( (string) preg_replace( '/\s+/', ' ', $name ) );
		return mb_strtoupper( mb_substr( $name, 0, 1 ) ) . mb_substr( $name, 1 );
	}

	/**
	 * Image data for an attachment id: url, width, height, alt, mime.
	 *
	 * @param int    $attachment_id Attachment id.
	 * @param string $size          Image size.
	 * @return array{url: string, width: int, height: int, alt: string, type: string}|null
	 */
	public static function image( int $attachment_id, string $size = 'full' ): ?array {
		if ( $attachment_id <= 0 ) {
			return null;
		}
		$src = wp_get_attachment_image_src( $attachment_id, $size );
		if ( ! $src ) {
			return null;
		}
		return array(
			'url'    => (string) $src[0],
			'width'  => (int) $src[1],
			'height' => (int) $src[2],
			'alt'    => (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ),
			'type'   => (string) get_post_mime_type( $attachment_id ),
		);
	}

	/**
	 * First image in post content.
	 *
	 * @param string $content Content.
	 */
	public static function first_content_image( string $content ): string {
		if ( preg_match( '/<img[^>]+src=["\']([^"\']+)["\']/i', $content, $m ) ) {
			return esc_url_raw( $m[1] );
		}
		return '';
	}

	/**
	 * All images (src + alt + attachment id when resolvable) in content.
	 *
	 * @param string $content HTML content.
	 * @return array<int, array{src: string, alt: string, id: int}>
	 */
	public static function content_images( string $content ): array {
		$images = array();
		if ( ! preg_match_all( '/<img\b[^>]*>/i', $content, $tags ) ) {
			return $images;
		}
		foreach ( $tags[0] as $tag ) {
			$src = preg_match( '/\ssrc=(["\'])(.*?)\1/i', $tag, $m ) ? html_entity_decode( $m[2] ) : '';
			if ( '' === $src ) {
				continue;
			}
			$alt      = preg_match( '/\salt=(["\'])(.*?)\1/i', $tag, $m ) ? html_entity_decode( $m[2] ) : '';
			$id       = preg_match( '/wp-image-(\d+)/', $tag, $m ) ? (int) $m[1] : 0;
			$images[] = array(
				'src' => $src,
				'alt' => $alt,
				'id'  => $id,
			);
		}
		return $images;
	}

	/**
	 * Whether stealth (no fingerprint) mode is on.
	 */
	public static function stealth(): bool {
		return (bool) Options::get( 'titles', 'stealth', true );
	}

	/**
	 * Robots directive list allowed in per-object settings.
	 *
	 * @return array<string, string>
	 */
	public static function robots_directives(): array {
		return array(
			'noindex'      => __( 'No Index', 'mg-seo' ),
			'nofollow'     => __( 'No Follow', 'mg-seo' ),
			'noarchive'    => __( 'No Archive', 'mg-seo' ),
			'nosnippet'    => __( 'No Snippet', 'mg-seo' ),
			'noimageindex' => __( 'No Image Index', 'mg-seo' ),
			'notranslate'  => __( 'No Translate', 'mg-seo' ),
		);
	}

	/**
	 * Sanitize a robots directive array.
	 *
	 * @param mixed $value Raw value.
	 * @return string[]
	 */
	public static function sanitize_robots( mixed $value ): array {
		$value   = is_array( $value ) ? $value : array_filter( array_map( 'trim', explode( ',', (string) $value ) ) );
		$allowed = array_keys( self::robots_directives() );
		$allowed = array_merge( $allowed, array( 'index', 'follow' ) );
		return array_values( array_intersect( array_map( 'sanitize_key', $value ), $allowed ) );
	}

	/**
	 * Current URL (front end), without query string unless asked.
	 *
	 * @param bool $with_query Keep the query string.
	 */
	public static function current_url( bool $with_query = false ): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '/';
		if ( ! $with_query ) {
			$uri = (string) strtok( $uri, '?' );
		}
		return home_url( $uri );
	}

	/**
	 * Request path relative to the home path, without leading/trailing slashes and query.
	 *
	 * @param string|null $uri Raw request URI (defaults to the current one).
	 */
	public static function request_path( ?string $uri = null ): string {
		if ( null === $uri ) {
			$uri = isset( $_SERVER['REQUEST_URI'] ) ? wp_unslash( $_SERVER['REQUEST_URI'] ) : '/'; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- normalised below.
		}
		$path      = (string) wp_parse_url( (string) $uri, PHP_URL_PATH );
		$path      = rawurldecode( $path );
		$home_path = (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		if ( '' !== $home_path && '/' !== $home_path && str_starts_with( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}
		return trim( $path, '/' );
	}

	/**
	 * Encode data as JSON for <script type="application/ld+json">.
	 *
	 * @param mixed $data Data.
	 */
	public static function json_ld( mixed $data ): string {
		$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP;
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && ! self::stealth() ) {
			$flags |= JSON_PRETTY_PRINT;
		}
		return (string) wp_json_encode( $data, $flags );
	}

	/**
	 * Whether the current user can edit SEO data for a post.
	 *
	 * @param int $post_id Post id.
	 */
	public static function can_edit_post_seo( int $post_id ): bool {
		return current_user_can( 'edit_post', $post_id ) && current_user_can( 'mgseo_edit_meta' );
	}
}
