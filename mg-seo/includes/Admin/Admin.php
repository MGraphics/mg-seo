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
		add_action( 'admin_head', array( $this, 'hide_grouped' ) );
		add_filter( 'submenu_file', array( $this, 'current_entry' ) );
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
	 * Submenu slugs registered for access but not listed (screens reached through their group).
	 *
	 * @var string[]
	 */
	private array $hidden = array();

	/**
	 * Group lead (the listed submenu entry) per page slug.
	 *
	 * @var array<string, string>
	 */
	private array $leads = array();

	/**
	 * Register the admin menu.
	 *
	 * Every screen keeps its own page and slug; the submenu lists one entry per group (Dashboard, General Settings,
	 * Titles & Meta, Sitemaps, Redirections, Tools), which opens the first screen of the group the user may open.
	 * The other screens are registered under the same parent (so access checks, page hooks and URLs stay the
	 * same) and taken out of the visible list in hide_grouped().
	 */
	public function menu(): void {
		$icon = 'data:image/svg+xml;base64,' . base64_encode( '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"><path fill="black" d="M2 16V4h2.6L10 10.4 15.4 4H18v12h-2.4V7.8L10 14.3 4.4 7.8V16z"/></svg>' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- inline menu icon.

		$pages = array(
			self::MENU_SLUG => array(
				'slug'       => self::MENU_SLUG,
				'title'      => __( 'Dashboard', 'mg-seo' ),
				'menu_title' => __( 'Dashboard', 'mg-seo' ),
				'capability' => 'mgseo_manage_settings',
				'callback'   => array( $this, 'dashboard' ),
				'position'   => 0,
			),
		);
		foreach ( Plugin::instance()->modules() as $id => $module ) {
			if ( $module->settings() ) {
				$pages[ 'mg-seo-' . $id ] = array(
					'slug'       => 'mg-seo-' . $id,
					'title'      => Plugin::title( $id ),
					'menu_title' => $module->settings_menu_title(),
					'capability' => $module->capability(),
					'callback'   => static function () use ( $module ): void {
						Settings::render_page( $module );
					},
					'position'   => 50,
					'module'     => $id,
				);
			}
			foreach ( $module->admin_pages() as $page ) {
				if ( ! empty( $page['slug'] ) ) {
					$pages[ $page['slug'] ] = $page + array( 'position' => 20 );
				}
			}
		}
		Navigation::set_pages( $pages );

		$dashboard = $this->screen( $pages[ self::MENU_SLUG ] );
		add_menu_page( __( 'MG SEO', 'mg-seo' ), __( 'MG SEO', 'mg-seo' ), 'mgseo_manage_settings', self::MENU_SLUG, $dashboard, $icon, 80 );

		$this->hidden = array();
		$this->leads  = array();
		$done         = array();
		foreach ( array_keys( Navigation::groups() ) as $group ) {
			$lead    = Navigation::lead( (string) $group );
			$members = Navigation::members( (string) $group );
			$title   = (string) ( Navigation::groups()[ $group ]['title'] ?? '' );
			foreach ( $members as $slug ) {
				$page                 = $pages[ $slug ];
				$done[ $slug ]        = true;
				$this->leads[ $slug ] = (string) $lead;
				add_submenu_page( self::MENU_SLUG, $page['title'], $slug === $lead ? $title : ( $page['menu_title'] ?? $page['title'] ), $page['capability'] ?? 'mgseo_manage_settings', $slug, $this->screen( $page ) );
				if ( $slug !== $lead ) {
					$this->hidden[] = $slug;
				}
			}
		}

		// Screens outside every group (e.g. added through filters) keep their own entry.
		$rest = array_diff_key( $pages, $done );
		uasort( $rest, static fn( $a, $b ) => ( $a['position'] ?? 50 ) <=> ( $b['position'] ?? 50 ) );
		foreach ( $rest as $slug => $page ) {
			add_submenu_page( self::MENU_SLUG, $page['title'], $page['menu_title'] ?? $page['title'], $page['capability'] ?? 'mgseo_manage_settings', $slug, $this->screen( $page ) );
		}
	}

	/**
	 * Page callback that shows a screen inside its group shell.
	 *
	 * @param array $page Page definition.
	 * @return callable
	 */
	private function screen( array $page ): callable {
		$callback = $page['callback'];
		if ( ! empty( $page['module'] ) ) {
			return $callback; // Settings pages render the shell themselves (rail with their tabs).
		}
		$slug = (string) $page['slug'];
		return static function () use ( $slug, $callback ): void {
			Navigation::render( $slug, $callback );
		};
	}

	/**
	 * Take grouped screens out of the visible submenu.
	 *
	 * Runs on admin_head: after WordPress has checked access to the current page (which needs the entries) and
	 * before the menu is printed.
	 */
	public function hide_grouped(): void {
		foreach ( $this->hidden as $slug ) {
			remove_submenu_page( self::MENU_SLUG, $slug );
		}
	}

	/**
	 * Highlight the group entry while one of its screens is open.
	 *
	 * @param string|null $submenu_file Current submenu file.
	 * @return string|null
	 */
	public function current_entry( $submenu_file ) {
		global $plugin_page;
		if ( is_string( $plugin_page ) && ! empty( $this->leads[ $plugin_page ] ) ) {
			return $this->leads[ $plugin_page ];
		}
		return $submenu_file;
	}

	/**
	 * Slugs hidden from the submenu (for tests).
	 *
	 * @internal
	 * @return string[]
	 */
	public function hidden_pages(): array {
		return $this->hidden;
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
		\MGSEO\Modules\Analysis\Editors::register();
		wp_enqueue_style( 'mgseo-ui' );
		wp_enqueue_style( 'mgseo-admin', MGSEO_URL . 'assets/admin.css', array( 'mgseo-ui' ), MGSEO_VERSION );
		wp_enqueue_media();
		wp_enqueue_script( 'mgseo-admin', MGSEO_URL . 'assets/admin.js', array( 'jquery', 'wp-api-fetch', 'mgseo-ui' ), MGSEO_VERSION, true );
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
					'toImprove'   => __( 'to improve', 'mg-seo' ),
					'seo'         => __( 'SEO', 'mg-seo' ),
					'noImage'     => __( 'No image: add a featured image or choose one below.', 'mg-seo' ),
					'on'          => __( 'On', 'mg-seo' ),
					'off'         => __( 'Off', 'mg-seo' ),
					'unsaved'     => __( 'Unsaved changes', 'mg-seo' ),
				),
			)
		);
	}

	/**
	 * Dashboard page: module cards with on/off switches.
	 *
	 * The switches are checkboxes named modules[<id>] posted to handle_modules() with the "Save modules" button.
	 */
	public function dashboard(): void {
		if ( ! current_user_can( 'mgseo_manage_settings' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mg-seo' ) );
		}
		$plugin   = Plugin::instance();
		$registry = Plugin::registry();
		$on       = count( array_filter( array_keys( $registry ), array( $plugin, 'is_module_active' ) ) );
		echo '<div class="wrap mgseo-wrap mgseo-dashboard">';
		echo '<h1>' . esc_html__( 'Modules', 'mg-seo' ) . '</h1>';
		echo '<div class="mgseo-modules-intro">';
		echo '<p>' . esc_html__( 'Turn modules on or off. Disabled modules load no code on the front end.', 'mg-seo' ) . '</p>';
		/* translators: 1: number of modules turned on, 2: number of all modules */
		echo '<p class="mgseo-modules-count">' . esc_html( sprintf( __( 'On: %1$d of %2$d', 'mg-seo' ), $on, count( $registry ) ) ) . '</p>';
		echo '</div>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mgseo-modules-form">';
		wp_nonce_field( 'mgseo_modules' );
		echo '<input type="hidden" name="action" value="mgseo_modules" />';
		echo '<div class="mgseo-modules">';
		foreach ( $registry as $id => $def ) {
			$this->module_card( $id, $def );
		}
		echo '</div>';
		echo '<div class="mgseo-modules-bar">';
		echo '<span class="mgseo-modules-bar-note" aria-live="polite"></span>';
		submit_button( __( 'Save modules', 'mg-seo' ), 'primary', 'submit', false );
		echo '</div>';
		echo '</form></div>';
	}

	/**
	 * One module card: icon, name, description, a link to its screen when on, and the switch.
	 *
	 * @param string $id  Module id.
	 * @param array  $def Registry definition.
	 */
	private function module_card( string $id, array $def ): void {
		$plugin   = Plugin::instance();
		$active   = $plugin->is_module_active( $id );
		$required = ! empty( $def['required'] );
		$missing  = ! empty( $def['requires'] ) && ! Plugin::dependency_met( $def['requires'] );
		$checked  = $active || ( ! $missing && $this->stored_state( $id, $def ) );
		$title    = Plugin::title( $id );
		$key      = sanitize_html_class( $id );
		$link     = $active ? $this->module_link( $id ) : null;

		$classes = array( 'mgseo-module', $checked ? 'is-on' : 'is-off' );
		if ( $required ) {
			$classes[] = 'is-locked';
		}
		if ( $missing ) {
			$classes[] = 'is-unavailable';
		}

		echo '<div class="' . esc_attr( implode( ' ', $classes ) ) . '" data-module="' . esc_attr( $id ) . '">';
		echo '<div class="mgseo-module-head">';
		echo '<span class="mgseo-module-icon">' . ModuleIcons::svg( $id ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- filtered with wp_kses in ModuleIcons.
		if ( $required ) {
			echo '<span class="mgseo-module-tag">' . esc_html__( 'Always on', 'mg-seo' ) . '</span>';
		} elseif ( $missing ) {
			/* translators: %s: name of the plugin the module needs, e.g. WooCommerce */
			echo '<span class="mgseo-module-tag is-warning">' . esc_html( sprintf( __( 'Needs %s', 'mg-seo' ), self::dependency_name( (string) $def['requires'] ) ) ) . '</span>';
		}
		echo '</div>';
		echo '<h2 class="mgseo-module-title" id="mgseo-module-' . esc_attr( $key ) . '-title">' . esc_html( $title ) . '</h2>';
		echo '<p class="mgseo-module-desc" id="mgseo-module-' . esc_attr( $key ) . '-desc">' . esc_html( Plugin::labels()[ $id ][1] ?? '' ) . '</p>';
		echo '<div class="mgseo-module-foot">';
		if ( $link ) {
			echo '<a class="mgseo-module-link" href="' . esc_url( $link['url'] ) . '">' . esc_html( $link['label'] ) . '<span class="screen-reader-text">: ' . esc_html( $title ) . '</span></a>';
		}
		echo '<label class="mgseo-switch">';
		echo '<span class="mgseo-switch-state" aria-hidden="true">' . esc_html( $checked ? __( 'On', 'mg-seo' ) : __( 'Off', 'mg-seo' ) ) . '</span>';
		echo '<input type="checkbox" role="switch" class="mgseo-switch-input" name="modules[' . esc_attr( $id ) . ']" value="1" aria-labelledby="mgseo-module-' . esc_attr( $key ) . '-title" aria-describedby="mgseo-module-' . esc_attr( $key ) . '-desc" ' . checked( $checked, true, false ) . disabled( $required || $missing, true, false ) . ' />';
		echo '<span class="mgseo-switch-track" aria-hidden="true"><span class="mgseo-switch-thumb"></span></span>';
		echo '</label>';
		echo '</div>';
		echo '</div>';
	}

	/**
	 * Where the card of an active module leads: its settings page, otherwise its first screen.
	 *
	 * @param string $id Module id.
	 * @return array{url: string, label: string}|null
	 */
	private function module_link( string $id ): ?array {
		$module = Plugin::instance()->module( $id );
		if ( ! $module ) {
			return null;
		}
		if ( $module->settings() && current_user_can( $module->capability() ) ) {
			return array(
				'url'   => admin_url( 'admin.php?page=mg-seo-' . $id ),
				'label' => __( 'Settings', 'mg-seo' ),
			);
		}
		foreach ( $module->admin_pages() as $page ) {
			if ( ! empty( $page['slug'] ) && current_user_can( $page['capability'] ?? 'mgseo_manage_settings' ) ) {
				return array(
					'url'   => admin_url( 'admin.php?page=' . $page['slug'] ),
					'label' => _x( 'Open', 'link to a module screen', 'mg-seo' ),
				);
			}
		}
		return null;
	}

	/**
	 * Display name of a module dependency.
	 *
	 * @param string $requires Dependency key.
	 */
	private static function dependency_name( string $requires ): string {
		return match ( $requires ) {
			'woocommerce' => 'WooCommerce',
			default       => $requires,
		};
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
