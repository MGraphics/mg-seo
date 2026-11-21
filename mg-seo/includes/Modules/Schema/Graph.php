<?php
/**
 * Site JSON-LD graph.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Schema;

use MGSEO\Context;
use MGSEO\Helpers;
use MGSEO\Meta;
use MGSEO\Modules\Breadcrumbs\Module as Breadcrumbs;
use MGSEO\Modules\Social\Module as Social;
use MGSEO\Modules\Titles\Module as Titles;
use MGSEO\Options;
use MGSEO\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Builds the single @graph printed on every front-end page.
 */
final class Graph {

	/**
	 * Node id: "<base>#<fragment>" (base defaults to the home URL).
	 *
	 * @param string $fragment Fragment without "#".
	 * @param string $base     Base URL (canonical of the page); '' for the home URL.
	 */
	public static function id( string $fragment, string $base = '' ): string {
		$base = '' === $base ? home_url( '/' ) : $base;
		$hash = strpos( $base, '#' );
		if ( false !== $hash ) {
			$base = substr( $base, 0, $hash );
		}
		return $base . '#' . ltrim( $fragment, '#' );
	}

	/**
	 * Canonical URL of the current request (falls back to the current URL).
	 */
	public static function canonical(): string {
		$url = Titles::current_canonical();
		if ( '' === $url ) {
			$url = Helpers::current_url( is_search() );
		}
		return $url;
	}

	/**
	 * Site language (BCP 47).
	 */
	public static function language(): string {
		return (string) get_bloginfo( 'language' );
	}

	/**
	 * Whether the site represents a person.
	 */
	public static function is_person(): bool {
		return 'person' === Options::get( 'schema', 'kg_type', 'organization' );
	}

	/**
	 * Id of the site entity (Organization or Person).
	 */
	public static function site_id(): string {
		return self::id( self::is_person() ? 'person' : 'organization' );
	}

	/**
	 * Build the graph for the current request.
	 *
	 * @return array{graph: array<int, array>, scripts: array<int, array>}
	 */
	public static function build(): array {
		$ctx       = Context::current();
		$obj       = $ctx['object'];
		$canonical = self::canonical();
		$nodes     = array();
		$scripts   = array();
		$site      = self::site_node();
		if ( $site ) {
			$nodes[] = $site;
		}
		$nodes[] = self::website_node( $site ? (string) $site['@id'] : '' );

		$env = array(
			'canonical'  => $canonical,
			'webpage_id' => '',
			'site_id'    => $site ? (string) $site['@id'] : '',
			'image_id'   => '',
			'image_url'  => '',
			'author_id'  => '',
		);

		if ( Context::NOT_FOUND !== $ctx['type'] ) {
			$post      = $obj instanceof \WP_Post && in_array( $ctx['type'], array( Context::SINGULAR, Context::FRONT_PAGE, Context::BLOG ), true ) ? $obj : null;
			$protected = $post && post_password_required( $post );

			$image = self::primary_image( $ctx, $canonical );
			if ( $image ) {
				$nodes[]          = $image;
				$env['image_id']  = (string) $image['@id'];
				$env['image_url'] = (string) $image['url'];
			}

			$author = null;
			if ( $post && Context::SINGULAR === $ctx['type'] && post_type_supports( $post->post_type, 'author' ) && $post->post_author ) {
				$author = self::person_node( (int) $post->post_author );
			} elseif ( $obj instanceof \WP_User && Context::AUTHOR === $ctx['type'] ) {
				$author = self::person_node( $obj->ID );
			}

			$breadcrumb = null;
			if ( Plugin::instance()->module( 'breadcrumbs' ) && Context::FRONT_PAGE !== $ctx['type'] ) {
				$breadcrumb = Breadcrumbs::schema();
			}

			$webpage           = self::webpage_node( $ctx, $canonical, $env, $breadcrumb, $author, $protected );
			$env['webpage_id'] = (string) $webpage['@id'];
			$nodes[]           = $webpage;
			if ( $breadcrumb ) {
				$nodes[] = $breadcrumb;
			}
			if ( $author ) {
				$nodes[]          = $author;
				$env['author_id'] = (string) $author['@id'];
			}

			if ( $post && ! $protected && Context::BLOG !== $ctx['type'] ) {
				$built   = Entities::build_all( Entities::for_post( $post ), $post, $env );
				$nodes   = array_merge( $nodes, $built['nodes'] );
				$scripts = $built['scripts'];
			}
		}

		/**
		 * Filter the JSON-LD graph nodes.
		 *
		 * @param array $nodes Nodes.
		 * @param array $ctx   Context (type, object) plus canonical, webpage_id, site_id, image_id, author_id.
		 */
		$nodes = apply_filters( 'mgseo/schema/graph', $nodes, $ctx + $env );
		$nodes = self::finalize( is_array( $nodes ) ? $nodes : array() );

		/**
		 * Filter standalone JSON-LD scripts (custom JSON-LD that has its own @context).
		 *
		 * @param array $scripts Scripts.
		 * @param array $ctx     Context.
		 */
		$scripts = (array) apply_filters( 'mgseo/schema/scripts', $scripts, $ctx + $env );
		return array(
			'graph'   => $nodes,
			'scripts' => array_values( array_filter( array_map( array( Entities::class, 'prune' ), $scripts ) ) ),
		);
	}

	/**
	 * Remove empty values, drop duplicates by @id (later nodes are merged into earlier ones).
	 *
	 * @param array $nodes Nodes.
	 * @return array<int, array>
	 */
	public static function finalize( array $nodes ): array {
		$out   = array();
		$index = array();
		foreach ( $nodes as $node ) {
			if ( ! is_array( $node ) || ! $node ) {
				continue;
			}
			$node = Entities::prune( $node );
			if ( ! is_array( $node ) || ! $node ) {
				continue;
			}
			$id = isset( $node['@id'] ) && is_string( $node['@id'] ) ? $node['@id'] : '';
			if ( '' !== $id && isset( $index[ $id ] ) ) {
				$out[ $index[ $id ] ] = array_merge( $out[ $index[ $id ] ], $node );
				continue;
			}
			if ( '' !== $id ) {
				$index[ $id ] = count( $out );
			}
			$out[] = $node;
		}
		return $out;
	}

	/**
	 * Organization or Person node for the site.
	 *
	 * @return array<string, mixed>|null
	 */
	public static function site_node(): ?array {
		$opt  = static fn( string $key, mixed $fallback = '' ) => Options::get( 'schema', $key, $fallback );
		$same = self::same_as();
		if ( self::is_person() ) {
			$name = (string) $opt( 'person_name' );
			$name = '' !== $name ? $name : get_bloginfo( 'name' );
			$node = array(
				'@type'       => 'Person',
				'@id'         => self::id( 'person' ),
				'name'        => $name,
				'url'         => self::site_url(),
				'jobTitle'    => (string) $opt( 'person_job_title' ),
				'description' => (string) $opt( 'org_description' ),
				'email'       => (string) $opt( 'org_email' ),
				'telephone'   => (string) $opt( 'org_phone' ),
				'sameAs'      => $same,
			);
			$img  = self::image_object( (string) $opt( 'person_image' ), self::id( 'personlogo' ), $name );
			if ( $img ) {
				$node['image'] = $img;
			}
			/**
			 * Filter the site Person node.
			 *
			 * @param array $node Node.
			 */
			return apply_filters( 'mgseo/schema/person', $node );
		}

		$name = (string) $opt( 'org_name' );
		$name = '' !== $name ? $name : get_bloginfo( 'name' );
		$type = (string) $opt( 'org_type', 'Organization' );
		$node = array(
			'@type'         => '' !== $type ? $type : 'Organization',
			'@id'           => self::id( 'organization' ),
			'name'          => $name,
			'alternateName' => (string) $opt( 'org_alt_name' ),
			'legalName'     => (string) $opt( 'org_legal_name' ),
			'url'           => self::site_url(),
			'description'   => (string) $opt( 'org_description' ),
			'email'         => (string) $opt( 'org_email' ),
			'telephone'     => (string) $opt( 'org_phone' ),
			'foundingDate'  => Entities::iso_date( (string) $opt( 'org_founding_date' ) ),
			'sameAs'        => $same,
		);
		$logo = self::image_object( (string) $opt( 'org_logo' ), self::id( 'logo' ), $name );
		if ( $logo ) {
			$node['logo']  = $logo;
			$node['image'] = array( '@id' => $logo['@id'] );
		}
		$points = array();
		foreach ( (array) $opt( 'contact_points', array() ) as $point ) {
			if ( ! is_array( $point ) || ( empty( $point['telephone'] ) && empty( $point['email'] ) && empty( $point['url'] ) ) ) {
				continue;
			}
			$points[] = array(
				'@type'             => 'ContactPoint',
				'contactType'       => (string) ( $point['contactType'] ?? '' ),
				'telephone'         => (string) ( $point['telephone'] ?? '' ),
				'email'             => (string) ( $point['email'] ?? '' ),
				'url'               => (string) ( $point['url'] ?? '' ),
				'areaServed'        => (string) ( $point['areaServed'] ?? '' ),
				'availableLanguage' => (string) ( $point['availableLanguage'] ?? '' ),
			);
		}
		$node['contactPoint'] = $points;

		/**
		 * Filter the site Organization node (Local SEO turns it into a LocalBusiness).
		 *
		 * @param array $node Node.
		 */
		$node = apply_filters( 'mgseo/schema/organization', $node );
		return is_array( $node ) && $node ? $node : null;
	}

	/**
	 * URL of the site entity.
	 */
	private static function site_url(): string {
		$url = (string) Options::get( 'schema', 'org_url', '' );
		return '' !== $url ? $url : home_url( '/' );
	}

	/**
	 * Profiles for sameAs: setting lines plus Facebook page and X account from Social settings.
	 *
	 * @return string[]
	 */
	public static function same_as(): array {
		$list = Options::get( 'schema', 'same_as', array() );
		$list = is_array( $list ) ? $list : preg_split( '/\r\n|\r|\n/', (string) $list );
		$fb   = (string) Options::get( 'social', 'facebook_page', '' );
		if ( '' !== $fb ) {
			$list[] = $fb;
		}
		$tw = ltrim( (string) Options::get( 'social', 'twitter_site', '' ), '@' );
		if ( '' !== $tw ) {
			$list[] = str_starts_with( $tw, 'http' ) ? $tw : 'https://x.com/' . $tw;
		}
		$list = array_map( static fn( $url ) => Entities::absolute_url( (string) $url ), (array) $list );
		return array_values( array_unique( array_filter( $list ) ) );
	}

	/**
	 * ImageObject from a URL.
	 *
	 * @param string $url     URL.
	 * @param string $id      Node id.
	 * @param string $caption Caption.
	 * @return array<string, mixed>|null
	 */
	public static function image_object( string $url, string $id, string $caption = '' ): ?array {
		if ( '' === $url ) {
			return null;
		}
		$img = Social::image_from_url( $url );
		return self::image_from_data( $img, $id, $caption );
	}

	/**
	 * ImageObject from image data (Helpers::image() shape).
	 *
	 * @param array  $img     Image data.
	 * @param string $id      Node id.
	 * @param string $caption Caption fallback.
	 * @return array<string, mixed>|null
	 */
	private static function image_from_data( array $img, string $id, string $caption = '' ): ?array {
		$url = Entities::absolute_url( (string) ( $img['url'] ?? '' ) );
		if ( '' === $url ) {
			return null;
		}
		$alt = (string) ( $img['alt'] ?? '' );
		return array(
			'@type'      => 'ImageObject',
			'@id'        => $id,
			'url'        => $url,
			'contentUrl' => $url,
			'width'      => (int) ( $img['width'] ?? 0 ),
			'height'     => (int) ( $img['height'] ?? 0 ),
			'caption'    => '' !== $alt ? $alt : $caption,
			'inLanguage' => self::language(),
		);
	}

	/**
	 * WebSite node.
	 *
	 * @param string $publisher Publisher id.
	 * @return array<string, mixed>
	 */
	public static function website_node( string $publisher ): array {
		$name = (string) Options::get( 'schema', 'website_name', '' );
		$node = array(
			'@type'         => 'WebSite',
			'@id'           => self::id( 'website' ),
			'url'           => home_url( '/' ),
			'name'          => '' !== $name ? $name : get_bloginfo( 'name' ),
			'alternateName' => (string) Options::get( 'schema', 'website_alt_name', '' ),
			'description'   => get_bloginfo( 'description' ),
			'publisher'     => '' !== $publisher ? array( '@id' => $publisher ) : null,
			'inLanguage'    => self::language(),
		);
		if ( Options::get( 'schema', 'search_action', true ) ) {
			$node['potentialAction'] = array(
				'@type'       => 'SearchAction',
				'target'      => array(
					'@type'       => 'EntryPoint',
					'urlTemplate' => home_url( '/?s={search_term_string}' ),
				),
				'query-input' => 'required name=search_term_string',
			);
		}
		/**
		 * Filter the WebSite node.
		 *
		 * @param array $node Node.
		 */
		return apply_filters( 'mgseo/schema/website', $node );
	}

	/**
	 * WebPage types selectable per post.
	 *
	 * @return array<string, string>
	 */
	public static function webpage_types(): array {
		return array(
			''                  => __( 'Automatic', 'mg-seo' ),
			'WebPage'           => 'WebPage',
			'AboutPage'         => 'AboutPage',
			'ContactPage'       => 'ContactPage',
			'CheckoutPage'      => 'CheckoutPage',
			'CollectionPage'    => 'CollectionPage',
			'ItemPage'          => 'ItemPage',
			'MedicalWebPage'    => 'MedicalWebPage',
			'ProfilePage'       => 'ProfilePage',
			'QAPage'            => 'QAPage',
			'RealEstateListing' => 'RealEstateListing',
			'SearchResultsPage' => 'SearchResultsPage',
		);
	}

	/**
	 * WebPage node.
	 *
	 * @param array      $ctx        Context.
	 * @param string     $canonical  Canonical URL.
	 * @param array      $env        Environment.
	 * @param array|null $breadcrumb Breadcrumb node.
	 * @param array|null $author     Author node.
	 * @param bool       $is_protected Password protected post.
	 * @return array<string, mixed>
	 */
	private static function webpage_node( array $ctx, string $canonical, array $env, ?array $breadcrumb, ?array $author, bool $is_protected ): array {
		$obj  = $ctx['object'];
		$type = match ( $ctx['type'] ) {
			Context::BLOG, Context::TERM, Context::POST_TYPE_ARCHIVE, Context::DATE => 'CollectionPage',
			Context::AUTHOR => 'ProfilePage',
			Context::SEARCH => 'SearchResultsPage',
			default         => 'WebPage',
		};
		if ( $obj instanceof \WP_Post && in_array( $ctx['type'], array( Context::SINGULAR, Context::FRONT_PAGE ), true ) ) {
			$custom = (string) Meta::post( $obj->ID, Entities::META_WEBPAGE, '' );
			if ( '' !== $custom && isset( self::webpage_types()[ $custom ] ) ) {
				$type = $custom;
			}
		}
		$node = array(
			'@type'      => $type,
			'@id'        => self::id( 'webpage', $canonical ),
			'url'        => $canonical,
			'name'       => Titles::document_title(),
			'isPartOf'   => array( '@id' => self::id( 'website' ) ),
			'inLanguage' => self::language(),
		);
		if ( ! $is_protected ) {
			$node['description'] = Titles::current_description();
		}
		if ( Context::FRONT_PAGE === $ctx['type'] && '' !== $env['site_id'] ) {
			$node['about'] = array( '@id' => $env['site_id'] );
		}
		if ( $obj instanceof \WP_Post && in_array( $ctx['type'], array( Context::SINGULAR, Context::FRONT_PAGE, Context::BLOG ), true ) ) {
			$node['datePublished'] = (string) get_post_time( 'c', false, $obj );
			$node['dateModified']  = (string) get_post_modified_time( 'c', false, $obj );
		}
		if ( '' !== $env['image_id'] ) {
			$node['primaryImageOfPage'] = array( '@id' => $env['image_id'] );
			$node['image']              = array( '@id' => $env['image_id'] );
		}
		if ( $breadcrumb && ! empty( $breadcrumb['@id'] ) ) {
			$node['breadcrumb'] = array( '@id' => $breadcrumb['@id'] );
		}
		if ( $author && Context::AUTHOR === $ctx['type'] ) {
			$node['mainEntity'] = array( '@id' => $author['@id'] );
		}
		/**
		 * Filter the WebPage node.
		 *
		 * @param array $node Node.
		 * @param array $ctx  Context.
		 */
		return apply_filters( 'mgseo/schema/webpage', $node, $ctx );
	}

	/**
	 * Primary image of the page.
	 *
	 * @param array  $ctx       Context.
	 * @param string $canonical Canonical.
	 * @return array<string, mixed>|null
	 */
	private static function primary_image( array $ctx, string $canonical ): ?array {
		$obj = $ctx['object'];
		$img = null;
		if ( $obj instanceof \WP_Post && in_array( $ctx['type'], array( Context::SINGULAR, Context::FRONT_PAGE, Context::BLOG ), true ) ) {
			$img = Social::post_image( $obj );
		} elseif ( $obj instanceof \WP_Term ) {
			$url = (string) Meta::term( $obj->term_id, 'og_image' );
			$img = '' !== $url ? Social::image_from_url( $url ) : null;
		}
		if ( ! $img && Context::FRONT_PAGE === $ctx['type'] ) {
			$url = (string) Options::get( 'titles', 'home_og_image', '' );
			$img = '' !== $url ? Social::image_from_url( $url ) : null;
		}
		if ( ! $img ) {
			return null;
		}
		$caption = $obj instanceof \WP_Post ? wp_strip_all_tags( get_the_title( $obj ) ) : '';
		return self::image_from_data( $img, self::id( 'primaryimage', $canonical ), $caption );
	}

	/**
	 * Person node for a user (author).
	 *
	 * @param int $user_id User id.
	 * @return array<string, mixed>|null
	 */
	public static function person_node( int $user_id ): ?array {
		$user = get_userdata( $user_id );
		if ( ! $user ) {
			return null;
		}
		$same = array();
		if ( $user->user_url ) {
			$same[] = $user->user_url;
		}
		foreach ( array( 'facebook', 'twitter', 'linkedin', 'instagram', 'youtube', 'pinterest', 'tiktok' ) as $network ) {
			$value = trim( (string) Meta::user( $user_id, 'social_' . $network ) );
			if ( '' === $value ) {
				continue;
			}
			if ( 'twitter' === $network && ! str_starts_with( $value, 'http' ) ) {
				$value = 'https://x.com/' . ltrim( $value, '@' );
			}
			$same[] = $value;
		}
		$archives = (bool) Options::get( 'titles', 'author_archive', true );
		$node     = array(
			'@type'       => 'Person',
			'@id'         => self::id( 'author-' . $user_id ),
			'name'        => $user->display_name,
			'url'         => $archives ? get_author_posts_url( $user_id, $user->user_nicename ) : (string) $user->user_url,
			'description' => wp_strip_all_tags( (string) get_the_author_meta( 'description', $user_id ) ),
			'sameAs'      => array_values( array_unique( array_filter( array_map( array( Entities::class, 'absolute_url' ), $same ) ) ) ),
		);
		$avatar   = get_avatar_url( $user_id, array( 'size' => 96 ) );
		if ( $avatar && get_option( 'show_avatars' ) ) {
			$node['image'] = array(
				'@type'   => 'ImageObject',
				'@id'     => self::id( 'author-image-' . $user_id ),
				'url'     => $avatar,
				'caption' => $user->display_name,
			);
		}
		/**
		 * Filter an author Person node.
		 *
		 * @param array    $node Node.
		 * @param \WP_User $user User.
		 */
		return apply_filters( 'mgseo/schema/author', $node, $user );
	}

	/**
	 * Environment for building entities of a post outside the page request (REST validation, previews).
	 *
	 * @param \WP_Post $post Post.
	 * @return array{graph: array<int, array>, env: array<string, string>}
	 */
	public static function post_env( \WP_Post $post ): array {
		Context::set(
			array(
				'type'   => Context::SINGULAR,
				'object' => $post,
			)
		);
		$canonical = (string) Meta::post( $post->ID, 'canonical', '' );
		$canonical = '' !== $canonical ? $canonical : (string) get_permalink( $post );
		$site      = self::site_node();
		$graph     = array();
		$env       = array(
			'canonical'  => $canonical,
			'webpage_id' => self::id( 'webpage', $canonical ),
			'site_id'    => $site ? (string) $site['@id'] : '',
			'image_id'   => '',
			'image_url'  => '',
			'author_id'  => '',
		);
		if ( $site ) {
			$graph[] = $site;
		}
		$img = Social::post_image( $post );
		if ( $img ) {
			$node = self::image_from_data( $img, self::id( 'primaryimage', $canonical ) );
			if ( $node ) {
				$graph[]          = $node;
				$env['image_id']  = $node['@id'];
				$env['image_url'] = $node['url'];
			}
		}
		if ( post_type_supports( $post->post_type, 'author' ) && $post->post_author ) {
			$author = self::person_node( (int) $post->post_author );
			if ( $author ) {
				$graph[]          = $author;
				$env['author_id'] = $author['@id'];
			}
		}
		return array(
			'graph' => $graph,
			'env'   => $env,
		);
	}
}
