<?php
/**
 * Sitemap cache.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Sitemap;

defined( 'ABSPATH' ) || exit;

/**
 * Versioned cache for generated sitemaps and item lists.
 *
 * Every entry is stored under a fixed key together with the cache version it was built for.
 * Invalidation bumps the version, so stale entries are simply rebuilt and overwritten (no orphans).
 * Transients use the object cache automatically when one is installed.
 */
final class Cache {

	public const VERSION_OPTION = 'mgseo_sitemap_version';

	/**
	 * Whether the version was bumped since the last cache write in this request.
	 *
	 * @var bool
	 */
	private static bool $bumped = false;

	/**
	 * Whether caching is enabled.
	 */
	public static function enabled(): bool {
		/**
		 * Filter whether generated sitemaps are cached.
		 *
		 * @param bool $enabled Enabled.
		 */
		return (bool) apply_filters( 'mgseo/sitemap/cache_enabled', true );
	}

	/**
	 * Current cache version.
	 */
	public static function version(): string {
		return (string) get_option( self::VERSION_OPTION, '0' );
	}

	/**
	 * Read a cached value.
	 *
	 * @param string $key Logical key.
	 * @return mixed|null Null when missing or stale.
	 */
	public static function get( string $key ): mixed {
		if ( ! self::enabled() ) {
			return null;
		}
		$stored = get_transient( self::key( $key ) );
		if ( ! is_array( $stored ) || ! isset( $stored['v'] ) || self::version() !== $stored['v'] || ! array_key_exists( 'data', $stored ) ) {
			return null;
		}
		return $stored['data'];
	}

	/**
	 * Store a value.
	 *
	 * @param string $key    Logical key.
	 * @param mixed  $data   Data.
	 * @param int    $expiry Seconds.
	 */
	public static function set( string $key, mixed $data, int $expiry = DAY_IN_SECONDS ): void {
		if ( ! self::enabled() ) {
			return;
		}
		set_transient(
			self::key( $key ),
			array(
				'v'    => self::version(),
				'data' => $data,
			),
			$expiry
		);
		self::$bumped = false;
	}

	/**
	 * Invalidate everything (at most one option write until the next cache write).
	 */
	public static function bump(): void {
		if ( self::$bumped ) {
			return;
		}
		self::$bumped = true;
		update_option( self::VERSION_OPTION, uniqid( '', true ), false );
	}

	/**
	 * Delete every stored entry and the version option (uninstall).
	 */
	public static function purge(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- uninstall cleanup of our own transients.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_mgseo_sm_' ) . '%', $wpdb->esc_like( '_transient_timeout_mgseo_sm_' ) . '%' ) );
		delete_option( self::VERSION_OPTION );
		self::$bumped = false;
	}

	/**
	 * Transient key.
	 *
	 * @param string $key Logical key.
	 */
	private static function key( string $key ): string {
		return 'mgseo_sm_' . md5( $key );
	}
}
