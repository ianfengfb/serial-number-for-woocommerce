<?php
namespace SerialNumberForWooCommerce\Pro\LicenseKey;

use SerialNumberForWooCommerce\Licensing;
use SerialNumberForWooCommerce\Orders\Assigner;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: activates a product's license-key serials per that product's own
 * "Activation trigger" setting (immediate, or on order Completed) — unlike
 * Warranty's activation trigger, this is a per-product choice rather than a
 * single store-wide setting, since licensed products can have very
 * different real-world activation needs.
 *
 * Hooked at a later priority than Assigner's own checkout hooks so
 * Assigner::serial_ids() already has this order's serials by the time we
 * read them, regardless of registration order in Plugin::init().
 */
final class ActivationTrigger {

	const PRIORITY = 20;

	/** Order meta marking that snw_license_delivered has already fired for this order. */
	const DELIVERY_NOTIFIED_META_KEY = '_snw_license_delivery_notified';

	public function __construct() {
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_order_placed' ), self::PRIORITY, 3 );
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_order_placed_object' ), self::PRIORITY );
		add_action( 'woocommerce_blocks_checkout_order_processed', array( $this, 'on_order_placed_object' ), self::PRIORITY );
		add_action( 'woocommerce_order_status_completed', array( $this, 'on_order_completed' ), self::PRIORITY );
	}

	/**
	 * @param mixed $order Third arg is the WC_Order on WC 3.0+, but stays loose
	 *                     because the hook has historically passed less.
	 */
	public function on_order_placed( int $order_id, array $posted_data, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );

		$this->on_order_placed_object( $order );
	}

	/**
	 * @param mixed $order WC_Order, or anything else if a third party mis-fires the hook.
	 */
	public function on_order_placed_object( $order ): void {
		if ( $order instanceof \WC_Order ) {
			$this->activate_matching( $order, 'immediate' );
			$this->maybe_notify_delivery( $order );
		}
	}

	public function on_order_completed( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( $order instanceof \WC_Order ) {
			$this->activate_matching( $order, 'on_completed' );
		}
	}

	/**
	 * Fires the license delivery notification once per order, the moment
	 * it's placed — regardless of each item's own activation trigger, since
	 * the customer should receive their key(s) right away even if
	 * activation itself is delayed or manual.
	 *
	 * Guarded by DELIVERY_NOTIFIED_META_KEY, set right before firing —
	 * same "persist state, then fire" order as activate_serial()'s own
	 * idempotency guard on both Warranty and LicenseKey — so if the
	 * order-placed hooks were ever fired more than once for the same order
	 * (a retried webhook, a re-processing third-party integration), the
	 * customer never gets a second email re-exposing their key.
	 *
	 * A license-renewal line item (Pro\LicenseKey\Renewal) is skipped here:
	 * it reuses its existing key rather than being handed a fresh one, so
	 * Assigner never assigns it any serials, and an order made up only of
	 * renewal items would otherwise still fire this notification with no
	 * keys for LicenseKey::collect_for_order() to find — an empty delivery
	 * email on top of the LicenseRenewedEmail the customer already gets.
	 */
	private function maybe_notify_delivery( \WC_Order $order ): void {
		if ( ! Licensing::is_pro_active() || $order->get_meta( self::DELIVERY_NOTIFIED_META_KEY, true ) ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || $item->get_meta( Assigner::RENEWAL_ITEM_META_KEY, true ) ) {
				continue;
			}

			$product_id = $item->get_product_id();

			if ( $product_id && LicenseKey::is_enabled_for_product( $product_id ) ) {
				$order->update_meta_data( self::DELIVERY_NOTIFIED_META_KEY, 'yes' );
				$order->save_meta_data();

				do_action( 'snw_license_delivered', $order->get_id() );
				return;
			}
		}
	}

	private function activate_matching( \WC_Order $order, string $trigger_mode ): void {
		if ( ! Licensing::is_pro_active() ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product_id = $item->get_product_id();

			if ( ! $product_id || ! LicenseKey::is_enabled_for_product( $product_id ) ) {
				continue;
			}

			if ( LicenseKey::activation_trigger_for_product( $product_id ) !== $trigger_mode ) {
				continue;
			}

			foreach ( Assigner::serial_ids( $item ) as $serial_id ) {
				LicenseKey::activate_serial( $serial_id );
			}
		}
	}
}
