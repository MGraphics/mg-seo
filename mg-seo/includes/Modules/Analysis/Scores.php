<?php
/**
 * Stored SEO scores: computing, batches, background passes and plain-language issues.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Analysis;

use MGSEO\Context;
use MGSEO\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Every post of a managed post type gets two meta values:
 * - `_mgseo_score` (int 0-100): only when a focus keyword is set (sortable list column, overview);
 * - `_mgseo_score_data` (array): v (format version), t (time), k (has keyword), s (raw score), i (top issues).
 *
 * Scores depend on other posts (duplicate focus keywords), so they are recomputed after imports,
 * on demand (button, bulk action, WP-CLI, REST) and by a daily WP-Cron pass in small batches.
 */
final class Scores {

	/**
	 * Bump when the stored data format or the analysis changes, to recompute stale data lazily.
	 */
	public const VERSION = 1;

	public const DATA_KEY     = 'score_data';
	public const PASS_OPTION  = 'mgseo_scores_pass';
	public const LAST_OPTION  = 'mgseo_scores_last';
	public const VER_OPTION   = 'mgseo_scores_version';
	public const DAILY_EVENT  = 'mgseo_scores_daily';
	public const BATCH_EVENT  = 'mgseo_scores_batch';
	public const CRON_BATCH   = 25;
	public const CRON_DELAY   = 30;
	public const LAZY_BUDGET  = 25;
	public const STATUSES     = array( 'publish', 'future', 'draft', 'pending', 'private' );
	public const BAND_GOOD    = 80;
	public const BAND_OK      = 50;
	public const MAX_ISSUES   = 3;
	public const NO_KEYWORD   = 'no_keyword';
	public const BAND_NONE    = 'none';
	public const BAND_PENDING = 'pending';

	/**
	 * Lazy computations left in this request (null = not initialised yet).
	 *
	 * @var int|null
	 */
	private static ?int $lazy_left = null;

	/**
	 * Whether a post gets a score (managed post type, real status).
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function eligible( \WP_Post $post ): bool {
		return isset( Context::post_types()[ $post->post_type ] ) && in_array( $post->post_status, self::STATUSES, true );
	}

	/**
	 * Compute and store the score of a post.
	 *
	 * @param int|\WP_Post $post Post or id.
	 * @return array|null Stored data (see data()), null when the post is not eligible.
	 */
	public static function compute( int|\WP_Post $post ): ?array {
		$post = get_post( $post );
		if ( ! $post instanceof \WP_Post || ! self::eligible( $post ) ) {
			return null;
		}
		$result = Module::analyze_post( $post );
		$has_kw = ! empty( $result['has_keyword'] );
		$data   = array(
			'v' => self::VERSION,
			't' => time(),
			'k' => $has_kw,
			's' => (int) $result['score'],
			'i' => self::top_issues( $result['checks'], $has_kw ),
		);
		update_post_meta( $post->ID, Meta::PREFIX . self::DATA_KEY, $data );
		if ( $has_kw ) {
			update_post_meta( $post->ID, Meta::PREFIX . 'score', (int) $result['score'] );
		} else {
			delete_post_meta( $post->ID, Meta::PREFIX . 'score' );
		}
		return self::data( $post->ID );
	}

	/**
	 * Stored data of a post, normalised.
	 *
	 * @param int $post_id Post id.
	 * @return array{score: int|null, keyword: bool, issues: array<int, array{id: string, status: string}>, time: int, band: string, version: int}|null Null when never computed.
	 */
	public static function data( int $post_id ): ?array {
		$raw = get_post_meta( $post_id, Meta::PREFIX . self::DATA_KEY, true );
		if ( ! is_array( $raw ) || ! isset( $raw['v'] ) ) {
			return null;
		}
		$keyword = ! empty( $raw['k'] );
		$score   = $keyword ? (int) ( $raw['s'] ?? 0 ) : null;
		return array(
			'score'   => $score,
			'keyword' => $keyword,
			'issues'  => array_values( array_filter( (array) ( $raw['i'] ?? array() ), 'is_array' ) ),
			'time'    => (int) ( $raw['t'] ?? 0 ),
			'band'    => self::band( $score, $keyword ),
			'version' => (int) $raw['v'],
		);
	}

	/**
	 * Whether a post's stored data is missing or out of date.
	 *
	 * @param int $post_id Post id.
	 */
	public static function needs_compute( int $post_id ): bool {
		$data = self::data( $post_id );
		if ( null === $data || $data['version'] < self::VERSION ) {
			return true;
		}
		$has_kw = '' !== trim( (string) Meta::post( $post_id, 'focus_keyword' ) );
		return $has_kw !== $data['keyword'] || ( $has_kw && ! metadata_exists( 'post', $post_id, Meta::PREFIX . 'score' ) );
	}

	/**
	 * Stored data, computing it first when missing (limited number per request).
	 *
	 * @param int $post_id Post id.
	 * @return array|null Data, or null when still pending (budget used up) or not eligible.
	 */
	public static function lazy( int $post_id ): ?array {
		if ( ! self::needs_compute( $post_id ) ) {
			return self::data( $post_id );
		}
		if ( null === self::$lazy_left ) {
			/**
			 * Filter how many missing scores list screens may calculate per request (0 = only on demand).
			 *
			 * @param int $budget Number of posts.
			 */
			self::$lazy_left = max( 0, (int) apply_filters( 'mgseo/analysis/lazy_budget', self::LAZY_BUDGET ) );
		}
		if ( self::$lazy_left <= 0 ) {
			return null;
		}
		--self::$lazy_left;
		return self::compute( $post_id );
	}

	/**
	 * Reset the lazy budget (tests).
	 *
	 * @param int|null $budget Budget (null = from the filter on next use).
	 */
	public static function reset_budget( ?int $budget = null ): void {
		self::$lazy_left = $budget;
	}

	/**
	 * Band of a score.
	 *
	 * @param int|null $score   Score.
	 * @param bool     $keyword Has a focus keyword.
	 * @return string good|ok|poor|none
	 */
	public static function band( ?int $score, bool $keyword = true ): string {
		if ( ! $keyword || null === $score ) {
			return self::BAND_NONE;
		}
		return $score >= self::BAND_GOOD ? 'good' : ( $score >= self::BAND_OK ? 'ok' : 'poor' );
	}

	/**
	 * CSS class of a band (shared with the editor badge).
	 *
	 * @param string $band Band.
	 */
	public static function band_class( string $band ): string {
		return match ( $band ) {
			'good'  => 'is-good',
			'ok'    => 'is-ok',
			'poor'  => 'is-bad',
			default => 'is-none',
		};
	}

	/**
	 * Translated band labels.
	 *
	 * @return array<string, string>
	 */
	public static function band_labels(): array {
		return array(
			'poor'          => __( 'Poor (0–49)', 'mg-seo' ),
			'ok'            => __( 'Needs work (50–79)', 'mg-seo' ),
			'good'          => __( 'Good (80–100)', 'mg-seo' ),
			self::BAND_NONE => __( 'No focus keyword', 'mg-seo' ),
		);
	}

	/**
	 * The most important failed checks: failures before warnings, then by points lost.
	 *
	 * @param array $checks  Checks from Analyzer::run().
	 * @param bool  $keyword Whether a focus keyword is set.
	 * @return array<int, array{id: string, status: string}>
	 */
	public static function top_issues( array $checks, bool $keyword ): array {
		$failed = array();
		foreach ( $checks as $check ) {
			if ( 'ok' === ( $check['status'] ?? '' ) || (int) ( $check['weight'] ?? 0 ) <= 0 ) {
				continue;
			}
			$lost     = 'fail' === $check['status'] ? (float) $check['weight'] : $check['weight'] * 0.5;
			$failed[] = array(
				'id'     => (string) $check['id'],
				'status' => (string) $check['status'],
				'lost'   => $lost,
			);
		}
		usort(
			$failed,
			static function ( array $a, array $b ): int {
				if ( $a['status'] !== $b['status'] ) {
					return 'fail' === $a['status'] ? -1 : 1;
				}
				return $b['lost'] <=> $a['lost'];
			}
		);
		$issues = array();
		if ( ! $keyword ) {
			$issues[] = array(
				'id'     => self::NO_KEYWORD,
				'status' => 'fail',
			);
		}
		foreach ( $failed as $item ) {
			if ( count( $issues ) >= self::MAX_ISSUES ) {
				break;
			}
			$issues[] = array(
				'id'     => $item['id'],
				'status' => $item['status'],
			);
		}
		return $issues;
	}

	/**
	 * Plain-language advice for a check id (what to do, not what was measured).
	 *
	 * @param string $id Check id.
	 */
	public static function issue_label( string $id ): string {
		$labels = array(
			self::NO_KEYWORD         => __( 'Set a focus keyword so the page can be scored.', 'mg-seo' ),
			'title_length'           => __( 'Make the SEO title 30–60 characters long.', 'mg-seo' ),
			'description_length'     => __( 'Write a meta description of 120–160 characters.', 'mg-seo' ),
			'content_length'         => __( 'Add more content (at least 600 words is recommended).', 'mg-seo' ),
			'keyword_in_title'       => __( 'Put the focus keyword in the SEO title.', 'mg-seo' ),
			'keyword_in_description' => __( 'Use the focus keyword in the meta description.', 'mg-seo' ),
			'keyword_in_url'         => __( 'Use the focus keyword in the URL.', 'mg-seo' ),
			'keyword_in_intro'       => __( 'Mention the focus keyword in the first paragraph.', 'mg-seo' ),
			'keyword_in_content'     => __( 'Use the focus keyword in the content.', 'mg-seo' ),
			'keyword_density'        => __( 'Use the focus keyword more naturally (0.5–2.5% of the words).', 'mg-seo' ),
			'keyword_in_subheadings' => __( 'Use the focus keyword in a subheading (H2–H4).', 'mg-seo' ),
			'keyword_in_alt'         => __( 'Add an image with the focus keyword in its alt text.', 'mg-seo' ),
			'keyword_title_start'    => __( 'Move the focus keyword closer to the start of the title.', 'mg-seo' ),
			'keyword_unique'         => __( 'Another page targets the same focus keyword: pick a different one.', 'mg-seo' ),
			'url_length'             => __( 'Shorten the URL to under 75 characters.', 'mg-seo' ),
			'internal_links'         => __( 'Link to other pages of this site.', 'mg-seo' ),
			'external_links'         => __( 'Link to a trustworthy external source.', 'mg-seo' ),
			'title_number'           => __( 'Consider a number in the title.', 'mg-seo' ),
			'short_paragraphs'       => __( 'Split paragraphs longer than 150 words.', 'mg-seo' ),
			'has_media'              => __( 'Add an image or a video.', 'mg-seo' ),
		);
		/**
		 * Filter the plain-language advice for analysis checks.
		 *
		 * @param array $labels Check id => advice.
		 */
		$labels = (array) apply_filters( 'mgseo/analysis/issue_labels', $labels );
		return (string) ( $labels[ $id ] ?? $id );
	}

	/**
	 * Issues with labels.
	 *
	 * @param array $issues Issues from data().
	 * @param int   $limit  Max issues.
	 * @return array<int, array{id: string, status: string, label: string}>
	 */
	public static function labelled( array $issues, int $limit = self::MAX_ISSUES ): array {
		$out = array();
		foreach ( array_slice( $issues, 0, $limit ) as $issue ) {
			$id    = (string) ( $issue['id'] ?? '' );
			$out[] = array(
				'id'     => $id,
				'status' => (string) ( $issue['status'] ?? 'fail' ),
				'label'  => self::issue_label( $id ),
			);
		}
		return $out;
	}

	/**
	 * Suggest a focus keyword from the first H1 of the content or the post title.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function suggest_keyword( \WP_Post $post ): string {
		$source = '';
		if ( preg_match( '#<h1[^>]*>(.*?)</h1>#is', (string) $post->post_content, $m ) ) {
			$source = wp_strip_all_tags( $m[1] );
		}
		if ( '' === trim( $source ) ) {
			$source = (string) $post->post_title;
		}
		$source = html_entity_decode( wp_strip_all_tags( $source ), ENT_QUOTES, 'UTF-8' );
		// Keep the part before a separator: "Strony WWW – Firma X" → "strony www".
		$parts  = preg_split( '/\s+[|–—-]\s+|[:?!.]\s+/u', $source );
		$parts  = array_values( array_filter( array_map( 'trim', is_array( $parts ) ? $parts : array( $source ) ) ) );
		$source = trim( (string) preg_replace( '/[\p{P}\p{S}]+$/u', '', (string) ( $parts[0] ?? '' ) ) );
		$words  = preg_split( '/\s+/u', $source );
		$words  = is_array( $words ) ? array_slice( array_filter( $words ), 0, 5 ) : array();
		/**
		 * Filter the suggested focus keyword.
		 *
		 * @param string   $keyword Suggestion (may be empty).
		 * @param \WP_Post $post    Post.
		 */
		return (string) apply_filters( 'mgseo/analysis/suggest_keyword', mb_strtolower( implode( ' ', $words ), 'UTF-8' ), $post );
	}

	/**
	 * Ids of posts sharing a primary focus keyword (for recomputing duplicates).
	 *
	 * @param string $keyword Primary keyword.
	 * @param int    $exclude Post id to skip.
	 * @param int    $limit   Limit.
	 * @return int[]
	 */
	public static function sharing_keyword( string $keyword, int $exclude, int $limit = 10 ): array {
		global $wpdb;
		$keyword = trim( $keyword );
		if ( '' === $keyword ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching -- lightweight lookup.
		$rows   = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT post_id, meta_value FROM {$wpdb->postmeta} WHERE meta_key = %s AND post_id <> %d AND meta_value LIKE %s LIMIT 50",
				Meta::PREFIX . 'focus_keyword',
				$exclude,
				$wpdb->esc_like( $keyword ) . '%'
			),
			ARRAY_A
		);
		$needle = Analyzer::normalize( $keyword );
		$ids    = array();
		foreach ( (array) $rows as $row ) {
			if ( Analyzer::normalize( trim( explode( ',', (string) $row['meta_value'] )[0] ) ) === $needle ) {
				$ids[] = (int) $row['post_id'];
			}
		}
		return array_slice( array_values( array_unique( $ids ) ), 0, $limit );
	}

	/**
	 * Compute one batch of posts, by ascending id.
	 *
	 * @param int   $after Last processed id.
	 * @param int   $limit Batch size.
	 * @param array $args  post_type (string|string[]), missing (only posts without current data).
	 * @return array{last: int, processed: int, computed: int, done: bool, total: int}
	 */
	public static function batch( int $after, int $limit = 20, array $args = array() ): array {
		global $wpdb;
		$types   = self::types( $args['post_type'] ?? array() );
		$limit   = max( 1, min( 500, $limit ) );
		$missing = ! empty( $args['missing'] );
		if ( ! $types ) {
			return array(
				'last'      => $after,
				'processed' => 0,
				'computed'  => 0,
				'done'      => true,
				'total'     => 0,
			);
		}
		$in_types  = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		$in_status = implode( ',', array_fill( 0, count( self::STATUSES ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		$ids   = $wpdb->get_col( $wpdb->prepare( "SELECT ID FROM {$wpdb->posts} WHERE ID > %d AND post_type IN ($in_types) AND post_status IN ($in_status) ORDER BY ID ASC LIMIT %d", array_merge( array( $after ), $types, self::STATUSES, array( $limit ) ) ) );
		$total = 0 === $after ? (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type IN ($in_types) AND post_status IN ($in_status)", array_merge( $types, self::STATUSES ) ) ) : 0;
		// phpcs:enable
		$ids = array_map( 'intval', (array) $ids );
		if ( $ids ) {
			_prime_post_caches( $ids, false, true );
		}
		$computed = 0;
		foreach ( $ids as $id ) {
			if ( $missing && ! self::needs_compute( $id ) ) {
				continue;
			}
			if ( null !== self::compute( $id ) ) {
				++$computed;
			}
		}
		$done = count( $ids ) < $limit;
		if ( $done && ! $missing && empty( $args['post_type'] ) ) {
			// A complete pass just finished: a pending background pass would only repeat it.
			update_option( self::LAST_OPTION, time(), false );
			delete_option( self::PASS_OPTION );
		}
		return array(
			'last'      => $ids ? (int) end( $ids ) : $after,
			'processed' => count( $ids ),
			'computed'  => $computed,
			'done'      => $done,
			'total'     => $total,
		);
	}

	/**
	 * Recompute everything synchronously (WP-CLI, imports run from WP-CLI, tests).
	 *
	 * @param array         $args     See batch().
	 * @param callable|null $progress Called with each batch result.
	 * @param int           $size     Batch size.
	 * @return array{processed: int, computed: int}
	 */
	public static function recalculate_all( array $args = array(), ?callable $progress = null, int $size = 100 ): array {
		$after     = 0;
		$processed = 0;
		$computed  = 0;
		do {
			$result     = self::batch( $after, $size, $args );
			$processed += $result['processed'];
			$computed  += $result['computed'];
			$after      = $result['last'];
			if ( $progress ) {
				$progress( $result );
			}
			if ( function_exists( 'wp_cache_flush_runtime' ) ) {
				wp_cache_flush_runtime();
			}
		} while ( ! $result['done'] );
		return compact( 'processed', 'computed' );
	}

	/**
	 * Recompute selected posts.
	 *
	 * @param int[] $ids Post ids.
	 * @return int Number computed.
	 */
	public static function recalculate_ids( array $ids ): int {
		$count = 0;
		foreach ( array_unique( array_map( 'intval', $ids ) ) as $id ) {
			if ( $id > 0 && null !== self::compute( $id ) ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Start (or restart) a background pass over all posts.
	 */
	public static function start_pass(): void {
		update_option(
			self::PASS_OPTION,
			array(
				'after'   => 0,
				'started' => time(),
			),
			false
		);
		if ( ! wp_next_scheduled( self::BATCH_EVENT ) ) {
			wp_schedule_single_event( time(), self::BATCH_EVENT );
		}
	}

	/**
	 * State of the running background pass, null when idle.
	 *
	 * @return array{after: int, started: int}|null
	 */
	public static function pass(): ?array {
		$pass = get_option( self::PASS_OPTION );
		return is_array( $pass ) ? array(
			'after'   => (int) ( $pass['after'] ?? 0 ),
			'started' => (int) ( $pass['started'] ?? 0 ),
		) : null;
	}

	/**
	 * WP-Cron: one small batch of the background pass, then schedule the next one.
	 *
	 * @param int $size Batch size.
	 * @return array|null Batch result, null when no pass is running.
	 */
	public static function run_pass_batch( int $size = self::CRON_BATCH ): ?array {
		$pass = self::pass();
		if ( null === $pass ) {
			return null;
		}
		$result = self::batch( $pass['after'], $size );
		if ( $result['done'] ) {
			delete_option( self::PASS_OPTION );
			return $result;
		}
		$pass['after'] = $result['last'];
		update_option( self::PASS_OPTION, $pass, false );
		if ( ! wp_next_scheduled( self::BATCH_EVENT ) ) {
			wp_schedule_single_event( time() + self::CRON_DELAY, self::BATCH_EVENT );
		}
		return $result;
	}

	/**
	 * Schedule the daily pass and start a first pass after installing or updating to a new score format.
	 */
	public static function ensure_scheduled(): void {
		if ( ! wp_next_scheduled( self::DAILY_EVENT ) ) {
			wp_schedule_event( time() + HOUR_IN_SECONDS, 'daily', self::DAILY_EVENT );
		}
		if ( (int) get_option( self::VER_OPTION ) !== self::VERSION ) {
			update_option( self::VER_OPTION, self::VERSION, false );
			self::start_pass();
		}
	}

	/**
	 * Remove scheduled events.
	 */
	public static function unschedule(): void {
		wp_clear_scheduled_hook( self::DAILY_EVENT );
		wp_clear_scheduled_hook( self::BATCH_EVENT );
	}

	/**
	 * Managed post types, optionally narrowed.
	 *
	 * @param string|string[] $only Post type(s); empty = all managed.
	 * @return string[]
	 */
	public static function types( string|array $only = array() ): array {
		$all  = array_keys( Context::post_types() );
		$only = array_filter( (array) $only );
		return $only ? array_values( array_intersect( $all, $only ) ) : $all;
	}
}
