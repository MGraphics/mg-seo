<?php
/**
 * Titles & Meta tests.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Meta;
use MGSEO\Modules\Titles\Module as Titles;

/**
 * @covers \MGSEO\Modules\Titles\Module
 */
class TitlesTest extends TestCase {

	public function test_default_post_title_and_description(): void {
		update_option( 'blogname', 'Studio' );
		$post = self::factory()->post->create_and_get(
			array(
				'post_title'   => 'Hello world',
				'post_excerpt' => '',
				'post_content' => '<!-- wp:paragraph --><p>First paragraph.</p><!-- /wp:paragraph --><p>Second.</p>',
			)
		);
		$head = $this->head_for( get_permalink( $post ) );
		$this->assertSame( 'Hello world - Studio', Titles::document_title() );
		$this->assertStringContainsString( '<meta name="description" content="First paragraph. Second." />', $head );
		$this->assertStringContainsString( '<link rel="canonical" href="' . get_permalink( $post ) . '" />', $head );
	}

	public function test_custom_title_with_variables_and_separator(): void {
		update_option( 'blogname', 'Studio' );
		$this->set_option( 'titles', 'separator', '|' );
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Oferta' ) );
		Meta::set_post( $post->ID, 'title', '%title% %sep% %currentyear% %sep% %sitename%' );
		$this->go_to( get_permalink( $post ) );
		$this->assertSame( 'Oferta | ' . wp_date( 'Y' ) . ' | Studio', Titles::document_title() );
	}

	public function test_empty_variables_do_not_leave_dangling_separators(): void {
		update_option( 'blogname', 'Studio' );
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'X', 'post_excerpt' => '' ) );
		Meta::set_post( $post->ID, 'title', '%title% %sep% %category_missing% %sep% %excerpt_only% %sep% %sitename%' );
		$this->go_to( get_permalink( $post ) );
		$this->assertSame( 'X - %category_missing% - Studio', Titles::document_title() );
	}

	public function test_robots_defaults_and_overrides(): void {
		$post = self::factory()->post->create_and_get();
		$this->go_to( get_permalink( $post ) );
		$robots = Titles::current_robots();
		$this->assertTrue( $robots['index'] );
		$this->assertSame( 'large', $robots['max-image-preview'] );

		Meta::set_post( $post->ID, 'robots', array( 'noindex', 'nofollow' ) );
		$this->go_to( get_permalink( $post ) );
		$robots = Titles::current_robots();
		$this->assertTrue( $robots['noindex'] );
		$this->assertTrue( $robots['nofollow'] );
		$this->assertArrayNotHasKey( 'max-snippet', $robots );
		$this->assertStringNotContainsString( 'rel="canonical"', \MGSEO\Head::render(), 'No canonical on noindex pages' );
	}

	public function test_post_type_noindex_can_be_overridden_per_post(): void {
		$this->set_option( 'titles', 'pt_page_robots', array( 'noindex' ) );
		$page = self::factory()->post->create_and_get( array( 'post_type' => 'page' ) );
		$this->go_to( get_permalink( $page ) );
		$this->assertTrue( Titles::current_robots()['noindex'] );
		Meta::set_post( $page->ID, 'robots', array( 'index' ) );
		$this->go_to( get_permalink( $page ) );
		$this->assertTrue( Titles::current_robots()['index'] );
	}

	public function test_wp_robots_output(): void {
		$post = self::factory()->post->create_and_get();
		$this->go_to( get_permalink( $post ) );
		$html = get_echo( 'wp_robots' );
		$this->assertStringContainsString( 'index, follow, max-snippet:-1, max-video-preview:-1, max-image-preview:large', $html );
	}

	public function test_search_and_404_are_noindex(): void {
		$this->go_to( home_url( '/?s=test' ) );
		$this->assertTrue( Titles::current_robots()['noindex'] );
		$this->go_to( home_url( '/does-not-exist-xyz/' ) );
		$this->assertTrue( is_404() );
		$this->assertTrue( Titles::current_robots()['noindex'] );
		$this->assertStringContainsString( 'Page not found', Titles::document_title() );
	}

	public function test_empty_term_noindex_and_term_meta(): void {
		$term = self::factory()->category->create_and_get( array( 'name' => 'Empty cat' ) );
		$this->go_to( get_term_link( $term ) );
		$this->assertTrue( Titles::current_robots()['noindex'] );

		$post = self::factory()->post->create();
		wp_set_post_categories( $post, array( $term->term_id ) );
		Meta::set_term( $term->term_id, 'title', 'Custom %term%' );
		Meta::set_term( $term->term_id, 'description', 'Cat description' );
		$head = $this->head_for( get_term_link( $term ) );
		$this->assertFalse( Titles::current_robots()['noindex'] );
		$this->assertSame( 'Custom Empty cat', Titles::document_title() );
		$this->assertStringContainsString( 'content="Cat description"', $head );
	}

	public function test_canonical_custom_and_paged_archive(): void {
		$post = self::factory()->post->create_and_get();
		Meta::set_post( $post->ID, 'canonical', 'https://example.com/original/' );
		$this->go_to( get_permalink( $post ) );
		$this->assertSame( 'https://example.com/original/', Titles::current_canonical() );

		$cat = self::factory()->category->create_and_get( array( 'slug' => 'news' ) );
		self::factory()->post->create_many( 12, array( 'post_category' => array( $cat->term_id ) ) );
		update_option( 'posts_per_page', 5 );
		$this->go_to( get_term_link( $cat ) . 'page/2/' );
		$this->assertSame( get_term_link( $cat ) . 'page/2/', Titles::current_canonical() );
		$head = \MGSEO\Head::render();
		$this->assertStringContainsString( 'rel="prev"', $head );
		$this->assertStringContainsString( 'rel="next"', $head );
	}

	public function test_verification_only_on_front_page(): void {
		$this->set_option( 'titles', 'verify_google', '<meta name="google-site-verification" content="abc123" />' );
		$head = $this->head_for( home_url( '/' ) );
		$this->assertStringContainsString( '<meta name="google-site-verification" content="abc123" />', $head );
		$post = self::factory()->post->create();
		$this->assertStringNotContainsString( 'google-site-verification', $this->head_for( get_permalink( $post ) ) );
	}

	public function test_no_html_comments_or_plugin_names_in_head(): void {
		$post = self::factory()->post->create();
		$head = $this->head_for( get_permalink( $post ) );
		$this->assertStringNotContainsString( '<!--', $head );
		$this->assertDoesNotMatchRegularExpression( '/mg-?seo|mgseo|rank-?math|yoast/i', $head );
	}

	public function test_explicit_index_choice_replaces_post_type_directives(): void {
		$this->set_option( 'titles', 'pt_page_robots', array( 'nofollow', 'noarchive' ) );
		$page = self::factory()->post->create_and_get( array( 'post_type' => 'page' ) );
		$this->go_to( get_permalink( $page ) );
		$robots = Titles::current_robots();
		$this->assertTrue( $robots['nofollow'] );
		$this->assertTrue( $robots['noarchive'] );

		Meta::set_post( $page->ID, 'robots', array( 'index', 'nosnippet' ) );
		$this->go_to( get_permalink( $page ) );
		$robots = Titles::current_robots();
		$this->assertTrue( $robots['follow'] );
		$this->assertArrayNotHasKey( 'noarchive', $robots );
		$this->assertTrue( $robots['nosnippet'] );
	}

	public function test_password_protected_stays_noindex_even_with_explicit_index(): void {
		$post = self::factory()->post->create_and_get( array( 'post_password' => 'secret' ) );
		Meta::set_post( $post->ID, 'robots', array( 'index' ) );
		$this->go_to( get_permalink( $post ) );
		$this->assertTrue( Titles::current_robots()['noindex'] );
	}

	public function test_term_open_graph_overrides(): void {
		$term = self::factory()->category->create_and_get( array( 'name' => 'Ogrody' ) );
		self::factory()->post->create( array( 'post_category' => array( $term->term_id ) ) );
		Meta::set_term( $term->term_id, 'og_title', 'OG %term%' );
		$head = $this->head_for( get_term_link( $term ) );
		$this->assertStringContainsString( '<meta property="og:title" content="OG Ogrody" />', $head );
	}
}
