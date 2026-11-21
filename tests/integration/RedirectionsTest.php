<?php
/**
 * Redirections & 404 Monitor tests.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Modules\Redirections\AutoRedirect;
use MGSEO\Modules\Redirections\Csv;
use MGSEO\Modules\Redirections\Log404;
use MGSEO\Modules\Redirections\Matcher;
use MGSEO\Modules\Redirections\Module;
use MGSEO\Modules\Redirections\Repository;
use MGSEO\Plugin;

/**
 * Thrown from the wp_redirect filter so tests never reach exit.
 */
class RedirectCaught extends \Exception {

	/**
	 * Location.
	 *
	 * @var string
	 */
	public string $location;

	/**
	 * Status.
	 *
	 * @var int
	 */
	public int $status;

	/**
	 * Constructor.
	 *
	 * @param string $location Location.
	 * @param int    $status   Status.
	 */
	public function __construct( string $location, int $status ) {
		parent::__construct( 'redirect' );
		$this->location = $location;
		$this->status   = $status;
	}
}

/**
 * @covers \MGSEO\Modules\Redirections\Module
 * @covers \MGSEO\Modules\Redirections\Repository
 * @covers \MGSEO\Modules\Redirections\Matcher
 */
class RedirectionsTest extends TestCase {

	/**
	 * Last status code sent via status_header().
	 *
	 * @var int
	 */
	private int $status = 0;

	/**
	 * Set up.
	 */
	public function set_up(): void {
		parent::set_up();
		Repository::flush_cache();
		$this->status = 0;
		add_filter(
			'wp_redirect',
			static function ( $location, $status ) {
				throw new RedirectCaught( (string) $location, (int) $status );
			},
			10,
			2
		);
		add_filter(
			'status_header',
			function ( $header, $code ) {
				$this->status = (int) $code;
				return $header;
			},
			10,
			2
		);
	}

	/**
	 * Module instance.
	 */
	private function module(): Module {
		$module = Plugin::instance()->module( 'redirections' );
		$this->assertInstanceOf( Module::class, $module );
		return $module;
	}

	/**
	 * Request a URL through the module; returns [location, status] or null.
	 *
	 * @param string $path Path (with optional query).
	 */
	private function visit( string $path ): ?array {
		$this->go_to( home_url( $path ) );
		try {
			$this->module()->template_redirect();
		} catch ( RedirectCaught $e ) {
			return array( $e->location, $e->status );
		}
		return null;
	}

	/**
	 * Create a redirection or fail.
	 *
	 * @param array $args Args.
	 */
	private function create( array $args ): int {
		$id = Repository::create( $args );
		$this->assertIsInt( $id, is_wp_error( $id ) ? $id->get_error_message() : '' );
		return $id;
	}

	public function test_tables_exist(): void {
		$this->assertTrue( Repository::table_exists() );
		global $wpdb;
		$this->assertSame( Log404::table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Log404::table() ) ) );
	}

	public function test_exact_match_redirects_with_status_and_normalised_slashes(): void {
		$this->create(
			array(
				'sources' => array( '/old-page/' ),
				'url_to'  => '/new-page/',
				'type'    => 301,
			)
		);
		$this->assertSame( array( home_url( '/new-page/' ), 301 ), $this->visit( '/old-page/' ) );
		$this->assertSame( array( home_url( '/new-page/' ), 301 ), $this->visit( '/old-page' ) );
		$this->assertNull( $this->visit( '/old-page-2/' ) );
		$this->assertNull( $this->visit( '/OLD-PAGE/' ), 'Case sensitive by default' );
	}

	public function test_full_url_source_and_ignore_case(): void {
		$this->create(
			array(
				'sources' => array(
					array(
						'pattern'     => home_url( '/Promo/' ),
						'comparison'  => 'exact',
						'ignore_case' => true,
					),
				),
				'url_to'  => 'https://example.com/landing',
				'type'    => 302,
			)
		);
		$row = Repository::find_by_source( 'promo', 'exact' );
		$this->assertNotNull( $row );
		$this->assertSame( 'Promo', $row['sources'][0]['pattern'] );
		$this->assertSame( array( 'https://example.com/landing', 302 ), $this->visit( '/PROMO/' ) );
	}

	public function test_contains_start_end_matching(): void {
		$this->create(
			array(
				'sources' => array(
					array(
						'pattern'    => 'legacy',
						'comparison' => 'contains',
					),
				),
				'url_to'  => '/a/',
			)
		);
		$this->create(
			array(
				'sources' => array(
					array(
						'pattern'    => '/shop/old-',
						'comparison' => 'start',
					),
				),
				'url_to'  => '/b/',
			)
		);
		$this->create(
			array(
				'sources' => array(
					array(
						'pattern'    => '.html',
						'comparison' => 'end',
					),
				),
				'url_to'  => '/c/',
			)
		);
		$this->assertSame( home_url( '/a/' ), $this->visit( '/x/legacy-stuff/' )[0] );
		$this->assertSame( home_url( '/b/' ), $this->visit( '/shop/old-product/' )[0] );
		$this->assertSame( home_url( '/c/' ), $this->visit( '/about-us.html' )[0] );
		$this->assertNull( $this->visit( '/shop/new-product/' ) );
	}

	public function test_regex_with_backreferences(): void {
		$this->create(
			array(
				'sources' => array(
					array(
						'pattern'    => '^blog/(\d{4})/([a-z0-9-]+)$',
						'comparison' => 'regex',
					),
				),
				'url_to'  => '/articles/$2/?year=$1',
			)
		);
		$this->assertSame( array( home_url( '/articles/hello-world/?year=2019' ), 301 ), $this->visit( '/blog/2019/hello-world/' ) );
		$this->assertNull( $this->visit( '/blog/abcd/hello/' ) );
	}

	public function test_invalid_regex_and_missing_target_are_rejected(): void {
		$result = Repository::create(
			array(
				'sources' => array(
					array(
						'pattern'    => '^(unclosed',
						'comparison' => 'regex',
					),
				),
				'url_to'  => '/x/',
			)
		);
		$this->assertWPError( $result );
		$this->assertSame( 'invalid_regex', $result->get_error_code() );
		$this->assertWPError( Repository::create( array( 'sources' => array( '/a/' ) ) ) );
		$this->assertWPError( Repository::create( array( 'url_to' => '/a/' ) ) );
	}

	public function test_query_string_modes(): void {
		$this->create(
			array(
				'sources' => array( '/promo/' ),
				'url_to'  => '/offer/',
			)
		);
		$this->create(
			array(
				'sources' => array( '/page.php?id=7&lang=pl' ),
				'url_to'  => '/seven/',
			)
		);
		// Ignore (default): query does not prevent the match and is dropped.
		$this->assertSame( home_url( '/offer/' ), $this->visit( '/promo/?utm_source=x' )[0] );
		// A source with a query matches that query in any parameter order.
		$this->assertSame( home_url( '/seven/' ), $this->visit( '/page.php?lang=pl&id=7' )[0] );
		$this->assertNull( $this->visit( '/page.php?id=8&lang=pl' ) );

		$this->set_option( 'redirections', 'query_mode', 'pass' );
		$this->assertSame( home_url( '/offer/?utm_source=x' ), $this->visit( '/promo/?utm_source=x' )[0] );

		$this->set_option( 'redirections', 'query_mode', 'exact' );
		$this->assertNull( $this->visit( '/promo/?utm_source=x' ) );
		$this->assertSame( home_url( '/offer/' ), $this->visit( '/promo/' )[0] );
	}

	public function test_410_sends_status_and_renders_404_template(): void {
		$post = self::factory()->post->create_and_get( array( 'post_name' => 'removed-product' ) );
		$this->create(
			array(
				'sources' => array( '/removed-product/' ),
				'type'    => 410,
				'url_to'  => '/ignored/',
			)
		);
		$row = Repository::find_by_source( 'removed-product' );
		$this->assertSame( '', $row['url_to'], '410 stores no target' );
		$this->assertNull( $this->visit( '/' . $post->post_name . '/' ) );
		$this->assertSame( 410, $this->status );
		$this->assertTrue( is_404(), 'The theme 404 template is used for the body' );

		$this->create(
			array(
				'sources' => array( '/legal-takedown/' ),
				'type'    => 451,
			)
		);
		$this->assertNull( $this->visit( '/legal-takedown/' ) );
		$this->assertSame( 451, $this->status );
	}

	public function test_loop_protection(): void {
		$loop = Repository::create(
			array(
				'sources' => array( '/same/' ),
				'url_to'  => home_url( '/same/' ),
			)
		);
		$this->assertWPError( $loop );
		$this->assertSame( 'loop', $loop->get_error_code() );

		// Regex that would match its own destination: the matcher refuses to redirect to the requested URL.
		$this->create(
			array(
				'sources' => array(
					array(
						'pattern'    => '^docs/(.*)$',
						'comparison' => 'regex',
					),
				),
				'url_to'  => '/docs/$1',
			)
		);
		$this->assertNull( $this->visit( '/docs/install/' ) );
		// Ignore mode would drop the query and land on the same path again.
		$this->create(
			array(
				'sources' => array(
					array(
						'pattern'    => 'tracking',
						'comparison' => 'contains',
					),
				),
				'url_to'  => '/tracking/',
			)
		);
		$this->assertNull( $this->visit( '/tracking/?ref=1' ) );
	}

	public function test_inactive_redirections_are_ignored_and_cache_invalidated(): void {
		$id = $this->create(
			array(
				'sources' => array( '/temp/' ),
				'url_to'  => '/elsewhere/',
			)
		);
		$this->assertNotNull( $this->visit( '/temp/' ) );
		$this->assertIsArray( get_option( Repository::CACHE ) );
		Repository::set_status( $id, 'inactive' );
		$this->assertFalse( get_option( Repository::CACHE ), 'Writes delete the compiled cache' );
		$this->assertNull( $this->visit( '/temp/' ) );
		$this->assertSame( array(), get_option( Repository::CACHE )['exact'] );
		Repository::update( $id, array( 'status' => 'active' ) );
		$this->assertNotNull( $this->visit( '/temp/' ) );
	}

	public function test_early_matching_on_init(): void {
		$this->create(
			array(
				'sources' => array( '/early/' ),
				'url_to'  => '/later/',
			)
		);
		$this->set_option( 'redirections', 'match_timing', 'init' );
		$this->go_to( home_url( '/early/' ) );
		$GLOBALS['pagenow'] = 'index.php';
		try {
			$this->module()->match_early();
			$this->fail( 'Expected redirect on init' );
		} catch ( RedirectCaught $e ) {
			$this->assertSame( home_url( '/later/' ), $e->location );
		}
		// template_redirect does not match again in init mode.
		$this->assertNull( $this->visit( '/early/' ) );
	}

	public function test_hits_are_counted_in_one_batch_at_shutdown(): void {
		$id = $this->create(
			array(
				'sources' => array( '/counted/' ),
				'url_to'  => '/target/',
			)
		);
		$this->visit( '/counted/' );
		$this->visit( '/counted/' );
		$this->assertSame( 0, Repository::get( $id )['hits'], 'Nothing written during the request' );
		$this->module()->shutdown();
		$row = Repository::get( $id );
		$this->assertSame( 2, $row['hits'] );
		$this->assertNotNull( $row['last_accessed'] );
	}

	public function test_fallback_redirects_unmatched_404_to_home(): void {
		$this->set_option( 'redirections', 'fallback', 'home' );
		$this->assertSame( array( home_url( '/' ), 301 ), $this->visit( '/nothing-here-at-all/' ) );
		$this->set_option( 'redirections', 'fallback', 'default' );
		$this->assertNull( $this->visit( '/nothing-here-at-all/' ) );
	}

	public function test_auto_redirect_on_slug_change_without_loops(): void {
		$post_id = self::factory()->post->create( array( 'post_name' => 'first-slug' ) );
		wp_update_post(
			array(
				'ID'        => $post_id,
				'post_name' => 'second-slug',
			)
		);
		$row = Repository::find_by_source( 'first-slug' );
		$this->assertNotNull( $row );
		$this->assertSame( '/second-slug/', $row['url_to'] );
		$this->assertSame( 301, $row['type'] );
		$this->assertSame( AutoRedirect::CATEGORY, $row['category'] );

		// Chain flattening and loop avoidance when renaming back.
		wp_update_post(
			array(
				'ID'        => $post_id,
				'post_name' => 'first-slug',
			)
		);
		$this->assertNull( Repository::find_by_source( 'first-slug' ), 'Source that is live again is released' );
		$back = Repository::find_by_source( 'second-slug' );
		$this->assertSame( '/first-slug/', $back['url_to'] );
		$this->assertSame( array( home_url( '/first-slug/' ), 301 ), $this->visit( '/second-slug/' ) );
		$this->assertSame( 1, Repository::count() );
	}

	public function test_auto_redirect_can_be_disabled_and_ignores_drafts(): void {
		$draft = self::factory()->post->create(
			array(
				'post_name'   => 'draft-a',
				'post_status' => 'draft',
			)
		);
		wp_update_post(
			array(
				'ID'        => $draft,
				'post_name' => 'draft-b',
			)
		);
		$this->assertSame( 0, Repository::count() );

		$this->set_option( 'redirections', 'auto_redirect', false );
		$post = self::factory()->post->create( array( 'post_name' => 'p-one' ) );
		wp_update_post(
			array(
				'ID'        => $post,
				'post_name' => 'p-two',
			)
		);
		$this->assertSame( 0, Repository::count() );
	}

	public function test_auto_redirect_on_term_slug_change(): void {
		$term = self::factory()->category->create_and_get( array( 'slug' => 'old-cat' ) );
		wp_update_term( $term->term_id, 'category', array( 'slug' => 'new-cat' ) );
		$row = Repository::find_by_source( 'category/old-cat' );
		$this->assertNotNull( $row );
		$this->assertSame( '/category/new-cat/', $row['url_to'] );
	}

	public function test_trash_behaviour_410_and_restore(): void {
		$this->set_option( 'redirections', 'trash_behavior', '410' );
		$post = self::factory()->post->create( array( 'post_name' => 'to-be-trashed' ) );
		wp_trash_post( $post );
		$row = Repository::find_by_source( 'to-be-trashed' );
		$this->assertNotNull( $row );
		$this->assertSame( 410, $row['type'] );
		wp_untrash_post( $post );
		$this->assertNull( Repository::find_by_source( 'to-be-trashed' ) );
	}

	public function test_404_logging_simple_mode_aggregates_without_ip(): void {
		$_SERVER['REMOTE_ADDR']     = '203.0.113.77';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test';
		$this->visit( '/missing-thing/?utm=1' );
		$this->module()->shutdown();
		$this->visit( '/missing-thing/?utm=2' );
		$this->module()->shutdown();
		$rows = Log404::all();
		$this->assertCount( 1, $rows );
		$this->assertSame( '/missing-thing/', $rows[0]['uri'], 'Query stripped by default' );
		$this->assertSame( 2, (int) $rows[0]['hits'] );
		$this->assertSame( '', $rows[0]['ip'] );
	}

	public function test_404_logging_advanced_mode_anonymises_ip(): void {
		$this->set_option( 'redirections', 'log_mode', 'advanced' );
		$_SERVER['REMOTE_ADDR']     = '203.0.113.77';
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test';
		$this->visit( '/adv-missing/' );
		$this->module()->shutdown();
		$_SERVER['REMOTE_ADDR'] = '2001:db8:abcd:12:34::1';
		$this->visit( '/adv-missing/' );
		$this->module()->shutdown();
		$rows = Log404::all( array( 'orderby' => 'id', 'order' => 'ASC' ) );
		$this->assertCount( 2, $rows );
		$this->assertSame( '203.0.113.0', $rows[0]['ip'] );
		$this->assertSame( '2001:db8:abcd::', $rows[1]['ip'] );
		$this->assertSame( 'Mozilla/5.0 Test', $rows[0]['user_agent'] );
	}

	public function test_404_exclusions_and_bots(): void {
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test';
		foreach ( array( '/wp-content/uploads/missing.jpg', '/assets/app.js.map', '/favicon.ico' ) as $path ) {
			$this->visit( $path );
			$this->module()->shutdown();
		}
		$this->assertSame( 0, Log404::count() );

		$this->set_option( 'redirections', 'log_ignore_bots', true );
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
		$this->visit( '/bot-missing/' );
		$this->module()->shutdown();
		$this->assertSame( 0, Log404::count() );

		$this->set_option( 'redirections', 'log_404', false );
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Test';
		$this->visit( '/off-missing/' );
		$this->module()->shutdown();
		$this->assertSame( 0, Log404::count() );
	}

	public function test_ip_anonymisation(): void {
		$this->assertSame( '192.168.10.0', Log404::anonymize_ip( '192.168.10.254' ) );
		$this->assertSame( '2a00:1450:4001::', Log404::anonymize_ip( '2a00:1450:4001:81c::200e' ) );
		$this->assertSame( '', Log404::anonymize_ip( 'not-an-ip' ) );
	}

	public function test_log_cleanup_respects_row_cap(): void {
		$this->set_option( 'redirections', 'log_max_rows', 3 );
		for ( $i = 1; $i <= 6; $i++ ) {
			Log404::log( '/gone-' . $i . '/', '', 'UA', '', 'simple' );
		}
		Log404::cleanup();
		$this->assertSame( 3, Log404::count() );
	}

	public function test_csv_round_trip(): void {
		$this->create(
			array(
				'sources'  => array(
					'/one/',
					array(
						'pattern'     => '^two/(.*)',
						'comparison'  => 'regex',
						'ignore_case' => true,
					),
				),
				'url_to'   => '/target/$1',
				'type'     => 302,
				'category' => 'Campaign',
			)
		);
		$this->create(
			array(
				'sources' => array( '/gone/' ),
				'type'    => 410,
				'status'  => 'inactive',
			)
		);
		$before = Repository::all();
		$csv    = Csv::to_string( array_merge( array( Csv::HEADERS ), Csv::redirection_rows() ) );
		$this->assertStringContainsString( 'id,source,comparison', $csv );

		$result = Csv::import( $csv );
		$this->assertSame( 0, $result['created'], 'Existing sources are skipped' );
		$this->assertSame( 2, $result['skipped'] );

		Repository::truncate();
		$result = Csv::import( $csv );
		$this->assertSame( 2, $result['created'], implode( '; ', $result['errors'] ) );
		$after = Repository::all();
		$strip = static fn( $rows ) => array_map( static fn( $r ) => array_diff_key( $r, array_flip( array( 'id', 'updated', 'last_accessed' ) ) ), $rows );
		$this->assertSame( $strip( $before ), $strip( $after ) );
	}

	public function test_csv_import_validation_semicolons_and_formula_escape(): void {
		$csv    = "source;destination;type\n/a/;/b/;301\n;/x/;301\n/c/;;302\n/d/;/e/;999\n";
		$result = Csv::import( $csv );
		$this->assertSame( 1, $result['created'] );
		$this->assertSame( 3, $result['skipped'] );
		$this->assertCount( 3, $result['errors'] );
		$this->assertSame( "'=cmd", Csv::escape_cell( '=cmd' ) );
	}

	public function test_public_api_for_importer(): void {
		$id = Repository::create(
			array(
				'sources'       => array(
					array(
						'pattern'    => 'imported',
						'comparison' => 'start',
					),
				),
				'url_to'        => 'https://example.org/x/',
				'type'          => 307,
				'hits'          => 12,
				'last_accessed' => '2024-01-02 03:04:05',
				'created'       => '2023-05-06 07:08:09',
			)
		);
		$this->assertIsInt( $id );
		$row = Repository::find_by_source( 'imported', 'start' );
		$this->assertSame( $id, $row['id'] );
		$this->assertSame( 12, $row['hits'] );
		$this->assertSame( '2023-05-06 07:08:09', $row['created'] );
		$this->assertCount( 1, Repository::all() );
		$this->assertNull( Repository::find_by_source( 'imported', 'exact' ) );
	}

	public function test_matcher_handles_home_subdirectory_paths(): void {
		$compiled = array(
			'exact'    => array(
				'a/b' => array(
					'id'     => 1,
					'type'   => 301,
					'url_to' => '/c/',
				),
			),
			'exact_ci' => array(),
			'other'    => array(),
		);
		$match    = Matcher::match( 'a/b', '', $compiled );
		$this->assertSame( home_url( '/c/' ), $match['url'] );
		$this->assertNull( Matcher::match( 'a/b', 'x=1', $compiled, 'exact' ) );
	}

	public function test_no_plugin_fingerprint_in_redirect(): void {
		$this->create(
			array(
				'sources' => array( '/fp/' ),
				'url_to'  => '/fp-target/',
			)
		);
		$by = null;
		add_filter(
			'x_redirect_by',
			static function ( $value ) use ( &$by ) {
				$by = $value;
				return $value;
			},
			1
		);
		remove_all_filters( 'wp_redirect' );
		add_filter(
			'wp_redirect',
			static function ( $location, $status ) {
				return $location;
			},
			10,
			2
		);
		add_filter(
			'x_redirect_by',
			static function ( $value, $status, $location ) {
				throw new RedirectCaught( (string) $location, (int) $status );
			},
			99,
			3
		);
		$this->go_to( home_url( '/fp/' ) );
		try {
			$this->module()->template_redirect();
			$this->fail( 'Expected redirect' );
		} catch ( RedirectCaught $e ) {
			$this->assertSame( 'WordPress', $by );
		}
	}
}
