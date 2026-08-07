<?php
namespace SerialNumberForWooCommerce\Orders;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;
use SerialNumberForWooCommerce\Admin\SerialNumbers\Status;
use SerialNumberForWooCommerce\Licensing;
use SerialNumberForWooCommerce\Pro\StockSync\StockSync;

defined( 'ABSPATH' ) || exit;

/**
 * Free tier: releases a serial number back to its pool when the order
 * holding it is cancelled or fully refunded — the inverse of what
 * Assigner does when an order is placed. A separate class rather than a
 * method on Assigner, since that class's own stated purpose is assigning
 * serials when an order is placed, not the reverse lifecycle direction
 * (same reasoning that already keeps ItemDisplay/CustomerItemDisplay
 * separate from Assigner despite depending on its serial_rows()).
 *
 * Only ever releases a serial still in Assigned status — one that was
 * never activated, so nothing was actually put to use. An already
 * Activated serial is a Pro policy decision (see each feature's own
 * revoke_serial()/CancellationHandler), out of scope here.
 *
 * Also renders an order-level notice for a *partial* refund — see
 * render_partial_refund_notice() — since that case can never be resolved
 * automatically the way a full refund/cancellation is above.
 */
final class RefundHandler {

	public function __construct() {
		add_action( 'woocommerce_order_status_cancelled', array( $this, 'on_order_cancelled_or_refunded' ) );
		add_action( 'woocommerce_order_status_refunded', array( $this, 'on_order_cancelled_or_refunded' ) );
		add_action( 'woocommerce_admin_order_data_after_order_details', array( $this, 'render_partial_refund_notice' ) );
	}

	/**
	 * @param mixed $order WC_Order on WC's own status-transition hooks, but
	 *                      stays loose in case a third party mis-fires it.
	 */
	public function on_order_cancelled_or_refunded( int $order_id, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );

		if ( $order instanceof \WC_Order ) {
			self::release_unactivated_for_order( $order );
		}
	}

	/**
	 * A partial refund gives no reliable way to know which serial number(s)
	 * it actually corresponds to. `ItemDisplay`'s own per-item notice (from
	 * `get_qty_refunded_for_item()`) already covers a refund tied to a
	 * specific item's quantity, but a refund made instead against the
	 * order's own lump "Refund amount" field isn't tied to any item at all,
	 * so that per-item check can't catch it. This is a coarser, order-level
	 * backstop: any order carrying a refund that hasn't brought it to the
	 * "Refunded" status (a full refund — already handled automatically by
	 * `on_order_cancelled_or_refunded()` above) gets a single notice
	 * pointing the seller at this order's serial assignments to review by
	 * hand, regardless of whether any specific item's own notice also fired.
	 */
	public function render_partial_refund_notice( \WC_Order $order ): void {
		if ( 'refunded' === $order->get_status() || $order->get_total_refunded() <= 0 ) {
			return;
		}
		?>
		<p class="form-field form-field-wide snw-partial-refund-notice" style="color: #b32d2e;">
			<?php esc_html_e( 'This order has a partial refund on it. There is no reliable way to know which serial number(s) that corresponds to — please review the serial numbers assigned to this order\'s items and update their status/assignment manually if needed.', 'serial-number-for-woocommerce' ); ?>
		</p>
		<?php
	}

	/**
	 * Releases every still-Assigned (never activated) serial on the order
	 * back to its pool, then syncs stock once per distinct affected
	 * product. Checking for Assigned specifically — not "anything other
	 * than Activated" — makes this naturally idempotent against re-firing
	 * and leaves Deleted/Unavailable/Revoked rows untouched.
	 */
	public static function release_unactivated_for_order( \WC_Order $order ): void {
		$touched_products = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			foreach ( Assigner::serial_rows( $item ) as $row ) {
				if ( Status::ASSIGNED !== $row->status ) {
					continue;
				}

				Repository::release( (int) $row->id );
				$touched_products[ (int) $row->product_id ] = true;

				/**
				 * Fires after a serial is released back to its pool by a
				 * cancelled/refunded order.
				 *
				 * @param int $serial_id
				 * @param int $order_id
				 */
				do_action( 'snw_serial_released', (int) $row->id, $order->get_id() );
			}
		}

		if ( Licensing::is_pro_active() ) {
			foreach ( array_keys( $touched_products ) as $product_id ) {
				StockSync::sync( $product_id );
			}
		}
	}
}
