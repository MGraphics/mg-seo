<?php
/**
 * Media library tools: find & replace in alt/title/caption/description, missing alt report.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Image;

defined( 'ABSPATH' ) || exit;

/**
 * Batched media library operations (also exposed through REST for the admin screen).
 */
final class Tools {

	public const FIELDS = array( 'alt', 'title', 'caption', 'description' );

	/**
	 * Find & replace in one batch of image attachments.
	 *
	 * @param array $args find, replace, fields, case_sensitive, dry_run, after_id, limit.
	 * @return array{matches: array<int, array>, scanned: int, changed: int, next_after_id: int, done: bool}
	 */
	public static function replace_batch( array $args ): array {
		global $wpdb;
		$find    = (string) ( $args['find'] ?? '' );
		$replace = (string) ( $args['replace'] ?? '' );
		$fields  = array_values( array_intersect( (array) ( $args['fields'] ?? array( 'alt' ) ), self::FIELDS ) );
		$case    = ! empty( $args['case_sensitive'] );
		$dry     = ! isset( $args['dry_run'] ) || ! empty( $args['dry_run'] );
		$after   = max( 0, (int) ( $args['after_id'] ?? 0 ) );
		$limit   = min( 200, max( 1, (int) ( $args['limit'] ?? 50 ) ) );
		$result  = array(
			'matches'       => array(),
			'scanned'       => 0,
			'changed'       => 0,
			'next_after_id' => $after,
			'done'          => true,
		);
		if ( '' === $find || ! $fields ) {
			return $result;
		}

		$like   = '%' . $wpdb->esc_like( $find ) . '%';
		$where  = array();
		$params = array();
		$map    = array(
			'alt'         => 'm.meta_value',
			'title'       => 'p.post_title',
			'caption'     => 'p.post_excerpt',
			'description' => 'p.post_content',
		);
		foreach ( $fields as $field ) {
			$where[]  = $map[ $field ] . ' LIKE %s';
			$params[] = $like;
		}
		$sql = "SELECT p.ID FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
			WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE 'image/%%' AND p.ID > %d AND ( " . implode( ' OR ', $where ) . ' )
			GROUP BY p.ID ORDER BY p.ID ASC LIMIT %d';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared -- placeholders only; columns come from a fixed map.
		$ids = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql, array_merge( array( $after ), $params, array( $limit ) ) ) ) );

		$result['scanned']       = count( $ids );
		$result['done']          = count( $ids ) < $limit;
		$result['next_after_id'] = $ids ? (int) end( $ids ) : $after;
		if ( $ids ) {
			_prime_post_caches( $ids, false, true );
		}
		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$post   = get_post( $id );
			$update = array();
			foreach ( $fields as $field ) {
				$before      = self::field_value( $post, $field );
				$after_value = $case ? str_replace( $find, $replace, $before ) : str_ireplace( $find, $replace, $before );
				if ( $after_value === $before ) {
					continue;
				}
				$result['matches'][] = array(
					'id'     => $id,
					'field'  => $field,
					'before' => $before,
					'after'  => $after_value,
					'thumb'  => (string) wp_get_attachment_image_url( $id, 'thumbnail' ),
					'edit'   => (string) get_edit_post_link( $id, 'raw' ),
				);
				if ( 'alt' === $field ) {
					if ( ! $dry ) {
						update_post_meta( $id, '_wp_attachment_image_alt', sanitize_text_field( $after_value ) );
						++$result['changed'];
					}
				} else {
					$update[ $field ] = $after_value;
				}
			}
			if ( $update && ! $dry ) {
				$data = array( 'ID' => $id );
				foreach ( $update as $field => $value ) {
					$column          = array(
						'title'       => 'post_title',
						'caption'     => 'post_excerpt',
						'description' => 'post_content',
					)[ $field ];
					$data[ $column ] = 'title' === $field ? sanitize_text_field( $value ) : wp_kses_post( $value );
				}
				wp_update_post( wp_slash( $data ) );
				$result['changed'] += count( $update );
			}
		}
		return $result;
	}

	/**
	 * Field value of an attachment.
	 *
	 * @param \WP_Post $post  Attachment.
	 * @param string   $field Field.
	 */
	private static function field_value( \WP_Post $post, string $field ): string {
		return match ( $field ) {
			'alt'         => (string) get_post_meta( $post->ID, '_wp_attachment_image_alt', true ),
			'title'       => (string) $post->post_title,
			'caption'     => (string) $post->post_excerpt,
			'description' => (string) $post->post_content,
			default       => '',
		};
	}

	/**
	 * SQL condition for images without alt text.
	 */
	private static function missing_sql(): string {
		global $wpdb;
		// The mime pattern is passed as the first %s placeholder.
		return "FROM {$wpdb->posts} p LEFT JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_wp_attachment_image_alt'
			WHERE p.post_type = 'attachment' AND p.post_mime_type LIKE %s AND p.post_mime_type <> 'image/svg+xml' AND ( m.meta_value IS NULL OR TRIM( m.meta_value ) = '' )";
	}

	/**
	 * Number of images without alt text.
	 */
	public static function missing_count(): int {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- the placeholder is in missing_sql().
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(DISTINCT p.ID) ' . self::missing_sql(), 'image/%' ) );
	}

	/**
	 * Images without alt text (newest first).
	 *
	 * @param int $page     Page (1-based).
	 * @param int $per_page Per page.
	 * @return int[]
	 */
	public static function missing( int $page = 1, int $per_page = 20 ): array {
		global $wpdb;
		$offset = ( max( 1, $page ) - 1 ) * $per_page;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.NotPrepared,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- one placeholder is in missing_sql().
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT p.ID ' . self::missing_sql() . ' ORDER BY p.ID DESC LIMIT %d, %d', 'image/%', $offset, $per_page ) ) );
	}

	/**
	 * Fill missing alt text from the upload template, in batches.
	 *
	 * @param bool $dry_run Only report.
	 * @param int  $limit   Batch size.
	 * @return array{items: array<int, array>, changed: int, remaining: int}
	 */
	public static function fill_missing( bool $dry_run, int $limit = 50 ): array {
		$ids     = self::missing( 1, min( 200, max( 1, $limit ) ) );
		$items   = array();
		$changed = 0;
		_prime_post_caches( $ids, false, true );
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$alt = Attributes::upload_alt( $post );
			if ( '' === $alt ) {
				continue;
			}
			$items[] = array(
				'id'  => $id,
				'alt' => $alt,
			);
			if ( ! $dry_run ) {
				update_post_meta( $id, '_wp_attachment_image_alt', $alt );
				++$changed;
			}
		}
		return array(
			'items'     => $items,
			'changed'   => $changed,
			'remaining' => self::missing_count(),
		);
	}

	/**
	 * Register REST routes.
	 *
	 * @param string $ns Namespace.
	 */
	public static function routes( string $ns ): void {
		$permission = static fn() => current_user_can( 'mgseo_bulk_edit' ) && current_user_can( 'upload_files' );
		register_rest_route(
			$ns,
			'/image/replace',
			array(
				'methods'             => 'POST',
				'permission_callback' => $permission,
				'callback'            => static fn( \WP_REST_Request $request ) => rest_ensure_response(
					self::replace_batch(
						array(
							'find'           => (string) $request->get_param( 'find' ),
							'replace'        => (string) $request->get_param( 'replace' ),
							'fields'         => array_map( 'sanitize_key', (array) $request->get_param( 'fields' ) ),
							'case_sensitive' => (bool) $request->get_param( 'case_sensitive' ),
							'dry_run'        => (bool) $request->get_param( 'dry_run' ),
							'after_id'       => (int) $request->get_param( 'after_id' ),
							'limit'          => (int) ( $request->get_param( 'limit' ) ?? 50 ),
						)
					)
				),
				'args'                => array(
					'find'    => array(
						'type'     => 'string',
						'required' => true,
					),
					'replace' => array(
						'type'    => 'string',
						'default' => '',
					),
					'fields'  => array(
						'type'    => 'array',
						'items'   => array(
							'type' => 'string',
							'enum' => self::FIELDS,
						),
						'default' => array( 'alt' ),
					),
					'dry_run' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/image/alt',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn( \WP_REST_Request $request ) => current_user_can( 'upload_files' ) && current_user_can( 'edit_post', (int) $request->get_param( 'id' ) ),
				'callback'            => static function ( \WP_REST_Request $request ) {
					$id = (int) $request->get_param( 'id' );
					if ( ! wp_attachment_is_image( $id ) ) {
						return new \WP_Error( 'mgseo_not_image', __( 'Not an image.', 'mg-seo' ), array( 'status' => 400 ) );
					}
					$alt = sanitize_text_field( (string) $request->get_param( 'alt' ) );
					update_post_meta( $id, '_wp_attachment_image_alt', $alt );
					return rest_ensure_response(
						array(
							'id'  => $id,
							'alt' => $alt,
						)
					);
				},
				'args'                => array(
					'id'  => array(
						'type'     => 'integer',
						'required' => true,
					),
					'alt' => array(
						'type'     => 'string',
						'required' => true,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/image/fill-missing',
			array(
				'methods'             => 'POST',
				'permission_callback' => $permission,
				'callback'            => static fn( \WP_REST_Request $request ) => rest_ensure_response( self::fill_missing( (bool) $request->get_param( 'dry_run' ), (int) ( $request->get_param( 'limit' ) ?? 50 ) ) ),
				'args'                => array(
					'dry_run' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);
	}
}
