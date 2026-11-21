<?php
/**
 * Focus keyword content analysis.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Analysis;

defined( 'ABSPATH' ) || exit;

/**
 * Pure analysis engine: give it the SEO title, description, slug, URL, HTML content and keywords;
 * it returns checks and a 0-100 score. No WordPress state is read except where passed in.
 */
final class Analyzer {

	/**
	 * Inputs.
	 *
	 * @var array<string, mixed>
	 */
	private array $input;

	/**
	 * Constructor.
	 *
	 * @param array $input Keys: title, description, slug, url, content (HTML), keywords (string[]), home_url, used_elsewhere (bool), locale.
	 */
	public function __construct( array $input ) {
		$this->input = array_merge(
			array(
				'title'          => '',
				'description'    => '',
				'slug'           => '',
				'url'            => '',
				'content'        => '',
				'keywords'       => array(),
				'home_url'       => '',
				'used_elsewhere' => false,
			),
			$input
		);
	}

	/**
	 * Normalise text for matching: lowercase, strip accents, collapse whitespace.
	 *
	 * @param string $text Text.
	 */
	public static function normalize( string $text ): string {
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = mb_strtolower( $text, 'UTF-8' );
		if ( function_exists( 'remove_accents' ) ) {
			$text = remove_accents( $text );
		}
		return trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
	}

	/**
	 * Whether the keyword occurs as a phrase in the text (word boundaries, accent-insensitive).
	 *
	 * @param string $keyword Keyword.
	 * @param string $text    Text.
	 */
	public static function contains( string $keyword, string $text ): bool {
		return self::count( $keyword, $text ) > 0;
	}

	/**
	 * Count phrase occurrences.
	 *
	 * @param string $keyword Keyword.
	 * @param string $text    Text.
	 */
	public static function count( string $keyword, string $text ): int {
		$keyword = self::normalize( $keyword );
		if ( '' === $keyword ) {
			return 0;
		}
		$pattern = '/(?<![\p{L}\p{N}])' . preg_quote( $keyword, '/' ) . '(?![\p{L}\p{N}])/u';
		return (int) preg_match_all( $pattern, self::normalize( $text ) );
	}

	/**
	 * Plain text of HTML content.
	 *
	 * @param string $html HTML.
	 */
	public static function text( string $html ): string {
		$html = (string) preg_replace( '/<!--.*?-->/s', ' ', $html );
		$html = (string) preg_replace( '#<(script|style)[^>]*>.*?</\1>#is', ' ', $html );
		$html = (string) preg_replace( '/\[[^\]]+\]/', ' ', $html );
		$text = wp_strip_all_tags( str_replace( '<', ' <', $html ) );
		return trim( (string) preg_replace( '/\s+/u', ' ', html_entity_decode( $text, ENT_QUOTES, 'UTF-8' ) ) );
	}

	/**
	 * Word count.
	 *
	 * @param string $text Plain text.
	 */
	public static function words( string $text ): int {
		if ( '' === trim( $text ) ) {
			return 0;
		}
		$parts = preg_split( '/[\s]+/u', trim( $text ) );
		return is_array( $parts ) ? count( $parts ) : 0;
	}

	/**
	 * Run all checks.
	 *
	 * @return array{score: int, checks: array<int, array{id: string, group: string, status: string, message: string, weight: int}>, stats: array<string, mixed>}
	 */
	public function run(): array {
		$keywords = array_values( array_filter( array_map( 'trim', (array) $this->input['keywords'] ) ) );
		$primary  = $keywords[0] ?? '';
		$html     = (string) $this->input['content'];
		$text     = self::text( $html );
		$words    = self::words( $text );
		$title    = (string) $this->input['title'];
		$desc     = (string) $this->input['description'];
		$checks   = array();

		$add = static function ( string $id, string $group, bool|string $status, string $message, int $weight ) use ( &$checks ): void {
			if ( is_bool( $status ) ) {
				$status = $status ? 'ok' : 'fail';
			}
			$checks[] = compact( 'id', 'group', 'status', 'message', 'weight' );
		};

		// Length checks apply with or without a keyword.
		$title_len = mb_strlen( $title );
		$add(
			'title_length',
			'title',
			$title_len >= 30 && $title_len <= 60 ? 'ok' : ( $title_len > 0 && $title_len <= 70 ? 'warn' : 'fail' ),
			/* translators: %d: characters */
			sprintf( __( 'SEO title has %d characters (aim for 30–60).', 'mg-seo' ), $title_len ),
			5
		);
		$desc_len = mb_strlen( $desc );
		$add(
			'description_length',
			'basic',
			$desc_len >= 120 && $desc_len <= 160 ? 'ok' : ( $desc_len >= 50 && $desc_len <= 200 ? 'warn' : 'fail' ),
			/* translators: %d: characters */
			sprintf( __( 'Meta description has %d characters (aim for 120–160).', 'mg-seo' ), $desc_len ),
			5
		);
		$min_words = 600;
		$add(
			'content_length',
			'basic',
			$words >= $min_words ? 'ok' : ( $words >= 300 ? 'warn' : 'fail' ),
			/* translators: 1: word count, 2: recommended minimum */
			sprintf( __( 'Content is %1$d words long (recommended at least %2$d).', 'mg-seo' ), $words, $min_words ),
			8
		);

		if ( '' !== $primary ) {
			$add( 'keyword_in_title', 'basic', self::contains( $primary, $title ), self::msg( self::contains( $primary, $title ), __( 'Focus keyword appears in the SEO title.', 'mg-seo' ), __( 'Add the focus keyword to the SEO title.', 'mg-seo' ) ), 10 );
			$add( 'keyword_in_description', 'basic', self::contains( $primary, $desc ), self::msg( self::contains( $primary, $desc ), __( 'Focus keyword appears in the meta description.', 'mg-seo' ), __( 'Add the focus keyword to the meta description.', 'mg-seo' ) ), 7 );

			$slug_kw = sanitize_title( $primary );
			$slug    = sanitize_title( rawurldecode( (string) $this->input['slug'] ) );
			$in_slug = '' !== $slug_kw && str_contains( $slug, $slug_kw );
			$add( 'keyword_in_url', 'basic', $in_slug, self::msg( $in_slug, __( 'Focus keyword is used in the URL.', 'mg-seo' ), __( 'Use the focus keyword in the URL slug.', 'mg-seo' ) ), 7 );

			$intro_words = max( 50, (int) ceil( $words * 0.1 ) );
			$all_words   = preg_split( '/\s+/u', $text );
			$intro       = implode( ' ', array_slice( is_array( $all_words ) ? $all_words : array(), 0, $intro_words ) );
			$in_intro    = self::contains( $primary, $intro );
			$add( 'keyword_in_intro', 'basic', $in_intro, self::msg( $in_intro, __( 'Focus keyword appears at the beginning of the content.', 'mg-seo' ), __( 'Use the focus keyword in the first paragraph.', 'mg-seo' ) ), 6 );

			$count = self::count( $primary, $text );
			$add(
				'keyword_in_content',
				'basic',
				$count > 0,
				/* translators: %d: occurrences */
				$count > 0 ? sprintf( _n( 'Focus keyword found %d time in the content.', 'Focus keyword found %d times in the content.', $count, 'mg-seo' ), $count ) : __( 'Focus keyword does not appear in the content.', 'mg-seo' ),
				8
			);

			$kw_words = max( 1, self::words( $primary ) );
			$density  = $words > 0 ? round( ( $count * $kw_words / $words ) * 100, 2 ) : 0.0;
			$add(
				'keyword_density',
				'additional',
				$density >= 0.5 && $density <= 2.5 ? 'ok' : ( $density > 0 && $density <= 3.5 ? 'warn' : 'fail' ),
				/* translators: %s: density percent */
				sprintf( __( 'Keyword density is %s%% (aim for 0.5–2.5%%).', 'mg-seo' ), number_format_i18n( $density, 2 ) ),
				6
			);

			preg_match_all( '#<h([2-4])[^>]*>(.*?)</h\1>#is', $html, $heads );
			$in_heads = false;
			foreach ( $heads[2] as $heading ) {
				if ( self::contains( $primary, wp_strip_all_tags( $heading ) ) ) {
					$in_heads = true;
					break;
				}
			}
			$add( 'keyword_in_subheadings', 'additional', $in_heads, self::msg( $in_heads, __( 'Focus keyword found in subheadings (H2–H4).', 'mg-seo' ), __( 'Use the focus keyword in at least one subheading.', 'mg-seo' ) ), 5 );

			preg_match_all( '/<img[^>]*\salt=(["\'])(.*?)\1/i', $html, $alts );
			$in_alt = false;
			foreach ( $alts[2] as $alt ) {
				if ( self::contains( $primary, $alt ) ) {
					$in_alt = true;
					break;
				}
			}
			$add( 'keyword_in_alt', 'additional', $in_alt, self::msg( $in_alt, __( 'Focus keyword found in an image alt attribute.', 'mg-seo' ), __( 'Add an image with the focus keyword in its alt text.', 'mg-seo' ) ), 4 );

			$title_norm = self::normalize( $title );
			$pos        = mb_strpos( $title_norm, self::normalize( $primary ) );
			$early      = false !== $pos && $pos <= max( 10, (int) ( mb_strlen( $title_norm ) * 0.5 ) );
			$add( 'keyword_title_start', 'title', $early, self::msg( $early, __( 'Focus keyword is near the beginning of the title.', 'mg-seo' ), __( 'Move the focus keyword closer to the beginning of the title.', 'mg-seo' ) ), 3 );

			$unique = ! $this->input['used_elsewhere'];
			$add( 'keyword_unique', 'additional', $unique, self::msg( $unique, __( 'Focus keyword is not used by other content yet.', 'mg-seo' ), __( 'Another post already targets this focus keyword.', 'mg-seo' ) ), 3 );

			foreach ( array_slice( $keywords, 1 ) as $secondary ) {
				$found = self::contains( $secondary, $text ) || self::contains( $secondary, $title );
				$add(
					'secondary_' . sanitize_key( $secondary ),
					'additional',
					$found ? 'ok' : 'warn',
					/* translators: %s: keyword */
					sprintf( $found ? __( 'Secondary keyword "%s" is used.', 'mg-seo' ) : __( 'Secondary keyword "%s" is not used yet.', 'mg-seo' ), $secondary ),
					0
				);
			}
		}

		$url_len = mb_strlen( (string) $this->input['url'] );
		$add(
			'url_length',
			'additional',
			$url_len <= 75,
			/* translators: %d: characters */
			sprintf( __( 'URL is %d characters long (keep it under 75).', 'mg-seo' ), $url_len ),
			3
		);

		$home_host = (string) wp_parse_url( (string) $this->input['home_url'], PHP_URL_HOST );
		preg_match_all( '/<a\s[^>]*href=(["\'])(.*?)\1[^>]*>/i', $html, $links );
		$internal = 0;
		$external = 0;
		foreach ( $links[2] as $href ) {
			if ( str_starts_with( $href, '#' ) || str_starts_with( $href, 'mailto:' ) || str_starts_with( $href, 'tel:' ) ) {
				continue;
			}
			$host = (string) wp_parse_url( $href, PHP_URL_HOST );
			if ( '' === $host || $host === $home_host ) {
				++$internal;
			} else {
				++$external;
			}
		}
		$add( 'internal_links', 'additional', $internal > 0, self::msg( $internal > 0, __( 'Content links to other pages of this site.', 'mg-seo' ), __( 'Add internal links to related content.', 'mg-seo' ) ), 5 );
		$add( 'external_links', 'additional', $external > 0 ? 'ok' : 'warn', self::msg( $external > 0, __( 'Content links to external sources.', 'mg-seo' ), __( 'Consider linking to a relevant external source.', 'mg-seo' ) ), 3 );

		$has_number = (bool) preg_match( '/\d/', $title );
		$add( 'title_number', 'title', $has_number ? 'ok' : 'warn', self::msg( $has_number, __( 'Title contains a number.', 'mg-seo' ), __( 'Titles with numbers often get more clicks.', 'mg-seo' ) ), 2 );

		$paragraphs = preg_split( '#</p>|\n\s*\n#i', $html );
		$paragraphs = is_array( $paragraphs ) ? $paragraphs : array();
		$long       = 0;
		foreach ( $paragraphs as $paragraph ) {
			if ( self::words( self::text( $paragraph ) ) > 150 ) {
				++$long;
			}
		}
		$add( 'short_paragraphs', 'readability', 0 === $long ? 'ok' : 'warn', self::msg( 0 === $long, __( 'Paragraphs are a readable length.', 'mg-seo' ), __( 'Some paragraphs are longer than 150 words; split them.', 'mg-seo' ) ), 3 );

		$media = (bool) preg_match( '/<(img|video|iframe|figure)\b/i', $html );
		$add( 'has_media', 'readability', $media ? 'ok' : 'warn', self::msg( $media, __( 'Content contains images or video.', 'mg-seo' ), __( 'Add images or video to the content.', 'mg-seo' ) ), 3 );

		$total  = 0;
		$earned = 0.0;
		foreach ( $checks as $check ) {
			$total  += $check['weight'];
			$earned += 'ok' === $check['status'] ? $check['weight'] : ( 'warn' === $check['status'] ? $check['weight'] * 0.5 : 0 );
		}
		$score = $total > 0 ? (int) round( $earned / $total * 100 ) : 0;
		if ( '' === $primary ) {
			// Without a keyword the score is capped: the most important checks cannot pass.
			$score = min( $score, 40 );
		}

		return array(
			'score'  => $score,
			'checks' => $checks,
			'stats'  => array(
				'words'    => $words,
				'internal' => $internal,
				'external' => $external,
			),
		);
	}

	/**
	 * Pick a message.
	 *
	 * @param bool   $ok   Passed.
	 * @param string $pass Pass message.
	 * @param string $fail Fail message.
	 */
	private static function msg( bool $ok, string $pass, string $fail ): string {
		return $ok ? $pass : $fail;
	}
}
