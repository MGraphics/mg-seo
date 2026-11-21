<?php
/**
 * Search Console: OAuth, encryption and the "no network unless connected" guarantee.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Modules\SearchConsole\Client;
use MGSEO\Modules\SearchConsole\Crypto;
use MGSEO\Modules\SearchConsole\Module;
use MGSEO\Modules\SearchConsole\OAuth;
use MGSEO\Modules\SearchConsole\Sync;
use MGSEO\Options;

/**
 * @covers \MGSEO\Modules\SearchConsole\OAuth
 * @covers \MGSEO\Modules\SearchConsole\Crypto
 * @covers \MGSEO\Modules\SearchConsole\Client
 */
class SearchConsoleOAuthTest extends TestCase {

	/**
	 * Recorded HTTP requests.
	 *
	 * @var array<int, array{url: string, args: array}>
	 */
	private array $requests = array();

	/**
	 * Responder for mocked requests.
	 *
	 * @var callable|null
	 */
	private $responder = null;

	public function set_up(): void {
		parent::set_up();
		$this->requests  = array();
		$this->responder = null;
		update_option( 'mgseo_modules', array( 'gsc' => true ) );
		Sync::create_table();
		$this->set_option( 'gsc', 'client_id', 'cid.apps.googleusercontent.com' );
		$this->set_option( 'gsc', 'client_secret', Crypto::encrypt( 'top-secret-value' ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
				);
				if ( $this->responder ) {
					return call_user_func( $this->responder, $url, $args );
				}
				return new \WP_Error( 'blocked', 'Network blocked in tests' );
			},
			10,
			3
		);
	}

	public function tear_down(): void {
		delete_option( 'mgseo_modules' );
		OAuth::forget();
		parent::tear_down();
	}

	private static function json( array $data, int $code = 200 ): array {
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => (string) wp_json_encode( $data ),
			'response' => array(
				'code'    => $code,
				'message' => 200 === $code ? 'OK' : 'Error',
			),
			'cookies'  => array(),
		);
	}

	private function connect(): void {
		OAuth::save_tokens(
			array(
				'access_token'  => 'ya29.valid',
				'refresh_token' => '1//refresh',
				'expires_at'    => time() + 3600,
				'scope'         => OAuth::SCOPE_GSC,
			)
		);
	}

	public function test_authorization_url_has_pkce_state_and_readonly_scope(): void {
		$url = OAuth::authorization_url();
		$this->assertStringStartsWith( OAuth::AUTH_URL . '?', $url );
		parse_str( (string) wp_parse_url( $url, PHP_URL_QUERY ), $q );
		$this->assertSame( 'cid.apps.googleusercontent.com', $q['client_id'] );
		$this->assertSame( admin_url( 'admin-post.php?action=mgseo_gsc_oauth' ), $q['redirect_uri'] );
		$this->assertSame( 'code', $q['response_type'] );
		$this->assertSame( 'https://www.googleapis.com/auth/webmasters.readonly', $q['scope'] );
		$this->assertSame( 'offline', $q['access_type'] );
		$this->assertSame( 'consent', $q['prompt'] );
		$this->assertSame( 'S256', $q['code_challenge_method'] );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9]{32}$/', $q['state'] );

		$stored = get_transient( OAuth::STATE_PREFIX . $q['state'] );
		$this->assertIsArray( $stored );
		$this->assertMatchesRegularExpression( '/^[A-Za-z0-9\-._~]{43,128}$/', $stored['verifier'] );
		$expected = rtrim( strtr( base64_encode( hash( 'sha256', $stored['verifier'], true ) ), '+/', '-_' ), '=' );
		$this->assertSame( $expected, $q['code_challenge'] );
		$this->assertStringNotContainsString( '=', $q['code_challenge'] );
		$this->assertSame( array(), $this->requests, 'Building the URL must not call the network.' );

		$this->set_option( 'gsc', 'indexing_api', true );
		parse_str( (string) wp_parse_url( OAuth::authorization_url(), PHP_URL_QUERY ), $q2 );
		$this->assertSame( OAuth::SCOPE_GSC . ' ' . OAuth::SCOPE_INDEX, $q2['scope'] );
	}

	public function test_rfc7636_challenge_vector(): void {
		// Example from RFC 7636 appendix B.
		$this->assertSame( 'E9Melhoa2OwvFrEMTJguCHaoeK1t8URWbuGJSstw-cM', OAuth::code_challenge( 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk' ) );
	}

	public function test_state_is_single_use_and_bound_to_user(): void {
		parse_str( (string) wp_parse_url( OAuth::authorization_url(), PHP_URL_QUERY ), $q );
		$other = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$me    = get_current_user_id();
		wp_set_current_user( $other );
		$this->assertNull( OAuth::consume_state( $q['state'] ), 'Another user cannot use the state.' );

		parse_str( (string) wp_parse_url( OAuth::authorization_url(), PHP_URL_QUERY ), $q );
		$this->assertNotNull( OAuth::consume_state( $q['state'] ) );
		$this->assertNull( OAuth::consume_state( $q['state'] ), 'State can only be used once.' );
		$this->assertNull( OAuth::consume_state( 'bogus' ) );
		unset( $me );
	}

	public function test_code_exchange_stores_tokens_encrypted(): void {
		$this->responder = static fn( $url, $args ) => self::json(
			array(
				'access_token'  => 'ya29.ACCESS',
				'refresh_token' => '1//REFRESH',
				'expires_in'    => 3599,
				'scope'         => OAuth::SCOPE_GSC,
				'token_type'    => 'Bearer',
			)
		);
		$this->assertTrue( OAuth::exchange_code( 'auth-code', 'verifier-123' ) );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( OAuth::TOKEN_URL, $this->requests[0]['url'] );
		$this->assertSame( 'POST', $this->requests[0]['args']['method'] );
		$body = $this->requests[0]['args']['body'];
		$this->assertSame( 'authorization_code', $body['grant_type'] );
		$this->assertSame( 'auth-code', $body['code'] );
		$this->assertSame( 'verifier-123', $body['code_verifier'] );
		$this->assertSame( 'top-secret-value', $body['client_secret'] );
		$this->assertSame( OAuth::redirect_uri(), $body['redirect_uri'] );
		$this->assertGreaterThan( 0, $this->requests[0]['args']['timeout'] );

		$raw = get_option( OAuth::TOKENS );
		$this->assertIsString( $raw );
		$this->assertStringNotContainsString( 'ACCESS', $raw );
		$this->assertStringNotContainsString( 'REFRESH', $raw );
		$this->assertTrue( OAuth::connected() );
		$this->assertSame( '1//REFRESH', OAuth::tokens()['refresh_token'] );
		$this->assertSame( 'ya29.ACCESS', OAuth::access_token() );

		global $wpdb;
		$autoload = $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", OAuth::TOKENS ) );
		$this->assertContains( $autoload, array( 'no', 'off', 'auto-off' ) );
	}

	public function test_exchange_error_is_reported(): void {
		$this->responder = static fn() => self::json(
			array(
				'error'             => 'invalid_grant',
				'error_description' => 'Bad Request',
			),
			400
		);
		$result = OAuth::exchange_code( 'x', 'y' );
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_grant', $result->get_error_code() );
		$this->assertFalse( OAuth::connected() );
	}

	public function test_expired_token_is_refreshed_and_revoked_grant_disconnects(): void {
		OAuth::save_tokens(
			array(
				'access_token'  => 'old',
				'refresh_token' => '1//keep',
				'expires_at'    => time() - 10,
				'scope'         => OAuth::SCOPE_GSC,
			)
		);
		$this->responder = static fn() => self::json(
			array(
				'access_token' => 'fresh',
				'expires_in'   => 3600,
			)
		);
		$this->assertSame( 'fresh', OAuth::access_token() );
		$this->assertSame( 'refresh_token', $this->requests[0]['args']['body']['grant_type'] );
		$this->assertSame( '1//keep', $this->requests[0]['args']['body']['refresh_token'] );
		$this->assertSame( '1//keep', OAuth::tokens()['refresh_token'], 'Refresh token is kept when Google omits it.' );
		$this->assertSame( 'fresh', OAuth::access_token(), 'Valid token is reused without a request.' );
		$this->assertCount( 1, $this->requests );

		OAuth::save_tokens( array( 'access_token' => 'old', 'refresh_token' => '1//revoked', 'expires_at' => 0 ) );
		$this->responder = static fn() => self::json( array( 'error' => 'invalid_grant' ), 400 );
		$this->assertWPError( OAuth::access_token() );
		$this->assertFalse( OAuth::connected() );
		$this->assertNotEmpty( get_option( Module::ERROR_OPTION ) );
	}

	public function test_api_401_triggers_one_refresh_and_retry(): void {
		$this->connect();
		$calls           = 0;
		$this->responder = static function ( $url ) use ( &$calls ) {
			if ( OAuth::TOKEN_URL === $url ) {
				return self::json( array( 'access_token' => 'new-token', 'expires_in' => 3600 ) );
			}
			++$calls;
			return 1 === $calls ? self::json( array( 'error' => array( 'code' => 401, 'message' => 'Expired', 'status' => 'UNAUTHENTICATED' ) ), 401 ) : self::json( array( 'siteEntry' => array() ) );
		};
		$this->assertSame( array( 'siteEntry' => array() ), Client::request( 'GET', Client::SITES_URL ) );
		$this->assertSame( 'Bearer new-token', $this->requests[2]['args']['headers']['Authorization'] );
	}

	public function test_crypto_round_trip_and_tamper_detection(): void {
		$this->assertTrue( Crypto::available() );
		$secret = Crypto::encrypt( 'hello ąę token' );
		$this->assertStringStartsWith( 'mgs1:', $secret );
		$this->assertNotSame( $secret, Crypto::encrypt( 'hello ąę token' ), 'Random nonce per encryption.' );
		$this->assertSame( 'hello ąę token', Crypto::decrypt( $secret ) );
		$raw    = base64_decode( substr( $secret, 5 ) );
		$raw[30] = chr( ord( $raw[30] ) ^ 1 );
		$this->assertNull( Crypto::decrypt( 'mgs1:' . base64_encode( $raw ) ) );
		$this->assertNull( Crypto::decrypt( 'garbage' ) );
		$this->assertSame( '', Crypto::decrypt( '' ) );
	}

	public function test_client_secret_is_kept_encrypted_and_never_rendered(): void {
		$module = new Module();
		$stored = Options::get( 'gsc', 'client_secret' );
		$this->assertSame( $stored, $module->sanitize_client_secret( '' ), 'Empty input keeps the stored secret.' );
		$new = $module->sanitize_client_secret( 'new-secret-9876' );
		$this->assertStringStartsWith( 'mgs1:', $new );
		$this->assertSame( 'new-secret-9876', Crypto::decrypt( $new ) );
		$this->assertSame( '', $module->sanitize_client_secret( '__delete__' ) );

		ob_start();
		$module->field_client_secret();
		$html = (string) ob_get_clean();
		$this->assertStringNotContainsString( 'top-secret-value', $html );
		$this->assertStringContainsString( 'alue', $html, 'Only the last four characters are hinted.' );
		$this->assertStringContainsString( 'value=""', $html );
	}

	public function test_nothing_calls_the_network_when_not_connected(): void {
		$this->assertFalse( OAuth::connected() );
		$module = new Module();
		$module->boot();
		do_action( 'mgseo_daily' );
		$module->cron();
		$this->assertWPError( Sync::run() );
		$this->assertWPError( Client::request( 'GET', Client::SITES_URL ) );
		$this->assertWPError( Client::fetch_sites() );
		$this->assertWPError( OAuth::refresh() );
		$this->assertWPError( OAuth::access_token() );
		OAuth::disconnect();
		$this->assertSame( array(), $this->requests );
	}

	public function test_nothing_calls_the_network_when_module_disabled(): void {
		$this->connect();
		$this->set_option( 'gsc', 'property', 'sc-domain:example.org' );
		update_option( 'mgseo_modules', array( 'gsc' => false ) );
		$this->assertFalse( Client::ready() );
		$this->assertWPError( Sync::run() );
		$this->assertWPError( Client::request( 'GET', Client::SITES_URL ) );
		$this->assertWPError( OAuth::exchange_code( 'code', 'verifier' ) );
		( new Module() )->cron();
		OAuth::disconnect();
		$this->assertSame( array(), $this->requests );
		$this->assertFalse( OAuth::connected() );
	}

	public function test_disconnect_revokes_at_google(): void {
		$this->connect();
		$this->responder = static fn() => self::json( array() );
		OAuth::disconnect();
		$this->assertCount( 1, $this->requests );
		$this->assertSame( OAuth::REVOKE_URL, $this->requests[0]['url'] );
		$this->assertSame( '1//refresh', $this->requests[0]['args']['body']['token'] );
		$this->assertFalse( OAuth::connected() );
	}

	public function test_sites_listing_and_property_matching(): void {
		$this->connect();
		$this->responder = static fn() => self::json(
			array(
				'siteEntry' => array(
					array( 'siteUrl' => 'sc-domain:example.org', 'permissionLevel' => 'siteOwner' ),
					array( 'siteUrl' => 'http://example.org/', 'permissionLevel' => 'siteFullUser' ),
					array( 'siteUrl' => 'https://other.com/', 'permissionLevel' => 'siteUnverifiedUser' ),
				),
			)
		);
		$sites = Client::fetch_sites();
		$this->assertCount( 2, $sites );
		$this->assertSame( 'Bearer ya29.valid', $this->requests[0]['args']['headers']['Authorization'] );
		$this->assertSame( 'http://example.org/', Client::property(), 'URL-prefix property matching home is preferred.' );
		$this->assertTrue( Client::url_in_property( 'https://blog.example.org/x', 'sc-domain:example.org' ) );
		$this->assertFalse( Client::url_in_property( 'https://notexample.org/x', 'sc-domain:example.org' ) );
		$this->assertFalse( Client::url_in_property( 'https://example.org/x', 'http://example.org/' ) );
		$module = new Module();
		$this->assertSame( 'sc-domain:example.org', $module->sanitize_property( 'sc-domain:example.org' ) );
		$this->assertSame( '', $module->sanitize_property( 'https://evil.example/' ) );
	}
}
