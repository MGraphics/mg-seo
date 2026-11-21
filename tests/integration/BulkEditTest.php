<?php
/**
 * Bulk editing tests.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Meta;
use MGSEO\Modules\Bulk\Csv;
use MGSEO\Modules\Bulk\ListTable;
use MGSEO\Modules\Bulk\Module as Bulk;

/**
 * @covers \MGSEO\Modules\Bulk\Module
 * @covers \MGSEO\Modules\Bulk\Csv
 */
class BulkEditTest extends TestCase {

	/**
	 * Dispatch a bulk save request.
	 *
	 * @param array $items Items.
	 */
	private function save( array $items ): \WP_REST_Response {
		do_action( 'rest_api_init' );
		$request = new \WP_REST_Request( 'POST', '/mg-seo/v1/bulk/save' );
		$request->set_header( 'content-type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'items' => $items ) ) );
		return rest_get_server()->dispatch( $request );
	}

	public function test_editor_can_bulk_save(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		$a = self::factory()->post->create();
		$b = self::factory()->post->create( array( 'post_type' => 'page' ) );
		Meta::set_post( $b, 'robots', array( 'nofollow' ) );
		$response = $this->save(
			array(
				array(
					'id'            => $a,
					'title'         => '%title% %sep% Sklep',
					'description'   => 'Opis',
					'focus_keyword' => 'a, b ,c',
					'index'         => 'noindex',
				),
				array(
					'id'    => $b,
					'index' => 'index',
				),
			)
		);
		$this->assertSame( 200, $response->get_status() );
		$this->assertSame( '%title% %sep% Sklep', Meta::post( $a, 'title' ) );
		$this->assertSame( 'a,b,c', Meta::post( $a, 'focus_keyword' ) );
		$this->assertSame( array( 'noindex' ), Meta::post( $a, 'robots' ) );
		$this->assertSame( array( 'index', 'nofollow' ), Meta::post( $b, 'robots' ), 'Other directives are kept.' );

		$this->save( array( array( 'id' => $b, 'index' => '' ) ) );
		$this->assertSame( array( 'nofollow' ), Meta::post( $b, 'robots' ) );
	}

	public function test_permissions(): void {
		$post = self::factory()->post->create();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'author' ) ) );
		$this->assertSame( 403, $this->save( array( array( 'id' => $post, 'title' => 'X' ) ) )->get_status(), 'Authors lack mgseo_bulk_edit.' );

		get_role( 'author' )->add_cap( 'mgseo_bulk_edit' );
		$author = wp_get_current_user();
		$author->get_role_caps();
		$response = $this->save( array( array( 'id' => $post, 'title' => 'X' ) ) );
		$this->assertSame( 200, $response->get_status() );
		$this->assertArrayHasKey( 'error', $response->get_data()['items'][0], 'Another user\'s post cannot be edited.' );
		$this->assertSame( '', Meta::post( $post, 'title' ) );

		$own      = self::factory()->post->create( array( 'post_author' => get_current_user_id() ) );
		$response = $this->save( array( array( 'id' => $own, 'title' => 'Mine', 'index' => 'noindex' ) ) );
		$item     = $response->get_data()['items'][0];
		$this->assertSame( 'You cannot change indexing.', $item['error'] ?? '', 'Authors lack mgseo_edit_advanced.' );
		$this->assertSame( '', Meta::post( $own, 'title' ), 'Nothing is saved when the row asks for a forbidden change.' );
		$this->save( array( array( 'id' => $own, 'title' => 'Mine' ) ) );
		$this->assertSame( 'Mine', Meta::post( $own, 'title' ) );
		get_role( 'author' )->remove_cap( 'mgseo_bulk_edit' );
	}

	public function test_list_filters(): void {
		$with    = self::factory()->post->create();
		$without = self::factory()->post->create();
		$noindex = self::factory()->post->create();
		Meta::set_post( $with, 'title', 'T' );
		Meta::set_post( $with, 'score', 85 );
		Meta::set_post( $noindex, 'robots', array( 'noindex', 'nofollow' ) );
		Meta::set_post( $noindex, 'score', 30 );
		$ids = static fn( string $filter ) => array_map( 'intval', ( new \WP_Query( array_merge( ListTable::query_args( 'post', $filter, '' ), array( 'fields' => 'ids', 'posts_per_page' => -1, 'perm' => '' ) ) ) )->posts );
		$missing = $ids( 'missing_title' );
		$this->assertContains( $without, $missing );
		$this->assertNotContains( $with, $missing );
		$this->assertSame( array( $noindex ), array_values( $ids( 'noindex' ) ) );
		$this->assertSame( array( $with ), array_values( $ids( 'score_good' ) ) );
		$this->assertSame( array( $noindex ), array_values( $ids( 'score_bad' ) ) );
	}

	public function test_csv_round_trip_with_dry_run(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$a = self::factory()->post->create( array( 'post_name' => 'alfa' ) );
		$b = self::factory()->post->create( array( 'post_type' => 'page', 'post_name' => 'beta' ) );
		Meta::set_post( $a, 'title', 'Alfa, "cytat" %sep% %sitename%' );
		Meta::set_post( $a, 'description', "Opis\nw dwóch liniach" );
		Meta::set_post( $a, 'robots', array( 'noindex', 'nofollow' ) );
		Meta::set_post( $b, 'focus_keyword', 'beta,gamma' );
		Meta::set_post( $b, 'og_title', '=SUM(1)' );

		$csv  = Csv::to_csv( Csv::rows( array( 'post', 'page' ) ) );
		$rows = Csv::parse( $csv );
		$this->assertIsArray( $rows );
		$by = array_column( $rows, null, 'id' );
		$this->assertSame( 'Alfa, "cytat" %sep% %sitename%', $by[ (string) $a ]['seo_title'] );
		$this->assertSame( "Opis\nw dwóch liniach", $by[ (string) $a ]['seo_description'] );
		$this->assertSame( 'noindex,nofollow', $by[ (string) $a ]['robots'] );
		$this->assertSame( '=SUM(1)', $by[ (string) $b ]['og_title'], 'Formula guard is reversed on import.' );
		$this->assertStringContainsString( "'=SUM(1)", $csv, 'Formulas are neutralised in the file.' );

		// Unchanged file: no changes planned.
		$this->assertSame( array(), Csv::plan( $rows )['changes'] );

		// Edit: by id, by slug, clear with "-", robots default.
		$edited = "id,post_type,slug,seo_title,seo_description,robots,focus_keyword\n"
			. "{$a},post,alfa,Nowy tytuł,-,default,\n"
			. ",page,beta,,,noindex,delta\n"
			. "999999,post,brak,X,,,\n";
		$rows   = Csv::parse( $edited );
		$plan   = Csv::plan( $rows );
		$fields = array_map( static fn( $c ) => $c['id'] . ':' . $c['field'] . '=' . $c['new'], $plan['changes'] );
		sort( $fields );
		$this->assertSame(
			array(
				"{$a}:robots=",
				"{$a}:seo_description=",
				"{$a}:seo_title=Nowy tytuł",
				"{$b}:focus_keyword=delta",
				"{$b}:robots=noindex",
			),
			$fields
		);
		$this->assertCount( 1, $plan['errors'] );
		// Dry run (plan only) wrote nothing.
		$this->assertSame( 'Alfa, "cytat" %sep% %sitename%', Meta::post( $a, 'title' ) );

		$this->assertSame( 5, Csv::apply( $plan['changes'] ) );
		$this->assertSame( 'Nowy tytuł', Meta::post( $a, 'title' ) );
		$this->assertFalse( metadata_exists( 'post', $a, '_mgseo_description' ) );
		$this->assertFalse( metadata_exists( 'post', $a, '_mgseo_robots' ) );
		$this->assertSame( array( 'noindex' ), Meta::post( $b, 'robots' ) );
		$this->assertSame( 'delta', Meta::post( $b, 'focus_keyword' ) );
	}

	public function test_csv_respects_permissions(): void {
		$post = self::factory()->post->create();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'contributor' ) ) );
		$plan = Csv::plan( Csv::parse( "id,seo_title\n{$post},Hack\n" ) );
		$this->assertSame( array(), $plan['changes'] );
		$this->assertCount( 1, $plan['errors'] );
		$this->assertSame( 0, Csv::apply( array( array( 'id' => $post, 'field' => 'seo_title', 'value' => 'Hack' ) ) ) );
		$this->assertSame( '', Meta::post( $post, 'title' ) );
	}

	public function test_quick_edit_and_bulk_edit_save(): void {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$post = self::factory()->post->create();
		$_POST['mgseo_quick_nonce'] = wp_create_nonce( 'mgseo_quick_edit' );
		$_REQUEST['mgseo_quick_nonce'] = $_POST['mgseo_quick_nonce'];
		$_POST['mgseo_quick'] = array(
			'title'         => 'Quick',
			'description'   => 'Desc',
			'focus_keyword' => 'kw',
			'index'         => 'noindex',
		);
		( new Bulk() )->save_quick_edit( $post, get_post( $post ) );
		$this->assertSame( 'Quick', Meta::post( $post, 'title' ) );
		$this->assertSame( array( 'noindex' ), Meta::post( $post, 'robots' ) );

		unset( $_POST['mgseo_quick'] );
		$_REQUEST['bulk_edit']        = 'Update';
		$_REQUEST['mgseo_bulk_index'] = '-1';
		( new Bulk() )->save_quick_edit( $post, get_post( $post ) );
		$this->assertSame( array( 'noindex' ), Meta::post( $post, 'robots' ), '"No change" keeps robots.' );
		$_REQUEST['mgseo_bulk_index'] = 'index';
		( new Bulk() )->save_quick_edit( $post, get_post( $post ) );
		$this->assertSame( array( 'index' ), Meta::post( $post, 'robots' ) );
		$this->assertSame( 'Quick', Meta::post( $post, 'title' ), 'Bulk edit does not touch titles.' );
		unset( $_POST['mgseo_quick_nonce'], $_REQUEST['mgseo_quick_nonce'], $_REQUEST['bulk_edit'], $_REQUEST['mgseo_bulk_index'] );
	}
}
