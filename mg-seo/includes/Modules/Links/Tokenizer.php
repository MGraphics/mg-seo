<?php
/**
 * Language-light tokenizer for local relevance scoring.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Links;

defined( 'ABSPATH' ) || exit;

/**
 * Lowercases, strips accents, drops stop words and applies a crude prefix stem (first 5 characters),
 * which groups most English and Polish inflections ("strona", "strony", "stronie") without dictionaries.
 */
final class Tokenizer {

	public const STEM = 5;

	/**
	 * Stop words (accents removed).
	 *
	 * @var array<string, true>|null
	 */
	private static ?array $stop = null;

	/**
	 * Stop word list.
	 *
	 * @return array<string, true>
	 */
	private static function stop_words(): array {
		if ( null === self::$stop ) {
			$words      = 'the and for are but not you all any can had her was one our out has have him his how its may new now old see two way who did get let put say she too use that with this from they will your what when were been more than then them into some such only over also just like most other which their there about would these could after where while because those being every very much many here each make made does doing should through before between under again both same than own off why yes '
				. 'jest sie oraz czy dla nie jak ale lub tak juz byc ich jej jego ten ta to tym tej tego tych tez przy przez pod nad bez dla od do na za ze po co gdy aby albo moze mozna jako jaki jaka jakie ktory ktora ktore ktorych kiedy gdzie wiec tylko bardzo takze jednak wszystko wszystkie inne innych sobie swoj swoje mamy jestem jestes sa byl byla bylo beda bedzie nas was nam wam mnie tobie dlaczego dzieki';
			self::$stop = array_fill_keys( explode( ' ', $words ), true );
		}
		return self::$stop;
	}

	/**
	 * Normalise text: decode entities, lowercase, strip accents, collapse spaces.
	 *
	 * @param string $text Text.
	 */
	public static function normalize( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = mb_strtolower( $text, 'UTF-8' );
		$text = remove_accents( $text );
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Plain text from HTML (blocks comments, shortcodes and tags removed).
	 *
	 * @param string $html HTML.
	 */
	public static function plain( string $html ): string {
		$html = (string) preg_replace( '/<!--.*?-->/s', ' ', $html );
		$html = (string) preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', $html );
		$html = strip_shortcodes( $html );
		return trim( (string) preg_replace( '/\s+/u', ' ', wp_strip_all_tags( str_replace( '<', ' <', $html ) ) ) );
	}

	/**
	 * Stemmed tokens (with repetitions).
	 *
	 * @param string $text Plain text.
	 * @return string[]
	 */
	public static function tokens( string $text ): array {
		$words = preg_split( '/[^\p{L}\p{N}]+/u', self::normalize( $text ), -1, PREG_SPLIT_NO_EMPTY );
		$stop  = self::stop_words();
		$out   = array();
		foreach ( (array) $words as $word ) {
			$len = mb_strlen( $word );
			if ( $len < 3 || isset( $stop[ $word ] ) || ctype_digit( $word ) ) {
				continue;
			}
			$out[] = $len > self::STEM ? mb_substr( $word, 0, self::STEM ) : $word;
		}
		return $out;
	}

	/**
	 * Weighted term vector of a document.
	 *
	 * @param string   $title    Title.
	 * @param string[] $keywords Focus keywords.
	 * @param string   $body     Plain body text (excerpt or content).
	 * @param int      $max      Max terms kept.
	 * @return array<string, float> term => weight
	 */
	public static function vector( string $title, array $keywords, string $body, int $max = 60 ): array {
		$vector = array();
		$add    = static function ( array $tokens, float $weight, float $cap ) use ( &$vector ): void {
			$counts = array_count_values( $tokens );
			foreach ( $counts as $term => $count ) {
				$vector[ $term ] = ( $vector[ $term ] ?? 0.0 ) + min( $cap, $weight * $count );
			}
		};
		$add( self::tokens( implode( ' ', $keywords ) ), 4.0, 8.0 );
		$add( self::tokens( $title ), 3.0, 6.0 );
		$body_tokens = self::tokens( $body );
		$counts      = array_count_values( $body_tokens );
		foreach ( $counts as $term => $count ) {
			$vector[ (string) $term ] = ( $vector[ (string) $term ] ?? 0.0 ) + min( 3.0, 1.0 + log( (float) $count ) );
		}
		arsort( $vector );
		$vector = array_slice( $vector, 0, $max, true );
		return array_map( static fn( $w ) => round( min( 12.0, $w ), 3 ), $vector );
	}

	/**
	 * Phrase containment with word boundaries on normalised text.
	 *
	 * @param string $phrase Phrase.
	 * @param string $text   Text.
	 */
	public static function contains( string $phrase, string $text ): bool {
		$phrase = self::normalize( $phrase );
		if ( '' === $phrase ) {
			return false;
		}
		return (bool) preg_match( '/(?<![\p{L}\p{N}])' . preg_quote( $phrase, '/' ) . '(?![\p{L}\p{N}])/u', self::normalize( $text ) );
	}
}
