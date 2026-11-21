<?php
/**
 * Local internal link suggestions.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Links;

use MGSEO\Meta;
use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Ranks published posts by relevance to the current post:
 * - TF-IDF-like similarity of weighted term vectors (focus keywords > title > excerpt/content),
 * - bonus when a candidate's focus keyword or title already appears in the current text (ready anchor),
 * - bonus for shared categories/tags and for pillar content.
 * Posts the current content already links to, the post itself and noindex posts are excluded.
 */
final class Suggester {

	/**
	 * Suggest links.
	 *
	 * @param \WP_Post $post      Current post.
	 * @param array    $overrides Unsaved editor state: content, title, keywords (comma separated).
	 * @param int|null $limit     Max suggestions.
	 * @return array<int, array<string, mixed>>
	 */
	public static function suggest( \WP_Post $post, array $overrides = array(), ?int $limit = null ): array {
		$limit    = $limit ?? (int) Options::get( 'links', 'suggestions_limit', 8 );
		$limit    = max( 1, min( 50, $limit ) );
		$content  = isset( $overrides['content'] ) && null !== $overrides['content'] ? (string) $overrides['content'] : (string) $post->post_content;
		$title    = isset( $overrides['title'] ) && null !== $overrides['title'] && '' !== $overrides['title'] ? (string) $overrides['title'] : (string) $post->post_title;
		$keywords = isset( $overrides['keywords'] ) && null !== $overrides['keywords'] ? (string) $overrides['keywords'] : (string) Meta::post( $post->ID, 'focus_keyword' );
		$keywords = array_values( array_filter( array_map( 'trim', explode( ',', $keywords ) ) ) );
		$plain    = Tokenizer::plain( $content );

		$types = Module::suggestion_post_types();
		if ( ! $types ) {
			return array();
		}

		// Exclusions: self + already linked targets.
		$exclude = array( $post->ID => true );
		foreach ( Parser::parse( $content, (string) get_permalink( $post ) ) as $link ) {
			if ( $link['target'] ) {
				$exclude[ $link['target'] ] = true;
			}
		}

		// Query vector.
		$query              = Tokenizer::vector( $title, $keywords, $plain, 40 );
		$terms              = array_map( 'strval', array_keys( $query ) );
		list( $df, $total ) = Store::document_frequency( $terms );
		$total              = max( 1, $total );
		$idf                = array();
		foreach ( $terms as $term ) {
			$idf[ $term ] = log( 1 + $total / max( 1, $df[ $term ] ?? 0 ) );
		}

		$scores = array();
		foreach ( Store::term_matches( $terms, $types, $post->ID ) as $row ) {
			$id = (int) $row->post_id;
			if ( isset( $exclude[ $id ] ) ) {
				continue;
			}
			$term          = (string) $row->term;
			$scores[ $id ] = ( $scores[ $id ] ?? 0.0 ) + ( $query[ $term ] ?? 0.0 ) * (float) $row->weight * ( $idf[ $term ] ?? 0.0 ) ** 2;
		}
		$norms = Store::norms( array_keys( $scores ) );
		foreach ( $scores as $id => $score ) {
			$scores[ $id ] = $score / max( 1.0, $norms[ $id ] ?? 1.0 );
		}
		$max = $scores ? max( $scores ) : 0.0;
		if ( $max > 0 ) {
			foreach ( $scores as $id => $score ) {
				$scores[ $id ] = $score / $max;
			}
		}

		// Shared taxonomy terms.
		$shared = Store::shared_terms( $post->ID );
		foreach ( $shared as $id => $count ) {
			if ( isset( $exclude[ $id ] ) ) {
				continue;
			}
			$scores[ $id ] = ( $scores[ $id ] ?? 0.0 ) + min( 0.3, 0.1 * $count );
		}
		if ( ! $scores ) {
			return array();
		}

		// Look at the best candidates in detail.
		arsort( $scores );
		$candidate_ids = array_slice( array_keys( $scores ), 0, max( 60, $limit * 4 ) );
		_prime_post_caches( $candidate_ids, false, true );
		$primary = $keywords[0] ?? '';
		$results = array();
		foreach ( $candidate_ids as $id ) {
			$candidate = get_post( $id );
			if ( ! $candidate || 'publish' !== $candidate->post_status || ! in_array( $candidate->post_type, $types, true ) || ! Module::is_indexable( $candidate ) ) {
				continue;
			}
			$score      = $scores[ $id ];
			$c_keywords = array_values( array_filter( array_map( 'trim', explode( ',', (string) Meta::post( $id, 'focus_keyword' ) ) ) ) );
			$c_primary  = $c_keywords[0] ?? '';
			$c_title    = get_the_title( $candidate );
			$in_content = '';
			foreach ( $c_keywords as $keyword ) {
				if ( Tokenizer::contains( $keyword, $plain ) ) {
					$in_content = $keyword;
					break;
				}
			}
			if ( '' === $in_content && '' !== $c_title && Tokenizer::contains( wp_strip_all_tags( $c_title ), $plain ) ) {
				$in_content = wp_strip_all_tags( $c_title );
			}
			if ( '' !== $in_content ) {
				$score += 1.0;
			}
			if ( '' !== $primary && Tokenizer::contains( $primary, $c_title . ' ' . implode( ' ', $c_keywords ) ) ) {
				$score += 0.3;
			}
			$pillar = (bool) Meta::post( $id, 'pillar', false );
			if ( $pillar ) {
				$score = $score * 1.25 + 0.1;
			}
			$anchors   = array_values( array_unique( array_filter( array( $in_content, $c_primary, wp_strip_all_tags( $c_title ) ) ) ) );
			$results[] = array(
				'id'         => $id,
				'title'      => wp_strip_all_tags( $c_title ),
				'url'        => (string) get_permalink( $candidate ),
				'type'       => $candidate->post_type,
				'score'      => round( $score, 4 ),
				'pillar'     => $pillar,
				'keyword'    => $c_primary,
				'in_content' => $in_content,
				'anchors'    => array_slice( $anchors, 0, 3 ),
			);
		}
		usort( $results, static fn( $a, $b ) => $b['score'] <=> $a['score'] );
		/**
		 * Filter internal link suggestions.
		 *
		 * @param array    $results Suggestions.
		 * @param \WP_Post $post    Current post.
		 */
		return array_slice( (array) apply_filters( 'mgseo/links/suggestions', $results, $post ), 0, $limit );
	}
}
