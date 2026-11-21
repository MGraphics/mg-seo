<?php
/**
 * Search Console module.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\SearchConsole;

use MGSEO\Module as Base;
use MGSEO\Options;
use MGSEO\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Google Search Console integration using the site owner's own Google Cloud OAuth client.
 * No request leaves the site unless this module is enabled and an account was connected.
 */
final class Module extends Base {

	public const ERROR_OPTION = 'mgseo_gsc_last_error';
	public const NOTICE       = 'mgseo_gsc_notice_';
	public const REPORTS_SLUG = 'mg-seo-gsc-reports';
	public const DB_OPTION    = 'mgseo_gsc_db';
	public const DB_VERSION   = '1';

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'gsc';
	}

	/**
	 * Whether the module is enabled.
	 */
	public static function enabled(): bool {
		return Plugin::instance()->is_module_active( 'gsc' );
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'maybe_install' ), 1 );
		add_action( 'mgseo_daily', array( $this, 'cron' ) );
		add_action( 'admin_init', array( $this, 'ensure_cron' ) );
		add_action( 'admin_post_mgseo_gsc_connect', array( $this, 'handle_connect' ) );
		add_action( 'admin_post_mgseo_gsc_oauth', array( $this, 'handle_callback' ) );
		add_action( 'admin_post_mgseo_gsc_disconnect', array( $this, 'handle_disconnect' ) );
		add_action( 'admin_post_mgseo_gsc_sites', array( $this, 'handle_sites' ) );
		add_action( 'admin_post_mgseo_gsc_sync', array( $this, 'handle_sync' ) );
		add_action( 'admin_post_mgseo_gsc_reset', array( $this, 'handle_reset' ) );
		add_action( 'admin_post_mgseo_gsc_track', array( ReportsPage::class, 'handle_track' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'mgseo/metabox_tabs', array( $this, 'metabox_tab' ), 10, 2 );
		add_action( 'mgseo/rest_routes', array( $this, 'routes' ) );
	}

	/**
	 * Settings schema.
	 */
	public function settings(): array {
		return array(
			array(
				'id'     => 'connection',
				'title'  => __( 'Connection', 'mg-seo' ),
				'fields' => array(
					array(
						'id'    => 'setup_heading',
						'type'  => 'heading',
						'label' => __( 'Your own Google Cloud project', 'mg-seo' ),
						'desc'  => self::setup_steps(),
					),
					array(
						'id'       => 'redirect_uri_info',
						'type'     => 'callback',
						'label'    => __( 'Authorized redirect URI', 'mg-seo' ),
						'callback' => array( $this, 'field_redirect_uri' ),
					),
					array(
						'id'          => 'client_id',
						'type'        => 'text',
						'label'       => __( 'OAuth client ID', 'mg-seo' ),
						'default'     => '',
						'placeholder' => '1234567890-abc.apps.googleusercontent.com',
					),
					array(
						'id'       => 'client_secret',
						'type'     => 'callback',
						'label'    => __( 'OAuth client secret', 'mg-seo' ),
						'default'  => '',
						'callback' => array( $this, 'field_client_secret' ),
						'sanitize' => array( $this, 'sanitize_client_secret' ),
					),
					array(
						'id'       => 'connection_status',
						'type'     => 'callback',
						'label'    => __( 'Google account', 'mg-seo' ),
						'callback' => array( $this, 'field_connection' ),
					),
				),
			),
			array(
				'id'     => 'data',
				'title'  => __( 'Data', 'mg-seo' ),
				'fields' => array(
					array(
						'id'       => 'property',
						'type'     => 'callback',
						'label'    => __( 'Search Console property', 'mg-seo' ),
						'default'  => '',
						'callback' => array( $this, 'field_property' ),
						'sanitize' => array( $this, 'sanitize_property' ),
					),
					array(
						'id'      => 'sync_days',
						'type'    => 'number',
						'label'   => __( 'Days to import', 'mg-seo' ),
						'desc'    => __( 'How many days of performance data to keep in sync (Google keeps up to 16 months, about 486 days). Older days are imported gradually by the daily task.', 'mg-seo' ),
						'default' => 90,
						'min'     => 7,
						'max'     => 486,
					),
					array(
						'id'      => 'retention_days',
						'type'    => 'number',
						'label'   => __( 'Keep stored data for (days)', 'mg-seo' ),
						'desc'    => __( 'Rows older than this are deleted from the local table. You can keep data longer than Google does.', 'mg-seo' ),
						'default' => 486,
						'min'     => 30,
						'max'     => 1830,
					),
					array(
						'id'       => 'sync_status',
						'type'     => 'callback',
						'label'    => __( 'Synchronisation', 'mg-seo' ),
						'callback' => array( $this, 'field_sync_status' ),
					),
				),
			),
			array(
				'id'     => 'features',
				'title'  => __( 'Features', 'mg-seo' ),
				'fields' => array(
					array(
						'id'           => 'editor_box',
						'type'         => 'toggle',
						'label'        => __( 'Performance in the editor', 'mg-seo' ),
						'toggle_label' => __( 'Show clicks, impressions, queries and index status in the SEO box', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'           => 'indexing_api',
						'type'         => 'toggle',
						'label'        => __( 'Google Indexing API', 'mg-seo' ),
						'toggle_label' => __( 'Allow submitting URLs to the Indexing API (off by default)', 'mg-seo' ),
						'desc'         => __( 'Google officially supports the Indexing API only for pages with JobPosting or BroadcastEvent (livestream) structured data. Enable the "Web Search Indexing API" in your Google Cloud project, then reconnect your account so the extra permission is granted. URLs are only submitted when you click the button in the editor.', 'mg-seo' ),
						'default'      => false,
					),
				),
			),
		);
	}

	/**
	 * Setup instructions (HTML).
	 */
	private static function setup_steps(): string {
		$steps = array(
			__( 'In the Google Cloud console create a project (or pick an existing one) and enable the "Google Search Console API".', 'mg-seo' ),
			__( 'Configure the OAuth consent screen (External or Internal, add yourself as a test user while the app is in testing).', 'mg-seo' ),
			__( 'Create credentials → OAuth client ID → Web application, and add the redirect URI shown below as an authorized redirect URI.', 'mg-seo' ),
			__( 'Paste the client ID and client secret here, save, then click "Connect with Google".', 'mg-seo' ),
		);
		$html  = '<ol>';
		foreach ( $steps as $step ) {
			$html .= '<li>' . esc_html( $step ) . '</li>';
		}
		$html .= '</ol><p>' . esc_html__( 'MG SEO talks to Google directly from this site with your own credentials. No data is sent to MGraphics or any third party, and no request is made until you connect an account.', 'mg-seo' ) . '</p>';
		return $html;
	}

	/**
	 * Redirect URI field.
	 */
	public function field_redirect_uri(): void {
		printf(
			'<input type="text" readonly class="large-text code mgseo-gsc-select" value="%s" />',
			esc_attr( OAuth::redirect_uri() )
		);
		echo '<p class="description">' . esc_html__( 'Copy this exact URL into "Authorized redirect URIs" of your OAuth client.', 'mg-seo' ) . '</p>';
	}

	/**
	 * Client secret field: never prints the stored secret.
	 */
	public function field_client_secret(): void {
		$secret = OAuth::client_secret();
		$hint   = '' !== $secret ? str_repeat( '•', 8 ) . mb_substr( $secret, -4 ) : '';
		printf(
			'<input type="password" id="mgseo-client-secret" name="mgseo[client_secret]" value="" class="regular-text" autocomplete="new-password" placeholder="%s" />',
			esc_attr( $hint )
		);
		echo '<p class="description">';
		if ( '' !== $secret ) {
			esc_html_e( 'A secret is stored (encrypted). Leave empty to keep it, or type a new one to replace it.', 'mg-seo' );
			echo ' <label><input type="checkbox" name="mgseo[client_secret]" value="__delete__" /> ' . esc_html__( 'Delete stored secret', 'mg-seo' ) . '</label>';
		} else {
			esc_html_e( 'Stored encrypted with a key derived from your WordPress salts.', 'mg-seo' );
		}
		echo '</p>';
	}

	/**
	 * Keep the stored secret when the field is left empty; encrypt new values.
	 *
	 * @param mixed $value Raw value.
	 */
	public function sanitize_client_secret( mixed $value ): string {
		$value  = trim( sanitize_text_field( (string) $value ) );
		$stored = (string) Options::get( 'gsc', 'client_secret', '' );
		if ( '__delete__' === $value ) {
			return '';
		}
		if ( '' === $value ) {
			return $stored;
		}
		return Crypto::encrypt( $value );
	}

	/**
	 * Connection status, connect and disconnect buttons.
	 */
	public function field_connection(): void {
		if ( OAuth::connected() ) {
			$tokens = (array) OAuth::tokens();
			$user   = get_userdata( (int) ( $tokens['connected_by'] ?? 0 ) );
			echo '<p><span class="mgseo-gsc-status is-ok">' . esc_html__( 'Connected', 'mg-seo' ) . '</span> ';
			if ( ! empty( $tokens['connected_at'] ) ) {
				/* translators: 1: date, 2: user name */
				echo esc_html( sprintf( __( 'since %1$s by %2$s', 'mg-seo' ), wp_date( get_option( 'date_format' ), (int) $tokens['connected_at'] ), $user ? $user->display_name : '—' ) );
			}
			echo '</p>';
			if ( Options::get( 'gsc', 'indexing_api', false ) && ! OAuth::has_scope( OAuth::SCOPE_INDEX ) ) {
				echo '<p class="description">' . esc_html__( 'The Indexing API is enabled but was not granted. Disconnect and connect again to grant it.', 'mg-seo' ) . '</p>';
			}
			printf(
				'<p><a class="button" href="%s">%s</a></p>',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mgseo_gsc_disconnect' ), 'mgseo_gsc_disconnect' ) ),
				esc_html__( 'Disconnect and revoke access', 'mg-seo' )
			);
			return;
		}
		echo '<p><span class="mgseo-gsc-status">' . esc_html__( 'Not connected', 'mg-seo' ) . '</span></p>';
		if ( ! OAuth::configured() ) {
			echo '<p class="description">' . esc_html__( 'Save the client ID and client secret first.', 'mg-seo' ) . '</p>';
			return;
		}
		printf(
			'<p><a class="button button-primary" href="%s">%s</a></p><p class="description">%s</p>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mgseo_gsc_connect' ), 'mgseo_gsc_connect' ) ),
			esc_html__( 'Connect with Google', 'mg-seo' ),
			esc_html__( 'You will be sent to Google to grant read-only access to Search Console data.', 'mg-seo' )
		);
	}

	/**
	 * Property select.
	 *
	 * @param array $field Field.
	 * @param mixed $value Value.
	 */
	public function field_property( array $field, mixed $value ): void {
		$sites = Client::sites();
		if ( ! OAuth::connected() ) {
			echo '<p class="description">' . esc_html__( 'Connect your Google account on the Connection tab first.', 'mg-seo' ) . '</p>';
			echo '<input type="hidden" name="mgseo[property]" value="' . esc_attr( (string) $value ) . '" />';
			return;
		}
		echo '<select id="mgseo-property" name="mgseo[property]"><option value="">' . esc_html__( '— Select —', 'mg-seo' ) . '</option>';
		foreach ( $sites as $site ) {
			$label = 'domain' === $site['type']
				/* translators: %s: domain name */
				? sprintf( __( 'Domain: %s', 'mg-seo' ), substr( $site['url'], 10 ) )
				/* translators: %s: URL prefix */
				: sprintf( __( 'URL prefix: %s', 'mg-seo' ), $site['url'] );
			printf( '<option value="%s" %s>%s</option>', esc_attr( $site['url'] ), selected( (string) $value, $site['url'], false ), esc_html( $label ) );
		}
		echo '</select> ';
		printf(
			'<a class="button" href="%s">%s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mgseo_gsc_sites' ), 'mgseo_gsc_sites' ) ),
			esc_html__( 'Refresh list', 'mg-seo' )
		);
		echo '<p class="description">' . esc_html__( 'Domain properties cover all protocols and subdomains; URL-prefix properties only URLs starting with that prefix.', 'mg-seo' ) . '</p>';
		if ( '' !== (string) $value && ! Client::url_in_property( home_url( '/' ), (string) $value ) ) {
			echo '<p class="description mgseo-gsc-warning">' . esc_html__( 'Warning: this property does not contain the address of this site.', 'mg-seo' ) . '</p>';
		}
	}

	/**
	 * Only accept properties from the fetched list.
	 *
	 * @param mixed $value Raw value.
	 */
	public function sanitize_property( mixed $value ): string {
		$value = trim( (string) $value );
		foreach ( Client::sites() as $site ) {
			if ( $site['url'] === $value ) {
				return $value;
			}
		}
		return '';
	}

	/**
	 * Sync status and actions.
	 */
	public function field_sync_status(): void {
		$state = get_option( Sync::STATE, array() );
		$state = is_array( $state ) ? $state : array();
		if ( ! empty( $state['last_run'] ) ) {
			/* translators: 1: date and time, 2: number of rows, 3: first date, 4: last date */
			echo '<p>' . esc_html( sprintf( __( 'Last run: %1$s (%2$s rows). Stored range: %3$s – %4$s.', 'mg-seo' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $state['last_run'] ), number_format_i18n( (int) ( $state['last_rows'] ?? 0 ) ), (string) ( $state['from'] ?? '—' ), (string) ( $state['to'] ?? '—' ) ) ) . '</p>';
		} else {
			echo '<p>' . esc_html__( 'Not synchronised yet. Data is imported once a day by WP-Cron.', 'mg-seo' ) . '</p>';
		}
		if ( Client::ready() && '' !== Client::property() ) {
			printf(
				'<a class="button" href="%s">%s</a> ',
				esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mgseo_gsc_sync' ), 'mgseo_gsc_sync' ) ),
				esc_html__( 'Sync now', 'mg-seo' )
			);
		}
		printf(
			'<a class="button button-link-delete mgseo-gsc-confirm" href="%s" data-confirm="%s">%s</a>',
			esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mgseo_gsc_reset' ), 'mgseo_gsc_reset' ) ),
			esc_attr__( 'Delete all stored Search Console data?', 'mg-seo' ),
			esc_html__( 'Delete stored data', 'mg-seo' )
		);
	}

	/**
	 * Reports page.
	 */
	public function admin_pages(): array {
		return array(
			array(
				'slug'       => self::REPORTS_SLUG,
				'title'      => __( 'Search Performance', 'mg-seo' ),
				'menu_title' => __( 'Search Performance', 'mg-seo' ),
				'capability' => 'mgseo_view_reports',
				'callback'   => array( ReportsPage::class, 'render' ),
				'position'   => 15,
			),
		);
	}

	/**
	 * Create the table and schedule the daily task.
	 */
	public function activate(): void {
		Sync::create_table();
		update_option( self::DB_OPTION, self::DB_VERSION, true );
		if ( ! wp_next_scheduled( 'mgseo_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mgseo_daily' );
		}
	}

	/**
	 * Create the table if the module was enabled without running activate().
	 */
	public function maybe_install(): void {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			$this->activate();
		}
	}

	/**
	 * Remove table, tokens and options.
	 */
	public function uninstall(): void {
		global $wpdb;
		Sync::drop_table();
		OAuth::forget();
		delete_option( Sync::STATE );
		delete_option( Reports::TRACKED );
		delete_option( self::ERROR_OPTION );
		delete_option( self::DB_OPTION );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)", Inspection::META, Inspection::INDEXING_META ) );
		$settings = get_option( Options::OPTION, array() );
		if ( is_array( $settings ) && isset( $settings['gsc'] ) ) {
			unset( $settings['gsc'] );
			update_option( Options::OPTION, $settings );
		}
	}

	/**
	 * Daily task: sync when (and only when) connected.
	 */
	public function cron(): void {
		if ( ! Client::ready() || '' === Client::property() ) {
			return;
		}
		$result = Sync::run();
		if ( is_wp_error( $result ) ) {
			self::log_error( $result->get_error_message() );
		}
	}

	/**
	 * Re-schedule the daily event if it went missing while connected.
	 */
	public function ensure_cron(): void {
		if ( OAuth::connected() && ! wp_next_scheduled( 'mgseo_daily' ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', 'mgseo_daily' );
		}
	}

	/**
	 * Remember the last error for an admin notice.
	 *
	 * @param string $message Message.
	 */
	public static function log_error( string $message ): void {
		update_option(
			self::ERROR_OPTION,
			array(
				'message' => $message,
				'time'    => time(),
			),
			false
		);
	}

	/**
	 * One-time notice for the current user.
	 *
	 * @param string $type    success|error|warning|info.
	 * @param string $message Message.
	 */
	public static function flash( string $type, string $message ): void {
		set_transient(
			self::NOTICE . get_current_user_id(),
			array(
				'type'    => $type,
				'message' => $message,
			),
			5 * MINUTE_IN_SECONDS
		);
	}

	/**
	 * Show notices on MG SEO screens.
	 */
	public function notices(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! str_contains( (string) $screen->id, 'mg-seo' ) ) {
			return;
		}
		$flash = get_transient( self::NOTICE . get_current_user_id() );
		if ( is_array( $flash ) && ! empty( $flash['message'] ) ) {
			delete_transient( self::NOTICE . get_current_user_id() );
			$type = in_array( $flash['type'] ?? '', array( 'success', 'error', 'warning', 'info' ), true ) ? $flash['type'] : 'info';
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( (string) $flash['message'] ) );
		}
		$error = get_option( self::ERROR_OPTION );
		if ( is_array( $error ) && ! empty( $error['message'] ) && current_user_can( 'mgseo_manage_settings' ) && str_contains( (string) $screen->id, 'mg-seo-gsc' ) ) {
			printf(
				'<div class="notice notice-warning"><p><strong>%s</strong> %s</p></div>',
				esc_html__( 'Search Console:', 'mg-seo' ),
				esc_html( (string) $error['message'] )
			);
		}
	}

	/**
	 * Redirect back to a settings tab.
	 *
	 * @param string $tab Tab id.
	 */
	private function back( string $tab = 'connection' ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => 'mg-seo-gsc',
					'tab'  => $tab,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Verify capability and nonce of an admin-post action.
	 *
	 * @param string $action Nonce action.
	 */
	private function guard( string $action ): void {
		if ( ! current_user_can( 'mgseo_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'mg-seo' ), 403 );
		}
		check_admin_referer( $action );
	}

	/**
	 * Start the OAuth flow.
	 */
	public function handle_connect(): void {
		$this->guard( 'mgseo_gsc_connect' );
		if ( ! OAuth::configured() ) {
			self::flash( 'error', __( 'Enter the OAuth client ID and client secret first.', 'mg-seo' ) );
			$this->back();
		}
		wp_redirect( OAuth::authorization_url() ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- Google consent screen.
		exit;
	}

	/**
	 * OAuth redirect target.
	 */
	public function handle_callback(): void {
		if ( ! current_user_can( 'mgseo_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'mg-seo' ), 403 );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- the OAuth `state` parameter is the CSRF token (validated below).
		$state = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		$code  = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$error = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';
		// phpcs:enable
		$verifier = OAuth::consume_state( $state );
		if ( null === $verifier ) {
			self::flash( 'error', __( 'The connection request expired or is invalid. Please try again.', 'mg-seo' ) );
			$this->back();
		}
		if ( '' !== $error || '' === $code ) {
			/* translators: %s: error code returned by Google */
			self::flash( 'error', sprintf( __( 'Google did not grant access (%s).', 'mg-seo' ), '' !== $error ? $error : 'no_code' ) );
			$this->back();
		}
		$result = OAuth::exchange_code( $code, $verifier );
		if ( is_wp_error( $result ) ) {
			self::flash( 'error', $result->get_error_message() );
			$this->back();
		}
		delete_option( self::ERROR_OPTION );
		$sites = Client::fetch_sites();
		$this->ensure_cron();
		if ( is_wp_error( $sites ) ) {
			self::flash( 'warning', __( 'Connected, but the list of properties could not be loaded:', 'mg-seo' ) . ' ' . $sites->get_error_message() );
		} else {
			self::flash( 'success', __( 'Connected to Google Search Console. Choose the property to use.', 'mg-seo' ) );
		}
		$this->back( 'data' );
	}

	/**
	 * Disconnect.
	 */
	public function handle_disconnect(): void {
		$this->guard( 'mgseo_gsc_disconnect' );
		OAuth::disconnect();
		self::flash( 'success', __( 'Disconnected. Access was revoked at Google; stored reports were kept.', 'mg-seo' ) );
		$this->back();
	}

	/**
	 * Refresh the property list.
	 */
	public function handle_sites(): void {
		$this->guard( 'mgseo_gsc_sites' );
		$sites = Client::fetch_sites();
		if ( is_wp_error( $sites ) ) {
			self::flash( 'error', $sites->get_error_message() );
		} else {
			/* translators: %d: number of properties */
			self::flash( 'success', sprintf( _n( '%d property found.', '%d properties found.', count( $sites ), 'mg-seo' ), count( $sites ) ) );
		}
		$this->back( 'data' );
	}

	/**
	 * Manual sync.
	 */
	public function handle_sync(): void {
		$this->guard( 'mgseo_gsc_sync' );
		$result = Sync::run();
		if ( is_wp_error( $result ) ) {
			self::log_error( $result->get_error_message() );
			self::flash( 'error', $result->get_error_message() );
		} else {
			/* translators: 1: rows, 2: API requests */
			self::flash( 'success', sprintf( __( 'Imported %1$s rows with %2$d API requests.', 'mg-seo' ), number_format_i18n( $result['rows'] ), $result['requests'] ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce checked in guard().
		$to = isset( $_GET['to'] ) ? sanitize_key( wp_unslash( $_GET['to'] ) ) : '';
		if ( 'reports' === $to ) {
			wp_safe_redirect( admin_url( 'admin.php?page=' . self::REPORTS_SLUG ) );
			exit;
		}
		$this->back( 'data' );
	}

	/**
	 * Delete stored data.
	 */
	public function handle_reset(): void {
		$this->guard( 'mgseo_gsc_reset' );
		Sync::truncate();
		self::flash( 'success', __( 'Stored Search Console data deleted.', 'mg-seo' ) );
		$this->back( 'data' );
	}

	/**
	 * Enqueue assets on our screens and the post editor.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function assets( string $hook ): void {
		$screen = get_current_screen();
		$ours   = str_contains( $hook, 'mg-seo-gsc' );
		$editor = $screen && 'post' === $screen->base;
		if ( ! $ours && ! $editor ) {
			return;
		}
		wp_enqueue_style( 'mgseo-gsc', MGSEO_URL . 'assets/gsc.css', array(), MGSEO_VERSION );
		wp_enqueue_script( 'mgseo-gsc', MGSEO_URL . 'assets/gsc.js', array( 'jquery', 'wp-api-fetch' ), MGSEO_VERSION, true );
		wp_localize_script(
			'mgseo-gsc',
			'mgseoGsc',
			array(
				'i18n' => array(
					'checking'   => __( 'Checking…', 'mg-seo' ),
					'submitting' => __( 'Submitting…', 'mg-seo' ),
					'error'      => __( 'Request failed.', 'mg-seo' ),
				),
			)
		);
	}

	/**
	 * Editor tab.
	 *
	 * @param array    $tabs Tabs.
	 * @param \WP_Post $post Post.
	 */
	public function metabox_tab( array $tabs, \WP_Post $post ): array {
		if ( ! Options::get( 'gsc', 'editor_box', true ) ) {
			return $tabs;
		}
		$tabs[] = array(
			'id'         => 'gsc',
			'title'      => __( 'Search Console', 'mg-seo' ),
			'render'     => array( ReportsPage::class, 'render_post_box' ),
			'capability' => 'mgseo_view_reports',
			'priority'   => 70,
		);
		return $tabs;
	}

	/**
	 * REST routes.
	 *
	 * @param string $ns Namespace.
	 */
	public function routes( string $ns ): void {
		$args = array(
			'post_id' => array(
				'type'     => 'integer',
				'required' => true,
			),
		);
		register_rest_route(
			$ns,
			'/gsc/inspect',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_inspect' ),
				'permission_callback' => static fn( \WP_REST_Request $r ) => current_user_can( 'edit_post', (int) $r->get_param( 'post_id' ) ) && current_user_can( 'mgseo_view_reports' ),
				'args'                => $args,
			)
		);
		register_rest_route(
			$ns,
			'/gsc/indexing',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_indexing' ),
				'permission_callback' => static fn( \WP_REST_Request $r ) => current_user_can( 'edit_post', (int) $r->get_param( 'post_id' ) ) && current_user_can( 'mgseo_edit_advanced' ),
				'args'                => $args,
			)
		);
	}

	/**
	 * Inspect a post URL (user action).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_inspect( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );
		$result  = Inspection::inspect_post( $post_id );
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}
		return new \WP_REST_Response(
			array(
				'result' => $result,
				'html'   => ReportsPage::inspection_html( $result ),
			)
		);
	}

	/**
	 * Submit a post URL to the Indexing API (user action).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_indexing( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request->get_param( 'post_id' );
		if ( 'publish' !== get_post_status( $post_id ) ) {
			return new \WP_Error( 'mgseo_gsc_not_public', __( 'Only published posts can be submitted.', 'mg-seo' ), array( 'status' => 400 ) );
		}
		$result = Inspection::submit( (string) get_permalink( $post_id ) );
		if ( is_wp_error( $result ) ) {
			return new \WP_Error( $result->get_error_code(), $result->get_error_message(), array( 'status' => 400 ) );
		}
		update_post_meta( $post_id, Inspection::INDEXING_META, $result );
		return new \WP_REST_Response(
			array(
				'result'  => $result,
				'message' => __( 'Submitted to the Google Indexing API.', 'mg-seo' ),
			)
		);
	}
}
