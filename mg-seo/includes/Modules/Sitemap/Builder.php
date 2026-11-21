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
 * Sitemap names: '<post_type>', '<taxonomy>', 'author', 'news', 'video'.
 * Items are lists of array( object id, GMT datetime of last modification ); id 0 in a post type list is the homepage.
 */
final class Builder {

	public const NEWS_MAX = 1000;

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
		$maps = array();
		foreach ( $this->post_types() as $type ) {
			$maps[ $type ] = array( 'post_type', $type );
		}
		foreach ( $this->taxonomies() as $taxonomy ) {
			$maps[ $taxonomy ] = array( 'taxonomy', $taxonomy );
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
	 * Public URL of a sitemap page.
	 *
	 * @param string $name Sitemap name ('index' for the index).
	 * @param int    $page Page number.
	 */
	public static function url( string $name, int $page = 1 ): string {
		if ( 'index' === $name ) {
			return home_url( '/sitemap_index.xml' );
		}
		return home_url( '/' . $name . '-sitemap' . ( $page > 1 ? $page : '' ) . '.xml' );
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
		$cache_key = 'xml|' . $name . '|' . $page . '|' . $this->stylesheet_url();
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
		[ $kind ] = $maps[ $name ];
		$entries  = array();

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
				 * @param string      $name   Sitemap name (post type, taxonomy, author, news, video).
				 * @param object|null $object WP_Post, WP_Term, WP_User or null (homepage).
				 */
				$entry = apply_filters( 'mgseo/sitemap/entry', $entry, $name, $object );
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
