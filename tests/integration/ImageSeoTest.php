<?php
/**
 * Image SEO tests.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Modules\Image\Attributes;
use MGSEO\Modules\Image\Module as Image;
use MGSEO\Modules\Image\Tools;

/**
 * @covers \MGSEO\Modules\Image\Module
 * @covers \MGSEO\Modules\Image\Attributes
 * @covers \MGSEO\Modules\Image\Tools
 */
class ImageSeoTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		( new Image() )->boot();
		Attributes::reset();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	/**
	 * Image attachment without a physical file.
	 *
	 * @param string $file   File name.
	 * @param int    $parent Parent post.
	 * @param array  $args   Post fields.
	 */
	private function attachment( string $file, int $parent = 0, array $args = array() ): int {
		return self::factory()->attachment->create_object(
			$file,
			$parent,
			$args + array(
				'post_mime_type' => 'image/jpeg',
				'post_title'     => pathinfo( $file, PATHINFO_FILENAME ),
			)
		);
	}

	public function test_change_case(): void {
		$text = 'zażółć GĘŚLĄ jaźń';
		$this->assertSame( 'zażółć gęślą jaźń', Attributes::change_case( $text, 'lower' ) );
		$this->assertSame( 'ZAŻÓŁĆ GĘŚLĄ JAŹŃ', Attributes::change_case( $text, 'upper' ) );
		$this->assertSame( 'Zażółć Gęślą Jaźń', Attributes::change_case( $text, 'title' ) );
		$this->assertSame( 'Zażółć gęślą jaźń', Attributes::change_case( $text, 'sentence' ) );
		$this->assertSame( $text, Attributes::change_case( $text, 'none' ) );
	}

	public function test_content_alt_and_title_injection(): void {
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Kubki ręcznie malowane' ) );
		$id   = $this->attachment( '2026/11/kubek-niebieski_01.jpg', $post->ID, array( 'post_title' => 'Niebieski kubek' ) );
		$html = '<p>Intro</p>'
			. '<img src="https://example.org/wp-content/uploads/2026/11/kubek-niebieski_01-300x200.jpg" class="wp-image-' . $id . '" width="300">'
			. '<IMG SRC="https://cdn.example.com/a/zielony-talerz.png">'
			. '<img src="https://example.org/x/existing.jpg" alt="Własny opis">'
			. '<img src="https://example.org/x/empty-alt.jpg" alt="">'
			. '<img src="https://example.org/x/bool-alt.jpg" alt>';

		$out = Attributes::filter_html( $html, $post );
		$this->assertStringContainsString( '<img alt="Kubek niebieski 01" src="https://example.org/wp-content/uploads/2026/11/kubek-niebieski_01-300x200.jpg" class="wp-image-' . $id . '" width="300">', $out );
		$this->assertStringContainsString( '<IMG alt="Zielony talerz" SRC="https://cdn.example.com/a/zielony-talerz.png">', $out );
		$this->assertStringContainsString( 'alt="Własny opis"', $out );
		$this->assertStringContainsString( 'src="https://example.org/x/empty-alt.jpg" alt="Empty alt"', $out );
		$this->assertStringContainsString( 'src="https://example.org/x/bool-alt.jpg" alt="Bool alt"', $out );
		$this->assertStringNotContainsString( 'title=', $out );
		$this->assertStringContainsString( '<p>Intro</p>', $out );

		// Titles, post variables and image variables, case change.
		Attributes::reset();
		$this->set_option( 'image', 'add_title', true );
		$this->set_option( 'image', 'title_template', '%imagetitle%' );
		$this->set_option( 'image', 'alt_template', '%title% %sep% %filename%' );
		$this->set_option( 'image', 'alt_case', 'lower' );
		$out = Attributes::filter_html( $html, $post );
		$this->assertStringContainsString( '<img alt="kubki ręcznie malowane - kubek niebieski 01" title="Niebieski kubek" src=', $out );
		$this->assertStringContainsString( 'alt="Własny opis"', $out, 'Existing alt untouched without overwrite.' );

		// Overwrite existing alt.
		Attributes::reset();
		$this->set_option( 'image', 'overwrite_alt', true );
		$this->set_option( 'image', 'alt_template', '%filename%' );
		$this->set_option( 'image', 'alt_case', 'upper' );
		$out = Attributes::filter_html( $html, $post );
		$this->assertStringContainsString( 'alt="EXISTING"', $out );

		// Case change of existing values only.
		Attributes::reset();
		$this->set_option( 'image', 'overwrite_alt', false );
		$this->set_option( 'image', 'add_alt', false );
		$this->set_option( 'image', 'add_title', false );
		$this->set_option( 'image', 'case_existing', true );
		$this->assertStringContainsString( 'alt="WŁASNY OPIS"', Attributes::filter_html( $html, $post ) );
	}

	public function test_the_content_filter_and_target_toggle(): void {
		$post = self::factory()->post->create(
			array(
				'post_title'   => 'Wpis',
				'post_content' => '<!-- wp:image --><figure class="wp-block-image"><img src="https://example.org/foto-gory.jpg" alt=""/></figure><!-- /wp:image -->',
			)
		);
		$this->go_to( get_permalink( $post ) );
		the_post();
		$this->assertStringContainsString( 'alt="Foto gory"', apply_filters( 'the_content', get_post_field( 'post_content', $post ) ) );
		$this->set_option( 'image', 'targets', array( 'attachment' ) );
		$this->assertStringContainsString( 'alt=""', apply_filters( 'the_content', get_post_field( 'post_content', $post ) ) );
		$this->assertSame( 'no images', Attributes::filter_html( 'no images' ) );
	}

	public function test_attachment_image_attributes(): void {
		$post = self::factory()->post->create_and_get( array( 'post_title' => 'Oferta' ) );
		$id   = $this->attachment( 'sala-konferencyjna.jpg', $post->ID );
		update_post_meta( $id, '_wp_attachment_metadata', array( 'width' => 800, 'height' => 600, 'file' => 'sala-konferencyjna.jpg' ) );
		$this->set_option( 'image', 'alt_template', '%filename% %sep% %title%' );
		$this->go_to( get_permalink( $post ) );
		the_post();
		$attr = apply_filters( 'wp_get_attachment_image_attributes', array( 'src' => 'x', 'alt' => '' ), get_post( $id ), 'full' );
		$this->assertSame( 'Sala konferencyjna - Oferta', $attr['alt'] );
		$attr = apply_filters( 'wp_get_attachment_image_attributes', array( 'src' => 'x', 'alt' => 'Mam opis' ), get_post( $id ), 'full' );
		$this->assertSame( 'Mam opis', $attr['alt'] );
		$this->set_option( 'image', 'targets', array( 'content' ) );
		$attr = apply_filters( 'wp_get_attachment_image_attributes', array( 'src' => 'x', 'alt' => '' ), get_post( $id ), 'full' );
		$this->assertSame( '', $attr['alt'] );
	}

	public function test_alt_and_title_on_upload(): void {
		$id = $this->attachment( '2026/11/zdjecie-biura_nr-2.jpg' );
		$this->assertSame( 'Zdjecie biura nr 2', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'Zdjecie biura nr 2', get_post( $id )->post_title );

		// A custom (IPTC) title is kept; template uses the parent post.
		$parent = self::factory()->post->create( array( 'post_title' => 'Realizacja' ) );
		$this->set_option( 'image', 'upload_alt_template', '%title% %filename%' );
		$id = $this->attachment( 'foto-1.jpg', $parent, array( 'post_title' => 'Widok z góry' ) );
		$this->assertSame( 'Widok z góry', get_post( $id )->post_title );
		$this->assertSame( 'Realizacja Foto 1', get_post_meta( $id, '_wp_attachment_image_alt', true ) );

		$this->set_option( 'image', 'upload_alt', false );
		$id = $this->attachment( 'bez-alt.jpg' );
		$this->assertSame( '', get_post_meta( $id, '_wp_attachment_image_alt', true ) );
	}

	public function test_find_and_replace(): void {
		$this->set_option( 'image', 'upload_alt', false );
		$a = $this->attachment( 'a.jpg', 0, array( 'post_title' => 'Kubek Firma X', 'post_excerpt' => 'Kubek od firmy X' ) );
		$b = $this->attachment( 'b.jpg', 0, array( 'post_title' => 'Talerz' ) );
		update_post_meta( $a, '_wp_attachment_image_alt', 'Kubek firma x' );
		update_post_meta( $b, '_wp_attachment_image_alt', 'Talerz Firma X' );

		$preview = Tools::replace_batch(
			array(
				'find'    => 'firma x',
				'replace' => 'MGraphics',
				'fields'  => array( 'alt', 'title' ),
				'dry_run' => true,
			)
		);
		$this->assertCount( 3, $preview['matches'] );
		$this->assertSame( 0, $preview['changed'] );
		$this->assertTrue( $preview['done'] );
		$this->assertSame( 'Kubek firma x', get_post_meta( $a, '_wp_attachment_image_alt', true ) );

		// Case sensitive: only exact matches.
		$exact = Tools::replace_batch(
			array(
				'find'           => 'Firma X',
				'replace'        => 'MGraphics',
				'fields'         => array( 'alt' ),
				'case_sensitive' => true,
				'dry_run'        => true,
			)
		);
		$this->assertCount( 1, $exact['matches'] );
		$this->assertSame( $b, $exact['matches'][0]['id'] );

		// Batches.
		$first = Tools::replace_batch(
			array(
				'find'    => 'firma x',
				'replace' => 'MGraphics',
				'fields'  => array( 'alt', 'title', 'caption' ),
				'dry_run' => false,
				'limit'   => 1,
			)
		);
		$this->assertFalse( $first['done'] );
		$this->assertSame( $a, $first['next_after_id'] );
		$second = Tools::replace_batch(
			array(
				'find'     => 'firma x',
				'replace'  => 'MGraphics',
				'fields'   => array( 'alt', 'title', 'caption' ),
				'dry_run'  => false,
				'limit'    => 1,
				'after_id' => $first['next_after_id'],
			)
		);
		$this->assertSame( 3, $first['changed'] + $second['changed'] );
		$this->assertSame( 'Kubek MGraphics', get_post_meta( $a, '_wp_attachment_image_alt', true ) );
		$this->assertSame( 'Kubek MGraphics', get_post( $a )->post_title );
		$this->assertSame( 'Kubek od firmy X', get_post( $a )->post_excerpt, 'No match in caption ("firmy").' );
		$this->assertSame( 'Talerz MGraphics', get_post_meta( $b, '_wp_attachment_image_alt', true ) );
	}

	public function test_missing_alt_report_and_fill(): void {
		$this->set_option( 'image', 'upload_alt', false );
		$a = $this->attachment( 'brak-opisu.jpg' );
		$b = $this->attachment( 'ma-opis.jpg' );
		$c = $this->attachment( 'pusty-opis.jpg' );
		update_post_meta( $b, '_wp_attachment_image_alt', 'Opis' );
		update_post_meta( $c, '_wp_attachment_image_alt', '  ' );
		$this->assertSame( 2, Tools::missing_count() );
		$this->assertSame( array( $c, $a ), Tools::missing() );

		$preview = Tools::fill_missing( true );
		$this->assertCount( 2, $preview['items'] );
		$this->assertSame( 2, Tools::missing_count() );
		$done = Tools::fill_missing( false );
		$this->assertSame( 2, $done['changed'] );
		$this->assertSame( 0, $done['remaining'] );
		$this->assertSame( 'Brak opisu', get_post_meta( $a, '_wp_attachment_image_alt', true ) );
	}

	public function test_rest_routes_require_capabilities(): void {
		do_action( 'rest_api_init' );
		$request = new \WP_REST_Request( 'POST', '/mg-seo/v1/image/replace' );
		$request->set_param( 'find', 'x' );
		$this->assertSame( 200, rest_do_request( $request )->get_status() );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_do_request( $request )->get_status() );
	}
}
