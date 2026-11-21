<?php
/**
 * Bulk Editing module.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Bulk;

use MGSEO\Context;
use MGSEO\Helpers;
use MGSEO\Meta;
use MGSEO\Module as Base;

defined( 'ABSPATH' ) || exit;

/**
 * Bulk SEO editor (list table with inline fields saved over REST), quick/bulk edit fields on the core
 * posts list and CSV export/import of per-post SEO data.
 */
final class Module extends Base {

	public const PAGE = 'mg-seo-bulk';
	public const CAP  = 'mgseo_bulk_edit';

	/**
	 * Module id.
	 */
	public function id(): string {
		return 'bulk';
	}

	/**
	 * Capability for the module screens.
	 */
	public function capability(): string {
		return self::CAP;
	}

	/**
	 * Register hooks.
	 */
	public function boot(): void {
		add_action( 'mgseo/rest_routes', array( $this, 'routes' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'assets' ) );
		add_action( 'admin_init', array( $this, 'list_hooks' ) );
		add_action( 'save_post', array( $this, 'save_quick_edit' ), 20, 2 );
		add_action( 'admin_post_mgseo_bulk_csv_export', array( Csv::class, 'handle_export' ) );
		add_action( 'admin_post_mgseo_bulk_csv_import', array( Csv::class, 'handle_import' ) );
		add_action( 'admin_post_mgseo_bulk_csv_apply', array( Csv::class, 'handle_apply' ) );
	}

	/**
	 * Admin page.
	 */
	public function admin_pages(): array {
		return array(
			array(
				'slug'       => self::PAGE,
				'title'      => __( 'Bulk SEO Editor', 'mg-seo' ),
				'menu_title' => __( 'Bulk Editor', 'mg-seo' ),
				'capability' => self::CAP,
				'callback'   => array( Page::class, 'render' ),
				'position'   => 30,
			),
		);
	}

	/**
	 * Assets for the bulk page and the core posts list.
	 *
	 * @param string $hook Hook suffix.
	 */
	public function assets( string $hook ): void {
		$screen = get_current_screen();
		$ours   = str_ends_with( $hook, self::PAGE );
		$list   = $screen && 'edit' === $screen->base && isset( Context::post_types()[ $screen->post_type ] );
		if ( ( ! $ours && ! $list ) || ! current_user_can( 'mgseo_edit_meta' ) ) {
			return;
		}
		wp_enqueue_style( 'mgseo-bulk', MGSEO_URL . 'assets/bulk.css', array(), MGSEO_VERSION );
		wp_enqueue_script( 'mgseo-bulk', MGSEO_URL . 'assets/bulk.js', array( 'jquery', 'wp-api-fetch', 'wp-i18n' ), MGSEO_VERSION, true );
		wp_set_script_translations( 'mgseo-bulk', 'mg-seo' );
		wp_localize_script(
			'mgseo-bulk',
			'mgseoBulk',
			array(
				'path'  => '/' . \MGSEO\Rest::NAMESPACE . '/bulk/save',
				'batch' => 20,
			)
		);
	}

	/**
	 * Hooks for the core posts list (column data and quick/bulk edit boxes).
	 */
	public function list_hooks(): void {
		if ( ! current_user_can( 'mgseo_edit_meta' ) ) {
			return;
		}
		foreach ( array_keys( Context::post_types() ) as $post_type ) {
			add_filter( "manage_{$post_type}_posts_columns", array( $this, 'columns' ), 20 );
			add_action( "manage_{$post_type}_posts_custom_column", array( $this, 'column_data' ), 20, 2 );
		}
		add_action( 'quick_edit_custom_box', array( $this, 'quick_edit_box' ), 10, 2 );
		add_action( 'bulk_edit_custom_box', array( $this, 'bulk_edit_box' ), 10, 2 );
	}

	/**
	 * Make sure a SEO column exists (the Analysis module adds `mgseo`; without it we add our own).
	 *
	 * @param array $columns Columns.
	 * @return array
	 */
	public function columns( array $columns ): array {
		if ( ! isset( $columns['mgseo'] ) ) {
			$columns['mgseo_bulk'] = __( 'SEO', 'mg-seo' );
		}
		return $columns;
	}

	/**
	 * Hidden inline data used to fill the quick edit fields.
	 *
	 * @param string $column  Column.
	 * @param int    $post_id Post id.
	 */
	public function column_data( string $column, int $post_id ): void {
		if ( 'mgseo' !== $column && 'mgseo_bulk' !== $column ) {
			return;
		}
		if ( 'mgseo_bulk' === $column && in_array( 'noindex', (array) Meta::post( $post_id, 'robots', array() ), true ) ) {
			echo '<small class="mgseo-noindex">' . esc_html__( 'No Index', 'mg-seo' ) . '</small>';
		}
		printf(
			'<div class="hidden mgseo-inline-data" data-title="%s" data-description="%s" data-keyword="%s" data-index="%s"></div>',
			esc_attr( (string) Meta::post( $post_id, 'title' ) ),
			esc_attr( (string) Meta::post( $post_id, 'description' ) ),
			esc_attr( (string) Meta::post( $post_id, 'focus_keyword' ) ),
			esc_attr( self::index_state( $post_id ) )
		);
	}

	/**
	 * Explicit index state of a post: '', 'index' or 'noindex'.
	 *
	 * @param int $post_id Post id.
	 */
	public static function index_state( int $post_id ): string {
		$robots = (array) Meta::post( $post_id, 'robots', array() );
		if ( in_array( 'noindex', $robots, true ) ) {
			return 'noindex';
		}
		return in_array( 'index', $robots, true ) ? 'index' : '';
	}

	/**
	 * Quick edit fields.
	 *
	 * @param string $column    Column.
	 * @param string $post_type Post type.
	 */
	public function quick_edit_box( string $column, string $post_type ): void {
		if ( ( 'mgseo' !== $column && 'mgseo_bulk' !== $column ) || ! isset( Context::post_types()[ $post_type ] ) ) {
			return;
		}
		wp_nonce_field( 'mgseo_quick_edit', 'mgseo_quick_nonce' );
		echo '<fieldset class="inline-edit-col-left mgseo-quick-edit"><div class="inline-edit-col"><span class="title inline-edit-categories-label">' . esc_html__( 'SEO', 'mg-seo' ) . '</span>';
		echo '<label><span class="title">' . esc_html__( 'SEO title', 'mg-seo' ) . '</span><span class="input-text-wrap"><input type="text" name="mgseo_quick[title]" class="mgseo-quick-title" value="" /></span></label>';
		echo '<label><span class="title">' . esc_html__( 'Description', 'mg-seo' ) . '</span><span class="input-text-wrap"><textarea name="mgseo_quick[description]" class="mgseo-quick-description" rows="2"></textarea></span></label>';
		echo '<label><span class="title">' . esc_html__( 'Keywords', 'mg-seo' ) . '</span><span class="input-text-wrap"><input type="text" name="mgseo_quick[focus_keyword]" class="mgseo-quick-keyword" value="" /></span></label>';
		if ( current_user_can( 'mgseo_edit_advanced' ) ) {
			echo '<label><span class="title">' . esc_html__( 'Indexing', 'mg-seo' ) . '</span><select name="mgseo_quick[index]" class="mgseo-quick-index">' . self::index_options( false ) . '</select></label>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in index_options().
		}
		echo '</div></fieldset>';
	}

	/**
	 * Bulk edit fields.
	 *
	 * @param string $column    Column.
	 * @param string $post_type Post type.
	 */
	public function bulk_edit_box( string $column, string $post_type ): void {
		if ( ( 'mgseo' !== $column && 'mgseo_bulk' !== $column ) || ! isset( Context::post_types()[ $post_type ] ) || ! current_user_can( 'mgseo_edit_advanced' ) ) {
			return;
		}
		wp_nonce_field( 'mgseo_quick_edit', 'mgseo_quick_nonce' );
		echo '<fieldset class="inline-edit-col-right mgseo-bulk-edit"><div class="inline-edit-col"><label class="inline-edit-status alignleft"><span class="title">' . esc_html__( 'SEO indexing', 'mg-seo' ) . '</span>';
		echo '<select name="mgseo_bulk_index">' . self::index_options( true ) . '</select></label></div></fieldset>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaped in index_options().
	}

	/**
	 * Index select options.
	 *
	 * @param bool $no_change Include a "no change" option.
	 */
	private static function index_options( bool $no_change ): string {
		$options = array();
		if ( $no_change ) {
			$options['-1'] = __( '— No change —', 'mg-seo' );
		}
		$options['']        = __( 'Default', 'mg-seo' );
		$options['index']   = __( 'Index', 'mg-seo' );
		$options['noindex'] = __( 'No Index', 'mg-seo' );
		$html               = '';
		foreach ( $options as $value => $label ) {
			$html .= '<option value="' . esc_attr( (string) $value ) . '">' . esc_html( $label ) . '</option>';
		}
		return $html;
	}

	/**
	 * Save quick edit / bulk edit fields.
	 *
	 * @param int      $post_id Post id.
	 * @param \WP_Post $post    Post.
	 */
	public function save_quick_edit( int $post_id, \WP_Post $post ): void {
		if ( ! isset( $_REQUEST['mgseo_quick_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_REQUEST['mgseo_quick_nonce'] ) ), 'mgseo_quick_edit' ) ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || ! Helpers::can_edit_post_seo( $post_id ) || ! isset( Context::post_types()[ $post->post_type ] ) ) {
			return;
		}
		// Bulk edit (several posts) sends `bulk_edit`; only the indexing select applies there.
		if ( isset( $_REQUEST['bulk_edit'] ) ) {
			$index = isset( $_REQUEST['mgseo_bulk_index'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['mgseo_bulk_index'] ) ) : '-1';
			if ( in_array( $index, array( '', 'index', 'noindex' ), true ) ) {
				self::save_item( $post_id, array( 'index' => $index ) );
			}
			return;
		}
		// Quick edit (single post, inline-save).
		if ( isset( $_POST['mgseo_quick'] ) && is_array( $_POST['mgseo_quick'] ) ) {
			// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- sanitized in save_item().
			$data = wp_unslash( $_POST['mgseo_quick'] );
			$data = array_intersect_key( (array) $data, array_flip( array( 'title', 'description', 'focus_keyword', 'index' ) ) );
			if ( ! current_user_can( 'mgseo_edit_advanced' ) ) {
				unset( $data['index'] );
			}
			self::save_item( $post_id, $data );
		}
	}

	/**
	 * Save SEO fields of one post (only keys present in $data change).
	 *
	 * @param int   $post_id Post id.
	 * @param array $data    title, description, focus_keyword, index ('' | index | noindex), canonical.
	 * @return array|\WP_Error Saved values.
	 */
	public static function save_item( int $post_id, array $data ): array|\WP_Error {
		$post = get_post( $post_id );
		if ( ! $post || ! isset( Context::post_types()[ $post->post_type ] ) ) {
			return new \WP_Error( 'mgseo_bulk_post', __( 'Post not found.', 'mg-seo' ), array( 'status' => 404 ) );
		}
		if ( ! Helpers::can_edit_post_seo( $post_id ) ) {
			return new \WP_Error( 'mgseo_bulk_forbidden', __( 'You cannot edit the SEO data of this post.', 'mg-seo' ), array( 'status' => 403 ) );
		}
		$advanced = current_user_can( 'mgseo_edit_advanced' );
		if ( array_key_exists( 'index', $data ) && ! $advanced ) {
			return new \WP_Error( 'mgseo_bulk_advanced', __( 'You cannot change indexing.', 'mg-seo' ), array( 'status' => 403 ) );
		}
		if ( array_key_exists( 'title', $data ) ) {
			Meta::set_post( $post_id, 'title', sanitize_text_field( (string) $data['title'] ) );
		}
		if ( array_key_exists( 'description', $data ) ) {
			Meta::set_post( $post_id, 'description', sanitize_textarea_field( (string) $data['description'] ) );
		}
		if ( array_key_exists( 'focus_keyword', $data ) ) {
			$keywords = array_filter( array_map( 'trim', explode( ',', sanitize_text_field( (string) $data['focus_keyword'] ) ) ) );
			Meta::set_post( $post_id, 'focus_keyword', implode( ',', $keywords ) );
		}
		if ( array_key_exists( 'index', $data ) ) {
			$index  = sanitize_key( (string) $data['index'] );
			$robots = array_values( array_diff( Helpers::sanitize_robots( Meta::post( $post_id, 'robots', array() ) ), array( 'index', 'noindex' ) ) );
			if ( in_array( $index, array( 'index', 'noindex' ), true ) ) {
				array_unshift( $robots, $index );
			}
			Meta::set_post( $post_id, 'robots', $robots );
		}
		if ( array_key_exists( 'robots', $data ) && $advanced ) {
			Meta::set_post( $post_id, 'robots', Helpers::sanitize_robots( $data['robots'] ) );
		}
		if ( array_key_exists( 'canonical', $data ) && $advanced ) {
			Meta::set_post( $post_id, 'canonical', esc_url_raw( (string) $data['canonical'] ) );
		}
		/**
		 * Fires after the bulk editor saved a post's SEO fields.
		 *
		 * @param int   $post_id Post id.
		 * @param array $data    Submitted data.
		 */
		do_action( 'mgseo/bulk_saved', $post_id, $data );
		return array(
			'id'            => $post_id,
			'title'         => (string) Meta::post( $post_id, 'title' ),
			'description'   => (string) Meta::post( $post_id, 'description' ),
			'focus_keyword' => (string) Meta::post( $post_id, 'focus_keyword' ),
			'index'         => self::index_state( $post_id ),
		);
	}

	/**
	 * REST routes.
	 *
	 * @param string $ns Namespace.
	 */
	public function routes( string $ns ): void {
		register_rest_route(
			$ns,
			'/bulk/save',
			array(
				'methods'             => 'POST',
				'permission_callback' => static fn(): bool => current_user_can( self::CAP ) && current_user_can( 'mgseo_edit_meta' ),
				'args'                => array(
					'items' => array(
						'type'     => 'array',
						'required' => true,
						'maxItems' => 200,
						'items'    => array(
							'type'       => 'object',
							'properties' => array(
								'id'            => array(
									'type'     => 'integer',
									'required' => true,
								),
								'title'         => array( 'type' => 'string' ),
								'description'   => array( 'type' => 'string' ),
								'focus_keyword' => array( 'type' => 'string' ),
								'index'         => array(
									'type' => 'string',
									'enum' => array( '', 'index', 'noindex' ),
								),
							),
						),
					),
				),
				'callback'            => static function ( \WP_REST_Request $request ) {
					$out = array();
					foreach ( (array) $request['items'] as $item ) {
						$id     = (int) ( $item['id'] ?? 0 );
						$fields = array_intersect_key( (array) $item, array_flip( array( 'title', 'description', 'focus_keyword', 'index' ) ) );
						$result = self::save_item( $id, $fields );
						$out[]  = is_wp_error( $result )
							? array(
								'id'    => $id,
								'error' => $result->get_error_message(),
							)
							: $result;
					}
					return rest_ensure_response( array( 'items' => $out ) );
				},
			)
		);
	}
}
