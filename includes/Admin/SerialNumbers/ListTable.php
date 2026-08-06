<?php
namespace SerialNumberForWooCommerce\Admin\SerialNumbers;

defined( 'ABSPATH' ) || exit;

if ( ! class_exists( '\WP_List_Table' ) ) {
	require_once ABSPATH . 'wp-admin/includes/class-wp-list-table.php';
}

/**
 * Renders the Serial Numbers list + search UI.
 */
final class ListTable extends \WP_List_Table {

	public function __construct() {
		parent::__construct(
			array(
				'singular' => 'serial_number',
				'plural'   => 'serial_numbers',
				'ajax'     => false,
			)
		);
	}

	public function get_columns(): array {
		return array(
			'cb'            => '<input type="checkbox" />',
			'serial_number' => __( 'Serial Number', 'serial-number-for-woocommerce' ),
			'status'        => __( 'Status', 'serial-number-for-woocommerce' ),
			'product'       => __( 'Product', 'serial-number-for-woocommerce' ),
			'order'         => __( 'Order', 'serial-number-for-woocommerce' ),
			'created_at'    => __( 'Created On', 'serial-number-for-woocommerce' ),
			'expires_at'    => __( 'Expires On', 'serial-number-for-woocommerce' ),
		);
	}

	protected function get_bulk_actions(): array {
		return array(
			'bulk_delete' => __( 'Delete', 'serial-number-for-woocommerce' ),
		);
	}

	protected function get_sortable_columns(): array {
		return array(
			'expires_at' => array( 'expires_at', false ),
		);
	}

	public function column_cb( $item ): string {
		return sprintf( '<input type="checkbox" name="serial_ids[]" value="%d" />', (int) $item->id );
	}

	public function prepare_items(): void {
		$per_page     = 20;
		$current_page = $this->get_pagenum();
		$search       = isset( $_REQUEST['s'] ) ? sanitize_text_field( wp_unslash( $_REQUEST['s'] ) ) : '';

		$filters = array(
			'no_product' => isset( $_REQUEST['snw_filter_no_product'] ) ? 1 : 0,
			'product_id' => isset( $_REQUEST['snw_filter_product_id'] ) ? absint( $_REQUEST['snw_filter_product_id'] ) : 0,
			'status'     => isset( $_REQUEST['snw_filter_status'] ) ? sanitize_key( wp_unslash( $_REQUEST['snw_filter_status'] ) ) : '',
		);

		$orderby = isset( $_REQUEST['orderby'] ) ? sanitize_key( wp_unslash( $_REQUEST['orderby'] ) ) : '';
		$order   = isset( $_REQUEST['order'] ) ? sanitize_key( wp_unslash( $_REQUEST['order'] ) ) : '';

		$this->_column_headers = array( $this->get_columns(), array(), $this->get_sortable_columns() );

		$result = Repository::search( $search, $per_page, $current_page, $filters, $orderby, $order );

		$this->items = $result['items'];

		$this->set_pagination_args(
			array(
				'total_items' => $result['total'],
				'per_page'    => $per_page,
				'total_pages' => (int) ceil( $result['total'] / $per_page ),
			)
		);
	}

	public function no_items(): void {
		esc_html_e( 'No serial numbers found.', 'serial-number-for-woocommerce' );
	}

	protected function get_default_primary_column_name(): string {
		return 'serial_number';
	}

	public function column_serial_number( $item ): string {
		$edit_url = add_query_arg(
			array(
				'page'   => 'serial-number-for-woocommerce',
				'action' => 'edit',
				'id'     => $item->id,
			),
			admin_url( 'admin.php' )
		);

		$delete_url = wp_nonce_url(
			add_query_arg(
				array(
					'page'   => 'serial-number-for-woocommerce',
					'action' => 'delete',
					'id'     => $item->id,
				),
				admin_url( 'admin.php' )
			),
			'snw_delete_serial_number_' . $item->id
		);

		$actions = array(
			'edit'   => sprintf( '<a href="%s">%s</a>', esc_url( $edit_url ), esc_html__( 'Edit', 'serial-number-for-woocommerce' ) ),
			'delete' => sprintf(
				'<a href="%s" onclick="return confirm(%s);">%s</a>',
				esc_url( $delete_url ),
				esc_attr( wp_json_encode( __( 'Delete this serial number?', 'serial-number-for-woocommerce' ) ) ),
				esc_html__( 'Delete', 'serial-number-for-woocommerce' )
			),
		);

		return esc_html( $item->serial_number ) . $this->row_actions( $actions );
	}

	public function column_default( $item, $column_name ) {
		switch ( $column_name ) {
			case 'status':
				return esc_html( Status::label( $item->status ) );

			case 'product':
				if ( ! $item->product_id ) {
					return '&mdash;';
				}
				$product = wc_get_product( $item->product_id );
				return $product ? esc_html( $product->get_name() ) : '&mdash;';

			case 'order':
				if ( ! $item->order_id ) {
					return '&mdash;';
				}
				$order = wc_get_order( $item->order_id );
				return $order ? sprintf( '<a href="%s">#%s</a>', esc_url( $order->get_edit_order_url() ), esc_html( $order->get_order_number() ) ) : '&mdash;';

			case 'created_at':
				return $item->created_at ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $item->created_at ) ) ) : '&mdash;';

			case 'expires_at':
				return $item->expires_at ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $item->expires_at ) ) ) : '&mdash;';

			default:
				return '';
		}
	}
}
