<?php
/**
 * Import & Export admin page.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Import;

defined( 'ABSPATH' ) || exit;

/**
 * Detection panel, import form with progress, result log and leftover cleanup.
 */
final class Page {

	/**
	 * Scope labels.
	 *
	 * @return array<string, string>
	 */
	public static function scope_labels(): array {
		return array(
			'settings'     => __( 'Settings', 'mg-seo' ),
			'posts'        => __( 'Post meta', 'mg-seo' ),
			'terms'        => __( 'Term meta', 'mg-seo' ),
			'users'        => __( 'User meta', 'mg-seo' ),
			'redirections' => __( 'Redirections', 'mg-seo' ),
			'schema'       => __( 'Schema', 'mg-seo' ),
			'log404'       => __( '404 log', 'mg-seo' ),
		);
	}

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( Module::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mg-seo' ) );
		}
		$detect = Runner::detect();
		$labels = self::scope_labels();
		$log    = Log::load()->to_array();
		$first  = '';
		foreach ( $detect as $id => $info ) {
			if ( '' === $first && array_sum( $info['counts'] ) > 0 ) {
				$first = $id;
			}
		}
		$first = '' !== $first ? $first : (string) array_key_first( $detect );

		echo '<div class="wrap mgseo-wrap mgseo-import">';
		echo '<h1>' . esc_html__( 'Import & Export', 'mg-seo' ) . '</h1>';
		echo '<p>' . esc_html__( 'MG SEO reads the data other SEO plugins leave in the database, so the import also works after Rank Math or Yoast SEO has been deactivated and deleted. Nothing is sent outside your site.', 'mg-seo' ) . '</p>';

		// Detection panel.
		echo '<h2>' . esc_html__( 'Data found', 'mg-seo' ) . '</h2>';
		echo '<table class="widefat striped mgseo-import-detect"><thead><tr><th>' . esc_html__( 'Source', 'mg-seo' ) . '</th><th>' . esc_html__( 'Plugin', 'mg-seo' ) . '</th>';
		foreach ( $labels as $label ) {
			echo '<th>' . esc_html( $label ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $detect as $info ) {
			echo '<tr><th scope="row">' . esc_html( $info['label'] ) . '</th><td>';
			if ( $info['active'] ) {
				esc_html_e( 'Active', 'mg-seo' );
			} elseif ( $info['installed'] ) {
				esc_html_e( 'Installed, inactive', 'mg-seo' );
			} else {
				esc_html_e( 'Not installed', 'mg-seo' );
			}
			echo '</td>';
			foreach ( array_keys( $labels ) as $scope ) {
				echo '<td>' . ( in_array( $scope, $info['scopes'], true ) ? esc_html( number_format_i18n( (int) ( $info['counts'][ $scope ] ?? 0 ) ) ) : '&ndash;' ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';

		// Import form.
		echo '<h2>' . esc_html__( 'Import', 'mg-seo' ) . '</h2>';
		echo '<form id="mgseo-import-form" class="mgseo-import-form">';
		echo '<fieldset><legend>' . esc_html__( 'Source', 'mg-seo' ) . '</legend>';
		foreach ( $detect as $id => $info ) {
			printf(
				'<label class="mgseo-inline"><input type="radio" name="source" value="%1$s" data-scopes="%2$s" %3$s /> %4$s</label> ',
				esc_attr( $id ),
				esc_attr( implode( ',', $info['scopes'] ) ),
				checked( $id, $first, false ),
				esc_html( $info['label'] )
			);
		}
		echo '</fieldset><fieldset><legend>' . esc_html__( 'What to import', 'mg-seo' ) . '</legend>';
		foreach ( $labels as $scope => $label ) {
			printf(
				'<label class="mgseo-inline mgseo-scope" data-scope="%1$s"><input type="checkbox" name="scopes[]" value="%1$s" checked /> %2$s</label> ',
				esc_attr( $scope ),
				esc_html( $label )
			);
		}
		echo '</fieldset><fieldset><legend>' . esc_html__( 'Options', 'mg-seo' ) . '</legend>';
		echo '<label class="mgseo-block"><input type="checkbox" name="overwrite" value="1" /> ' . esc_html__( 'Overwrite values that already exist in MG SEO', 'mg-seo' ) . '</label>';
		echo '<label class="mgseo-block"><input type="checkbox" name="dry_run" value="1" /> ' . esc_html__( 'Dry run: only show what would change', 'mg-seo' ) . '</label>';
		echo '</fieldset>';
		echo '<p><button type="submit" class="button button-primary" id="mgseo-import-start">' . esc_html__( 'Start import', 'mg-seo' ) . '</button></p>';
		echo '</form>';
		echo '<div id="mgseo-import-progress" class="mgseo-import-progress" hidden><div class="mgseo-import-bar"><span style="width:0"></span></div><p class="mgseo-import-status" aria-live="polite"></p></div>';

		// Result log.
		echo '<div id="mgseo-import-log">';
		self::render_log( $log );
		echo '</div>';

		// Cleanup.
		echo '<h2>' . esc_html__( 'Delete leftover data', 'mg-seo' ) . '</h2>';
		echo '<p>' . esc_html__( 'After a successful import you can remove the old plugin\'s data (post, term and user meta, options and tables). This cannot be undone – make a database backup first.', 'mg-seo' ) . '</p>';
		foreach ( $detect as $id => $info ) {
			if ( ! array_sum( $info['counts'] ) ) {
				continue;
			}
			echo '<form class="mgseo-import-cleanup" data-source="' . esc_attr( $id ) . '"><p>';
			if ( $info['active'] ) {
				/* translators: %s: plugin name */
				echo esc_html( sprintf( __( 'Deactivate %s to delete its data.', 'mg-seo' ), $info['label'] ) );
			} else {
				echo '<label><input type="checkbox" name="understand" value="1" /> ';
				/* translators: %s: plugin name */
				echo esc_html( sprintf( __( 'I want to permanently delete all %s data.', 'mg-seo' ), $info['label'] ) ) . '</label> ';
				echo '<label>' . esc_html__( 'Type DELETE:', 'mg-seo' ) . ' <input type="text" name="confirm" class="small-text" autocomplete="off" /></label> ';
				echo '<button type="submit" class="button button-link-delete" disabled>' . esc_html__( 'Delete data', 'mg-seo' ) . '</button>';
			}
			echo '</p></form>';
		}

		// Export.
		echo '<h2>' . esc_html__( 'Export', 'mg-seo' ) . '</h2><ul class="ul-disc">';
		echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=mg-seo-utilities' ) ) . '">' . esc_html__( 'Export or import MG SEO settings (JSON)', 'mg-seo' ) . '</a></li>';
		if ( current_user_can( 'mgseo_bulk_edit' ) ) {
			echo '<li><a href="' . esc_url( admin_url( 'admin.php?page=mg-seo-bulk&tab=csv' ) ) . '">' . esc_html__( 'Export or import per-post SEO data (CSV)', 'mg-seo' ) . '</a></li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Render a result log.
	 *
	 * @param array $log Log data.
	 */
	public static function render_log( array $log ): void {
		if ( empty( $log['started'] ) ) {
			return;
		}
		$labels = self::scope_labels();
		echo '<h2>' . ( ! empty( $log['dry_run'] ) ? esc_html__( 'Last dry run', 'mg-seo' ) : esc_html__( 'Last import', 'mg-seo' ) ) . '</h2>';
		/* translators: 1: source, 2: date */
		echo '<p>' . esc_html( sprintf( __( 'Source: %1$s, started %2$s (UTC).', 'mg-seo' ), (string) $log['source'], (string) $log['started'] ) ) . '</p>';
		if ( $log['counts'] ) {
			echo '<table class="widefat striped mgseo-import-counts"><tbody>';
			foreach ( $log['counts'] as $scope => $counts ) {
				$parts = array();
				foreach ( $counts as $key => $value ) {
					$parts[] = $key . ': ' . number_format_i18n( (int) $value );
				}
				echo '<tr><th scope="row">' . esc_html( $labels[ $scope ] ?? $scope ) . '</th><td>' . esc_html( implode( ', ', $parts ) ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		if ( $log['unknown'] ) {
			echo '<h3>' . esc_html__( 'Variables kept as they were (no MG SEO equivalent)', 'mg-seo' ) . '</h3><ul class="ul-disc">';
			foreach ( $log['unknown'] as $variable => $count ) {
				echo '<li><code>' . esc_html( (string) $variable ) . '</code> &times;' . esc_html( (string) $count ) . '</li>';
			}
			echo '</ul>';
		}
		if ( $log['warnings'] ) {
			echo '<h3>' . esc_html__( 'Notes', 'mg-seo' ) . '</h3><ul class="ul-disc">';
			foreach ( $log['warnings'] as $warning ) {
				echo '<li>' . esc_html( (string) $warning ) . '</li>';
			}
			echo '</ul>';
		}
		if ( ! empty( $log['dry_run'] ) && $log['changes'] ) {
			echo '<h3>' . esc_html__( 'Planned changes (first 200)', 'mg-seo' ) . '</h3><pre class="mgseo-import-changes">' . esc_html( implode( "\n", $log['changes'] ) ) . '</pre>';
		}
	}
}
