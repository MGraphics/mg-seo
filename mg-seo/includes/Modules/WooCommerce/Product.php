<?php
/**
 * Product schema node builder and Google Product rich result checks.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\WooCommerce;

use MGSEO\Helpers;
use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Builds a schema.org Product node from a WooCommerce product.
 */
final class Product {

	/**
	 * Setting shortcut.
	 *
	 * @param string $key      Key.
	 * @param mixed  $fallback Fallback.
	 * @return mixed
	 */
	private static function opt( string $key, mixed $fallback = null ): mixed {
		return Options::get( 'woocommerce', $key, $fallback );
	}

	/**
	 * Product node.
	 *
	 * @param \WC_Product $product   Product.
	 * @param string      $canonical Canonical URL of the page (node ids are based on it).
	 * @param array       $seller    Seller node or reference.
	 * @return array<string, mixed>
	 */
	public static function node( \WC_Product $product, string $canonical, array $seller = array() ): array {
		$url   = (string) $product->get_permalink();
		$node  = array(
			'@type'            => 'Product',
			'@id'              => Module::node_id( 'product', $canonical ),
			'name'             => html_entity_decode( wp_strip_all_tags( $product->get_name() ), ENT_QUOTES, 'UTF-8' ),
			'url'              => $url,
			'description'      => self::description( $product ),
			'sku'              => (string) $product->get_sku(),
			'mainEntityOfPage' => array( '@id' => Module::node_id( 'webpage', $canonical ) ),
		);
		$node  = array_merge( $node, self::identifiers( $product ) );
		$brand = self::brand( $product );
		if ( '' !== $brand ) {
			$node['brand'] = array(
				'@type' => 'Brand',
				'name'  => $brand,
			);
		}
		$images = self::images( $product );
		if ( $images ) {
			$node['image'] = 1 === count( $images ) ? $images[0] : $images;
		}
		$category = self::category( $product );
		if ( '' !== $category ) {
			$node['category'] = $category;
		}
		$weight = $product->get_weight();
		if ( '' !== (string) $weight ) {
			$node['weight'] = array(
				'@type'    => 'QuantitativeValue',
				'value'    => (float) $weight,
				'unitCode' => self::unit_code( (string) get_option( 'woocommerce_weight_unit', 'kg' ) ),
			);
		}
		$offers = self::offers( $product, $seller );
		if ( $offers ) {
			$node['offers'] = $offers;
		}
		if ( self::opt( 'include_reviews', true ) && function_exists( 'wc_review_ratings_enabled' ) && wc_review_ratings_enabled() ) {
			$count   = (int) $product->get_review_count();
			$average = (float) $product->get_average_rating();
			if ( $count > 0 && $average > 0 ) {
				$node['aggregateRating'] = array(
					'@type'       => 'AggregateRating',
					'ratingValue' => round( $average, 2 ),
					'reviewCount' => $count,
					'bestRating'  => 5,
					'worstRating' => 1,
				);
				$reviews                 = self::reviews( $product );
				if ( $reviews ) {
					$node['review'] = $reviews;
				}
			}
		}
		$node = array_filter( $node, static fn( $v ) => '' !== $v && array() !== $v && null !== $v );

		/**
		 * Filter the Product node.
		 *
		 * @param array       $node    Node.
		 * @param \WC_Product $product Product.
		 */
		return apply_filters( 'mgseo/woocommerce/product_node', $node, $product );
	}

	/**
	 * Plain-text description (short description, then the long one).
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function description( \WC_Product $product ): string {
		$text = (string) $product->get_short_description();
		if ( '' === trim( wp_strip_all_tags( $text ) ) ) {
			$text = (string) $product->get_description();
		}
		return Helpers::excerpt_from_content( $text, 5000 );
	}

	/**
	 * GTIN / MPN properties.
	 *
	 * @param \WC_Product $product Product (or variation).
	 * @return array<string, string>
	 */
	public static function identifiers( \WC_Product $product ): array {
		$out  = array();
		$gtin = self::gtin( $product );
		if ( '' !== $gtin ) {
			$out[ self::gtin_property( $gtin ) ] = $gtin;
		}
		$mpn = trim( (string) $product->get_meta( '_mgseo_mpn' ) );
		if ( '' === $mpn && $product->get_parent_id() ) {
			$parent = wc_get_product( $product->get_parent_id() );
			$mpn    = $parent ? trim( (string) $parent->get_meta( '_mgseo_mpn' ) ) : '';
		}
		if ( '' !== $mpn ) {
			$out['mpn'] = $mpn;
		}
		return $out;
	}

	/**
	 * GTIN of a product: own field, WooCommerce's GTIN field, then the ISBN (as GTIN-13).
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function gtin( \WC_Product $product ): string {
		$gtin = self::digits( (string) $product->get_meta( '_mgseo_gtin' ) );
		if ( '' === $gtin && method_exists( $product, 'get_global_unique_id' ) ) {
			$gtin = self::digits( (string) $product->get_global_unique_id() );
		}
		if ( '' === $gtin ) {
			$gtin = self::isbn13( (string) $product->get_meta( '_mgseo_isbn' ) );
		}
		return $gtin;
	}

	/**
	 * Schema property for a GTIN by its length.
	 *
	 * @param string $gtin Digits.
	 */
	public static function gtin_property( string $gtin ): string {
		return match ( strlen( $gtin ) ) {
			8       => 'gtin8',
			12      => 'gtin12',
			13      => 'gtin13',
			14      => 'gtin14',
			default => 'gtin',
		};
	}

	/**
	 * Digits only.
	 *
	 * @param string $value Value.
	 */
	private static function digits( string $value ): string {
		return (string) preg_replace( '/\D/', '', $value );
	}

	/**
	 * ISBN-10/13 as a 13 digit GTIN ('' when invalid).
	 *
	 * @param string $isbn ISBN.
	 */
	public static function isbn13( string $isbn ): string {
		$isbn = strtoupper( (string) preg_replace( '/[^0-9Xx]/', '', $isbn ) );
		if ( 13 === strlen( $isbn ) && ctype_digit( $isbn ) ) {
			return $isbn;
		}
		if ( 10 !== strlen( $isbn ) ) {
			return '';
		}
		$base = '978' . substr( $isbn, 0, 9 );
		$sum  = 0;
		for ( $i = 0; $i < 12; $i++ ) {
			$sum += (int) $base[ $i ] * ( 0 === $i % 2 ? 1 : 3 );
		}
		return $base . ( ( 10 - $sum % 10 ) % 10 );
	}

	/**
	 * Brand name.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function brand( \WC_Product $product ): string {
		$parent = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : null;
		$base   = $parent ? $parent : $product;
		$own    = trim( (string) $base->get_meta( '_mgseo_brand' ) );
		if ( '' !== $own ) {
			return $own;
		}
		$source = (string) self::opt( 'brand_source', 'auto' );
		switch ( $source ) {
			case 'none':
				return '';
			case 'text':
				return trim( (string) self::opt( 'brand_text', '' ) );
			case 'attribute':
				$value = (string) $base->get_attribute( (string) self::opt( 'brand_attribute', '' ) );
				$parts = array_map( 'trim', explode( ',', $value ) );
				return (string) ( $parts[0] ?? '' );
			case 'taxonomy':
			case 'auto':
			default:
				$taxonomy = 'taxonomy' === $source ? (string) self::opt( 'brand_taxonomy', 'product_brand' ) : 'product_brand';
				if ( ! taxonomy_exists( $taxonomy ) ) {
					return '';
				}
				$terms = get_the_terms( $base->get_id(), $taxonomy );
				return is_array( $terms ) && $terms ? html_entity_decode( reset( $terms )->name, ENT_QUOTES, 'UTF-8' ) : '';
		}
	}

	/**
	 * Image URLs (main image + gallery).
	 *
	 * @param \WC_Product $product Product.
	 * @return string[]
	 */
	public static function images( \WC_Product $product ): array {
		$ids  = array_filter( array_merge( array( (int) $product->get_image_id() ), array_map( 'intval', $product->get_gallery_image_ids() ) ) );
		$urls = array();
		foreach ( array_unique( $ids ) as $id ) {
			$src = wp_get_attachment_image_url( $id, 'full' );
			if ( $src ) {
				$urls[] = (string) $src;
			}
		}
		return $urls;
	}

	/**
	 * Primary product category name.
	 *
	 * @param \WC_Product $product Product.
	 */
	private static function category( \WC_Product $product ): string {
		$post = get_post( $product->get_id() );
		if ( ! $post ) {
			return '';
		}
		$term = Helpers::primary_term( $post, 'product_cat', true );
		return $term && 'uncategorized' !== $term->slug ? html_entity_decode( $term->name, ENT_QUOTES, 'UTF-8' ) : '';
	}

	/**
	 * UN/CEFACT unit code for a WooCommerce weight unit.
	 *
	 * @param string $unit Unit.
	 */
	private static function unit_code( string $unit ): string {
		return match ( $unit ) {
			'g'     => 'GRM',
			'lbs'   => 'LBR',
			'oz'    => 'ONZ',
			default => 'KGM',
		};
	}

	/**
	 * Offers for a product.
	 *
	 * @param \WC_Product $product Product.
	 * @param array       $seller  Seller.
	 * @return array<string, mixed>|array<int, array<string, mixed>>
	 */
	public static function offers( \WC_Product $product, array $seller = array() ): array {
		if ( $product->is_type( 'variable' ) ) {
			return 'per_variation' === self::opt( 'variable_offers', 'aggregate' )
				? self::variation_offers( $product, $seller )
				: self::aggregate_offer( $product, self::variable_prices( $product ), $seller );
		}
		if ( $product->is_type( 'grouped' ) ) {
			$prices = array();
			foreach ( $product->get_children() as $child_id ) {
				$child = wc_get_product( $child_id );
				if ( $child && $child->is_visible() && '' !== (string) $child->get_price() ) {
					$prices[] = (float) wc_get_price_to_display( $child );
				}
			}
			return self::aggregate_offer( $product, $prices, $seller );
		}
		return self::offer( $product, $seller );
	}

	/**
	 * Single Offer.
	 *
	 * @param \WC_Product $product Product or variation.
	 * @param array       $seller  Seller.
	 * @return array<string, mixed>
	 */
	public static function offer( \WC_Product $product, array $seller = array() ): array {
		if ( '' === (string) $product->get_price() ) {
			return array();
		}
		$offer = array(
			'@type'           => 'Offer',
			'price'           => self::price( (float) wc_get_price_to_display( $product ) ),
			'priceCurrency'   => get_woocommerce_currency(),
			'availability'    => self::availability( $product ),
			'url'             => (string) $product->get_permalink(),
			'priceValidUntil' => self::price_valid_until( $product ),
			'itemCondition'   => self::condition( $product ),
		);
		if ( wc_tax_enabled() ) {
			$offer['priceSpecification'] = array(
				'@type'                 => 'UnitPriceSpecification',
				'price'                 => $offer['price'],
				'priceCurrency'         => $offer['priceCurrency'],
				'valueAddedTaxIncluded' => 'incl' === get_option( 'woocommerce_tax_display_shop' ),
			);
		}
		return self::offer_extras( $offer, $seller );
	}

	/**
	 * Seller, shipping and return policy on an offer.
	 *
	 * @param array $offer  Offer.
	 * @param array $seller Seller.
	 * @return array<string, mixed>
	 */
	private static function offer_extras( array $offer, array $seller ): array {
		if ( $seller ) {
			$offer['seller'] = $seller;
		}
		$shipping = self::shipping_details();
		if ( $shipping ) {
			$offer['shippingDetails'] = $shipping;
		}
		$returns = self::return_policy();
		if ( $returns ) {
			$offer['hasMerchantReturnPolicy'] = $returns;
		}
		return array_filter( $offer, static fn( $v ) => '' !== $v && array() !== $v );
	}

	/**
	 * Display prices of the purchasable variations.
	 *
	 * @param \WC_Product $product Variable product.
	 * @return float[]
	 */
	private static function variable_prices( \WC_Product $product ): array {
		if ( ! $product instanceof \WC_Product_Variable ) {
			return array();
		}
		$prices = $product->get_variation_prices( true );
		return array_map( 'floatval', array_values( (array) ( $prices['price'] ?? array() ) ) );
	}

	/**
	 * AggregateOffer.
	 *
	 * @param \WC_Product $product Product.
	 * @param float[]     $prices  Prices.
	 * @param array       $seller  Seller.
	 * @return array<string, mixed>
	 */
	private static function aggregate_offer( \WC_Product $product, array $prices, array $seller ): array {
		if ( ! $prices ) {
			return array();
		}
		$offer = array(
			'@type'         => 'AggregateOffer',
			'lowPrice'      => self::price( min( $prices ) ),
			'highPrice'     => self::price( max( $prices ) ),
			'offerCount'    => count( $prices ),
			'priceCurrency' => get_woocommerce_currency(),
			'availability'  => self::availability( $product ),
			'url'           => (string) $product->get_permalink(),
			'itemCondition' => self::condition( $product ),
		);
		return self::offer_extras( $offer, $seller );
	}

	/**
	 * One Offer per purchasable variation.
	 *
	 * @param \WC_Product $product Variable product.
	 * @param array       $seller  Seller.
	 * @return array<int, array<string, mixed>>
	 */
	private static function variation_offers( \WC_Product $product, array $seller ): array {
		$offers = array();
		foreach ( $product->get_children() as $child_id ) {
			$variation = wc_get_product( $child_id );
			if ( ! $variation instanceof \WC_Product_Variation || ! $variation->variation_is_visible() ) {
				continue;
			}
			$offer = self::offer( $variation, $seller );
			if ( ! $offer ) {
				continue;
			}
			$offer    = array(
				'@type' => 'Offer',
				'name'  => html_entity_decode( wp_strip_all_tags( $variation->get_name() ), ENT_QUOTES, 'UTF-8' ),
				'sku'   => (string) $variation->get_sku(),
			) + self::identifiers( $variation ) + $offer;
			$offers[] = array_filter( $offer, static fn( $v ) => '' !== $v );
		}
		return $offers;
	}

	/**
	 * Schema.org availability URL.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function availability( \WC_Product $product ): string {
		$status = (string) $product->get_stock_status();
		if ( $product->is_type( 'variable' ) || $product->is_type( 'grouped' ) ) {
			$status = $product->is_in_stock() ? 'instock' : 'outofstock';
		}
		$map = array(
			'instock'     => 'InStock',
			'outofstock'  => 'OutOfStock',
			'onbackorder' => 'BackOrder',
		);
		return 'https://schema.org/' . ( $map[ $status ] ?? 'InStock' );
	}

	/**
	 * Item condition URL.
	 *
	 * @param \WC_Product $product Product.
	 */
	private static function condition( \WC_Product $product ): string {
		$parent    = $product->get_parent_id() ? wc_get_product( $product->get_parent_id() ) : null;
		$own       = (string) ( $parent ? $parent : $product )->get_meta( '_mgseo_condition' );
		$condition = '' !== $own ? $own : (string) self::opt( 'item_condition', 'NewCondition' );
		$allowed   = array( 'NewCondition', 'UsedCondition', 'RefurbishedCondition', 'DamagedCondition' );
		return in_array( $condition, $allowed, true ) ? 'https://schema.org/' . $condition : '';
	}

	/**
	 * Value of priceValidUntil (Y-m-d): sale end date, or one year from today.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function price_valid_until( \WC_Product $product ): string {
		$mode = (string) self::opt( 'price_valid_until', 'auto' );
		if ( 'none' === $mode ) {
			return '';
		}
		if ( 'auto' === $mode && $product->is_on_sale() ) {
			$end = $product->get_date_on_sale_to();
			if ( $end ) {
				return $end->date( 'Y-m-d' );
			}
		}
		return gmdate( 'Y-m-d', strtotime( '+1 year', time() ) );
	}

	/**
	 * Price formatted with the store decimals ("39.00").
	 *
	 * @param float $price Price.
	 */
	public static function price( float $price ): string {
		return number_format( $price, wc_get_price_decimals(), '.', '' );
	}

	/**
	 * Reviews.
	 *
	 * @param \WC_Product $product Product.
	 * @return array<int, array<string, mixed>>
	 */
	private static function reviews( \WC_Product $product ): array {
		$limit = (int) self::opt( 'reviews_limit', 5 );
		if ( $limit < 1 ) {
			return array();
		}
		$comments = get_comments(
			array(
				'post_id' => $product->get_id(),
				'status'  => 'approve',
				'type'    => 'review',
				'parent'  => 0,
				'number'  => $limit,
				'orderby' => 'comment_date_gmt',
				'order'   => 'DESC',
			)
		);
		$reviews  = array();
		foreach ( $comments as $comment ) {
			$rating = (int) get_comment_meta( (int) $comment->comment_ID, 'rating', true );
			$review = array(
				'@type'         => 'Review',
				'author'        => array(
					'@type' => 'Person',
					'name'  => '' !== trim( $comment->comment_author ) ? $comment->comment_author : __( 'Anonymous', 'mg-seo' ),
				),
				'datePublished' => mysql2date( 'c', $comment->comment_date_gmt . ' +0000', false ),
				'reviewBody'    => wp_strip_all_tags( $comment->comment_content ),
			);
			if ( $rating > 0 ) {
				$review['reviewRating'] = array(
					'@type'       => 'Rating',
					'ratingValue' => $rating,
					'bestRating'  => 5,
					'worstRating' => 1,
				);
			}
			$reviews[] = $review;
		}
		return $reviews;
	}

	/**
	 * OfferShippingDetails from settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function shipping_details(): array {
		if ( ! self::opt( 'shipping_enabled', false ) ) {
			return array();
		}
		$country = strtoupper( (string) self::opt( 'shipping_country', '' ) );
		$country = '' !== $country ? $country : self::store_country();
		$details = array(
			'@type'               => 'OfferShippingDetails',
			'shippingRate'        => array(
				'@type'    => 'MonetaryAmount',
				'value'    => (float) self::opt( 'shipping_rate', 0 ),
				'currency' => get_woocommerce_currency(),
			),
			'shippingDestination' => array(
				'@type'          => 'DefinedRegion',
				'addressCountry' => $country,
			),
			'deliveryTime'        => array(
				'@type'        => 'ShippingDeliveryTime',
				'handlingTime' => self::days( (int) self::opt( 'handling_min', 0 ), (int) self::opt( 'handling_max', 1 ) ),
				'transitTime'  => self::days( (int) self::opt( 'transit_min', 1 ), (int) self::opt( 'transit_max', 3 ) ),
			),
		);
		return $details;
	}

	/**
	 * QuantitativeValue in days.
	 *
	 * @param int $min Min.
	 * @param int $max Max.
	 * @return array<string, mixed>
	 */
	private static function days( int $min, int $max ): array {
		return array(
			'@type'    => 'QuantitativeValue',
			'minValue' => max( 0, $min ),
			'maxValue' => max( $min, $max ),
			'unitCode' => 'DAY',
		);
	}

	/**
	 * MerchantReturnPolicy from settings.
	 *
	 * @return array<string, mixed>
	 */
	public static function return_policy(): array {
		if ( ! self::opt( 'return_enabled', false ) ) {
			return array();
		}
		$country  = strtoupper( (string) self::opt( 'return_country', '' ) );
		$country  = '' !== $country ? $country : self::store_country();
		$category = (string) self::opt( 'return_category', 'MerchantReturnFiniteReturnWindow' );
		$policy   = array(
			'@type'                => 'MerchantReturnPolicy',
			'applicableCountry'    => $country,
			'returnPolicyCategory' => 'https://schema.org/' . $category,
		);
		if ( 'MerchantReturnNotPermitted' !== $category ) {
			if ( 'MerchantReturnFiniteReturnWindow' === $category ) {
				$policy['merchantReturnDays'] = max( 1, (int) self::opt( 'return_days', 14 ) );
			}
			$policy['returnMethod'] = 'https://schema.org/' . (string) self::opt( 'return_method', 'ReturnByMail' );
			$policy['returnFees']   = 'https://schema.org/' . (string) self::opt( 'return_fees', 'FreeReturn' );
		}
		return $policy;
	}

	/**
	 * Store country code.
	 */
	private static function store_country(): string {
		$default = (string) get_option( 'woocommerce_default_country', '' );
		return strtoupper( (string) strtok( $default, ':' ) );
	}

	/**
	 * Check a Product node against Google's Product rich result requirements.
	 *
	 * @param array $node Product node.
	 * @return array{errors: string[], warnings: string[]}
	 */
	public static function validate( array $node ): array {
		$errors   = array();
		$warnings = array();
		if ( '' === trim( (string) ( $node['name'] ?? '' ) ) ) {
			$errors[] = __( 'Missing "name".', 'mg-seo' );
		}
		if ( empty( $node['offers'] ) && empty( $node['review'] ) && empty( $node['aggregateRating'] ) ) {
			$errors[] = __( 'One of "offers", "review" or "aggregateRating" is required (set a price or collect reviews).', 'mg-seo' );
		}
		$offers = $node['offers'] ?? array();
		$offers = isset( $offers['@type'] ) ? array( $offers ) : (array) $offers;
		foreach ( $offers as $offer ) {
			$type  = (string) ( $offer['@type'] ?? '' );
			$price = 'AggregateOffer' === $type ? ( $offer['lowPrice'] ?? '' ) : ( $offer['price'] ?? '' );
			if ( '' === (string) $price ) {
				/* translators: %s: property name */
				$errors[] = sprintf( __( 'Offer is missing "%s".', 'mg-seo' ), 'AggregateOffer' === $type ? 'lowPrice' : 'price' );
			}
			if ( '' === (string) ( $offer['priceCurrency'] ?? '' ) ) {
				$errors[] = __( 'Offer is missing "priceCurrency".', 'mg-seo' );
			}
			if ( 'Offer' === $type && empty( $offer['availability'] ) ) {
				$warnings[] = __( 'Offer has no "availability".', 'mg-seo' );
			}
			if ( 'Offer' === $type && empty( $offer['shippingDetails'] ) ) {
				$warnings[] = __( 'Offer has no "shippingDetails" (recommended for merchant listings).', 'mg-seo' );
			}
			if ( 'Offer' === $type && empty( $offer['hasMerchantReturnPolicy'] ) ) {
				$warnings[] = __( 'Offer has no "hasMerchantReturnPolicy" (recommended for merchant listings).', 'mg-seo' );
			}
		}
		if ( empty( $node['image'] ) ) {
			$warnings[] = __( 'No product image ("image" is required for merchant listings).', 'mg-seo' );
		}
		if ( empty( $node['brand'] ) ) {
			$warnings[] = __( 'No "brand".', 'mg-seo' );
		}
		$has_id = false;
		foreach ( array( 'gtin', 'gtin8', 'gtin12', 'gtin13', 'gtin14', 'mpn' ) as $key ) {
			$has_id = $has_id || ! empty( $node[ $key ] );
		}
		if ( ! $has_id ) {
			$warnings[] = __( 'No GTIN or MPN.', 'mg-seo' );
		}
		if ( empty( $node['description'] ) ) {
			$warnings[] = __( 'No "description".', 'mg-seo' );
		}
		return array(
			'errors'   => array_values( array_unique( $errors ) ),
			'warnings' => array_values( array_unique( $warnings ) ),
		);
	}
}
