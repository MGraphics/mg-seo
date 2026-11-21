<?php
/**
 * CSV import/export of redirections and the 404 log.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Redirections;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- streaming CSV through php://output / uploaded temp files.

/**
 * Format: one row per source. Rows with the same non-empty `id` form one redirection with several sources.
 * Columns: id, source, comparison, ignore_case, destination, type, status, category, hits, created.
 */
final class Csv {

	public const HEADERS = array( 'id', 'source', 'comparison', 'ignore_case', 'destination', 'type', 'status', 'category', 'hits', 'created' );

	/**
	 * Column aliases accepted on import.
	 */
	private const ALIASES = array(
		'id'          => array( 'id', 'group', 'redirection_id' ),
		'source'      => array( 'source', 'sources', 'from', 'url_from', 'source_url', 'old_url', 'pattern' ),
		'comparison'  => array( 'comparison', 'matching', 'match', 'match_type' ),
		'ignore_case' => array( 'ignore_case', 'ignorecase', 'case_insensitive' ),
		'destination' => array( 'destination', 'target', 'url_to', 'to', 'new_url', 'destination_url' ),
		'type'        => array( 'type', 'code', 'status_code', 'header_code', 'redirect_type' ),
		'status'      => array( 'status', 'state', 'active' ),
		'category'    => array( 'category', 'group_name' ),
		'hits'        => array( 'hits', 'count' ),
		'created'     => array( 'created', 'date' ),
	);

	/**
	 * Export rows (without header) for all redirections.
	 *
	 * @return array<int, array<int, string>>
	 */
	public static function redirection_rows(): array {
		$rows = array();
		foreach ( Repository::all() as $row ) {
			foreach ( $row['sources'] as $source ) {
				$rows[] = array(
					(string) $row['id'],
					(string) $source['pattern'],
					(string) $source['comparison'],
					! empty( $source['ignore_case'] ) ? '1' : '0',
					$row['url_to'],
					(string) $row['type'],
					$row['status'],
					$row['category'],
					(string) $row['hits'],
					$row['created'],
				);
			}
		}
		return $rows;
	}

	/**
	 * Export rows (with header) for the 404 log.
	 *
	 * @return array<int, array<int, string>>
	 */
	public static function log_rows(): array {
		$rows = array( array( 'uri', 'hits', 'first_seen', 'last_seen', 'referer', 'user_agent', 'ip' ) );
		foreach ( Log404::all() as $row ) {
			$rows[] = array( $row['uri'], (string) $row['hits'], $row['first_seen'], $row['last_seen'], $row['referer'], $row['user_agent'], $row['ip'] );
		}
		return $rows;
	}

	/**
	 * Build CSV text.
	 *
	 * @param array<int, array<int, string>> $rows Rows.
	 */
	public static function to_string( array $rows ): string {
		$handle = fopen( 'php://temp', 'r+' );
		if ( ! $handle ) {
			return '';
		}
		foreach ( $rows as $row ) {
			fputcsv( $handle, array_map( array( self::class, 'escape_cell' ), $row ), ',', '"', '' );
		}
		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle );
		return $csv;
	}

	/**
	 * Send a CSV download and stop.
	 *
	 * @param string                         $filename File name.
	 * @param array<int, array<int, string>> $rows     Rows.
	 */
	public static function download( string $filename, array $rows ): void {
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . sanitize_file_name( $filename ) . '"' );
		echo "\xEF\xBB\xBF" . self::to_string( $rows ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download.
		exit;
	}

	/**
	 * Neutralise spreadsheet formulas.
	 *
	 * @param string $value Cell.
	 */
	public static function escape_cell( string $value ): string {
		return preg_match( '/^[=+\-@\t\r]/', $value ) ? "'" . $value : $value;
	}

	/**
	 * Undo escape_cell().
	 *
	 * @param string $value Cell.
	 */
	private static function unescape_cell( string $value ): string {
		return preg_match( '/^\'[=+\-@\t\r]/', $value ) ? substr( $value, 1 ) : $value;
	}

	/**
	 * Import redirections from a CSV file.
	 *
	 * @param string $file Path.
	 * @return array{created: int, skipped: int, errors: string[]}
	 */
	public static function import_file( string $file ): array {
		$contents = is_readable( $file ) ? (string) file_get_contents( $file ) : '';
		return self::import( $contents );
	}

	/**
	 * Import redirections from CSV text.
	 *
	 * @param string $csv CSV.
	 * @return array{created: int, skipped: int, errors: string[]}
	 */
	public static function import( string $csv ): array {
		$result = array(
			'created' => 0,
			'skipped' => 0,
			'errors'  => array(),
		);
		$csv    = (string) preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		if ( '' === trim( $csv ) ) {
			$result['errors'][] = __( 'The file is empty.', 'mg-seo' );
			return $result;
		}
		$first     = (string) strtok( $csv, "\n" );
		$delimiter = substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ( substr_count( $first, "\t" ) > substr_count( $first, ',' ) ? "\t" : ',' );
		$handle    = fopen( 'php://temp', 'r+' );
		if ( ! $handle ) {
			return $result;
		}
		fwrite( $handle, $csv );
		rewind( $handle );

		$map    = null;
		$groups = array();
		$line   = 0;
		while ( false !== ( $cells = fgetcsv( $handle, 0, $delimiter, '"', '' ) ) ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			++$line;
			if ( array( null ) === $cells || ! array_filter( $cells, static fn( $c ) => '' !== trim( (string) $c ) ) ) {
				continue;
			}
			$cells = array_map( static fn( $c ) => self::unescape_cell( trim( (string) $c ) ), $cells );
			if ( null === $map ) {
				$map = self::header_map( $cells );
				if ( $map ) {
					continue;
				}
				// No header: source, destination, type.
				$map = array(
					'source'      => 0,
					'destination' => 1,
					'type'        => 2,
				);
			}
			$get    = static fn( string $key ): string => isset( $map[ $key ], $cells[ $map[ $key ] ] ) ? (string) $cells[ $map[ $key ] ] : '';
			$source = $get( 'source' );
			if ( '' === $source ) {
				++$result['skipped'];
				/* translators: %d: line number */
				$result['errors'][] = sprintf( __( 'Line %d: missing source.', 'mg-seo' ), $line );
				continue;
			}
			$key = '' !== $get( 'id' ) ? 'id:' . $get( 'id' ) : 'line:' . $line;
			if ( ! isset( $groups[ $key ] ) ) {
				$type           = (int) preg_replace( '/\D/', '', $get( 'type' ) );
				$status         = strtolower( $get( 'status' ) );
				$hits           = $get( 'hits' );
				$groups[ $key ] = array(
					'line' => $line,
					'args' => array(
						'sources'  => array(),
						'url_to'   => $get( 'destination' ),
						'type'     => $type ? $type : 301,
						'status'   => in_array( $status, array( 'inactive', '0', 'no', 'false', 'disabled' ), true ) ? 'inactive' : 'active',
						'category' => $get( 'category' ),
					) + ( '' !== $hits ? array( 'hits' => (int) $hits ) : array() )
						+ ( '' !== $get( 'created' ) ? array( 'created' => $get( 'created' ) ) : array() ),
				);
			}
			$comparison                          = strtolower( $get( 'comparison' ) );
			$ignore                              = strtolower( $get( 'ignore_case' ) );
			$groups[ $key ]['args']['sources'][] = array(
				'pattern'     => $source,
				'comparison'  => in_array( $comparison, Repository::COMPARISONS, true ) ? $comparison : 'exact',
				'ignore_case' => in_array( $ignore, array( '1', 'yes', 'true', 'y' ), true ),
			);
		}
		fclose( $handle );

		foreach ( $groups as $group ) {
			$duplicate = false;
			foreach ( $group['args']['sources'] as $source ) {
				if ( Repository::find_by_source( $source['pattern'], $source['comparison'] ) ) {
					$duplicate = $source['pattern'];
					break;
				}
			}
			if ( false !== $duplicate ) {
				++$result['skipped'];
				/* translators: 1: line number, 2: source URL */
				$result['errors'][] = sprintf( __( 'Line %1$d: a redirection for "%2$s" already exists.', 'mg-seo' ), $group['line'], $duplicate );
				continue;
			}
			$id = Repository::create( $group['args'] );
			if ( is_wp_error( $id ) ) {
				++$result['skipped'];
				/* translators: 1: line number, 2: error message */
				$result['errors'][] = sprintf( __( 'Line %1$d: %2$s', 'mg-seo' ), $group['line'], $id->get_error_message() );
				continue;
			}
			++$result['created'];
		}
		return $result;
	}

	/**
	 * Detect a header row and map our column keys to indexes.
	 *
	 * @param array<int, string> $cells Cells.
	 * @return array<string, int>|null
	 */
	private static function header_map( array $cells ): ?array {
		$normalized = array_map( static fn( $c ) => str_replace( array( ' ', '-' ), '_', strtolower( $c ) ), $cells );
		$map        = array();
		foreach ( self::ALIASES as $key => $aliases ) {
			foreach ( $normalized as $index => $cell ) {
				if ( in_array( $cell, $aliases, true ) && ! in_array( $index, $map, true ) ) {
					$map[ $key ] = $index;
					break;
				}
			}
		}
		return isset( $map['source'] ) ? $map : null;
	}
}
