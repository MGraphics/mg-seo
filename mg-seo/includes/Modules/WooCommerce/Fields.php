<?php
/**
 * Product data panel fields (GTIN, MPN, ISBN, brand, condition) and the structured data check.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\WooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * WooCommerce admin product fields.
 */
final class Fields {

	/**
	 * Register hooks (admin only).
	 */
	public static function boot(): void {
		add_action( 'woocommerce_product_options_sku', array( self::class, 'render' ) );
		add_action( 'woocommerce_admin_process_product_object', array( self::class, 'save' ) );
		add_action( 'woocommerce_variation_options_pricing', array( self::class, 'render_variation' ), 20, 3 );
		add_action( 'woocommerce_save_product_variation', array( self::class, 'save_variation' ), 10, 2 );
	}

	/**
	 * Condition options.
	 *
	 * @return array<string, string>
	 */
	public static function conditions(): array {
		return array(
			''                     => __( 'Default', 'mg-seo' ),
			'NewCondition'         => __( 'New', 'mg-seo' ),
			'UsedCondition'        => __( 'Used', 'mg-seo' ),
			'RefurbishedCondition' => __( 'Refurbished', 'mg-seo' ),
			'DamagedCondition'     => __( 'Damaged', 'mg-seo' ),
		);
	}

	/**
	 * Fields in the Inventory tab.
	 */
	public static function render(): void {
		global $product_object;
		if ( ! $product_object instanceof \WC_Product || ! current_user_can( 'mgseo_edit_meta' ) ) {
			return;
		}
		echo '<div class="options_group">';
		woocommerce_wp_text_input(
			array(
				'id'          => '_mgseo_gtin',
				'label'       => __( 'GTIN (EAN, UPC)', 'mg-seo' ),
				'desc_tip'    => true,
				'description' => __( 'GTIN-8, GTIN-12 (UPC), GTIN-13 (EAN) or GTIN-14 for structured data. The schema property is chosen from the length. When empty, the WooCommerce GTIN field is used.', 'mg-seo' ),
				'value'       => (string) $product_object->get_meta( '_mgseo_gtin' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_mgseo_mpn',
				'label'       => __( 'MPN', 'mg-seo' ),
				'desc_tip'    => true,
				'description' => __( 'Manufacturer part number.', 'mg-seo' ),
				'value'       => (string) $product_object->get_meta( '_mgseo_mpn' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_mgseo_isbn',
				'label'       => __( 'ISBN', 'mg-seo' ),
				'desc_tip'    => true,
				'description' => __( 'For books. Used as GTIN-13 when no GTIN is set.', 'mg-seo' ),
				'value'       => (string) $product_object->get_meta( '_mgseo_isbn' ),
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'          => '_mgseo_brand',
				'label'       => __( 'Brand (SEO)', 'mg-seo' ),
				'desc_tip'    => true,
				'description' => __( 'Overrides the brand from the WooCommerce SEO settings.', 'mg-seo' ),
				'value'       => (string) $product_object->get_meta( '_mgseo_brand' ),
			)
		);
		woocommerce_wp_select(
			array(
				'id'      => '_mgseo_condition',
				'label'   => __( 'Condition', 'mg-seo' ),
				'options' => self::conditions(),
				'value'   => (string) $product_object->get_meta( '_mgseo_condition' ),
			)
		);
		if ( $product_object->get_id() && 'publish' === $product_object->get_status() ) {
			self::render_check( $product_object );
		}
		echo '</div>';
	}

	/**
	 * Structured data check of the saved product.
	 *
	 * @param \WC_Product $product Product.
	 */
	private static function render_check( \WC_Product $product ): void {
		$url    = (string) get_permalink( $product->get_id() );
		$node   = Product::node( $product, $url );
		$result = Product::validate( $node );
		echo '<div class="form-field" style="padding:0 12px 12px 162px"><strong>' . esc_html__( 'Product structured data', 'mg-seo' ) . '</strong><ul style="margin:4px 0 0">';
		if ( ! $result['errors'] ) {
			echo '<li>' . esc_html__( 'Eligible for Product rich results.', 'mg-seo' ) . '</li>';
		}
		foreach ( $result['errors'] as $message ) {
			echo '<li style="color:#b32d2e">' . esc_html( $message ) . '</li>';
		}
		foreach ( $result['warnings'] as $message ) {
			echo '<li style="color:#996800">' . esc_html( $message ) . '</li>';
		}
		echo '</ul></div>';
	}

	/**
	 * Save fields. WooCommerce verified the nonce and capability before this action.
	 *
	 * @param \WC_Product $product Product.
	 */
	public static function save( \WC_Product $product ): void {
		if ( ! current_user_can( 'mgseo_edit_meta' ) ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by WooCommerce (woocommerce_meta_nonce).
		foreach ( array( '_mgseo_gtin', '_mgseo_mpn', '_mgseo_isbn', '_mgseo_brand' ) as $key ) {
			if ( isset( $_POST[ $key ] ) ) {
				$value = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
				if ( '_mgseo_brand' !== $key && '_mgseo_mpn' !== $key ) {
					$value = (string) preg_replace( '/[^0-9Xx\-\s]/', '', $value );
				}
				self::set_meta( $product, $key, trim( $value ) );
			}
		}
		if ( isset( $_POST['_mgseo_condition'] ) ) {
			$condition = sanitize_text_field( wp_unslash( $_POST['_mgseo_condition'] ) );
			self::set_meta( $product, '_mgseo_condition', array_key_exists( $condition, self::conditions() ) ? $condition : '' );
		}
		// phpcs:enable
	}

	/**
	 * Variation fields.
	 *
	 * @param int      $loop           Index.
	 * @param array    $variation_data Data.
	 * @param \WP_Post $variation      Variation post.
	 */
	public static function render_variation( $loop, $variation_data, $variation ): void {
		if ( ! current_user_can( 'mgseo_edit_meta' ) || ! $variation instanceof \WP_Post ) {
			return;
		}
		$product = wc_get_product( $variation->ID );
		if ( ! $product ) {
			return;
		}
		woocommerce_wp_text_input(
			array(
				'id'            => 'mgseo_variation_gtin_' . $loop,
				'name'          => 'mgseo_variation_gtin[' . $loop . ']',
				'label'         => __( 'GTIN (EAN, UPC)', 'mg-seo' ),
				'value'         => (string) $product->get_meta( '_mgseo_gtin' ),
				'wrapper_class' => 'form-row form-row-first',
			)
		);
		woocommerce_wp_text_input(
			array(
				'id'            => 'mgseo_variation_mpn_' . $loop,
				'name'          => 'mgseo_variation_mpn[' . $loop . ']',
				'label'         => __( 'MPN', 'mg-seo' ),
				'value'         => (string) $product->get_meta( '_mgseo_mpn' ),
				'wrapper_class' => 'form-row form-row-last',
			)
		);
	}

	/**
	 * Save variation fields (nonce verified by WooCommerce's variation AJAX handler).
	 *
	 * @param int $variation_id Variation id.
	 * @param int $i            Index.
	 */
	public static function save_variation( $variation_id, $i ): void {
		if ( ! current_user_can( 'mgseo_edit_meta' ) || ! current_user_can( 'edit_post', (int) $variation_id ) ) {
			return;
		}
		$variation = wc_get_product( (int) $variation_id );
		if ( ! $variation ) {
			return;
		}
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- verified by WooCommerce (save-variations nonce).
		if ( isset( $_POST['mgseo_variation_gtin'][ $i ] ) ) {
			$gtin = (string) preg_replace( '/[^0-9\-\s]/', '', sanitize_text_field( wp_unslash( $_POST['mgseo_variation_gtin'][ $i ] ) ) );
			self::set_meta( $variation, '_mgseo_gtin', trim( $gtin ) );
		}
		if ( isset( $_POST['mgseo_variation_mpn'][ $i ] ) ) {
			self::set_meta( $variation, '_mgseo_mpn', trim( sanitize_text_field( wp_unslash( $_POST['mgseo_variation_mpn'][ $i ] ) ) ) );
		}
		// phpcs:enable
		$variation->save();
	}

	/**
	 * Set or delete product meta.
	 *
	 * @param \WC_Product $product Product.
	 * @param string      $key     Key.
	 * @param string      $value   Value.
	 */
	private static function set_meta( \WC_Product $product, string $key, string $value ): void {
		if ( '' === $value ) {
			$product->delete_meta_data( $key );
			return;
		}
		$product->update_meta_data( $key, $value );
	}
}
