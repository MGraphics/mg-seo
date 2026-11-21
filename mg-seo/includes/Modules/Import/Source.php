<?php
/**
 * Base class for import sources.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Import;

use MGSEO\Meta;
use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery -- importers read other plugins' leftover rows directly.

/**
 * An import source reads another SEO plugin's leftover data straight from the database
 * (never through the plugin's own code, which is usually deleted already).
 *
 * Scopes are processed in batches keyed by object id (`after` = last processed id).
 */
abstract class Source {

	public const SCOPES = array( 'settings', 'posts', 'terms', 'users', 'redirections', 'schema', 'log404' );

	/**
	 * Overwrite existing MG SEO values.
	 *
	 * @var bool
	 */
	protected bool $overwrite = false;

	/**
	 * Dry run (nothing is written).
	 *
	 * @var bool
	 */
	protected bool $dry_run = false;

	/**
	 * Log of the current run.
	 *
	 * @var Log
	 */
	protected Log $log;

	/**
	 * Settings staged during a batch (group => key => value), written once.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	private array $staged = array();

	/**
	 * Constructor.
	 *
	 * @param bool     $overwrite Overwrite existing values.
	 * @param bool     $dry_run   Dry run.
	 * @param Log|null $log       Log.
	 */
	public function __construct( bool $overwrite = false, bool $dry_run = false, ?Log $log = null ) {
		$this->overwrite = $overwrite;
		$this->dry_run   = $dry_run;
		$this->log       = $log ?? new Log();
	}

	/**
	 * Source id (rankmath, yoast).
	 */
	abstract public function id(): string;

	/**
	 * Human label.
	 */
	abstract public function label(): string;

	/**
	 * Plugin basename of the source plugin.
	 */
	abstract public function plugin_file(): string;

	/**
	 * Scopes this source supports.
	 *
	 * @return string[]
	 */
	abstract public function scopes(): array;

	/**
	 * Leftover data found per scope (counts), independent of the plugin being installed.
	 *
	 * @return array<string, int>
	 */
	abstract public function detect(): array;

	/**
	 * Process one batch of a scope.
	 *
	 * @param string $scope Scope.
	 * @param int    $after Last processed object id (0 to start).
	 * @param int    $limit Batch size.
	 * @return array{last: int, processed: int, done: bool}
	 */
	abstract public function batch( string $scope, int $after, int $limit ): array;

	/**
	 * Delete the source plugin's leftover data.
	 *
	 * @return array<string, int> Deleted rows per kind.
	 */
	abstract public function cleanup(): array;

	/**
	 * Number of objects a scope iterates (for progress bars).
	 *
	 * @param string $scope Scope.
	 */
	public function total( string $scope ): int {
		return (int) ( $this->detect()[ $scope ] ?? 0 );
	}

	/**
	 * Whether the source plugin is currently active.
	 */
	public function plugin_active(): bool {
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		return is_plugin_active( $this->plugin_file() );
	}

	/**
	 * Whether the plugin files exist (installed but maybe inactive).
	 */
	public function plugin_installed(): bool {
		return file_exists( WP_PLUGIN_DIR . '/' . $this->plugin_file() );
	}

	/**
	 * Whether any leftover data exists.
	 */
	public function has_data(): bool {
		return array_sum( $this->detect() ) > 0;
	}

	/**
	 * The run log.
	 */
	public function log(): Log {
		return $this->log;
	}

	/**
	 * Ids of objects that have meta matching a LIKE pattern, after an id.
	 *
	 * @param string $type    post|term|user.
	 * @param string $like    LIKE pattern (already escaped).
	 * @param int    $after   Last id.
	 * @param int    $limit   Limit.
	 * @return int[]
	 */
	protected function meta_object_ids( string $type, string $like, int $after, int $limit ): array {
		global $wpdb;
		[ $table, $column ] = $this->meta_table( $type );
		$ids                = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT %i FROM %i WHERE meta_key LIKE %s AND %i > %d ORDER BY %i ASC LIMIT %d', $column, $table, $like, $column, $after, $column, $limit ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Number of objects with matching meta.
	 *
	 * @param string $type post|term|user.
	 * @param string $like LIKE pattern.
	 */
	protected function meta_object_count( string $type, string $like ): int {
		global $wpdb;
		[ $table, $column ] = $this->meta_table( $type );
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT %i) FROM %i WHERE meta_key LIKE %s', $column, $table, $like ) );
	}

	/**
	 * All meta of an object matching a LIKE pattern (key => raw value, first value wins).
	 *
	 * @param string $type post|term|user.
	 * @param int    $id   Object id.
	 * @param string $like LIKE pattern.
	 * @return array<string, string>
	 */
	protected function raw_meta( string $type, int $id, string $like ): array {
		global $wpdb;
		[ $table, $column ] = $this->meta_table( $type );
		$order              = 'user' === $type ? 'umeta_id' : 'meta_id';
		$rows               = $wpdb->get_results( $wpdb->prepare( 'SELECT meta_key, meta_value FROM %i WHERE %i = %d AND meta_key LIKE %s ORDER BY %i ASC', $table, $column, $id, $like, $order ), ARRAY_A );
		$out                = array();
		foreach ( (array) $rows as $row ) {
			if ( ! array_key_exists( $row['meta_key'], $out ) ) {
				$out[ $row['meta_key'] ] = (string) $row['meta_value'];
			}
		}
		return $out;
	}

	/**
	 * Delete meta rows matching a LIKE pattern.
	 *
	 * @param string $type post|term|user.
	 * @param string $like Pattern.
	 */
	protected function delete_meta_like( string $type, string $like ): int {
		global $wpdb;
		[ $table ] = $this->meta_table( $type );
		$deleted   = (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key LIKE %s', $table, $like ) );
		wp_cache_flush_group( $type . '_meta' );
		return $deleted;
	}

	/**
	 * Meta table and id column.
	 *
	 * @param string $type post|term|user.
	 * @return array{0: string, 1: string}
	 */
	private function meta_table( string $type ): array {
		global $wpdb;
		return match ( $type ) {
			'term'  => array( $wpdb->termmeta, 'term_id' ),
			'user'  => array( $wpdb->usermeta, 'user_id' ),
			default => array( $wpdb->postmeta, 'post_id' ),
		};
	}

	/**
	 * Whether a table exists.
	 *
	 * @param string $table Full table name.
	 */
	protected function table_exists( string $table ): bool {
		global $wpdb;
		if ( $table === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) ) ) {
			return true;
		}
		// Temporary tables (e.g. in test suites) are not listed by SHOW TABLES.
		$suppress = $wpdb->suppress_errors( true );
		$wpdb->query( $wpdb->prepare( 'SELECT 1 FROM %i LIMIT 0', $table ) );
		$ok = '' === $wpdb->last_error;
		$wpdb->suppress_errors( $suppress );
		return $ok;
	}

	/**
	 * Write MG SEO meta for an object, honouring overwrite and dry-run.
	 *
	 * @param string $type  post|term|user.
	 * @param int    $id    Object id.
	 * @param string $key   MG SEO key without prefix.
	 * @param mixed  $value Value (empty values are skipped).
	 * @return bool Whether the value was (or would be) written.
	 */
	protected function put_meta( string $type, int $id, string $key, mixed $value ): bool {
		if ( null === $value || '' === $value || array() === $value || false === $value ) {
			return false;
		}
		$exists = metadata_exists( $type, $id, Meta::PREFIX . $key );
		if ( $exists && ! $this->overwrite ) {
			$current = get_metadata( $type, $id, Meta::PREFIX . $key, true );
			if ( '' !== $current && array() !== $current ) {
				return false;
			}
		}
		if ( $this->dry_run ) {
			$this->log->change( sprintf( '%s #%d: %s = %s', $type, $id, $key, is_scalar( $value ) ? (string) $value : (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) );
			return true;
		}
		match ( $type ) {
			'term'  => Meta::set_term( $id, $key, $value ),
			'user'  => Meta::set_user( $id, $key, $value ),
			default => Meta::set_post( $id, $key, $value ),
		};
		return true;
	}

	/**
	 * Stage a setting (written by flush_settings()).
	 *
	 * @param string $group Options group.
	 * @param string $key   Key.
	 * @param mixed  $value Value (null skips).
	 */
	protected function put_setting( string $group, string $key, mixed $value ): void {
		if ( null === $value ) {
			return;
		}
		$stored = Options::all();
		if ( ! $this->overwrite && isset( $stored[ $group ] ) && is_array( $stored[ $group ] ) && array_key_exists( $key, $stored[ $group ] ) ) {
			return;
		}
		$this->staged[ $group ][ $key ] = $value;
	}

	/**
	 * Write staged settings.
	 *
	 * @return int Number of settings written.
	 */
	protected function flush_settings(): int {
		$count = 0;
		$all   = Options::all();
		foreach ( $this->staged as $group => $values ) {
			foreach ( $values as $key => $value ) {
				++$count;
				if ( $this->dry_run ) {
					$this->log->change( sprintf( 'setting %s.%s = %s', $group, $key, is_scalar( $value ) ? var_export( $value, true ) : (string) wp_json_encode( $value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES ) ) ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_var_export -- readable preview.
					continue;
				}
				$all[ $group ]         = isset( $all[ $group ] ) && is_array( $all[ $group ] ) ? $all[ $group ] : array();
				$all[ $group ][ $key ] = $value;
			}
		}
		if ( ! $this->dry_run && $count ) {
			Options::save( $all );
			update_option( 'mgseo_flush_rewrite', 1 );
		}
		$this->staged = array();
		return $count;
	}

	/**
	 * Enable a MG SEO module (unless dry run).
	 *
	 * @param string $id Module id.
	 */
	protected function enable_module( string $id ): void {
		$plugin = \MGSEO\Plugin::instance();
		if ( $plugin->is_module_active( $id ) ) {
			return;
		}
		if ( $this->dry_run ) {
			$this->log->change( sprintf( 'module %s = on', $id ) );
			return;
		}
		$plugin->set_module_state( $id, true );
	}
}
