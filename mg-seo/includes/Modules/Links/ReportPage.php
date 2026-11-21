<?php
/**
 * Link report screen and editor tab.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Links;

use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the "Link Report" admin page (overview, orphans, broken links) and the "Links" meta box tab.
 */
final class ReportPage {

	/**
	 * Render the report.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'mgseo_view_reports' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mg-seo' ) );
		}
		$tabs = array(
			'overview' => __( 'Overview', 'mg-seo' ),
			'orphans'  => __( 'Orphan content', 'mg-seo' ),
			'broken'   => __( 'Broken internal links', 'mg-seo' ),
		);
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- view selection only.
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		$tab = isset( $tabs[ $tab ] ) ? $tab : 'overview';

		echo '<div class="wrap mgseo-wrap mgseo-links">';
		echo '<h1>' . esc_html__( 'Link Report', 'mg-seo' ) . '</h1>';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( $tabs as $id => $label ) {
			$url = add_query_arg(
				array(
					'page' => Module::REPORT_SLUG,
					'tab'  => $id,
				),
				admin_url( 'admin.php' )
			);
			printf( '<a class="nav-tab%s" href="%s">%s</a>', $id === $tab ? ' nav-tab-active' : '', esc_url( $url ), esc_html( $label ) );
		}
		echo '</nav>';

		if ( 'orphans' === $tab ) {
			self::render_orphans();
		} elseif ( 'broken' === $tab ) {
			self::render_broken();
		} else {
			self::render_overview();
		}
		echo '</div>';
	}

	/**
	 * Overview with totals, rebuild tool and most linked posts.
	 */
	private static function render_overview(): void {
		$totals = Store::totals();
		$built  = (int) get_option( Module::BUILT_OPTION, 0 );
		echo '<div class="mgseo-links-cards">';
		foreach ( array(
			array( __( 'Indexed posts', 'mg-seo' ), $totals['posts'] ),
			array( __( 'Internal links', 'mg-seo' ), $totals['internal'] ),
			array( __( 'External links', 'mg-seo' ), $totals['external'] ),
		) as $card ) {
			echo '<div class="mgseo-links-card"><div class="mgseo-links-card-label">' . esc_html( $card[0] ) . '</div><div class="mgseo-links-card-value">' . esc_html( number_format_i18n( (int) $card[1] ) ) . '</div></div>';
		}
		echo '</div>';

		echo '<h2>' . esc_html__( 'Link index', 'mg-seo' ) . '</h2>';
		echo '<p>' . esc_html__( 'Links are counted automatically whenever a post is saved. Rebuild the index after importing content or changing permalinks.', 'mg-seo' ) . '</p>';
		if ( $built ) {
			/* translators: %s: date and time */
			echo '<p class="description">' . esc_html( sprintf( __( 'Last full build: %s', 'mg-seo' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $built ) ) ) . '</p>';
		} elseif ( wp_next_scheduled( Module::REBUILD_EVENT, array( 0 ) ) || $totals['posts'] ) {
			echo '<p class="description">' . esc_html__( 'Existing content is being indexed in the background.', 'mg-seo' ) . '</p>';
		}
		if ( current_user_can( 'mgseo_manage_settings' ) ) {
			echo '<p><button type="button" class="button mgseo-links-rebuild">' . esc_html__( 'Rebuild link index', 'mg-seo' ) . '</button> <span class="mgseo-links-progress" aria-live="polite"></span></p>';
		}

		$rows = Store::most_linked( 15 );
		echo '<h2>' . esc_html__( 'Most linked content', 'mg-seo' ) . '</h2>';
		if ( ! $rows ) {
			echo '<p class="mgseo-links-none">' . esc_html__( 'No internal links found yet.', 'mg-seo' ) . '</p>';
			return;
		}
		$ids    = array_map( static fn( $r ) => (int) $r->target_post_id, $rows );
		$counts = Store::counts( $ids );
		self::posts_table( $ids, $counts );
	}

	/**
	 * Table of posts with counts.
	 *
	 * @param int[] $ids    Post ids.
	 * @param array $counts Counts.
	 */
	private static function posts_table( array $ids, array $counts ): void {
		echo '<table class="widefat striped mgseo-links-table"><thead><tr><th>' . esc_html__( 'Title', 'mg-seo' ) . '</th><th>' . esc_html__( 'Type', 'mg-seo' ) . '</th><th class="num">' . esc_html__( 'Internal out', 'mg-seo' ) . '</th><th class="num">' . esc_html__( 'External out', 'mg-seo' ) . '</th><th class="num">' . esc_html__( 'Inbound', 'mg-seo' ) . '</th></tr></thead><tbody>';
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post ) {
				continue;
			}
			$type  = get_post_type_object( $post->post_type );
			$edit  = get_edit_post_link( $id );
			$title = '' !== get_the_title( $post ) ? get_the_title( $post ) : __( '(no title)', 'mg-seo' );
			echo '<tr><td>';
			echo $edit ? '<a href="' . esc_url( $edit ) . '"><strong>' . esc_html( $title ) . '</strong></a>' : '<strong>' . esc_html( $title ) . '</strong>';
			echo ' <a class="mgseo-links-view" href="' . esc_url( (string) get_permalink( $post ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'View', 'mg-seo' ) . '</a>';
			echo '</td><td>' . esc_html( $type ? $type->labels->singular_name : $post->post_type ) . '</td>';
			foreach ( array( 'internal', 'external', 'inbound' ) as $key ) {
				echo '<td class="num">' . esc_html( number_format_i18n( (int) ( $counts[ $id ][ $key ] ?? 0 ) ) ) . '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Orphan posts.
	 *
	 * @return int[] Orphan post ids (published, indexable, zero inbound internal links).
	 */
	public static function orphans(): array {
		$skip = array_filter( array( (int) get_option( 'page_on_front' ), (int) get_option( 'page_for_posts' ) ) );
		$out  = array();
		$ids  = Store::orphan_candidates( 1000 );
		_prime_post_caches( $ids, false, true );
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post || in_array( $id, $skip, true ) || ! Module::is_indexable( $post ) ) {
				continue;
			}
			$out[] = $id;
		}
		return $out;
	}

	/**
	 * Orphans tab.
	 */
	private static function render_orphans(): void {
		echo '<p>' . esc_html__( 'Published, indexable content that no other published post links to. Search engines and visitors find these pages only through menus, archives or the sitemap — add internal links to them.', 'mg-seo' ) . '</p>';
		$ids = self::orphans();
		if ( ! $ids ) {
			echo '<p class="mgseo-links-none">' . esc_html__( 'No orphan content found.', 'mg-seo' ) . '</p>';
			return;
		}
		/* translators: %d: number of posts */
		echo '<p><strong>' . esc_html( sprintf( _n( '%d orphan post', '%d orphan posts', count( $ids ), 'mg-seo' ), count( $ids ) ) ) . '</strong></p>';
		self::posts_table( array_slice( $ids, 0, 500 ), Store::counts( array_slice( $ids, 0, 500 ) ) );
	}

	/**
	 * Broken links tab.
	 */
	private static function render_broken(): void {
		echo '<p>' . esc_html__( 'Internal links in published content whose URL matches no post, archive, uploaded file or redirect. Checked locally without crawling.', 'mg-seo' ) . '</p>';
		$rows = Broken::find();
		if ( ! $rows ) {
			echo '<p class="mgseo-links-none">' . esc_html__( 'No broken internal links found.', 'mg-seo' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped mgseo-links-table"><thead><tr><th>' . esc_html__( 'Link URL', 'mg-seo' ) . '</th><th>' . esc_html__( 'Found in', 'mg-seo' ) . '</th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			$edit = get_edit_post_link( $row['post_id'] );
			echo '<tr><td><code>' . esc_html( $row['url'] ) . '</code></td><td>';
			echo $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( get_the_title( $row['post_id'] ) ) . '</a>' : esc_html( get_the_title( $row['post_id'] ) );
			echo '</td></tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Editor "Links" tab: counts and suggestions.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_box( \WP_Post $post ): void {
		$counts = Store::counts( array( $post->ID ) )[ $post->ID ];
		echo '<div class="mgseo-links-box" data-post-id="' . esc_attr( (string) $post->ID ) . '">';
		echo '<ul class="mgseo-links-stats">';
		echo '<li><strong>' . esc_html( number_format_i18n( $counts['internal'] ) ) . '</strong> ' . esc_html__( 'internal links', 'mg-seo' ) . '</li>';
		echo '<li><strong>' . esc_html( number_format_i18n( $counts['external'] ) ) . '</strong> ' . esc_html__( 'external links', 'mg-seo' ) . '</li>';
		echo '<li><strong>' . esc_html( number_format_i18n( $counts['inbound'] ) ) . '</strong> ' . esc_html__( 'inbound internal links', 'mg-seo' ) . '</li>';
		echo '</ul>';
		echo '<p class="description">' . esc_html__( 'Counts reflect the last saved version.', 'mg-seo' ) . '</p>';

		if ( ! Options::get( 'links', 'suggestions', true ) ) {
			echo '</div>';
			return;
		}
		echo '<h4>' . esc_html__( 'Internal link suggestions', 'mg-seo' ) . ' <button type="button" class="button button-small mgseo-links-refresh">' . esc_html__( 'Refresh', 'mg-seo' ) . '</button></h4>';
		echo '<p class="description">' . esc_html__( 'Related published content ranked locally by focus keywords, titles, shared categories and tags. Pillar content is preferred; posts you already link to are skipped.', 'mg-seo' ) . '</p>';
		echo '<div class="mgseo-links-suggestions" aria-live="polite">';
		self::suggestions_html( Suggester::suggest( $post ) );
		echo '</div></div>';
	}

	/**
	 * Suggestions list (server-rendered; links.js renders the same markup after a refresh).
	 *
	 * @param array $items Suggestions.
	 */
	public static function suggestions_html( array $items ): void {
		if ( ! $items ) {
			echo '<p class="mgseo-links-none">' . esc_html__( 'No suggestions. Add focus keywords or more content.', 'mg-seo' ) . '</p>';
			return;
		}
		echo '<ul class="mgseo-links-list">';
		foreach ( $items as $item ) {
			echo '<li class="mgseo-links-item">';
			echo '<a class="mgseo-links-title" href="' . esc_url( $item['url'] ) . '" target="_blank" rel="noopener">' . esc_html( $item['title'] ) . '</a>';
			if ( $item['pillar'] ) {
				echo ' <span class="mgseo-links-badge">' . esc_html__( 'Pillar', 'mg-seo' ) . '</span>';
			}
			echo ' <button type="button" class="button-link mgseo-links-copy" data-copy="' . esc_attr( $item['url'] ) . '">' . esc_html__( 'Copy link', 'mg-seo' ) . '</button>';
			if ( '' !== $item['in_content'] ) {
				echo '<div class="mgseo-links-hint">' . esc_html__( 'Phrase found in your text:', 'mg-seo' ) . ' <mark>' . esc_html( $item['in_content'] ) . '</mark></div>';
			}
			echo '<div class="mgseo-links-anchors">';
			foreach ( $item['anchors'] as $anchor ) {
				$html = '<a href="' . esc_url( $item['url'] ) . '">' . esc_html( $anchor ) . '</a>';
				echo '<button type="button" class="mgseo-links-anchor" data-copy="' . esc_attr( $html ) . '" title="' . esc_attr__( 'Copy as HTML link', 'mg-seo' ) . '">' . esc_html( $anchor ) . '</button> ';
			}
			echo '</div></li>';
		}
		echo '</ul>';
	}
}
