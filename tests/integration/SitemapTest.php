<?php
/**
 * XML sitemap tests.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Meta;
use MGSEO\Modules\Sitemap\Builder;
use MGSEO\Modules\Sitemap\Cache;
use MGSEO\Modules\Sitemap\Module as Sitemap;
use MGSEO\Modules\Sitemap\Renderer;
use MGSEO\Modules\Sitemap\Stylesheet;
use MGSEO\Modules\Sitemap\Video;
use MGSEO\Plugin;

/**
 * @covers \MGSEO\Modules\Sitemap\Module
 * @covers \MGSEO\Modules\Sitemap\Builder
 * @covers \MGSEO\Modules\Sitemap\Renderer
 * @covers \MGSEO\Modules\Sitemap\Video
 */
class SitemapTest extends TestCase {

	/**
	 * Disable caching except where a test checks it.
	 */
	public function set_up(): void {
		parent::set_up();
		add_filter( 'mgseo/sitemap/cache_enabled', '__return_false' );
		update_option( 'show_on_front', 'posts' );
		update_option( 'blog_public', 1 );
	}

	/**
	 * Clean up.
	 */
	public function tear_down(): void {
		unregister_post_type( 'book' );
		parent::tear_down();
	}

	/**
	 * Parse XML and fail on errors.
	 *
	 * @param string|null $xml XML.
	 */
	private function dom( ?string $xml ): \DOMDocument {
		$this->assertIsString( $xml );
		$dom = new \DOMDocument();
		$this->assertTrue( $dom->loadXML( $xml ), 'XML must be well-formed' );
		return $dom;
	}

	/**
	 * <loc> values of a sitemap (direct children of url/sitemap only).
	 *
	 * @param string|null $xml XML.
	 * @return string[]
	 */
	private function locs( ?string $xml ): array {
		$xpath = new \DOMXPath( $this->dom( $xml ) );
		$xpath->registerNamespace( 's', Renderer::NS_SITEMAP );
		$locs = array();
		foreach ( $xpath->query( '/s:*/s:*/s:loc' ) as $node ) {
			$locs[] = $node->textContent;
		}
		return $locs;
	}

	public function test_core_sitemaps_disabled_and_module_booted(): void {
		$this->assertInstanceOf( Sitemap::class, Plugin::instance()->module( 'sitemap' ) );
		$this->assertFalse( (bool) apply_filters( 'wp_sitemaps_enabled', true ) );
		global $wp_rewrite;
		$rules = $wp_rewrite->wp_rewrite_rules();
		$this->assertArrayHasKey( '^sitemap_index\.xml$', $rules );
		$this->assertArrayHasKey( '^sitemap\.xsl$', $rules );
		$this->assertArrayHasKey( '^([a-z0-9_-]+?)-sitemap([0-9]+)?\.xml$', $rules );
	}

	public function test_paths_and_redirects(): void {
		$this->assertSame( array( 'index', 1 ), Sitemap::match_path( 'sitemap_index.xml' ) );
		$this->assertSame( array( 'post', 1 ), Sitemap::match_path( 'post-sitemap.xml' ) );
		$this->assertSame( array( 'my-type', 3 ), Sitemap::match_path( 'my-type-sitemap3.xml' ) );
		$this->assertSame( array( 'core', 1 ), Sitemap::match_path( 'wp-sitemap.xml' ) );
		$this->assertNull( Sitemap::match_path( 'about-us' ) );

		$module = Plugin::instance()->module( 'sitemap' );
		$this->assertSame( array( 'redirect' => home_url( '/sitemap_index.xml' ) ), $module->response( 'legacy' ) );
		$this->assertSame( array( 'redirect' => home_url( '/sitemap_index.xml' ) ), $module->response( 'core' ) );
		$this->assertNull( $module->response( 'nonexistent' ) );
		$this->assertFalse( $module->no_canonical_redirect( 'http://example.org/sitemap_index.xml/', 'http://example.org/sitemap_index.xml' ) );
	}

	public function test_index_lists_sitemaps(): void {
		$user = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create( array( 'post_author' => $user ) );
		self::factory()->post->create( array( 'post_type' => 'page' ) );
		$xml  = ( new Builder() )->xml( 'index' );
		$dom  = $this->dom( $xml );
		$locs = $this->locs( $xml );
		$this->assertSame( 'sitemapindex', $dom->documentElement->localName );
		$this->assertSame( Renderer::NS_SITEMAP, $dom->documentElement->namespaceURI );
		$this->assertContains( home_url( '/post-sitemap.xml' ), $locs );
		$this->assertContains( home_url( '/page-sitemap.xml' ), $locs );
		$this->assertContains( home_url( '/category-sitemap.xml' ), $locs );
		$this->assertContains( home_url( '/author-sitemap.xml' ), $locs );
		$this->assertNotContains( home_url( '/news-sitemap.xml' ), $locs );
		$this->assertNotContains( home_url( '/post_tag-sitemap.xml' ), $locs );
		$this->assertMatchesRegularExpression( '/<lastmod>\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00<\/lastmod>/', $xml );
		$this->assertStringContainsString( '<?xml-stylesheet type="text/xsl" href="/sitemap.xsl"?>', $xml );

		$this->set_option( 'sitemap', 'pt_page_include', false );
		$this->set_option( 'sitemap', 'stylesheet', false );
		$xml = ( new Builder() )->xml( 'index' );
		$this->assertNotContains( home_url( '/page-sitemap.xml' ), $this->locs( $xml ) );
		$this->assertStringNotContainsString( 'xml-stylesheet', $xml );
		$this->assertNull( Plugin::instance()->module( 'sitemap' )->response( 'xsl' ) );
	}

	public function test_post_sitemap_images_and_exclusions(): void {
		$post = self::factory()->post->create(
			array(
				'post_title'   => 'Visible',
				'post_content' => '<p>Text</p><img class="wp-image-5" src="/wp-content/uploads/zdjęcie 1.jpg" alt="a" /><img src="https://cdn.example.com/a.png?x=1&amp;y=2" />',
			)
		);
		$att = self::factory()->attachment->create_object( 'featured.jpg', $post, array( 'post_mime_type' => 'image/jpeg' ) );
		set_post_thumbnail( $post, $att );

		$noindex   = self::factory()->post->create( array( 'post_title' => 'Noindex' ) );
		$excluded  = self::factory()->post->create( array( 'post_title' => 'Excluded' ) );
		$password  = self::factory()->post->create( array( 'post_title' => 'Password', 'post_password' => 'secret' ) );
		$draft     = self::factory()->post->create( array( 'post_title' => 'Draft', 'post_status' => 'draft' ) );
		$canonical = self::factory()->post->create( array( 'post_title' => 'Canonical elsewhere' ) );
		$self_can  = self::factory()->post->create( array( 'post_title' => 'Canonical self' ) );
		$by_id     = self::factory()->post->create( array( 'post_title' => 'Excluded by id' ) );
		$by_filter = self::factory()->post->create( array( 'post_title' => 'Excluded by filter' ) );
		Meta::set_post( $noindex, 'robots', array( 'noindex', 'nofollow' ) );
		Meta::set_post( $excluded, 'sitemap_exclude', true );
		Meta::set_post( $canonical, 'canonical', 'https://other.example.com/page/' );
		Meta::set_post( $self_can, 'canonical', get_permalink( $self_can ) );
		$this->set_option( 'sitemap', 'exclude_posts', (string) $by_id );
		add_filter( 'mgseo/sitemap/exclude_post', static fn( $exclude, $id ) => $id === $by_filter ? true : $exclude, 10, 2 );

		$xml  = ( new Builder() )->xml( 'post' );
		$locs = $this->locs( $xml );
		$this->assertSame( home_url( '/' ), $locs[0], 'Homepage comes first when the front page shows posts' );
		$this->assertContains( get_permalink( $post ), $locs );
		$this->assertContains( get_permalink( $self_can ), $locs );
		foreach ( array( $noindex, $excluded, $password, $canonical, $by_id, $by_filter ) as $id ) {
			$this->assertNotContains( get_permalink( $id ), $locs, get_the_title( $id ) );
		}
		$this->assertStringNotContainsString( 'p=' . $draft, $xml );

		$dom = $this->dom( $xml );
		$this->assertSame( Renderer::NS_IMAGE, $dom->documentElement->lookupNamespaceURI( 'image' ) );
		$xpath = new \DOMXPath( $dom );
		$xpath->registerNamespace( 's', Renderer::NS_SITEMAP );
		$xpath->registerNamespace( 'image', Renderer::NS_IMAGE );
		$images = array();
		foreach ( $xpath->query( '//s:url[s:loc="' . get_permalink( $post ) . '"]/image:image/image:loc' ) as $node ) {
			$images[] = $node->textContent;
		}
		$this->assertSame(
			array(
				wp_get_attachment_url( $att ),
				home_url( '/wp-content/uploads/zdj%C4%99cie%201.jpg' ),
				'https://cdn.example.com/a.png?x=1&y=2',
			),
			$images
		);
		$this->assertStringContainsString( 'https://cdn.example.com/a.png?x=1&amp;y=2', $xml );

		$this->set_option( 'sitemap', 'include_featured', false );
		$this->assertStringNotContainsString( 'featured.jpg', ( new Builder() )->xml( 'post' ) );
		$this->set_option( 'sitemap', 'include_images', false );
		$xml = ( new Builder() )->xml( 'post' );
		$this->assertStringNotContainsString( '<image:image>', $xml );
		$this->assertStringNotContainsString( 'xmlns:image', $xml );
	}

	public function test_post_type_noindex_setting_and_index_override(): void {
		$a = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'A' ) );
		$b = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'B' ) );
		$this->set_option( 'titles', 'pt_page_robots', array( 'noindex' ) );
		Meta::set_post( $b, 'robots', array( 'index' ) );
		$locs = $this->locs( ( new Builder() )->xml( 'page' ) );
		$this->assertNotContains( get_permalink( $a ), $locs );
		$this->assertContains( get_permalink( $b ), $locs );
	}

	public function test_static_front_page_first_in_page_sitemap(): void {
		$other = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Older', 'post_date' => '2020-01-01 10:00:00' ) );
		$front = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Front' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );
		$locs = $this->locs( ( new Builder() )->xml( 'page' ) );
		$this->assertSame( home_url( '/' ), $locs[0] );
		$this->assertContains( get_permalink( $other ), $locs );
		$this->assertNotContains( home_url( '/' ), $this->locs( ( new Builder() )->xml( 'post' ) ?? '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"/>' ) );
	}

	public function test_pagination_split(): void {
		register_post_type( 'book', array( 'public' => true ) );
		$ids = array();
		for ( $i = 1; $i <= 5; $i++ ) {
			$ids[] = self::factory()->post->create(
				array(
					'post_type'  => 'book',
					'post_title' => 'Book ' . $i,
					'post_date'  => '2024-01-0' . $i . ' 10:00:00',
				)
			);
		}
		$this->set_option( 'sitemap', 'links_per_sitemap', 2 );
		$builder = new Builder();
		$index   = $this->locs( $builder->xml( 'index' ) );
		$this->assertContains( home_url( '/book-sitemap.xml' ), $index );
		$this->assertContains( home_url( '/book-sitemap2.xml' ), $index );
		$this->assertContains( home_url( '/book-sitemap3.xml' ), $index );
		$this->assertNotContains( home_url( '/book-sitemap4.xml' ), $index );

		$this->assertSame( array( get_permalink( $ids[0] ), get_permalink( $ids[1] ) ), $this->locs( $builder->xml( 'book', 1 ) ) );
		$this->assertSame( array( get_permalink( $ids[2] ), get_permalink( $ids[3] ) ), $this->locs( $builder->xml( 'book', 2 ) ) );
		$this->assertSame( array( get_permalink( $ids[4] ), ), $this->locs( $builder->xml( 'book', 3 ) ) );
		$this->assertNull( $builder->xml( 'book', 4 ) );

		$this->set_option( 'sitemap', 'pt_book_exclude', $ids[0] . ', ' . $ids[1] );
		$this->assertSame( array( get_permalink( $ids[2] ), get_permalink( $ids[3] ) ), $this->locs( ( new Builder() )->xml( 'book', 1 ) ) );
	}

	public function test_taxonomy_sitemap_hides_empty_and_noindex_terms(): void {
		$full    = self::factory()->category->create( array( 'name' => 'Full' ) );
		$empty   = self::factory()->category->create( array( 'name' => 'Empty' ) );
		$hidden  = self::factory()->category->create( array( 'name' => 'Hidden' ) );
		$skipped = self::factory()->category->create( array( 'name' => 'Skipped' ) );
		self::factory()->post->create( array( 'post_category' => array( $full, $hidden, $skipped ) ) );
		Meta::set_term( $hidden, 'robots', array( 'noindex' ) );
		$this->set_option( 'sitemap', 'exclude_terms', (string) $skipped );

		$locs = $this->locs( ( new Builder() )->xml( 'category' ) );
		$this->assertContains( get_term_link( $full ), $locs );
		$this->assertNotContains( get_term_link( $empty ), $locs );
		$this->assertNotContains( get_term_link( $hidden ), $locs );
		$this->assertNotContains( get_term_link( $skipped ), $locs );

		// Empty terms appear only when neither the sitemap nor the robots settings hide them.
		$this->set_option( 'sitemap', 'tax_category_hide_empty', false );
		$this->assertNotContains( get_term_link( $empty ), $this->locs( ( new Builder() )->xml( 'category' ) ) );
		$this->set_option( 'titles', 'noindex_empty_terms', false );
		$this->assertContains( get_term_link( $empty ), $this->locs( ( new Builder() )->xml( 'category' ) ) );

		// Tags are off by default; enabled tags still follow the taxonomy noindex default.
		$this->assertArrayNotHasKey( 'post_tag', ( new Builder() )->sitemaps() );
	}

	public function test_author_sitemap(): void {
		$author = self::factory()->user->create( array( 'role' => 'author' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		$none   = self::factory()->user->create( array( 'role' => 'author' ) );
		self::factory()->post->create( array( 'post_author' => $author ) );
		self::factory()->post->create( array( 'post_author' => $editor ) );
		$locs = $this->locs( ( new Builder() )->xml( 'author' ) );
		$this->assertContains( get_author_posts_url( $author ), $locs );
		$this->assertContains( get_author_posts_url( $editor ), $locs );
		$this->assertNotContains( get_author_posts_url( $none ), $locs );

		$this->set_option( 'sitemap', 'author_exclude_roles', array( 'editor' ) );
		$this->assertNotContains( get_author_posts_url( $editor ), $this->locs( ( new Builder() )->xml( 'author' ) ) );

		$this->set_option( 'titles', 'author_archive', false );
		$this->assertArrayNotHasKey( 'author', ( new Builder() )->sitemaps() );
	}

	public function test_news_sitemap_last_48_hours(): void {
		$this->set_option( 'sitemap', 'news', true );
		$this->set_option( 'sitemap', 'news_name', 'Daily <News> & Co' );
		$this->set_option( 'sitemap', 'news_language', 'pl' );
		$skip_cat = self::factory()->category->create( array( 'name' => 'Sponsored' ) );
		$fresh    = self::factory()->post->create( array( 'post_title' => 'Fresh & "new" story', 'post_date' => gmdate( 'Y-m-d H:i:s', time() - HOUR_IN_SECONDS ) ) );
		$old      = self::factory()->post->create( array( 'post_title' => 'Old', 'post_date' => gmdate( 'Y-m-d H:i:s', time() - 3 * DAY_IN_SECONDS ) ) );
		$opt_out  = self::factory()->post->create( array( 'post_title' => 'Opt out' ) );
		$in_cat   = self::factory()->post->create( array( 'post_title' => 'Sponsored post', 'post_category' => array( $skip_cat ) ) );
		$page     = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Page' ) );
		Meta::set_post( $opt_out, 'news_exclude', true );
		$this->set_option( 'sitemap', 'news_exclude_terms', array( (string) $skip_cat ) );

		$builder = new Builder();
		$this->assertContains( home_url( '/news-sitemap.xml' ), $this->locs( $builder->xml( 'index' ) ) );
		$xml  = $builder->xml( 'news' );
		$dom  = $this->dom( $xml );
		$locs = $this->locs( $xml );
		$this->assertSame( array( get_permalink( $fresh ) ), $locs );
		foreach ( array( $old, $opt_out, $in_cat, $page ) as $id ) {
			$this->assertNotContains( get_permalink( $id ), $locs );
		}
		$this->assertSame( Renderer::NS_NEWS, $dom->documentElement->lookupNamespaceURI( 'news' ) );
		$xpath = new \DOMXPath( $dom );
		$xpath->registerNamespace( 'n', Renderer::NS_NEWS );
		$this->assertSame( 'Daily <News> & Co', $xpath->evaluate( 'string(//n:news/n:publication/n:name)' ) );
		$this->assertSame( 'pl', $xpath->evaluate( 'string(//n:news/n:publication/n:language)' ) );
		$this->assertSame( 'Fresh & "new" story', $xpath->evaluate( 'string(//n:news/n:title)' ) );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\d\+00:00$/', $xpath->evaluate( 'string(//n:news/n:publication_date)' ) );
		$this->assertNull( $builder->xml( 'news', 2 ) );
	}

	public function test_video_sitemap_detects_embeds(): void {
		$this->set_option( 'sitemap', 'video', true );
		$yt = self::factory()->post->create(
			array(
				'post_title'   => 'With YouTube',
				'post_excerpt' => 'Watch the clip.',
				'post_content' => "<!-- wp:embed {\"url\":\"https://www.youtube.com/watch?v=dQw4w9WgXcQ\",\"type\":\"video\",\"providerNameSlug\":\"youtube\"} -->\n<figure class=\"wp-block-embed is-type-video\"><div class=\"wp-block-embed__wrapper\">\nhttps://www.youtube.com/watch?v=dQw4w9WgXcQ\n</div></figure>\n<!-- /wp:embed -->\n<p>Also https://youtu.be/dQw4w9WgXcQ again</p>",
			)
		);
		$tag   = self::factory()->post->create(
			array(
				'post_title'   => 'Self hosted',
				'post_content' => '<figure class="wp-block-video"><video controls poster="/wp-content/uploads/poster.jpg" src="/wp-content/uploads/clip.mp4"></video></figure>',
			)
		);
		$vimeo = self::factory()->post->create( array( 'post_title' => 'Vimeo without thumbnail', 'post_content' => 'https://vimeo.com/76979871' ) );
		$plain = self::factory()->post->create( array( 'post_title' => 'No video' ) );

		$builder = new Builder();
		$this->assertContains( home_url( '/video-sitemap.xml' ), $this->locs( $builder->xml( 'index' ) ) );
		$xml  = $builder->xml( 'video' );
		$dom  = $this->dom( $xml );
		$locs = $this->locs( $xml );
		$this->assertContains( get_permalink( $yt ), $locs );
		$this->assertContains( get_permalink( $tag ), $locs );
		$this->assertNotContains( get_permalink( $vimeo ), $locs, 'Vimeo needs a featured image as thumbnail' );
		$this->assertNotContains( get_permalink( $plain ), $locs );

		$this->assertSame( Renderer::NS_VIDEO, $dom->documentElement->lookupNamespaceURI( 'video' ) );
		$xpath = new \DOMXPath( $dom );
		$xpath->registerNamespace( 's', Renderer::NS_SITEMAP );
		$xpath->registerNamespace( 'v', Renderer::NS_VIDEO );
		$yt_videos = $xpath->query( '//s:url[s:loc="' . get_permalink( $yt ) . '"]/v:video' );
		$this->assertSame( 1, $yt_videos->length, 'Duplicate URLs of the same video are merged' );
		$this->assertSame( 'https://i.ytimg.com/vi/dQw4w9WgXcQ/hqdefault.jpg', $xpath->evaluate( 'string(v:thumbnail_loc)', $yt_videos->item( 0 ) ) );
		$this->assertSame( 'https://www.youtube.com/embed/dQw4w9WgXcQ', $xpath->evaluate( 'string(v:player_loc)', $yt_videos->item( 0 ) ) );
		$this->assertSame( 'With YouTube', $xpath->evaluate( 'string(v:title)', $yt_videos->item( 0 ) ) );
		$this->assertSame( 'Watch the clip.', $xpath->evaluate( 'string(v:description)', $yt_videos->item( 0 ) ) );
		$this->assertNotSame( '', $xpath->evaluate( 'string(v:publication_date)', $yt_videos->item( 0 ) ) );
		$own = $xpath->query( '//s:url[s:loc="' . get_permalink( $tag ) . '"]/v:video' )->item( 0 );
		$this->assertSame( home_url( '/wp-content/uploads/clip.mp4' ), $xpath->evaluate( 'string(v:content_loc)', $own ) );
		$this->assertSame( home_url( '/wp-content/uploads/poster.jpg' ), $xpath->evaluate( 'string(v:thumbnail_loc)', $own ) );

		// Element order required by the Google video schema.
		$names = array();
		foreach ( $yt_videos->item( 0 )->childNodes as $child ) {
			if ( XML_ELEMENT_NODE === $child->nodeType ) {
				$names[] = $child->localName;
			}
		}
		$this->assertSame( array( 'thumbnail_loc', 'title', 'description', 'player_loc', 'publication_date' ), $names );
	}

	public function test_video_from_schema_meta(): void {
		$this->set_option( 'sitemap', 'video', true );
		$this->set_option( 'sitemap', 'video_detect', false );
		$post = self::factory()->post->create( array( 'post_title' => 'Schema video' ) );
		Meta::set_post(
			$post,
			'schema',
			wp_json_encode(
				array(
					array(
						'id'     => 'abc',
						'type'   => 'VideoObject',
						'custom' => false,
						'data'   => array(
							'name'         => '%title% clip',
							'description'  => 'Manual description',
							'thumbnailUrl' => 'https://cdn.example.com/thumb.jpg',
							'contentUrl'   => 'https://cdn.example.com/video.mp4',
							'duration'     => 'PT1M30S',
							'uploadDate'   => '2025-05-01T10:00:00+02:00',
						),
					),
				)
			)
		);
		$videos = Video::for_post( get_post( $post ), false );
		$this->assertCount( 1, $videos );
		$this->assertSame( 'Schema video clip', $videos[0]['title'] );
		$this->assertSame( 90, $videos[0]['duration'] );
		$this->assertSame( '2025-05-01T08:00:00+00:00', $videos[0]['publication_date'] );
		$xml = ( new Builder() )->xml( 'video' );
		$this->assertStringContainsString( '<video:content_loc>https://cdn.example.com/video.mp4</video:content_loc>', $xml );
		$this->assertStringContainsString( '<video:duration>90</video:duration>', $xml );
	}

	public function test_entry_filter_can_modify_and_skip(): void {
		$keep = self::factory()->post->create( array( 'post_title' => 'Keep' ) );
		$drop = self::factory()->post->create( array( 'post_title' => 'Drop' ) );
		add_filter(
			'mgseo/sitemap/entry',
			static function ( $entry, $name, $object ) use ( $drop ) {
				if ( $object instanceof \WP_Post && $object->ID === $drop ) {
					return false;
				}
				if ( null === $object ) {
					$entry['loc'] = home_url( '/?home=1' );
				}
				return $entry;
			},
			10,
			3
		);
		add_filter( 'mgseo/sitemap/post_types', static fn( $types ) => array_diff( $types, array( 'page' ) ) );
		$builder = new Builder();
		$locs    = $this->locs( $builder->xml( 'post' ) );
		$this->assertContains( get_permalink( $keep ), $locs );
		$this->assertNotContains( get_permalink( $drop ), $locs );
		$this->assertSame( home_url( '/?home=1' ), $locs[0] );
		$this->assertArrayNotHasKey( 'page', $builder->sitemaps() );
	}

	public function test_robots_txt_line(): void {
		$line   = 'Sitemap: ' . home_url( '/sitemap_index.xml' );
		$output = apply_filters( 'robots_txt', "User-agent: *\nDisallow: /wp-admin/\n", true );
		$this->assertSame( 1, substr_count( $output, $line ) );
		$this->assertSame( 1, substr_count( apply_filters( 'robots_txt', $output, true ), $line ), 'Not duplicated' );
		$this->assertStringNotContainsString( 'Sitemap:', apply_filters( 'robots_txt', "User-agent: *\nDisallow: /\n", false ) );
		$this->assertStringNotContainsString( 'wp-sitemap', $output );
	}

	public function test_cache_invalidation(): void {
		remove_filter( 'mgseo/sitemap/cache_enabled', '__return_false' );
		$first = self::factory()->post->create( array( 'post_title' => 'First' ) );
		$xml   = ( new Builder() )->xml( 'post' );
		$this->assertContains( get_permalink( $first ), $this->locs( $xml ) );
		$this->assertSame( $xml, ( new Builder() )->xml( 'post' ), 'Served from cache' );

		$second = self::factory()->post->create( array( 'post_title' => 'Second' ) );
		$this->assertContains( get_permalink( $second ), $this->locs( ( new Builder() )->xml( 'post' ) ), 'Invalidated on publish' );

		Meta::set_post( $second, 'robots', array( 'noindex' ) );
		$this->assertNotContains( get_permalink( $second ), $this->locs( ( new Builder() )->xml( 'post' ) ), 'Invalidated on meta change' );

		$first_url = get_permalink( $first );
		wp_delete_post( $first, true );
		$this->assertNotContains( $first_url, $this->locs( ( new Builder() )->xml( 'post' ) ), 'Invalidated on delete' );

		$version = Cache::version();
		( new Builder() )->xml( 'index' );
		$this->set_option( 'sitemap', 'links_per_sitemap', 100 );
		$this->assertNotSame( $version, Cache::version(), 'Invalidated on settings change' );
		$cat = self::factory()->category->create( array( 'name' => 'Before' ) );
		self::factory()->post->create( array( 'post_category' => array( $cat ) ) );
		( new Builder() )->xml( 'category' );
		wp_update_term( $cat, 'category', array( 'slug' => 'after' ) );
		$this->assertStringContainsString( '/category/after/', ( new Builder() )->xml( 'category' ) );
	}

	public function test_renderer_escaping_and_no_branding(): void {
		$xml = Renderer::urlset(
			array(
				array(
					'loc'     => 'https://example.org/a?b=1&c=<2>',
					'lastmod' => Renderer::date( '2025-01-02 03:04:05' ),
					'images'  => array( 'https://example.org/ż ó.jpg' ),
				),
			)
		);
		$dom = $this->dom( $xml );
		$this->assertSame( 'urlset', $dom->documentElement->localName );
		$this->assertStringContainsString( '<loc>https://example.org/a?b=1&amp;c=&lt;2&gt;</loc>', $xml );
		$this->assertStringContainsString( '<lastmod>2025-01-02T03:04:05+00:00</lastmod>', $xml );
		$this->assertStringContainsString( 'https://example.org/%C5%BC%20%C3%B3.jpg', $xml );
		$this->assertSame( '', Renderer::date( '0000-00-00 00:00:00' ) );
		$this->assertSame( 'a b', Renderer::esc( "a\x01 b" ) );

		$xsl = Stylesheet::xsl();
		$this->dom( $xsl );
		$this->assertStringContainsString( '<title>XML Sitemap</title>', $xsl );
		self::factory()->post->create();
		foreach ( array( $xsl, ( new Builder() )->xml( 'index' ), ( new Builder() )->xml( 'post' ) ) as $doc ) {
			$this->assertDoesNotMatchRegularExpression( '/mg-?seo|rank-?math|yoast|<!--/i', $doc );
		}
	}
}
