<?php
/**
 * Internal links: counting, inbound links, orphans, broken links, rebuild.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Meta;
use MGSEO\Modules\Links\Broken;
use MGSEO\Modules\Links\Module as Links;
use MGSEO\Modules\Links\Parser;
use MGSEO\Modules\Links\ReportPage;
use MGSEO\Modules\Links\Store;

/**
 * @covers \MGSEO\Modules\Links\Parser
 * @covers \MGSEO\Modules\Links\Store
 * @covers \MGSEO\Modules\Links\Broken
 */
class LinksTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		Parser::reset();
	}

	private function post( string $title, string $content = '', array $args = array() ): int {
		return self::factory()->post->create(
			array_merge(
				array(
					'post_title'   => $title,
					'post_content' => $content,
					'post_status'  => 'publish',
				),
				$args
			)
		);
	}

	public function test_module_is_booted_and_tables_exist(): void {
		global $wpdb;
		$this->assertInstanceOf( Links::class, \MGSEO\Plugin::instance()->module( 'links' ) );
		$this->assertSame( Store::table(), $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', Store::table() ) ) );
	}

	public function test_parser_classifies_internal_external_relative_and_ignores_non_links(): void {
		$target = $this->post( 'Target page' );
		$url    = get_permalink( $target );
		$path   = wp_parse_url( $url, PHP_URL_PATH );
		$html   = '<p><a href="' . esc_url( $url ) . '">abs</a>'
			. '<a href="' . esc_attr( $path ) . '#section">root-relative with fragment</a>'
			. '<a href="//example.org' . esc_attr( $path ) . '">protocol-relative</a>'
			. '<a href="http://www.example.org' . esc_attr( $path ) . '">www</a>'
			. '<a href="sibling/">relative</a>'
			. '<a href="?p=' . $target . '">query</a>'
			. '<a href="https://developers.google.com/search">external</a>'
			. '<A HREF="HTTPS://WWW.W3.ORG/">upper</A>'
			. '<a href="#top">anchor</a><a href="mailto:a@b.c">mail</a><a href="tel:+48123">tel</a>'
			. '<a href="javascript:void(0)">js</a><a href="">empty</a><a name="x">no href</a>'
			. '<a href="data:text/html,hi">data</a></p>';

		$links = Parser::parse( $html, home_url( '/blog/post/' ) );
		$types = array_count_values( array_column( $links, 'type' ) );
		$this->assertSame( 6, $types['internal'] );
		$this->assertSame( 2, $types['external'] );
		$this->assertCount( 8, $links );
		$this->assertSame( $target, $links[0]['target'] );
		$this->assertSame( $target, $links[1]['target'], 'Fragment is stripped before resolving.' );
		$this->assertStringNotContainsString( '#', $links[1]['url'] );
		$this->assertSame( $target, $links[2]['target'] );
		$this->assertSame( $target, $links[3]['target'] );
		$this->assertSame( home_url( '/blog/post/sibling/' ), $links[4]['url'] );
		$this->assertSame( 0, $links[4]['target'] );
		$this->assertSame( $target, $links[5]['target'] );
		$this->assertSame( 0, $links[6]['target'] );
	}

	public function test_counts_and_inbound_links_are_stored_on_save(): void {
		$b     = $this->post( 'Page B' );
		$c     = $this->post( 'Page C' );
		$b_url = get_permalink( $b );
		$c_url = get_permalink( $c );
		$a     = $this->post( 'Page A', '<a href="' . $b_url . '">b</a> <a href="' . $b_url . '">b again</a> <a href="' . $c_url . '">c</a> <a href="https://wordpress.org/">wp</a> <a href="' . get_permalink( $b ) . '#x">self?</a>' );
		wp_update_post(
			array(
				'ID'           => $b,
				'post_content' => '<a href="' . $c_url . '">c</a><a href="' . $b_url . '">self link</a>',
			)
		);
		$this->post( 'Draft D', '<a href="' . $c_url . '">c</a>', array( 'post_status' => 'draft' ) );

		$counts = Store::counts( array( $a, $b, $c ) );
		$this->assertSame( array( 'internal' => 4, 'external' => 1, 'inbound' => 0 ), $counts[ $a ] );
		$this->assertSame( array( 'internal' => 2, 'external' => 0, 'inbound' => 3 ), $counts[ $b ], 'Inbound counts links from other published posts; self links are ignored.' );
		$this->assertSame( 2, $counts[ $c ]['inbound'], 'Links from drafts do not count as inbound.' );
		$targets = Store::targets( $a );
		sort( $targets );
		$this->assertSame( array( $b, $c ), $targets );

		// Editing removes old rows.
		wp_update_post( array( 'ID' => $a, 'post_content' => 'no links' ) );
		$this->assertSame( 0, Store::counts( array( $a ) )[ $a ]['internal'] );
		$this->assertSame( 0, Store::counts( array( $b ) )[ $b ]['inbound'] );
	}

	public function test_trash_and_delete_update_the_index(): void {
		$target = $this->post( 'Target' );
		$source = $this->post( 'Source', '<a href="' . get_permalink( $target ) . '">t</a>' );
		$this->assertSame( 1, Store::counts( array( $target ) )[ $target ]['inbound'] );
		wp_trash_post( $source );
		$this->assertSame( 0, Store::counts( array( $target ) )[ $target ]['inbound'] );
		wp_untrash_post( $source );
		wp_publish_post( $source );
		$this->assertSame( 1, Store::counts( array( $target ) )[ $target ]['inbound'] );

		wp_delete_post( $target, true );
		$this->assertSame( array(), Store::targets( $source ), 'Deleted targets become unresolved links.' );
	}

	public function test_link_resolves_when_target_is_published_later(): void {
		$source = $this->post( 'Source', '<a href="' . home_url( '/coming-soon/' ) . '">soon</a>' );
		$this->assertSame( array(), Store::targets( $source ) );
		$target = $this->post( 'Coming soon', '', array( 'post_name' => 'coming-soon' ) );
		$this->assertSame( array( $target ), Store::targets( $source ) );
	}

	public function test_orphan_report_lists_published_indexable_posts_without_inbound_links(): void {
		$linked   = $this->post( 'Linked' );
		$orphan   = $this->post( 'Orphan' );
		$noindex  = $this->post( 'Noindex orphan' );
		$draft    = $this->post( 'Draft orphan', '', array( 'post_status' => 'draft' ) );
		$self     = $this->post( 'Self linker' );
		$front    = $this->post( 'Front', '', array( 'post_type' => 'page' ) );
		$hub      = $this->post( 'Hub', '<a href="' . get_permalink( $linked ) . '">l</a>' );
		Meta::set_post( $noindex, 'robots', array( 'noindex' ) );
		wp_update_post( array( 'ID' => $self, 'post_content' => '<a href="' . get_permalink( $self ) . '">me</a>' ) );
		update_option( 'show_on_front', 'page' );
		update_option( 'page_on_front', $front );

		$orphans = ReportPage::orphans();
		$this->assertContains( $orphan, $orphans );
		$this->assertContains( $self, $orphans, 'A self link does not rescue a post.' );
		$this->assertContains( $hub, $orphans );
		$this->assertNotContains( $linked, $orphans );
		$this->assertNotContains( $noindex, $orphans );
		$this->assertNotContains( $draft, $orphans );
		$this->assertNotContains( $front, $orphans );

		$this->set_option( 'titles', 'pt_post_robots', array( 'noindex' ) );
		$this->assertNotContains( $orphan, ReportPage::orphans(), 'Post type default noindex is respected.' );
	}

	public function test_broken_internal_links(): void {
		$cat    = self::factory()->category->create( array( 'slug' => 'kategoria' ) );
		$target = $this->post( 'Exists', '', array( 'post_category' => array( $cat ) ) );
		$source = $this->post(
			'Source',
			'<a href="/missing-page/">x</a><a href="' . get_permalink( $target ) . '">ok</a><a href="' . get_category_link( $cat ) . '">cat</a>'
			. '<a href="/2026/05/">date</a><a href="' . wp_get_upload_dir()['baseurl'] . '/nope.pdf">file</a><a href="/wp-content/themes/x/readme.txt">other file</a><a href="/old-url/">redirected</a><a href="/?p=999999">bad id</a>'
		);
		add_filter( 'mgseo/links/is_redirected', static fn( $is, $url ) => $is || str_contains( $url, '/old-url/' ), 10, 2 );

		$this->assertTrue( Broken::is_broken( home_url( '/missing-page/' ) ) );
		$this->assertFalse( Broken::is_broken( get_permalink( $target ) ) );
		$this->assertFalse( Broken::is_broken( get_category_link( $cat ) ) );
		$this->assertFalse( Broken::is_broken( home_url( '/2026/05/' ) ) );
		$this->assertFalse( Broken::is_broken( home_url( '/' ) ) );
		$this->assertFalse( Broken::is_broken( home_url( '/?s=kawa' ) ) );

		$urls = array_column( Broken::find(), 'url' );
		sort( $urls );
		$this->assertSame( array( home_url( '/?p=999999' ), home_url( '/missing-page/' ), wp_get_upload_dir()['baseurl'] . '/nope.pdf' ), $urls );
		$this->assertSame( $source, Broken::find()[0]['post_id'] );
	}

	public function test_rebuild_batches_index_existing_content(): void {
		global $wpdb;
		$target = $this->post( 'T' );
		$ids    = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$ids[] = $this->post( 'S' . $i, '<a href="' . get_permalink( $target ) . '">t</a>' );
		}
		$wpdb->query( 'DELETE FROM ' . Store::table() ); // phpcs:ignore
		$wpdb->query( 'DELETE FROM ' . Store::terms_table() ); // phpcs:ignore
		delete_option( Links::BUILT_OPTION );
		$this->assertSame( 0, Store::counts( array( $target ) )[ $target ]['inbound'] );

		$offset = 0;
		$loops  = 0;
		do {
			$result = Links::rebuild_batch( $offset, 2 );
			$offset = $result['next'];
			++$loops;
		} while ( ! $result['done'] && $loops < 50 );
		$this->assertTrue( $result['done'] );
		$this->assertGreaterThanOrEqual( 3, $loops );
		$this->assertSame( 5, Store::counts( array( $target ) )[ $target ]['inbound'] );
		$this->assertNotEmpty( get_option( Links::BUILT_OPTION ) );
	}

	public function test_list_column_renders_counts(): void {
		$target = $this->post( 'T' );
		$source = $this->post( 'S', '<a href="' . get_permalink( $target ) . '">t</a><a href="https://example.com/">e</a>' );
		$module = \MGSEO\Plugin::instance()->module( 'links' );
		$this->assertArrayHasKey( 'mgseo_links', $module->columns( array() ) );
		ob_start();
		$module->column( 'mgseo_links', $source );
		$html = (string) ob_get_clean();
		$this->assertMatchesRegularExpression( '/Internal links:<\/span> 1<\/span>.*External links:<\/span> 1<\/span>.*Inbound internal links:<\/span> 0/s', $html );
		$this->assertStringContainsString( 'is-orphan', $html );
	}
}
