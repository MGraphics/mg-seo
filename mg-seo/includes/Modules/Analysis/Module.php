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
 * Live analysis via REST, stored scores (see Scores), SEO column in post lists, site overview,
 * score badges in the editors and the admin bar.
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
		add_action( 'mgseo/bulk_saved', array( $this, 'bulk_saved' ) );
		add_action( 'mgseo/bulk/csv_applied', array( $this, 'csv_applied' ) );
		add_action( 'mgseo/import/finished', array( $this, 'import_finished' ), 10, 2 );
		add_action( Scores::DAILY_EVENT, array( $this, 'daily' ) );
		add_action( Scores::BATCH_EVENT, array( Scores::class, 'run_pass_batch' ) );
		add_action( 'admin_init', array( Scores::class, 'ensure_scheduled' ) );
		add_action(
			'admin_init',
			function (): void {
				foreach ( array_keys( Context::post_types() ) as $post_type ) {
					add_filter( "manage_{$post_type}_posts_columns", array( $this, 'columns' ) );
					add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'column' ), 10, 2 );
					add_filter( "manage_edit-{$post_type}_sortable_columns", array( $this, 'sortable' ) );
					add_filter( "bulk_actions-edit-{$post_type}", array( $this, 'bulk_actions' ) );
					add_filter( "handle_bulk_actions-edit-{$post_type}", array( $this, 'handle_bulk' ), 10, 3 );
				}
			}
		);
		add_action( 'pre_get_posts', array( $this, 'sort_query' ) );
		add_action( 'manage_posts_extra_tablenav', array( $this, 'tablenav' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'post_submitbox_misc_actions', array( $this, 'publish_box' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 85 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_bar_style' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'admin_bar_style' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_data' ), 20 );
		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			\WP_CLI::add_command( 'mgseo scores', Cli::class );
		}
	}

	/**
	 * Overview page.
	 */
	public function admin_pages(): array {
		return array(
			array(
				'slug'       => Overview::SLUG,
				'title'      => __( 'SEO Overview', 'mg-seo' ),
				'menu_title' => __( 'Overview', 'mg-seo' ),
				'capability' => Overview::CAP,
				'callback'   => array( Overview::class, 'render' ),
				'position'   => 5,
			),
		);
	}

	/**
	 * Schedule the daily pass.
	 */
	public function activate(): void {
		Scores::ensure_scheduled();
	}

	/**
	 * Data removal.
	 */
	public function uninstall(): void {
		Scores::unschedule();
		delete_option( Scores::PASS_OPTION );
		delete_option( Scores::LAST_OPTION );
		delete_option( Scores::VER_OPTION );
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
		// Analysis of arbitrary rendered HTML (page builders send the HTML they render).
		register_rest_route(
			$ns,
			'/analyze/html',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_analyze_html' ),
				'permission_callback' => static function ( \WP_REST_Request $r ): bool {
					$post_id = (int) $r->get_param( 'post_id' );
					return $post_id > 0 ? Helpers::can_edit_post_seo( $post_id ) : current_user_can( 'mgseo_edit_meta' );
				},
				'args'                => array(
					'html'        => array(
						'type'     => 'string',
						'required' => true,
					),
					'keywords'    => array( 'type' => 'string' ),
					'title'       => array( 'type' => 'string' ),
					'description' => array( 'type' => 'string' ),
					'slug'        => array( 'type' => 'string' ),
					'url'         => array( 'type' => 'string' ),
					'post_id'     => array(
						'type'    => 'integer',
						'default' => 0,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/scores/recalc',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_recalc' ),
				'permission_callback' => static fn() => current_user_can( 'mgseo_edit_meta' ),
				'args'                => array(
					'after'     => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
					'limit'     => array(
						'type'    => 'integer',
						'default' => 20,
						'minimum' => 1,
						'maximum' => 100,
					),
					'post_type' => array(
						'type'    => 'string',
						'default' => '',
					),
					'missing'   => array(
						'type'    => 'boolean',
						'default' => false,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/scores/post/(?P<id>\d+)',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_score_post' ),
				'permission_callback' => static fn( \WP_REST_Request $r ) => current_user_can( 'edit_post', (int) $r['id'] ) && current_user_can( 'mgseo_edit_meta' ),
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
	 * Analyze rendered HTML.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_analyze_html( \WP_REST_Request $request ): \WP_REST_Response {
		$post_id = (int) $request->get_param( 'post_id' );
		$post    = $post_id > 0 ? get_post( $post_id ) : null;
		$input   = array(
			'html'     => (string) $request->get_param( 'html' ),
			'keywords' => null !== $request->get_param( 'keywords' ) ? (string) $request->get_param( 'keywords' ) : ( $post ? (string) Meta::post( $post->ID, 'focus_keyword' ) : '' ),
			'post_id'  => $post ? $post->ID : 0,
		);
		foreach ( array( 'title', 'description', 'slug', 'url' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$input[ $key ] = sanitize_text_field( (string) $request->get_param( $key ) );
			}
		}
		if ( $post ) {
			$input += array(
				'title'       => Variables::replace( Titles::post_title_template( $post ), $post ),
				'description' => Titles::post_description( $post ),
				'slug'        => $post->post_name,
				'url'         => (string) get_permalink( $post ),
			);
		}
		return new \WP_REST_Response( self::analyze_html( $input ) );
	}

	/**
	 * Analyze arbitrary HTML (public API for page builder integrations).
	 *
	 * @param array $input html, keywords (comma string or array), title, description (rendered, no variables), slug, url, post_id (for the duplicate keyword check).
	 * @return array Analyzer result plus has_keyword, band and issues.
	 */
	public static function analyze_html( array $input ): array {
		$keywords        = $input['keywords'] ?? array();
		$keywords        = is_array( $keywords ) ? $keywords : explode( ',', (string) $keywords );
		$keywords        = array_values( array_filter( array_map( 'trim', array_map( 'strval', $keywords ) ) ) );
		$analyzer        = new Analyzer(
			array(
				'title'          => (string) ( $input['title'] ?? '' ),
				'description'    => (string) ( $input['description'] ?? '' ),
				'slug'           => (string) ( $input['slug'] ?? '' ),
				'url'            => (string) ( $input['url'] ?? '' ),
				'content'        => (string) ( $input['html'] ?? '' ),
				'keywords'       => $keywords,
				'home_url'       => home_url( '/' ),
				'used_elsewhere' => $keywords ? self::keyword_used_elsewhere( $keywords[0], (int) ( $input['post_id'] ?? 0 ) ) : false,
			)
		);
		$result          = self::summarize( $analyzer->run(), (bool) $keywords );
		$result['title'] = (string) ( $input['title'] ?? '' );
		$result['desc']  = (string) ( $input['description'] ?? '' );
		return $result;
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
		/**
		 * Filter the HTML that is analysed for a post (page builders can return their rendered output).
		 *
		 * @param string   $content Rendered block content.
		 * @param \WP_Post $post    Post.
		 */
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
		$result          = self::summarize( $analyzer->run(), (bool) $keywords );
		$result['title'] = Variables::replace( $title_tpl, $post );
		$result['desc']  = $desc;
		return $result;
	}

	/**
	 * Add keyword state, band and the most important issues to an analyzer result.
	 *
	 * @param array $result      Analyzer result.
	 * @param bool  $has_keyword Whether a focus keyword was given.
	 */
	private static function summarize( array $result, bool $has_keyword ): array {
		$result['has_keyword'] = $has_keyword;
		$result['band']        = Scores::band( (int) $result['score'], $has_keyword );
		$result['issues']      = Scores::labelled( Scores::top_issues( $result['checks'], $has_keyword ) );
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
	 * Store the score after save, and refresh posts that share the primary keyword (duplicate check).
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public function store_score( int $post_id, $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) || ! $post instanceof \WP_Post ) {
			return;
		}
		if ( ! Scores::eligible( $post ) ) {
			return;
		}
		Scores::compute( $post );
		$primary = trim( explode( ',', (string) Meta::post( $post_id, 'focus_keyword' ) )[0] );
		foreach ( Scores::sharing_keyword( $primary, $post_id ) as $other ) {
			Scores::compute( $other );
		}
	}

	/**
	 * Bulk editor and quick edit saves (quick edit also fires save_post, handled there).
	 *
	 * @param int $post_id Post id.
	 */
	public function bulk_saved( int $post_id ): void {
		if ( ! doing_action( 'save_post' ) ) {
			Scores::compute( $post_id );
		}
	}

	/**
	 * CSV import applied: score the changed posts now (a background pass covers large files and dependants).
	 *
	 * @param int[] $ids Changed post ids.
	 */
	public function csv_applied( array $ids ): void {
		$ids = array_values( array_unique( array_map( 'intval', $ids ) ) );
		Scores::recalculate_ids( array_slice( $ids, 0, 100 ) );
		if ( $ids ) {
			Scores::start_pass();
		}
	}

	/**
	 * Rank Math / Yoast import finished: score everything (synchronously from WP-CLI, else in the background).
	 *
	 * @param array $log  Import log.
	 * @param bool  $sync Whether the import ran synchronously.
	 */
	public function import_finished( array $log, bool $sync = false ): void {
		if ( ! empty( $log['dry_run'] ) ) {
			return;
		}
		if ( $sync ) {
			Scores::recalculate_all();
			return;
		}
		Scores::start_pass();
	}

	/**
	 * Daily: start a pass over all posts and run its first batch.
	 */
	public function daily(): void {
		Scores::start_pass();
		Scores::run_pass_batch();
	}

	/**
	 * Recalculate a batch (button on list screens and the overview).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_recalc( \WP_REST_Request $request ): \WP_REST_Response {
		$type = sanitize_key( (string) $request['post_type'] );
		return new \WP_REST_Response(
			Scores::batch(
				(int) $request['after'],
				(int) $request['limit'],
				array(
					'post_type' => $type,
					'missing'   => (bool) $request['missing'],
				)
			)
		);
	}

	/**
	 * Calculate one post's score and return the list cell HTML ("Calculate score" button).
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_score_post( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post_id = (int) $request['id'];
		$data    = Scores::compute( $post_id );
		if ( null === $data ) {
			return new \WP_Error( 'mgseo_score', __( 'This post cannot be scored.', 'mg-seo' ), array( 'status' => 400 ) );
		}
		return new \WP_REST_Response(
			array(
				'score'   => $data['score'],
				'band'    => $data['band'],
				'keyword' => $data['keyword'],
				'html'    => $this->cell( $post_id, $data ),
			)
		);
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
	 * Render SEO column (computes missing scores lazily, a limited number per page).
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post id.
	 */
	public function column( string $column, int $post_id ): void {
		if ( 'mgseo' !== $column ) {
			return;
		}
		$post = get_post( $post_id );
		$data = $post && Scores::eligible( $post ) ? Scores::lazy( $post_id ) : null;
		echo '<div class="mgseo-cell" data-post-id="' . esc_attr( (string) $post_id ) . '">';
		echo $this->cell( $post_id, $data ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in cell().
		echo '</div>';
	}

	/**
	 * Inner HTML of the SEO list cell.
	 *
	 * @param int        $post_id Post id.
	 * @param array|null $data    Score data, null when not calculated.
	 */
	public function cell( int $post_id, ?array $data ): string {
		$keyword  = (string) Meta::post( $post_id, 'focus_keyword' );
		$robots   = (array) Meta::post( $post_id, 'robots', array() );
		$can_edit = current_user_can( 'edit_post', $post_id ) && current_user_can( 'mgseo_edit_meta' );
		$post     = get_post( $post_id );
		ob_start();
		if ( '' === trim( $keyword ) ) {
			Overview::keyword_hint( $post_id, $can_edit );
		} elseif ( null === $data ) {
			if ( $can_edit && $post && Scores::eligible( $post ) ) {
				echo '<button type="button" class="button button-small mgseo-score-now" data-post-id="' . esc_attr( (string) $post_id ) . '">' . esc_html__( 'Calculate score', 'mg-seo' ) . '</button>';
			} else {
				echo '<span class="mgseo-score is-none">–</span>';
			}
		} else {
			$issues = Scores::labelled( $data['issues'], Scores::MAX_ISSUES );
			$title  = implode( "\n", wp_list_pluck( $issues, 'label' ) );
			echo '<span class="mgseo-score ' . esc_attr( Scores::band_class( $data['band'] ) ) . '" title="' . esc_attr( $title ) . '">' . esc_html( (string) (int) $data['score'] ) . '/100</span>';
			if ( $issues && 'good' !== $data['band'] ) {
				echo '<br /><small class="mgseo-top-issue">' . esc_html( $issues[0]['label'] ) . '</small>';
			}
		}
		if ( '' !== $keyword ) {
			echo '<br /><small>' . esc_html( $keyword ) . '</small>';
		}
		if ( in_array( 'noindex', $robots, true ) ) {
			echo '<br /><small class="mgseo-noindex">' . esc_html__( 'No Index', 'mg-seo' ) . '</small>';
		}
		return (string) ob_get_clean();
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
	 * Sort by score; posts without a score stay in the list (first when ascending).
	 *
	 * @param \WP_Query $query Query.
	 */
	public function sort_query( \WP_Query $query ): void {
		if ( ! is_admin() || ! $query->is_main_query() || 'mgseo_score' !== $query->get( 'orderby' ) ) {
			return;
		}
		$order  = 'DESC' === strtoupper( (string) $query->get( 'order' ) ) ? 'DESC' : 'ASC';
		$meta   = (array) $query->get( 'meta_query' );
		$meta[] = array(
			'relation'           => 'OR',
			'mgseo_score_clause' => array(
				'key'     => Meta::PREFIX . 'score',
				'compare' => 'EXISTS',
				'type'    => 'NUMERIC',
			),
			array(
				'key'     => Meta::PREFIX . 'score',
				'compare' => 'NOT EXISTS',
			),
		);
		$query->set( 'meta_query', $meta );
		$query->set(
			'orderby',
			array(
				'mgseo_score_clause' => $order,
				'ID'                 => $order,
			)
		);
	}

	/**
	 * Bulk action.
	 *
	 * @param array $actions Actions.
	 */
	public function bulk_actions( array $actions ): array {
		if ( current_user_can( 'mgseo_edit_meta' ) ) {
			$actions['mgseo_recalc'] = __( 'Recalculate SEO score', 'mg-seo' );
		}
		return $actions;
	}

	/**
	 * Handle the bulk action.
	 *
	 * @param string $redirect Redirect URL.
	 * @param string $action   Action.
	 * @param array  $ids      Post ids.
	 */
	public function handle_bulk( $redirect, $action, $ids ) {
		if ( 'mgseo_recalc' !== $action || ! current_user_can( 'mgseo_edit_meta' ) ) {
			return $redirect;
		}
		$ids   = array_filter( array_map( 'intval', (array) $ids ), static fn( int $id ): bool => current_user_can( 'edit_post', $id ) );
		$count = Scores::recalculate_ids( $ids );
		return add_query_arg( 'mgseo_recalculated', $count, remove_query_arg( 'mgseo_recalculated', (string) $redirect ) );
	}

	/**
	 * "Recalculate all" next to the list filters.
	 *
	 * @param string $which top|bottom.
	 */
	public function tablenav( $which ): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( 'top' !== $which || ! $screen || 'edit' !== $screen->base || ! isset( Context::post_types()[ $screen->post_type ] ) ) {
			return;
		}
		echo '<div class="alignleft actions mgseo-tablenav">';
		Overview::recalc_button( (string) $screen->post_type );
		echo '</div>';
	}

	/**
	 * Notice after the bulk action.
	 */
	public function notices(): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		if ( ! isset( $_GET['mgseo_recalculated'] ) ) {
			return;
		}
		$count = absint( $_GET['mgseo_recalculated'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- display only.
		/* translators: %d: number of posts */
		echo '<div class="notice notice-success is-dismissible"><p>' . esc_html( sprintf( _n( 'SEO score recalculated for %d post.', 'SEO score recalculated for %d posts.', $count, 'mg-seo' ), $count ) ) . '</p></div>';
	}

	/**
	 * Scripts for the recalculate buttons (post lists, overview, import page).
	 *
	 * @param string $hook Hook suffix.
	 */
	public function assets( string $hook ): void {
		$screen = get_current_screen();
		$list   = $screen && 'edit' === $screen->base && isset( Context::post_types()[ $screen->post_type ] );
		if ( ! $list && ! str_ends_with( $hook, Overview::SLUG ) && ! str_ends_with( $hook, \MGSEO\Modules\Import\Module::PAGE ) ) {
			return;
		}
		wp_enqueue_style( 'mgseo-admin', MGSEO_URL . 'assets/admin.css', array(), MGSEO_VERSION );
		wp_enqueue_script( 'mgseo-scores', MGSEO_URL . 'assets/scores.js', array( 'wp-api-fetch', 'wp-i18n' ), MGSEO_VERSION, true );
		wp_set_script_translations( 'mgseo-scores', 'mg-seo', MGSEO_DIR . 'languages' );
		wp_localize_script(
			'mgseo-scores',
			'mgseoScores',
			array(
				'path'  => '/' . \MGSEO\Rest::NAMESPACE . '/scores',
				'batch' => 20,
			)
		);
	}

	/**
	 * Badge data for a post: stored score or "no keyword".
	 *
	 * @param \WP_Post $post Post.
	 * @return array{score: int|null, band: string, keyword: bool, issues: array, text: string}
	 */
	public static function badge_data( \WP_Post $post ): array {
		$data    = Scores::eligible( $post ) ? Scores::lazy( $post->ID ) : null;
		$keyword = '' !== trim( (string) Meta::post( $post->ID, 'focus_keyword' ) );
		$band    = $keyword ? ( $data ? $data['band'] : Scores::BAND_PENDING ) : Scores::BAND_NONE;
		$score   = $keyword && $data ? $data['score'] : null;
		$text    = null !== $score ? $score . '/100' : ( $keyword ? '–' : __( 'No keyword', 'mg-seo' ) );
		return array(
			'score'   => $score,
			'band'    => $band,
			'keyword' => $keyword,
			'issues'  => $data ? Scores::labelled( $data['issues'] ) : array(),
			'text'    => $text,
		);
	}

	/**
	 * Classic editor: score in the Publish box.
	 *
	 * @param \WP_Post $post Post.
	 */
	public function publish_box( $post ): void {
		if ( ! $post instanceof \WP_Post || ! isset( Context::post_types()[ $post->post_type ] ) || ! current_user_can( 'mgseo_edit_meta' ) ) {
			return;
		}
		$badge = self::badge_data( $post );
		echo '<div class="misc-pub-section mgseo-pub-score">';
		echo '<span class="dashicons dashicons-chart-bar" aria-hidden="true"></span> ' . esc_html__( 'SEO score:', 'mg-seo' ) . ' ';
		echo '<span class="mgseo-score mgseo-live-score ' . esc_attr( Scores::band_class( $badge['band'] ) ) . '">' . esc_html( $badge['text'] ) . '</span> ';
		echo '<a href="#mgseo-box">' . esc_html__( 'Details', 'mg-seo' ) . '</a>';
		echo '</div>';
	}

	/**
	 * Admin bar badge on the front end (singular views) and on editor screens, regardless of the page builder.
	 *
	 * @param \WP_Admin_Bar $bar Admin bar.
	 */
	public function admin_bar( $bar ): void {
		if ( ! $bar instanceof \WP_Admin_Bar || ! current_user_can( 'mgseo_edit_meta' ) ) {
			return;
		}
		$post = null;
		if ( is_admin() ) {
			$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
			if ( $screen && 'post' === $screen->base && isset( $GLOBALS['post'] ) && $GLOBALS['post'] instanceof \WP_Post ) {
				$post = $GLOBALS['post'];
			}
		} elseif ( is_singular() ) {
			$object = get_queried_object();
			$post   = $object instanceof \WP_Post ? $object : null;
		}
		if ( ! $post || ! isset( Context::post_types()[ $post->post_type ] ) || ! current_user_can( 'edit_post', $post->ID ) || 'auto-draft' === $post->post_status ) {
			return;
		}
		$badge = self::badge_data( $post );
		$edit  = (string) get_edit_post_link( $post->ID, 'raw' );
		$bar->add_node(
			array(
				'id'    => 'seo-score',
				'title' => '<span class="ab-icon" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'SEO', 'mg-seo' ) . ' <span class="seo-score-value ' . esc_attr( Scores::band_class( $badge['band'] ) ) . '">' . esc_html( $badge['text'] ) . '</span></span>',
				'href'  => is_admin() ? '#mgseo-box' : ( $edit ? $edit . '#mgseo-box' : '' ),
				'meta'  => array( 'title' => __( 'SEO score of this page', 'mg-seo' ) ),
			)
		);
		if ( ! $badge['keyword'] ) {
			$bar->add_node(
				array(
					'parent' => 'seo-score',
					'id'     => 'seo-score-keyword',
					'title'  => esc_html__( 'Set a focus keyword', 'mg-seo' ),
					'href'   => Overview::keyword_link( $post->ID ),
				)
			);
		}
		foreach ( $badge['issues'] as $i => $issue ) {
			if ( Scores::NO_KEYWORD === $issue['id'] ) {
				continue;
			}
			$bar->add_node(
				array(
					'parent' => 'seo-score',
					'id'     => 'seo-score-issue-' . $i,
					'title'  => esc_html( $issue['label'] ),
				)
			);
		}
		if ( current_user_can( Overview::CAP ) ) {
			$bar->add_node(
				array(
					'parent' => 'seo-score',
					'id'     => 'seo-score-overview',
					'title'  => esc_html__( 'SEO overview of all pages', 'mg-seo' ),
					'href'   => admin_url( 'admin.php?page=' . Overview::SLUG ),
				)
			);
		}
	}

	/**
	 * Inline style of the admin bar badge (no plugin file on the front end).
	 */
	public function admin_bar_style(): void {
		if ( ! is_admin_bar_showing() || ! current_user_can( 'mgseo_edit_meta' ) ) {
			return;
		}
		$css = '#wpadminbar #wp-admin-bar-seo-score .ab-icon:before{content:"\f185";top:2px}'
			. '#wpadminbar .seo-score-value{display:inline-block;padding:0 6px;margin-left:2px;border-radius:9px;line-height:18px;font-weight:600;background:#646970;color:#fff}'
			. '#wpadminbar .seo-score-value.is-good{background:#00a32a}#wpadminbar .seo-score-value.is-ok{background:#dba617;color:#1d2327}'
			. '#wpadminbar .seo-score-value.is-bad{background:#d63638}#wpadminbar .seo-score-value.is-none{background:#646970}';
		wp_add_inline_style( 'admin-bar', $css );
	}

	/**
	 * Block editor: stored score for the header badge before the first live analysis.
	 */
	public function editor_data(): void {
		$post = get_post();
		if ( ! $post || ! isset( Context::post_types()[ $post->post_type ] ) || ! wp_script_is( 'mgseo-editor', 'enqueued' ) ) {
			return;
		}
		$badge = self::badge_data( $post );
		wp_localize_script(
			'mgseo-editor',
			'mgseoEditor',
			array(
				'postId'      => $post->ID,
				'score'       => $badge['score'],
				'hasKeyword'  => $badge['keyword'],
				'issues'      => $badge['issues'],
				'overviewUrl' => current_user_can( Overview::CAP ) ? admin_url( 'admin.php?page=' . Overview::SLUG ) : '',
				'keywordUrl'  => Overview::keyword_link( $post->ID ),
			)
		);
	}
}
