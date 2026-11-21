<?php
/**
 * Automatic redirections on slug change and trash.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Redirections;

use MGSEO\Meta;
use MGSEO\Options;

defined( 'ABSPATH' ) || exit;

/**
 * Creates 301 redirects when the URL of a published post/page/term changes and,
 * optionally, a 410 or a redirect when a published post is trashed.
 */
final class AutoRedirect {

	public const CATEGORY       = 'auto';
	public const TRASH_CATEGORY = 'trash';

	/**
	 * Permalinks captured before an update.
	 *
	 * @var array<string, string>
	 */
	private static array $before = array();

	/**
	 * Register hooks.
	 */
	public static function boot(): void {
		add_action( 'pre_post_update', array( self::class, 'capture_post' ), 10, 1 );
		add_action( 'post_updated', array( self::class, 'post_updated' ), 10, 3 );
		add_action( 'edit_terms', array( self::class, 'capture_term' ), 10, 2 );
		add_action( 'edited_term', array( self::class, 'term_updated' ), 10, 3 );
		add_action( 'wp_trash_post', array( self::class, 'trash_post' ), 10, 1 );
		add_action( 'untrashed_post', array( self::class, 'untrash_post' ), 10, 1 );
		add_action( 'transition_post_status', array( self::class, 'published' ), 20, 3 );
	}

	/**
	 * Whether a post can get automatic redirects.
	 *
	 * @param \WP_Post $post Post.
	 */
	private static function eligible_post( \WP_Post $post ): bool {
		return 'publish' === $post->post_status
			&& 'attachment' !== $post->post_type
			&& is_post_type_viewable( $post->post_type )
			&& ! wp_is_post_revision( $post )
			&& ! wp_is_post_autosave( $post );
	}

	/**
	 * Remember the permalink before the update is written.
	 *
	 * @param int $post_id Post id.
	 */
	public static function capture_post( int $post_id ): void {
		if ( ! Options::get( 'redirections', 'auto_redirect', true ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( $post instanceof \WP_Post && self::eligible_post( $post ) ) {
			self::$before[ 'p' . $post_id ] = (string) get_permalink( $post );
		}
	}

	/**
	 * Compare permalinks after the update.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $after   Post after.
	 * @param \WP_Post $before  Post before.
	 */
	public static function post_updated( int $post_id, \WP_Post $after, \WP_Post $before ): void {
		$old = self::$before[ 'p' . $post_id ] ?? '';
		unset( self::$before[ 'p' . $post_id ] );
		if ( '' === $old || ! self::eligible_post( $after ) || 'publish' !== $before->post_status ) {
			return;
		}
		$new = (string) get_permalink( $after );
		self::link_changed( $old, $new );
	}

	/**
	 * Remember a term link before it changes.
	 *
	 * @param int    $term_id  Term id.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function capture_term( int $term_id, string $taxonomy ): void {
		if ( ! Options::get( 'redirections', 'auto_redirect', true ) || ! is_taxonomy_viewable( $taxonomy ) ) {
			return;
		}
		$link = get_term_link( $term_id, $taxonomy );
		if ( is_string( $link ) ) {
			self::$before[ 't' . $term_id ] = $link;
		}
	}

	/**
	 * Compare term links after the update.
	 *
	 * @param int    $term_id  Term id.
	 * @param int    $tt_id    Term taxonomy id.
	 * @param string $taxonomy Taxonomy.
	 */
	public static function term_updated( int $term_id, int $tt_id, string $taxonomy ): void {
		unset( $tt_id );
		$old = self::$before[ 't' . $term_id ] ?? '';
		unset( self::$before[ 't' . $term_id ] );
		if ( '' === $old ) {
			return;
		}
		$new = get_term_link( $term_id, $taxonomy );
		if ( is_string( $new ) ) {
			self::link_changed( $old, $new );
		}
	}

	/**
	 * Create/maintain redirects after a URL change: old → new (301), retarget redirects that pointed to old,
	 * and drop sources equal to the new URL so they cannot loop.
	 *
	 * @param string $old Old URL.
	 * @param string $new_url New URL.
	 * @return int Redirection id (0 when nothing was created).
	 */
	public static function link_changed( string $old, string $new_url ): int {
		$old_path = Repository::local_path( $old );
		$new_path = Repository::local_path( $new_url );
		if ( null === $old_path || null === $new_path || $old_path === $new_path || '' === $old_path ) {
			return 0;
		}
		self::release_source( $new_url );
		$target = self::relative( $new_url );
		foreach ( Repository::find_by_target( $old ) as $row ) {
			Repository::update( (int) $row['id'], array( 'url_to' => $target ) );
		}
		$existing = Repository::find_by_source( $old, 'exact' );
		if ( $existing ) {
			Repository::update(
				(int) $existing['id'],
				array(
					'url_to' => $target,
					'status' => 'active',
					'type'   => Repository::is_gone_type( (int) $existing['type'] ) ? 301 : (int) $existing['type'],
				)
			);
			return (int) $existing['id'];
		}
		$id = Repository::create(
			array(
				'sources'  => array(
					array(
						'pattern'    => $old_path,
						'comparison' => 'exact',
					),
				),
				'url_to'   => $target,
				'type'     => 301,
				'category' => self::CATEGORY,
			)
		);
		return is_wp_error( $id ) ? 0 : $id;
	}

	/**
	 * Remove an exact source equal to a URL from every redirection (deleting rows left without sources).
	 *
	 * @param string $url URL that is now a live page.
	 */
	public static function release_source( string $url ): void {
		$path = Repository::local_path( $url );
		if ( null === $path || '' === $path ) {
			return;
		}
		foreach ( Repository::find_all_by_source( $path, 'exact' ) as $row ) {
			$sources = array_values(
				array_filter(
					$row['sources'],
					static fn( $s ) => ! ( 'exact' === $s['comparison'] && mb_strtolower( $s['pattern'] ) === mb_strtolower( $path ) )
				)
			);
			if ( $sources ) {
				Repository::update( (int) $row['id'], array( 'sources' => $sources ) );
			} else {
				Repository::delete( (int) $row['id'] );
			}
		}
	}

	/**
	 * A post got published for the first time: an old redirect from its URL would hide it.
	 *
	 * @param string   $new_status New status.
	 * @param string   $old_status Old status.
	 * @param \WP_Post $post       Post.
	 */
	public static function published( string $new_status, string $old_status, \WP_Post $post ): void {
		if ( 'publish' !== $new_status || 'publish' === $old_status || ! self::eligible_post( $post ) ) {
			return;
		}
		if ( ! Options::get( 'redirections', 'auto_redirect', true ) ) {
			return;
		}
		self::release_source( (string) get_permalink( $post ) );
	}

	/**
	 * Create a 410 or a redirect when a published post is trashed (setting, off by default).
	 *
	 * @param int $post_id Post id.
	 */
	public static function trash_post( int $post_id ): void {
		$behavior = (string) Options::get( 'redirections', 'trash_behavior', 'none' );
		$post     = get_post( $post_id );
		if ( 'none' === $behavior || ! $post instanceof \WP_Post || ! self::eligible_post( $post ) ) {
			return;
		}
		$url  = (string) get_permalink( $post );
		$args = array(
			'sources'  => array( $url ),
			'category' => self::TRASH_CATEGORY,
		);
		if ( '410' === $behavior ) {
			$args['type'] = 410;
		} else {
			$target = '';
			if ( 'parent' === $behavior && $post->post_parent ) {
				$parent = get_post( $post->post_parent );
				if ( $parent && 'publish' === $parent->post_status ) {
					$target = (string) get_permalink( $parent );
				}
			}
			if ( '' === $target && 'parent' === $behavior && 'post' === $post->post_type ) {
				$cats = get_the_category( $post->ID );
				if ( $cats ) {
					$link   = get_term_link( $cats[0] );
					$target = is_string( $link ) ? $link : '';
				}
			}
			$args['type']   = 301;
			$args['url_to'] = self::relative( '' !== $target ? $target : home_url( '/' ) );
		}
		if ( Repository::find_by_source( $url, 'exact' ) ) {
			return;
		}
		$id = Repository::create( $args );
		if ( ! is_wp_error( $id ) ) {
			Meta::set_post( $post_id, 'trash_redirect', $id );
		}
	}

	/**
	 * Remove the redirect created on trash when the post is restored.
	 *
	 * @param int $post_id Post id.
	 */
	public static function untrash_post( int $post_id ): void {
		$id = (int) Meta::post( $post_id, 'trash_redirect', 0 );
		if ( $id ) {
			$row = Repository::get( $id );
			if ( $row && self::TRASH_CATEGORY === $row['category'] ) {
				Repository::delete( $id );
			}
			Meta::set_post( $post_id, 'trash_redirect', '' );
		}
	}

	/**
	 * Site-relative form of a local URL (keeps redirects working after a domain change).
	 *
	 * @param string $url URL.
	 */
	public static function relative( string $url ): string {
		$home = untrailingslashit( home_url() );
		if ( str_starts_with( $url, $home ) ) {
			$rest = substr( $url, strlen( $home ) );
			return '' === $rest ? '/' : $rest;
		}
		return $url;
	}
}
