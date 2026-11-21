<?php
/**
 * Breadcrumbs.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Breadcrumbs;

use MGSEO\Context;
use MGSEO\Head;
use MGSEO\Helpers;
use MGSEO\Meta;
use MGSEO\Module as Base;
use MGSEO\Options;
use MGSEO\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Breadcrumb trail, shortcode, block, template functions and BreadcrumbList schema.
 */
final class Module extends Base {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'breadcrumbs';
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_shortcode( 'mgseo_breadcrumbs', array( self::class, 'shortcode' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_action( 'mgseo/head', array( $this, 'standalone_schema' ), 90 );
		add_action( 'init', array( $this, 'compat' ), 1 );
	}

	/**
	 * Settings.
	 */
	public function settings(): array {
		return array(
			array(
				'id'          => 'general',
				'title'       => __( 'Breadcrumbs', 'mg-seo' ),
				'description' => __( 'Show breadcrumbs with the [mgseo_breadcrumbs] shortcode, the Breadcrumbs block or mgseo_breadcrumbs() in a theme.', 'mg-seo' ),
				'fields'      => array(
					array(
						'id'      => 'separator',
						'type'    => 'text',
						'label'   => __( 'Separator', 'mg-seo' ),
						'default' => '»',
					),
					array(
						'id'           => 'show_home',
						'type'         => 'toggle',
						'label'        => __( 'Home link', 'mg-seo' ),
						'toggle_label' => __( 'Show the homepage as the first item', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'      => 'home_label',
						'type'    => 'text',
						'label'   => __( 'Home label', 'mg-seo' ),
						'default' => __( 'Home', 'mg-seo' ),
					),
					array(
						'id'      => 'prefix',
						'type'    => 'text',
						'label'   => __( 'Prefix', 'mg-seo' ),
						'default' => '',
						'desc'    => __( 'Text before the trail, e.g. "You are here:".', 'mg-seo' ),
					),
					array(
						'id'      => 'archive_format',
						'type'    => 'text',
						'label'   => __( 'Archive format', 'mg-seo' ),
						/* translators: %s: archive name placeholder, keep %s */
						'default' => __( 'Archives for %s', 'mg-seo' ),
					),
					array(
						'id'      => 'search_format',
						'type'    => 'text',
						'label'   => __( 'Search results format', 'mg-seo' ),
						/* translators: %s: search query placeholder, keep %s */
						'default' => __( 'Results for %s', 'mg-seo' ),
					),
					array(
						'id'      => 'label_404',
						'type'    => 'text',
						'label'   => __( '404 label', 'mg-seo' ),
						'default' => __( 'Page not found', 'mg-seo' ),
					),
					array(
						'id'           => 'show_taxonomy',
						'type'         => 'toggle',
						'label'        => __( 'Category in post trail', 'mg-seo' ),
						'toggle_label' => __( 'Show the primary category (with parents) for posts', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'           => 'show_blog',
						'type'         => 'toggle',
						'label'        => __( 'Posts page', 'mg-seo' ),
						'toggle_label' => __( 'Show the posts page in post trails (when a static front page is set)', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'           => 'show_pt_archive',
						'type'         => 'toggle',
						'label'        => __( 'Post type archive', 'mg-seo' ),
						'toggle_label' => __( 'Show the custom post type archive in trails', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'           => 'hide_current',
						'type'         => 'toggle',
						'label'        => __( 'Current page', 'mg-seo' ),
						'toggle_label' => __( 'Hide the current page title from the visible trail (still in schema)', 'mg-seo' ),
						'default'      => false,
					),
					array(
						'id'      => 'css_class',
						'type'    => 'text',
						'label'   => __( 'CSS class', 'mg-seo' ),
						'default' => 'breadcrumbs',
						'desc'    => __( 'Class of the <nav> element. Keep it generic in no-fingerprint mode.', 'mg-seo' ),
					),
					array(
						'id'           => 'compat',
						'type'         => 'toggle',
						'label'        => __( 'Theme compatibility', 'mg-seo' ),
						'toggle_label' => __( 'Answer Rank Math and Yoast breadcrumb functions and shortcodes when those plugins are not active', 'mg-seo' ),
						'default'      => true,
					),
				),
			),
		);
	}

	/**
	 * Breadcrumb items for the current request.
	 *
	 * @return array<int, array{text: string, url: string}>
	 */
	public static function trail(): array {
		$ctx   = Context::current();
		$obj   = $ctx['object'];
		$items = array();
		$opt   = static fn( string $key, mixed $fallback = null ) => Options::get( 'breadcrumbs', $key, $fallback );

		if ( $opt( 'show_home', true ) ) {
			$items[] = array(
				'text' => (string) $opt( 'home_label', __( 'Home', 'mg-seo' ) ),
				'url'  => home_url( '/' ),
			);
		}

		switch ( $ctx['type'] ) {
			case Context::FRONT_PAGE:
				break;
			case Context::BLOG:
				if ( $obj instanceof \WP_Post ) {
					$items[] = self::post_item( $obj );
				}
				break;
			case Context::SINGULAR:
				if ( $obj instanceof \WP_Post ) {
					$items   = array_merge( $items, self::post_parents( $obj ) );
					$items[] = self::post_item( $obj );
				}
				break;
			case Context::TERM:
				if ( $obj instanceof \WP_Term ) {
					$items   = array_merge( $items, self::term_ancestors( $obj ) );
					$items[] = self::term_item( $obj );
				}
				break;
			case Context::AUTHOR:
				if ( $obj instanceof \WP_User ) {
					$items[] = array(
						'text' => sprintf( (string) $opt( 'archive_format', 'Archives for %s' ), $obj->display_name ),
						'url'  => get_author_posts_url( $obj->ID ),
					);
				}
				break;
			case Context::POST_TYPE_ARCHIVE:
				if ( $obj instanceof \WP_Post_Type ) {
					$items[] = array(
						'text' => $obj->labels->name,
						'url'  => (string) get_post_type_archive_link( $obj->name ),
					);
				}
				break;
			case Context::DATE:
				$year  = (int) get_query_var( 'year' );
				$month = (int) get_query_var( 'monthnum' );
				if ( $year ) {
					$items[] = array(
						'text' => (string) $year,
						'url'  => get_year_link( $year ),
					);
				}
				if ( $month ) {
					$items[] = array(
						'text' => (string) wp_date( 'F', (int) mktime( 0, 0, 0, $month, 1, $year ? $year : (int) gmdate( 'Y' ) ) ),
						'url'  => get_month_link( $year, $month ),
					);
				}
				if ( is_day() ) {
					$items[] = array(
						'text' => (string) (int) get_query_var( 'day' ),
						'url'  => get_day_link( $year, $month, (int) get_query_var( 'day' ) ),
					);
				}
				break;
			case Context::SEARCH:
				$items[] = array(
					'text' => sprintf( (string) $opt( 'search_format', 'Results for %s' ), get_search_query( false ) ),
					'url'  => get_search_link(),
				);
				break;
			case Context::NOT_FOUND:
				$items[] = array(
					'text' => (string) $opt( 'label_404', __( 'Page not found', 'mg-seo' ) ),
					'url'  => '',
				);
				break;
		}

		$page = Context::page_number();
		if ( $page > 1 && ! is_singular() ) {
			$items[] = array(
				/* translators: %d: page number */
				'text' => sprintf( __( 'Page %d', 'mg-seo' ), $page ),
				'url'  => Helpers::current_url(),
			);
		}

		/**
		 * Filter breadcrumb items.
		 *
		 * @param array $items Items (text, url).
		 * @param array $ctx   Context.
		 */
		return apply_filters( 'mgseo/breadcrumbs', $items, $ctx );
	}

	/**
	 * Crumb for a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{text: string, url: string}
	 */
	private static function post_item( \WP_Post $post ): array {
		$title = (string) Meta::post( $post->ID, 'breadcrumb_title' );
		return array(
			'text' => '' !== $title ? $title : wp_strip_all_tags( get_the_title( $post ) ),
			'url'  => (string) get_permalink( $post ),
		);
	}

	/**
	 * Crumb for a term.
	 *
	 * @param \WP_Term $term Term.
	 * @return array{text: string, url: string}
	 */
	private static function term_item( \WP_Term $term ): array {
		$link = get_term_link( $term );
		return array(
			'text' => $term->name,
			'url'  => is_wp_error( $link ) ? '' : $link,
		);
	}

	/**
	 * Ancestors of a term.
	 *
	 * @param \WP_Term $term Term.
	 * @return array<int, array{text: string, url: string}>
	 */
	private static function term_ancestors( \WP_Term $term ): array {
		$items = array();
		foreach ( array_reverse( get_ancestors( $term->term_id, $term->taxonomy, 'taxonomy' ) ) as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, $term->taxonomy );
			if ( $ancestor instanceof \WP_Term ) {
				$items[] = self::term_item( $ancestor );
			}
		}
		return $items;
	}

	/**
	 * Items between home and a post: blog page / post type archive / primary term / page ancestors.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<int, array{text: string, url: string}>
	 */
	private static function post_parents( \WP_Post $post ): array {
		$items = array();
		$opt   = static fn( string $key, mixed $fallback = null ) => Options::get( 'breadcrumbs', $key, $fallback );
		$type  = get_post_type_object( $post->post_type );

		if ( 'post' === $post->post_type && $opt( 'show_blog', true ) && 'page' === get_option( 'show_on_front' ) ) {
			$blog = (int) get_option( 'page_for_posts' );
			if ( $blog && get_post( $blog ) ) {
				$items[] = self::post_item( get_post( $blog ) );
			}
		} elseif ( $type && $type->has_archive && $opt( 'show_pt_archive', true ) ) {
			$items[] = array(
				'text' => $type->labels->name,
				'url'  => (string) get_post_type_archive_link( $post->post_type ),
			);
		}

		if ( is_post_type_hierarchical( $post->post_type ) ) {
			foreach ( array_reverse( get_post_ancestors( $post ) ) as $ancestor_id ) {
				$ancestor = get_post( $ancestor_id );
				if ( $ancestor ) {
					$items[] = self::post_item( $ancestor );
				}
			}
			return $items;
		}

		if ( $opt( 'show_taxonomy', true ) ) {
			$taxonomy = self::main_taxonomy( $post->post_type );
			if ( $taxonomy ) {
				$term = Helpers::primary_term( $post, $taxonomy, true );
				if ( $term ) {
					$items   = array_merge( $items, self::term_ancestors( $term ) );
					$items[] = self::term_item( $term );
				}
			}
		}
		return $items;
	}

	/**
	 * Taxonomy used for a post type's trail.
	 *
	 * @param string $post_type Post type.
	 */
	private static function main_taxonomy( string $post_type ): string {
		$map      = array(
			'post'    => 'category',
			'product' => 'product_cat',
		);
		$taxonomy = $map[ $post_type ] ?? '';
		if ( '' === $taxonomy ) {
			foreach ( get_object_taxonomies( $post_type, 'objects' ) as $tax ) {
				if ( $tax->hierarchical && $tax->public ) {
					$taxonomy = $tax->name;
					break;
				}
			}
		}
		/**
		 * Filter the taxonomy shown in a post type's breadcrumb trail.
		 *
		 * @param string $taxonomy  Taxonomy ('' for none).
		 * @param string $post_type Post type.
		 */
		return (string) apply_filters( 'mgseo/breadcrumbs_taxonomy', $taxonomy, $post_type );
	}

	/**
	 * Breadcrumb HTML.
	 *
	 * @param array $args wrap_before, wrap_after, separator, class.
	 */
	public static function html( array $args = array() ): string {
		$items = self::trail();
		if ( count( $items ) < 2 && ! is_front_page() ) {
			return '';
		}
		if ( is_front_page() ) {
			return '';
		}
		$sep    = $args['separator'] ?? (string) Options::get( 'breadcrumbs', 'separator', '»' );
		$class  = $args['class'] ?? (string) Options::get( 'breadcrumbs', 'css_class', 'breadcrumbs' );
		$prefix = (string) Options::get( 'breadcrumbs', 'prefix', '' );
		$last   = count( $items ) - 1;
		$parts  = array();
		foreach ( $items as $i => $item ) {
			if ( $i === $last ) {
				if ( Options::get( 'breadcrumbs', 'hide_current', false ) ) {
					continue;
				}
				$parts[] = '<span aria-current="page">' . esc_html( $item['text'] ) . '</span>';
				continue;
			}
			$parts[] = '' !== $item['url'] ? '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['text'] ) . '</a>' : '<span>' . esc_html( $item['text'] ) . '</span>';
		}
		$html  = $args['wrap_before'] ?? '';
		$html .= '<nav' . ( '' !== $class ? ' class="' . esc_attr( $class ) . '"' : '' ) . ' aria-label="' . esc_attr__( 'Breadcrumbs', 'mg-seo' ) . '"><p>';
		if ( '' !== $prefix ) {
			$html .= '<span>' . esc_html( $prefix ) . '</span> ';
		}
		$html .= implode( ' <span aria-hidden="true">' . esc_html( $sep ) . '</span> ', $parts );
		$html .= '</p></nav>';
		$html .= $args['wrap_after'] ?? '';
		return $html;
	}

	/**
	 * BreadcrumbList schema node (used by the Schema module graph).
	 *
	 * @return array<string, mixed>|null
	 */
	public static function schema(): ?array {
		$items = self::trail();
		if ( count( $items ) < 2 ) {
			return null;
		}
		$list = array();
		$pos  = 1;
		foreach ( $items as $item ) {
			$entry = array(
				'@type'    => 'ListItem',
				'position' => $pos++,
				'name'     => $item['text'],
			);
			if ( '' !== $item['url'] ) {
				$entry['item'] = $item['url'];
			}
			$list[] = $entry;
		}
		return array(
			'@type'           => 'BreadcrumbList',
			'@id'             => Helpers::current_url() . '#breadcrumb',
			'itemListElement' => $list,
		);
	}

	/**
	 * Output BreadcrumbList JSON-LD when the Schema module is off (it includes it in its graph otherwise).
	 */
	public function standalone_schema(): void {
		if ( Plugin::instance()->module( 'schema' ) || is_front_page() ) {
			return;
		}
		$node = self::schema();
		if ( $node ) {
			Head::json_ld( array( '@context' => 'https://schema.org' ) + $node );
		}
	}

	/**
	 * Shortcode handler.
	 *
	 * @param array|string $atts Attributes.
	 */
	public static function shortcode( $atts = array() ): string {
		$atts = shortcode_atts(
			array(
				'separator' => null,
				'class'     => null,
			),
			(array) $atts
		);
		return self::html( array_filter( $atts, static fn( $v ) => null !== $v ) );
	}

	/**
	 * Server-rendered block.
	 */
	public function register_block(): void {
		register_block_type(
			'mg-seo/breadcrumbs',
			array(
				'api_version'     => 3,
				'title'           => __( 'Breadcrumbs', 'mg-seo' ),
				'category'        => 'theme',
				'render_callback' => static fn() => self::html(),
				'supports'        => array(
					'html'       => false,
					'typography' => array( 'fontSize' => true ),
					'spacing'    => array( 'margin' => true ),
				),
			)
		);
	}

	/**
	 * Theme compatibility with other SEO plugins' breadcrumb calls (only when they are inactive).
	 */
	public function compat(): void {
		if ( ! Options::get( 'breadcrumbs', 'compat', true ) ) {
			return;
		}
		if ( ! defined( 'RANK_MATH_VERSION' ) && ! class_exists( 'RankMath' ) ) {
			if ( ! shortcode_exists( 'rank_math_breadcrumb' ) ) {
				add_shortcode( 'rank_math_breadcrumb', array( self::class, 'shortcode' ) );
			}
			require_once __DIR__ . '/compat-rank-math.php';
		}
		if ( ! defined( 'WPSEO_VERSION' ) ) {
			if ( ! shortcode_exists( 'wpseo_breadcrumb' ) ) {
				add_shortcode( 'wpseo_breadcrumb', array( self::class, 'shortcode' ) );
			}
			require_once __DIR__ . '/compat-yoast.php';
		}
	}
}
