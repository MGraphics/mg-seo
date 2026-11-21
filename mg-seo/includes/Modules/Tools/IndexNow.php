<?php
/**
 * IndexNow submissions (opt-in).
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Tools;

use MGSEO\Helpers;
use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * When the user turns IndexNow on, a key is generated and served at /<key>.txt and the URLs of
 * published/updated posts of the selected types are sent to the IndexNow API (Bing, Yandex, Seznam, Naver …).
 * Nothing is sent while the feature is off (default).
 */
final class IndexNow {

	public const ENDPOINT = 'https://api.indexnow.org/indexnow';
	public const LOG      = 'mgseo_indexnow_log';
	public const RECENT   = 'mgseo_indexnow_recent';

	/**
	 * URLs queued during this request.
	 *
	 * @var array<string, true>
	 */
	private static array $queue = array();

	/**
	 * Register hooks.
	 */
	public static function boot(): void {
		add_action( 'parse_request', array( self::class, 'serve_key' ), 0 );
		add_action( 'transition_post_status', array( self::class, 'on_transition' ), 20, 3 );
		add_action( 'shutdown', array( self::class, 'flush_queue' ) );
		add_action( 'mgseo/settings_saved', array( self::class, 'settings_saved' ), 10, 2 );
		add_action( 'admin_init', array( self::class, 'register_bulk_actions' ) );
	}

	/**
	 * Whether IndexNow is on.
	 */
	public static function enabled(): bool {
		return (bool) Options::get( 'tools', 'indexnow_enabled', false );
	}

	/**
	 * API key (generated on first use).
	 */
	public static function key(): string {
		$key = (string) Options::get( 'tools', 'indexnow_key', '' );
		if ( ! preg_match( '/^[a-zA-Z0-9-]{8,128}$/', $key ) ) {
			$key = bin2hex( random_bytes( 16 ) );
			Options::set( 'tools', 'indexnow_key', $key );
		}
		return $key;
	}

	/**
	 * Public URL of the key file.
	 */
	public static function key_url(): string {
		return home_url( '/' . self::key() . '.txt' );
	}

	/**
	 * Generate the key as soon as the feature is enabled.
	 *
	 * @param string $module_id Module.
	 * @param array  $values    Values.
	 */
	public static function settings_saved( string $module_id, array $values ): void {
		if ( 'tools' === $module_id && ! empty( $values['indexnow_enabled'] ) ) {
			self::key();
		}
	}

	/**
	 * Serve /<key>.txt.
	 */
	public static function serve_key(): void {
		$body = self::key_file_body( Helpers::request_path() );
		if ( null === $body ) {
			return;
		}
		if ( ! headers_sent() ) {
			status_header( 200 );
			header( 'Content-Type: text/plain; charset=utf-8' );
			header( 'X-Robots-Tag: noindex' );
		}
		echo esc_html( $body );
		exit;
	}

	/**
	 * Body of the key file for a request path, or null when the path is not the key file (or IndexNow is off).
	 *
	 * @param string $path Request path without slashes.
	 */
	public static function key_file_body( string $path ): ?string {
		if ( ! self::enabled() || ! str_ends_with( $path, '.txt' ) ) {
			return null;
		}
		return self::key() . '.txt' === $path ? self::key() : null;
	}

	/**
	 * Post types that are submitted automatically.
	 *
	 * @return string[]
	 */
	public static function post_types(): array {
		return array_map( 'strval', (array) Options::get( 'tools', 'indexnow_post_types', array( 'post', 'page' ) ) );
	}

	/**
	 * Queue a post URL when it is published or updated.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public static function on_transition( string $new_status, string $old_status, \WP_Post $post ): void {
		unset( $old_status );
		if ( 'publish' !== $new_status || ! self::enabled() || ( defined( 'WP_IMPORTING' ) && WP_IMPORTING ) ) {
			return;
		}
		if ( wp_is_post_revision( $post ) || wp_is_post_autosave( $post ) || '' !== $post->post_password ) {
			return;
		}
		if ( ! in_array( $post->post_type, self::post_types(), true ) || ! is_post_type_viewable( $post->post_type ) || Llms::is_noindex( $post ) ) {
			return;
		}
		$url = (string) get_permalink( $post );
		if ( '' !== $url ) {
			self::$queue[ $url ] = true;
		}
	}

	/**
	 * URLs queued in this request (tests).
	 *
	 * @return string[]
	 */
	public static function queued(): array {
		return array_keys( self::$queue );
	}

	/**
	 * Submit queued URLs at the end of the request (non-blocking).
	 */
	public static function flush_queue(): void {
		if ( ! self::$queue ) {
			return;
		}
		$urls        = array_keys( self::$queue );
		self::$queue = array();
		$recent      = get_transient( self::RECENT );
		$recent      = is_array( $recent ) ? $recent : array();
		$now         = time();
		$recent      = array_filter( $recent, static fn( $time ) => $now - (int) $time < 5 * MINUTE_IN_SECONDS );
		$urls        = array_values( array_filter( $urls, static fn( $url ) => ! isset( $recent[ $url ] ) ) );
		if ( ! $urls ) {
			return;
		}
		foreach ( $urls as $url ) {
			$recent[ $url ] = $now;
		}
		set_transient( self::RECENT, $recent, 10 * MINUTE_IN_SECONDS );
		self::submit( $urls, false, 'auto' );
	}

	/**
	 * Send URLs to IndexNow.
	 *
	 * @param string[] $urls     URLs (must be on this site's host).
	 * @param bool     $blocking Wait for the response (manual submissions).
	 * @param string   $source   auto|manual|bulk (for the log).
	 * @return array{ok: bool, code: int, message: string, count: int}
	 */
	public static function submit( array $urls, bool $blocking = false, string $source = 'manual' ): array {
		$host = (string) wp_parse_url( home_url(), PHP_URL_HOST );
		$urls = array_values(
			array_unique(
				array_filter(
					array_map( 'esc_url_raw', array_map( 'trim', $urls ) ),
					static fn( $url ) => '' !== $url && strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) ) === strtolower( $host )
				)
			)
		);
		$urls = array_slice( $urls, 0, 10000 );
		if ( ! self::enabled() ) {
			return array(
				'ok'      => false,
				'code'    => 0,
				'message' => __( 'IndexNow is turned off.', 'mg-seo' ),
				'count'   => 0,
			);
		}
		if ( ! $urls ) {
			return array(
				'ok'      => false,
				'code'    => 0,
				'message' => __( 'No valid URLs of this site to submit.', 'mg-seo' ),
				'count'   => 0,
			);
		}
		/**
		 * Filter the IndexNow endpoint.
		 *
		 * @param string $endpoint Endpoint URL.
		 */
		$endpoint = (string) apply_filters( 'mgseo/indexnow/endpoint', self::ENDPOINT );
		$response = wp_remote_post(
			$endpoint,
			array(
				'timeout'  => $blocking ? 10 : 3,
				'blocking' => $blocking,
				'headers'  => array( 'Content-Type' => 'application/json; charset=utf-8' ),
				'body'     => (string) wp_json_encode(
					array(
						'host'        => $host,
						'key'         => self::key(),
						'keyLocation' => self::key_url(),
						'urlList'     => $urls,
					),
					JSON_UNESCAPED_SLASHES
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			$result = array(
				'ok'      => false,
				'code'    => 0,
				'message' => $response->get_error_message(),
				'count'   => count( $urls ),
			);
		} elseif ( ! $blocking ) {
			$result = array(
				'ok'      => true,
				'code'    => 0,
				'message' => __( 'Sent', 'mg-seo' ),
				'count'   => count( $urls ),
			);
		} else {
			$code   = (int) wp_remote_retrieve_response_code( $response );
			$result = array(
				'ok'      => $code >= 200 && $code < 300,
				'code'    => $code,
				'message' => self::describe_code( $code ),
				'count'   => count( $urls ),
			);
		}
		self::log( $urls, $source, $result );
		return $result;
	}

	/**
	 * Human readable IndexNow response code.
	 *
	 * @param int $code HTTP code.
	 */
	public static function describe_code( int $code ): string {
		return match ( $code ) {
			200     => __( 'OK: URLs submitted.', 'mg-seo' ),
			202     => __( 'Accepted: the key will be validated later.', 'mg-seo' ),
			400     => __( 'Bad request: invalid format.', 'mg-seo' ),
			403     => __( 'Forbidden: the key file was not found or does not match.', 'mg-seo' ),
			422     => __( 'Unprocessable: the URLs do not belong to this host or the key does not match.', 'mg-seo' ),
			429     => __( 'Too many requests: try again later.', 'mg-seo' ),
			/* translators: %d: HTTP status code */
			default => sprintf( __( 'HTTP %d', 'mg-seo' ), $code ),
		};
	}

	/**
	 * Append to the submission log (last 50 entries).
	 *
	 * @param string[] $urls   URLs.
	 * @param string   $source Source.
	 * @param array    $result Result.
	 */
	private static function log( array $urls, string $source, array $result ): void {
		$log = get_option( self::LOG, array() );
		$log = is_array( $log ) ? $log : array();
		array_unshift(
			$log,
			array(
				'time'    => time(),
				'source'  => $source,
				'count'   => count( $urls ),
				'urls'    => array_slice( $urls, 0, 20 ),
				'ok'      => (bool) $result['ok'],
				'code'    => (int) $result['code'],
				'message' => (string) $result['message'],
			)
		);
		update_option( self::LOG, array_slice( $log, 0, 50 ), false );
	}

	/**
	 * Submission log.
	 *
	 * @return array<int, array>
	 */
	public static function get_log(): array {
		$log = get_option( self::LOG, array() );
		return is_array( $log ) ? $log : array();
	}

	/**
	 * "Submit to IndexNow" bulk action on post lists of the selected types.
	 */
	public static function register_bulk_actions(): void {
		if ( ! self::enabled() || ! current_user_can( 'mgseo_manage_settings' ) ) {
			return;
		}
		foreach ( self::post_types() as $post_type ) {
			add_filter(
				'bulk_actions-edit-' . $post_type,
				static function ( array $actions ): array {
					$actions['mgseo_indexnow'] = __( 'Submit to IndexNow', 'mg-seo' );
					return $actions;
				}
			);
			add_filter( 'handle_bulk_actions-edit-' . $post_type, array( self::class, 'handle_bulk' ), 10, 3 );
		}
	}

	/**
	 * Handle the bulk action (WordPress verified the bulk-posts nonce).
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param int[]  $ids      Post ids.
	 */
	public static function handle_bulk( string $redirect, string $action, array $ids ): string {
		if ( 'mgseo_indexnow' !== $action || ! current_user_can( 'mgseo_manage_settings' ) ) {
			return $redirect;
		}
		$urls = array();
		foreach ( $ids as $id ) {
			$post = get_post( (int) $id );
			if ( $post && 'publish' === $post->post_status ) {
				$urls[] = (string) get_permalink( $post );
			}
		}
		$result = self::submit( $urls, true, 'bulk' );
		return add_query_arg( 'mgseo-message', rawurlencode( 'IndexNow: ' . $result['message'] ), $redirect );
	}
}
