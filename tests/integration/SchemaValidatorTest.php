<?php
/**
 * Schema validator tests.
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Modules\Schema\Validator;

/**
 * Schema module tests.
 *
 * @covers \MGSEO\Modules\Schema\Validator
 */
class SchemaValidatorTest extends TestCase {

	/**
	 * Whether a message list mentions a property.
	 *
	 * @param array  $messages Messages.
	 * @param string $needle   Text.
	 */
	private function mentions( array $messages, string $needle ): bool {
		foreach ( $messages as $message ) {
			if ( str_contains( $message, $needle ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Product needs offers review or rating.
	 */
	public function test_product_needs_offers_review_or_rating(): void {
		$result = Validator::validate(
			array(
				'@type' => 'Product',
				'name'  => 'Chleb',
			)
		);
		$this->assertTrue( $this->mentions( $result['errors'], 'offers, review, aggregateRating' ) );
		$this->assertTrue( $this->mentions( $result['warnings'], 'image' ) );

		$result = Validator::validate(
			array(
				'@type'  => 'Product',
				'name'   => 'Chleb',
				'image'  => 'https://example.org/a.jpg',
				'offers' => array(
					'@type'        => 'Offer',
					'price'        => '12 zł',
					'availability' => 'https://schema.org/InStock',
				),
			)
		);
		$this->assertTrue( $this->mentions( $result['errors'], 'offers.priceCurrency' ) );
		$this->assertTrue( $this->mentions( $result['errors'], 'Price must be a number' ) );

		$result = Validator::validate(
			array(
				'@type'           => 'Product',
				'name'            => 'Chleb',
				'aggregateRating' => array(
					'@type'       => 'AggregateRating',
					'ratingValue' => 7,
					'reviewCount' => 3,
				),
			)
		);
		$this->assertTrue( $this->mentions( $result['errors'], 'outside the bestRating' ) );
	}

	/**
	 * Event rules.
	 */
	public function test_event_rules(): void {
		$result = Validator::validate(
			array(
				'@type' => 'MusicEvent',
				'name'  => 'Koncert',
			)
		);
		$this->assertTrue( $this->mentions( $result['errors'], 'startDate' ) );
		$this->assertTrue( $this->mentions( $result['errors'], 'location' ) );

		$result = Validator::validate(
			array(
				'@type'     => 'Event',
				'name'      => 'Koncert',
				'startDate' => '2026-12-05T20:00:00+01:00',
				'endDate'   => '2026-12-04T20:00:00+01:00',
				'location'  => array(
					'@type' => 'Place',
					'name'  => 'Klub',
				),
			)
		);
		$this->assertTrue( $this->mentions( $result['errors'], 'location.address' ) );
		$this->assertTrue( $this->mentions( $result['errors'], 'ends before it starts' ) );

		$result = Validator::validate(
			array(
				'@type'     => 'Event',
				'name'      => 'Webinar',
				'startDate' => '5 grudnia',
				'location'  => array(
					'@type' => 'VirtualLocation',
					'url'   => 'https://example.org/live',
				),
			)
		);
		$this->assertSame( array( 'Invalid date (use ISO 8601): startDate' ), $result['errors'] );
	}

	/**
	 * Faq howto video localbusiness review.
	 */
	public function test_faq_howto_video_localbusiness_review(): void {
		$result = Validator::validate(
			array(
				'@type'      => 'FAQPage',
				'mainEntity' => array(
					array(
						'@type' => 'Question',
						'name'  => 'Bez odpowiedzi?',
					),
				),
			)
		);
		$this->assertTrue( $this->mentions( $result['errors'], 'mainEntity.acceptedAnswer' ) );

		$result = Validator::validate(
			array(
				'@type'     => 'HowTo',
				'name'      => 'X',
				'totalTime' => '90 minutes',
			)
		);
		$this->assertTrue( $this->mentions( $result['errors'], 'step' ) );
		$this->assertTrue( $this->mentions( $result['errors'], 'Invalid duration' ) );

		$result = Validator::validate(
			array(
				'@type' => 'VideoObject',
				'name'  => 'Film',
			)
		);
		$this->assertTrue( $this->mentions( $result['errors'], 'thumbnailUrl' ) );
		$this->assertTrue( $this->mentions( $result['errors'], 'uploadDate' ) );

		$result = Validator::validate(
			array(
				'@type' => 'Dentist',
				'name'  => 'Gabinet',
			)
		);
		$this->assertTrue( $this->mentions( $result['errors'], 'address' ) );

		$result = Validator::validate(
			array(
				'@type'        => 'Review',
				'itemReviewed' => array(
					'@type' => 'Book',
					'name'  => 'Lalka',
				),
				'author'       => array( '@id' => 'https://example.org/#author-1' ),
				'reviewRating' => array(
					'@type'       => 'Rating',
					'ratingValue' => 4,
				),
			),
			array(
				array(
					'@type' => 'Person',
					'@id'   => 'https://example.org/#author-1',
					'name'  => 'Jan',
				),
			)
		);
		$this->assertSame( array(), $result['errors'], 'References are resolved against the graph' );
	}

	/**
	 * Article and breadcrumbs.
	 */
	public function test_article_and_breadcrumbs(): void {
		$result = Validator::validate( array( '@type' => 'BlogPosting' ) );
		$this->assertSame( array(), $result['errors'], 'Article has no required properties' );
		$this->assertTrue( $this->mentions( $result['warnings'], 'headline' ) );

		$result = Validator::validate(
			array(
				'@type'           => 'BreadcrumbList',
				'itemListElement' => array(
					array(
						'@type'    => 'ListItem',
						'position' => 1,
						'name'     => 'Home',
					),
					array(
						'@type'    => 'ListItem',
						'position' => 2,
						'name'     => 'Post',
					),
				),
			)
		);
		$this->assertTrue( $this->mentions( $result['errors'], 'item 1 needs an item URL' ) );
	}

	/**
	 * Rest endpoint.
	 */
	public function test_rest_endpoint(): void {
		$post = self::factory()->post->create( array( 'post_title' => 'Walidacja' ) );
		do_action( 'rest_api_init' ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- core hook.
		$request = new \WP_REST_Request( 'POST', '/mg-seo/v1/schema/validate' );
		$request->set_param( 'post_id', $post );
		$request->set_param(
			'entities',
			wp_json_encode(
				array(
					array(
						'type' => 'Product',
						'data' => array( 'name' => '%title%' ),
					),
				)
			)
		);

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		$this->assertSame( 403, rest_get_server()->dispatch( $request )->get_status() );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		$response = rest_get_server()->dispatch( $request );
		$this->assertSame( 200, $response->get_status() );
		$data = $response->get_data();
		$this->assertSame( 'Product', $data['results'][0]['type'] );
		$this->assertTrue( $this->mentions( $data['results'][0]['errors'], 'offers, review, aggregateRating' ) );
		$this->assertStringContainsString( '"name": "Walidacja"', $data['json'] );
	}
}
