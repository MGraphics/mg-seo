<?php
/**
 * Open Graph and Twitter Cards.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Social;

use MGSEO\Context;
use MGSEO\Head;
use MGSEO\Helpers;
use MGSEO\Meta;
use MGSEO\Module as Base;
use MGSEO\Modules\Titles\Module as Titles;
use MGSEO\Options;
use MGSEO\Variables;

defined( 'ABSPATH' ) || exit;

/**
 * Social meta module.
 */
final class Module extends Base {

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'social';
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'mgseo/head', array( $this, 'open_graph' ), 30 );
		add_action( 'mgseo/head', array( $this, 'twitter' ), 40 );
		add_filter( 'mgseo/metabox_tabs', array( $this, 'metabox_tab' ), 10, 2 );
	}

	/**
	 * Settings.
	 */
	public function settings(): array {
		return array(
			array(
				'id'     => 'general',
				'title'  => __( 'Social', 'mg-seo' ),
				'fields' => array(
					array(
						'id'      => 'default_image',
						'type'    => 'image',
						'label'   => __( 'Default share image', 'mg-seo' ),
						'default' => '',
						'desc'    => __( 'Used when a page has no featured image. Recommended 1200×630 px.', 'mg-seo' ),
					),
					array(
						'id'           => 'use_content_image',
						'type'         => 'toggle',
						'label'        => __( 'Content image fallback', 'mg-seo' ),
						'toggle_label' => __( 'Use the first image in the content when there is no featured image', 'mg-seo' ),
						'default'      => true,
					),
					array(
						'id'      => 'facebook_page',
						'type'    => 'url',
						'label'   => __( 'Facebook page URL', 'mg-seo' ),
						'default' => '',
					),
					array(
						'id'      => 'facebook_author',
						'type'    => 'url',
						'label'   => __( 'Default Facebook author URL', 'mg-seo' ),
						'default' => '',
						'desc'    => __( 'Used as article:author when the post author has no Facebook profile in their user profile.', 'mg-seo' ),
					),
					array(
						'id'      => 'facebook_app_id',
						'type'    => 'text',
						'label'   => __( 'Facebook app ID', 'mg-seo' ),
						'default' => '',
					),
					array(
						'id'      => 'twitter_site',
						'type'    => 'text',
						'label'   => __( 'X (Twitter) username', 'mg-seo' ),
						'default' => '',
						'desc'    => __( 'Without @.', 'mg-seo' ),
					),
					array(
						'id'      => 'twitter_card',
						'type'    => 'select',
						'label'   => __( 'Default Twitter card', 'mg-seo' ),
						'options' => array(
							'summary_large_image' => __( 'Summary with large image', 'mg-seo' ),
							'summary'             => __( 'Summary', 'mg-seo' ),
						),
						'default' => 'summary_large_image',
					),
					array(
						'id'           => 'twitter_labels',
						'type'         => 'toggle',
						'label'        => __( 'Slack/X labels', 'mg-seo' ),
						'toggle_label' => __( 'Add author and reading time labels to posts', 'mg-seo' ),
						'default'      => true,
					),
				),
			),
		);
	}

	/**
	 * Social tab in the meta box.
	 *
	 * @param array    $tabs Tabs.
	 * @param \WP_Post $post Post.
	 * @return array
	 */
	public function metabox_tab( array $tabs, \WP_Post $post ): array {
		$tabs[] = array(
			'id'       => 'social',
			'title'    => __( 'Social media', 'mg-seo' ),
			'priority' => 30,
			'render'   => static function ( \WP_Post $post ): void {
				$custom_og = (string) Meta::post( $post->ID, 'og_image' );
				// Image used when the field is empty: featured image, first content image or the default.
				$auto_img = '';
				if ( '' === $custom_og ) {
					$image    = self::post_image( $post );
					$auto_img = $image ? (string) $image['url'] : (string) Options::get( 'social', 'default_image', '' );
				}
				$fallback = array(
					'title'       => Titles::post_title( $post ),
					'description' => Titles::post_description( $post ),
					'image'       => $auto_img,
					'site'        => html_entity_decode( get_bloginfo( 'name' ), ENT_QUOTES, 'UTF-8' ),
					'sep'         => Variables::separator(),
					'postTitle'   => html_entity_decode( get_the_title( $post ), ENT_QUOTES, 'UTF-8' ),
				);
				$host     = (string) wp_parse_url( home_url( '/' ), PHP_URL_HOST );
				$networks = array(
					'og'      => __( 'Facebook and LinkedIn', 'mg-seo' ),
					'twitter' => __( 'X', 'mg-seo' ),
				);
				echo '<div class="mgseo-social" data-fallback="' . esc_attr( (string) wp_json_encode( $fallback ) ) . '">';
				echo '<div class="mgseo-social-switch" role="group" aria-label="' . esc_attr__( 'Network', 'mg-seo' ) . '">';
				$first = true;
				foreach ( $networks as $net => $label ) {
					echo '<button type="button" class="mgseo-device-btn" data-network="' . esc_attr( $net ) . '" aria-pressed="' . ( $first ? 'true' : 'false' ) . '">' . esc_html( $label ) . '</button>';
					$first = false;
				}
				echo '</div>';
				$first = true;
				foreach ( $networks as $net => $label ) {
					echo '<div class="mgseo-social-net" data-network="' . esc_attr( $net ) . '"' . ( $first ? '' : ' hidden' ) . '>';
					echo '<div class="mgseo-social-card" data-network="' . esc_attr( $net ) . '" aria-hidden="true"><div class="mgseo-social-img"></div><div class="mgseo-social-text"><span class="mgseo-social-host">' . esc_html( $host ) . '</span><span class="mgseo-social-title"></span><span class="mgseo-social-desc"></span></div></div>';
					echo '<div class="mgseo-social-fields">';
					echo '<p class="mgseo-help">' . esc_html__( 'This is how the link looks when shared. When the fields are empty, the title, description and image of the page are used.', 'mg-seo' ) . '</p>';
					$fields = array(
						$net . '_title'       => __( 'Title when shared', 'mg-seo' ),
						$net . '_description' => __( 'Description when shared', 'mg-seo' ),
					);
					foreach ( $fields as $key => $field_label ) {
						$dom   = 'mgseo-' . str_replace( '_', '-', $key );
						$value = (string) Meta::post( $post->ID, $key );
						echo '<div class="mgseo-field"><label class="mgseo-label" for="' . esc_attr( $dom ) . '">' . esc_html( $field_label ) . '</label>';
						if ( str_ends_with( $key, '_description' ) ) {
							echo '<textarea id="' . esc_attr( $dom ) . '" name="mgseo[' . esc_attr( $key ) . ']" rows="2" class="widefat mgseo-input">' . esc_textarea( $value ) . '</textarea>';
						} else {
							echo '<input type="text" id="' . esc_attr( $dom ) . '" name="mgseo[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" class="widefat mgseo-input" />';
						}
						echo '</div>';
					}
					$key = $net . '_image';
					$dom = 'mgseo-' . $net . '-image';
					echo '<div class="mgseo-field"><span class="mgseo-label" id="' . esc_attr( $dom ) . '-label">' . esc_html__( 'Image', 'mg-seo' ) . '</span>';
					echo '<div class="mgseo-image-pick" data-target="' . esc_attr( $dom ) . '"><span class="mgseo-image-thumb"></span>';
					echo '<input type="hidden" id="' . esc_attr( $dom ) . '" name="mgseo[' . esc_attr( $key ) . ']" value="' . esc_attr( (string) Meta::post( $post->ID, $key ) ) . '" />';
					echo '<button type="button" class="button mgseo-media" data-target="' . esc_attr( $dom ) . '" aria-describedby="' . esc_attr( $dom ) . '-label">' . esc_html__( 'Choose a different image', 'mg-seo' ) . '</button> ';
					echo '<button type="button" class="button-link mgseo-image-remove">' . esc_html__( 'Remove', 'mg-seo' ) . '</button></div>';
					echo '<p class="mgseo-help">' . esc_html__( 'Best size: 1200 × 630 pixels.', 'mg-seo' ) . '</p></div>';
					if ( 'twitter' === $net ) {
						$card = (string) Meta::post( $post->ID, 'twitter_card' );
						echo '<div class="mgseo-field"><label class="mgseo-label" for="mgseo-twitter-card">' . esc_html__( 'Card type', 'mg-seo' ) . '</label><select id="mgseo-twitter-card" name="mgseo[twitter_card]">';
						foreach (
							array(
								''                    => __( 'Default', 'mg-seo' ),
								'summary_large_image' => __( 'Summary with large image', 'mg-seo' ),
								'summary'             => __( 'Summary', 'mg-seo' ),
							) as $value => $option
						) {
							echo '<option value="' . esc_attr( $value ) . '" ' . selected( $card, $value, false ) . '>' . esc_html( $option ) . '</option>';
						}
						echo '</select></div>';
					}
					echo '</div></div>';
					$first = false;
				}
				echo '</div>';
			},
			'save'     => static function ( int $post_id, array $data ): void {
				if ( ! array_key_exists( 'og_title', $data ) ) {
					return;
				}
				foreach ( array( 'og_title', 'twitter_title' ) as $key ) {
					Meta::set_post( $post_id, $key, sanitize_text_field( (string) ( $data[ $key ] ?? '' ) ) );
				}
				foreach ( array( 'og_description', 'twitter_description' ) as $key ) {
					Meta::set_post( $post_id, $key, sanitize_textarea_field( (string) ( $data[ $key ] ?? '' ) ) );
				}
				foreach ( array( 'og_image', 'twitter_image' ) as $key ) {
					$url = esc_url_raw( (string) ( $data[ $key ] ?? '' ) );
					Meta::set_post( $post_id, $key, $url );
					if ( 'og_image' === $key ) {
						Meta::set_post( $post_id, 'og_image_id', $url ? attachment_url_to_postid( $url ) : 0 );
					}
				}
				$card = sanitize_key( (string) ( $data['twitter_card'] ?? '' ) );
				Meta::set_post( $post_id, 'twitter_card', in_array( $card, array( 'summary', 'summary_large_image' ), true ) ? $card : '' );
			},
		);
		return $tabs;
	}

	/**
	 * Open Graph data for the current request.
	 *
	 * @return array<string, mixed> keys: type, title, description, url, image (array|null), published, modified, section, tags
	 */
	public static function data(): array {
		$ctx  = Context::current();
		$obj  = $ctx['object'];
		$data = array(
			'type'        => 'website',
			'title'       => Titles::document_title(),
			'description' => Titles::current_description(),
			'url'         => Titles::current_canonical(),
			'image'       => null,
		);
		if ( '' === $data['url'] ) {
			$data['url'] = Helpers::current_url();
		}

		$post = $obj instanceof \WP_Post ? $obj : null;
		if ( $post ) {
			$og_title = (string) Meta::post( $post->ID, 'og_title' );
			$og_desc  = (string) Meta::post( $post->ID, 'og_description' );
			if ( '' !== $og_title ) {
				$data['title'] = Variables::replace( $og_title, $post );
			}
			if ( '' !== $og_desc ) {
				$data['description'] = Variables::replace( $og_desc, $post );
			}
			if ( Context::SINGULAR === $ctx['type'] && 'page' !== $post->post_type ) {
				$data['type']      = 'article';
				$data['published'] = get_post_time( 'c', true, $post );
				$data['modified']  = get_post_modified_time( 'c', true, $post );
				$section           = Helpers::primary_term( $post, 'category', true );
				$data['section']   = $section ? $section->name : '';
				$tags              = get_the_terms( $post, 'post_tag' );
				$data['tags']      = is_array( $tags ) ? wp_list_pluck( $tags, 'name' ) : array();
			}
			$data['image'] = self::post_image( $post );
		} elseif ( $obj instanceof \WP_Term ) {
			$og_title = (string) Meta::term( $obj->term_id, 'og_title' );
			$og_desc  = (string) Meta::term( $obj->term_id, 'og_description' );
			if ( '' !== $og_title ) {
				$data['title'] = Variables::replace( $og_title, $obj );
			}
			if ( '' !== $og_desc ) {
				$data['description'] = Variables::replace( $og_desc, $obj );
			}
			$url = (string) Meta::term( $obj->term_id, 'og_image' );
			if ( '' !== $url ) {
				$data['image'] = self::image_from_url( $url );
			}
		} elseif ( Context::AUTHOR === $ctx['type'] ) {
			$data['type'] = 'profile';
		}
		if ( Context::FRONT_PAGE === $ctx['type'] ) {
			$home_image = (string) Options::get( 'titles', 'home_og_image', '' );
			if ( '' !== $home_image && ( ! $post || ! (string) Meta::post( $post->ID, 'og_image' ) ) ) {
				$data['image'] = self::image_from_url( $home_image );
			}
		}
		if ( ! $data['image'] ) {
			$default = (string) Options::get( 'social', 'default_image', '' );
			if ( '' !== $default ) {
				$data['image'] = self::image_from_url( $default );
			}
		}
		/**
		 * Filter Open Graph data.
		 *
		 * @param array $data Data.
		 * @param array $ctx  Context.
		 */
		return apply_filters( 'mgseo/open_graph', $data, $ctx );
	}

	/**
	 * Share image for a post.
	 *
	 * @param \WP_Post $post Post.
	 * @return array|null
	 */
	public static function post_image( \WP_Post $post ): ?array {
		$custom_id = (int) Meta::post( $post->ID, 'og_image_id', 0 );
		$custom    = (string) Meta::post( $post->ID, 'og_image' );
		if ( $custom_id ) {
			$img = Helpers::image( $custom_id );
			if ( $img ) {
				return $img;
			}
		}
		if ( '' !== $custom ) {
			return self::image_from_url( $custom );
		}
		if ( 'attachment' === $post->post_type && wp_attachment_is_image( $post ) ) {
			return Helpers::image( $post->ID );
		}
		$thumb = (int) get_post_thumbnail_id( $post );
		if ( $thumb ) {
			$img = Helpers::image( $thumb );
			if ( $img ) {
				return $img;
			}
		}
		if ( Options::get( 'social', 'use_content_image', true ) ) {
			$first = Helpers::first_content_image( $post->post_content );
			if ( '' !== $first ) {
				return self::image_from_url( $first );
			}
		}
		return null;
	}

	/**
	 * Image data from a URL (resolving the attachment when it is local).
	 *
	 * @param string $url URL.
	 * @return array{url: string, width: int, height: int, alt: string, type: string}
	 */
	public static function image_from_url( string $url ): array {
		$id = attachment_url_to_postid( $url );
		if ( $id ) {
			$img = Helpers::image( $id );
			if ( $img ) {
				return $img;
			}
		}
		$type = wp_check_filetype( (string) wp_parse_url( $url, PHP_URL_PATH ) );
		return array(
			'url'    => $url,
			'width'  => 0,
			'height' => 0,
			'alt'    => '',
			'type'   => (string) ( $type['type'] ?? '' ),
		);
	}

	/**
	 * Print Open Graph tags.
	 */
	public function open_graph(): void {
		$data = self::data();
		Head::property( 'og:locale', (string) get_locale() );
		Head::property( 'og:type', (string) $data['type'] );
		Head::property( 'og:title', (string) $data['title'] );
		Head::property( 'og:description', (string) $data['description'] );
		Head::property( 'og:url', esc_url_raw( (string) $data['url'] ) );
		Head::property( 'og:site_name', get_bloginfo( 'name' ) );
		if ( ! empty( $data['modified'] ) ) {
			Head::property( 'og:updated_time', (string) $data['modified'] );
		}
		if ( ! empty( $data['image'] ) ) {
			$img = $data['image'];
			Head::property( 'og:image', esc_url_raw( $img['url'] ) );
			if ( str_starts_with( $img['url'], 'https://' ) ) {
				Head::property( 'og:image:secure_url', esc_url_raw( $img['url'] ) );
			}
			if ( ! empty( $img['width'] ) ) {
				Head::property( 'og:image:width', (string) $img['width'] );
				Head::property( 'og:image:height', (string) $img['height'] );
			}
			Head::property( 'og:image:alt', '' !== ( $img['alt'] ?? '' ) ? $img['alt'] : (string) $data['title'] );
			if ( ! empty( $img['type'] ) ) {
				Head::property( 'og:image:type', (string) $img['type'] );
			}
		}
		if ( 'article' === $data['type'] ) {
			$publisher = (string) Options::get( 'social', 'facebook_page', '' );
			Head::property( 'article:publisher', $publisher );
			$ctx = Context::current();
			if ( $ctx['object'] instanceof \WP_Post ) {
				$author_fb = (string) Meta::user( (int) $ctx['object']->post_author, 'social_facebook' );
				Head::property( 'article:author', '' !== $author_fb ? $author_fb : (string) Options::get( 'social', 'facebook_author', '' ) );
			}
			foreach ( (array) ( $data['tags'] ?? array() ) as $tag ) {
				Head::property( 'article:tag', (string) $tag );
			}
			Head::property( 'article:section', (string) ( $data['section'] ?? '' ) );
			Head::property( 'article:published_time', (string) ( $data['published'] ?? '' ) );
			Head::property( 'article:modified_time', (string) ( $data['modified'] ?? '' ) );
		}
		Head::property( 'fb:app_id', (string) Options::get( 'social', 'facebook_app_id', '' ) );
		/**
		 * Print extra Open Graph tags (e.g. product:*).
		 *
		 * @param array $data Open Graph data.
		 */
		do_action( 'mgseo/open_graph_tags', $data );
	}

	/**
	 * Print Twitter Card tags.
	 */
	public function twitter(): void {
		$data  = self::data();
		$ctx   = Context::current();
		$post  = $ctx['object'] instanceof \WP_Post ? $ctx['object'] : null;
		$card  = (string) Options::get( 'social', 'twitter_card', 'summary_large_image' );
		$title = (string) $data['title'];
		$desc  = (string) $data['description'];
		$image = $data['image']['url'] ?? '';
		if ( $post ) {
			$own_card = (string) Meta::post( $post->ID, 'twitter_card' );
			$card     = '' !== $own_card ? $own_card : $card;
			$t        = (string) Meta::post( $post->ID, 'twitter_title' );
			$d        = (string) Meta::post( $post->ID, 'twitter_description' );
			$i        = (string) Meta::post( $post->ID, 'twitter_image' );
			$title    = '' !== $t ? Variables::replace( $t, $post ) : $title;
			$desc     = '' !== $d ? Variables::replace( $d, $post ) : $desc;
			$image    = '' !== $i ? $i : $image;
		}
		Head::meta( 'twitter:card', $card );
		Head::meta( 'twitter:title', $title );
		Head::meta( 'twitter:description', $desc );
		$site = ltrim( (string) Options::get( 'social', 'twitter_site', '' ), '@' );
		if ( '' !== $site ) {
			Head::meta( 'twitter:site', '@' . $site );
		}
		if ( $post ) {
			$creator = ltrim( (string) Meta::user( (int) $post->post_author, 'social_twitter' ), '@' );
			$creator = '' !== $creator ? $creator : $site;
			if ( '' !== $creator ) {
				Head::meta( 'twitter:creator', '@' . $creator );
			}
		}
		if ( '' !== $image ) {
			Head::meta( 'twitter:image', esc_url_raw( (string) $image ) );
		}
		if ( $post && 'article' === $data['type'] && Options::get( 'social', 'twitter_labels', true ) ) {
			Head::meta( 'twitter:label1', __( 'Written by', 'mg-seo' ) );
			Head::meta( 'twitter:data1', (string) get_the_author_meta( 'display_name', (int) $post->post_author ) );
			$words   = str_word_count( wp_strip_all_tags( $post->post_content ) );
			$minutes = max( 1, (int) ceil( $words / 200 ) );
			Head::meta( 'twitter:label2', __( 'Time to read', 'mg-seo' ) );
			/* translators: %d: minutes */
			Head::meta( 'twitter:data2', sprintf( _n( '%d minute', '%d minutes', $minutes, 'mg-seo' ), $minutes ) );
		}
	}
}
