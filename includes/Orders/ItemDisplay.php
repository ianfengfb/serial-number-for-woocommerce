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
}
