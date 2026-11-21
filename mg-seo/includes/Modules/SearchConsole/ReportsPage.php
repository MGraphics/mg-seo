<?php
/**
 * Search Console reports screen and editor box.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\SearchConsole;

defined( 'ABSPATH' ) || exit;

/**
 * Renders the "Search Performance" admin page and the per-post panel.
 */
final class ReportsPage {

	/**
	 * Report tabs.
	 *
	 * @return array<string, string>
	 */
	private static function tabs(): array {
		return array(
			'overview' => __( 'Overview', 'mg-seo' ),
			'pages'    => __( 'Pages', 'mg-seo' ),
			'queries'  => __( 'Keywords', 'mg-seo' ),
			'movers'   => __( 'Winning & losing', 'mg-seo' ),
			'tracker'  => __( 'Rank tracker', 'mg-seo' ),
		);
	}

	/**
	 * Allowed ranges.
	 *
	 * @return array<int, string>
	 */
	private static function ranges(): array {
		return array(
			7   => __( 'Last 7 days', 'mg-seo' ),
			28  => __( 'Last 28 days', 'mg-seo' ),
			90  => __( 'Last 3 months', 'mg-seo' ),
			180 => __( 'Last 6 months', 'mg-seo' ),
			365 => __( 'Last 12 months', 'mg-seo' ),
		);
	}

	/**
	 * Format helpers.
	 *
	 * @param float $ctr CTR 0..1.
	 */
	public static function pct( float $ctr ): string {
		return number_format_i18n( $ctr * 100, 1 ) . '%';
	}

	/**
	 * Format a position.
	 *
	 * @param float|null $position Position.
	 */
	public static function pos( ?float $position ): string {
		return null === $position || $position <= 0 ? '—' : number_format_i18n( $position, 1 );
	}

	/**
	 * Change badge.
	 *
	 * @param float|null $change   Change (positive = better).
	 * @param string     $suffix   Suffix.
	 * @param int        $decimals Decimals.
	 */
	private static function delta( ?float $change, string $suffix = '', int $decimals = 1 ): string {
		if ( null === $change || abs( $change ) < 0.05 ) {
			return '<span class="mgseo-gsc-delta">±0' . esc_html( $suffix ) . '</span>';
		}
		$class = $change > 0 ? 'is-up' : 'is-down';
		$sign  = $change > 0 ? '+' : '−';
		return '<span class="mgseo-gsc-delta ' . esc_attr( $class ) . '">' . esc_html( $sign . number_format_i18n( abs( $change ), $decimals ) . $suffix ) . '</span>';
	}

	/**
	 * Relative change in percent.
	 *
	 * @param float $current  Current.
	 * @param float $previous Previous.
	 */
	private static function rel( float $current, float $previous ): ?float {
		if ( $previous <= 0 ) {
			return null;
		}
		return ( $current - $previous ) / $previous * 100;
	}

	/**
	 * Render the page.
	 */
	public static function render(): void {
		if ( ! current_user_can( 'mgseo_view_reports' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mg-seo' ) );
		}
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- view filters only.
		$tab     = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'overview';
		$range   = isset( $_GET['range'] ) ? absint( $_GET['range'] ) : 28;
		$orderby = isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'clicks';
		// phpcs:enable
		$tab    = isset( self::tabs()[ $tab ] ) ? $tab : 'overview';
		$range  = isset( self::ranges()[ $range ] ) ? $range : 28;
		$period = Reports::period( $range );

		echo '<div class="wrap mgseo-wrap mgseo-gsc">';
		echo '<h1>' . esc_html__( 'Search Performance', 'mg-seo' ) . '</h1>';

		if ( ! OAuth::connected() ) {
			echo '<div class="mgseo-gsc-empty"><p>' . esc_html__( 'Connect your own Google Cloud OAuth client to import Search Console data. Until then nothing is sent to Google.', 'mg-seo' ) . '</p>';
			if ( current_user_can( 'mgseo_manage_settings' ) ) {
				echo '<p><a class="button button-primary" href="' . esc_url( admin_url( 'admin.php?page=mg-seo-gsc' ) ) . '">' . esc_html__( 'Set up Search Console', 'mg-seo' ) . '</a></p>';
			}
			echo '</div>';
		}

		echo '<div class="mgseo-gsc-toolbar">';
		echo '<nav class="nav-tab-wrapper">';
		foreach ( self::tabs() as $id => $label ) {
			printf( '<a class="nav-tab%s" href="%s">%s</a>', $id === $tab ? ' nav-tab-active' : '', esc_url( self::url( $id, $range ) ), esc_html( $label ) );
		}
		echo '</nav>';
		echo '<form method="get" class="mgseo-gsc-range"><input type="hidden" name="page" value="' . esc_attr( Module::REPORTS_SLUG ) . '" /><input type="hidden" name="tab" value="' . esc_attr( $tab ) . '" />';
		echo '<label class="screen-reader-text" for="mgseo-gsc-range">' . esc_html__( 'Date range', 'mg-seo' ) . '</label><select id="mgseo-gsc-range" name="range">';
		foreach ( self::ranges() as $days => $label ) {
			printf( '<option value="%d" %s>%s</option>', (int) $days, selected( $range, $days, false ), esc_html( $label ) );
		}
		echo '</select> <button class="button">' . esc_html__( 'Apply', 'mg-seo' ) . '</button>';
		if ( Client::ready() && current_user_can( 'mgseo_manage_settings' ) ) {
			echo ' <a class="button" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=mgseo_gsc_sync&to=reports' ), 'mgseo_gsc_sync' ) ) . '">' . esc_html__( 'Sync now', 'mg-seo' ) . '</a>';
		}
		echo '</form></div>';

		$property = Client::property();
		$property = '' === $property ? '—' : $property;
		/* translators: 1: start date, 2: end date, 3: property */
		echo '<p class="description">' . esc_html( sprintf( __( '%1$s – %2$s · Property: %3$s', 'mg-seo' ), $period['start'], $period['end'], $property ) ) . '</p>';

		switch ( $tab ) {
			case 'pages':
				self::render_table( Reports::top_pages( $period['start'], $period['end'], 200, $orderby ), 'page', $tab, $range, $orderby );
				break;
			case 'queries':
				self::render_table( Reports::top_queries( $period['start'], $period['end'], 200, $orderby ), 'query', $tab, $range, $orderby );
				break;
			case 'movers':
				self::render_movers( $period );
				break;
			case 'tracker':
				self::render_tracker( $period );
				break;
			default:
				self::render_overview( $period, $range );
		}
		echo '</div>';
	}

	/**
	 * Report URL.
	 *
	 * @param string $tab     Tab.
	 * @param int    $range   Range.
	 * @param string $orderby Order.
	 */
	private static function url( string $tab, int $range, string $orderby = '' ): string {
		$args = array(
			'page'  => Module::REPORTS_SLUG,
			'tab'   => $tab,
			'range' => $range,
		);
		if ( '' !== $orderby ) {
			$args['orderby'] = $orderby;
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	/**
	 * Overview: stat cards, charts and top lists.
	 *
	 * @param array $period Period.
	 * @param int   $range  Range.
	 */
	private static function render_overview( array $period, int $range ): void {
		$cur   = Reports::totals( $period['start'], $period['end'] );
		$prev  = Reports::totals( $period['prev_start'], $period['prev_end'] );
		$daily = Reports::daily( $period['start'], $period['end'] );
		$cards = array(
			'clicks'      => array( __( 'Clicks', 'mg-seo' ), number_format_i18n( $cur['clicks'] ), self::delta( self::rel( $cur['clicks'], $prev['clicks'] ), '%' ), false ),
			'impressions' => array( __( 'Impressions', 'mg-seo' ), number_format_i18n( $cur['impressions'] ), self::delta( self::rel( $cur['impressions'], $prev['impressions'] ), '%' ), false ),
			'ctr'         => array( __( 'Average CTR', 'mg-seo' ), self::pct( $cur['ctr'] ), self::delta( ( $cur['ctr'] - $prev['ctr'] ) * 100, ' pp' ), false ),
			'position'    => array( __( 'Average position', 'mg-seo' ), self::pos( $cur['position'] ), $prev['position'] > 0 && $cur['position'] > 0 ? self::delta( $prev['position'] - $cur['position'] ) : self::delta( null ), true ),
		);
		echo '<div class="mgseo-gsc-cards">';
		foreach ( $cards as $metric => $card ) {
			$series = array();
			foreach ( $daily as $date => $row ) {
				$series[ $date ] = 'position' === $metric ? ( $row['position'] > 0 ? $row['position'] : null ) : ( 'ctr' === $metric ? $row['ctr'] * 100 : $row[ $metric ] );
			}
			echo '<div class="mgseo-gsc-card mgseo-gsc-metric-' . esc_attr( $metric ) . '">';
			echo '<div class="mgseo-gsc-card-label">' . esc_html( $card[0] ) . '</div>';
			echo '<div class="mgseo-gsc-card-value">' . esc_html( $card[1] ) . ' ' . wp_kses_post( $card[2] ) . '</div>';
			$svg = Chart::line(
				$series,
				array(
					'label'  => $card[0],
					'invert' => $card[3],
					'class'  => 'is-' . $metric,
					'width'  => 300,
					'height' => 110,
					'format' => 'ctr' === $metric ? static fn( $v ) => number_format_i18n( (float) $v, 1 ) . '%' : ( 'position' === $metric ? static fn( $v ) => number_format_i18n( (float) $v, 1 ) : static fn( $v ) => number_format_i18n( (float) $v ) ),
				)
			);
			echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built with escaped values.
			echo '</div>';
		}
		echo '</div>';
		/* translators: %d: number of days */
		echo '<p class="description">' . esc_html( sprintf( __( 'Compared with the previous %d days.', 'mg-seo' ), $range ) ) . '</p>';

		echo '<div class="mgseo-gsc-columns"><div>';
		echo '<h2>' . esc_html__( 'Top pages', 'mg-seo' ) . '</h2>';
		self::render_table( Reports::top_pages( $period['start'], $period['end'], 10 ), 'page', 'pages', $range, '', false );
		echo '<p><a href="' . esc_url( self::url( 'pages', $range ) ) . '">' . esc_html__( 'All pages →', 'mg-seo' ) . '</a></p>';
		echo '</div><div>';
		echo '<h2>' . esc_html__( 'Top keywords', 'mg-seo' ) . '</h2>';
		self::render_table( Reports::top_queries( $period['start'], $period['end'], 10 ), 'query', 'queries', $range, '', false );
		echo '<p><a href="' . esc_url( self::url( 'queries', $range ) ) . '">' . esc_html__( 'All keywords →', 'mg-seo' ) . '</a></p>';
		echo '</div></div>';
	}

	/**
	 * Page title for a URL (local lookup).
	 *
	 * @param string $url URL.
	 */
	private static function page_label( string $url ): string {
		$post_id = url_to_postid( $url );
		$path    = (string) wp_parse_url( $url, PHP_URL_PATH );
		$label   = '' !== $path ? $path : $url;
		if ( $post_id ) {
			$edit  = get_edit_post_link( $post_id );
			$label = '<strong>' . esc_html( get_the_title( $post_id ) ) . '</strong>';
			if ( $edit ) {
				$label = '<a href="' . esc_url( $edit ) . '">' . $label . '</a>';
			}
			return $label . '<br /><a class="mgseo-gsc-url" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $path ) . '</a>';
		}
		return '<a class="mgseo-gsc-url" href="' . esc_url( $url ) . '" target="_blank" rel="noopener">' . esc_html( $label ) . '</a>';
	}

	/**
	 * Table of pages or queries.
	 *
	 * @param array  $rows     Rows.
	 * @param string $kind     page|query.
	 * @param string $tab      Tab for sort links.
	 * @param int    $range    Range.
	 * @param string $orderby  Current order.
	 * @param bool   $sortable Show sort links.
	 */
	private static function render_table( array $rows, string $kind, string $tab, int $range, string $orderby = 'clicks', bool $sortable = true ): void {
		if ( ! $rows ) {
			echo '<p class="mgseo-gsc-none">' . esc_html__( 'No data for this period yet.', 'mg-seo' ) . '</p>';
			return;
		}
		$cols    = array(
			'clicks'      => __( 'Clicks', 'mg-seo' ),
			'impressions' => __( 'Impressions', 'mg-seo' ),
			'ctr'         => __( 'CTR', 'mg-seo' ),
			'position'    => __( 'Position', 'mg-seo' ),
		);
		$tracked = 'query' === $kind ? Reports::tracked_keywords() : array();
		echo '<table class="widefat striped mgseo-gsc-table"><thead><tr><th>' . esc_html( 'page' === $kind ? __( 'Page', 'mg-seo' ) : __( 'Keyword', 'mg-seo' ) ) . '</th>';
		foreach ( $cols as $key => $label ) {
			if ( $sortable ) {
				printf( '<th class="num"><a href="%s"%s>%s</a></th>', esc_url( self::url( $tab, $range, $key ) ), $key === $orderby ? ' class="is-sorted"' : '', esc_html( $label ) );
			} else {
				echo '<th class="num">' . esc_html( $label ) . '</th>';
			}
		}
		if ( 'query' === $kind && $sortable && current_user_can( 'mgseo_view_reports' ) ) {
			echo '<th><span class="screen-reader-text">' . esc_html__( 'Track', 'mg-seo' ) . '</span></th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>';
			if ( 'page' === $kind ) {
				echo wp_kses_post( self::page_label( (string) $row['name'] ) );
			} else {
				echo esc_html( (string) $row['name'] );
			}
			echo '</td><td class="num">' . esc_html( number_format_i18n( $row['clicks'] ) ) . '</td>';
			echo '<td class="num">' . esc_html( number_format_i18n( $row['impressions'] ) ) . '</td>';
			echo '<td class="num">' . esc_html( self::pct( $row['ctr'] ) ) . '</td>';
			echo '<td class="num">' . esc_html( self::pos( $row['position'] ) ) . '</td>';
			if ( 'query' === $kind && $sortable ) {
				echo '<td>';
				if ( in_array( (string) $row['name'], $tracked, true ) ) {
					echo '<span class="mgseo-gsc-tracked">' . esc_html__( 'Tracked', 'mg-seo' ) . '</span>';
				} else {
					printf( '<a class="button button-small" href="%s">%s</a>', esc_url( self::track_url( 'add', (string) $row['name'] ) ), esc_html__( 'Track', 'mg-seo' ) );
				}
				echo '</td>';
			}
			echo '</tr>';
		}
		echo '</tbody></table>';
	}

	/**
	 * Winners and losers.
	 *
	 * @param array $period Period.
	 */
	private static function render_movers( array $period ): void {
		$movers = Reports::movers( $period );
		echo '<p class="description">' . esc_html__( 'Average position of each keyword compared with the previous period of the same length (keywords with at least 5 impressions in both periods).', 'mg-seo' ) . '</p>';
		echo '<div class="mgseo-gsc-columns">';
		foreach ( array(
			'winners' => __( 'Winning keywords', 'mg-seo' ),
			'losers'  => __( 'Losing keywords', 'mg-seo' ),
		) as $key => $title ) {
			echo '<div><h2>' . esc_html( $title ) . '</h2>';
			if ( ! $movers[ $key ] ) {
				echo '<p class="mgseo-gsc-none">' . esc_html__( 'Nothing to show yet.', 'mg-seo' ) . '</p></div>';
				continue;
			}
			echo '<table class="widefat striped mgseo-gsc-table"><thead><tr><th>' . esc_html__( 'Keyword', 'mg-seo' ) . '</th><th class="num">' . esc_html__( 'Position', 'mg-seo' ) . '</th><th class="num">' . esc_html__( 'Change', 'mg-seo' ) . '</th><th class="num">' . esc_html__( 'Clicks', 'mg-seo' ) . '</th></tr></thead><tbody>';
			foreach ( $movers[ $key ] as $row ) {
				printf(
					'<tr><td>%s</td><td class="num">%s <span class="mgseo-gsc-prev">(%s)</span></td><td class="num">%s</td><td class="num">%s</td></tr>',
					esc_html( $row['name'] ),
					esc_html( self::pos( $row['position'] ) ),
					esc_html( self::pos( $row['prev_position'] ) ),
					wp_kses_post( self::delta( $row['change'] ) ),
					esc_html( number_format_i18n( $row['clicks'] ) )
				);
			}
			echo '</tbody></table></div>';
		}
		echo '</div>';
	}

	/**
	 * Rank tracker.
	 *
	 * @param array $period Period.
	 */
	private static function render_tracker( array $period ): void {
		echo '<p class="description">' . esc_html__( 'Positions come from your own Search Console data (impression-weighted daily average). Nothing is scraped and no third-party service is used; keywords without impressions show no position.', 'mg-seo' ) . '</p>';
		echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" class="mgseo-gsc-track-form">';
		wp_nonce_field( 'mgseo_gsc_track' );
		echo '<input type="hidden" name="action" value="mgseo_gsc_track" /><input type="hidden" name="op" value="add" />';
		echo '<label for="mgseo-gsc-keywords"><strong>' . esc_html__( 'Add keywords (one per line)', 'mg-seo' ) . '</strong></label><br />';
		echo '<textarea id="mgseo-gsc-keywords" name="keywords" rows="3" class="large-text"></textarea>';
		submit_button( __( 'Track keywords', 'mg-seo' ), 'secondary', 'submit', false );
		echo '</form>';

		$rows = Reports::tracker( Reports::tracked_keywords(), $period );
		if ( ! $rows ) {
			echo '<p class="mgseo-gsc-none">' . esc_html__( 'No tracked keywords yet.', 'mg-seo' ) . '</p>';
			return;
		}
		echo '<table class="widefat striped mgseo-gsc-table"><thead><tr><th>' . esc_html__( 'Keyword', 'mg-seo' ) . '</th><th class="num">' . esc_html__( 'Position', 'mg-seo' ) . '</th><th class="num">' . esc_html__( 'Change', 'mg-seo' ) . '</th><th>' . esc_html__( 'Trend', 'mg-seo' ) . '</th><th class="num">' . esc_html__( 'Clicks', 'mg-seo' ) . '</th><th class="num">' . esc_html__( 'Impressions', 'mg-seo' ) . '</th><th>' . esc_html__( 'Best page', 'mg-seo' ) . '</th><th></th></tr></thead><tbody>';
		foreach ( $rows as $row ) {
			echo '<tr><td>' . esc_html( $row['keyword'] ) . '</td>';
			echo '<td class="num">' . esc_html( self::pos( $row['position'] ) ) . '</td>';
			echo '<td class="num">' . wp_kses_post( self::delta( $row['change'] ) ) . '</td>';
			echo '<td class="mgseo-gsc-spark">';
			$svg = Chart::line(
				$row['series'],
				array(
					/* translators: %s: keyword */
					'label'     => sprintf( __( 'Daily position for %s', 'mg-seo' ), $row['keyword'] ),
					'invert'    => true,
					'sparkline' => true,
					'width'     => 120,
					'height'    => 28,
					'class'     => 'is-position',
				)
			);
			echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built with escaped values.
			echo '</td><td class="num">' . esc_html( number_format_i18n( $row['clicks'] ) ) . '</td>';
			echo '<td class="num">' . esc_html( number_format_i18n( $row['impressions'] ) ) . '</td>';
			echo '<td>' . ( '' !== $row['page'] ? wp_kses_post( self::page_label( $row['page'] ) ) : '—' ) . '</td>';
			printf( '<td><a class="button-link-delete" href="%s">%s</a></td></tr>', esc_url( self::track_url( 'remove', $row['keyword'] ) ), esc_html__( 'Remove', 'mg-seo' ) );
		}
		echo '</tbody></table>';
	}

	/**
	 * Nonce URL to add/remove a tracked keyword.
	 *
	 * @param string $op      add|remove.
	 * @param string $keyword Keyword.
	 */
	private static function track_url( string $op, string $keyword ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'mgseo_gsc_track',
					'op'       => $op,
					'keywords' => rawurlencode( $keyword ),
				),
				admin_url( 'admin-post.php' )
			),
			'mgseo_gsc_track'
		);
	}

	/**
	 * Add/remove tracked keywords.
	 */
	public static function handle_track(): void {
		if ( ! current_user_can( 'mgseo_view_reports' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'mg-seo' ), 403 );
		}
		check_admin_referer( 'mgseo_gsc_track' );
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- checked above (works for GET and POST).
		$op  = isset( $_REQUEST['op'] ) ? sanitize_key( wp_unslash( $_REQUEST['op'] ) ) : 'add';
		$raw = isset( $_REQUEST['keywords'] ) ? sanitize_textarea_field( wp_unslash( $_REQUEST['keywords'] ) ) : '';
		// phpcs:enable
		$list    = preg_split( '/[\r\n]+/', $raw );
		$list    = is_array( $list ) ? $list : array();
		$list    = array_map( static fn( $k ) => trim( (string) preg_replace( '/\s+/u', ' ', mb_strtolower( $k ) ) ), $list );
		$list    = array_filter( $list );
		$current = Reports::tracked_keywords();
		if ( 'remove' === $op ) {
			$current = array_diff( $current, $list );
		} else {
			$current = array_merge( $current, $list );
		}
		Reports::set_tracked_keywords( $current );
		wp_safe_redirect(
			add_query_arg(
				array(
					'page' => Module::REPORTS_SLUG,
					'tab'  => 'tracker',
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	/**
	 * Editor panel: performance of this post, top queries, index status.
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function render_post_box( \WP_Post $post ): void {
		echo '<div class="mgseo-gsc-box" data-post-id="' . esc_attr( (string) $post->ID ) . '">';
		if ( ! OAuth::connected() ) {
			echo '<p>' . esc_html__( 'Search Console is not connected.', 'mg-seo' );
			if ( current_user_can( 'mgseo_manage_settings' ) ) {
				echo ' <a href="' . esc_url( admin_url( 'admin.php?page=mg-seo-gsc' ) ) . '">' . esc_html__( 'Set it up', 'mg-seo' ) . '</a>';
			}
			echo '</p></div>';
			return;
		}
		if ( 'publish' !== $post->post_status ) {
			echo '<p>' . esc_html__( 'Search performance is available after the post is published.', 'mg-seo' ) . '</p></div>';
			return;
		}
		$url    = (string) get_permalink( $post );
		$period = Reports::period( 28 );
		$cur    = Reports::page_totals( $url, $period['start'], $period['end'] );
		$prev   = Reports::page_totals( $url, $period['prev_start'], $period['prev_end'] );
		/* translators: 1: start date, 2: end date */
		echo '<p class="description">' . esc_html( sprintf( __( 'Last 28 days (%1$s – %2$s) compared with the previous 28 days.', 'mg-seo' ), $period['start'], $period['end'] ) ) . '</p>';
		echo '<div class="mgseo-gsc-cards is-compact">';
		$cards = array(
			array( __( 'Clicks', 'mg-seo' ), number_format_i18n( $cur['clicks'] ), self::delta( self::rel( $cur['clicks'], $prev['clicks'] ), '%' ) ),
			array( __( 'Impressions', 'mg-seo' ), number_format_i18n( $cur['impressions'] ), self::delta( self::rel( $cur['impressions'], $prev['impressions'] ), '%' ) ),
			array( __( 'CTR', 'mg-seo' ), self::pct( $cur['ctr'] ), '' ),
			array( __( 'Position', 'mg-seo' ), self::pos( $cur['position'] ), $prev['position'] > 0 && $cur['position'] > 0 ? self::delta( $prev['position'] - $cur['position'] ) : '' ),
		);
		foreach ( $cards as $card ) {
			echo '<div class="mgseo-gsc-card"><div class="mgseo-gsc-card-label">' . esc_html( $card[0] ) . '</div><div class="mgseo-gsc-card-value">' . esc_html( $card[1] ) . ' ' . wp_kses_post( $card[2] ) . '</div></div>';
		}
		echo '</div>';
		$daily  = Reports::page_daily( $url, $period['start'], $period['end'] );
		$series = array_map( static fn( $r ) => $r['clicks'], $daily );
		$svg    = Chart::line(
			$series,
			array(
				'label'  => __( 'Clicks per day', 'mg-seo' ),
				'class'  => 'is-clicks',
				'height' => 120,
			)
		);
		echo $svg; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- SVG built with escaped values.

		$queries = Reports::top( 'query', $period['start'], $period['end'], 10, 'clicks', $url );
		echo '<h4>' . esc_html__( 'Top keywords for this page', 'mg-seo' ) . '</h4>';
		if ( $queries ) {
			echo '<table class="widefat striped mgseo-gsc-table"><thead><tr><th>' . esc_html__( 'Keyword', 'mg-seo' ) . '</th><th class="num">' . esc_html__( 'Clicks', 'mg-seo' ) . '</th><th class="num">' . esc_html__( 'Impressions', 'mg-seo' ) . '</th><th class="num">' . esc_html__( 'Position', 'mg-seo' ) . '</th></tr></thead><tbody>';
			foreach ( $queries as $row ) {
				printf( '<tr><td>%s</td><td class="num">%s</td><td class="num">%s</td><td class="num">%s</td></tr>', esc_html( $row['name'] ), esc_html( number_format_i18n( $row['clicks'] ) ), esc_html( number_format_i18n( $row['impressions'] ) ), esc_html( self::pos( $row['position'] ) ) );
			}
			echo '</tbody></table>';
		} else {
			echo '<p class="mgseo-gsc-none">' . esc_html__( 'No search data for this URL yet.', 'mg-seo' ) . '</p>';
		}

		echo '<h4>' . esc_html__( 'Index status', 'mg-seo' ) . '</h4>';
		echo '<div class="mgseo-gsc-inspection">';
		$cached = Inspection::cached( $post->ID );
		echo $cached ? wp_kses_post( self::inspection_html( $cached ) ) : '<p class="mgseo-gsc-none">' . esc_html__( 'Not checked yet.', 'mg-seo' ) . '</p>';
		echo '</div>';
		echo '<p><button type="button" class="button mgseo-gsc-inspect">' . esc_html__( 'Check index status', 'mg-seo' ) . '</button> <span class="description">' . esc_html__( 'Uses the URL Inspection API (daily quota applies).', 'mg-seo' ) . '</span></p>';

		if ( Inspection::indexing_available() && current_user_can( 'mgseo_edit_advanced' ) ) {
			$last = get_post_meta( $post->ID, Inspection::INDEXING_META, true );
			echo '<p><button type="button" class="button mgseo-gsc-submit">' . esc_html__( 'Submit to Google Indexing API', 'mg-seo' ) . '</button> <span class="description">' . esc_html__( 'Officially supported only for JobPosting and BroadcastEvent pages.', 'mg-seo' ) . '</span></p>';
			if ( is_array( $last ) && ! empty( $last['submitted_at'] ) ) {
				/* translators: %s: date and time */
				echo '<p class="description">' . esc_html( sprintf( __( 'Last submitted: %s', 'mg-seo' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $last['submitted_at'] ) ) ) . '</p>';
			}
		}
		echo '<p class="mgseo-gsc-message" aria-live="polite"></p>';
		echo '</div>';
	}

	/**
	 * Human labels for API enum values.
	 *
	 * @param string $value Enum.
	 */
	private static function label( string $value ): string {
		$labels = array(
			'PASS'                   => __( 'Indexed (URL is on Google)', 'mg-seo' ),
			'PARTIAL'                => __( 'Indexed with issues', 'mg-seo' ),
			'FAIL'                   => __( 'Not indexed (error)', 'mg-seo' ),
			'NEUTRAL'                => __( 'Not indexed', 'mg-seo' ),
			'VERDICT_UNSPECIFIED'    => __( 'Unknown', 'mg-seo' ),
			'ALLOWED'                => __( 'Allowed', 'mg-seo' ),
			'DISALLOWED'             => __( 'Blocked by robots.txt', 'mg-seo' ),
			'INDEXING_ALLOWED'       => __( 'Indexing allowed', 'mg-seo' ),
			'BLOCKED_BY_META_TAG'    => __( 'Blocked by robots meta tag', 'mg-seo' ),
			'BLOCKED_BY_HTTP_HEADER' => __( 'Blocked by X-Robots-Tag header', 'mg-seo' ),
			'BLOCKED_BY_ROBOTS_TXT'  => __( 'Blocked by robots.txt', 'mg-seo' ),
			'SUCCESSFUL'             => __( 'Successful', 'mg-seo' ),
			'MOBILE'                 => __( 'Googlebot smartphone', 'mg-seo' ),
			'DESKTOP'                => __( 'Googlebot desktop', 'mg-seo' ),
		);
		return $labels[ $value ] ?? ucfirst( strtolower( str_replace( '_', ' ', $value ) ) );
	}

	/**
	 * HTML for an inspection result.
	 *
	 * @param array $result Parsed result.
	 */
	public static function inspection_html( array $result ): string {
		$class = ! empty( $result['indexed'] ) ? 'is-ok' : ( 'FAIL' === ( $result['verdict'] ?? '' ) ? 'is-bad' : 'is-warn' );
		$rows  = array(
			__( 'Coverage', 'mg-seo' )         => (string) ( $result['coverage'] ?? '' ),
			__( 'Indexing', 'mg-seo' )         => self::label( (string) ( $result['indexing_state'] ?? '' ) ),
			__( 'robots.txt', 'mg-seo' )       => self::label( (string) ( $result['robots_txt'] ?? '' ) ),
			__( 'Page fetch', 'mg-seo' )       => self::label( (string) ( $result['page_fetch'] ?? '' ) ),
			__( 'Crawled as', 'mg-seo' )       => self::label( (string) ( $result['crawled_as'] ?? '' ) ),
			__( 'Last crawl', 'mg-seo' )       => ! empty( $result['last_crawl'] ) && strtotime( (string) $result['last_crawl'] ) ? wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) strtotime( (string) $result['last_crawl'] ) ) : '',
			__( 'Google canonical', 'mg-seo' ) => (string) ( $result['google_canonical'] ?? '' ),
			__( 'User canonical', 'mg-seo' )   => (string) ( $result['user_canonical'] ?? '' ),
			__( 'Rich results', 'mg-seo' )     => implode( ', ', (array) ( $result['rich_types'] ?? array() ) ),
		);
		$html  = '<p><span class="mgseo-gsc-status ' . esc_attr( $class ) . '">' . esc_html( self::label( (string) ( $result['verdict'] ?? 'VERDICT_UNSPECIFIED' ) ) ) . '</span>';
		if ( ! empty( $result['checked_at'] ) ) {
			/* translators: %s: date and time */
			$html .= ' <span class="description">' . esc_html( sprintf( __( 'checked %s', 'mg-seo' ), wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), (int) $result['checked_at'] ) ) ) . '</span>';
		}
		$html .= '</p><table class="mgseo-gsc-kv"><tbody>';
		foreach ( $rows as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$html .= '<tr><th>' . esc_html( $label ) . '</th><td>' . esc_html( $value ) . '</td></tr>';
		}
		$html .= '</tbody></table>';
		if ( ! empty( $result['link'] ) ) {
			$html .= '<p><a href="' . esc_url( (string) $result['link'] ) . '" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Open in Search Console', 'mg-seo' ) . '</a></p>';
		}
		return $html;
	}
}
