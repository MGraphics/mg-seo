<?php
/**
 * Page builder detection and rendering for the analysis.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Analysis;

defined( 'ABSPATH' ) || exit;

/**
 * Page builders keep their content outside `post_content` (Elementor, Beaver Builder, Bricks) or in shortcodes
 * that are only registered on the front end (Divi 4, WPBakery). The analysis must read what they render.
 *
 * Tested with the free versions: Elementor, Beaver Builder Lite. Bricks, Divi and WPBakery have no free
 * version; their support follows their documented functions and is marked untested.
 */
final class Builders {

	public const ELEMENTOR = 'elementor';
	public const BEAVER    = 'beaver';
	public const BRICKS    = 'bricks';
	public const DIVI      = 'divi';
	public const WPBAKERY  = 'wpbakery';

	/**
	 * Builder used for a post ('' = none detected).
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function detect( \WP_Post $post ): string {
		$builder = '';
		if ( self::is_elementor( $post->ID ) ) {
			$builder = self::ELEMENTOR;
		} elseif ( class_exists( '\FLBuilderModel' ) && method_exists( '\FLBuilderModel', 'is_builder_enabled' ) && \FLBuilderModel::is_builder_enabled( $post->ID ) ) {
			$builder = self::BEAVER;
		} elseif ( defined( 'BRICKS_VERSION' ) && 'bricks' === get_post_meta( $post->ID, '_bricks_editor_mode', true ) ) {
			$builder = self::BRICKS;
		} elseif ( function_exists( 'et_pb_is_pagebuilder_used' ) && et_pb_is_pagebuilder_used( $post->ID ) ) {
			$builder = self::DIVI;
		} elseif ( defined( 'WPB_VC_VERSION' ) && str_contains( (string) $post->post_content, '[vc_' ) ) {
			$builder = self::WPBAKERY;
		}
		/**
		 * Filter the page builder detected for a post (return '' to analyse post_content).
		 *
		 * @param string   $builder Builder id or ''.
		 * @param \WP_Post $post    Post.
		 */
		return (string) apply_filters( 'mgseo/analysis/builder', $builder, $post );
	}

	/**
	 * Rendered HTML of a builder post, null when the builder cannot render it here.
	 *
	 * @param \WP_Post $post    Post.
	 * @param string   $builder Builder id.
	 */
	public static function render( \WP_Post $post, string $builder ): ?string {
		/**
		 * Short-circuit builder rendering (return a string to use it as the analysed HTML).
		 *
		 * @param string|null $html    Null to use the built-in renderer.
		 * @param \WP_Post    $post    Post.
		 * @param string      $builder Builder id.
		 */
		$html = apply_filters( 'mgseo/analysis/builder_content', null, $post, $builder );
		if ( is_string( $html ) ) {
			return $html;
		}
		try {
			$html = match ( $builder ) {
				self::ELEMENTOR => self::render_elementor( $post ),
				self::BEAVER    => self::render_beaver( $post ),
				self::BRICKS    => self::render_bricks( $post ),
				self::DIVI      => self::render_shortcodes( $post, self::DIVI ),
				self::WPBAKERY  => self::render_shortcodes( $post, self::WPBAKERY ),
				default         => null,
			};
		} catch ( \Throwable $e ) {
			$html = null;
		}
		return is_string( $html ) && '' !== trim( wp_strip_all_tags( $html ) ) ? $html : null;
	}

	/**
	 * Builders active on this site (for the editor integrations).
	 *
	 * @return string[]
	 */
	public static function active(): array {
		$active = array();
		if ( did_action( 'elementor/loaded' ) ) {
			$active[] = self::ELEMENTOR;
		}
		if ( class_exists( '\FLBuilderModel' ) ) {
			$active[] = self::BEAVER;
		}
		if ( defined( 'BRICKS_VERSION' ) ) {
			$active[] = self::BRICKS;
		}
		if ( function_exists( 'et_setup_theme' ) || defined( 'ET_BUILDER_PLUGIN_VERSION' ) || defined( 'ET_BUILDER_VERSION' ) ) {
			$active[] = self::DIVI;
		}
		if ( defined( 'WPB_VC_VERSION' ) ) {
			$active[] = self::WPBAKERY;
		}
		return $active;
	}

	/**
	 * Whether a post is built with Elementor.
	 *
	 * @param int $post_id Post id.
	 */
	public static function is_elementor( int $post_id ): bool {
		if ( ! did_action( 'elementor/loaded' ) || ! class_exists( '\Elementor\Plugin' ) ) {
			return false;
		}
		$plugin = \Elementor\Plugin::$instance;
		if ( ! $plugin || ! isset( $plugin->documents ) ) {
			return 'builder' === get_post_meta( $post_id, '_elementor_edit_mode', true );
		}
		$document = $plugin->documents->get( $post_id );
		return $document && method_exists( $document, 'is_built_with_elementor' ) && $document->is_built_with_elementor();
	}

	/**
	 * Elementor: the builder content as rendered on the front end (without the page wrapper CSS).
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function render_elementor( \WP_Post $post ): ?string {
		$plugin = \Elementor\Plugin::$instance;
		if ( ! $plugin || ! isset( $plugin->frontend ) ) {
			return null;
		}
		$previous = $GLOBALS['post'] ?? null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- Elementor reads the global post; restored below.
		$GLOBALS['post'] = $post;
		try {
			$html = (string) $plugin->frontend->get_builder_content( $post->ID, false );
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore.
			$GLOBALS['post'] = $previous;
		}
		return $html;
	}

	/**
	 * Beaver Builder: render the layout by post id.
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function render_beaver( \WP_Post $post ): ?string {
		if ( ! class_exists( '\FLBuilder' ) || ! method_exists( '\FLBuilder', 'render_content_by_id' ) ) {
			return null;
		}
		ob_start();
		try {
			\FLBuilder::render_content_by_id( $post->ID );
		} finally {
			$html = (string) ob_get_clean();
		}
		return $html;
	}

	/**
	 * Bricks (untested, no free version): render the stored page elements.
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function render_bricks( \WP_Post $post ): ?string {
		$key      = defined( 'BRICKS_DB_PAGE_CONTENT' ) ? constant( 'BRICKS_DB_PAGE_CONTENT' ) : '_bricks_page_content_2';
		$elements = get_post_meta( $post->ID, $key, true );
		if ( ! is_array( $elements ) || ! $elements ) {
			return null;
		}
		if ( class_exists( '\Bricks\Frontend' ) && method_exists( '\Bricks\Frontend', 'render_data' ) ) {
			return (string) \Bricks\Frontend::render_data( $elements );
		}
		// Fallback: text settings of the elements.
		$html = '';
		foreach ( $elements as $element ) {
			$settings = (array) ( $element['settings'] ?? array() );
			$tag      = (string) ( $settings['tag'] ?? '' );
			foreach ( array( 'text', 'title', 'content' ) as $field ) {
				if ( isset( $settings[ $field ] ) && is_string( $settings[ $field ] ) ) {
					$wrap  = preg_match( '/^h[1-6]$/', $tag ) ? $tag : 'p';
					$html .= '<' . $wrap . '>' . $settings[ $field ] . '</' . $wrap . '>';
				}
			}
		}
		return $html;
	}

	/**
	 * Divi 4 and WPBakery (untested, no free versions): load their shortcodes, then render the content.
	 *
	 * @param \WP_Post $post    Post.
	 * @param string   $builder Builder id.
	 */
	private static function render_shortcodes( \WP_Post $post, string $builder ): ?string {
		if ( self::WPBAKERY === $builder && class_exists( '\WPBMap' ) && method_exists( '\WPBMap', 'addAllMappedShortcodes' ) ) {
			\WPBMap::addAllMappedShortcodes();
		}
		if ( self::DIVI === $builder && ! shortcode_exists( 'et_pb_section' ) && function_exists( 'et_builder_add_main_elements' ) ) {
			et_builder_add_main_elements();
		}
		return Content::filters( $post, (string) $post->post_content );
	}
}
