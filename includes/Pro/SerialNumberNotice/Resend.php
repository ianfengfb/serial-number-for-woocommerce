<?php
namespace SerialNumberForWooCommerce\Pro\SerialNumberNotice;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Status;
use SerialNumberForWooCommerce\Licensing;
use SerialNumberForWooCommerce\Pro\LicenseKey\LicenseKey;
use SerialNumberForWooCommerce\Pro\Warranty\Warranty;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: decides which customer-facing "send/resend email" action(s) apply to
 * a given serial row on the Serial Numbers list, and maps each one to the
 * action hook that actually sends it.
 *
 * A serial tied to an order always has at least one applicable action once
 * licensed: License/Warranty Activated or Expired when the row's status
 * matches one of those and its product has the corresponding feature
 * enabled (both, in the rare case a product has both features on at once —
 * shown as two separate row actions rather than guessing which one the
 * seller means), falling back to the generic SerialNumberNoticeEmail for
 * everything else (a plain serial, or a License/Warranty product whose
 * serial hasn't reached either status yet).
 */
final class Resend {

	/**
	 * @param object $serial A snw_serial_numbers row.
	 * @return array<string,string> email_type => row-action label.
	 */
	public static function actions_for_serial( object $serial ): array {
		if ( ! Licensing::is_pro_active() || empty( $serial->order_id ) ) {
			return array();
		}

		$product_id = (int) ( $serial->product_id ?? 0 );
		$actions    = array();

		if ( $product_id && LicenseKey::is_enabled_for_product( $product_id ) ) {
			if ( Status::ACTIVATED === $serial->status ) {
				$actions['license_activated'] = __( 'Resend License Activated Email', 'serial-number-for-woocommerce' );
			} elseif ( Status::EXPIRED === $serial->status ) {
				$actions['license_expired'] = __( 'Resend License Expired Email', 'serial-number-for-woocommerce' );
			}
		}

		if ( $product_id && Warranty::is_enabled_for_product( $product_id ) ) {
			if ( Status::ACTIVATED === $serial->status ) {
				$actions['warranty_activated'] = __( 'Resend Warranty Activated Email', 'serial-number-for-woocommerce' );
			} elseif ( Status::EXPIRED === $serial->status ) {
				$actions['warranty_expired'] = __( 'Resend Warranty Expired Email', 'serial-number-for-woocommerce' );
			}
		}

		if ( empty( $actions ) ) {
			$actions['serial_number_notice'] = __( 'Send Serial Number Email', 'serial-number-for-woocommerce' );
		}

		return $actions;
	}

	/**
	 * The action hook that actually sends a given email_type, or null for an
	 * unrecognized value. Used by Ajax::resend_email(), which never trusts
	 * the browser's posted email_type on its own — it's only ever fired
	 * after confirming the value is still present in actions_for_serial()
	 * for that exact row.
	 */
	public static function action_hook( string $email_type ): ?string {
		$map = array(
			'license_activated'    => 'snw_resend_license_activated',
			'license_expired'      => 'snw_resend_license_expired',
			'warranty_activated'   => 'snw_resend_warranty_activated',
			'warranty_expired'     => 'snw_resend_warranty_expired',
			'serial_number_notice' => 'snw_serial_number_notice_requested',
		);

		return $map[ $email_type ] ?? null;
	}
}
