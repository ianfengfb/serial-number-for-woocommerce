<?php
namespace SerialNumberForWooCommerce\Pro\SerialNumberNotice;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: wp_ajax_snw_resend_email backing the Serial Numbers list's per-row
 * "send/resend email" action(s) (see Resend). Re-validates everything
 * server-side — which email_type is actually applicable for this exact row
 * right now — rather than trusting whatever the browser posts, same posture
 * as every other AJAX handler in this plugin that acts on a row a user
 * picked (e.g. Orders\Assigner::add_manual_serial()).
 */
final class Ajax {

	public function __construct() {
		add_action( 'wp_ajax_snw_resend_email', array( $this, 'resend_email' ) );
	}

	public function resend_email(): void {
		check_ajax_referer( 'snw_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'serial-number-for-woocommerce' ) ), 403 );
		}

		$serial_id  = isset( $_POST['serial_id'] ) ? absint( $_POST['serial_id'] ) : 0;
		$email_type = isset( $_POST['email_type'] ) ? sanitize_key( wp_unslash( $_POST['email_type'] ) ) : '';
		$serial     = $serial_id ? Repository::find( $serial_id ) : null;

		if ( ! $serial ) {
			wp_send_json_error( array( 'message' => __( 'Serial number not found.', 'serial-number-for-woocommerce' ) ) );
		}

		$applicable = Resend::actions_for_serial( $serial );

		if ( ! isset( $applicable[ $email_type ] ) ) {
			wp_send_json_error( array( 'message' => __( 'That email is no longer applicable to this serial number.', 'serial-number-for-woocommerce' ) ) );
		}

		$order = $serial->order_id ? wc_get_order( $serial->order_id ) : false;

		if ( ! $order instanceof \WC_Order || ! $order->get_billing_email() ) {
			wp_send_json_error( array( 'message' => __( 'This order has no billing email to send to.', 'serial-number-for-woocommerce' ) ) );
		}

		do_action( Resend::action_hook( $email_type ), $serial_id );

		wp_send_json_success( array( 'message' => __( 'Email sent.', 'serial-number-for-woocommerce' ) ) );
	}
}
