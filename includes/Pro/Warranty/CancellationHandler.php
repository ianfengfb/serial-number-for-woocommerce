<?php
namespace SerialNumberForWooCommerce\Pro\Warranty;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Status;
use SerialNumberForWooCommerce\Licensing;
use SerialNumberForWooCommerce\Orders\Assigner;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: reacts to an order being cancelled/refunded for its Warranty-
 * enabled items — two independent jobs:
 *
 * 1. Unconditionally clears any pending delayed-activation cron
 *    (ActivationTrigger's "days after Completed" mode) for the order's
 *    serials. This is a correctness fix, not a policy choice: once
 *    Orders\RefundHandler can release a still-Assigned serial back to the
 *    pool, a different order can reclaim it before the original delay
 *    elapses — a stale cron event would then activate that *recycled*
 *    serial against whichever order currently holds it, jumping its own
 *    trigger condition. Warranty::activate_serial()'s own idempotency
 *    guard (checks activated_at) doesn't prevent this, since it only
 *    blocks re-activating an already-activated serial, not a stale cron
 *    activating a serial that was never activated in the first place.
 * 2. Optionally revokes an already-Activated serial, gated by the
 *    `snw_warranty_revoke_on_refund` setting (default off — a refund
 *    doesn't retroactively cancel a warranty already protecting a
 *    physical/software item's usable life).
 *
 * Walks the order's items via Assigner::serial_ids()/serial_rows() (order-
 * item meta) rather than re-querying each row's own order_id — safe
 * regardless of whether Orders\RefundHandler's own callback already ran
 * and mutated the row, since release() never touches the item meta array.
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
		if ( ! Licensing::is_pro_active() ) {
			return;
		}

		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$revoke = 'yes' === get_option( 'snw_warranty_revoke_on_refund', 'no' );

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			foreach ( Assigner::serial_ids( $item ) as $serial_id ) {
				wp_clear_scheduled_hook( ActivationTrigger::DELAYED_ACTIVATION_HOOK, array( $serial_id ) );
			}

			if ( ! $revoke ) {
				continue;
			}

			foreach ( Assigner::serial_rows( $item ) as $row ) {
				// is_warranty_serial() prefers the row's own stored type
				// over the product's current setting, so a serial that
				// actually activated as a License is never revoked here
				// just because the product's Warranty checkbox happens to
				// be on now (e.g. after a later reconfiguration).
				if ( Status::ACTIVATED === $row->status && Warranty::is_warranty_serial( $row ) ) {
					Warranty::revoke_serial( (int) $row->id );
				}
			}
		}
	}
}
