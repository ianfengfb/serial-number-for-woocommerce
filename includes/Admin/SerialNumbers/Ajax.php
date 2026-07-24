<?php
namespace SerialNumberForWooCommerce\Admin\SerialNumbers;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX search endpoints backing the Product/Order select fields on the Add New form.
 */
final class Ajax {

	public function __construct() {
		add_action( 'wp_ajax_snw_search_products', array( $this, 'search_products' ) );
		add_action( 'wp_ajax_snw_search_orders', array( $this, 'search_orders' ) );
		add_action( 'wp_ajax_snw_generate_serial', array( $this, 'generate_serial' ) );
	}

	public function generate_serial(): void {
		$this->check_request();

		wp_send_json_success( array( 'serial_number' => Generator::generate() ) );
	}

	private function check_request(): void {
		check_ajax_referer( 'snw_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array(), 403 );
		}
	}

	public function search_products(): void {
		$this->check_request();

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';

		$products = wc_get_products(
			array(
				's'      => $term,
				'limit'  => 20,
				'status' => 'publish',
			)
		);

		$results = array();
		foreach ( $products as $product ) {
			$results[] = self::format_product_option( $product );
		}

		wp_send_json_success( $results );
	}

	public function search_orders(): void {
		$this->check_request();

		$term = isset( $_GET['term'] ) ? sanitize_text_field( wp_unslash( $_GET['term'] ) ) : '';

		$order_ids = wc_get_orders(
			array(
				'return' => 'ids',
				'limit'  => 20,
				's'      => $term,
			)
		);

		$results = array();
		foreach ( $order_ids as $order_id ) {
			$order = wc_get_order( $order_id );

			if ( ! $order ) {
				continue;
			}

			$results[] = self::format_order_option( $order );
		}

		wp_send_json_success( $results );
	}

	public static function format_product_option( \WC_Product $product ): array {
		return array(
			'id'   => $product->get_id(),
			'text' => sprintf( '%s (#%d)', $product->get_name(), $product->get_id() ),
		);
	}

	public static function format_order_option( \WC_Order $order ): array {
		return array(
			'id'   => $order->get_id(),
			'text' => sprintf( '#%s %s', $order->get_order_number(), trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) ),
		);
	}
}
