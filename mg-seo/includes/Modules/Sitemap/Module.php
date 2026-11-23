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
 * Public URLs: /sitemap.xml (index), /sitemap-{name}.xml, /sitemap-{name}-{N}.xml (page N ≥ 2), /sitemap.xsl.
 * {name} is 'article' (posts), 'page', 'category', 'tag', 'author', 'news', 'video' or the slug of another post type
 * or taxonomy (rule in Builder::slugs()). Old addresses (sitemap_index.xml, {name}-sitemap{N}.xml of MG SEO before
 * 0.3.2, Rank Math and Yoast, and the core wp-sitemap*.xml) redirect (301) to the new ones while the
 * "legacy_redirects" setting is on, otherwise they are a plain 404.
 */
final class Module extends Base {

	public const QUERY_VAR      = 'xml_sitemap';
	public const QUERY_VAR_PAGE = 'xml_sitemap_page';

	/**
	 * Sitemap names served by other modules: /sitemap-locations.xml (Local SEO).
	 */
	public const EXTERNAL = array( 'locations' );

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
	 *
	 * Requests are resolved from the path in parse_request() (match_path()), so the rules mainly keep WordPress from
	 * treating these addresses as pages; old rules left from an earlier version keep working until the next flush.
	 */
	public function rewrite_rules(): void {
		$var      = self::QUERY_VAR;
		$page     = self::QUERY_VAR_PAGE;
		$external = implode( '|', array_map( static fn( $name ) => preg_quote( (string) $name, '/' ), self::external() ) );
		add_rewrite_rule( '^sitemap\.xml$', 'index.php?' . $var . '=index', 'top' );
		add_rewrite_rule( '^sitemap\.xsl$', 'index.php?' . $var . '=xsl', 'top' );
		add_rewrite_rule( '^sitemap-' . ( '' !== $external ? '(?!(?:' . $external . ')\.xml$)' : '' ) . '([a-z0-9-]+?)(?:-([0-9]+))?\.xml$', 'index.php?' . $var . '=$matches[1]&' . $page . '=$matches[2]', 'top' );
		// Old addresses, with the same targets as before 0.3.2 so a downgrade still works without a flush.
		add_rewrite_rule( '^sitemap_index\.xml$', 'index.php?' . $var . '=index', 'top' );
		add_rewrite_rule( '^([a-z0-9_-]+?)-sitemap([0-9]+)?\.xml$', 'index.php?' . $var . '=$matches[1]&' . $page . '=$matches[2]', 'top' );
	}

	/**
	 * Sitemap names served by other modules (never handled here).
	 *
	 * @return string[]
	 */
	public static function external(): array {
		/**
		 * Filter the /sitemap-{name}.xml names that other code serves (default: locations, Local SEO).
		 *
		 * @param string[] $names Names.
		 */
		return array_values( array_filter( array_map( 'strval', (array) apply_filters( 'mgseo/sitemap/external', self::EXTERNAL ) ) ) );
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
	 * Resolve a request path to array( name, page ) or null when the path is not a sitemap address of this module.
	 *
	 * Names: 'index', 'xsl', a sitemap name, 'legacy:<old name>' (old scheme, 'legacy:index' for sitemap_index.xml)
	 * or 'core:<path>' (WordPress core sitemaps). Page 0 marks an invalid page number (e.g. sitemap-page-1.xml:
	 * page 1 has no number).
	 *
	 * @param string $path Request path relative to home, without slashes.
	 * @return array{0: string, 1: int}|null
	 */
	public static function match_path( string $path ): ?array {
		if ( 'sitemap.xml' === $path ) {
			return array( 'index', 1 );
		}
		if ( 'sitemap.xsl' === $path ) {
			return array( 'xsl', 1 );
		}
		if ( preg_match( '/^sitemap-([a-z0-9-]+?)(?:-([0-9]+))?\.xml$/', $path, $m ) ) {
			if ( in_array( $m[1], self::external(), true ) && ! isset( $m[2] ) ) {
				return null;
			}
			return array( $m[1], isset( $m[2] ) ? ( (int) $m[2] >= 2 && ! str_starts_with( $m[2], '0' ) ? (int) $m[2] : 0 ) : 1 );
		}
		if ( 'sitemap_index.xml' === $path ) {
			return array( 'legacy:index', 1 );
		}
		if ( preg_match( '/^wp-sitemap(?:-[a-z0-9_-]+)?\.xml$/', $path ) ) {
			return array( 'core:' . $path, 1 );
		}
		if ( preg_match( '/^([a-z0-9_-]+?)-sitemap([0-9]+)?\.xml$/', $path, $m ) ) {
			return array( 'legacy:' . $m[1], isset( $m[2] ) ? max( 1, (int) $m[2] ) : 1 );
		}
		return null;
	}

	/**
	 * Serve sitemap requests early (before the main query runs).
	 *
	 * @param \WP $wp WordPress request.
	 */
	public function parse_request( \WP $wp ): void {
		$matched = self::match_path( Helpers::request_path() );
		if ( ! $matched && ! empty( $wp->query_vars[ self::QUERY_VAR ] ) ) {
			// Plain permalinks: index.php?xml_sitemap=article&xml_sitemap_page=2.
			$name    = sanitize_key( (string) $wp->query_vars[ self::QUERY_VAR ] );
			$page    = isset( $wp->query_vars[ self::QUERY_VAR_PAGE ] ) && '' !== $wp->query_vars[ self::QUERY_VAR_PAGE ] ? (int) $wp->query_vars[ self::QUERY_VAR_PAGE ] : 1;
			$matched = array( $name, $page );
		}
		if ( ! $matched ) {
			return;
		}
		[ $name, $page ] = $matched;
		if ( str_starts_with( $name, 'core:' ) && isset( $wp->query_vars['error'] ) && '404' === (string) $wp->query_vars['error'] ) {
			// Another plugin already turned the core address into a 404 (e.g. MG Stealth hides wp-* paths).
			return;
		}

		$response = $this->response( $name, $page );
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
	 * Whether old sitemap addresses redirect to the new ones.
	 */
	public static function legacy_redirects(): bool {
		return (bool) Options::get( 'sitemap', 'legacy_redirects', true );
	}

	/**
	 * Build the response for a sitemap request.
	 *
	 * @param string $name Name from match_path(): index, xsl, a sitemap name, legacy:<old> or core:<path>.
	 * @param int    $page Page (0 = invalid).
	 * @return array{redirect?: string, body?: string}|null Null for 404.
	 */
	public function response( string $name, int $page = 1 ): ?array {
		if ( str_starts_with( $name, 'legacy:' ) || str_starts_with( $name, 'core:' ) ) {
			if ( ! self::legacy_redirects() ) {
				return null;
			}
			$target = str_starts_with( $name, 'legacy:' )
				? $this->builder()->legacy_target( substr( $name, 7 ), $page )
				: $this->builder()->core_target( substr( $name, 5 ) );
			return $target ? array( 'redirect' => Builder::url( $target[0], $target[1] ) ) : null;
		}
		if ( 'xsl' === $name ) {
			if ( ! Options::get( 'sitemap', 'stylesheet', true ) ) {
				return null;
			}
			return array( 'body' => Stylesheet::xsl() );
		}
		if ( $page < 1 ) {
			return null;
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
		// Drop lines with this site's old index addresses (e.g. left in a custom robots.txt after an import).
		$home   = preg_quote( untrailingslashit( (string) preg_replace( '~^https?://~i', '', home_url() ) ), '/' );
		$output = (string) preg_replace( '/^[ \t]*sitemap:[ \t]*https?:\/\/' . $home . '\/(?:sitemap_index|wp-sitemap)\.xml[ \t]*(?:\r?\n|$)/mi', '', $output );
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
			array(
				'id'           => 'legacy_redirects',
				'type'         => 'toggle',
				'label'        => __( 'Old addresses', 'mg-seo' ),
				'toggle_label' => __( 'Redirect old sitemap addresses to the new ones (301)', 'mg-seo' ),
				'default'      => true,
				'desc'         => __( 'sitemap_index.xml, post-sitemap.xml, page-sitemap.xml and the other names used by earlier versions, Rank Math and Yoast, and the WordPress addresses wp-sitemap.xml …, so sitemaps submitted earlier in Search Console keep working. When off, these addresses return a normal 404 page.', 'mg-seo' ),
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
				'toggle_label' => sprintf( __( 'List entries in %s', 'mg-seo' ), 'sitemap-' . $this->builder()->slug( 'post_type', $name ) . '.xml' ),
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
				'toggle_label' => sprintf( __( 'List terms in %s', 'mg-seo' ), 'sitemap-' . $this->builder()->slug( 'taxonomy', $name ) . '.xml' ),
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
				'description' => __( 'The sitemap index is /sitemap.xml; it lists /sitemap-article.xml (posts), /sitemap-page.xml, /sitemap-category.xml, /sitemap-author.xml and /sitemap-{name}.xml for other content types, numbered /sitemap-{name}-2.xml … when split. WordPress core sitemaps are turned off while this module is active. Search engines are not pinged: the sitemap URL is added to robots.txt instead.', 'mg-seo' ),
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
						'toggle_label' => __( 'List author archives of users with published posts (sitemap-author.xml)', 'mg-seo' ),
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
				'description' => __( 'Google News sitemap (sitemap-news.xml). As required by Google News it lists only articles published in the last 48 hours, at most 1,000.', 'mg-seo' ),
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
				'description' => __( 'Video sitemap (sitemap-video.xml) built from VideoObject schema data and, optionally, from YouTube and Vimeo embeds, video blocks, [video] shortcodes and attached video files. No external requests are made: YouTube thumbnails use the standard image URL, Vimeo videos use the featured image as thumbnail.', 'mg-seo' ),
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
