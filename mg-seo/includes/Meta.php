<?php
/**
 * Per-object SEO meta access.
 *
 * @package MGSEO
 */

namespace MGSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Reads/writes `_mgseo_*` meta for posts, terms and users.
 *
 * Known keys (without prefix):
 *  title, description, focus_keyword (comma separated, first is primary), robots (array of directives),
 *  advanced_robots (array: max-snippet, max-image-preview, max-video-preview), canonical, pillar (bool),
 *  og_title, og_description, og_image, og_image_id, twitter_title, twitter_description, twitter_image,
 *  twitter_card, schema (JSON string: list of schema entities), score (int 0-100),
 *  primary_<taxonomy> (term id), breadcrumb_title, redirect (array), sitemap_exclude (bool), news_exclude (bool).
 */
final class Meta {

	public const PREFIX = '_mgseo_';

	/**
	 * Keys exposed via REST / registered meta for posts.
	 *
	 * @return array<string, string> key => type
	 */
	public static function post_keys(): array {
		return array(
			'title'               => 'string',
			'description'         => 'string',
			'focus_keyword'       => 'string',
			'robots'              => 'array',
			'advanced_robots'     => 'object',
			'canonical'           => 'string',
			'pillar'              => 'boolean',
			'og_title'            => 'string',
			'og_description'      => 'string',
			'og_image'            => 'string',
			'og_image_id'         => 'integer',
			'twitter_title'       => 'string',
			'twitter_description' => 'string',
			'twitter_image'       => 'string',
			'twitter_card'        => 'string',
			'schema'              => 'string',
			'score'               => 'integer',
			'breadcrumb_title'    => 'string',
			'sitemap_exclude'     => 'boolean',
			'news_exclude'        => 'boolean',
		);
	}

	/**
	 * Get post meta.
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Key without prefix.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function post( int $post_id, string $key, mixed $fallback = '' ): mixed {
		if ( ! metadata_exists( 'post', $post_id, self::PREFIX . $key ) ) {
			return $fallback;
		}
		return get_post_meta( $post_id, self::PREFIX . $key, true );
	}

	/**
	 * Set post meta (deletes when empty).
	 *
	 * @param int    $post_id Post id.
	 * @param string $key     Key without prefix.
	 * @param mixed  $value   Value.
	 */
	public static function set_post( int $post_id, string $key, mixed $value ): void {
		if ( self::is_empty( $value ) ) {
			delete_post_meta( $post_id, self::PREFIX . $key );
			return;
		}
		update_post_meta( $post_id, self::PREFIX . $key, $value );
	}

	/**
	 * Get term meta.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $key      Key without prefix.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function term( int $term_id, string $key, mixed $fallback = '' ): mixed {
		if ( ! metadata_exists( 'term', $term_id, self::PREFIX . $key ) ) {
			return $fallback;
		}
		return get_term_meta( $term_id, self::PREFIX . $key, true );
	}

	/**
	 * Set term meta.
	 *
	 * @param int    $term_id Term id.
	 * @param string $key     Key without prefix.
	 * @param mixed  $value   Value.
	 */
	public static function set_term( int $term_id, string $key, mixed $value ): void {
		if ( self::is_empty( $value ) ) {
			delete_term_meta( $term_id, self::PREFIX . $key );
			return;
		}
		update_term_meta( $term_id, self::PREFIX . $key, $value );
	}

	/**
	 * Get user meta.
	 *
	 * @param int    $user_id  User id.
	 * @param string $key      Key without prefix.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	public static function user( int $user_id, string $key, mixed $fallback = '' ): mixed {
		if ( ! metadata_exists( 'user', $user_id, self::PREFIX . $key ) ) {
			return $fallback;
		}
		return get_user_meta( $user_id, self::PREFIX . $key, true );
	}

	/**
	 * Set user meta.
	 *
	 * @param int    $user_id User id.
	 * @param string $key     Key without prefix.
	 * @param mixed  $value   Value.
	 */
	public static function set_user( int $user_id, string $key, mixed $value ): void {
		if ( self::is_empty( $value ) ) {
			delete_user_meta( $user_id, self::PREFIX . $key );
			return;
		}
		update_user_meta( $user_id, self::PREFIX . $key, $value );
	}

	/**
	 * Get meta for any queried object.
	 *
	 * @param object|null $obj      WP_Post, WP_Term or WP_User.
	 * @param string      $key      Key without prefix.
	 * @param mixed       $fallback Fallback.
	 * @return mixed
	 */
	public static function for_object( ?object $obj, string $key, mixed $fallback = '' ): mixed {
		if ( $obj instanceof \WP_Post ) {
			return self::post( $obj->ID, $key, $fallback );
		}
		if ( $obj instanceof \WP_Term ) {
			return self::term( $obj->term_id, $key, $fallback );
		}
		if ( $obj instanceof \WP_User ) {
			return self::user( $obj->ID, $key, $fallback );
		}
		return $fallback;
	}

	/**
	 * Whether a value counts as empty for storage.
	 *
	 * @param mixed $value Value.
	 */
	private static function is_empty( mixed $value ): bool {
		return null === $value || '' === $value || array() === $value || false === $value;
	}
}
