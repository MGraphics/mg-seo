<?php
/**
 * Template variables (%title%, %sitename% ...).
 *
 * @package MGSEO
 */

namespace MGSEO;

defined( 'ABSPATH' ) || exit;

/**
 * Replaces %variable% placeholders in templates.
 *
 * Supported: %title% %sitename% %sitedesc% %sep% %excerpt% %excerpt_only% %category% %categories% %primary_category%
 * %tag% %tags% %term% %term_description% %term_title% %author% %name% %date% %modified% %currentyear% %currentmonth%
 * %currentday% %currentdate% %page% %pagenumber% %pagetotal% %search_query% %pt_single% %pt_plural% %focuskw% %keywords%
 * %id% %filename% %url% %org_name% %customfield(key)% %customterm(taxonomy)% %date(format)% %modified(format)%.
 * Modules add more through the `mgseo/variables` filter (array name => callable( ?object $obj, string $arg ): string).
 */
final class Variables {

	/**
	 * Registered callbacks.
	 *
	 * @var array<string, callable>|null
	 */
	private static ?array $vars = null;

	/**
	 * Replace variables in a template.
	 *
	 * @param string      $template Template.
	 * @param object|null $obj      Object the template describes (WP_Post, WP_Term, WP_User, WP_Post_Type) or null for current context.
	 */
	public static function replace( string $template, ?object $obj = null ): string {
		if ( ! str_contains( $template, '%' ) ) {
			return $template;
		}
		$vars   = self::vars();
		$result = preg_replace_callback(
			'/%([a-z0-9_]+)(?:\(([^)%]*)\))?%/i',
			static function ( array $m ) use ( $vars, $obj ): string {
				$name = strtolower( $m[1] );
				if ( ! isset( $vars[ $name ] ) ) {
					return $m[0];
				}
				$value = call_user_func( $vars[ $name ], $obj, $m[2] ?? '' );
				return is_scalar( $value ) ? (string) $value : '';
			},
			$template
		);
		return self::clean( (string) $result );
	}

	/**
	 * Normalise whitespace and dangling separators after replacement.
	 *
	 * @param string $text Text.
	 */
	public static function clean( string $text ): string {
		$text = wp_strip_all_tags( $text );
		$text = preg_replace( '/\s+/u', ' ', $text );
		$sep  = preg_quote( self::separator(), '/' );
		// Collapse repeated separators ("A - - B") and trim separators at the edges.
		$text = preg_replace( '/(\s*' . $sep . '\s*){2,}/u', ' ' . self::separator() . ' ', (string) $text );
		$text = preg_replace( '/^\s*' . $sep . '\s*|\s*' . $sep . '\s*$/u', '', (string) $text );
		return trim( (string) $text );
	}

	/**
	 * Title separator from settings.
	 */
	public static function separator(): string {
		$sep = (string) Options::get( 'titles', 'separator', '-' );
		return '' === $sep ? '-' : html_entity_decode( $sep, ENT_QUOTES, 'UTF-8' );
	}

	/**
	 * Variable list for UI hints.
	 *
	 * @return array<string, string> name => description
	 */
	public static function describe(): array {
		$list = array(
			'title'            => __( 'Title of the post, page, term or archive', 'mg-seo' ),
			'sitename'         => __( 'Site title', 'mg-seo' ),
			'sitedesc'         => __( 'Site tagline', 'mg-seo' ),
			'sep'              => __( 'Separator', 'mg-seo' ),
			'excerpt'          => __( 'Excerpt (generated from content when empty)', 'mg-seo' ),
			'excerpt_only'     => __( 'Manual excerpt only', 'mg-seo' ),
			'category'         => __( 'First category', 'mg-seo' ),
			'primary_category' => __( 'Primary category', 'mg-seo' ),
			'categories'       => __( 'All categories', 'mg-seo' ),
			'tag'              => __( 'First tag', 'mg-seo' ),
			'tags'             => __( 'All tags', 'mg-seo' ),
			'term'             => __( 'Current term name', 'mg-seo' ),
			'term_description' => __( 'Current term description', 'mg-seo' ),
			'author'           => __( 'Author display name', 'mg-seo' ),
			'date'             => __( 'Publish date, optional format: %date(Y-m-d)%', 'mg-seo' ),
			'modified'         => __( 'Last modified date', 'mg-seo' ),
			'currentyear'      => __( 'Current year', 'mg-seo' ),
			'currentmonth'     => __( 'Current month', 'mg-seo' ),
			'currentdate'      => __( 'Current date', 'mg-seo' ),
			'page'             => __( 'Page number text, e.g. "Page 2 of 4" (empty on page 1)', 'mg-seo' ),
			'pagenumber'       => __( 'Current page number', 'mg-seo' ),
			'pagetotal'        => __( 'Total pages', 'mg-seo' ),
			'search_query'     => __( 'Search query', 'mg-seo' ),
			'pt_single'        => __( 'Post type singular label', 'mg-seo' ),
			'pt_plural'        => __( 'Post type plural label', 'mg-seo' ),
			'focuskw'          => __( 'Primary focus keyword', 'mg-seo' ),
			'id'               => __( 'Post ID', 'mg-seo' ),
			'url'              => __( 'Permalink', 'mg-seo' ),
			'filename'         => __( 'Attachment file name without extension', 'mg-seo' ),
			'org_name'         => __( 'Organization name from schema settings', 'mg-seo' ),
			'customfield'      => __( 'Custom field value: %customfield(field_name)%', 'mg-seo' ),
			'customterm'       => __( 'Terms of a taxonomy: %customterm(taxonomy)%', 'mg-seo' ),
		);
		/**
		 * Filter variable descriptions shown in the UI.
		 *
		 * @param array $list name => description.
		 */
		return apply_filters( 'mgseo/variable_descriptions', $list );
	}

	/**
	 * Reset registered variables (tests).
	 */
	public static function reset(): void {
		self::$vars = null;
	}

	/**
	 * Variable callbacks.
	 *
	 * @return array<string, callable>
	 */
	private static function vars(): array {
		if ( null !== self::$vars ) {
			return self::$vars;
		}
		$post = static function ( ?object $obj ): ?\WP_Post {
			if ( $obj instanceof \WP_Post ) {
				return $obj;
			}
			if ( null === $obj ) {
				$ctx = Context::current();
				if ( $ctx['object'] instanceof \WP_Post ) {
					return $ctx['object'];
				}
			}
			return null;
		};
		$term = static function ( ?object $obj ): ?\WP_Term {
			if ( $obj instanceof \WP_Term ) {
				return $obj;
			}
			if ( null === $obj ) {
				$ctx = Context::current();
				if ( $ctx['object'] instanceof \WP_Term ) {
					return $ctx['object'];
				}
			}
			return null;
		};
		$terms = static function ( ?\WP_Post $p, string $taxonomy, bool $all ): string {
			if ( ! $p ) {
				return '';
			}
			$list = get_the_terms( $p, $taxonomy );
			if ( ! is_array( $list ) || ! $list ) {
				return '';
			}
			$names = wp_list_pluck( $list, 'name' );
			return $all ? implode( ', ', $names ) : (string) reset( $names );
		};

		$vars = array(
			'sitename'         => static fn() => get_bloginfo( 'name' ),
			'sitedesc'         => static fn() => get_bloginfo( 'description' ),
			'sep'              => static fn() => self::separator(),
			'title'            => static function ( ?object $obj ) use ( $post, $term ): string {
				$p = $post( $obj );
				if ( $p ) {
					return get_the_title( $p );
				}
				$t = $term( $obj );
				if ( $t ) {
					return $t->name;
				}
				if ( $obj instanceof \WP_User ) {
					return $obj->display_name;
				}
				if ( $obj instanceof \WP_Post_Type ) {
					return $obj->labels->name;
				}
				if ( null === $obj ) {
					$ctx = Context::current();
					if ( $ctx['object'] instanceof \WP_User ) {
						return $ctx['object']->display_name;
					}
					if ( $ctx['object'] instanceof \WP_Post_Type ) {
						return $ctx['object']->labels->name;
					}
					if ( Context::DATE === $ctx['type'] ) {
						return wp_strip_all_tags( get_the_archive_title() );
					}
				}
				return '';
			},
			'term_title'       => static fn( ?object $obj ) => ( $t = $term( $obj ) ) ? $t->name : '',
			'term'             => static fn( ?object $obj ) => ( $t = $term( $obj ) ) ? $t->name : '',
			'term_description' => static fn( ?object $obj ) => ( $t = $term( $obj ) ) ? wp_strip_all_tags( term_description( $t ) ) : '',
			'excerpt'          => static function ( ?object $obj ) use ( $post ): string {
				$p = $post( $obj );
				if ( ! $p ) {
					return '';
				}
				if ( has_excerpt( $p ) ) {
					return wp_strip_all_tags( $p->post_excerpt );
				}
				return Helpers::excerpt_from_content( $p->post_content, 160 );
			},
			'excerpt_only'     => static fn( ?object $obj ) => ( $p = $post( $obj ) ) && has_excerpt( $p ) ? wp_strip_all_tags( $p->post_excerpt ) : '',
			'category'         => static fn( ?object $obj ) => $terms( $post( $obj ), 'category', false ),
			'categories'       => static fn( ?object $obj ) => $terms( $post( $obj ), 'category', true ),
			'primary_category' => static function ( ?object $obj ) use ( $post, $terms ): string {
				$p = $post( $obj );
				if ( ! $p ) {
					return '';
				}
				$primary = Helpers::primary_term( $p, 'category' );
				return $primary ? $primary->name : $terms( $p, 'category', false );
			},
			'tag'              => static fn( ?object $obj ) => $terms( $post( $obj ), 'post_tag', false ),
			'tags'             => static fn( ?object $obj ) => $terms( $post( $obj ), 'post_tag', true ),
			'customterm'       => static fn( ?object $obj, string $arg ) => $terms( $post( $obj ), sanitize_key( $arg ), true ),
			'author'           => static function ( ?object $obj ) use ( $post ): string {
				$p = $post( $obj );
				if ( $p ) {
					return (string) get_the_author_meta( 'display_name', (int) $p->post_author );
				}
				if ( $obj instanceof \WP_User ) {
					return $obj->display_name;
				}
				$ctx = Context::current();
				return $ctx['object'] instanceof \WP_User ? $ctx['object']->display_name : '';
			},
			'name'             => static fn( ?object $obj ) => call_user_func( self::$vars['author'], $obj, '' ),
			'date'             => static fn( ?object $obj, string $arg ) => ( $p = $post( $obj ) ) ? get_the_date( '' !== $arg ? $arg : '', $p ) : '',
			'modified'         => static fn( ?object $obj, string $arg ) => ( $p = $post( $obj ) ) ? get_the_modified_date( '' !== $arg ? $arg : '', $p ) : '',
			'currentyear'      => static fn() => wp_date( 'Y' ),
			'currentmonth'     => static fn() => wp_date( 'F' ),
			'currentday'       => static fn() => wp_date( 'j' ),
			'currentdate'      => static fn() => wp_date( (string) get_option( 'date_format' ) ),
			'pagenumber'       => static fn() => (string) Context::page_number(),
			'pagetotal'        => static function (): string {
				global $wp_query;
				return (string) max( 1, (int) ( $wp_query->max_num_pages ?? 1 ) );
			},
			'page'             => static function (): string {
				global $wp_query;
				$current = Context::page_number();
				if ( $current < 2 ) {
					return '';
				}
				$total = max( $current, (int) ( $wp_query->max_num_pages ?? 1 ) );
				/* translators: 1: current page, 2: total pages */
				return sprintf( __( 'Page %1$d of %2$d', 'mg-seo' ), $current, $total );
			},
			'search_query'     => static fn() => get_search_query( false ),
			'pt_single'        => static function ( ?object $obj ) use ( $post ): string {
				$type = self::post_type_object( $obj, $post );
				return $type ? $type->labels->singular_name : '';
			},
			'pt_plural'        => static function ( ?object $obj ) use ( $post ): string {
				$type = self::post_type_object( $obj, $post );
				return $type ? $type->labels->name : '';
			},
			'focuskw'          => static function ( ?object $obj ) use ( $post, $term ): string {
				$target = $post( $obj ) ?? $term( $obj );
				$kw     = (string) Meta::for_object( $target, 'focus_keyword', '' );
				$parts  = array_map( 'trim', explode( ',', $kw ) );
				return (string) ( $parts[0] ?? '' );
			},
			'keywords'         => static fn( ?object $obj ) => (string) Meta::for_object( $post( $obj ) ?? $term( $obj ), 'focus_keyword', '' ),
			'id'               => static fn( ?object $obj ) => ( $p = $post( $obj ) ) ? (string) $p->ID : '',
			'url'              => static fn( ?object $obj ) => ( $p = $post( $obj ) ) ? (string) get_permalink( $p ) : '',
			'filename'         => static function ( ?object $obj ) use ( $post ): string {
				$p = $post( $obj );
				if ( ! $p || 'attachment' !== $p->post_type ) {
					return '';
				}
				$file = (string) get_attached_file( $p->ID );
				return '' === $file ? '' : Helpers::humanize_filename( $file );
			},
			'org_name'         => static function (): string {
				$name = (string) Options::get( 'schema', 'org_name', '' );
				return '' !== $name ? $name : get_bloginfo( 'name' );
			},
			'customfield'      => static function ( ?object $obj, string $arg ) use ( $post ): string {
				$p = $post( $obj );
				if ( ! $p || '' === $arg ) {
					return '';
				}
				$value = get_post_meta( $p->ID, $arg, true );
				return is_scalar( $value ) ? (string) $value : '';
			},
		);

		/**
		 * Filter template variables.
		 *
		 * @param array $vars name => callable( ?object $obj, string $arg ): string
		 */
		self::$vars = apply_filters( 'mgseo/variables', $vars );
		return self::$vars;
	}

	/**
	 * Post type object for type label variables.
	 *
	 * @param object|null $obj  Object.
	 * @param callable    $post Post resolver.
	 */
	private static function post_type_object( ?object $obj, callable $post ): ?\WP_Post_Type {
		if ( $obj instanceof \WP_Post_Type ) {
			return $obj;
		}
		$p = $post( $obj );
		if ( $p ) {
			return get_post_type_object( $p->post_type );
		}
		if ( null === $obj ) {
			$ctx = Context::current();
			if ( $ctx['object'] instanceof \WP_Post_Type ) {
				return $ctx['object'];
			}
		}
		return null;
	}
}
