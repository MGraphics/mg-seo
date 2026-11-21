<?php
/**
 * Search Console: analytics sync, report aggregation and URL inspection.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Modules\SearchConsole\Inspection;
use MGSEO\Modules\SearchConsole\Module;
use MGSEO\Modules\SearchConsole\OAuth;
use MGSEO\Modules\SearchConsole\Reports;
use MGSEO\Modules\SearchConsole\ReportsPage;
use MGSEO\Modules\SearchConsole\Sync;

/**
 * @covers \MGSEO\Modules\SearchConsole\Sync
 * @covers \MGSEO\Modules\SearchConsole\Reports
 * @covers \MGSEO\Modules\SearchConsole\Inspection
 */
class SearchConsoleSyncTest extends TestCase {

	/**
	 * Recorded requests.
	 *
	 * @var array<int, array{url: string, args: array, body: array}>
	 */
	private array $requests = array();

	/**
	 * Responder.
	 *
	 * @var callable|null
	 */
	private $responder = null;

	public function set_up(): void {
		parent::set_up();
		$this->requests  = array();
		$this->responder = null;
		update_option( 'mgseo_modules', array( 'gsc' => true ) );
		Sync::create_table();
		Sync::truncate();
		$this->set_option( 'gsc', 'property', 'sc-domain:example.org' );
		OAuth::save_tokens(
			array(
				'access_token'  => 'ya29.valid',
				'refresh_token' => '1//refresh',
				'expires_at'    => time() + 3600,
				'scope'         => OAuth::SCOPE_GSC,
			)
		);
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$body             = isset( $args['body'] ) && is_string( $args['body'] ) ? (array) json_decode( $args['body'], true ) : array();
				$this->requests[] = array(
					'url'  => $url,
					'args' => $args,
					'body' => $body,
				);
				return $this->responder ? call_user_func( $this->responder, $url, $body ) : new \WP_Error( 'blocked', 'blocked' );
			},
			10,
			3
		);
	}

	public function tear_down(): void {
		delete_option( 'mgseo_modules' );
		OAuth::forget();
		parent::tear_down();
	}

	private static function json( array $data, int $code = 200 ): array {
		return array(
			'headers'  => array(),
			'body'     => (string) wp_json_encode( $data ),
			'response' => array(
				'code'    => $code,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	}

	private function row( string $date, string $page, string $query, int $clicks, int $impressions, float $position ): array {
		return array(
			'date'        => $date,
			'page'        => $page,
			'query'       => $query,
			'clicks'      => $clicks,
			'impressions' => $impressions,
			'ctr'         => $impressions ? $clicks / $impressions : 0,
			'position'    => $position,
		);
	}

	private function count_rows( string $where = '1=1' ): int {
		global $wpdb;
		return (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Sync::table() . ' WHERE ' . $where ); // phpcs:ignore
	}

	public function test_sync_paginates_and_stores_totals_and_detail_rows(): void {
		$this->set_option( 'gsc', 'sync_days', 7 );
		add_filter( 'mgseo/gsc/row_limit', static fn() => 2 );
		$this->responder = static function ( $url, $body ) {
			$day = $body['startDate'];
			if ( array( 'date' ) === $body['dimensions'] ) {
				return self::json(
					array(
						'rows' => array(
							array( 'keys' => array( $day ), 'clicks' => 10, 'impressions' => 100, 'ctr' => 0.1, 'position' => 7.5 ),
						),
					)
				);
			}
			$all  = array(
				array( 'keys' => array( $day, 'https://example.org/a/', 'Kawa Ziarnista' ), 'clicks' => 5, 'impressions' => 40, 'ctr' => 0.125, 'position' => 3.2 ),
				array( 'keys' => array( $day, 'https://example.org/b/', 'ekspres' ), 'clicks' => 2, 'impressions' => 30, 'ctr' => 0.0667, 'position' => 8.1 ),
				array( 'keys' => array( $day, 'https://example.org/a/', 'kawa' ), 'clicks' => 1, 'impressions' => 20, 'ctr' => 0.05, 'position' => 11.0 ),
			);
			return self::json( array( 'rows' => array_slice( $all, (int) $body['startRow'], (int) $body['rowLimit'] ) ) );
		};

		$stats = Sync::run();
		$this->assertIsArray( $stats );
		// Two ranges (recent 5 days + 2 backfilled days), each: 1 totals + 2 detail pages.
		$this->assertSame( 6, $stats['requests'] );
		$this->assertCount( 2, $stats['ranges'] );
		$this->assertSame( 8, $stats['rows'] );

		$first = $this->requests[0];
		$this->assertSame( 'https://www.googleapis.com/webmasters/v3/sites/sc-domain%3Aexample.org/searchAnalytics/query', $first['url'] );
		$this->assertSame( 'POST', $first['args']['method'] );
		$this->assertSame( 'Bearer ya29.valid', $first['args']['headers']['Authorization'] );
		$this->assertSame( array( 'date', 'page', 'query' ), $this->requests[1]['body']['dimensions'] );
		$this->assertSame( 0, $this->requests[1]['body']['startRow'] );
		$this->assertSame( 2, $this->requests[2]['body']['startRow'] );
		$this->assertSame( 'web', $this->requests[1]['body']['type'] );

		$this->assertSame( 2, $this->count_rows( "page = '' AND query = ''" ) );
		$this->assertSame( 6, $this->count_rows( "query <> ''" ) );
		$this->assertSame( 2, $this->count_rows( "query = 'kawa ziarnista'" ), 'Queries are stored lowercased.' );

		$state = get_option( Sync::STATE );
		$this->assertSame( Sync::end_date(), $state['to'] );
		$this->assertSame( Sync::shift( Sync::end_date(), -6 ), $state['from'] );

		// A second run only refreshes recent days and replaces (not duplicates) rows.
		$this->requests = array();
		$stats          = Sync::run();
		$this->assertSame( 3, $stats['requests'] );
		$this->assertSame( 2, $this->count_rows( "page = '' AND query = ''" ) );
	}

	public function test_sync_reports_api_errors_and_respects_budget(): void {
		$this->responder = static fn() => self::json( array( 'error' => array( 'code' => 403, 'message' => 'User does not have sufficient permission', 'status' => 'PERMISSION_DENIED' ) ), 403 );
		$result          = Sync::run();
		$this->assertWPError( $result );
		$this->assertSame( 'mgseo_gsc_permission_denied', $result->get_error_code() );
		$this->assertCount( 1, $this->requests );

		( new Module() )->cron();
		$this->assertStringContainsString( 'sufficient permission', get_option( Module::ERROR_OPTION )['message'] );

		$this->requests  = array();
		$this->responder = static fn() => self::json( array( 'rows' => array() ) );
		$this->set_option( 'gsc', 'sync_days', 486 );
		$stats = Sync::run( 6 );
		$this->assertLessThanOrEqual( 6, $stats['requests'] );
		$this->assertGreaterThan( Sync::shift( Sync::end_date(), -485 ), get_option( Sync::STATE )['from'], 'Backfill continues on later runs.' );
		$this->assertFalse( get_option( Module::ERROR_OPTION ), 'Successful run clears the error.' );
	}

	public function test_retention_purges_old_rows(): void {
		$this->set_option( 'gsc', 'retention_days', 30 );
		$old = gmdate( 'Y-m-d', time() - 40 * DAY_IN_SECONDS );
		$new = gmdate( 'Y-m-d', time() - 5 * DAY_IN_SECONDS );
		Sync::replace( $old, $old, array( $this->row( $old, '', '', 1, 1, 1 ) ) );
		Sync::replace( $new, $new, array( $this->row( $new, '', '', 1, 1, 1 ) ) );
		Sync::purge();
		$this->assertSame( 1, $this->count_rows() );
	}

	public function test_report_aggregation_top_lists_and_movers(): void {
		$end  = '2026-11-14';
		$cur  = '2026-11-10';
		$prev = '2026-11-03';
		$rows = array(
			// Current period (7 days ending 2026-11-14).
			$this->row( $cur, '', '', 30, 300, 6.0 ),
			$this->row( $end, '', '', 10, 200, 9.0 ),
			$this->row( $cur, 'https://example.org/a/', 'kawa', 20, 100, 2.0 ),
			$this->row( $cur, 'https://example.org/a/', 'ekspres', 1, 50, 9.0 ),
			$this->row( $end, 'https://example.org/b/', 'kawa', 5, 100, 4.0 ),
			$this->row( $end, 'https://example.org/b/', 'mlynek', 4, 40, 15.0 ),
			// Previous period.
			$this->row( $prev, 'https://example.org/a/', 'kawa', 5, 100, 8.0 ),
			$this->row( $prev, 'https://example.org/a/', 'ekspres', 3, 50, 4.0 ),
			$this->row( $prev, 'https://example.org/b/', 'mlynek', 1, 40, 15.0 ),
		);
		Sync::replace( $prev, $end, $rows );

		$period = Reports::period( 7, $end );
		$this->assertSame( '2026-11-08', $period['start'] );
		$this->assertSame( '2026-11-01', $period['prev_start'] );
		$this->assertSame( '2026-11-07', $period['prev_end'] );
		$this->assertSame( $end, Reports::latest_date() );

		$totals = Reports::totals( $period['start'], $period['end'] );
		$this->assertSame( 40, $totals['clicks'] );
		$this->assertSame( 500, $totals['impressions'] );
		$this->assertEqualsWithDelta( 0.08, $totals['ctr'], 0.0001 );
		$this->assertEqualsWithDelta( ( 6 * 300 + 9 * 200 ) / 500, $totals['position'], 0.001, 'Impression-weighted position.' );

		$daily = Reports::daily( $period['start'], $period['end'] );
		$this->assertCount( 7, $daily );
		$this->assertSame( 0, $daily['2026-11-08']['clicks'], 'Missing days are zero-filled.' );
		$this->assertSame( 30, $daily['2026-11-10']['clicks'] );

		$pages = Reports::top_pages( $period['start'], $period['end'] );
		$this->assertSame( array( 'https://example.org/a/', 'https://example.org/b/' ), array_column( $pages, 'name' ) );
		$this->assertSame( 21, $pages[0]['clicks'] );
		$this->assertSame( 150, $pages[0]['impressions'] );

		$queries = Reports::top_queries( $period['start'], $period['end'] );
		$this->assertSame( 'kawa', $queries[0]['name'] );
		$this->assertSame( 25, $queries[0]['clicks'] );
		$this->assertEqualsWithDelta( 3.0, $queries[0]['position'], 0.001 );
		$by_position = Reports::top_queries( $period['start'], $period['end'], 10, 'position' );
		$this->assertSame( 'kawa', $by_position[0]['name'] );
		$this->assertSame( 'mlynek', end( $by_position )['name'] );

		$movers = Reports::movers( $period );
		$this->assertSame( array( 'kawa' ), array_column( $movers['winners'], 'name' ) );
		$this->assertEqualsWithDelta( 5.0, $movers['winners'][0]['change'], 0.001 );
		$this->assertSame( array( 'ekspres' ), array_column( $movers['losers'], 'name' ) );
		$this->assertEqualsWithDelta( -5.0, $movers['losers'][0]['change'], 0.001 );

		$page = Reports::page_totals( 'https://example.org/b/', $period['start'], $period['end'] );
		$this->assertSame( 9, $page['clicks'] );
		$page_queries = Reports::top( 'query', $period['start'], $period['end'], 10, 'clicks', 'https://example.org/b/' );
		$this->assertSame( array( 'kawa', 'mlynek' ), array_column( $page_queries, 'name' ) );

		Reports::set_tracked_keywords( array( '  Kawa ', 'kawa', 'brak danych' ) );
		$this->assertSame( array( 'kawa', 'brak danych' ), Reports::tracked_keywords() );
		$tracker = Reports::tracker( Reports::tracked_keywords(), $period );
		$this->assertEqualsWithDelta( 3.0, $tracker[0]['position'], 0.001 );
		$this->assertEqualsWithDelta( 8.0, $tracker[0]['prev_position'], 0.001 );
		$this->assertEqualsWithDelta( 5.0, $tracker[0]['change'], 0.001 );
		$this->assertSame( 'https://example.org/a/', $tracker[0]['page'] );
		$this->assertEqualsWithDelta( 2.0, $tracker[0]['series']['2026-11-10'], 0.001 );
		$this->assertNull( $tracker[0]['series']['2026-11-09'] );
		$this->assertNull( $tracker[1]['position'] );

		$this->assertSame( array(), $this->requests, 'Reports are computed locally.' );
	}

	public function test_url_inspection_is_parsed_and_cached(): void {
		$post            = self::factory()->post->create( array( 'post_title' => 'Inspect me' ) );
		$url             = get_permalink( $post );
		$this->responder = static fn() => self::json(
			array(
				'inspectionResult' => array(
					'inspectionResultLink' => 'https://search.google.com/search-console/inspect?resource_id=x',
					'indexStatusResult'    => array(
						'verdict'         => 'PASS',
						'coverageState'   => 'Submitted and indexed',
						'robotsTxtState'  => 'ALLOWED',
						'indexingState'   => 'INDEXING_ALLOWED',
						'lastCrawlTime'   => '2026-11-01T10:00:00Z',
						'pageFetchState'  => 'SUCCESSFUL',
						'googleCanonical' => $url,
						'userCanonical'   => $url,
						'crawledAs'       => 'MOBILE',
						'sitemap'         => array( 'https://example.org/sitemap_index.xml' ),
					),
					'mobileUsabilityResult' => array( 'verdict' => 'PASS' ),
					'richResultsResult'     => array(
						'verdict'       => 'PASS',
						'detectedItems' => array( array( 'richResultType' => 'Breadcrumbs' ), array( 'richResultType' => 'Breadcrumbs' ) ),
					),
				),
			)
		);
		$result = Inspection::inspect_post( $post );
		$this->assertIsArray( $result );
		$this->assertSame( Inspection::cached( $post ), $result );
		$this->assertTrue( $result['indexed'] );
		$this->assertSame( 'Submitted and indexed', $result['coverage'] );
		$this->assertSame( 'MOBILE', $result['crawled_as'] );
		$this->assertSame( array( 'Breadcrumbs' ), $result['rich_types'] );
		$this->assertSame( array( 'https://example.org/sitemap_index.xml' ), $result['sitemaps'] );
		$this->assertSame( \MGSEO\Modules\SearchConsole\Client::INSPECT_URL, $this->requests[0]['url'] );
		$this->assertSame( $url, $this->requests[0]['body']['inspectionUrl'] );
		$this->assertSame( 'sc-domain:example.org', $this->requests[0]['body']['siteUrl'] );

		$html = ReportsPage::inspection_html( $result );
		$this->assertStringContainsString( 'Indexed (URL is on Google)', $html );
		$this->assertStringContainsString( 'Submitted and indexed', $html );

		$this->set_option( 'gsc', 'property', 'https://another-site.com/' );
		$this->requests = array();
		$this->assertWPError( Inspection::inspect( $url ) );
		$this->assertSame( array(), $this->requests, 'URLs outside the property are rejected locally.' );
	}

	public function test_rest_inspect_and_indexing_permissions(): void {
		$module = new Module();
		$module->boot();
		$GLOBALS['wp_rest_server'] = null;
		$server                    = rest_get_server();
		$post                      = self::factory()->post->create();

		$request = new \WP_REST_Request( 'POST', '/mg-seo/v1/gsc/inspect' );
		$request->set_body_params( array( 'post_id' => $post ) );
		$this->assertSame( 401, $server->dispatch( $request )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( 403, $server->dispatch( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$this->responder = static fn() => self::json( array( 'inspectionResult' => array( 'indexStatusResult' => array( 'verdict' => 'NEUTRAL', 'coverageState' => 'Discovered - currently not indexed' ) ) ) );
		$response        = $server->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertFalse( $response->get_data()['result']['indexed'] );
		$this->assertStringContainsString( 'Discovered', $response->get_data()['html'] );

		// Indexing API is off by default: no request is made.
		$this->requests = array();
		$index          = new \WP_REST_Request( 'POST', '/mg-seo/v1/gsc/indexing' );
		$index->set_body_params( array( 'post_id' => $post ) );
		$this->assertSame( 400, $server->dispatch( $index )->get_status() );
		$this->assertSame( array(), $this->requests );

		$this->set_option( 'gsc', 'indexing_api', true );
		OAuth::save_tokens( array( 'access_token' => 'a', 'refresh_token' => 'r', 'expires_at' => time() + 3600, 'scope' => OAuth::SCOPE_GSC . ' ' . OAuth::SCOPE_INDEX ) );
		$this->responder = static fn() => self::json( array( 'urlNotificationMetadata' => array( 'url' => get_permalink( $post ), 'latestUpdate' => array( 'notifyTime' => '2026-11-20T10:00:00Z' ) ) ) );
		$response        = $server->dispatch( $index );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'https://indexing.googleapis.com/v3/urlNotifications:publish', $this->requests[0]['url'] );
		$this->assertSame( 'URL_UPDATED', $this->requests[0]['body']['type'] );
	}
}
