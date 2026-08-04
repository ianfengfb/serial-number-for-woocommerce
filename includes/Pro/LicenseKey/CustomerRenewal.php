<?php
namespace SerialNumberForWooCommerce\Pro\LicenseKey;

use SerialNumberForWooCommerce\Orders\Assigner;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: renders the "Renew" link next to an Activated or Expired (and
 * non-lifetime) license key on the customer's My Account order view — the
 * counterpart to CustomerActivation, same scoping to the view-order
 * endpoint specifically and same reasoning for why no separate permission
 * check is needed here (WooCommerce's own view-order template already
 * gates the page). Renewal itself (adding to cart, pricing, extending
 * expires_at) lives in Renewal; this class only draws the link.
 */
final class CustomerRenewal {

	public function __construct() {
		add_action( 'woocommerce_order_item_meta_end', array( $this, 'render' ), 21, 4 );
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

		$renewable = array_filter(
			Assigner::serial_rows( $item ),
			array( Renewal::class, 'is_renewable' )
		);

		if ( empty( $renewable ) ) {
			return;
		}

		foreach ( $renewable as $serial ) {
			$renew_url = add_query_arg( 'snw_renew_serial', $serial->id, wc_get_checkout_url() );
			?>
			<p class="snw-license-renew" style="margin: 4px 0 0;">
				<a class="button" href="<?php echo esc_url( $renew_url ); ?>">
					<?php
					printf(
						/* translators: %s: license key */
						esc_html__( 'Renew %s', 'serial-number-for-woocommerce' ),
						esc_html( $serial->serial_number )
					);
					?>
				</a>
			</p>
			<?php
		}
	}
}
