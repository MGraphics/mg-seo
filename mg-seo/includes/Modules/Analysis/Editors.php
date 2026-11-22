<?php
/**
 * Score and hints inside page builders.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Analysis;

use MGSEO\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Loads the shared SEO panel (assets/seo-ui.js) where people edit pages:
 * - Elementor: an "SEO · 68" tab next to Widgets and Globals plus the score in the top bar (assets/elementor.js);
 * - Beaver Builder, Divi, Bricks, WPBakery front-end editor: a floating "SEO 68" button that opens the same
 *   panel (assets/builders.js), because these editors hide the WordPress admin bar or cover it.
 * The live score is calculated from the HTML the builder renders on screen.
 */
final class Editors {

	/**
	 * Register hooks.
	 */
	public static function boot(): void {
		add_action( 'elementor/editor/after_enqueue_scripts', array( self::class, 'elementor' ) );
		add_action( 'elementor/editor/after_enqueue_styles', array( self::class, 'styles' ) );
		add_action( 'wp_enqueue_scripts', array( self::class, 'front_builders' ), 100 );
		add_action( 'admin_enqueue_scripts', array( self::class, 'admin_builders' ), 100 );
		add_filter( 'mgseo/analysis/admin_bar_hints_url', array( self::class, 'hints_url' ), 10, 2 );
	}

	/**
	 * Register the shared UI script and style.
	 */
	public static function register(): void {
		if ( ! wp_script_is( 'mgseo-ui', 'registered' ) ) {
			wp_register_script( 'mgseo-ui', MGSEO_URL . 'assets/seo-ui.js', array( 'wp-api-fetch', 'wp-i18n' ), MGSEO_VERSION, true );
			wp_set_script_translations( 'mgseo-ui', 'mg-seo', MGSEO_DIR . 'languages' );
		}
		if ( ! wp_style_is( 'mgseo-ui', 'registered' ) ) {
			wp_register_style( 'mgseo-ui', MGSEO_URL . 'assets/seo-ui.css', array(), MGSEO_VERSION );
		}
	}

	/**
	 * Shared style in the Elementor editor.
	 */
	public static function styles(): void {
		self::register();
		wp_enqueue_style( 'mgseo-ui' );
	}

	/**
	 * Elementor editor: SEO tab and top bar score.
	 */
	public static function elementor(): void {
		$post = self::editable_post( isset( $_GET['post'] ) ? absint( wp_unslash( $_GET['post'] ) ) : 0 ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- editor screen.
		if ( ! $post ) {
			return;
		}
		self::register();
		wp_enqueue_style( 'mgseo-ui' );
		wp_enqueue_script( 'mgseo-elementor', MGSEO_URL . 'assets/elementor.js', array( 'mgseo-ui', 'react', 'jquery' ), MGSEO_VERSION, true );
		wp_set_script_translations( 'mgseo-elementor', 'mg-seo', MGSEO_DIR . 'languages' );
		wp_localize_script( 'mgseo-elementor', 'mgseoBuilder', self::config( $post, Builders::ELEMENTOR ) );
	}

	/**
	 * Front-end builders (Beaver Builder, Divi visual builder, Bricks): floating button and panel.
	 */
	public static function front_builders(): void {
		if ( ! is_singular() || ! is_user_logged_in() ) {
			return;
		}
		$builder = '';
		if ( class_exists( '\FLBuilderModel' ) && method_exists( '\FLBuilderModel', 'is_builder_active' ) && \FLBuilderModel::is_builder_active() ) {
			$builder = Builders::BEAVER;
		} elseif ( function_exists( 'et_core_is_fb_enabled' ) && et_core_is_fb_enabled() ) {
			$builder = Builders::DIVI;
		} elseif ( function_exists( 'bricks_is_builder_main' ) && bricks_is_builder_main() ) {
			$builder = Builders::BRICKS;
		}
		if ( '' === $builder ) {
			return;
		}
		$post = self::editable_post( (int) get_queried_object_id() );
		if ( $post ) {
			self::floating( $post, $builder );
		}
	}

	/**
	 * WPBakery front-end editor runs in wp-admin (post.php?vc_action=vc_inline).
	 */
	public static function admin_builders(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- editor screen detection.
		if ( ! isset( $_GET['vc_action'] ) || 'vc_inline' !== sanitize_key( wp_unslash( $_GET['vc_action'] ) ) ) {
			return;
		}
		$post = self::editable_post( absint( wp_unslash( $_GET['post_id'] ?? ( $_GET['post'] ?? 0 ) ) ) );
		// phpcs:enable
		if ( $post ) {
			self::floating( $post, Builders::WPBAKERY );
		}
	}

	/**
	 * Enqueue the floating panel.
	 *
	 * @param \WP_Post $post    Post.
	 * @param string   $builder Builder id.
	 */
	private static function floating( \WP_Post $post, string $builder ): void {
		self::register();
		wp_enqueue_style( 'mgseo-ui' );
		wp_enqueue_script( 'mgseo-builders', MGSEO_URL . 'assets/builders.js', array( 'mgseo-ui' ), MGSEO_VERSION, true );
		wp_set_script_translations( 'mgseo-builders', 'mg-seo', MGSEO_DIR . 'languages' );
		wp_localize_script( 'mgseo-builders', 'mgseoBuilder', self::config( $post, $builder ) );
	}

	/**
	 * Editor config plus where the builder renders the page.
	 *
	 * @param \WP_Post $post    Post.
	 * @param string   $builder Builder id.
	 */
	public static function config( \WP_Post $post, string $builder ): array {
		$config            = Module::client_config( $post );
		$config['builder'] = $builder;
		$config['tested']  = in_array( $builder, array( Builders::ELEMENTOR, Builders::BEAVER ), true );
		// Where the rendered page is (frame = iframe selector, '' = this document) and builder UI to leave out.
		$config['canvas'] = match ( $builder ) {
			Builders::ELEMENTOR => array(
				'frame'   => '#elementor-preview-iframe',
				'content' => '[data-elementor-id="' . $post->ID . '"]',
				'strip'   => '.elementor-editor-element-settings, .elementor-element-overlay, .elementor-add-section, #elementor-add-new-section, .elementor-empty-view, .elementor-add-section-inline, .e-con-select-preset',
			),
			Builders::BEAVER    => array(
				'frame'   => '',
				'content' => '.fl-builder-content-' . $post->ID . ', .fl-builder-content[data-post-id="' . $post->ID . '"]',
				'strip'   => '.fl-block-overlay, .fl-row-overlay, .fl-col-overlay, .fl-module-overlay, .fl-drop-target, .fl-builder-node-loading-placeholder, .fl-builder-block-insert',
			),
			Builders::DIVI      => array(
				'frame'   => '',
				'content' => '.et-l--post, #et-boc',
				'strip'   => '.et-fb-component-settings, .et_fb_ignore, [class*="et-fb-button"], .et-fb-skeleton',
			),
			Builders::BRICKS    => array(
				'frame'   => '#bricks-builder-iframe',
				'content' => '#brx-content',
				'strip'   => '.bricks-element-actions, .bricks-draggable-placeholder',
			),
			Builders::WPBAKERY  => array(
				'frame'   => '#vc_inline-frame',
				'content' => '.vc_container-anchor, #vc_inline-anchor, .entry-content',
				'strip'   => '.vc_controls, .vc_empty-placeholder, .vc_welcome',
			),
			default             => array(
				'frame'   => '',
				'content' => '',
				'strip'   => '',
			),
		};
		return $config;
	}

	/**
	 * Admin bar "Open SEO hints" for builder pages: open the builder with the SEO panel.
	 *
	 * @param string   $url  Default URL.
	 * @param \WP_Post $post Post.
	 */
	public static function hints_url( string $url, \WP_Post $post ): string {
		$builder = Builders::detect( $post );
		if ( Builders::ELEMENTOR === $builder ) {
			$document = \Elementor\Plugin::$instance->documents->get( $post->ID );
			return $document ? add_query_arg( 'mgseo', 'open', $document->get_edit_url() ) : $url;
		}
		if ( Builders::BEAVER === $builder ) {
			return add_query_arg(
				array(
					'fl_builder' => '',
					'mgseo'      => 'open',
				),
				(string) get_permalink( $post )
			);
		}
		return $url;
	}

	/**
	 * Post the current user may edit SEO data of, in a managed post type.
	 *
	 * @param int $post_id Post id.
	 */
	private static function editable_post( int $post_id ): ?\WP_Post {
		$post = $post_id > 0 ? get_post( $post_id ) : null;
		if ( ! $post || ! isset( Context::post_types()[ $post->post_type ] ) || ! current_user_can( 'mgseo_edit_meta' ) || ! current_user_can( 'edit_post', $post->ID ) ) {
			return null;
		}
		return $post;
	}
}
