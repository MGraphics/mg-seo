<?php
/**
 * Encryption at rest for Search Console secrets and tokens.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\SearchConsole;

defined( 'ABSPATH' ) || exit;

/**
 * Symmetric encryption with libsodium (secretbox). WordPress ships sodium_compat, so this works on every
 * supported PHP build. The key is derived from AUTH_KEY + SECURE_AUTH_SALT; when those constants are
 * missing or still the sample value, WordPress' generated salts (stored in the database) are used instead.
 *
 * Format: "mgs1:" + base64( nonce . ciphertext ). If sodium is unavailable the value is stored as
 * "mgs0:" + base64( plaintext ), which keeps the plugin working while making the weaker storage explicit.
 */
final class Crypto {

	private const PREFIX       = 'mgs1:';
	private const PLAIN_PREFIX = 'mgs0:';

	/**
	 * Whether real encryption is available.
	 */
	public static function available(): bool {
		return function_exists( 'sodium_crypto_secretbox' ) && function_exists( 'sodium_crypto_generichash' ) && defined( 'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' );
	}

	/**
	 * Derive the 32-byte key.
	 */
	private static function key(): string {
		$material = '';
		if ( defined( 'AUTH_KEY' ) && defined( 'SECURE_AUTH_SALT' ) && AUTH_KEY && SECURE_AUTH_SALT && 'put your unique phrase here' !== AUTH_KEY ) {
			$material = AUTH_KEY . SECURE_AUTH_SALT;
		}
		if ( '' === $material ) {
			$material = wp_salt( 'secure_auth' ) . wp_salt( 'auth' );
		}
		return sodium_crypto_generichash( 'mgseo-gsc|' . $material, '', SODIUM_CRYPTO_SECRETBOX_KEYBYTES );
	}

	/**
	 * Encrypt a string.
	 *
	 * @param string $plain Plain text.
	 */
	public static function encrypt( string $plain ): string {
		if ( '' === $plain ) {
			return '';
		}
		if ( ! self::available() ) {
			return self::PLAIN_PREFIX . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storage encoding.
		}
		try {
			$nonce  = random_bytes( SODIUM_CRYPTO_SECRETBOX_NONCEBYTES );
			$cipher = sodium_crypto_secretbox( $plain, $nonce, self::key() );
		} catch ( \Throwable $e ) {
			return self::PLAIN_PREFIX . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storage encoding.
		}
		return self::PREFIX . base64_encode( $nonce . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- storage encoding.
	}

	/**
	 * Decrypt a string. Returns null when the value cannot be decrypted (e.g. the keys were rotated).
	 *
	 * @param string $stored Stored value.
	 */
	public static function decrypt( string $stored ): ?string {
		if ( '' === $stored ) {
			return '';
		}
		if ( str_starts_with( $stored, self::PLAIN_PREFIX ) ) {
			$plain = base64_decode( substr( $stored, strlen( self::PLAIN_PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- storage encoding.
			return false === $plain ? null : $plain;
		}
		if ( ! str_starts_with( $stored, self::PREFIX ) || ! self::available() ) {
			return null;
		}
		$raw = base64_decode( substr( $stored, strlen( self::PREFIX ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- storage encoding.
		if ( false === $raw || strlen( $raw ) <= SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ) {
			return null;
		}
		try {
			$plain = sodium_crypto_secretbox_open( substr( $raw, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), substr( $raw, 0, SODIUM_CRYPTO_SECRETBOX_NONCEBYTES ), self::key() );
		} catch ( \Throwable $e ) {
			return null;
		}
		return false === $plain ? null : $plain;
	}

	/**
	 * Whether a stored value is in an encrypted (or plain-fallback) envelope.
	 *
	 * @param string $stored Stored value.
	 */
	public static function is_envelope( string $stored ): bool {
		return str_starts_with( $stored, self::PREFIX ) || str_starts_with( $stored, self::PLAIN_PREFIX );
	}
}
