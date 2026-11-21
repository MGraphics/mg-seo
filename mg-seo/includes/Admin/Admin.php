<?php
/**
 * Admin bootstrap: menu, dashboard, assets.
 *
 * @package MGSEO
 */

namespace MGSEO\Admin;

use MGSEO\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Admin controller.
 */
final class Admin {

	public const MENU_SLUG = 'mg-seo';

	/**
	 * Instance.
	 *
	 * @var Admin|null
	 */
	private static ?Admin $instance = null;

	/**
	 * Get instance.
	 */
	public static function instance(): Admin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'admin_menu', array( $this, 'menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_post_mgseo_save_settings', array( Settings::class, 'handle_save' ) );
		add_action( 'admin_post_mgseo_modules', array( $this, 'handle_modules' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( MGSEO_FILE ), array( $this, 'action_links' ) );
		Metabox::boot();
		TermFields::boot();
		UserFields::boot();
	}

	/**
	 * Register the admin menu.
	 */
	public function menu(): void {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M2 16V4h2.6L10 10.4 15.4 4H18v12h-2.4V7.8L10 14.3 4.4 7.8V16z"/></svg>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- inline menu icon.
		add_menu_page(
			__( 'MG SEO', 'mg-seo' ),
			__( 'MG SEO', 'mg-seo' ),
			'mgseo_manage_settings',
			self::MENU_SLUG,
			array( $this, 'dashboard' ),
			$icon,
			80
		);
		add_submenu_page( self::MENU_SLUG, __( 'Dashboard', 'mg-seo' ), __( 'Dashboard', 'mg-seo' ), 'mgseo_manage_settings', self::MENU_SLUG, array( $this, 'dashboard' ) );

		$pages = array();
		foreach ( Plugin::instance()->modules() as $id => $module ) {
			if ( $module->settings() ) {
				$pages[] = array(
					'slug'       => 'mg-seo-' . $id,
					'title'      => Plugin::title( $id ),
					'menu_title' => $module->settings_menu_title(),
					'capability' => $module->capability(),
					'callback'   => static function () use ( $module ): void {
						Settings::render_page( $module );
					},
					'position'   => 50,
				);
			}
			foreach ( $module->admin_pages() as $page ) {
				$pages[] = $page + array( 'position' => 20 );
			}
		}
		usort( $pages, static fn( $a, $b ) => ( $a['position'] ?? 50 ) <=> ( $b['position'] ?? 50 ) );
		foreach ( $pages as $page ) {
			add_submenu_page( self::MENU_SLUG, $page['title'], $page['menu_title'] ?? $page['title'], $page['capability'] ?? 'mgseo_manage_settings', $page['slug'], $page['callback'] );
		}
	}

	/**
	 * Enqueue admin assets on MG SEO screens and editors.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function assets( string $hook ): void {
		$screen  = get_current_screen();
		$is_ours = str_contains( $hook, 'mg-seo' );
		$editor  = $screen && in_array( $screen->base, array( 'post', 'term', 'edit-tags', 'profile', 'user-edit' ), true );
		if ( ! $is_ours && ! $editor && ! ( $screen && 'edit' === $screen->base ) ) {
			return;
		}
		wp_enqueue_style( 'mgseo-admin', MGSEO_URL . 'assets/admin.css', array(), MGSEO_VERSION );
		wp_enqueue_media();
		wp_enqueue_script( 'mgseo-admin', MGSEO_URL . 'assets/admin.js', array( 'jquery', 'wp-api-fetch' ), MGSEO_VERSION, true );
		wp_localize_script(
			'mgseo-admin',
			'mgseoAdmin',
			array(
				'restUrl'   => esc_url_raw( rest_url( \MGSEO\Rest::NAMESPACE . '/' ) ),
				'nonce'     => wp_create_nonce( 'wp_rest' ),
				'siteName'  => get_bloginfo( 'name' ),
				'homeUrl'   => home_url( '/' ),
				'separator' => \MGSEO\Variables::separator(),
				'i18n'      => array(
					'chooseImage' => __( 'Choose image', 'mg-seo' ),
					'useImage'    => __( 'Use this image', 'mg-seo' ),
					'saved'       => __( 'Saved', 'mg-seo' ),
					'error'       => __( 'Error', 'mg-seo' ),
					'noKeyword'   => __( 'No keyword', 'mg-seo' ),
				),
			)
		);
	}

	/**
	 * Dashboard page: module toggles.
	 */
	public function dashboard(): void {
		if ( ! current_user_can( 'mgseo_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mg-seo' ) );
		}
		$plugin = Plugin::instance();
		echo '<div class="wrap mgseo-wrap">';
		echo '<h1>' . esc_html__( 'MG SEO', 'mg-seo' ) . '</h1>';
		echo '<p>' . esc_html__( 'Turn modules on or off. Disabled modules load no code on the front end.', 'mg-seo' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '">';
		wp_nonce_field( 'mgseo_modules' );
		echo '<input type="hidden" name="action" value="mgseo_modules" />';
		echo '<div class="mgseo-modules">';
		foreach ( Plugin::registry() as $id => $def ) {
			$active   = $plugin->is_module_active( $id );
			$required = ! empty( $def['required'] );
			$missing  = ! empty( $def['requires'] ) && ! Plugin::dependency_met( $def['requires'] );
			echo '<div class="mgseo-module-card' . ( $active ? ' is-active' : '' ) . '">';
			echo '<label><input type="checkbox" name="modules[' . esc_attr( $id ) . ']" value="1" ' . checked( $active || ( ! $missing && $this->stored_state( $id, $def ) ), true, false ) . disabled( $required || $missing, true, false ) . ' /> ';
			echo '<strong>' . esc_html( Plugin::title( $id ) ) . '</strong></label>';
			echo '<p>' . esc_html( Plugin::labels()[ $id ][1] ?? '' ) . '</p>';
			if ( $missing ) {
				/* translators: %s: dependency name */
				echo '<p class="description">' . esc_html( sprintf( __( 'Requires %s.', 'mg-seo' ), $def['requires'] ) ) . '</p>';
			}
			if ( $active && isset( $plugin->modules()[ $id ] ) && $plugin->modules()[ $id ]->settings() ) {
				echo '<p><a href="' . esc_url( admin_url( 'admin.php?page=mg-seo-' . $id ) ) . '">' . esc_html__( 'Settings', 'mg-seo' ) . '</a></p>';
			}
			echo '</div>';
		}
		echo '</div>';
		submit_button( __( 'Save modules', 'mg-seo' ) );
		echo '</form></div>';
	}

	/**
	 * Stored on/off state ignoring dependency.
	 *
	 * @param string $id  Module id.
	 * @param array  $def Definition.
	 */
	private function stored_state( string $id, array $def ): bool {
		$states = get_option( 'mgseo_modules', array() );
		if ( is_array( $states ) && array_key_exists( $id, $states ) ) {
			return (bool) $states[ $id ];
		}
		return (bool) $def['default'];
	}

	/**
	 * Save module toggles.
	 */
	public function handle_modules(): void {
		if ( ! current_user_can( 'mgseo_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'mg-seo' ) );
		}
		check_admin_referer( 'mgseo_modules' );
		$raw    = isset( $_POST['modules'] ) && is_array( $_POST['modules'] ) ? map_deep( wp_unslash( $_POST['modules'] ), 'sanitize_key' ) : array();
		$posted = array_map( 'sanitize_key', array_keys( $raw ) );
		foreach ( Plugin::registry() as $id => $def ) {
			if ( ! empty( $def['required'] ) ) {
				continue;
			}
			if ( ! empty( $def['requires'] ) && ! Plugin::dependency_met( $def['requires'] ) ) {
				continue;
			}
			$enabled = in_array( $id, $posted, true );
			if ( Plugin::instance()->is_module_active( $id ) !== $enabled ) {
				Plugin::instance()->set_module_state( $id, $enabled );
			}
		}
		update_option( 'mgseo_flush_rewrite', 1 );
		wp_safe_redirect( add_query_arg( 'mgseo-notice', 'saved', admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) );
		exit;
	}

	/**
	 * Admin notices after saves.
	 */
	public function notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$notice = isset( $_GET['mgseo-notice'] ) ? sanitize_key( wp_unslash( $_GET['mgseo-notice'] ) ) : '';
		if ( 'saved' === $notice ) {
			echo '<div class="notice notice-success is-dismissible"><p>' . esc_html__( 'Settings saved.', 'mg-seo' ) . '</p></div>';
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		$message = isset( $_GET['mgseo-message'] ) ? sanitize_text_field( wp_unslash( $_GET['mgseo-message'] ) ) : '';
		if ( '' !== $message ) {
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
			$type = isset( $_GET['mgseo-notice-type'] ) ? sanitize_key( wp_unslash( $_GET['mgseo-notice-type'] ) ) : 'info';
			$type = in_array( $type, array( 'error', 'warning', 'success', 'info' ), true ) ? $type : 'info';
			echo '<div class="notice notice-' . esc_attr( $type ) . ' is-dismissible"><p>' . esc_html( $message ) . '</p></div>';
		}
	}

	/**
	 * Plugin list action links.
	 *
	 * @param array $links Links.
	 * @return array
	 */
	public function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'admin.php?page=' . self::MENU_SLUG ) ) . '">' . esc_html__( 'Settings', 'mg-seo' ) . '</a>' );
		return $links;
	}
}
