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
		add_action( 'pre_get_posts', array( $this, 'filter_query' ) );
		add_action( 'restrict_manage_posts', array( $this, 'filter_dropdown' ), 20, 2 );
		add_filter( 'default_hidden_columns', array( $this, 'hidden_columns' ), 10, 2 );
		add_action( 'manage_posts_extra_tablenav', array( $this, 'tablenav' ) );
		add_action( 'admin_notices', array( $this, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'post_submitbox_misc_actions', array( $this, 'publish_box' ) );
		add_action( 'admin_bar_menu', array( $this, 'admin_bar' ), 85 );
		add_action( 'admin_enqueue_scripts', array( $this, 'admin_bar_style' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'admin_bar_style' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_data' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'classic_data' ), 20 );
		add_filter( 'mgseo/analysis/client_config', array( $this, 'client_config_defaults' ), 5, 2 );
		Editors::boot();
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
	 * Settings: which hints and thresholds apply to each content type.
	 */
	public function settings(): array {
		$fields = array(
			array(
				'id'    => 'types_heading',
				'type'  => 'heading',
				'label' => __( 'Hints per content type', 'mg-seo' ),
				'desc'  => __( 'Blog posts get every hint. Pages such as Contact or Pricing skip the advice meant for articles: the minimum length, a number in the title, a link to an external source and an image or video.', 'mg-seo' ),
			),
		);
		foreach ( Context::post_types() as $name => $type ) {
			$fields[] = array(
				'id'      => 'profile_' . $name,
				'type'    => 'select',
				/* translators: %s: post type name */
				'label'   => sprintf( __( '%s: hints', 'mg-seo' ), $type->labels->name ),
				'options' => array(
					Analyzer::PROFILE_ARTICLE => __( 'Article: all hints', 'mg-seo' ),
					Analyzer::PROFILE_PAGE    => __( 'Page: without blog hints', 'mg-seo' ),
				),
				'default' => self::default_profile( $name ),
			);
			$fields[] = array(
				'id'      => 'min_words_' . $name,
				'type'    => 'number',
				/* translators: %s: post type name */
				'label'   => sprintf( __( '%s: minimum words', 'mg-seo' ), $type->labels->name ),
				'desc'    => __( '0 = no minimum length.', 'mg-seo' ),
				'default' => Analyzer::PROFILE_PAGE === self::default_profile( $name ) ? 0 : 600,
				'min'     => 0,
				'max'     => 5000,
			);
		}
		return array(
			array(
				'id'     => 'general',
				'title'  => __( 'Content types', 'mg-seo' ),
				'fields' => $fields,
			),
		);
	}

	/**
	 * Default hint profile of a post type: pages and products are not articles.
	 *
	 * @param string $post_type Post type.
	 */
	public static function default_profile( string $post_type ): string {
		$object = get_post_type_object( $post_type );
		return ( $object && $object->hierarchical ) || in_array( $post_type, array( 'page', 'product' ), true ) ? Analyzer::PROFILE_PAGE : Analyzer::PROFILE_ARTICLE;
	}

	/**
	 * Hint profile and minimum length of a post type.
	 *
	 * @param string $post_type Post type.
	 * @return array{profile: string, min_words: int}
	 */
	public static function type_rules( string $post_type ): array {
		$default = self::default_profile( $post_type );
		$profile = (string) \MGSEO\Options::get( 'analysis', 'profile_' . $post_type, $default );
		$profile = in_array( $profile, array( Analyzer::PROFILE_ARTICLE, Analyzer::PROFILE_PAGE ), true ) ? $profile : $default;
		$min     = \MGSEO\Options::get( 'analysis', 'min_words_' . $post_type, null );
		$min     = null === $min ? ( Analyzer::PROFILE_PAGE === $default ? 0 : 600 ) : max( 0, (int) $min );
		/**
		 * Filter the analysis rules of a post type.
		 *
		 * @param array  $rules     profile (article|page), min_words (int).
		 * @param string $post_type Post type.
		 */
		return (array) apply_filters(
			'mgseo/analysis/type_rules',
			array(
				'profile'   => $profile,
				'min_words' => $min,
			),
			$post_type
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
					'html'        => array( 'type' => 'string' ),
				),
			)
		);
		// Save the keyword, title and description from editors without a form (Elementor, page builders).
		register_rest_route(
			$ns,
			'/post/(?P<id>\d+)/seo',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'rest_save_seo' ),
				'permission_callback' => static fn( \WP_REST_Request $r ) => Helpers::can_edit_post_seo( (int) $r['id'] ),
				'args'                => array(
					'focus_keyword' => array( 'type' => 'string' ),
					'title'         => array( 'type' => 'string' ),
					'description'   => array( 'type' => 'string' ),
					'html'          => array( 'type' => 'string' ),
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
	 * Analyze request handler: the edited (unsaved) state of a post.
	 *
	 * Content is either raw editor content (`content`, rendered here like on the front end) or HTML already
	 * rendered by a page builder in the browser (`html`).
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
				'content'     => $request->get_param( 'content' ),
				'html'        => $request->get_param( 'html' ),
			)
		);
		return new \WP_REST_Response( $result );
	}

	/**
	 * Save keyword, title and description (editors without the SEO box form) and return the new analysis.
	 *
	 * @param \WP_REST_Request $request Request.
	 */
	public function rest_save_seo( \WP_REST_Request $request ): \WP_REST_Response|\WP_Error {
		$post = get_post( (int) $request['id'] );
		if ( ! $post || ! isset( Context::post_types()[ $post->post_type ] ) ) {
			return new \WP_Error( 'mgseo_not_found', __( 'Post not found.', 'mg-seo' ), array( 'status' => 404 ) );
		}
		$data = array();
		foreach ( array( 'focus_keyword', 'title', 'description' ) as $key ) {
			if ( null !== $request->get_param( $key ) ) {
				$data[ $key ] = (string) $request->get_param( $key );
			}
		}
		\MGSEO\Admin\Metabox::save_general( $post->ID, $data );
		/** This action is documented in includes/Admin/Metabox.php */
		do_action( 'mgseo/metabox_saved', $post->ID, $data );
		if ( Scores::eligible( $post ) ) {
			Scores::compute( $post );
		}
		$overrides = array();
		if ( null !== $request->get_param( 'html' ) ) {
			$overrides['html'] = (string) $request->get_param( 'html' );
		}
		$result           = self::analyze_post( get_post( $post->ID ), $overrides );
		$result['values'] = array(
			'focus_keyword' => (string) Meta::post( $post->ID, 'focus_keyword' ),
			'title'         => (string) Meta::post( $post->ID, 'title' ),
			'description'   => (string) Meta::post( $post->ID, 'description' ),
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
				'post_type'   => $post->post_type,
			);
		}
		return new \WP_REST_Response( self::analyze_html( $input ) );
	}

	/**
	 * Analyze arbitrary HTML (public API for page builder integrations).
	 *
	 * @param array $input html, keywords (comma string or array), title, description (rendered, no variables), slug, url, post_id (for the duplicate keyword check), post_type (rules per content type).
	 * @return array Analyzer result plus has_keyword, band, issues and the report (groups, fix_count, summary).
	 */
	public static function analyze_html( array $input ): array {
		$keywords = $input['keywords'] ?? array();
		$keywords = is_array( $keywords ) ? $keywords : explode( ',', (string) $keywords );
		$keywords = array_values( array_filter( array_map( 'trim', array_map( 'strval', $keywords ) ) ) );
		$rules    = self::type_rules( (string) ( $input['post_type'] ?? 'post' ) );
		self::configure_matcher();
		$analyzer        = new Analyzer(
			array(
				'title'          => (string) ( $input['title'] ?? '' ),
				'description'    => (string) ( $input['description'] ?? '' ),
				'slug'           => (string) ( $input['slug'] ?? '' ),
				'url'            => (string) ( $input['url'] ?? '' ),
				'content'        => (string) ( $input['html'] ?? '' ),
				'keywords'       => $keywords,
				'home_url'       => home_url( '/' ),
				'used_elsewhere' => $keywords ? self::keyword_used_elsewhere( $keywords[0], (int) ( $input['post_id'] ?? 0 ), true ) : false,
				'profile'        => $rules['profile'],
				'min_words'      => $rules['min_words'],
				'auto_redirect'  => self::auto_redirect(),
			)
		);
		$result          = self::summarize( $analyzer->run(), $keywords );
		$result['title'] = (string) ( $input['title'] ?? '' );
		$result['desc']  = (string) ( $input['description'] ?? '' );
		return $result;
	}

	/**
	 * Analyze a post, optionally with overrides for unsaved values.
	 *
	 * @param \WP_Post $post      Post.
	 * @param array    $overrides title, description (templates, may be null), keywords (comma string, may be null),
	 *                            content (raw unsaved content, null = saved), html (rendered HTML from a page builder).
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
		if ( isset( $overrides['html'] ) && null !== $overrides['html'] && '' !== trim( (string) $overrides['html'] ) ) {
			/** This filter is documented in includes/Modules/Analysis/Content.php */
			$content = (string) apply_filters( 'mgseo/analysis_content', Content::shortcode_text( (string) $overrides['html'] ), $post );
		} else {
			$raw     = isset( $overrides['content'] ) && null !== $overrides['content'] ? (string) $overrides['content'] : null;
			$content = Content::render( $post, $raw );
		}
		$rules = self::type_rules( $post->post_type );
		self::configure_matcher();

		$analyzer        = new Analyzer(
			array(
				'title'          => Variables::replace( $title_tpl, $post ),
				'description'    => $desc,
				'slug'           => $slug,
				'url'            => $url,
				'content'        => $content,
				'keywords'       => $keywords,
				'home_url'       => home_url( '/' ),
				'used_elsewhere' => $keywords ? self::keyword_used_elsewhere( $keywords[0], $post->ID, true ) : false,
				'profile'        => $rules['profile'],
				'min_words'      => $rules['min_words'],
				'auto_redirect'  => self::auto_redirect(),
			)
		);
		$result          = self::summarize( $analyzer->run(), $keywords );
		$result['title'] = Variables::replace( $title_tpl, $post );
		$result['desc']  = $desc;
		return $result;
	}

	/**
	 * Apply the inflection setting to the matcher.
	 */
	private static function configure_matcher(): void {
		/**
		 * Whether keywords match inflected forms and short words in between ("w Krakowie" for "Kraków").
		 *
		 * @param bool $inflection Default true.
		 */
		Analyzer::$inflection = (bool) apply_filters( 'mgseo/analysis/inflection', true );
	}

	/**
	 * Whether changed slugs are redirected automatically (mentioned in the URL hint).
	 */
	private static function auto_redirect(): bool {
		return \MGSEO\Plugin::instance()->is_module_active( 'redirections' ) && (bool) \MGSEO\Options::get( 'redirections', 'auto_redirect', true );
	}

	/**
	 * Add keyword state, band, the report (groups, fix count, summary) and the most important issues.
	 *
	 * @param array    $result   Analyzer result.
	 * @param string[] $keywords Keywords (empty = none).
	 */
	private static function summarize( array $result, array $keywords ): array {
		$has_keyword           = (bool) $keywords;
		$report                = Report::build(
			$result,
			$has_keyword,
			(string) ( $keywords[0] ?? '' ),
			array( 'links' => \MGSEO\Plugin::instance()->is_module_active( 'links' ) && (bool) \MGSEO\Options::get( 'links', 'suggestions', true ) )
		);
		$result['has_keyword'] = $has_keyword;
		$result['band']        = Scores::band( (int) $result['score'], $has_keyword );
		$result['band_word']   = Report::band_word( $result['band'] );
		$result['fix_count']   = $report['fix_count'];
		$result['summary']     = Report::summary( $report['fix_count'], $has_keyword );
		$result['spoken']      = Report::spoken( $has_keyword ? (int) $result['score'] : null, $result['band'], $report['fix_count'] );
		$result['groups']      = $report['groups'];
		$result['report']      = array( 'items' => $report['items'] );
		$result['keyword']     = (string) ( $keywords[0] ?? '' );
		$issues                = array();
		foreach ( array_slice( $report['items'], 0, Scores::MAX_ISSUES ) as $item ) {
			$issues[] = array(
				'id'     => $item['id'],
				'status' => $item['status'],
				'label'  => $item['title'],
			);
		}
		if ( ! $has_keyword ) {
			array_unshift(
				$issues,
				array(
					'id'     => Scores::NO_KEYWORD,
					'status' => 'fail',
					'label'  => Scores::issue_label( Scores::NO_KEYWORD ),
				)
			);
			$issues = array_slice( $issues, 0, Scores::MAX_ISSUES );
		}
		$result['issues'] = $issues;
		return $result;
	}

	/**
	 * Whether another published post targets the same primary keyword.
	 *
	 * @param string $keyword Keyword.
	 * @param int    $post_id Current post id.
	 * @param bool   $details Return the other post (id, title, url) instead of true.
	 * @return bool|array{id: int, title: string, url: string}
	 */
	public static function keyword_used_elsewhere( string $keyword, int $post_id, bool $details = false ): bool|array {
		global $wpdb;
		$like = $wpdb->esc_like( $keyword );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- lightweight lookup.
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.post_id, pm.meta_value FROM {$wpdb->postmeta} pm INNER JOIN {$wpdb->posts} p ON p.ID = pm.post_id
				WHERE pm.meta_key = %s AND p.post_status = 'publish' AND pm.post_id <> %d AND pm.meta_value LIKE %s LIMIT 20",
				Meta::PREFIX . 'focus_keyword',
				$post_id,
				$like . '%'
			),
			ARRAY_A
		);
		$needle = Analyzer::normalize( $keyword );
		foreach ( (array) $rows as $row ) {
			$first = Analyzer::normalize( trim( explode( ',', (string) $row['meta_value'] )[0] ) );
			if ( $first !== $needle ) {
				continue;
			}
			if ( ! $details ) {
				return true;
			}
			$other_id = (int) $row['post_id'];
			$link     = current_user_can( 'edit_post', $other_id ) ? (string) get_edit_post_link( $other_id, 'raw' ) : (string) get_permalink( $other_id );
			return array(
				'id'    => $other_id,
				'title' => html_entity_decode( get_the_title( $other_id ), ENT_QUOTES, 'UTF-8' ),
				'url'   => $link,
			);
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
	 * Inner HTML of the SEO list cell: one line with the mark, number and word, one line with what to do and the keyword.
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
			echo Report::rating_html( null, Scores::BAND_NONE ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in rating_html().
			echo '<br /><small class="mgseo-cell-line">';
			Overview::keyword_hint( $post_id, $can_edit, true );
			echo '</small>';
		} elseif ( null === $data ) {
			if ( $can_edit && $post && Scores::eligible( $post ) ) {
				echo '<button type="button" class="button button-small mgseo-score-now" data-post-id="' . esc_attr( (string) $post_id ) . '">' . esc_html__( 'Calculate score', 'mg-seo' ) . '</button>';
			} else {
				echo Report::rating_html( null, Scores::BAND_PENDING ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in rating_html().
			}
			echo '<br /><small class="mgseo-cell-line">' . esc_html( $keyword ) . '</small>';
		} else {
			$issues = Scores::labelled( $data['issues'], Scores::MAX_ISSUES );
			$fix    = (int) ( $data['fix_count'] ?? -1 );
			echo Report::rating_html( $data['score'], $data['band'], array( 'fix_count' => $fix ) ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in rating_html().
			$line = array();
			if ( $fix > 0 ) {
				$line[] = Report::short_summary( $fix );
			}
			$line[] = $keyword;
			$title  = implode( "\n", wp_list_pluck( $issues, 'label' ) );
			echo '<br /><small class="mgseo-cell-line" title="' . esc_attr( $title ) . '">' . esc_html( implode( ' · ', $line ) ) . '</small>';
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
	 * "SEO score" filter next to the date and category filters.
	 *
	 * @param string $post_type Post type.
	 * @param string $which     top|bottom.
	 */
	public function filter_dropdown( $post_type, $which = 'top' ): void {
		if ( 'top' !== $which || ! isset( Context::post_types()[ (string) $post_type ] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filter.
		$current = isset( $_GET['mgseo_band'] ) ? sanitize_key( wp_unslash( $_GET['mgseo_band'] ) ) : '';
		echo '<label for="mgseo-band-filter" class="screen-reader-text">' . esc_html__( 'Filter by SEO score', 'mg-seo' ) . '</label>';
		echo '<select name="mgseo_band" id="mgseo-band-filter">';
		foreach ( self::filter_options() as $value => $label ) {
			echo '<option value="' . esc_attr( $value ) . '" ' . selected( $current, $value, false ) . '>' . esc_html( $label ) . '</option>';
		}
		echo '</select>';
	}

	/**
	 * Options of the SEO score filter.
	 *
	 * @return array<string, string>
	 */
	public static function filter_options(): array {
		return array(
			''          => __( 'All SEO scores', 'mg-seo' ),
			'improve'   => __( 'SEO: to improve (below 80)', 'mg-seo' ),
			'poor'      => __( 'SEO: poor (0–49)', 'mg-seo' ),
			'ok'        => __( 'SEO: needs work (50–79)', 'mg-seo' ),
			'good'      => __( 'SEO: good (80–100)', 'mg-seo' ),
			'nokeyword' => __( 'SEO: no keyword', 'mg-seo' ),
		);
	}

	/**
	 * Apply the SEO score filter.
	 *
	 * @param \WP_Query $query Query.
	 */
	public function filter_query( \WP_Query $query ): void {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- list filter.
		$band = isset( $_GET['mgseo_band'] ) ? sanitize_key( wp_unslash( $_GET['mgseo_band'] ) ) : '';
		if ( ! is_admin() || ! $query->is_main_query() || '' === $band || ! array_key_exists( $band, self::filter_options() ) ) {
			return;
		}
		$ranges = array(
			'improve' => array( 0, Scores::BAND_GOOD - 1 ),
			'poor'    => array( 0, Scores::BAND_OK - 1 ),
			'ok'      => array( Scores::BAND_OK, Scores::BAND_GOOD - 1 ),
			'good'    => array( Scores::BAND_GOOD, 100 ),
		);
		$meta   = (array) $query->get( 'meta_query' );
		if ( isset( $ranges[ $band ] ) ) {
			$meta[] = array(
				'key'     => Meta::PREFIX . 'score',
				'value'   => $ranges[ $band ],
				'compare' => 'BETWEEN',
				'type'    => 'NUMERIC',
			);
		} else {
			$meta[] = array(
				'relation' => 'OR',
				array(
					'key'     => Meta::PREFIX . 'focus_keyword',
					'compare' => 'NOT EXISTS',
				),
				array(
					'key'   => Meta::PREFIX . 'focus_keyword',
					'value' => '',
				),
			);
		}
		$query->set( 'meta_query', $meta );
	}

	/**
	 * Link counts take room from the title: hidden by default (Screen Options can show them).
	 *
	 * @param string[]   $hidden Hidden columns.
	 * @param \WP_Screen $screen Screen.
	 * @return string[]
	 */
	public function hidden_columns( $hidden, $screen ): array {
		$hidden = (array) $hidden;
		if ( $screen instanceof \WP_Screen && 'edit' === $screen->base && isset( Context::post_types()[ $screen->post_type ] ) ) {
			$hidden[] = 'mgseo_links';
		}
		return $hidden;
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
		Editors::register();
		wp_enqueue_style( 'mgseo-ui' );
		wp_enqueue_style( 'mgseo-admin', MGSEO_URL . 'assets/admin.css', array( 'mgseo-ui' ), MGSEO_VERSION );
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
	 * @return array{score: int|null, band: string, keyword: bool, issues: array, text: string, fix_count: int, word: string, spoken: string}
	 */
	public static function badge_data( \WP_Post $post ): array {
		$data    = Scores::eligible( $post ) ? Scores::lazy( $post->ID ) : null;
		$keyword = '' !== trim( (string) Meta::post( $post->ID, 'focus_keyword' ) );
		$band    = $keyword ? ( $data ? $data['band'] : Scores::BAND_PENDING ) : Scores::BAND_NONE;
		$score   = $keyword && $data ? $data['score'] : null;
		$fix     = $data ? (int) ( $data['fix_count'] ?? -1 ) : -1;
		$text    = null !== $score ? $score . '/100' : ( $keyword ? '–' : __( 'No keyword', 'mg-seo' ) );
		return array(
			'score'     => $score,
			'band'      => $band,
			'keyword'   => $keyword,
			'issues'    => $data ? Scores::labelled( $data['issues'] ) : array(),
			'text'      => $text,
			'fix_count' => $fix,
			'word'      => Report::band_word( $band ),
			'spoken'    => Report::spoken( $score, $band, $fix ),
		);
	}

	/**
	 * Classic editor: one line in the Publish box ("SEO: 21/100, poor · 4 things to improve · Show").
	 *
	 * @param \WP_Post $post Post.
	 */
	public function publish_box( $post ): void {
		if ( ! $post instanceof \WP_Post || ! isset( Context::post_types()[ $post->post_type ] ) || ! current_user_can( 'mgseo_edit_meta' ) ) {
			return;
		}
		$badge = self::badge_data( $post );
		echo '<div class="misc-pub-section mgseo-pub-score">';
		echo '<span class="mgseo-mark is-' . esc_attr( $badge['band'] ) . '" aria-hidden="true"></span>';
		echo '<span class="mgseo-pub-text"><span class="mgseo-live-line">' . self::publish_line( $badge ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in publish_line().
		echo ' <a href="#mgseo-box" class="mgseo-show-hints">' . esc_html__( 'Show', 'mg-seo' ) . '</a></span>';
		echo '</div>';
	}

	/**
	 * Text of the Publish box line.
	 *
	 * @param array $badge badge_data().
	 */
	private static function publish_line( array $badge ): string {
		if ( null === $badge['score'] ) {
			/* translators: %s: state, e.g. "No keyword" */
			return esc_html( sprintf( __( 'SEO: %s', 'mg-seo' ), $badge['word'] ) );
		}
		/* translators: 1: score, 2: band word */
		$html = sprintf( esc_html__( 'SEO: %1$s, %2$s', 'mg-seo' ), '<b>' . esc_html( $badge['score'] . '/100' ) . '</b>', esc_html( mb_strtolower( $badge['word'], 'UTF-8' ) ) );
		if ( $badge['fix_count'] > 0 ) {
			$html .= '<br />' . esc_html( Report::summary( $badge['fix_count'] ) );
		}
		return $html;
	}

	/**
	 * Admin bar: "SEO 68" on the front end (any page builder that shows the bar) and on editor screens,
	 * with the score, the three most important things and a link to all hints.
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
		$value = null !== $badge['score'] ? (string) $badge['score'] : $badge['word'];
		$bar->add_node(
			array(
				'id'    => 'seo-score',
				'title' => '<span class="mgseo-mark is-' . esc_attr( $badge['band'] ) . '" aria-hidden="true"></span><span class="ab-label">' . esc_html__( 'SEO', 'mg-seo' ) . ' <span class="seo-score-value">' . esc_html( $value ) . '</span></span><span class="screen-reader-text">' . esc_html( $badge['spoken'] ) . '</span>',
				'href'  => is_admin() ? '#mgseo-box' : ( $edit ? $edit . '#mgseo-box' : '' ),
				'meta'  => array( 'title' => $badge['spoken'] ),
			)
		);
		$panel = '<span class="mgseo-ab-score"><b>' . esc_html( null !== $badge['score'] ? (string) $badge['score'] : '–' ) . '</b>' . ( null !== $badge['score'] ? '<small>/100</small>' : '' ) . ' <span class="mgseo-ab-word is-' . esc_attr( $badge['band'] ) . '">' . esc_html( $badge['word'] ) . '</span></span>';
		$bar->add_node(
			array(
				'parent' => 'seo-score',
				'id'     => 'seo-score-head',
				'title'  => $panel,
				'meta'   => array( 'class' => 'mgseo-ab-head' ),
			)
		);
		if ( ! $badge['keyword'] ) {
			$bar->add_node(
				array(
					'parent' => 'seo-score',
					'id'     => 'seo-score-keyword',
					'title'  => esc_html__( 'Enter a keyword so the page can be scored', 'mg-seo' ),
					'href'   => Overview::keyword_link( $post->ID ),
				)
			);
		}
		$i = 0;
		foreach ( $badge['issues'] as $issue ) {
			if ( Scores::NO_KEYWORD === $issue['id'] ) {
				continue;
			}
			++$i;
			$bar->add_node(
				array(
					'parent' => 'seo-score',
					'id'     => 'seo-score-issue-' . $i,
					'title'  => '<span class="mgseo-ab-n">' . $i . '.</span> ' . esc_html( $issue['label'] ),
					'meta'   => array( 'class' => 'mgseo-ab-issue' ),
				)
			);
		}
		$hints = ! is_admin() && $edit ? $edit . '#mgseo-box' : '';
		/**
		 * Filter the "Open SEO hints" link of the admin bar (page builders link to their own editor panel).
		 *
		 * @param string   $url  URL ('' = none).
		 * @param \WP_Post $post Post.
		 */
		$hints = (string) apply_filters( 'mgseo/analysis/admin_bar_hints_url', $hints, $post );
		if ( '' !== $hints ) {
			$bar->add_node(
				array(
					'parent' => 'seo-score',
					'id'     => 'seo-score-hints',
					'title'  => esc_html__( 'Open SEO hints', 'mg-seo' ),
					'href'   => $hints,
					'meta'   => array( 'class' => 'mgseo-ab-link' ),
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
		wp_add_inline_style( 'admin-bar', self::mark_css( '#wpadminbar ' ) . self::admin_bar_css() );
	}

	/**
	 * Shape marks (circle with "!", triangle, check, ring): status never by colour alone.
	 *
	 * @param string $scope CSS scope prefix.
	 */
	public static function mark_css( string $scope = '' ): string {
		$m = $scope . '.mgseo-mark';
		return $m . '{display:inline-block;flex:none;width:14px;height:14px;position:relative;vertical-align:-2px;box-sizing:border-box}'
			. $m . '.is-poor,' . $m . '.is-fail{background:#b32d2e;border-radius:50%}'
			. $m . '.is-poor:after,' . $m . '.is-fail:after{content:"!";position:absolute;left:0;right:0;top:0;text-align:center;color:#fff;font:700 10px/14px -apple-system,Arial,sans-serif}'
			. $m . '.is-ok,' . $m . '.is-warn{width:0;height:0;border-left:7px solid transparent;border-right:7px solid transparent;border-bottom:13px solid #b26200;vertical-align:-1px}'
			. $m . '.is-good,' . $m . '.is-pass{width:14px}'
			. $m . '.is-good:after,' . $m . '.is-pass:after{content:"";position:absolute;left:4px;top:0;width:5px;height:10px;border:solid #007017;border-width:0 2px 2px 0;transform:rotate(40deg)}'
			. $m . '.is-none,' . $m . '.is-pending{border:2px solid #8c8f94;border-radius:50%}';
	}

	/**
	 * Admin bar node and dropdown panel.
	 */
	private static function admin_bar_css(): string {
		return '#wpadminbar #wp-admin-bar-seo-score>.ab-item{display:flex;align-items:center;gap:6px}'
			. '#wpadminbar #wp-admin-bar-seo-score .mgseo-mark.is-good:after{border-color:#68de7c}'
			. '#wpadminbar #wp-admin-bar-seo-score .mgseo-mark.is-ok{border-bottom-color:#f0b849}'
			. '#wpadminbar #wp-admin-bar-seo-score .mgseo-mark.is-poor{background:#f86368}'
			. '#wpadminbar #wp-admin-bar-seo-score .mgseo-mark.is-poor:after{color:#1d2327}'
			. '#wpadminbar .seo-score-value{font-weight:600}'
			. '#wpadminbar #wp-admin-bar-seo-score .ab-sub-wrapper{min-width:280px}'
			. '#wpadminbar .mgseo-ab-head .ab-item{height:auto!important;padding:8px 12px 4px!important}'
			. '#wpadminbar .mgseo-ab-score b{font-size:22px;line-height:1.2;color:#fff}#wpadminbar .mgseo-ab-score small{color:#c3c4c7;margin-left:1px}'
			. '#wpadminbar .mgseo-ab-word{font-weight:600;margin-left:6px}#wpadminbar .mgseo-ab-word.is-poor{color:#f86368}#wpadminbar .mgseo-ab-word.is-ok{color:#f0b849}#wpadminbar .mgseo-ab-word.is-good{color:#68de7c}'
			. '#wpadminbar .mgseo-ab-issue .ab-item{white-space:normal!important;height:auto!important;line-height:1.4!important;padding:3px 12px!important;max-width:320px}'
			. '#wpadminbar .mgseo-ab-n{color:#c3c4c7}'
			. '#wpadminbar .mgseo-ab-link .ab-item{font-weight:600}';
	}

	/**
	 * Block editor: data for the SEO sidebar before the first live analysis.
	 */
	public function editor_data(): void {
		$post = get_post();
		if ( ! $post || ! isset( Context::post_types()[ $post->post_type ] ) || ! wp_script_is( 'mgseo-editor', 'enqueued' ) ) {
			return;
		}
		wp_localize_script( 'mgseo-editor', 'mgseoEditor', self::client_config( $post ) );
	}

	/**
	 * Post edit screens (classic editor and the SEO box in the block editor): data for the shared UI.
	 */
	public function classic_data(): void {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		$post   = $GLOBALS['post'] ?? null;
		if ( ! $screen || 'post' !== $screen->base || ! $post instanceof \WP_Post || ! isset( Context::post_types()[ $post->post_type ] ) || ! wp_script_is( 'mgseo-ui', 'registered' ) || ! current_user_can( 'mgseo_edit_meta' ) ) {
			return;
		}
		wp_localize_script( 'mgseo-ui', 'mgseoPost', self::client_config( $post ) );
	}

	/**
	 * Everything an editor integration needs to show the score, hints and fields of a post.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function client_config( \WP_Post $post ): array {
		/**
		 * Filter the data passed to the editor integrations (block editor, classic, Elementor, page builders).
		 *
		 * @param array    $config Config.
		 * @param \WP_Post $post   Post.
		 */
		return (array) apply_filters( 'mgseo/analysis/client_config', array(), $post );
	}

	/**
	 * Default editor config.
	 *
	 * @param array    $config Config.
	 * @param \WP_Post $post   Post.
	 */
	public function client_config_defaults( array $config, \WP_Post $post ): array {
		$badge     = self::badge_data( $post );
		$title_tpl = (string) \MGSEO\Options::get( 'titles', 'pt_' . $post->post_type . '_title', '%title% %page% %sep% %sitename%' );
		$desc_tpl  = (string) \MGSEO\Options::get( 'titles', 'pt_' . $post->post_type . '_description', '%excerpt%' );
		$home      = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
		return array_merge(
			array(
				'postId'        => $post->ID,
				'postType'      => $post->post_type,
				'restPath'      => '/' . \MGSEO\Rest::NAMESPACE,
				'score'         => $badge['score'],
				'band'          => $badge['band'],
				'hasKeyword'    => $badge['keyword'],
				'fixCount'      => $badge['fix_count'],
				'word'          => $badge['word'],
				'issues'        => $badge['issues'],
				'overviewUrl'   => current_user_can( Overview::CAP ) ? admin_url( 'admin.php?page=' . Overview::SLUG ) : '',
				'keywordUrl'    => Overview::keyword_link( $post->ID ),
				'editUrl'       => (string) get_edit_post_link( $post->ID, 'raw' ),
				'links'         => \MGSEO\Plugin::instance()->is_module_active( 'links' ) && (bool) \MGSEO\Options::get( 'links', 'suggestions', true ),
				'values'        => array(
					'focus_keyword' => (string) Meta::post( $post->ID, 'focus_keyword' ),
					'title'         => (string) Meta::post( $post->ID, 'title' ),
					'description'   => (string) Meta::post( $post->ID, 'description' ),
				),
				'auto'          => array(
					'title'       => Variables::replace( $title_tpl, $post ),
					'description' => Variables::replace( $desc_tpl, $post ),
				),
				'site'          => array(
					'name' => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
					'host' => $home,
					'icon' => (string) get_site_icon_url( 32 ),
					'url'  => (string) get_permalink( $post ),
				),
				'sep'           => Variables::separator(),
				'titleTemplate' => $title_tpl,
				'suggestion'    => '' === trim( (string) Meta::post( $post->ID, 'focus_keyword' ) ) ? Scores::suggest_keyword( $post ) : '',
			),
			$config
		);
	}
}
