<?php
/**
 * Content analysis module.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Analysis;

use MGSEO\Context;
use MGSEO\Helpers;
use MGSEO\Meta;
use MGSEO\Module as Base;
use MGSEO\Modules\Titles\Module as Titles;
use MGSEO\Variables;

defined( 'ABSPATH' ) || exit;

/**
 * Live analysis via REST, score stored on save, SEO column in post lists.
 */
final class Module extends Base {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'analysis';
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'mgseo/rest_routes', array( $this, 'routes' ) );
		add_action( 'save_post', array( $this, 'store_score' ), 30, 2 );
		add_action(
			'admin_init',
			function (): void {
				foreach ( array_keys( Context::post_types() ) as $post_type ) {
					add_filter( "manage_{$post_type}_posts_columns", array( $this, 'columns' ) );
					add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'column' ), 10, 2 );
					add_filter( "manage_edit-{$post_type}_sortable_columns", array( $this, 'sortable' ) );
				}
			}
		);
		add_action( 'pre_get_posts', array( $this, 'sort_query' ) );
	}

	/**
	 * REST routes.
	 *
	 * @param string $ns Namespace.
	 */
	public function routes( string $ns ): void {
		register_rest_route(
			$ns,
			'/analyze',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_analyze' ),
				'permission_callback' => static fn( \WP_REST_Request $r ) => Helpers::can_edit_post_seo( (int) $r->get_param( 'post_id' ) ),
				'args'                => array(
					'post_id'     => array(
						'type'     => 'integer',
						'required' => true,
					),
					'title'       => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'content'     => array( 'type' => 'string' ),
					'keywords'    => array( 'type' => 'string' ),
					'post_title'  => array( 'type' => 'string' ),
				),
			)
		);
	}

	/**
	 * Analyze request handler.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_analyze( \WP_REST_Request $request ): \WP_REST_Response {
		$post = get_post( (int) $request->get_param( 'post_id' ) );
		if ( ! $post ) {
			return new \WP_REST_Response( array( 'message' => 'Not found' ), 404 );
		}
		// Work on an in-memory copy reflecting unsaved editor state.
		$draft = clone $post;
		if ( null !== $request->get_param( 'content' ) ) {
			$draft->post_content = (string) $request->get_param( 'content' );
		}
		if ( null !== $request->get_param( 'post_title' ) ) {
			$draft->post_title = sanitize_text_field( (string) $request->get_param( 'post_title' ) );
		}
		if ( null !== $request->get_param( 'slug' ) && '' !== (string) $request->get_param( 'slug' ) ) {
			$draft->post_name = sanitize_title( (string) $request->get_param( 'slug' ) );
		}
		$result = self::analyze_post(
			$draft,
			array(
				'title'       => $request->get_param( 'title' ),
				'description' => $request->get_param( 'description' ),
				'keywords'    => $request->get_param( 'keywords' ),
			)
		);
		return new \WP_REST_Response( $result );
	}

	/**
	 * Analyze a post, optionally with overrides for unsaved values.
	 *
	 * @param \WP_Post $post      Post.
	 * @param array    $overrides title, description (templates, may be null), keywords (comma string, may be null).
	 * @return array
	 */
	public static function analyze_post( \WP_Post $post, array $overrides = array() ): array {
		$title_tpl = isset( $overrides['title'] ) && null !== $overrides['title'] && '' !== $overrides['title'] ? (string) $overrides['title'] : Titles::post_title_template( $post );
		if ( isset( $overrides['description'] ) && null !== $overrides['description'] && '' !== $overrides['description'] ) {
			$desc = Variables::replace( (string) $overrides['description'], $post );
		} else {
			$desc = Titles::post_description( $post );
		}
		$keywords = isset( $overrides['keywords'] ) && null !== $overrides['keywords'] ? (string) $overrides['keywords'] : (string) Meta::post( $post->ID, 'focus_keyword' );
		$keywords = array_values( array_filter( array_map( 'trim', explode( ',', $keywords ) ) ) );
		$slug     = '' !== $post->post_name ? $post->post_name : sanitize_title( $post->post_title );
		$url      = (string) get_permalink( $post );
		if ( '' !== $post->post_name && ! str_contains( $url, $post->post_name ) ) {
			$url = trailingslashit( home_url( $slug ) );
		}
		$content = (string) apply_filters( 'mgseo/analysis_content', do_blocks( $post->post_content ), $post );

		$analyzer        = new Analyzer(
			array(
				'title'          => Variables::replace( $title_tpl, $post ),
				'description'    => $desc,
				'slug'           => $slug,
				'url'            => $url,
				'content'        => $content,
				'keywords'       => $keywords,
				'home_url'       => home_url( '/' ),
				'used_elsewhere' => $keywords ? self::keyword_used_elsewhere( $keywords[0], $post->ID ) : false,
			)
		);
		$result          = $analyzer->run();
		$result['title'] = Variables::replace( $title_tpl, $post );
		$result['desc']  = $desc;
		return $result;
	}

	/**
	 * Whether another published post targets the same primary keyword.
	 *
	 * @param string $keyword Keyword.
	 * @param int    $post_id Current post id.
	 */
	public static function keyword_used_elsewhere( string $keyword, int $post_id ): bool {
		global $wpdb;
		$like = $wpdb->esc_like( $keyword );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- lightweight lookup.
		$ids    = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND p.post_status = 'publish' AND pm.post_id <> %d AND pm.meta_value LIKE %s LIMIT 20",
				Meta::PREFIX . 'focus_keyword',
				$post_id,
				$like . '%'
			),
			1
		);
		$needle = Analyzer::normalize( $keyword );
		foreach ( (array) $ids as $value ) {
			$first = Analyzer::normalize( trim( explode( ',', (string) $value )[0] ) );
			if ( $first === $needle ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Store score after save.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public function store_score( int $post_id, $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! $post instanceof \WP_Post ) {
			return;
		}
		if ( ! isset( Context::post_types()[ $post->post_type ] ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		if ( '' === (string) Meta::post( $post_id, 'focus_keyword' ) ) {
			delete_post_meta( $post_id, Meta::PREFIX . 'score' );
			return;
		}
		$result = self::analyze_post( $post );
		update_post_meta( $post_id, Meta::PREFIX . 'score', (int) $result['score'] );
	}

	/**
	 * Add SEO column.
	 *
	 * @param array $columns Columns.
	 */
	public function columns( array $columns ): array {
		$columns['mgseo'] = __( 'SEO', 'mg-seo' );
		return $columns;
	}

	/**
	 * Render SEO column.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post id.
	 */
	public function column( string $column, int $post_id ): void {
		if ( 'mgseo' !== $column ) {
			return;
		}
		$score   = Meta::post( $post_id, 'score', '' );
		$keyword = (string) Meta::post( $post_id, 'focus_keyword' );
		$robots  = (array) Meta::post( $post_id, 'robots', array() );
		if ( '' === $score ) {
			echo '<span class="mgseo-score is-none">' . esc_html__( 'N/A', 'mg-seo' ) . '</span>';
		} else {
			$class = (int) $score >= 80 ? 'is-good' : ( (int) $score >= 50 ? 'is-ok' : 'is-bad' );
			echo '<span class="mgseo-score ' . esc_attr( $class ) . '">' . esc_html( (string) (int) $score ) . '/100</span>';
		}
		if ( '' !== $keyword ) {
			echo '<br /><small>' . esc_html( $keyword ) . '</small>';
		}
		if ( in_array( 'noindex', $robots, true ) ) {
			echo '<br /><small class="mgseo-noindex">' . esc_html__( 'No Index', 'mg-seo' ) . '</small>';
		}
	}

	/**
	 * Sortable column.
	 *
	 * @param array $columns Columns.
	 */
	public function sortable( array $columns ): array {
		$columns['mgseo'] = 'mgseo_score';
		return $columns;
	}

	/**
	 * Sort by score.
	 *
	 * @param \WP_Query $query Query.
	 */
	public function sort_query( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'mgseo_score' !== $query->get( 'orderby' ) ) {
			return;
		}
		$query->set( 'meta_key', Meta::PREFIX . 'score' );
		$query->set( 'orderby', 'meta_value_num' );
	}
}
