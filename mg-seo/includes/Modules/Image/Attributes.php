<?php
/**
 * Automatic alt/title attributes from templates.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Image;

use MGSEO\Helpers;
use MGSEO\Options;
use MGSEO\Variables;

defined( 'ABSPATH' ) || exit;

/**
 * Generates alt/title values and applies them to content HTML and attachment image attributes.
 *
 * Image variables (besides all site/post variables, which describe the post that shows the image):
 * %filename% (humanized file name), %imagetitle%, %imagealt%, %imagecaption%.
 */
final class Attributes {

	/**
	 * Tokens used while post variables are replaced (never printed).
	 */
	private const TOKENS = array(
		'%filename%'     => '{{img:filename}}',
		'%imagetitle%'   => '{{img:title}}',
		'%imagealt%'     => '{{img:alt}}',
		'%imagecaption%' => '{{img:caption}}',
	);

	/**
	 * Per-request cache of post-resolved templates.
	 *
	 * @var array<string, string>
	 */
	private static array $resolved = array();

	/**
	 * Setting shortcut.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	private static function opt( string $key ): mixed {
		return Options::get( 'image', $key );
	}

	/**
	 * Case transformation.
	 *
	 * @param string $text Text.
	 * @param string $mode none|lower|upper|title|sentence.
	 */
	public static function change_case( string $text, string $mode ): string {
		return match ( $mode ) {
			'lower'    => mb_strtolower( $text, 'UTF-8' ),
			'upper'    => mb_strtoupper( $text, 'UTF-8' ),
			'title'    => mb_convert_case( mb_strtolower( $text, 'UTF-8' ), MB_CASE_TITLE, 'UTF-8' ),
			'sentence' => mb_strtoupper( mb_substr( $text, 0, 1, 'UTF-8' ), 'UTF-8' ) . mb_strtolower( mb_substr( $text, 1, null, 'UTF-8' ), 'UTF-8' ),
			default    => $text,
		};
	}

	/**
	 * Image data used by the image variables.
	 *
	 * @param int    $attachment_id Attachment id (0 when unknown).
	 * @param string $src           Image URL (used for the file name when there is no attachment).
	 * @return array{filename: string, title: string, alt: string, caption: string}
	 */
	public static function image_data( int $attachment_id, string $src = '' ): array {
		$data       = array(
			'filename' => '',
			'title'    => '',
			'alt'      => '',
			'caption'  => '',
		);
		$attachment = $attachment_id > 0 ? get_post( $attachment_id ) : null;
		if ( $attachment instanceof \WP_Post && 'attachment' === $attachment->post_type ) {
			$file             = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
			$data['filename'] = '' !== $file ? Helpers::humanize_filename( $file ) : '';
			$data['title']    = wp_strip_all_tags( $attachment->post_title );
			$data['alt']      = (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true );
			$data['caption']  = wp_strip_all_tags( $attachment->post_excerpt );
		}
		if ( '' === $data['filename'] && '' !== $src ) {
			$path             = (string) wp_parse_url( $src, PHP_URL_PATH );
			$data['filename'] = '' !== $path ? Helpers::humanize_filename( rawurldecode( $path ) ) : '';
		}
		return $data;
	}

	/**
	 * Render a template for an image shown on a post.
	 *
	 * @param string        $template Template.
	 * @param \WP_Post|null $post     Post showing the image (null: current context).
	 * @param array         $image    Image data from image_data().
	 * @param string        $case_mode Case mode.
	 */
	public static function render( string $template, ?\WP_Post $post, array $image, string $case_mode = 'none' ): string {
		$key = md5( $template . '|' . ( $post ? $post->ID : 'ctx' ) );
		if ( ! isset( self::$resolved[ $key ] ) ) {
			self::$resolved[ $key ] = Variables::replace( strtr( $template, self::TOKENS ), $post );
		}
		$text = strtr(
			self::$resolved[ $key ],
			array(
				self::TOKENS['%filename%']     => (string) ( $image['filename'] ?? '' ),
				self::TOKENS['%imagetitle%']   => (string) ( $image['title'] ?? '' ),
				self::TOKENS['%imagealt%']     => (string) ( $image['alt'] ?? '' ),
				self::TOKENS['%imagecaption%'] => (string) ( $image['caption'] ?? '' ),
			)
		);
		$text = trim( (string) preg_replace( '/\s+/u', ' ', $text ) );
		return self::change_case( $text, $case_mode );
	}

	/**
	 * Whether a target is enabled (content, attachment, woocommerce).
	 *
	 * @param string $target Target.
	 */
	public static function target_enabled( string $target ): bool {
		return in_array( $target, (array) self::opt( 'targets' ), true );
	}

	/**
	 * New alt/title for an image or null when unchanged.
	 *
	 * @param string        $attr    'alt' or 'title'.
	 * @param mixed         $current Current value (null when absent).
	 * @param \WP_Post|null $post    Post showing the image.
	 * @param callable      $image   Lazy image data provider.
	 */
	public static function value( string $attr, mixed $current, ?\WP_Post $post, callable $image ): ?string {
		$enabled   = (bool) self::opt( 'add_' . $attr );
		$overwrite = (bool) self::opt( 'overwrite_' . $attr );
		$case      = (string) self::opt( $attr . '_case' );
		$current   = is_string( $current ) ? $current : '';
		$missing   = '' === trim( $current );
		if ( $enabled && ( $missing || $overwrite ) ) {
			$value = self::render( (string) self::opt( $attr . '_template' ), $post, $image(), $case );
			if ( '' !== $value && $value !== $current ) {
				return $value;
			}
			return null;
		}
		if ( ! $missing && 'none' !== $case && self::opt( 'case_existing' ) ) {
			$value = self::change_case( $current, $case );
			return $value !== $current ? $value : null;
		}
		return null;
	}

	/**
	 * Add missing attributes to images in HTML.
	 *
	 * @param string        $html HTML.
	 * @param \WP_Post|null $post Post that renders the HTML.
	 */
	public static function filter_html( string $html, ?\WP_Post $post = null ): string {
		if ( false === stripos( $html, '<img' ) ) {
			return $html;
		}
		$post = $post ?? self::loop_post();
		$ids  = array();
		$scan = new \WP_HTML_Tag_Processor( $html );
		while ( $scan->next_tag( 'img' ) ) {
			$id = self::attachment_id( $scan );
			if ( $id ) {
				$ids[] = $id;
			}
		}
		if ( $ids ) {
			_prime_post_caches( array_unique( $ids ), false, true );
		}
		$processor = new \WP_HTML_Tag_Processor( $html );
		$changed   = false;
		while ( $processor->next_tag( 'img' ) ) {
			$id    = self::attachment_id( $processor );
			$src   = (string) $processor->get_attribute( 'src' );
			$cache = null;
			$image = static function () use ( &$cache, $id, $src ): array {
				$cache = $cache ?? self::image_data( $id, $src );
				return $cache;
			};
			foreach ( array( 'alt', 'title' ) as $attr ) {
				$value = self::value( $attr, $processor->get_attribute( $attr ), $post, $image );
				if ( null !== $value ) {
					$processor->set_attribute( $attr, $value );
					$changed = true;
				}
			}
		}
		return $changed ? $processor->get_updated_html() : $html;
	}

	/**
	 * Attachment id from a wp-image-N class or data-id.
	 *
	 * @param \WP_HTML_Tag_Processor $processor Processor at an IMG tag.
	 */
	private static function attachment_id( \WP_HTML_Tag_Processor $processor ): int {
		$class = (string) $processor->get_attribute( 'class' );
		if ( '' !== $class && preg_match( '/\bwp-image-(\d+)\b/', $class, $m ) ) {
			return (int) $m[1];
		}
		$data = $processor->get_attribute( 'data-id' );
		return is_string( $data ) && ctype_digit( $data ) ? (int) $data : 0;
	}

	/**
	 * Post of the current loop iteration or the queried post.
	 */
	private static function loop_post(): ?\WP_Post {
		$post = get_post();
		return $post instanceof \WP_Post ? $post : null;
	}

	/**
	 * Filter for the_content.
	 *
	 * @param string $content Content.
	 */
	public static function filter_content( $content ) {
		if ( ! is_string( $content ) || is_admin() || is_feed() || ! self::target_enabled( 'content' ) ) {
			return $content;
		}
		return self::filter_html( $content );
	}

	/**
	 * Filter for wp_get_attachment_image_attributes (featured images, galleries, product images).
	 *
	 * @param array    $attr       Attributes.
	 * @param \WP_Post $attachment Attachment.
	 * @return array
	 */
	public static function filter_attributes( $attr, $attachment = null ) {
		if ( ! is_array( $attr ) || is_admin() || ! $attachment instanceof \WP_Post ) {
			return $attr;
		}
		$post   = self::loop_post();
		$parent = $attachment->post_parent ? get_post( $attachment->post_parent ) : null;
		if ( ! $post || 'attachment' === $post->post_type ) {
			$post = $parent instanceof \WP_Post ? $parent : $post;
		}
		$is_product = ( $post && 'product' === $post->post_type ) || ( $parent instanceof \WP_Post && 'product' === $parent->post_type );
		if ( ! self::target_enabled( $is_product ? 'woocommerce' : 'attachment' ) ) {
			return $attr;
		}
		$cache = null;
		$image = static function () use ( &$cache, $attachment ): array {
			$cache = $cache ?? self::image_data( $attachment->ID );
			return $cache;
		};
		foreach ( array( 'alt', 'title' ) as $name ) {
			$value = self::value( $name, $attr[ $name ] ?? null, $post, $image );
			if ( null !== $value ) {
				$attr[ $name ] = $value;
			}
		}
		return $attr;
	}

	/**
	 * Set alt text (and a readable title) when an image is uploaded.
	 *
	 * @param int $attachment_id Attachment id.
	 */
	public static function on_upload( $attachment_id ): void {
		$attachment_id = (int) $attachment_id;
		if ( ! wp_attachment_is_image( $attachment_id ) ) {
			return;
		}
		$attachment = get_post( $attachment_id );
		if ( ! $attachment ) {
			return;
		}
		$file  = (string) get_post_meta( $attachment_id, '_wp_attached_file', true );
		$human = '' !== $file ? Helpers::humanize_filename( $file ) : '';
		if ( self::opt( 'upload_title' ) && '' !== $human ) {
			$raw = pathinfo( $file, PATHINFO_FILENAME );
			// Only replace the default title WordPress derived from the file name (keep IPTC/EXIF titles).
			$defaults = array( $raw, sanitize_text_field( $raw ), str_replace( array( '-', '_' ), ' ', $raw ), preg_replace( '/-scaled$/', '', $raw ) );
			if ( in_array( $attachment->post_title, $defaults, true ) && $attachment->post_title !== $human ) {
				wp_update_post(
					array(
						'ID'         => $attachment_id,
						'post_title' => $human,
					)
				);
				$attachment = get_post( $attachment_id );
			}
		}
		if ( self::opt( 'upload_alt' ) && '' === trim( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) ) ) {
			$alt = self::upload_alt( $attachment );
			if ( '' !== $alt ) {
				update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt );
			}
		}
	}

	/**
	 * Alt text from the upload template for an attachment.
	 *
	 * @param \WP_Post $attachment Attachment.
	 */
	public static function upload_alt( \WP_Post $attachment ): string {
		$parent  = $attachment->post_parent ? get_post( $attachment->post_parent ) : null;
		$context = $parent instanceof \WP_Post ? $parent : $attachment;
		return self::render( (string) self::opt( 'upload_alt_template' ), $context, self::image_data( $attachment->ID ), (string) self::opt( 'alt_case' ) );
	}

	/**
	 * Reset caches (tests).
	 */
	public static function reset(): void {
		self::$resolved = array();
	}
}
