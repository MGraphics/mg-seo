<?php
/**
 * The HTML that is analysed: rendered content, the way visitors and Google see it.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Analysis;

defined( 'ABSPATH' ) || exit;

/**
 * One pipeline for live analysis in the editors and for stored scores, so both give the same number:
 * - posts built with a page builder (Elementor, Beaver Builder, Bricks, Divi, WPBakery, see Builders)
 *   are rendered by that builder;
 * - everything else (and unsaved editor content) goes through the `the_content` filters (blocks,
 *   shortcodes, paragraphs), with the post set up as the global post.
 * Text that builders keep in shortcode attributes is recovered when a shortcode cannot be rendered.
 */
final class Content {

	/**
	 * Recursion guard (a the_content filter may ask for the analysis again).
	 *
	 * @var bool
	 */
	private static bool $rendering = false;

	/**
	 * Rendered HTML of a post.
	 *
	 * @param \WP_Post    $post Post.
	 * @param string|null $raw  Unsaved content from an editor; null = the saved content.
	 */
	public static function render( \WP_Post $post, ?string $raw = null ): string {
		$html = null;
		if ( null === $raw || $raw === $post->post_content ) {
			$builder = Builders::detect( $post );
			if ( '' !== $builder ) {
				$html = Builders::render( $post, $builder );
			}
		}
		if ( null === $html ) {
			$html = self::filters( $post, null === $raw ? (string) $post->post_content : $raw );
		}
		$html = self::shortcode_text( $html );
		/**
		 * Filter the HTML that is analysed for a post (page builders can return their rendered output).
		 *
		 * @param string   $content Rendered content.
		 * @param \WP_Post $post    Post.
		 */
		return (string) apply_filters( 'mgseo/analysis_content', $html, $post );
	}

	/**
	 * Run content through the `the_content` filters with the post as the global post.
	 *
	 * @param \WP_Post $post    Post.
	 * @param string   $content Raw content.
	 */
	public static function filters( \WP_Post $post, string $content ): string {
		/**
		 * Whether to render content through all `the_content` filters (false = blocks and shortcodes only).
		 *
		 * @param bool     $full Default true.
		 * @param \WP_Post $post Post.
		 */
		$full = (bool) apply_filters( 'mgseo/analysis/the_content', true, $post );
		if ( self::$rendering ) {
			return do_shortcode( do_blocks( $content ) );
		}
		self::$rendering = true;
		$previous        = $GLOBALS['post'] ?? null;
		// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- temporary, restored below.
		$GLOBALS['post'] = $post;
		setup_postdata( $post );
		try {
			// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core filter: content as rendered on the front end.
			$html = $full ? (string) apply_filters( 'the_content', $content ) : do_shortcode( do_blocks( $content ) );
		} catch ( \Throwable $e ) {
			$html = do_shortcode( do_blocks( $content ) );
		} finally {
			// phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore.
			$GLOBALS['post'] = $previous;
			if ( $previous instanceof \WP_Post ) {
				setup_postdata( $previous );
			} else {
				wp_reset_postdata();
			}
			self::$rendering = false;
		}
		return $html;
	}

	/**
	 * Keep text stored in attributes of shortcodes that were not rendered (WPBakery, Divi 4 and others
	 * when their shortcodes are not loaded): `[vc_custom_heading text="Physiotherapy"]` → "Physiotherapy".
	 *
	 * @param string $html HTML.
	 */
	public static function shortcode_text( string $html ): string {
		if ( ! str_contains( $html, '[' ) ) {
			return $html;
		}
		$html = (string) preg_replace( '/\[\/[a-zA-Z][\w-]*\]/', ' ', $html );
		return (string) preg_replace_callback(
			'/\[(?!\/)([a-zA-Z][\w-]*)(\s[^\]]*)?\]/',
			static function ( array $m ): string {
				$attrs = isset( $m[2] ) ? shortcode_parse_atts( $m[2] ) : array();
				$texts = array();
				foreach ( (array) $attrs as $key => $value ) {
					if ( is_string( $key ) && preg_match( '/^(title|text|heading|subtitle|sub_title|content|caption|button_text|label|description|alt|admin_label_text)$/', $key ) && '' !== trim( (string) $value ) ) {
						$value   = rawurldecode( (string) $value );
						$texts[] = 'alt' === $key ? '<img alt="' . esc_attr( wp_strip_all_tags( $value ) ) . '" />' : '<p>' . wp_strip_all_tags( $value ) . '</p>';
					}
				}
				return $texts ? ' ' . implode( ' ', $texts ) . ' ' : ' ';
			},
			$html
		);
	}
}
