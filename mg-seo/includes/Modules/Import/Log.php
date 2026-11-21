<?php
/**
 * Import result log.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Collects counters, unknown template variables, warnings and (in dry runs) a sample of planned changes.
 * A run spans several batch requests, so the log is persisted in the `mgseo_import_log` option between batches.
 */
final class Log {

	public const OPTION     = 'mgseo_import_log';
	private const MAX_ITEMS = 200;

	/**
	 * Data.
	 *
	 * @var array{source: string, dry_run: bool, started: string, finished: string, counts: array<string, array<string, int>>, unknown: array<string, int>, warnings: string[], changes: string[]}
	 */
	private array $data;

	/**
	 * Constructor.
	 *
	 * @param array $data Previous data.
	 */
	public function __construct( array $data = array() ) {
		$this->data = array_merge(
			array(
				'source'   => '',
				'dry_run'  => false,
				'started'  => '',
				'finished' => '',
				'counts'   => array(),
				'unknown'  => array(),
				'warnings' => array(),
				'changes'  => array(),
			),
			$data
		);
	}

	/**
	 * Start a fresh log.
	 *
	 * @param string $source  Source id.
	 * @param bool   $dry_run Dry run.
	 */
	public static function start( string $source, bool $dry_run ): self {
		$log = new self(
			array(
				'source'  => $source,
				'dry_run' => $dry_run,
				'started' => current_time( 'mysql', true ),
			)
		);
		$log->save();
		return $log;
	}

	/**
	 * Load the stored log.
	 */
	public static function load(): self {
		$stored = get_option( self::OPTION, array() );
		return new self( is_array( $stored ) ? $stored : array() );
	}

	/**
	 * Persist.
	 */
	public function save(): void {
		update_option( self::OPTION, $this->data, false );
	}

	/**
	 * Increment a counter.
	 *
	 * @param string $scope Scope.
	 * @param string $key   Counter (imported, skipped, errors …).
	 * @param int    $by    Amount.
	 */
	public function count( string $scope, string $key, int $by = 1 ): void {
		$this->data['counts'][ $scope ][ $key ] = ( $this->data['counts'][ $scope ][ $key ] ?? 0 ) + $by;
	}

	/**
	 * Record an unknown template variable.
	 *
	 * @param string $variable Variable as written in the template.
	 */
	public function unknown_variable( string $variable ): void {
		$this->data['unknown'][ $variable ] = ( $this->data['unknown'][ $variable ] ?? 0 ) + 1;
	}

	/**
	 * Add a warning.
	 *
	 * @param string $message Message.
	 */
	public function warning( string $message ): void {
		if ( count( $this->data['warnings'] ) < self::MAX_ITEMS && ! in_array( $message, $this->data['warnings'], true ) ) {
			$this->data['warnings'][] = $message;
		}
	}

	/**
	 * Record a planned/applied change (kept for dry-run previews).
	 *
	 * @param string $message Message.
	 */
	public function change( string $message ): void {
		if ( count( $this->data['changes'] ) < self::MAX_ITEMS ) {
			$this->data['changes'][] = $message;
		}
	}

	/**
	 * Mark finished.
	 */
	public function finish(): void {
		$this->data['finished'] = current_time( 'mysql', true );
	}

	/**
	 * Whether this is a dry run.
	 */
	public function dry_run(): bool {
		return (bool) $this->data['dry_run'];
	}

	/**
	 * Raw data.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return $this->data;
	}
}
