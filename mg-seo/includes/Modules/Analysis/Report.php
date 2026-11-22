<?php
/**
 * Turns analyzer checks into what people read: three groups, one hint per cause, one way to show the score.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Analysis;

defined( 'ABSPATH' ) || exit;

/**
 * Shared by every place that shows a score or hints: the SEO box, the block editor sidebar, the classic
 * Publish box, Elementor and the other page builders, the admin bar, post lists, the bulk editor and the overview.
 *
 * Groups: "fix" (failed checks, by weight), "consider" (warnings), "ok" (passed). When the primary keyword is
 * missing from the text, all keyword checks become one hint listing the places where it is missing; once the
 * keyword is used, the missing places come back as separate, smaller hints.
 */
final class Report {

	public const GROUP_FIX       = 'fix';
	public const GROUP_CONSIDER  = 'consider';
	public const GROUP_OK        = 'ok';
	public const KEYWORD_MISSING = 'keyword_missing';

	/**
	 * Keyword checks merged into "keyword_missing" when the keyword is not in the text.
	 */
	private const KEYWORD_CHECKS = array( 'keyword_in_title', 'keyword_in_description', 'keyword_in_url', 'keyword_in_intro', 'keyword_in_content', 'keyword_density', 'keyword_in_subheadings', 'keyword_in_alt', 'keyword_title_start' );

	/**
	 * Build the report.
	 *
	 * @param array  $result      Analyzer::run() result.
	 * @param bool   $has_keyword Whether a focus keyword is set.
	 * @param string $keyword     Primary keyword (for the merged hint).
	 * @param array  $context     links (bool: link suggestions available), edit_other (callable|null: id => edit URL).
	 * @return array{groups: array<int, array{id: string, label: string, items: array}>, fix_count: int, items: array}
	 */
	public static function build( array $result, bool $has_keyword, string $keyword = '', array $context = array() ): array {
		$checks = array();
		foreach ( (array) ( $result['checks'] ?? array() ) as $check ) {
			$checks[ (string) $check['id'] ] = $check;
		}

		$items   = array();
		$merged  = false;
		$content = $checks['keyword_in_content'] ?? null;
		if ( $has_keyword && $content && 'ok' !== $content['status'] ) {
			$places = array();
			foreach ( self::KEYWORD_CHECKS as $id ) {
				if ( isset( $checks[ $id ] ) && 'ok' !== $checks[ $id ]['status'] && '' !== (string) ( $checks[ $id ]['place'] ?? '' ) ) {
					$places[] = (string) $checks[ $id ]['place'];
				}
			}
			$weight = 0;
			foreach ( self::KEYWORD_CHECKS as $id ) {
				$weight += isset( $checks[ $id ] ) ? (int) $checks[ $id ]['weight'] : 0;
			}
			$in_meta = isset( $checks['keyword_in_title'] ) && 'ok' === $checks['keyword_in_title']['status'];
			$items[] = array(
				'id'      => self::KEYWORD_MISSING,
				'status'  => 'fail',
				'weight'  => $weight,
				/* translators: %s: keyword */
				'title'   => sprintf( $in_meta ? __( 'The keyword “%s” is not in the text', 'mg-seo' ) : __( 'The keyword “%s” is not on this page', 'mg-seo' ), $keyword ),
				'help'    => __( 'Google will not connect the page with what people search for. Use the keyword in the title and in the first paragraph, then in one subheading.', 'mg-seo' ),
				'places'  => $places,
				'actions' => array(),
			);
			$merged  = true;
		}

		// "The keyword appears 3 times" and "used 3 times, just right" say the same: keep one, with the inflection note.
		$both_ok = isset( $checks['keyword_in_content'], $checks['keyword_density'] ) && 'ok' === $checks['keyword_in_content']['status'] && 'ok' === $checks['keyword_density']['status'];
		if ( $both_ok ) {
			$checks['keyword_density']['help'] = (string) $checks['keyword_in_content']['help'];
			unset( $checks['keyword_in_content'] );
		}

		foreach ( $checks as $id => $check ) {
			if ( $merged && in_array( $id, self::KEYWORD_CHECKS, true ) && 'ok' !== $check['status'] ) {
				continue;
			}
			// One cause, one hint: "move the keyword to the start of the title" only once it is in the title.
			$requires = (string) ( $check['requires'] ?? '' );
			if ( '' !== $requires && 'ok' !== $check['status'] && isset( $checks[ $requires ] ) && 'ok' !== $checks[ $requires ]['status'] ) {
				continue;
			}
			$items[] = array(
				'id'      => (string) $id,
				'status'  => (string) $check['status'],
				'weight'  => (int) $check['weight'],
				'title'   => (string) $check['message'],
				'help'    => 'ok' === $check['status'] && 'keyword_density' !== $id ? '' : (string) ( $check['help'] ?? '' ),
				'places'  => array(),
				'actions' => 'ok' === $check['status'] ? array() : self::actions( $check, $context ),
			);
		}

		$groups = array(
			self::GROUP_FIX      => array(),
			self::GROUP_CONSIDER => array(),
			self::GROUP_OK       => array(),
		);
		foreach ( $items as $item ) {
			$group              = 'fail' === $item['status'] ? self::GROUP_FIX : ( 'warn' === $item['status'] ? self::GROUP_CONSIDER : self::GROUP_OK );
			$groups[ $group ][] = $item;
		}
		foreach ( array( self::GROUP_FIX, self::GROUP_CONSIDER ) as $group ) {
			usort( $groups[ $group ], static fn( array $a, array $b ): int => $b['weight'] <=> $a['weight'] );
		}

		$labels = self::group_labels( $has_keyword );
		$out    = array();
		foreach ( $groups as $id => $list ) {
			$out[] = array(
				'id'    => $id,
				'label' => $labels[ $id ],
				'items' => $list,
			);
		}
		return array(
			'groups'    => $out,
			'fix_count' => count( $groups[ self::GROUP_FIX ] ),
			'items'     => array_merge( $groups[ self::GROUP_FIX ], $groups[ self::GROUP_CONSIDER ] ),
		);
	}

	/**
	 * Group headings.
	 *
	 * @param bool $has_keyword Whether a keyword is set.
	 * @return array<string, string>
	 */
	public static function group_labels( bool $has_keyword = true ): array {
		return array(
			self::GROUP_FIX      => $has_keyword ? __( 'To improve', 'mg-seo' ) : __( 'To improve without a keyword', 'mg-seo' ),
			self::GROUP_CONSIDER => __( 'Worth considering', 'mg-seo' ),
			self::GROUP_OK       => __( 'All good', 'mg-seo' ),
		);
	}

	/**
	 * Buttons and links shown under a hint.
	 *
	 * @param array $check   Check.
	 * @param array $context See build().
	 * @return array<int, array{type: string, label: string, target?: string, url?: string}>
	 */
	private static function actions( array $check, array $context ): array {
		$data    = (array) ( $check['data'] ?? array() );
		$actions = array();
		if ( ! empty( $data['other']['url'] ) ) {
			$actions[] = array(
				'type'  => 'url',
				'label' => __( 'Open the other page', 'mg-seo' ),
				'url'   => (string) $data['other']['url'],
			);
		}
		$focus = (string) ( $data['focus'] ?? '' );
		$label = array(
			'title'       => __( 'Edit the title in Google', 'mg-seo' ),
			'description' => __( 'Write the description', 'mg-seo' ),
			'keyword'     => __( 'Change the keyword', 'mg-seo' ),
			'slug'        => __( 'Change the address', 'mg-seo' ),
		);
		if ( isset( $label[ $focus ] ) ) {
			$actions[] = array(
				'type'   => 'focus',
				'label'  => $label[ $focus ],
				'target' => $focus,
			);
		}
		if ( ! empty( $data['links'] ) && ! empty( $context['links'] ) ) {
			$actions[] = array(
				'type'  => 'links',
				'label' => __( 'Show matching pages', 'mg-seo' ),
			);
		}
		return $actions;
	}

	/**
	 * Word for a score band.
	 *
	 * @param string $band good|ok|poor|none|pending.
	 */
	public static function band_word( string $band ): string {
		return match ( $band ) {
			'good'               => __( 'Good', 'mg-seo' ),
			'ok'                 => __( 'Needs work', 'mg-seo' ),
			'poor'               => __( 'Poor', 'mg-seo' ),
			Scores::BAND_PENDING => __( 'Not calculated yet', 'mg-seo' ),
			default              => __( 'No keyword', 'mg-seo' ),
		};
	}

	/**
	 * "4 things to improve".
	 *
	 * @param int  $count       Items in the fix group.
	 * @param bool $has_keyword Whether a keyword is set.
	 */
	public static function summary( int $count, bool $has_keyword = true ): string {
		if ( ! $has_keyword ) {
			return __( 'Enter a keyword to calculate the score', 'mg-seo' );
		}
		if ( 0 === $count ) {
			return __( 'Nothing important left to fix', 'mg-seo' );
		}
		/* translators: %d: number of things to improve */
		return sprintf( _n( '%d thing to improve', '%d things to improve', $count, 'mg-seo' ), $count );
	}

	/**
	 * Short form for lists: "4 to improve".
	 *
	 * @param int $count Items in the fix group.
	 */
	public static function short_summary( int $count ): string {
		/* translators: %d: number of things to improve */
		return sprintf( _n( '%d to improve', '%d to improve', $count, 'mg-seo' ), $count );
	}

	/**
	 * Screen reader sentence: "SEO score: 21 out of 100, poor. 4 things to improve."
	 *
	 * @param int|null $score     Score.
	 * @param string   $band      Band.
	 * @param int      $fix_count Fix count (-1 = unknown).
	 */
	public static function spoken( ?int $score, string $band, int $fix_count = -1 ): string {
		if ( null === $score || Scores::BAND_NONE === $band || Scores::BAND_PENDING === $band ) {
			/* translators: %s: state, e.g. "No keyword" */
			return sprintf( __( 'SEO score: %s', 'mg-seo' ), self::band_word( $band ) );
		}
		/* translators: 1: score, 2: band word */
		$text = sprintf( __( 'SEO score: %1$d out of 100, %2$s', 'mg-seo' ), $score, mb_strtolower( self::band_word( $band ), 'UTF-8' ) );
		if ( $fix_count >= 0 ) {
			$text .= '. ' . self::summary( $fix_count );
		}
		return $text;
	}

	/**
	 * The one way a score is shown: shape mark, number and word ("21 Poor"), or "No keyword".
	 *
	 * @param int|null $score     Score, null without a keyword or before calculation.
	 * @param string   $band      good|ok|poor|none|pending.
	 * @param array    $args      fix_count (int, -1 = do not mention), max (bool: "/100"), class (extra class).
	 */
	public static function rating_html( ?int $score, string $band, array $args = array() ): string {
		$args   = array_merge(
			array(
				'fix_count' => -1,
				'max'       => false,
				'class'     => '',
			),
			$args
		);
		$class  = 'mgseo-rating is-' . sanitize_html_class( $band ) . ( '' !== $args['class'] ? ' ' . $args['class'] : '' );
		$spoken = self::spoken( $score, $band, (int) $args['fix_count'] );
		$html   = '<span class="' . esc_attr( $class ) . '" title="' . esc_attr( $spoken ) . '">';
		$html  .= '<span class="mgseo-mark is-' . esc_attr( $band ) . '" aria-hidden="true"></span>';
		$html  .= '<span class="screen-reader-text">' . esc_html( $spoken ) . '</span>';
		$html  .= '<span aria-hidden="true">';
		if ( null !== $score && in_array( $band, array( 'good', 'ok', 'poor' ), true ) ) {
			$html .= '<b class="mgseo-rating-num">' . esc_html( (string) $score ) . ( $args['max'] ? '<small>/100</small>' : '' ) . '</b> ';
			$html .= '<span class="mgseo-rating-word">' . esc_html( self::band_word( $band ) ) . '</span>';
		} elseif ( Scores::BAND_PENDING === $band ) {
			$html .= '<span class="mgseo-rating-word">–</span>';
		} else {
			$html .= '<span class="mgseo-rating-word">' . esc_html( self::band_word( Scores::BAND_NONE ) ) . '</span>';
		}
		$html .= '</span></span>';
		return $html;
	}
}
