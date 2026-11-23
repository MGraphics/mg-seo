<?php
/**
 * Sitemap data builder.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Sitemap;

use MGSEO\Context;
use MGSEO\Meta;
use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Decides which sitemaps exist, which objects they list and builds their XML.
 *
 * Sitemap names (the {name} in /sitemap-{name}.xml) are URL slugs, see slugs(): 'article' (posts), 'page',
 * 'category', 'tag', 'author', 'news', 'video' and one slug per other post type and taxonomy.
 * Items are lists of array( object id, GMT datetime of last modification ); id 0 in a post type list is the homepage.
 */
final class Builder {

	public const NEWS_MAX = 1000;

	/**
	 * URL scheme version, part of the XML cache key (2: /sitemap.xml and /sitemap-{name}.xml since 0.3.2).
	 */
	public const SCHEME = 2;

	/**
	 * Fixed slugs of core post types and taxonomies.
	 */
	public const ALIASES = array(
		'post_type' => array(
			'post' => 'article',
			'page' => 'page',
		),
		'taxonomy'  => array(
			'category' => 'category',
			'post_tag' => 'tag',
		),
	);

	/**
	 * Slugs no post type or taxonomy may take: the index, the special sitemaps (also while they are off, so
	 * names never shift when a sitemap is switched on) and /sitemap-locations.xml of the Local SEO module.
	 */
	public const RESERVED = array( 'index', 'author', 'news', 'video', 'image', 'images', 'locations', 'style' );

	/**
	 * Per-request memo of slug maps.
	 *
	 * @var array<string, array{post_type: array<string, string>, taxonomy: array<string, string>}>
	 */
	private array $slugs = array();

	/**
	 * Per-request memo of item lists.
	 *
	 * @var array<string, array<int, array{0: int, 1: string}>>
	 */
	private array $items = array();

	/**
	 * Setting.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	private function opt( string $key, mixed $fallback = null ): mixed {
		return Options::get( 'sitemap', $key, $fallback );
	}

	/**
	 * Links per sitemap.
	 */
	public function limit(): int {
		return max( 1, min( 50000, (int) $this->opt( 'links_per_sitemap', 200 ) ) );
	}

	/**
	 * Post types included in the sitemap.
	 *
	 * @return string[]
	 */
	public function post_types(): array {
		$types = array();
		foreach ( Context::post_types() as $name => $object ) {
			if ( $this->opt( 'pt_' . $name . '_include', true ) ) {
				$types[] = $name;
			}
		}
		/**
		 * Filter the post types listed in XML sitemaps.
		 *
		 * @param string[] $types Post type names.
		 */
		return array_values( array_filter( (array) apply_filters( 'mgseo/sitemap/post_types', $types ), 'post_type_exists' ) );
	}

	/**
	 * Taxonomies included in the sitemap.
	 *
	 * @return string[]
	 */
	public function taxonomies(): array {
		$taxonomies = array();
		foreach ( Context::taxonomies() as $name => $object ) {
			if ( $this->opt( 'tax_' . $name . '_include', 'category' === $name ) ) {
				$taxonomies[] = $name;
			}
		}
		/**
		 * Filter the taxonomies listed in XML sitemaps.
		 *
		 * @param string[] $taxonomies Taxonomy names.
		 */
		return array_values( array_filter( (array) apply_filters( 'mgseo/sitemap/taxonomies', $taxonomies ), 'taxonomy_exists' ) );
	}

	/**
	 * All sitemap names that may exist: name => array( kind, subtype ).
	 *
	 * @return array<string, array{0: string, 1: string}>
	 */
	public function sitemaps(): array {
		$maps       = array();
		$types      = $this->post_types();
		$taxonomies = $this->taxonomies();
		$slugs      = $this->slugs( $types, $taxonomies );
		foreach ( $types as $type ) {
			$maps[ $slugs['post_type'][ $type ] ] = array( 'post_type', $type );
		}
		foreach ( $taxonomies as $taxonomy ) {
			$maps[ $slugs['taxonomy'][ $taxonomy ] ] = array( 'taxonomy', $taxonomy );
		}
		if ( $this->authors_enabled() ) {
			$maps['author'] = array( 'author', '' );
		}
		if ( $this->opt( 'news', false ) && $this->news_post_types() ) {
			$maps['news'] = array( 'news', '' );
		}
		if ( $this->opt( 'video', false ) && $this->video_post_types() ) {
			$maps['video'] = array( 'video', '' );
		}
		return $maps;
	}

	/**
	 * Slug of a post type or taxonomy sitemap (the {name} in /sitemap-{name}.xml).
	 *
	 * @param string $kind 'post_type' or 'taxonomy'.
	 * @param string $name Post type or taxonomy name.
	 */
	public function slug( string $kind, string $name ): string {
		$map = $this->slugs();
		if ( ! isset( $map[ $kind ][ $name ] ) ) {
			$map = $this->slugs( $this->post_types(), $this->taxonomies() );
		}
		return $map[ $kind ][ $name ] ?? self::normalize( $name );
	}

	/**
	 * Slugs of all public post types and taxonomies (plus those added with the sitemap filters).
	 *
	 * Rule, applied in a fixed order so every object always gets the same name, whatever is switched on: post,
	 * page, the other public post types in registration order, category, post_tag, the other public taxonomies,
	 * then post types and taxonomies that only the `mgseo/sitemap/post_types` / `mgseo/sitemap/taxonomies`
	 * filters add.
	 * 1. post is 'article', page 'page', category 'category', post_tag 'tag'; any other name is lower-cased with
	 *    '_' turned into '-' and characters other than a-z, 0-9 and '-' removed (product_cat → product-cat).
	 * 2. A name that is reserved (see RESERVED), already taken, empty or ends in '-<number>' (that would read as a
	 *    page number) gets the suffix '-posts' (post types) or '-terms' (taxonomies): a taxonomy named "page" is
	 *    'page-terms', a post type named "news" is 'news-posts', a post type "top-10" is 'top-10-posts'.
	 * 3. Should that be taken too, '-b', '-c' … are appended until the name is free.
	 *
	 * Post types are named before taxonomies, so on a clash the post type keeps the plain name.
	 * Reads no settings (it is also used to build the settings screen).
	 *
	 * @param string[] $extra_types      Post types beyond the public ones (from the sitemap filter).
	 * @param string[] $extra_taxonomies Taxonomies beyond the public ones (from the sitemap filter).
	 * @return array{post_type: array<string, string>, taxonomy: array<string, string>}
	 */
	public function slugs( array $extra_types = array(), array $extra_taxonomies = array() ): array {
		$types      = array_values( array_unique( array_merge( array_keys( self::ALIASES['post_type'] ), array_keys( Context::post_types() ) ) ) );
		$taxonomies = array_values( array_unique( array_merge( array_keys( self::ALIASES['taxonomy'] ), array_keys( Context::taxonomies() ) ) ) );
		$groups     = array(
			array( 'post_type', $types ),
			array( 'taxonomy', $taxonomies ),
			array( 'post_type', array_values( array_diff( array_map( 'strval', $extra_types ), $types ) ) ),
			array( 'taxonomy', array_values( array_diff( array_map( 'strval', $extra_taxonomies ), $taxonomies ) ) ),
		);
		$memo_key   = (string) wp_json_encode( $groups );
		if ( isset( $this->slugs[ $memo_key ] ) ) {
			return $this->slugs[ $memo_key ];
		}
		$taken = array_fill_keys( self::RESERVED, true );
		$map   = array(
			'post_type' => array(),
			'taxonomy'  => array(),
		);
		foreach ( $groups as [ $kind, $names ] ) {
			foreach ( $names as $name ) {
				$slug = self::ALIASES[ $kind ][ $name ] ?? self::normalize( $name );
				if ( '' === $slug || isset( $taken[ $slug ] ) || preg_match( '/-[0-9]+$/', $slug ) ) {
					$slug = ltrim( $slug . ( 'post_type' === $kind ? '-posts' : '-terms' ), '-' );
					$base = $slug;
					for ( $letter = 'b'; isset( $taken[ $slug ] ); $letter++ ) {
						$slug = $base . '-' . $letter;
					}
				}
				$taken[ $slug ]        = true;
				$map[ $kind ][ $name ] = $slug;
			}
		}
		$this->slugs[ $memo_key ] = $map;
		return $map;
	}

	/**
	 * URL-safe form of an object name: lower case, '_' → '-', only a-z, 0-9 and single hyphens.
	 *
	 * @param string $name Name.
	 */
	public static function normalize( string $name ): string {
		$slug = (string) preg_replace( '/[^a-z0-9-]+/', '', str_replace( '_', '-', strtolower( $name ) ) );
		return trim( (string) preg_replace( '/-{2,}/', '-', $slug ), '-' );
	}

	/**
	 * Public URL of a sitemap page: /sitemap.xml (index), /sitemap-{name}.xml, /sitemap-{name}-{page}.xml.
	 *
	 * @param string $name Sitemap name ('index' for the index).
	 * @param int    $page Page number.
	 */
	public static function url( string $name, int $page = 1 ): string {
		if ( 'index' === $name ) {
			return home_url( '/sitemap.xml' );
		}
		return home_url( '/sitemap-' . $name . ( $page > 1 ? '-' . $page : '' ) . '.xml' );
	}

	/**
	 * Whether a sitemap page exists (a news sitemap always has page 1 while it is on).
	 *
	 * @param string $name Sitemap name.
	 * @param int    $page Page number.
	 */
	public function exists( string $name, int $page = 1 ): bool {
		if ( 'index' === $name ) {
			return true;
		}
		if ( $page < 1 || ! isset( $this->sitemaps()[ $name ] ) ) {
			return false;
		}
		if ( 'news' === $name ) {
			return 1 === $page;
		}
		return $page <= count( $this->chunks( $name, $this->items( $name ) ) );
	}

	/**
	 * New sitemap for an address of the old scheme (MG SEO before 0.3.2, Rank Math, Yoast): sitemap_index.xml and
	 * {name}-sitemap{N}.xml, where {name} was the post type or taxonomy name, author, news or video.
	 *
	 * @param string $old  Old name ('index' for sitemap_index.xml).
	 * @param int    $page Old page number.
	 * @return array{0: string, 1: int}|null Sitemap name and page, null when nothing matches.
	 */
	public function legacy_target( string $old, int $page = 1 ): ?array {
		if ( 'index' === $old ) {
			return array( 'index', 1 );
		}
		$names = array();
		foreach ( $this->sitemaps() as $name => [ $kind, $sub ] ) {
			// Later entries win, as they did in the old scheme (a post type "news" lost to the News sitemap).
			$names[ in_array( $kind, array( 'post_type', 'taxonomy' ), true ) ? $sub : $kind ] = $name;
		}
		return isset( $names[ $old ] ) ? $this->page_target( $names[ $old ], $page ) : null;
	}

	/**
	 * New sitemap for a WordPress core sitemap address (wp-sitemap.xml, wp-sitemap-posts-post-1.xml …).
	 *
	 * @param string $path Request path, e.g. 'wp-sitemap-taxonomies-category-1.xml'.
	 * @return array{0: string, 1: int}|null
	 */
	public function core_target( string $path ): ?array {
		if ( 'wp-sitemap.xml' === $path ) {
			return array( 'index', 1 );
		}
		if ( preg_match( '/^wp-sitemap-(posts|taxonomies)-([a-z0-9_-]+)-([0-9]+)\.xml$/', $path, $m ) ) {
			$kind = 'posts' === $m[1] ? 'post_type' : 'taxonomy';
			$list = 'post_type' === $kind ? $this->post_types() : $this->taxonomies();
			return in_array( $m[2], $list, true ) ? $this->page_target( $this->slug( $kind, $m[2] ), (int) $m[3] ) : null;
		}
		if ( preg_match( '/^wp-sitemap-users-([0-9]+)\.xml$/', $path, $m ) ) {
			return $this->page_target( 'author', (int) $m[1] );
		}
		return null;
	}

	/**
	 * The same page of a sitemap when it exists, otherwise its first page (page sizes differ between plugins).
	 *
	 * @param string $name Sitemap name.
	 * @param int    $page Page.
	 * @return array{0: string, 1: int}|null
	 */
	private function page_target( string $name, int $page ): ?array {
		if ( $this->exists( $name, max( 1, $page ) ) ) {
			return array( $name, max( 1, $page ) );
		}
		return $this->exists( $name, 1 ) ? array( $name, 1 ) : null;
	}

	/**
	 * Stylesheet URL used in the processing instruction (root-relative so it stays same-origin).
	 */
	public function stylesheet_url(): string {
		if ( ! $this->opt( 'stylesheet', true ) ) {
			return '';
		}
		return wp_make_link_relative( home_url( '/sitemap.xsl' ) );
	}

	/**
	 * Index entries.
	 *
	 * @return array<int, array{loc: string, lastmod: string}>
	 */
	public function index_entries(): array {
		$entries = array();
		foreach ( $this->sitemaps() as $name => $def ) {
			$items = $this->items( $name );
			if ( ! $items ) {
				if ( 'news' === $name ) {
					// Keep the news sitemap discoverable even when no article is recent enough.
					$entries[] = array(
						'loc'     => self::url( $name ),
						'lastmod' => '',
					);
				}
				continue;
			}
			foreach ( $this->chunks( $name, $items ) as $i => $chunk ) {
				$entries[] = array(
					'loc'     => self::url( $name, $i + 1 ),
					'lastmod' => Renderer::date( (string) max( array_column( $chunk, 1 ) ) ),
				);
			}
		}
		/**
		 * Filter the sitemap index entries.
		 *
		 * @param array $entries List of array( loc, lastmod ).
		 */
		return (array) apply_filters( 'mgseo/sitemap/index', $entries );
	}

	/**
	 * Split items into pages.
	 *
	 * @param string $name  Sitemap name.
	 * @param array  $items Items.
	 * @return array<int, array<int, array{0: int, 1: string}>>
	 */
	private function chunks( string $name, array $items ): array {
		if ( 'news' === $name ) {
			return array( array_slice( $items, 0, self::NEWS_MAX ) );
		}
		return array_chunk( $items, $this->limit() );
	}

	/**
	 * XML of a sitemap page (null when it does not exist).
	 *
	 * @param string $name Sitemap name or 'index'.
	 * @param int    $page Page number (1-based).
	 */
	public function xml( string $name, int $page = 1 ): ?string {
		$page      = max( 1, $page );
		$cache_key = 'xml|' . self::SCHEME . '|' . $name . '|' . $page . '|' . $this->stylesheet_url();
		$cached    = Cache::get( $cache_key );
		if ( is_string( $cached ) ) {
			return $cached;
		}

		if ( 'index' === $name ) {
			$xml = Renderer::index( $this->index_entries(), $this->stylesheet_url() );
		} else {
			$maps = $this->sitemaps();
			if ( ! isset( $maps[ $name ] ) ) {
				return null;
			}
			$chunks = $this->chunks( $name, $this->items( $name ) );
			if ( 'news' === $name && 1 === $page && ! $chunks[0] ) {
				$chunks = array( array() );
			} elseif ( ! isset( $chunks[ $page - 1 ] ) ) {
				return null;
			}
			$xml = Renderer::urlset( $this->entries( $name, $chunks[ $page - 1 ] ), $this->stylesheet_url() );
		}
		Cache::set( $cache_key, $xml, 'news' === $name ? HOUR_IN_SECONDS : DAY_IN_SECONDS );
		return $xml;
	}

	/**
	 * Items of a sitemap (cached).
	 *
	 * @param string $name Sitemap name.
	 * @return array<int, array{0: int, 1: string}>
	 */
	public function items( string $name ): array {
		if ( isset( $this->items[ $name ] ) ) {
			return $this->items[ $name ];
		}
		$maps = $this->sitemaps();
		if ( ! isset( $maps[ $name ] ) ) {
			return array();
		}
		$cache_key = 'items|' . $name;
		$cached    = Cache::get( $cache_key );
		if ( is_array( $cached ) ) {
			$this->items[ $name ] = $cached;
			return $cached;
		}
		[ $kind, $sub ] = $maps[ $name ];
		$items          = match ( $kind ) {
			'post_type' => $this->post_items( $sub ),
			'taxonomy'  => $this->term_items( $sub ),
			'author'    => $this->author_items(),
			'news'      => $this->news_items(),
			'video'     => $this->video_items(),
			default     => array(),
		};
		$this->items[ $name ] = $items;
		Cache::set( $cache_key, $items, 'news' === $name ? HOUR_IN_SECONDS : DAY_IN_SECONDS );
		return $items;
	}

	/**
	 * Comma separated id list setting to ints.
	 *
	 * @param string $key Setting key.
	 * @return int[]
	 */
	private function ids_option( string $key ): array {
		$value = $this->opt( $key, '' );
		$value = is_array( $value ) ? $value : explode( ',', (string) $value );
		return array_values( array_filter( array_map( 'absint', $value ) ) );
	}

	/**
	 * Whether a robots directive list contains noindex (after an explicit index override).
	 *
	 * @param mixed $own       Object's own directives.
	 * @param mixed $inherited Inherited directives (type/taxonomy setting).
	 * @param bool  $forced    Extra inherited noindex (e.g. empty term).
	 */
	private static function is_noindex( mixed $own, mixed $inherited, bool $forced = false ): bool {
		$own = is_array( $own ) ? $own : array_filter( array_map( 'trim', explode( ',', (string) $own ) ) );
		if ( in_array( 'noindex', $own, true ) ) {
			return true;
		}
		if ( in_array( 'index', $own, true ) ) {
			return false;
		}
		return $forced || in_array( 'noindex', (array) $inherited, true );
	}

	/**
	 * Eligible published post ids of the given post types with their modification date.
	 *
	 * Excludes: non-published, password protected, `_mgseo_sitemap_exclude`, noindex (own or post type setting),
	 * excluded ids, canonical pointing elsewhere and posts removed with the `mgseo/sitemap/exclude_post` filter.
	 *
	 * @param string[] $post_types Post types.
	 * @param array    $args       Extra: since (GMT datetime), order (ASC|DESC), limit (int), exclude_meta (meta key), exclude_terms (term_taxonomy ids).
	 * @return array<int, array{0: int, 1: string}>
	 */
	public function eligible_posts( array $post_types, array $args = array() ): array {
		global $wpdb;
		$post_types = array_values( array_filter( $post_types, 'post_type_exists' ) );
		if ( ! $post_types ) {
			return array();
		}
		$exclude = $this->ids_option( 'exclude_posts' );
		$where   = array();
		$params  = array();

		$type_sql = array();
		foreach ( $post_types as $type ) {
			$exclude      = array_merge( $exclude, $this->ids_option( 'pt_' . $type . '_exclude' ) );
			$type_noindex = in_array( 'noindex', (array) Options::get( 'titles', 'pt_' . $type . '_robots', array() ), true );
			if ( $type_noindex ) {
				// Whole type is noindex: only posts explicitly set to index.
				$type_sql[] = "( p.post_type = %s AND EXISTS ( SELECT 1 FROM {$wpdb->postmeta} ri WHERE ri.post_id = p.ID AND ri.meta_key = '_mgseo_robots' AND ( ri.meta_value LIKE %s OR ri.meta_value LIKE %s ) ) )";
				$params[]   = $type;
				$params[]   = '%' . $wpdb->esc_like( '"index"' ) . '%';
				$params[]   = $wpdb->esc_like( 'index' ) . '%';
			} else {
				$type_sql[] = 'p.post_type = %s';
				$params[]   = $type;
			}
		}
		$where[]  = '( ' . implode( ' OR ', $type_sql ) . ' )';
		$where[]  = "p.post_status = 'publish'";
		$where[]  = "p.post_password = ''";
		$where[]  = "NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} rx WHERE rx.post_id = p.ID AND rx.meta_key = '_mgseo_robots' AND rx.meta_value LIKE %s )";
		$params[] = '%' . $wpdb->esc_like( 'noindex' ) . '%';
		$where[]  = "NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} sx WHERE sx.post_id = p.ID AND sx.meta_key = '_mgseo_sitemap_exclude' AND sx.meta_value NOT IN ( '', '0' ) )";
		if ( ! empty( $args['exclude_meta'] ) ) {
			$where[]  = "NOT EXISTS ( SELECT 1 FROM {$wpdb->postmeta} ex WHERE ex.post_id = p.ID AND ex.meta_key = %s AND ex.meta_value NOT IN ( '', '0' ) )";
			$params[] = (string) $args['exclude_meta'];
		}
		if ( ! empty( $args['since'] ) ) {
			$where[]  = 'p.post_date_gmt >= %s';
			$params[] = (string) $args['since'];
		}
		if ( ! empty( $args['exclude_terms'] ) ) {
			$tt_ids  = array_map( 'absint', (array) $args['exclude_terms'] );
			$where[] = "NOT EXISTS ( SELECT 1 FROM {$wpdb->term_relationships} tr WHERE tr.object_id = p.ID AND tr.term_taxonomy_id IN ( " . implode( ',', array_fill( 0, count( $tt_ids ), '%d' ) ) . ' ) )';
			$params  = array_merge( $params, $tt_ids );
		}
		$exclude = array_unique( array_filter( $exclude ) );
		if ( $exclude ) {
			$where[] = 'p.ID NOT IN ( ' . implode( ',', array_fill( 0, count( $exclude ), '%d' ) ) . ' )';
			$params  = array_merge( $params, $exclude );
		}
		$order = 'DESC' === ( $args['order'] ?? 'ASC' ) ? 'p.post_date_gmt DESC, p.ID DESC' : 'p.post_date_gmt ASC, p.ID ASC';
		$limit = '';
		if ( ! empty( $args['limit'] ) ) {
			$limit    = ' LIMIT %d';
			$params[] = (int) $args['limit'];
		}

		$sql = "SELECT p.ID, p.post_modified_gmt, p.post_type FROM {$wpdb->posts} p WHERE " . implode( ' AND ', $where ) . " ORDER BY {$order}{$limit}";
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- built from placeholders above; cached by Cache.
		$rows = (array) $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );

		// Posts whose canonical URL points to another page are not listed.
		$ids       = array_map( 'intval', array_column( $rows, 'ID' ) );
		$canonical = array();
		if ( $ids ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one query for the whole list.
			$canon_rows = (array) $wpdb->get_results( "SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = '_mgseo_canonical' AND meta_value <> ''", ARRAY_A );
			foreach ( $canon_rows as $row ) {
				$canonical[ (int) $row['post_id'] ] = (string) $row['meta_value'];
			}
		}
		$filtered = has_filter( 'mgseo/sitemap/exclude_post' );
		$items    = array();
		foreach ( $rows as $row ) {
			$id = (int) $row['ID'];
			if ( isset( $canonical[ $id ] ) && ! self::same_url( $canonical[ $id ], (string) get_permalink( $id ) ) ) {
				continue;
			}
			/**
			 * Filter whether a post is left out of XML sitemaps.
			 *
			 * @param bool   $exclude   Exclude.
			 * @param int    $post_id   Post id.
			 * @param string $post_type Post type.
			 */
			if ( $filtered && apply_filters( 'mgseo/sitemap/exclude_post', false, $id, (string) $row['post_type'] ) ) {
				continue;
			}
			$items[] = array( $id, (string) $row['post_modified_gmt'] );
		}
		return $items;
	}

	/**
	 * Compare two URLs ignoring scheme, trailing slash and case of host.
	 *
	 * @param string $a URL.
	 * @param string $b URL.
	 */
	private static function same_url( string $a, string $b ): bool {
		$norm = static fn( string $url ): string => strtolower( (string) preg_replace( '~^https?://~i', '', untrailingslashit( trim( $url ) ) ) );
		return $norm( $a ) === $norm( $b );
	}

	/**
	 * Items of a post type sitemap (homepage first where it belongs).
	 *
	 * @param string $type Post type.
	 * @return array<int, array{0: int, 1: string}>
	 */
	private function post_items( string $type ): array {
		$items = $this->eligible_posts( array( $type ) );
		$front = 'page' === get_option( 'show_on_front' ) ? (int) get_option( 'page_on_front' ) : 0;

		if ( $front && 'page' === $type ) {
			foreach ( $items as $i => $item ) {
				if ( $item[0] === $front ) {
					unset( $items[ $i ] );
					array_unshift( $items, $item );
					break;
				}
			}
			return array_values( $items );
		}

		if ( ! $front && 'post' === $type && ! $this->home_noindex() ) {
			$latest = $items ? (string) max( array_column( $items, 1 ) ) : '';
			array_unshift( $items, array( 0, $latest ) );
		}
		return $items;
	}

	/**
	 * Whether the (posts) homepage is set to noindex.
	 */
	private function home_noindex(): bool {
		return in_array( 'noindex', (array) Options::get( 'titles', 'home_robots', array() ), true );
	}

	/**
	 * Items of a taxonomy sitemap.
	 *
	 * @param string $taxonomy Taxonomy.
	 * @return array<int, array{0: int, 1: string}>
	 */
	private function term_items( string $taxonomy ): array {
		global $wpdb;
		$hide_empty = (bool) $this->opt( 'tax_' . $taxonomy . '_hide_empty', true );
		$terms      = get_terms(
			array(
				'taxonomy'   => $taxonomy,
				'hide_empty' => $hide_empty,
				'exclude'    => $this->ids_option( 'exclude_terms' ),
				'orderby'    => 'term_id',
				'order'      => 'ASC',
			)
		);
		if ( ! is_array( $terms ) || ! $terms ) {
			return array();
		}
		$inherited     = (array) Options::get( 'titles', 'tax_' . $taxonomy . '_robots', 'post_tag' === $taxonomy ? array( 'noindex' ) : array() );
		$noindex_empty = (bool) Options::get( 'titles', 'noindex_empty_terms', true );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one aggregate query per taxonomy, result cached.
		$rows    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT tt.term_id, MAX( p.post_modified_gmt ) AS modified FROM {$wpdb->term_taxonomy} tt INNER JOIN {$wpdb->term_relationships} tr ON tr.term_taxonomy_id = tt.term_taxonomy_id INNER JOIN {$wpdb->posts} p ON p.ID = tr.object_id WHERE tt.taxonomy = %s AND p.post_status = 'publish' GROUP BY tt.term_id", $taxonomy ), ARRAY_A );
		$lastmod = array_column( $rows, 'modified', 'term_id' );

		$items = array();
		foreach ( $terms as $term ) {
			if ( ! $term instanceof \WP_Term ) {
				continue;
			}
			if ( Meta::term( $term->term_id, 'sitemap_exclude', false ) ) {
				continue;
			}
			if ( self::is_noindex( Meta::term( $term->term_id, 'robots', array() ), $inherited, 0 === (int) $term->count && $noindex_empty ) ) {
				continue;
			}
			$canonical = (string) Meta::term( $term->term_id, 'canonical', '' );
			if ( '' !== $canonical ) {
				$link = get_term_link( $term );
				if ( is_string( $link ) && ! self::same_url( $canonical, $link ) ) {
					continue;
				}
			}
			$items[] = array( (int) $term->term_id, (string) ( $lastmod[ $term->term_id ] ?? '' ) );
		}
		return $items;
	}

	/**
	 * Whether the authors sitemap is enabled and author archives are indexable.
	 */
	public function authors_enabled(): bool {
		if ( ! $this->opt( 'authors', true ) || ! Options::get( 'titles', 'author_archive', true ) ) {
			return false;
		}
		return true;
	}

	/**
	 * Items of the author sitemap: users with published posts in included post types.
	 *
	 * @return array<int, array{0: int, 1: string}>
	 */
	private function author_items(): array {
		global $wpdb;
		$types = array_values( array_intersect( $this->post_types(), array_keys( get_post_types( array( 'public' => true ) ) ) ) );
		if ( ! $types ) {
			return array();
		}
		$args  = array(
			'has_published_posts' => $types,
			'fields'              => 'ID',
			'orderby'             => 'ID',
			'order'               => 'ASC',
		);
		$roles = array_filter( (array) $this->opt( 'author_exclude_roles', array() ) );
		if ( $roles ) {
			$args['role__not_in'] = $roles;
		}
		$users = array_map( 'intval', (array) get_users( $args ) );
		if ( ! $users ) {
			return array();
		}
		$inherited = (array) Options::get( 'titles', 'author_robots', array() );
		$sql       = "SELECT post_author, MAX( post_modified_gmt ) AS modified FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ( " . implode( ',', array_fill( 0, count( $types ), '%s' ) ) . ' ) GROUP BY post_author';
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared -- placeholders built above; cached.
		$rows    = (array) $wpdb->get_results( $wpdb->prepare( $sql, $types ), ARRAY_A );
		$lastmod = array_column( $rows, 'modified', 'post_author' );
		$items   = array();
		foreach ( $users as $user_id ) {
			if ( self::is_noindex( Meta::user( $user_id, 'robots', array() ), $inherited ) || Meta::user( $user_id, 'sitemap_exclude', false ) ) {
				continue;
			}
			$items[] = array( $user_id, (string) ( $lastmod[ $user_id ] ?? '' ) );
		}
		return $items;
	}

	/**
	 * News post types.
	 *
	 * @return string[]
	 */
	public function news_post_types(): array {
		return array_values( array_filter( (array) $this->opt( 'news_post_types', array( 'post' ) ), 'post_type_exists' ) );
	}

	/**
	 * Items of the news sitemap: articles published in the last 48 hours, newest first, max 1000.
	 *
	 * @return array<int, array{0: int, 1: string}>
	 */
	private function news_items(): array {
		$tt_ids = array();
		foreach ( array_filter( array_map( 'absint', (array) $this->opt( 'news_exclude_terms', array() ) ) ) as $term_id ) {
			$term = get_term( $term_id );
			if ( $term instanceof \WP_Term ) {
				$tt_ids[] = (int) $term->term_taxonomy_id;
			}
		}
		return $this->eligible_posts(
			$this->news_post_types(),
			array(
				'since'         => gmdate( 'Y-m-d H:i:s', time() - 2 * DAY_IN_SECONDS ),
				'order'         => 'DESC',
				'limit'         => self::NEWS_MAX,
				'exclude_meta'  => '_mgseo_news_exclude',
				'exclude_terms' => $tt_ids,
			)
		);
	}

	/**
	 * Video post types.
	 *
	 * @return string[]
	 */
	public function video_post_types(): array {
		return array_values( array_filter( (array) $this->opt( 'video_post_types', array( 'post', 'page' ) ), 'post_type_exists' ) );
	}

	/**
	 * Items of the video sitemap: eligible posts with at least one detected video.
	 *
	 * @return array<int, array{0: int, 1: string}>
	 */
	private function video_items(): array {
		$detect = (bool) $this->opt( 'video_detect', true );
		$items  = array();
		foreach ( array_chunk( $this->eligible_posts( $this->video_post_types() ), 200 ) as $chunk ) {
			foreach ( $this->load_posts( array_column( $chunk, 0 ) ) as $i => $post ) {
				$maybe = str_contains( (string) Meta::post( $post->ID, 'schema', '' ), 'VideoObject' )
					|| ( $detect && ( Video::maybe_has_video( $post->post_content ) || get_attached_media( 'video', $post ) ) );
				if ( $maybe && Video::for_post( $post, $detect ) ) {
					$items[] = $chunk[ $i ];
				}
			}
		}
		return $items;
	}

	/**
	 * Load posts by id keeping order and keys aligned with the input.
	 *
	 * @param int[] $ids Ids.
	 * @return array<int, \WP_Post>
	 */
	private function load_posts( array $ids ): array {
		$ids = array_values( array_filter( array_map( 'intval', $ids ) ) );
		if ( ! $ids ) {
			return array();
		}
		_prime_post_caches( $ids, false, true );
		$posts = array();
		foreach ( $ids as $i => $id ) {
			$post = get_post( $id );
			if ( $post instanceof \WP_Post ) {
				$posts[ $i ] = $post;
			}
		}
		return $posts;
	}

	/**
	 * Build the entries of one sitemap page.
	 *
	 * @param string $name  Sitemap name.
	 * @param array  $items Items of the page.
	 * @return array<int, array<string, mixed>>
	 */
	public function entries( string $name, array $items ): array {
		$maps = $this->sitemaps();
		if ( ! isset( $maps[ $name ] ) ) {
			return array();
		}
		[ $kind, $sub ] = $maps[ $name ];
		// Filter argument as before 0.3.2: the post type or taxonomy name, or author/news/video.
		$filter_name = in_array( $kind, array( 'post_type', 'taxonomy' ), true ) ? $sub : $kind;
		$entries     = array();

		foreach ( array_chunk( $items, 200 ) as $chunk ) {
			$objects = array();
			if ( in_array( $kind, array( 'post_type', 'news', 'video' ), true ) ) {
				$ids  = array_column( $chunk, 0 );
				$list = $this->load_posts( $ids );
				$this->prime_thumbnails( $list );
				foreach ( $list as $post ) {
					$objects[ $post->ID ] = $post;
				}
			}
			foreach ( $chunk as [ $id, $modified ] ) {
				$entry  = null;
				$object = null;
				if ( 'post_type' === $kind && 0 === $id ) {
					$entry = array(
						'loc'     => home_url( '/' ),
						'lastmod' => Renderer::date( $modified ),
					);
				} elseif ( isset( $objects[ $id ] ) ) {
					$object = $objects[ $id ];
					$entry  = match ( $kind ) {
						'news'  => $this->news_entry( $object ),
						'video' => $this->video_entry( $object ),
						default => $this->post_entry( $object ),
					};
				} elseif ( 'taxonomy' === $kind ) {
					$object = get_term( $id );
					$link   = $object instanceof \WP_Term ? get_term_link( $object ) : '';
					$entry  = is_string( $link ) && '' !== $link ? array(
						'loc'     => $link,
						'lastmod' => Renderer::date( $modified ),
					) : null;
				} elseif ( 'author' === $kind ) {
					$object = get_userdata( $id );
					$entry  = $object ? array(
						'loc'     => get_author_posts_url( $id ),
						'lastmod' => Renderer::date( $modified ),
					) : null;
				}
				if ( ! $entry ) {
					continue;
				}
				/**
				 * Filter a sitemap entry. Return false to leave it out.
				 *
				 * @param array|false $entry  Entry: loc, lastmod, images, news, videos.
				 * @param string      $type   Post type or taxonomy name, or author, news, video.
				 * @param object|null $object WP_Post, WP_Term, WP_User or null (homepage).
				 * @param string      $name   Sitemap name, the {name} in /sitemap-{name}.xml (since 0.3.2).
				 */
				$entry = apply_filters( 'mgseo/sitemap/entry', $entry, $filter_name, $object, $name );
				if ( is_array( $entry ) && ! empty( $entry['loc'] ) ) {
					$entries[] = $entry;
				}
			}
		}
		return $entries;
	}

	/**
	 * Prime attachment caches for featured images.
	 *
	 * @param \WP_Post[] $posts Posts.
	 */
	private function prime_thumbnails( array $posts ): void {
		$thumbs = array();
		foreach ( $posts as $post ) {
			$thumb = (int) get_post_meta( $post->ID, '_thumbnail_id', true );
			if ( $thumb ) {
				$thumbs[] = $thumb;
			}
		}
		if ( $thumbs ) {
			_prime_post_caches( array_unique( $thumbs ), false, true );
		}
	}

	/**
	 * Regular entry for a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>|null
	 */
	private function post_entry( \WP_Post $post ): ?array {
		$loc = (string) get_permalink( $post );
		if ( '' === $loc ) {
			return null;
		}
		return array(
			'loc'     => $loc,
			'lastmod' => Renderer::date( $post->post_modified_gmt ),
			'images'  => $this->images( $post ),
		);
	}

	/**
	 * News entry for a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>|null
	 */
	private function news_entry( \WP_Post $post ): ?array {
		$loc = (string) get_permalink( $post );
		if ( '' === $loc ) {
			return null;
		}
		$name = trim( (string) $this->opt( 'news_name', '' ) );
		return array(
			'loc'  => $loc,
			'news' => array(
				'name'             => '' !== $name ? $name : wp_specialchars_decode( (string) get_bloginfo( 'name' ), ENT_QUOTES ),
				'language'         => $this->news_language(),
				'publication_date' => Renderer::date( $post->post_date_gmt ),
				'title'            => Video::plain_title( $post ),
			),
		);
	}

	/**
	 * Google News language code (ISO 639; zh-cn / zh-tw for Chinese).
	 */
	public function news_language(): string {
		$lang = strtolower( trim( (string) $this->opt( 'news_language', '' ) ) );
		return '' !== $lang ? $lang : self::locale_language();
	}

	/**
	 * Language code derived from the site locale.
	 */
	public static function locale_language(): string {
		$locale = strtolower( str_replace( '_', '-', determine_locale() ) );
		if ( in_array( $locale, array( 'zh-cn', 'zh-tw' ), true ) ) {
			return $locale;
		}
		if ( str_starts_with( $locale, 'zh-hk' ) ) {
			return 'zh-tw';
		}
		return (string) strtok( $locale, '-' );
	}

	/**
	 * Video entry for a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>|null
	 */
	private function video_entry( \WP_Post $post ): ?array {
		$videos = Video::for_post( $post, (bool) $this->opt( 'video_detect', true ) );
		$loc    = (string) get_permalink( $post );
		if ( ! $videos || '' === $loc ) {
			return null;
		}
		return array(
			'loc'    => $loc,
			'videos' => $videos,
		);
	}

	/**
	 * Images of a post: featured image and images in the content.
	 *
	 * @param \WP_Post $post Post.
	 * @return string[]
	 */
	public function images( \WP_Post $post ): array {
		if ( ! $this->opt( 'include_images', true ) ) {
			return array();
		}
		$urls = array();
		if ( $this->opt( 'include_featured', true ) ) {
			$thumb = (int) get_post_thumbnail_id( $post );
			if ( $thumb ) {
				$urls[] = (string) wp_get_attachment_url( $thumb );
			}
		}
		$content = (string) $post->post_content;
		foreach ( \MGSEO\Helpers::content_images( $content ) as $image ) {
			$urls[] = $image['src'];
		}
		if ( str_contains( $content, '[gallery' ) && preg_match_all( '/\[gallery[^\]]*\bids=["\']?([\d,\s]+)/', $content, $m ) ) {
			foreach ( $m[1] as $list ) {
				foreach ( array_filter( array_map( 'absint', explode( ',', $list ) ) ) as $attachment_id ) {
					$urls[] = (string) wp_get_attachment_url( $attachment_id );
				}
			}
		}
		$clean = array();
		foreach ( $urls as $url ) {
			$url = trim( $url );
			if ( '' === $url || str_starts_with( $url, 'data:' ) ) {
				continue;
			}
			$url           = Video::absolute( $url );
			$clean[ $url ] = $url;
		}
		/**
		 * Filter the images listed for a post.
		 *
		 * @param string[] $images Absolute image URLs.
		 * @param \WP_Post $post   Post.
		 */
		return array_slice( array_values( (array) apply_filters( 'mgseo/sitemap/images', array_values( $clean ), $post ) ), 0, 1000 );
	}
}
