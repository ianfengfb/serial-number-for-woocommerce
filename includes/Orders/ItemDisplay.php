<?php
namespace SerialNumberForWooCommerce\Orders;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Free tier: shows a line item's assigned serial numbers on the admin order
 * edit screen, right under its product/qty/price meta.
 */
final class ItemDisplay {

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

		$serial_ids = Assigner::serial_ids( $item );

		if ( empty( $serial_ids ) ) {
			return;
		}

		$serial_numbers = array();

		foreach ( $serial_ids as $serial_id ) {
			$serial = Repository::find( $serial_id );

			if ( $serial ) {
				$serial_numbers[] = $serial->serial_number;
			}
		}

		if ( empty( $serial_numbers ) ) {
			return;
		}
		?>
		<div class="snw-order-item-serials">
			<strong><?php esc_html_e( 'Serial Numbers', 'serial-number-for-woocommerce' ); ?>:</strong>
			<?php echo esc_html( implode( ', ', $serial_numbers ) ); ?>
		</div>
		<?php
	}
}
