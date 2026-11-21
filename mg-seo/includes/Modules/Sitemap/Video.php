<?php
/**
 * Video detection for the video sitemap.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Sitemap;

use MGSEO\Meta;
use MGSEO\Modules\Titles\Module as Titles;
use MGSEO\Variables;

defined( 'ABSPATH' ) || exit;

/**
 * Finds videos in a post: VideoObject schema entities, YouTube/Vimeo embeds and URLs,
 * <video> tags, [video] shortcodes and attached video files. Never makes HTTP requests.
 */
final class Video {

	/**
	 * YouTube URL patterns (capture group 1 = 11 character id).
	 */
	private const YOUTUBE = '~(?:https?:)?//(?:www\.|m\.)?(?:youtube(?:-nocookie)?\.com/(?:watch\?(?:[^\s"\'<>]*?&(?:amp;)?)?v=|embed/|shorts/|live/|v/)|youtu\.be/)([A-Za-z0-9_-]{11})(?![A-Za-z0-9_-])~i';

	/**
	 * Vimeo URL patterns (capture group 1 = numeric id).
	 */
	private const VIMEO = '~(?:https?:)?//(?:www\.)?(?:player\.)?vimeo\.com/(?:video/|channels/[^/\s"\'<>]+/|groups/[^/\s"\'<>]+/videos/)?(\d{6,12})(?![\d])~i';

	/**
	 * Videos of a post.
	 *
	 * @param \WP_Post $post   Post.
	 * @param bool     $detect Auto-detect videos in content (otherwise only schema data).
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_post( \WP_Post $post, bool $detect = true ): array {
		$defaults = self::defaults( $post );
		$videos   = self::from_schema( $post, $defaults );
		if ( $detect ) {
			$videos = array_merge( $videos, self::from_content( $post, $defaults ), self::from_attachments( $post, $defaults ) );
		}

		$unique = array();
		$seen   = array();
		foreach ( $videos as $video ) {
			if ( empty( $video['thumbnail_loc'] ) || ( empty( $video['content_loc'] ) && empty( $video['player_loc'] ) ) ) {
				continue;
			}
			$key = (string) ( $video['key'] ?? ( ! empty( $video['player_loc'] ) ? $video['player_loc'] : $video['content_loc'] ) );
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			unset( $video['key'] );
			$video['title']       = '' !== trim( (string) $video['title'] ) ? $video['title'] : $defaults['title'];
			$video['description'] = '' !== trim( (string) $video['description'] ) ? $video['description'] : $defaults['description'];
			$unique[]             = $video;
		}
		return $unique;
	}

	/**
	 * Fallback values from the post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array{title: string, description: string, thumbnail: string, date: string}
	 */
	private static function defaults( \WP_Post $post ): array {
		$title       = self::plain_title( $post );
		$description = class_exists( Titles::class ) ? Titles::post_description( $post ) : '';
		if ( '' === trim( $description ) ) {
			$description = $title;
		}
		$thumb_id = (int) get_post_thumbnail_id( $post );
		return array(
			'title'       => $title,
			'description' => $description,
			'thumbnail'   => $thumb_id ? (string) wp_get_attachment_url( $thumb_id ) : '',
			'date'        => Renderer::date( $post->post_date_gmt ),
		);
	}

	/**
	 * VideoObject entities from the per-post schema meta.
	 *
	 * @param \WP_Post $post     Post.
	 * @param array    $defaults Defaults.
	 * @return array<int, array<string, mixed>>
	 */
	public static function from_schema( \WP_Post $post, array $defaults ): array {
		$raw = (string) Meta::post( $post->ID, 'schema', '' );
		if ( '' === $raw || ! str_contains( $raw, 'VideoObject' ) ) {
			return array();
		}
		$entities = json_decode( $raw, true );
		if ( ! is_array( $entities ) ) {
			return array();
		}
		if ( isset( $entities['type'] ) || isset( $entities['@type'] ) ) {
			$entities = array( $entities );
		}
		$videos = array();
		foreach ( $entities as $entity ) {
			if ( ! is_array( $entity ) ) {
				continue;
			}
			$data = isset( $entity['data'] ) && is_array( $entity['data'] ) ? $entity['data'] : $entity;
			$type = $entity['type'] ?? ( $data['@type'] ?? '' );
			if ( ! in_array( 'VideoObject', (array) $type, true ) ) {
				continue;
			}
			$text      = static fn( $value ): string => is_scalar( $value ) ? trim( wp_strip_all_tags( Variables::replace( (string) $value, $post ) ) ) : '';
			$thumbnail = $data['thumbnailUrl'] ?? '';
			if ( is_array( $thumbnail ) ) {
				$thumbnail = $thumbnail['url'] ?? ( $thumbnail[0] ?? '' );
				$thumbnail = is_array( $thumbnail ) ? ( $thumbnail['url'] ?? '' ) : $thumbnail;
			}
			$embed   = $text( $data['embedUrl'] ?? '' );
			$content = $text( $data['contentUrl'] ?? '' );
			$thumb   = $text( $thumbnail );
			if ( '' === $thumb && '' !== $embed . $content ) {
				$thumb = self::youtube_thumbnail( $embed . ' ' . $content );
			}
			$upload   = $text( $data['uploadDate'] ?? '' );
			$family   = $data['isFamilyFriendly'] ?? null;
			$videos[] = array(
				'key'              => self::key( '' !== $embed ? $embed : $content ),
				'thumbnail_loc'    => '' !== $thumb ? $thumb : $defaults['thumbnail'],
				'title'            => $text( $data['name'] ?? '' ),
				'description'      => $text( $data['description'] ?? '' ),
				'content_loc'      => $content,
				'player_loc'       => $embed,
				'duration'         => self::duration( $text( $data['duration'] ?? '' ) ),
				'publication_date' => '' !== $upload ? self::w3c( $upload ) : $defaults['date'],
				'family_friendly'  => null === $family ? null : ! in_array( strtolower( (string) $family ), array( '', '0', 'false', 'no' ), true ),
			);
		}
		return $videos;
	}

	/**
	 * Videos embedded in content: YouTube, Vimeo, <video> tags and [video] shortcodes.
	 *
	 * @param \WP_Post $post     Post.
	 * @param array    $defaults Defaults.
	 * @return array<int, array<string, mixed>>
	 */
	public static function from_content( \WP_Post $post, array $defaults ): array {
		$content = (string) $post->post_content;
		$videos  = array();
		$base    = array(
			'title'            => '',
			'description'      => '',
			'content_loc'      => '',
			'player_loc'       => '',
			'duration'         => 0,
			'publication_date' => $defaults['date'],
		);

		if ( preg_match_all( self::YOUTUBE, $content, $m ) ) {
			foreach ( array_unique( $m[1] ) as $id ) {
				$videos[] = array(
					'key'           => 'youtube:' . $id,
					'thumbnail_loc' => 'https://i.ytimg.com/vi/' . $id . '/hqdefault.jpg',
					'player_loc'    => 'https://www.youtube.com/embed/' . $id,
				) + $base;
			}
		}
		if ( preg_match_all( self::VIMEO, $content, $m ) ) {
			foreach ( array_unique( $m[1] ) as $id ) {
				// No thumbnail without an API request: use the featured image (skipped when there is none).
				$videos[] = array(
					'key'           => 'vimeo:' . $id,
					'thumbnail_loc' => $defaults['thumbnail'],
					'player_loc'    => 'https://player.vimeo.com/video/' . $id,
				) + $base;
			}
		}
		if ( preg_match_all( '~<video\b[^>]*>.*?</video>|<video\b[^>]*/?>~is', $content, $tags ) ) {
			foreach ( $tags[0] as $tag ) {
				$open   = (string) strtok( $tag, '>' );
				$src    = self::attr( $open, 'src' );
				$poster = self::attr( $open, 'poster' );
				if ( '' === $src && preg_match( '~<source\b[^>]*>~i', $tag, $source ) ) {
					$src = self::attr( $source[0], 'src' );
				}
				if ( '' === $src ) {
					continue;
				}
				$src      = self::absolute( $src );
				$videos[] = array(
					'key'           => self::key( $src ),
					'thumbnail_loc' => '' !== $poster ? self::absolute( $poster ) : self::attachment_thumbnail( $src, $defaults['thumbnail'] ),
					'content_loc'   => $src,
				) + $base;
			}
		}
		if ( str_contains( $content, '[video' ) && preg_match_all( '/' . get_shortcode_regex( array( 'video' ) ) . '/s', $content, $codes, PREG_SET_ORDER ) ) {
			foreach ( $codes as $code ) {
				$atts = shortcode_parse_atts( $code[3] );
				$atts = is_array( $atts ) ? $atts : array();
				$src  = '';
				foreach ( array( 'src', 'mp4', 'm4v', 'webm', 'ogv', 'wmv', 'flv' ) as $att ) {
					if ( ! empty( $atts[ $att ] ) ) {
						$src = (string) $atts[ $att ];
						break;
					}
				}
				if ( '' === $src ) {
					continue;
				}
				$src      = self::absolute( $src );
				$poster   = ! empty( $atts['poster'] ) ? self::absolute( (string) $atts['poster'] ) : '';
				$videos[] = array(
					'key'           => self::key( $src ),
					'thumbnail_loc' => '' !== $poster ? $poster : self::attachment_thumbnail( $src, $defaults['thumbnail'] ),
					'content_loc'   => $src,
				) + $base;
			}
		}
		return $videos;
	}

	/**
	 * Video files attached to the post.
	 *
	 * @param \WP_Post $post     Post.
	 * @param array    $defaults Defaults.
	 * @return array<int, array<string, mixed>>
	 */
	public static function from_attachments( \WP_Post $post, array $defaults ): array {
		$videos = array();
		foreach ( get_attached_media( 'video', $post ) as $attachment ) {
			$url = (string) wp_get_attachment_url( $attachment->ID );
			if ( '' === $url ) {
				continue;
			}
			$thumb    = (int) get_post_thumbnail_id( $attachment );
			$meta     = wp_get_attachment_metadata( $attachment->ID );
			$videos[] = array(
				'key'              => self::key( $url ),
				'thumbnail_loc'    => $thumb ? (string) wp_get_attachment_url( $thumb ) : $defaults['thumbnail'],
				'title'            => wp_strip_all_tags( $attachment->post_title ),
				'description'      => wp_strip_all_tags( '' !== $attachment->post_content ? $attachment->post_content : $attachment->post_excerpt ),
				'content_loc'      => $url,
				'player_loc'       => '',
				'duration'         => is_array( $meta ) ? (int) ( $meta['length'] ?? 0 ) : 0,
				'publication_date' => $defaults['date'],
			);
		}
		return $videos;
	}

	/**
	 * Post title as plain text (no texturize, no HTML, entities decoded).
	 *
	 * @param \WP_Post $post Post.
	 */
	public static function plain_title( \WP_Post $post ): string {
		return trim( html_entity_decode( wp_strip_all_tags( $post->post_title ), ENT_QUOTES | ENT_HTML5, 'UTF-8' ) );
	}

	/**
	 * YouTube thumbnail URL for the first YouTube URL in a string ('' if none).
	 *
	 * @param string $text Text containing a URL.
	 */
	public static function youtube_thumbnail( string $text ): string {
		return preg_match( self::YOUTUBE, $text, $m ) ? 'https://i.ytimg.com/vi/' . $m[1] . '/hqdefault.jpg' : '';
	}

	/**
	 * Whether a post could contain videos (cheap pre-check before full detection).
	 *
	 * @param string $content Content.
	 */
	public static function maybe_has_video( string $content ): bool {
		return (bool) preg_match( '~youtu|vimeo|<video|\[video~i', $content );
	}

	/**
	 * Duplicate-detection key for a video URL.
	 *
	 * @param string $url URL.
	 */
	private static function key( string $url ): string {
		if ( preg_match( self::YOUTUBE, $url, $m ) ) {
			return 'youtube:' . $m[1];
		}
		if ( preg_match( self::VIMEO, $url, $m ) ) {
			return 'vimeo:' . $m[1];
		}
		return $url;
	}

	/**
	 * Thumbnail of the video attachment behind a local URL, or the fallback.
	 *
	 * @param string $url      Video URL.
	 * @param string $fallback Fallback thumbnail.
	 */
	private static function attachment_thumbnail( string $url, string $fallback ): string {
		$id = attachment_url_to_postid( $url );
		if ( $id ) {
			$thumb = (int) get_post_thumbnail_id( $id );
			if ( $thumb ) {
				return (string) wp_get_attachment_url( $thumb );
			}
		}
		return $fallback;
	}

	/**
	 * HTML attribute value.
	 *
	 * @param string $tag  Tag HTML.
	 * @param string $name Attribute name.
	 */
	private static function attr( string $tag, string $name ): string {
		if ( preg_match( '~\s' . preg_quote( $name, '~' ) . '\s*=\s*(["\'])(.*?)\1~is', $tag, $m ) ) {
			return trim( html_entity_decode( $m[2], ENT_QUOTES, 'UTF-8' ) );
		}
		return '';
	}

	/**
	 * Make a URL absolute (relative to the site).
	 *
	 * @param string $url URL.
	 */
	public static function absolute( string $url ): string {
		$url = trim( $url );
		if ( str_starts_with( $url, '//' ) ) {
			return ( is_ssl() ? 'https:' : 'http:' ) . $url;
		}
		if ( str_starts_with( $url, '/' ) ) {
			$home = wp_parse_url( home_url() );
			return ( $home['scheme'] ?? 'http' ) . '://' . ( $home['host'] ?? '' ) . ( isset( $home['port'] ) ? ':' . $home['port'] : '' ) . $url;
		}
		if ( ! preg_match( '~^https?://~i', $url ) ) {
			return home_url( '/' . ltrim( $url, './' ) );
		}
		return $url;
	}

	/**
	 * ISO 8601 duration (PT1H2M3S) or seconds to seconds.
	 *
	 * @param string $value Duration.
	 */
	public static function duration( string $value ): int {
		if ( '' === $value ) {
			return 0;
		}
		if ( ctype_digit( $value ) ) {
			return (int) $value;
		}
		if ( preg_match( '/^P(?:(\d+)D)?(?:T(?:(\d+)H)?(?:(\d+)M)?(?:(\d+(?:\.\d+)?)S)?)?$/i', $value, $m ) ) {
			return (int) ( ( (int) ( $m[1] ?? 0 ) ) * 86400 + ( (int) ( $m[2] ?? 0 ) ) * 3600 + ( (int) ( $m[3] ?? 0 ) ) * 60 + (float) ( $m[4] ?? 0 ) );
		}
		return 0;
	}

	/**
	 * Normalise a date string to W3C datetime.
	 *
	 * @param string $value Date.
	 */
	private static function w3c( string $value ): string {
		$time = strtotime( $value );
		return false === $time ? '' : gmdate( DATE_W3C, $time );
	}
}
