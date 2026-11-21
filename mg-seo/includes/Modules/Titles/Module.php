<?php
/**
 * Titles & Meta module.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Titles;

use MGSEO\Context;
use MGSEO\Head;
use MGSEO\Helpers;
use MGSEO\Meta;
use MGSEO\Module as Base;
use MGSEO\Options;
use MGSEO\Variables;

defined( 'ABSPATH' ) || exit;

/**
 * Title, description, robots, canonical, pagination links, verification, RSS and attachment options.
 */
final class Module extends Base {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'titles';
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'pre_get_document_title', array( $this, 'filter_title' ), 15 );
		add_filter( 'wp_robots', array( $this, 'filter_robots' ), 20 );
		add_action( 'mgseo/head', array( $this, 'verification' ), 5 );
		add_action( 'mgseo/head', array( $this, 'description' ), 10 );
		add_action( 'mgseo/head', array( $this, 'canonical' ), 20 );
		add_action( 'mgseo/head', array( $this, 'pagination_links' ), 25 );
		add_action( 'template_redirect', array( $this, 'attachment_redirect' ), 1 );
		add_filter( 'the_content_feed', array( $this, 'rss_content' ) );
		add_filter( 'the_excerpt_rss', array( $this, 'rss_content' ) );
		// Core outputs its own canonical; ours replaces it.
		remove_action( 'wp_head', 'rel_canonical' );
		remove_filter( 'wp_robots', 'wp_robots_max_image_preview_large' );
	}

	/**
	 * Settings schema.
	 */
	public function settings(): array {
		$robots_options = array_merge(
			array( 'noindex' => __( 'No Index', 'mg-seo' ) ),
			array_diff_key( Helpers::robots_directives(), array( 'noindex' => true ) )
		);
		$separators     = array(
			'-'  => '-',
			'–'  => '–',
			'—'  => '—',
			'|'  => '|',
			'·'  => '·',
			'•'  => '•',
			'»'  => '»',
			'/'  => '/',
			'~'  => '~',
			':'  => ':',
		);

		$tabs = array(
			array(
				'id'     => 'general',
				'title'  => __( 'General', 'mg-seo' ),
				'fields' => array(
					array(
						'id'      => 'separator',
						'type'    => 'select',
						'label'   => __( 'Title separator', 'mg-seo' ),
						'options' => $separators,
						'default' => '-',
						'desc'    => __( 'Used by the %sep% variable.', 'mg-seo' ),
					),
					array(
						'id'           => 'capitalize',
						'type'         => 'toggle',
						'label'        => __( 'Capitalize titles', 'mg-seo' ),
						'toggle_label' => __( 'Uppercase the first letter of every word in SEO titles', 'mg-seo' ),
						'default'      => false,
					),
					array(
						'id'      => 'robots_global',
						'type'    => 'multicheck',
						'label'   => __( 'Global robots meta', 'mg-seo' ),
						'options' => array_diff_key( $robots_options, array( 'noindex' => true ) ),
						'default' => array(),
						'desc'    => __( 'Applied to every page unless overridden.', 'mg-seo' ),
					),
					array(
						'id'      => 'max_snippet',
						'type'    => 'number',
						'label'   => __( 'Max snippet length', 'mg-seo' ),
						'default' => -1,
						'min'     => -1,
						'desc'    => __( '-1 = no limit.', 'mg-seo' ),
					),
					array(
						'id'      => 'max_video_preview',
						'type'    => 'number',
						'label'   => __( 'Max video preview (seconds)', 'mg-seo' ),
						'default' => -1,
						'min'     => -1,
					),
					array(
						'id'      => 'max_image_preview',
						'type'    => 'select',
						'label'   => __( 'Max image preview', 'mg-seo' ),
						'options' => array(
							'large'    => 'large',
							'standard' => 'standard',
							'none'     => 'none',
						),
						'default' => 'large',
					),
					array(
						'id'           => 'noindex_empty_terms',
						'type'         => 'toggle',
						'label'        => __( 'Empty archives', 'mg-seo' ),
						'toggle_label' => __( 'No Index categories, tags and terms without posts', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'           => 'noindex_paged',
						'type'         => 'toggle',
						'label'        => __( 'Paginated archives', 'mg-seo' ),
						'toggle_label' => __( 'No Index page 2+ of archives', 'mg-seo' ),
						'default'      => false,
					),
					array(
						'id'           => 'noindex_password',
						'type'         => 'toggle',
						'label'        => __( 'Password protected', 'mg-seo' ),
						'toggle_label' => __( 'No Index password protected posts', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'           => 'rel_prev_next',
						'type'         => 'toggle',
						'label'        => __( 'Pagination links', 'mg-seo' ),
						'toggle_label' => __( 'Output rel="prev"/"next" on paginated archives', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'           => 'attachment_redirect',
						'type'         => 'toggle',
						'label'        => __( 'Attachment pages', 'mg-seo' ),
						'toggle_label' => __( 'Redirect attachment pages to the parent post (or the file)', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'           => 'stealth',
						'type'         => 'toggle',
						'label'        => __( 'No fingerprint mode', 'mg-seo' ),
						'toggle_label' => __( 'Hide every trace of the plugin on the front end (REST index, asset URLs, sitemap stylesheet path)', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'      => 'metabox_hidden',
						'type'    => 'multicheck',
						'label'   => __( 'Hide SEO box on', 'mg-seo' ),
						'options' => array_map( static fn( $t ) => $t->labels->name, Context::post_types() ),
						'default' => array(),
					),
					array(
						'id'    => 'rss_heading',
						'type'  => 'heading',
						'label' => __( 'RSS feed', 'mg-seo' ),
						'desc'  => __( 'Text added to every feed item. Variables: %postlink%, %sitelink%, %title%, %sitename%.', 'mg-seo' ),
					),
					array(
						'id'      => 'rss_before',
						'type'    => 'textarea',
						'label'   => __( 'Before content', 'mg-seo' ),
						'default' => '',
					),
					array(
						'id'      => 'rss_after',
						'type'    => 'textarea',
						'label'   => __( 'After content', 'mg-seo' ),
						'default' => '',
					),
				),
			),
			array(
				'id'     => 'home',
				'title'  => __( 'Homepage', 'mg-seo' ),
				'fields' => array(
					array(
						'id'        => 'home_title',
						'type'      => 'text',
						'label'     => __( 'Homepage title', 'mg-seo' ),
						'default'   => '%sitename% %page% %sep% %sitedesc%',
						'variables' => true,
						'desc'      => __( 'Used when the front page shows latest posts, or as fallback for a static front page without its own SEO title.', 'mg-seo' ),
					),
					array(
						'id'        => 'home_description',
						'type'      => 'textarea',
						'label'     => __( 'Homepage description', 'mg-seo' ),
						'default'   => '%sitedesc%',
						'variables' => true,
					),
					array(
						'id'      => 'home_robots',
						'type'    => 'multicheck',
						'label'   => __( 'Homepage robots', 'mg-seo' ),
						'options' => $robots_options,
						'default' => array(),
					),
					array(
						'id'      => 'home_og_image',
						'type'    => 'image',
						'label'   => __( 'Homepage social image', 'mg-seo' ),
						'default' => '',
					),
				),
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
				'id'        => 'pt_' . $name . '_title',
				'type'      => 'text',
				'label'     => __( 'Single title', 'mg-seo' ),
				'default'   => '%title% %page% %sep% %sitename%',
				'variables' => true,
			);
			$pt_fields[] = array(
				'id'        => 'pt_' . $name . '_description',
				'type'      => 'textarea',
				'label'     => __( 'Single description', 'mg-seo' ),
				'default'   => '%excerpt%',
				'variables' => true,
			);
			$pt_fields[] = array(
				'id'      => 'pt_' . $name . '_robots',
				'type'    => 'multicheck',
				'label'   => __( 'Robots meta', 'mg-seo' ),
				'options' => $robots_options,
				'default' => array(),
			);
			if ( $type->has_archive ) {
				$pt_fields[] = array(
					'id'        => 'pt_' . $name . '_archive_title',
					'type'      => 'text',
					'label'     => __( 'Archive title', 'mg-seo' ),
					'default'   => '%pt_plural% %page% %sep% %sitename%',
					'variables' => true,
				);
				$pt_fields[] = array(
					'id'        => 'pt_' . $name . '_archive_description',
					'type'      => 'textarea',
					'label'     => __( 'Archive description', 'mg-seo' ),
					'default'   => '',
					'variables' => true,
				);
			}
		}
		$tabs[] = array(
			'id'     => 'post_types',
			'title'  => __( 'Post types', 'mg-seo' ),
			'fields' => $pt_fields,
		);

		$tax_fields = array();
		foreach ( Context::taxonomies() as $name => $tax ) {
			$tax_fields[] = array(
				'id'    => 'tax_' . $name . '_heading',
				'type'  => 'heading',
				'label' => $tax->labels->name,
			);
			$tax_fields[] = array(
				'id'        => 'tax_' . $name . '_title',
				'type'      => 'text',
				'label'     => __( 'Archive title', 'mg-seo' ),
				'default'   => '%term% %page% %sep% %sitename%',
				'variables' => true,
			);
			$tax_fields[] = array(
				'id'        => 'tax_' . $name . '_description',
				'type'      => 'textarea',
				'label'     => __( 'Archive description', 'mg-seo' ),
				'default'   => '%term_description%',
				'variables' => true,
			);
			$tax_fields[] = array(
				'id'      => 'tax_' . $name . '_robots',
				'type'    => 'multicheck',
				'label'   => __( 'Robots meta', 'mg-seo' ),
				'options' => $robots_options,
				'default' => 'post_tag' === $name ? array( 'noindex' ) : array(),
			);
		}
		$tabs[] = array(
			'id'     => 'taxonomies',
			'title'  => __( 'Taxonomies', 'mg-seo' ),
			'fields' => $tax_fields,
		);

		$tabs[] = array(
			'id'     => 'archives',
			'title'  => __( 'Other pages', 'mg-seo' ),
			'fields' => array(
				array(
					'id'           => 'author_archive',
					'type'         => 'toggle',
					'label'        => __( 'Author archives', 'mg-seo' ),
					'toggle_label' => __( 'Enabled (disable to redirect author archives to the homepage)', 'mg-seo' ),
					'default'      => true,
				),
				array(
					'id'        => 'author_title',
					'type'      => 'text',
					'label'     => __( 'Author archive title', 'mg-seo' ),
					'default'   => '%name% %page% %sep% %sitename%',
					'variables' => true,
				),
				array(
					'id'        => 'author_description',
					'type'      => 'textarea',
					'label'     => __( 'Author archive description', 'mg-seo' ),
					'default'   => '',
					'variables' => true,
				),
				array(
					'id'      => 'author_robots',
					'type'    => 'multicheck',
					'label'   => __( 'Author robots', 'mg-seo' ),
					'options' => $robots_options,
					'default' => array(),
				),
				array(
					'id'           => 'date_archive',
					'type'         => 'toggle',
					'label'        => __( 'Date archives', 'mg-seo' ),
					'toggle_label' => __( 'Enabled (disable to redirect date archives to the homepage)', 'mg-seo' ),
					'default'      => true,
				),
				array(
					'id'        => 'date_title',
					'type'      => 'text',
					'label'     => __( 'Date archive title', 'mg-seo' ),
					'default'   => '%title% %page% %sep% %sitename%',
					'variables' => true,
				),
				array(
					'id'      => 'date_robots',
					'type'    => 'multicheck',
					'label'   => __( 'Date archive robots', 'mg-seo' ),
					'options' => $robots_options,
					'default' => array( 'noindex' ),
				),
				array(
					'id'        => 'blog_title',
					'type'      => 'text',
					'label'     => __( 'Posts page title', 'mg-seo' ),
					'default'   => '%title% %page% %sep% %sitename%',
					'variables' => true,
					'desc'      => __( 'When a static page shows the latest posts and has no SEO title of its own.', 'mg-seo' ),
				),
				array(
					'id'        => 'search_title',
					'type'      => 'text',
					'label'     => __( 'Search results title', 'mg-seo' ),
					/* translators: search title template */
					'default'   => __( 'Search: %search_query% %page% %sep% %sitename%', 'mg-seo' ),
					'variables' => true,
				),
				array(
					'id'        => '404_title',
					'type'      => 'text',
					'label'     => __( '404 title', 'mg-seo' ),
					'default'   => __( 'Page not found %sep% %sitename%', 'mg-seo' ),
					'variables' => true,
				),
			),
		);

		$tabs[] = array(
			'id'     => 'verification',
			'title'  => __( 'Webmaster tools', 'mg-seo' ),
			'fields' => array(
				array(
					'id'      => 'verify_google',
					'type'    => 'text',
					'label'   => __( 'Google Search Console', 'mg-seo' ),
					'default' => '',
					'desc'    => __( 'Content of the google-site-verification meta tag (or the whole tag).', 'mg-seo' ),
				),
				array(
					'id'      => 'verify_bing',
					'type'    => 'text',
					'label'   => __( 'Bing Webmaster Tools', 'mg-seo' ),
					'default' => '',
				),
				array(
					'id'      => 'verify_yandex',
					'type'    => 'text',
					'label'   => __( 'Yandex', 'mg-seo' ),
					'default' => '',
				),
				array(
					'id'      => 'verify_baidu',
					'type'    => 'text',
					'label'   => __( 'Baidu', 'mg-seo' ),
					'default' => '',
				),
				array(
					'id'      => 'verify_pinterest',
					'type'    => 'text',
					'label'   => __( 'Pinterest', 'mg-seo' ),
					'default' => '',
				),
				array(
					'id'      => 'verify_facebook',
					'type'    => 'text',
					'label'   => __( 'Facebook domain verification', 'mg-seo' ),
					'default' => '',
				),
				array(
					'id'      => 'verify_norton',
					'type'    => 'text',
					'label'   => __( 'Norton Safe Web', 'mg-seo' ),
					'default' => '',
				),
			),
		);

		return $tabs;
	}

	/**
	 * Filter document title.
	 *
	 * @param string $title Title from other filters.
	 */
	public function filter_title( string $title ): string {
		if ( is_feed() || is_admin() ) {
			return $title;
		}
		$ours = self::document_title();
		return '' !== $ours ? $ours : $title;
	}

	/**
	 * Resolve the SEO title for the current request.
	 */
	public static function document_title(): string {
		$ctx = Context::current();
		$obj = $ctx['object'];
		$tpl = '';

		switch ( $ctx['type'] ) {
			case Context::SINGULAR:
				if ( $obj instanceof \WP_Post ) {
					$tpl = self::post_title_template( $obj );
				}
				break;
			case Context::FRONT_PAGE:
				$custom = $obj instanceof \WP_Post ? (string) Meta::post( $obj->ID, 'title' ) : '';
				$tpl    = '' !== $custom ? $custom : (string) Options::get( 'titles', 'home_title', '%sitename% %page% %sep% %sitedesc%' );
				break;
			case Context::BLOG:
				$custom = $obj instanceof \WP_Post ? (string) Meta::post( $obj->ID, 'title' ) : '';
				$tpl    = '' !== $custom ? $custom : (string) Options::get( 'titles', $obj ? 'blog_title' : 'home_title', '%title% %page% %sep% %sitename%' );
				break;
			case Context::TERM:
				if ( $obj instanceof \WP_Term ) {
					$custom = (string) Meta::term( $obj->term_id, 'title' );
					$tpl    = '' !== $custom ? $custom : (string) Options::get( 'titles', 'tax_' . $obj->taxonomy . '_title', '%term% %page% %sep% %sitename%' );
				}
				break;
			case Context::AUTHOR:
				$custom = $obj instanceof \WP_User ? (string) Meta::user( $obj->ID, 'title' ) : '';
				$tpl    = '' !== $custom ? $custom : (string) Options::get( 'titles', 'author_title', '%name% %page% %sep% %sitename%' );
				break;
			case Context::POST_TYPE_ARCHIVE:
				if ( $obj instanceof \WP_Post_Type ) {
					$tpl = (string) Options::get( 'titles', 'pt_' . $obj->name . '_archive_title', '%pt_plural% %page% %sep% %sitename%' );
				}
				break;
			case Context::DATE:
				$tpl = (string) Options::get( 'titles', 'date_title', '%title% %page% %sep% %sitename%' );
				break;
			case Context::SEARCH:
				$tpl = (string) Options::get( 'titles', 'search_title', 'Search: %search_query% %page% %sep% %sitename%' );
				break;
			case Context::NOT_FOUND:
				$tpl = (string) Options::get( 'titles', '404_title', 'Page not found %sep% %sitename%' );
				break;
		}

		/**
		 * Filter the title template before variables are replaced.
		 *
		 * @param string $tpl Template.
		 * @param array  $ctx Context.
		 */
		$tpl = (string) apply_filters( 'mgseo/title_template', $tpl, $ctx );
		if ( '' === $tpl ) {
			return '';
		}
		$title = Variables::replace( $tpl, $obj instanceof \WP_Post_Type ? $obj : ( is_object( $obj ) ? $obj : null ) );
		if ( Options::get( 'titles', 'capitalize', false ) ) {
			$title = mb_convert_case( $title, MB_CASE_TITLE, 'UTF-8' );
		}
		/**
		 * Filter the final SEO title.
		 *
		 * @param string $title Title.
		 * @param array  $ctx   Context.
		 */
		return (string) apply_filters( 'mgseo/title', $title, $ctx );
	}

	/**
	 * Title template for a post (custom or post type default).
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function post_title_template( \WP_Post $post ): string {
		$custom = (string) Meta::post( $post->ID, 'title' );
		if ( '' !== $custom ) {
			return $custom;
		}
		return (string) Options::get( 'titles', 'pt_' . $post->post_type . '_title', '%title% %page% %sep% %sitename%' );
	}

	/**
	 * Resolved SEO title for an arbitrary post (sitemaps, schema, bulk edit).
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function post_title( \WP_Post $post ): string {
		return Variables::replace( self::post_title_template( $post ), $post );
	}

	/**
	 * Resolved meta description for an arbitrary post.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function post_description( \WP_Post $post ): string {
		$custom = (string) Meta::post( $post->ID, 'description' );
		$tpl    = '' !== $custom ? $custom : (string) Options::get( 'titles', 'pt_' . $post->post_type . '_description', '%excerpt%' );
		return Helpers::truncate( Variables::replace( $tpl, $post ), 320 );
	}

	/**
	 * Description for the current request.
	 */
	public static function current_description(): string {
		$ctx = Context::current();
		$obj = $ctx['object'];
		$tpl = '';
		switch ( $ctx['type'] ) {
			case Context::SINGULAR:
				if ( $obj instanceof \WP_Post ) {
					return (string) apply_filters( 'mgseo/description', self::post_description( $obj ), $ctx );
				}
				break;
			case Context::FRONT_PAGE:
				$custom = $obj instanceof \WP_Post ? (string) Meta::post( $obj->ID, 'description' ) : '';
				$tpl    = '' !== $custom ? $custom : (string) Options::get( 'titles', 'home_description', '%sitedesc%' );
				if ( '' === $custom && $obj instanceof \WP_Post && ! Options::get( 'titles', 'home_description', '' ) ) {
					$tpl = '%excerpt%';
				}
				break;
			case Context::BLOG:
				$custom = $obj instanceof \WP_Post ? (string) Meta::post( $obj->ID, 'description' ) : '';
				$tpl    = '' !== $custom ? $custom : ( $obj ? '' : (string) Options::get( 'titles', 'home_description', '%sitedesc%' ) );
				break;
			case Context::TERM:
				if ( $obj instanceof \WP_Term ) {
					$custom = (string) Meta::term( $obj->term_id, 'description' );
					$tpl    = '' !== $custom ? $custom : (string) Options::get( 'titles', 'tax_' . $obj->taxonomy . '_description', '%term_description%' );
				}
				break;
			case Context::AUTHOR:
				$custom = $obj instanceof \WP_User ? (string) Meta::user( $obj->ID, 'description' ) : '';
				$tpl    = '' !== $custom ? $custom : (string) Options::get( 'titles', 'author_description', '' );
				break;
			case Context::POST_TYPE_ARCHIVE:
				if ( $obj instanceof \WP_Post_Type ) {
					$tpl = (string) Options::get( 'titles', 'pt_' . $obj->name . '_archive_description', '' );
				}
				break;
		}
		$desc = '' === $tpl ? '' : Helpers::truncate( Variables::replace( $tpl, is_object( $obj ) ? $obj : null ), 320 );
		/**
		 * Filter the meta description.
		 *
		 * @param string $desc Description.
		 * @param array  $ctx  Context.
		 */
		return (string) apply_filters( 'mgseo/description', $desc, $ctx );
	}

	/**
	 * Print meta description.
	 */
	public function description(): void {
		Head::meta( 'description', self::current_description() );
	}

	/**
	 * Robots directives for the current request (array directive => true|string).
	 *
	 * @return array<string, bool|string>
	 */
	public static function current_robots(): array {
		$ctx    = Context::current();
		$obj    = $ctx['object'];
		$robots = (array) Options::get( 'titles', 'robots_global', array() );
		$own    = array();

		switch ( $ctx['type'] ) {
			case Context::SINGULAR:
				if ( $obj instanceof \WP_Post ) {
					$robots = array_merge( $robots, (array) Options::get( 'titles', 'pt_' . $obj->post_type . '_robots', array() ) );
					$own    = (array) Meta::post( $obj->ID, 'robots', array() );
					if ( post_password_required( $obj ) && Options::get( 'titles', 'noindex_password', true ) ) {
						$robots[] = 'noindex';
					}
				}
				break;
			case Context::FRONT_PAGE:
				$robots = array_merge( $robots, (array) Options::get( 'titles', 'home_robots', array() ) );
				$own    = $obj instanceof \WP_Post ? (array) Meta::post( $obj->ID, 'robots', array() ) : array();
				break;
			case Context::BLOG:
				$own = $obj instanceof \WP_Post ? (array) Meta::post( $obj->ID, 'robots', array() ) : array();
				break;
			case Context::TERM:
				if ( $obj instanceof \WP_Term ) {
					$robots = array_merge( $robots, (array) Options::get( 'titles', 'tax_' . $obj->taxonomy . '_robots', array() ) );
					$own    = (array) Meta::term( $obj->term_id, 'robots', array() );
					if ( 0 === (int) $obj->count && Options::get( 'titles', 'noindex_empty_terms', true ) ) {
						$robots[] = 'noindex';
					}
				}
				break;
			case Context::AUTHOR:
				$robots = array_merge( $robots, (array) Options::get( 'titles', 'author_robots', array() ) );
				$own    = $obj instanceof \WP_User ? (array) Meta::user( $obj->ID, 'robots', array() ) : array();
				break;
			case Context::DATE:
				$robots = array_merge( $robots, (array) Options::get( 'titles', 'date_robots', array( 'noindex' ) ) );
				break;
			case Context::SEARCH:
			case Context::NOT_FOUND:
				$robots[] = 'noindex';
				break;
		}

		if ( Context::page_number() > 1 && Options::get( 'titles', 'noindex_paged', false ) && ! is_singular() ) {
			$robots[] = 'noindex';
		}

		// Per-object override: explicit index/noindex replaces the inherited value; other directives add up.
		if ( in_array( 'index', $own, true ) ) {
			$robots = array_diff( $robots, array( 'noindex' ) );
		}
		$robots = array_unique( array_merge( $robots, array_diff( $own, array( 'index' ) ) ) );

		$out              = array();
		$noindex          = in_array( 'noindex', $robots, true );
		$out['index']     = ! $noindex;
		$out['noindex']   = $noindex;
		$nofollow         = in_array( 'nofollow', $robots, true );
		$out['follow']    = ! $nofollow;
		$out['nofollow']  = $nofollow;
		foreach ( array( 'noarchive', 'nosnippet', 'noimageindex', 'notranslate' ) as $directive ) {
			if ( in_array( $directive, $robots, true ) ) {
				$out[ $directive ] = true;
			}
		}

		if ( ! $noindex ) {
			$advanced = array(
				'max-snippet'       => (string) Options::get( 'titles', 'max_snippet', -1 ),
				'max-video-preview' => (string) Options::get( 'titles', 'max_video_preview', -1 ),
				'max-image-preview' => (string) Options::get( 'titles', 'max_image_preview', 'large' ),
			);
			$own_adv  = $obj instanceof \WP_Post ? (array) Meta::post( $obj->ID, 'advanced_robots', array() ) : array();
			foreach ( $own_adv as $key => $value ) {
				if ( isset( $advanced[ $key ] ) && '' !== (string) $value ) {
					$advanced[ $key ] = (string) $value;
				}
			}
			if ( empty( $out['nosnippet'] ) ) {
				$out = array_merge( $out, $advanced );
			}
		}
		/**
		 * Filter robots directives.
		 *
		 * @param array $out Directives.
		 * @param array $ctx Context.
		 */
		return apply_filters( 'mgseo/robots', $out, $ctx );
	}

	/**
	 * Merge our directives into core's wp_robots.
	 *
	 * @param array $robots Core directives.
	 * @return array
	 */
	public function filter_robots( array $robots ): array {
		if ( is_admin() ) {
			return $robots;
		}
		// Respect "discourage search engines": core already set noindex/nofollow.
		if ( ! get_option( 'blog_public' ) ) {
			return $robots;
		}
		foreach ( self::current_robots() as $key => $value ) {
			if ( false === $value ) {
				unset( $robots[ $key ] );
				continue;
			}
			$robots[ $key ] = $value;
		}
		return $robots;
	}

	/**
	 * Canonical URL for the current request ('' when none).
	 */
	public static function current_canonical(): string {
		$ctx = Context::current();
		$obj = $ctx['object'];
		$url = '';
		switch ( $ctx['type'] ) {
			case Context::SINGULAR:
				if ( $obj instanceof \WP_Post ) {
					$custom = (string) Meta::post( $obj->ID, 'canonical' );
					$url    = '' !== $custom ? $custom : (string) wp_get_canonical_url( $obj );
				}
				break;
			case Context::FRONT_PAGE:
				$custom = $obj instanceof \WP_Post ? (string) Meta::post( $obj->ID, 'canonical' ) : '';
				$url    = '' !== $custom ? $custom : self::paged_url( home_url( '/' ) );
				break;
			case Context::BLOG:
				$custom = $obj instanceof \WP_Post ? (string) Meta::post( $obj->ID, 'canonical' ) : '';
				$url    = '' !== $custom ? $custom : self::paged_url( $obj instanceof \WP_Post ? (string) get_permalink( $obj ) : home_url( '/' ) );
				break;
			case Context::TERM:
				if ( $obj instanceof \WP_Term ) {
					$custom = (string) Meta::term( $obj->term_id, 'canonical' );
					$link   = get_term_link( $obj );
					$url    = '' !== $custom ? $custom : ( is_wp_error( $link ) ? '' : self::paged_url( $link ) );
				}
				break;
			case Context::AUTHOR:
				if ( $obj instanceof \WP_User ) {
					$url = self::paged_url( get_author_posts_url( $obj->ID, $obj->user_nicename ) );
				}
				break;
			case Context::POST_TYPE_ARCHIVE:
				if ( $obj instanceof \WP_Post_Type ) {
					$link = get_post_type_archive_link( $obj->name );
					$url  = $link ? self::paged_url( $link ) : '';
				}
				break;
			case Context::DATE:
				$url = self::paged_url( self::date_archive_url() );
				break;
		}
		/**
		 * Filter the canonical URL.
		 *
		 * @param string $url Canonical URL ('' to disable).
		 * @param array  $ctx Context.
		 */
		return (string) apply_filters( 'mgseo/canonical', $url, $ctx );
	}

	/**
	 * Print canonical link (not on noindex pages).
	 */
	public function canonical(): void {
		$robots = self::current_robots();
		if ( ! empty( $robots['noindex'] ) ) {
			return;
		}
		Head::link( 'canonical', self::current_canonical() );
	}

	/**
	 * Print rel prev/next on archives.
	 */
	public function pagination_links(): void {
		if ( ! Options::get( 'titles', 'rel_prev_next', true ) || is_singular() || is_404() ) {
			return;
		}
		global $wp_query;
		$current = Context::page_number();
		$total   = (int) ( $wp_query->max_num_pages ?? 1 );
		if ( $current > 1 ) {
			Head::link( 'prev', get_pagenum_link( $current - 1 ) );
		}
		if ( $current < $total ) {
			Head::link( 'next', get_pagenum_link( $current + 1 ) );
		}
	}

	/**
	 * Add /page/N/ to an archive URL when paginated.
	 *
	 * @param string $url Base URL.
	 */
	private static function paged_url( string $url ): string {
		$page = Context::page_number();
		if ( $page < 2 ) {
			return $url;
		}
		global $wp_rewrite;
		if ( ! $wp_rewrite->using_permalinks() ) {
			return add_query_arg( 'paged', $page, $url );
		}
		return user_trailingslashit( trailingslashit( $url ) . $wp_rewrite->pagination_base . '/' . $page, 'paged' );
	}

	/**
	 * URL of the current date archive.
	 */
	private static function date_archive_url(): string {
		if ( is_day() ) {
			return get_day_link( (int) get_query_var( 'year' ), (int) get_query_var( 'monthnum' ), (int) get_query_var( 'day' ) );
		}
		if ( is_month() ) {
			return get_month_link( (int) get_query_var( 'year' ), (int) get_query_var( 'monthnum' ) );
		}
		return get_year_link( (int) get_query_var( 'year' ) );
	}

	/**
	 * Print verification meta tags.
	 */
	public function verification(): void {
		if ( ! is_front_page() ) {
			return;
		}
		$map = array(
			'verify_google'    => 'google-site-verification',
			'verify_bing'      => 'msvalidate.01',
			'verify_yandex'    => 'yandex-verification',
			'verify_baidu'     => 'baidu-site-verification',
			'verify_pinterest' => 'p:domain_verify',
			'verify_facebook'  => 'facebook-domain-verification',
			'verify_norton'    => 'norton-safeweb-site-verification',
		);
		foreach ( $map as $key => $name ) {
			$value = (string) Options::get( 'titles', $key, '' );
			if ( preg_match( '/content=["\']([^"\']+)["\']/i', $value, $m ) ) {
				$value = $m[1];
			}
			Head::meta( $name, trim( $value ) );
		}
	}

	/**
	 * Redirect attachment pages; disabled date/author archives.
	 */
	public function attachment_redirect(): void {
		if ( is_attachment() && Options::get( 'titles', 'attachment_redirect', true ) ) {
			$post   = get_queried_object();
			$target = '';
			if ( $post instanceof \WP_Post && $post->post_parent ) {
				$parent = get_post( $post->post_parent );
				if ( $parent && 'publish' === $parent->post_status ) {
					$target = (string) get_permalink( $parent );
				}
			}
			if ( '' === $target && $post instanceof \WP_Post ) {
				$target = (string) wp_get_attachment_url( $post->ID );
			}
			if ( '' !== $target ) {
				wp_safe_redirect( $target, 301, 'WordPress' );
				exit;
			}
		}
		if ( ( is_author() && ! Options::get( 'titles', 'author_archive', true ) ) || ( is_date() && ! Options::get( 'titles', 'date_archive', true ) ) ) {
			wp_safe_redirect( home_url( '/' ), 301, 'WordPress' );
			exit;
		}
	}

	/**
	 * Add before/after text to feed items.
	 *
	 * @param string $content Content.
	 */
	public function rss_content( string $content ): string {
		$before = (string) Options::get( 'titles', 'rss_before', '' );
		$after  = (string) Options::get( 'titles', 'rss_after', '' );
		if ( '' === $before && '' === $after ) {
			return $content;
		}
		$post    = get_post();
		$replace = static function ( string $text ) use ( $post ): string {
			$text = str_replace(
				array( '%postlink%', '%sitelink%' ),
				array(
					$post ? '<a href="' . esc_url( (string) get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a>' : '',
					'<a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html( get_bloginfo( 'name' ) ) . '</a>',
				),
				$text
			);
			return wp_kses_post( str_replace( array( '%title%', '%sitename%' ), array( $post ? get_the_title( $post ) : '', get_bloginfo( 'name' ) ), $text ) );
		};
		return ( '' !== $before ? '<p>' . $replace( $before ) . '</p>' : '' ) . $content . ( '' !== $after ? '<p>' . $replace( $after ) . '</p>' : '' );
	}
}
