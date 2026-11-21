<?php
/**
 * Link and term index storage.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Links;

use MGSEO\Context;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- custom tables; table names and IN() placeholder lists are built internally.

/**
 * Tables:
 * - {prefix}mgseo_links: one row per outbound link (post_id, target_post_id, url, type internal|external).
 * - {prefix}mgseo_link_terms: small weighted term index (post_id, term, weight) for link suggestions.
 */
final class Store {

	/**
	 * Links table.
	 */
	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mgseo_links';
	}

	/**
	 * Term index table.
	 */
	public static function terms_table(): string {
		global $wpdb;
		return $wpdb->prefix . 'mgseo_link_terms';
	}

	/**
	 * Create tables.
	 */
	public static function create_tables(): void {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$charset = $wpdb->get_charset_collate();
		$links   = self::table();
		$terms   = self::terms_table();
		dbDelta(
			"CREATE TABLE {$links} (
  id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  target_post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  url text NOT NULL,
  type varchar(10) NOT NULL DEFAULT 'internal',
  PRIMARY KEY  (id),
  KEY post_id (post_id),
  KEY target_post_id (target_post_id),
  KEY type (type)
) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$terms} (
  post_id bigint(20) unsigned NOT NULL DEFAULT 0,
  term varchar(32) NOT NULL DEFAULT '',
  weight float NOT NULL DEFAULT 0,
  PRIMARY KEY  (post_id,term),
  KEY term (term)
) {$charset};"
		);
	}

	/**
	 * Drop tables.
	 */
	public static function drop_tables(): void {
		global $wpdb;
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::table() );
		$wpdb->query( 'DROP TABLE IF EXISTS ' . self::terms_table() );
	}

	/**
	 * Replace the links of a post.
	 *
	 * @param int   $post_id Post id.
	 * @param array $links   Parsed links.
	 */
	public static function save_links( int $post_id, array $links ): void {
		global $wpdb;
		$table = self::table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE post_id = %d", $post_id ) );
		foreach ( array_chunk( $links, 200 ) as $chunk ) {
			$placeholders = array();
			$values       = array();
			foreach ( $chunk as $link ) {
				$placeholders[] = '(%d, %d, %s, %s)';
				array_push( $values, $post_id, (int) $link['target'], (string) $link['url'], 'external' === $link['type'] ? 'external' : 'internal' );
			}
			$wpdb->query( $wpdb->prepare( "INSERT INTO {$table} (post_id, target_post_id, url, type) VALUES " . implode( ', ', $placeholders ), $values ) );
		}
	}

	/**
	 * Replace the term vector of a post.
	 *
	 * @param int                  $post_id Post id.
	 * @param array<string, float> $vector  term => weight.
	 */
	public static function save_terms( int $post_id, array $vector ): void {
		global $wpdb;
		$table = self::terms_table();
		$wpdb->query( $wpdb->prepare( "DELETE FROM {$table} WHERE post_id = %d", $post_id ) );
		if ( ! $vector ) {
			return;
		}
		$placeholders = array();
		$values       = array();
		foreach ( $vector as $term => $weight ) {
			$placeholders[] = '(%d, %s, %f)';
			array_push( $values, $post_id, mb_substr( (string) $term, 0, 32 ), (float) $weight );
		}
		$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$table} (post_id, term, weight) VALUES " . implode( ', ', $placeholders ), $values ) );
	}

	/**
	 * Remove everything stored for a source post.
	 *
	 * @param int $post_id Post id.
	 */
	public static function delete_source( int $post_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . ' WHERE post_id = %d', $post_id ) );
		$wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::terms_table() . ' WHERE post_id = %d', $post_id ) );
	}

	/**
	 * A deleted post can no longer be a link target.
	 *
	 * @param int $post_id Post id.
	 */
	public static function detach_target( int $post_id ): void {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . ' SET target_post_id = 0 WHERE target_post_id = %d', $post_id ) );
	}

	/**
	 * Point previously unresolved links at a post that now owns their URL.
	 *
	 * @param int    $post_id Post id.
	 * @param string $url     Permalink.
	 */
	public static function attach_target( int $post_id, string $url ): void {
		global $wpdb;
		$variants = array_unique( array( $url, untrailingslashit( $url ), trailingslashit( $url ) ) );
		$in       = implode( ', ', array_fill( 0, count( $variants ), '%s' ) );
		$wpdb->query( $wpdb->prepare( 'UPDATE ' . self::table() . " SET target_post_id = %d WHERE target_post_id = 0 AND type = 'internal' AND url IN ({$in})", array_merge( array( $post_id ), $variants ) ) );
	}

	/**
	 * Placeholder list for integers.
	 *
	 * @param array $ids Ids.
	 */
	private static function in_ints( array $ids ): string {
		return implode( ', ', array_fill( 0, count( $ids ), '%d' ) );
	}

	/**
	 * Link counts for posts: internal out, external out, inbound (from other published posts).
	 *
	 * @param int[] $ids Post ids.
	 * @return array<int, array{internal: int, external: int, inbound: int}>
	 */
	public static function counts( array $ids ): array {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'intval', $ids ) ) ) );
		$out = array();
		foreach ( $ids as $id ) {
			$out[ $id ] = array(
				'internal' => 0,
				'external' => 0,
				'inbound'  => 0,
			);
		}
		if ( ! $ids ) {
			return $out;
		}
		$in   = self::in_ints( $ids );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, type, COUNT(*) AS n FROM ' . self::table() . " WHERE post_id IN ({$in}) GROUP BY post_id, type", $ids ) );
		foreach ( (array) $rows as $row ) {
			$type                                = 'external' === $row->type ? 'external' : 'internal';
			$out[ (int) $row->post_id ][ $type ] = (int) $row->n;
		}
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.target_post_id, COUNT(*) AS n FROM ' . self::table() . " l INNER JOIN {$wpdb->posts} s ON s.ID = l.post_id
				WHERE l.type = 'internal' AND l.target_post_id IN ({$in}) AND l.post_id <> l.target_post_id AND s.post_status = 'publish'
				GROUP BY l.target_post_id",
				$ids
			)
		);
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->target_post_id ]['inbound'] = (int) $row->n;
		}
		return $out;
	}

	/**
	 * Internal targets a post already links to.
	 *
	 * @param int $post_id Post id.
	 * @return int[]
	 */
	public static function targets( int $post_id ): array {
		global $wpdb;
		$ids = $wpdb->get_col( $wpdb->prepare( 'SELECT DISTINCT target_post_id FROM ' . self::table() . " WHERE post_id = %d AND type = 'internal' AND target_post_id > 0", $post_id ) );
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Published posts in managed post types without inbound internal links from other published posts.
	 *
	 * @param int $limit Max candidates examined.
	 * @return int[]
	 */
	public static function orphan_candidates( int $limit = 1000 ): array {
		global $wpdb;
		$types = array_keys( Context::post_types() );
		if ( ! $types ) {
			return array();
		}
		$in_types = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		$params   = array_merge( $types, array( $limit ) );
		$ids      = $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID FROM {$wpdb->posts} p
				WHERE p.post_status = 'publish' AND p.post_type IN ({$in_types})
				AND NOT EXISTS (
					SELECT 1 FROM " . self::table() . " l INNER JOIN {$wpdb->posts} s ON s.ID = l.post_id AND s.post_status = 'publish'
					WHERE l.target_post_id = p.ID AND l.type = 'internal' AND l.post_id <> p.ID
				)
				ORDER BY p.post_date DESC LIMIT %d",
				$params
			)
		);
		return array_map( 'intval', (array) $ids );
	}

	/**
	 * Unresolved internal links in published posts.
	 *
	 * @param int $limit Limit.
	 * @return array<int, object{post_id: string, url: string}>
	 */
	public static function unresolved( int $limit = 2000 ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.post_id, l.url FROM ' . self::table() . " l INNER JOIN {$wpdb->posts} s ON s.ID = l.post_id AND s.post_status = 'publish'
				WHERE l.type = 'internal' AND l.target_post_id = 0 ORDER BY l.post_id DESC LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Most linked-to posts.
	 *
	 * @param int $limit Limit.
	 * @return array<int, object{target_post_id: string, n: string}>
	 */
	public static function most_linked( int $limit = 10 ): array {
		global $wpdb;
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT l.target_post_id, COUNT(*) AS n FROM ' . self::table() . " l INNER JOIN {$wpdb->posts} s ON s.ID = l.post_id AND s.post_status = 'publish'
				INNER JOIN {$wpdb->posts} t ON t.ID = l.target_post_id AND t.post_status = 'publish'
				WHERE l.type = 'internal' AND l.post_id <> l.target_post_id GROUP BY l.target_post_id ORDER BY n DESC LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * Totals of the whole index.
	 *
	 * @return array{internal: int, external: int, posts: int}
	 */
	public static function totals(): array {
		global $wpdb;
		$rows = $wpdb->get_results( 'SELECT type, COUNT(*) AS n, COUNT(DISTINCT post_id) AS p FROM ' . self::table() . ' GROUP BY type' );
		$out  = array(
			'internal' => 0,
			'external' => 0,
			'posts'    => (int) $wpdb->get_var( 'SELECT COUNT(DISTINCT post_id) FROM ' . self::terms_table() ),
		);
		foreach ( (array) $rows as $row ) {
			$out[ 'external' === $row->type ? 'external' : 'internal' ] = (int) $row->n;
		}
		return $out;
	}

	/**
	 * Term rows of published candidates for a set of terms.
	 *
	 * @param string[] $terms   Terms.
	 * @param string[] $types   Post types.
	 * @param int      $exclude Post id to exclude.
	 * @return array<int, object{post_id: string, term: string, weight: string}>
	 */
	public static function term_matches( array $terms, array $types, int $exclude ): array {
		global $wpdb;
		if ( ! $terms || ! $types ) {
			return array();
		}
		$in_terms = implode( ', ', array_fill( 0, count( $terms ), '%s' ) );
		$in_types = implode( ', ', array_fill( 0, count( $types ), '%s' ) );
		return (array) $wpdb->get_results(
			$wpdb->prepare(
				'SELECT t.post_id, t.term, t.weight FROM ' . self::terms_table() . " t INNER JOIN {$wpdb->posts} p ON p.ID = t.post_id
				WHERE t.term IN ({$in_terms}) AND p.post_status = 'publish' AND p.post_type IN ({$in_types}) AND t.post_id <> %d",
				array_merge( $terms, $types, array( $exclude ) )
			)
		);
	}

	/**
	 * Document frequency per term and number of indexed documents.
	 *
	 * @param string[] $terms Terms.
	 * @return array{0: array<string, int>, 1: int}
	 */
	public static function document_frequency( array $terms ): array {
		global $wpdb;
		$table = self::terms_table();
		$total = (int) $wpdb->get_var( "SELECT COUNT(DISTINCT post_id) FROM {$table}" );
		$df    = array();
		if ( $terms ) {
			$in   = implode( ', ', array_fill( 0, count( $terms ), '%s' ) );
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT term, COUNT(*) AS n FROM {$table} WHERE term IN ({$in}) GROUP BY term", $terms ) );
			foreach ( (array) $rows as $row ) {
				$df[ (string) $row->term ] = (int) $row->n;
			}
		}
		return array( $df, $total );
	}

	/**
	 * Raw vector norms of documents.
	 *
	 * @param int[] $ids Post ids.
	 * @return array<int, float>
	 */
	public static function norms( array $ids ): array {
		global $wpdb;
		if ( ! $ids ) {
			return array();
		}
		$in   = self::in_ints( $ids );
		$rows = $wpdb->get_results( $wpdb->prepare( 'SELECT post_id, SQRT(SUM(weight * weight)) AS norm FROM ' . self::terms_table() . " WHERE post_id IN ({$in}) GROUP BY post_id", $ids ) );
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->post_id ] = (float) $row->norm;
		}
		return $out;
	}

	/**
	 * Posts sharing taxonomy terms with a post, with the number of shared terms.
	 *
	 * @param int $post_id Post id.
	 * @param int $limit   Limit.
	 * @return array<int, int> post id => shared terms
	 */
	public static function shared_terms( int $post_id, int $limit = 100 ): array {
		global $wpdb;
		$post = get_post( $post_id );
		if ( ! $post ) {
			return array();
		}
		$tt_ids = array();
		foreach ( get_object_taxonomies( $post->post_type, 'objects' ) as $taxonomy ) {
			if ( ! $taxonomy->public || 'post_format' === $taxonomy->name ) {
				continue;
			}
			$terms = get_the_terms( $post, $taxonomy->name );
			if ( is_array( $terms ) ) {
				foreach ( $terms as $term ) {
					if ( 'uncategorized' !== $term->slug ) {
						$tt_ids[] = (int) $term->term_taxonomy_id;
					}
				}
			}
		}
		if ( ! $tt_ids ) {
			return array();
		}
		$in   = self::in_ints( $tt_ids );
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT object_id, COUNT(*) AS n FROM {$wpdb->term_relationships} WHERE term_taxonomy_id IN ({$in}) AND object_id <> %d GROUP BY object_id ORDER BY n DESC LIMIT %d",
				array_merge( $tt_ids, array( $post_id, $limit ) )
			)
		);
		$out  = array();
		foreach ( (array) $rows as $row ) {
			$out[ (int) $row->object_id ] = (int) $row->n;
		}
		return $out;
	}
}
