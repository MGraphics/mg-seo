<?php
/**
 * Local SEO module.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Local;

use MGSEO\Context;
use MGSEO\Head;
use MGSEO\Helpers;
use MGSEO\Meta;
use MGSEO\Module as Base;
use MGSEO\Modules\Titles\Module as Titles;
use MGSEO\Options;
use MGSEO\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Business data, multiple locations, opening hours, address shortcode/block, KML and LocalBusiness schema.
 *
 * Schema integration (owned by the Schema module):
 * - `mgseo/schema/organization`: the site Organization node becomes the LocalBusiness (single location mode).
 * - `mgseo/schema/graph`: adds `<canonical>#localbusiness-<post id>` on location pages and switches the WebPage
 *   node of the selected contact/about page to ContactPage/AboutPage.
 * - Without the Schema module the same nodes are printed as standalone JSON-LD.
 */
final class Module extends Base {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'local';
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'init', array( Locations::class, 'register' ), 5 );
		add_action( 'init', array( $this, 'rewrites' ) );
		add_action( 'init', array( $this, 'register_block' ) );
		add_filter( 'query_vars', array( $this, 'query_vars' ) );
		add_action( 'template_redirect', array( $this, 'serve_files' ), 0 );
		add_shortcode( 'mgseo_local', array( Renderer::class, 'shortcode' ) );
		add_action( 'init', array( $this, 'compat_shortcodes' ), 20 );

		add_filter( 'mgseo/schema/organization', array( $this, 'organization' ) );
		add_filter( 'mgseo/schema/graph', array( $this, 'graph' ), 20, 2 );
		add_filter( 'mgseo/schema/webpage_type', array( $this, 'webpage_type' ), 10, 2 );
		add_action( 'mgseo/head', array( $this, 'standalone_schema' ), 91 );
		add_filter( 'mgseo/sitemap/index', array( $this, 'sitemap_entries' ) );
		add_filter( 'mgseo/sitemap/post_types', array( $this, 'sitemap_post_types' ) );

		add_filter( 'body_class', array( Locations::class, 'neutral_classes' ), 99 );
		add_filter( 'post_class', array( Locations::class, 'neutral_classes' ), 99 );

		add_action( 'add_meta_boxes_' . Locations::POST_TYPE, array( Admin::class, 'add_meta_box' ) );
		add_action( 'save_post_' . Locations::POST_TYPE, array( Admin::class, 'save_meta_box' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'editor_assets' ) );
		add_action(
			'mgseo/settings_saved',
			static function ( string $module ): void {
				if ( 'local' === $module ) {
					update_option( 'mgseo_local_modified', time(), false );
				}
			}
		);
	}

	/**
	 * Settings schema.
	 */
	public function settings(): array {
		$fields = Business::fields( false );
		return array(
			array(
				'id'          => 'business',
				'title'       => __( 'Business', 'mg-seo' ),
				'description' => __( 'With a single location the site Organization in the schema graph becomes this LocalBusiness (when the site represents an organization). Show the data with the [mgseo_local] shortcode or the Business info block.', 'mg-seo' ),
				'fields'      => $fields['business'],
			),
			array(
				'id'     => 'contact',
				'title'  => __( 'Contact', 'mg-seo' ),
				'fields' => $fields['contact'],
			),
			array(
				'id'     => 'hours',
				'title'  => __( 'Opening hours', 'mg-seo' ),
				'fields' => array_merge(
					$fields['hours'],
					array(
						array(
							'id'      => 'time_format',
							'type'    => 'select',
							'label'   => __( 'Time format', 'mg-seo' ),
							'options' => array(
								'H:i'   => '14:30',
								'g:i A' => '2:30 PM',
								'g:i a' => '2:30 pm',
							),
							'default' => 'H:i',
						),
					)
				),
			),
			array(
				'id'     => 'display',
				'title'  => __( 'Display & pages', 'mg-seo' ),
				'fields' => array(
					array(
						'id'      => 'map_mode',
						'type'    => 'select',
						'label'   => __( 'Map in the shortcode', 'mg-seo' ),
						'options' => array(
							'none'  => __( 'No map', 'mg-seo' ),
							'link'  => __( 'Link to the map', 'mg-seo' ),
							'embed' => __( 'Embedded map (iframe)', 'mg-seo' ),
						),
						'default' => 'link',
					),
					array(
						'id'      => 'map_provider',
						'type'    => 'select',
						'label'   => __( 'Map provider', 'mg-seo' ),
						'options' => array(
							'google' => 'Google Maps',
							'osm'    => 'OpenStreetMap',
						),
						'default' => 'google',
					),
					array(
						'id'           => 'map_embed_allowed',
						'type'         => 'toggle',
						'label'        => __( 'Allow map embeds', 'mg-seo' ),
						'toggle_label' => __( 'Print map iframes', 'mg-seo' ),
						'default'      => false,
						'desc'         => __( 'An embedded map loads third-party content from Google Maps or OpenStreetMap in the visitor\'s browser (their cookies and IP logging apply; you may need cookie consent). Without this only a plain link is printed.', 'mg-seo' ),
					),
					array(
						'id'      => 'map_zoom',
						'type'    => 'number',
						'label'   => __( 'Map zoom', 'mg-seo' ),
						'default' => 15,
						'min'     => 3,
						'max'     => 20,
					),
					array(
						'id'      => 'map_height',
						'type'    => 'number',
						'label'   => __( 'Map height (px)', 'mg-seo' ),
						'default' => 300,
						'min'     => 150,
						'max'     => 1200,
					),
					array(
						'id'       => 'contact_page',
						'type'     => 'callback',
						'label'    => __( 'Contact page', 'mg-seo' ),
						'default'  => 0,
						'callback' => array( Admin::class, 'render_page_select' ),
						'sanitize' => 'absint',
						'desc'     => __( 'Marked as ContactPage in the schema graph.', 'mg-seo' ),
					),
					array(
						'id'       => 'about_page',
						'type'     => 'callback',
						'label'    => __( 'About page', 'mg-seo' ),
						'default'  => 0,
						'callback' => array( Admin::class, 'render_page_select' ),
						'sanitize' => 'absint',
						'desc'     => __( 'Marked as AboutPage in the schema graph.', 'mg-seo' ),
					),
				),
			),
			array(
				'id'     => 'locations',
				'title'  => __( 'Locations', 'mg-seo' ),
				'fields' => array(
					array(
						'id'           => 'multiple',
						'type'         => 'toggle',
						'label'        => __( 'Multiple locations', 'mg-seo' ),
						'toggle_label' => __( 'Manage several locations (branches, shops, offices) as separate posts', 'mg-seo' ),
						'default'      => false,
						'desc'         => __( 'Each location gets its own LocalBusiness schema on its page. The site Organization keeps its type and gets the address and contact data from these settings.', 'mg-seo' ),
					),
					array(
						'id'           => 'locations_public',
						'type'         => 'toggle',
						'label'        => __( 'Location pages', 'mg-seo' ),
						'toggle_label' => __( 'Locations have public pages', 'mg-seo' ),
						'default'      => true,
						'show_if'      => 'multiple',
					),
					array(
						'id'       => 'locations_slug',
						'type'     => 'text',
						'label'    => __( 'URL slug', 'mg-seo' ),
						'default'  => _x( 'locations', 'URL slug', 'mg-seo' ),
						'desc'     => __( 'For example lokalizacje or oddzialy.', 'mg-seo' ),
						'sanitize' => static fn( $value ) => sanitize_title( (string) $value ),
						'show_if'  => 'multiple',
					),
					array(
						'id'           => 'locations_archive',
						'type'         => 'toggle',
						'label'        => __( 'Archive page', 'mg-seo' ),
						'toggle_label' => __( 'List all locations at the slug URL', 'mg-seo' ),
						'default'      => true,
						'show_if'      => 'multiple',
					),
					array(
						'id'           => 'kml',
						'type'         => 'toggle',
						'label'        => __( 'KML file', 'mg-seo' ),
						'toggle_label' => __( 'Serve /locations.kml (listed in /sitemap-locations.xml together with the location pages)', 'mg-seo' ),
						'default'      => true,
					),
				),
			),
		);
	}

	/**
	 * Mark rewrite rules for flushing.
	 */
	public function activate(): void {
		update_option( 'mgseo_flush_rewrite', 1 );
	}

	/**
	 * Remove module data.
	 */
	public function uninstall(): void {
		global $wpdb;
		$all = Options::all();
		unset( $all['local'] );
		Options::save( $all );
		delete_option( 'mgseo_local_modified' );
		delete_post_meta_by_key( Meta::PREFIX . Business::META );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup on uninstall.
		$ids = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE post_type = %s", Locations::POST_TYPE ) );
		foreach ( $ids as $id ) {
			wp_delete_post( (int) $id, true );
		}
	}

	/**
	 * Rewrite rules for the KML file and its sitemap.
	 */
	public function rewrites(): void {
		add_rewrite_rule( '^locations\.kml$', 'index.php?' . Kml::QUERY_VAR . '=kml', 'top' );
		add_rewrite_rule( '^sitemap-locations\.xml$', 'index.php?' . Kml::QUERY_VAR . '=sitemap', 'top' );
	}

	/**
	 * Register query vars.
	 *
	 * @param string[] $vars Vars.
	 * @return string[]
	 */
	public function query_vars( array $vars ): array {
		$vars[] = Kml::QUERY_VAR;
		return $vars;
	}

	/**
	 * Serve the KML file / local sitemap.
	 */
	public function serve_files(): void {
		$file = (string) get_query_var( Kml::QUERY_VAR );
		if ( '' === $file ) {
			return;
		}
		if ( 'kml' === $file && Options::get( 'local', 'kml', true ) ) {
			header( 'Content-Type: application/vnd.google-earth.kml+xml; charset=UTF-8' );
			echo Kml::document(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML escaped while building.
		} elseif ( 'sitemap' === $file && Kml::sitemap_urls() ) {
			header( 'Content-Type: application/xml; charset=UTF-8' );
			header( 'X-Robots-Tag: noindex, follow' );
			echo Kml::sitemap(); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- XML escaped while building.
		} else {
			return;
		}
		exit;
	}

	/**
	 * Location post types are listed in /sitemap-locations.xml instead of a sitemap named after the internal post type.
	 *
	 * @param array $types Post types.
	 * @return array
	 */
	public function sitemap_post_types( $types ): array {
		return array_values( array_diff( is_array( $types ) ? $types : array(), array( Locations::POST_TYPE ) ) );
	}

	/**
	 * Sitemap index entry for the locations sitemap (filter `mgseo/sitemap/index` of the Sitemap module).
	 *
	 * Entries are arrays with `loc` and `lastmod` (W3C datetime).
	 *
	 * @param array $entries Entries.
	 * @return array
	 */
	public function sitemap_entries( $entries ): array {
		$entries = is_array( $entries ) ? $entries : array();
		if ( Kml::sitemap_urls() ) {
			$entries[] = array(
				'loc'     => home_url( '/sitemap-locations.xml' ),
				'lastmod' => Kml::lastmod(),
			);
		}
		return $entries;
	}

	/**
	 * Turn the site Organization into the LocalBusiness (single location) or enrich it (multiple locations).
	 *
	 * @param array $node Organization node.
	 * @return array
	 */
	public function organization( $node ): array {
		$node = is_array( $node ) ? $node : array();
		$id   = (string) ( $node['@id'] ?? '' );
		if ( '' !== $id && ! str_ends_with( $id, '#organization' ) ) {
			return $node;
		}
		$data = Business::from_options();
		if ( ! Business::has_address( $data ) ) {
			return $node;
		}
		$props = Business::properties( $data );
		if ( Locations::enabled() ) {
			// Place-only properties are not valid on a plain Organization.
			unset( $props['@type'], $props['geo'], $props['hasMap'], $props['openingHoursSpecification'], $props['specialOpeningHoursSpecification'], $props['priceRange'], $props['paymentAccepted'], $props['image'] );
		}
		$same_as = array_values( array_unique( array_merge( (array) ( $node['sameAs'] ?? array() ), (array) ( $props['sameAs'] ?? array() ) ) ) );
		if ( ! empty( $node['contactPoint'] ) && ! empty( $props['contactPoint'] ) ) {
			// Keep the contact points from the schema settings and add the business phones that are not there yet.
			$known = array_map( static fn( $p ) => preg_replace( '/[^0-9+]/', '', (string) ( $p['telephone'] ?? '' ) ), (array) $node['contactPoint'] );
			foreach ( $props['contactPoint'] as $point ) {
				if ( ! in_array( preg_replace( '/[^0-9+]/', '', $point['telephone'] ), $known, true ) ) {
					$node['contactPoint'][] = $point;
				}
			}
			unset( $props['contactPoint'] );
		}
		if ( isset( $node['logo'] ) && isset( $props['logo'] ) ) {
			unset( $props['logo'] );
		}
		$node = array_merge( $node, $props );
		if ( $same_as ) {
			$node['sameAs'] = $same_as;
		}
		if ( ! Locations::enabled() && empty( $node['image'] ) && ! empty( $node['logo'] ) ) {
			$node['image'] = is_array( $node['logo'] ) && isset( $node['logo']['@id'] ) ? array( '@id' => $node['logo']['@id'] ) : ( is_array( $node['logo'] ) ? ( $node['logo']['url'] ?? '' ) : $node['logo'] );
		}
		return $node;
	}

	/**
	 * Add location / page type nodes to the graph.
	 *
	 * @param array $nodes Graph nodes.
	 * @param array $ctx   Schema context.
	 * @return array
	 */
	public function graph( $nodes, $ctx = array() ): array {
		$nodes = is_array( $nodes ) ? $nodes : array();
		$post  = self::current_post();
		if ( ! $post ) {
			return $nodes;
		}
		$canonical = is_array( $ctx ) && ! empty( $ctx['canonical'] ) ? (string) $ctx['canonical'] : self::canonical();
		if ( Locations::POST_TYPE === $post->post_type && Locations::enabled() ) {
			$types = array_keys( Business::types() );
			if ( ! self::has_user_entity( $post, $types ) && ! self::graph_has_type( $nodes, $types ) ) {
				$node   = self::location_node( $post, $canonical );
				$org_id = $node['parentOrganization']['@id'];
				if ( ! array_filter( $nodes, static fn( $n ) => is_array( $n ) && ( $n['@id'] ?? '' ) === $org_id ) ) {
					// The site represents a person (or has no Organization node): do not point to a missing node.
					unset( $node['parentOrganization'] );
				}
				$nodes[] = $node;
				foreach ( $nodes as $i => $existing ) {
					if ( is_array( $existing ) && ( $existing['@id'] ?? '' ) === self::node_id( 'webpage', $canonical ) ) {
						$nodes[ $i ]['about'] = array( '@id' => $node['@id'] );
					}
				}
			}
		}
		$type = $this->webpage_type( '', $post );
		if ( '' !== $type ) {
			foreach ( $nodes as $i => $existing ) {
				if ( ! is_array( $existing ) ) {
					continue;
				}
				// Only the automatic type is replaced; a type chosen in the post's schema settings wins.
				if ( ( $existing['@id'] ?? '' ) === self::node_id( 'webpage', $canonical ) && 'WebPage' === ( $existing['@type'] ?? '' ) ) {
					$nodes[ $i ]['@type'] = $type;
					break;
				}
			}
		}
		return $nodes;
	}

	/**
	 * WebPage type for the contact/about page (for a Schema module that asks through a filter).
	 *
	 * @param mixed $type Current type.
	 * @param mixed $post Post (optional; defaults to the current one).
	 */
	public function webpage_type( $type, $post = null ): string {
		$post = $post instanceof \WP_Post ? $post : self::current_post();
		if ( $post ) {
			if ( (int) Options::get( 'local', 'contact_page', 0 ) === $post->ID ) {
				return 'ContactPage';
			}
			if ( (int) Options::get( 'local', 'about_page', 0 ) === $post->ID ) {
				return 'AboutPage';
			}
		}
		return is_string( $type ) ? $type : '';
	}

	/**
	 * LocalBusiness node of a location.
	 *
	 * @param \WP_Post $post      Location.
	 * @param string   $canonical Canonical URL of the page the node is printed on.
	 * @return array<string, mixed>
	 */
	public static function location_node( \WP_Post $post, string $canonical ): array {
		$node                       = array( '@id' => self::node_id( 'localbusiness-' . $post->ID, $canonical ) ) + Business::properties( Business::from_post( $post ) );
		$node['parentOrganization'] = array( '@id' => self::node_id( 'organization', home_url( '/' ) ) );
		$node['mainEntityOfPage']   = array( '@id' => self::node_id( 'webpage', $canonical ) );
		/**
		 * Filter a location's LocalBusiness node.
		 *
		 * @param array    $node Node.
		 * @param \WP_Post $post Location.
		 */
		return apply_filters( 'mgseo/local/location_node', $node, $post );
	}

	/**
	 * Standalone JSON-LD when the Schema module is inactive.
	 */
	public function standalone_schema(): void {
		if ( Plugin::instance()->module( 'schema' ) ) {
			return;
		}
		$post = self::current_post();
		if ( $post && Locations::POST_TYPE === $post->post_type && Locations::enabled() && ! self::has_user_entity( $post, array_keys( Business::types() ) ) ) {
			$node = self::location_node( $post, self::canonical() );
			unset( $node['parentOrganization'], $node['mainEntityOfPage'] );
			Head::json_ld( array( '@context' => 'https://schema.org' ) + $node );
			return;
		}
		if ( is_front_page() && ! Locations::enabled() ) {
			$data = Business::from_options();
			if ( Business::has_address( $data ) ) {
				Head::json_ld( array( '@context' => 'https://schema.org' ) + array( '@id' => self::node_id( 'organization', home_url( '/' ) ) ) + Business::properties( $data ) );
			}
		}
	}

	/**
	 * Server-rendered "Business info" block.
	 */
	public function register_block(): void {
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( 'mg-seo/local' ) ) {
			return;
		}
		register_block_type(
			'mg-seo/local',
			array(
				'api_version'     => 3,
				'title'           => __( 'Business info', 'mg-seo' ),
				'category'        => 'widgets',
				'attributes'      => array(
					'show'     => array(
						'type'    => 'string',
						'default' => 'all',
					),
					'location' => array(
						'type'    => 'string',
						'default' => '',
					),
					'map'      => array(
						'type'    => 'string',
						'default' => '',
					),
				),
				'render_callback' => static fn( $attributes ) => Renderer::shortcode( is_array( $attributes ) ? $attributes : array() ),
				'supports'        => array(
					'html'       => false,
					'typography' => array( 'fontSize' => true ),
					'spacing'    => array( 'margin' => true ),
				),
			)
		);
	}

	/**
	 * Block editor script for the block.
	 */
	public function editor_assets(): void {
		wp_enqueue_script( 'mgseo-local', MGSEO_URL . 'assets/local.js', array( 'wp-blocks', 'wp-element', 'wp-components', 'wp-block-editor', 'wp-server-side-render', 'wp-i18n' ), MGSEO_VERSION, true );
		wp_set_script_translations( 'mgseo-local', 'mg-seo', MGSEO_DIR . 'languages' );
	}

	/**
	 * Answer Rank Math local shortcodes on switched sites (only when Rank Math is inactive).
	 */
	public function compat_shortcodes(): void {
		if ( defined( 'RANK_MATH_VERSION' ) || class_exists( 'RankMath' ) ) {
			return;
		}
		foreach ( array( 'rank_math_contact_info', 'rank_math_local' ) as $tag ) {
			if ( ! shortcode_exists( $tag ) ) {
				add_shortcode(
					$tag,
					static function ( $atts = array() ): string {
						$atts = is_array( $atts ) ? $atts : array();
						if ( isset( $atts['type'] ) && ! isset( $atts['show'] ) ) {
							$atts['show'] = 'opening-hours' === $atts['type'] ? 'hours' : $atts['type'];
						}
						if ( isset( $atts['locations'] ) && ! isset( $atts['location'] ) ) {
							$atts['location'] = $atts['locations'];
						}
						unset( $atts['type'], $atts['locations'] );
						return Renderer::shortcode( $atts );
					}
				);
			}
		}
	}

	/**
	 * Whether the post has a user-defined schema entity of one of the types.
	 *
	 * @param \WP_Post $post  Post.
	 * @param string[] $types Types.
	 */
	public static function has_user_entity( \WP_Post $post, array $types ): bool {
		$raw      = Meta::post( $post->ID, 'schema', '' );
		$entities = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $entities ) ) {
			return false;
		}
		foreach ( $entities as $entity ) {
			if ( ! is_array( $entity ) ) {
				continue;
			}
			$type = $entity['type'] ?? ( $entity['data']['@type'] ?? '' );
			if ( ! empty( $entity['custom'] ) && isset( $entity['data']['@type'] ) ) {
				$type = $entity['data']['@type'];
			}
			if ( array_intersect( (array) $type, $types ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Whether a graph already contains a node of one of the types.
	 *
	 * @param array    $nodes Nodes.
	 * @param string[] $types Types.
	 */
	public static function graph_has_type( array $nodes, array $types ): bool {
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && isset( $node['@type'] ) && ! str_ends_with( (string) ( $node['@id'] ?? '' ), '#organization' ) && array_intersect( (array) $node['@type'], $types ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Current singular post.
	 */
	private static function current_post(): ?\WP_Post {
		$ctx = Context::current();
		if ( in_array( $ctx['type'], array( Context::SINGULAR, Context::FRONT_PAGE, Context::BLOG ), true ) && $ctx['object'] instanceof \WP_Post ) {
			return $ctx['object'];
		}
		return null;
	}

	/**
	 * Canonical URL of the current page.
	 */
	private static function canonical(): string {
		$url = Titles::current_canonical();
		return '' !== $url ? $url : Helpers::current_url();
	}

	/**
	 * Node id following the Schema module convention.
	 *
	 * @param string $fragment Fragment without '#'.
	 * @param string $base     Base URL.
	 */
	public static function node_id( string $fragment, string $base ): string {
		$fallback = $base . '#' . $fragment;
		if ( class_exists( '\MGSEO\Modules\Schema\Graph' ) && method_exists( '\MGSEO\Modules\Schema\Graph', 'id' ) ) {
			$id = (string) \MGSEO\Modules\Schema\Graph::id( $fragment, $base );
			if ( str_ends_with( $id, '#' . $fragment ) ) {
				return $id;
			}
		}
		return $fallback;
	}
}
