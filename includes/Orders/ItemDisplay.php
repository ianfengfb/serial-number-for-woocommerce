<?php
namespace SerialNumberForWooCommerce\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Free tier: shows a line item's assigned serial numbers on the admin order
 * edit screen, right under its product/qty/price meta.
 */
final class ItemDisplay {

	private bool $printed_styles = false;

	public function __construct() {
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'render' ), 10, 2 );
	}

	/**
	 * @param mixed $item Loose-typed because the hook doesn't guarantee it's a
	 *                     WC_Order_Item_Product (e.g. shipping/fee line items).
	 */
	public function render( int $item_id, $item ): void {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		$serial_numbers = Assigner::serial_numbers( $item );

		if ( ! empty( $serial_numbers ) ) {
			?>
			<div class="snw-order-item-serials">
				<strong><?php esc_html_e( 'Serial Numbers', 'serial-number-for-woocommerce' ); ?>:</strong>
				<?php echo esc_html( implode( ', ', $serial_numbers ) ); ?>
			</div>
			<?php
			$this->render_partial_refund_notice( $item_id, $item );
		}

		/*
		 * A manual override for items that never got auto-assigned in the
		 * first place (e.g. the product wasn't Serial Number-enabled yet at
		 * checkout) — Assigner::add_manual_serial() decides row by row
		 * whether a typed value creates, attaches to, or is rejected for this
		 * item. Hidden once the item already holds one serial per ordered
		 * unit, since there's nothing left to add at that point.
		 */
		if ( count( $serial_numbers ) < $item->get_quantity() ) {
			?>
			<div class="snw-order-item-add-serial" style="margin-top: 6px;">
				<input type="text" class="snw-add-serial-input" placeholder="<?php esc_attr_e( 'Add serial number&hellip;', 'serial-number-for-woocommerce' ); ?>" style="width: 160px;" />
				<button
					type="button"
					class="button snw-add-serial-btn"
					data-item-id="<?php echo esc_attr( $item_id ); ?>"
					data-order-id="<?php echo esc_attr( $item->get_order_id() ); ?>"
				><?php esc_html_e( 'Add Serial Number', 'serial-number-for-woocommerce' ); ?></button>
				<span class="snw-add-serial-result" style="margin-left: 6px;"></span>
			</div>
			<?php
		}

		if ( ! $this->printed_styles ) {
			$this->printed_styles = true;
			?>
			<style>
				.snw-add-serial-result.snw-add-serial-error { color: #b32d2e; }
			</style>
			<?php
		}
	}

	/**
	 * A partial refund gives no way to know which specific serial the
	 * refunded unit(s) correspond to — _snw_serial_ids is a flat,
	 * unordered array with no per-unit tracking — so this is a note, not
	 * an automatic action. Computed at render time from the order's own
	 * refund records (not a hook), so it also surfaces a partial refund
	 * that happened before this version was installed. The admin can
	 * already set any serial's status by hand via the Edit form's
	 * unrestricted status dropdown, so nothing else is needed beyond
	 * pointing them at it.
	 */
	private function render_partial_refund_notice( int $item_id, \WC_Order_Item_Product $item ): void {
		$order = $item->get_order();

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$refunded_qty = abs( (float) $order->get_qty_refunded_for_item( $item_id ) );

		if ( $refunded_qty <= 0 ) {
			return;
		}
		?>
		<p class="description" style="color: #b32d2e;">
			<?php
			printf(
				/* translators: %d: number of units refunded */
				esc_html__( '%d unit(s) of this item have been refunded. There is no way to know which specific serial number that corresponds to — please review the serial numbers above and update the correct one\'s status manually if needed.', 'serial-number-for-woocommerce' ),
				(int) round( $refunded_qty )
			);
			?>
		</p>
		<?php
	}
}
