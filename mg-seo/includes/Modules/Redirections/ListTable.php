<?php
/**
 * Redirections list table.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Redirections;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table for redirections.
 */
final class ListTable extends \WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'redirection',
				'plural'   => 'redirections',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Current filters from the request.
	 *
	 * @return array<string, mixed>
	 */
	public static function filters(): array {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters only.
		return array(
			'status'   => isset( $_GET['status'] ) ? sanitize_key( wp_unslash( $_GET['status'] ) ) : '',
			'type'     => isset( $_GET['type'] ) ? absint( $_GET['type'] ) : 0,
			'category' => isset( $_GET['category'] ) ? sanitize_text_field( wp_unslash( $_GET['category'] ) ) : '',
			'search'   => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'orderby'  => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'id',
			'order'    => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc',
		);
		// phpcs:enable
	}

	/**
	 * Load items.
	 */
	public function prepare_items(): void {
		$per_page    = $this->get_items_per_page( 'mgseo_redirections_per_page', 20 );
		$args        = self::filters() + array(
			'per_page' => $per_page,
			'page'     => $this->get_pagenum(),
		);
		$this->items = Repository::all( $args );
		$total       = Repository::count( $args );
		$this->set_pagination_args(
			array(
				'total_items' => $total,
				'per_page'    => $per_page,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'sources' );
	}

	/**
	 * Columns.
	 */
	public function get_columns(): array {
		return array(
			'cb'            => '<input type="checkbox" />',
			'sources'       => __( 'From', 'mg-seo' ),
			'url_to'        => __( 'To', 'mg-seo' ),
			'type'          => __( 'Type', 'mg-seo' ),
			'hits'          => __( 'Hits', 'mg-seo' ),
			'last_accessed' => __( 'Last accessed', 'mg-seo' ),
			'category'      => __( 'Category', 'mg-seo' ),
		);
	}

	/**
	 * Sortable columns.
	 */
	protected function get_sortable_columns(): array {
		return array(
			'url_to'        => array( 'url_to', false ),
			'type'          => array( 'type', false ),
			'hits'          => array( 'hits', true ),
			'last_accessed' => array( 'last_accessed', true ),
			'category'      => array( 'category', false ),
		);
	}

	/**
	 * Bulk actions.
	 */
	protected function get_bulk_actions(): array {
		return array(
			'activate'   => __( 'Activate', 'mg-seo' ),
			'deactivate' => __( 'Deactivate', 'mg-seo' ),
			'delete'     => __( 'Delete', 'mg-seo' ),
		);
	}

	/**
	 * Status views.
	 */
	protected function get_views(): array {
		$current = self::filters()['status'];
		$base    = admin_url( 'admin.php?page=' . Admin::PAGE );
		$views   = array();
		foreach (
			array(
				''         => __( 'All', 'mg-seo' ),
				'active'   => __( 'Active', 'mg-seo' ),
				'inactive' => __( 'Inactive', 'mg-seo' ),
			) as $status => $label
		) {
			$count                                     = Repository::count( array( 'status' => $status ) );
			$url                                       = '' === $status ? $base : add_query_arg( 'status', $status, $base );
			$views[ '' === $status ? 'all' : $status ] = sprintf(
				'<a href="%s"%s>%s <span class="count">(%d)</span></a>',
				esc_url( $url ),
				$current === $status ? ' class="current" aria-current="page"' : '',
				esc_html( $label ),
				$count
			);
		}
		return $views;
	}

	/**
	 * Filters above the table.
	 *
	 * @param string $which top|bottom.
	 */
	protected function extra_tablenav( $which ): void {
		if ( 'top' !== $which ) {
			return;
		}
		$filters = self::filters();
		echo '<div class="alignleft actions">';
		echo '<label class="screen-reader-text" for="mgseo-filter-type">' . esc_html__( 'Filter by type', 'mg-seo' ) . '</label>';
		echo '<select name="type" id="mgseo-filter-type"><option value="">' . esc_html__( 'All types', 'mg-seo' ) . '</option>';
		foreach ( Module::type_labels() as $code => $label ) {
			printf( '<option value="%s"%s>%s</option>', esc_attr( (string) $code ), selected( (int) $code, $filters['type'], false ), esc_html( $label ) );
		}
		echo '</select>';
		$categories = Repository::categories();
		if ( $categories ) {
			echo '<label class="screen-reader-text" for="mgseo-filter-category">' . esc_html__( 'Filter by category', 'mg-seo' ) . '</label>';
			echo '<select name="category" id="mgseo-filter-category"><option value="">' . esc_html__( 'All categories', 'mg-seo' ) . '</option>';
			foreach ( $categories as $category ) {
				printf( '<option value="%s"%s>%s</option>', esc_attr( $category ), selected( $category, $filters['category'], false ), esc_html( $category ) );
			}
			echo '</select>';
		}
		submit_button( __( 'Filter', 'mg-seo' ), '', 'filter_action', false );
		echo '</div>';
	}

	/**
	 * Checkbox column.
	 *
	 * @param array $item Row.
	 */
	protected function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="ids[]" value="%d" />', (int) $item['id'] );
	}

	/**
	 * Sources column with row actions.
	 *
	 * @param array $item Row.
	 */
	protected function column_sources( array $item ): string {
		$labels = Admin::comparison_labels();
		$lines  = array();
		foreach ( $item['sources'] as $source ) {
			$lines[] = '<code>' . esc_html( '' === $source['pattern'] ? '/' : $source['pattern'] ) . '</code>'
				. ( 'exact' !== $source['comparison'] ? ' <span class="mgseo-rd-cmp">' . esc_html( $labels[ $source['comparison'] ] ?? $source['comparison'] ) . '</span>' : '' )
				. ( ! empty( $source['ignore_case'] ) ? ' <span class="mgseo-rd-cmp">' . esc_html__( 'ignore case', 'mg-seo' ) . '</span>' : '' );
		}
		$html = implode( '<br />', $lines );
		if ( 'inactive' === $item['status'] ) {
			$html .= ' — <span class="post-state">' . esc_html__( 'Inactive', 'mg-seo' ) . '</span>';
		}
		$id      = (int) $item['id'];
		$base    = admin_url( 'admin.php?page=' . Admin::PAGE );
		$toggle  = 'active' === $item['status'] ? 'deactivate' : 'activate';
		$actions = array(
			'edit'  => sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					add_query_arg(
						array(
							'action' => 'edit',
							'id'     => $id,
						),
						$base
					)
				),
				esc_html__( 'Edit', 'mg-seo' )
			),
			$toggle => sprintf( '<a href="%s">%s</a>', esc_url( Admin::row_action_url( Admin::PAGE, $toggle, $id ) ), 'activate' === $toggle ? esc_html__( 'Activate', 'mg-seo' ) : esc_html__( 'Deactivate', 'mg-seo' ) ),
			'trash' => sprintf( '<a href="%s" class="submitdelete mgseo-confirm" data-confirm="%s">%s</a>', esc_url( Admin::row_action_url( Admin::PAGE, 'delete', $id ) ), esc_attr__( 'Delete this redirection?', 'mg-seo' ), esc_html__( 'Delete', 'mg-seo' ) ),
		);
		return $html . $this->row_actions( $actions );
	}

	/**
	 * Destination column.
	 *
	 * @param array $item Row.
	 */
	protected function column_url_to( array $item ): string {
		if ( Repository::is_gone_type( (int) $item['type'] ) ) {
			return '<span aria-hidden="true">—</span>';
		}
		return '<code>' . esc_html( $item['url_to'] ) . '</code>';
	}

	/**
	 * Default columns.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 */
	protected function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'type':
				return esc_html( (string) $item['type'] );
			case 'hits':
				return esc_html( number_format_i18n( (int) $item['hits'] ) );
			case 'last_accessed':
				return $item['last_accessed'] ? esc_html( get_date_from_gmt( $item['last_accessed'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) ) : '—';
			case 'category':
				return esc_html( $item['category'] );
		}
		return '';
	}

	/**
	 * Empty state.
	 */
	public function no_items(): void {
		esc_html_e( 'No redirections found.', 'mg-seo' );
	}
}
