<?php
/**
 * Search Analytics → local table synchronisation.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\SearchConsole;

use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Stores two kinds of rows in {prefix}mgseo_gsc:
 * - site totals per day (page = '' and query = ''), from a query with the `date` dimension only
 *   (includes anonymised queries, so the overview matches the Search Console UI);
 * - detail rows per day, page and query (dimensions date, page, query).
 *
 * Each run refreshes the most recent days (Google finalises data with a delay of 2-3 days) and backfills
 * older days in chunks, within a request budget, until the configured window is covered.
 */
final class Sync {

	public const STATE     = 'mgseo_gsc_sync';
	public const ROW_LIMIT = 25000;

	/**
	 * Table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mgseo_gsc';
	}

	/**
	 * Create the table.
	 */
	public static function create_table(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		dbDelta(
			"CREATE TABLE {$table} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  date date NOT NULL,
  page varchar(500) NOT NULL DEFAULT '',
  query varchar(255) NOT NULL DEFAULT '',
  clicks int(10) unsigned NOT NULL DEFAULT 0,
  impressions int(10) unsigned NOT NULL DEFAULT 0,
  ctr float NOT NULL DEFAULT 0,
  position float NOT NULL DEFAULT 0,
  PRIMARY KEY  (id),
  KEY date (date),
  KEY page (page(191)),
  KEY query (query(191))
) {$charset};"
		);
	}

	/**
	 * Drop the table.
	 */
	public static function drop_table(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name only.
	}

	/**
	 * Rows per Search Analytics request (API maximum 25,000).
	 */
	public static function row_limit(): int {
		return max( 1, min( self::ROW_LIMIT, (int) apply_filters( 'mgseo/gsc/row_limit', self::ROW_LIMIT ) ) );
	}

	/**
	 * Latest day with final data we ask for.
	 */
	public static function end_date(): string {
		$lag = max( 1, (int) apply_filters( 'mgseo/gsc/data_lag_days', 2 ) );
		return gmdate( 'Y-m-d', time() - $lag * DAY_IN_SECONDS );
	}

	/**
	 * Run a sync pass.
	 *
	 * @param int|null $budget Max API requests for this run.
	 * @return array{rows: int, requests: int, ranges: array}|\WP_Error
	 */
	public static function run( ?int $budget = null ): array|\WP_Error {
		if ( ! Client::ready() ) {
			return new \WP_Error( 'mgseo_gsc_not_connected', __( 'Search Console is not connected.', 'mg-seo' ) );
		}
		if ( '' === Client::property() ) {
			return new \WP_Error( 'mgseo_gsc_no_property', __( 'Select a Search Console property first.', 'mg-seo' ) );
		}
		$budget = $budget ?? (int) apply_filters( 'mgseo/gsc/request_budget', 40 );
		$days   = max( 1, min( 486, (int) Options::get( 'gsc', 'sync_days', 90 ) ) );
		$end    = self::end_date();
		$window = self::shift( $end, -( $days - 1 ) );
		$state  = get_option( self::STATE, array() );
		$state  = is_array( $state ) ? $state : array();
		if ( ( $state['property'] ?? '' ) !== Client::property() ) {
			// Property changed: start over.
			self::truncate();
			$state = array( 'property' => Client::property() );
		}

		$stats = array(
			'rows'     => 0,
			'requests' => 0,
			'ranges'   => array(),
		);

		// 1. Recent days (and any gap since the last run).
		$recent_start = self::shift( $end, -4 );
		if ( ! empty( $state['to'] ) && $state['to'] < $recent_start ) {
			$recent_start = self::shift( $state['to'], 1 );
		}
		$recent_start = max( $window, $recent_start );
		$result       = self::sync_range( $recent_start, $end, $stats );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$state['to']   = $end;
		$state['from'] = isset( $state['from'] ) ? min( (string) $state['from'], $recent_start ) : $recent_start;
		if ( $state['from'] < $window ) {
			$state['from'] = $window;
		}

		// 2. Backfill older days in chunks while budget remains.
		$chunk = max( 1, (int) apply_filters( 'mgseo/gsc/backfill_chunk_days', 30 ) );
		while ( $state['from'] > $window && $stats['requests'] + 2 <= $budget ) {
			$chunk_end   = self::shift( $state['from'], -1 );
			$chunk_start = max( $window, self::shift( $chunk_end, -( $chunk - 1 ) ) );
			$result      = self::sync_range( $chunk_start, $chunk_end, $stats );
			if ( is_wp_error( $result ) ) {
				update_option( self::STATE, $state, false );
				return $result;
			}
			$state['from'] = $chunk_start;
		}

		self::purge();
		$state['last_run']  = time();
		$state['last_rows'] = $stats['rows'];
		update_option( self::STATE, $state, false );
		delete_option( Module::ERROR_OPTION );
		return $stats;
	}

	/**
	 * Fetch and store one date range (replaces existing rows for those days).
	 *
	 * @param string $start Start (Y-m-d).
	 * @param string $end   End (Y-m-d).
	 * @param array  $stats Running stats (by reference).
	 * @return true|\WP_Error
	 */
	public static function sync_range( string $start, string $end, array &$stats ): bool|\WP_Error {
		$base = array(
			'startDate' => $start,
			'endDate'   => $end,
			'type'      => 'web',
		);

		$totals = Client::search_analytics(
			$base + array(
				'dimensions' => array( 'date' ),
				'rowLimit'   => self::ROW_LIMIT,
			)
		);
		++$stats['requests'];
		if ( is_wp_error( $totals ) ) {
			return $totals;
		}
		$rows = array();
		foreach ( (array) ( $totals['rows'] ?? array() ) as $row ) {
			$parsed = self::parse_row( $row, array( 'date' ) );
			if ( $parsed ) {
				$rows[] = $parsed;
			}
		}

		$limit     = self::row_limit();
		$max_pages = max( 1, (int) apply_filters( 'mgseo/gsc/max_pages_per_range', 40 ) );
		for ( $page = 0; $page < $max_pages; $page++ ) {
			$detail = Client::search_analytics(
				$base + array(
					'dimensions' => array( 'date', 'page', 'query' ),
					'rowLimit'   => $limit,
					'startRow'   => $page * $limit,
				)
			);
			++$stats['requests'];
			if ( is_wp_error( $detail ) ) {
				return $detail;
			}
			$batch = (array) ( $detail['rows'] ?? array() );
			foreach ( $batch as $row ) {
				$parsed = self::parse_row( $row, array( 'date', 'page', 'query' ) );
				if ( $parsed ) {
					$rows[] = $parsed;
				}
			}
			if ( count( $batch ) < $limit ) {
				break;
			}
		}

		self::replace( $start, $end, $rows );
		$stats['rows']    += count( $rows );
		$stats['ranges'][] = array( $start, $end );
		return true;
	}

	/**
	 * Turn an API row into a table row.
	 *
	 * @param array    $row        API row.
	 * @param string[] $dimensions Dimension order.
	 * @return array{date: string, page: string, query: string, clicks: int, impressions: int, ctr: float, position: float}|null
	 */
	public static function parse_row( array $row, array $dimensions ): ?array {
		$keys = array_values( (array) ( $row['keys'] ?? array() ) );
		$map  = array();
		foreach ( $dimensions as $i => $dimension ) {
			$map[ $dimension ] = (string) ( $keys[ $i ] ?? '' );
		}
		if ( empty( $map['date'] ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $map['date'] ) ) {
			return null;
		}
		return array(
			'date'        => $map['date'],
			'page'        => mb_substr( $map['page'] ?? '', 0, 500 ),
			'query'       => mb_substr( mb_strtolower( $map['query'] ?? '' ), 0, 255 ),
			'clicks'      => max( 0, (int) ( $row['clicks'] ?? 0 ) ),
			'impressions' => max( 0, (int) ( $row['impressions'] ?? 0 ) ),
			'ctr'         => (float) ( $row['ctr'] ?? 0 ),
			'position'    => (float) ( $row['position'] ?? 0 ),
		);
	}

	/**
	 * Replace rows of a date range with new rows (multi-row inserts of 500).
	 *
	 * @param string $start Start date.
	 * @param string $end   End date.
	 * @param array  $rows  Parsed rows.
	 */
	public static function replace( string $start, string $end, array $rows ): void {
		global $wpdb;
		$table = self::table();
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- placeholders built per row.
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE date BETWEEN %s AND %s", $start, $end ) );
		foreach ( array_chunk( $rows, 500 ) as $chunk ) {
			$placeholders = array();
			$values       = array();
			foreach ( $chunk as $row ) {
				$placeholders[] = '(%s, %s, %s, %d, %d, %f, %f)';
				array_push( $values, $row['date'], $row['page'], $row['query'], $row['clicks'], $row['impressions'], $row['ctr'], $row['position'] );
			}
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (date, page, query, clicks, impressions, ctr, position) VALUES " . implode( ', ', $placeholders ), $values ) );
		}
		// phpcs:enable
	}

	/**
	 * Delete rows older than the retention period.
	 */
	public static function purge(): void {
		global $wpdb;
		$days = max( 30, (int) Options::get( 'gsc', 'retention_days', 486 ) );
		$cut  = self::shift( gmdate( 'Y-m-d' ), -$days );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE date < %s', $cut ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name only.
	}

	/**
	 * Delete all stored data and the sync state.
	 */
	public static function truncate(): void {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . self::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name only.
		delete_option( self::STATE );
	}

	/**
	 * Add days to a Y-m-d date.
	 *
	 * @param string $date Date.
	 * @param int    $days Days (may be negative).
	 */
	public static function shift( string $date, int $days ): string {
		return gmdate( 'Y-m-d', (int) strtotime( $date . ' 00:00:00 UTC' ) + $days * DAY_IN_SECONDS );
	}
}
