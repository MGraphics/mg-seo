<?php
/**
 * Tools → Utilities admin page: IndexNow manual submit and log, settings export/import/reset.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Tools;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page and admin-post handlers (capability `mgseo_manage_settings` + nonces).
 */
final class Utilities {

	public const PAGE = 'mg-seo-utilities';
	public const CAP  = 'mgseo_manage_settings';

	/**
	 * Register hooks.
	 */
	public static function boot(): void {
		add_action( 'admin_post_mgseo_indexnow_submit', array( self::class, 'handle_indexnow' ) );
		add_action( 'admin_post_mgseo_settings_export', array( self::class, 'handle_export' ) );
		add_action( 'admin_post_mgseo_settings_import', array( self::class, 'handle_import' ) );
		add_action( 'admin_post_mgseo_settings_reset', array( self::class, 'handle_reset' ) );
		add_action( 'admin_enqueue_scripts', array( self::class, 'assets' ) );
	}

	/**
	 * Assets on the Tools settings and Utilities pages.
	 *
	 * @param string $hook Hook suffix.
	 */
	public static function assets( string $hook ): void {
		if ( ! str_ends_with( $hook, self::PAGE ) && ! str_ends_with( $hook, 'mg-seo-tools' ) ) {
			return;
		}
		wp_enqueue_style( 'mgseo-tools', MGSEO_URL . 'assets/tools.css', array(), MGSEO_VERSION );
		wp_enqueue_script( 'mgseo-tools', MGSEO_URL . 'assets/tools.js', array(), MGSEO_VERSION, true );
	}

	/**
	 * Check capability or die.
	 */
	private static function guard(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'mg-seo' ), '', array( 'response' => 403 ) );
		}
	}

	/**
	 * Redirect back to the page with a message.
	 *
	 * @param string $message Message.
	 */
	private static function back( string $message ): void {
		wp_safe_redirect(
			add_query_arg(
				array(
					'page'          => self::PAGE,
					'mgseo-message' => rawurlencode( $message ),
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Settings field: IndexNow key info.
	 */
	public static function render_indexnow_status(): void {
		if ( ! IndexNow::enabled() ) {
			echo '<p class="description">' . esc_html__( 'A key is generated when you turn IndexNow on and save.', 'mg-seo' ) . '</p>';
			return;
		}
		echo '<p><code>' . esc_html( IndexNow::key() ) . '</code></p>';
		echo '<p>' . esc_html__( 'Key file:', 'mg-seo' ) . ' <a href="' . esc_url( IndexNow::key_url() ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( IndexNow::key_url() ) . '</a></p>';
		echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Submit URLs manually and view the submission log', 'mg-seo' ) . '</a></p>';
	}

	/**
	 * Settings field: link to the utilities page.
	 */
	public static function render_link(): void {
		echo '<a class="button" href="' . esc_url( admin_url( 'admin.php?page=' . self::PAGE ) ) . '">' . esc_html__( 'Open utilities', 'mg-seo' ) . '</a>';
	}

	/**
	 * Manual IndexNow submission.
	 */
	public static function handle_indexnow(): void {
		self::guard();
		check_admin_referer( 'mgseo_indexnow_submit' );
		$raw    = isset( $_POST['urls'] ) ? sanitize_textarea_field( wp_unslash( $_POST['urls'] ) ) : '';
		$urls   = array_filter( array_map( 'trim', (array) preg_split( '/\R/', $raw ) ) );
		$result = IndexNow::submit( $urls, true, 'manual' );
		/* translators: 1: number of URLs, 2: result message */
		self::back( sprintf( __( 'IndexNow (%1$d URLs): %2$s', 'mg-seo' ), $result['count'], $result['message'] ) );
	}

	/**
	 * Download settings JSON.
	 */
	public static function handle_export(): void {
		self::guard();
		check_admin_referer( 'mgseo_settings_export' );
		$data = Transfer::export( ! empty( $_POST['with_redirections'] ) );
		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="mg-seo-settings-' . gmdate( 'Y-m-d' ) . '.json"' );
		echo wp_json_encode( $data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		exit;
	}

	/**
	 * Import settings JSON.
	 */
	public static function handle_import(): void {
		self::guard();
		check_admin_referer( 'mgseo_settings_import' );
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput -- uploaded file array; validated with is_uploaded_file().
		$file = isset( $_FILES['settings'] ) && is_array( $_FILES['settings'] ) ? $_FILES['settings'] : null;
		if ( ! $file || ! empty( $file['error'] ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			self::back( __( 'Choose a settings file to import.', 'mg-seo' ) );
		}
		if ( 'json' !== strtolower( pathinfo( (string) $file['name'], PATHINFO_EXTENSION ) ) || (int) $file['size'] > 20 * MB_IN_BYTES ) {
			self::back( __( 'The file must be a JSON file exported by MG SEO.', 'mg-seo' ) );
		}
		$data   = json_decode( (string) file_get_contents( $file['tmp_name'] ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- uploaded temp file.
		$result = Transfer::import( $data, ! empty( $_POST['with_redirections'] ) );
		if ( is_wp_error( $result ) ) {
			self::back( $result->get_error_message() );
		}
		/* translators: 1: settings groups, 2: redirections created, 3: redirections skipped */
		self::back( sprintf( __( 'Settings imported: %1$d sections, %2$d redirections created, %3$d skipped.', 'mg-seo' ), $result['groups'], $result['redirections'], $result['skipped'] ) );
	}

	/**
	 * Reset settings.
	 */
	public static function handle_reset(): void {
		self::guard();
		check_admin_referer( 'mgseo_settings_reset' );
		if ( empty( $_POST['confirm'] ) ) {
			self::back( __( 'Tick the confirmation box to reset the settings.', 'mg-seo' ) );
		}
		Transfer::reset( ! empty( $_POST['reset_modules'] ) );
		self::back( __( 'All settings were reset to their defaults.', 'mg-seo' ) );
	}

	/**
	 * Render the page.
	 */
	public static function render(): void {
		self::guard();
		$post_url = admin_url( 'admin-post.php' );
		echo '<div class="wrap mgseo-wrap mgseo-tools">';
		echo '<h1>' . esc_html__( 'SEO Utilities', 'mg-seo' ) . '</h1>';

		// IndexNow.
		echo '<div class="card mgseo-tools-card"><h2>' . esc_html__( 'IndexNow', 'mg-seo' ) . '</h2>';
		if ( ! IndexNow::enabled() ) {
			echo '<p>' . esc_html__( 'IndexNow is off. Turn it on in the Tools settings to submit URLs to api.indexnow.org.', 'mg-seo' ) . ' <a href="' . esc_url( admin_url( 'admin.php?page=mg-seo-tools&tab=indexnow' ) ) . '">' . esc_html__( 'Settings', 'mg-seo' ) . '</a></p>';
		} else {
			echo '<p>' . esc_html__( 'Submitting sends the URLs below to api.indexnow.org. One URL per line; only URLs of this site are accepted.', 'mg-seo' ) . '</p>';
			echo '<form method="post" action="' . esc_url( $post_url ) . '">';
			wp_nonce_field( 'mgseo_indexnow_submit' );
			echo '<input type="hidden" name="action" value="mgseo_indexnow_submit" />';
			echo '<label class="screen-reader-text" for="mgseo-indexnow-urls">' . esc_html__( 'URLs', 'mg-seo' ) . '</label>';
			echo '<textarea id="mgseo-indexnow-urls" name="urls" rows="6" class="large-text code" placeholder="' . esc_attr( home_url( '/example-page/' ) ) . '"></textarea>';
			submit_button( __( 'Submit URLs', 'mg-seo' ), 'primary', 'submit', false );
			echo '</form>';
		}
		$log = IndexNow::get_log();
		if ( $log ) {
			echo '<h3>' . esc_html__( 'Recent submissions', 'mg-seo' ) . '</h3><table class="widefat striped"><thead><tr><th>' . esc_html__( 'Date', 'mg-seo' ) . '</th><th>' . esc_html__( 'Source', 'mg-seo' ) . '</th><th>' . esc_html__( 'URLs', 'mg-seo' ) . '</th><th>' . esc_html__( 'Result', 'mg-seo' ) . '</th></tr></thead><tbody>';
			$sources = array(
				'auto'   => __( 'Automatic', 'mg-seo' ),
				'manual' => __( 'Manual', 'mg-seo' ),
				'bulk'   => __( 'Bulk action', 'mg-seo' ),
			);
			foreach ( array_slice( $log, 0, 20 ) as $entry ) {
				echo '<tr><td>' . esc_html( wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $entry['time'] ) ) . '</td>';
				echo '<td>' . esc_html( $sources[ $entry['source'] ] ?? (string) $entry['source'] ) . '</td>';
				echo '<td><details><summary>' . esc_html( number_format_i18n( (int) $entry['count'] ) ) . '</summary>';
				foreach ( (array) $entry['urls'] as $url ) {
					echo '<code>' . esc_html( (string) $url ) . '</code><br />';
				}
				echo '</details></td><td>' . ( $entry['ok'] ? '' : '<span class="mgseo-tools-error">' ) . esc_html( (string) $entry['message'] ) . ( $entry['ok'] ? '' : '</span>' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		echo '</div>';

		// Export.
		echo '<div class="card mgseo-tools-card"><h2>' . esc_html__( 'Export settings', 'mg-seo' ) . '</h2>';
		echo '<form method="post" action="' . esc_url( $post_url ) . '">';
		wp_nonce_field( 'mgseo_settings_export' );
		echo '<input type="hidden" name="action" value="mgseo_settings_export" />';
		echo '<p><label><input type="checkbox" name="with_redirections" value="1" /> ' . esc_html__( 'Include redirections', 'mg-seo' ) . '</label></p>';
		submit_button( __( 'Download JSON', 'mg-seo' ), 'secondary', 'submit', false );
		echo '</form></div>';

		// Import.
		echo '<div class="card mgseo-tools-card"><h2>' . esc_html__( 'Import settings', 'mg-seo' ) . '</h2>';
		echo '<p>' . esc_html__( 'Imported sections replace the current settings of the same modules.', 'mg-seo' ) . '</p>';
		echo '<form method="post" enctype="multipart/form-data" action="' . esc_url( $post_url ) . '">';
		wp_nonce_field( 'mgseo_settings_import' );
		echo '<input type="hidden" name="action" value="mgseo_settings_import" />';
		echo '<p><label class="screen-reader-text" for="mgseo-settings-file">' . esc_html__( 'Settings file', 'mg-seo' ) . '</label><input type="file" id="mgseo-settings-file" name="settings" accept=".json,application/json" required /></p>';
		echo '<p><label><input type="checkbox" name="with_redirections" value="1" checked="checked" /> ' . esc_html__( 'Import redirections from the file (existing sources are skipped)', 'mg-seo' ) . '</label></p>';
		submit_button( __( 'Import', 'mg-seo' ), 'secondary', 'submit', false );
		echo '</form></div>';

		// Reset.
		echo '<div class="card mgseo-tools-card"><h2>' . esc_html__( 'Reset settings', 'mg-seo' ) . '</h2>';
		echo '<p>' . esc_html__( 'Restores the defaults of every module. SEO data of posts and terms, redirections and the 404 log are not touched.', 'mg-seo' ) . '</p>';
		echo '<form method="post" action="' . esc_url( $post_url ) . '" class="mgseo-tools-reset">';
		wp_nonce_field( 'mgseo_settings_reset' );
		echo '<input type="hidden" name="action" value="mgseo_settings_reset" />';
		echo '<p><label><input type="checkbox" name="reset_modules" value="1" /> ' . esc_html__( 'Also reset which modules are enabled', 'mg-seo' ) . '</label></p>';
		echo '<p><label><input type="checkbox" name="confirm" value="1" required /> ' . esc_html__( 'I understand that this cannot be undone', 'mg-seo' ) . '</label></p>';
		echo '<button type="submit" class="button button-link-delete mgseo-confirm" data-confirm="' . esc_attr__( 'Reset all MG SEO settings?', 'mg-seo' ) . '">' . esc_html__( 'Reset settings', 'mg-seo' ) . '</button>';
		echo '</form></div>';

		echo '</div>';
	}
}
