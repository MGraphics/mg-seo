<?php
/**
 * URL Inspection API and optional Indexing API.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\SearchConsole;

use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * On-demand URL inspection (user action only), cached per post in `_mgseo_gsc_inspection`.
 */
final class Inspection {

	public const META          = '_mgseo_gsc_inspection';
	public const INDEXING_META = '_mgseo_gsc_indexing';

	/**
	 * Inspect a URL.
	 *
	 * @param string $url URL.
	 * @return array<string, mixed>|\WP_Error Parsed result.
	 */
	public static function inspect( string $url ): array|\WP_Error {
		$property = Client::property();
		if ( '' === $property ) {
			return new \WP_Error( 'mgseo_gsc_no_property', __( 'Select a Search Console property first.', 'mg-seo' ) );
		}
		if ( ! Client::url_in_property( $url, $property ) ) {
			return new \WP_Error( 'mgseo_gsc_outside_property', __( 'This URL does not belong to the selected Search Console property.', 'mg-seo' ) );
		}
		$data = Client::request(
			'POST',
			Client::INSPECT_URL,
			array(
				'inspectionUrl' => $url,
				'siteUrl'       => $property,
				'languageCode'  => str_replace( '_', '-', get_user_locale() ),
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		return self::parse( $data ) + array(
			'url'        => $url,
			'checked_at' => time(),
		);
	}

	/**
	 * Flatten an inspection response.
	 *
	 * @param array $data API response.
	 * @return array<string, mixed>
	 */
	public static function parse( array $data ): array {
		$result = (array) ( $data['inspectionResult'] ?? array() );
		$index  = (array) ( $result['indexStatusResult'] ?? array() );
		$mobile = (array) ( $result['mobileUsabilityResult'] ?? array() );
		$rich   = (array) ( $result['richResultsResult'] ?? array() );
		$types  = array();
		foreach ( (array) ( $rich['detectedItems'] ?? array() ) as $item ) {
			if ( ! empty( $item['richResultType'] ) ) {
				$types[] = (string) $item['richResultType'];
			}
		}
		return array(
			'verdict'          => (string) ( $index['verdict'] ?? 'VERDICT_UNSPECIFIED' ),
			'coverage'         => (string) ( $index['coverageState'] ?? '' ),
			'indexing_state'   => (string) ( $index['indexingState'] ?? '' ),
			'robots_txt'       => (string) ( $index['robotsTxtState'] ?? '' ),
			'page_fetch'       => (string) ( $index['pageFetchState'] ?? '' ),
			'last_crawl'       => (string) ( $index['lastCrawlTime'] ?? '' ),
			'crawled_as'       => (string) ( $index['crawledAs'] ?? '' ),
			'google_canonical' => (string) ( $index['googleCanonical'] ?? '' ),
			'user_canonical'   => (string) ( $index['userCanonical'] ?? '' ),
			'sitemaps'         => array_values( array_map( 'strval', (array) ( $index['sitemap'] ?? array() ) ) ),
			'referring_urls'   => array_values( array_map( 'strval', (array) ( $index['referringUrls'] ?? array() ) ) ),
			'mobile_verdict'   => (string) ( $mobile['verdict'] ?? '' ),
			'rich_verdict'     => (string) ( $rich['verdict'] ?? '' ),
			'rich_types'       => array_values( array_unique( $types ) ),
			'link'             => (string) ( $result['inspectionResultLink'] ?? '' ),
			'indexed'          => 'PASS' === ( $index['verdict'] ?? '' ),
		);
	}

	/**
	 * Inspect a post and cache the result.
	 *
	 * @param int $post_id Post id.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function inspect_post( int $post_id ): array|\WP_Error {
		$url = (string) get_permalink( $post_id );
		if ( '' === $url || 'publish' !== get_post_status( $post_id ) ) {
			return new \WP_Error( 'mgseo_gsc_not_public', __( 'Only published posts can be inspected.', 'mg-seo' ) );
		}
		$result = self::inspect( $url );
		if ( ! is_wp_error( $result ) ) {
			update_post_meta( $post_id, self::META, $result );
		}
		return $result;
	}

	/**
	 * Cached inspection of a post.
	 *
	 * @param int $post_id Post id.
	 * @return array<string, mixed>|null
	 */
	public static function cached( int $post_id ): ?array {
		$data = get_post_meta( $post_id, self::META, true );
		return is_array( $data ) && $data ? $data : null;
	}

	/**
	 * Whether the Indexing API feature is enabled and granted.
	 */
	public static function indexing_available(): bool {
		return (bool) Options::get( 'gsc', 'indexing_api', false ) && OAuth::has_scope( OAuth::SCOPE_INDEX );
	}

	/**
	 * Notify the Indexing API about an updated or deleted URL.
	 *
	 * @param string $url  URL.
	 * @param string $type URL_UPDATED|URL_DELETED.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function submit( string $url, string $type = 'URL_UPDATED' ): array|\WP_Error {
		if ( ! self::indexing_available() ) {
			return new \WP_Error( 'mgseo_gsc_indexing_off', __( 'The Indexing API feature is off or was not granted. Enable it and reconnect your Google account.', 'mg-seo' ) );
		}
		$type = 'URL_DELETED' === $type ? 'URL_DELETED' : 'URL_UPDATED';
		$data = Client::request(
			'POST',
			Client::INDEXING_URL,
			array(
				'url'  => $url,
				'type' => $type,
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$meta = (array) ( $data['urlNotificationMetadata'] ?? array() );
		return array(
			'url'          => (string) ( $meta['url'] ?? $url ),
			'type'         => $type,
			'notify_time'  => (string) ( $meta['latestUpdate']['notifyTime'] ?? '' ),
			'submitted_at' => time(),
		);
	}
}
