<?php
/**
 * SEO fields on term edit screens.
 *
 * @package MGSEO
 */

namespace MGSEO\Admin;

use MGSEO\Context;
use MGSEO\Helpers;
use MGSEO\Meta;
use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Term SEO fields. Modules may add rows via the `mgseo/term_fields` action (receives WP_Term)
 * and save via `mgseo/term_saved` (term id, raw data).
 */
final class TermFields {

	/**
	 * Register hooks.
	 */
	public static function boot(): void {
		add_action(
			'admin_init',
			static function (): void {
				foreach ( array_keys( Context::taxonomies() ) as $taxonomy ) {
					add_action( $taxonomy . '_edit_form', array( self::class, 'render' ), 20 );
					add_action( 'edited_' . $taxonomy, array( self::class, 'save' ) );
				}
			}
		);
	}

	/**
	 * Render fields.
	 *
	 * @param \WP_Term $term Term.
	 */
	public static function render( \WP_Term $term ): void {
		if ( ! current_user_can( 'mgseo_edit_meta' ) ) {
			return;
		}
		$robots    = (array) Meta::term( $term->term_id, 'robots', array() );
		$index     = in_array( 'noindex', $robots, true ) ? 'noindex' : ( in_array( 'index', $robots, true ) ? 'index' : '' );
		$title_tpl = (string) Options::get( 'titles', 'tax_' . $term->taxonomy . '_title', '%term% %sep% %sitename%' );
		wp_nonce_field( 'mgseo_term', 'mgseo_term_nonce' );
		?>
		<h2><?php esc_html_e( 'SEO', 'mg-seo' ); ?></h2>
		<table class="form-table mgseo-term-fields" role="presentation"><tbody>
			<tr>
				<th><label for="mgseo-title"><?php esc_html_e( 'SEO title', 'mg-seo' ); ?></label></th>
				<td><input type="text" id="mgseo-title" name="mgseo[title]" class="large-text mgseo-has-variables" value="<?php echo esc_attr( (string) Meta::term( $term->term_id, 'title' ) ); ?>" placeholder="<?php echo esc_attr( $title_tpl ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="mgseo-description"><?php esc_html_e( 'Meta description', 'mg-seo' ); ?></label></th>
				<td><textarea id="mgseo-description" name="mgseo[description]" rows="3" class="large-text mgseo-has-variables"><?php echo esc_textarea( (string) Meta::term( $term->term_id, 'description' ) ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="mgseo-focus-keyword"><?php esc_html_e( 'Focus keywords', 'mg-seo' ); ?></label></th>
				<td><input type="text" id="mgseo-focus-keyword" name="mgseo[focus_keyword]" class="large-text" value="<?php echo esc_attr( (string) Meta::term( $term->term_id, 'focus_keyword' ) ); ?>" /></td>
			</tr>
			<?php if ( current_user_can( 'mgseo_edit_advanced' ) ) : ?>
			<tr>
				<th><label for="mgseo-index"><?php esc_html_e( 'Indexing', 'mg-seo' ); ?></label></th>
				<td>
					<select id="mgseo-index" name="mgseo[index]">
						<option value=""><?php esc_html_e( 'Default', 'mg-seo' ); ?></option>
						<option value="index" <?php selected( $index, 'index' ); ?>><?php esc_html_e( 'Index', 'mg-seo' ); ?></option>
						<option value="noindex" <?php selected( $index, 'noindex' ); ?>><?php esc_html_e( 'No Index', 'mg-seo' ); ?></option>
					</select>
					<?php foreach ( Helpers::robots_directives() as $key => $label ) : ?>
						<?php
						if ( 'noindex' === $key ) {
							continue;
						}
						?>
						<label class="mgseo-inline"><input type="checkbox" name="mgseo[robots][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $robots, true ) ); ?> /> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
				</td>
			</tr>
			<tr>
				<th><label for="mgseo-canonical"><?php esc_html_e( 'Canonical URL', 'mg-seo' ); ?></label></th>
				<td><input type="url" id="mgseo-canonical" name="mgseo[canonical]" class="large-text" value="<?php echo esc_attr( (string) Meta::term( $term->term_id, 'canonical' ) ); ?>" /></td>
			</tr>
			<?php endif; ?>
			<tr>
				<th><label for="mgseo-og-title"><?php esc_html_e( 'Open Graph title', 'mg-seo' ); ?></label></th>
				<td><input type="text" id="mgseo-og-title" name="mgseo[og_title]" class="large-text mgseo-has-variables" value="<?php echo esc_attr( (string) Meta::term( $term->term_id, 'og_title' ) ); ?>" /></td>
			</tr>
			<tr>
				<th><label for="mgseo-og-description"><?php esc_html_e( 'Open Graph description', 'mg-seo' ); ?></label></th>
				<td><textarea id="mgseo-og-description" name="mgseo[og_description]" rows="2" class="large-text mgseo-has-variables"><?php echo esc_textarea( (string) Meta::term( $term->term_id, 'og_description' ) ); ?></textarea></td>
			</tr>
			<tr>
				<th><label for="mgseo-og-image"><?php esc_html_e( 'Social image', 'mg-seo' ); ?></label></th>
				<td><input type="url" id="mgseo-og-image" name="mgseo[og_image]" class="regular-text" value="<?php echo esc_attr( (string) Meta::term( $term->term_id, 'og_image' ) ); ?>" /> <button type="button" class="button mgseo-media" data-target="mgseo-og-image"><?php esc_html_e( 'Choose image', 'mg-seo' ); ?></button></td>
			</tr>
			<?php
			/**
			 * Fires inside the term SEO table.
			 *
			 * @param \WP_Term $term Term.
			 */
			do_action( 'mgseo/term_fields', $term );
			?>
		</tbody></table>
		<?php
	}

	/**
	 * Save fields.
	 *
	 * @param int $term_id Term id.
	 */
	public static function save( int $term_id ): void {
		if ( ! isset( $_POST['mgseo_term_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mgseo_term_nonce'] ) ), 'mgseo_term' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_term', $term_id ) || ! current_user_can( 'mgseo_edit_meta' ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		$data = isset( $_POST['mgseo'] ) && is_array( $_POST['mgseo'] ) ? wp_unslash( $_POST['mgseo'] ) : array();
		Meta::set_term( $term_id, 'title', sanitize_text_field( (string) ( $data['title'] ?? '' ) ) );
		Meta::set_term( $term_id, 'description', sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ) );
		Meta::set_term( $term_id, 'focus_keyword', sanitize_text_field( (string) ( $data['focus_keyword'] ?? '' ) ) );
		Meta::set_term( $term_id, 'og_image', esc_url_raw( (string) ( $data['og_image'] ?? '' ) ) );
		Meta::set_term( $term_id, 'og_title', sanitize_text_field( (string) ( $data['og_title'] ?? '' ) ) );
		Meta::set_term( $term_id, 'og_description', sanitize_textarea_field( (string) ( $data['og_description'] ?? '' ) ) );
		if ( current_user_can( 'mgseo_edit_advanced' ) && array_key_exists( 'index', $data ) ) {
			$robots = array_diff( Helpers::sanitize_robots( $data['robots'] ?? array() ), array( 'index', 'noindex' ) );
			$index  = sanitize_key( (string) $data['index'] );
			if ( in_array( $index, array( 'index', 'noindex' ), true ) ) {
				array_unshift( $robots, $index );
			}
			Meta::set_term( $term_id, 'robots', array_values( $robots ) );
			Meta::set_term( $term_id, 'canonical', esc_url_raw( (string) ( $data['canonical'] ?? '' ) ) );
		}
		/**
		 * Fires after term SEO data is saved.
		 *
		 * @param int   $term_id Term id.
		 * @param array $data    Raw data.
		 */
		do_action( 'mgseo/term_saved', $term_id, $data );
	}
}
