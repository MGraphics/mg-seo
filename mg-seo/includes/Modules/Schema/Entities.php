<?php
/**
 * Per-post schema entities: storage, sanitizing and conversion to JSON-LD nodes.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Schema;

use MGSEO\Helpers;
use MGSEO\Meta;
use MGSEO\Modules\Titles\Module as Titles;
use MGSEO\Options;
use MGSEO\Variables;

defined( 'ABSPATH' ) || exit;

/**
 * Entities are stored in `_mgseo_schema` as a JSON list of { id, type, custom, data }.
 * A post without that meta gets the default entity of its post type.
 */
final class Entities {

	public const META             = 'schema';
	public const META_NO_TEMPLATE = 'schema_no_templates';
	public const META_WEBPAGE     = 'schema_webpage_type';

	/**
	 * Property names holding dates (normalised to ISO 8601).
	 */
	private const DATE_KEYS = array( 'datePublished', 'dateModified', 'dateCreated', 'uploadDate', 'startDate', 'endDate', 'priceValidUntil', 'validFrom', 'validThrough', 'datePosted', 'foundingDate', 'birthDate', 'deathDate', 'expires', 'availabilityStarts', 'availabilityEnds' );

	/**
	 * Property names holding durations (normalised to ISO 8601 durations).
	 */
	private const DURATION_KEYS = array( 'totalTime', 'prepTime', 'cookTime', 'performTime', 'duration', 'timeRequired', 'courseWorkload' );

	/**
	 * Property names holding URLs (made absolute).
	 */
	private const URL_KEYS = array( 'url', 'image', 'logo', 'thumbnailUrl', 'contentUrl', 'embedUrl', 'sameAs', 'hasMap', 'item', 'photo', 'screenshot', 'downloadUrl', 'installUrl' );

	/**
	 * Property names holding numbers.
	 */
	private const NUMBER_KEYS = array( 'price', 'lowPrice', 'highPrice', 'ratingValue', 'reviewCount', 'ratingCount', 'bestRating', 'worstRating', 'latitude', 'longitude', 'numberOfPages', 'wordCount', 'minValue', 'maxValue', 'value' );

	/**
	 * Stored entities of a post (null when the post has no stored list).
	 *
	 * @param int $post_id Post id.
	 * @return array<int, array<string, mixed>>|null
	 */
	public static function stored( int $post_id ): ?array {
		if ( ! metadata_exists( 'post', $post_id, Meta::PREFIX . self::META ) ) {
			return null;
		}
		return self::decode( Meta::post( $post_id, self::META, '' ) );
	}

	/**
	 * Decode a stored list (JSON string or array) into normalised entities.
	 *
	 * @param mixed $raw Raw value.
	 * @return array<int, array<string, mixed>>
	 */
	public static function decode( mixed $raw ): array {
		if ( is_string( $raw ) ) {
			$raw = '' === trim( $raw ) ? array() : json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		// A single entity object instead of a list.
		if ( isset( $raw['type'] ) || isset( $raw['data'] ) ) {
			$raw = array( $raw );
		}
		$list = array();
		foreach ( $raw as $entity ) {
			if ( ! is_array( $entity ) ) {
				continue;
			}
			$custom = ! empty( $entity['custom'] );
			$data   = $entity['data'] ?? array();
			if ( $custom && is_string( $data ) ) {
				$decoded = json_decode( $data, true );
				$data    = is_array( $decoded ) ? $decoded : array();
			}
			$list[] = array(
				'id'     => isset( $entity['id'] ) && is_scalar( $entity['id'] ) ? (string) $entity['id'] : wp_generate_uuid4(),
				'type'   => isset( $entity['type'] ) && is_string( $entity['type'] ) ? $entity['type'] : ( $custom ? 'Thing' : '' ),
				'custom' => $custom,
				'data'   => is_array( $data ) ? $data : array(),
			);
		}
		return $list;
	}

	/**
	 * Store a list for a post (null deletes it so the post type default applies).
	 *
	 * @param int        $post_id  Post id.
	 * @param array|null $entities Entities.
	 */
	public static function store( int $post_id, ?array $entities ): void {
		if ( null === $entities ) {
			delete_post_meta( $post_id, Meta::PREFIX . self::META );
			return;
		}
		$json = (string) wp_json_encode( array_values( $entities ), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		// update_post_meta() unslashes values, so slash the JSON to keep escaped characters.
		update_post_meta( $post_id, Meta::PREFIX . self::META, wp_slash( $json ) );
	}

	/**
	 * Sanitize a submitted entity list.
	 *
	 * @param mixed $raw    Raw list (array or JSON string).
	 * @param array $errors Receives error messages (invalid custom JSON).
	 * @param array $previous Previously stored entities (invalid custom entities keep their old data).
	 * @return array<int, array<string, mixed>>
	 */
	public static function sanitize_list( mixed $raw, array &$errors = array(), array $previous = array() ): array {
		if ( is_string( $raw ) ) {
			$raw = json_decode( $raw, true );
		}
		if ( ! is_array( $raw ) ) {
			return array();
		}
		$old = array();
		foreach ( $previous as $entity ) {
			$old[ $entity['id'] ] = $entity;
		}
		$list = array();
		foreach ( $raw as $entity ) {
			if ( ! is_array( $entity ) ) {
				continue;
			}
			$clean = self::sanitize_entity( $entity, $error );
			if ( null === $clean ) {
				if ( '' !== $error ) {
					$errors[] = $error;
					$id       = isset( $entity['id'] ) && is_scalar( $entity['id'] ) ? sanitize_text_field( (string) $entity['id'] ) : '';
					if ( '' !== $id && isset( $old[ $id ] ) ) {
						$list[] = $old[ $id ];
					}
				}
				continue;
			}
			$list[] = $clean;
		}
		return $list;
	}

	/**
	 * Sanitize one entity.
	 *
	 * @param array       $entity Raw entity.
	 * @param string|null $error  Receives an error message ('' when none).
	 * @return array<string, mixed>|null
	 */
	public static function sanitize_entity( array $entity, ?string &$error = '' ): ?array {
		$error = '';
		$id    = isset( $entity['id'] ) && is_scalar( $entity['id'] ) ? sanitize_text_field( (string) $entity['id'] ) : '';
		$id    = '' !== $id ? $id : wp_generate_uuid4();
		if ( ! empty( $entity['custom'] ) ) {
			$data = $entity['data'] ?? '';
			if ( is_string( $data ) ) {
				$decoded = '' === trim( $data ) ? null : json_decode( $data, true );
				if ( ! is_array( $decoded ) ) {
					$error = sprintf(
						/* translators: %s: JSON parser error message */
						__( 'Custom JSON-LD was not saved because it is not valid JSON (%s).', 'mg-seo' ),
						'' === trim( $data ) ? __( 'empty', 'mg-seo' ) : json_last_error_msg()
					);
					return null;
				}
				$data = $decoded;
			}
			if ( ! is_array( $data ) || ! $data ) {
				$error = __( 'Custom JSON-LD was not saved because it is empty.', 'mg-seo' );
				return null;
			}
			$data = self::sanitize_custom( $data );
			$type = $data['@type'] ?? ( isset( $data['@graph'] ) ? 'Graph' : 'Thing' );
			return array(
				'id'     => $id,
				'type'   => is_array( $type ) ? (string) reset( $type ) : sanitize_text_field( (string) $type ),
				'custom' => true,
				'data'   => $data,
			);
		}
		$type = isset( $entity['type'] ) && is_string( $entity['type'] ) ? $entity['type'] : '';
		$def  = Types::get( $type );
		if ( ! $def ) {
			return null;
		}
		return array(
			'id'     => $id,
			'type'   => $type,
			'custom' => false,
			'data'   => Types::sanitize_data( $def['fields'], $entity['data'] ?? array() ),
		);
	}

	/**
	 * Sanitize custom JSON-LD: keep structure, clean strings (no HTML except the allowed subset).
	 *
	 * @param array $data Decoded JSON.
	 * @return array
	 */
	private static function sanitize_custom( array $data ): array {
		$out = array();
		foreach ( $data as $key => $value ) {
			$key = is_string( $key ) ? sanitize_text_field( $key ) : $key;
			if ( is_array( $value ) ) {
				$out[ $key ] = self::sanitize_custom( $value );
			} elseif ( is_string( $value ) ) {
				$out[ $key ] = wp_kses( $value, Types::allowed_html() );
			} elseif ( is_scalar( $value ) || null === $value ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Default entity for a post type ('' setting = none).
	 *
	 * @param \WP_Post $post Post.
	 * @return array<string, mixed>|null
	 */
	public static function default_entity( \WP_Post $post ): ?array {
		$type = (string) Options::get( 'schema', 'pt_' . $post->post_type . '_type', self::fallback_default( $post->post_type ) );
		if ( '' === $type || ! Types::get( $type ) ) {
			return null;
		}
		$data = 'Article' === $type ? array() : Types::defaults( $type );
		if ( 'Article' === $type ) {
			$data['@type'] = (string) Options::get( 'schema', 'article_type', 'BlogPosting' );
		}
		return array(
			'id'      => 'default',
			'type'    => $type,
			'custom'  => false,
			'data'    => $data,
			'default' => true,
		);
	}

	/**
	 * Built-in default type per post type.
	 *
	 * @param string $post_type Post type.
	 */
	public static function fallback_default( string $post_type ): string {
		return 'post' === $post_type ? 'Article' : '';
	}

	/**
	 * Entity list that applies to a post: stored or default, templates, blocks.
	 *
	 * @param \WP_Post $post Post.
	 * @return array<int, array<string, mixed>>
	 */
	public static function for_post( \WP_Post $post ): array {
		$stored   = self::stored( $post->ID );
		$entities = null === $stored ? array_filter( array( self::default_entity( $post ) ) ) : $stored;
		if ( ! Meta::post( $post->ID, self::META_NO_TEMPLATE, false ) ) {
			$types = array_map( static fn( $e ) => $e['type'], $entities );
			foreach ( Templates::for_post( $post ) as $entity ) {
				if ( ! $entity['custom'] && in_array( $entity['type'], $types, true ) ) {
					continue;
				}
				$entities[] = $entity;
				$types[]    = $entity['type'];
			}
		}
		$entities = array_merge( $entities, Blocks::entities( $post ) );
		/**
		 * Filter the schema entities of a post before they are converted to JSON-LD.
		 *
		 * @param array    $entities Entities ({ id, type, custom, data }).
		 * @param \WP_Post $post     Post.
		 */
		return array_values( (array) apply_filters( 'mgseo/schema/entities', array_values( $entities ), $post ) );
	}

	/**
	 * Convert entities to graph nodes.
	 *
	 * @param array    $entities Entities.
	 * @param \WP_Post $post     Post.
	 * @param array    $env      canonical, webpage_id, site_id, image_id, author_id.
	 * @return array{nodes: array<int, array>, scripts: array<int, array>, map: array<string, string>}
	 */
	public static function build_all( array $entities, \WP_Post $post, array $env ): array {
		$nodes    = array();
		$scripts  = array();
		$map      = array();
		$counters = array();
		$faq      = null;
		foreach ( $entities as $entity ) {
			if ( ! empty( $entity['custom'] ) ) {
				$data = self::resolve( $entity['data'] ?? array(), $post );
				if ( ! is_array( $data ) || ! $data ) {
					continue;
				}
				if ( isset( $data['@context'] ) ) {
					$scripts[] = $data;
					continue;
				}
				$list = isset( $data['@graph'] ) && is_array( $data['@graph'] ) ? $data['@graph'] : ( array_is_list( $data ) ? $data : array( $data ) );
				foreach ( $list as $node ) {
					if ( is_array( $node ) && $node ) {
						$nodes[]                       = $node;
						$map[ (string) $entity['id'] ] = (string) ( $node['@id'] ?? '' );
					}
				}
				continue;
			}
			$node = self::build( $entity, $post, $env, $counters );
			if ( ! $node ) {
				continue;
			}
			if ( 'FAQPage' === $entity['type'] ) {
				// One FAQPage per page: merge questions from the generator, templates and blocks.
				if ( null === $faq ) {
					$faq = count( $nodes );
				} else {
					$nodes[ $faq ]['mainEntity']   = array_merge( (array) ( $nodes[ $faq ]['mainEntity'] ?? array() ), (array) ( $node['mainEntity'] ?? array() ) );
					$map[ (string) $entity['id'] ] = (string) $nodes[ $faq ]['@id'];
					continue;
				}
			}
			$nodes[]                       = $node;
			$map[ (string) $entity['id'] ] = (string) $node['@id'];
		}
		return array(
			'nodes'   => $nodes,
			'scripts' => $scripts,
			'map'     => $map,
		);
	}

	/**
	 * Convert one entity to a node.
	 *
	 * @param array    $entity   Entity.
	 * @param \WP_Post $post     Post.
	 * @param array    $env      Environment.
	 * @param array    $counters Id counters per type.
	 * @return array<string, mixed>|null
	 */
	public static function build( array $entity, \WP_Post $post, array $env, array &$counters ): ?array {
		$type = (string) ( $entity['type'] ?? '' );
		$def  = Types::get( $type );
		if ( ! $def ) {
			return null;
		}
		$data = self::resolve( is_array( $entity['data'] ?? null ) ? $entity['data'] : array(), $post );
		$data = self::normalize( $data );

		$slug              = (string) ( $def['id'] ?? strtolower( $type ) );
		$counters[ $slug ] = ( $counters[ $slug ] ?? 0 ) + 1;
		$fragment          = 1 === $counters[ $slug ] ? $slug : $slug . '-' . $counters[ $slug ];
		$schema_type       = isset( $data['@type'] ) && '' !== $data['@type'] ? $data['@type'] : $type;
		unset( $data['@type'], $data['@id'] );
		$node = array(
			'@type' => $schema_type,
			'@id'   => Graph::id( $fragment, $env['canonical'] ),
		) + $data;

		$node = self::complete( $type, $node, $post, $env );
		/**
		 * Filter one generated schema entity node.
		 *
		 * @param array    $node   Node.
		 * @param array    $entity Entity ({ id, type, custom, data }).
		 * @param \WP_Post $post   Post.
		 */
		$node = (array) apply_filters( 'mgseo/schema/entity', $node, $entity, $post );
		$node = self::prune( $node );
		return count( $node ) > 2 ? $node : null;
	}

	/**
	 * Fill automatic properties per type.
	 *
	 * @param string   $type Form type.
	 * @param array    $node Node.
	 * @param \WP_Post $post Post.
	 * @param array    $env  Environment.
	 * @return array<string, mixed>
	 */
	private static function complete( string $type, array $node, \WP_Post $post, array $env ): array {
		$ref       = static fn( string $id ) => '' !== $id ? array( '@id' => $id ) : null;
		$image     = $ref( $env['image_id'] ?? '' );
		$author    = $ref( $env['author_id'] ?? '' );
		$site      = $ref( $env['site_id'] ?? '' );
		$webpage   = $ref( $env['webpage_id'] ?? '' );
		$published = (string) get_post_time( 'c', false, $post );
		$modified  = (string) get_post_modified_time( 'c', false, $post );
		$missing   = static fn( string $key ) => ! isset( $node[ $key ] ) || '' === $node[ $key ] || array() === $node[ $key ];
		$lang      = Graph::language();

		switch ( $type ) {
			case 'Article':
				if ( $missing( 'headline' ) ) {
					$node['headline'] = wp_strip_all_tags( get_the_title( $post ) );
				}
				$node['headline'] = Helpers::truncate( (string) $node['headline'], 110 );
				if ( $missing( 'description' ) ) {
					$node['description'] = Titles::post_description( $post );
				}
				$defaults = array(
					'datePublished'    => $published,
					'dateModified'     => $modified,
					'author'           => $author,
					'publisher'        => $site,
					'image'            => $image,
					'mainEntityOfPage' => $webpage,
					'isPartOf'         => $webpage,
				);
				foreach ( $defaults as $key => $value ) {
					if ( $missing( $key ) ) {
						$node[ $key ] = $value;
					}
				}
				if ( $missing( 'articleSection' ) ) {
					$sections = array();
					$primary  = Helpers::primary_term( $post, 'category', true );
					if ( $primary ) {
						$sections[] = $primary->name;
					}
					$node['articleSection'] = implode( ', ', $sections );
				}
				if ( $missing( 'keywords' ) ) {
					$tags             = get_the_terms( $post, 'post_tag' );
					$node['keywords'] = is_array( $tags ) ? implode( ', ', wp_list_pluck( $tags, 'name' ) ) : '';
				}
				$node['wordCount']  = self::word_count( $post->post_content );
				$node['inLanguage'] = $lang;
				break;
			case 'Product':
			case 'Service':
			case 'Event':
			case 'Course':
			case 'SoftwareApplication':
			case 'Book':
				if ( $missing( 'url' ) && 'Book' !== $type ) {
					$node['url'] = $env['canonical'];
				}
				if ( $missing( 'image' ) && 'Course' !== $type ) {
					$node['image'] = $image;
				}
				if ( isset( $node['offers'] ) && is_array( $node['offers'] ) && ! array_is_list( $node['offers'] ) && empty( $node['offers']['url'] ) && 'Course' !== $type ) {
					$node['offers']['url'] = $env['canonical'];
				}
				if ( in_array( $type, array( 'Service', 'Course' ), true ) && $missing( 'provider' ) ) {
					$node['provider'] = $site;
				}
				break;
			case 'HowTo':
			case 'Recipe':
				if ( $missing( 'image' ) ) {
					$node['image'] = $image;
				}
				if ( 'Recipe' === $type ) {
					if ( $missing( 'author' ) ) {
						$node['author'] = $author ?? $site;
					}
					if ( $missing( 'datePublished' ) ) {
						$node['datePublished'] = $published;
					}
				}
				$node['inLanguage'] = $lang;
				break;
			case 'VideoObject':
				if ( $missing( 'thumbnailUrl' ) && ! empty( $env['image_url'] ) ) {
					$node['thumbnailUrl'] = $env['image_url'];
				}
				if ( $missing( 'uploadDate' ) ) {
					$node['uploadDate'] = $published;
				}
				break;
			case 'JobPosting':
				if ( $missing( 'hiringOrganization' ) ) {
					$node['hiringOrganization'] = $site;
				}
				if ( $missing( 'datePosted' ) ) {
					$node['datePosted'] = $published;
				}
				if ( isset( $node['employmentType'] ) && is_array( $node['employmentType'] ) && 1 === count( $node['employmentType'] ) ) {
					$node['employmentType'] = reset( $node['employmentType'] );
				}
				break;
			case 'Review':
				if ( $missing( 'author' ) ) {
					$node['author'] = $author ?? $site;
				}
				if ( $missing( 'datePublished' ) ) {
					$node['datePublished'] = $published;
				}
				break;
			case 'LocalBusiness':
			case 'Organization':
			case 'Person':
				if ( 'LocalBusiness' === $type && $missing( 'url' ) ) {
					$node['url'] = $env['canonical'];
				}
				break;
		}
		return $node;
	}

	/**
	 * Words in content.
	 *
	 * @param string $content Content.
	 */
	private static function word_count( string $content ): int {
		$text  = wp_strip_all_tags( strip_shortcodes( excerpt_remove_blocks( $content ) ) );
		$text  = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$words = preg_split( '/[\s\p{P}]+/u', $text, -1, PREG_SPLIT_NO_EMPTY );
		return is_array( $words ) ? count( $words ) : 0;
	}

	/**
	 * Replace %variables% in all string values.
	 *
	 * @param mixed         $data Data.
	 * @param \WP_Post|null $post Post.
	 * @return mixed
	 */
	public static function resolve( mixed $data, ?\WP_Post $post ): mixed {
		if ( is_array( $data ) ) {
			foreach ( $data as $key => $value ) {
				$data[ $key ] = self::resolve( $value, $post );
			}
			return $data;
		}
		if ( is_string( $data ) && Types::has_variable( $data ) ) {
			return Variables::replace( $data, $post );
		}
		return $data;
	}

	/**
	 * Normalise dates, durations, URLs and numbers by property name.
	 *
	 * @param array $data Data.
	 * @return array
	 */
	public static function normalize( array $data ): array {
		foreach ( $data as $key => $value ) {
			if ( is_array( $value ) ) {
				if ( in_array( $key, self::URL_KEYS, true ) && array_is_list( $value ) ) {
					$data[ $key ] = array_values( array_filter( array_map( static fn( $v ) => is_string( $v ) ? self::absolute_url( $v ) : $v, $value ) ) );
					continue;
				}
				$data[ $key ] = self::normalize( $value );
				continue;
			}
			if ( ! is_string( $value ) || ! is_string( $key ) ) {
				continue;
			}
			$value = trim( $value );
			if ( in_array( $key, self::DATE_KEYS, true ) ) {
				$data[ $key ] = self::iso_date( $value );
			} elseif ( in_array( $key, self::DURATION_KEYS, true ) ) {
				$data[ $key ] = self::iso_duration( $value );
			} elseif ( in_array( $key, self::URL_KEYS, true ) ) {
				$data[ $key ] = self::absolute_url( $value );
			} elseif ( in_array( $key, self::NUMBER_KEYS, true ) && is_numeric( str_replace( ',', '.', $value ) ) ) {
				$data[ $key ] = str_replace( ',', '.', $value ) + 0;
			} else {
				$data[ $key ] = $value;
			}
		}
		return $data;
	}

	/**
	 * Date to ISO 8601 ('' when unparsable). Plain dates stay Y-m-d; times get the site offset.
	 *
	 * @param string $value Value.
	 */
	public static function iso_date( string $value ): string {
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return $value;
		}
		try {
			$date = new \DateTimeImmutable( $value, wp_timezone() );
		} catch ( \Exception $e ) {
			return '';
		}
		return $date->format( 'c' );
	}

	/**
	 * Duration to ISO 8601 ("90", "1h 30m", "1:30", "PT1H30M" → "PT1H30M"; '' when unparsable).
	 *
	 * @param string $value Value.
	 */
	public static function iso_duration( string $value ): string {
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		if ( preg_match( '/^P(?=\d|T\d)(\d+Y)?(\d+M)?(\d+W)?(\d+D)?(T(?=\d)(\d+H)?(\d+M)?(\d+(?:\.\d+)?S)?)?$/i', $value ) ) {
			return strtoupper( $value );
		}
		$minutes = null;
		$seconds = 0;
		if ( preg_match( '/^\d+$/', $value ) ) {
			$minutes = (int) $value;
		} elseif ( preg_match( '/^(\d+):(\d{1,2})(?::(\d{1,2}))?$/', $value, $m ) ) {
			$minutes = (int) $m[1] * 60 + (int) $m[2];
			$seconds = isset( $m[3] ) ? (int) $m[3] : 0;
		} else {
			$total = 0;
			$found = false;
			if ( preg_match_all( '/(\d+(?:[.,]\d+)?)\s*(d|days?|dni|dzień|h|hours?|hrs?|godz\w*|m|mins?|minutes?|minut\w*|s|secs?|seconds?|sek\w*)\b/iu', $value, $all, PREG_SET_ORDER ) ) {
				foreach ( $all as $part ) {
					$num   = (float) str_replace( ',', '.', $part[1] );
					$unit  = mb_strtolower( $part[2] );
					$found = true;
					if ( str_starts_with( $unit, 'd' ) ) {
						$total += $num * 86400;
					} elseif ( str_starts_with( $unit, 'h' ) || str_starts_with( $unit, 'g' ) ) {
						$total += $num * 3600;
					} elseif ( str_starts_with( $unit, 'm' ) ) {
						$total += $num * 60;
					} else {
						$total += $num;
					}
				}
			}
			if ( ! $found ) {
				return '';
			}
			$minutes = intdiv( (int) round( $total ), 60 );
			$seconds = (int) round( $total ) % 60;
		}
		$days  = intdiv( $minutes, 1440 );
		$hours = intdiv( $minutes % 1440, 60 );
		$mins  = $minutes % 60;
		$out   = 'P' . ( $days ? $days . 'D' : '' );
		$time  = ( $hours ? $hours . 'H' : '' ) . ( $mins ? $mins . 'M' : '' ) . ( $seconds ? $seconds . 'S' : '' );
		if ( '' !== $time ) {
			$out .= 'T' . $time;
		}
		return 'P' === $out ? 'PT0M' : $out;
	}

	/**
	 * Make a URL absolute ('' when not a URL).
	 *
	 * @param string $url URL.
	 */
	public static function absolute_url( string $url ): string {
		$url = trim( $url );
		if ( '' === $url ) {
			return '';
		}
		if ( str_starts_with( $url, '//' ) ) {
			$url = ( is_ssl() ? 'https:' : 'http:' ) . $url;
		} elseif ( str_starts_with( $url, '/' ) || str_starts_with( $url, '#' ) ) {
			$url = str_starts_with( $url, '#' ) ? $url : home_url( $url );
		}
		if ( str_starts_with( $url, '#' ) ) {
			return $url;
		}
		if ( ! preg_match( '#^https?://#i', $url ) ) {
			return '';
		}
		return esc_url_raw( $url );
	}

	/**
	 * Remove empty values recursively (and objects holding only @type).
	 *
	 * @param mixed $data Data.
	 * @return mixed
	 */
	public static function prune( mixed $data ): mixed {
		if ( ! is_array( $data ) ) {
			return $data;
		}
		$is_list = array_is_list( $data );
		foreach ( $data as $key => $value ) {
			$value = self::prune( $value );
			if ( null === $value || '' === $value || array() === $value || ( false === $value && 'directApply' !== $key ) ) {
				unset( $data[ $key ] );
				continue;
			}
			if ( is_array( $value ) && ! array_is_list( $value ) && array( '@type' ) === array_keys( $value ) ) {
				unset( $data[ $key ] );
				continue;
			}
			$data[ $key ] = $value;
		}
		return $is_list ? array_values( $data ) : $data;
	}
}
