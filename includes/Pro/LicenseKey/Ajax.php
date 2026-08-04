<?php
namespace SerialNumberForWooCommerce\Pro\LicenseKey;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;
use SerialNumberForWooCommerce\Orders\Assigner;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: backs the "Activate" button CustomerActivation renders on the My
 * Account order view, for license products whose activation trigger is
 * 'manual'. A logged-in-only AJAX action (not nopriv) since manual
 * activation is only ever offered on an account page in the first place.
 */
final class Ajax {

	public function __construct() {
		add_action( 'wp_ajax_snw_activate_license', array( $this, 'activate_license' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	public function enqueue_assets(): void {
		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'view-order' ) ) {
			return;
		}

		wp_enqueue_script(
			'snw-license-activation',
			SNW_PLUGIN_URL . 'assets/pro/js/license-activation.js',
			array( 'jquery' ),
			SNW_VERSION,
			true
		);

		wp_localize_script(
			'snw-license-activation',
			'SNWLicenseActivation',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'snw_license_activation' ),
			)
		);
	}

	public function activate_license(): void {
		check_ajax_referer( 'snw_license_activation', 'nonce' );

		if ( ! is_user_logged_in() ) {
			wp_send_json_error( array( 'message' => __( 'You must be logged in to do this.', 'serial-number-for-woocommerce' ) ), 403 );
		}

		$order_id  = isset( $_POST['order_id'] ) ? absint( $_POST['order_id'] ) : 0;
		$serial_id = isset( $_POST['serial_id'] ) ? absint( $_POST['serial_id'] ) : 0;

		$order = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof \WC_Order || ! current_user_can( 'view_order', $order_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Order not found.', 'serial-number-for-woocommerce' ) ) );
		}

		// Confirms the serial actually belongs to one of this order's items —
		// never trust the posted order_id/serial_id pairing on its own.
		if ( ! Assigner::find_item_for_serial( $order, $serial_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This license key does not belong to this order.', 'serial-number-for-woocommerce' ) ) );
		}

		$serial = Repository::find( $serial_id );

		if ( ! $serial || ! LicenseKey::is_enabled_for_product( (int) $serial->product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This is not a license key.', 'serial-number-for-woocommerce' ) ) );
		}

		if ( 'manual' !== LicenseKey::activation_trigger_for_product( (int) $serial->product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This license key does not support manual activation.', 'serial-number-for-woocommerce' ) ) );
		}

		if ( ! empty( $serial->activated_at ) ) {
			wp_send_json_error( array( 'message' => __( 'This license key is already activated.', 'serial-number-for-woocommerce' ) ) );
		}

		LicenseKey::activate_serial( $serial_id );

		wp_send_json_success( array( 'message' => __( 'License activated.', 'serial-number-for-woocommerce' ) ) );
	}
}
