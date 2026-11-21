<?php
/**
 * Removal of the /product/ and /product-category/ bases.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\WooCommerce;

use MGSEO\Helpers;
use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Short product and category URLs without extra rewrite rules: links are rewritten, and requests that
 * WordPress could not resolve to existing content are mapped to products/categories in the `request` filter.
 * Existing pages and posts always win; a product or category whose short URL would collide keeps its base.
 * Old URLs (with the base) are redirected with 301.
 */
final class Permalinks {

	/**
	 * Conflict cache.
	 *
	 * @var array<string, bool>
	 */
	private static array $conflicts = array();

	/**
	 * Register hooks.
	 */
	public static function boot(): void {
		add_filter( 'post_type_link', array( self::class, 'product_link' ), 20, 2 );
		add_filter( 'term_link', array( self::class, 'term_link' ), 20, 3 );
		add_filter( 'request', array( self::class, 'request' ), 20 );
		add_action( 'template_redirect', array( self::class, 'redirect_old' ), 5 );
	}

	/**
	 * Whether the product base is removed.
	 */
	public static function remove_product_base(): bool {
		return (bool) Options::get( 'woocommerce', 'remove_product_base', false ) && get_option( 'permalink_structure' );
	}

	/**
	 * Whether the category base is removed.
	 */
	public static function remove_category_base(): bool {
		return (bool) Options::get( 'woocommerce', 'remove_category_base', false ) && get_option( 'permalink_structure' );
	}

	/**
	 * Short product link.
	 *
	 * @param string   $link Link.
	 * @param \WP_Post $post Post.
	 */
	public static function product_link( $link, $post ) {
		if ( ! $post instanceof \WP_Post || 'product' !== $post->post_type || ! self::remove_product_base() ) {
			return $link;
		}
		if ( '' === $post->post_name || in_array( $post->post_status, array( 'draft', 'pending', 'auto-draft', 'future' ), true ) ) {
			return $link;
		}
		if ( self::path_taken( $post->post_name, 'product' ) ) {
			return $link;
		}
		return home_url( user_trailingslashit( $post->post_name ) );
	}

	/**
	 * Short category link.
	 *
	 * @param string   $link     Link.
	 * @param \WP_Term $term     Term.
	 * @param string   $taxonomy Taxonomy.
	 */
	public static function term_link( $link, $term, $taxonomy ) {
		if ( 'product_cat' !== $taxonomy || ! $term instanceof \WP_Term || ! self::remove_category_base() ) {
			return $link;
		}
		$path = self::term_path( $term );
		if ( self::path_taken( $path, 'product_cat' ) ) {
			return $link;
		}
		return home_url( user_trailingslashit( $path, 'category' ) );
	}

	/**
	 * Hierarchical slug path of a category.
	 *
	 * @param \WP_Term $term Term.
	 */
	public static function term_path( \WP_Term $term ): string {
		$slugs = array();
		foreach ( array_reverse( get_ancestors( $term->term_id, 'product_cat', 'taxonomy' ) ) as $ancestor_id ) {
			$ancestor = get_term( $ancestor_id, 'product_cat' );
			if ( $ancestor instanceof \WP_Term ) {
				$slugs[] = $ancestor->slug;
			}
		}
		$slugs[] = $term->slug;
		return implode( '/', $slugs );
	}

	/**
	 * Whether a short path is used by other content (pages, posts, products vs categories).
	 *
	 * @param string $path Path without slashes.
	 * @param string $kind 'product' or 'product_cat'.
	 */
	private static function path_taken( string $path, string $kind ): bool {
		$key = $kind . ':' . $path;
		if ( isset( self::$conflicts[ $key ] ) ) {
			return self::$conflicts[ $key ];
		}
		$taken = (bool) get_page_by_path( $path, OBJECT, 'page' );
		if ( ! $taken && ! str_contains( $path, '/' ) ) {
			$taken = (bool) self::find_post( $path );
			if ( ! $taken && 'product_cat' === $kind && self::remove_product_base() ) {
				// Products take precedence over categories with the same slug.
				$taken = (bool) get_page_by_path( $path, OBJECT, 'product' );
			}
		}
		self::$conflicts[ $key ] = $taken;
		return $taken;
	}

	/**
	 * Published post (type "post") with a slug.
	 *
	 * @param string $slug Slug.
	 */
	private static function find_post( string $slug ): ?\WP_Post {
		$posts = get_posts(
			array(
				'name'             => $slug,
				'post_type'        => 'post',
				'post_status'      => 'publish',
				'numberposts'      => 1,
				'no_found_rows'    => true,
			)
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * Map unresolved requests to products and product categories.
	 *
	 * @param array $vars Query vars.
	 * @return array
	 */
	public static function request( $vars ) {
		if ( ! is_array( $vars ) || is_admin() || ( ! self::remove_product_base() && ! self::remove_category_base() ) ) {
			return $vars;
		}
		$generic = array( 'name', 'pagename', 'attachment', 'page', 'paged', 'error', 'category_name' );
		if ( array_diff( array_keys( $vars ), $generic ) ) {
			return $vars;
		}
		$path = Helpers::request_path();
		if ( '' === $path || str_contains( $path, '.' ) ) {
			return $vars;
		}
		$paged = 0;
		if ( preg_match( '#^(.+?)/page/(\d+)$#', $path, $m ) ) {
			$path  = $m[1];
			$paged = (int) $m[2];
		}
		if ( get_page_by_path( $path, OBJECT, 'page' ) ) {
			return $vars;
		}
		if ( ! str_contains( $path, '/' ) ) {
			if ( self::find_post( $path ) ) {
				return $vars;
			}
			if ( self::remove_product_base() && ! $paged ) {
				$product = get_page_by_path( $path, OBJECT, 'product' );
				if ( $product instanceof \WP_Post && 'publish' === $product->post_status ) {
					return array(
						'product'   => $product->post_name,
						'post_type' => 'product',
						'name'      => $product->post_name,
					);
				}
			}
		}
		if ( isset( $vars['attachment'] ) && get_page_by_path( (string) $vars['attachment'], OBJECT, 'attachment' ) ) {
			return $vars;
		}
		if ( self::remove_category_base() ) {
			$term = self::term_by_path( $path );
			if ( $term ) {
				$out = array( 'product_cat' => $term->slug );
				if ( $paged > 1 ) {
					$out['paged'] = $paged;
				}
				return $out;
			}
		}
		return $vars;
	}

	/**
	 * Category whose full slug path equals the path.
	 *
	 * @param string $path Path.
	 */
	public static function term_by_path( string $path ): ?\WP_Term {
		$slugs = explode( '/', $path );
		$term  = get_term_by( 'slug', sanitize_title( (string) end( $slugs ) ), 'product_cat' );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}
		return self::term_path( $term ) === implode( '/', array_map( 'sanitize_title', $slugs ) ) ? $term : null;
	}

	/**
	 * Redirect URLs that still contain the removed base.
	 */
	public static function redirect_old(): void {
		if ( ! function_exists( 'wc_get_permalink_structure' ) ) {
			return;
		}
		$structure = wc_get_permalink_structure();
		$path      = Helpers::request_path();
		$target    = '';
		if ( self::remove_product_base() && is_singular( 'product' ) ) {
			$base = trim( (string) strtok( (string) ( $structure['product_rewrite_slug'] ?? 'product' ), '%' ), '/' );
			if ( '' !== $base && str_starts_with( $path . '/', $base . '/' ) ) {
				$target = (string) get_permalink( get_queried_object_id() );
			}
		} elseif ( self::remove_category_base() && is_tax( 'product_cat' ) ) {
			$base = trim( (string) ( $structure['category_rewrite_slug'] ?? 'product-category' ), '/' );
			if ( '' !== $base && str_starts_with( $path . '/', $base . '/' ) ) {
				$link   = get_term_link( get_queried_object() );
				$target = is_wp_error( $link ) ? '' : $link;
				$paged  = (int) get_query_var( 'paged' );
				if ( '' !== $target && $paged > 1 ) {
					$target = user_trailingslashit( trailingslashit( $target ) . 'page/' . $paged, 'paged' );
				}
			}
		}
		if ( '' === $target || str_contains( $target, '/' . ( $base ?? '' ) . '/' ) ) {
			return;
		}
		$query = isset( $_SERVER['QUERY_STRING'] ) ? sanitize_text_field( wp_unslash( $_SERVER['QUERY_STRING'] ) ) : '';
		if ( '' !== $query ) {
			$target .= '?' . $query;
		}
		wp_safe_redirect( $target, 301 );
		exit;
	}

	/**
	 * Reset caches (tests).
	 */
	public static function reset(): void {
		self::$conflicts = array();
	}
}
