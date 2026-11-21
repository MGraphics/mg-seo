<?php
/**
 * Social and breadcrumbs tests.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Meta;
use MGSEO\Modules\Breadcrumbs\Module as Breadcrumbs;

/**
 * @covers \MGSEO\Modules\Social\Module
 * @covers \MGSEO\Modules\Breadcrumbs\Module
 */
class SocialBreadcrumbsTest extends TestCase {

	public function test_open_graph_and_twitter_overrides(): void {
		$this->set_option( 'social', 'twitter_site', '@mgraphics' );
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Post' ) );
		Meta::set_post( $post->ID, 'og_title', 'OG %title%' );
		Meta::set_post( $post->ID, 'twitter_title', 'TW title' );
		Meta::set_post( $post->ID, 'og_image', 'https://cdn.example.com/share.jpg' );
		$head = $this->head_for( get_permalink( $post ) );
		$this->assertStringContainsString( '<meta property="og:type" content="article" />', $head );
		$this->assertStringContainsString( '<meta property="og:title" content="OG Post" />', $head );
		$this->assertStringContainsString( '<meta property="og:image" content="https://cdn.example.com/share.jpg" />', $head );
		$this->assertStringContainsString( '<meta property="og:image:secure_url" content="https://cdn.example.com/share.jpg" />', $head );
		$this->assertStringContainsString( '<meta name="twitter:title" content="TW title" />', $head );
		$this->assertStringContainsString( '<meta name="twitter:site" content="@mgraphics" />', $head );
	}

	public function test_default_image_and_website_type_on_home(): void {
		$this->set_option( 'social', 'default_image', 'https://example.org/default.png' );
		$head = $this->head_for( home_url( '/' ) );
		$this->assertStringContainsString( '<meta property="og:type" content="website" />', $head );
		$this->assertStringContainsString( 'og:image" content="https://example.org/default.png"', $head );
	}

	public function test_post_trail_uses_primary_category_with_parents(): void {
		$parent = self::factory()->category->create( array( 'name' => 'Usługi' ) );
		$child  = self::factory()->category->create( array( 'name' => 'Strony', 'parent' => $parent ) );
		$other  = self::factory()->category->create( array( 'name' => 'Aaa' ) );
		$post   = self::factory()->post->create( array( 'post_title' => 'Wpis', 'post_category' => array( $other, $child ) ) );
		Meta::set_post( $post, 'primary_category', $child );
		$this->go_to( get_permalink( $post ) );
		$this->assertSame( array( 'Home', 'Usługi', 'Strony', 'Wpis' ), wp_list_pluck( Breadcrumbs::trail(), 'text' ) );
		$html = Breadcrumbs::html();
		$this->assertStringContainsString( '<nav class="breadcrumbs" aria-label="Breadcrumbs">', $html );
		$this->assertStringContainsString( '<span aria-current="page">Wpis</span>', $html );
		$schema = Breadcrumbs::schema();
		$this->assertSame( 'BreadcrumbList', $schema['@type'] );
		$this->assertCount( 4, $schema['itemListElement'] );
		$this->assertSame( 4, $schema['itemListElement'][3]['position'] );
	}

	public function test_page_trail_and_compat_shortcodes(): void {
		$parent = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Oferta' ) );
		$child  = self::factory()->post->create( array( 'post_type' => 'page', 'post_title' => 'Sklepy', 'post_parent' => $parent ) );
		Meta::set_post( $child, 'breadcrumb_title', 'Sklepy internetowe' );
		$this->go_to( get_permalink( $child ) );
		$this->assertSame( array( 'Home', 'Oferta', 'Sklepy internetowe' ), wp_list_pluck( Breadcrumbs::trail(), 'text' ) );
		$this->assertTrue( shortcode_exists( 'mgseo_breadcrumbs' ) );
		$this->assertTrue( shortcode_exists( 'rank_math_breadcrumb' ) );
		$this->assertTrue( function_exists( 'rank_math_the_breadcrumbs' ) );
		$this->assertTrue( function_exists( 'yoast_breadcrumb' ) );
		$this->assertStringContainsString( 'Sklepy internetowe', do_shortcode( '[rank_math_breadcrumb]' ) );
	}
}
