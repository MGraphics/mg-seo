<?php
/**
 * Broken internal link detection (local, no HTTP crawling).
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Links;

use MGSEO\Context;

defined( 'ABSPATH' ) || exit;

/**
 * An internal link counts as broken when its URL resolves to no post, no archive (term, author, post type,
 * date, feed, pagination), no existing upload file and is not covered by a redirect.
 */
final class Broken {

	/**
	 * Whether an internal URL is broken.
	 *
	 * @param string $url Absolute internal URL.
	 */
	public static function is_broken( string $url ): bool {
		if ( Parser::resolve( $url ) ) {
			return false;
		}
		/**
		 * Whether a URL is handled by a redirect. The Redirections module (or any plugin) can hook here.
		 *
		 * @param bool   $redirected Redirected.
		 * @param string $url        URL.
		 */
		if ( apply_filters( 'mgseo/links/is_redirected', false, $url ) ) {
			return false;
		}
		$home_path = trim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		$path      = trim( rawurldecode( (string) wp_parse_url( $url, PHP_URL_PATH ) ), '/' );
		if ( '' !== $home_path && str_starts_with( $path . '/', $home_path . '/' ) ) {
			$path = trim( substr( $path, strlen( $home_path ) ), '/' );
		}
		$query = (string) wp_parse_url( $url, PHP_URL_QUERY );

		if ( '' === $path ) {
			// Home page, or query-string URLs such as ?s= or ?cat=; ?p=/?page_id= that did not resolve are broken.
			return (bool) preg_match( '/(^|&)(p|page_id|attachment_id)=\d+/', $query );
		}
		if ( preg_match( '#^(wp-admin|wp-json|wp-login\.php|wp-includes|xmlrpc\.php)#', $path ) ) {
			return false;
		}
		if ( preg_match( '#\.[a-z0-9]{2,5}$#i', $path ) ) {
			return self::file_missing( $url );
		}
		$segments = array_values( array_filter( explode( '/', $path ), 'strlen' ) );
		// Strip pagination / feed suffixes.
		while ( $segments && ( in_array( end( $segments ), array( 'feed', 'rss', 'rss2', 'atom', 'rdf', 'amp', 'embed', 'trackback' ), true ) || ctype_digit( (string) end( $segments ) ) ) ) {
			$last = array_pop( $segments );
			if ( ctype_digit( (string) $last ) && $segments && 'page' === end( $segments ) ) {
				array_pop( $segments );
			}
		}
		if ( ! $segments ) {
			return false;
		}
		// Date archives.
		if ( preg_match( '#^\d{4}(/\d{2}){0,2}$#', implode( '/', $segments ) ) ) {
			return false;
		}
		$last = sanitize_title( (string) end( $segments ) );
		foreach ( Context::taxonomies() as $taxonomy ) {
			if ( get_term_by( 'slug', $last, $taxonomy->name ) ) {
				return false;
			}
		}
		$author_base = isset( $GLOBALS['wp_rewrite'] ) ? (string) $GLOBALS['wp_rewrite']->author_base : 'author';
		if ( in_array( $segments[0] ?? '', array( 'author', $author_base ), true ) ) {
			if ( get_user_by( 'slug', $last ) ) {
				return false;
			}
		}
		foreach ( Context::post_types() as $type ) {
			$archive = get_post_type_archive_link( $type->name );
			if ( $archive && trailingslashit( (string) wp_parse_url( $archive, PHP_URL_PATH ) ) === trailingslashit( '/' . ltrim( (string) wp_parse_url( $url, PHP_URL_PATH ), '/' ) ) ) {
				return false;
			}
		}
		/**
		 * Filter the broken verdict for URLs MG SEO could not resolve.
		 *
		 * @param bool   $broken Broken.
		 * @param string $url    URL.
		 */
		return (bool) apply_filters( 'mgseo/links/is_broken', true, $url );
	}

	/**
	 * For file URLs inside the uploads directory, check the file exists. Other files are assumed fine.
	 *
	 * @param string $url URL.
	 */
	private static function file_missing( string $url ): bool {
		$uploads = wp_get_upload_dir();
		$base    = (string) ( $uploads['baseurl'] ?? '' );
		$plain   = (string) strtok( $url, '?' );
		if ( '' !== $base && str_starts_with( set_url_scheme( $plain, 'http' ), set_url_scheme( $base, 'http' ) ) ) {
			$relative = ltrim( rawurldecode( substr( set_url_scheme( $plain, 'http' ), strlen( set_url_scheme( $base, 'http' ) ) ) ), '/' );
			if ( str_contains( $relative, '..' ) ) {
				return true;
			}
			return ! file_exists( trailingslashit( (string) $uploads['basedir'] ) . $relative );
		}
		return false;
	}

	/**
	 * Broken links in published content.
	 *
	 * @param int $limit Max rows examined.
	 * @return array<int, array{post_id: int, url: string}>
	 */
	public static function find( int $limit = 2000 ): array {
		$checked = array();
		$out     = array();
		foreach ( Store::unresolved( $limit ) as $row ) {
			$url = (string) $row->url;
			if ( ! isset( $checked[ $url ] ) ) {
				if ( count( $checked ) >= 500 ) {
					break;
				}
				$checked[ $url ] = self::is_broken( $url );
			}
			if ( $checked[ $url ] ) {
				$out[] = array(
					'post_id' => (int) $row->post_id,
					'url'     => $url,
				);
			}
		}
		return $out;
	}
}
