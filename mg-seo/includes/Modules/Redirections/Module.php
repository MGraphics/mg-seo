<?php
/**
 * Redirections & 404 Monitor module.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Redirections;

use MGSEO\Helpers;
use MGSEO\Module as Base;
use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * 301/302/307/410/451 redirections with exact/contains/start/end/regex sources, automatic redirects
 * on slug changes, fallback behaviour for unmatched 404s and a 404 log.
 */
final class Module extends Base {

	public const DB_VERSION = '1';

	/**
	 * Redirection matched on `init`, applied on `template_redirect` (410/451 need the template).
	 *
	 * @var array|null
	 */
	private ?array $pending = null;

	/**
	 * Hits to record at shutdown: id => count.
	 *
	 * @var array<int, int>
	 */
	private array $hits = array();

	/**
	 * 404 to log at shutdown.
	 *
	 * @var array|null
	 */
	private ?array $log = null;

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'redirections';
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'maybe_install' ), 1 );
		add_action( 'init', array( $this, 'match_early' ), 2 );
		add_action( 'template_redirect', array( $this, 'template_redirect' ), 0 );
		add_action( 'shutdown', array( $this, 'shutdown' ) );
		add_action( 'mgseo_daily', array( Log404::class, 'cleanup' ) );
		add_action( 'mgseo/settings_saved', array( $this, 'settings_saved' ) );
		AutoRedirect::boot();
		if ( is_admin() ) {
			Admin::boot();
		}
	}

	/**
	 * Settings schema.
	 */
	public function settings(): array {
		return array(
			array(
				'id'     => 'general',
				'title'  => __( 'Redirections', 'mg-seo' ),
				'fields' => array(
					array(
						'id'      => 'query_mode',
						'type'    => 'select',
						'label'   => __( 'Query strings', 'mg-seo' ),
						'options' => array(
							'ignore' => __( 'Ignore: match on the path (sources that contain "?" still need that query)', 'mg-seo' ),
							'exact'  => __( 'Exact: the query string must match the source', 'mg-seo' ),
							'pass'   => __( 'Pass through: match on the path and append the query string to the destination', 'mg-seo' ),
						),
						'default' => 'ignore',
					),
					array(
						'id'      => 'match_timing',
						'type'    => 'select',
						'label'   => __( 'When to match', 'mg-seo' ),
						'options' => array(
							'template_redirect' => __( 'After WordPress parsed the request (recommended)', 'mg-seo' ),
							'init'              => __( 'Early, before the main query (faster; also for URLs WordPress does not know)', 'mg-seo' ),
						),
						'default' => 'template_redirect',
					),
					array(
						'id'      => 'fallback',
						'type'    => 'select',
						'label'   => __( 'Fallback behaviour', 'mg-seo' ),
						'options' => array(
							'default' => __( 'Default 404 page', 'mg-seo' ),
							'home'    => __( 'Redirect to the homepage', 'mg-seo' ),
							'custom'  => __( 'Redirect to a custom URL', 'mg-seo' ),
						),
						'default' => 'default',
						'desc'    => __( 'What happens with 404 requests that match no redirection.', 'mg-seo' ),
					),
					array(
						'id'      => 'fallback_url',
						'type'    => 'url',
						'label'   => __( 'Fallback URL', 'mg-seo' ),
						'default' => '',
						'desc'    => __( 'Used when the fallback behaviour is "Redirect to a custom URL".', 'mg-seo' ),
					),
					array(
						'id'      => 'fallback_type',
						'type'    => 'select',
						'label'   => __( 'Fallback redirect type', 'mg-seo' ),
						'options' => array(
							'301' => __( '301 Moved Permanently', 'mg-seo' ),
							'302' => __( '302 Found', 'mg-seo' ),
							'307' => __( '307 Temporary Redirect', 'mg-seo' ),
						),
						'default' => '301',
					),
					array(
						'id'      => 'default_type',
						'type'    => 'select',
						'label'   => __( 'Default redirection type', 'mg-seo' ),
						'options' => self::type_labels(),
						'default' => '301',
					),
					array(
						'id'           => 'auto_redirect',
						'type'         => 'toggle',
						'label'        => __( 'Auto redirect', 'mg-seo' ),
						'toggle_label' => __( 'Create a 301 redirect when the URL of a published post, page or term changes', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'      => 'trash_behavior',
						'type'    => 'select',
						'label'   => __( 'Trashed posts', 'mg-seo' ),
						'options' => array(
							'none'   => __( 'Do nothing (404)', 'mg-seo' ),
							'410'    => __( 'Create a 410 Gone entry', 'mg-seo' ),
							'parent' => __( 'Redirect to the parent page or category', 'mg-seo' ),
							'home'   => __( 'Redirect to the homepage', 'mg-seo' ),
						),
						'default' => 'none',
						'desc'    => __( 'Applied when a published post is moved to the trash; restoring it removes the entry.', 'mg-seo' ),
					),
					array(
						'id'           => 'count_hits',
						'type'         => 'toggle',
						'label'        => __( 'Hit counter', 'mg-seo' ),
						'toggle_label' => __( 'Count hits and the last access time of redirections', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'           => 'debug',
						'type'         => 'toggle',
						'label'        => __( 'Debug redirections', 'mg-seo' ),
						'toggle_label' => __( 'Show an interstitial page to users who manage redirections instead of redirecting them', 'mg-seo' ),
						'default'      => false,
					),
				),
			),
			array(
				'id'     => 'monitor',
				'title'  => __( '404 Monitor', 'mg-seo' ),
				'fields' => array(
					array(
						'id'           => 'log_404',
						'type'         => 'toggle',
						'label'        => __( '404 log', 'mg-seo' ),
						'toggle_label' => __( 'Record requests that end in a 404 error', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'      => 'log_mode',
						'show_if' => 'log_404',
						'type'    => 'select',
						'label'   => __( 'Mode', 'mg-seo' ),
						'options' => array(
							'simple'   => __( 'Simple: one row per URL with a hit counter (no IP addresses)', 'mg-seo' ),
							'advanced' => __( 'Advanced: one row per hit with referer, user agent and anonymised IP', 'mg-seo' ),
						),
						'default' => 'simple',
					),
					array(
						'id'      => 'log_exclude',
						'show_if' => 'log_404',
						'type'    => 'textarea',
						'rows'    => 6,
						'label'   => __( 'Exclude', 'mg-seo' ),
						'default' => Log404::DEFAULT_EXCLUDE,
						'desc'    => __( 'One pattern per line. Patterns with * are matched against the whole path (e.g. *.map), others match any part of the URL.', 'mg-seo' ),
					),
					array(
						'id'           => 'log_ignore_bots',
						'show_if'      => 'log_404',
						'type'         => 'toggle',
						'label'        => __( 'Bots', 'mg-seo' ),
						'toggle_label' => __( 'Do not log requests from crawlers, scripts and empty user agents', 'mg-seo' ),
						'default'      => false,
					),
					array(
						'id'           => 'log_ignore_query',
						'show_if'      => 'log_404',
						'type'         => 'toggle',
						'label'        => __( 'Query strings', 'mg-seo' ),
						'toggle_label' => __( 'Log URLs without their query string', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'      => 'log_max_rows',
						'show_if' => 'log_404',
						'type'    => 'number',
						'label'   => __( 'Maximum rows', 'mg-seo' ),
						'default' => 5000,
						'min'     => 0,
						'desc'    => __( 'Older entries are removed daily. 0 = no limit.', 'mg-seo' ),
					),
					array(
						'id'      => 'log_retention',
						'show_if' => 'log_404',
						'type'    => 'number',
						'label'   => __( 'Keep entries for (days)', 'mg-seo' ),
						'default' => 30,
						'min'     => 0,
						'desc'    => __( '0 = keep until the row limit is reached.', 'mg-seo' ),
					),
				),
			),
		);
	}

	/**
	 * Redirection type labels.
	 *
	 * @return array<string, string>
	 */
	public static function type_labels(): array {
		return array(
			'301' => __( '301 Moved Permanently', 'mg-seo' ),
			'302' => __( '302 Found', 'mg-seo' ),
			'307' => __( '307 Temporary Redirect', 'mg-seo' ),
			'410' => __( '410 Content Deleted', 'mg-seo' ),
			'451' => __( '451 Unavailable For Legal Reasons', 'mg-seo' ),
		);
	}

	/**
	 * Admin pages.
	 */
	public function admin_pages(): array {
		return array(
			array(
				'slug'       => Admin::PAGE,
				'title'      => __( 'Redirections', 'mg-seo' ),
				'menu_title' => __( 'Redirections', 'mg-seo' ),
				'capability' => 'mgseo_redirections',
				'callback'   => array( Admin::class, 'render_redirections' ),
				'position'   => 15,
			),
			array(
				'slug'       => Admin::LOG_PAGE,
				'title'      => __( '404 Monitor', 'mg-seo' ),
				'menu_title' => __( '404 Monitor', 'mg-seo' ),
				'capability' => 'mgseo_redirections',
				'callback'   => array( Admin::class, 'render_log' ),
				'position'   => 16,
			),
		);
	}

	/**
	 * Create tables.
	 */
	public function activate(): void {
		Repository::install();
		Log404::install();
		update_option( 'mgseo_redirections_db', self::DB_VERSION );
		if ( ! wp_next_scheduled( 'mgseo_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mgseo_daily' );
		}
	}

	/**
	 * Remove data.
	 */
	public function uninstall(): void {
		Repository::drop();
		Log404::drop();
		delete_option( 'mgseo_redirections_db' );
		delete_option( Repository::CACHE );
	}

	/**
	 * Create tables when the module was enabled before this version existed.
	 */
	public function maybe_install(): void {
		if ( self::DB_VERSION !== get_option( 'mgseo_redirections_db' ) ) {
			$this->activate();
		}
	}

	/**
	 * Rebuild compiled rules when settings change (cheap; keeps the cache coherent).
	 *
	 * @param string $module_id Saved module.
	 */
	public function settings_saved( string $module_id ): void {
		if ( $this->id() === $module_id ) {
			Repository::flush_cache();
		}
	}

	/**
	 * Whether the current request is a front-end request we may redirect.
	 */
	private function is_frontend_request(): bool {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'WP_CLI' ) && WP_CLI ) || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return false;
		}
		if ( is_customize_preview() ) {
			return false;
		}
		return true;
	}

	/**
	 * Request method.
	 */
	private function method(): string {
		return isset( $_SERVER['REQUEST_METHOD'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['REQUEST_METHOD'] ) ) ) : 'GET';
	}

	/**
	 * Raw request URI.
	 */
	private function request_uri(): string {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed and normalised by the caller.
		return isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '/';
	}

	/**
	 * Match the current request.
	 */
	public function match_current(): ?array {
		$uri   = $this->request_uri();
		$path  = Helpers::request_path( $uri );
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		$match = Matcher::match( $path, $query, Repository::compiled(), (string) Options::get( 'redirections', 'query_mode', 'ignore' ) );
		if ( $match && ! in_array( $this->method(), array( 'GET', 'HEAD' ), true ) && 307 !== $match['type'] ) {
			return null;
		}
		/**
		 * Filter the matched redirection for the current request.
		 *
		 * @param array|null $match Match (id, type, url, source) or null.
		 * @param string     $path  Request path.
		 */
		return apply_filters( 'mgseo/redirections/match', $match, $path );
	}

	/**
	 * Early matching on `init` (setting).
	 */
	public function match_early(): void {
		if ( 'init' !== Options::get( 'redirections', 'match_timing', 'template_redirect' ) || ! $this->is_frontend_request() ) {
			return;
		}
		global $pagenow;
		if ( 'index.php' !== $pagenow ) {
			return;
		}
		$rest_prefix = '/' . trim( rest_get_url_prefix(), '/' ) . '/';
		if ( str_contains( $this->request_uri(), $rest_prefix ) ) {
			return;
		}
		$match = $this->match_current();
		if ( ! $match ) {
			return;
		}
		if ( Repository::is_gone_type( $match['type'] ) ) {
			$this->pending = $match;
			return;
		}
		$this->apply( $match );
	}

	/**
	 * Main matching, fallback and 404 logging.
	 */
	public function template_redirect(): void {
		if ( ! $this->is_frontend_request() || is_preview() ) {
			return;
		}
		if ( $this->pending ) {
			$match         = $this->pending;
			$this->pending = null;
			$this->apply( $match );
			return;
		}
		if ( 'init' !== Options::get( 'redirections', 'match_timing', 'template_redirect' ) ) {
			$match = $this->match_current();
			if ( $match ) {
				$this->apply( $match );
				return;
			}
		}
		if ( ! is_404() ) {
			return;
		}
		$this->queue_404();
		$this->fallback();
	}

	/**
	 * Perform a matched redirection.
	 *
	 * @param array $found Match.
	 */
	public function apply( array $found ): void {
		if ( Options::get( 'redirections', 'debug', false ) && current_user_can( 'mgseo_redirections' ) ) {
			$this->debug_page( $found );
			return;
		}
		if ( Options::get( 'redirections', 'count_hits', true ) ) {
			$this->hits[ $found['id'] ] = ( $this->hits[ $found['id'] ] ?? 0 ) + 1;
		}
		if ( Repository::is_gone_type( $found['type'] ) ) {
			global $wp_query;
			if ( $wp_query instanceof \WP_Query ) {
				$wp_query->set_404();
			}
			status_header( $found['type'] );
			nocache_headers();
			/**
			 * Fires when a 410/451 response is about to render the 404 template.
			 *
			 * @param array $found Match.
			 */
			do_action( 'mgseo/redirections/gone', $found );
			return;
		}
		$this->redirect( $found['url'], $found['type'] );
	}

	/**
	 * Send a redirect and stop.
	 *
	 * @param string $url    URL.
	 * @param int    $status Status code.
	 */
	private function redirect( string $url, int $status ): void {
		// phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- destinations may be external by design; set by users with the redirections capability.
		if ( wp_redirect( $url, $status, 'WordPress' ) ) {
			exit;
		}
	}

	/**
	 * Interstitial shown to redirection managers in debug mode.
	 *
	 * @param array $found Match.
	 */
	private function debug_page( array $found ): void {
		nocache_headers();
		status_header( 200 );
		$edit  = add_query_arg(
			array(
				'page'   => Admin::PAGE,
				'action' => 'edit',
				'id'     => $found['id'],
			),
			admin_url( 'admin.php' )
		);
		$title = esc_html__( 'Redirection debugger', 'mg-seo' );
		echo '<!DOCTYPE html><html><head><meta charset="utf-8"><meta name="robots" content="noindex"><meta name="viewport" content="width=device-width"><title>' . $title . '</title>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '<style>body{font:16px/1.5 system-ui,sans-serif;max-width:640px;margin:3em auto;padding:0 1em;color:#1d2327}code{background:#f0f0f1;padding:2px 4px;word-break:break-all}a.button{display:inline-block;margin:1em .5em 0 0;padding:.5em 1em;background:#2271b1;color:#fff;text-decoration:none;border-radius:3px}</style></head><body>';
		echo '<h1>' . $title . '</h1>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '<p>' . esc_html__( 'Debug mode is on, so you see this page instead of being redirected. Visitors are redirected normally.', 'mg-seo' ) . '</p><ul>';
		echo '<li>' . esc_html__( 'Requested URL:', 'mg-seo' ) . ' <code>' . esc_html( Helpers::current_url( true ) ) . '</code></li>';
		echo '<li>' . esc_html__( 'Matched source:', 'mg-seo' ) . ' <code>' . esc_html( $found['source'] ) . '</code></li>';
		echo '<li>' . esc_html__( 'Type:', 'mg-seo' ) . ' <code>' . esc_html( (string) $found['type'] ) . '</code></li>';
		if ( '' !== $found['url'] ) {
			echo '<li>' . esc_html__( 'Destination:', 'mg-seo' ) . ' <code>' . esc_html( $found['url'] ) . '</code></li>';
		}
		echo '</ul>';
		if ( '' !== $found['url'] ) {
			echo '<a class="button" href="' . esc_url( $found['url'] ) . '">' . esc_html__( 'Continue to the destination', 'mg-seo' ) . '</a>';
		}
		echo '<a class="button" href="' . esc_url( $edit ) . '">' . esc_html__( 'Edit redirection', 'mg-seo' ) . '</a>';
		echo '</body></html>';
		exit;
	}

	/**
	 * Remember the current 404 for logging at shutdown.
	 */
	public function queue_404(): void {
		if ( ! Options::get( 'redirections', 'log_404', true ) ) {
			return;
		}
		$uri       = $this->request_uri();
		$path      = (string) wp_parse_url( $uri, PHP_URL_PATH );
		$path      = rawurldecode( '' === $path ? '/' : $path );
		$home_path = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home_path && str_starts_with( $path, $home_path . '/' ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}
		$query = (string) wp_parse_url( $uri, PHP_URL_QUERY );
		if ( '' !== $query && ! Options::get( 'redirections', 'log_ignore_query', true ) ) {
			$path .= '?' . $query;
		}
		$path = (string) wp_check_invalid_utf8( (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $path ) );
		$ua   = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( ! Log404::should_log( $path, $ua ) ) {
			return;
		}
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		/**
		 * Filter the client IP used for the 404 log (it is anonymised before storage).
		 *
		 * @param string $ip IP from REMOTE_ADDR.
		 */
		$ip        = (string) apply_filters( 'mgseo/redirections/client_ip', $ip );
		$this->log = array(
			'uri'        => $path,
			'referer'    => (string) wp_get_raw_referer(),
			'user_agent' => $ua,
			'ip'         => $ip,
		);
	}

	/**
	 * Fallback behaviour for unmatched 404s.
	 */
	private function fallback(): void {
		$mode = (string) Options::get( 'redirections', 'fallback', 'default' );
		if ( 'default' === $mode || ! in_array( $this->method(), array( 'GET', 'HEAD' ), true ) ) {
			return;
		}
		$target = 'custom' === $mode ? (string) Options::get( 'redirections', 'fallback_url', '' ) : home_url( '/' );
		if ( '' === $target ) {
			return;
		}
		$target = Matcher::absolute( Repository::sanitize_target( $target ) );
		if ( Repository::local_path( $target ) === Helpers::request_path( $this->request_uri() ) ) {
			return;
		}
		$type = (int) Options::get( 'redirections', 'fallback_type', '301' );
		$this->redirect( $target, in_array( $type, array( 301, 302, 307 ), true ) ? $type : 301 );
	}

	/**
	 * Write hit counters and the 404 log entry (one query each, after the response).
	 */
	public function shutdown(): void {
		if ( $this->hits ) {
			Repository::record_hits( $this->hits );
			$this->hits = array();
		}
		if ( $this->log ) {
			$log       = $this->log;
			$this->log = null;
			Log404::log( $log['uri'], $log['referer'], $log['user_agent'], $log['ip'], (string) Options::get( 'redirections', 'log_mode', 'simple' ) );
		}
	}
}
