<?php
/**
 * Request → redirection matching.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Redirections;

defined( 'ABSPATH' ) || exit;

/**
 * Pure matching logic over the compiled rules (see Repository::compiled()).
 */
final class Matcher {

	/**
	 * Find the redirection for a request.
	 *
	 * Exact sources are looked up first (hash map), then contains/start/end/regex sources in id order.
	 * Non-regex sources are compared with the decoded path without leading/trailing slashes; regex sources
	 * see the same path (and, when that fails and a query string is present, "path?query").
	 *
	 * @param string $path       Request path relative to home, decoded, without leading/trailing slashes.
	 * @param string $query      Raw query string (without "?").
	 * @param array  $compiled   Compiled rules.
	 * @param string $query_mode ignore (match on path; sources containing "?" still need that query) |
	 *                           exact (the query must match the source exactly) | pass (like ignore, and the query is appended to the target).
	 * @return array{id: int, type: int, url: string, source: string}|null Absolute target URL ('' for 410/451).
	 */
	public static function match( string $path, string $query, array $compiled, string $query_mode = 'ignore' ): ?array {
		$nq          = '' !== $query ? Repository::normalize_query( $query ) : '';
		$request_key = '' !== $nq ? $path . '?' . $nq : $path;
		$keys        = array();
		if ( '' !== $nq ) {
			$keys[] = $request_key;
		}
		if ( '' === $nq || 'exact' !== $query_mode ) {
			$keys[] = $path;
		}

		foreach ( $keys as $key ) {
			$rule = $compiled['exact'][ $key ] ?? $compiled['exact_ci'][ mb_strtolower( $key ) ] ?? null;
			if ( $rule ) {
				$result = self::result( $rule, (string) $rule['url_to'], $key, $request_key, $query, $query_mode );
				if ( $result ) {
					return $result;
				}
			}
		}

		$subject = 'exact' === $query_mode && '' !== $nq ? $request_key : $path;
		foreach ( $compiled['other'] ?? array() as $rule ) {
			$target = self::test( $rule, $subject, $path, $query );
			if ( null === $target ) {
				continue;
			}
			$result = self::result( $rule, $target, (string) $rule['pattern'], $request_key, $query, $query_mode );
			if ( $result ) {
				return $result;
			}
		}
		return null;
	}

	/**
	 * Test one non-exact rule. Returns the (backreference-expanded) target or null.
	 *
	 * @param array  $rule    Rule with pattern/comparison/ignore_case.
	 * @param string $subject Subject (path, or path?query in exact query mode).
	 * @param string $path    Path.
	 * @param string $query   Raw query.
	 */
	private static function test( array $rule, string $subject, string $path, string $query ): ?string {
		$pattern = (string) $rule['pattern'];
		$ci      = ! empty( $rule['ignore_case'] );
		$target  = (string) $rule['url_to'];

		if ( 'regex' === $rule['comparison'] ) {
			$regex    = Repository::regex( $pattern, $ci );
			$subjects = array( $path );
			if ( '' !== $query ) {
				$subjects[] = $path . '?' . $query;
			}
			foreach ( $subjects as $candidate ) {
				// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- user supplied pattern, validated on save.
				if ( @preg_match( $regex, $candidate, $m ) ) {
					return self::backreferences( $target, $m );
				}
			}
			return null;
		}

		$hay    = $ci ? mb_strtolower( $subject ) : $subject;
		$needle = $ci ? mb_strtolower( $pattern ) : $pattern;
		$hit    = match ( $rule['comparison'] ) {
			'contains' => str_contains( '/' . $hay . '/', $needle ),
			'start'    => str_starts_with( $hay, $needle ) || str_starts_with( $hay . '/', $needle ),
			'end'      => str_ends_with( $hay, $needle ) || str_ends_with( $hay . '/', $needle ),
			default    => false,
		};
		return $hit ? $target : null;
	}

	/**
	 * Replace $1…$99 in the target with regex groups.
	 *
	 * @param string $target  Target.
	 * @param array  $matches preg matches.
	 */
	public static function backreferences( string $target, array $matches ): string {
		return (string) preg_replace_callback(
			'/\$(\d{1,2})/',
			static fn( $m ) => isset( $matches[ (int) $m[1] ] ) ? self::encode_capture( (string) $matches[ (int) $m[1] ] ) : '',
			$target
		);
	}

	/**
	 * Encode a captured value for use in a URL path while keeping slashes.
	 *
	 * @param string $value Captured value.
	 */
	private static function encode_capture( string $value ): string {
		return implode( '/', array_map( static fn( $part ) => str_replace( '%2B', '+', rawurlencode( $part ) ), explode( '/', $value ) ) );
	}

	/**
	 * Build the match result; null when it would loop back to the requested URL.
	 *
	 * @param array  $rule        Rule.
	 * @param string $target      Target (relative or absolute).
	 * @param string $source      Matched source pattern.
	 * @param string $request_key Normalised request (path?query).
	 * @param string $query       Raw query.
	 * @param string $query_mode  Query mode.
	 */
	private static function result( array $rule, string $target, string $source, string $request_key, string $query, string $query_mode ): ?array {
		$type = (int) $rule['type'];
		if ( Repository::is_gone_type( $type ) ) {
			return array(
				'id'     => (int) $rule['id'],
				'type'   => $type,
				'url'    => '',
				'source' => $source,
			);
		}
		$url = self::absolute( $target );
		if ( 'pass' === $query_mode && '' !== $query ) {
			$url .= ( str_contains( $url, '?' ) ? '&' : '?' ) . $query;
		}
		$target_key = Repository::local_path( $url );
		if ( null !== $target_key ) {
			$path_only = (string) strtok( $request_key, '?' );
			if ( $target_key === $request_key || ( ! str_contains( $target_key, '?' ) && $target_key === $path_only && 'exact' !== $query_mode && ! str_contains( $source, '?' ) ) ) {
				return null;
			}
		}
		return array(
			'id'     => (int) $rule['id'],
			'type'   => $type,
			'url'    => $url,
			'source' => $source,
		);
	}

	/**
	 * Make a stored target absolute.
	 *
	 * @param string $target Target.
	 */
	public static function absolute( string $target ): string {
		if ( preg_match( '#^https?://#i', $target ) ) {
			return $target;
		}
		return home_url( '/' . ltrim( $target, '/' ) );
	}
}
