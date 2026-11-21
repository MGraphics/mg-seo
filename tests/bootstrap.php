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

require $mgseo_tests_dir . '/includes/bootstrap.php';
require_once __DIR__ . '/TestCase.php';
