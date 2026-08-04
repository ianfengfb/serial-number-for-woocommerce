<?php
namespace SerialNumberForWooCommerce\Orders;

use SerialNumberForWooCommerce\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * Free tier: renders the "Print Slip" link (or its unlicensed teaser) in
 * the admin order edit screen's Order actions box — the whole feature is
 * Pro (see Pro\PrintSlip\Printer, which actually streams the slip), but
 * the teaser itself has to live here: a Pro class can't render its own
 * teaser, since it doesn't exist at all in the free zip.
 *
 * Unlike Bulk Generate/Import (which route an unlicensed click to a
 * static teaser page), this renders as a plain, non-clickable `<span>`
 * when unlicensed — same reasoning as Export CSV: there's no HTML page on
 * the other end of `admin_post_snw_print_slip` to redirect to when it
 * isn't hooked, so a dead link would be worse than a disabled control.
 */
final class PrintSlip {

	public function __construct() {
		add_action( 'woocommerce_order_actions_end', array( $this, 'render_link' ) );
	}

	/**
	 * @param mixed $order_id The hook's own argument, best-effort — falls
	 *                         back to WC's `$theorder` global (set by
	 *                         WC_Meta_Box_Order_Data::output() around this
	 *                         exact hook) if that argument comes back empty.
	 */
	public function render_link( $order_id = 0 ): void {
		$order_id = absint( $order_id );

		if ( ! $order_id ) {
			global $theorder;
			$order_id = $theorder instanceof \WC_Order ? $theorder->get_id() : 0;
		}

		$order = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof \WC_Order || ! self::is_available_for_order( $order ) ) {
			return;
		}

		if ( ! Licensing::is_pro_active() ) {
			?>
			<p class="form-field form-field-wide">
				<span style="opacity: 0.5;" title="<?php esc_attr_e( 'Upgrade to Pro to print a serial number/license key slip for this order.', 'serial-number-for-woocommerce' ); ?>">
					<?php esc_html_e( 'Print Slip', 'serial-number-for-woocommerce' ); ?>
					<span style="background: #7f54b3; color: #fff; font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: 3px; margin-left: 4px; vertical-align: middle;">
						<?php esc_html_e( 'PRO', 'serial-number-for-woocommerce' ); ?>
					</span>
				</span>
			</p>
			<?php
			return;
		}

		$url = wp_nonce_url(
			add_query_arg(
				array(
					'action'   => 'snw_print_slip',
					'order_id' => $order_id,
				),
				admin_url( 'admin-post.php' )
			),
			'snw_print_slip_' . $order_id
		);
		?>
		<p class="form-field form-field-wide">
			<a href="<?php echo esc_url( $url ); ?>" class="button" target="_blank"><?php esc_html_e( 'Print Slip', 'serial-number-for-woocommerce' ); ?></a>
		</p>
		<?php
	}

	/**
	 * Whether any of the order's items actually have a serial/license
	 * number assigned — suppresses the link/teaser entirely on an order
	 * with nothing to print, same "only show it if there's something to
	 * show" treatment as ItemDisplay's own manual "Add Serial Number"
	 * control.
	 */
	public static function is_available_for_order( \WC_Order $order ): bool {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && ! empty( Assigner::serial_rows( $item ) ) ) {
				return true;
			}
		}

		return false;
	}
}
