<?php
/**
 * WooCommerce SEO module.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\WooCommerce;

use MGSEO\Context;
use MGSEO\Head;
use MGSEO\Helpers;
use MGSEO\Meta;
use MGSEO\Module as Base;
use MGSEO\Modules\Titles\Module as Titles;
use MGSEO\Options;
use MGSEO\Plugin;

defined( 'ABSPATH' ) || exit;

/**
 * Product schema, product fields, Open Graph product tags, variables, robots and URL options.
 *
 * Schema integration: adds `<canonical>#product` through `mgseo/schema/graph` (skipped when the post has a
 * user-defined Product entity in `_mgseo_schema` or the graph already holds a Product); printed standalone when
 * the Schema module is inactive. WooCommerce's own JSON-LD is removed to avoid duplicates.
 */
final class Module extends Base {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'woocommerce';
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_filter( 'mgseo/schema/graph', array( $this, 'graph' ), 20, 2 );
		add_action( 'mgseo/head', array( $this, 'standalone_schema' ), 90 );
		add_action( 'init', array( $this, 'remove_wc_schema' ), 20 );
		add_filter( 'mgseo/open_graph', array( $this, 'open_graph' ), 10, 2 );
		add_action( 'mgseo/open_graph_tags', array( $this, 'open_graph_tags' ) );
		add_action( 'mgseo/head', array( $this, 'twitter_labels' ), 41 );
		add_filter( 'mgseo/variables', array( $this, 'variables' ) );
		add_filter( 'mgseo/variable_descriptions', array( $this, 'variable_descriptions' ) );
		add_filter( 'mgseo/robots', array( $this, 'robots' ), 10, 2 );
		add_filter( 'mgseo/sitemap/exclude_post', array( $this, 'sitemap_exclude' ), 10, 3 );
		add_filter( 'mgseo/option_defaults', array( $this, 'title_defaults' ), 10, 2 );
		add_action( 'wp', array( $this, 'remove_generator' ) );
		Permalinks::boot();
		if ( is_admin() ) {
			Fields::boot();
		}
	}

	/**
	 * Settings schema.
	 */
	public function settings(): array {
		$taxonomies = array();
		foreach ( get_object_taxonomies( 'product', 'objects' ) as $taxonomy ) {
			if ( ! in_array( $taxonomy->name, array( 'product_type', 'product_visibility', 'product_shipping_class' ), true ) ) {
				$taxonomies[ $taxonomy->name ] = $taxonomy->labels->singular_name;
			}
		}
		$attributes = array( '' => __( '— Select —', 'mg-seo' ) );
		if ( function_exists( 'wc_get_attribute_taxonomies' ) ) {
			foreach ( wc_get_attribute_taxonomies() as $attribute ) {
				$attributes[ 'pa_' . $attribute->attribute_name ] = $attribute->attribute_label;
			}
		}
		return array(
			array(
				'id'     => 'schema',
				'title'  => __( 'Product schema', 'mg-seo' ),
				'fields' => array(
					array(
						'id'           => 'schema_enabled',
						'type'         => 'toggle',
						'label'        => __( 'Product schema', 'mg-seo' ),
						'toggle_label' => __( 'Add a Product entity to product pages', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'           => 'remove_wc_schema',
						'type'         => 'toggle',
						'label'        => __( 'WooCommerce structured data', 'mg-seo' ),
						'toggle_label' => __( 'Remove WooCommerce\'s own JSON-LD (product, breadcrumbs, website) to avoid duplicates', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'      => 'brand_source',
						'type'    => 'select',
						'label'   => __( 'Brand', 'mg-seo' ),
						'options' => array(
							'auto'      => __( 'WooCommerce Brands taxonomy', 'mg-seo' ),
							'taxonomy'  => __( 'Another taxonomy', 'mg-seo' ),
							'attribute' => __( 'Product attribute', 'mg-seo' ),
							'text'      => __( 'Fixed text', 'mg-seo' ),
							'none'      => __( 'No brand', 'mg-seo' ),
						),
						'default' => 'auto',
						'desc'    => __( 'The Brand (SEO) field of a product overrides this.', 'mg-seo' ),
					),
					array(
						'id'      => 'brand_taxonomy',
						'type'    => 'select',
						'label'   => __( 'Brand taxonomy', 'mg-seo' ),
						'options' => $taxonomies ? $taxonomies : array( 'product_brand' => 'product_brand' ),
						'default' => 'product_brand',
					),
					array(
						'id'      => 'brand_attribute',
						'type'    => 'select',
						'label'   => __( 'Brand attribute', 'mg-seo' ),
						'options' => $attributes,
						'default' => '',
					),
					array(
						'id'      => 'brand_text',
						'type'    => 'text',
						'label'   => __( 'Brand text', 'mg-seo' ),
						'default' => '',
					),
					array(
						'id'      => 'variable_offers',
						'type'    => 'select',
						'label'   => __( 'Variable products', 'mg-seo' ),
						'options' => array(
							'aggregate'     => __( 'AggregateOffer (lowest/highest price)', 'mg-seo' ),
							'per_variation' => __( 'One Offer per variation', 'mg-seo' ),
						),
						'default' => 'aggregate',
					),
					array(
						'id'      => 'price_valid_until',
						'type'    => 'select',
						'label'   => __( 'Price valid until', 'mg-seo' ),
						'options' => array(
							'auto' => __( 'Sale end date, otherwise one year from today', 'mg-seo' ),
							'year' => __( 'Always one year from today', 'mg-seo' ),
							'none' => __( 'Do not output', 'mg-seo' ),
						),
						'default' => 'auto',
					),
					array(
						'id'      => 'item_condition',
						'type'    => 'select',
						'label'   => __( 'Default condition', 'mg-seo' ),
						'options' => array_diff_key( Fields::conditions(), array( '' => '' ) ),
						'default' => 'NewCondition',
					),
					array(
						'id'           => 'include_reviews',
						'type'         => 'toggle',
						'label'        => __( 'Ratings', 'mg-seo' ),
						'toggle_label' => __( 'Add aggregateRating and reviews from WooCommerce reviews', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'      => 'reviews_limit',
						'type'    => 'number',
						'label'   => __( 'Reviews in schema', 'mg-seo' ),
						'default' => 5,
						'min'     => 0,
						'max'     => 50,
					),
				),
			),
			array(
				'id'          => 'merchant',
				'title'       => __( 'Shipping & returns', 'mg-seo' ),
				'description' => __( 'Basic shipping and return policy data added to every offer (recommended by Google for merchant listings).', 'mg-seo' ),
				'fields'      => array(
					array(
						'id'           => 'shipping_enabled',
						'type'         => 'toggle',
						'label'        => __( 'Shipping details', 'mg-seo' ),
						'toggle_label' => __( 'Add OfferShippingDetails', 'mg-seo' ),
						'default'      => false,
					),
					array(
						'id'      => 'shipping_country',
						'type'    => 'text',
						'label'   => __( 'Shipping country', 'mg-seo' ),
						'default' => '',
						'desc'    => __( 'ISO code; the store country when empty.', 'mg-seo' ),
					),
					array(
						'id'      => 'shipping_rate',
						'type'    => 'number',
						'label'   => __( 'Shipping cost', 'mg-seo' ),
						'default' => 0,
						'min'     => 0,
						'step'    => '0.01',
					),
					array(
						'id'      => 'handling_min',
						'type'    => 'number',
						'label'   => __( 'Handling time (min days)', 'mg-seo' ),
						'default' => 0,
						'min'     => 0,
					),
					array(
						'id'      => 'handling_max',
						'type'    => 'number',
						'label'   => __( 'Handling time (max days)', 'mg-seo' ),
						'default' => 1,
						'min'     => 0,
					),
					array(
						'id'      => 'transit_min',
						'type'    => 'number',
						'label'   => __( 'Transit time (min days)', 'mg-seo' ),
						'default' => 1,
						'min'     => 0,
					),
					array(
						'id'      => 'transit_max',
						'type'    => 'number',
						'label'   => __( 'Transit time (max days)', 'mg-seo' ),
						'default' => 3,
						'min'     => 0,
					),
					array(
						'id'           => 'return_enabled',
						'type'         => 'toggle',
						'label'        => __( 'Return policy', 'mg-seo' ),
						'toggle_label' => __( 'Add MerchantReturnPolicy', 'mg-seo' ),
						'default'      => false,
					),
					array(
						'id'      => 'return_country',
						'type'    => 'text',
						'label'   => __( 'Return policy country', 'mg-seo' ),
						'default' => '',
					),
					array(
						'id'      => 'return_category',
						'type'    => 'select',
						'label'   => __( 'Returns', 'mg-seo' ),
						'options' => array(
							'MerchantReturnFiniteReturnWindow' => __( 'Within a number of days', 'mg-seo' ),
							'MerchantReturnUnlimitedWindow' => __( 'Unlimited time', 'mg-seo' ),
							'MerchantReturnNotPermitted' => __( 'Not permitted', 'mg-seo' ),
						),
						'default' => 'MerchantReturnFiniteReturnWindow',
					),
					array(
						'id'      => 'return_days',
						'type'    => 'number',
						'label'   => __( 'Return window (days)', 'mg-seo' ),
						'default' => 14,
						'min'     => 1,
					),
					array(
						'id'      => 'return_method',
						'type'    => 'select',
						'label'   => __( 'Return method', 'mg-seo' ),
						'options' => array(
							'ReturnByMail'  => __( 'By mail', 'mg-seo' ),
							'ReturnInStore' => __( 'In store', 'mg-seo' ),
							'ReturnAtKiosk' => __( 'At a kiosk', 'mg-seo' ),
						),
						'default' => 'ReturnByMail',
					),
					array(
						'id'      => 'return_fees',
						'type'    => 'select',
						'label'   => __( 'Return fees', 'mg-seo' ),
						'options' => array(
							'FreeReturn' => __( 'Free return', 'mg-seo' ),
							'ReturnFeesCustomerResponsibility' => __( 'Customer pays the return shipping', 'mg-seo' ),
						),
						'default' => 'FreeReturn',
					),
				),
			),
			array(
				'id'     => 'seo',
				'title'  => __( 'URLs & indexing', 'mg-seo' ),
				'fields' => array(
					array(
						'id'           => 'remove_product_base',
						'type'         => 'toggle',
						'label'        => __( 'Product base', 'mg-seo' ),
						'toggle_label' => __( 'Remove the product base (/product/name/ becomes /name/)', 'mg-seo' ),
						'default'      => false,
						'desc'         => __( 'Pages and posts with the same slug keep priority; old URLs are redirected with 301.', 'mg-seo' ),
					),
					array(
						'id'           => 'remove_category_base',
						'type'         => 'toggle',
						'label'        => __( 'Category base', 'mg-seo' ),
						'toggle_label' => __( 'Remove the product category base (/product-category/name/ becomes /name/)', 'mg-seo' ),
						'default'      => false,
					),
					array(
						'id'           => 'noindex_hidden',
						'type'         => 'toggle',
						'label'        => __( 'Hidden products', 'mg-seo' ),
						'toggle_label' => __( 'No index for products with catalog visibility "Hidden" (and leave them out of sitemaps)', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'           => 'noindex_wc_pages',
						'type'         => 'toggle',
						'label'        => __( 'Cart, checkout, my account', 'mg-seo' ),
						'toggle_label' => __( 'No index for the cart, checkout and account pages (and leave them out of sitemaps)', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'           => 'noindex_add_to_cart',
						'type'         => 'toggle',
						'label'        => __( 'Add-to-cart links', 'mg-seo' ),
						'toggle_label' => __( 'No index for URLs with ?add-to-cart= (the canonical never contains it)', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'           => 'og_product',
						'type'         => 'toggle',
						'label'        => __( 'Open Graph', 'mg-seo' ),
						'toggle_label' => __( 'og:type product with price, currency, availability and brand tags', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'           => 'twitter_labels',
						'type'         => 'toggle',
						'label'        => __( 'Slack/X labels', 'mg-seo' ),
						'toggle_label' => __( 'Show price and availability labels in link previews', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'           => 'remove_generator',
						'type'         => 'toggle',
						'label'        => __( 'Generator tag', 'mg-seo' ),
						'toggle_label' => __( 'Remove the WooCommerce generator meta tag', 'mg-seo' ),
						'default'      => true,
					),
				),
			),
		);
	}

	/**
	 * Setting shortcut.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	private function opt( string $key ): mixed {
		return Options::get( 'woocommerce', $key );
	}

	/**
	 * Product of the current singular product page.
	 */
	public static function current_product(): ?\WC_Product {
		$ctx = Context::current();
		if ( Context::SINGULAR !== $ctx['type'] || ! $ctx['object'] instanceof \WP_Post || 'product' !== $ctx['object']->post_type ) {
			return null;
		}
		$product = wc_get_product( $ctx['object'] );
		return $product instanceof \WC_Product ? $product : null;
	}

	/**
	 * Add the Product node to the graph.
	 *
	 * @param array $nodes Nodes.
	 * @param array $ctx   Context.
	 * @return array
	 */
	public function graph( $nodes, $ctx = array() ): array {
		$nodes   = is_array( $nodes ) ? $nodes : array();
		$product = self::current_product();
		if ( ! $product || ! $this->opt( 'schema_enabled' ) || self::has_user_product( $product->get_id() ) ) {
			return $nodes;
		}
		foreach ( $nodes as $node ) {
			if ( is_array( $node ) && array_intersect( (array) ( $node['@type'] ?? array() ), array( 'Product', 'ProductGroup', 'IndividualProduct' ) ) ) {
				return $nodes;
			}
		}
		$site_id = is_array( $ctx ) && ! empty( $ctx['site_id'] ) ? (string) $ctx['site_id'] : self::node_id( 'organization', home_url( '/' ) );
		$seller  = array_filter( $nodes, static fn( $n ) => is_array( $n ) && ( $n['@id'] ?? '' ) === $site_id ) ? array( '@id' => $site_id ) : self::inline_seller();
		$canon   = is_array( $ctx ) && ! empty( $ctx['canonical'] ) ? (string) $ctx['canonical'] : self::canonical();
		$nodes[] = Product::node( $product, $canon, $seller );
		return $nodes;
	}

	/**
	 * Standalone Product JSON-LD when the Schema module is inactive.
	 */
	public function standalone_schema(): void {
		if ( Plugin::instance()->module( 'schema' ) || ! $this->opt( 'schema_enabled' ) ) {
			return;
		}
		$product = self::current_product();
		if ( ! $product || self::has_user_product( $product->get_id() ) ) {
			return;
		}
		$node = Product::node( $product, self::canonical(), self::inline_seller() );
		unset( $node['mainEntityOfPage'] );
		Head::json_ld( array( '@context' => 'https://schema.org' ) + $node );
	}

	/**
	 * Seller as an inline Organization.
	 *
	 * @return array<string, string>
	 */
	private static function inline_seller(): array {
		$name = (string) Options::get( 'schema', 'org_name', '' );
		return array(
			'@type' => 'Organization',
			'name'  => '' !== $name ? $name : get_bloginfo( 'name' ),
			'url'   => home_url( '/' ),
		);
	}

	/**
	 * Whether the product has a user-defined Product entity in its schema meta.
	 *
	 * @param int $post_id Post id.
	 */
	public static function has_user_product( int $post_id ): bool {
		$raw      = Meta::post( $post_id, 'schema', '' );
		$entities = is_string( $raw ) ? json_decode( $raw, true ) : $raw;
		if ( ! is_array( $entities ) ) {
			return false;
		}
		foreach ( $entities as $entity ) {
			if ( ! is_array( $entity ) ) {
				continue;
			}
			$type = ! empty( $entity['custom'] ) ? ( $entity['data']['@type'] ?? '' ) : ( $entity['type'] ?? '' );
			if ( array_intersect( (array) $type, array( 'Product', 'ProductGroup', 'IndividualProduct' ) ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Remove WooCommerce's JSON-LD.
	 */
	public function remove_wc_schema(): void {
		if ( ! $this->opt( 'remove_wc_schema' ) ) {
			return;
		}
		if ( $this->opt( 'schema_enabled' ) ) {
			add_filter( 'woocommerce_structured_data_product', '__return_empty_array', 99 );
			add_filter( 'woocommerce_structured_data_review', '__return_empty_array', 99 );
		}
		if ( Plugin::instance()->module( 'breadcrumbs' ) || Plugin::instance()->module( 'schema' ) ) {
			add_filter( 'woocommerce_structured_data_breadcrumblist', '__return_empty_array', 99 );
		}
		if ( Plugin::instance()->module( 'schema' ) ) {
			add_filter( 'woocommerce_structured_data_website', '__return_empty_array', 99 );
		}
	}

	/**
	 * Remove WooCommerce's generator tag.
	 */
	public function remove_generator(): void {
		if ( $this->opt( 'remove_generator' ) ) {
			remove_action( 'get_the_generator_html', 'wc_generator_tag' );
			remove_action( 'get_the_generator_xhtml', 'wc_generator_tag' );
			remove_filter( 'get_the_generator_html', 'wc_generator_tag', 10 );
			remove_filter( 'get_the_generator_xhtml', 'wc_generator_tag', 10 );
		}
	}

	/**
	 * Open Graph type for products.
	 *
	 * @param array $data Data.
	 * @param array $ctx  Context.
	 * @return array
	 */
	public function open_graph( $data, $ctx = array() ): array {
		$data = is_array( $data ) ? $data : array();
		if ( ! $this->opt( 'og_product' ) || ! self::current_product() ) {
			return $data;
		}
		$data['type'] = 'product';
		return $data;
	}

	/**
	 * Open Graph product:* tags.
	 *
	 * @param array $data Open Graph data.
	 */
	public function open_graph_tags( $data = array() ): void {
		$product = self::current_product();
		if ( ! $product || ! $this->opt( 'og_product' ) || 'product' !== ( $data['type'] ?? '' ) ) {
			return;
		}
		$price = self::display_price( $product );
		if ( '' !== $price ) {
			Head::property( 'product:price:amount', $price );
			Head::property( 'product:price:currency', get_woocommerce_currency() );
		}
		Head::property( 'product:availability', self::og_availability( $product ) );
		Head::property( 'product:brand', Product::brand( $product ) );
		Head::property( 'product:retailer_item_id', (string) $product->get_sku() );
		Head::property( 'product:condition', 'new' );
	}

	/**
	 * Price for OG/labels ("39.00"; the lowest price for variable products).
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function display_price( \WC_Product $product ): string {
		if ( '' === (string) $product->get_price() ) {
			return '';
		}
		if ( $product instanceof \WC_Product_Variable ) {
			return Product::price( (float) $product->get_variation_price( 'min', true ) );
		}
		return Product::price( (float) wc_get_price_to_display( $product ) );
	}

	/**
	 * Open Graph availability.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function og_availability( \WC_Product $product ): string {
		if ( $product->is_on_backorder() ) {
			return 'available for order';
		}
		return $product->is_in_stock() ? 'instock' : 'out of stock';
	}

	/**
	 * Twitter/Slack labels for products.
	 */
	public function twitter_labels(): void {
		$product = self::current_product();
		if ( ! $product || ! $this->opt( 'twitter_labels' ) || ! Plugin::instance()->module( 'social' ) ) {
			return;
		}
		$price = self::price_text( $product );
		if ( '' !== $price ) {
			Head::meta( 'twitter:label1', __( 'Price', 'mg-seo' ) );
			Head::meta( 'twitter:data1', $price );
		}
		Head::meta( 'twitter:label2', __( 'Availability', 'mg-seo' ) );
		Head::meta( 'twitter:data2', $product->is_in_stock() ? __( 'In stock', 'mg-seo' ) : __( 'Out of stock', 'mg-seo' ) );
	}

	/**
	 * Formatted plain-text price ("39,00 zł", "79,00 zł – 89,00 zł").
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function price_text( \WC_Product $product ): string {
		if ( '' === (string) $product->get_price() ) {
			return '';
		}
		if ( $product instanceof \WC_Product_Variable ) {
			$min  = (float) $product->get_variation_price( 'min', true );
			$max  = (float) $product->get_variation_price( 'max', true );
			$text = $min === $max ? wc_price( $min ) : wc_format_price_range( $min, $max );
		} else {
			$text = wc_price( (float) wc_get_price_to_display( $product ) );
		}
		$text = html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES, 'UTF-8' );
		return trim( (string) preg_replace( '/[\s\x{00A0}]+/u', ' ', $text ) );
	}

	/**
	 * Product variables.
	 *
	 * @param array $vars Variables.
	 * @return array
	 */
	public function variables( $vars ): array {
		$vars    = is_array( $vars ) ? $vars : array();
		$product = static function ( ?object $obj ): ?\WC_Product {
			if ( null === $obj ) {
				$obj = Context::current()['object'];
			}
			if ( $obj instanceof \WP_Post && 'product' === $obj->post_type ) {
				$p = wc_get_product( $obj );
				return $p instanceof \WC_Product ? $p : null;
			}
			return null;
		};
		$make    = static function ( callable $value ) use ( $product ): callable {
			return static function ( ?object $obj ) use ( $product, $value ): string {
				$p = $product( $obj );
				return $p ? (string) $value( $p ) : '';
			};
		};
		return $vars + array(
			'wc_price'     => $make( array( self::class, 'price_text' ) ),
			'wc_sku'       => $make( static fn( \WC_Product $p ) => $p->get_sku() ),
			'wc_brand'     => $make( array( Product::class, 'brand' ) ),
			'wc_gtin'      => $make( array( Product::class, 'gtin' ) ),
			'wc_shortdesc' => $make( array( Product::class, 'description' ) ),
		);
	}

	/**
	 * Variable descriptions.
	 *
	 * @param array $descriptions Descriptions.
	 * @return array
	 */
	public function variable_descriptions( $descriptions ): array {
		$descriptions = is_array( $descriptions ) ? $descriptions : array();
		return $descriptions + array(
			'wc_price'     => __( 'Product price (price range for variable products)', 'mg-seo' ),
			'wc_sku'       => __( 'Product SKU', 'mg-seo' ),
			'wc_brand'     => __( 'Product brand', 'mg-seo' ),
			'wc_gtin'      => __( 'Product GTIN', 'mg-seo' ),
			'wc_shortdesc' => __( 'Product short description (the description when empty)', 'mg-seo' ),
		);
	}

	/**
	 * Default title/description templates for products.
	 *
	 * @param array  $defaults Defaults.
	 * @param string $group    Group.
	 * @return array
	 */
	public function title_defaults( $defaults, $group = '' ): array {
		$defaults = is_array( $defaults ) ? $defaults : array();
		if ( 'titles' !== $group ) {
			return $defaults;
		}
		$defaults['pt_product_title']       = '%title% %sep% %sitename%';
		$defaults['pt_product_description'] = '%wc_shortdesc%';
		return $defaults;
	}

	/**
	 * Robots for hidden products, cart/checkout/account and add-to-cart URLs.
	 *
	 * @param array $robots Directives.
	 * @param array $ctx    Context.
	 * @return array
	 */
	public function robots( $robots, $ctx = array() ): array {
		$robots  = is_array( $robots ) ? $robots : array();
		$noindex = false;
		$product = self::current_product();
		if ( $product && $this->opt( 'noindex_hidden' ) && 'hidden' === $product->get_catalog_visibility() ) {
			$noindex = true;
		}
		$ctx = Context::current();
		if ( $ctx['object'] instanceof \WP_Post && $this->opt( 'noindex_wc_pages' ) && self::is_wc_utility_page( $ctx['object']->ID ) ) {
			$noindex = true;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only check of a public URL parameter.
		if ( $this->opt( 'noindex_add_to_cart' ) && isset( $_GET['add-to-cart'] ) ) {
			$noindex = true;
		}
		if ( $noindex ) {
			$robots['index']   = false;
			$robots['noindex'] = true;
			foreach ( array( 'max-snippet', 'max-video-preview', 'max-image-preview' ) as $key ) {
				unset( $robots[ $key ] );
			}
		}
		return $robots;
	}

	/**
	 * Cart, checkout or my account page.
	 *
	 * @param int $post_id Post id.
	 */
	public static function is_wc_utility_page( int $post_id ): bool {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return false;
		}
		foreach ( array( 'cart', 'checkout', 'myaccount' ) as $page ) {
			$id = (int) wc_get_page_id( $page );
			if ( $id > 0 && $id === $post_id ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Leave hidden products and the cart/checkout/account pages out of sitemaps.
	 *
	 * @param bool         $exclude   Exclude.
	 * @param \WP_Post|int $post      Post or post id.
	 * @param string       $post_type Post type (when known).
	 */
	public function sitemap_exclude( $exclude, $post = null, $post_type = '' ): bool {
		if ( $exclude ) {
			return true;
		}
		if ( is_string( $post_type ) && '' !== $post_type && ! in_array( $post_type, array( 'page', 'product' ), true ) ) {
			return false;
		}
		$post = get_post( $post );
		if ( ! $post ) {
			return false;
		}
		if ( 'page' === $post->post_type && $this->opt( 'noindex_wc_pages' ) && self::is_wc_utility_page( $post->ID ) ) {
			return true;
		}
		if ( 'product' === $post->post_type && $this->opt( 'noindex_hidden' ) ) {
			$product = wc_get_product( $post );
			return $product && 'hidden' === $product->get_catalog_visibility();
		}
		return false;
	}

	/**
	 * Canonical URL of the current page.
	 */
	private static function canonical(): string {
		$url = Titles::current_canonical();
		return '' !== $url ? $url : Helpers::current_url();
	}

	/**
	 * Node id following the Schema module convention.
	 *
	 * @param string $fragment Fragment without '#'.
	 * @param string $base     Base URL.
	 */
	public static function node_id( string $fragment, string $base ): string {
		if ( class_exists( '\MGSEO\Modules\Schema\Graph' ) && method_exists( '\MGSEO\Modules\Schema\Graph', 'id' ) ) {
			$id = (string) \MGSEO\Modules\Schema\Graph::id( $fragment, $base );
			if ( str_ends_with( $id, '#' . $fragment ) ) {
				return $id;
			}
		}
		return $base . '#' . $fragment;
	}
}
