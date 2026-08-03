<?php
namespace SerialNumberForWooCommerce\Orders;

defined( 'ABSPATH' ) || exit;

/**
 * Free tier: shows a line item's assigned serial numbers to the customer —
 * order emails, the thank-you page, and the My Account order view all render
 * through the same `woocommerce_order_item_meta_end` hook, unlike the admin
 * order edit screen (`woocommerce_after_order_itemmeta`, see ItemDisplay),
 * so one hook covers all three. Each of the two contexts (emails vs. the
 * order-details page) has its own on/off setting in WooCommerce > Settings >
 * Serial Numbers, both defaulting to on. The order-details page also shows
 * each serial's expiry date, if it has one — emails stay serial-number-only.
 */
final class CustomerItemDisplay {

	/**
	 * Whether we're currently inside an email's order-details table, per
	 * WooCommerce's own `woocommerce_email_order_details` /
	 * `woocommerce_email_after_order_table` bracket (both fire once per
	 * email, around the items table) — the only way to tell an HTML email
	 * apart from the thank-you/account page, since both render through
	 * `woocommerce_order_item_meta_end` with `$plain_text` false.
	 */
	private bool $in_email = false;

	public function __construct() {
		add_action( 'woocommerce_email_order_details', array( $this, 'mark_email_start' ) );
		add_action( 'woocommerce_email_after_order_table', array( $this, 'mark_email_end' ) );
		add_action( 'woocommerce_order_item_meta_end', array( $this, 'render' ), 10, 4 );
	}

	public function mark_email_start(): void {
		$this->in_email = true;
	}

	public function mark_email_end(): void {
		$this->in_email = false;
	}

	/**
	 * @param mixed $item Loose-typed because the hook doesn't guarantee it's a
	 *                     WC_Order_Item_Product (e.g. shipping/fee line items).
	 */
	public function render( int $item_id, $item, $order, bool $plain_text = false ): void {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		$setting = $this->in_email ? 'snw_show_serials_in_emails' : 'snw_show_serials_in_account';

		if ( 'yes' !== get_option( $setting, 'yes' ) ) {
			return;
		}

		$serials = Assigner::serial_rows( $item );

		if ( empty( $serials ) ) {
			return;
		}

		$label       = _n( 'Serial Number', 'Serial Numbers', count( $serials ), 'serial-number-for-woocommerce' );
		$show_expiry = ! $this->in_email;

		$parts = array();

		foreach ( $serials as $serial ) {
			$parts[] = $this->format_serial( $serial, $show_expiry, $plain_text );
		}

		if ( $plain_text ) {
			// Plain-text context, not HTML — no entity-escaping here.
			echo "\n" . $label . ': ' . implode( ', ', $parts ) . "\n";
			return;
		}
		?>
		<p class="snw-order-item-serials" style="margin: 4px 0 0; font-size: small; color: #767676;">
			<strong><?php echo esc_html( $label ); ?>:</strong>
			<?php echo implode( ', ', $parts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- already escaped in format_serial(). ?>
		</p>
		<?php
	}

	/**
	 * @param object $serial A snw_serial_numbers row.
	 */
	private function format_serial( object $serial, bool $show_expiry, bool $plain_text ): string {
		if ( ! $show_expiry || ! $serial->expires_at ) {
			return $plain_text ? $serial->serial_number : esc_html( $serial->serial_number );
		}

		$formatted = sprintf(
			/* translators: 1: serial number, 2: expiry date */
			__( '%1$s (expires %2$s)', 'serial-number-for-woocommerce' ),
			$serial->serial_number,
			date_i18n( get_option( 'date_format' ), strtotime( $serial->expires_at ) )
		);

		return $plain_text ? $formatted : esc_html( $formatted );
	}
}
