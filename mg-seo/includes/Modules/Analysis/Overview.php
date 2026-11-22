<?php
/**
 * Site overview: every indexable page sorted by SEO score with the fixes that matter most.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Analysis;

use MGSEO\Context;
use MGSEO\Helpers;
use MGSEO\Meta;

defined( 'ABSPATH' ) || exit;

/**
 * Admin page "MG SEO → Overview". Reads stored scores only (no external calls); posts without
 * current data are computed lazily, a limited number per page load.
 */
final class Overview {

	public const SLUG     = 'mg-seo-overview';
	public const CAP      = 'mgseo_view_reports';
	public const PER_PAGE = 50;

	/**
	 * All indexable published posts with their stored score data.
	 *
	 * @param array $args post_type (string, '' = all), band (good|ok|poor|none|pending, '' = all), paged, per_page, compute (lazy budget, 0 = none).
	 * @return array{rows: array<int, array<string, mixed>>, counts: array<string, int>, total: int, found: int, pages: int}
	 */
	public static function data( array $args = array() ): array {
		$args  = array_merge(
			array(
				'post_type' => '',
				'band'      => '',
				'paged'     => 1,
				'per_page'  => self::PER_PAGE,
				'compute'   => Scores::LAZY_BUDGET,
			),
			$args
		);
		$types = Scores::types( (string) $args['post_type'] );
		$rows  = $types ? self::query( $types ) : array();

		// Lazily compute missing or stale data, lowest ids first, within the budget.
		$budget = max( 0, (int) $args['compute'] );
		foreach ( $rows as $id => $row ) {
			if ( $budget <= 0 ) {
				break;
			}
			if ( null !== $row['data'] && ! Scores::needs_compute( $id ) ) {
				continue;
			}
			--$budget;
			$rows[ $id ]['data'] = Scores::compute( $id );
		}

		$counts = array(
			'poor'               => 0,
			'ok'                 => 0,
			'good'               => 0,
			Scores::BAND_NONE    => 0,
			Scores::BAND_PENDING => 0,
		);
		foreach ( $rows as $id => $row ) {
			$band                 = null === $row['data'] ? ( '' === $row['keyword'] ? Scores::BAND_NONE : Scores::BAND_PENDING ) : $row['data']['band'];
			$rows[ $id ]['band']  = $band;
			$rows[ $id ]['score'] = null === $row['data'] ? null : $row['data']['score'];
			$counts[ $band ]      = ( $counts[ $band ] ?? 0 ) + 1;
		}
		$total = count( $rows );

		$band = (string) $args['band'];
		if ( '' !== $band ) {
			$rows = array_filter( $rows, static fn( array $row ): bool => $row['band'] === $band );
		}

		// Worst first: scored pages by score, then pages waiting for a score, then pages without a keyword.
		$rank = array(
			Scores::BAND_PENDING => 1,
			Scores::BAND_NONE    => 2,
		);
		uasort(
			$rows,
			static function ( array $a, array $b ) use ( $rank ): int {
				$ra = $rank[ $a['band'] ] ?? 0;
				$rb = $rank[ $b['band'] ] ?? 0;
				if ( $ra !== $rb ) {
					return $ra <=> $rb;
				}
				return array( (int) $a['score'], $a['id'] ) <=> array( (int) $b['score'], $b['id'] );
			}
		);

		$found    = count( $rows );
		$per_page = max( 1, (int) $args['per_page'] );
		$pages    = max( 1, (int) ceil( $found / $per_page ) );
		$paged    = min( $pages, max( 1, (int) $args['paged'] ) );
		$rows     = array_values( array_slice( $rows, ( $paged - 1 ) * $per_page, $per_page, true ) );

		return array(
			'rows'   => $rows,
			'counts' => $counts,
			'total'  => $total,
			'found'  => $found,
			'pages'  => $pages,
		);
	}

	/**
	 * Indexable published posts with score-related meta, one query.
	 *
	 * @param string[] $types Post types.
	 * @return array<int, array{id: int, type: string, title: string, keyword: string, data: array|null}>
	 */
	private static function query( array $types ): array {
		global $wpdb;
		$in = implode( ',', array_fill( 0, count( $types ), '%s' ) );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber,WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- placeholders built above.
		$results = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, p.post_type, p.post_title, p.post_password, k.meta_value AS kw, r.meta_value AS robots
				FROM {$wpdb->posts} p
				LEFT JOIN {$wpdb->postmeta} k ON k.post_id = p.ID AND k.meta_key = %s
				LEFT JOIN {$wpdb->postmeta} r ON r.post_id = p.ID AND r.meta_key = %s
				WHERE p.post_status = 'publish' AND p.post_type IN ($in)
				ORDER BY p.ID ASC",
				array_merge( array( Meta::PREFIX . 'focus_keyword', Meta::PREFIX . 'robots' ), $types )
			),
			ARRAY_A
		);
		// phpcs:enable
		$rows = array();
		foreach ( (array) $results as $result ) {
			$id = (int) $result['ID'];
			if ( isset( $rows[ $id ] ) ) {
				continue;
			}
			$robots = maybe_unserialize( (string) $result['robots'] );
			if ( ! Helpers::robots_allow_index( is_array( $robots ) ? $robots : array(), (string) $result['post_password'], (string) $result['post_type'] ) ) {
				continue;
			}
			$rows[ $id ] = array(
				'id'      => $id,
				'type'    => (string) $result['post_type'],
				'title'   => (string) $result['post_title'],
				'keyword' => trim( (string) $result['kw'] ),
				'data'    => null,
			);
		}
		if ( $rows ) {
			update_meta_cache( 'post', array_keys( $rows ) );
			foreach ( array_keys( $rows ) as $id ) {
				$rows[ $id ]['data'] = Scores::data( $id );
			}
		}
		return $rows;
	}

	/**
	 * Score badge HTML.
	 *
	 * @param int|null $score     Score.
	 * @param string   $band      Band.
	 * @param int      $fix_count Number of things to improve (-1 = unknown).
	 */
	public static function badge( ?int $score, string $band, int $fix_count = -1 ): string {
		return Report::rating_html( $score, $band, array( 'fix_count' => $fix_count ) );
	}

	/**
	 * Edit link that opens the SEO box with the focus keyword field.
	 *
	 * @param int $post_id Post id.
	 */
	public static function keyword_link( int $post_id ): string {
		$link = get_edit_post_link( $post_id, 'raw' );
		return $link ? add_query_arg( 'mgseo_focus', 'keyword', $link ) . '#mgseo-box' : '';
	}

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mg-seo' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only filters.
		$type  = isset( $_GET['post_type'] ) ? sanitize_key( wp_unslash( $_GET['post_type'] ) ) : '';
		$band  = isset( $_GET['band'] ) ? sanitize_key( wp_unslash( $_GET['band'] ) ) : '';
		$paged = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable
		$types  = Context::post_types();
		$type   = isset( $types[ $type ] ) ? $type : '';
		$labels = Scores::band_labels();
		$band   = isset( $labels[ $band ] ) || Scores::BAND_PENDING === $band ? $band : '';
		$data   = self::data(
			array(
				'post_type' => $type,
				'band'      => $band,
				'paged'     => $paged,
			)
		);
		$base   = admin_url( 'admin.php?page=' . self::SLUG );
		$filter = array_filter( array( 'post_type' => $type ) );

		echo '<div class="wrap mgseo-wrap mgseo-overview">';
		echo '<h1>' . esc_html__( 'SEO Overview', 'mg-seo' ) . '</h1>';
		echo '<p>' . esc_html__( 'Every indexable page with its SEO score, worst first. Start at the top: each row lists the changes that raise the score the most. Scores are calculated on this server from the focus keyword and the content.', 'mg-seo' ) . '</p>';

		echo '<ul class="mgseo-bands">';
		$all_class = '' === $band ? ' is-current' : '';
		printf(
			'<li class="mgseo-band is-all%1$s"><a href="%2$s"><strong>%3$d</strong><span><span class="mgseo-mark is-all" aria-hidden="true"></span>%4$s</span></a></li>',
			esc_attr( $all_class ),
			esc_url( add_query_arg( $filter, $base ) ),
			(int) $data['total'],
			esc_html__( 'All pages', 'mg-seo' )
		);
		foreach ( $labels as $key => $label ) {
			printf(
				'<li class="mgseo-band %1$s%2$s"><a href="%3$s"><strong>%4$d</strong><span><span class="mgseo-mark is-%6$s" aria-hidden="true"></span>%5$s</span></a></li>',
				esc_attr( Scores::band_class( $key ) ),
				esc_attr( $band === $key ? ' is-current' : '' ),
				esc_url( add_query_arg( $filter + array( 'band' => $key ), $base ) ),
				(int) $data['counts'][ $key ],
				esc_html( $label ),
				esc_attr( $key )
			);
		}
		echo '</ul>';

		echo '<div class="mgseo-overview-bar">';
		echo '<form method="get" action="' . esc_url( admin_url( 'admin.php' ) ) . '">';
		echo '<input type="hidden" name="page" value="' . esc_attr( self::SLUG ) . '" />';
		if ( '' !== $band ) {
			echo '<input type="hidden" name="band" value="' . esc_attr( $band ) . '" />';
		}
		echo '<label for="mgseo-overview-type" class="screen-reader-text">' . esc_html__( 'Post type', 'mg-seo' ) . '</label>';
		echo '<select name="post_type" id="mgseo-overview-type"><option value="">' . esc_html__( 'All post types', 'mg-seo' ) . '</option>';
		foreach ( $types as $slug => $object ) {
			echo '<option value="' . esc_attr( $slug ) . '" ' . selected( $type, $slug, false ) . '>' . esc_html( $object->labels->name ) . '</option>';
		}
		echo '</select> ';
		submit_button( __( 'Filter', 'mg-seo' ), 'secondary', '', false );
		echo '</form>';
		self::recalc_button( $type );
		echo '</div>';

		if ( $data['counts'][ Scores::BAND_PENDING ] > 0 ) {
			echo '<div class="notice notice-info inline"><p>';
			/* translators: %d: number of pages */
			echo esc_html( sprintf( _n( '%d page has not been scored yet. It will be scored in the background, or click "Recalculate all".', '%d pages have not been scored yet. They will be scored in the background, or click "Recalculate all".', $data['counts'][ Scores::BAND_PENDING ], 'mg-seo' ), $data['counts'][ Scores::BAND_PENDING ] ) );
			echo '</p></div>';
		}

		echo '<table class="widefat striped mgseo-overview-table"><thead><tr>';
		echo '<th scope="col" class="column-score">' . esc_html__( 'Score', 'mg-seo' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Page', 'mg-seo' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'What to improve first', 'mg-seo' ) . '</th>';
		echo '<th scope="col">' . esc_html__( 'Focus keyword', 'mg-seo' ) . '</th>';
		echo '</tr></thead><tbody>';
		if ( ! $data['rows'] ) {
			echo '<tr><td colspan="4">' . esc_html__( 'No pages found.', 'mg-seo' ) . '</td></tr>';
		}
		foreach ( $data['rows'] as $row ) {
			self::render_row( $row, $types );
		}
		echo '</tbody></table>';

		if ( $data['pages'] > 1 ) {
			echo '<div class="tablenav bottom"><div class="tablenav-pages">';
			echo wp_kses_post(
				(string) paginate_links(
					array(
						'base'    => add_query_arg( 'paged', '%#%' ),
						'format'  => '',
						'current' => min( $paged, $data['pages'] ),
						'total'   => $data['pages'],
					)
				)
			);
			echo '</div></div>';
		}

		$last = (int) get_option( Scores::LAST_OPTION );
		echo '<p class="description">';
		if ( $last ) {
			/* translators: %s: date and time */
			echo esc_html( sprintf( __( 'All scores last recalculated: %s.', 'mg-seo' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $last ) ) ) . ' ';
		}
		echo esc_html__( 'Scores update when a page is saved and once a day in the background (they also depend on other pages, e.g. duplicate focus keywords).', 'mg-seo' );
		echo '</p>';
		echo '</div>';
	}

	/**
	 * One table row.
	 *
	 * @param array $row   Row from data().
	 * @param array $types Post type objects.
	 */
	private static function render_row( array $row, array $types ): void {
		$id       = (int) $row['id'];
		$can_edit = current_user_can( 'edit_post', $id );
		$edit     = $can_edit ? get_edit_post_link( $id, 'raw' ) : '';
		$title    = '' !== trim( $row['title'] ) ? $row['title'] : __( '(no title)', 'mg-seo' );
		echo '<tr class="mgseo-band-' . esc_attr( $row['band'] ) . '">';
		echo '<td class="column-score">' . self::badge( $row['score'], $row['band'], (int) ( $row['data']['fix_count'] ?? -1 ) ) . '</td>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in badge().
		echo '<td><strong>';
		if ( $edit ) {
			echo '<a href="' . esc_url( $edit . '#mgseo-box' ) . '">' . esc_html( $title ) . '</a>';
		} else {
			echo esc_html( $title );
		}
		echo '</strong><br /><small>' . esc_html( isset( $types[ $row['type'] ] ) ? $types[ $row['type'] ]->labels->singular_name : $row['type'] ) . '</small>';
		echo '<div class="row-actions visible">';
		if ( $edit ) {
			echo '<a href="' . esc_url( $edit . '#mgseo-box' ) . '">' . esc_html__( 'Edit SEO', 'mg-seo' ) . '</a> | ';
		}
		echo '<a href="' . esc_url( (string) get_permalink( $id ) ) . '">' . esc_html__( 'View', 'mg-seo' ) . '</a></div>';
		echo '</td><td>';
		if ( Scores::BAND_PENDING === $row['band'] ) {
			echo '<em>' . esc_html__( 'Not calculated yet.', 'mg-seo' ) . '</em>';
		} else {
			$issues = Scores::labelled( (array) ( $row['data']['issues'] ?? array() ) );
			if ( ! $issues ) {
				echo '<span class="mgseo-overview-ok">' . esc_html__( 'Nothing important left to fix.', 'mg-seo' ) . '</span>';
			} else {
				echo '<ul class="mgseo-issues">';
				foreach ( $issues as $issue ) {
					echo '<li class="is-' . esc_attr( $issue['status'] ) . '"><span class="mgseo-mark is-' . esc_attr( $issue['status'] ) . '" aria-hidden="true"></span> <span class="screen-reader-text">' . esc_html( 'fail' === $issue['status'] ? __( 'To improve:', 'mg-seo' ) : __( 'Worth considering:', 'mg-seo' ) ) . ' </span>' . esc_html( $issue['label'] ) . '</li>';
				}
				echo '</ul>';
			}
		}
		echo '</td><td>';
		if ( '' !== $row['keyword'] ) {
			echo esc_html( $row['keyword'] );
		} else {
			self::keyword_hint( $id, $can_edit );
		}
		echo '</td></tr>';
	}

	/**
	 * "Enter a keyword" link with a suggestion.
	 *
	 * @param int  $post_id  Post id.
	 * @param bool $can_edit Whether the user may edit the post.
	 * @param bool $short    One short line (post list column).
	 */
	public static function keyword_hint( int $post_id, bool $can_edit, bool $short = false ): void {
		$link       = $can_edit ? self::keyword_link( $post_id ) : '';
		$label      = $short ? __( 'enter a keyword in the editor', 'mg-seo' ) : __( 'Set a focus keyword', 'mg-seo' );
		$post       = get_post( $post_id );
		$suggestion = $post ? Scores::suggest_keyword( $post ) : '';
		/* translators: %s: suggested focus keyword */
		$title = '' !== $suggestion ? sprintf( __( 'Suggestion: “%s”', 'mg-seo' ), $suggestion ) : '';
		if ( $link ) {
			echo '<a class="mgseo-set-keyword" href="' . esc_url( $link ) . '" title="' . esc_attr( $title ) . '">' . esc_html( $label ) . '</a>';
		} else {
			echo '<span class="mgseo-set-keyword" title="' . esc_attr( $title ) . '">' . esc_html( $label ) . '</span>';
		}
		if ( '' !== $suggestion && ! $short ) {
			/* translators: %s: suggested focus keyword */
			echo '<br /><small class="mgseo-suggestion">' . esc_html( sprintf( __( 'Suggestion: “%s”', 'mg-seo' ), $suggestion ) ) . '</small>';
		}
	}

	/**
	 * "Recalculate scores" button (handled by assets/scores.js).
	 *
	 * @param string $post_type Post type ('' = all).
	 */
	public static function recalc_button( string $post_type = '' ): void {
		if ( ! current_user_can( 'mgseo_edit_meta' ) ) {
			return;
		}
		echo '<span class="mgseo-recalc-wrap">';
		echo '<button type="button" class="button mgseo-recalc" data-post-type="' . esc_attr( $post_type ) . '">' . esc_html__( 'Recalculate all', 'mg-seo' ) . '</button>';
		echo ' <span class="mgseo-recalc-status" aria-live="polite"></span>';
		echo '</span>';
	}
}
