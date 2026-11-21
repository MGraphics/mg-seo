<?php
/**
 * Google OAuth 2.0 (authorization code + PKCE) for the site owner's own Google Cloud project.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\SearchConsole;

use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the consent URL, exchanges/refreshes/revokes tokens and stores them encrypted.
 */
final class OAuth {

	public const AUTH_URL     = 'https://accounts.google.com/o/oauth2/v2/auth';
	public const TOKEN_URL    = 'https://oauth2.googleapis.com/token';
	public const REVOKE_URL   = 'https://oauth2.googleapis.com/revoke';
	public const SCOPE_GSC    = 'https://www.googleapis.com/auth/webmasters.readonly';
	public const SCOPE_INDEX  = 'https://www.googleapis.com/auth/indexing';
	public const TOKENS       = 'mgseo_gsc_tokens';
	public const STATE_PREFIX = 'mgseo_gsc_state_';
	public const TIMEOUT      = 15;

	/**
	 * OAuth client id from settings.
	 */
	public static function client_id(): string {
		return trim( (string) Options::get( 'gsc', 'client_id', '' ) );
	}

	/**
	 * OAuth client secret (decrypted).
	 */
	public static function client_secret(): string {
		$stored = (string) Options::get( 'gsc', 'client_secret', '' );
		if ( '' === $stored ) {
			return '';
		}
		if ( ! Crypto::is_envelope( $stored ) ) {
			return $stored;
		}
		return (string) Crypto::decrypt( $stored );
	}

	/**
	 * Whether client credentials are configured.
	 */
	public static function configured(): bool {
		return '' !== self::client_id() && '' !== self::client_secret();
	}

	/**
	 * Redirect URI to register in the Google Cloud console.
	 */
	public static function redirect_uri(): string {
		return admin_url( 'admin-post.php?action=mgseo_gsc_oauth' );
	}

	/**
	 * Requested scopes.
	 *
	 * @return string[]
	 */
	public static function scopes(): array {
		$scopes = array( self::SCOPE_GSC );
		if ( Options::get( 'gsc', 'indexing_api', false ) ) {
			$scopes[] = self::SCOPE_INDEX;
		}
		return $scopes;
	}

	/**
	 * Random PKCE code verifier (RFC 7636: 43-128 chars of the unreserved set).
	 */
	public static function code_verifier(): string {
		$chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789-._~';
		$out   = '';
		for ( $i = 0; $i < 64; $i++ ) {
			$out .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
		}
		return $out;
	}

	/**
	 * S256 code challenge for a verifier.
	 *
	 * @param string $verifier Verifier.
	 */
	public static function code_challenge( string $verifier ): string {
		return rtrim( strtr( base64_encode( hash( 'sha256', $verifier, true ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- base64url per RFC 7636.
	}

	/**
	 * Build the consent URL and remember state + verifier for the current user (15 minutes).
	 */
	public static function authorization_url(): string {
		$state    = wp_generate_password( 32, false, false );
		$verifier = self::code_verifier();
		set_transient(
			self::STATE_PREFIX . $state,
			array(
				'verifier' => $verifier,
				'user'     => get_current_user_id(),
			),
			15 * MINUTE_IN_SECONDS
		);
		$params = array(
			'client_id'              => self::client_id(),
			'redirect_uri'           => self::redirect_uri(),
			'response_type'          => 'code',
			'scope'                  => implode( ' ', self::scopes() ),
			'access_type'            => 'offline',
			'prompt'                 => 'consent',
			'include_granted_scopes' => 'true',
			'state'                  => $state,
			'code_challenge'         => self::code_challenge( $verifier ),
			'code_challenge_method'  => 'S256',
		);
		return self::AUTH_URL . '?' . http_build_query( $params, '', '&', PHP_QUERY_RFC3986 );
	}

	/**
	 * Validate and consume a state value. Returns the PKCE verifier or null.
	 *
	 * @param string $state State from the callback.
	 */
	public static function consume_state( string $state ): ?string {
		if ( '' === $state || ! preg_match( '/^[A-Za-z0-9]{32}$/', $state ) ) {
			return null;
		}
		$data = get_transient( self::STATE_PREFIX . $state );
		delete_transient( self::STATE_PREFIX . $state );
		if ( ! is_array( $data ) || empty( $data['verifier'] ) || (int) ( $data['user'] ?? 0 ) !== get_current_user_id() ) {
			return null;
		}
		return (string) $data['verifier'];
	}

	/**
	 * Exchange an authorization code for tokens and store them.
	 *
	 * @param string $code     Authorization code.
	 * @param string $verifier PKCE verifier.
	 * @return true|\WP_Error
	 */
	public static function exchange_code( string $code, string $verifier ): bool|\WP_Error {
		if ( ! self::configured() ) {
			return new \WP_Error( 'mgseo_gsc_not_configured', __( 'Enter the OAuth client ID and client secret first.', 'mg-seo' ) );
		}
		$data = self::token_request(
			array(
				'code'          => $code,
				'client_id'     => self::client_id(),
				'client_secret' => self::client_secret(),
				'redirect_uri'  => self::redirect_uri(),
				'grant_type'    => 'authorization_code',
				'code_verifier' => $verifier,
			)
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['refresh_token'] ) ) {
			return new \WP_Error( 'mgseo_gsc_no_refresh', __( 'Google did not return a refresh token. Remove the app from your Google account permissions and connect again.', 'mg-seo' ) );
		}
		self::save_tokens(
			array(
				'access_token'  => (string) $data['access_token'],
				'refresh_token' => (string) $data['refresh_token'],
				'expires_at'    => time() + (int) ( $data['expires_in'] ?? 3600 ),
				'scope'         => (string) ( $data['scope'] ?? implode( ' ', self::scopes() ) ),
				'connected_at'  => time(),
				'connected_by'  => get_current_user_id(),
			)
		);
		return true;
	}

	/**
	 * Refresh the access token.
	 *
	 * @return true|\WP_Error
	 */
	public static function refresh(): bool|\WP_Error {
		$tokens = self::tokens();
		if ( ! $tokens || empty( $tokens['refresh_token'] ) ) {
			return new \WP_Error( 'mgseo_gsc_not_connected', __( 'Search Console is not connected.', 'mg-seo' ) );
		}
		$data = self::token_request(
			array(
				'client_id'     => self::client_id(),
				'client_secret' => self::client_secret(),
				'refresh_token' => $tokens['refresh_token'],
				'grant_type'    => 'refresh_token',
			)
		);
		if ( is_wp_error( $data ) ) {
			if ( 'invalid_grant' === $data->get_error_code() ) {
				// The grant was revoked or expired: drop it so the UI asks to reconnect.
				self::forget();
				Module::log_error( __( 'Google revoked access for MG SEO. Connect your account again.', 'mg-seo' ) );
			}
			return $data;
		}
		$tokens['access_token'] = (string) $data['access_token'];
		$tokens['expires_at']   = time() + (int) ( $data['expires_in'] ?? 3600 );
		if ( ! empty( $data['refresh_token'] ) ) {
			$tokens['refresh_token'] = (string) $data['refresh_token'];
		}
		if ( ! empty( $data['scope'] ) ) {
			$tokens['scope'] = (string) $data['scope'];
		}
		self::save_tokens( $tokens );
		return true;
	}

	/**
	 * Valid access token (refreshing when it expires within a minute).
	 *
	 * @return string|\WP_Error
	 */
	public static function access_token(): string|\WP_Error {
		$tokens = self::tokens();
		if ( ! $tokens ) {
			return new \WP_Error( 'mgseo_gsc_not_connected', __( 'Search Console is not connected.', 'mg-seo' ) );
		}
		if ( empty( $tokens['access_token'] ) || (int) ( $tokens['expires_at'] ?? 0 ) < time() + 60 ) {
			$refreshed = self::refresh();
			if ( is_wp_error( $refreshed ) ) {
				return $refreshed;
			}
			$tokens = self::tokens();
		}
		return (string) ( $tokens['access_token'] ?? '' );
	}

	/**
	 * Revoke the grant at Google (best effort) and forget the tokens.
	 */
	public static function disconnect(): void {
		$tokens = self::tokens();
		if ( $tokens && Module::enabled() ) {
			$token = (string) ( $tokens['refresh_token'] ?? $tokens['access_token'] ?? '' );
			if ( '' !== $token ) {
				wp_remote_post(
					self::REVOKE_URL,
					array(
						'timeout' => self::TIMEOUT,
						'headers' => array( 'Content-Type' => 'application/x-www-form-urlencoded' ),
						'body'    => array( 'token' => $token ),
					)
				);
			}
		}
		self::forget();
	}

	/**
	 * Delete stored tokens and cached account data.
	 */
	public static function forget(): void {
		delete_option( self::TOKENS );
		delete_option( 'mgseo_gsc_sites' );
	}

	/**
	 * Whether an account is connected (tokens stored and decryptable).
	 */
	public static function connected(): bool {
		$tokens = self::tokens();
		return ! empty( $tokens['refresh_token'] );
	}

	/**
	 * Whether the stored grant includes a scope.
	 *
	 * @param string $scope Scope URL.
	 */
	public static function has_scope( string $scope ): bool {
		$tokens = self::tokens();
		return $tokens && in_array( $scope, explode( ' ', (string) ( $tokens['scope'] ?? '' ) ), true );
	}

	/**
	 * Decrypted tokens.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function tokens(): ?array {
		$stored = get_option( self::TOKENS, '' );
		if ( ! is_string( $stored ) || '' === $stored ) {
			return null;
		}
		$json = Crypto::decrypt( $stored );
		if ( null === $json ) {
			return null;
		}
		$data = json_decode( $json, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Encrypt and store tokens (not autoloaded).
	 *
	 * @param array<string, mixed> $tokens Tokens.
	 */
	public static function save_tokens( array $tokens ): void {
		$value = Crypto::encrypt( (string) wp_json_encode( $tokens ) );
		if ( false === get_option( self::TOKENS, false ) ) {
			add_option( self::TOKENS, $value, '', false );
		} else {
			update_option( self::TOKENS, $value, false );
		}
	}

	/**
	 * POST to the token endpoint.
	 *
	 * @param array<string, string> $body Form fields.
	 * @return array<string, mixed>|\WP_Error
	 */
	private static function token_request( array $body ): array|\WP_Error {
		if ( ! Module::enabled() ) {
			return new \WP_Error( 'mgseo_gsc_disabled', __( 'The Search Console module is disabled.', 'mg-seo' ) );
		}
		$response = wp_remote_post(
			self::TOKEN_URL,
			array(
				'timeout' => self::TIMEOUT,
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
					'Accept'       => 'application/json',
				),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$data = is_array( $data ) ? $data : array();
		if ( $code < 200 || $code >= 300 || empty( $data['access_token'] ) ) {
			$error = (string) ( $data['error'] ?? 'mgseo_gsc_token_error' );
			$desc  = (string) ( $data['error_description'] ?? '' );
			/* translators: 1: HTTP status code, 2: error code from Google, 3: error description */
			return new \WP_Error( $error, sprintf( __( 'Google token request failed (HTTP %1$d, %2$s). %3$s', 'mg-seo' ), $code, $error, $desc ) );
		}
		return $data;
	}
}
