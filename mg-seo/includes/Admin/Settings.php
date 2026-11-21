<?php
/**
 * Generic settings page renderer and saver.
 *
 * @package MGSEO
 */

namespace MGSEO\Admin;

use MGSEO\Module;
use MGSEO\Options;
use MGSEO\Plugin;
use MGSEO\Variables;

defined( 'ABSPATH' ) || exit;

/**
 * Renders a module's settings() schema as a tabbed form and saves it.
 */
final class Settings {

	/**
	 * Render a module settings page.
	 *
	 * @param Module $module Module.
	 */
	public static function render_page( Module $module ): void {
		if ( ! current_user_can( $module->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mg-seo' ) );
		}
		$tabs   = $module->settings();
		$values = Options::group( $module->id() );
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- tab selection only.
		$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : ( $tabs[0]['id'] ?? '' );

		echo '<div class="wrap mgseo-wrap">';
		echo '<h1>' . esc_html( Plugin::title( $module->id() ) ) . '</h1>';
		if ( count( $tabs ) > 1 ) {
			echo '<nav class="nav-tab-wrapper mgseo-tabs">';
			foreach ( $tabs as $tab ) {
				$url = add_query_arg(
					array(
						'page' => 'mg-seo-' . $module->id(),
						'tab'  => $tab['id'],
					),
					admin_url( 'admin.php' )
				);
				printf( '<a href="%s" class="nav-tab%s">%s</a>', esc_url( $url ), $tab['id'] === $current ? ' nav-tab-active' : '', esc_html( $tab['title'] ) );
			}
			echo '</nav>';
		}
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mgseo-settings-form">';
		wp_nonce_field( 'mgseo_save_' . $module->id() );
		echo '<input type="hidden" name="action" value="mgseo_save_settings" />';
		echo '<input type="hidden" name="module" value="' . esc_attr( $module->id() ) . '" />';
		echo '<input type="hidden" name="tab" value="' . esc_attr( $current ) . '" />';

		foreach ( $tabs as $tab ) {
			if ( $tab['id'] !== $current ) {
				continue;
			}
			if ( ! empty( $tab['description'] ) ) {
				echo '<p class="mgseo-tab-desc">' . wp_kses_post( $tab['description'] ) . '</p>';
			}
			echo '<table class="form-table" role="presentation"><tbody>';
			foreach ( $tab['fields'] ?? array() as $field ) {
				self::render_field( $field, $values[ $field['id'] ?? '' ] ?? ( $field['default'] ?? '' ) );
			}
			echo '</tbody></table>';
			if ( empty( $tab['no_submit'] ) ) {
				submit_button();
			}
		}
		echo '</form>';
		self::render_variables_help( $tabs, $current );
		echo '</div>';
	}

	/**
	 * Render one field row.
	 *
	 * @param array $field Field definition.
	 * @param mixed $value Current value.
	 */
	public static function render_field( array $field, mixed $value ): void {
		$type = $field['type'] ?? 'text';
		$id   = $field['id'] ?? '';
		$name = 'mgseo[' . $id . ']';
		$dom  = 'mgseo-' . str_replace( '_', '-', $id );

		if ( 'heading' === $type ) {
			echo '<tr><th colspan="2"><h2 class="mgseo-heading">' . esc_html( $field['label'] ?? '' ) . '</h2>';
			if ( ! empty( $field['desc'] ) ) {
				echo '<p class="description">' . wp_kses_post( $field['desc'] ) . '</p>';
			}
			echo '</th></tr>';
			return;
		}
		if ( 'hidden' === $type ) {
			echo '<input type="hidden" name="' . esc_attr( $name ) . '" value="' . esc_attr( (string) $value ) . '" />';
			return;
		}

		$show_if = ! empty( $field['show_if'] ) ? ' data-show-if="' . esc_attr( 'mgseo-' . str_replace( '_', '-', $field['show_if'] ) ) . '"' : '';
		echo '<tr class="mgseo-field mgseo-field-' . esc_attr( $type ) . '"' . $show_if . '>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped above.
		echo '<th scope="row"><label for="' . esc_attr( $dom ) . '">' . esc_html( $field['label'] ?? '' ) . '</label></th><td>';

		switch ( $type ) {
			case 'textarea':
			case 'code':
				printf(
					'<textarea id="%s" name="%s" rows="%d" class="large-text%s" placeholder="%s">%s</textarea>',
					esc_attr( $dom ),
					esc_attr( $name ),
					(int) ( $field['rows'] ?? ( 'code' === $type ? 10 : 3 ) ),
					'code' === $type ? ' code' : '',
					esc_attr( $field['placeholder'] ?? '' ),
					esc_textarea( is_array( $value ) ? implode( "\n", $value ) : (string) $value )
				);
				break;
			case 'toggle':
				printf(
					'<input type="hidden" name="%1$s" value="0" /><label class="mgseo-toggle"><input type="checkbox" id="%2$s" name="%1$s" value="1" %3$s /> <span>%4$s</span></label>',
					esc_attr( $name ),
					esc_attr( $dom ),
					checked( (bool) $value, true, false ),
					esc_html( $field['toggle_label'] ?? __( 'Enabled', 'mg-seo' ) )
				);
				break;
			case 'select':
				printf( '<select id="%s" name="%s">', esc_attr( $dom ), esc_attr( $name ) );
				foreach ( $field['options'] ?? array() as $opt_value => $opt_label ) {
					printf( '<option value="%s" %s>%s</option>', esc_attr( (string) $opt_value ), selected( (string) $value, (string) $opt_value, false ), esc_html( $opt_label ) );
				}
				echo '</select>';
				break;
			case 'multicheck':
				$value = is_array( $value ) ? $value : array();
				echo '<input type="hidden" name="' . esc_attr( $name ) . '[]" value="" />';
				echo '<fieldset id="' . esc_attr( $dom ) . '" class="mgseo-multicheck">';
				foreach ( $field['options'] ?? array() as $opt_value => $opt_label ) {
					printf(
						'<label><input type="checkbox" name="%s[]" value="%s" %s /> %s</label><br />',
						esc_attr( $name ),
						esc_attr( (string) $opt_value ),
						checked( in_array( (string) $opt_value, array_map( 'strval', $value ), true ), true, false ),
						esc_html( $opt_label )
					);
				}
				echo '</fieldset>';
				break;
			case 'number':
				printf(
					'<input type="number" id="%s" name="%s" value="%s" class="small-text" min="%s" max="%s" step="%s" />',
					esc_attr( $dom ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_attr( (string) ( $field['min'] ?? '' ) ),
					esc_attr( (string) ( $field['max'] ?? '' ) ),
					esc_attr( (string) ( $field['step'] ?? '1' ) )
				);
				break;
			case 'image':
				printf(
					'<div class="mgseo-image-field"><input type="url" id="%1$s" name="%2$s" value="%3$s" class="regular-text" /> <button type="button" class="button mgseo-media" data-target="%1$s">%4$s</button><div class="mgseo-image-preview">%5$s</div></div>',
					esc_attr( $dom ),
					esc_attr( $name ),
					esc_attr( (string) $value ),
					esc_html__( 'Choose image', 'mg-seo' ),
					$value ? '<img src="' . esc_url( (string) $value ) . '" alt="" />' : ''
				);
				break;
			case 'callback':
				if ( isset( $field['callback'] ) && is_callable( $field['callback'] ) ) {
					call_user_func( $field['callback'], $field, $value, $name );
				}
				break;
			case 'color':
				printf( '<input type="color" id="%s" name="%s" value="%s" />', esc_attr( $dom ), esc_attr( $name ), esc_attr( (string) $value ) );
				break;
			case 'url':
			case 'text':
			default:
				printf(
					'<input type="%s" id="%s" name="%s" value="%s" class="regular-text%s" placeholder="%s" />',
					'url' === $type ? 'url' : 'text',
					esc_attr( $dom ),
					esc_attr( $name ),
					esc_attr( is_array( $value ) ? implode( ', ', $value ) : (string) $value ),
					! empty( $field['variables'] ) ? ' mgseo-has-variables' : '',
					esc_attr( $field['placeholder'] ?? '' )
				);
				break;
		}
		if ( ! empty( $field['desc'] ) ) {
			echo '<p class="description">' . wp_kses_post( $field['desc'] ) . '</p>';
		}
		echo '</td></tr>';
	}

	/**
	 * Variables reference shown under template forms.
	 *
	 * @param array  $tabs    Tabs.
	 * @param string $current Current tab id.
	 */
	private static function render_variables_help( array $tabs, string $current ): void {
		$has = false;
		foreach ( $tabs as $tab ) {
			if ( $tab['id'] !== $current ) {
				continue;
			}
			foreach ( $tab['fields'] ?? array() as $field ) {
				if ( ! empty( $field['variables'] ) ) {
					$has = true;
				}
			}
		}
		if ( ! $has ) {
			return;
		}
		echo '<details class="mgseo-variables"><summary>' . esc_html__( 'Available variables', 'mg-seo' ) . '</summary><table class="widefat striped"><tbody>';
		foreach ( Variables::describe() as $var => $desc ) {
			echo '<tr><td><code>%' . esc_html( $var ) . '%</code></td><td>' . esc_html( $desc ) . '</td></tr>';
		}
		echo '</tbody></table></details>';
	}

	/**
	 * Handle settings save.
	 */
	public static function handle_save(): void {
		$module_id = isset( $_POST['module'] ) ? sanitize_key( wp_unslash( $_POST['module'] ) ) : '';
		$module    = Plugin::instance()->module( $module_id );
		if ( ! $module || ! current_user_can( $module->capability() ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'mg-seo' ) );
		}
		check_admin_referer( 'mgseo_save_' . $module_id );
		$tab = isset( $_POST['tab'] ) ? sanitize_key( wp_unslash( $_POST['tab'] ) ) : '';
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized per field below.
		$raw = isset( $_POST['mgseo'] ) && is_array( $_POST['mgseo'] ) ? wp_unslash( $_POST['mgseo'] ) : array();

		$values = Options::group( $module_id );
		foreach ( $module->settings() as $tab_def ) {
			if ( $tab_def['id'] !== $tab ) {
				continue;
			}
			foreach ( $tab_def['fields'] ?? array() as $field ) {
				$key = $field['id'] ?? '';
				if ( '' === $key || in_array( $field['type'] ?? '', array( 'heading' ), true ) ) {
					continue;
				}
				if ( ! array_key_exists( $key, $raw ) ) {
					continue;
				}
				$values[ $key ] = self::sanitize( $field, $raw[ $key ] );
			}
		}
		/**
		 * Filter settings before they are saved.
		 *
		 * @param array  $values    Values for the module group.
		 * @param string $module_id Module id.
		 * @param string $tab       Saved tab.
		 */
		$values = apply_filters( 'mgseo/save_settings', $values, $module_id, $tab );
		Options::set_group( $module_id, $values );
		/**
		 * Fires after module settings are saved.
		 *
		 * @param string $module_id Module id.
		 * @param array  $values    Values.
		 */
		do_action( 'mgseo/settings_saved', $module_id, $values );
		update_option( 'mgseo_flush_rewrite', 1 );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'         => 'mg-seo-' . $module_id,
					'tab'          => $tab,
					'mgseo-notice' => 'saved',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Sanitize a field value by type.
	 *
	 * @param array $field Field.
	 * @param mixed $value Raw value.
	 * @return mixed
	 */
	public static function sanitize( array $field, mixed $value ): mixed {
		if ( isset( $field['sanitize'] ) && is_callable( $field['sanitize'] ) ) {
			return call_user_func( $field['sanitize'], $value );
		}
		switch ( $field['type'] ?? 'text' ) {
			case 'toggle':
				return (bool) $value;
			case 'number':
				$num = is_numeric( $value ) ? $value + 0 : ( $field['default'] ?? 0 );
				if ( isset( $field['min'] ) ) {
					$num = max( $field['min'], $num );
				}
				if ( isset( $field['max'] ) ) {
					$num = min( $field['max'], $num );
				}
				return $num;
			case 'select':
				$value = (string) $value;
				return array_key_exists( $value, $field['options'] ?? array() ) ? $value : ( $field['default'] ?? '' );
			case 'multicheck':
				$value = is_array( $value ) ? array_map( 'strval', $value ) : array();
				return array_values( array_intersect( $value, array_map( 'strval', array_keys( $field['options'] ?? array() ) ) ) );
			case 'url':
			case 'image':
				return esc_url_raw( trim( (string) $value ) );
			case 'textarea':
				return sanitize_textarea_field( (string) $value );
			case 'code':
				// Raw code (robots.txt, custom JSON) is stored as-is for administrators; escaped on output by consumers.
				return current_user_can( 'unfiltered_html' ) ? (string) $value : sanitize_textarea_field( (string) $value );
			case 'color':
				return (string) sanitize_hex_color( (string) $value );
			case 'callback':
				return is_array( $value ) ? map_deep( $value, 'sanitize_text_field' ) : sanitize_text_field( (string) $value );
			case 'text':
			default:
				return sanitize_text_field( (string) $value );
		}
	}
}
