<?php
/**
 * 404 log list table.
 *
 * @package MGSEO
 */

namespace MGSEO\Modules\Redirections;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * WP_List_Table for the 404 log.
 */
final class LogTable extends \WP_List_Table {

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct(
			array(
				'singular' => '404',
				'plural'   => '404s',
				'ajax'     => false,
			)
		);
	}

	/**
	 * Load items.
	 */
	public function prepare_items(): void {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- list filters only.
		$args = array(
			'search'  => isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '',
			'orderby' => isset( $_GET['orderby'] ) ? sanitize_key( wp_unslash( $_GET['orderby'] ) ) : 'last_seen',
			'order'   => isset( $_GET['order'] ) ? sanitize_key( wp_unslash( $_GET['order'] ) ) : 'desc',
		);
		// phpcs:enable
		$per_page    = $this->get_items_per_page( 'mgseo_404_per_page', 30 );
		$this->items = Log404::all(
			$args + array(
				'per_page' => $per_page,
				'page'     => $this->get_pagenum(),
			)
		);
		$this->set_pagination_args(
			array(
				'total_items' => Log404::count( $args ),
				'per_page'    => $per_page,
			)
		);
		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns(), 'uri' );
	}

	/**
	 * Columns.
	 */
	public function get_columns(): array {
		return array(
			'cb'         => '<input type="checkbox" />',
			'uri'        => __( 'URI', 'mg-seo' ),
			'hits'       => __( 'Hits', 'mg-seo' ),
			'last_seen'  => __( 'Last hit', 'mg-seo' ),
			'referer'    => __( 'Referer', 'mg-seo' ),
			'user_agent' => __( 'User agent', 'mg-seo' ),
			'ip'         => __( 'IP (anonymised)', 'mg-seo' ),
		);
	}

	/**
	 * Sortable columns.
	 */
	protected function get_sortable_columns(): array {
		return array(
			'uri'       => array( 'uri', false ),
			'hits'      => array( 'hits', true ),
			'last_seen' => array( 'last_seen', true ),
		);
	}

	/**
	 * Bulk actions.
	 */
	protected function get_bulk_actions(): array {
		return array(
			'redirect' => __( 'Redirect', 'mg-seo' ),
			'delete'   => __( 'Delete', 'mg-seo' ),
		);
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
	 * URI column with row actions.
	 *
	 * @param array $item Row.
	 */
	protected function column_uri( array $item ): string {
		$id      = (int) $item['id'];
		$actions = array(
			'redirect' => sprintf(
				'<a href="%s">%s</a>',
				esc_url(
					add_query_arg(
						array(
							'page'    => Admin::PAGE,
							'action'  => 'new',
							'log_ids' => $id,
						),
						admin_url( 'admin.php' )
					)
				),
				esc_html__( 'Redirect', 'mg-seo' )
			),
			'view'     => sprintf( '<a href="%s" target="_blank" rel="noopener noreferrer">%s</a>', esc_url( home_url( $item['uri'] ) ), esc_html__( 'View', 'mg-seo' ) ),
			'trash'    => sprintf( '<a href="%s" class="submitdelete">%s</a>', esc_url( Admin::row_action_url( Admin::LOG_PAGE, 'delete', $id ) ), esc_html__( 'Delete', 'mg-seo' ) ),
		);
		return '<code>' . esc_html( $item['uri'] ) . '</code>' . $this->row_actions( $actions );
	}

	/**
	 * Default columns.
	 *
	 * @param array  $item        Row.
	 * @param string $column_name Column.
	 */
	protected function column_default( $item, $column_name ): string {
		switch ( $column_name ) {
			case 'hits':
				return esc_html( number_format_i18n( (int) $item['hits'] ) );
			case 'last_seen':
				return esc_html( get_date_from_gmt( $item['last_seen'], get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ) );
			case 'referer':
				return '' !== $item['referer'] ? '<span class="mgseo-rd-break">' . esc_html( $item['referer'] ) . '</span>' : '—';
			case 'user_agent':
				return '' !== $item['user_agent'] ? '<span class="mgseo-rd-break">' . esc_html( $item['user_agent'] ) . '</span>' : '—';
			case 'ip':
				return '' !== $item['ip'] ? esc_html( $item['ip'] ) : '—';
		}
		return '';
	}

	/**
	 * Empty state.
	 */
	public function no_items(): void {
		esc_html_e( 'No 404 errors logged yet.', 'mg-seo' );
	}
}
