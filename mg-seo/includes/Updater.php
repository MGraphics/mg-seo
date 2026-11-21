<?php
/**
 * Self-hosted updates from GitHub Releases.
 *
 * @package MGSEO
 */

namespace MGSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Wires YahnisElsts/plugin-update-checker (MIT) to the public GitHub repository MGraphics/mg-seo.
 * The repository is public, so no access token is used.
 *
 * The checker is only loaded when:
 * - MGSEO_UPDATE_REPO is a non-empty URL (define it as '' in wp-config.php to opt out),
 * - the bundled library exists (the WordPress.org build ships without lib/plugin-update-checker),
 * - the plugin was not installed from WordPress.org (filter `mgseo/self_updates`).
 * It talks to the GitHub API only when WordPress itself checks for plugin updates.
 */
final class Updater {

	/**
	 * Boot the update checker.
	 */
	public static function boot(): void {
		$repo = defined( 'MGSEO_UPDATE_REPO' ) ? (string) MGSEO_UPDATE_REPO : '';
		$lib  = MGSEO_DIR . 'lib/plugin-update-checker/plugin-update-checker.php';
		/**
		 * Whether MG SEO checks GitHub Releases for updates.
		 *
		 * @param bool $enabled Enabled.
		 */
		$enabled = (bool) apply_filters( 'mgseo/self_updates', '' !== $repo && is_readable( $lib ) && ! self::installed_from_wporg() );
		if ( ! $enabled ) {
			return;
		}
		require_once $lib;
		if ( ! class_exists( '\\YahnisElsts\\PluginUpdateChecker\\v5\\PucFactory' ) ) {
			return;
		}
		$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker( trailingslashit( $repo ), MGSEO_FILE, 'mg-seo' );
		$api     = $checker->getVcsApi();
		if ( $api && method_exists( $api, 'enableReleaseAssets' ) ) {
			// Use the packaged mg-seo-x.y.z.zip asset attached to each GitHub release (not the source archive).
			$api->enableReleaseAssets( '/mg-seo[^\/]*\.zip($|[?&#])/i' );
		}
	}

	/**
	 * The WordPress.org build (bin/build.sh --wporg) ships without the Update URI header and without the
	 * update checker library, so either signal disables self-updates.
	 */
	public static function installed_from_wporg(): bool {
		if ( ! function_exists( 'get_file_data' ) ) {
			return false;
		}
		$headers = get_file_data( MGSEO_FILE, array( 'update_uri' => 'Update URI' ) );
		$uri     = (string) ( $headers['update_uri'] ?? '' );
		return '' === $uri || str_contains( $uri, 'wordpress.org' ) || str_starts_with( $uri, 'w.org' );
	}
}
