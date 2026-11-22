<?php
/**
 * Stored SEO scores: imports, lazy computing, recalculation, cron passes, list column, overview, badges.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Meta;
use MGSEO\Modules\Analysis\Cli;
use MGSEO\Modules\Analysis\Module as Analysis;
use MGSEO\Modules\Analysis\Overview;
use MGSEO\Modules\Analysis\Scores;
use MGSEO\Modules\Bulk\Csv;
use MGSEO\Modules\Import\Log;
use MGSEO\Modules\Import\Runner;
use MGSEO\Plugin;

/**
 * @covers \MGSEO\Modules\Analysis\Scores
 * @covers \MGSEO\Modules\Analysis\Overview
 */
class ScoresTest extends TestCase {

	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		delete_option( Scores::PASS_OPTION );
		delete_option( Scores::LAST_OPTION );
		delete_option( Log::OPTION );
		Scores::reset_budget();
		wp_clear_scheduled_hook( Scores::BATCH_EVENT );
		wp_clear_scheduled_hook( Scores::DAILY_EVENT );
	}

	private function module(): Analysis {
		$module = Plugin::instance()->module( 'analysis' );
		$this->assertInstanceOf( Analysis::class, $module );
		return $module;
	}

	/**
	 * Post whose keyword was written without save_post (like an import or a direct DB write).
	 */
	private function unscored_post( string $keyword, array $args = array() ): int {
		$id = self::factory()->post->create(
			$args + array(
				'post_title'   => 'Strony internetowe dla firm',
				'post_content' => '<p>Strony internetowe dla firm. ' . str_repeat( 'Tekst o stronach. ', 40 ) . '</p>',
			)
		);
		if ( '' !== $keyword ) {
			update_post_meta( $id, Meta::PREFIX . 'focus_keyword', $keyword );
		}
		delete_post_meta( $id, Meta::PREFIX . 'score' );
		delete_post_meta( $id, Meta::PREFIX . Scores::DATA_KEY );
		return $id;
	}

	private function column( int $post_id ): string {
		ob_start();
		$this->module()->column( 'mgseo', $post_id );
		return (string) ob_get_clean();
	}

	public function test_score_after_rank_math_import(): void {
		$with    = self::factory()->post->create( array( 'post_title' => 'Mały ogród', 'post_content' => '<p>Mały ogród w mieście.</p>' ) );
		$without = self::factory()->post->create( array( 'post_title' => 'Bez frazy' ) );
		update_post_meta( $with, 'rank_math_focus_keyword', 'mały ogród' );
		update_post_meta( $without, 'rank_math_title', '%title%' );
		delete_post_meta( $with, Meta::PREFIX . Scores::DATA_KEY );
		delete_post_meta( $without, Meta::PREFIX . Scores::DATA_KEY );

		Runner::run( 'rankmath', array( 'posts' ) );

		$expected = Analysis::analyze_post( get_post( $with ) )['score'];
		$this->assertSame( $expected, (int) Meta::post( $with, 'score' ) );
		$this->assertTrue( Scores::data( $with )['keyword'] );
		$this->assertFalse( metadata_exists( 'post', $without, Meta::PREFIX . 'score' ) );
		$this->assertSame( Scores::BAND_NONE, Scores::data( $without )['band'] );
		$this->assertSame( Scores::NO_KEYWORD, Scores::data( $without )['issues'][0]['id'] );
	}

	public function test_imported_plugin_score_is_replaced_by_own_score(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'Ogród', 'post_content' => '<p>ogród</p>' ) );
		update_post_meta( $post, '_yoast_wpseo_focuskw', 'ogród' );
		update_post_meta( $post, '_yoast_wpseo_linkdex', '97' );
		Runner::run( 'yoast', array( 'posts' ) );
		$this->assertSame( Analysis::analyze_post( get_post( $post ) )['score'], (int) Meta::post( $post, 'score' ) );
		$this->assertNotSame( 97, (int) Meta::post( $post, 'score' ) );
	}

	public function test_dry_run_import_computes_nothing(): void {
		$post = $this->unscored_post( '' );
		update_post_meta( $post, 'rank_math_focus_keyword', 'ogród' );
		Runner::run( 'rankmath', array( 'posts' ), array( 'dry_run' => true ) );
		$this->assertNull( Scores::data( $post ) );
	}

	public function test_import_via_rest_starts_background_pass(): void {
		wp_set_current_user( $this->admin );
		Runner::batch( 'rankmath', 'posts', 0, array( 'start' => true ) );
		Runner::finish();
		$this->assertNotNull( Scores::pass() );
		$this->assertNotFalse( wp_next_scheduled( Scores::BATCH_EVENT ) );
	}

	public function test_csv_import_scores_changed_posts(): void {
		wp_set_current_user( $this->admin );
		$post = $this->unscored_post( '' );
		$plan = Csv::plan( Csv::parse( "id,focus_keyword\n{$post},strony internetowe\n" ) );
		$this->assertSame( 1, Csv::apply( $plan['changes'] ) );
		$this->assertTrue( metadata_exists( 'post', $post, Meta::PREFIX . 'score' ) );
		$this->assertTrue( Scores::data( $post )['keyword'] );
	}

	public function test_bulk_editor_save_scores_post(): void {
		wp_set_current_user( $this->admin );
		$post = $this->unscored_post( '' );
		\MGSEO\Modules\Bulk\Module::save_item( $post, array( 'focus_keyword' => 'strony internetowe' ) );
		$this->assertTrue( metadata_exists( 'post', $post, Meta::PREFIX . 'score' ) );
	}

	public function test_lazy_compute_in_list_column_and_calculate_button(): void {
		wp_set_current_user( $this->admin );
		$first  = $this->unscored_post( 'strony internetowe' );
		$second = $this->unscored_post( 'sklepy internetowe' );
		Scores::reset_budget( 1 );

		$html = $this->column( $first );
		$this->assertMatchesRegularExpression( '#mgseo-rating-num">\d+</b>#', $html );
		$this->assertMatchesRegularExpression( '#SEO score: \d+ out of 100#', $html );
		$this->assertTrue( metadata_exists( 'post', $first, Meta::PREFIX . 'score' ) );

		// Budget used up: the cell offers to calculate the score in place.
		$html = $this->column( $second );
		$this->assertStringContainsString( 'mgseo-score-now', $html );
		$this->assertStringContainsString( 'data-post-id="' . $second . '"', $html );

		$request  = new \WP_REST_Request( 'POST', '/mg-seo/v1/scores/post/' . $second );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertMatchesRegularExpression( '#mgseo-rating-num">\d+</b>#', $response->get_data()['html'] );
		$this->assertStringNotContainsString( 'mgseo-score-now', $response->get_data()['html'] );
		$this->assertTrue( metadata_exists( 'post', $second, Meta::PREFIX . 'score' ) );
	}

	public function test_calculate_score_endpoint_requires_permission(): void {
		$post = $this->unscored_post( 'strony' );
		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_get_server()->dispatch( new \WP_REST_Request( 'POST', '/mg-seo/v1/scores/post/' . $post ) )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_get_server()->dispatch( new \WP_REST_Request( 'POST', '/mg-seo/v1/scores/recalc' ) )->get_status() );
		$this->assertNull( Scores::data( $post ) );
	}

	public function test_calculate_endpoint_shows_keyword_hint_without_keyword(): void {
		wp_set_current_user( $this->admin );
		$post     = $this->unscored_post( '' );
		$response = rest_get_server()->dispatch( new \WP_REST_Request( 'POST', '/mg-seo/v1/scores/post/' . $post ) );
		$this->assertFalse( $response->get_data()['keyword'] );
		$this->assertStringContainsString( 'mgseo-set-keyword', $response->get_data()['html'] );
	}

	public function test_no_keyword_hint_links_to_field_with_suggestion(): void {
		wp_set_current_user( $this->admin );
		$post = $this->unscored_post( '', array( 'post_title' => 'Strony WWW – Firma Kowalski' ) );
		$html = $this->column( $post );
		$this->assertStringContainsString( 'enter a keyword in the editor', $html );
		$this->assertStringContainsString( 'mgseo-mark is-none', $html );
		$this->assertStringContainsString( 'mgseo_focus=keyword', $html );
		$this->assertStringContainsString( 'strony www', $html );
		$this->assertStringNotContainsString( 'N/A', $html );
	}

	public function test_keyword_suggestion_prefers_h1(): void {
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Oferta | Firma', 'post_content' => '<h1>Projektowanie <em>logo</em> dla firm</h1><p>x</p>' ) );
		$this->assertSame( 'projektowanie logo dla firm', Scores::suggest_keyword( $post ) );
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Pozycjonowanie stron: poradnik 2026' ) );
		$this->assertSame( 'pozycjonowanie stron', Scores::suggest_keyword( $post ) );
	}

	public function test_recalc_rest_batches_and_filters(): void {
		wp_set_current_user( $this->admin );
		$posts = array( $this->unscored_post( 'a' ), $this->unscored_post( 'b' ), $this->unscored_post( 'c' ) );
		$page  = $this->unscored_post( 'd', array( 'post_type' => 'page' ) );

		$request = new \WP_REST_Request( 'POST', '/mg-seo/v1/scores/recalc' );
		$request->set_body_params( array( 'limit' => 2, 'post_type' => 'post' ) );
		$first = rest_get_server()->dispatch( $request )->get_data();
		$this->assertSame( 2, $first['processed'] );
		$this->assertFalse( $first['done'] );
		$this->assertGreaterThanOrEqual( 3, $first['total'] );

		$request->set_body_params( array( 'limit' => 100, 'post_type' => 'post', 'after' => $first['last'] ) );
		$this->assertTrue( rest_get_server()->dispatch( $request )->get_data()['done'] );
		foreach ( $posts as $id ) {
			$this->assertNotNull( Scores::data( $id ) );
		}
		$this->assertNull( Scores::data( $page ) );
	}

	public function test_cli_recalc_and_status(): void {
		require_once dirname( __DIR__ ) . '/stubs/wp-cli.php';
		\WP_CLI::$lines = array();
		$post           = $this->unscored_post( 'strony internetowe' );
		$other          = $this->unscored_post( '' );
		( new Cli() )( array( 'recalc' ), array( 'missing' => true ) );
		$this->assertTrue( metadata_exists( 'post', $post, Meta::PREFIX . 'score' ) );
		$this->assertNotNull( Scores::data( $other ) );
		$this->assertSame( 'success', end( \WP_CLI::$lines )[0] );

		update_post_meta( $post, Meta::PREFIX . 'focus_keyword', 'inna fraza' );
		( new Cli() )( array( 'recalc' ), array( 'ids' => (string) $post ) );
		$this->assertStringContainsString( 'Scored 1 of 1', end( \WP_CLI::$lines )[1] );

		\WP_CLI::$lines = array();
		( new Cli() )( array( 'status' ), array( 'format' => 'json' ) );
		$row = json_decode( \WP_CLI::$lines[0][1], true );
		$this->assertSame( 'all', $row['post_type'] );
		$this->assertGreaterThanOrEqual( 1, $row['none'] );
	}

	public function test_cron_pass_runs_in_small_batches(): void {
		$ids = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->unscored_post( 'fraza ' . $i );
		}
		Scores::start_pass();
		$this->assertNotFalse( wp_next_scheduled( Scores::BATCH_EVENT ) );

		$result = Scores::run_pass_batch( 2 );
		$this->assertSame( 2, $result['processed'] );
		$this->assertNotNull( Scores::pass() );
		$this->assertGreaterThan( 0, Scores::pass()['after'] );

		$guard = 0;
		while ( Scores::pass() && $guard++ < 50 ) {
			wp_clear_scheduled_hook( Scores::BATCH_EVENT );
			Scores::run_pass_batch( 2 );
		}
		$this->assertNull( Scores::pass() );
		$this->assertGreaterThan( 0, (int) get_option( Scores::LAST_OPTION ) );
		foreach ( $ids as $id ) {
			$this->assertTrue( metadata_exists( 'post', $id, Meta::PREFIX . 'score' ) );
		}
		$this->assertNull( Scores::run_pass_batch() );
	}

	public function test_daily_event_and_first_pass_are_scheduled(): void {
		delete_option( Scores::VER_OPTION );
		Scores::ensure_scheduled();
		$this->assertNotFalse( wp_next_scheduled( Scores::DAILY_EVENT ) );
		$this->assertNotNull( Scores::pass() );
		delete_option( Scores::PASS_OPTION );
		Scores::ensure_scheduled();
		$this->assertNull( Scores::pass(), 'A pass starts only once per score format version.' );
		$this->assertTrue( has_action( Scores::DAILY_EVENT ) > 0 );
		$this->assertTrue( has_action( Scores::BATCH_EVENT ) > 0 );
	}

	public function test_duplicate_keyword_updates_the_other_post_on_save(): void {
		$first = self::factory()->post->create( array( 'post_title' => 'Strony WWW', 'post_content' => '<p>strony www</p>' ) );
		update_post_meta( $first, Meta::PREFIX . 'focus_keyword', 'strony www' );
		wp_update_post( array( 'ID' => $first ) );
		$this->assertNotContains( 'keyword_unique', wp_list_pluck( Scores::data( $first )['issues'], 'id' ) );
		$before = (int) Meta::post( $first, 'score' );

		$second = self::factory()->post->create( array( 'post_title' => 'Też strony WWW' ) );
		update_post_meta( $second, Meta::PREFIX . 'focus_keyword', 'Strony WWW' );
		wp_update_post( array( 'ID' => $second ) );

		$this->assertLessThan( $before, (int) Meta::post( $first, 'score' ) );
	}

	public function test_overview_counts_sorting_and_filters(): void {
		wp_set_current_user( $this->admin );
		$good = self::factory()->post->create(
			array(
				'post_title'   => '10 porad: pozycjonowanie stron w 2026 roku',
				'post_name'    => 'pozycjonowanie-stron',
				'post_excerpt' => 'Pozycjonowanie stron krok po kroku: dowiedz się, jak zwiększyć widoczność witryny w Google dzięki sprawdzonym metodom technicznym i treściom.',
				'post_content' => '<p>Pozycjonowanie stron to proces. ' . str_repeat( 'Lorem ipsum dolor sit amet consectetur adipiscing elit sed do. ', 70 ) . '</p><h2>Pozycjonowanie stron krok po kroku</h2><p>' . str_repeat( 'Tekst akapitu o pozycjonowanie stron i nie tylko. ', 15 ) . '</p><img src="a.jpg" alt="pozycjonowanie stron przykład" /><a href="/kontakt/">kontakt</a> <a href="https://developers.google.com/">Google</a>',
			)
		);
		Meta::set_post( $good, 'title', '%title%' );
		Meta::set_post( $good, 'focus_keyword', 'pozycjonowanie stron' );
		wp_update_post( array( 'ID' => $good ) );
		$poor    = $this->unscored_post( 'zupełnie inna fraza' );
		$none    = $this->unscored_post( '' );
		$hidden  = $this->unscored_post( 'ukryta' );
		$draft   = $this->unscored_post( 'szkic', array( 'post_status' => 'draft' ) );
		$page    = $this->unscored_post( '', array( 'post_type' => 'page' ) );
		Meta::set_post( $hidden, 'robots', array( 'noindex' ) );

		$data = Overview::data();
		$ids  = wp_list_pluck( $data['rows'], 'id' );
		$this->assertNotContains( $hidden, $ids, 'noindex pages are not listed' );
		$this->assertNotContains( $draft, $ids, 'only published pages' );
		$this->assertContains( $page, $ids );
		$this->assertSame( $poor, $ids[0], 'worst score first' );
		$this->assertSame( 'good', $data['rows'][ array_search( $good, $ids, true ) ]['band'] );
		$this->assertGreaterThan( array_search( $good, $ids, true ), array_search( $none, $ids, true ), 'no keyword after scored pages' );
		$this->assertSame( 1, $data['counts']['good'] );
		$this->assertSame( 2, $data['counts']['none'] );
		$this->assertGreaterThanOrEqual( 1, $data['counts']['poor'] );
		$this->assertCount( 3, Scores::labelled( $data['rows'][0]['data']['issues'] ) );

		$this->assertSame( array( $page ), wp_list_pluck( Overview::data( array( 'post_type' => 'page' ) )['rows'], 'id' ) );
		$this->assertSame( array( $good ), wp_list_pluck( Overview::data( array( 'band' => 'good' ) )['rows'], 'id' ) );

		ob_start();
		Overview::render();
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'mgseo-bands', $html );
		$this->assertStringContainsString( 'Recalculate all', $html );
		$this->assertStringContainsString( esc_html( Scores::issue_label( $data['rows'][0]['data']['issues'][0]['id'] ) ), $html );
		$this->assertStringContainsString( 'post.php?post=' . $poor, $html );
	}

	public function test_overview_computes_missing_scores_within_budget(): void {
		$a = $this->unscored_post( 'a' );
		$b = $this->unscored_post( 'b' );
		$data = Overview::data( array( 'compute' => 1 ) );
		$this->assertSame( 1, $data['counts']['pending'] );
		$this->assertNotNull( Scores::data( $a ) );
		$this->assertNull( Scores::data( $b ) );
		$this->assertSame( 0, Overview::data()['counts']['pending'] );
	}

	public function test_sorting_by_score_keeps_posts_without_score(): void {
		$scored   = $this->unscored_post( 'strony' );
		$unscored = $this->unscored_post( '' );
		Scores::compute( $scored );
		set_current_screen( 'edit-post' );
		$query = new \WP_Query();
		$this->module()->sort_query( $query ); // Not main query: untouched.
		$this->assertSame( '', $query->get( 'meta_query' ) );

		$main                    = $GLOBALS['wp_the_query'];
		$GLOBALS['wp_the_query'] = $query; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- make it the main query.
		$query->set( 'orderby', 'mgseo_score' );
		$query->set( 'order', 'asc' );
		$query->set( 'post_type', 'post' );
		$query->set( 'fields', 'ids' );
		$query->set( 'posts_per_page', -1 );
		$this->module()->sort_query( $query );
		$ids = $query->get_posts();
		$this->assertContains( $unscored, $ids );
		$this->assertContains( $scored, $ids );
		$this->assertLessThan( array_search( $scored, $ids, true ), array_search( $unscored, $ids, true ) );
		$GLOBALS['wp_the_query'] = $main; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- restore.
		set_current_screen( 'front' );
	}

	public function test_bulk_action_recalculates_selected_posts(): void {
		wp_set_current_user( $this->admin );
		$a        = $this->unscored_post( 'a' );
		$b        = $this->unscored_post( 'b' );
		$redirect = $this->module()->handle_bulk( 'edit.php', 'mgseo_recalc', array( $a, $b ) );
		$this->assertStringContainsString( 'mgseo_recalculated=2', $redirect );
		$this->assertNotNull( Scores::data( $b ) );
		$this->assertSame( 'edit.php', $this->module()->handle_bulk( 'edit.php', 'trash', array( $a ) ) );
		$this->assertArrayHasKey( 'mgseo_recalc', $this->module()->bulk_actions( array() ) );
	}

	public function test_analyze_html_endpoint(): void {
		wp_set_current_user( $this->admin );
		$request = new \WP_REST_Request( 'POST', '/mg-seo/v1/analyze/html' );
		$request->set_body_params(
			array(
				'html'     => '<h2>Kawa ziarnista</h2><p>Kawa ziarnista z palarni. ' . str_repeat( 'Słowo ', 100 ) . '</p>',
				'keywords' => 'kawa ziarnista',
				'title'    => 'Kawa ziarnista – sklep',
			)
		);
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertTrue( $data['has_keyword'] );
		$this->assertContains( $data['band'], array( 'good', 'ok', 'poor' ) );
		$checks = wp_list_pluck( $data['checks'], 'status', 'id' );
		$this->assertSame( 'ok', $checks['keyword_in_title'] );
		$this->assertSame( 'ok', $checks['keyword_in_subheadings'] );
		$this->assertNotEmpty( $data['issues'][0]['label'] );

		wp_set_current_user( 0 );
		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_analyze_endpoint_reports_missing_keyword(): void {
		wp_set_current_user( $this->admin );
		$post    = $this->unscored_post( '' );
		$request = new \WP_REST_Request( 'POST', '/mg-seo/v1/analyze' );
		$request->set_body_params( array( 'post_id' => $post, 'keywords' => '' ) );
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertFalse( $data['has_keyword'] );
		$this->assertSame( Scores::BAND_NONE, $data['band'] );
		$this->assertSame( Scores::NO_KEYWORD, $data['issues'][0]['id'] );
	}

	public function test_admin_bar_and_publish_box_badges(): void {
		wp_set_current_user( $this->admin );
		$post = $this->unscored_post( 'strony internetowe' );
		$this->go_to( get_permalink( $post ) );
		require_once ABSPATH . WPINC . '/class-wp-admin-bar.php';
		$bar = new \WP_Admin_Bar();
		$this->module()->admin_bar( $bar );
		$node = $bar->get_node( 'seo-score' );
		$this->assertNotNull( $node );
		$this->assertMatchesRegularExpression( '#seo-score-value">\d+<#', $node->title );
		$this->assertStringContainsString( 'out of 100', $node->title );
		$this->assertNotNull( $bar->get_node( 'seo-score-head' ) );
		$this->assertNotNull( $bar->get_node( 'seo-score-issue-1' ) );
		$this->assertNotNull( $bar->get_node( 'seo-score-hints' ) );

		$none = $this->unscored_post( '' );
		$this->go_to( get_permalink( $none ) );
		$bar = new \WP_Admin_Bar();
		$this->module()->admin_bar( $bar );
		$this->assertStringContainsString( 'No keyword', $bar->get_node( 'seo-score' )->title );
		$this->assertNotNull( $bar->get_node( 'seo-score-keyword' ) );

		ob_start();
		$this->module()->publish_box( get_post( $post ) );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'mgseo-live-line', $html );
		$this->assertStringContainsString( 'mgseo-show-hints', $html );
		$this->assertMatchesRegularExpression( '#\d+/100#', $html );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$bar = new \WP_Admin_Bar();
		$this->module()->admin_bar( $bar );
		$this->assertNull( $bar->get_node( 'seo-score' ) );
	}

	public function test_top_issues_order_failures_by_points_lost(): void {
		$issues = Scores::top_issues(
			array(
				array( 'id' => 'a', 'status' => 'warn', 'weight' => 10 ),
				array( 'id' => 'b', 'status' => 'fail', 'weight' => 3 ),
				array( 'id' => 'c', 'status' => 'fail', 'weight' => 8 ),
				array( 'id' => 'd', 'status' => 'ok', 'weight' => 10 ),
				array( 'id' => 'e', 'status' => 'warn', 'weight' => 0 ),
			),
			true
		);
		$this->assertSame( array( 'c', 'b', 'a' ), wp_list_pluck( $issues, 'id' ) );
		$this->assertSame( array( Scores::NO_KEYWORD, 'c', 'b' ), wp_list_pluck( Scores::top_issues( array( array( 'id' => 'b', 'status' => 'fail', 'weight' => 3 ), array( 'id' => 'c', 'status' => 'fail', 'weight' => 8 ), array( 'id' => 'a', 'status' => 'warn', 'weight' => 10 ) ), false ), 'id' ) );
	}

	public function test_deactivation_and_uninstall_clear_events(): void {
		Scores::ensure_scheduled();
		Scores::start_pass();
		$this->module()->uninstall();
		$this->assertFalse( wp_next_scheduled( Scores::DAILY_EVENT ) );
		$this->assertFalse( wp_next_scheduled( Scores::BATCH_EVENT ) );
		$this->assertFalse( get_option( Scores::PASS_OPTION ) );
	}
}
