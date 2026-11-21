<?php
/**
 * 404 monitor storage.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Redirections;

use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom log table.

/**
 * The `{prefix}mgseo_404` table.
 *
 * "simple" mode keeps one aggregated row per URI (unique hash of the URI) without IP addresses;
 * "advanced" mode stores one row per hit with referer, user agent and an anonymised IP.
 */
final class Log404 {

	/**
	 * Default exclusion patterns (one per line; "*" = wildcard over the whole path, otherwise substring).
	 */
	public const DEFAULT_EXCLUDE = "/wp-content/*\n/wp-includes/*\n*.map\n*.ico\n*/apple-touch-icon*\n/.well-known/*";

	/**
	 * Table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mgseo_404';
	}

	/**
	 * Create the table.
	 */
	public static function install(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				hash char(32) NOT NULL,
				uri text NOT NULL,
				referer text NOT NULL,
				user_agent varchar(255) NOT NULL DEFAULT '',
				ip varchar(45) NOT NULL DEFAULT '',
				hits bigint(20) unsigned NOT NULL DEFAULT 1,
				first_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				last_seen datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				UNIQUE KEY hash (hash),
				KEY last_seen (last_seen)
			) {$charset};"
		);
	}

	/**
	 * Drop the table.
	 */
	public static function drop(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall.
	}

	/**
	 * Anonymise an IP address: IPv4 → last octet zeroed, IPv6 → first 48 bits kept.
	 *
	 * @param string $ip IP.
	 */
	public static function anonymize_ip( string $ip ): string {
		$ip = trim( $ip );
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return (string) preg_replace( '/\.\d+$/', '.0', $ip );
		}
		if ( filter_var( $ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$packed = inet_pton( $ip );
			if ( false === $packed ) {
				return '';
			}
			$packed = substr( $packed, 0, 6 ) . str_repeat( "\0", 10 );
			return (string) inet_ntop( $packed );
		}
		return '';
	}

	/**
	 * Whether a 404 should be logged according to the exclusion settings.
	 *
	 * @param string $uri        Request URI (relative to home, leading slash).
	 * @param string $user_agent User agent.
	 */
	public static function should_log( string $uri, string $user_agent ): bool {
		$path = (string) strtok( $uri, '?' );
		$path = '' === $path ? '/' : $path;
		foreach ( self::exclusions() as $pattern ) {
			if ( str_contains( $pattern, '*' ) ) {
				$regex = '#^' . str_replace( '\*', '.*', preg_quote( $pattern, '#' ) ) . '$#i';
				if ( preg_match( $regex, $path ) ) {
					return false;
				}
			} elseif ( false !== stripos( $uri, $pattern ) ) {
				return false;
			}
		}
		if ( Options::get( 'redirections', 'log_ignore_bots', false ) && self::is_bot( $user_agent ) ) {
			return false;
		}
		/**
		 * Filter whether a 404 is logged.
		 *
		 * @param bool   $log        Log it.
		 * @param string $uri        URI.
		 * @param string $user_agent User agent.
		 */
		return (bool) apply_filters( 'mgseo/redirections/log_404', true, $uri, $user_agent );
	}

	/**
	 * Exclusion patterns from settings.
	 *
	 * @return string[]
	 */
	public static function exclusions(): array {
		$raw = (string) Options::get( 'redirections', 'log_exclude', self::DEFAULT_EXCLUDE );
		return array_values( array_filter( array_map( 'trim', (array) preg_split( '/\R/', $raw ) ) ) );
	}

	/**
	 * Whether a user agent looks like a crawler or script.
	 *
	 * @param string $user_agent User agent.
	 */
	public static function is_bot( string $user_agent ): bool {
		if ( '' === trim( $user_agent ) ) {
			return true;
		}
		return (bool) preg_match( '/bot\b|bot\/|crawl|spider|slurp|facebookexternalhit|preview|curl\/|wget|python-|httpclient|okhttp|go-http|java\/|libwww|scan|headless/i', $user_agent );
	}

	/**
	 * Store a 404 hit.
	 *
	 * @param string $uri        URI (relative to home, leading slash).
	 * @param string $referer    Referer.
	 * @param string $user_agent User agent.
	 * @param string $ip         Raw IP (anonymised before storage; ignored in simple mode).
	 * @param string $mode       simple|advanced.
	 */
	public static function log( string $uri, string $referer, string $user_agent, string $ip, string $mode = 'simple' ): void {
		global $wpdb;
		$uri        = mb_substr( $uri, 0, 2000 );
		$referer    = mb_substr( esc_url_raw( $referer ), 0, 2000 );
		$user_agent = mb_substr( sanitize_text_field( $user_agent ), 0, 255 );
		$now        = current_time( 'mysql', true );
		if ( 'advanced' === $mode ) {
			$wpdb->insert(
				self::table(),
				array(
					'hash'       => md5( $uri . '|' . uniqid( '', true ) . '|' . wp_rand() ),
					'uri'        => $uri,
					'referer'    => $referer,
					'user_agent' => $user_agent,
					'ip'         => self::anonymize_ip( $ip ),
					'hits'       => 1,
					'first_seen' => $now,
					'last_seen'  => $now,
				)
			);
		} else {
			$wpdb->query(
				$wpdb->prepare(
					'INSERT INTO %i (hash, uri, referer, user_agent, ip, hits, first_seen, last_seen) VALUES (%s, %s, %s, %s, %s, 1, %s, %s)
					ON DUPLICATE KEY UPDATE hits = hits + 1, last_seen = VALUES(last_seen), referer = IF(VALUES(referer) = \'\', referer, VALUES(referer)), user_agent = VALUES(user_agent)',
					self::table(),
					md5( 's|' . $uri ),
					$uri,
					$referer,
					$user_agent,
					'',
					$now,
					$now
				)
			);
		}
		// Occasionally enforce the row cap between daily cleanups.
		if ( 1 === wp_rand( 1, 200 ) ) {
			self::trim_rows();
		}
	}

	/**
	 * WHERE clause for list queries.
	 *
	 * @param array $args search.
	 */
	private static function where( array $args ): string {
		global $wpdb;
		$sql = ' WHERE 1=1';
		if ( ! empty( $args['search'] ) ) {
			$like = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$sql .= $wpdb->prepare( ' AND ( uri LIKE %s OR referer LIKE %s OR user_agent LIKE %s )', $like, $like, $like );
		}
		if ( ! empty( $args['ids'] ) ) {
			$ids  = array_map( 'intval', (array) $args['ids'] );
			$sql .= ' AND id IN (' . implode( ',', $ids ) . ')';
		}
		return $sql;
	}

	/**
	 * List log rows.
	 *
	 * @param array $args search, ids, orderby, order, per_page (0 = all), page.
	 * @return array<int, array>
	 */
	public static function all( array $args = array() ): array {
		global $wpdb;
		$orderby = in_array( $args['orderby'] ?? '', array( 'id', 'uri', 'hits', 'first_seen', 'last_seen' ), true ) ? $args['orderby'] : 'last_seen';
		$order   = 'ASC' === strtoupper( (string) ( $args['order'] ?? 'DESC' ) ) ? 'ASC' : 'DESC';
		$sql     = $wpdb->prepare( 'SELECT * FROM %i', self::table() ) . self::where( $args ) . " ORDER BY {$orderby} {$order}, id {$order}";
		$per     = (int) ( $args['per_page'] ?? 0 );
		if ( $per > 0 ) {
			$page = max( 1, (int) ( $args['page'] ?? 1 ) );
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $per, ( $page - 1 ) * $per );
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared fragments, whitelisted identifiers.
		return is_array( $rows ) ? $rows : array();
	}

	/**
	 * Count log rows.
	 *
	 * @param array $args Filters.
	 */
	public static function count( array $args = array() ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) . self::where( $args ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared fragments.
	}

	/**
	 * Delete rows.
	 *
	 * @param int[] $ids Ids.
	 */
	public static function delete( array $ids ): int {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders built above.
		return (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$placeholders})", self::table(), ...$ids ) );
	}

	/**
	 * Delete every row.
	 */
	public static function clear(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::table() ) );
	}

	/**
	 * Daily cleanup: retention period and row cap.
	 */
	public static function cleanup(): void {
		global $wpdb;
		$days = (int) Options::get( 'redirections', 'log_retention', 30 );
		if ( $days > 0 ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE last_seen < %s', self::table(), gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
		}
		self::trim_rows();
	}

	/**
	 * Keep only the newest N rows.
	 */
	public static function trim_rows(): void {
		global $wpdb;
		$max = (int) Options::get( 'redirections', 'log_max_rows', 5000 );
		if ( $max <= 0 ) {
			return;
		}
		$cutoff = $wpdb->get_var( $wpdb->prepare( 'SELECT id FROM %i ORDER BY last_seen DESC, id DESC LIMIT 1 OFFSET %d', self::table(), $max ) );
		if ( null === $cutoff ) {
			return;
		}
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT id, last_seen FROM %i WHERE id = %d', self::table(), (int) $cutoff ), ARRAY_A );
		if ( $row ) {
			$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE last_seen < %s OR ( last_seen = %s AND id <= %d )', self::table(), $row['last_seen'], $row['last_seen'], (int) $row['id'] ) );
		}
	}
}
