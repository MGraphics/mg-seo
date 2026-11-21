<?php
/**
 * Local SEO tests.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Meta;
use MGSEO\Modules\Local\Business;
use MGSEO\Modules\Local\Kml;
use MGSEO\Modules\Local\Locations;
use MGSEO\Modules\Local\Module as Local;
use MGSEO\Options;

/**
 * @covers \MGSEO\Modules\Local\Module
 * @covers \MGSEO\Modules\Local\Business
 * @covers \MGSEO\Modules\Local\Renderer
 * @covers \MGSEO\Modules\Local\Kml
 */
class LocalSeoTest extends TestCase {

	/**
	 * Module instance.
	 *
	 * @var Local
	 */
	private Local $module;

	public function set_up(): void {
		parent::set_up();
		$this->module = new Local();
		$this->module->boot();
		$this->module->register_block();
		$this->module->compat_shortcodes();
	}

	/**
	 * Store a business in the settings.
	 *
	 * @param array $extra Extra values.
	 */
	private function business( array $extra = array() ): void {
		$values = array_merge(
			array(
				'type'        => 'Dentist',
				'name'        => 'Gabinet Uśmiech',
				'street'      => 'ul. Mariacka 10',
				'postal_code' => '40-014',
				'locality'    => 'Katowice',
				'region'      => 'śląskie',
				'country'     => 'PL',
				'lat'         => '50.2584',
				'lng'         => '19.0275',
				'phones'      => array(
					array(
						'number' => '+48 32 123 45 67',
						'type'   => 'customer service',
					),
					array(
						'number' => '+48 600 100 200',
						'type'   => 'reservations',
					),
				),
				'email'       => 'kontakt@example.com',
				'price_range' => '$$',
				'payment'     => 'Cash, BLIK',
				'area_served' => 'Katowice, Chorzów',
				'social'      => "https://www.facebook.com/usmiech\nhttps://www.instagram.com/usmiech",
				'vat_id'      => 'PL6340000000',
				'tax_id'      => '6340000000',
				'regon'       => '123456789',
				'hours'       => Business::sanitize_hours(
					array(
						'monday'    => array( 'ranges' => '09:00-17:00' ),
						'tuesday'   => array( 'ranges' => '09:00-17:00' ),
						'wednesday' => array( 'ranges' => '08:00-12:00, 13:00-19:00' ),
						'thursday'  => array( 'ranges' => '9.00 - 17.00' ),
						'friday'    => array( 'ranges' => '09:00-17:00' ),
						'saturday'  => array( 'mode' => '24h' ),
						'sunday'    => array( 'mode' => 'closed' ),
					)
				),
			),
			$extra
		);
		Options::set_group( 'local', $values );
		Options::flush_cache();
	}

	/**
	 * Organization node as the Schema module would pass it.
	 */
	private function org_node(): array {
		return array(
			'@type'  => 'Organization',
			'@id'    => home_url( '/#organization' ),
			'name'   => 'Site',
			'url'    => home_url( '/' ),
			'logo'   => array(
				'@type' => 'ImageObject',
				'@id'   => home_url( '/#logo' ),
				'url'   => 'https://example.org/logo.png',
			),
			'sameAs' => array( 'https://www.facebook.com/usmiech' ),
		);
	}

	public function test_opening_hours_specification_groups_days(): void {
		$this->business();
		$spec = Business::opening_hours( (array) Options::get( 'local', 'hours' ) );
		$this->assertSame(
			array(
				array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => array( 'Monday', 'Tuesday', 'Thursday', 'Friday' ),
					'opens'     => '09:00',
					'closes'    => '17:00',
				),
				array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => array( 'Wednesday' ),
					'opens'     => '08:00',
					'closes'    => '12:00',
				),
				array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => array( 'Wednesday' ),
					'opens'     => '13:00',
					'closes'    => '19:00',
				),
				array(
					'@type'     => 'OpeningHoursSpecification',
					'dayOfWeek' => array( 'Saturday' ),
					'opens'     => '00:00',
					'closes'    => '23:59',
				),
			),
			$spec
		);
		$this->assertSame( 'closed', Options::get( 'local', 'hours' )['sunday']['mode'] );
	}

	public function test_special_hours(): void {
		$text = Business::sanitize_special_hours( "2026-12-24 | 08:00-12:00\n2026-12-25 - 2026-12-26 | closed\nbad line" );
		$this->assertSame( "2026-12-24 | 08:00-12:00\n2026-12-25 - 2026-12-26 | closed", $text );
		$spec = Business::special_hours( $text );
		$this->assertCount( 2, $spec );
		$this->assertSame( '2026-12-26', $spec[1]['validThrough'] );
		$this->assertSame( '00:00', $spec[1]['opens'] );
		$this->assertSame( '00:00', $spec[1]['closes'] );
	}

	public function test_single_location_turns_organization_into_local_business(): void {
		$this->business();
		$node = apply_filters( 'mgseo/schema/organization', $this->org_node() );
		$this->assertSame( 'Dentist', $node['@type'] );
		$this->assertSame( home_url( '/#organization' ), $node['@id'] );
		$this->assertSame( 'Gabinet Uśmiech', $node['name'] );
		$this->assertSame( 'PostalAddress', $node['address']['@type'] );
		$this->assertSame( 'ul. Mariacka 10', $node['address']['streetAddress'] );
		$this->assertSame( '40-014', $node['address']['postalCode'] );
		$this->assertSame( 'PL', $node['address']['addressCountry'] );
		$this->assertSame( 50.2584, $node['geo']['latitude'] );
		$this->assertSame( '+48 32 123 45 67', $node['telephone'] );
		$this->assertCount( 2, $node['contactPoint'] );
		$this->assertSame( 'reservations', $node['contactPoint'][1]['contactType'] );
		$this->assertSame( '$$', $node['priceRange'] );
		$this->assertSame( array( 'Katowice', 'Chorzów' ), $node['areaServed'] );
		$this->assertSame( 'PL6340000000', $node['vatID'] );
		$this->assertSame( '6340000000', $node['taxID'] );
		$this->assertSame( 'REGON', $node['identifier']['propertyID'] );
		$this->assertSame( array( 'https://www.facebook.com/usmiech', 'https://www.instagram.com/usmiech' ), $node['sameAs'] );
		$this->assertSame( 'https://example.org/logo.png', $node['logo']['url'], 'Existing logo is kept.' );
		$this->assertSame( array( '@id' => home_url( '/#logo' ) ), $node['image'] );
		$this->assertCount( 4, $node['openingHoursSpecification'] );
		$this->assertStringStartsWith( 'https://www.google.com/maps/search/?api=1&query=', $node['hasMap'] );
	}

	public function test_person_node_and_empty_business_are_left_alone(): void {
		$person = array(
			'@type' => 'Person',
			'@id'   => home_url( '/#person' ),
		);
		$this->business();
		$this->assertSame( $person, apply_filters( 'mgseo/schema/organization', $person ) );
		Options::set_group( 'local', array() );
		Options::flush_cache();
		$this->assertSame( 'Organization', apply_filters( 'mgseo/schema/organization', $this->org_node() )['@type'] );
	}

	/**
	 * Create a location post.
	 *
	 * @param array $data Business data.
	 */
	private function location( array $data = array() ): \WP_Post {
		$this->set_option( 'local', 'multiple', true );
		Locations::register();
		flush_rewrite_rules( false );
		$post = self::factory()->post->create_and_get(
			array(
				'post_type'  => Locations::POST_TYPE,
				'post_title' => 'Oddział Gliwice',
				'post_name'  => 'gliwice',
			)
		);
		Meta::set_post(
			$post->ID,
			Business::META,
			$data + array(
				'type'     => 'ProfessionalService',
				'street'   => 'ul. Zwycięstwa 1',
				'locality' => 'Gliwice',
				'country'  => 'PL',
				'lat'      => '50.2945',
				'lng'      => '18.6714',
				'phones'   => array(
					array(
						'number' => '+48 32 000 00 00',
						'type'   => 'sales',
					),
				),
				'hours'    => array(
					'monday' => array(
						'mode'   => 'open',
						'ranges' => '08:00-16:00',
					),
				),
			)
		);
		return $post;
	}

	public function test_multiple_locations_emit_location_node_on_its_page(): void {
		$this->business();
		$post = $this->location();
		$url  = get_permalink( $post );
		$this->assertSame( home_url( '/locations/gliwice/' ), $url );
		$this->go_to( $url );
		$this->assertTrue( is_singular( Locations::POST_TYPE ) );

		$webpage = array(
			'@type' => 'WebPage',
			'@id'   => $url . '#webpage',
		);
		$nodes   = apply_filters( 'mgseo/schema/graph', array( $this->org_node(), $webpage ), array() );
		$this->assertCount( 3, $nodes );
		$node = $nodes[2];
		$this->assertSame( 'ProfessionalService', $node['@type'] );
		$this->assertSame( $url . '#localbusiness-' . $post->ID, $node['@id'] );
		$this->assertSame( 'Oddział Gliwice', $node['name'] );
		$this->assertSame( $url, $node['url'] );
		$this->assertSame( array( '@id' => home_url( '/#organization' ) ), $node['parentOrganization'] );
		$this->assertSame( array( '@id' => $url . '#webpage' ), $node['mainEntityOfPage'] );
		$this->assertSame( array( 'Monday' ), $node['openingHoursSpecification'][0]['dayOfWeek'] );
		$this->assertSame( array( '@id' => $node['@id'] ), $nodes[1]['about'] );

		// In multi-location mode the site Organization keeps its type and loses place-only properties.
		$org = apply_filters( 'mgseo/schema/organization', $this->org_node() );
		$this->assertSame( 'Organization', $org['@type'] );
		$this->assertArrayHasKey( 'address', $org );
		$this->assertArrayNotHasKey( 'openingHoursSpecification', $org );
		$this->assertArrayNotHasKey( 'geo', $org );

		// Neutral front-end classes.
		$this->assertNotContains( 'single-' . Locations::POST_TYPE, get_body_class() );
		$this->assertContains( 'single-location', get_body_class() );
	}

	public function test_location_node_skipped_when_user_defined_local_business_exists(): void {
		$post = $this->location();
		Meta::set_post(
			$post->ID,
			'schema',
			wp_json_encode(
				array(
					array(
						'id'     => 'a1',
						'type'   => 'Dentist',
						'custom' => false,
						'data'   => array( 'name' => 'Own' ),
					),
				)
			)
		);
		$this->go_to( get_permalink( $post ) );
		$nodes = apply_filters( 'mgseo/schema/graph', array( $this->org_node() ), array() );
		$this->assertCount( 1, $nodes );
	}

	public function test_json_ld_output_on_location_page(): void {
		$post = $this->location();
		$url  = get_permalink( $post );
		$head = $this->head_for( $url );
		$ld   = $this->json_ld( $head );
		if ( \MGSEO\Plugin::instance()->module( 'schema' ) ) {
			// Inside the Schema module graph.
			$graph = $ld[0]['@graph'] ?? array();
			$nodes = array_values( array_filter( $graph, static fn( $n ) => ( $n['@id'] ?? '' ) === $url . '#localbusiness-' . $post->ID ) );
			$this->assertCount( 1, $nodes );
			$this->assertSame( 'ProfessionalService', $nodes[0]['@type'] );
		} else {
			$nodes = array_values( array_filter( $ld, static fn( $d ) => 'ProfessionalService' === ( $d['@type'] ?? '' ) ) );
			$this->assertCount( 1, $nodes );
			$this->assertSame( 'https://schema.org', $nodes[0]['@context'] );
			$this->assertArrayNotHasKey( 'parentOrganization', $nodes[0] );
		}
		$this->assertStringNotContainsString( 'mgseo', $head );
	}

	public function test_contact_page_becomes_contact_page(): void {
		$page = self::factory()->post->create_and_get(
			array(
				'post_type'  => 'page',
				'post_title' => 'Kontakt',
			)
		);
		$this->set_option( 'local', 'contact_page', $page->ID );
		$this->go_to( get_permalink( $page ) );
		$nodes = apply_filters(
			'mgseo/schema/graph',
			array(
				array(
					'@type' => 'WebPage',
					'@id'   => get_permalink( $page ) . '#webpage',
				),
			),
			array()
		);
		$this->assertSame( 'ContactPage', $nodes[0]['@type'] );
		$this->assertSame( 'ContactPage', apply_filters( 'mgseo/schema/webpage_type', 'WebPage', $page ) );
	}

	public function test_shortcode_html(): void {
		$this->business( array( 'special_hours' => gmdate( 'Y' ) + 1 . '-12-24 | 08:00-12:00' ) );
		$html = do_shortcode( '[mgseo_local]' );
		$this->assertStringStartsWith( '<div class="contact-info"><h3>Gabinet Uśmiech</h3><address>', $html );
		$this->assertStringContainsString( '<span>ul. Mariacka 10<br />40-014 Katowice<br />śląskie</span>', $html );
		$this->assertStringContainsString( '<a href="tel:+48321234567">+48 32 123 45 67</a>', $html );
		$this->assertStringContainsString( 'Reservations: <a href="tel:+48600100200">', $html );
		$this->assertStringContainsString( '<table><caption>Opening hours</caption><tbody>', $html );
		$this->assertStringContainsString( '<th scope="row">Wednesday</th><td>08:00–12:00, 13:00–19:00</td>', $html );
		$this->assertStringContainsString( '<th scope="row">Saturday</th><td>Open 24 hours</td>', $html );
		$this->assertStringContainsString( '<th scope="row">Sunday</th><td>Closed</td>', $html );
		$this->assertStringContainsString( '<td>08:00–12:00</td>', $html, 'Upcoming special hours are listed.' );
		$this->assertStringContainsString( 'NIP: 6340000000', $html );
		$this->assertStringContainsString( '<a href="https://www.google.com/maps/search/?api=1&#038;query=50.2584%2C19.0275" rel="noopener" target="_blank">Show on map</a>', $html );
		$this->assertStringNotContainsString( 'iframe', $html );
		$this->assertStringNotContainsString( 'mgseo', $html );
		$this->assertStringNotContainsString( 'mg-seo', $html );

		$hours_only = do_shortcode( '[mgseo_local show="hours" class=""]' );
		$this->assertStringStartsWith( '<div><table>', $hours_only );
		$this->assertStringNotContainsString( '<address>', $hours_only );

		// Embeds need the administrator's opt-in.
		$this->assertStringNotContainsString( '<iframe', do_shortcode( '[mgseo_local show="map" map="embed"]' ) );
		$this->set_option( 'local', 'map_embed_allowed', true );
		$this->set_option( 'local', 'map_provider', 'osm' );
		$embed = do_shortcode( '[mgseo_local show="map" map="embed"]' );
		$this->assertStringContainsString( '<iframe src="https://www.openstreetmap.org/export/embed.html?bbox=', $embed );
		$this->assertStringContainsString( 'loading="lazy"', $embed );

		// Rank Math compatible shortcode and the block.
		$this->assertStringContainsString( '<address>', do_shortcode( '[rank_math_contact_info show="address"]' ) );
		$this->assertStringContainsString( '<table>', render_block( array( 'blockName' => 'mg-seo/local', 'attrs' => array( 'show' => 'hours' ), 'innerBlocks' => array(), 'innerHTML' => '', 'innerContent' => array() ) ) );
	}

	public function test_shortcode_lists_all_locations(): void {
		$first  = $this->location();
		$second = self::factory()->post->create(
			array(
				'post_type'  => Locations::POST_TYPE,
				'post_title' => 'Oddział Zabrze',
			)
		);
		Meta::set_post(
			$second,
			Business::META,
			array(
				'street'   => 'ul. Wolności 5',
				'locality' => 'Zabrze',
			)
		);
		$html = do_shortcode( '[mgseo_local location="all" show="name,address"]' );
		$this->assertSame( 2, substr_count( $html, '<address>' ) );
		$this->assertStringContainsString( '<h3><a href="' . get_permalink( $first ) . '">Oddział Gliwice</a></h3>', $html );
		$this->assertStringContainsString( 'Zabrze', do_shortcode( '[mgseo_local location="' . $second . '" show="address"]' ) );
	}

	public function test_kml_document_is_valid(): void {
		$first = $this->location();
		$other = self::factory()->post->create(
			array(
				'post_type'  => Locations::POST_TYPE,
				'post_title' => 'Biuro & Co',
			)
		);
		Meta::set_post( $other, Business::META, array( 'locality' => 'Bytom' ) );
		$xml = Kml::document();
		$dom = new \DOMDocument();
		$this->assertTrue( $dom->loadXML( $xml ) );
		$this->assertSame( 'http://www.opengis.net/kml/2.2', $dom->documentElement->namespaceURI );
		$placemarks = $dom->getElementsByTagName( 'Placemark' );
		$this->assertSame( 2, $placemarks->length );
		$this->assertSame( '18.6714,50.2945,0', $dom->getElementsByTagName( 'coordinates' )->item( 0 )->textContent );
		$this->assertStringContainsString( 'Biuro &amp; Co', $xml );
		$this->assertSame( 1, $dom->getElementsByTagName( 'Point' )->length, 'Locations without coordinates have no Point.' );
		$this->assertStringContainsString( (string) get_permalink( $first ), $xml );

		$sitemap = new \DOMDocument();
		$this->assertTrue( $sitemap->loadXML( Kml::sitemap() ) );
		$locs = array_map( static fn( $n ) => $n->textContent, iterator_to_array( $sitemap->getElementsByTagName( 'loc' ) ) );
		$this->assertSame( array( home_url( '/locations/' ), get_permalink( $other ), get_permalink( $first ), home_url( '/locations.kml' ) ), $locs );

		$entries = apply_filters( 'mgseo/sitemap/index', array() );
		$this->assertSame( home_url( '/sitemap-locations.xml' ), end( $entries )['loc'] );
		$this->assertNotContains( Locations::POST_TYPE, apply_filters( 'mgseo/sitemap/post_types', array( 'post', Locations::POST_TYPE ) ) );

		$this->module->rewrites();
		flush_rewrite_rules( false );
		$rules = get_option( 'rewrite_rules' );
		$this->assertArrayHasKey( '^locations\.kml$', $rules );
		$this->assertArrayHasKey( '^sitemap-locations\.xml$', $rules );
	}

	public function test_location_meta_box_sanitizes(): void {
		$data = \MGSEO\Modules\Local\Admin::sanitize_data(
			array(
				'type'   => 'NotAType',
				'lat'    => '50,123',
				'email'  => 'bad email@@',
				'phones' => array(
					array(
						'number' => '+48 <b>111</b>',
						'type'   => 'sales',
					),
					array( 'number' => '' ),
				),
				'social' => "javascript:alert(1)\nhttps://x.com/a",
			)
		);
		$this->assertSame( 'LocalBusiness', $data['type'] );
		$this->assertSame( '50.123', $data['lat'] );
		$this->assertArrayNotHasKey( 'email', $data );
		$this->assertSame(
			array(
				array(
					'number' => '+48 111',
					'type'   => 'sales',
				),
			),
			$data['phones']
		);
		$this->assertSame( 'https://x.com/a', $data['social'] );
		$this->assertSame( 'https://www.openstreetmap.org/?mlat=1&mlon=2#map=17/1/2', Business::map_link( array( 'lat' => '1', 'lng' => '2' ), 'osm' ) );
	}
}
