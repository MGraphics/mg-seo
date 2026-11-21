<?php
/**
 * Internal links module.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Links;

use MGSEO\Context;
use MGSEO\Helpers;
use MGSEO\Meta;
use MGSEO\Module as Base;
use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Link counter, orphan and broken link reports and local internal link suggestions.
 * Everything is computed on this site; no external service is used.
 */
final class Module extends Base {

	public const REPORT_SLUG   = 'mg-seo-links-report';
	public const BUILT_OPTION  = 'mgseo_links_built';
	public const REBUILD_EVENT = 'mgseo_links_rebuild';
	public const DB_OPTION     = 'mgseo_links_db';
	public const DB_VERSION    = '1';

	/**
	 * Statuses whose content is indexed.
	 */
	private const STATUSES = array( 'publish', 'future', 'draft', 'pending', 'private' );

	/**
	 * Counts prefetched for the current list table.
	 *
	 * @var array<int, array{internal: int, external: int, inbound: int}>|null
	 */
	private ?array $list_counts = null;

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'links';
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'init', array( $this, 'maybe_install' ), 1 );
		add_action( 'wp_after_insert_post', array( $this, 'on_save' ), 20, 2 );
		add_action( 'trashed_post', array( Store::class, 'delete_source' ) );
		add_action( 'deleted_post', array( $this, 'on_delete' ) );
		add_action( self::REBUILD_EVENT, array( $this, 'background_rebuild' ) );
		add_action( 'admin_init', array( $this, 'register_columns' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_filter( 'mgseo/metabox_tabs', array( $this, 'metabox_tab' ), 10, 2 );
		add_action( 'mgseo/rest_routes', array( $this, 'routes' ) );
	}

	/**
	 * Settings schema.
	 */
	public function settings(): array {
		$types = array();
		foreach ( Context::post_types() as $name => $type ) {
			$types[ $name ] = $type->labels->name;
		}
		return array(
			array(
				'id'     => 'general',
				'title'  => __( 'General', 'mg-seo' ),
				'fields' => array(
					array(
						'id'           => 'list_column',
						'type'         => 'toggle',
						'label'        => __( 'Links column', 'mg-seo' ),
						'toggle_label' => __( 'Show internal, external and inbound link counts in post lists', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'           => 'suggestions',
						'type'         => 'toggle',
						'label'        => __( 'Link suggestions', 'mg-seo' ),
						'toggle_label' => __( 'Suggest related posts to link to in the editor', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'      => 'suggestions_limit',
						'type'    => 'number',
						'label'   => __( 'Number of suggestions', 'mg-seo' ),
						'default' => 8,
						'min'     => 1,
						'max'     => 30,
					),
					array(
						'id'      => 'suggest_post_types',
						'type'    => 'multicheck',
						'label'   => __( 'Suggest posts of these types', 'mg-seo' ),
						'desc'    => __( 'Leave all unchecked to suggest every public post type.', 'mg-seo' ),
						'default' => array(),
						'options' => $types,
					),
				),
			),
		);
	}

	/**
	 * Report page.
	 */
	public function admin_pages(): array {
		return array(
			array(
				'slug'       => self::REPORT_SLUG,
				'title'      => __( 'Link Report', 'mg-seo' ),
				'menu_title' => __( 'Link Report', 'mg-seo' ),
				'capability' => 'mgseo_view_reports',
				'callback'   => array( ReportPage::class, 'render' ),
				'position'   => 25,
			),
		);
	}

	/**
	 * Create tables and start a background index build for existing content.
	 */
	public function activate(): void {
		Store::create_tables();
		update_option( self::DB_OPTION, self::DB_VERSION, true );
		if ( ! get_option( self::BUILT_OPTION ) && ! wp_next_scheduled( self::REBUILD_EVENT, array( 0 ) ) ) {
			wp_schedule_single_event( time() + MINUTE_IN_SECONDS, self::REBUILD_EVENT, array( 0 ) );
		}
	}

	/**
	 * Create the tables when the module was enabled without running activate() (e.g. an update from a
	 * version without this module, or the module list changed in the database).
	 */
	public function maybe_install(): void {
		if ( self::DB_VERSION !== get_option( self::DB_OPTION ) ) {
			$this->activate();
		}
	}

	/**
	 * Remove tables and options.
	 */
	public function uninstall(): void {
		Store::drop_tables();
		delete_option( self::BUILT_OPTION );
		delete_option( self::DB_OPTION );
		wp_clear_scheduled_hook( self::REBUILD_EVENT );
		$settings = get_option( Options::OPTION, array() );
		if ( is_array( $settings ) && isset( $settings['links'] ) ) {
			unset( $settings['links'] );
			update_option( Options::OPTION, $settings );
		}
	}

	/**
	 * Post types eligible as suggestions.
	 *
	 * @return string[]
	 */
	public static function suggestion_post_types(): array {
		$all      = array_keys( Context::post_types() );
		$selected = array_filter( (array) Options::get( 'links', 'suggest_post_types', array() ) );
		return $selected ? array_values( array_intersect( $all, $selected ) ) : $all;
	}

	/**
	 * Whether a post is indexable (robots meta, post type default, password protection).
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function is_indexable( \WP_Post $post ): bool {
		return Helpers::robots_allow_index( (array) Meta::post( $post->ID, 'robots', array() ), (string) $post->post_password, $post->post_type );
	}

	/**
	 * Index one post: links and term vector.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function index_post( \WP_Post $post ): void {
		if ( ! in_array( $post->post_status, self::STATUSES, true ) || ! isset( Context::post_types()[ $post->post_type ] ) ) {
			Store::delete_source( $post->ID );
			return;
		}
		/**
		 * Filter the content scanned for links.
		 *
		 * @param string   $content Content.
		 * @param \WP_Post $post    Post.
		 */
		$content = (string) apply_filters( 'mgseo/links/content', (string) $post->post_content, $post );
		$url     = (string) get_permalink( $post );
		Store::save_links( $post->ID, Parser::parse( $content, '' !== $url ? $url : home_url( '/' ) ) );

		$keywords = array_values( array_filter( array_map( 'trim', explode( ',', (string) Meta::post( $post->ID, 'focus_keyword' ) ) ) ) );
		$body     = '' !== trim( $post->post_excerpt ) ? $post->post_excerpt : wp_trim_words( Tokenizer::plain( $content ), 300, '' );
		Store::save_terms( $post->ID, Tokenizer::vector( (string) $post->post_title, $keywords, $body ) );

		if ( 'publish' === $post->post_status && '' !== $url ) {
			Store::attach_target( $post->ID, $url );
		}
	}

	/**
	 * Re-index after every save (fires after meta and terms are stored, also for the block editor).
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public function on_save( int $post_id, $post ): void {
		if ( ! $post instanceof \WP_Post || wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		self::index_post( $post );
	}

	/**
	 * Clean up after permanent deletion.
	 *
	 * @param int $post_id Post id.
	 */
	public function on_delete( int $post_id ): void {
		Store::delete_source( $post_id );
		Store::detach_target( $post_id );
	}

	/**
	 * Index a batch of existing posts.
	 *
	 * @param int $offset Offset.
	 * @param int $size   Batch size.
	 * @return array{processed: int, next: int, total: int, done: bool}
	 */
	public static function rebuild_batch( int $offset, int $size = 50 ): array {
		$types = array_keys( Context::post_types() );
		$query = new \WP_Query(
			array(
				'post_type'              => $types ? $types : array( 'post' ),
				'post_status'            => self::STATUSES,
				'orderby'                => 'ID',
				'order'                  => 'ASC',
				'posts_per_page'         => max( 1, $size ),
				'offset'                 => max( 0, $offset ),
				'no_found_rows'          => false,
				'ignore_sticky_posts'    => true,
				'update_post_term_cache' => false,
			)
		);
		foreach ( $query->posts as $post ) {
			self::index_post( $post );
		}
		$processed = count( $query->posts );
		$next      = $offset + $processed;
		$done      = 0 === $processed || $next >= (int) $query->found_posts;
		if ( $done ) {
			update_option( self::BUILT_OPTION, time(), false );
		}
		return array(
			'processed' => $processed,
			'next'      => $next,
			'total'     => (int) $query->found_posts,
			'done'      => $done,
		);
	}

	/**
	 * WP-Cron driven build of the index for existing content.
	 *
	 * @param int $offset Offset.
	 */
	public function background_rebuild( $offset = 0 ): void {
		$result = self::rebuild_batch( (int) $offset, 100 );
		if ( ! $result['done'] ) {
			wp_schedule_single_event( time() + 30, self::REBUILD_EVENT, array( $result['next'] ) );
		}
	}

	/**
	 * Post list columns.
	 */
	public function register_columns(): void {
		if ( ! Options::get( 'links', 'list_column', true ) ) {
			return;
		}
		foreach ( array_keys( Context::post_types() ) as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'columns' ) );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'column' ), 10, 2 );
		}
	}

	/**
	 * Add the column.
	 *
	 * @param array $columns Columns.
	 */
	public function columns( array $columns ): array {
		$columns['mgseo_links'] = '<span title="' . esc_attr__( 'Internal links out / external links out / inbound internal links', 'mg-seo' ) . '">' . esc_html__( 'Links', 'mg-seo' ) . '</span>';
		return $columns;
	}

	/**
	 * Column content (counts for the whole page are fetched with two queries).
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post id.
	 */
	public function column( string $column, int $post_id ): void {
		if ( 'mgseo_links' !== $column ) {
			return;
		}
		if ( null === $this->list_counts || ! isset( $this->list_counts[ $post_id ] ) ) {
			global $wp_query;
			$ids               = $wp_query instanceof \WP_Query ? wp_list_pluck( (array) $wp_query->posts, 'ID' ) : array();
			$ids[]             = $post_id;
			$this->list_counts = Store::counts( $ids ) + (array) $this->list_counts;
		}
		$counts = $this->list_counts[ $post_id ] ?? array(
			'internal' => 0,
			'external' => 0,
			'inbound'  => 0,
		);
		printf(
			'<span class="mgseo-links-count" title="%1$s"><span class="dashicons dashicons-admin-links" aria-hidden="true"></span><span class="screen-reader-text">%1$s:</span> %2$d</span> <span class="mgseo-links-count" title="%3$s"><span class="dashicons dashicons-external" aria-hidden="true"></span><span class="screen-reader-text">%3$s:</span> %4$d</span> <span class="mgseo-links-count%7$s" title="%5$s"><span class="dashicons dashicons-download" aria-hidden="true"></span><span class="screen-reader-text">%5$s:</span> %6$d</span>',
			esc_attr__( 'Internal links', 'mg-seo' ),
			(int) $counts['internal'],
			esc_attr__( 'External links', 'mg-seo' ),
			(int) $counts['external'],
			esc_attr__( 'Inbound internal links', 'mg-seo' ),
			(int) $counts['inbound'],
			0 === (int) $counts['inbound'] && 'publish' === get_post_status( $post_id ) ? ' is-orphan' : ''
		);
	}

	/**
	 * Assets on post lists, the editor and the report.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function assets( string $hook ): void {
		$screen = get_current_screen();
		$base   = $screen ? $screen->base : '';
		if ( ! in_array( $base, array( 'post', 'edit' ), true ) && ! str_contains( $hook, self::REPORT_SLUG ) ) {
			return;
		}
		wp_enqueue_style( 'mgseo-links', MGSEO_URL . 'assets/links.css', array( 'dashicons' ), MGSEO_VERSION );
		if ( 'edit' === $base ) {
			return;
		}
		wp_enqueue_script( 'mgseo-links', MGSEO_URL . 'assets/links.js', array( 'jquery', 'wp-api-fetch' ), MGSEO_VERSION, true );
		wp_localize_script(
			'mgseo-links',
			'mgseoLinks',
			array(
				'i18n' => array(
					'copy'     => __( 'Copy link', 'mg-seo' ),
					'copied'   => __( 'Copied', 'mg-seo' ),
					'copyHtml' => __( 'Copy as HTML link', 'mg-seo' ),
					'pillar'   => __( 'Pillar', 'mg-seo' ),
					'inText'   => __( 'Phrase found in your text:', 'mg-seo' ),
					'none'     => __( 'No suggestions. Add focus keywords or more content.', 'mg-seo' ),
					'loading'  => __( 'Loading…', 'mg-seo' ),
					'error'    => __( 'Request failed.', 'mg-seo' ),
					'rebuilt'  => __( 'Index rebuilt.', 'mg-seo' ),
					/* translators: 1: processed posts, 2: total posts */
					'progress' => __( 'Indexed %1$d of %2$d…', 'mg-seo' ),
				),
			)
		);
	}

	/**
	 * Editor tab.
	 *
	 * @param array    $tabs Tabs.
	 * @param \WP_Post $post Post.
	 */
	public function metabox_tab( array $tabs, \WP_Post $post ): array {
		$tabs[] = array(
			'id'       => 'links',
			'title'    => __( 'Links', 'mg-seo' ),
			'render'   => array( ReportPage::class, 'render_box' ),
			'priority' => 60,
		);
		return $tabs;
	}

	/**
	 * REST routes.
	 *
	 * @param string $ns Namespace.
	 */
	public function routes( string $ns ): void {
		register_rest_route(
			$ns,
			'/links/suggest',
			array(
				'methods'             => \WP_REST_Server::READABLE . ', ' . \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'rest_suggest' ),
				'permission_callback' => static fn( \WP_REST_Request $r ) => Helpers::can_edit_post_seo( (int) $r->get_param( 'post_id' ) ),
				'args'                => array(
					'post_id'  => array(
						'type'     => 'integer',
						'required' => true,
					),
					'content'  => array( 'type' => 'string' ),
					'title'    => array( 'type' => 'string' ),
					'keywords' => array( 'type' => 'string' ),
					'limit'    => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 50,
					),
				),
			)
		);
		register_rest_route(
			$ns,
			'/links/rebuild',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => static fn( \WP_REST_Request $r ) => new \WP_REST_Response( self::rebuild_batch( (int) $r->get_param( 'offset' ), 50 ) ),
				'permission_callback' => static fn() => current_user_can( 'mgseo_manage_settings' ),
				'args'                => array(
					'offset' => array(
						'type'    => 'integer',
						'default' => 0,
						'minimum' => 0,
					),
				),
			)
		);
	}

	/**
	 * Suggestions endpoint.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_suggest( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = get_post( (int) $request->get_param( 'post_id' ) );
		if ( ! $post ) {
			return new \WP_Error( 'mgseo_not_found', __( 'Post not found.', 'mg-seo' ), array( 'status' => 404 ) );
		}
		$limit = $request->get_param( 'limit' );
		$items = Suggester::suggest(
			$post,
			array(
				'content'  => $request->get_param( 'content' ),
				'title'    => $request->get_param( 'title' ),
				'keywords' => $request->get_param( 'keywords' ),
			),
			null === $limit ? null : (int) $limit
		);
		return new \WP_REST_Response( array( 'suggestions' => $items ) );
	}
}
