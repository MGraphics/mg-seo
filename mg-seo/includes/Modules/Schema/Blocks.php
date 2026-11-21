<?php
/**
 * FAQ and How-to blocks.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Schema;

defined( 'ABSPATH' ) || exit;

/**
 * Server-rendered blocks with plain, accessible markup (generic class names only)
 * that also contribute FAQPage / HowTo entities to the page graph.
 */
final class Blocks {

	public const FAQ   = 'mg-seo/faq';
	public const HOWTO = 'mg-seo/howto';

	/**
	 * Register the blocks (on init).
	 */
	public static function register(): void {
		$common = array(
			'api_version' => 3,
			'category'    => 'text',
			'supports'    => array(
				'html'            => false,
				'className'       => false,
				'customClassName' => true,
				'anchor'          => true,
			),
		);
		register_block_type(
			self::FAQ,
			$common + array(
				'title'           => __( 'FAQ', 'mg-seo' ),
				'description'     => __( 'Questions and answers marked up as FAQPage structured data.', 'mg-seo' ),
				'keywords'        => array( 'faq', 'questions', 'schema' ),
				'attributes'      => array(
					'questions'    => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array( 'type' => 'object' ),
					),
					'headingLevel' => array(
						'type'    => 'integer',
						'default' => 3,
					),
					'accordion'    => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'className'    => array( 'type' => 'string' ),
					'anchor'       => array( 'type' => 'string' ),
				),
				'render_callback' => array( self::class, 'render_faq' ),
			)
		);
		register_block_type(
			self::HOWTO,
			$common + array(
				'title'           => __( 'How-to', 'mg-seo' ),
				'description'     => __( 'Step-by-step instructions marked up as HowTo structured data.', 'mg-seo' ),
				'keywords'        => array( 'howto', 'steps', 'schema' ),
				'attributes'      => array(
					'title'        => array(
						'type'    => 'string',
						'default' => '',
					),
					'description'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'hours'        => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'minutes'      => array(
						'type'    => 'integer',
						'default' => 0,
					),
					'supplies'     => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array( 'type' => 'string' ),
					),
					'tools'        => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array( 'type' => 'string' ),
					),
					'steps'        => array(
						'type'    => 'array',
						'default' => array(),
						'items'   => array( 'type' => 'object' ),
					),
					'headingLevel' => array(
						'type'    => 'integer',
						'default' => 3,
					),
					'className'    => array( 'type' => 'string' ),
					'anchor'       => array( 'type' => 'string' ),
				),
				'render_callback' => array( self::class, 'render_howto' ),
			)
		);
	}

	/**
	 * Heading tag from a level attribute.
	 *
	 * @param mixed $level Level.
	 */
	private static function heading( mixed $level ): string {
		$level = (int) $level;
		return 'h' . ( $level >= 2 && $level <= 6 ? $level : 3 );
	}

	/**
	 * Opening wrapper attributes (generic class + user classes + anchor).
	 *
	 * @param string $base       Base class.
	 * @param array  $attributes Attributes.
	 */
	private static function wrapper( string $base, array $attributes ): string {
		$classes = trim( $base . ' ' . (string) ( $attributes['className'] ?? '' ) );
		$html    = ' class="' . esc_attr( $classes ) . '"';
		if ( ! empty( $attributes['anchor'] ) ) {
			$html .= ' id="' . esc_attr( (string) $attributes['anchor'] ) . '"';
		}
		return $html;
	}

	/**
	 * Plain text from a rich text value (tags removed, entities decoded).
	 *
	 * @param mixed $value Value.
	 */
	private static function plain( mixed $value ): string {
		$text = wp_strip_all_tags( is_scalar( $value ) ? (string) $value : '' );
		return trim( html_entity_decode( $text, ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * Valid FAQ items.
	 *
	 * @param array $attributes Attributes.
	 * @return array<int, array{question: string, answer: string, id: string}>
	 */
	private static function faq_items( array $attributes ): array {
		$items = array();
		foreach ( (array) ( $attributes['questions'] ?? array() ) as $i => $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}
			$question = self::plain( $item['question'] ?? '' );
			$answer   = trim( wp_kses( (string) ( $item['answer'] ?? '' ), Types::allowed_html() ) );
			if ( '' === $question || '' === $answer || ! empty( $item['hidden'] ) ) {
				continue;
			}
			$items[] = array(
				'question' => $question,
				'answer'   => $answer,
				'id'       => sanitize_html_class( (string) ( $item['id'] ?? ( 'faq-' . ( $i + 1 ) ) ) ),
			);
		}
		return $items;
	}

	/**
	 * Render the FAQ block.
	 *
	 * @param array $attributes Attributes.
	 */
	public static function render_faq( array $attributes ): string {
		$items = self::faq_items( $attributes );
		if ( ! $items ) {
			return '';
		}
		$tag  = self::heading( $attributes['headingLevel'] ?? 3 );
		$html = '<div' . self::wrapper( 'faq', $attributes ) . '>';
		foreach ( $items as $item ) {
			$answer = wpautop( $item['answer'] );
			if ( ! empty( $attributes['accordion'] ) ) {
				$html .= '<details class="faq-item"' . ( '' !== $item['id'] ? ' id="' . esc_attr( $item['id'] ) . '"' : '' ) . '>';
				$html .= '<summary class="faq-question">' . esc_html( $item['question'] ) . '</summary>';
				$html .= '<div class="faq-answer">' . wp_kses( $answer, Types::allowed_html() ) . '</div></details>';
				continue;
			}
			$html .= '<div class="faq-item"' . ( '' !== $item['id'] ? ' id="' . esc_attr( $item['id'] ) . '"' : '' ) . '>';
			$html .= '<' . $tag . ' class="faq-question">' . esc_html( $item['question'] ) . '</' . $tag . '>';
			$html .= '<div class="faq-answer">' . wp_kses( $answer, Types::allowed_html() ) . '</div></div>';
		}
		return $html . '</div>';
	}

	/**
	 * Valid how-to steps.
	 *
	 * @param array $attributes Attributes.
	 * @return array<int, array{title: string, text: string, image: string, id: string}>
	 */
	private static function howto_steps( array $attributes ): array {
		$steps = array();
		foreach ( (array) ( $attributes['steps'] ?? array() ) as $i => $step ) {
			if ( ! is_array( $step ) ) {
				continue;
			}
			$title = self::plain( $step['title'] ?? '' );
			$id    = sanitize_html_class( (string) ( $step['id'] ?? '' ) );
			$text  = trim( wp_kses( (string) ( $step['text'] ?? '' ), Types::allowed_html() ) );
			$image = esc_url_raw( (string) ( $step['image'] ?? '' ) );
			if ( '' === $title && '' === $text ) {
				continue;
			}
			$steps[] = array(
				'title' => $title,
				'text'  => $text,
				'image' => $image,
				'alt'   => sanitize_text_field( (string) ( $step['alt'] ?? '' ) ),
				'id'    => '' !== $id ? $id : 'step-' . ( count( $steps ) + 1 ),
			);
		}
		return $steps;
	}

	/**
	 * Human readable duration.
	 *
	 * @param int $hours   Hours.
	 * @param int $minutes Minutes.
	 */
	private static function duration_text( int $hours, int $minutes ): string {
		$parts = array();
		if ( $hours > 0 ) {
			/* translators: %d: number of hours */
			$parts[] = sprintf( _n( '%d hour', '%d hours', $hours, 'mg-seo' ), $hours );
		}
		if ( $minutes > 0 ) {
			/* translators: %d: number of minutes */
			$parts[] = sprintf( _n( '%d minute', '%d minutes', $minutes, 'mg-seo' ), $minutes );
		}
		return implode( ' ', $parts );
	}

	/**
	 * Clean string list.
	 *
	 * @param mixed $values List.
	 * @return string[]
	 */
	private static function strings( mixed $values ): array {
		return array_values( array_filter( array_map( static fn( $v ) => trim( wp_strip_all_tags( is_scalar( $v ) ? (string) $v : '' ) ), (array) $values ) ) );
	}

	/**
	 * Render the How-to block.
	 *
	 * @param array $attributes Attributes.
	 */
	public static function render_howto( array $attributes ): string {
		$steps = self::howto_steps( $attributes );
		if ( ! $steps ) {
			return '';
		}
		$tag      = self::heading( $attributes['headingLevel'] ?? 3 );
		$html     = '<div' . self::wrapper( 'howto', $attributes ) . '>';
		$desc     = trim( wp_kses( (string) ( $attributes['description'] ?? '' ), Types::allowed_html() ) );
		$duration = self::duration_text( (int) ( $attributes['hours'] ?? 0 ), (int) ( $attributes['minutes'] ?? 0 ) );
		if ( '' !== $desc ) {
			$html .= '<div class="howto-description">' . wp_kses( wpautop( $desc ), Types::allowed_html() ) . '</div>';
		}
		if ( '' !== $duration ) {
			$html .= '<p class="howto-duration"><strong>' . esc_html__( 'Total time:', 'mg-seo' ) . '</strong> <time datetime="' . esc_attr( Entities::iso_duration( ( (int) ( $attributes['hours'] ?? 0 ) * 60 + (int) ( $attributes['minutes'] ?? 0 ) ) . '' ) ) . '">' . esc_html( $duration ) . '</time></p>';
		}
		foreach (
			array(
				'supplies' => __( 'Supplies', 'mg-seo' ),
				'tools'    => __( 'Tools', 'mg-seo' ),
			) as $key => $label
		) {
			$list = self::strings( $attributes[ $key ] ?? array() );
			if ( ! $list ) {
				continue;
			}
			$html .= '<' . $tag . ' class="howto-' . esc_attr( $key ) . '-title">' . esc_html( $label ) . '</' . $tag . '>';
			$html .= '<ul class="howto-' . esc_attr( $key ) . '">';
			foreach ( $list as $entry ) {
				$html .= '<li>' . esc_html( $entry ) . '</li>';
			}
			$html .= '</ul>';
		}
		$html .= '<ol class="howto-steps">';
		foreach ( $steps as $step ) {
			$html .= '<li class="howto-step" id="' . esc_attr( $step['id'] ) . '">';
			if ( '' !== $step['title'] ) {
				$html .= '<' . $tag . ' class="howto-step-title">' . esc_html( $step['title'] ) . '</' . $tag . '>';
			}
			if ( '' !== $step['text'] ) {
				$html .= '<div class="howto-step-text">' . wp_kses( wpautop( $step['text'] ), Types::allowed_html() ) . '</div>';
			}
			if ( '' !== $step['image'] ) {
				$html .= '<img class="howto-step-image" src="' . esc_url( $step['image'] ) . '" alt="' . esc_attr( $step['alt'] ) . '" loading="lazy" decoding="async" />';
			}
			$html .= '</li>';
		}
		return $html . '</ol></div>';
	}

	/**
	 * Find blocks recursively.
	 *
	 * @param array  $blocks Parsed blocks.
	 * @param string $name   Block name.
	 * @return array<int, array>
	 */
	private static function find( array $blocks, string $name ): array {
		$found = array();
		foreach ( $blocks as $block ) {
			if ( ( $block['blockName'] ?? '' ) === $name ) {
				$found[] = $block;
			}
			if ( ! empty( $block['innerBlocks'] ) ) {
				$found = array_merge( $found, self::find( $block['innerBlocks'], $name ) );
			}
		}
		return $found;
	}

	/**
	 * Entities contributed by FAQ / How-to blocks in a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<int, array<string, mixed>>
	 */
	public static function entities( \WP_Post $post ): array {
		if ( ! has_block( self::FAQ, $post ) && ! has_block( self::HOWTO, $post ) ) {
			return array();
		}
		$blocks    = parse_blocks( $post->post_content );
		$entities  = array();
		$questions = array();
		foreach ( self::find( $blocks, self::FAQ ) as $block ) {
			foreach ( self::faq_items( (array) ( $block['attrs'] ?? array() ) ) as $item ) {
				$questions[] = array(
					'@type'          => 'Question',
					'name'           => $item['question'],
					'acceptedAnswer' => array(
						'@type' => 'Answer',
						'text'  => $item['answer'],
					),
				);
			}
		}
		if ( $questions ) {
			$entities[] = array(
				'id'     => 'block-faq',
				'type'   => 'FAQPage',
				'custom' => false,
				'data'   => array( 'mainEntity' => $questions ),
				'block'  => true,
			);
		}
		$permalink = (string) get_permalink( $post );
		foreach ( self::find( $blocks, self::HOWTO ) as $n => $block ) {
			$attrs = (array) ( $block['attrs'] ?? array() );
			$steps = array();
			foreach ( self::howto_steps( $attrs ) as $step ) {
				$steps[] = array(
					'@type' => 'HowToStep',
					'name'  => '' !== $step['title'] ? $step['title'] : wp_trim_words( wp_strip_all_tags( $step['text'] ), 8, '' ),
					'text'  => '' !== $step['text'] ? $step['text'] : $step['title'],
					'image' => $step['image'],
					'url'   => $permalink . '#' . $step['id'],
				);
			}
			if ( ! $steps ) {
				continue;
			}
			$minutes    = (int) ( $attrs['hours'] ?? 0 ) * 60 + (int) ( $attrs['minutes'] ?? 0 );
			$title      = trim( wp_strip_all_tags( (string) ( $attrs['title'] ?? '' ) ) );
			$data       = array(
				'name'        => '' !== $title ? $title : wp_strip_all_tags( get_the_title( $post ) ),
				'description' => wp_strip_all_tags( (string) ( $attrs['description'] ?? '' ) ),
				'totalTime'   => $minutes > 0 ? (string) $minutes : '',
				'supply'      => array_map(
					static fn( $s ) => array(
						'@type' => 'HowToSupply',
						'name'  => $s,
					),
					self::strings( $attrs['supplies'] ?? array() )
				),
				'tool'        => array_map(
					static fn( $s ) => array(
						'@type' => 'HowToTool',
						'name'  => $s,
					),
					self::strings( $attrs['tools'] ?? array() )
				),
				'step'        => $steps,
			);
			$entities[] = array(
				'id'     => 'block-howto-' . ( $n + 1 ),
				'type'   => 'HowTo',
				'custom' => false,
				'data'   => $data,
				'block'  => true,
			);
		}
		return $entities;
	}
}
