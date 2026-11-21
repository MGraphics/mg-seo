<?php
/**
 * Authenticated Google API client.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\SearchConsole;

use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Thin wrapper over wp_remote_request. Refuses to make any request unless the module is enabled
 * AND an account is connected.
 */
final class Client {

	public const SITES_URL       = 'https://www.googleapis.com/webmasters/v3/sites';
	public const ANALYTICS_URL   = 'https://www.googleapis.com/webmasters/v3/sites/%s/searchAnalytics/query';
	public const INSPECT_URL     = 'https://searchconsole.googleapis.com/v1/urlInspection/index:inspect';
	public const INDEXING_URL    = 'https://indexing.googleapis.com/v3/urlNotifications:publish';
	public const SITES_OPTION    = 'mgseo_gsc_sites';
	public const DEFAULT_TIMEOUT = 30;

	/**
	 * Whether calls are allowed at all.
	 */
	public static function ready(): bool {
		return Module::enabled() && OAuth::connected();
	}

	/**
	 * JSON request to a Google API.
	 *
	 * @param string     $method HTTP method.
	 * @param string     $url    URL.
	 * @param array|null $body   JSON body.
	 * @param bool       $retry  Retry once after a 401 with a refreshed token.
	 * @return array<string, mixed>|\WP_Error Decoded JSON.
	 */
	public static function request( string $method, string $url, ?array $body = null, bool $retry = true ): array|\WP_Error {
		if ( ! self::ready() ) {
			return new \WP_Error( 'mgseo_gsc_not_connected', __( 'Search Console is not connected.', 'mg-seo' ) );
		}
		$token = OAuth::access_token();
		if ( is_wp_error( $token ) ) {
			return $token;
		}
		$args = array(
			'method'  => $method,
			'timeout' => (int) apply_filters( 'mgseo/gsc/timeout', self::DEFAULT_TIMEOUT ),
			'headers' => array(
				'Authorization' => 'Bearer ' . $token,
				'Accept'        => 'application/json',
			),
		);
		if ( null !== $body ) {
			$args['headers']['Content-Type'] = 'application/json';
			$args['body']                    = (string) wp_json_encode( $body );
		}
		$response = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();
		if ( 401 === $code && $retry ) {
			$refreshed = OAuth::refresh();
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
			return self::request( $method, $url, $body, false );
		}
		if ( $code < 200 || $code >= 300 ) {
			$message = (string) ( $data['error']['message'] ?? wp_remote_retrieve_response_message( $response ) );
			$status  = (string) ( $data['error']['status'] ?? 'http_' . $code );
			/* translators: 1: HTTP status code, 2: Google error message */
			return new \WP_Error( 'mgseo_gsc_' . strtolower( $status ), sprintf( __( 'Google API error (HTTP %1$d): %2$s', 'mg-seo' ), $code, $message ), array( 'status' => $code ) );
		}
		return $data;
	}

	/**
	 * List Search Console properties of the connected account and cache them.
	 *
	 * @return array<int, array{url: string, permission: string, type: string}>|\WP_Error
	 */
	public static function fetch_sites(): array|\WP_Error {
		$data = self::request( 'GET', self::SITES_URL );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$sites = array();
		foreach ( (array) ( $data['siteEntry'] ?? array() ) as $entry ) {
			$url        = (string) ( $entry['siteUrl'] ?? '' );
			$permission = (string) ( $entry['permissionLevel'] ?? '' );
			if ( '' === $url || 'siteUnverifiedUser' === $permission ) {
				continue;
			}
			$sites[] = array(
				'url'        => $url,
				'permission' => $permission,
				'type'       => str_starts_with( $url, 'sc-domain:' ) ? 'domain' : 'prefix',
			);
		}
		usort( $sites, static fn( $a, $b ) => strcmp( $a['url'], $b['url'] ) );
		update_option( self::SITES_OPTION, $sites, false );
		if ( '' === self::property() ) {
			$match = self::match_property( $sites, home_url( '/' ) );
			if ( '' !== $match ) {
				Options::set( 'gsc', 'property', $match );
			}
		}
		return $sites;
	}

	/**
	 * Cached properties.
	 *
	 * @return array<int, array{url: string, permission: string, type: string}>
	 */
	public static function sites(): array {
		$sites = get_option( self::SITES_OPTION, array() );
		return is_array( $sites ) ? $sites : array();
	}

	/**
	 * Selected property (siteUrl).
	 */
	public static function property(): string {
		return (string) Options::get( 'gsc', 'property', '' );
	}

	/**
	 * Best property for a URL: the longest matching URL-prefix property, else a matching domain property.
	 *
	 * @param array  $sites Sites.
	 * @param string $url   URL.
	 */
	public static function match_property( array $sites, string $url ): string {
		$best = '';
		foreach ( $sites as $site ) {
			if ( 'prefix' === $site['type'] && self::url_in_property( $url, $site['url'] ) && strlen( $site['url'] ) > strlen( $best ) ) {
				$best = $site['url'];
			}
		}
		if ( '' !== $best ) {
			return $best;
		}
		foreach ( $sites as $site ) {
			if ( 'domain' === $site['type'] && self::url_in_property( $url, $site['url'] ) ) {
				return $site['url'];
			}
		}
		return '';
	}

	/**
	 * Whether a URL belongs to a property (domain property: host or subdomain; URL prefix: string prefix).
	 *
	 * @param string $url      URL.
	 * @param string $property Property siteUrl.
	 */
	public static function url_in_property( string $url, string $property ): bool {
		if ( str_starts_with( $property, 'sc-domain:' ) ) {
			$domain = strtolower( substr( $property, 10 ) );
			$host   = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
			return $host === $domain || str_ends_with( $host, '.' . $domain );
		}
		return '' !== $property && str_starts_with( $url, $property );
	}

	/**
	 * Search Analytics query for the selected property.
	 *
	 * @param array<string, mixed> $body Request body.
	 * @return array<string, mixed>|\WP_Error
	 */
	public static function search_analytics( array $body ): array|\WP_Error {
		$property = self::property();
		if ( '' === $property ) {
			return new \WP_Error( 'mgseo_gsc_no_property', __( 'Select a Search Console property first.', 'mg-seo' ) );
		}
		return self::request( 'POST', sprintf( self::ANALYTICS_URL, rawurlencode( $property ) ), $body );
	}
}
