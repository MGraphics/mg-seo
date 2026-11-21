<?php
/**
 * Bulk editor admin page.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Bulk;

use MGSEO\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Tabs: inline editor and CSV export/import.
 */
final class Page {

	/**
	 * Render.
	 */
	public static function render(): void {
		if ( ! current_user_can( Module::CAP ) || ! current_user_can( 'mgseo_edit_meta' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mg-seo' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- navigation only.
		$tab   = isset( $_GET['tab'] ) && 'csv' === sanitize_key( wp_unslash( $_GET['tab'] ) ) ? 'csv' : 'editor';
		$types = Context::post_types();
		$type  = isset( $_GET['pt'] ) ? sanitize_key( wp_unslash( $_GET['pt'] ) ) : 'post';
		$type  = isset( $types[ $type ] ) ? $type : (string) array_key_first( $types );
		// phpcs:enable

		echo '<div class="wrap mgseo-wrap mgseo-bulk">';
		echo '<h1>' . esc_html__( 'Bulk SEO Editor', 'mg-seo' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( array(
			'editor' => __( 'Editor', 'mg-seo' ),
			'csv'    => __( 'CSV export / import', 'mg-seo' ),
		) as $id => $label ) {
			printf(
				'<a class="nav-tab%s" href="%s">%s</a>',
				$id === $tab ? ' nav-tab-active' : '',
				esc_url(
					add_query_arg(
						array(
							'page' => Module::PAGE,
							'tab'  => $id,
						),
						admin_url( 'admin.php' )
					)
				),
				esc_html( $label )
			);
		}
		echo '</nav>';
		if ( 'csv' === $tab ) {
			self::render_csv( $types );
		} else {
			self::render_editor( $types, $type );
		}
		echo '</div>';
	}

	/**
	 * Inline editor.
	 *
	 * @param array  $types Post types.
	 * @param string $type  Current post type.
	 */
	private static function render_editor( array $types, string $type ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters.
		$filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : '';
		// phpcs:enable
		$table = new ListTable( $type );
		$table->prepare_items();
		echo '<form method="get" class="mgseo-bulk-filters">';
		echo '<input type="hidden" name="page" value="' . esc_attr( Module::PAGE ) . '" />';
		echo '<label for="mgseo-bulk-type" class="screen-reader-text">' . esc_html__( 'Post type', 'mg-seo' ) . '</label><select id="mgseo-bulk-type" name="pt">';
		foreach ( $types as $name => $object ) {
			echo '<option value="' . esc_attr( $name ) . '"' . selected( $name, $type, false ) . '>' . esc_html( $object->labels->name ) . '</option>';
		}
		echo '</select> <label for="mgseo-bulk-filter" class="screen-reader-text">' . esc_html__( 'Filter', 'mg-seo' ) . '</label><select id="mgseo-bulk-filter" name="filter">';
		foreach ( ListTable::filters() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '"' . selected( $value, $filter, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Filter', 'mg-seo' ), 'secondary', '', false );
		$table->search_box( __( 'Search', 'mg-seo' ), 'mgseo-bulk-search' );
		echo '</form>';
		echo '<p><button type="button" class="button button-primary" id="mgseo-bulk-save-all">' . esc_html__( 'Save all changes', 'mg-seo' ) . '</button> <span id="mgseo-bulk-global-status" aria-live="polite"></span></p>';
		echo '<div id="mgseo-bulk-table">';
		$table->display();
		echo '</div>';
	}

	/**
	 * CSV tab.
	 *
	 * @param array $types Post types.
	 */
	private static function render_csv( array $types ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- display of redirect status.
		$status  = isset( $_GET['csv-status'] ) ? sanitize_key( wp_unslash( $_GET['csv-status'] ) ) : '';
		$message = isset( $_GET['csv-message'] ) ? sanitize_text_field( rawurldecode( wp_unslash( $_GET['csv-message'] ) ) ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized after decoding.
		// phpcs:enable
		if ( '' !== $message ) {
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', 'error' === $status ? 'error' : 'success', esc_html( $message ) );
		}

		echo '<h2>' . esc_html__( 'Export', 'mg-seo' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mgseo_bulk_csv_export' );
		echo '<input type="hidden" name="action" value="mgseo_bulk_csv_export" />';
		echo '<label for="mgseo-csv-type">' . esc_html__( 'Post type', 'mg-seo' ) . '</label> <select id="mgseo-csv-type" name="post_type"><option value="">' . esc_html__( 'All', 'mg-seo' ) . '</option>';
		foreach ( $types as $name => $object ) {
			echo '<option value="' . esc_attr( $name ) . '">' . esc_html( $object->labels->name ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Download CSV', 'mg-seo' ), 'secondary', 'submit', false );
		echo '</form>';
		echo '<p class="description">' . esc_html( sprintf( /* translators: %s: column list */ __( 'Columns: %s.', 'mg-seo' ), implode( ', ', Csv::COLUMNS ) ) ) . '</p>';

		echo '<h2>' . esc_html__( 'Import', 'mg-seo' ) . '</h2>';
		echo '<p>' . esc_html__( 'Rows are matched by id (or post type + slug, or URL). Empty cells keep the current value, a single dash (-) clears it. Robots: comma separated directives (noindex,nofollow …) or "default".', 'mg-seo' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mgseo_bulk_csv_import' );
		echo '<input type="hidden" name="action" value="mgseo_bulk_csv_import" />';
		echo '<label for="mgseo-csv-file" class="screen-reader-text">' . esc_html__( 'CSV file', 'mg-seo' ) . '</label><input type="file" id="mgseo-csv-file" name="csv" accept=".csv,text/csv" required /> ';
		echo '<label><input type="checkbox" name="dry_run" value="1" checked /> ' . esc_html__( 'Preview changes first (dry run)', 'mg-seo' ) . '</label> ';
		submit_button( __( 'Upload', 'mg-seo' ), 'secondary', 'submit', false );
		echo '</form>';

		$plan = 'preview' === $status ? Csv::pending() : null;
		if ( $plan ) {
			/* translators: 1: rows, 2: changes */
			echo '<h3>' . esc_html( sprintf( __( 'Preview: %1$d rows, %2$d changes', 'mg-seo' ), (int) $plan['rows'], count( $plan['changes'] ) ) ) . '</h3>';
			foreach ( $plan['errors'] as $error ) {
				echo '<p class="mgseo-csv-error">' . esc_html( (string) $error ) . '</p>';
			}
			if ( $plan['changes'] ) {
				echo '<table class="widefat striped mgseo-csv-preview"><thead><tr><th>' . esc_html__( 'Post', 'mg-seo' ) . '</th><th>' . esc_html__( 'Field', 'mg-seo' ) . '</th><th>' . esc_html__( 'Current', 'mg-seo' ) . '</th><th>' . esc_html__( 'New', 'mg-seo' ) . '</th></tr></thead><tbody>';
				foreach ( array_slice( $plan['changes'], 0, 500 ) as $change ) {
					printf(
						'<tr><td>#%d %s</td><td><code>%s</code></td><td>%s</td><td>%s</td></tr>',
						(int) $change['id'],
						esc_html( (string) $change['title'] ),
						esc_html( (string) $change['field'] ),
						esc_html( '' !== $change['old'] ? (string) $change['old'] : '—' ),
						esc_html( '' !== $change['new'] ? (string) $change['new'] : '—' )
					);
				}
				echo '</tbody></table>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
				wp_nonce_field( 'mgseo_bulk_csv_apply' );
				echo '<input type="hidden" name="action" value="mgseo_bulk_csv_apply" />';
				submit_button( __( 'Apply these changes', 'mg-seo' ) );
				echo '</form>';
			}
		}
	}
}
