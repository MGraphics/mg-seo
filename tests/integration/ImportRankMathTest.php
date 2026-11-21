<?php
/**
 * Rank Math importer tests (fixture rows mirror what Rank Math 1.0.282 stores).
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Meta;
use MGSEO\Modules\Import\Converter;
use MGSEO\Modules\Import\Log;
use MGSEO\Modules\Import\Runner;
use MGSEO\Modules\Redirections\Repository;
use MGSEO\Options;

/**
 * @covers \MGSEO\Modules\Import\RankMath
 * @covers \MGSEO\Modules\Import\Converter
 * @covers \MGSEO\Modules\Import\Runner
 */
class ImportRankMathTest extends TestCase {

	/**
	 * Create Rank Math's tables with leftover rows.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$charset = $wpdb->get_charset_collate();
		// phpcs:disable WordPress.DB
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}rank_math_redirections ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, sources longtext NOT NULL, url_to text NOT NULL, header_code smallint(4) unsigned NOT NULL, hits bigint(20) unsigned NOT NULL DEFAULT 0, status varchar(25) NOT NULL DEFAULT 'active', created datetime NOT NULL DEFAULT '0000-00-00 00:00:00', updated datetime NOT NULL DEFAULT '0000-00-00 00:00:00', last_accessed datetime NOT NULL DEFAULT '0000-00-00 00:00:00', PRIMARY KEY (id) ) $charset" );
		$wpdb->query( "CREATE TABLE {$wpdb->prefix}rank_math_404_logs ( id bigint(20) unsigned NOT NULL AUTO_INCREMENT, uri varchar(255) NOT NULL, accessed datetime NOT NULL DEFAULT '0000-00-00 00:00:00', times_accessed bigint(20) unsigned NOT NULL DEFAULT 1, referer varchar(255) NOT NULL DEFAULT '', user_agent varchar(255) NOT NULL DEFAULT '', PRIMARY KEY (id) ) $charset" );
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mgseo_redirections" );
		// phpcs:enable
		delete_option( 'mgseo_import_map' );
		delete_option( Log::OPTION );
	}

	/**
	 * Drop fixture tables.
	 */
	public function tear_down(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB
		$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}rank_math_redirections, {$wpdb->prefix}rank_math_404_logs" );
		parent::tear_down();
	}

	/**
	 * Insert Rank Math options as observed.
	 */
	private function seed_options(): void {
		update_option(
			'rank-math-options-titles',
			array(
				'title_separator'            => '|',
				'capitalize_titles'          => 'off',
				'robots_global'              => array( 'index' ),
				'advanced_robots_global'     => array(
					'max-snippet'       => -1,
					'max-video-preview' => -1,
					'max-image-preview' => 'large',
				),
				'noindex_empty_taxonomies'   => 'on',
				'noindex_password_protected' => 'off',
				'homepage_title'             => '%sitename% %sep% Projektowanie ogrodów',
				'homepage_description'       => 'Ogrody. %sitedesc%.',
				'homepage_custom_robots'     => 'off',
				'pt_post_title'              => '%title% %sep% %category% %sep% %sitename%',
				'pt_post_description'        => '%excerpt%',
				'pt_post_robots'             => array( 'index' ),
				'pt_post_custom_robots'      => 'off',
				'pt_post_default_rich_snippet' => 'article',
				'pt_post_default_article_type' => 'BlogPosting',
				'pt_page_title'              => '%title% %page% %sep% %sitename%',
				'pt_page_description'        => '%excerpt% %currenttime(H:i)%',
				'pt_page_robots'             => array( 'index', 'noarchive' ),
				'pt_page_custom_robots'      => 'on',
				'pt_page_default_rich_snippet' => 'off',
				'pt_ghost_title'             => '%title%',
				'tax_category_title'         => '%term% – kategoria %sep% %sitename%',
				'tax_category_description'   => '%term_description%',
				'tax_category_robots'        => array( 'index' ),
				'tax_category_custom_robots' => 'off',
				'tax_post_tag_title'         => 'Tag %term%',
				'tax_post_tag_robots'        => array( 'noindex' ),
				'tax_post_tag_custom_robots' => 'on',
				'author_custom_robots'       => 'on',
				'author_robots'              => array( 'noindex' ),
				'author_archive_title'       => 'Autor: %name% %sep% %sitename% %page%',
				'disable_date_archives'      => 'on',
				'date_archive_robots'        => array( 'noindex' ),
				'search_title'               => '%search_query% %page% %sep% %sitename%',
				'404_title'                  => 'Page Not Found %sep% %sitename%',
				'knowledgegraph_type'        => 'company',
				'knowledgegraph_name'        => 'Kowalski Studio Sp. z o.o.',
				'knowledgegraph_logo'        => 'http://example.org/wp-content/uploads/logo.webp',
				'website_name'               => 'Kowalski Studio',
				'website_alternate_name'     => 'KS Ogrody',
				'organization_description'   => 'Projektowanie ogrodów',
				'email'                      => 'biuro@kowalski.example',
				'local_business_type'        => 'Florist',
				'local_address'              => array(
					'streetAddress'   => 'ul. Kwiatowa 5',
					'addressLocality' => 'Kraków',
					'postalCode'      => '30-001',
					'addressCountry'  => 'PL',
				),
				'opening_hours'              => array(
					array(
						'day'  => 'Monday',
						'time' => '09:00-17:00',
					),
					array(
						'day'  => 'Saturday',
						'time' => '10:00-14:00',
					),
				),
				'phone_numbers'              => array(
					array(
						'type'   => 'customer support',
						'number' => '+48 600 100 200',
					),
				),
				'geo'                        => '50.0614, 19.9366',
				'price_range'                => '$$',
				'additional_info'            => array(
					array(
						'type'  => 'legalName',
						'value' => 'Kowalski Studio Sp. z o.o.',
					),
				),
				'open_graph_image'           => 'http://example.org/wp-content/uploads/og.webp',
				'social_url_facebook'        => 'https://www.facebook.com/kowalskistudio',
				'facebook_app_id'            => '987654321',
				'twitter_author_names'       => 'kowalskistudio',
				'twitter_card_type'          => 'summary_large_image',
				'social_additional_profiles' => "https://www.instagram.com/kowalskistudio\nhttps://www.linkedin.com/company/ks",
			)
		);
		update_option(
			'rank-math-options-general',
			array(
				'breadcrumbs'                => 'on',
				'breadcrumbs_separator'      => '&raquo;',
				'breadcrumbs_home'           => 'on',
				'breadcrumbs_home_label'     => 'Start',
				'breadcrumbs_archive_format' => 'Archiwum: %s',
				'google_verify'              => 'google-abc',
				'bing_verify'                => 'BING1',
				'redirections_header_code'   => '302',
				'404_monitor_mode'           => 'simple',
				'404_monitor_limit'          => 100,
				'attachment_redirect_urls'   => 'on',
				'rss_after_content'          => 'Wpis %POSTLINK% na %BLOGLINK% %AUTHORLINK%',
			)
		);
		update_option(
			'rank-math-options-sitemap',
			array(
				'items_per_page'       => 500,
				'include_images'       => 'on',
				'pt_post_sitemap'      => 'on',
				'pt_page_sitemap'      => 'off',
				'tax_post_tag_sitemap' => 'off',
				'exclude_roles'        => array( 'subscriber' ),
			)
		);
		update_option( 'rank_math_modules', array( 'sitemap', 'rich-snippet', 'local-seo', '404-monitor', 'redirections' ) );
	}

	public function test_rank_math_code_is_not_loaded(): void {
		$this->assertFalse( class_exists( 'RankMath' ) );
		$this->assertFalse( function_exists( 'rank_math' ) );
		$this->assertFalse( class_exists( '\RankMath\Helper' ) );
	}

	public function test_detects_leftover_data(): void {
		$this->seed_options();
		$post = self::factory()->post->create();
		update_post_meta( $post, 'rank_math_title', 'X' );
		update_post_meta( $post, 'rank_math_schema_Article', array( '@type' => 'Article' ) );
		$detect = Runner::detect()['rankmath'];
		$this->assertFalse( $detect['active'] );
		$this->assertSame( 3, $detect['counts']['settings'] );
		$this->assertSame( 1, $detect['counts']['posts'] );
		$this->assertSame( 1, $detect['counts']['schema'] );
	}

	public function test_imports_settings(): void {
		$this->seed_options();
		$result = Runner::run( 'rankmath', array( 'settings' ) );
		Options::flush_cache();
		$this->assertSame( '|', Options::get( 'titles', 'separator' ) );
		$this->assertSame( '%sitename% %sep% Projektowanie ogrodów', Options::get( 'titles', 'home_title' ) );
		$this->assertSame( '%title% %sep% %primary_category% %sep% %sitename%', Options::get( 'titles', 'pt_post_title' ) );
		$this->assertSame( '%excerpt% %currenttime(H:i)%', Options::get( 'titles', 'pt_page_description' ) );
		$this->assertSame( array(), Options::get( 'titles', 'pt_post_robots' ) );
		$this->assertSame( array( 'noarchive' ), Options::get( 'titles', 'pt_page_robots' ) );
		$this->assertSame( array( 'noindex' ), Options::get( 'titles', 'tax_post_tag_robots' ) );
		$this->assertSame( array(), Options::get( 'titles', 'tax_category_robots' ) );
		$this->assertSame( array( 'noindex' ), Options::get( 'titles', 'author_robots' ) );
		$this->assertFalse( Options::get( 'titles', 'date_archive' ) );
		$this->assertTrue( Options::get( 'titles', 'noindex_empty_terms' ) );
		$this->assertSame( 'google-abc', Options::get( 'titles', 'verify_google' ) );
		$this->assertSame( 'BING1', Options::get( 'titles', 'verify_bing' ) );
		$this->assertSame( 'Wpis %postlink% na %sitelink% %AUTHORLINK%', Options::get( 'titles', 'rss_after' ) );
		$this->assertArrayNotHasKey( 'pt_ghost_title', Options::all()['titles'], 'Settings of unregistered post types are skipped.' );
		// Social.
		$this->assertSame( 'http://example.org/wp-content/uploads/og.webp', Options::get( 'social', 'default_image' ) );
		$this->assertSame( 'kowalskistudio', Options::get( 'social', 'twitter_site' ) );
		$this->assertSame( '987654321', Options::get( 'social', 'facebook_app_id' ) );
		// Schema / knowledge graph.
		$this->assertSame( 'organization', Options::get( 'schema', 'kg_type' ) );
		$this->assertSame( 'Kowalski Studio Sp. z o.o.', Options::get( 'schema', 'org_name' ) );
		$this->assertSame( 'KS Ogrody', Options::get( 'schema', 'website_alt_name' ) );
		$this->assertSame( '+48 600 100 200', Options::get( 'schema', 'org_phone' ) );
		$this->assertSame( array( 'https://www.instagram.com/kowalskistudio', 'https://www.linkedin.com/company/ks' ), Options::get( 'schema', 'same_as' ) );
		$this->assertSame( 'Article', Options::get( 'schema', 'pt_post_type' ) );
		$this->assertSame( '', Options::get( 'schema', 'pt_page_type' ) );
		$this->assertSame( 'BlogPosting', Options::get( 'schema', 'article_type' ) );
		// Local SEO.
		$this->assertSame( 'Florist', Options::get( 'local', 'type' ) );
		$this->assertSame( 'ul. Kwiatowa 5', Options::get( 'local', 'street' ) );
		$this->assertSame( '50.0614', Options::get( 'local', 'lat' ) );
		$hours = Options::get( 'local', 'hours' );
		$this->assertSame( array( 'mode' => 'open', 'ranges' => '10:00-14:00' ), $hours['saturday'] );
		$this->assertSame( 'closed', $hours['sunday']['mode'] );
		// Breadcrumbs, sitemap, redirections.
		$this->assertSame( '»', Options::get( 'breadcrumbs', 'separator' ) );
		$this->assertSame( 'Start', Options::get( 'breadcrumbs', 'home_label' ) );
		$this->assertSame( 500, Options::get( 'sitemap', 'links_per_sitemap' ) );
		$this->assertFalse( Options::get( 'sitemap', 'pt_page_include' ) );
		$this->assertFalse( Options::get( 'sitemap', 'tax_post_tag_include' ) );
		$this->assertSame( '302', Options::get( 'redirections', 'default_type' ) );
		$this->assertTrue( Options::get( 'redirections', 'log_404' ) );
		$this->assertArrayHasKey( '%AUTHORLINK%', $result['unknown'] );
		$states = get_option( 'mgseo_modules' );
		$this->assertTrue( $states['local'] );
	}

	public function test_settings_are_not_overwritten_unless_asked(): void {
		$this->seed_options();
		$this->set_option( 'titles', 'separator', '–' );
		Runner::run( 'rankmath', array( 'settings' ) );
		Options::flush_cache();
		$this->assertSame( '–', Options::get( 'titles', 'separator' ) );
		Runner::run( 'rankmath', array( 'settings' ), array( 'overwrite' => true ) );
		Options::flush_cache();
		$this->assertSame( '|', Options::get( 'titles', 'separator' ) );
	}

	/**
	 * A post with the meta Rank Math stores.
	 *
	 * @param array $extra Extra meta.
	 */
	private function rm_post( array $extra = array() ): int {
		$cat  = self::factory()->category->create( array( 'name' => 'Porady' ) );
		$cat2 = self::factory()->category->create( array( 'name' => 'Ogrody' ) );
		$post = self::factory()->post->create(
			array(
				'post_title'    => 'Mały ogród',
				'post_excerpt'  => 'Krótki poradnik.',
				'post_category' => array( $cat2, $cat ),
			)
		);
		$meta = $extra + array(
			'rank_math_title'                => '%title% %sep% %category% %sep% %sitename%',
			'rank_math_description'          => 'Jak zaplanować ogród? %excerpt%',
			'rank_math_focus_keyword'        => 'mały ogród,projekt ogrodu',
			'rank_math_robots'               => array( 'index', 'nofollow', 'noarchive' ),
			'rank_math_advanced_robots'      => array(
				'max-snippet'       => '120',
				'max-video-preview' => '-1',
				'max-image-preview' => 'standard',
			),
			'rank_math_canonical_url'        => 'https://example.org/canonical/',
			'rank_math_facebook_title'       => 'FB title',
			'rank_math_facebook_description' => 'FB description',
			'rank_math_facebook_image'       => 'https://example.org/fb.jpg',
			'rank_math_facebook_image_id'    => '8',
			'rank_math_twitter_use_facebook' => 'off',
			'rank_math_twitter_title'        => 'X title',
			'rank_math_twitter_card_type'    => 'summary',
			'rank_math_primary_category'     => (string) $cat,
			'rank_math_pillar_content'       => 'on',
			'rank_math_breadcrumb_title'     => 'Ogródek',
			'rank_math_seo_score'            => '52',
			'rank_math_internal_links_processed' => '1',
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post, $key, $value );
		}
		return $post;
	}

	public function test_imports_post_meta_and_renders_same_title(): void {
		update_option( 'blogname', 'Studio' );
		$this->set_option( 'titles', 'separator', '|' );
		$post = $this->rm_post();
		// Check the raw mapping of rank_math_seo_score; ScoresTest covers the recalculation after imports.
		remove_all_actions( 'mgseo/import/finished' );
		Runner::run( 'rankmath', array( 'posts' ) );

		$this->assertSame( '%title% %sep% %primary_category% %sep% %sitename%', Meta::post( $post, 'title' ) );
		$this->assertSame( 'Jak zaplanować ogród? %excerpt%', Meta::post( $post, 'description' ) );
		$this->assertSame( 'mały ogród,projekt ogrodu', Meta::post( $post, 'focus_keyword' ) );
		$this->assertSame( array( 'index', 'nofollow', 'noarchive' ), Meta::post( $post, 'robots' ) );
		$this->assertEquals( array( 'max-snippet' => '120', 'max-image-preview' => 'standard', 'max-video-preview' => '-1' ), array_intersect_key( (array) Meta::post( $post, 'advanced_robots' ), array( 'max-snippet' => 1, 'max-image-preview' => 1, 'max-video-preview' => 1 ) ) );
		$this->assertSame( 'https://example.org/canonical/', Meta::post( $post, 'canonical' ) );
		$this->assertSame( 'FB title', Meta::post( $post, 'og_title' ) );
		$this->assertSame( 8, (int) Meta::post( $post, 'og_image_id' ) );
		$this->assertSame( 'X title', Meta::post( $post, 'twitter_title' ) );
		$this->assertSame( 'summary', Meta::post( $post, 'twitter_card' ) );
		$this->assertTrue( (bool) Meta::post( $post, 'pillar' ) );
		$this->assertSame( 'Ogródek', Meta::post( $post, 'breadcrumb_title' ) );
		$this->assertSame( 52, (int) Meta::post( $post, 'score' ) );
		$primary = (int) Meta::post( $post, 'primary_category' );
		$this->assertSame( 'Porady', get_term( $primary )->name );

		$head = $this->head_for( get_permalink( $post ) );
		$this->assertSame( 'Mały ogród | Porady | Studio', \MGSEO\Modules\Titles\Module::document_title() );
		$this->assertStringContainsString( 'content="Jak zaplanować ogród? Krótki poradnik."', $head );
		$robots = \MGSEO\Modules\Titles\Module::current_robots();
		$this->assertTrue( $robots['nofollow'] );
		$this->assertSame( '120', $robots['max-snippet'] );
	}

	public function test_robots_without_index_become_explicit_index(): void {
		$post = $this->rm_post( array( 'rank_math_robots' => array( 'nosnippet' ) ) );
		Runner::run( 'rankmath', array( 'posts' ) );
		$this->assertSame( array( 'index', 'nosnippet' ), Meta::post( $post, 'robots' ) );
	}

	public function test_seo_title_variable_in_own_title_is_not_recursive(): void {
		$post = $this->rm_post(
			array(
				'rank_math_title'       => '%seo_title%',
				'rank_math_description' => '%seo_description%',
			)
		);
		Runner::run( 'rankmath', array( 'posts' ) );
		$this->assertSame( '%title%', Meta::post( $post, 'title' ) );
		$this->assertSame( '%excerpt%', Meta::post( $post, 'description' ) );
	}

	public function test_existing_values_are_kept_without_overwrite(): void {
		$post = $this->rm_post();
		Meta::set_post( $post, 'title', 'Mine' );
		Runner::run( 'rankmath', array( 'posts' ) );
		$this->assertSame( 'Mine', Meta::post( $post, 'title' ) );
		$this->assertSame( 'FB title', Meta::post( $post, 'og_title' ), 'Other fields are still filled.' );
		Runner::run( 'rankmath', array( 'posts' ), array( 'overwrite' => true ) );
		$this->assertSame( '%title% %sep% %primary_category% %sep% %sitename%', Meta::post( $post, 'title' ) );
	}

	public function test_dry_run_writes_nothing(): void {
		$this->seed_options();
		$post   = $this->rm_post();
		$before = Options::all();
		$log    = Runner::run( 'rankmath', array(), array( 'dry_run' => true ) );
		Options::flush_cache();
		$this->assertSame( $before, Options::all() );
		$this->assertFalse( metadata_exists( 'post', $post, '_mgseo_title' ) );
		$this->assertTrue( $log['dry_run'] );
		$this->assertNotEmpty( $log['changes'] );
		$this->assertStringContainsString( 'title = %title%', implode( "\n", $log['changes'] ) );
	}

	public function test_batches_cover_all_posts(): void {
		$ids = array();
		for ( $i = 0; $i < 7; $i++ ) {
			$ids[] = $id = self::factory()->post->create();
			update_post_meta( $id, 'rank_math_title', 'T' . $i . ' %sep% %sitename%' );
		}
		$log = Runner::run( 'rankmath', array( 'posts' ), array( 'limit' => 2 ) );
		$this->assertSame( 7, $log['counts']['posts']['imported'] );
		foreach ( $ids as $i => $id ) {
			$this->assertSame( 'T' . $i . ' %sep% %sitename%', Meta::post( $id, 'title' ) );
		}
	}

	public function test_imports_terms_and_users(): void {
		$term = self::factory()->category->create( array( 'name' => 'Ogrody' ) );
		update_term_meta( $term, 'rank_math_title', '%term% – realizacje %sep% %sitename%' );
		update_term_meta( $term, 'rank_math_description', '%term_description% Zobacz.' );
		update_term_meta( $term, 'rank_math_robots', array( 'index', 'nofollow' ) );
		update_term_meta( $term, 'rank_math_canonical_url', 'https://example.org/category/ogrody/' );
		update_term_meta( $term, 'rank_math_facebook_title', 'Galeria' );

		$user = self::factory()->user->create( array( 'role' => 'author' ) );
		update_user_meta( $user, 'rank_math_title', '%name% %sep% %sitename%' );
		update_user_meta( $user, 'rank_math_robots', array( 'index' ) );
		update_user_meta( $user, 'twitter', 'annanowak' );
		update_user_meta( $user, 'facebook', 'https://www.facebook.com/anna' );
		update_user_meta( $user, 'additional_profile_urls', 'https://www.linkedin.com/in/anna%20https://www.instagram.com/anna' );

		Runner::run( 'rankmath', array( 'terms', 'users' ) );
		$this->assertSame( '%term% – realizacje %sep% %sitename%', Meta::term( $term, 'title' ) );
		$this->assertSame( array( 'index', 'nofollow' ), Meta::term( $term, 'robots' ) );
		$this->assertSame( 'https://example.org/category/ogrody/', Meta::term( $term, 'canonical' ) );
		$this->assertSame( 'Galeria', Meta::term( $term, 'og_title' ) );
		$this->assertSame( '%name% %sep% %sitename%', Meta::user( $user, 'title' ) );
		$this->assertSame( array( 'index' ), Meta::user( $user, 'robots' ) );
		$this->assertSame( 'annanowak', Meta::user( $user, 'social_twitter' ) );
		$this->assertSame( 'https://www.linkedin.com/in/anna', Meta::user( $user, 'social_linkedin' ) );
		$this->assertSame( 'https://www.instagram.com/anna', Meta::user( $user, 'social_instagram' ) );
	}

	/**
	 * Insert Rank Math redirection rows.
	 */
	private function seed_redirections(): void {
		global $wpdb;
		$rows = array(
			array( array( array( 'ignore' => '', 'pattern' => 'stara-oferta', 'comparison' => 'exact' ) ), 'http://example.org/oferta/', 301, 'active', 2, '2026-11-21 19:35:10' ),
			array( array( array( 'ignore' => '', 'pattern' => '^blog/(\d+)/(.*)$', 'comparison' => 'regex' ) ), 'http://example.org/$2/', 302, 'active', 0, '0000-00-00 00:00:00' ),
			array( array( array( 'ignore' => '', 'pattern' => 'stary-wpis', 'comparison' => 'exact' ) ), '', 410, 'active', 1, '0000-00-00 00:00:00' ),
			array( array( array( 'ignore' => 'case', 'pattern' => 'promocja', 'comparison' => 'start' ), array( 'ignore' => '', 'pattern' => 'sukulent-old', 'comparison' => 'exact' ) ), 'http://example.org/oferta/', 307, 'inactive', 0, '0000-00-00 00:00:00' ),
		);
		foreach ( $rows as $row ) {
			$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				$wpdb->prefix . 'rank_math_redirections',
				array(
					'sources'       => serialize( $row[0] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.serialize_serialize -- Rank Math's storage format.
					'url_to'        => $row[1],
					'header_code'   => $row[2],
					'status'        => $row[3],
					'hits'          => $row[4],
					'created'       => '2026-11-21 19:32:50',
					'updated'       => '2026-11-21 19:32:50',
					'last_accessed' => $row[5],
				)
			);
		}
	}

	public function test_imports_redirections_idempotently(): void {
		$this->seed_redirections();
		$log = Runner::run( 'rankmath', array( 'redirections' ) );
		$this->assertSame( 4, $log['counts']['redirections']['imported'] );
		$all = Repository::all();
		$this->assertCount( 4, $all );
		$by = array();
		foreach ( $all as $row ) {
			$by[ $row['sources'][0]['pattern'] ] = $row;
		}
		$this->assertSame( 301, (int) $by['stara-oferta']['type'] );
		$this->assertSame( 2, (int) $by['stara-oferta']['hits'] );
		$this->assertSame( 'regex', $by['^blog/(\d+)/(.*)$']['sources'][0]['comparison'] );
		$this->assertSame( 410, (int) $by['stary-wpis']['type'] );
		$this->assertSame( '', $by['stary-wpis']['url_to'] );
		$this->assertSame( 'inactive', $by['promocja']['status'] );
		$this->assertTrue( $by['promocja']['sources'][0]['ignore_case'] );
		$this->assertCount( 2, $by['promocja']['sources'] );

		$log = Runner::run( 'rankmath', array( 'redirections' ) );
		$this->assertSame( 4, $log['counts']['redirections']['duplicates'] );
		$this->assertCount( 4, Repository::all(), 'Re-running does not duplicate redirections.' );

		// Even without the id map (e.g. option deleted) the source lookup prevents duplicates.
		delete_option( 'mgseo_import_map' );
		Runner::run( 'rankmath', array( 'redirections' ) );
		$this->assertCount( 4, Repository::all() );
	}

	public function test_imports_404_log(): void {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
		$wpdb->insert( $wpdb->prefix . 'rank_math_404_logs', array( 'uri' => 'nie-ma-strony', 'accessed' => '2026-11-21 19:35:11', 'times_accessed' => 3, 'referer' => 'https://google.com/', 'user_agent' => 'UA' ) );
		Runner::run( 'rankmath', array( 'log404' ) );
		Runner::run( 'rankmath', array( 'log404' ) );
		$rows = \MGSEO\Modules\Redirections\Log404::all();
		$this->assertCount( 1, $rows );
		$this->assertSame( '/nie-ma-strony', $rows[0]['uri'] );
		$this->assertSame( 3, (int) $rows[0]['hits'] );
	}

	public function test_imports_schema(): void {
		$post    = self::factory()->post->create( array( 'post_content' => '<!-- wp:rank-math/faq-block {"questions":[{"id":"q1","title":"Ile kosztuje?","content":"Od 1500 zł.","visible":true},{"id":"q2","title":"Ukryte","content":"x","visible":false}]} --><div class="wp-block-rank-math-faq-block"></div><!-- /wp:rank-math/faq-block -->' ) );
		$product = array(
			'metadata' => array( 'type' => 'template', 'shortcode' => 's-abc', 'isPrimary' => '1', 'title' => 'Product' ),
			'@type'    => 'Product',
			'name'     => '%title%',
			'description' => '%seo_description%',
			'sku'      => 'KAKT-001',
			'brand'    => array( '@type' => 'Brand', 'name' => 'Kowalski' ),
			'image'    => array( '@type' => 'ImageObject', 'url' => '%post_thumbnail%' ),
			'offers'   => array( '@type' => 'Offer', 'url' => '%url%', 'price' => '49.99', 'priceCurrency' => 'PLN', 'availability' => 'InStock' ),
			'review'   => array( '@type' => 'Review', 'datePublished' => '%date(Y-m-dTH:i:sP)%', 'reviewRating' => array( '@type' => 'Rating' ) ),
		);
		$article = array(
			'metadata'       => array( 'type' => 'template', 'shortcode' => 's-def', 'isPrimary' => '1' ),
			'@type'          => 'NewsArticle',
			'headline'       => '%seo_title%',
			'articleSection' => '%primary_taxonomy_terms%',
			'author'         => array( '@type' => 'Person', 'name' => '%name%' ),
			'datePublished'  => '%date(Y-m-dTH:i:sP)%',
		);
		$custom = array(
			'metadata' => array( 'type' => 'custom', 'shortcode' => 's-ghi' ),
			'@type'    => 'MusicGroup',
			'name'     => 'Band',
		);
		update_post_meta( $post, 'rank_math_schema_Product', $product );
		update_post_meta( $post, 'rank_math_schema_NewsArticle', $article );
		update_post_meta( $post, 'rank_math_schema_MusicGroup', $custom );

		Runner::run( 'rankmath', array( 'schema' ) );
		$entities = \MGSEO\Modules\Schema\Entities::stored( $post );
		$by       = array();
		foreach ( $entities as $entity ) {
			$by[ $entity['type'] ] = $entity;
		}
		$this->assertSame( array( 'Product', 'Article', 'MusicGroup', 'FAQPage' ), array_keys( $by ) );
		$this->assertSame( 'rm-s-abc', $by['Product']['id'] );
		$this->assertSame( 'KAKT-001', $by['Product']['data']['sku'] );
		$this->assertArrayNotHasKey( 'image', $by['Product']['data'], 'Featured image placeholder is left to MG SEO.' );
		$this->assertArrayNotHasKey( 'reviewRating', $by['Product']['data']['review'], 'Empty rating is pruned.' );
		$this->assertSame( '%date(c)%', $by['Product']['data']['review']['datePublished'] );
		$this->assertSame( 'NewsArticle', $by['Article']['data']['@type'] );
		$this->assertSame( '%seo_title%', $by['Article']['data']['headline'], 'Schema keeps %seo_title%.' );
		$this->assertSame( '%primary_category%', $by['Article']['data']['articleSection'] );
		$this->assertArrayNotHasKey( 'author', $by['Article']['data'] );
		$this->assertArrayNotHasKey( 'datePublished', $by['Article']['data'] );
		$this->assertTrue( $by['MusicGroup']['custom'] );
		$this->assertCount( 1, $by['FAQPage']['data']['mainEntity'] );
		$this->assertSame( 'Od 1500 zł.', $by['FAQPage']['data']['mainEntity'][0]['acceptedAnswer']['text'] );

		$head  = $this->head_for( get_permalink( $post ) );
		$types = array();
		foreach ( $this->json_ld( $head ) as $graph ) {
			foreach ( $graph['@graph'] ?? array() as $node ) {
				$types[] = is_array( $node['@type'] ) ? implode( '+', $node['@type'] ) : $node['@type'];
			}
		}
		$this->assertContains( 'Product', $types );
		$this->assertContains( 'NewsArticle', $types );
		$this->assertContains( 'FAQPage', $types );

		// Re-run without overwrite keeps the stored list.
		$log = Runner::run( 'rankmath', array( 'schema' ) );
		$this->assertSame( 1, $log['counts']['schema']['skipped'] );
	}

	public function test_variable_conversion(): void {
		$log = new Log();
		$this->assertSame( '%title% %sep% %primary_category% %categories%', Converter::rank_math( '%title% %sep% %category% %categories(limit=3&separator= | )%', $log ) );
		$this->assertSame( '%date(c)% %modified(Y)%', Converter::rank_math( '%date(Y-m-dTH:i:sP)% %modified(Y)%', $log ) );
		$this->assertSame( '%foo_bar% %title%', Converter::rank_math( '%foo_bar% %title%', $log ) );
		$this->assertSame( array( '%foo_bar%' => 1 ), $log->to_array()['unknown'] );
		$this->assertSame( '%title% %sep% %term% %customfield(price)% %customterm_desc(color)%', Converter::yoast( '%%title%% %%sep%% %%term_title%% %%cf_price%% %%ct_desc_color%%', $log ) );
	}

	public function test_compat_variables_render(): void {
		$author = self::factory()->user->create();
		$parent = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Rodzic' ) );
		$child  = get_post( self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Dziecko', 'post_parent' => $parent, 'post_author' => $author ) ) );
		$this->assertSame( 'Rodzic', \MGSEO\Variables::replace( '%parent_title%', $child ) );
		$this->assertSame( (string) $child->post_author, \MGSEO\Variables::replace( '%userid%', $child ) );
		$this->assertMatchesRegularExpression( '/^\d{2}:\d{2}$/', \MGSEO\Variables::replace( '%currenttime(H:i)%', $child ) );
	}

	public function test_cleanup_removes_leftovers(): void {
		$this->seed_options();
		$this->seed_redirections();
		$post = $this->rm_post();
		Runner::run( 'rankmath' );
		$deleted = Runner::cleanup( 'rankmath' );
		$this->assertGreaterThan( 0, $deleted['postmeta'] );
		$this->assertGreaterThanOrEqual( 4, $deleted['options'] );
		$this->assertFalse( get_option( 'rank-math-options-titles' ) );
		$this->assertSame( '', get_post_meta( $post, 'rank_math_title', true ) );
		$this->assertNotSame( '', Meta::post( $post, 'title' ), 'MG SEO data stays.' );
		$this->assertSame( 0, array_sum( Runner::detect()['rankmath']['counts'] ) );
	}

	public function test_rest_routes_require_capability(): void {
		do_action( 'rest_api_init' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$request = new \WP_REST_Request( 'GET', '/mg-seo/v1/import/detect' );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $request )->get_status() );

		$cleanup = new \WP_REST_Request( 'POST', '/mg-seo/v1/import/cleanup' );
		$cleanup->set_body_params( array( 'source' => 'rankmath', 'confirm' => 'yes' ) );
		$this->assertSame( 400, rest_get_server()->dispatch( $cleanup )->get_status(), 'Cleanup needs the DELETE confirmation.' );

		$run = new \WP_REST_Request( 'POST', '/mg-seo/v1/import/run' );
		$run->set_body_params( array( 'source' => 'rankmath', 'scope' => 'settings', 'start' => true ) );
		$response = rest_get_server()->dispatch( $run );
		$this->assertSame( 200, $response->get_status() );
		$this->assertTrue( $response->get_data()['done'] );
	}
}
