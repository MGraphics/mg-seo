<?php
/**
 * Internal link suggestions: ranking, exclusions and REST permissions.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Meta;
use MGSEO\Modules\Links\Parser;
use MGSEO\Modules\Links\Suggester;
use MGSEO\Modules\Links\Tokenizer;

/**
 * @covers \MGSEO\Modules\Links\Suggester
 * @covers \MGSEO\Modules\Links\Tokenizer
 */
class LinksSuggestTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		Parser::reset();
	}

	/**
	 * Create a published post and (re)index it with focus keywords.
	 */
	private function post( string $title, string $content = '', string $keywords = '', array $args = array() ): int {
		$id = self::factory()->post->create(
			array_merge(
				array(
					'post_title'   => $title,
					'post_content' => $content,
					'post_status'  => 'publish',
				),
				$args
			)
		);
		if ( '' !== $keywords ) {
			Meta::set_post( $id, 'focus_keyword', $keywords );
			wp_update_post( array( 'ID' => $id ) );
		}
		return $id;
	}

	private function ids( array $suggestions ): array {
		return array_column( $suggestions, 'id' );
	}

	public function test_tokenizer_normalises_and_stems(): void {
		$this->assertSame( array( 'stron', 'stron', 'stron', 'inter' ), Tokenizer::tokens( 'Strony, stron i STRONIE internetowe' ) );
		$this->assertSame( array( 'lodz', 'kawa' ), Tokenizer::tokens( 'Łódź — kawa 2026 the' ) );
		$this->assertTrue( Tokenizer::contains( 'kawa ziarnista', 'Najlepsza KAWA ziarnista w mieście' ) );
		$this->assertFalse( Tokenizer::contains( 'kawa', 'kawałek' ) );
	}

	public function test_suggestions_rank_by_relevance_and_exclude_linked_and_self(): void {
		$beans   = $this->post( 'Kawa ziarnista – przewodnik', '<p>Jak wybrać kawę ziarnistą: arabika, robusta, palenie.</p>', 'kawa ziarnista' );
		$grinder = $this->post( 'Młynek do kawy – ranking', '<p>Młynek żarnowy do kawy ziarnistej i ekspresu.</p>', 'młynek do kawy' );
		$bikes   = $this->post( 'Rowery górskie dla początkujących', '<p>Rama, amortyzator, koła 29 cali.</p>', 'rowery górskie' );
		$linked  = $this->post( 'Ekspres ciśnieniowy do kawy', '<p>Ekspres do kawy ziarnistej.</p>', 'ekspres do kawy' );
		$current = $this->post(
			'Jak parzyć kawę w domu',
			'<p>Dobra kawa ziarnista to podstawa. Świeżo zmielona kawa z młynka smakuje lepiej. Zobacz też <a href="' . get_permalink( $linked ) . '">nasz ekspres</a>.</p>',
			'parzenie kawy'
		);

		$results = Suggester::suggest( get_post( $current ) );
		$ids     = $this->ids( $results );
		$this->assertSame( $beans, $ids[0], 'Post whose focus keyword appears in the text ranks first.' );
		$this->assertContains( $grinder, $ids );
		$this->assertNotContains( $bikes, $ids, 'Unrelated content is not suggested.' );
		$this->assertNotContains( $linked, $ids, 'Already linked posts are excluded.' );
		$this->assertNotContains( $current, $ids, 'The post itself is excluded.' );

		$first = $results[0];
		$this->assertSame( 'kawa ziarnista', $first['in_content'] );
		$this->assertSame( 'kawa ziarnista', $first['anchors'][0] );
		$this->assertContains( 'Kawa ziarnista – przewodnik', $first['anchors'] );
		$this->assertSame( get_permalink( $beans ), $first['url'] );

		// Unsaved editor content: removing the link makes the post eligible again.
		$results = Suggester::suggest( get_post( $current ), array( 'content' => '<p>Ekspres do kawy ziarnistej w domu.</p>' ) );
		$this->assertContains( $linked, $this->ids( $results ) );

		$this->assertCount( 1, Suggester::suggest( get_post( $current ), array(), 1 ) );
	}

	public function test_pillar_content_is_boosted_and_noindex_or_drafts_excluded(): void {
		$plain   = $this->post( 'Pozycjonowanie stron w Google', '<p>Pozycjonowanie stron krok po kroku.</p>', 'pozycjonowanie stron' );
		$pillar  = $this->post( 'Pozycjonowanie stron w Google', '<p>Pozycjonowanie stron krok po kroku.</p>', 'pozycjonowanie stron' );
		$noindex = $this->post( 'Pozycjonowanie stron – notatki', '<p>Pozycjonowanie stron.</p>', 'pozycjonowanie stron' );
		$draft   = $this->post( 'Pozycjonowanie stron – szkic', '<p>Pozycjonowanie stron.</p>', 'pozycjonowanie stron', array( 'post_status' => 'draft' ) );
		Meta::set_post( $pillar, 'pillar', true );
		Meta::set_post( $noindex, 'robots', array( 'noindex' ) );
		$current = $this->post( 'Audyt SEO', '<p>Audyt to pierwszy etap, a pozycjonowanie stron to proces.</p>', 'audyt seo' );

		$results = Suggester::suggest( get_post( $current ) );
		$ids     = $this->ids( $results );
		$this->assertSame( $pillar, $ids[0] );
		$this->assertTrue( $results[0]['pillar'] );
		$this->assertSame( $plain, $ids[1] );
		$this->assertNotContains( $noindex, $ids );
		$this->assertNotContains( $draft, $ids );
	}

	public function test_shared_categories_and_post_type_setting(): void {
		$cat     = self::factory()->category->create( array( 'name' => 'Ogród' ) );
		$sibling = $this->post( 'Zupełnie inny temat', '<p>Lorem ipsum.</p>', '', array( 'post_category' => array( $cat ) ) );
		$page    = $this->post( 'Trawnik wiosną', '<p>Trawnik wiosną nawożenie.</p>', 'trawnik', array( 'post_type' => 'page' ) );
		$current = $this->post( 'Trawnik po zimie', '<p>Wertykulacja trawnik.</p>', 'trawnik', array( 'post_category' => array( $cat ) ) );

		$ids = $this->ids( Suggester::suggest( get_post( $current ) ) );
		$this->assertContains( $sibling, $ids, 'Posts in the same category are candidates.' );
		$this->assertContains( $page, $ids );

		$this->set_option( 'links', 'suggest_post_types', array( 'post' ) );
		$this->assertNotContains( $page, $this->ids( Suggester::suggest( get_post( $current ) ) ) );
	}

	public function test_rest_endpoint_requires_permission(): void {
		$target  = $this->post( 'Kawa ziarnista', '<p>Kawa.</p>', 'kawa ziarnista' );
		$current = $this->post( 'Parzenie', '<p>Kawa ziarnista jest super.</p>', 'parzenie' );

		$request = new \WP_REST_Request( 'GET', '/mg-seo/v1/links/suggest' );
		$request->set_query_params( array( 'post_id' => $current ) );
		$this->assertSame( 401, rest_get_server()->dispatch( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );

		$other = self::factory()->post->create( array( 'post_author' => self::factory()->user->create( array( 'role' => 'editor' ) ) ) );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$foreign = new \WP_REST_Request( 'GET', '/mg-seo/v1/links/suggest' );
		$foreign->set_query_params( array( 'post_id' => $other ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $foreign )->get_status(), 'Authors cannot query posts they cannot edit.' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( $target, $response->get_data()['suggestions'][0]['id'] );

		$post = new \WP_REST_Request( 'POST', '/mg-seo/v1/links/suggest' );
		$post->set_body_params(
			array(
				'post_id' => $current,
				'content' => '<a href="' . get_permalink( $target ) . '">k</a>',
			)
		);
		$this->assertNotContains( $target, $this->ids( rest_get_server()->dispatch( $post )->get_data()['suggestions'] ) );

		$rebuild = new \WP_REST_Request( 'POST', '/mg-seo/v1/links/rebuild' );
		$this->assertSame( 403, rest_get_server()->dispatch( $rebuild )->get_status(), 'Rebuild needs mgseo_manage_settings.' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$this->assertSame( 200, rest_get_server()->dispatch( $rebuild )->get_status() );
	}
}
