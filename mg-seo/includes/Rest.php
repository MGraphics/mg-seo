<?php
/**
 * REST API registry.
 *
 * @package MGSEO
 */

namespace MGSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Modules register routes through `mgseo/rest_routes` (receives the namespace) on rest_api_init.
 */
final class Rest {

	public const NAMESPACE = 'mg-seo/v1';

	/**
	 * Register hooks.
	 */
	public static function boot(): void {
		add_action(
			'rest_api_init',
			static function (): void {
				/**
				 * Register MG SEO REST routes.
				 *
				 * @param string $namespace REST namespace.
				 */
				do_action( 'mgseo/rest_routes', self::NAMESPACE );
			}
		);
	}
}
