<?php
/**
 * KML file with business locations and a small sitemap that lists it.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Local;

use MGSEO\Meta;
use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Builds /locations.kml and /sitemap-locations.xml (location pages + the KML file).
 */
final class Kml {

	public const QUERY_VAR = 'kml_locations';

	/**
	 * Business data sets included in the KML file.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public static function places(): array {
		if ( Locations::enabled() ) {
			$places = array_map( array( Business::class, 'from_post' ), Locations::all() );
			if ( $places ) {
				return $places;
			}
		}
		$main = Business::from_options();
		return Business::has_address( $main ) || ( '' !== $main['lat'] && '' !== $main['lng'] ) ? array( $main ) : array();
	}

	/**
	 * KML document.
	 *
	 * @param array<int, array<string, mixed>>|null $places Places (defaults to places()).
	 */
	public static function document( ?array $places = null ): string {
		$places = $places ?? self::places();
		$esc    = static fn( $text ) => htmlspecialchars( (string) $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
		$xml    = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml   .= '<kml xmlns="http://www.opengis.net/kml/2.2" xmlns:atom="http://www.w3.org/2005/Atom">' . "\n<Document>\n";
		$xml   .= "\t<name>" . $esc( get_bloginfo( 'name' ) ) . "</name>\n";
		$xml   .= "\t<atom:link href=\"" . $esc( home_url( '/' ) ) . "\"/>\n";
		foreach ( $places as $place ) {
			$place  = Business::normalize( $place );
			$xml   .= "\t<Placemark>\n";
			$xml   .= "\t\t<name>" . $esc( $place['name'] ) . "</name>\n";
			$desc   = '' !== (string) $place['description'] ? $place['description'] : Business::address_line( $place );
			$xml   .= "\t\t<description>" . $esc( $desc ) . "</description>\n";
			$xml   .= "\t\t<address>" . $esc( Business::address_line( $place ) ) . "</address>\n";
			$phones = Business::phones( $place );
			if ( $phones ) {
				$xml .= "\t\t<phoneNumber>" . $esc( $phones[0]['number'] ) . "</phoneNumber>\n";
			}
			if ( '' !== (string) $place['url'] ) {
				$xml .= "\t\t<atom:link href=\"" . $esc( $place['url'] ) . "\"/>\n";
			}
			if ( '' !== (string) $place['lat'] && '' !== (string) $place['lng'] ) {
				// KML coordinates are longitude,latitude[,altitude].
				$xml .= "\t\t<Point><coordinates>" . $esc( (float) $place['lng'] . ',' . (float) $place['lat'] . ',0' ) . "</coordinates></Point>\n";
			}
			$xml .= "\t</Placemark>\n";
		}
		$xml .= "</Document>\n</kml>\n";
		return $xml;
	}

	/**
	 * URLs of the locations sitemap: public location pages (and their archive) and the KML file.
	 *
	 * @return array<int, array{loc: string, lastmod: string}>
	 */
	public static function sitemap_urls(): array {
		$urls = array();
		if ( Locations::enabled() && post_type_exists( Locations::POST_TYPE ) && is_post_type_viewable( Locations::POST_TYPE ) ) {
			$archive = get_post_type_archive_link( Locations::POST_TYPE );
			$posts   = Locations::all();
			if ( $archive && $posts ) {
				$urls[] = array(
					'loc'     => $archive,
					'lastmod' => self::lastmod(),
				);
			}
			foreach ( $posts as $post ) {
				if ( apply_filters( 'mgseo/sitemap/exclude_post', (bool) Meta::post( $post->ID, 'sitemap_exclude', false ), $post->ID, $post->post_type ) ) {
					continue;
				}
				$urls[] = array(
					'loc'     => (string) get_permalink( $post ),
					'lastmod' => (string) get_post_modified_time( 'c', true, $post ),
				);
			}
		}
		if ( Options::get( 'local', 'kml', true ) && self::places() ) {
			$urls[] = array(
				'loc'     => home_url( '/locations.kml' ),
				'lastmod' => self::lastmod(),
			);
		}
		return $urls;
	}

	/**
	 * Locations sitemap (urlset).
	 */
	public static function sitemap(): string {
		$xml  = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		$xml .= '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( self::sitemap_urls() as $url ) {
			$xml .= "\t<url>\n\t\t<loc>" . htmlspecialchars( $url['loc'], ENT_XML1, 'UTF-8' ) . "</loc>\n";
			$xml .= "\t\t<lastmod>" . htmlspecialchars( $url['lastmod'], ENT_XML1, 'UTF-8' ) . "</lastmod>\n\t</url>\n";
		}
		$xml .= "</urlset>\n";
		return $xml;
	}

	/**
	 * Last modification of the location data (W3C datetime).
	 */
	public static function lastmod(): string {
		$time = (int) get_option( 'mgseo_local_modified', 0 );
		if ( Locations::enabled() ) {
			foreach ( Locations::all() as $post ) {
				$time = max( $time, (int) get_post_modified_time( 'U', true, $post ) );
			}
		}
		return gmdate( 'c', $time > 0 ? $time : time() );
	}
}
