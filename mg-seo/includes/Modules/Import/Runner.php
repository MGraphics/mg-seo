<?php
/**
 * Import orchestration shared by REST and WP-CLI.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Creates sources and runs batches with a persisted log.
 */
final class Runner {

	public const DEFAULT_BATCH = 100;

	/**
	 * Source classes by id.
	 *
	 * @return array<string, class-string<Source>>
	 */
	public static function sources(): array {
		/**
		 * Filter import sources.
		 *
		 * @param array $sources id => class name extending MGSEO\Modules\Import\Source.
		 */
		return apply_filters(
			'mgseo/import/sources',
			array(
				'rankmath' => RankMath::class,
				'yoast'    => Yoast::class,
			)
		);
	}

	/**
	 * Instantiate a source.
	 *
	 * @param string   $id        Source id.
	 * @param bool     $overwrite Overwrite.
	 * @param bool     $dry_run   Dry run.
	 * @param Log|null $log       Log.
	 */
	public static function source( string $id, bool $overwrite = false, bool $dry_run = false, ?Log $log = null ): ?Source {
		$class = self::sources()[ $id ] ?? null;
		if ( ! $class || ! class_exists( $class ) ) {
			return null;
		}
		$source = new $class( $overwrite, $dry_run, $log );
		return $source instanceof Source ? $source : null;
	}

	/**
	 * Detection summary for every source.
	 *
	 * @return array<string, array{label: string, active: bool, installed: bool, counts: array<string, int>, scopes: string[]}>
	 */
	public static function detect(): array {
		$out = array();
		foreach ( array_keys( self::sources() ) as $id ) {
			$source = self::source( $id );
			if ( ! $source ) {
				continue;
			}
			$out[ $id ] = array(
				'label'     => $source->label(),
				'active'    => $source->plugin_active(),
				'installed' => $source->plugin_installed(),
				'counts'    => $source->detect(),
				'scopes'    => $source->scopes(),
			);
		}
		return $out;
	}

	/**
	 * Run one batch.
	 *
	 * @param string $source_id Source.
	 * @param string $scope     Scope.
	 * @param int    $after     Last processed id.
	 * @param array  $args      overwrite, dry_run, limit, start (reset the log).
	 * @return array|\WP_Error
	 */
	public static function batch( string $source_id, string $scope, int $after, array $args = array() ): array|\WP_Error {
		$dry_run = ! empty( $args['dry_run'] );
		$log     = ! empty( $args['start'] ) ? Log::start( $source_id, $dry_run ) : Log::load();
		$source  = self::source( $source_id, ! empty( $args['overwrite'] ), $dry_run, $log );
		if ( ! $source ) {
			return new \WP_Error( 'mgseo_import_source', __( 'Unknown import source.', 'mg-seo' ), array( 'status' => 400 ) );
		}
		if ( ! in_array( $scope, $source->scopes(), true ) ) {
			return new \WP_Error( 'mgseo_import_scope', __( 'Unknown import scope.', 'mg-seo' ), array( 'status' => 400 ) );
		}
		$limit = max( 1, min( 1000, (int) ( $args['limit'] ?? self::DEFAULT_BATCH ) ) );
		if ( function_exists( 'wp_raise_memory_limit' ) ) {
			wp_raise_memory_limit( 'admin' );
		}
		$result = $source->batch( $scope, $after, $limit );
		$log->save();
		return $result + array(
			'scope' => $scope,
			'total' => 0 === $after ? $source->total( $scope ) : null,
		);
	}

	/**
	 * Run a whole import synchronously (WP-CLI and tests).
	 *
	 * @param string        $source_id Source.
	 * @param string[]      $scopes    Scopes (empty = all).
	 * @param array         $args      overwrite, dry_run, limit.
	 * @param callable|null $progress  Called with ( scope, processed ) after each batch.
	 * @return array|\WP_Error Log data.
	 */
	public static function run( string $source_id, array $scopes = array(), array $args = array(), ?callable $progress = null ): array|\WP_Error {
		$source = self::source( $source_id );
		if ( ! $source ) {
			return new \WP_Error( 'mgseo_import_source', __( 'Unknown import source.', 'mg-seo' ) );
		}
		$scopes = $scopes ? array_values( array_intersect( $source->scopes(), $scopes ) ) : $source->scopes();
		$start  = true;
		foreach ( $scopes as $scope ) {
			$after = 0;
			do {
				$result = self::batch( $source_id, $scope, $after, $args + array( 'start' => $start ) );
				$start  = false;
				if ( is_wp_error( $result ) ) {
					return $result;
				}
				if ( $progress ) {
					$progress( $scope, (int) $result['processed'] );
				}
				$stalled = $result['last'] === $after && ! $result['done'];
				$after   = (int) $result['last'];
			} while ( ! $result['done'] && ! $stalled );
		}
		return self::finish();
	}

	/**
	 * Mark the stored log finished and return it.
	 *
	 * @return array<string, mixed>
	 */
	public static function finish(): array {
		$log = Log::load();
		$log->finish();
		$log->save();
		if ( ! $log->dry_run() ) {
			update_option( 'mgseo_flush_rewrite', 1 );
		}
		return $log->to_array();
	}

	/**
	 * Remove a source's leftover data.
	 *
	 * @param string $source_id Source.
	 * @return array|\WP_Error Deleted counts.
	 */
	public static function cleanup( string $source_id ): array|\WP_Error {
		$source = self::source( $source_id );
		if ( ! $source ) {
			return new \WP_Error( 'mgseo_import_source', __( 'Unknown import source.', 'mg-seo' ), array( 'status' => 400 ) );
		}
		if ( $source->plugin_active() ) {
			/* translators: %s: plugin name */
			return new \WP_Error( 'mgseo_import_active', sprintf( __( 'Deactivate %s before deleting its data.', 'mg-seo' ), $source->label() ), array( 'status' => 409 ) );
		}
		return $source->cleanup();
	}
}
