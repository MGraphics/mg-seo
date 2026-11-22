<?php
/**
 * Keyword matching that tolerates inflection and short interposed words.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Analysis;

defined( 'ABSPATH' ) || exit;

/**
 * Finds a key phrase in text the way people write it, not letter for letter:
 * "masaż leczniczy Kraków" matches "masażu leczniczego w Krakowie".
 *
 * Words are compared by a shared stem (common prefix, accent-insensitive): the keyword word may lose at most
 * 2 trailing vowels and the text word may add at most 5 letters, so Polish case endings match ("rehabilitacja" →
 * "rehabilitację", "Kraków" → "Krakowie") while different words with the same beginning do not ("leczniczy" ≠
 * "lecznica"). 3-letter words take only a case ending ("ból" → "bólem", "seo" ≠ "seoul"); 1–2 letter words and
 * numbers must match exactly. Between two keyword words the text may contain one short connecting word
 * ("w", "na", "i", "do" …).
 */
final class Matcher {

	/**
	 * Connecting words that may stand between two keyword words.
	 */
	private const GLUE = array( 'w', 'we', 'na', 'i', 'oraz', 'do', 'z', 'ze', 'o', 'u', 'od', 'po', 'za', 'dla', 'a', 'in', 'on', 'at', 'of', 'for', 'the', 'and', 'to' );

	/**
	 * Endings a 3-letter keyword word may take (accent-free, as normalised).
	 */
	private const SHORT_ENDINGS = array( 'u', 'a', 'e', 'y', 'i', 'em', 'om', 'ie', 'ow', 'ach', 'ami', 'owi', 'owie', 's', 'es' );

	/**
	 * Most letters the keyword word may lose (case ending).
	 */
	private const MAX_CUT = 2;

	/**
	 * Most letters the text word may add after the shared stem.
	 */
	private const MAX_ADD = 5;

	/**
	 * Tokenised texts (normalised text => words), per request.
	 *
	 * @var array<string, string[]>
	 */
	private static array $cache = array();

	/**
	 * Split normalised text into words (letters and digits only).
	 *
	 * @param string $text Text, normalised with Analyzer::normalize().
	 * @return string[]
	 */
	public static function words( string $text ): array {
		$key = md5( $text );
		if ( isset( self::$cache[ $key ] ) ) {
			return self::$cache[ $key ];
		}
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		$words = is_array( $parts ) ? $parts : array();
		if ( count( self::$cache ) > 50 ) {
			self::$cache = array();
		}
		self::$cache[ $key ] = $words;
		return $words;
	}

	/**
	 * Whether two words are forms of the same word.
	 *
	 * @param string $keyword Keyword word (normalised).
	 * @param string $word    Text word (normalised).
	 */
	public static function same_word( string $keyword, string $word ): bool {
		if ( $keyword === $word ) {
			return true;
		}
		$len = mb_strlen( $keyword );
		if ( $len <= 2 || ctype_digit( $keyword ) ) {
			return false;
		}
		if ( $len <= 3 ) {
			// Short words take only a case ending: "ból" → "bólu", "bólem", but "seo" ≠ "seoul".
			return str_starts_with( $word, $keyword ) && in_array( mb_substr( $word, $len ), self::SHORT_ENDINGS, true );
		}
		$prefix = 0;
		$max    = min( $len, mb_strlen( $word ) );
		while ( $prefix < $max && mb_substr( $keyword, $prefix, 1 ) === mb_substr( $word, $prefix, 1 ) ) {
			++$prefix;
		}
		$cut = mb_substr( $keyword, $prefix );
		// The keyword may only lose a vowel ending ("rehabilitacja" → "rehabilitację"), never a consonant
		// ("leczniczy" ≠ "lecznica").
		return $prefix >= 3
			&& mb_strlen( $cut ) <= self::MAX_CUT
			&& ( '' === $cut || (bool) preg_match( '/^[aeiouy]+$/', $cut ) )
			&& mb_strlen( $word ) - $prefix <= self::MAX_ADD;
	}

	/**
	 * Find the phrase in text.
	 *
	 * @param string $keyword Keyword (normalised).
	 * @param string $text    Text (normalised).
	 * @return array<int, array{start: int, length: int}> Matches as word offsets.
	 */
	public static function find( string $keyword, string $text ): array {
		$needle = self::words( $keyword );
		$hay    = self::words( $text );
		$count  = count( $needle );
		if ( 0 === $count || ! $hay ) {
			return array();
		}
		$matches = array();
		$total   = count( $hay );
		for ( $i = 0; $i < $total; ) {
			$end = self::match_at( $needle, $hay, $i );
			if ( null === $end ) {
				++$i;
				continue;
			}
			$matches[] = array(
				'start'  => $i,
				'length' => $end - $i + 1,
			);
			$i         = $end + 1;
		}
		return $matches;
	}

	/**
	 * Number of occurrences.
	 *
	 * @param string $keyword Keyword (normalised).
	 * @param string $text    Text (normalised).
	 */
	public static function count( string $keyword, string $text ): int {
		return count( self::find( $keyword, $text ) );
	}

	/**
	 * The first matched fragment as written in the text (for "we also count …" hints).
	 *
	 * @param string $keyword Keyword (normalised).
	 * @param string $text    Text (normalised).
	 */
	public static function first_form( string $keyword, string $text ): string {
		$found = self::find( $keyword, $text );
		if ( ! $found ) {
			return '';
		}
		return implode( ' ', array_slice( self::words( $text ), $found[0]['start'], $found[0]['length'] ) );
	}

	/**
	 * Try to match all keyword words starting at a text position.
	 *
	 * @param string[] $needle Keyword words.
	 * @param string[] $hay    Text words.
	 * @param int      $start  Start index in $hay.
	 * @return int|null Index of the last matched text word, null when there is no match.
	 */
	private static function match_at( array $needle, array $hay, int $start ): ?int {
		$pos   = $start;
		$total = count( $hay );
		foreach ( $needle as $n => $word ) {
			if ( $pos >= $total ) {
				return null;
			}
			if ( self::same_word( $word, $hay[ $pos ] ) ) {
				++$pos;
				continue;
			}
			// One connecting word in between ("masaż leczniczy w Krakowie"), never before the first word.
			if ( $n > 0 && in_array( $hay[ $pos ], self::GLUE, true ) && $pos + 1 < $total && self::same_word( $word, $hay[ $pos + 1 ] ) ) {
				$pos += 2;
				continue;
			}
			return null;
		}
		return $pos - 1;
	}
}
