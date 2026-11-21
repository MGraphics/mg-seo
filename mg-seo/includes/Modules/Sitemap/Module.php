<?php
/**
 * XML Sitemaps module.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Sitemap;

use MGSEO\Context;
use MGSEO\Helpers;
use MGSEO\Module as Base;
use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * XML sitemaps (index, post types, taxonomies, authors, Google News, video) with an XSL view.
 *
 * Public URLs: /sitemap_index.xml, /<name>-sitemap.xml, /<name>-sitemap<N>.xml, /sitemap.xsl.
 * /sitemap.xml and the core /wp-sitemap*.xml URLs redirect (301) to the index.
 */
final class Module extends Base {

	public const QUERY_VAR      = 'xml_sitemap';
	public const QUERY_VAR_PAGE = 'xml_sitemap_page';

	/**
	 * Builder instance.
	 *
	 * @var Builder|null
	 */
	private ?Builder $builder = null;

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'sitemap';
	}

	/**
	 * Builder.
	 */
	public function builder(): Builder {
		if ( null === $this->builder ) {
			$this->builder = new Builder();
		}
		return $this->builder;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'wp_sitemaps_enabled', '__return_false', 100 );
		add_action( 'init', array( $this, 'rewrite_rules' ), 5 );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'parse_request', array( $this, 'parse_request' ), 1 );
		add_filter( 'robots_txt', array( $this, 'robots_txt' ), 20, 2 );
		add_filter( 'redirect_canonical', array( $this, 'no_canonical_redirect' ), 10, 2 );

		// Cache invalidation.
		add_action( 'save_post', array( $this, 'invalidate_post' ), 10, 2 );
		add_action( 'deleted_post', array( Cache::class, 'bump' ) );
		add_action( 'trashed_post', array( Cache::class, 'bump' ) );
		add_action( 'untrashed_post', array( Cache::class, 'bump' ) );
		add_action( 'transition_post_status', array( $this, 'invalidate_status' ), 10, 3 );
		add_action( 'created_term', array( Cache::class, 'bump' ) );
		add_action( 'edited_term', array( Cache::class, 'bump' ) );
		add_action( 'delete_term', array( Cache::class, 'bump' ) );
		add_action( 'set_object_terms', array( Cache::class, 'bump' ) );
		add_action( 'user_register', array( Cache::class, 'bump' ) );
		add_action( 'profile_update', array( Cache::class, 'bump' ) );
		add_action( 'deleted_user', array( Cache::class, 'bump' ) );
		foreach ( array( 'added_post_meta', 'updated_post_meta', 'deleted_post_meta', 'added_term_meta', 'updated_term_meta', 'deleted_term_meta', 'added_user_meta', 'updated_user_meta', 'deleted_user_meta' ) as $hook ) {
			add_action( $hook, array( $this, 'invalidate_meta' ), 10, 3 );
		}
		foreach ( array( 'mgseo_settings', 'show_on_front', 'page_on_front', 'page_for_posts', 'permalink_structure', 'category_base', 'tag_base', 'home', 'siteurl', 'blogname', 'WPLANG' ) as $option ) {
			add_action( 'update_option_' . $option, array( Cache::class, 'bump' ) );
			add_action( 'add_option_' . $option, array( Cache::class, 'bump' ) );
		}
	}

	/**
	 * Register rewrite rules.
	 */
	public function rewrite_rules(): void {
		$var  = self::QUERY_VAR;
		$page = self::QUERY_VAR_PAGE;
		add_rewrite_rule( '^sitemap_index\.xml$', 'index.php?' . $var . '=index', 'top' );
		add_rewrite_rule( '^sitemap\.xml$', 'index.php?' . $var . '=legacy', 'top' );
		add_rewrite_rule( '^sitemap\.xsl$', 'index.php?' . $var . '=xsl', 'top' );
		add_rewrite_rule( '^([a-z0-9_-]+?)-sitemap([0-9]+)?\.xml$', 'index.php?' . $var . '=$matches[1]&' . $page . '=$matches[2]', 'top' );
	}

	/**
	 * Public query vars.
	 *
	 * @param string[] $vars Vars.
	 * @return string[]
	 */
	public function query_vars( array $vars ): array {
		$vars[] = self::QUERY_VAR;
		$vars[] = self::QUERY_VAR_PAGE;
		return $vars;
	}

	/**
	 * Resolve a request path to array( name, page ) or null.
	 *
	 * @param string $path Request path relative to home, without slashes.
	 * @return array{0: string, 1: int}|null
	 */
	public static function match_path( string $path ): ?array {
		if ( 'sitemap_index.xml' === $path ) {
			return array( 'index', 1 );
		}
		if ( 'sitemap.xml' === $path ) {
			return array( 'legacy', 1 );
		}
		if ( 'sitemap.xsl' === $path ) {
			return array( 'xsl', 1 );
		}
		if ( preg_match( '/^wp-sitemap(?:[a-z0-9_-]*)\.(?:xml|xsl)$/', $path ) ) {
			return array( 'core', 1 );
		}
		if ( preg_match( '/^([a-z0-9_-]+?)-sitemap([0-9]+)?\.xml$/', $path, $m ) ) {
			return array( $m[1], isset( $m[2] ) ? (int) $m[2] : 1 );
		}
		return null;
	}

	/**
	 * Serve sitemap requests early (before the main query runs).
	 *
	 * @param \WP $wp WordPress request.
	 */
	public function parse_request( \WP $wp ): void {
		$name = isset( $wp->query_vars[ self::QUERY_VAR ] ) ? sanitize_key( (string) $wp->query_vars[ self::QUERY_VAR ] ) : '';
		$page = isset( $wp->query_vars[ self::QUERY_VAR_PAGE ] ) ? (int) $wp->query_vars[ self::QUERY_VAR_PAGE ] : 1;
		if ( 'wp' === $name || ( '' === $name && ( ! empty( $wp->query_vars['sitemap'] ) || ! empty( $wp->query_vars['sitemap-stylesheet'] ) ) ) ) {
			// Core sitemap URLs (wp-sitemap.xml matches our generic rule as "wp").
			$name = 'core';
		}
		if ( '' === $name ) {
			// Fallback when rewrite rules were not flushed yet.
			$matched = self::match_path( Helpers::request_path() );
			if ( ! $matched ) {
				return;
			}
			[ $name, $page ] = $matched;
		}

		$response = $this->response( $name, max( 1, $page ) );
		if ( null === $response ) {
			$wp->query_vars = array( 'error' => '404' );
			return;
		}
		if ( isset( $response['redirect'] ) ) {
			wp_safe_redirect( $response['redirect'], 301, 'WordPress' );
			exit;
		}
		status_header( 200 );
		header( 'Content-Type: application/xml; charset=UTF-8' );
		header( 'X-Robots-Tag: noindex, follow', true );
		echo $response['body']; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML escaped by Renderer.
		exit;
	}

	/**
	 * Build the response for a sitemap request.
	 *
	 * @param string $name Name: index, legacy, core, xsl or a sitemap name.
	 * @param int    $page Page.
	 * @return array{redirect?: string, body?: string}|null Null for 404.
	 */
	public function response( string $name, int $page = 1 ): ?array {
		if ( 'legacy' === $name || 'core' === $name ) {
			return array( 'redirect' => Builder::url( 'index' ) );
		}
		if ( 'xsl' === $name ) {
			if ( ! Options::get( 'sitemap', 'stylesheet', true ) ) {
				return null;
			}
			return array( 'body' => Stylesheet::xsl() );
		}
		$xml = $this->builder()->xml( $name, $page );
		if ( null === $xml ) {
			return null;
		}
		return array( 'body' => $xml );
	}

	/**
	 * Never add a trailing slash to sitemap URLs.
	 *
	 * @param string|false $redirect  Redirect URL.
	 * @param string       $requested Requested URL.
	 * @return string|false
	 */
	public function no_canonical_redirect( mixed $redirect, string $requested ): mixed {
		if ( preg_match( '~(?:sitemap[^/]*\.xml|sitemap\.xsl)$~', (string) wp_parse_url( $requested, PHP_URL_PATH ) ) ) {
			return false;
		}
		return $redirect;
	}

	/**
	 * Append the sitemap index to robots.txt.
	 *
	 * @param mixed $output    Robots.txt content.
	 * @param mixed $is_public Whether the site is public.
	 */
	public function robots_txt( mixed $output, mixed $is_public ): string {
		$output = (string) $output;
		if ( ! $is_public ) {
			return $output;
		}
		$url = Builder::url( 'index' );
		if ( preg_match( '/^\s*sitemap:\s*' . preg_quote( $url, '/' ) . '\s*$/mi', $output ) ) {
			return $output;
		}
		return rtrim( $output ) . ( '' !== trim( $output ) ? "\n\n" : '' ) . 'Sitemap: ' . $url . "\n";
	}

	/**
	 * Invalidate on post save (public post types only).
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public function invalidate_post( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'auto-draft' === $post->post_status ) {
			return;
		}
		Cache::bump();
	}

	/**
	 * Invalidate when a post enters or leaves the published state.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public function invalidate_status( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( $new_status !== $old_status && ( 'publish' === $new_status || 'publish' === $old_status ) ) {
			Cache::bump();
		}
	}

	/**
	 * Invalidate when SEO meta or the featured image changes.
	 *
	 * @param mixed  $meta_id   Meta id(s).
	 * @param int    $object_id Object id.
	 * @param string $meta_key  Meta key.
	 */
	public function invalidate_meta( mixed $meta_id, int $object_id, string $meta_key ): void {
		if ( str_starts_with( $meta_key, '_mgseo_' ) || '_thumbnail_id' === $meta_key ) {
			Cache::bump();
		}
	}

	/**
	 * Data removal.
	 */
	public function uninstall(): void {
		Cache::purge();
	}

	/**
	 * Comma separated ids sanitizer.
	 *
	 * @param mixed $value Raw.
	 */
	public static function sanitize_ids( mixed $value ): string {
		$value = is_array( $value ) ? $value : preg_split( '/[\s,]+/', (string) $value );
		$ids   = array_unique( array_filter( array_map( 'absint', (array) $value ) ) );
		return implode( ', ', $ids );
	}

	/**
	 * Settings schema.
	 */
	public function settings(): array {
		$ids_desc = __( 'Comma separated IDs.', 'mg-seo' );

		$general = array(
			array(
				'id'       => 'sitemap_links',
				'type'     => 'callback',
				'label'    => __( 'Your sitemaps', 'mg-seo' ),
				'callback' => array( $this, 'render_links' ),
			),
			array(
				'id'      => 'links_per_sitemap',
				'type'    => 'number',
				'label'   => __( 'Links per sitemap', 'mg-seo' ),
				'default' => 200,
				'min'     => 1,
				'max'     => 50000,
				'desc'    => __( 'Larger lists are split into numbered sitemaps (maximum 50,000).', 'mg-seo' ),
			),
			array(
				'id'           => 'include_images',
				'type'         => 'toggle',
				'label'        => __( 'Images', 'mg-seo' ),
				'toggle_label' => __( 'List images found in the content of each post', 'mg-seo' ),
				'default'      => true,
			),
			array(
				'id'           => 'include_featured',
				'type'         => 'toggle',
				'label'        => __( 'Featured image', 'mg-seo' ),
				'toggle_label' => __( 'Also list the featured image', 'mg-seo' ),
				'default'      => true,
				'show_if'      => 'include_images',
			),
			array(
				'id'       => 'exclude_posts',
				'type'     => 'text',
				'label'    => __( 'Exclude posts', 'mg-seo' ),
				'default'  => '',
				'desc'     => $ids_desc . ' ' . __( 'Posts, pages or other entries that never appear in sitemaps.', 'mg-seo' ),
				'sanitize' => array( self::class, 'sanitize_ids' ),
			),
			array(
				'id'       => 'exclude_terms',
				'type'     => 'text',
				'label'    => __( 'Exclude terms', 'mg-seo' ),
				'default'  => '',
				'desc'     => $ids_desc . ' ' . __( 'Categories, tags or other terms that never appear in sitemaps.', 'mg-seo' ),
				'sanitize' => array( self::class, 'sanitize_ids' ),
			),
			array(
				'id'           => 'stylesheet',
				'type'         => 'toggle',
				'label'        => __( 'Stylesheet', 'mg-seo' ),
				'toggle_label' => __( 'Show sitemaps as a readable table in browsers (served from /sitemap.xsl)', 'mg-seo' ),
				'default'      => true,
			),
		);

		$pt_fields = array();
		foreach ( Context::post_types() as $name => $type ) {
			$pt_fields[] = array(
				'id'    => 'pt_' . $name . '_heading',
				'type'  => 'heading',
				'label' => $type->labels->name,
			);
			$pt_fields[] = array(
				'id'           => 'pt_' . $name . '_include',
				'type'         => 'toggle',
				'label'        => __( 'Include in sitemap', 'mg-seo' ),
				/* translators: %s: sitemap file name */
				'toggle_label' => sprintf( __( 'List entries in %s', 'mg-seo' ), $name . '-sitemap.xml' ),
				'default'      => true,
			);
			$pt_fields[] = array(
				'id'       => 'pt_' . $name . '_exclude',
				'type'     => 'text',
				'label'    => __( 'Exclude IDs', 'mg-seo' ),
				'default'  => '',
				'desc'     => $ids_desc,
				'sanitize' => array( self::class, 'sanitize_ids' ),
				'show_if'  => 'pt_' . $name . '_include',
			);
		}

		$tax_fields = array();
		foreach ( Context::taxonomies() as $name => $tax ) {
			$tax_fields[] = array(
				'id'    => 'tax_' . $name . '_heading',
				'type'  => 'heading',
				'label' => $tax->labels->name,
			);
			$tax_fields[] = array(
				'id'           => 'tax_' . $name . '_include',
				'type'         => 'toggle',
				'label'        => __( 'Include in sitemap', 'mg-seo' ),
				/* translators: %s: sitemap file name */
				'toggle_label' => sprintf( __( 'List terms in %s', 'mg-seo' ), $name . '-sitemap.xml' ),
				'default'      => 'category' === $name,
			);
			$tax_fields[] = array(
				'id'           => 'tax_' . $name . '_hide_empty',
				'type'         => 'toggle',
				'label'        => __( 'Empty terms', 'mg-seo' ),
				'toggle_label' => __( 'Hide terms without published posts', 'mg-seo' ),
				'default'      => true,
				'show_if'      => 'tax_' . $name . '_include',
			);
		}

		$roles = array();
		foreach ( wp_roles()->get_names() as $role => $label ) {
			$roles[ $role ] = translate_user_role( $label );
		}

		$post_type_options = array();
		foreach ( Context::post_types() as $name => $type ) {
			$post_type_options[ $name ] = $type->labels->name;
		}
		$categories = array();
		$terms      = get_terms(
			array(
				'taxonomy'   => 'category',
				'hide_empty' => false,
				'number'     => 300,
				'orderby'    => 'name',
			)
		);
		if ( is_array( $terms ) ) {
			foreach ( $terms as $term ) {
				$categories[ (string) $term->term_id ] = $term->name;
			}
		}

		return array(
			array(
				'id'          => 'general',
				'title'       => __( 'General', 'mg-seo' ),
				'description' => __( 'WordPress core sitemaps are turned off while this module is active; their URLs redirect to the sitemap index. Search engines are not pinged: the sitemap URL is added to robots.txt instead.', 'mg-seo' ),
				'fields'      => $general,
			),
			array(
				'id'          => 'post_types',
				'title'       => __( 'Post types', 'mg-seo' ),
				'description' => __( 'Password protected, non-published and noindex entries, entries excluded in the SEO box and entries whose canonical URL points elsewhere are always left out.', 'mg-seo' ),
				'fields'      => $pt_fields,
			),
			array(
				'id'          => 'taxonomies',
				'title'       => __( 'Taxonomies', 'mg-seo' ),
				'description' => __( 'Terms set to noindex (per term or for the whole taxonomy under Titles & Meta) are left out.', 'mg-seo' ),
				'fields'      => $tax_fields,
			),
			array(
				'id'     => 'authors',
				'title'  => __( 'Authors', 'mg-seo' ),
				'fields' => array(
					array(
						'id'           => 'authors',
						'type'         => 'toggle',
						'label'        => __( 'Author sitemap', 'mg-seo' ),
						'toggle_label' => __( 'List author archives of users with published posts (author-sitemap.xml)', 'mg-seo' ),
						'default'      => true,
						'desc'         => __( 'Not generated when author archives are disabled or set to noindex under Titles & Meta.', 'mg-seo' ),
					),
					array(
						'id'      => 'author_exclude_roles',
						'type'    => 'multicheck',
						'label'   => __( 'Exclude roles', 'mg-seo' ),
						'options' => $roles,
						'default' => array(),
						'show_if' => 'authors',
					),
				),
			),
			array(
				'id'          => 'news',
				'title'       => __( 'News sitemap', 'mg-seo' ),
				'description' => __( 'Google News sitemap (news-sitemap.xml). As required by Google News it lists only articles published in the last 48 hours, at most 1,000.', 'mg-seo' ),
				'fields'      => array(
					array(
						'id'           => 'news',
						'type'         => 'toggle',
						'label'        => __( 'News sitemap', 'mg-seo' ),
						'toggle_label' => __( 'Generate a Google News sitemap', 'mg-seo' ),
						'default'      => false,
					),
					array(
						'id'          => 'news_name',
						'type'        => 'text',
						'label'       => __( 'Publication name', 'mg-seo' ),
						'default'     => '',
						'placeholder' => get_bloginfo( 'name' ),
						'desc'        => __( 'Must match the name in Google News. Defaults to the site title.', 'mg-seo' ),
						'show_if'     => 'news',
					),
					array(
						'id'          => 'news_language',
						'type'        => 'text',
						'label'       => __( 'Publication language', 'mg-seo' ),
						'default'     => '',
						'placeholder' => Builder::locale_language(),
						'desc'        => __( 'ISO 639 language code, e.g. en, pl, de (zh-cn or zh-tw for Chinese). Defaults to the site language.', 'mg-seo' ),
						'sanitize'    => static fn( $value ): string => strtolower( (string) preg_replace( '/[^A-Za-z-]/', '', (string) $value ) ),
						'show_if'     => 'news',
					),
					array(
						'id'      => 'news_post_types',
						'type'    => 'multicheck',
						'label'   => __( 'Post types', 'mg-seo' ),
						'options' => $post_type_options,
						'default' => array( 'post' ),
						'show_if' => 'news',
					),
					array(
						'id'      => 'news_exclude_terms',
						'type'    => 'multicheck',
						'label'   => __( 'Exclude categories', 'mg-seo' ),
						'options' => $categories,
						'default' => array(),
						'desc'    => __( 'Single posts can also be excluded in the SEO box.', 'mg-seo' ),
						'show_if' => 'news',
					),
				),
			),
			array(
				'id'          => 'video',
				'title'       => __( 'Video sitemap', 'mg-seo' ),
				'description' => __( 'Video sitemap (video-sitemap.xml) built from VideoObject schema data and, optionally, from YouTube and Vimeo embeds, video blocks, [video] shortcodes and attached video files. No external requests are made: YouTube thumbnails use the standard image URL, Vimeo videos use the featured image as thumbnail.', 'mg-seo' ),
				'fields'      => array(
					array(
						'id'           => 'video',
						'type'         => 'toggle',
						'label'        => __( 'Video sitemap', 'mg-seo' ),
						'toggle_label' => __( 'Generate a video sitemap', 'mg-seo' ),
						'default'      => false,
					),
					array(
						'id'      => 'video_post_types',
						'type'    => 'multicheck',
						'label'   => __( 'Post types', 'mg-seo' ),
						'options' => $post_type_options,
						'default' => array( 'post', 'page' ),
						'show_if' => 'video',
					),
					array(
						'id'           => 'video_detect',
						'type'         => 'toggle',
						'label'        => __( 'Auto-detect videos', 'mg-seo' ),
						'toggle_label' => __( 'Find videos in the content (otherwise only VideoObject schema is used)', 'mg-seo' ),
						'default'      => true,
						'show_if'      => 'video',
					),
				),
			),
		);
	}

	/**
	 * Settings field: links to the generated sitemaps.
	 */
	public function render_links(): void {
		$index = Builder::url( 'index' );
		echo '<p><a href="' . esc_url( $index ) . '" target="_blank" rel="noopener"><strong>' . esc_html( $index ) . '</strong></a></p>';
		$entries = $this->builder()->index_entries();
		if ( $entries ) {
			echo '<ul class="mgseo-sitemap-links">';
			foreach ( $entries as $entry ) {
				echo '<li><a href="' . esc_url( $entry['loc'] ) . '" target="_blank" rel="noopener">' . esc_html( $entry['loc'] ) . '</a></li>';
			}
			echo '</ul>';
		} else {
			echo '<p>' . esc_html__( 'No content is listed yet.', 'mg-seo' ) . '</p>';
		}
		echo '<p class="description">' . esc_html__( 'Submit the sitemap index in Google Search Console and Bing Webmaster Tools. It is also listed in robots.txt when the site is visible to search engines.', 'mg-seo' ) . '</p>';
		if ( ! get_option( 'blog_public' ) ) {
			echo '<p class="description"><strong>' . esc_html__( 'Search engines are discouraged from indexing this site (Settings → Reading).', 'mg-seo' ) . '</strong></p>';
		}
	}
}
