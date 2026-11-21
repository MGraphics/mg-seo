<?php
/**
 * Front-end request context.
 *
 * @package MGSEO
 */

namespace MGSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Resolves what the current front-end request represents.
 */
final class Context {

	public const FRONT_PAGE        = 'front_page';
	public const BLOG              = 'blog';
	public const SINGULAR          = 'singular';
	public const TERM              = 'term';
	public const AUTHOR            = 'author';
	public const POST_TYPE_ARCHIVE = 'post_type_archive';
	public const DATE              = 'date';
	public const SEARCH            = 'search';
	public const NOT_FOUND         = '404';
	public const OTHER             = 'other';

	/**
	 * Forced context (null = resolve from the main query).
	 *
	 * @var array{type: string, object: mixed}|null
	 */
	private static ?array $current = null;

	/**
	 * Current context: array( 'type' => one of the constants, 'object' => WP_Post|WP_Term|WP_User|WP_Post_Type|null ).
	 *
	 * @return array{type: string, object: mixed}
	 */
	public static function current(): array {
		if ( null !== self::$current ) {
			return self::$current;
		}
		return self::resolve();
	}

	/**
	 * Force a context (previews, tests). Pass null to resolve from the main query again.
	 *
	 * @param array{type: string, object: mixed}|null $context Context.
	 */
	public static function set( ?array $context ): void {
		self::$current = $context;
	}

	/**
	 * Resolve from the main query again.
	 */
	public static function reset(): void {
		self::$current = null;
	}

	/**
	 * Resolve the context from the main query.
	 *
	 * @return array{type: string, object: mixed}
	 */
	private static function resolve(): array {
		if ( is_404() ) {
			return array(
				'type'   => self::NOT_FOUND,
				'object' => null,
			);
		}
		if ( is_search() ) {
			return array(
				'type'   => self::SEARCH,
				'object' => null,
			);
		}
		if ( is_front_page() ) {
			if ( 'page' === get_option( 'show_on_front' ) && get_option( 'page_on_front' ) ) {
				return array(
					'type'   => self::FRONT_PAGE,
					'object' => get_post( (int) get_option( 'page_on_front' ) ),
				);
			}
			return array(
				'type'   => self::FRONT_PAGE,
				'object' => null,
			);
		}
		if ( is_home() ) {
			$page_for_posts = (int) get_option( 'page_for_posts' );
			return array(
				'type'   => self::BLOG,
				'object' => $page_for_posts ? get_post( $page_for_posts ) : null,
			);
		}
		if ( is_singular() ) {
			return array(
				'type'   => self::SINGULAR,
				'object' => get_queried_object(),
			);
		}
		if ( is_category() || is_tag() || is_tax() ) {
			return array(
				'type'   => self::TERM,
				'object' => get_queried_object(),
			);
		}
		if ( is_author() ) {
			return array(
				'type'   => self::AUTHOR,
				'object' => get_queried_object(),
			);
		}
		if ( is_post_type_archive() ) {
			$post_type = get_query_var( 'post_type' );
			$post_type = is_array( $post_type ) ? reset( $post_type ) : $post_type;
			return array(
				'type'   => self::POST_TYPE_ARCHIVE,
				'object' => get_post_type_object( (string) $post_type ),
			);
		}
		if ( is_date() ) {
			return array(
				'type'   => self::DATE,
				'object' => null,
			);
		}
		return array(
			'type'   => self::OTHER,
			'object' => null,
		);
	}

	/**
	 * Public post types handled by MG SEO.
	 *
	 * @return array<string, \WP_Post_Type>
	 */
	public static function post_types(): array {
		$types = get_post_types( array( 'public' => true ), 'objects' );
		unset( $types['attachment'] );
		/**
		 * Filter post types MG SEO manages.
		 *
		 * @param array $types Post type objects.
		 */
		return apply_filters( 'mgseo/post_types', $types );
	}

	/**
	 * Public taxonomies handled by MG SEO.
	 *
	 * @return array<string, \WP_Taxonomy>
	 */
	public static function taxonomies(): array {
		$taxonomies = get_taxonomies( array( 'public' => true ), 'objects' );
		unset( $taxonomies['post_format'] );
		/**
		 * Filter taxonomies MG SEO manages.
		 *
		 * @param array $taxonomies Taxonomy objects.
		 */
		return apply_filters( 'mgseo/taxonomies', $taxonomies );
	}

	/**
	 * Current page number for paginated views.
	 */
	public static function page_number(): int {
		$paged = (int) get_query_var( 'paged' );
		$page  = (int) get_query_var( 'page' );
		return max( 1, $paged, $page );
	}
}
