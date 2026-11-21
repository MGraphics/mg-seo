<?php
/**
 * Schema generator, templates and blocks tests.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Meta;
use MGSEO\Modules\Schema\Blocks;
use MGSEO\Modules\Schema\Entities;
use MGSEO\Modules\Schema\Templates;
use MGSEO\Plugin;

/**
 * Schema module tests.
 *
 * @covers \MGSEO\Modules\Schema\Entities
 * @covers \MGSEO\Modules\Schema\Templates
 * @covers \MGSEO\Modules\Schema\Blocks
 */
class SchemaGeneratorTest extends TestCase {

	/**
	 * Store entities on a post the way the meta box does.
	 *
	 * @param int   $post_id  Post id.
	 * @param array $entities Entities.
	 * @return array Errors.
	 */
	private function save_entities( int $post_id, array $entities ): array {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		Plugin::instance()->module( 'schema' )->save_tab(
			$post_id,
			array(
				'schema_tab'     => '1',
				'schema_changed' => '1',
				'schema_json'    => wp_json_encode( $entities ),
			)
		);
		$errors = get_transient( 'mgseo_schema_errors_' . get_current_user_id() );
		delete_transient( 'mgseo_schema_errors_' . get_current_user_id() );
		return is_array( $errors ) ? $errors : array();
	}

	/**
	 * Graph of a post: nodes by @id and all scripts.
	 *
	 * @param int $post_id Post id.
	 * @return array{0: array<string, array>, 1: array}
	 */
	private function graph( int $post_id ): array {
		$scripts = $this->json_ld( $this->head_for( get_permalink( $post_id ) ) );
		$nodes   = array();
		foreach ( $scripts[0]['@graph'] as $i => $node ) {
			$nodes[ $node['@id'] ?? 'n' . $i ] = $node;
		}
		return array( $nodes, $scripts );
	}

	/**
	 * Storage format and default replacement.
	 */
	public function test_storage_format_and_default_replacement(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'Pytania' ) );
		$this->save_entities(
			$post,
			array(
				array(
					'id'   => 'a1',
					'type' => 'FAQPage',
					'data' => array(
						'mainEntity' => array(
							array(
								'name'           => 'Ile kosztuje strona?',
								'acceptedAnswer' => array( 'text' => 'Od <strong>3000 zł</strong>.<script>x</script>' ),
								'evil'           => 'dropped',
							),
							array( 'name' => '' ),
						),
					),
				),
			)
		);
		$raw = get_post_meta( $post, '_mgseo_schema', true );
		$this->assertIsString( $raw );
		$stored = json_decode( $raw, true );
		$this->assertSame( 'a1', $stored[0]['id'] );
		$this->assertSame( 'FAQPage', $stored[0]['type'] );
		$this->assertFalse( $stored[0]['custom'] );
		$this->assertCount( 1, $stored[0]['data']['mainEntity'] );
		$this->assertSame( 'Question', $stored[0]['data']['mainEntity'][0]['@type'] );
		$this->assertArrayNotHasKey( 'evil', $stored[0]['data']['mainEntity'][0] );
		$this->assertSame( 'Od <strong>3000 zł</strong>.x', $stored[0]['data']['mainEntity'][0]['acceptedAnswer']['text'] );

		$url           = get_permalink( $post );
		list( $nodes ) = $this->graph( $post );
		$this->assertArrayNotHasKey( $url . '#article', $nodes, 'Stored schema replaces the post type default' );
		$faq = $nodes[ $url . '#faq' ];
		$this->assertSame( 'FAQPage', $faq['@type'] );
		$this->assertSame( 'Answer', $faq['mainEntity'][0]['acceptedAnswer']['@type'] );

		// Empty list = explicitly no schema; reset = default again.
		$this->save_entities( $post, array() );
		list( $nodes ) = $this->graph( $post );
		$this->assertArrayNotHasKey( $url . '#faq', $nodes );
		$this->assertArrayNotHasKey( $url . '#article', $nodes );
		Plugin::instance()->module( 'schema' )->save_tab(
			$post,
			array(
				'schema_tab'     => '1',
				'schema_changed' => '1',
				'schema_reset'   => '1',
			)
		);
		list( $nodes ) = $this->graph( $post );
		$this->assertArrayHasKey( $url . '#article', $nodes );
	}

	/**
	 * Howto product event service.
	 */
	public function test_howto_product_event_service(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'Kurs pieczenia' ) );
		$this->save_entities(
			$post,
			array(
				array(
					'type' => 'HowTo',
					'data' => array(
						'name'      => '%title%',
						'totalTime' => '90',
						'supply'    => array( array( 'name' => 'Mąka' ) ),
						'tool'      => array( array( 'name' => 'Piekarnik' ) ),
						'step'      => array(
							array(
								'name' => 'Wymieszaj',
								'text' => 'Wymieszaj składniki.',
							),
						),
					),
				),
				array(
					'type' => 'Product',
					'data' => array(
						'name'   => 'Chleb',
						'sku'    => 'CH-1',
						'brand'  => array( 'name' => 'Piekarnia' ),
						'offers' => array(
							'price'           => '12,50',
							'priceCurrency'   => 'PLN',
							'availability'    => 'https://schema.org/InStock',
							'priceValidUntil' => '2027-01-31',
						),
					),
				),
				array(
					'type' => 'Event',
					'data' => array(
						'@type'               => 'FoodEvent',
						'name'                => 'Warsztaty',
						'startDate'           => '2026-12-05T10:00',
						'endDate'             => '2026-12-05T14:00',
						'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
						'location'            => array(
							'@type'   => 'Place',
							'name'    => 'Piekarnia',
							'address' => array(
								'streetAddress'   => 'Długa 1',
								'addressLocality' => 'Kraków',
							),
						),
					),
				),
				array(
					'type' => 'Service',
					'data' => array(
						'name'        => 'Catering',
						'serviceType' => 'Catering',
						'areaServed'  => "Kraków\nWarszawa",
					),
				),
			)
		);
		$url           = get_permalink( $post );
		list( $nodes ) = $this->graph( $post );

		$howto = $nodes[ $url . '#howto' ];
		$this->assertSame( 'Kurs pieczenia', $howto['name'] );
		$this->assertSame( 'PT1H30M', $howto['totalTime'] );
		$this->assertSame( 'HowToSupply', $howto['supply'][0]['@type'] );
		$this->assertSame( 'HowToStep', $howto['step'][0]['@type'] );

		$product = $nodes[ $url . '#product' ];
		$this->assertSame( 'Offer', $product['offers']['@type'] );
		$this->assertSame( 12.5, $product['offers']['price'] );
		$this->assertSame( $url, $product['offers']['url'] );
		$this->assertSame( $url, $product['url'] );
		$this->assertSame(
			array(
				'@type' => 'Brand',
				'name'  => 'Piekarnia',
			),
			$product['brand']
		);

		$event = $nodes[ $url . '#event' ];
		$this->assertSame( 'FoodEvent', $event['@type'] );
		$this->assertMatchesRegularExpression( '/^2026-12-05T10:00:00[+-]\d{2}:\d{2}$/', $event['startDate'] );
		$this->assertSame( 'PostalAddress', $event['location']['address']['@type'] );

		$service = $nodes[ $url . '#service' ];
		$this->assertSame( array( '@id' => home_url( '/#organization' ) ), $service['provider'] );
		$this->assertSame( array( 'Kraków', 'Warszawa' ), $service['areaServed'] );
	}

	/**
	 * Custom json ld.
	 */
	public function test_custom_json_ld(): void {
		$post   = self::factory()->post->create( array( 'post_title' => 'Własny' ) );
		$errors = $this->save_entities(
			$post,
			array(
				array(
					'id'     => 'c1',
					'custom' => true,
					'data'   => '{"@type":"Dataset","name":"%title% data","url":"https://example.org/d"}',
				),
				array(
					'id'     => 'c2',
					'custom' => true,
					'data'   => '{"@context":"https://schema.org","@type":"SpecialAnnouncement","name":"Ogłoszenie"}',
				),
				array(
					'id'     => 'c3',
					'custom' => true,
					'data'   => '{"@type": "Broken",',
				),
			)
		);
		$this->assertCount( 1, $errors, 'Invalid JSON must be reported' );
		$stored = Entities::stored( $post );
		$this->assertCount( 2, $stored, 'Invalid JSON entity must not be stored' );
		$this->assertTrue( $stored[0]['custom'] );
		$this->assertSame( 'Dataset', $stored[0]['type'] );

		list( $nodes, $scripts ) = $this->graph( $post );
		$types                   = array_column( array_values( $nodes ), '@type' );
		$this->assertContains( 'Dataset', $types );
		$dataset = array_values( array_filter( $nodes, static fn( $n ) => 'Dataset' === $n['@type'] ) )[0];
		$this->assertSame( 'Własny data', $dataset['name'] );
		$this->assertCount( 2, $scripts );
		$this->assertSame( 'SpecialAnnouncement', $scripts[1]['@type'] );

		// Re-saving with the entity still broken keeps the previous valid version.
		$errors = $this->save_entities(
			$post,
			array(
				array(
					'id'     => 'c1',
					'custom' => true,
					'data'   => 'nope',
				),
			)
		);
		$this->assertCount( 1, $errors );
		$this->assertSame( 'Dataset', Entities::stored( $post )[0]['type'] );
	}

	/**
	 * Templates with conditions.
	 */
	public function test_templates_with_conditions(): void {
		$news = self::factory()->category->create( array( 'slug' => 'news' ) );
		$a    = self::factory()->post->create( array( 'post_title' => 'A' ) );
		$b    = self::factory()->post->create(
			array(
				'post_title'    => 'B',
				'post_category' => array( $news ),
			)
		);
		$page = self::factory()->post->create( array( 'post_type' => 'page' ) );
		$this->set_option(
			'schema',
			'templates',
			Templates::sanitize(
				wp_json_encode(
					array(
						array(
							'name'       => 'Firma',
							'type'       => 'Service',
							'data'       => array( 'name' => 'Usługa %title%' ),
							'conditions' => array(
								array(
									'include' => true,
									'kind'    => 'post_type',
									'value'   => 'post',
								),
								array(
									'include' => false,
									'kind'    => 'taxonomy',
									'value'   => 'category:news',
								),
							),
						),
						array(
							'name'       => 'Strona',
							'type'       => 'Person',
							'data'       => array( 'name' => 'Ja' ),
							'conditions' => array(
								array(
									'include' => true,
									'kind'    => 'post',
									'value'   => (string) $page,
								),
							),
						),
						array(
							'name'       => 'Nigdzie',
							'type'       => 'Person',
							'data'       => array( 'name' => 'Nikt' ),
							'conditions' => array(),
						),
					)
				)
			)
		);
		$templates = \MGSEO\Options::get( 'schema', 'templates' );
		$this->assertCount( 3, $templates );
		$this->assertNotEmpty( $templates[0]['id'] );

		list( $nodes ) = $this->graph( $a );
		$this->assertSame( 'Usługa A', $nodes[ get_permalink( $a ) . '#service' ]['name'] );
		$this->assertArrayHasKey( get_permalink( $a ) . '#article', $nodes, 'Templates add to the default schema' );

		list( $nodes ) = $this->graph( $b );
		$this->assertArrayNotHasKey( get_permalink( $b ) . '#service', $nodes, 'Excluded by taxonomy condition' );

		list( $nodes ) = $this->graph( $page );
		$this->assertSame( 'Ja', $nodes[ get_permalink( $page ) . '#person' ]['name'] );
		$this->assertSame( array(), array_filter( $nodes, static fn( $n ) => 'Nikt' === ( $n['name'] ?? '' ) ), 'Template without conditions is never applied' );

		Meta::set_post( $a, 'schema_no_templates', true );
		list( $nodes ) = $this->graph( $a );
		$this->assertArrayNotHasKey( get_permalink( $a ) . '#service', $nodes, 'Post can disable templates' );

		// Same type already on the post: template skipped.
		Meta::set_post( $a, 'schema_no_templates', false );
		$this->save_entities(
			$a,
			array(
				array(
					'type' => 'Service',
					'data' => array( 'name' => 'Własna usługa' ),
				),
			)
		);
		list( $nodes ) = $this->graph( $a );
		$this->assertSame( 'Własna usługa', $nodes[ get_permalink( $a ) . '#service' ]['name'] );
		$this->assertArrayNotHasKey( get_permalink( $a ) . '#service-2', $nodes );
	}

	/**
	 * Faq and howto blocks.
	 */
	public function test_faq_and_howto_blocks(): void {
		$faq_attrs   = array(
			'questions' => array(
				array(
					'id'       => 'q1',
					'question' => 'Czy robicie sklepy?',
					'answer'   => 'Tak, na <em>WooCommerce</em>.',
				),
				array(
					'question' => 'Pusta',
					'answer'   => '',
				),
			),
		);
		$howto_attrs = array(
			'minutes'  => 45,
			'supplies' => array( 'Farba' ),
			'steps'    => array(
				array(
					'title' => 'Przygotuj',
					'text'  => 'Oklej ściany.',
				),
				array(
					'title' => 'Maluj',
					'text'  => 'Dwie warstwy.',
				),
			),
		);
		$content     = serialize_blocks(
			array(
				array(
					'blockName'    => 'mg-seo/faq',
					'attrs'        => $faq_attrs,
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				),
				array(
					'blockName'    => 'mg-seo/howto',
					'attrs'        => $howto_attrs,
					'innerBlocks'  => array(),
					'innerHTML'    => '',
					'innerContent' => array(),
				),
			)
		);
		$post        = self::factory()->post->create(
			array(
				'post_title'   => 'Malowanie',
				'post_content' => wp_slash( $content ),
			)
		);

		$html = do_blocks( $content );
		$this->assertStringContainsString( '<div class="faq">', $html );
		$this->assertStringContainsString( '<h3 class="faq-question">Czy robicie sklepy?</h3>', $html );
		$this->assertStringNotContainsString( 'Pusta', $html );
		$this->assertStringContainsString( '<ol class="howto-steps">', $html );
		$this->assertStringContainsString( 'id="step-2"', $html );
		$this->assertDoesNotMatchRegularExpression( '/mgseo|mg-seo|wp-block-mg/i', $html );

		$entities = Blocks::entities( get_post( $post ) );
		$this->assertSame( array( 'FAQPage', 'HowTo' ), array_column( $entities, 'type' ) );

		$url           = get_permalink( $post );
		list( $nodes ) = $this->graph( $post );
		$faq           = $nodes[ $url . '#faq' ];
		$this->assertCount( 1, $faq['mainEntity'] );
		$this->assertSame( 'Tak, na <em>WooCommerce</em>.', $faq['mainEntity'][0]['acceptedAnswer']['text'] );
		$howto = $nodes[ $url . '#howto' ];
		$this->assertSame( 'Malowanie', $howto['name'] );
		$this->assertSame( 'PT45M', $howto['totalTime'] );
		$this->assertSame( $url . '#step-1', $howto['step'][0]['url'] );
		$this->assertArrayHasKey( $url . '#article', $nodes );

		// Generator FAQ + block FAQ merge into one FAQPage.
		$this->save_entities(
			$post,
			array(
				array(
					'type' => 'FAQPage',
					'data' => array(
						'mainEntity' => array(
							array(
								'name'           => 'Inne?',
								'acceptedAnswer' => array( 'text' => 'Tak.' ),
							),
						),
					),
				),
			)
		);
		list( $nodes ) = $this->graph( $post );
		$this->assertCount( 2, $nodes[ $url . '#faq' ]['mainEntity'] );
		$this->assertCount( 1, array_filter( $nodes, static fn( $n ) => 'FAQPage' === $n['@type'] ) );
	}

	/**
	 * Duration and date normalisation.
	 */
	public function test_duration_and_date_normalisation(): void {
		$this->assertSame( 'PT1H30M', Entities::iso_duration( '1h 30m' ) );
		$this->assertSame( 'PT2H', Entities::iso_duration( '2:00' ) );
		$this->assertSame( 'PT45M', Entities::iso_duration( 'PT45M' ) );
		$this->assertSame( 'P1DT2H', Entities::iso_duration( '1 day 2 hours' ) );
		$this->assertSame( '', Entities::iso_duration( 'soon' ) );
		$this->assertSame( '2026-01-02', Entities::iso_date( '2026-01-02' ) );
		$this->assertSame( '', Entities::iso_date( 'not a date at all' ) );
		$this->assertSame( home_url( '/x/' ), Entities::absolute_url( '/x/' ) );
		$this->assertSame( '', Entities::absolute_url( 'javascript:alert(1)' ) );
	}
}
