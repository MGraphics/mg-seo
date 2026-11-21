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
			'title'    => __( 'Social', 'mg-seo' ),
			'priority' => 30,
			'render'   => static function ( \WP_Post $post ): void {
				$fields = array(
					'og_title'            => array( __( 'Facebook / Open Graph title', 'mg-seo' ), 'text' ),
					'og_description'      => array( __( 'Facebook / Open Graph description', 'mg-seo' ), 'textarea' ),
					'og_image'            => array( __( 'Open Graph image', 'mg-seo' ), 'image' ),
					'twitter_title'       => array( __( 'X (Twitter) title', 'mg-seo' ), 'text' ),
					'twitter_description' => array( __( 'X (Twitter) description', 'mg-seo' ), 'textarea' ),
					'twitter_image'       => array( __( 'X (Twitter) image', 'mg-seo' ), 'image' ),
				);
				echo '<p class="description">' . esc_html__( 'Leave empty to reuse the SEO title, description and featured image.', 'mg-seo' ) . '</p>';
				foreach ( $fields as $key => $def ) {
					$value = (string) Meta::post( $post->ID, $key );
					$dom   = 'mgseo-' . str_replace( '_', '-', $key );
					echo '<p class="mgseo-row"><label for="' . esc_attr( $dom ) . '"><strong>' . esc_html( $def[0] ) . '</strong></label>';
					if ( 'textarea' === $def[1] ) {
						echo '<textarea id="' . esc_attr( $dom ) . '" name="mgseo[' . esc_attr( $key ) . ']" rows="2" class="widefat mgseo-has-variables">' . esc_textarea( $value ) . '</textarea>';
					} elseif ( 'image' === $def[1] ) {
						echo '<span class="mgseo-image-field"><input type="url" id="' . esc_attr( $dom ) . '" name="mgseo[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" class="regular-text" /> <button type="button" class="button mgseo-media" data-target="' . esc_attr( $dom ) . '">' . esc_html__( 'Choose image', 'mg-seo' ) . '</button></span>';
					} else {
						echo '<input type="text" id="' . esc_attr( $dom ) . '" name="mgseo[' . esc_attr( $key ) . ']" value="' . esc_attr( $value ) . '" class="widefat mgseo-has-variables" />';
					}
					echo '</p>';
				}
				$card = (string) Meta::post( $post->ID, 'twitter_card' );
				echo '<p class="mgseo-row"><label><strong>' . esc_html__( 'Twitter card', 'mg-seo' ) . '</strong> <select name="mgseo[twitter_card]">';
				foreach (
					array(
						''                    => __( 'Default', 'mg-seo' ),
						'summary_large_image' => __( 'Summary with large image', 'mg-seo' ),
						'summary'             => __( 'Summary', 'mg-seo' ),
					) as $value => $label
				) {
					echo '<option value="' . esc_attr( $value ) . '" ' . selected( $card, $value, false ) . '>' . esc_html( $label ) . '</option>';
				}
				echo '</select></label></p>';
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
				Head::property( 'article:author', (string) Meta::user( (int) $ctx['object']->post_author, 'social_facebook' ) );
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
