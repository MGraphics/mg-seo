<?php
/**
 * Multiple locations: post type.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Local;

use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * The location post type (internal name only; public URLs and markup use a neutral slug).
 */
final class Locations {

	public const POST_TYPE = 'mgseo_location';

	/**
	 * Whether multiple locations are enabled.
	 */
	public static function enabled(): bool {
		return (bool) Options::get( 'local', 'multiple', false );
	}

	/**
	 * Register the post type.
	 */
	public static function register(): void {
		if ( ! self::enabled() ) {
			return;
		}
		$public = (bool) Options::get( 'local', 'locations_public', true );
		$slug   = sanitize_title( (string) Options::get( 'local', 'locations_slug', 'locations' ) );
		$slug   = '' !== $slug ? $slug : 'locations';
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'               => __( 'Locations', 'mg-seo' ),
					'singular_name'      => __( 'Location', 'mg-seo' ),
					'add_new'            => __( 'Add location', 'mg-seo' ),
					'add_new_item'       => __( 'Add new location', 'mg-seo' ),
					'edit_item'          => __( 'Edit location', 'mg-seo' ),
					'new_item'           => __( 'New location', 'mg-seo' ),
					'view_item'          => __( 'View location', 'mg-seo' ),
					'search_items'       => __( 'Search locations', 'mg-seo' ),
					'not_found'          => __( 'No locations found.', 'mg-seo' ),
					'not_found_in_trash' => __( 'No locations found in Trash.', 'mg-seo' ),
					'all_items'          => __( 'Locations', 'mg-seo' ),
					'menu_name'          => __( 'Locations', 'mg-seo' ),
				),
				'public'              => $public,
				'publicly_queryable'  => $public,
				'exclude_from_search' => ! $public,
				'show_in_nav_menus'   => $public,
				'show_ui'             => true,
				'show_in_menu'        => true,
				'show_in_rest'        => true,
				'rest_base'           => 'locations',
				'menu_icon'           => 'dashicons-location',
				'menu_position'       => 25,
				'has_archive'         => $public && Options::get( 'local', 'locations_archive', true ) ? $slug : false,
				'rewrite'             => $public ? array(
					'slug'       => $slug,
					'with_front' => false,
				) : false,
				'query_var'           => false,
				'supports'            => array( 'title', 'editor', 'thumbnail', 'excerpt', 'page-attributes', 'revisions' ),
				'capability_type'     => 'page',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Published locations.
	 *
	 * @param int[] $ids Restrict to these ids (empty = all).
	 * @return \WP_Post[]
	 */
	public static function all( array $ids = array() ): array {
		if ( ! post_type_exists( self::POST_TYPE ) ) {
			return array();
		}
		$args = array(
			'post_type'        => self::POST_TYPE,
			'post_status'      => 'publish',
			'posts_per_page'   => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- all locations of a business.
			'orderby'          => array(
				'menu_order' => 'ASC',
				'title'      => 'ASC',
			),
			'no_found_rows'    => true,
			'suppress_filters' => false,
		);
		if ( $ids ) {
			$args['post__in'] = array_map( 'absint', $ids );
			$args['orderby']  = 'post__in';
		}
		return get_posts( $args );
	}

	/**
	 * Replace the internal post type name in front-end classes with a neutral word.
	 *
	 * @param string[] $classes Classes.
	 * @return string[]
	 */
	public static function neutral_classes( array $classes ): array {
		return array_map( static fn( $c ) => str_replace( self::POST_TYPE, 'location', (string) $c ), $classes );
	}
}
