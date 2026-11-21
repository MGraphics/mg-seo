<?php
/**
 * Link extraction from post content.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Links;

defined( 'ABSPATH' ) || exit;

/**
 * Finds <a href> links with WP_HTML_Tag_Processor and classifies them as internal or external.
 */
final class Parser {

	/**
	 * Resolved post ids per URL (request cache).
	 *
	 * @var array<string, int>
	 */
	private static array $resolved = array();

	/**
	 * Extract links.
	 *
	 * @param string $html Content.
	 * @param string $base Absolute URL the content lives at (for relative links).
	 * @return array<int, array{url: string, type: string, target: int}>
	 */
	public static function parse( string $html, string $base = '' ): array {
		$base  = '' !== $base ? $base : home_url( '/' );
		$links = array();
		if ( '' === trim( $html ) || false === stripos( $html, '<a' ) ) {
			return $links;
		}
		$processor = new \WP_HTML_Tag_Processor( $html );
		while ( $processor->next_tag( array( 'tag_name' => 'a' ) ) ) {
			$href = $processor->get_attribute( 'href' );
			if ( ! is_string( $href ) ) {
				continue;
			}
			$url = self::absolute( $href, $base );
			if ( null === $url ) {
				continue;
			}
			$internal = self::is_internal( $url );
			$links[]  = array(
				'url'    => $url,
				'type'   => $internal ? 'internal' : 'external',
				'target' => $internal ? self::resolve( $url ) : 0,
			);
		}
		return $links;
	}

	/**
	 * Turn an href into an absolute http(s) URL without fragment; null for links that are not page links
	 * (fragments, mailto:, tel:, javascript:, data: …).
	 *
	 * @param string $href Raw href (entities already decoded).
	 * @param string $base Base URL.
	 */
	public static function absolute( string $href, string $base ): ?string {
		$href = trim( $href );
		if ( '' === $href || str_starts_with( $href, '#' ) ) {
			return null;
		}
		if ( preg_match( '#^([a-z][a-z0-9+.\-]*):#i', $href, $m ) && ! in_array( strtolower( $m[1] ), array( 'http', 'https' ), true ) ) {
			return null;
		}
		if ( str_starts_with( $href, '//' ) ) {
			$href = ( is_ssl() || str_starts_with( home_url(), 'https' ) ? 'https:' : 'http:' ) . $href;
		} elseif ( ! preg_match( '#^https?://#i', $href ) ) {
			$href = \WP_Http::make_absolute_url( $href, $base );
		}
		$href = (string) strtok( $href, '#' );
		if ( '' === $href || ! wp_parse_url( $href, PHP_URL_HOST ) ) {
			return null;
		}
		return $href;
	}

	/**
	 * Host without a leading www.
	 *
	 * @param string $url URL.
	 */
	private static function host( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return str_starts_with( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Whether a URL points to this site.
	 *
	 * @param string $url Absolute URL.
	 */
	public static function is_internal( string $url ): bool {
		$hosts = array( self::host( home_url( '/' ) ), self::host( site_url( '/' ) ) );
		/**
		 * Filter hosts treated as internal (without "www.").
		 *
		 * @param string[] $hosts Hosts.
		 */
		$hosts = (array) apply_filters( 'mgseo/links/internal_hosts', array_unique( $hosts ) );
		return in_array( self::host( $url ), $hosts, true );
	}

	/**
	 * Post id an internal URL points to (0 when it is not a post).
	 *
	 * @param string $url Absolute URL.
	 */
	public static function resolve( string $url ): int {
		if ( ! isset( self::$resolved[ $url ] ) ) {
			$id = url_to_postid( $url );
			if ( ! $id && trailingslashit( $url ) === trailingslashit( home_url( '/' ) ) && 'page' === get_option( 'show_on_front' ) ) {
				$id = (int) get_option( 'page_on_front' );
			}
			if ( $id && ! get_post( $id ) ) {
				// url_to_postid() trusts ?p=123 without checking that the post exists.
				$id = 0;
			}
			self::$resolved[ $url ] = (int) $id;
		}
		return self::$resolved[ $url ];
	}

	/**
	 * Clear the resolve cache (tests, permalink changes).
	 */
	public static function reset(): void {
		self::$resolved = array();
	}
}
