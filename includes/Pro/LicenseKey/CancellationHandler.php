<?php
namespace SerialNumberForWooCommerce\Pro\LicenseKey;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Status;
use SerialNumberForWooCommerce\Licensing;
use SerialNumberForWooCommerce\Orders\Assigner;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: revokes an already-Activated license when its order is cancelled/
 * refunded, gated by the `snw_license_revoke_on_refund` setting (default
 * on — a license is "this specific purchase's right to use," so
 * continued access after a refund is a harder case to justify than
 * Warranty's own default-off policy for a kept physical/software item).
 *
 * No cron-clearing step is needed here, unlike Warranty's own
 * CancellationHandler — License has no delayed-activation mechanism
 * anywhere, so no stale-cron risk exists for it.
 */
final class CancellationHandler {

	public function __construct() {
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_order_cancelled_or_refunded' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'on_order_cancelled_or_refunded' ) );
	}

	/**
	 * @param mixed $order WC_Order on WC's own status-transition hooks, but
	 *                      stays loose in case a third party mis-fires it.
	 */
	public function on_order_cancelled_or_refunded( int $order_id, $order = null ): void {
		if ( ! Licensing::is_pro_active() || 'yes' !== get_option( 'snw_license_revoke_on_refund', 'yes' ) ) {
			return;
		}

		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product_id = $item->get_product_id();

			if ( ! $product_id || ! LicenseKey::is_enabled_for_product( $product_id ) ) {
				continue;
			}

			foreach ( Assigner::serial_rows( $item ) as $row ) {
				if ( Status::ACTIVATED === $row->status ) {
					LicenseKey::revoke_serial( (int) $row->id );
				}
			}
		}
	}
}
