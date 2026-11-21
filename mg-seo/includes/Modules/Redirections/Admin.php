<?php
/**
 * Redirections and 404 Monitor admin screens.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Redirections;

use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Admin pages, form handlers (admin-post) and list actions. Every action checks the
 * `mgseo_redirections` capability and a nonce.
 */
final class Admin {

	public const PAGE     = 'mg-seo-redirects';
	public const LOG_PAGE = 'mg-seo-404-monitor';
	public const CAP      = 'mgseo_redirections';

	/**
	 * Register hooks.
	 */
	public static function boot(): void {
		add_action( 'admin_init', array( self::class, 'handle_list_actions' ) );
		add_action( 'admin_post_mgseo_save_redirection', array( self::class, 'handle_save' ) );
		add_action( 'admin_post_mgseo_redirections_export', array( self::class, 'handle_export' ) );
		add_action( 'admin_post_mgseo_redirections_import', array( self::class, 'handle_import' ) );
		add_action( 'admin_post_mgseo_404_export', array( self::class, 'handle_log_export' ) );
		add_action( 'admin_post_mgseo_404_clear', array( self::class, 'handle_log_clear' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	/**
	 * Comparison labels.
	 *
	 * @return array<string, string>
	 */
	public static function comparison_labels(): array {
		return array(
			'exact'    => __( 'Exact', 'mg-seo' ),
			'contains' => __( 'Contains', 'mg-seo' ),
			'start'    => __( 'Starts with', 'mg-seo' ),
			'end'      => __( 'Ends with', 'mg-seo' ),
			'regex'    => __( 'Regex', 'mg-seo' ),
		);
	}

	/**
	 * Enqueue assets on our screens.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( string $hook ): void {
		if ( ! str_ends_with( $hook, self::PAGE ) && ! str_ends_with( $hook, self::LOG_PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'mgseo-redirections', MGSEO_URL . 'assets/redirections.css', array(), MGSEO_VERSION );
		wp_enqueue_script( 'mgseo-redirections', MGSEO_URL . 'assets/redirections.js', array(), MGSEO_VERSION, true );
	}

	/**
	 * Nonce-protected URL for a row action.
	 *
	 * @param string $page   Page slug.
	 * @param string $action Action.
	 * @param int    $id     Row id.
	 */
	public static function row_action_url( string $page, string $action, int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'page'      => $page,
					'rd_action' => $action,
					'id'        => $id,
				),
				admin_url( 'admin.php' )
			),
			'mgseo_rd_' . $page . '_' . $action . '_' . $id
		);
	}

	/**
	 * Redirect back to a page with a message.
	 *
	 * @param string $page    Page.
	 * @param string $message Message.
	 * @param array  $args    Extra query args.
	 */
	private static function back( string $page, string $message = '', array $args = array() ): void {
		$url = add_query_arg( array( 'page' => $page ) + $args, admin_url( 'admin.php' ) );
		if ( '' !== $message ) {
			$url = add_query_arg( 'mgseo-message', rawurlencode( $message ), $url );
		}
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Deny access.
	 */
	private static function deny(): void {
		wp_die( esc_html__( 'You do not have permission to do this.', 'mg-seo' ), '', array( 'response' => 403 ) );
	}

	/**
	 * Bulk and row actions of both list tables (run on admin_init before output).
	 */
	public static function handle_list_actions(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- nonces are verified below before acting.
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
		if ( self::PAGE !== $page && self::LOG_PAGE !== $page ) {
			return;
		}
		$row_action = isset( $_GET['rd_action'] ) ? sanitize_key( wp_unslash( $_GET['rd_action'] ) ) : '';
		$bulk       = '';
		foreach ( array( 'action', 'action2' ) as $key ) {
			$value = isset( $_REQUEST[ $key ] ) ? sanitize_key( wp_unslash( $_REQUEST[ $key ] ) ) : '';
			if ( '' !== $value && '-1' !== $value && in_array( $value, array( 'activate', 'deactivate', 'delete', 'redirect' ), true ) ) {
				$bulk = $value;
				break;
			}
		}
		if ( '' === $row_action && '' === $bulk ) {
			return;
		}
		if ( ! current_user_can( self::CAP ) ) {
			self::deny();
		}
		if ( '' !== $row_action ) {
			$id = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
			check_admin_referer( 'mgseo_rd_' . $page . '_' . $row_action . '_' . $id );
			$ids = array( $id );
			$act = $row_action;
		} else {
			check_admin_referer( self::PAGE === $page ? 'bulk-redirections' : 'bulk-404s' );
			$ids = isset( $_REQUEST['ids'] ) ? array_map( 'absint', (array) wp_unslash( $_REQUEST['ids'] ) ) : array();
			$act = $bulk;
		}
		// phpcs:enable
		$ids = array_values( array_filter( $ids ) );
		if ( ! $ids ) {
			self::back( $page );
		}

		if ( self::PAGE === $page ) {
			switch ( $act ) {
				case 'activate':
				case 'deactivate':
					$count = Repository::set_status( $ids, 'activate' === $act ? 'active' : 'inactive' );
					/* translators: %d: number of redirections */
					self::back( $page, sprintf( _n( '%d redirection updated.', '%d redirections updated.', $count, 'mg-seo' ), $count ) );
					break;
				case 'delete':
					$count = Repository::delete( $ids );
					/* translators: %d: number of redirections */
					self::back( $page, sprintf( _n( '%d redirection deleted.', '%d redirections deleted.', $count, 'mg-seo' ), $count ) );
					break;
			}
			self::back( $page );
		}

		if ( 'redirect' === $act ) {
			self::back(
				self::PAGE,
				'',
				array(
					'action'  => 'new',
					'log_ids' => implode( ',', $ids ),
				)
			);
		}
		if ( 'delete' === $act ) {
			$count = Log404::delete( $ids );
			/* translators: %d: number of log entries */
			self::back( $page, sprintf( _n( '%d entry deleted.', '%d entries deleted.', $count, 'mg-seo' ), $count ) );
		}
		self::back( $page );
	}

	/**
	 * Save a redirection from the add/edit form.
	 */
	public static function handle_save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			self::deny();
		}
		check_admin_referer( 'mgseo_save_redirection' );
		$id      = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0;
		$sources = array();
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- validated by Repository::sanitize_sources().
		$raw = isset( $_POST['sources'] ) && is_array( $_POST['sources'] ) ? wp_unslash( $_POST['sources'] ) : array();
		foreach ( $raw as $source ) {
			if ( ! is_array( $source ) ) {
				continue;
			}
			$sources[] = array(
				'pattern'     => (string) ( $source['pattern'] ?? '' ),
				'comparison'  => sanitize_key( (string) ( $source['comparison'] ?? 'exact' ) ),
				'ignore_case' => ! empty( $source['ignore_case'] ),
			);
		}
		$args    = array(
			'sources'  => $sources,
			'url_to'   => isset( $_POST['url_to'] ) ? sanitize_text_field( wp_unslash( $_POST['url_to'] ) ) : '',
			'type'     => isset( $_POST['type'] ) ? absint( $_POST['type'] ) : 301,
			'status'   => isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : 'active',
			'category' => isset( $_POST['category'] ) ? sanitize_text_field( wp_unslash( $_POST['category'] ) ) : '',
		);
		$result  = $id ? Repository::update( $id, $args ) : Repository::create( $args );
		$log_ids = isset( $_POST['log_ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_POST['log_ids'] ) ) ) ) ) : array();
		if ( is_wp_error( $result ) ) {
			set_transient(
				'mgseo_rd_form_' . get_current_user_id(),
				array(
					'error'  => $result->get_error_message(),
					'values' => $args + array( 'id' => $id ),
				),
				5 * MINUTE_IN_SECONDS
			);
			self::back(
				self::PAGE,
				'',
				array_filter(
					array(
						'action'  => $id ? 'edit' : 'new',
						'id'      => $id,
						'restore' => 1,
						'log_ids' => implode( ',', $log_ids ),
					)
				)
			);
		}
		if ( $log_ids && ! empty( $_POST['delete_log'] ) ) {
			Log404::delete( $log_ids );
		}
		self::back( self::PAGE, $id ? __( 'Redirection updated.', 'mg-seo' ) : __( 'Redirection created.', 'mg-seo' ) );
	}

	/**
	 * CSV export of redirections.
	 */
	public static function handle_export(): void {
		if ( ! current_user_can( self::CAP ) ) {
			self::deny();
		}
		check_admin_referer( 'mgseo_redirections_export' );
		Csv::download( 'redirections-' . gmdate( 'Y-m-d' ) . '.csv', array_merge( array( Csv::HEADERS ), Csv::redirection_rows() ) );
	}

	/**
	 * CSV import of redirections.
	 */
	public static function handle_import(): void {
		if ( ! current_user_can( self::CAP ) ) {
			self::deny();
		}
		check_admin_referer( 'mgseo_redirections_import' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- uploaded file array; path validated with is_uploaded_file().
		$file = isset( $_FILES['csv'] ) && is_array( $_FILES['csv'] ) ? $_FILES['csv'] : null;
		if ( ! $file || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::back( self::PAGE, __( 'Choose a CSV file to import.', 'mg-seo' ) );
		}
		$ext = strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'txt' ), true ) || (int) $file['size'] > 10 * MB_IN_BYTES ) {
			self::back( self::PAGE, __( 'The file must be a CSV file smaller than 10 MB.', 'mg-seo' ) );
		}
		$result = Csv::import_file( (string) $file['tmp_name'] );
		/* translators: 1: imported count, 2: skipped count */
		$message = sprintf( __( 'Import finished: %1$d created, %2$d skipped.', 'mg-seo' ), $result['created'], $result['skipped'] );
		if ( $result['errors'] ) {
			set_transient( 'mgseo_rd_import_' . get_current_user_id(), array_slice( $result['errors'], 0, 20 ), 5 * MINUTE_IN_SECONDS );
		}
		self::back( self::PAGE, $message );
	}

	/**
	 * CSV export of the 404 log.
	 */
	public static function handle_log_export(): void {
		if ( ! current_user_can( self::CAP ) ) {
			self::deny();
		}
		check_admin_referer( 'mgseo_404_export' );
		Csv::download( '404-log-' . gmdate( 'Y-m-d' ) . '.csv', Csv::log_rows() );
	}

	/**
	 * Clear the 404 log.
	 */
	public static function handle_log_clear(): void {
		if ( ! current_user_can( self::CAP ) ) {
			self::deny();
		}
		check_admin_referer( 'mgseo_404_clear' );
		Log404::clear();
		self::back( self::LOG_PAGE, __( 'The 404 log was cleared.', 'mg-seo' ) );
	}

	/**
	 * Redirections page.
	 */
	public static function render_redirections(): void {
		if ( ! current_user_can( self::CAP ) ) {
			self::deny();
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- view selection only.
		$action = isset( $_GET['action'] ) ? sanitize_key( wp_unslash( $_GET['action'] ) ) : '';
		$base   = admin_url( 'admin.php?page=' . self::PAGE );

		echo '<div class="wrap mgseo-wrap mgseo-rd" data-confirm-delete="' . esc_attr__( 'Delete the selected items?', 'mg-seo' ) . '">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( 'Redirections', 'mg-seo' ) . '</h1> ';
		echo '<a href="' . esc_url( add_query_arg( 'action', 'new', $base ) ) . '" class="page-title-action">' . esc_html__( 'Add New', 'mg-seo' ) . '</a> ';
		echo '<a href="#mgseo-rd-io" class="page-title-action">' . esc_html__( 'Import & Export', 'mg-seo' ) . '</a>';
		if ( current_user_can( 'mgseo_manage_settings' ) ) {
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=mg-seo-redirections' ) ) . '" class="page-title-action">' . esc_html__( 'Settings', 'mg-seo' ) . '</a>';
		}
		echo '<hr class="wp-header-end" />';

		$import_errors = get_transient( 'mgseo_rd_import_' . get_current_user_id() );
		if ( is_array( $import_errors ) && $import_errors ) {
			delete_transient( 'mgseo_rd_import_' . get_current_user_id() );
			echo '<div class="notice notice-warning"><ul>';
			foreach ( $import_errors as $error ) {
				echo '<li>' . esc_html( (string) $error ) . '</li>';
			}
			echo '</ul></div>';
		}

		if ( in_array( $action, array( 'new', 'edit' ), true ) ) {
			self::render_form( 'edit' === $action );
		}

		$table = new ListTable();
		$table->prepare_items();
		$table->views();
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::PAGE ) . '" />';
		$filters = ListTable::filters();
		if ( '' !== $filters['status'] ) {
			echo '<input type="hidden" name="status" value="' . esc_attr( $filters['status'] ) . '" />';
		}
		$table->search_box( __( 'Search redirections', 'mg-seo' ), 'mgseo-rd' );
		$table->display();
		echo '</form>';

		self::render_import_export();
		echo '</div>';
	}

	/**
	 * Add/edit form.
	 *
	 * @param bool $editing Editing an existing row.
	 */
	private static function render_form( bool $editing ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- prefill only.
		$id      = isset( $_GET['id'] ) ? absint( $_GET['id'] ) : 0;
		$restore = ! empty( $_GET['restore'] );
		$log_ids = isset( $_GET['log_ids'] ) ? array_filter( array_map( 'absint', explode( ',', sanitize_text_field( wp_unslash( $_GET['log_ids'] ) ) ) ) ) : array();
		$prefill = isset( $_GET['source'] ) ? sanitize_text_field( wp_unslash( $_GET['source'] ) ) : '';
		// phpcs:enable
		$values = array(
			'sources'  => array(),
			'url_to'   => '',
			'type'     => (int) Options::get( 'redirections', 'default_type', '301' ),
			'status'   => 'active',
			'category' => '',
		);
		if ( $editing && $id ) {
			$row = Repository::get( $id );
			if ( ! $row ) {
				echo '<div class="notice notice-error"><p>' . esc_html__( 'Redirection not found.', 'mg-seo' ) . '</p></div>';
				return;
			}
			$values = array_merge( $values, $row );
		}
		if ( '' !== $prefill ) {
			$values['sources'][] = array(
				'pattern'    => $prefill,
				'comparison' => 'exact',
			);
		}
		if ( $log_ids ) {
			foreach ( Log404::all( array( 'ids' => $log_ids ) ) as $log ) {
				$values['sources'][] = array(
					'pattern'    => (string) $log['uri'],
					'comparison' => 'exact',
				);
			}
		}
		$error = '';
		if ( $restore ) {
			$saved = get_transient( 'mgseo_rd_form_' . get_current_user_id() );
			if ( is_array( $saved ) ) {
				delete_transient( 'mgseo_rd_form_' . get_current_user_id() );
				$error  = (string) ( $saved['error'] ?? '' );
				$values = array_merge( $values, (array) ( $saved['values'] ?? array() ) );
			}
		}
		$sources   = array_values( (array) $values['sources'] );
		$sources[] = array(
			'pattern'    => '',
			'comparison' => 'exact',
		);

		echo '<div class="mgseo-rd-form card">';
		echo '<h2>' . ( $editing ? esc_html__( 'Edit redirection', 'mg-seo' ) : esc_html__( 'Add redirection', 'mg-seo' ) ) . '</h2>';
		if ( '' !== $error ) {
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mgseo_save_redirection' );
		echo '<input type="hidden" name="action" value="mgseo_save_redirection" />';
		echo '<input type="hidden" name="id" value="' . esc_attr( (string) ( $editing ? $id : 0 ) ) . '" />';
		if ( $log_ids ) {
			echo '<input type="hidden" name="log_ids" value="' . esc_attr( implode( ',', $log_ids ) ) . '" />';
		}
		echo '<table class="form-table" role="presentation"><tbody>';

		echo '<tr><th scope="row">' . esc_html__( 'Source URLs', 'mg-seo' ) . '</th><td><div class="mgseo-rd-sources">';
		foreach ( $sources as $index => $source ) {
			self::render_source_row( (int) $index, (array) $source );
		}
		echo '</div><p><button type="button" class="button mgseo-rd-add-source">' . esc_html__( 'Add another source', 'mg-seo' ) . '</button></p>';
		echo '<p class="description">' . esc_html__( 'Paths are relative to the site address, e.g. /old-page/. Regex patterns are matched against the path without leading and trailing slashes; use $1, $2 … in the destination for captured groups.', 'mg-seo' ) . '</p></td></tr>';

		echo '<tr class="mgseo-rd-target"><th scope="row"><label for="mgseo-rd-url-to">' . esc_html__( 'Destination URL', 'mg-seo' ) . '</label></th><td>';
		echo '<input type="text" class="large-text code" id="mgseo-rd-url-to" name="url_to" value="' . esc_attr( (string) $values['url_to'] ) . '" placeholder="/new-page/" /></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Redirection type', 'mg-seo' ) . '</th><td><fieldset class="mgseo-rd-types">';
		foreach ( Module::type_labels() as $code => $label ) {
			printf( '<label><input type="radio" name="type" value="%1$s" %2$s /> %3$s</label><br />', esc_attr( (string) $code ), checked( (int) $code, (int) $values['type'], false ), esc_html( $label ) );
		}
		echo '<p class="description">' . esc_html__( '410 and 451 show the theme 404 template with that status code and need no destination.', 'mg-seo' ) . '</p>';
		echo '</fieldset></td></tr>';

		echo '<tr><th scope="row"><label for="mgseo-rd-category">' . esc_html__( 'Category', 'mg-seo' ) . '</label></th><td>';
		echo '<input type="text" id="mgseo-rd-category" name="category" list="mgseo-rd-categories" value="' . esc_attr( (string) $values['category'] ) . '" class="regular-text" />';
		echo '<datalist id="mgseo-rd-categories">';
		foreach ( Repository::categories() as $category ) {
			echo '<option value="' . esc_attr( $category ) . '"></option>';
		}
		echo '</datalist></td></tr>';

		echo '<tr><th scope="row">' . esc_html__( 'Status', 'mg-seo' ) . '</th><td>';
		printf( '<label><input type="radio" name="status" value="active" %s /> %s</label> &nbsp; ', checked( 'active', $values['status'], false ), esc_html__( 'Active', 'mg-seo' ) );
		printf( '<label><input type="radio" name="status" value="inactive" %s /> %s</label>', checked( 'inactive', $values['status'], false ), esc_html__( 'Inactive', 'mg-seo' ) );
		echo '</td></tr>';
		if ( $log_ids ) {
			echo '<tr><th scope="row">' . esc_html__( '404 log', 'mg-seo' ) . '</th><td><label><input type="checkbox" name="delete_log" value="1" checked="checked" /> ' . esc_html__( 'Delete the selected 404 log entries after saving', 'mg-seo' ) . '</label></td></tr>';
		}
		echo '</tbody></table>';
		submit_button( $editing ? __( 'Update redirection', 'mg-seo' ) : __( 'Add redirection', 'mg-seo' ), 'primary', 'submit', false );
		echo ' <a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '" class="button">' . esc_html__( 'Cancel', 'mg-seo' ) . '</a>';
		echo '</form></div>';
	}

	/**
	 * One source row of the form.
	 *
	 * @param int   $index  Index.
	 * @param array $source Source.
	 */
	private static function render_source_row( int $index, array $source ): void {
		$name = 'sources[' . $index . ']';
		echo '<div class="mgseo-rd-source">';
		printf(
			'<input type="text" class="regular-text code" name="%1$s[pattern]" value="%2$s" aria-label="%3$s" placeholder="/old-page/" /> ',
			esc_attr( $name ),
			esc_attr( (string) ( $source['pattern'] ?? '' ) ),
			esc_attr__( 'Source URL', 'mg-seo' )
		);
		printf( '<select name="%s[comparison]" aria-label="%s">', esc_attr( $name ), esc_attr__( 'Comparison', 'mg-seo' ) );
		foreach ( self::comparison_labels() as $value => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( $value ), selected( $value, (string) ( $source['comparison'] ?? 'exact' ), false ), esc_html( $label ) );
		}
		echo '</select> ';
		printf( '<label><input type="checkbox" name="%s[ignore_case]" value="1" %s /> %s</label> ', esc_attr( $name ), checked( ! empty( $source['ignore_case'] ), true, false ), esc_html__( 'Ignore case', 'mg-seo' ) );
		echo '<button type="button" class="button-link mgseo-rd-remove-source" aria-label="' . esc_attr__( 'Remove source', 'mg-seo' ) . '">&times;</button>';
		echo '</div>';
	}

	/**
	 * Import/export box.
	 */
	private static function render_import_export(): void {
		echo '<div id="mgseo-rd-io" class="mgseo-rd-io card"><h2>' . esc_html__( 'Import & Export', 'mg-seo' ) . '</h2>';
		echo '<p>' . esc_html__( 'CSV columns: id, source, comparison (exact, contains, start, end, regex), ignore_case (0/1), destination, type (301, 302, 307, 410, 451), status (active/inactive), category, hits, created. Rows with the same id become one redirection with several sources; only "source" is required. Sources that already have a redirection are skipped.', 'mg-seo' ) . '</p>';
		$export = wp_nonce_url( admin_url( 'admin-post.php?action=mgseo_redirections_export' ), 'mgseo_redirections_export' );
		echo '<p><a class="button" href="' . esc_url( $export ) . '">' . esc_html__( 'Export CSV', 'mg-seo' ) . '</a></p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mgseo_redirections_import' );
		echo '<input type="hidden" name="action" value="mgseo_redirections_import" />';
		echo '<label class="screen-reader-text" for="mgseo-rd-csv">' . esc_html__( 'CSV file', 'mg-seo' ) . '</label>';
		echo '<input type="file" id="mgseo-rd-csv" name="csv" accept=".csv,text/csv" required /> ';
		submit_button( __( 'Import CSV', 'mg-seo' ), 'secondary', 'submit', false );
		echo '</form></div>';
	}

	/**
	 * 404 Monitor page.
	 */
	public static function render_log(): void {
		if ( ! current_user_can( self::CAP ) ) {
			self::deny();
		}
		echo '<div class="wrap mgseo-wrap mgseo-rd" data-confirm-delete="' . esc_attr__( 'Delete the selected items?', 'mg-seo' ) . '">';
		echo '<h1 class="wp-heading-inline">' . esc_html__( '404 Monitor', 'mg-seo' ) . '</h1> ';
		$export = wp_nonce_url( admin_url( 'admin-post.php?action=mgseo_404_export' ), 'mgseo_404_export' );
		echo '<a href="' . esc_url( $export ) . '" class="page-title-action">' . esc_html__( 'Export CSV', 'mg-seo' ) . '</a>';
		if ( current_user_can( 'mgseo_manage_settings' ) ) {
			echo ' <a href="' . esc_url( admin_url( 'admin.php?page=mg-seo-redirections&tab=monitor' ) ) . '" class="page-title-action">' . esc_html__( 'Settings', 'mg-seo' ) . '</a>';
		}
		echo '<hr class="wp-header-end" />';
		if ( ! Options::get( 'redirections', 'log_404', true ) ) {
			echo '<div class="notice notice-info inline"><p>' . esc_html__( '404 logging is turned off in the settings. Existing entries are kept.', 'mg-seo' ) . '</p></div>';
		}
		$table = new LogTable();
		$table->prepare_items();
		echo '<form method="get">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::LOG_PAGE ) . '" />';
		$table->search_box( __( 'Search log', 'mg-seo' ), 'mgseo-404' );
		$table->display();
		echo '</form>';

		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mgseo-rd-clear">';
		wp_nonce_field( 'mgseo_404_clear' );
		echo '<input type="hidden" name="action" value="mgseo_404_clear" />';
		echo '<button type="submit" class="button button-link-delete mgseo-confirm" data-confirm="' . esc_attr__( 'Delete every entry of the 404 log?', 'mg-seo' ) . '">' . esc_html__( 'Clear log', 'mg-seo' ) . '</button>';
		echo '</form></div>';
	}
}
