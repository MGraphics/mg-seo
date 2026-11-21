<?php
/**
 * PHPUnit bootstrap: loads the WordPress test suite and MG SEO.
 *
 * @package MGSEO
 */

require_once dirname( __DIR__ ) . '/vendor/autoload.php';

$mgseo_tests_dir = getenv( 'WP_PHPUNIT__DIR' );
require_once $mgseo_tests_dir . '/includes/functions.php';

tests_add_filter(
	'muplugins_loaded',
	static function (): void {
		require dirname( __DIR__ ) . '/mg-seo/mg-seo.php';
	}
);

tests_add_filter(
	'setup_theme',
	static function (): void {
		MGSEO\Installer::activate();
	}
);

// Optional: load WooCommerce from the dev site volume (MGSEO_TEST_WOOCOMMERCE=1). The default suite is unaffected.
if ( getenv( 'MGSEO_TEST_WOOCOMMERCE' ) ) {
	tests_add_filter(
		'muplugins_loaded',
		static function (): void {
			$file = ABSPATH . 'wp-content/plugins/woocommerce/woocommerce.php';
			if ( file_exists( $file ) ) {
				require $file;
			}
		},
		5
	);
	tests_add_filter(
		'setup_theme',
		static function (): void {
			if ( class_exists( 'WC_Install' ) ) {
				WC_Install::install();
				$GLOBALS['wp_roles'] = null; // phpcs:ignore WordPress.WP.GlobalVariablesOverride.Prohibited -- reload roles after WooCommerce added capabilities.
				wp_roles();
			}
		},
		20
	);
}

require $mgseo_tests_dir . '/includes/bootstrap.php';
require_once __DIR__ . '/TestCase.php';
