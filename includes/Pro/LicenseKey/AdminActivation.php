<?php
namespace SerialNumberForWooCommerce\Pro\LicenseKey;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;
use SerialNumberForWooCommerce\Orders\Assigner;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: lets a store admin activate a license key directly from the order
 * edit screen — an override available regardless of that product's own
 * activation trigger (immediate/on_completed/manual/api), for support cases
 * (a customer asks to have their key activated early, testing, etc.) where
 * waiting for the configured trigger isn't practical. Renders on the same
 * `woocommerce_after_order_itemmeta` hook as the Free ItemDisplay, at a
 * later priority so it appears below that class's read-only key list and
 * manual "Add Serial Number" control rather than interleaved with them.
 *
 * Reuses the order edit screen's existing `snw_admin` nonce and
 * SNWOrderItemSerials localized object (both printed by the Free
 * Orders\Ajax class, which always enqueues on this screen regardless of
 * license status) rather than localizing its own — the script dependency
 * below guarantees load order.
 */
final class AdminActivation {

	public function __construct() {
		add_action( 'woocommerce_after_order_itemmeta', array( $this, 'render' ), 20, 2 );
		add_action( 'wp_ajax_snw_admin_activate_license', array( $this, 'activate' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * @param mixed $item Loose-typed because the hook doesn't guarantee it's a
	 *                     WC_Order_Item_Product (e.g. shipping/fee line items).
	 */
	public function render( int $item_id, $item ): void {
		if ( ! $item instanceof \WC_Order_Item_Product ) {
			return;
		}

		$product_id = $item->get_product_id();

		if ( ! $product_id || ! LicenseKey::is_enabled_for_product( $product_id ) ) {
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
			<div class="snw-admin-activate-license" style="margin-top: 6px;">
				<button
					type="button"
					class="button snw-admin-activate-license-btn"
					data-serial-id="<?php echo esc_attr( $serial->id ); ?>"
				>
					<?php
					printf(
						/* translators: %s: license key */
						esc_html__( 'Activate %s', 'serial-number-for-woocommerce' ),
						esc_html( $serial->serial_number )
					);
					?>
				</button>
				<span class="snw-admin-activate-license-result" style="margin-left: 6px;"></span>
			</div>
			<?php
		}
	}

	public function enqueue_assets(): void {
		$screen = get_current_screen();

		if ( ! $screen ) {
			return;
		}

		$order_screen_id = function_exists( 'wc_get_page_screen_id' ) ? wc_get_page_screen_id( 'shop-order' ) : 'shop_order';

		if ( $screen->id !== $order_screen_id && 'shop_order' !== $screen->id ) {
			return;
		}

		wp_enqueue_script(
			'snw-admin-license-activation',
			SNW_PLUGIN_URL . 'assets/pro/js/admin-license-activation.js',
			array( 'jquery', 'snw-order-item-serials' ),
			SNW_VERSION,
			true
		);
	}

	public function activate(): void {
		check_ajax_referer( 'snw_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'serial-number-for-woocommerce' ) ), 403 );
		}

		$serial_id = isset( $_POST['serial_id'] ) ? absint( $_POST['serial_id'] ) : 0;
		$serial    = $serial_id ? Repository::find( $serial_id ) : null;

		if ( ! $serial || ! LicenseKey::is_enabled_for_product( (int) $serial->product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'This is not a license key.', 'serial-number-for-woocommerce' ) ) );
		}

		if ( ! empty( $serial->activated_at ) ) {
			wp_send_json_error( array( 'message' => __( 'This license key is already activated.', 'serial-number-for-woocommerce' ) ) );
		}

		LicenseKey::activate_serial( $serial_id );

		wp_send_json_success( array( 'message' => __( 'License activated.', 'serial-number-for-woocommerce' ) ) );
	}
}
