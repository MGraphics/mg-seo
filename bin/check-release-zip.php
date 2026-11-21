<?php
/**
 * Release-zip regression check: runs the bundled plugin-update-checker from an unpacked
 * release zip, with the zip's own Updater::ASSET_REGEX, against a mocked GitHub release whose
 * body is Markdown (needs Parsedown) and which lists the -wporg asset before the GitHub package.
 * The update must come from mg-seo-<version>.zip, never from mg-seo-<version>-wporg.zip.
 *
 * Run through bin/check-release-zip.sh, which unpacks the zip and calls:
 *   wp eval-file bin/check-release-zip.php <unpacked plugins dir> --skip-plugins --skip-themes
 *
 * @package MGSEO
 */

// phpcs:disable WordPress.WP.AlternativeFunctions, WordPress.Security.EscapeOutput

$mgseo_dir  = rtrim( (string) ( $args[0] ?? '' ), '/' ) . '/mg-seo';
$mgseo_file = $mgseo_dir . '/mg-seo.php';
$mgseo_lib  = $mgseo_dir . '/lib/plugin-update-checker/plugin-update-checker.php';
$mgseo_upd  = $mgseo_dir . '/includes/Updater.php';

$mgseo_fail = static function ( string $msg ): void {
	fwrite( STDERR, "FAIL: $msg\n" );
	exit( 1 );
};

if ( ! is_readable( $mgseo_file ) || ! is_readable( $mgseo_lib ) || ! is_readable( $mgseo_upd ) ) {
	$mgseo_fail( "plugin, Updater.php or update checker missing in $mgseo_dir" );
}
foreach ( array( 'Parsedown.php', 'PucReadmeParser.php' ) as $mgseo_vendor ) {
	if ( ! is_readable( $mgseo_dir . '/lib/plugin-update-checker/vendor/' . $mgseo_vendor ) ) {
		fwrite( STDERR, "missing lib/plugin-update-checker/vendor/$mgseo_vendor\n" );
	}
}

$mgseo_release = array(
	'tag_name'     => 'v9.9.9',
	'name'         => 'MG SEO 9.9.9',
	'draft'        => false,
	'prerelease'   => false,
	'created_at'   => '2026-11-21T00:00:00Z',
	'published_at' => '2026-11-21T00:00:00Z',
	'zipball_url'  => 'https://api.github.com/repos/MGraphics/mg-seo/zipball/v9.9.9',
	'body'         => "## Changes\n\n* **Fixed** update check with a *Markdown* release body.\n",
	'assets'       => array(
		// Listed first on purpose: a loose filter would pick this one (no updater inside).
		array(
			'name'                 => 'mg-seo-9.9.9-wporg.zip',
			'url'                  => 'https://api.github.com/repos/MGraphics/mg-seo/releases/assets/2',
			'browser_download_url' => 'https://github.com/MGraphics/mg-seo/releases/download/v9.9.9/mg-seo-9.9.9-wporg.zip',
			'download_count'       => 1,
		),
		array(
			'name'                 => 'mg-seo-9.9.9.zip',
			'url'                  => 'https://api.github.com/repos/MGraphics/mg-seo/releases/assets/1',
			'browser_download_url' => 'https://github.com/MGraphics/mg-seo/releases/download/v9.9.9/mg-seo-9.9.9.zip',
			'download_count'       => 1,
		),
	),
);

// Serve the GitHub API from fixtures; nothing leaves the machine.
add_filter(
	'pre_http_request',
	static function ( $pre, $r, $url ) use ( &$mgseo_release ) {
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		$body = null;
		if ( str_ends_with( $path, '/releases/latest' ) ) {
			$body = $mgseo_release;
		} elseif ( str_ends_with( $path, '/releases' ) ) {
			$body = array( $mgseo_release );
		}
		if ( null === $body ) {
			return array(
				'headers'  => array(),
				'body'     => '{"message":"Not Found"}',
				'response' => array(
					'code'    => 404,
					'message' => 'Not Found',
				),
				'cookies'  => array(),
			);
		}
		return array(
			'headers'  => array( 'content-type' => 'application/json' ),
			'body'     => wp_json_encode( $body ),
			'response' => array(
				'code'    => 200,
				'message' => 'OK',
			),
			'cookies'  => array(),
		);
	},
	10,
	3
);

$mgseo_errors = array();
add_action(
	'puc_api_error',
	static function ( $error ) use ( &$mgseo_errors ) {
		$mgseo_errors[] = is_wp_error( $error ) ? $error->get_error_code() . ': ' . $error->get_error_message() : 'unknown';
	}
);

require_once $mgseo_lib;
require_once $mgseo_upd;

$mgseo_request = static function () use ( $mgseo_file, $mgseo_fail ) {
	$checker = \YahnisElsts\PluginUpdateChecker\v5\PucFactory::buildUpdateChecker( 'https://github.com/MGraphics/mg-seo/', $mgseo_file, 'mg-seo' );
	$api     = $checker->getVcsApi();
	// Same call as Updater::boot(), with the filter shipped in the zip.
	$api->enableReleaseAssets( \MGSEO\Updater::ASSET_REGEX, $api::REQUIRE_RELEASE_ASSETS );
	try {
		return $checker->requestInfo();
	} catch ( \Throwable $e ) {
		$mgseo_fail( get_class( $e ) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine() );
	}
};

$mgseo_info = $mgseo_request();

if ( ! $mgseo_info ) {
	$mgseo_fail( 'requestInfo() returned no update info. ' . implode( '; ', $mgseo_errors ) );
}
if ( '9.9.9' !== $mgseo_info->version ) {
	$mgseo_fail( 'unexpected version ' . wp_json_encode( $mgseo_info->version ) );
}
$mgseo_url = (string) $mgseo_info->download_url;
if ( 'https://api.github.com/repos/MGraphics/mg-seo/releases/assets/1' !== $mgseo_url && ! str_ends_with( $mgseo_url, '/mg-seo-9.9.9.zip' ) ) {
	$mgseo_fail( 'GitHub package mg-seo-9.9.9.zip not chosen: ' . $mgseo_url );
}
if ( str_contains( $mgseo_url, 'wporg' ) || str_ends_with( $mgseo_url, '/assets/2' ) ) {
	$mgseo_fail( 'wp.org asset chosen (it has no updater): ' . $mgseo_url );
}
$mgseo_changelog = (string) ( $mgseo_info->sections['changelog'] ?? '' );
if ( ! str_contains( $mgseo_changelog, '<strong>Fixed</strong>' ) ) {
	$mgseo_fail( 'changelog not rendered from Markdown: ' . $mgseo_changelog );
}

// A release that carries only the -wporg zip must not offer an update at all (no source-archive fallback).
$mgseo_release['assets'] = array( $mgseo_release['assets'][0] );
$mgseo_only_wporg        = $mgseo_request();
if ( $mgseo_only_wporg && ! empty( $mgseo_only_wporg->download_url ) ) {
	$mgseo_fail( 'update offered without the GitHub package: ' . $mgseo_only_wporg->download_url );
}

echo "OK: requestInfo() -> {$mgseo_info->version} from {$mgseo_url} (wporg asset skipped), Markdown changelog rendered.\n";
