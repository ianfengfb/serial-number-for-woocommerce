<?php
namespace SerialNumberForWooCommerce\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Free tier: shows a line item's assigned serial numbers to the customer —
 * order emails, the thank-you page, and the My Account order view all render
 * through the same `woocommerce_order_item_meta_end` hook, unlike the admin
 * order edit screen (`woocommerce_after_order_itemmeta`, see ItemDisplay),
 * so one hook covers all three.
 */
final class CustomerItemDisplay {

	public function __construct() {
		add_action( 'woocommerce_order_item_meta_end', array( $this, 'render' ), 10, 4 );
	}

	/**
	 * @param mixed $item Loose-typed because the hook doesn't guarantee it's a
	 *                     WC_Order_Item_Product (e.g. shipping/fee line items).
	 */
	public function render( int $item_id, $item, $order, bool $plain_text = false ): void {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		$serial_numbers = Assigner::serial_numbers( $item );

		if ( empty( $serial_numbers ) ) {
			return;
		}

		$label = _n( 'Serial Number', 'Serial Numbers', count( $serial_numbers ), 'serial-number-for-woocommerce' );

		if ( $plain_text ) {
			// Plain-text context, not HTML — no entity-escaping here.
			echo "\n" . $label . ': ' . implode( ', ', $serial_numbers ) . "\n";
			return;
		}
		?>
		<p class="snw-order-item-serials" style="margin: 4px 0 0; font-size: small; color: #767676;">
			<strong><?php echo esc_html( $label ); ?>:</strong>
			<?php echo esc_html( implode( ', ', $serial_numbers ) ); ?>
		</p>
		<?php
	}
}
