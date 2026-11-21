<?php
/**
 * Tools module tests.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Meta;
use MGSEO\Modules\Redirections\Repository;
use MGSEO\Modules\Tools\IndexNow;
use MGSEO\Modules\Tools\Llms;
use MGSEO\Modules\Tools\Robots;
use MGSEO\Modules\Tools\Transfer;
use MGSEO\Options;

/**
 * @covers \MGSEO\Modules\Tools\Module
 */
class ToolsTest extends TestCase {

	/**
	 * Captured outgoing HTTP requests.
	 *
	 * @var array<int, array{url: string, args: array}>
	 */
	private array $requests = array();

	/**
	 * Set up: intercept every HTTP request.
	 */
	public function set_up(): void {
		parent::set_up();
		Repository::flush_cache();
		$this->requests = array();
		add_filter(
			'pre_http_request',
			function ( $pre, $args, $url ) {
				$this->requests[] = array(
					'url'  => (string) $url,
					'args' => (array) $args,
				);
				return array(
					'headers'  => array(),
					'body'     => '',
					'response' => array(
						'code'    => 200,
						'message' => 'OK',
					),
					'cookies'  => array(),
				);
			},
			10,
			3
		);
	}

	public function test_robots_txt_replaced_only_when_custom_content_set(): void {
		$default = "User-agent: *\nDisallow: /wp-admin/\n";
		$this->assertSame( $default, Robots::filter( $default, '1' ) );
		// Without custom content the output is not replaced (other plugins such as WooCommerce may still add lines).
		$this->assertStringContainsString( "Disallow: /wp-admin/\n", apply_filters( 'robots_txt', $default, '1' ) );

		$this->set_option( 'tools', 'robots_txt', "User-agent: *\nDisallow: /private/" );
		$out = apply_filters( 'robots_txt', $default, '1' );
		$this->assertStringStartsWith( "User-agent: *\nDisallow: /private/\n", $out );
		$this->assertStringNotContainsString( 'wp-admin', $out );

		// Not public: core's "Disallow: /" stays.
		$this->assertSame( "User-agent: *\nDisallow: /\n", Robots::filter( "User-agent: *\nDisallow: /\n", '0' ) );
	}

	public function test_custom_robots_drops_lines_added_by_other_plugins(): void {
		$this->set_option( 'tools', 'robots_txt', "User-agent: *\nDisallow: /only/" );
		add_filter( 'robots_txt', static fn( $out ) => $out . "Disallow: /injected/\n", 10 );
		$out = apply_filters( 'robots_txt', "User-agent: *\n", '1' );
		$this->assertStringStartsWith( "User-agent: *\nDisallow: /only/\n", $out );
		$this->assertStringNotContainsString( '/injected/', $out );
	}

	public function test_robots_sanitizer_strips_markup_and_normalises_newlines(): void {
		$this->assertSame( "User-agent: *\nDisallow: /a%20b/", Robots::sanitize( "User-agent: *  \r\n<b>Disallow: /a%20b/</b>\r\n\r\n" ) );
		$this->assertStringContainsString( 'User-agent: *', Robots::suggestion() );
		$this->assertStringNotContainsString( 'Sitemap:', Robots::suggestion() );
	}

	public function test_llms_txt_content(): void {
		update_option( 'blogname', 'Studio Graphics' );
		update_option( 'blogdescription', 'Design & web' );
		$page = self::factory()->post->create_and_get(
			array(
				'post_type'    => 'page',
				'post_title'   => 'About [us]',
				'post_excerpt' => '',
				'post_content' => 'We build websites.',
			)
		);
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Hello',
				'post_excerpt' => 'Short summary.',
			)
		);
		$hidden = self::factory()->post->create_and_get( array( 'post_title' => 'Secret post' ) );
		Meta::set_post( $hidden->ID, 'robots', array( 'noindex' ) );
		Meta::set_post( $post->ID, 'description', 'Custom description' );

		$txt = Llms::content();
		$this->assertStringStartsWith( "# Studio Graphics\n\n> Design & web\n", $txt );
		$this->assertStringContainsString( "## Pages\n", $txt );
		$this->assertStringContainsString( "## Posts\n", $txt );
		$this->assertStringContainsString( '- [About (us) - Studio Graphics](' . get_permalink( $page ) . '): We build websites.', $txt );
		$this->assertStringContainsString( '- [Hello - Studio Graphics](' . get_permalink( $post ) . '): Custom description', $txt );
		$this->assertStringNotContainsString( 'Secret post', $txt );

		$this->set_option( 'tools', 'llms_post_types', array( 'page' ) );
		$this->assertStringNotContainsString( '## Posts', Llms::content() );
	}

	public function test_llms_disabled_by_default(): void {
		$this->assertFalse( (bool) Options::get( 'tools', 'llms_enabled' ) );
		$this->go_to( home_url( '/llms.txt' ) );
		Llms::serve(); // Returns without output/exit when off.
		$this->assertTrue( is_404() );
	}

	public function test_indexnow_off_by_default_sends_nothing(): void {
		$this->assertFalse( IndexNow::enabled() );
		self::factory()->post->create( array( 'post_title' => 'Fresh' ) );
		$this->assertSame( array(), IndexNow::queued() );
		IndexNow::flush_queue();
		$result = IndexNow::submit( array( home_url( '/x/' ) ), true );
		$this->assertFalse( $result['ok'] );
		$this->assertSame( array(), $this->requests, 'No HTTP request while IndexNow is off' );
		$this->assertNull( IndexNow::key_file_body( IndexNow::key() . '.txt' ) );
	}

	public function test_indexnow_submits_on_publish_when_enabled(): void {
		$this->set_option( 'tools', 'indexnow_enabled', true );
		$key = IndexNow::key();
		$this->assertMatchesRegularExpression( '/^[a-f0-9]{32}$/', $key );
		$this->assertSame( $key, IndexNow::key(), 'Key is stable' );
		$this->assertSame( $key, IndexNow::key_file_body( $key . '.txt' ) );
		$this->assertNull( IndexNow::key_file_body( 'other.txt' ) );
		$this->assertSame( home_url( '/' . $key . '.txt' ), IndexNow::key_url() );

		$post = self::factory()->post->create( array( 'post_title' => 'Announce me' ) );
		self::factory()->post->create(
			array(
				'post_title'  => 'Draft',
				'post_status' => 'draft',
			)
		);
		$this->assertSame( array( get_permalink( $post ) ), IndexNow::queued() );
		IndexNow::flush_queue();
		$this->assertCount( 1, $this->requests );
		$request = $this->requests[0];
		$this->assertSame( IndexNow::ENDPOINT, $request['url'] );
		$this->assertFalse( $request['args']['blocking'] );
		$body = json_decode( $request['args']['body'], true );
		$this->assertSame( array( get_permalink( $post ) ), $body['urlList'] );
		$this->assertSame( $key, $body['key'] );
		$this->assertSame( wp_parse_url( home_url(), PHP_URL_HOST ), $body['host'] );

		// Duplicate saves within minutes are not resubmitted.
		wp_update_post(
			array(
				'ID'         => $post,
				'post_title' => 'Announce me again',
			)
		);
		IndexNow::flush_queue();
		$this->assertCount( 1, $this->requests );

		$log = IndexNow::get_log();
		$this->assertSame( 'auto', $log[0]['source'] );
		$this->assertSame( 1, $log[0]['count'] );
	}

	public function test_indexnow_manual_submit_filters_foreign_hosts(): void {
		$this->set_option( 'tools', 'indexnow_enabled', true );
		$result = IndexNow::submit( array( home_url( '/a/' ), 'https://other.example/b/', '' ), true, 'manual' );
		$this->assertTrue( $result['ok'] );
		$this->assertSame( 200, $result['code'] );
		$body = json_decode( $this->requests[0]['args']['body'], true );
		$this->assertSame( array( home_url( '/a/' ) ), $body['urlList'] );
	}

	public function test_settings_export_import_round_trip(): void {
		$this->set_option( 'titles', 'separator', '|' );
		$this->set_option( 'tools', 'robots_txt', "User-agent: *\nDisallow: /x/" );
		Repository::create(
			array(
				'sources' => array( '/exported/' ),
				'url_to'  => '/somewhere/',
			)
		);
		$export = Transfer::export( true );
		$json   = wp_json_encode( $export );
		$this->assertSame( Transfer::FORMAT, $export['format'] );
		$this->assertCount( 1, $export['redirections'] );

		Transfer::reset();
		Repository::truncate();
		$this->assertSame( '-', Options::get( 'titles', 'separator' ) );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$data                       = json_decode( $json, true );
		$data['settings']['bogus']  = array( 'x' => 1 );
		$data['settings']['titles'] = array_merge( $data['settings']['titles'], array( 'separator' => '|' ) );
		$result                     = Transfer::import( $data, true );
		$this->assertIsArray( $result );
		$this->assertSame( 1, $result['redirections'] );
		$this->assertSame( '|', Options::get( 'titles', 'separator' ) );
		$this->assertSame( "User-agent: *\nDisallow: /x/", Options::get( 'tools', 'robots_txt' ) );
		$this->assertArrayNotHasKey( 'bogus', Options::all() );
		$this->assertNotNull( Repository::find_by_source( 'exported' ) );

		$this->assertWPError( Transfer::import( array( 'settings' => array() ) ) );
	}

	public function test_import_sanitizes_values_by_schema(): void {
		$result = Transfer::import(
			array(
				'format'   => Transfer::FORMAT,
				'settings' => array(
					'titles' => array(
						'separator'  => 'not-allowed',
						'capitalize' => 'yes',
					),
				),
			)
		);
		$this->assertIsArray( $result );
		$this->assertSame( '-', Options::get( 'titles', 'separator' ), 'Invalid select value falls back to default' );
		$this->assertTrue( Options::get( 'titles', 'capitalize' ) );
	}

	public function test_delete_data_defaults_to_true_and_daily_cron_scheduled(): void {
		$this->assertTrue( Options::get( 'tools', 'delete_data' ) );
		wp_clear_scheduled_hook( 'mgseo_daily' );
		\MGSEO\Modules\Tools\Module::ensure_cron();
		$this->assertNotFalse( wp_next_scheduled( 'mgseo_daily' ) );
	}
}
