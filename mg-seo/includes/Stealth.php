<?php
/**
 * No-fingerprint mode.
 *
 * @package MGSEO
 */

namespace MGSEO;

defined( 'ABSPATH' ) || exit;

/**
 * MG SEO never prints HTML comments, generator tags or plugin class names on the front end.
 * With stealth mode on (default) it additionally:
 * - hides the mg-seo REST namespace from the public /wp-json index for visitors,
 * - serves the sitemap stylesheet from a neutral URL (or not at all) instead of the plugin directory,
 * - never enqueues plugin assets on the front end.
 */
final class Stealth {

	/**
	 * Register hooks.
	 */
	public static function boot(): void {
		add_filter( 'rest_index', array( self::class, 'filter_rest_index' ) );
	}

	/**
	 * Remove our namespace and routes from the REST index for users without edit rights.
	 *
	 * @param \WP_REST_Response $response Index response.
	 */
	public static function filter_rest_index( \WP_REST_Response $response ): \WP_REST_Response {
		if ( ! Helpers::stealth() || current_user_can( 'edit_posts' ) ) {
			return $response;
		}
		$data = $response->get_data();
		if ( isset( $data['namespaces'] ) && is_array( $data['namespaces'] ) ) {
			$data['namespaces'] = array_values( array_diff( $data['namespaces'], array( Rest::NAMESPACE ) ) );
		}
		if ( isset( $data['routes'] ) && is_array( $data['routes'] ) ) {
			foreach ( array_keys( $data['routes'] ) as $route ) {
				if ( str_starts_with( (string) $route, '/' . Rest::NAMESPACE ) ) {
					unset( $data['routes'][ $route ] );
				}
			}
		}
		$response->set_data( $data );
		return $response;
	}
}
