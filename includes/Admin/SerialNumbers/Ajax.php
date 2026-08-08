<?php
namespace SerialNumberForWooCommerce\Admin\SerialNumbers;

use SerialNumberForWooCommerce\Licensing;
use SerialNumberForWooCommerce\Pro\CustomRules\CustomRules;
use SerialNumberForWooCommerce\Pro\StockSync\StockSync;

defined( 'ABSPATH' ) || exit;

/**
 * AJAX search endpoints backing the Product/Order select fields on the Add New form.
 */
final class Ajax {

	public function __construct() {
		add_action( 'wp_ajax_snw_search_products', array( $this, 'search_products' ) );
		add_action( 'wp_ajax_snw_search_orders', array( $this, 'search_orders' ) );
		add_action( 'wp_ajax_snw_generate_serial', array( $this, 'generate_serial' ) );
		add_action( 'wp_ajax_snw_import_serials', array( $this, 'import_serials' ) );
	}

	/**
	 * Backs the Add/Edit form's "Generate" button. When the form's own
	 * Product field is already filled in, uses that product's own custom
	 * rule (Pro) instead of the global one — same rule the product tab's
	 * own "Bulk generate" button would use, just reached from the other
	 * direction (this form has no product yet to save a rule against, it's
	 * only ever reading one that already exists).
	 */
	public function generate_serial(): void {
		$this->check_request();

		$product_id = isset( $_REQUEST['product_id'] ) ? absint( $_REQUEST['product_id'] ) : 0;
		$overrides  = ( $product_id && Licensing::is_pro_active() ) ? CustomRules::resolve_overrides( $product_id ) : array();

		wp_send_json_success( array( 'serial_number' => Generator::generate( $overrides ) ) );
	}

	/**
	 * Creates serials from the Serial Number tab's bulk-add textarea,
	 * connected to the given product. Backs the "Add to Pool" button.
	 */
	public function import_serials(): void {
		$this->check_request();

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$raw        = isset( $_POST['serials'] ) ? (string) wp_unslash( $_POST['serials'] ) : '';

		if ( ! $product_id || ! wc_get_product( $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product.', 'serial-number-for-woocommerce' ) ) );
		}

		$result = Repository::import_for_product( $product_id, $raw );

		$stock_quantity = ( $result['created'] && Licensing::is_pro_active() ) ? StockSync::sync( $product_id ) : null;

		wp_send_json_success(
			array(
				'message'        => self::import_summary_message( $result ),
				'created'        => $result['created'],
				'skipped'        => $result['skipped'],
				'stock_quantity' => $stock_quantity,
			)
		);
	}

	private static function import_summary_message( array $result ): string {
		$message = sprintf(
			/* translators: %d: number of serial numbers created */
			_n( '%d serial number added.', '%d serial numbers added.', $result['created'], 'serial-number-for-woocommerce' ),
			$result['created']
		);

		if ( ! empty( $result['skipped'] ) ) {
			$message .= ' ' . sprintf(
				/* translators: %d: number of duplicate serial numbers skipped */
				_n( '%d duplicate skipped.', '%d duplicates skipped.', count( $result['skipped'] ), 'serial-number-for-woocommerce' ),
				count( $result['skipped'] )
			);
		}

		return $message;
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
