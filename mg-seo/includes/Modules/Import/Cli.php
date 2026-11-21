<?php
/**
 * WP-CLI command: wp mgseo import.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Import SEO data left behind by Rank Math or Yoast SEO.
 *
 * ## OPTIONS
 *
 * <source>
 * : rankmath, yoast, detect (show leftover data) or cleanup (delete a source's leftovers).
 *
 * [<target>]
 * : Source for `cleanup`.
 *
 * [--scope=<scopes>]
 * : Comma separated: settings, posts, terms, users, redirections, schema, log404. Default: all.
 *
 * [--dry-run]
 * : Show what would change without writing anything.
 *
 * [--overwrite]
 * : Overwrite values that already exist in MG SEO.
 *
 * [--batch=<number>]
 * : Objects per batch.
 * ---
 * default: 200
 * ---
 *
 * [--yes]
 * : Skip the confirmation of `cleanup`.
 *
 * ## EXAMPLES
 *
 *     wp mgseo import detect
 *     wp mgseo import rankmath --dry-run
 *     wp mgseo import rankmath --scope=posts,terms --overwrite
 *     wp mgseo import cleanup rankmath --yes
 */
final class Cli {

	/**
	 * Run the command.
	 *
	 * @param array $args  Positional args.
	 * @param array $assoc Assoc args.
	 */
	public function __invoke( array $args, array $assoc ): void {
		$what = (string) ( $args[0] ?? '' );
		if ( 'detect' === $what ) {
			$this->detect();
			return;
		}
		if ( 'cleanup' === $what ) {
			$this->cleanup( (string) ( $args[1] ?? '' ), $assoc );
			return;
		}
		if ( ! isset( Runner::sources()[ $what ] ) ) {
			\WP_CLI::error( 'Unknown source. Use: ' . implode( ', ', array_keys( Runner::sources() ) ) . ', detect or cleanup.' );
		}
		$scopes  = isset( $assoc['scope'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $assoc['scope'] ) ) ) : array();
		$dry_run = \WP_CLI\Utils\get_flag_value( $assoc, 'dry-run', false );
		$result  = Runner::run(
			$what,
			$scopes,
			array(
				'dry_run'   => $dry_run,
				'overwrite' => \WP_CLI\Utils\get_flag_value( $assoc, 'overwrite', false ),
				'limit'     => (int) ( $assoc['batch'] ?? 200 ),
			),
			static function ( string $scope, int $processed ): void {
				if ( $processed ) {
					\WP_CLI::log( sprintf( '%s: %d processed', $scope, $processed ) );
				}
			}
		);
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		foreach ( $result['counts'] as $scope => $counts ) {
			$parts = array();
			foreach ( $counts as $key => $value ) {
				$parts[] = $key . '=' . $value;
			}
			\WP_CLI::log( sprintf( '%-13s %s', $scope, implode( ' ', $parts ) ) );
		}
		foreach ( $result['unknown'] as $variable => $count ) {
			\WP_CLI::warning( sprintf( 'Unknown variable kept as is: %s (%d×)', $variable, $count ) );
		}
		foreach ( $result['warnings'] as $warning ) {
			\WP_CLI::warning( $warning );
		}
		if ( $dry_run ) {
			foreach ( $result['changes'] as $change ) {
				\WP_CLI::log( '  ' . $change );
			}
			\WP_CLI::success( 'Dry run finished, nothing was written.' );
			return;
		}
		\WP_CLI::success( 'Import finished.' );
	}

	/**
	 * Print detection table.
	 */
	private function detect(): void {
		$rows = array();
		foreach ( Runner::detect() as $id => $info ) {
			$row = array(
				'source' => $id,
				'plugin' => $info['active'] ? 'active' : ( $info['installed'] ? 'inactive' : 'not installed' ),
			);
			foreach ( Source::SCOPES as $scope ) {
				$row[ $scope ] = $info['counts'][ $scope ] ?? '-';
			}
			$rows[] = $row;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, array_merge( array( 'source', 'plugin' ), Source::SCOPES ) );
	}

	/**
	 * Delete leftovers.
	 *
	 * @param string $source Source.
	 * @param array  $assoc  Assoc args.
	 */
	private function cleanup( string $source, array $assoc ): void {
		if ( ! isset( Runner::sources()[ $source ] ) ) {
			\WP_CLI::error( 'Usage: wp mgseo import cleanup <rankmath|yoast> [--yes]' );
		}
		\WP_CLI::confirm( sprintf( 'Permanently delete all %s data (meta, options, tables)?', $source ), $assoc );
		$result = Runner::cleanup( $source );
		if ( is_wp_error( $result ) ) {
			\WP_CLI::error( $result->get_error_message() );
		}
		foreach ( $result as $kind => $count ) {
			\WP_CLI::log( sprintf( '%s: %d', $kind, $count ) );
		}
		\WP_CLI::success( 'Leftover data deleted.' );
	}
}
