<?php
/**
 * Template variables that Rank Math and Yoast offer and MG SEO core does not.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Import;

use MGSEO\Context;

defined( 'ABSPATH' ) || exit;

/**
 * Registers %currenttime%, %parent_title%, %user_description%, %userid% and %customterm_desc(taxonomy)%
 * so imported templates keep rendering the same text.
 */
final class Compat {

	/**
	 * Register hooks.
	 */
	public static function boot(): void {
		add_filter( 'mgseo/variables', array( self::class, 'variables' ) );
		add_filter( 'mgseo/variable_descriptions', array( self::class, 'describe' ) );
	}

	/**
	 * Variable descriptions (untranslated keys, used by the converter before `init`).
	 *
	 * @return array<string, string>
	 */
	public static function descriptions(): array {
		return array(
			'currenttime'      => 'currenttime',
			'parent_title'     => 'parent_title',
			'user_description' => 'user_description',
			'userid'           => 'userid',
			'customterm_desc'  => 'customterm_desc',
		);
	}

	/**
	 * Add variable callbacks.
	 *
	 * @param array $vars Variables.
	 * @return array
	 */
	public static function variables( array $vars ): array {
		$post = static function ( ?object $obj ): ?\WP_Post {
			if ( $obj instanceof \WP_Post ) {
				return $obj;
			}
			if ( null === $obj ) {
				$ctx = Context::current();
				return $ctx['object'] instanceof \WP_Post ? $ctx['object'] : null;
			}
			return null;
		};
		$user = static function ( ?object $obj ) use ( $post ): ?\WP_User {
			if ( $obj instanceof \WP_User ) {
				return $obj;
			}
			$p = $post( $obj );
			if ( $p ) {
				$u = get_userdata( (int) $p->post_author );
				return $u instanceof \WP_User ? $u : null;
			}
			if ( null === $obj ) {
				$ctx = Context::current();
				return $ctx['object'] instanceof \WP_User ? $ctx['object'] : null;
			}
			return null;
		};
		return $vars + array(
			'currenttime'      => static fn( ?object $obj, string $arg ) => wp_date( '' !== $arg ? $arg : (string) get_option( 'time_format' ) ),
			'parent_title'     => static function ( ?object $obj ) use ( $post ): string {
				$p = $post( $obj );
				return $p && $p->post_parent ? get_the_title( $p->post_parent ) : '';
			},
			'user_description' => static fn( ?object $obj ) => $user( $obj ) ? wp_strip_all_tags( (string) $user( $obj )->description ) : '',
			'userid'           => static fn( ?object $obj ) => $user( $obj ) ? (string) $user( $obj )->ID : '',
			'customterm_desc'  => static function ( ?object $obj, string $arg ) use ( $post ): string {
				$p = $post( $obj );
				if ( ! $p || '' === $arg ) {
					return '';
				}
				$terms = get_the_terms( $p, sanitize_key( $arg ) );
				return is_array( $terms ) && $terms ? wp_strip_all_tags( (string) reset( $terms )->description ) : '';
			},
		);
	}

	/**
	 * Variable descriptions for the UI.
	 *
	 * @param array $descriptions Descriptions.
	 * @return array
	 */
	public static function describe( array $descriptions ): array {
		return $descriptions + array(
			/* translators: %s: variable example */
			'currenttime'      => sprintf( __( 'Current time, optional format: %s', 'mg-seo' ), '%currenttime(H:i)%' ),
			'parent_title'     => __( 'Title of the parent page', 'mg-seo' ),
			'user_description' => __( 'Biographical info of the author', 'mg-seo' ),
			'userid'           => __( 'Author user ID', 'mg-seo' ),
			/* translators: %s: variable example */
			'customterm_desc'  => sprintf( __( 'Description of the first term of a taxonomy: %s', 'mg-seo' ), '%customterm_desc(taxonomy)%' ),
		);
	}
}
