<?php
/**
 * Role Manager module.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Roles;

use MGSEO\Installer;
use MGSEO\Module as Base;

defined( 'ABSPATH' ) || exit;

/**
 * Matrix of WordPress roles × MG SEO capabilities, saved with add_cap()/remove_cap().
 */
final class Module extends Base {

	public const PAGE   = 'mg-seo-roles';
	public const CAP    = 'mgseo_manage_settings';
	public const ACTION = 'mgseo_roles_save';

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'roles';
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_post_' . self::ACTION, array( $this, 'handle_save' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
	}

	/**
	 * Admin page.
	 */
	public function admin_pages(): array {
		return array(
			array(
				'slug'       => self::PAGE,
				'title'      => __( 'Role Manager', 'mg-seo' ),
				'menu_title' => __( 'Role Manager', 'mg-seo' ),
				'capability' => self::CAP,
				'callback'   => array( $this, 'render' ),
				'position'   => 88,
			),
		);
	}

	/**
	 * Assets.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function assets( string $hook ): void {
		if ( str_ends_with( $hook, self::PAGE ) ) {
			wp_enqueue_style( 'mgseo-roles', MGSEO_URL . 'assets/roles.css', array(), MGSEO_VERSION );
		}
	}

	/**
	 * Current matrix: role => list of MG SEO caps it has.
	 *
	 * @return array<string, string[]>
	 */
	public static function matrix(): array {
		$out  = array();
		$caps = array_keys( Installer::capabilities() );
		foreach ( wp_roles()->role_objects as $name => $role ) {
			$out[ $name ] = array_values( array_filter( $caps, static fn( $cap ) => $role->has_cap( $cap ) ) );
		}
		return $out;
	}

	/**
	 * Apply a matrix (role => granted caps). Roles missing from the matrix lose all MG SEO caps.
	 * Administrators always keep `mgseo_manage_settings`.
	 *
	 * @param array<string, string[]> $matrix Matrix.
	 */
	public static function apply( array $matrix ): void {
		$caps = array_keys( Installer::capabilities() );
		foreach ( wp_roles()->role_objects as $name => $role ) {
			$granted = array_intersect( $caps, array_map( 'strval', (array) ( $matrix[ $name ] ?? array() ) ) );
			if ( 'administrator' === $name ) {
				$granted[] = self::CAP;
			}
			foreach ( $caps as $cap ) {
				$has  = $role->has_cap( $cap );
				$want = in_array( $cap, $granted, true );
				if ( $want && ! $has ) {
					$role->add_cap( $cap );
				} elseif ( ! $want && $has ) {
					$role->remove_cap( $cap );
				}
			}
		}
		// Every capability now has an explicit owner list: the installer must not re-add defaults.
		update_option( 'mgseo_caps_configured', $caps );
	}

	/**
	 * Default matrix from the installer definition.
	 *
	 * @return array<string, string[]>
	 */
	public static function defaults(): array {
		$out = array();
		foreach ( Installer::capabilities() as $cap => $def ) {
			foreach ( $def['roles'] as $role ) {
				$out[ $role ][] = $cap;
			}
		}
		return $out;
	}

	/**
	 * Save handler.
	 */
	public function handle_save(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'mg-seo' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION );
		if ( isset( $_POST['reset'] ) ) {
			self::apply( self::defaults() );
			$message = 'reset';
		} else {
			$matrix = array();
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- keys and values sanitized below.
			$raw = isset( $_POST['caps'] ) && is_array( $_POST['caps'] ) ? wp_unslash( $_POST['caps'] ) : array();
			foreach ( $raw as $role => $caps ) {
				$matrix[ sanitize_key( (string) $role ) ] = array_map( 'sanitize_key', array_keys( (array) $caps ) );
			}
			self::apply( $matrix );
			$message = 'saved';
		}
		wp_safe_redirect( add_query_arg( 'mgseo-notice', $message, admin_url( 'admin.php?page=' . self::PAGE ) ) );
		exit;
	}

	/**
	 * Render the matrix.
	 */
	public function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mg-seo' ) );
		}
		$caps   = Installer::capabilities();
		$matrix = self::matrix();
		$names  = wp_roles()->get_names();
		echo '<div class="wrap mgseo-wrap mgseo-roles">';
		echo '<h1>' . esc_html__( 'Role Manager', 'mg-seo' ) . '</h1>';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( isset( $_GET['mgseo-notice'] ) && 'reset' === sanitize_key( wp_unslash( $_GET['mgseo-notice'] ) ) ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Default capabilities restored.', 'mg-seo' ) . '</p></div>';
		}
		echo '<p>' . esc_html__( 'Choose what each role may do in MG SEO. Administrators always keep access to the settings so the site cannot be locked out.', 'mg-seo' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( self::ACTION );
		echo '<input type="hidden" name="action" value="' . esc_attr( self::ACTION ) . '" />';
		echo '<table class="widefat striped mgseo-roles-matrix"><thead><tr><th scope="col">' . esc_html__( 'Role', 'mg-seo' ) . '</th>';
		foreach ( $caps as $def ) {
			echo '<th scope="col">' . esc_html( $def['label'] ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $names as $role => $label ) {
			echo '<tr><th scope="row">' . esc_html( translate_user_role( $label ) ) . '</th>';
			foreach ( array_keys( $caps ) as $cap ) {
				$locked  = 'administrator' === $role && self::CAP === $cap;
				$checked = $locked || in_array( $cap, $matrix[ $role ] ?? array(), true );
				printf(
					'<td><label class="screen-reader-text" for="mgseo-cap-%1$s-%2$s">%3$s</label><input type="checkbox" id="mgseo-cap-%1$s-%2$s" name="caps[%1$s][%2$s]" value="1" %4$s %5$s /></td>',
					esc_attr( $role ),
					esc_attr( $cap ),
					/* translators: 1: capability label, 2: role name */
					esc_html( sprintf( __( '%1$s – %2$s', 'mg-seo' ), $caps[ $cap ]['label'], translate_user_role( $label ) ) ),
					checked( $checked, true, false ),
					disabled( $locked, true, false )
				);
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
		echo '<p class="submit">';
		submit_button( __( 'Save roles', 'mg-seo' ), 'primary', 'submit', false );
		echo ' ';
		submit_button( __( 'Reset to defaults', 'mg-seo' ), 'secondary', 'reset', false );
		echo '</p></form></div>';
	}
}
