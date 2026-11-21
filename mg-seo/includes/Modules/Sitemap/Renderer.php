<?php
/**
 * Sitemap XML renderer.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Sitemap;

defined( 'ABSPATH' ) || exit;

/**
 * Builds sitemap XML strings (sitemaps.org protocol with Google image, news and video extensions).
 *
 * Entry format (urlset):
 *   array(
 *     'loc'     => absolute URL,
 *     'lastmod' => W3C datetime or '',
 *     'images'  => list of absolute image URLs,
 *     'news'    => array( name, language, publication_date, title ),
 *     'videos'  => list of array( thumbnail_loc, title, description, content_loc, player_loc, duration, publication_date, family_friendly ),
 *   )
 */
final class Renderer {

	public const NS_SITEMAP = 'http://www.sitemaps.org/schemas/sitemap/0.9';
	public const NS_IMAGE   = 'http://www.google.com/schemas/sitemap-image/1.1';
	public const NS_NEWS    = 'http://www.google.com/schemas/sitemap-news/0.9';
	public const NS_VIDEO   = 'http://www.google.com/schemas/sitemap-video/1.1';

	/**
	 * Sitemap index XML.
	 *
	 * @param array<int, array{loc: string, lastmod?: string}> $sitemaps Sitemaps.
	 * @param string                                           $xsl      Stylesheet URL ('' = none).
	 */
	public static function index( array $sitemaps, string $xsl = '' ): string {
		$xml  = self::prolog( $xsl );
		$xml .= '<sitemapindex xmlns="' . self::NS_SITEMAP . '">' . "\n";
		foreach ( $sitemaps as $sitemap ) {
			$xml .= "\t<sitemap>\n";
			$xml .= "\t\t<loc>" . self::url( $sitemap['loc'] ) . "</loc>\n";
			if ( ! empty( $sitemap['lastmod'] ) ) {
				$xml .= "\t\t<lastmod>" . self::esc( $sitemap['lastmod'] ) . "</lastmod>\n";
			}
			$xml .= "\t</sitemap>\n";
		}
		$xml .= "</sitemapindex>\n";
		return $xml;
	}

	/**
	 * URL set XML.
	 *
	 * @param array<int, array<string, mixed>> $entries Entries.
	 * @param string                           $xsl     Stylesheet URL ('' = none).
	 */
	public static function urlset( array $entries, string $xsl = '' ): string {
		$has = array(
			'image' => false,
			'news'  => false,
			'video' => false,
		);
		foreach ( $entries as $entry ) {
			$has['image'] = $has['image'] || ! empty( $entry['images'] );
			$has['news']  = $has['news'] || ! empty( $entry['news'] );
			$has['video'] = $has['video'] || ! empty( $entry['videos'] );
		}

		$xml  = self::prolog( $xsl );
		$xml .= '<urlset xmlns="' . self::NS_SITEMAP . '"';
		if ( $has['image'] ) {
			$xml .= ' xmlns:image="' . self::NS_IMAGE . '"';
		}
		if ( $has['news'] ) {
			$xml .= ' xmlns:news="' . self::NS_NEWS . '"';
		}
		if ( $has['video'] ) {
			$xml .= ' xmlns:video="' . self::NS_VIDEO . '"';
		}
		$xml .= ">\n";

		foreach ( $entries as $entry ) {
			$xml .= "\t<url>\n";
			$xml .= "\t\t<loc>" . self::url( (string) $entry['loc'] ) . "</loc>\n";
			if ( ! empty( $entry['lastmod'] ) ) {
				$xml .= "\t\t<lastmod>" . self::esc( (string) $entry['lastmod'] ) . "</lastmod>\n";
			}
			if ( ! empty( $entry['news'] ) ) {
				$xml .= self::news( $entry['news'] );
			}
			foreach ( array_slice( (array) ( $entry['images'] ?? array() ), 0, 1000 ) as $image ) {
				$xml .= "\t\t<image:image>\n\t\t\t<image:loc>" . self::url( (string) $image ) . "</image:loc>\n\t\t</image:image>\n";
			}
			foreach ( (array) ( $entry['videos'] ?? array() ) as $video ) {
				$xml .= self::video( $video );
			}
			$xml .= "\t</url>\n";
		}
		$xml .= "</urlset>\n";
		return $xml;
	}

	/**
	 * News block.
	 *
	 * @param array<string, string> $news News data.
	 */
	private static function news( array $news ): string {
		$xml  = "\t\t<news:news>\n";
		$xml .= "\t\t\t<news:publication>\n";
		$xml .= "\t\t\t\t<news:name>" . self::esc( (string) ( $news['name'] ?? '' ) ) . "</news:name>\n";
		$xml .= "\t\t\t\t<news:language>" . self::esc( (string) ( $news['language'] ?? '' ) ) . "</news:language>\n";
		$xml .= "\t\t\t</news:publication>\n";
		$xml .= "\t\t\t<news:publication_date>" . self::esc( (string) ( $news['publication_date'] ?? '' ) ) . "</news:publication_date>\n";
		$xml .= "\t\t\t<news:title>" . self::esc( (string) ( $news['title'] ?? '' ) ) . "</news:title>\n";
		$xml .= "\t\t</news:news>\n";
		return $xml;
	}

	/**
	 * Video block (element order follows the Google video sitemap schema).
	 *
	 * @param array<string, mixed> $video Video data.
	 */
	private static function video( array $video ): string {
		$xml  = "\t\t<video:video>\n";
		$xml .= "\t\t\t<video:thumbnail_loc>" . self::url( (string) $video['thumbnail_loc'] ) . "</video:thumbnail_loc>\n";
		$xml .= "\t\t\t<video:title>" . self::esc( self::limit( (string) $video['title'], 100 ) ) . "</video:title>\n";
		$xml .= "\t\t\t<video:description>" . self::esc( self::limit( (string) $video['description'], 2048 ) ) . "</video:description>\n";
		if ( ! empty( $video['content_loc'] ) ) {
			$xml .= "\t\t\t<video:content_loc>" . self::url( (string) $video['content_loc'] ) . "</video:content_loc>\n";
		}
		if ( ! empty( $video['player_loc'] ) ) {
			$xml .= "\t\t\t<video:player_loc>" . self::url( (string) $video['player_loc'] ) . "</video:player_loc>\n";
		}
		$duration = (int) ( $video['duration'] ?? 0 );
		if ( $duration > 0 && $duration <= 28800 ) {
			$xml .= "\t\t\t<video:duration>" . $duration . "</video:duration>\n";
		}
		if ( ! empty( $video['publication_date'] ) ) {
			$xml .= "\t\t\t<video:publication_date>" . self::esc( (string) $video['publication_date'] ) . "</video:publication_date>\n";
		}
		if ( isset( $video['family_friendly'] ) && false === $video['family_friendly'] ) {
			$xml .= "\t\t\t<video:family_friendly>no</video:family_friendly>\n";
		}
		$xml .= "\t\t</video:video>\n";
		return $xml;
	}

	/**
	 * XML declaration and optional stylesheet processing instruction.
	 *
	 * @param string $xsl Stylesheet URL.
	 */
	private static function prolog( string $xsl ): string {
		$xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		if ( '' !== $xsl ) {
			$xml .= '<?xml-stylesheet type="text/xsl" href="' . self::esc( $xsl ) . '"?>' . "\n";
		}
		return $xml;
	}

	/**
	 * Escape text for XML (also strips characters not allowed in XML 1.0).
	 *
	 * @param string $text Text.
	 */
	public static function esc( string $text ): string {
		if ( ! mb_check_encoding( $text, 'UTF-8' ) ) {
			$text = mb_convert_encoding( $text, 'UTF-8', 'UTF-8' );
		}
		$text = (string) preg_replace( '/[^\x{9}\x{A}\x{D}\x{20}-\x{D7FF}\x{E000}-\x{FFFD}\x{10000}-\x{10FFFF}]/u', '', $text );
		return htmlspecialchars( $text, ENT_XML1 | ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Encode and escape a URL: percent-encode spaces and non-ASCII characters (RFC 3986), then XML-escape.
	 *
	 * @param string $url URL.
	 */
	public static function url( string $url ): string {
		$url = html_entity_decode( trim( $url ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$url = (string) preg_replace_callback(
			'/[^\x21-\x7E]/',
			static fn( array $m ): string => rawurlencode( $m[0] ),
			$url
		);
		return self::esc( $url );
	}

	/**
	 * Limit text length (multibyte safe).
	 *
	 * @param string $text Text.
	 * @param int    $max  Max characters.
	 */
	private static function limit( string $text, int $max ): string {
		return mb_strlen( $text ) > $max ? mb_substr( $text, 0, $max ) : $text;
	}

	/**
	 * W3C datetime from a GMT MySQL datetime ('' when empty/invalid).
	 *
	 * @param string $gmt MySQL datetime in GMT.
	 */
	public static function date( string $gmt ): string {
		if ( '' === $gmt || str_starts_with( $gmt, '0000-00-00' ) ) {
			return '';
		}
		try {
			return ( new \DateTimeImmutable( $gmt, new \DateTimeZone( 'UTC' ) ) )->format( DATE_W3C );
		} catch ( \Exception $e ) {
			return '';
		}
	}
}
