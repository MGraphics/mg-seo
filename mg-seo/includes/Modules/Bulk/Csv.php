<?php
/**
 * CSV export/import of per-post SEO data.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Bulk;

use MGSEO\Context;
use MGSEO\Helpers;
use MGSEO\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Columns: id, post_type, slug, url, seo_title, seo_description, focus_keyword, robots, canonical, og_title, og_description, og_image.
 *
 * Import rules: rows are matched by id (falling back to post_type + slug, then url); an empty cell leaves the value unchanged,
 * the cell value `-` clears it; robots is a comma separated list of directives or `default`.
 */
final class Csv {

	public const COLUMNS = array( 'id', 'post_type', 'slug', 'url', 'seo_title', 'seo_description', 'focus_keyword', 'robots', 'canonical', 'og_title', 'og_description', 'og_image' );

	/**
	 * CSV column => meta key.
	 */
	private const FIELDS = array(
		'seo_title'       => 'title',
		'seo_description' => 'description',
		'focus_keyword'   => 'focus_keyword',
		'robots'          => 'robots',
		'canonical'       => 'canonical',
		'og_title'        => 'og_title',
		'og_description'  => 'og_description',
		'og_image'        => 'og_image',
	);

	/**
	 * Fields that need the advanced capability.
	 */
	private const ADVANCED = array( 'robots', 'canonical' );

	private const MAX_ROWS = 20000;

	/**
	 * Rows for export.
	 *
	 * @param string[] $post_types Post types.
	 * @return array<int, array<string, string>>
	 */
	public static function rows( array $post_types ): array {
		$rows  = array();
		$paged = 1;
		do {
			$query = new \WP_Query(
				array(
					'post_type'              => $post_types,
					'post_status'            => array( 'publish', 'draft', 'pending', 'future', 'private' ),
					'posts_per_page'         => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- paged export.
					'paged'                  => $paged,
					'orderby'                => 'ID',
					'order'                  => 'ASC',
					'update_post_term_cache' => false,
				)
			);
			foreach ( $query->posts as $post ) {
				if ( ! Helpers::can_edit_post_seo( $post->ID ) ) {
					continue;
				}
				$robots = (array) Meta::post( $post->ID, 'robots', array() );
				$rows[] = array(
					'id'              => (string) $post->ID,
					'post_type'       => $post->post_type,
					'slug'            => $post->post_name,
					'url'             => (string) get_permalink( $post ),
					'seo_title'       => (string) Meta::post( $post->ID, 'title' ),
					'seo_description' => (string) Meta::post( $post->ID, 'description' ),
					'focus_keyword'   => (string) Meta::post( $post->ID, 'focus_keyword' ),
					'robots'          => implode( ',', $robots ),
					'canonical'       => (string) Meta::post( $post->ID, 'canonical' ),
					'og_title'        => (string) Meta::post( $post->ID, 'og_title' ),
					'og_description'  => (string) Meta::post( $post->ID, 'og_description' ),
					'og_image'        => (string) Meta::post( $post->ID, 'og_image' ),
				);
			}
			++$paged;
		} while ( $paged <= (int) $query->max_num_pages );
		return $rows;
	}

	/**
	 * Build CSV text.
	 *
	 * @param array $rows Rows.
	 */
	public static function to_csv( array $rows ): string {
		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in-memory stream.
		fputcsv( $handle, self::COLUMNS, ',', '"', '' );
		foreach ( $rows as $row ) {
			$line = array();
			foreach ( self::COLUMNS as $column ) {
				$value = (string) ( $row[ $column ] ?? '' );
				// Neutralise spreadsheet formulas.
				if ( '' !== $value && in_array( $value[0], array( '=', '+', '@' ), true ) ) {
					$value = "'" . $value;
				}
				$line[] = $value;
			}
			fputcsv( $handle, $line, ',', '"', '' );
		}
		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- in-memory stream.
		return "\xEF\xBB\xBF" . $csv;
	}

	/**
	 * Parse CSV text into rows keyed by column name.
	 *
	 * @param string $csv CSV.
	 * @return array<int, array<string, string>>|\WP_Error
	 */
	public static function parse( string $csv ): array|\WP_Error {
		$csv    = preg_replace( '/^\xEF\xBB\xBF/', '', $csv );
		$handle = fopen( 'php://temp', 'r+' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- in-memory stream.
		fwrite( $handle, (string) $csv ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fwrite -- in-memory stream.
		rewind( $handle );
		$first = fgets( $handle );
		$delim = ( substr_count( (string) $first, ';' ) > substr_count( (string) $first, ',' ) ) ? ';' : ',';
		rewind( $handle );
		$header = fgetcsv( $handle, 0, $delim, '"', '' );
		if ( ! is_array( $header ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new \WP_Error( 'mgseo_csv_empty', __( 'The CSV file is empty.', 'mg-seo' ) );
		}
		$header = array_map( static fn( $h ) => sanitize_key( str_replace( ' ', '_', trim( (string) $h ) ) ), $header );
		if ( ! in_array( 'id', $header, true ) && ! in_array( 'url', $header, true ) && ! in_array( 'slug', $header, true ) ) {
			fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			return new \WP_Error( 'mgseo_csv_header', __( 'The CSV needs an id, slug or url column.', 'mg-seo' ) );
		}
		$rows = array();
		while ( ( $line = fgetcsv( $handle, 0, $delim, '"', '' ) ) !== false ) { // phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
			if ( array( null ) === $line ) {
				continue;
			}
			$row = array();
			foreach ( $header as $i => $column ) {
				$value = (string) ( $line[ $i ] ?? '' );
				if ( str_starts_with( $value, "'" ) && isset( $value[1] ) && in_array( $value[1], array( '=', '+', '@' ), true ) ) {
					$value = substr( $value, 1 );
				}
				$row[ $column ] = $value;
			}
			$rows[] = $row;
			if ( count( $rows ) >= self::MAX_ROWS ) {
				break;
			}
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		return $rows;
	}

	/**
	 * Find the post a row refers to.
	 *
	 * @param array $row Row.
	 */
	private static function find_post( array $row ): ?\WP_Post {
		$id = (int) ( $row['id'] ?? 0 );
		if ( $id ) {
			$post = get_post( $id );
			return $post instanceof \WP_Post ? $post : null;
		}
		$slug = sanitize_title( (string) ( $row['slug'] ?? '' ) );
		$type = sanitize_key( (string) ( $row['post_type'] ?? '' ) );
		if ( '' !== $slug ) {
			$found = get_posts(
				array(
					'name'           => $slug,
					'post_type'      => '' !== $type ? $type : array_keys( Context::post_types() ),
					'post_status'    => 'any',
					'posts_per_page' => 1,
				)
			);
			if ( $found ) {
				return $found[0];
			}
		}
		$url = (string) ( $row['url'] ?? '' );
		if ( '' !== $url ) {
			$id = url_to_postid( $url );
			return $id ? get_post( $id ) : null;
		}
		return null;
	}

	/**
	 * Normalise a CSV value for a field (null = unchanged, '' / [] = clear).
	 *
	 * @param string $field Meta key.
	 * @param string $value Raw cell.
	 * @return mixed
	 */
	private static function value( string $field, string $value ): mixed {
		$value = trim( $value );
		if ( '' === $value ) {
			return null;
		}
		if ( '-' === $value ) {
			return 'robots' === $field ? array() : '';
		}
		return match ( $field ) {
			'robots'                      => 'default' === strtolower( $value ) ? array() : Helpers::sanitize_robots( $value ),
			'canonical', 'og_image'       => esc_url_raw( $value ),
			'description', 'og_description' => sanitize_textarea_field( $value ),
			'focus_keyword'               => implode( ',', array_filter( array_map( 'trim', explode( ',', sanitize_text_field( $value ) ) ) ) ),
			default                       => sanitize_text_field( $value ),
		};
	}

	/**
	 * Plan changes for parsed rows (nothing is written).
	 *
	 * @param array $rows Rows.
	 * @return array{changes: array<int, array{id: int, title: string, field: string, old: string, new: string, value: mixed}>, errors: string[], rows: int}
	 */
	public static function plan( array $rows ): array {
		$changes  = array();
		$errors   = array();
		$advanced = current_user_can( 'mgseo_edit_advanced' );
		foreach ( $rows as $index => $row ) {
			$line = $index + 2;
			$post = self::find_post( $row );
			if ( ! $post ) {
				/* translators: %d: CSV line number */
				$errors[] = sprintf( __( 'Line %d: post not found.', 'mg-seo' ), $line );
				continue;
			}
			if ( ! isset( Context::post_types()[ $post->post_type ] ) || ! Helpers::can_edit_post_seo( $post->ID ) ) {
				/* translators: 1: CSV line number, 2: post id */
				$errors[] = sprintf( __( 'Line %1$d: you cannot edit post #%2$d.', 'mg-seo' ), $line, $post->ID );
				continue;
			}
			foreach ( self::FIELDS as $column => $field ) {
				if ( ! array_key_exists( $column, $row ) ) {
					continue;
				}
				$value = self::value( $field, (string) $row[ $column ] );
				if ( null === $value ) {
					continue;
				}
				if ( in_array( $field, self::ADVANCED, true ) && ! $advanced ) {
					continue;
				}
				$current = Meta::post( $post->ID, $field, 'robots' === $field ? array() : '' );
				$old     = is_array( $current ) ? implode( ',', $current ) : (string) $current;
				$new     = is_array( $value ) ? implode( ',', $value ) : (string) $value;
				if ( $old === $new ) {
					continue;
				}
				$changes[] = array(
					'id'    => $post->ID,
					'title' => get_the_title( $post ),
					'field' => $column,
					'old'   => $old,
					'new'   => $new,
					'value' => $value,
				);
			}
		}
		return array(
			'changes' => $changes,
			'errors'  => $errors,
			'rows'    => count( $rows ),
		);
	}

	/**
	 * Apply planned changes (permissions are checked again).
	 *
	 * @param array $changes Changes from plan().
	 * @return int Applied changes.
	 */
	public static function apply( array $changes ): int {
		$applied = 0;
		foreach ( $changes as $change ) {
			$id    = (int) $change['id'];
			$field = self::FIELDS[ $change['field'] ] ?? '';
			if ( '' === $field || ! Helpers::can_edit_post_seo( $id ) ) {
				continue;
			}
			if ( in_array( $field, self::ADVANCED, true ) && ! current_user_can( 'mgseo_edit_advanced' ) ) {
				continue;
			}
			Meta::set_post( $id, $field, $change['value'] );
			if ( 'og_image' === $field ) {
				Meta::set_post( $id, 'og_image_id', '' !== $change['value'] ? attachment_url_to_postid( (string) $change['value'] ) : 0 );
			}
			++$applied;
		}
		return $applied;
	}

	/**
	 * Guard for the admin-post handlers.
	 *
	 * @param string $action Nonce action.
	 */
	private static function guard( string $action ): void {
		if ( ! current_user_can( Module::CAP ) || ! current_user_can( 'mgseo_edit_meta' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'mg-seo' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( $action );
	}

	/**
	 * Download the CSV.
	 */
	public static function handle_export(): void {
		self::guard( 'mgseo_bulk_csv_export' );
		$types = array_keys( Context::post_types() );
		$type  = isset( $_POST['post_type'] ) ? sanitize_key( wp_unslash( $_POST['post_type'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		$types = '' !== $type && in_array( $type, $types, true ) ? array( $type ) : $types;
		$csv   = self::to_csv( self::rows( $types ) );
		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=seo-data-' . gmdate( 'Y-m-d' ) . '.csv' );
		echo $csv; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- CSV download.
		exit;
	}

	/**
	 * Transient key for the current user's pending import.
	 */
	private static function pending_key(): string {
		return 'mgseo_bulk_csv_' . get_current_user_id();
	}

	/**
	 * Upload: preview (dry run) or apply.
	 */
	public static function handle_import(): void {
		self::guard( 'mgseo_bulk_csv_import' );
		$file = isset( $_FILES['csv']['tmp_name'] ) ? sanitize_text_field( (string) $_FILES['csv']['tmp_name'] ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		if ( '' === $file || ! is_uploaded_file( $file ) ) {
			self::back( 'error', __( 'Choose a CSV file.', 'mg-seo' ) );
		}
		$rows = self::parse( (string) file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded file.
		if ( is_wp_error( $rows ) ) {
			self::back( 'error', $rows->get_error_message() );
		}
		$plan = self::plan( $rows );
		if ( empty( $_POST['dry_run'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
			$applied = self::apply( $plan['changes'] );
			/* translators: %d: number of changes */
			self::back( 'success', sprintf( _n( '%d change applied.', '%d changes applied.', $applied, 'mg-seo' ), $applied ) );
		}
		set_transient( self::pending_key(), $plan, HOUR_IN_SECONDS );
		self::back( 'preview', '' );
	}

	/**
	 * Apply the previewed import.
	 */
	public static function handle_apply(): void {
		self::guard( 'mgseo_bulk_csv_apply' );
		$plan = get_transient( self::pending_key() );
		delete_transient( self::pending_key() );
		if ( ! is_array( $plan ) || empty( $plan['changes'] ) ) {
			self::back( 'error', __( 'Nothing to apply. Upload the file again.', 'mg-seo' ) );
		}
		$applied = self::apply( $plan['changes'] );
		/* translators: %d: number of changes */
		self::back( 'success', sprintf( _n( '%d change applied.', '%d changes applied.', $applied, 'mg-seo' ), $applied ) );
	}

	/**
	 * The pending preview of the current user.
	 *
	 * @return array|null
	 */
	public static function pending(): ?array {
		$plan = get_transient( self::pending_key() );
		return is_array( $plan ) ? $plan : null;
	}

	/**
	 * Redirect back to the CSV tab.
	 *
	 * @param string $status  Status.
	 * @param string $message Message.
	 * @return never
	 */
	private static function back( string $status, string $message ): never {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => Module::PAGE,
					'tab'         => 'csv',
					'csv-status'  => $status,
					'csv-message' => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}
}
