<?php
/**
 * Bulk editor list table.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Bulk;

use MGSEO\Meta;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Posts of one post type with inline SEO fields.
 */
final class ListTable extends \WP_List_Table {

	/**
	 * Post type.
	 *
	 * @var string
	 */
	private string $post_type;

	/**
	 * Constructor.
	 *
	 * @param string $post_type Post type.
	 */
	public function __construct( string $post_type ) {
		$this->post_type = $post_type;
		parent::__construct(
			array(
				'singular' => 'mgseo-bulk-item',
				'plural'   => 'mgseo-bulk-items',
				'ajax'     => false,
				'screen'   => 'mgseo-bulk',
			)
		);
	}

	/**
	 * Filters.
	 *
	 * @return array<string, string>
	 */
	public static function filters(): array {
		return array(
			''                    => __( 'All', 'mg-seo' ),
			'missing_title'       => __( 'Missing SEO title', 'mg-seo' ),
			'missing_description' => __( 'Missing description', 'mg-seo' ),
			'missing_keyword'     => __( 'Missing focus keyword', 'mg-seo' ),
			'noindex'             => __( 'No Index', 'mg-seo' ),
			'score_bad'           => __( 'Score below 50', 'mg-seo' ),
			'score_ok'            => __( 'Score 50–79', 'mg-seo' ),
			'score_good'          => __( 'Score 80+', 'mg-seo' ),
			'score_none'          => __( 'No score', 'mg-seo' ),
		);
	}

	/**
	 * Query args for the current request.
	 *
	 * @param string $post_type Post type.
	 * @param string $filter    Filter.
	 * @param string $search    Search.
	 * @return array<string, mixed>
	 */
	public static function query_args( string $post_type, string $filter, string $search ): array {
		$args   = array(
			'post_type'     => $post_type,
			'post_status'   => array( 'publish', 'draft', 'pending', 'future', 'private' ),
			'orderby'       => 'date',
			'order'         => 'DESC',
			's'             => $search,
			'no_found_rows' => false,
		);
		$object = get_post_type_object( $post_type );
		if ( $object && ! current_user_can( $object->cap->edit_others_posts ) ) {
			$args['author'] = get_current_user_id();
		}
		$key  = static fn( string $k ) => Meta::PREFIX . $k;
		$meta = match ( $filter ) {
			'missing_title'       => array( self::missing( $key( 'title' ) ) ),
			'missing_description' => array( self::missing( $key( 'description' ) ) ),
			'missing_keyword'     => array( self::missing( $key( 'focus_keyword' ) ) ),
			'noindex'             => array(
				array(
					'key'     => $key( 'robots' ),
					'value'   => '"noindex"',
					'compare' => 'LIKE',
				),
			),
			'score_bad'           => array( self::score( 0, 49 ) ),
			'score_ok'            => array( self::score( 50, 79 ) ),
			'score_good'          => array( self::score( 80, 100 ) ),
			'score_none'          => array(
				array(
					'key'     => $key( 'score' ),
					'compare' => 'NOT EXISTS',
				),
			),
			default               => array(),
		};
		if ( $meta ) {
			$args['meta_query'] = $meta; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- admin-only filter.
		}
		return $args;
	}

	/**
	 * Meta query: key missing or empty.
	 *
	 * @param string $key Meta key.
	 * @return array
	 */
	private static function missing( string $key ): array {
		return array(
			'relation' => 'OR',
			array(
				'key'     => $key,
				'compare' => 'NOT EXISTS',
			),
			array(
				'key'   => $key,
				'value' => '',
			),
		);
	}

	/**
	 * Meta query: score range.
	 *
	 * @param int $min Min.
	 * @param int $max Max.
	 * @return array
	 */
	private static function score( int $min, int $max ): array {
		return array(
			'key'     => Meta::PREFIX . 'score',
			'value'   => array( $min, $max ),
			'compare' => 'BETWEEN',
			'type'    => 'NUMERIC',
		);
	}

	/**
	 * Columns.
	 */
	public function get_columns(): array {
		return array(
			'post'          => __( 'Post', 'mg-seo' ),
			'seo_title'     => __( 'SEO title', 'mg-seo' ),
			'description'   => __( 'Meta description', 'mg-seo' ),
			'focus_keyword' => __( 'Focus keywords', 'mg-seo' ),
			'index'         => __( 'Indexing', 'mg-seo' ),
			'score'         => __( 'Score', 'mg-seo' ),
			'actions'       => '<span class="screen-reader-text">' . esc_html__( 'Actions', 'mg-seo' ) . '</span>',
		);
	}

	/**
	 * Load items.
	 */
	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- read-only list filters.
		$filter = isset( $_GET['filter'] ) ? sanitize_key( wp_unslash( $_GET['filter'] ) ) : '';
		$search = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		// phpcs:enable
		$per_page              = 20;
		$args                  = self::query_args( $this->post_type, isset( self::filters()[ $filter ] ) ? $filter : '', $search );
		$args                 += array(
			'posts_per_page' => $per_page,
			'paged'          => $this->get_pagenum(),
		);
		$query                 = new \WP_Query( $args );
		$this->items           = $query->posts;
		$this->_column_headers = array( $this->get_columns(), array(), array() );
		$this->set_pagination_args(
			array(
				'total_items' => (int) $query->found_posts,
				'per_page'    => $per_page,
			)
		);
	}

	/**
	 * Row with data attributes.
	 *
	 * @param \WP_Post $item Post.
	 */
	public function single_row( $item ): void {
		printf( '<tr class="mgseo-bulk-row" data-id="%d">', (int) $item->ID );
		$this->single_row_columns( $item );
		echo '</tr>';
	}

	/**
	 * Default column.
	 *
	 * @param \WP_Post $item        Post.
	 * @param string   $column_name Column.
	 */
	public function column_default( $item, $column_name ): string {
		$id = (int) $item->ID;
		switch ( $column_name ) {
			case 'post':
				$edit  = get_edit_post_link( $id );
				$title = get_the_title( $item );
				$title = '' !== $title ? $title : __( '(no title)', 'mg-seo' );
				$out   = '<strong>' . ( $edit ? '<a href="' . esc_url( $edit ) . '">' . esc_html( $title ) . '</a>' : esc_html( $title ) ) . '</strong>';
				$out  .= '<br /><small><a href="' . esc_url( (string) get_permalink( $item ) ) . '" target="_blank" rel="noopener">' . esc_html( wp_make_link_relative( (string) get_permalink( $item ) ) ) . '</a></small>';
				if ( 'publish' !== $item->post_status ) {
					$out .= ' <small>(' . esc_html( (string) get_post_status_object( $item->post_status )?->label ) . ')</small>';
				}
				return $out;
			case 'seo_title':
				return sprintf(
					'<label class="screen-reader-text" for="mgseo-bt-%1$d">%2$s</label><input type="text" id="mgseo-bt-%1$d" class="large-text mgseo-bulk-field" data-field="title" value="%3$s" placeholder="%4$s" />',
					$id,
					esc_html__( 'SEO title', 'mg-seo' ),
					esc_attr( (string) Meta::post( $id, 'title' ) ),
					esc_attr( \MGSEO\Modules\Titles\Module::post_title_template( $item ) )
				);
			case 'description':
				return sprintf(
					'<label class="screen-reader-text" for="mgseo-bd-%1$d">%2$s</label><textarea id="mgseo-bd-%1$d" class="large-text mgseo-bulk-field" data-field="description" rows="2">%3$s</textarea>',
					$id,
					esc_html__( 'Meta description', 'mg-seo' ),
					esc_textarea( (string) Meta::post( $id, 'description' ) )
				);
			case 'focus_keyword':
				return sprintf(
					'<label class="screen-reader-text" for="mgseo-bk-%1$d">%2$s</label><input type="text" id="mgseo-bk-%1$d" class="regular-text mgseo-bulk-field" data-field="focus_keyword" value="%3$s" />',
					$id,
					esc_html__( 'Focus keywords', 'mg-seo' ),
					esc_attr( (string) Meta::post( $id, 'focus_keyword' ) )
				);
			case 'index':
				$state = Module::index_state( $id );
				$opts  = array(
					''        => __( 'Default', 'mg-seo' ),
					'index'   => __( 'Index', 'mg-seo' ),
					'noindex' => __( 'No Index', 'mg-seo' ),
				);
				$html  = '<label class="screen-reader-text" for="mgseo-bi-' . $id . '">' . esc_html__( 'Indexing', 'mg-seo' ) . '</label><select id="mgseo-bi-' . $id . '" class="mgseo-bulk-field" data-field="index"' . disabled( ! current_user_can( 'mgseo_edit_advanced' ), true, false ) . '>';
				foreach ( $opts as $value => $label ) {
					$html .= '<option value="' . esc_attr( $value ) . '"' . selected( $state, $value, false ) . '>' . esc_html( $label ) . '</option>';
				}
				return $html . '</select>';
			case 'score':
				$score = Meta::post( $id, 'score', '' );
				if ( '' === $score ) {
					return '<span class="mgseo-score is-none">' . esc_html__( 'N/A', 'mg-seo' ) . '</span>';
				}
				$class = (int) $score >= 80 ? 'is-good' : ( (int) $score >= 50 ? 'is-ok' : 'is-bad' );
				return '<span class="mgseo-score ' . esc_attr( $class ) . '">' . esc_html( (string) (int) $score ) . '</span>';
			case 'actions':
				return '<button type="button" class="button mgseo-bulk-save-row">' . esc_html__( 'Save', 'mg-seo' ) . '</button><span class="mgseo-bulk-status" aria-live="polite"></span>';
		}
		return '';
	}

	/**
	 * Empty message.
	 */
	public function no_items(): void {
		esc_html_e( 'No posts found.', 'mg-seo' );
	}
}
