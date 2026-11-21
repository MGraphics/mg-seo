<?php
/**
 * WooCommerce SEO tests (need MGSEO_TEST_WOOCOMMERCE=1; skipped otherwise).
 *
 * @package MGSEO
 */

namespace MGSEO\Tests;

use MGSEO\Meta;
use MGSEO\Modules\WooCommerce\Module as Woo;
use MGSEO\Modules\WooCommerce\Permalinks;
use MGSEO\Modules\WooCommerce\Product;
use MGSEO\Plugin;
use MGSEO\Variables;

/**
 * @covers \MGSEO\Modules\WooCommerce\Module
 * @covers \MGSEO\Modules\WooCommerce\Product
 * @covers \MGSEO\Modules\WooCommerce\Permalinks
 */
class WooCommerceSeoTest extends TestCase {

	public function set_up(): void {
		parent::set_up();
		if ( ! class_exists( 'WooCommerce' ) ) {
			$this->markTestSkipped( 'WooCommerce is not loaded (run with -e MGSEO_TEST_WOOCOMMERCE=1).' );
		}
		\WC_Post_Types::register_taxonomies();
		global $wp_rewrite;
		if ( post_type_exists( 'product' ) && ! isset( $wp_rewrite->extra_permastructs['product'] ) ) {
			// Registered before pretty permalinks were enabled: register again to get the permastruct.
			unregister_post_type( 'product' );
		}
		\WC_Post_Types::register_post_types();
		if ( class_exists( 'WC_Brands' ) && ! taxonomy_exists( 'product_brand' ) ) {
			\WC_Brands::init_taxonomy();
		}
		update_option( 'woocommerce_currency', 'PLN' );
		update_option( 'woocommerce_default_country', 'PL:SL' );
		update_option( 'woocommerce_price_num_decimals', 2 );
		update_option( 'woocommerce_calc_taxes', 'no' );
		Permalinks::reset();
		flush_rewrite_rules( false );
	}

	/**
	 * Create a simple product.
	 *
	 * @param array $args Overrides.
	 */
	private function simple( array $args = array() ): \WC_Product_Simple {
		$product = new \WC_Product_Simple();
		$product->set_name( $args['name'] ?? 'Kubek ceramiczny' );
		$product->set_slug( $args['slug'] ?? 'kubek-ceramiczny' );
		$product->set_short_description( 'Kubek 330 ml.' );
		$product->set_description( 'Biały kubek ceramiczny.' );
		$product->set_regular_price( $args['regular'] ?? '49' );
		if ( isset( $args['sale'] ) ) {
			$product->set_sale_price( $args['sale'] );
			$product->set_date_on_sale_to( '2030-06-30 23:59:59' );
		}
		$product->set_sku( $args['sku'] ?? 'MUG-001' );
		$product->set_stock_status( $args['stock'] ?? 'instock' );
		$product->set_catalog_visibility( $args['visibility'] ?? 'visible' );
		if ( isset( $args['cat'] ) ) {
			$product->set_category_ids( array( $args['cat'] ) );
		}
		$product->set_status( 'publish' );
		$product->update_meta_data( '_mgseo_gtin', '5901234123457' );
		$product->save();
		return $product;
	}

	/**
	 * Create a variable product with S/M/L.
	 */
	private function variable(): \WC_Product_Variable {
		$attr = new \WC_Product_Attribute();
		$attr->set_name( 'Rozmiar' );
		$attr->set_options( array( 'S', 'M', 'L' ) );
		$attr->set_visible( true );
		$attr->set_variation( true );
		$product = new \WC_Product_Variable();
		$product->set_name( 'Koszulka' );
		$product->set_slug( 'koszulka' );
		$product->set_sku( 'TS' );
		$product->set_attributes( array( $attr ) );
		$product->set_status( 'publish' );
		$product->save();
		foreach ( array(
			'S' => '79',
			'M' => '79',
			'L' => '89',
		) as $size => $price ) {
			$variation = new \WC_Product_Variation();
			$variation->set_parent_id( $product->get_id() );
			$variation->set_attributes( array( 'rozmiar' => $size ) );
			$variation->set_regular_price( $price );
			$variation->set_sku( 'TS-' . $size );
			$variation->update_meta_data( '_mgseo_gtin', 'L' === $size ? '12345670' : '' );
			$variation->save();
		}
		\WC_Product_Variable::sync( $product->get_id() );
		return wc_get_product( $product->get_id() );
	}

	public function test_simple_product_node(): void {
		$this->set_option( 'woocommerce', 'brand_source', 'text' );
		$this->set_option( 'woocommerce', 'brand_text', 'MGraphics' );
		$this->set_option( 'woocommerce', 'return_enabled', true );
		$this->set_option( 'woocommerce', 'shipping_enabled', true );
		$this->set_option( 'woocommerce', 'shipping_rate', 15 );
		$product = $this->simple( array( 'sale' => '39' ) );
		$url     = get_permalink( $product->get_id() );
		$node    = Product::node( $product, $url, array( '@id' => home_url( '/#organization' ) ) );

		$this->assertSame( 'Product', $node['@type'] );
		$this->assertSame( $url . '#product', $node['@id'] );
		$this->assertSame( 'Kubek ceramiczny', $node['name'] );
		$this->assertSame( 'Kubek 330 ml.', $node['description'] );
		$this->assertSame( 'MUG-001', $node['sku'] );
		$this->assertSame( '5901234123457', $node['gtin13'] );
		$this->assertSame(
			array(
				'@type' => 'Brand',
				'name'  => 'MGraphics',
			),
			$node['brand']
		);
		$offer = $node['offers'];
		$this->assertSame( 'Offer', $offer['@type'] );
		$this->assertSame( '39.00', $offer['price'] );
		$this->assertSame( 'PLN', $offer['priceCurrency'] );
		$this->assertSame( 'https://schema.org/InStock', $offer['availability'] );
		$this->assertSame( '2030-06-30', $offer['priceValidUntil'] );
		$this->assertSame( 'https://schema.org/NewCondition', $offer['itemCondition'] );
		$this->assertSame( array( '@id' => home_url( '/#organization' ) ), $offer['seller'] );
		$this->assertSame( 'PL', $offer['hasMerchantReturnPolicy']['applicableCountry'] );
		$this->assertSame( 14, $offer['hasMerchantReturnPolicy']['merchantReturnDays'] );
		$this->assertSame( 15.0, $offer['shippingDetails']['shippingRate']['value'] );
		$this->assertSame( array(), Product::validate( $node )['errors'] );
	}

	public function test_out_of_stock_and_price_valid_until_default(): void {
		$product = $this->simple(
			array(
				'stock' => 'outofstock',
				'slug'  => 'plakat',
			)
		);
		$node    = Product::node( $product, 'https://example.org/p/' );
		$this->assertSame( 'https://schema.org/OutOfStock', $node['offers']['availability'] );
		$this->assertSame( gmdate( 'Y-m-d', strtotime( '+1 year' ) ), $node['offers']['priceValidUntil'] );
	}

	public function test_variable_product_offers(): void {
		$product = $this->variable();
		$node    = Product::node( $product, 'https://example.org/p/' );
		$this->assertSame( 'AggregateOffer', $node['offers']['@type'] );
		$this->assertSame( '79.00', $node['offers']['lowPrice'] );
		$this->assertSame( '89.00', $node['offers']['highPrice'] );
		$this->assertSame( 3, $node['offers']['offerCount'] );
		$this->assertSame( array(), Product::validate( $node )['errors'] );

		$this->set_option( 'woocommerce', 'variable_offers', 'per_variation' );
		$node = Product::node( $product, 'https://example.org/p/' );
		$this->assertCount( 3, $node['offers'] );
		$this->assertSame( 'TS-L', $node['offers'][2]['sku'] );
		$this->assertSame( '12345670', $node['offers'][2]['gtin8'] );
		$this->assertSame( '89.00', $node['offers'][2]['price'] );
		$this->assertStringContainsString( 'attribute_rozmiar=L', $node['offers'][2]['url'] );
	}

	public function test_rating_and_reviews(): void {
		$product = $this->simple();
		foreach ( array(
			array( 'Anna', 5 ),
			array( 'Piotr', 4 ),
		) as $review ) {
			$id = self::factory()->comment->create(
				array(
					'comment_post_ID' => $product->get_id(),
					'comment_author'  => $review[0],
					'comment_type'    => 'review',
					'comment_content' => 'Opinia ' . $review[0],
				)
			);
			update_comment_meta( $id, 'rating', $review[1] );
		}
		$product->set_average_rating( 4.5 );
		$product->set_review_count( 2 );
		$product->save();
		$node = Product::node( wc_get_product( $product->get_id() ), 'https://example.org/p/' );
		$this->assertSame( 4.5, $node['aggregateRating']['ratingValue'] );
		$this->assertSame( 2, $node['aggregateRating']['reviewCount'] );
		$this->assertCount( 2, $node['review'] );
		$this->assertSame( 'Review', $node['review'][0]['@type'] );
		$this->assertContains( $node['review'][0]['reviewRating']['ratingValue'], array( 4, 5 ) );
		$this->assertSame( 'Person', $node['review'][0]['author']['@type'] );
	}

	public function test_validation_requires_offer_review_or_rating(): void {
		$result = Product::validate( array( 'name' => 'X' ) );
		$this->assertCount( 1, $result['errors'] );
		$result = Product::validate(
			array(
				'name'   => 'X',
				'offers' => array( '@type' => 'Offer' ),
			)
		);
		$this->assertCount( 2, $result['errors'] );
	}

	public function test_graph_and_standalone_output(): void {
		$product = $this->simple();
		$url     = get_permalink( $product->get_id() );
		$this->go_to( $url );
		$org   = array(
			'@type' => 'Organization',
			'@id'   => home_url( '/#organization' ),
		);
		$nodes = apply_filters( 'mgseo/schema/graph', array( $org ), array() );
		$this->assertCount( 2, $nodes );
		$this->assertSame( $url . '#product', $nodes[1]['@id'] );
		$this->assertSame( array( '@id' => home_url( '/#organization' ) ), $nodes[1]['offers']['seller'] );

		// User-defined Product entity: no automatic node.
		Meta::set_post( $product->get_id(), 'schema', wp_json_encode( array( array( 'id' => 'x', 'type' => 'Product', 'custom' => false, 'data' => array() ) ) ) );
		$this->assertCount( 1, apply_filters( 'mgseo/schema/graph', array( $org ), array() ) );
		Meta::set_post( $product->get_id(), 'schema', '' );

		$head = $this->head_for( $url );
		$ld   = $this->json_ld( $head );
		if ( Plugin::instance()->module( 'schema' ) ) {
			$graph    = $ld[0]['@graph'] ?? array();
			$products = array_values( array_filter( $graph, static fn( $n ) => 'Product' === ( $n['@type'] ?? '' ) ) );
			$this->assertCount( 1, $products );
			$this->assertSame( $url . '#product', $products[0]['@id'] );
		} else {
			$products = array_values( array_filter( $ld, static fn( $d ) => 'Product' === ( $d['@type'] ?? '' ) ) );
			$this->assertCount( 1, $products );
			$this->assertSame( 'Organization', $products[0]['offers']['seller']['@type'] );
		}

		// WooCommerce's own product JSON-LD is suppressed.
		$this->assertSame( array(), apply_filters( 'woocommerce_structured_data_product', array( '@type' => 'Product' ), $product ) );
	}

	public function test_open_graph_product_tags(): void {
		$product = $this->simple( array( 'sale' => '39' ) );
		$head    = $this->head_for( get_permalink( $product->get_id() ) );
		$this->assertStringContainsString( '<meta property="og:type" content="product" />', $head );
		$this->assertStringContainsString( '<meta property="product:price:amount" content="39.00" />', $head );
		$this->assertStringContainsString( '<meta property="product:price:currency" content="PLN" />', $head );
		$this->assertStringContainsString( '<meta property="product:availability" content="instock" />', $head );
		$this->assertStringContainsString( '<meta property="product:retailer_item_id" content="MUG-001" />', $head );
		$this->assertStringNotContainsString( 'article:published_time', $head );
		$this->assertStringContainsString( '<meta name="twitter:label1" content="Price" />', $head );
	}

	public function test_variables(): void {
		$this->set_option( 'woocommerce', 'brand_source', 'text' );
		$this->set_option( 'woocommerce', 'brand_text', 'Acme' );
		$product = $this->simple( array( 'sale' => '39' ) );
		$post    = get_post( $product->get_id() );
		$this->assertSame( 'MUG-001 | Acme | 5901234123457 | Kubek 330 ml.', Variables::replace( '%wc_sku% | %wc_brand% | %wc_gtin% | %wc_shortdesc%', $post ) );
		$this->assertStringContainsString( '39', Variables::replace( '%wc_price%', $post ) );
		$variable = $this->variable();
		$this->assertMatchesRegularExpression( '/79.*89/', Variables::replace( '%wc_price%', get_post( $variable->get_id() ) ) );
		$this->assertSame( '%title% %sep% %sitename%', \MGSEO\Options::defaults( 'titles' )['pt_product_title'] );
	}

	public function test_base_removal(): void {
		$cat     = self::factory()->term->create(
			array(
				'taxonomy' => 'product_cat',
				'name'     => 'Kubki',
				'slug'     => 'kubki',
			)
		);
		$child   = self::factory()->term->create(
			array(
				'taxonomy' => 'product_cat',
				'name'     => 'Duże',
				'slug'     => 'duze',
				'parent'   => $cat,
			)
		);
		$product = $this->simple( array( 'cat' => $child ) );
		$this->assertSame( home_url( '/product/kubek-ceramiczny/' ), get_permalink( $product->get_id() ) );

		$this->set_option( 'woocommerce', 'remove_product_base', true );
		$this->set_option( 'woocommerce', 'remove_category_base', true );
		Permalinks::reset();
		$this->assertSame( home_url( '/kubek-ceramiczny/' ), get_permalink( $product->get_id() ) );
		$this->assertSame( home_url( '/kubki/' ), get_term_link( $cat, 'product_cat' ) );
		$this->assertSame( home_url( '/kubki/duze/' ), get_term_link( $child, 'product_cat' ) );

		$this->go_to( home_url( '/kubek-ceramiczny/' ) );
		$this->assertTrue( is_singular( 'product' ) );
		$this->assertSame( $product->get_id(), get_queried_object_id() );

		$this->go_to( home_url( '/kubki/duze/' ) );
		$this->assertTrue( is_tax( 'product_cat', 'duze' ) );

		$this->go_to( home_url( '/kubki/' ) );
		$this->assertTrue( is_tax( 'product_cat', 'kubki' ) );

		// Existing pages win and the colliding category keeps its base.
		$page = self::factory()->post->create(
			array(
				'post_type' => 'page',
				'post_name' => 'kubki',
			)
		);
		Permalinks::reset();
		// The default link is used (/product-category/kubki/, or ?product_cat=kubki when the taxonomy was registered before pretty permalinks).
		$this->assertNotSame( home_url( '/kubki/' ), get_term_link( $cat, 'product_cat' ) );
		$this->assertStringContainsString( 'kubki', get_term_link( $cat, 'product_cat' ) );
		$this->go_to( home_url( '/kubki/' ) );
		$this->assertTrue( is_page( $page ) );

		// Old URLs still resolve so they can be redirected.
		$this->go_to( home_url( '/product/kubek-ceramiczny/' ) );
		$this->assertTrue( is_singular( 'product' ) );
	}

	public function test_robots_and_sitemap_exclusions(): void {
		$hidden = $this->simple(
			array(
				'slug'       => 'ukryty',
				'sku'        => 'HID-1',
				'visibility' => 'hidden',
			)
		);
		$this->go_to( get_permalink( $hidden->get_id() ) );
		$robots = \MGSEO\Modules\Titles\Module::current_robots();
		$this->assertTrue( $robots['noindex'] );
		$this->assertTrue( apply_filters( 'mgseo/sitemap/exclude_post', false, $hidden->get_id(), 'product' ) );

		$cart = self::factory()->post->create(
			array(
				'post_type'  => 'page',
				'post_title' => 'Koszyk',
			)
		);
		update_option( 'woocommerce_cart_page_id', $cart );
		$this->go_to( get_permalink( $cart ) );
		$this->assertTrue( \MGSEO\Modules\Titles\Module::current_robots()['noindex'] );
		$this->assertTrue( apply_filters( 'mgseo/sitemap/exclude_post', false, $cart, 'page' ) );
		$this->assertFalse( apply_filters( 'mgseo/sitemap/exclude_post', false, $cart, 'post' ) );

		$visible = $this->simple(
			array(
				'slug' => 'widoczny',
				'sku'  => 'VIS-1',
			)
		);
		$this->go_to( get_permalink( $visible->get_id() ) );
		$this->assertFalse( \MGSEO\Modules\Titles\Module::current_robots()['noindex'] );
		$_GET['add-to-cart'] = (string) $visible->get_id();
		$this->assertTrue( \MGSEO\Modules\Titles\Module::current_robots()['noindex'] );
		unset( $_GET['add-to-cart'] );
		$this->assertInstanceOf( Woo::class, Plugin::instance()->module( 'woocommerce' ) );
	}
}
