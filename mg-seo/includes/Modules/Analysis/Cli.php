<?php
/**
 * WP-CLI command: wp mgseo scores.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Analysis;

defined( 'ABSPATH' ) || exit;

/**
 * Calculate and inspect SEO scores.
 *
 * ## OPTIONS
 *
 * <action>
 * : recalc (calculate scores) or status (pages per score band).
 *
 * [--post_type=<types>]
 * : Comma separated post types. Default: all managed post types.
 *
 * [--ids=<ids>]
 * : Comma separated post ids (recalc only).
 *
 * [--missing]
 * : Only posts without a current score (recalc only).
 *
 * [--batch=<number>]
 * : Posts per batch.
 * ---
 * default: 100
 * ---
 *
 * [--format=<format>]
 * : Output format of status: table, json, csv.
 * ---
 * default: table
 * ---
 *
 * ## EXAMPLES
 *
 *     wp mgseo scores recalc
 *     wp mgseo scores recalc --post_type=page --missing
 *     wp mgseo scores recalc --ids=12,15
 *     wp mgseo scores status
 */
final class Cli {

	/**
	 * Run the command.
	 *
	 * @param array $args  Positional args.
	 * @param array $assoc Assoc args.
	 */
	public function __invoke( array $args, array $assoc ): void {
		$action = (string) ( $args[0] ?? '' );
		$types  = isset( $assoc['post_type'] ) ? array_filter( array_map( 'trim', explode( ',', (string) $assoc['post_type'] ) ) ) : array();
		if ( 'recalc' === $action ) {
			$this->recalc( $types, $assoc );
			return;
		}
		if ( 'status' === $action ) {
			$this->status( $types, (string) ( $assoc['format'] ?? 'table' ) );
			return;
		}
		\WP_CLI::error( 'Usage: wp mgseo scores <recalc|status>' );
	}

	/**
	 * Recalculate scores.
	 *
	 * @param string[] $types Post types.
	 * @param array    $assoc Assoc args.
	 */
	private function recalc( array $types, array $assoc ): void {
		if ( isset( $assoc['ids'] ) ) {
			$ids   = array_filter( array_map( 'intval', explode( ',', (string) $assoc['ids'] ) ) );
			$count = Scores::recalculate_ids( $ids );
			\WP_CLI::success( sprintf( 'Scored %d of %d posts.', $count, count( $ids ) ) );
			return;
		}
		if ( $types && ! Scores::types( $types ) ) {
			\WP_CLI::error( 'Unknown post type. Managed types: ' . implode( ', ', Scores::types() ) );
		}
		$result = Scores::recalculate_all(
			array(
				'post_type' => $types,
				'missing'   => \WP_CLI\Utils\get_flag_value( $assoc, 'missing', false ),
			),
			static function ( array $batch ): void {
				if ( $batch['processed'] ) {
					\WP_CLI::log( sprintf( 'up to #%d: %d checked, %d scored', $batch['last'], $batch['processed'], $batch['computed'] ) );
				}
			},
			max( 1, (int) ( $assoc['batch'] ?? 100 ) )
		);
		\WP_CLI::success( sprintf( 'Scored %d posts (%d checked).', $result['computed'], $result['processed'] ) );
	}

	/**
	 * Counts per band of indexable published pages.
	 *
	 * @param string[] $types  Post types.
	 * @param string   $format Format.
	 */
	private function status( array $types, string $format ): void {
		$rows = array();
		foreach ( $types ? $types : array( '' ) as $type ) {
			$data   = Overview::data(
				array(
					'post_type' => $type,
					'compute'   => 0,
					'per_page'  => 1,
				)
			);
			$rows[] = array_merge(
				array(
					'post_type' => '' === $type ? 'all' : $type,
					'total'     => $data['total'],
				),
				$data['counts']
			);
		}
		\WP_CLI\Utils\format_items( $format, $rows, array( 'post_type', 'total', 'good', 'ok', 'poor', Scores::BAND_NONE, Scores::BAND_PENDING ) );
		$pass = Scores::pass();
		if ( $pass ) {
			\WP_CLI::log( sprintf( 'Background pass running: at post #%d.', $pass['after'] ) );
		}
	}
}
