<?php
namespace SerialNumberForWooCommerce\Pro\LicenseKey;

use SerialNumberForWooCommerce\Orders\Assigner;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: renders the "Activate" button next to a license key on the
 * customer's My Account order view, for products whose own activation
 * trigger is set to 'manual'. Scoped to the view-order endpoint specifically
 * (not the thank-you page or emails) — WooCommerce's own view-order template
 * already gates that page behind `current_user_can( 'view_order', ... )`
 * before this hook ever fires, so no separate permission check is needed
 * here; the AJAX handler (see Ajax) re-checks independently since it's a
 * separate request that can't rely on the page load's own gate.
 *
 * Runs on the same `woocommerce_order_item_meta_end` hook as
 * CustomerItemDisplay, at a later priority so its buttons render below that
 * class's read-only key list rather than interleaved with it.
 */
final class CustomerActivation {

	public function __construct() {
		add_action( 'woocommerce_order_item_meta_end', array( $this, 'render' ), 20, 4 );
	}

	/**
	 * @param mixed $item Loose-typed because the hook doesn't guarantee it's a
	 *                     WC_Order_Item_Product (e.g. shipping/fee line items).
	 */
	public function render( int $item_id, $item, $order, bool $plain_text = false ): void {
		if ( $plain_text || ! $item instanceof \WC_Order_Item_Product || ! $order instanceof \WC_Order ) {
			return;
		}

		if ( ! function_exists( 'is_wc_endpoint_url' ) || ! is_wc_endpoint_url( 'view-order' ) ) {
			return;
		}

		$product_id = $item->get_product_id();

		if ( ! $product_id || ! LicenseKey::is_enabled_for_product( $product_id ) ) {
			return;
		}

		if ( 'manual' !== LicenseKey::activation_trigger_for_product( $product_id ) ) {
			return;
		}

		$pending = array_filter(
			Assigner::serial_rows( $item ),
			static function ( $serial ) {
				return empty( $serial->activated_at );
			}
		);

		if ( empty( $pending ) ) {
			return;
		}

		foreach ( $pending as $serial ) {
			?>
			<p class="snw-license-activate" style="margin: 4px 0 0;">
				<button
					type="button"
					class="button snw-activate-license-btn"
					data-serial-id="<?php echo esc_attr( $serial->id ); ?>"
					data-order-id="<?php echo esc_attr( $order->get_id() ); ?>"
				>
					<?php
					printf(
						/* translators: %s: license key */
						esc_html__( 'Activate %s', 'serial-number-for-woocommerce' ),
						esc_html( $serial->serial_number )
					);
					?>
				</button>
				<span class="snw-activate-license-result"></span>
			</p>
			<?php
		}
	}
}
