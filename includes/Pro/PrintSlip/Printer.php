<?php
namespace SerialNumberForWooCommerce\Pro\PrintSlip;

use SerialNumberForWooCommerce\Orders\Assigner;
use SerialNumberForWooCommerce\Pro\LicenseKey\LicenseKey;
use SerialNumberForWooCommerce\Pro\Warranty\Warranty;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: streams a standalone, printable HTML slip for one order's serial
 * numbers/license keys — an alternative to showing them to the customer
 * online at all (see the "Customer visibility" settings), or just a
 * convenient physical/PDF record. Mirrors Pro\Export\Exporter's own
 * admin-post streaming shape exactly, but renders a themeable HTML
 * template (see templates/print/order-slip.php) instead of a CSV file.
 *
 * Only ever instantiated when licensed (see Plugin::init()), so — same
 * reasoning Pro\BulkGenerate\Controller uses for calling StockSync
 * unguarded — the warranty/license lookups below don't re-check
 * Licensing::is_pro_active() themselves.
 */
final class Printer {

	public function __construct() {
		add_action( 'admin_post_snw_print_slip', array( $this, 'stream' ) );
	}

	public function stream(): void {
		$order_id = isset( $_GET['order_id'] ) ? absint( $_GET['order_id'] ) : 0;

		check_admin_referer( 'snw_print_slip_' . $order_id );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'serial-number-for-woocommerce' ) );
		}

		$order = $order_id ? wc_get_order( $order_id ) : false;

		if ( ! $order instanceof \WC_Order ) {
			wp_die( esc_html__( 'Order not found.', 'serial-number-for-woocommerce' ) );
		}

		$html = wc_get_template_html(
			'print/order-slip.php',
			array(
				'order'           => $order,
				'items'           => self::items_for_order( $order ),
				'default_message' => (string) get_option( 'snw_print_slip_message', '' ),
			),
			'',
			SNW_PLUGIN_DIR . 'templates/'
		);

		if ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/html; charset=utf-8' );
		echo $html; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- template output, already escaped internally.
		exit;
	}

	/**
	 * Uses `Assigner::display_rows()` rather than `serial_rows()` directly,
	 * so a license-renewal line item — which holds no serial of its own,
	 * only a reference to the one it renewed — still appears on the slip
	 * with that key and its new expiry, instead of being skipped entirely.
	 *
	 * @return array<int, array{
	 *     product_name: string,
	 *     is_license: bool,
	 *     serials: object[],
	 *     license: null|array{instructions: string, duration: array},
	 *     warranty: null|array{duration: array},
	 * }>
	 */
	private static function items_for_order( \WC_Order $order ): array {
		$items = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$serials = Assigner::display_rows( $item );

			if ( empty( $serials ) ) {
				continue;
			}

			$product_id = $item->get_product_id();

			// is_license_serial()/is_warranty_serial() prefer each row's
			// own stored type over the product's current setting, so a
			// later change to the product's checkboxes can't relabel an
			// already-activated key/warranty on an old order's slip. Any
			// one serial answering "yes" is enough — see the identical
			// reasoning in Orders\CustomerItemDisplay::render().
			$is_license  = false;
			$is_warranty = false;

			foreach ( $serials as $row ) {
				$is_license  = $is_license || LicenseKey::is_license_serial( $row );
				$is_warranty = $is_warranty || Warranty::is_warranty_serial( $row );
			}

			$items[] = array(
				'product_name' => $item->get_name(),
				'is_license'   => $is_license,
				'serials'      => $serials,
				'license'      => $is_license
					? array(
						'instructions' => LicenseKey::instructions_for_product( $product_id ),
						'duration'     => LicenseKey::duration_for_product( $product_id ),
					)
					: null,
				'warranty'     => $is_warranty
					? array( 'duration' => Warranty::duration_for_product( $product_id ) )
					: null,
			);
		}

		return $items;
	}
}
