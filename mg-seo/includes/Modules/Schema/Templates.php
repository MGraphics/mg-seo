<?php
/**
 * Schema templates: reusable entities applied by display conditions.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Schema;

use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Option `schema.templates`: list of { id, name, type, custom, data, conditions: [ { include, kind, value } ] }.
 * kind: all | post_type (value = post type) | post (value = ids, comma separated) | taxonomy (value = "taxonomy:term" with term id or slug).
 */
final class Templates {

	/**
	 * Stored templates.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function all(): array {
		$list = Options::get( 'schema', 'templates', array() );
		if ( is_string( $list ) ) {
			$list = json_decode( $list, true );
		}
		return is_array( $list ) ? array_values( array_filter( $list, 'is_array' ) ) : array();
	}

	/**
	 * Whether a template applies to a post.
	 *
	 * @param array    $template Template.
	 * @param \WP_Post $post     Post.
	 */
	public static function matches( array $template, \WP_Post $post ): bool {
		$included = false;
		foreach ( (array) ( $template['conditions'] ?? array() ) as $condition ) {
			if ( ! is_array( $condition ) ) {
				continue;
			}
			if ( ! self::condition_matches( $condition, $post ) ) {
				continue;
			}
			if ( empty( $condition['include'] ) ) {
				return false;
			}
			$included = true;
		}
		return $included;
	}

	/**
	 * Whether one condition matches a post.
	 *
	 * @param array    $condition Condition.
	 * @param \WP_Post $post      Post.
	 */
	private static function condition_matches( array $condition, \WP_Post $post ): bool {
		$value = trim( (string) ( $condition['value'] ?? '' ) );
		switch ( $condition['kind'] ?? '' ) {
			case 'all':
				return true;
			case 'post_type':
				return '' !== $value && $post->post_type === $value;
			case 'post':
				$ids = array_filter( array_map( 'absint', explode( ',', $value ) ) );
				return in_array( $post->ID, $ids, true );
			case 'taxonomy':
				if ( ! str_contains( $value, ':' ) ) {
					return '' !== $value && taxonomy_exists( $value ) && is_object_in_term( $post->ID, $value );
				}
				list( $taxonomy, $term ) = array_map( 'trim', explode( ':', $value, 2 ) );
				if ( ! taxonomy_exists( $taxonomy ) ) {
					return false;
				}
				if ( '' === $term || '*' === $term ) {
					return (bool) is_object_in_term( $post->ID, $taxonomy );
				}
				return (bool) has_term( ctype_digit( $term ) ? (int) $term : $term, $taxonomy, $post );
		}
		return false;
	}

	/**
	 * Template entities that apply to a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_post( \WP_Post $post ): array {
		$out = array();
		foreach ( self::all() as $template ) {
			if ( ! self::matches( $template, $post ) ) {
				continue;
			}
			$out[] = array(
				'id'       => 'template-' . (string) ( $template['id'] ?? count( $out ) ),
				'type'     => (string) ( $template['type'] ?? '' ),
				'custom'   => ! empty( $template['custom'] ),
				'data'     => is_array( $template['data'] ?? null ) ? $template['data'] : array(),
				'template' => (string) ( $template['name'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Sanitize submitted templates (JSON string from the settings screen).
	 *
	 * @param mixed $raw Raw value.
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitize( mixed $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return self::all();
		}
		$previous = array();
		foreach ( self::all() as $template ) {
			$previous[ (string) ( $template['id'] ?? '' ) ] = $template;
		}
		$out    = array();
		$errors = array();
		foreach ( $raw as $template ) {
			if ( ! is_array( $template ) ) {
				continue;
			}
			$id     = sanitize_key( (string) ( $template['id'] ?? '' ) );
			$id     = '' !== $id ? $id : strtolower( str_replace( '-', '', wp_generate_uuid4() ) );
			$entity = Entities::sanitize_entity(
				array(
					'id'     => $id,
					'type'   => $template['type'] ?? '',
					'custom' => ! empty( $template['custom'] ),
					'data'   => $template['data'] ?? array(),
				),
				$error
			);
			if ( null === $entity ) {
				if ( '' !== $error ) {
					$errors[] = $error;
					if ( isset( $previous[ $id ] ) ) {
						$out[] = $previous[ $id ];
					}
				}
				continue;
			}
			$conditions = array();
			foreach ( (array) ( $template['conditions'] ?? array() ) as $condition ) {
				if ( ! is_array( $condition ) ) {
					continue;
				}
				$kind = sanitize_key( (string) ( $condition['kind'] ?? '' ) );
				if ( ! in_array( $kind, array( 'all', 'post_type', 'post', 'taxonomy' ), true ) ) {
					continue;
				}
				$conditions[] = array(
					'include' => ! empty( $condition['include'] ) && 'false' !== $condition['include'],
					'kind'    => $kind,
					'value'   => 'all' === $kind ? '' : sanitize_text_field( (string) ( $condition['value'] ?? '' ) ),
				);
			}
			$name  = sanitize_text_field( (string) ( $template['name'] ?? '' ) );
			$out[] = array(
				'id'         => $id,
				'name'       => '' !== $name ? $name : $entity['type'],
				'type'       => $entity['type'],
				'custom'     => $entity['custom'],
				'data'       => $entity['data'],
				'conditions' => $conditions,
			);
		}
		if ( $errors && function_exists( 'get_current_user_id' ) ) {
			set_transient( 'mgseo_schema_errors_' . get_current_user_id(), $errors, 300 );
		}
		return $out;
	}

	/**
	 * Settings field: template editor (rendered by schema.js) storing JSON in a hidden input.
	 *
	 * @param array  $field Field.
	 * @param mixed  $value Value.
	 * @param string $name  Input name.
	 */
	public static function render_field( array $field, mixed $value, string $name ): void {
		$templates = is_array( $value ) ? $value : self::all();
		printf(
			'<div class="mgseo-schema-templates" data-templates="%s"><input type="hidden" class="mgseo-schema-json" name="%s" value="%s" /><div class="mgseo-schema-templates-list"></div><p><button type="button" class="button mgseo-schema-add-template">%s</button></p></div>',
			esc_attr( (string) wp_json_encode( $templates ) ),
			esc_attr( $name ),
			esc_attr( (string) wp_json_encode( $templates ) ),
			esc_html__( 'Add template', 'mg-seo' )
		);
		echo '<noscript><p>' . esc_html__( 'The template editor needs JavaScript.', 'mg-seo' ) . '</p></noscript>';
	}
}
