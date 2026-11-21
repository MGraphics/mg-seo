<?php
/**
 * Yoast SEO importer tests (fixture rows mirror Yoast's stored format).
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Meta;
use MGSEO\Modules\Import\Log;
use MGSEO\Modules\Import\Runner;
use MGSEO\Modules\Redirections\Repository;
use MGSEO\Options;

/**
 * @covers \MGSEO\Modules\Import\Yoast
 */
class ImportYoastTest extends TestCase {

	/**
	 * Reset.
	 */
	public function set_up(): void {
		parent::set_up();
		global $wpdb;
		$wpdb->query( "TRUNCATE TABLE {$wpdb->prefix}mgseo_redirections" ); // phpcs:ignore WordPress.DB
		delete_option( Log::OPTION );
	}

	public function test_yoast_code_is_not_loaded(): void {
		$this->assertFalse( defined( 'WPSEO_VERSION' ) );
		$this->assertFalse( class_exists( 'WPSEO_Options' ) );
	}

	public function test_imports_settings(): void {
		update_option(
			'wpseo_titles',
			array(
				'separator'                 => 'sc-pipe',
				'title-home-wpseo'          => '%%sitename%% %%page%% %%sep%% %%sitedesc%%',
				'metadesc-home-wpseo'       => 'Strona %%sitename%%',
				'title-post'                => '%%title%% %%page%% %%sep%% %%sitename%%',
				'metadesc-post'             => '%%excerpt%%',
				'noindex-post'              => false,
				'title-page'                => '%%title%% %%sep%% %%sitename%%',
				'noindex-page'              => false,
				'schema-article-type-post'  => 'NewsArticle',
				'schema-article-type-page'  => 'None',
				'title-tax-category'        => '%%term_title%% Archives %%page%% %%sep%% %%sitename%%',
				'metadesc-tax-category'     => '%%term_description%%',
				'noindex-tax-category'      => false,
				'noindex-tax-post_tag'      => true,
				'title-tax-post_tag'        => '%%term_title%%',
				'title-author-wpseo'        => '%%name%%, Author at %%sitename%% %%page%%',
				'noindex-author-wpseo'      => true,
				'title-archive-wpseo'       => '%%date%% %%page%% %%sep%% %%sitename%%',
				'noindex-archive-wpseo'     => true,
				'disable-date'              => true,
				'title-search-wpseo'        => 'You searched for %%searchphrase%% %%page%% %%sep%% %%sitename%%',
				'title-404-wpseo'           => 'Page not found %%sep%% %%sitename%%',
				'disable-attachment'        => true,
				'rssafter'                  => 'The post %%POSTLINK%% appeared first on %%BLOGLINK%%.',
				'breadcrumbs-enable'        => true,
				'breadcrumbs-sep'           => '&raquo;',
				'breadcrumbs-home'          => 'Start',
				'breadcrumbs-archiveprefix' => 'Archives for',
				'breadcrumbs-searchprefix'  => 'You searched for',
				'breadcrumbs-404crumb'      => 'Error 404: Page not found',
				'company_or_person'         => 'company',
				'company_name'              => 'Firma Sp. z o.o.',
				'company_logo'              => 'https://example.org/logo.png',
				'website_name'              => 'Firma',
			)
		);
		update_option(
			'wpseo_social',
			array(
				'facebook_site'     => 'https://www.facebook.com/firma',
				'twitter_site'      => '@firma',
				'og_default_image'  => 'https://example.org/og.jpg',
				'twitter_card_type' => 'summary',
				'other_social_urls' => array( 'https://www.instagram.com/firma' ),
			)
		);
		update_option(
			'wpseo',
			array(
				'googleverify'       => 'g-123',
				'msverify'           => 'ms-456',
				'enable_xml_sitemap' => true,
			)
		);
		$log = Runner::run( 'yoast', array( 'settings' ) );
		Options::flush_cache();
		$this->assertGreaterThan( 20, $log['counts']['settings']['imported'] );
		$this->assertSame( '|', Options::get( 'titles', 'separator' ) );
		$this->assertSame( '%sitename% %page% %sep% %sitedesc%', Options::get( 'titles', 'home_title' ) );
		$this->assertSame( '%title% %page% %sep% %sitename%', Options::get( 'titles', 'pt_post_title' ) );
		$this->assertSame( '%excerpt%', Options::get( 'titles', 'pt_post_description' ) );
		$this->assertSame( '%term% Archives %page% %sep% %sitename%', Options::get( 'titles', 'tax_category_title' ) );
		$this->assertSame( array( 'noindex' ), Options::get( 'titles', 'tax_post_tag_robots' ) );
		$this->assertSame( array(), Options::get( 'titles', 'tax_category_robots' ) );
		$this->assertSame( array( 'noindex' ), Options::get( 'titles', 'author_robots' ) );
		$this->assertFalse( Options::get( 'titles', 'date_archive' ) );
		$this->assertSame( 'You searched for %search_query% %page% %sep% %sitename%', Options::get( 'titles', 'search_title' ) );
		$this->assertSame( 'The post %postlink% appeared first on %sitelink%.', Options::get( 'titles', 'rss_after' ) );
		$this->assertSame( 'g-123', Options::get( 'titles', 'verify_google' ) );
		$this->assertSame( 'ms-456', Options::get( 'titles', 'verify_bing' ) );
		$this->assertSame( 'firma', Options::get( 'social', 'twitter_site' ) );
		$this->assertSame( 'summary', Options::get( 'social', 'twitter_card' ) );
		$this->assertSame( 'https://example.org/og.jpg', Options::get( 'social', 'default_image' ) );
		$this->assertSame( 'Firma Sp. z o.o.', Options::get( 'schema', 'org_name' ) );
		$this->assertSame( array( 'https://www.instagram.com/firma' ), Options::get( 'schema', 'same_as' ) );
		$this->assertSame( 'NewsArticle', Options::get( 'schema', 'article_type' ) );
		$this->assertSame( '', Options::get( 'schema', 'pt_page_type' ) );
		$this->assertSame( '»', Options::get( 'breadcrumbs', 'separator' ) );
		$this->assertSame( 'Archives for %s', Options::get( 'breadcrumbs', 'archive_format' ) );
	}

	public function test_imports_post_term_and_user_meta(): void {
		$cat  = self::factory()->category->create();
		$post = self::factory()->post->create( array( 'post_category' => array( $cat ) ) );
		$meta = array(
			'_yoast_wpseo_title'                 => '%%title%% %%sep%% %%primary_category%% %%sep%% %%sitename%%',
			'_yoast_wpseo_metadesc'              => 'Opis %%excerpt%%',
			'_yoast_wpseo_focuskw'               => 'ogród',
			'_yoast_wpseo_focuskeywords'         => wp_json_encode( array( array( 'keyword' => 'trawnik', 'score' => 50 ) ) ),
			'_yoast_wpseo_meta-robots-noindex'   => '1',
			'_yoast_wpseo_meta-robots-nofollow'  => '1',
			'_yoast_wpseo_meta-robots-adv'       => 'noimageindex,noarchive',
			'_yoast_wpseo_canonical'             => 'https://example.org/c/',
			'_yoast_wpseo_opengraph-title'       => 'OG',
			'_yoast_wpseo_opengraph-image'       => 'https://example.org/og.jpg',
			'_yoast_wpseo_opengraph-image-id'    => '12',
			'_yoast_wpseo_twitter-title'         => 'TW',
			'_yoast_wpseo_primary_category'      => (string) $cat,
			'_yoast_wpseo_is_cornerstone'        => '1',
			'_yoast_wpseo_bctitle'               => 'Krótko',
			'_yoast_wpseo_linkdex'               => '74',
		);
		foreach ( $meta as $key => $value ) {
			update_post_meta( $post, $key, $value );
		}
		$index = self::factory()->post->create();
		update_post_meta( $index, '_yoast_wpseo_meta-robots-noindex', '2' );

		update_option(
			'wpseo_taxonomy_meta',
			array(
				'category' => array(
					$cat => array(
						'wpseo_title'     => '%%term_title%% %%sep%% %%sitename%%',
						'wpseo_desc'      => 'Kategoria %%term_description%%',
						'wpseo_noindex'   => 'noindex',
						'wpseo_canonical' => 'https://example.org/cat/',
						'wpseo_focuskw'   => 'kategoria',
					),
				),
			)
		);
		$user = self::factory()->user->create();
		update_user_meta( $user, 'wpseo_title', '%%name%% %%sep%% %%sitename%%' );
		update_user_meta( $user, 'wpseo_noindex_author', 'on' );
		update_user_meta( $user, 'linkedin', 'https://www.linkedin.com/in/u' );

		Runner::run( 'yoast', array( 'posts', 'terms', 'users' ) );

		$this->assertSame( '%title% %sep% %primary_category% %sep% %sitename%', Meta::post( $post, 'title' ) );
		$this->assertSame( 'ogród,trawnik', Meta::post( $post, 'focus_keyword' ) );
		$this->assertSame( array( 'noindex', 'nofollow', 'noimageindex', 'noarchive' ), Meta::post( $post, 'robots' ) );
		$this->assertSame( array( 'index' ), Meta::post( $index, 'robots' ) );
		$this->assertSame( 'https://example.org/c/', Meta::post( $post, 'canonical' ) );
		$this->assertSame( 12, (int) Meta::post( $post, 'og_image_id' ) );
		$this->assertSame( 'TW', Meta::post( $post, 'twitter_title' ) );
		$this->assertSame( $cat, (int) Meta::post( $post, 'primary_category' ) );
		$this->assertTrue( (bool) Meta::post( $post, 'pillar' ) );
		$this->assertSame( 'Krótko', Meta::post( $post, 'breadcrumb_title' ) );
		$this->assertSame( 74, (int) Meta::post( $post, 'score' ) );

		$this->assertSame( '%term% %sep% %sitename%', Meta::term( $cat, 'title' ) );
		$this->assertSame( array( 'noindex' ), Meta::term( $cat, 'robots' ) );
		$this->assertSame( 'kategoria', Meta::term( $cat, 'focus_keyword' ) );

		$this->assertSame( '%name% %sep% %sitename%', Meta::user( $user, 'title' ) );
		$this->assertSame( array( 'noindex' ), Meta::user( $user, 'robots' ) );
		$this->assertSame( 'https://www.linkedin.com/in/u', Meta::user( $user, 'social_linkedin' ) );
	}

	public function test_imports_premium_redirects_once(): void {
		update_option(
			'wpseo-premium-redirects-base',
			array(
				array( 'origin' => 'old-page', 'url' => '/new-page/', 'type' => 301, 'format' => 'plain' ),
				array( 'origin' => '^cat/(.*)$', 'url' => '/category/$1/', 'type' => 302, 'format' => 'regex' ),
				array( 'origin' => 'gone', 'url' => '', 'type' => 410, 'format' => 'plain' ),
			)
		);
		Runner::run( 'yoast', array( 'redirections' ) );
		Runner::run( 'yoast', array( 'redirections' ) );
		$rows = Repository::all();
		$this->assertCount( 3, $rows );
		$types = wp_list_pluck( $rows, 'type' );
		sort( $types );
		$this->assertSame( array( 301, 302, 410 ), array_map( 'intval', $types ) );
	}

	public function test_cleanup(): void {
		update_option( 'wpseo_titles', array( 'separator' => 'sc-dash' ) );
		$post = self::factory()->post->create();
		update_post_meta( $post, '_yoast_wpseo_title', 'X' );
		$deleted = Runner::cleanup( 'yoast' );
		$this->assertSame( 1, $deleted['postmeta'] );
		$this->assertFalse( get_option( 'wpseo_titles' ) );
	}
}
