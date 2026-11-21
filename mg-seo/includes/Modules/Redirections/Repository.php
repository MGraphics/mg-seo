<?php
/**
 * Redirections storage (public PHP API, also used by the importer).
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Redirections;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- custom table; results are cached in the compiled rules option.

/**
 * CRUD for the `{prefix}mgseo_redirections` table and the compiled rule cache.
 *
 * Row shape returned by the read methods:
 *   array( id: int, sources: list<array{pattern: string, comparison: string, ignore_case: bool}>, url_to: string,
 *          type: int, status: string, category: string, hits: int, last_accessed: ?string, created: string, updated: string )
 * Dates are stored in UTC (MySQL format).
 */
final class Repository {

	public const TYPES       = array( 301, 302, 307, 410, 451 );
	public const COMPARISONS = array( 'exact', 'contains', 'start', 'end', 'regex' );
	public const STATUSES    = array( 'active', 'inactive' );
	public const CACHE       = 'mgseo_redirections_compiled';

	/**
	 * In-request copy of the compiled rules.
	 *
	 * @var array<string, mixed>|null
	 */
	private static ?array $compiled = null;

	/**
	 * Table name.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mgseo_redirections';
	}

	/**
	 * Types that need no target URL.
	 *
	 * @param int $type Status code.
	 */
	public static function is_gone_type( int $type ): bool {
		return 410 === $type || 451 === $type;
	}

	/**
	 * Create a redirection.
	 *
	 * @param array $args sources (string|string[]|array[]), url_to, type, status, category, hits, last_accessed, created.
	 * @return int|\WP_Error New id.
	 */
	public static function create( array $args ): int|\WP_Error {
		global $wpdb;
		$data = self::prepare( $args );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$now                   = current_time( 'mysql', true );
		$data['created']       = isset( $args['created'] ) && self::valid_date( (string) $args['created'] ) ? (string) $args['created'] : $now;
		$data['updated']       = $now;
		$data['hits']          = isset( $args['hits'] ) ? max( 0, (int) $args['hits'] ) : 0;
		$data['last_accessed'] = isset( $args['last_accessed'] ) && self::valid_date( (string) $args['last_accessed'] ) ? (string) $args['last_accessed'] : null;
		if ( false === $wpdb->insert( self::table(), $data ) ) {
			return new \WP_Error( 'db_error', __( 'Could not save the redirection.', 'mg-seo' ) );
		}
		self::flush_cache();
		$id = (int) $wpdb->insert_id;
		/**
		 * Fires after a redirection is created.
		 *
		 * @param int   $id   Redirection id.
		 * @param array $data Stored data.
		 */
		do_action( 'mgseo/redirection_created', $id, $data );
		return $id;
	}

	/**
	 * Update a redirection (partial update: only given keys change).
	 *
	 * @param int   $id   Id.
	 * @param array $args Same keys as create().
	 * @return true|\WP_Error
	 */
	public static function update( int $id, array $args ): bool|\WP_Error {
		global $wpdb;
		$current = self::get( $id );
		if ( ! $current ) {
			return new \WP_Error( 'not_found', __( 'Redirection not found.', 'mg-seo' ) );
		}
		$data = self::prepare( array_merge( $current, $args ) );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$data['updated'] = current_time( 'mysql', true );
		if ( isset( $args['hits'] ) ) {
			$data['hits'] = max( 0, (int) $args['hits'] );
		}
		$wpdb->update( self::table(), $data, array( 'id' => $id ) );
		self::flush_cache();
		return true;
	}

	/**
	 * Validate and normalise input into a DB row (without dates).
	 *
	 * @param array $args Input.
	 * @return array|\WP_Error
	 */
	private static function prepare( array $args ): array|\WP_Error {
		$sources = self::sanitize_sources( $args['sources'] ?? array() );
		if ( is_wp_error( $sources ) ) {
			return $sources;
		}
		$type = (int) ( $args['type'] ?? 301 );
		if ( ! in_array( $type, self::TYPES, true ) ) {
			return new \WP_Error( 'invalid_type', __( 'Invalid redirection type.', 'mg-seo' ) );
		}
		$url_to = self::sanitize_target( (string) ( $args['url_to'] ?? '' ) );
		if ( self::is_gone_type( $type ) ) {
			$url_to = '';
		} elseif ( '' === $url_to ) {
			return new \WP_Error( 'missing_target', __( 'Enter a destination URL.', 'mg-seo' ) );
		}
		if ( '' !== $url_to ) {
			$target_path = self::local_path( $url_to );
			foreach ( $sources as $source ) {
				if ( null !== $target_path && 'exact' === $source['comparison'] && self::same_path( $source['pattern'], $target_path, $source['ignore_case'] ) ) {
					return new \WP_Error( 'loop', __( 'The destination is the same as a source URL, which would create a redirect loop.', 'mg-seo' ) );
				}
			}
		}
		$status = (string) ( $args['status'] ?? 'active' );
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			$status = 'active';
		}
		return array(
			'sources'  => (string) wp_json_encode( $sources, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'url_to'   => $url_to,
			'type'     => $type,
			'status'   => $status,
			'category' => mb_substr( sanitize_text_field( (string) ( $args['category'] ?? '' ) ), 0, 100 ),
		);
	}

	/**
	 * Compare a normalised exact pattern with a path.
	 *
	 * @param string $pattern     Pattern.
	 * @param string $path        Path.
	 * @param bool   $ignore_case Case-insensitive.
	 */
	private static function same_path( string $pattern, string $path, bool $ignore_case ): bool {
		return $ignore_case ? mb_strtolower( $pattern ) === mb_strtolower( $path ) : $pattern === $path;
	}

	/**
	 * Normalised local path of a URL (null when it points to another host).
	 *
	 * @param string $url URL or path.
	 */
	public static function local_path( string $url ): ?string {
		$host = wp_parse_url( $url, PHP_URL_HOST );
		if ( $host && strtolower( (string) $host ) !== strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) ) {
			return null;
		}
		return self::normalize_pattern( $url, 'exact' );
	}

	/**
	 * Sanitize a destination URL. Relative paths stay relative to the site.
	 *
	 * @param string $url Raw URL.
	 */
	public static function sanitize_target( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( ! preg_match( '#^[a-z][a-z0-9+.-]*://#i', $url ) && ! str_starts_with( $url, '/' ) ) {
			$url = '/' . $url;
		}
		return (string) esc_url_raw( $url, array( 'http', 'https' ) );
	}

	/**
	 * Validate a sources list.
	 *
	 * @param mixed $sources string, list of strings or list of arrays with pattern/comparison/ignore_case.
	 * @return array|\WP_Error
	 */
	public static function sanitize_sources( mixed $sources ): array|\WP_Error {
		if ( is_string( $sources ) ) {
			$sources = array( $sources );
		}
		if ( ! is_array( $sources ) ) {
			$sources = array();
		}
		$clean = array();
		foreach ( $sources as $source ) {
			if ( is_string( $source ) ) {
				$source = array( 'pattern' => $source );
			}
			if ( ! is_array( $source ) ) {
				continue;
			}
			$comparison = (string) ( $source['comparison'] ?? 'exact' );
			$comparison = in_array( $comparison, self::COMPARISONS, true ) ? $comparison : 'exact';
			$raw        = trim( wp_check_invalid_utf8( (string) ( $source['pattern'] ?? '' ) ) );
			$raw        = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $raw );
			if ( '' === $raw ) {
				continue;
			}
			$ignore_case = ! empty( $source['ignore_case'] );
			if ( 'regex' === $comparison ) {
				if ( ! self::valid_regex( $raw ) ) {
					/* translators: %s: regular expression */
					return new \WP_Error( 'invalid_regex', sprintf( __( 'Invalid regular expression: %s', 'mg-seo' ), $raw ) );
				}
				$pattern = $raw;
			} else {
				$pattern = self::normalize_pattern( $raw, $comparison );
				if ( '' === $pattern && 'exact' !== $comparison ) {
					continue;
				}
			}
			$key = $comparison . '|' . $pattern;
			if ( isset( $clean[ $key ] ) ) {
				continue;
			}
			$clean[ $key ] = array(
				'pattern'     => $pattern,
				'comparison'  => $comparison,
				'ignore_case' => $ignore_case,
			);
		}
		if ( ! $clean ) {
			return new \WP_Error( 'missing_source', __( 'Enter at least one source URL.', 'mg-seo' ) );
		}
		return array_values( $clean );
	}

	/**
	 * Whether a user-supplied regex compiles.
	 *
	 * @param string $pattern Pattern without delimiters.
	 */
	public static function valid_regex( string $pattern ): bool {
		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- testing user input.
		return false !== @preg_match( self::regex( $pattern, false ), '' );
	}

	/**
	 * Wrap a user regex in delimiters.
	 *
	 * @param string $pattern     Pattern.
	 * @param bool   $ignore_case Case-insensitive.
	 */
	public static function regex( string $pattern, bool $ignore_case ): string {
		return '~' . str_replace( '~', '\~', $pattern ) . '~u' . ( $ignore_case ? 'i' : '' );
	}

	/**
	 * Normalise a non-regex source pattern: strip the site URL and home path, decode, and trim slashes
	 * (exact: both sides, start: leading, end: trailing). A query string is kept (sorted) after "?".
	 *
	 * @param string $pattern    Raw pattern (path or full URL).
	 * @param string $comparison Comparison.
	 */
	public static function normalize_pattern( string $pattern, string $comparison ): string {
		$pattern = trim( $pattern );
		if ( preg_match( '#^(?:[a-z][a-z0-9+.-]*:)?//[^/?\#]*#i', $pattern, $m ) ) {
			$pattern = substr( $pattern, strlen( $m[0] ) );
		}
		$pattern = (string) strtok( $pattern, '#' );
		$query   = null;
		if ( str_contains( $pattern, '?' ) ) {
			list( $pattern, $query ) = explode( '?', $pattern, 2 );
		}
		$path      = rawurldecode( $pattern );
		$home_path = rtrim( (string) wp_parse_url( home_url( '/' ), PHP_URL_PATH ), '/' );
		if ( '' !== $home_path && ( $path === $home_path || str_starts_with( $path, $home_path . '/' ) ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}
		$path = match ( $comparison ) {
			'exact' => trim( $path, '/' ),
			'start' => ltrim( $path, '/' ),
			'end'   => rtrim( $path, '/' ),
			default => $path,
		};
		if ( null !== $query && '' !== $query ) {
			$path .= '?' . self::normalize_query( $query );
		}
		return $path;
	}

	/**
	 * Canonical form of a query string (decoded, keys sorted) so parameter order does not matter.
	 *
	 * @param string $query Query string.
	 */
	public static function normalize_query( string $query ): string {
		$pairs = array();
		foreach ( explode( '&', $query ) as $pair ) {
			if ( '' === $pair ) {
				continue;
			}
			$parts   = explode( '=', $pair, 2 );
			$pairs[] = array( rawurldecode( str_replace( '+', ' ', $parts[0] ) ), isset( $parts[1] ) ? rawurldecode( str_replace( '+', ' ', $parts[1] ) ) : null );
		}
		usort( $pairs, static fn( $a, $b ) => array( $a[0], (string) $a[1] ) <=> array( $b[0], (string) $b[1] ) );
		return implode( '&', array_map( static fn( $p ) => null === $p[1] ? $p[0] : $p[0] . '=' . $p[1], $pairs ) );
	}

	/**
	 * Validate a MySQL datetime string.
	 *
	 * @param string $date Date.
	 */
	private static function valid_date( string $date ): bool {
		return (bool) preg_match( '/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $date );
	}

	/**
	 * Decode a DB row.
	 *
	 * @param array $row Raw row.
	 */
	private static function hydrate( array $row ): array {
		$sources = json_decode( (string) $row['sources'], true );
		return array(
			'id'            => (int) $row['id'],
			'sources'       => is_array( $sources ) ? array_values( $sources ) : array(),
			'url_to'        => (string) $row['url_to'],
			'type'          => (int) $row['type'],
			'status'        => (string) $row['status'],
			'category'      => (string) $row['category'],
			'hits'          => (int) $row['hits'],
			'last_accessed' => $row['last_accessed'] ? (string) $row['last_accessed'] : null,
			'created'       => (string) $row['created'],
			'updated'       => (string) $row['updated'],
		);
	}

	/**
	 * Get one redirection.
	 *
	 * @param int $id Id.
	 */
	public static function get( int $id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $id ), ARRAY_A );
		return $row ? self::hydrate( $row ) : null;
	}

	/**
	 * Build WHERE clause for list queries.
	 *
	 * @param array $args status, type, category, search.
	 * @return string Prepared SQL fragment (starts with " WHERE 1=1").
	 */
	private static function where( array $args ): string {
		global $wpdb;
		$sql = ' WHERE 1=1';
		if ( ! empty( $args['status'] ) && in_array( $args['status'], self::STATUSES, true ) ) {
			$sql .= $wpdb->prepare( ' AND status = %s', $args['status'] );
		}
		if ( ! empty( $args['type'] ) ) {
			$sql .= $wpdb->prepare( ' AND type = %d', (int) $args['type'] );
		}
		if ( isset( $args['category'] ) && '' !== $args['category'] ) {
			$sql .= $wpdb->prepare( ' AND category = %s', (string) $args['category'] );
		}
		if ( ! empty( $args['search'] ) ) {
			$like = '%' . $wpdb->esc_like( (string) $args['search'] ) . '%';
			$sql .= $wpdb->prepare( ' AND ( sources LIKE %s OR url_to LIKE %s OR category LIKE %s )', $like, $like, $like );
		}
		return $sql;
	}

	/**
	 * List redirections.
	 *
	 * @param array $args status, type, category, search, orderby, order, per_page (0 = all), page.
	 * @return array<int, array>
	 */
	public static function all( array $args = array() ): array {
		global $wpdb;
		$orderby = in_array( $args['orderby'] ?? '', array( 'id', 'hits', 'last_accessed', 'created', 'updated', 'type', 'url_to', 'category', 'status' ), true ) ? $args['orderby'] : 'id';
		$order   = 'DESC' === strtoupper( (string) ( $args['order'] ?? 'ASC' ) ) ? 'DESC' : 'ASC';
		$sql     = $wpdb->prepare( 'SELECT * FROM %i', self::table() ) . self::where( $args ) . " ORDER BY {$orderby} {$order}, id {$order}";
		$per     = (int) ( $args['per_page'] ?? 0 );
		if ( $per > 0 ) {
			$page = max( 1, (int) ( $args['page'] ?? 1 ) );
			$sql .= $wpdb->prepare( ' LIMIT %d OFFSET %d', $per, ( $page - 1 ) * $per );
		}
		$rows = $wpdb->get_results( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from prepared fragments and whitelisted identifiers.
		return array_map( array( self::class, 'hydrate' ), is_array( $rows ) ? $rows : array() );
	}

	/**
	 * Count redirections.
	 *
	 * @param array $args Same filters as all().
	 */
	public static function count( array $args = array() ): int {
		global $wpdb;
		$sql = $wpdb->prepare( 'SELECT COUNT(*) FROM %i', self::table() ) . self::where( $args );
		return (int) $wpdb->get_var( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- built from prepared fragments.
	}

	/**
	 * Distinct categories in use.
	 *
	 * @return string[]
	 */
	public static function categories(): array {
		global $wpdb;
		$cats = $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT category FROM %i WHERE category <> '' ORDER BY category", self::table() ) );
		return array_map( 'strval', is_array( $cats ) ? $cats : array() );
	}

	/**
	 * Find redirections that have a given source.
	 *
	 * @param string $pattern    Source pattern (path or URL; normalised like on save).
	 * @param string $comparison Comparison ('' = any).
	 * @param bool   $active_only Only active rows.
	 * @return array|null First matching row.
	 */
	public static function find_by_source( string $pattern, string $comparison = 'exact', bool $active_only = false ): ?array {
		$rows = self::find_all_by_source( $pattern, $comparison, $active_only );
		return $rows[0] ?? null;
	}

	/**
	 * All redirections that have a given source.
	 *
	 * @param string $pattern     Pattern.
	 * @param string $comparison  Comparison ('' = any).
	 * @param bool   $active_only Only active rows.
	 * @return array<int, array>
	 */
	public static function find_all_by_source( string $pattern, string $comparison = 'exact', bool $active_only = false ): array {
		global $wpdb;
		$normalized = 'regex' === $comparison ? trim( $pattern ) : self::normalize_pattern( $pattern, '' === $comparison ? 'exact' : $comparison );
		$sql        = $wpdb->prepare( 'SELECT * FROM %i WHERE 1=1', self::table() );
		$encoded    = substr( (string) wp_json_encode( $normalized, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ), 1, -1 );
		if ( '' !== $encoded ) {
			$sql .= $wpdb->prepare( ' AND sources LIKE %s', '%' . $wpdb->esc_like( $encoded ) . '%' );
		}
		if ( $active_only ) {
			$sql .= " AND status = 'active'";
		}
		$rows = $wpdb->get_results( $sql . ' ORDER BY id ASC', ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- prepared fragments.
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row = self::hydrate( $row );
			foreach ( $row['sources'] as $source ) {
				if ( '' !== $comparison && $source['comparison'] !== $comparison ) {
					continue;
				}
				$match = 'exact' === $source['comparison'] && ! empty( $source['ignore_case'] )
					? mb_strtolower( $source['pattern'] ) === mb_strtolower( $normalized )
					: $source['pattern'] === $normalized;
				if ( $match ) {
					$out[] = $row;
					break;
				}
			}
		}
		return $out;
	}

	/**
	 * Redirections whose destination is a given URL/path.
	 *
	 * @param string $url URL or path.
	 * @return array<int, array>
	 */
	public static function find_by_target( string $url ): array {
		$path = self::local_path( $url );
		if ( null === $path ) {
			return array();
		}
		global $wpdb;
		$like = '%' . $wpdb->esc_like( '' === $path ? '/' : $path ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE url_to LIKE %s', self::table(), $like ), ARRAY_A );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row = self::hydrate( $row );
			if ( self::local_path( $row['url_to'] ) === $path ) {
				$out[] = $row;
			}
		}
		return $out;
	}

	/**
	 * Delete redirections.
	 *
	 * @param int[]|int $ids Ids.
	 * @return int Rows deleted.
	 */
	public static function delete( array|int $ids ): int {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', (array) $ids ) );
		if ( ! $ids ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders built above.
		$deleted = (int) $wpdb->query( $wpdb->prepare( "DELETE FROM %i WHERE id IN ({$placeholders})", self::table(), ...$ids ) );
		self::flush_cache();
		return $deleted;
	}

	/**
	 * Set status of redirections.
	 *
	 * @param int[]|int $ids    Ids.
	 * @param string    $status active|inactive.
	 * @return int Rows changed.
	 */
	public static function set_status( array|int $ids, string $status ): int {
		global $wpdb;
		$ids = array_filter( array_map( 'intval', (array) $ids ) );
		if ( ! $ids || ! in_array( $status, self::STATUSES, true ) ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders built above.
		$changed = (int) $wpdb->query( $wpdb->prepare( "UPDATE %i SET status = %s, updated = %s WHERE id IN ({$placeholders})", self::table(), $status, current_time( 'mysql', true ), ...$ids ) );
		self::flush_cache();
		return $changed;
	}

	/**
	 * Add hits to redirections in one query (called once per request at shutdown).
	 *
	 * @param array<int, int> $hits id => hit count.
	 */
	public static function record_hits( array $hits ): void {
		global $wpdb;
		$hits = array_filter( array_map( 'intval', $hits ) );
		if ( ! $hits ) {
			return;
		}
		$case = '';
		$args = array( self::table() );
		foreach ( $hits as $id => $count ) {
			$case  .= ' WHEN %d THEN %d';
			$args[] = (int) $id;
			$args[] = $count;
		}
		$args[]       = current_time( 'mysql', true );
		$placeholders = implode( ',', array_fill( 0, count( $hits ), '%d' ) );
		$args         = array_merge( $args, array_map( 'intval', array_keys( $hits ) ) );
		// phpcs:ignore WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- placeholders built above.
		$wpdb->query( $wpdb->prepare( "UPDATE %i SET hits = hits + CASE id{$case} ELSE 0 END, last_accessed = %s WHERE id IN ({$placeholders})", ...$args ) );
	}

	/**
	 * Delete every redirection.
	 */
	public static function truncate(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i', self::table() ) );
		self::flush_cache();
	}

	/**
	 * Compiled active rules for the matcher (cached in an option, rebuilt after any change).
	 *
	 * Shape: array( 'exact' => array( key => rule ), 'exact_ci' => array( lowercased key => rule ), 'other' => list<rule + source> )
	 * where rule = array( id, type, url_to ).
	 *
	 * @return array<string, array>
	 */
	public static function compiled(): array {
		if ( null !== self::$compiled ) {
			return self::$compiled;
		}
		$cached = get_option( self::CACHE );
		if ( is_array( $cached ) && isset( $cached['v'], $cached['exact'], $cached['exact_ci'], $cached['other'] ) && 1 === $cached['v'] ) {
			self::$compiled = $cached;
			return self::$compiled;
		}
		$compiled = array(
			'v'        => 1,
			'exact'    => array(),
			'exact_ci' => array(),
			'other'    => array(),
		);
		if ( self::table_exists() ) {
			foreach ( self::all( array( 'status' => 'active' ) ) as $row ) {
				$rule = array(
					'id'     => $row['id'],
					'type'   => $row['type'],
					'url_to' => $row['url_to'],
				);
				foreach ( $row['sources'] as $source ) {
					$pattern = (string) ( $source['pattern'] ?? '' );
					$ci      = ! empty( $source['ignore_case'] );
					$cmp     = (string) ( $source['comparison'] ?? 'exact' );
					if ( 'exact' === $cmp ) {
						$bucket = $ci ? 'exact_ci' : 'exact';
						$key    = $ci ? mb_strtolower( $pattern ) : $pattern;
						if ( ! isset( $compiled[ $bucket ][ $key ] ) ) {
							$compiled[ $bucket ][ $key ] = $rule;
						}
						continue;
					}
					$compiled['other'][] = $rule + array(
						'pattern'     => $pattern,
						'comparison'  => $cmp,
						'ignore_case' => $ci,
					);
				}
			}
		}
		update_option( self::CACHE, $compiled );
		self::$compiled = $compiled;
		return $compiled;
	}

	/**
	 * Invalidate the compiled rules.
	 */
	public static function flush_cache(): void {
		self::$compiled = null;
		delete_option( self::CACHE );
	}

	/**
	 * Whether the table exists (cached per request).
	 */
	public static function table_exists(): bool {
		static $exists = null;
		if ( null === $exists || ! $exists ) {
			global $wpdb;
			$exists = self::table() === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( self::table() ) ) );
		}
		return $exists;
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
				sources longtext NOT NULL,
				url_to text NOT NULL,
				type smallint(3) unsigned NOT NULL DEFAULT 301,
				status varchar(20) NOT NULL DEFAULT 'active',
				category varchar(100) NOT NULL DEFAULT '',
				hits bigint(20) unsigned NOT NULL DEFAULT 0,
				last_accessed datetime NULL DEFAULT NULL,
				created datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00',
				PRIMARY KEY  (id),
				KEY status (status),
				KEY category (category)
			) {$charset};"
		);
		self::flush_cache();
	}

	/**
	 * Drop the table.
	 */
	public static function drop(): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DROP TABLE IF EXISTS %i', self::table() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery.SchemaChange -- uninstall.
		delete_option( self::CACHE );
	}
}
