<?php
/**
 * Content analysis tests.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Modules\Analysis\Analyzer;
use MGSEO\Modules\Analysis\Module as Analysis;
use MGSEO\Meta;

/**
 * @covers \MGSEO\Modules\Analysis\Analyzer
 */
class AnalysisTest extends TestCase {

	private function checks( array $result ): array {
		$out = array();
		foreach ( $result['checks'] as $check ) {
			$out[ $check['id'] ] = $check['status'];
		}
		return $out;
	}

	public function test_matching_is_case_and_accent_insensitive_with_word_boundaries(): void {
		$this->assertTrue( Analyzer::contains( 'strony internetowe', 'Tworzymy STRONY internetowe w Łodzi' ) );
		$this->assertTrue( Analyzer::contains( 'łódź', 'Agencja z Lodz' ) );
		$this->assertFalse( Analyzer::contains( 'seo', 'seoul' ) );
		$this->assertSame( 2, Analyzer::count( 'seo', 'SEO i seo, ale nie seoul' ) );
	}

	public function test_good_content_scores_high(): void {
		$body  = '<p>Pozycjonowanie stron to proces. ' . str_repeat( 'Lorem ipsum dolor sit amet consectetur adipiscing elit sed do. ', 70 ) . '</p>';
		$body .= '<h2>Pozycjonowanie stron krok po kroku</h2><p>' . str_repeat( 'Tekst akapitu o pozycjonowanie stron i nie tylko. ', 15 ) . '</p>';
		$body .= '<img src="a.jpg" alt="pozycjonowanie stron przykład" /><a href="/kontakt/">kontakt</a> <a href="https://developers.google.com/">Google</a>';
		$result = ( new Analyzer(
			array(
				'title'       => '10 porad: pozycjonowanie stron w 2026 roku',
				'description' => 'Pozycjonowanie stron krok po kroku: dowiedz się, jak zwiększyć widoczność witryny w Google dzięki sprawdzonym metodom technicznym i treściom.',
				'slug'        => 'pozycjonowanie-stron',
				'url'         => 'https://example.org/pozycjonowanie-stron/',
				'content'     => $body,
				'keywords'    => array( 'pozycjonowanie stron' ),
				'home_url'    => 'https://example.org/',
			)
		) )->run();
		$checks = $this->checks( $result );
		foreach ( array( 'keyword_in_title', 'keyword_in_description', 'keyword_in_url', 'keyword_in_intro', 'keyword_in_content', 'keyword_in_subheadings', 'keyword_in_alt', 'internal_links', 'external_links', 'content_length', 'title_number' ) as $id ) {
			$this->assertSame( 'ok', $checks[ $id ], $id );
		}
		$this->assertGreaterThanOrEqual( 80, $result['score'] );
	}

	public function test_missing_keyword_caps_score(): void {
		$result = ( new Analyzer( array( 'title' => 'A', 'content' => '<p>x</p>' ) ) )->run();
		$this->assertLessThanOrEqual( 40, $result['score'] );
		$this->assertArrayNotHasKey( 'keyword_in_title', $this->checks( $result ) );
	}

	public function test_score_saved_on_post_save_and_duplicate_keyword_detected(): void {
		$first = self::factory()->post->create( array( 'post_title' => 'Strony WWW' ) );
		Meta::set_post( $first, 'focus_keyword', 'strony www' );
		wp_update_post( array( 'ID' => $first, 'post_content' => '<p>strony www</p>' ) );
		$this->assertNotSame( '', Meta::post( $first, 'score' ) );

		$second = self::factory()->post->create_and_get( array( 'post_title' => 'Inne strony www' ) );
		Meta::set_post( $second->ID, 'focus_keyword', 'Strony WWW, sklep' );
		$checks = $this->checks( Analysis::analyze_post( $second ) );
		$this->assertSame( 'fail', $checks['keyword_unique'] );
		$this->assertArrayHasKey( 'secondary_sklep', $checks );
	}

	public function test_rest_endpoint_requires_permission_and_analyses_unsaved_state(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'Draft' ) );
		$request = new \WP_REST_Request( 'POST', '/mg-seo/v1/analyze' );
		$request->set_body_params( array( 'post_id' => $post, 'keywords' => 'kawa', 'content' => '<p>kawa kawa</p>', 'title' => 'Kawa %sep% %sitename%' ) );
		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertStringStartsWith( 'Kawa - ', $data['title'] );
		$this->assertSame( 'ok', $this->checks( $data )['keyword_in_title'] );
	}
}
