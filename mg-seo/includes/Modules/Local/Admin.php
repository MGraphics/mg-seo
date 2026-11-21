<?php
/**
 * Local SEO admin UI: custom field renderers and the location meta box.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Local;

use MGSEO\Admin\Settings;
use MGSEO\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Admin helpers.
 */
final class Admin {

	/**
	 * Weekday labels.
	 *
	 * @return array<string, string>
	 */
	public static function day_labels(): array {
		global $wp_locale;
		$labels = array();
		foreach ( Business::DAYS as $i => $day ) {
			// WP_Locale weekdays start on Sunday (0).
			$labels[ $day ] = $wp_locale ? $wp_locale->get_weekday( ( $i + 1 ) % 7 ) : ucfirst( $day );
		}
		return $labels;
	}

	/**
	 * Render the phone rows field.
	 *
	 * @param array  $field Field.
	 * @param mixed  $value Value.
	 * @param string $name  Input name.
	 */
	public static function render_phones( array $field, mixed $value, string $name ): void {
		$rows   = Business::phones( array( 'phones' => is_array( $value ) ? $value : array() ) );
		$rows[] = array(
			'number' => '',
			'type'   => 'customer service',
		);
		$rows[] = array(
			'number' => '',
			'type'   => 'sales',
		);
		echo '<table class="widefat striped" style="max-width:640px"><thead><tr><th>' . esc_html__( 'Number', 'mg-seo' ) . '</th><th>' . esc_html__( 'Contact type', 'mg-seo' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $i => $row ) {
			echo '<tr><td><input type="tel" class="regular-text" name="' . esc_attr( $name . '[' . $i . '][number]' ) . '" value="' . esc_attr( $row['number'] ) . '" /></td><td><select name="' . esc_attr( $name . '[' . $i . '][type]' ) . '">';
			foreach ( Business::contact_types() as $type => $label ) {
				echo '<option value="' . esc_attr( $type ) . '" ' . selected( $row['type'], $type, false ) . '>' . esc_html( $label ) . '</option>';
			}
			echo '</select></td></tr>';
		}
		echo '</tbody></table>';
		echo '<p class="description">' . esc_html__( 'Save to get more empty rows.', 'mg-seo' ) . '</p>';
	}

	/**
	 * Render the opening hours field.
	 *
	 * @param array  $field Field.
	 * @param mixed  $value Value.
	 * @param string $name  Input name.
	 */
	public static function render_hours( array $field, mixed $value, string $name ): void {
		$value = is_array( $value ) ? $value : array();
		$modes = array(
			''       => __( 'Not specified', 'mg-seo' ),
			'open'   => __( 'Open', 'mg-seo' ),
			'24h'    => __( 'Open 24 hours', 'mg-seo' ),
			'closed' => __( 'Closed', 'mg-seo' ),
		);
		echo '<table class="widefat striped" style="max-width:640px"><tbody>';
		foreach ( self::day_labels() as $day => $label ) {
			$row = isset( $value[ $day ] ) && is_array( $value[ $day ] ) ? $value[ $day ] : array();
			echo '<tr><th scope="row">' . esc_html( $label ) . '</th><td><select name="' . esc_attr( $name . '[' . $day . '][mode]' ) . '" aria-label="' . esc_attr( $label ) . '">';
			foreach ( $modes as $mode => $mode_label ) {
				echo '<option value="' . esc_attr( $mode ) . '" ' . selected( (string) ( $row['mode'] ?? '' ), $mode, false ) . '>' . esc_html( $mode_label ) . '</option>';
			}
			echo '</select></td><td><input type="text" class="regular-text" name="' . esc_attr( $name . '[' . $day . '][ranges]' ) . '" value="' . esc_attr( (string) ( $row['ranges'] ?? '' ) ) . '" placeholder="09:00-17:00" /></td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Render a page selector.
	 *
	 * @param array  $field Field.
	 * @param mixed  $value Value.
	 * @param string $name  Input name.
	 */
	public static function render_page_select( array $field, mixed $value, string $name ): void {
		wp_dropdown_pages(
			array(
				'name'              => esc_attr( $name ),
				'id'                => esc_attr( 'mgseo-' . str_replace( '_', '-', (string) $field['id'] ) ),
				'selected'          => (int) $value,
				'show_option_none'  => esc_html__( '— None —', 'mg-seo' ),
				'option_none_value' => '0',
			)
		);
	}

	/**
	 * Register the location details meta box.
	 */
	public static function add_meta_box(): void {
		add_meta_box( 'mgseo-location', __( 'Location details', 'mg-seo' ), array( self::class, 'render_meta_box' ), Locations::POST_TYPE, 'normal', 'high' );
	}

	/**
	 * Render the location meta box (same fields as the global business settings).
	 *
	 * @param \WP_Post $post Location.
	 */
	public static function render_meta_box( \WP_Post $post ): void {
		wp_nonce_field( 'mgseo_location', 'mgseo_location_nonce' );
		$stored = Meta::post( $post->ID, Business::META, array() );
		$stored = is_array( $stored ) ? $stored : array();
		$titles = array(
			'business' => __( 'Business', 'mg-seo' ),
			'contact'  => __( 'Contact', 'mg-seo' ),
			'hours'    => __( 'Opening hours', 'mg-seo' ),
		);
		ob_start();
		foreach ( Business::fields( true ) as $group => $fields ) {
			echo '<h3>' . esc_html( $titles[ $group ] ?? $group ) . '</h3><table class="form-table" role="presentation"><tbody>';
			foreach ( $fields as $field ) {
				Settings::render_field( $field, $stored[ $field['id'] ] ?? ( $field['default'] ?? '' ) );
			}
			echo '</tbody></table>';
		}
		// The generic renderer names fields mgseo[...]; locations use their own array so they never clash with the SEO box.
		echo str_replace( 'name="mgseo[', 'name="mgseo_local[', (string) ob_get_clean() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped by the field renderer.
		/* translators: %s: shortcode */
		echo '<p class="description">' . esc_html( sprintf( __( 'Show this location anywhere with %s.', 'mg-seo' ), '[mgseo_local location="' . $post->ID . '"]' ) ) . '</p>';
	}

	/**
	 * Save the location meta box.
	 *
	 * @param int $post_id Post id.
	 */
	public static function save_meta_box( int $post_id ): void {
		if ( ! isset( $_POST['mgseo_location_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mgseo_location_nonce'] ) ), 'mgseo_location' ) ) {
			return;
		}
		if ( ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) || wp_is_post_revision( $post_id ) || ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
		$raw = isset( $_POST['mgseo_local'] ) && is_array( $_POST['mgseo_local'] ) ? wp_unslash( $_POST['mgseo_local'] ) : array();
		Meta::set_post( $post_id, Business::META, self::sanitize_data( $raw ) );
	}

	/**
	 * Sanitize a raw business data array with the field definitions.
	 *
	 * @param array $raw Raw values.
	 * @return array<string, mixed>
	 */
	public static function sanitize_data( array $raw ): array {
		$data = array();
		foreach ( Business::flat_fields( true ) as $field ) {
			if ( 'heading' === $field['type'] || ! array_key_exists( $field['id'], $raw ) ) {
				continue;
			}
			$data[ $field['id'] ] = Settings::sanitize( $field, $raw[ $field['id'] ] );
		}
		return array_filter( $data, static fn( $v ) => '' !== $v && array() !== $v );
	}
}
