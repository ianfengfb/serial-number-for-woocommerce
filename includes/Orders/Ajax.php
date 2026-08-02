<?php
namespace SerialNumberForWooCommerce\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Free tier: backs the "Add Serial Number" control on the admin order edit
 * screen (see ItemDisplay), for line items that never got auto-assigned —
 * e.g. their product wasn't serial-number-enabled yet at checkout time.
 */
final class Ajax {

	public function __construct() {
		add_action( 'wp_ajax_snw_add_order_item_serial', array( $this, 'add_order_item_serial' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets(): void {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$order_screen_id = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

		if ( $screen->id !== $order_screen_id && 'shop_order' !== $screen->id ) {
			return;
		}

		wp_enqueue_script(
			'snw-order-item-serials',
			SNW_PLUGIN_URL . 'assets/js/order-item-serials.js',
			array( 'jquery' ),
			SNW_VERSION,
			true
		);

		wp_localize_script(
			'snw-order-item-serials',
			'SNWOrderItemSerials',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'snw_admin' ),
			)
		);
	}

	public function add_order_item_serial(): void {
		check_ajax_referer( 'snw_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'serial-number-for-woocommerce' ) ), 403 );
		}

		$order_id      = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$item_id       = isset( $_POST['item_id'] ) ? absint( $_POST['item_id'] ) : 0;
		$serial_number = isset( $_POST['serial_number'] ) ? sanitize_text_field( wp_unslash( $_POST['serial_number'] ) ) : '';

		$order = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'serial-number-for-woocommerce' ) ) );
		}

		// $load_from_db = false scopes the lookup to this order's own items
		// collection, so an item_id belonging to a different order is rejected
		// rather than loaded regardless of ownership.
		$item = $order->get_item( $item_id, false );

		if ( ! $item instanceof \WC_Order_Item_Product ) {
			wp_send_json_error( array( 'message' => __( 'Order item not found.', 'serial-number-for-woocommerce' ) ) );
		}

		$result = Assigner::add_manual_serial( $item, $serial_number );

		if ( ! $result['success'] ) {
			wp_send_json_error( array( 'message' => $result['message'] ) );
		}

		wp_send_json_success( array( 'message' => $result['message'] ) );
	}
}
