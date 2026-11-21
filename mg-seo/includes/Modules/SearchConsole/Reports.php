<?php
/**
 * Aggregation queries over the local Search Console table.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\SearchConsole;

defined( 'ABSPATH' ) || exit;

/**
 * All report data is computed locally from {prefix}mgseo_gsc. Average position is weighted by impressions.
 */
final class Reports {

	public const TRACKED = 'mgseo_gsc_tracked';

	/**
	 * Latest date stored (or the expected end date when the table is empty).
	 */
	public static function latest_date(): string {
		global $wpdb;
		$date = $wpdb->get_var( 'SELECT MAX(date) FROM ' . Sync::table() ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- table name only.
		return $date ? (string) $date : Sync::end_date();
	}

	/**
	 * Current and previous period of the same length.
	 *
	 * @param int         $days Days.
	 * @param string|null $end  End date (defaults to the latest stored date).
	 * @return array{start: string, end: string, prev_start: string, prev_end: string, days: int}
	 */
	public static function period( int $days, ?string $end = null ): array {
		$days  = max( 1, $days );
		$end   = $end ?? self::latest_date();
		$start = Sync::shift( $end, -( $days - 1 ) );
		return array(
			'start'      => $start,
			'end'        => $end,
			'prev_start' => Sync::shift( $start, -$days ),
			'prev_end'   => Sync::shift( $start, -1 ),
			'days'       => $days,
		);
	}

	/**
	 * SELECT list for metrics.
	 */
	private static function metrics_sql(): string {
		return 'SUM(clicks) AS clicks, SUM(impressions) AS impressions, '
			. 'IF(SUM(impressions) > 0, SUM(clicks) / SUM(impressions), 0) AS ctr, '
			. 'IF(SUM(impressions) > 0, SUM(position * impressions) / SUM(impressions), AVG(position)) AS position';
	}

	/**
	 * Cast a DB row to numbers.
	 *
	 * @param array|object|null $row Row.
	 * @return array<string, mixed>
	 */
	private static function cast( $row ): array {
		$row = (array) $row;
		foreach ( array( 'clicks', 'impressions' ) as $key ) {
			$row[ $key ] = (int) ( $row[ $key ] ?? 0 );
		}
		foreach ( array( 'ctr', 'position' ) as $key ) {
			$row[ $key ] = round( (float) ( $row[ $key ] ?? 0 ), 4 );
		}
		return $row;
	}

	/**
	 * Whether site total rows exist for a range (otherwise detail rows are summed).
	 *
	 * @param string $start Start.
	 * @param string $end   End.
	 */
	private static function has_totals( string $start, string $end ): bool {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		return (bool) $wpdb->get_var( $wpdb->prepare( 'SELECT 1 FROM ' . Sync::table() . " WHERE date BETWEEN %s AND %s AND page = '' AND query = '' LIMIT 1", $start, $end ) );
	}

	/**
	 * Site totals for a range.
	 *
	 * @param string $start Start.
	 * @param string $end   End.
	 * @return array{clicks: int, impressions: int, ctr: float, position: float}
	 */
	public static function totals( string $start, string $end ): array {
		global $wpdb;
		$where = self::has_totals( $start, $end ) ? "page = '' AND query = ''" : "query <> ''";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::metrics_sql() . ' FROM ' . Sync::table() . " WHERE date BETWEEN %s AND %s AND {$where}", $start, $end ), ARRAY_A );
		return self::cast( $row );
	}

	/**
	 * Daily series for a range (every day present, zero-filled).
	 *
	 * @param string $start Start.
	 * @param string $end   End.
	 * @return array<string, array{clicks: int, impressions: int, ctr: float, position: float}>
	 */
	public static function daily( string $start, string $end ): array {
		global $wpdb;
		$where = self::has_totals( $start, $end ) ? "page = '' AND query = ''" : "query <> ''";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT date, ' . self::metrics_sql() . ' FROM ' . Sync::table() . " WHERE date BETWEEN %s AND %s AND {$where} GROUP BY date ORDER BY date", $start, $end ), ARRAY_A );
		return self::fill( $start, $end, $rows );
	}

	/**
	 * Zero-fill a date series.
	 *
	 * @param string $start Start.
	 * @param string $end   End.
	 * @param array  $rows  Rows with a date key.
	 * @return array<string, array<string, mixed>>
	 */
	private static function fill( string $start, string $end, array $rows ): array {
		$by_date = array();
		foreach ( $rows as $row ) {
			$by_date[ (string) $row['date'] ] = $row;
		}
		$out = array();
		for ( $date = $start; $date <= $end; $date = Sync::shift( $date, 1 ) ) {
			$row = self::cast( $by_date[ $date ] ?? array() );
			unset( $row['date'] );
			$out[ $date ] = $row;
		}
		return $out;
	}

	/**
	 * Top rows grouped by a dimension.
	 *
	 * @param string $dimension page|query.
	 * @param string $start     Start.
	 * @param string $end       End.
	 * @param int    $limit     Limit.
	 * @param string $orderby   clicks|impressions|ctr|position.
	 * @param string $filter_page Restrict to one page URL ('' = all).
	 * @return array<int, array<string, mixed>>
	 */
	public static function top( string $dimension, string $start, string $end, int $limit = 25, string $orderby = 'clicks', string $filter_page = '' ): array {
		global $wpdb;
		$dimension = 'page' === $dimension ? 'page' : 'query';
		$orderby   = in_array( $orderby, array( 'clicks', 'impressions', 'ctr', 'position' ), true ) ? $orderby : 'clicks';
		$order     = 'position' === $orderby ? 'ASC' : 'DESC';
		$params    = array( $start, $end );
		$extra     = '';
		if ( '' !== $filter_page ) {
			$extra    = ' AND page = %s';
			$params[] = $filter_page;
		}
		$params[] = max( 1, $limit );
		$sql      = 'SELECT ' . $dimension . ' AS name, ' . self::metrics_sql() . ' FROM ' . Sync::table()
			. " WHERE date BETWEEN %s AND %s AND query <> ''" . $extra
			. ' GROUP BY ' . $dimension . ' ORDER BY ' . $orderby . ' ' . $order . ', impressions DESC LIMIT %d';
		$rows     = $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared -- prepared; identifiers whitelisted.
		return array_map( array( self::class, 'cast' ), (array) $rows );
	}

	/**
	 * Top pages.
	 *
	 * @param string $start   Start.
	 * @param string $end     End.
	 * @param int    $limit   Limit.
	 * @param string $orderby Order.
	 */
	public static function top_pages( string $start, string $end, int $limit = 25, string $orderby = 'clicks' ): array {
		return self::top( 'page', $start, $end, $limit, $orderby );
	}

	/**
	 * Top queries.
	 *
	 * @param string $start   Start.
	 * @param string $end     End.
	 * @param int    $limit   Limit.
	 * @param string $orderby Order.
	 */
	public static function top_queries( string $start, string $end, int $limit = 25, string $orderby = 'clicks' ): array {
		return self::top( 'query', $start, $end, $limit, $orderby );
	}

	/**
	 * Winning and losing keywords: average position change between the previous and the current period.
	 *
	 * @param array $period          Period from period().
	 * @param int   $limit           Rows per list.
	 * @param int   $min_impressions Minimum impressions in both periods.
	 * @return array{winners: array, losers: array}
	 */
	public static function movers( array $period, int $limit = 25, int $min_impressions = 5 ): array {
		$current  = self::by_name( self::top( 'query', $period['start'], $period['end'], 2000, 'impressions' ) );
		$previous = self::by_name( self::top( 'query', $period['prev_start'], $period['prev_end'], 2000, 'impressions' ) );
		$rows     = array();
		foreach ( $current as $name => $cur ) {
			if ( ! isset( $previous[ $name ] ) ) {
				continue;
			}
			$prev = $previous[ $name ];
			if ( $cur['impressions'] < $min_impressions || $prev['impressions'] < $min_impressions ) {
				continue;
			}
			$rows[] = array(
				'name'          => $name,
				'position'      => $cur['position'],
				'prev_position' => $prev['position'],
				'change'        => round( $prev['position'] - $cur['position'], 2 ),
				'clicks'        => $cur['clicks'],
				'prev_clicks'   => $prev['clicks'],
				'impressions'   => $cur['impressions'],
			);
		}
		$winners = array_values( array_filter( $rows, static fn( $r ) => $r['change'] > 0 ) );
		$losers  = array_values( array_filter( $rows, static fn( $r ) => $r['change'] < 0 ) );
		usort( $winners, static fn( $a, $b ) => array( $b['change'], $b['impressions'] ) <=> array( $a['change'], $a['impressions'] ) );
		usort( $losers, static fn( $a, $b ) => array( $a['change'], $b['impressions'] ) <=> array( $b['change'], $a['impressions'] ) );
		return array(
			'winners' => array_slice( $winners, 0, $limit ),
			'losers'  => array_slice( $losers, 0, $limit ),
		);
	}

	/**
	 * Index rows by name.
	 *
	 * @param array $rows Rows.
	 * @return array<string, array>
	 */
	private static function by_name( array $rows ): array {
		$out = array();
		foreach ( $rows as $row ) {
			$out[ (string) $row['name'] ] = $row;
		}
		return $out;
	}

	/**
	 * Totals for one page URL.
	 *
	 * @param string $url   Page URL.
	 * @param string $start Start.
	 * @param string $end   End.
	 */
	public static function page_totals( string $url, string $start, string $end ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT ' . self::metrics_sql() . ' FROM ' . Sync::table() . " WHERE date BETWEEN %s AND %s AND page = %s AND query <> ''", $start, $end, $url ), ARRAY_A );
		return self::cast( $row );
	}

	/**
	 * Daily series for one page.
	 *
	 * @param string $url   Page URL.
	 * @param string $start Start.
	 * @param string $end   End.
	 */
	public static function page_daily( string $url, string $start, string $end ): array {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT date, ' . self::metrics_sql() . ' FROM ' . Sync::table() . " WHERE date BETWEEN %s AND %s AND page = %s AND query <> '' GROUP BY date", $start, $end, $url ), ARRAY_A );
		return self::fill( $start, $end, (array) $rows );
	}

	/**
	 * Tracked keywords.
	 *
	 * @return string[]
	 */
	public static function tracked_keywords(): array {
		$list = get_option( self::TRACKED, array() );
		return is_array( $list ) ? array_values( array_filter( array_map( 'strval', $list ) ) ) : array();
	}

	/**
	 * Save tracked keywords (normalised, unique, max 200).
	 *
	 * @param string[] $keywords Keywords.
	 */
	public static function set_tracked_keywords( array $keywords ): void {
		$clean = array();
		foreach ( $keywords as $keyword ) {
			$keyword = trim( (string) preg_replace( '/\s+/u', ' ', mb_strtolower( sanitize_text_field( (string) $keyword ) ) ) );
			if ( '' !== $keyword ) {
				$clean[ $keyword ] = true;
			}
		}
		update_option( self::TRACKED, array_slice( array_keys( $clean ), 0, 200 ), false );
	}

	/**
	 * Rank tracker data: per keyword the average position over the period and the previous period,
	 * the daily average position series and the best ranking page — all from stored GSC data.
	 *
	 * @param string[] $keywords Keywords.
	 * @param array    $period   Period.
	 * @return array<int, array<string, mixed>>
	 */
	public static function tracker( array $keywords, array $period ): array {
		global $wpdb;
		if ( ! $keywords ) {
			return array();
		}
		$table = Sync::table();
		$in    = implode( ', ', array_fill( 0, count( $keywords ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- IN() placeholders built above.
		$daily = $wpdb->get_results( $wpdb->prepare( 'SELECT query, date, ' . self::metrics_sql() . " FROM {$table} WHERE query IN ({$in}) AND date BETWEEN %s AND %s GROUP BY query, date", array_merge( $keywords, array( $period['prev_start'], $period['end'] ) ) ), ARRAY_A );
		$pages = $wpdb->get_results( $wpdb->prepare( "SELECT query, page, SUM(clicks) AS clicks, SUM(impressions) AS impressions FROM {$table} WHERE query IN ({$in}) AND date BETWEEN %s AND %s GROUP BY query, page ORDER BY clicks DESC, impressions DESC", array_merge( $keywords, array( $period['start'], $period['end'] ) ) ), ARRAY_A );
		// phpcs:enable
		$series = array();
		foreach ( (array) $daily as $row ) {
			$series[ (string) $row['query'] ][ (string) $row['date'] ] = self::cast( $row );
		}
		$best = array();
		foreach ( (array) $pages as $row ) {
			if ( ! isset( $best[ (string) $row['query'] ] ) ) {
				$best[ (string) $row['query'] ] = (string) $row['page'];
			}
		}
		$out = array();
		foreach ( $keywords as $keyword ) {
			$days = $series[ $keyword ] ?? array();
			$cur  = self::weighted( $days, $period['start'], $period['end'] );
			$prev = self::weighted( $days, $period['prev_start'], $period['prev_end'] );
			$line = array();
			for ( $date = $period['start']; $date <= $period['end']; $date = Sync::shift( $date, 1 ) ) {
				$line[ $date ] = isset( $days[ $date ] ) ? $days[ $date ]['position'] : null;
			}
			$out[] = array(
				'keyword'       => $keyword,
				'position'      => $cur['position'],
				'prev_position' => $prev['position'],
				'change'        => ( null !== $cur['position'] && null !== $prev['position'] ) ? round( $prev['position'] - $cur['position'], 2 ) : null,
				'clicks'        => $cur['clicks'],
				'impressions'   => $cur['impressions'],
				'page'          => $best[ $keyword ] ?? '',
				'series'        => $line,
			);
		}
		return $out;
	}

	/**
	 * Impression-weighted position over daily rows within a range.
	 *
	 * @param array  $days  date => row.
	 * @param string $start Start.
	 * @param string $end   End.
	 * @return array{position: float|null, clicks: int, impressions: int}
	 */
	private static function weighted( array $days, string $start, string $end ): array {
		$impressions = 0;
		$clicks      = 0;
		$sum         = 0.0;
		foreach ( $days as $date => $row ) {
			if ( $date < $start || $date > $end ) {
				continue;
			}
			$impressions += $row['impressions'];
			$clicks      += $row['clicks'];
			$sum         += $row['position'] * $row['impressions'];
		}
		return array(
			'position'    => $impressions > 0 ? round( $sum / $impressions, 2 ) : null,
			'clicks'      => $clicks,
			'impressions' => $impressions,
		);
	}
}
