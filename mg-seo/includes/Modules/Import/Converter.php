<?php
/**
 * Value conversion helpers for importers (template variables, robots, booleans).
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Import;

use MGSEO\Variables;

defined( 'ABSPATH' ) || exit;

/**
 * Converts Rank Math (%var%) and Yoast (%%var%%) templates into MG SEO templates.
 *
 * Unknown variables are kept untouched and reported to the import log.
 */
final class Converter {

	/**
	 * Context of a template: plain SEO title/description or schema property.
	 */
	public const CONTEXT_META   = 'meta';
	public const CONTEXT_SCHEMA = 'schema';

	/**
	 * Rank Math variable names that differ in MG SEO (name => replacement template without %).
	 * A value of '' drops the variable.
	 *
	 * @return array<string, string>
	 */
	public static function rank_math_map(): array {
		return array(
			// Rank Math prints the primary category (or the first one) for %category%.
			'category'               => 'primary_category',
			'primary_taxonomy_terms' => 'primary_category',
			'term_title'             => 'term',
			'post_author'            => 'author',
			'author_name'            => 'name',
			'searchphrase'           => 'search_query',
			'count'                  => '',
			'title_tag'              => 'title',
		);
	}

	/**
	 * Yoast variable names mapped to MG SEO names.
	 *
	 * @return array<string, string>
	 */
	public static function yoast_map(): array {
		return array(
			'title'                => 'title',
			'sitename'             => 'sitename',
			'sitedesc'             => 'sitedesc',
			'sep'                  => 'sep',
			'excerpt'              => 'excerpt',
			'excerpt_only'         => 'excerpt_only',
			'category'             => 'categories',
			'primary_category'     => 'primary_category',
			'tag'                  => 'tags',
			'term_title'           => 'term',
			'term_description'     => 'term_description',
			'category_description' => 'term_description',
			'tag_description'      => 'term_description',
			'term_hierarchy'       => 'term',
			'name'                 => 'name',
			'author_first_name'    => 'name',
			'date'                 => 'date',
			'modified'             => 'modified',
			'currentyear'          => 'currentyear',
			'currentdate'          => 'currentdate',
			'currentmonth'         => 'currentmonth',
			'currentday'           => 'currentday',
			'currenttime'          => 'currenttime',
			'page'                 => 'page',
			'pagenumber'           => 'pagenumber',
			'pagetotal'            => 'pagetotal',
			'searchphrase'         => 'search_query',
			'pt_single'            => 'pt_single',
			'pt_plural'            => 'pt_plural',
			'focuskw'              => 'focuskw',
			'id'                   => 'id',
			'parent_title'         => 'parent_title',
			'userid'               => 'userid',
			'user_description'     => 'user_description',
			'archive_title'        => 'title',
			'permalink'            => 'url',
			'post_year'            => 'date(Y)',
			'post_month'           => 'date(F)',
			'post_day'             => 'date(j)',
			'post_content'         => 'excerpt',
		);
	}

	/**
	 * Names MG SEO understands (core, modules and the importer's compatibility variables).
	 *
	 * @return array<string, bool>
	 */
	public static function known(): array {
		static $known = null;
		if ( null === $known ) {
			$names = array_keys( Variables::describe() );
			$names = array_merge(
				$names,
				array_keys( Compat::descriptions() ),
				array( 'term_title', 'currentday', 'name', 'tag', 'keywords', 'seo_title', 'seo_description', 'post_thumbnail', 'org_url', 'org_logo', 'wc_price', 'wc_sku', 'wc_brand', 'wc_gtin', 'wc_shortdesc' )
			);
			$known = array_fill_keys( array_map( 'strtolower', $names ), true );
		}
		return $known;
	}

	/**
	 * Convert a Rank Math template.
	 *
	 * @param string $template Template.
	 * @param Log    $log      Log for unknown variables.
	 * @param string $context  CONTEXT_META or CONTEXT_SCHEMA.
	 */
	public static function rank_math( string $template, Log $log, string $context = self::CONTEXT_META ): string {
		if ( ! str_contains( $template, '%' ) ) {
			return $template;
		}
		$map   = self::rank_math_map();
		$known = self::known();
		return (string) preg_replace_callback(
			'/%([a-z0-9_]+)(?:\(([^)%]*)\))?%/i',
			static function ( array $m ) use ( $map, $known, $log, $context ): string {
				$name = strtolower( $m[1] );
				$arg  = $m[2] ?? '';
				// In a title or description field %seo_title% would refer to itself.
				if ( self::CONTEXT_META === $context && 'seo_title' === $name ) {
					return '%title%';
				}
				if ( self::CONTEXT_META === $context && 'seo_description' === $name ) {
					return '%excerpt%';
				}
				if ( in_array( $name, array( 'date', 'modified', 'currenttime' ), true ) && '' !== $arg ) {
					return '%' . $name . '(' . self::date_format( $arg ) . ')%';
				}
				if ( in_array( $name, array( 'categories', 'tags' ), true ) ) {
					return '%' . $name . '%';
				}
				if ( array_key_exists( $name, $map ) ) {
					return '' === $map[ $name ] ? '' : '%' . $map[ $name ] . '%';
				}
				if ( isset( $known[ $name ] ) ) {
					return $m[0];
				}
				$log->unknown_variable( $m[0] );
				return $m[0];
			},
			$template
		);
	}

	/**
	 * Convert a Yoast template (%%var%%) to MG SEO syntax.
	 *
	 * @param string $template Template.
	 * @param Log    $log      Log.
	 */
	public static function yoast( string $template, Log $log ): string {
		if ( ! str_contains( $template, '%%' ) ) {
			return $template;
		}
		$map = self::yoast_map();
		return (string) preg_replace_callback(
			'/%%([a-z0-9_\-]+)%%/i',
			static function ( array $m ) use ( $map, $log ): string {
				$name = strtolower( $m[1] );
				if ( isset( $map[ $name ] ) ) {
					return '%' . $map[ $name ] . '%';
				}
				if ( str_starts_with( $name, 'cf_' ) ) {
					return '%customfield(' . substr( $m[1], 3 ) . ')%';
				}
				if ( str_starts_with( $name, 'ct_desc_' ) ) {
					return '%customterm_desc(' . substr( $m[1], 8 ) . ')%';
				}
				if ( str_starts_with( $name, 'ct_' ) ) {
					return '%customterm(' . substr( $m[1], 3 ) . ')%';
				}
				$log->unknown_variable( $m[0] );
				return $m[0];
			},
			$template
		);
	}

	/**
	 * Repair a PHP date format whose escaping backslashes were lost (Rank Math stores "Y-m-dTH:i:sP").
	 *
	 * @param string $format Format.
	 */
	public static function date_format( string $format ): string {
		$compact = str_replace( '\\', '', $format );
		if ( 'Y-m-dTH:i:sP' === $compact || 'c' === $compact ) {
			return 'c';
		}
		return $format;
	}

	/**
	 * Rank Math / CMB2 boolean: "on", "1", true.
	 *
	 * @param mixed $value Value.
	 */
	public static function bool( mixed $value ): bool {
		if ( is_bool( $value ) ) {
			return $value;
		}
		$value = strtolower( trim( (string) $value ) );
		return in_array( $value, array( 'on', '1', 'yes', 'true' ), true );
	}

	/**
	 * Normalise a list of robots directives to MG SEO's vocabulary.
	 *
	 * @param mixed $value Array or comma separated string.
	 * @return string[]
	 */
	public static function robots( mixed $value ): array {
		if ( is_string( $value ) ) {
			$maybe = maybe_unserialize( $value );
			$value = is_array( $maybe ) ? $maybe : explode( ',', $value );
		}
		$allowed = array( 'index', 'noindex', 'nofollow', 'noarchive', 'nosnippet', 'noimageindex', 'notranslate' );
		$out     = array();
		foreach ( (array) $value as $directive ) {
			$directive = strtolower( trim( (string) $directive ) );
			if ( 'none' === $directive ) {
				$out[] = 'noindex';
				$out[] = 'nofollow';
				continue;
			}
			if ( in_array( $directive, $allowed, true ) ) {
				$out[] = $directive;
			}
		}
		$out = array_values( array_unique( $out ) );
		if ( in_array( 'noindex', $out, true ) ) {
			$out = array_values( array_diff( $out, array( 'index' ) ) );
		}
		return $out;
	}

	/**
	 * Advanced robots (max-snippet, max-video-preview, max-image-preview).
	 *
	 * @param mixed $value Value.
	 * @return array<string, string>
	 */
	public static function advanced_robots( mixed $value ): array {
		$value = is_string( $value ) ? maybe_unserialize( $value ) : $value;
		$out   = array();
		foreach ( array( 'max-snippet', 'max-video-preview', 'max-image-preview' ) as $key ) {
			if ( ! is_array( $value ) || ! isset( $value[ $key ] ) || '' === (string) $value[ $key ] ) {
				continue;
			}
			$raw = strtolower( trim( (string) $value[ $key ] ) );
			if ( 'max-image-preview' === $key ) {
				if ( in_array( $raw, array( 'none', 'standard', 'large' ), true ) ) {
					$out[ $key ] = $raw;
				}
				continue;
			}
			if ( is_numeric( $raw ) ) {
				$out[ $key ] = (string) (int) $raw;
			}
		}
		return $out;
	}

	/**
	 * Split a list of URLs separated by new lines, spaces or encoded spaces.
	 *
	 * @param mixed $value Value.
	 * @return string[]
	 */
	public static function url_list( mixed $value ): array {
		$parts = is_array( $value ) ? $value : preg_split( '/[\s,]+|%20/', (string) $value );
		$out   = array();
		foreach ( (array) $parts as $part ) {
			$url = esc_url_raw( trim( (string) $part ) );
			if ( '' !== $url ) {
				$out[] = $url;
			}
		}
		return array_values( array_unique( $out ) );
	}

	/**
	 * Map a profile URL to a MG SEO user social key.
	 *
	 * @param string $url URL.
	 */
	public static function profile_key( string $url ): string {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		$map  = array(
			'facebook.com'  => 'facebook',
			'linkedin.com'  => 'linkedin',
			'instagram.com' => 'instagram',
			'youtube.com'   => 'youtube',
			'pinterest.'    => 'pinterest',
			'tiktok.com'    => 'tiktok',
			'twitter.com'   => 'twitter',
			'x.com'         => 'twitter',
		);
		foreach ( $map as $needle => $key ) {
			$domain = rtrim( $needle, '.' );
			if ( $host === $domain || str_ends_with( $host, '.' . $domain ) || ( str_ends_with( $needle, '.' ) && str_contains( $host, $needle ) ) ) {
				return $key;
			}
		}
		return '';
	}
}
