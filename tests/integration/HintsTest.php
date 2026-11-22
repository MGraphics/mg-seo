<?php
/**
 * Hints UX (0.3.0): matching inflected keywords, grouped and merged hints, content-type rules,
 * rendered builder content, editor endpoints, list filter and the SEO box markup.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Admin\Metabox;
use MGSEO\Meta;
use MGSEO\Modules\Analysis\Analyzer;
use MGSEO\Modules\Analysis\Builders;
use MGSEO\Modules\Analysis\Content;
use MGSEO\Modules\Analysis\Editors;
use MGSEO\Modules\Analysis\Matcher;
use MGSEO\Modules\Analysis\Module as Analysis;
use MGSEO\Modules\Analysis\Report;
use MGSEO\Modules\Analysis\Scores;
use MGSEO\Plugin;

/**
 * @covers \MGSEO\Modules\Analysis\Matcher
 * @covers \MGSEO\Modules\Analysis\Report
 * @covers \MGSEO\Modules\Analysis\Content
 */
class HintsTest extends TestCase {

	private int $admin;

	public function set_up(): void {
		parent::set_up();
		$this->admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		Analyzer::$inflection = true;
		Scores::reset_budget();
	}

	public function tear_down(): void {
		unload_textdomain( 'mg-seo' );
		Analyzer::$inflection = true;
		parent::tear_down();
	}

	private function module(): Analysis {
		$module = Plugin::instance()->module( 'analysis' );
		$this->assertInstanceOf( Analysis::class, $module );
		return $module;
	}

	private function group( array $result, string $id ): array {
		foreach ( $result['groups'] as $group ) {
			if ( $id === $group['id'] ) {
				return $group;
			}
		}
		$this->fail( 'No group ' . $id );
	}

	/**
	 * Polish sentences from the UX review: the keyword in its natural, inflected form counts.
	 */
	public function test_inflected_keywords_match_polish_sentences(): void {
		$cases = array(
			array( 'masaż leczniczy Kraków', 'Oferujemy masaż leczniczy w Krakowie od 2010 roku.' ),
			array( 'ból kręgosłupa lędźwiowego', 'Z bólem kręgosłupa lędźwiowego warto iść do fizjoterapeuty.' ),
			array( 'rehabilitacja po urazach', 'Rehabilitację po urazach zaczynamy od wywiadu.' ),
			array( 'fizjoterapeuta Kraków', 'Najlepszy fizjoterapeuta Kraków Krowodrza.' ),
			array( 'masaż leczniczy', 'Cena masażu leczniczego zależy od czasu.' ),
			array( 'Warszawa', 'Gabinet w Warszawie.' ),
		);
		foreach ( $cases as $case ) {
			$this->assertSame( 1, Analyzer::count( $case[0], $case[1] ), $case[0] );
		}
		// Different words with the same beginning, short words with other endings, numbers.
		$this->assertSame( 0, Analyzer::count( 'leczniczy', 'Nowa lecznica w mieście.' ) );
		$this->assertSame( 0, Analyzer::count( 'seo', 'seoul' ) );
		$this->assertSame( 0, Analyzer::count( 'fizjoterapeuta', 'fizjoterapia' ) );
		$this->assertSame( 0, Analyzer::count( 'masaż leczniczy', 'leczniczy masaż' ) );
		$this->assertSame( 0, Analyzer::count( '2026', '20261' ) );
		// Only one connecting word, never before the first word.
		$this->assertSame( 0, Analyzer::count( 'masaż Kraków', 'masaż w samym Krakowie' ) );
		$this->assertTrue( Matcher::same_word( 'bol', 'bolem' ) );

		Analyzer::$inflection = false;
		$this->assertSame( 0, Analyzer::count( 'masaż leczniczy Kraków', 'Oferujemy masaż leczniczy w Krakowie.' ) );
	}

	public function test_inflected_form_is_named_in_the_hint(): void {
		$result = ( new Analyzer(
			array(
				'title'    => 'Masaż leczniczy w Krakowie',
				'content'  => '<p>Oferujemy masaż leczniczy w Krakowie od 2010 roku.</p>',
				'keywords' => array( 'masaż leczniczy Kraków' ),
			)
		) )->run();
		$check = wp_list_filter( $result['checks'], array( 'id' => 'keyword_in_content' ) );
		$check = reset( $check );
		$this->assertSame( 'ok', $check['status'] );
		$this->assertStringContainsString( 'masaż leczniczy w krakowie', $check['help'] );
	}

	/**
	 * Correct Polish plural forms in messages with numbers ("22 słowa", "53 znaki", "25 słów").
	 */
	public function test_polish_plural_forms(): void {
		$this->assertTrue( load_textdomain( 'mg-seo', MGSEO_DIR . 'languages/mg-seo-pl_PL.mo' ) );
		$run = static fn( array $input ): array => wp_list_pluck( ( new Analyzer( $input ) )->run()['checks'], 'message', 'id' );

		$checks = $run(
			array(
				'title'   => str_repeat( 'a', 53 ),
				'content' => '<p>' . trim( str_repeat( 'słowo ', 22 ) ) . '</p>',
			)
		);
		$this->assertSame( 'Tytuł w Google ma dobrą długość (53 znaki)', $checks['title_length'] );
		$this->assertSame( 'Tekst jest bardzo krótki: 22 słowa', $checks['content_length'] );

		$checks = $run(
			array(
				'title'   => str_repeat( 'a', 25 ),
				'content' => '<p>' . trim( str_repeat( 'słowo ', 25 ) ) . '</p>',
			)
		);
		$this->assertSame( 'Tytuł w Google jest krótki: 25 znaków', $checks['title_length'] );
		$this->assertSame( 'Tekst jest bardzo krótki: 25 słów', $checks['content_length'] );
		$this->assertSame( '4 rzeczy do poprawy', Report::summary( 4 ) );
		$this->assertSame( '5 rzeczy do poprawy', Report::summary( 5 ) );
		$this->assertSame( '1 rzecz do poprawy', Report::summary( 1 ) );
	}

	/**
	 * One cause, one hint: a keyword missing from the page is one item with the places where it is missing.
	 */
	public function test_missing_keyword_is_one_hint_with_places(): void {
		$post   = self::factory()->post->create(
			array(
				'post_title'   => 'Nowości w gabinecie',
				'post_content' => '<p>Od września przyjmujemy także w soboty. Mamy nowy stół do terapii.</p>',
			)
		);
		$result = Analysis::analyze_post( get_post( $post ), array( 'keywords' => 'masaż leczniczy Kraków' ) );
		$fix    = $this->group( $result, Report::GROUP_FIX );
		$ids    = wp_list_pluck( $fix['items'], 'id' );
		$this->assertSame( Report::KEYWORD_MISSING, $ids[0], 'The heaviest hint comes first.' );
		foreach ( array( 'keyword_in_title', 'keyword_in_description', 'keyword_in_url', 'keyword_in_intro', 'keyword_in_content', 'keyword_density', 'keyword_in_subheadings', 'keyword_in_alt', 'keyword_title_start' ) as $id ) {
			$this->assertNotContains( $id, $ids, $id );
		}
		$this->assertCount( 6, $fix['items'][0]['places'] );
		$this->assertStringContainsString( 'masaż leczniczy Kraków', $fix['items'][0]['title'] );
		$this->assertSame( count( $fix['items'] ), $result['fix_count'] );
		$this->assertSame( array( 'fix', 'consider', 'ok' ), wp_list_pluck( $result['groups'], 'id' ) );

		// Once the keyword is in the text, the missing places come back as separate hints.
		$result = Analysis::analyze_post( get_post( $post ), array( 'keywords' => 'stół do terapii' ) );
		$ids    = wp_list_pluck( $this->group( $result, Report::GROUP_FIX )['items'], 'id' );
		$this->assertNotContains( Report::KEYWORD_MISSING, $ids );
		$this->assertContains( 'keyword_in_title', $ids );
		$this->assertNotContains( 'keyword_title_start', $ids, 'Only once the keyword is in the title.' );
	}

	public function test_no_keyword_state_has_no_number_but_checks_basics(): void {
		$post   = self::factory()->post->create( array( 'post_content' => '<p>Krótki tekst.</p>' ) );
		$result = Analysis::analyze_post( get_post( $post ) );
		$this->assertFalse( $result['has_keyword'] );
		$this->assertSame( Scores::BAND_NONE, $result['band'] );
		$this->assertSame( 'No keyword', $result['band_word'] );
		$this->assertSame( 'To improve without a keyword', $this->group( $result, Report::GROUP_FIX )['label'] );
		$this->assertContains( 'content_length', wp_list_pluck( $this->group( $result, Report::GROUP_FIX )['items'], 'id' ) );
		$this->assertStringNotContainsString( '/100', Report::rating_html( null, Scores::BAND_NONE ) );
	}

	public function test_hint_actions(): void {
		$other = self::factory()->post->create( array( 'post_title' => 'Masaż leczniczy: co warto wiedzieć' ) );
		update_post_meta( $other, Meta::PREFIX . 'focus_keyword', 'masaż leczniczy' );
		wp_set_current_user( $this->admin );
		$post   = self::factory()->post->create( array( 'post_content' => '<p>Masaż leczniczy pomaga.</p>' ) );
		$result = Analysis::analyze_post( get_post( $post ), array( 'keywords' => 'masaż leczniczy' ) );
		$items  = array_column( $this->group( $result, Report::GROUP_FIX )['items'], null, 'id' );
		$this->assertArrayHasKey( 'keyword_unique', $items );
		$this->assertStringContainsString( 'Masaż leczniczy: co warto wiedzieć', $items['keyword_unique']['help'] );
		$types = wp_list_pluck( $items['keyword_unique']['actions'], 'type' );
		$this->assertSame( array( 'url', 'focus' ), $types );
		$this->assertStringContainsString( 'post=' . $other, $items['keyword_unique']['actions'][0]['url'] );
		$this->assertSame( 'links', $items['internal_links']['actions'][0]['type'] );
	}

	/**
	 * Pages such as Contact or Pricing do not get advice meant for blog posts.
	 */
	public function test_pages_skip_blog_hints_and_rules_are_configurable(): void {
		$page   = self::factory()->post->create(
			array(
				'post_type'    => 'page',
				'post_content' => '<p>ul. Długa 12, Kraków. Telefon 600 000 000.</p>',
			)
		);
		$result = Analysis::analyze_post( get_post( $page ) );
		$ids    = wp_list_pluck( $result['checks'], 'id' );
		foreach ( array( 'content_length', 'title_number', 'external_links', 'has_media' ) as $id ) {
			$this->assertNotContains( $id, $ids, $id );
		}
		$this->assertSame( Analyzer::PROFILE_PAGE, $result['stats']['profile'] );

		$this->set_option( 'analysis', 'profile_page', Analyzer::PROFILE_ARTICLE );
		$this->set_option( 'analysis', 'min_words_page', 100 );
		$result = Analysis::analyze_post( get_post( $page ) );
		$this->assertContains( 'title_number', wp_list_pluck( $result['checks'], 'id' ) );
		$this->assertStringContainsString( '100 words', wp_list_filter( $result['checks'], array( 'id' => 'content_length' ) )[ array_key_first( wp_list_filter( $result['checks'], array( 'id' => 'content_length' ) ) ) ]['help'] );

		$this->assertSame( Analyzer::PROFILE_ARTICLE, Analysis::type_rules( 'post' )['profile'] );
		$this->assertSame( 600, Analysis::type_rules( 'post' )['min_words'] );
		$tab = $this->module()->settings()[0];
		$this->assertContains( 'profile_post', wp_list_pluck( $tab['fields'], 'id' ) );
	}

	public function test_rendered_content_and_builders(): void {
		// Text kept in attributes of shortcodes that are not registered (WPBakery, Divi without their plugin).
		$html = Content::shortcode_text( '[vc_row][vc_column][vc_custom_heading text="Fizjoterapia sportowa"][vc_single_image alt="gabinet w Krakowie"][/vc_column][/vc_row]' );
		$this->assertStringContainsString( '<p>Fizjoterapia sportowa</p>', $html );
		$this->assertStringContainsString( 'alt="gabinet w Krakowie"', $html );
		$this->assertStringNotContainsString( 'vc_row', $html );

		// Shortcodes and blocks are rendered like on the front end.
		add_shortcode( 'mgseo_test', static fn() => '<h2>Fraza z shortcode</h2>' );
		$post = self::factory()->post->create( array( 'post_content' => '[mgseo_test]' ) );
		$this->assertStringContainsString( '<h2>Fraza z shortcode</h2>', Content::render( get_post( $post ) ) );
		remove_shortcode( 'mgseo_test' );

		// A builder keeping content outside post_content: its rendered output is analysed, live and stored.
		$built   = self::factory()->post->create( array( 'post_content' => '' ) );
		$builder = static fn( string $b, \WP_Post $p ): string => $p->ID === $built ? 'testbuilder' : $b;
		$render  = static fn( $html, \WP_Post $p, string $b ) => 'testbuilder' === $b ? '<h2>Rehabilitacja po urazach</h2><p>' . str_repeat( 'Rehabilitacja po urazach sportowych. ', 30 ) . '</p>' : $html;
		add_filter( 'mgseo/analysis/builder', $builder, 10, 2 );
		add_filter( 'mgseo/analysis/builder_content', $render, 10, 3 );
		update_post_meta( $built, Meta::PREFIX . 'focus_keyword', 'rehabilitacja po urazach' );
		$this->assertSame( 'testbuilder', Builders::detect( get_post( $built ) ) );
		$result = Analysis::analyze_post( get_post( $built ) );
		$this->assertSame( 123, $result['stats']['words'] );
		$this->assertSame( $result['score'], Scores::compute( $built )['score'] );
		remove_filter( 'mgseo/analysis/builder', $builder, 10 );
		remove_filter( 'mgseo/analysis/builder_content', $render, 10 );

		$this->assertSame( '', Builders::detect( get_post( $post ) ) );
	}

	public function test_rest_analyze_rendered_html_and_save_endpoint(): void {
		wp_set_current_user( $this->admin );
		$post = self::factory()->post->create( array( 'post_content' => '' ) );

		$request = new \WP_REST_Request( 'POST', '/mg-seo/v1/analyze' );
		$request->set_body_params(
			array(
				'post_id'  => $post,
				'keywords' => 'fizjoterapia sportowa',
				'html'     => '<div class="builder"><h2>Fizjoterapia sportowa</h2><p>Fizjoterapia sportowa skraca powrót do treningów.</p></div>',
			)
		);
		$data = rest_get_server()->dispatch( $request )->get_data();
		$this->assertSame( 8, $data['stats']['words'] );
		$this->assertArrayHasKey( 'groups', $data );
		$this->assertArrayHasKey( 'spoken', $data );

		$request = new \WP_REST_Request( 'POST', '/mg-seo/v1/post/' . $post . '/seo' );
		$request->set_body_params(
			array(
				'focus_keyword' => 'fizjoterapia sportowa, rehabilitacja',
				'title'         => 'Fizjoterapia sportowa %sep% %sitename%',
				'description'   => 'Opis strony.',
			)
		);
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( 'fizjoterapia sportowa, rehabilitacja', Meta::post( $post, 'focus_keyword' ) );
		$this->assertSame( 'Fizjoterapia sportowa %sep% %sitename%', Meta::post( $post, 'title' ) );
		$this->assertSame( 'Opis strony.', $response->get_data()['values']['description'] );
		$this->assertNotNull( Scores::data( $post ) );
		$this->assertGreaterThanOrEqual( 0, Scores::data( $post )['fix_count'] );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );
	}

	public function test_list_filter_and_hidden_links_column(): void {
		$poor = self::factory()->post->create();
		$good = self::factory()->post->create();
		$none = self::factory()->post->create();
		update_post_meta( $poor, Meta::PREFIX . 'focus_keyword', 'a' );
		update_post_meta( $poor, Meta::PREFIX . 'score', 20 );
		update_post_meta( $good, Meta::PREFIX . 'focus_keyword', 'b' );
		update_post_meta( $good, Meta::PREFIX . 'score', 90 );
		set_current_screen( 'edit-post' );
		$query = function ( string $band ): array {
			$_GET['mgseo_band'] = $band;
			$q                  = new \WP_Query();
			add_filter( 'pre_get_posts', array( $this->module(), 'filter_query' ) );
			$GLOBALS['wp_the_query'] = $q;
			$ids = $q->query(
				array(
					'post_type' => 'post',
					'fields'    => 'ids',
					'nopaging'  => true,
				)
			);
			remove_filter( 'pre_get_posts', array( $this->module(), 'filter_query' ) );
			unset( $_GET['mgseo_band'] );
			return $ids;
		};
		$this->assertSame( array( $poor ), $query( 'poor' ) );
		$this->assertSame( array( $poor ), $query( 'improve' ) );
		$this->assertSame( array( $good ), $query( 'good' ) );
		$this->assertContains( $none, $query( 'nokeyword' ) );
		$this->assertNotContains( $poor, $query( 'nokeyword' ) );

		ob_start();
		$this->module()->filter_dropdown( 'post', 'top' );
		$this->assertStringContainsString( 'name="mgseo_band"', (string) ob_get_clean() );
		$this->assertContains( 'mgseo_links', $this->module()->hidden_columns( array(), get_current_screen() ) );
		set_current_screen( 'front' );
	}

	public function test_seo_box_markup_tabs_and_previews(): void {
		wp_set_current_user( $this->admin );
		$post = get_post( self::factory()->post->create( array( 'post_title' => 'Pierwsza wizyta' ) ) );
		ob_start();
		Metabox::render( $post );
		$html = (string) ob_get_clean();
		$this->assertStringContainsString( 'role="tablist"', $html );
		$this->assertStringContainsString( 'aria-controls="mgseo-panel-snippet"', $html );
		$this->assertStringContainsString( 'aria-labelledby="mgseo-tab-general"', $html );
		$this->assertMatchesRegularExpression( '#id="mgseo-panel-snippet"[^>]*hidden#', $html );
		$this->assertStringContainsString( 'name="mgseo[focus_keyword]"', $html );
		$this->assertStringContainsString( 'name="mgseo[title]"', $html );
		// Fields show the automatic text, not template variables.
		$this->assertMatchesRegularExpression( '#id="mgseo-title"[^>]*placeholder="Pierwsza wizyta#', $html );
		$this->assertStringNotContainsString( 'placeholder="%title%', $html );
		$this->assertStringNotContainsString( 'aria-live="polite"><div class="mgseo-serp', $html );
		// Social preview card and image picker instead of a URL field.
		$this->assertStringContainsString( 'mgseo-social-card', $html );
		$this->assertStringContainsString( 'type="hidden" id="mgseo-og-image" name="mgseo[og_image]"', $html );
	}

	public function test_editor_config_and_canvas(): void {
		wp_set_current_user( $this->admin );
		$post = get_post( self::factory()->post->create() );
		update_post_meta( $post->ID, Meta::PREFIX . 'focus_keyword', 'test' );
		$config = Analysis::client_config( $post );
		$this->assertSame( $post->ID, $config['postId'] );
		$this->assertSame( 'test', $config['values']['focus_keyword'] );
		$this->assertArrayHasKey( 'auto', $config );
		$elementor = Editors::config( $post, Builders::ELEMENTOR );
		$this->assertSame( '#elementor-preview-iframe', $elementor['canvas']['frame'] );
		$this->assertStringContainsString( (string) $post->ID, $elementor['canvas']['content'] );
		$this->assertTrue( $elementor['tested'] );
		$this->assertFalse( Editors::config( $post, Builders::BRICKS )['tested'] );
	}
}
