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
 *
 * Every check says what to do (`message`, a short headline) and why or how (`help`, one sentence).
 * Keyword checks carry `place` (where the keyword is missing) so the report can merge them into one
 * hint when the keyword is missing from the page altogether.
 */
final class Analyzer {

	public const PROFILE_ARTICLE = 'article';
	public const PROFILE_PAGE    = 'page';

	/**
	 * Whether keywords match inflected forms ("Kraków" in "w Krakowie"). See Matcher.
	 *
	 * @var bool
	 */
	public static bool $inflection = true;

	/**
	 * Inputs.
	 *
	 * @var array<string, mixed>
	 */
	private array $input;

	/**
	 * Constructor.
	 *
	 * @param array $input Keys: title, description, slug, url, content (HTML), keywords (string[]), home_url,
	 *                     used_elsewhere (bool or array{id: int, title: string, url: string}), profile (article|page),
	 *                     min_words (int, 0 = no minimum), auto_redirect (bool: slug changes are redirected).
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
				'profile'        => self::PROFILE_ARTICLE,
				'min_words'      => 600,
				'auto_redirect'  => false,
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
	 * Whether the keyword occurs in the text (accent-insensitive, inflected forms count, see Matcher).
	 *
	 * @param string $keyword Keyword.
	 * @param string $text    Text.
	 */
	public static function contains( string $keyword, string $text ): bool {
		return self::count( $keyword, $text ) > 0;
	}

	/**
	 * Count keyword occurrences.
	 *
	 * @param string $keyword Keyword.
	 * @param string $text    Text.
	 */
	public static function count( string $keyword, string $text ): int {
		$keyword = self::normalize( $keyword );
		if ( '' === $keyword ) {
			return 0;
		}
		if ( self::$inflection ) {
			return Matcher::count( $keyword, self::normalize( $text ) );
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
		$html = (string) preg_replace( '#<(script|style|template|noscript)[^>]*>.*?</\1>#is', ' ', $html );
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
	 * @return array{score: int, checks: array<int, array<string, mixed>>, stats: array<string, mixed>}
	 */
	public function run(): array {
		$keywords = array_values( array_filter( array_map( 'trim', (array) $this->input['keywords'] ) ) );
		$primary  = $keywords[0] ?? '';
		$html     = (string) $this->input['content'];
		$text     = self::text( $html );
		$words    = self::words( $text );
		$title    = (string) $this->input['title'];
		$desc     = (string) $this->input['description'];
		$article  = self::PROFILE_PAGE !== $this->input['profile'];
		$checks   = array();

		/*
		 * Add a check.
		 * $extra: help (why / how), place (label of the spot where the keyword is missing), data (for the UI).
		 */
		$add = static function ( string $id, string $group, bool|string $status, string $message, int $weight, array $extra = array() ) use ( &$checks ): void {
			if ( is_bool( $status ) ) {
				$status = $status ? 'ok' : 'fail';
			}
			$checks[] = array_merge(
				compact( 'id', 'group', 'status', 'message', 'weight' ),
				array(
					'help'  => '',
					'place' => '',
					'data'  => array(),
				),
				$extra
			);
		};

		// Length checks apply with or without a keyword.
		$title_len = mb_strlen( $title );
		if ( $title_len >= 30 && $title_len <= 60 ) {
			/* translators: %d: number of characters */
			$add( 'title_length', 'title', 'ok', sprintf( _n( 'The title in Google has a good length (%d character)', 'The title in Google has a good length (%d characters)', $title_len, 'mg-seo' ), $title_len ), 5 );
		} elseif ( $title_len > 60 ) {
			$add(
				'title_length',
				'title',
				$title_len <= 70 ? 'warn' : 'fail',
				/* translators: %d: number of characters */
				sprintf( _n( 'The title in Google is too long: %d character', 'The title in Google is too long: %d characters', $title_len, 'mg-seo' ), $title_len ),
				5,
				array(
					'help' => __( 'Google cuts it off after about 60 characters. Shorten the title or remove the site name from it.', 'mg-seo' ),
					'data' => array( 'focus' => 'title' ),
				)
			);
		} else {
			$add(
				'title_length',
				'title',
				$title_len > 0 ? 'warn' : 'fail',
				/* translators: %d: number of characters */
				$title_len > 0 ? sprintf( _n( 'The title in Google is short: %d character', 'The title in Google is short: %d characters', $title_len, 'mg-seo' ), $title_len ) : __( 'Write a title for Google', 'mg-seo' ),
				5,
				array(
					'help' => __( 'There is room to say what the page is about (up to about 60 characters).', 'mg-seo' ),
					'data' => array( 'focus' => 'title' ),
				)
			);
		}

		$desc_len = mb_strlen( $desc );
		$desc_st  = $desc_len >= 120 && $desc_len <= 160 ? 'ok' : ( $desc_len >= 50 && $desc_len <= 200 ? 'warn' : 'fail' );
		if ( 'ok' === $desc_st ) {
			/* translators: %d: number of characters */
			$add( 'description_length', 'basic', 'ok', sprintf( _n( 'The description in Google has a good length (%d character)', 'The description in Google has a good length (%d characters)', $desc_len, 'mg-seo' ), $desc_len ), 5 );
		} elseif ( $desc_len > 160 ) {
			$add(
				'description_length',
				'basic',
				$desc_st,
				/* translators: %d: number of characters */
				sprintf( _n( 'The description in Google is too long: %d character', 'The description in Google is too long: %d characters', $desc_len, 'mg-seo' ), $desc_len ),
				5,
				array(
					'help' => __( 'Google cuts it off after about 160 characters. Put the most important part at the beginning.', 'mg-seo' ),
					'data' => array( 'focus' => 'description' ),
				)
			);
		} else {
			$add(
				'description_length',
				'basic',
				$desc_st,
				/* translators: %d: number of characters */
				$desc_len > 0 ? sprintf( _n( 'The description in Google is too short: %d character', 'The description in Google is too short: %d characters', $desc_len, 'mg-seo' ), $desc_len ) : __( 'Write a description for Google', 'mg-seo' ),
				5,
				array(
					'help' => __( 'This is the text under the title in search results. Write 1 or 2 sentences (120–160 characters) that make people want to click.', 'mg-seo' ),
					'data' => array( 'focus' => 'description' ),
				)
			);
		}

		$min_words = max( 0, (int) $this->input['min_words'] );
		if ( $min_words > 0 ) {
			$content_st = $words >= $min_words ? 'ok' : ( $words >= (int) ceil( $min_words / 2 ) ? 'warn' : 'fail' );
			if ( 'ok' === $content_st ) {
				/* translators: %d: number of words */
				$message = sprintf( _n( 'The text has a good length (%d word)', 'The text has a good length (%d words)', $words, 'mg-seo' ), $words );
				$help    = '';
			} elseif ( 'warn' === $content_st ) {
				/* translators: %d: number of words */
				$message = sprintf( _n( 'The text is short: %d word', 'The text is short: %d words', $words, 'mg-seo' ), $words );
				/* translators: %d: recommended number of words */
				$help = sprintf( _n( 'Cover the topic in more depth, ideally in %d word or more.', 'Cover the topic in more depth, ideally in %d words or more.', $min_words, 'mg-seo' ), $min_words );
			} else {
				/* translators: %d: number of words */
				$message = sprintf( _n( 'The text is very short: %d word', 'The text is very short: %d words', $words, 'mg-seo' ), $words );
				/* translators: %d: recommended number of words */
				$help = sprintf( _n( 'Texts this short rarely rank high in Google. Cover the topic in more depth, ideally in %d word or more.', 'Texts this short rarely rank high in Google. Cover the topic in more depth, ideally in %d words or more.', $min_words, 'mg-seo' ), $min_words );
			}
			$add( 'content_length', 'basic', $content_st, $message, 8, array( 'help' => $help ) );
		}

		if ( '' !== $primary ) {
			$in_title = self::contains( $primary, $title );
			$add(
				'keyword_in_title',
				'basic',
				$in_title,
				$in_title ? __( 'The keyword is in the title', 'mg-seo' ) : __( 'Add the keyword to the title', 'mg-seo' ),
				10,
				array(
					'help'  => $in_title ? '' : __( 'The title is the first place where Google and readers look for what the page is about.', 'mg-seo' ),
					'place' => __( 'title', 'mg-seo' ),
					'data'  => array( 'focus' => 'title' ),
				)
			);

			$in_desc = self::contains( $primary, $desc );
			$add(
				'keyword_in_description',
				'basic',
				$in_desc,
				$in_desc ? __( 'The keyword is in the description in Google', 'mg-seo' ) : __( 'Add the keyword to the description in Google', 'mg-seo' ),
				7,
				array(
					'help'     => $in_desc ? '' : __( 'Google highlights the searched words in the description, so the result stands out.', 'mg-seo' ),
					'place'    => __( 'description in Google', 'mg-seo' ),
					'data'     => array( 'focus' => 'description' ),
					// No description at all is one cause: "Write a description" covers it.
					'requires' => 0 === $desc_len ? 'description_length' : '',
				)
			);

			$slug_kw = sanitize_title( $primary );
			$slug    = sanitize_title( rawurldecode( (string) $this->input['slug'] ) );
			$in_slug = '' !== $slug_kw && ( str_contains( $slug, $slug_kw ) || self::contains( str_replace( '-', ' ', $slug_kw ), str_replace( '-', ' ', $slug ) ) );
			$help    = '';
			if ( ! $in_slug ) {
				/* translators: %s: example address ending, e.g. /physiotherapy-krakow/ */
				$help = sprintf( __( 'The address is shown in search results. Change its ending, e.g. to %s.', 'mg-seo' ), '/' . $slug_kw . '/' );
				if ( $this->input['auto_redirect'] ) {
					$help .= ' ' . __( 'The old address will be redirected automatically.', 'mg-seo' );
				}
			}
			$add(
				'keyword_in_url',
				'basic',
				$in_slug,
				$in_slug ? __( 'The keyword is in the page address', 'mg-seo' ) : __( 'Add the keyword to the page address', 'mg-seo' ),
				7,
				array(
					'help'  => $help,
					'place' => __( 'page address', 'mg-seo' ),
					'data'  => array( 'focus' => 'slug' ),
				)
			);

			$intro_words = max( 50, (int) ceil( $words * 0.1 ) );
			$all_words   = preg_split( '/\s+/u', $text );
			$intro       = implode( ' ', array_slice( is_array( $all_words ) ? $all_words : array(), 0, $intro_words ) );
			$in_intro    = self::contains( $primary, $intro );
			$add(
				'keyword_in_intro',
				'basic',
				$in_intro,
				$in_intro ? __( 'The keyword is at the beginning of the text', 'mg-seo' ) : __( 'Use the keyword at the beginning of the text', 'mg-seo' ),
				6,
				array(
					'help'  => $in_intro ? '' : __( 'Ideally in the first paragraph: readers know right away they are in the right place.', 'mg-seo' ),
					'place' => __( 'first paragraph', 'mg-seo' ),
				)
			);

			$count = self::count( $primary, $text );
			$form  = self::$inflection ? Matcher::first_form( self::normalize( $primary ), self::normalize( $text ) ) : '';
			$help  = '';
			if ( $count > 0 && '' !== $form && self::normalize( $primary ) !== $form ) {
				/* translators: %s: keyword form found in the text */
				$help = sprintf( __( 'Inflected forms and short words in between count too, e.g. “%s”.', 'mg-seo' ), self::original_form( $primary, $text ) );
			} elseif ( 0 === $count ) {
				$help = __( 'Google will not connect the page with what people search for. Use the keyword in the title and in the first paragraph, then in one subheading.', 'mg-seo' );
			}
			$add(
				'keyword_in_content',
				'basic',
				$count > 0,
				/* translators: 1: keyword, 2: number of occurrences */
				$count > 0 ? sprintf( _n( 'The keyword appears in the text %2$d time', 'The keyword appears in the text %2$d times', $count, 'mg-seo' ), $primary, $count ) : sprintf( __( 'The keyword “%1$s” is not on this page', 'mg-seo' ), $primary, $count ),
				8,
				array(
					'help' => $help,
					'data' => array( 'count' => $count ),
				)
			);

			$kw_words = max( 1, self::words( $primary ) );
			$density  = $words > 0 ? round( ( $count * $kw_words / $words ) * 100, 2 ) : 0.0;
			$low      = max( 1, (int) ceil( $words * 0.005 / $kw_words ) );
			$high     = max( $low + 1, (int) floor( $words * 0.025 / $kw_words ) );
			$dens_st  = $density >= 0.5 && $density <= 2.5 ? 'ok' : ( $density > 0 && $density <= 3.5 ? 'warn' : 'fail' );
			if ( 'ok' === $dens_st ) {
				/* translators: %d: number of occurrences */
				$message = sprintf( _n( 'The keyword is used %d time, just right', 'The keyword is used %d times, just right', $count, 'mg-seo' ), $count );
				$help    = '';
			} elseif ( $density > 2.5 ) {
				/* translators: %d: number of occurrences */
				$message = sprintf( _n( 'The keyword is repeated too often: %d time', 'The keyword is repeated too often: %d times', $count, 'mg-seo' ), $count );
				$help    = __( 'This sounds unnatural. Replace some of the repetitions with other words.', 'mg-seo' );
			} else {
				$message = 1 === $count ? __( 'The keyword appears in the text only once', 'mg-seo' ) : sprintf(
					/* translators: %d: number of occurrences */
					_n( 'The keyword appears in the text too rarely: %d time', 'The keyword appears in the text too rarely: %d times', $count, 'mg-seo' ),
					$count
				);
				/* translators: 1: minimum, 2: maximum number of uses */
				$help = sprintf( __( 'For a text of this length, use it %1$d to %2$d times, naturally.', 'mg-seo' ), $low, $high );
			}
			$add(
				'keyword_density',
				'additional',
				$dens_st,
				$message,
				6,
				array(
					'help' => $help,
					'data' => array( 'density' => $density ),
				)
			);

			preg_match_all( '#<h([2-4])[^>]*>(.*?)</h\1>#is', $html, $heads );
			$in_heads = false;
			foreach ( $heads[2] as $heading ) {
				if ( self::contains( $primary, wp_strip_all_tags( $heading ) ) ) {
					$in_heads = true;
					break;
				}
			}
			$add(
				'keyword_in_subheadings',
				'additional',
				$in_heads,
				$in_heads ? __( 'The keyword is in a subheading', 'mg-seo' ) : __( 'Use the keyword in one subheading', 'mg-seo' ),
				5,
				array(
					'help'  => $in_heads ? '' : __( 'Subheadings help Google understand what each part of the text is about.', 'mg-seo' ),
					'place' => __( 'subheadings', 'mg-seo' ),
				)
			);

			preg_match_all( '/<img[^>]*\salt=(["\'])(.*?)\1/i', $html, $alts );
			$has_img = (bool) preg_match( '/<img\b/i', $html );
			$in_alt  = false;
			foreach ( $alts[2] as $alt ) {
				if ( self::contains( $primary, $alt ) ) {
					$in_alt = true;
					break;
				}
			}
			if ( $in_alt ) {
				$message = __( 'The keyword is in an image description', 'mg-seo' );
			} else {
				$message = $has_img ? __( 'Describe an image with words from the keyword', 'mg-seo' ) : __( 'Add an image and describe it with words from the keyword', 'mg-seo' );
			}
			$add(
				'keyword_in_alt',
				'additional',
				$in_alt,
				$message,
				4,
				array(
					'help'  => $in_alt ? '' : __( 'Image settings have an “Alternative text” field. Describe briefly what the image shows.', 'mg-seo' ),
					'place' => __( 'image description', 'mg-seo' ),
				)
			);

			$title_norm = self::normalize( $title );
			$pos        = $in_title ? self::position( $primary, $title_norm ) : false;
			$early      = false !== $pos && $pos <= max( 10, (int) ( mb_strlen( $title_norm ) * 0.5 ) );
			$add(
				'keyword_title_start',
				'title',
				$early,
				$early ? __( 'The keyword is at the beginning of the title', 'mg-seo' ) : __( 'Move the keyword closer to the beginning of the title', 'mg-seo' ),
				3,
				array(
					'help'     => $early ? '' : __( 'The beginning of the title is the most visible part, especially on phones.', 'mg-seo' ),
					'data'     => array( 'focus' => 'title' ),
					'requires' => 'keyword_in_title',
				)
			);

			$other  = $this->input['used_elsewhere'];
			$unique = empty( $other );
			$data   = array( 'focus' => 'keyword' );
			$help   = '';
			if ( ! $unique ) {
				$help = __( 'Two pages with the same keyword compete with each other. Open the other page or choose a different keyword.', 'mg-seo' );
				if ( is_array( $other ) && ! empty( $other['title'] ) ) {
					/* translators: 1: title of the other page, 2: explanation */
					$help          = sprintf( __( '“%1$s”. %2$s', 'mg-seo' ), (string) $other['title'], __( 'Two pages with the same keyword compete with each other.', 'mg-seo' ) );
					$data['other'] = array(
						'id'    => (int) ( $other['id'] ?? 0 ),
						'title' => (string) $other['title'],
						'url'   => (string) ( $other['url'] ?? '' ),
					);
				}
			}
			$add(
				'keyword_unique',
				'additional',
				$unique,
				$unique ? __( 'No other page uses this keyword', 'mg-seo' ) : __( 'Another page already uses this keyword', 'mg-seo' ),
				3,
				array(
					'help' => $help,
					'data' => $data,
				)
			);

			foreach ( array_slice( $keywords, 1 ) as $secondary ) {
				$found = self::contains( $secondary, $text ) || self::contains( $secondary, $title );
				$add(
					'secondary_' . sanitize_key( $secondary ),
					'additional',
					$found ? 'ok' : 'warn',
					/* translators: %s: keyword */
					sprintf( $found ? __( 'The additional keyword “%s” is used in the text', 'mg-seo' ) : __( 'The additional keyword “%s” is not used in the text', 'mg-seo' ), $secondary ),
					0,
					array( 'help' => $found ? '' : __( 'Use it at least once if it fits the topic.', 'mg-seo' ) )
				);
			}
		}

		$url_len = mb_strlen( (string) $this->input['url'] );
		$add(
			'url_length',
			'additional',
			$url_len <= 75,
			/* translators: %d: number of characters */
			$url_len <= 75 ? __( 'The page address is short', 'mg-seo' ) : sprintf( _n( 'The page address is long: %d character', 'The page address is long: %d characters', $url_len, 'mg-seo' ), $url_len ),
			3,
			array(
				'help' => $url_len <= 75 ? '' : __( 'A short address is easier to read and copy. Shorten its ending.', 'mg-seo' ),
				'data' => array( 'focus' => 'slug' ),
			)
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
		$add(
			'internal_links',
			'additional',
			$internal > 0,
			/* translators: %d: number of links */
			$internal > 0 ? sprintf( _n( 'The text links to other pages of the site (%d link)', 'The text links to other pages of the site (%d links)', $internal, 'mg-seo' ), $internal ) : __( 'No links to other pages of the site', 'mg-seo' ),
			5,
			array(
				'help' => $internal > 0 ? '' : __( 'Links lead readers and Google to the rest of the site. Add 1 or 2.', 'mg-seo' ),
				'data' => array( 'links' => true ),
			)
		);

		if ( $article ) {
			$add(
				'external_links',
				'additional',
				$external > 0 ? 'ok' : 'warn',
				$external > 0 ? __( 'The text links to an external source', 'mg-seo' ) : __( 'You can link to a trustworthy source', 'mg-seo' ),
				3,
				array( 'help' => $external > 0 ? '' : __( 'A link to research, an authority or a manufacturer shows where your information comes from.', 'mg-seo' ) )
			);

			$has_number = (bool) preg_match( '/\d/', $title );
			$add(
				'title_number',
				'title',
				$has_number ? 'ok' : 'warn',
				$has_number ? __( 'The title contains a number', 'mg-seo' ) : __( 'A number in the title often gets more clicks', 'mg-seo' ),
				2,
				array( 'help' => $has_number ? '' : __( 'For example “5 exercises …” or “in 2026”. This is a tip, not an error.', 'mg-seo' ) )
			);
		}

		$paragraphs = preg_split( '#</p>|\n\s*\n#i', $html );
		$paragraphs = is_array( $paragraphs ) ? $paragraphs : array();
		$long       = 0;
		foreach ( $paragraphs as $paragraph ) {
			if ( self::words( self::text( $paragraph ) ) > 150 ) {
				++$long;
			}
		}
		$add(
			'short_paragraphs',
			'readability',
			0 === $long ? 'ok' : 'warn',
			0 === $long ? __( 'Paragraphs are short and easy to read', 'mg-seo' ) : __( 'Split long paragraphs', 'mg-seo' ),
			3,
			/* translators: %d: number of paragraphs */
			array( 'help' => 0 === $long ? '' : sprintf( _n( '%d paragraph has more than 150 words. Shorter ones are easier to read on a phone.', '%d paragraphs have more than 150 words. Shorter ones are easier to read on a phone.', $long, 'mg-seo' ), $long ) )
		);

		if ( $article ) {
			$media = (bool) preg_match( '/<(img|video|iframe|figure)\b/i', $html );
			$add(
				'has_media',
				'readability',
				$media ? 'ok' : 'warn',
				$media ? __( 'The text has an image or a video', 'mg-seo' ) : __( 'Add an image or a video', 'mg-seo' ),
				3,
				array( 'help' => $media ? '' : __( 'Images break up the text and can appear in Google Images.', 'mg-seo' ) )
			);
		}

		$total  = 0;
		$earned = 0.0;
		foreach ( $checks as $check ) {
			$total  += $check['weight'];
			$earned += 'ok' === $check['status'] ? $check['weight'] : ( 'warn' === $check['status'] ? $check['weight'] * 0.5 : 0 );
		}
		$score = $total > 0 ? (int) round( $earned / $total * 100 ) : 0;
		if ( '' === $primary ) {
			// Without a keyword the score is capped: the most important checks cannot pass. The UI shows no number.
			$score = min( $score, 40 );
		}

		return array(
			'score'  => $score,
			'checks' => $checks,
			'stats'  => array(
				'words'    => $words,
				'internal' => $internal,
				'external' => $external,
				'profile'  => $article ? self::PROFILE_ARTICLE : self::PROFILE_PAGE,
			),
		);
	}

	/**
	 * Character position of the keyword in normalised text (first match), false when not found.
	 *
	 * @param string $keyword Keyword.
	 * @param string $norm    Normalised text.
	 */
	private static function position( string $keyword, string $norm ): int|false {
		$exact = mb_strpos( $norm, self::normalize( $keyword ) );
		if ( false !== $exact || ! self::$inflection ) {
			return $exact;
		}
		$found = Matcher::find( self::normalize( $keyword ), $norm );
		if ( ! $found ) {
			return false;
		}
		$words = Matcher::words( $norm );
		return mb_strpos( $norm, $words[ $found[0]['start'] ] );
	}

	/**
	 * The first matched form as written in the original text (with accents and case).
	 *
	 * @param string $keyword Keyword.
	 * @param string $text    Plain text.
	 */
	private static function original_form( string $keyword, string $text ): string {
		$found = Matcher::find( self::normalize( $keyword ), self::normalize( $text ) );
		if ( ! $found ) {
			return $keyword;
		}
		$parts = preg_split( '/[^\p{L}\p{N}]+/u', mb_strtolower( $text, 'UTF-8' ), -1, PREG_SPLIT_NO_EMPTY );
		$parts = is_array( $parts ) ? $parts : array();
		$form  = implode( ' ', array_slice( $parts, $found[0]['start'], $found[0]['length'] ) );
		return '' !== $form ? $form : $keyword;
	}
}
