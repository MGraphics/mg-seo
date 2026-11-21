<?php
/**
 * SEO fields on user profiles.
 *
 * @package MGSEO
 */

namespace MGSEO\Admin;

use MGSEO\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Author archive SEO and social profiles (used for Person schema sameAs and twitter:creator).
 */
final class UserFields {

	/**
	 * Social profile fields.
	 *
	 * @return array<string, string>
	 */
	public static function profiles(): array {
		return array(
			'facebook'  => 'Facebook',
			'twitter'   => 'X (Twitter) @username',
			'linkedin'  => 'LinkedIn',
			'instagram' => 'Instagram',
			'youtube'   => 'YouTube',
			'pinterest' => 'Pinterest',
			'tiktok'    => 'TikTok',
		);
	}

	/**
	 * Register hooks.
	 */
	public static function boot(): void {
		add_action( 'show_user_profile', array( self::class, 'render' ) );
		add_action( 'edit_user_profile', array( self::class, 'render' ) );
		add_action( 'personal_options_update', array( self::class, 'save' ) );
		add_action( 'edit_user_profile_update', array( self::class, 'save' ) );
	}

	/**
	 * Render.
	 *
	 * @param \WP_User $user User.
	 */
	public static function render( \WP_User $user ): void {
		if ( ! current_user_can( 'edit_user', $user->ID ) ) {
			return;
		}
		wp_nonce_field( 'mgseo_user', 'mgseo_user_nonce' );
		echo '<h2>' . esc_html__( 'SEO', 'mg-seo' ) . '</h2><table class="form-table" role="presentation"><tbody>';
		foreach ( self::profiles() as $key => $label ) {
			printf(
				'<tr><th><label for="mgseo-social-%1$s">%2$s</label></th><td><input type="text" id="mgseo-social-%1$s" name="mgseo[social_%1$s]" value="%3$s" class="regular-text" /></td></tr>',
				esc_attr( $key ),
				esc_html( $label ),
				esc_attr( (string) Meta::user( $user->ID, 'social_' . $key ) )
			);
		}
		if ( current_user_can( 'mgseo_edit_meta' ) ) {
			printf(
				'<tr><th><label for="mgseo-user-title">%s</label></th><td><input type="text" id="mgseo-user-title" name="mgseo[title]" value="%s" class="large-text" /></td></tr>',
				esc_html__( 'Author archive SEO title', 'mg-seo' ),
				esc_attr( (string) Meta::user( $user->ID, 'title' ) )
			);
			printf(
				'<tr><th><label for="mgseo-user-description">%s</label></th><td><textarea id="mgseo-user-description" name="mgseo[description]" rows="3" class="large-text">%s</textarea></td></tr>',
				esc_html__( 'Author archive meta description', 'mg-seo' ),
				esc_textarea( (string) Meta::user( $user->ID, 'description' ) )
			);
			printf(
				'<tr><th>%s</th><td><label><input type="checkbox" name="mgseo[noindex]" value="1" %s /> %s</label></td></tr>',
				esc_html__( 'Author archive', 'mg-seo' ),
				checked( in_array( 'noindex', (array) Meta::user( $user->ID, 'robots', array() ), true ), true, false ),
				esc_html__( 'No Index', 'mg-seo' )
			);
		}
		echo '</tbody></table>';
	}

	/**
	 * Save.
	 *
	 * @param int $user_id User id.
	 */
	public static function save( int $user_id ): void {
		if ( ! isset( $_POST['mgseo_user_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['mgseo_user_nonce'] ) ), 'mgseo_user' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized below.
		$data = isset( $_POST['mgseo'] ) && is_array( $_POST['mgseo'] ) ? wp_unslash( $_POST['mgseo'] ) : array();
		foreach ( array_keys( self::profiles() ) as $key ) {
			$value = sanitize_text_field( (string) ( $data[ 'social_' . $key ] ?? '' ) );
			Meta::set_user( $user_id, 'social_' . $key, $value );
		}
		if ( current_user_can( 'mgseo_edit_meta' ) ) {
			Meta::set_user( $user_id, 'title', sanitize_text_field( (string) ( $data['title'] ?? '' ) ) );
			Meta::set_user( $user_id, 'description', sanitize_textarea_field( (string) ( $data['description'] ?? '' ) ) );
			Meta::set_user( $user_id, 'robots', ! empty( $data['noindex'] ) ? array( 'noindex' ) : array() );
		}
	}
}
