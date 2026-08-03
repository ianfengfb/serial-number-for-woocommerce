<?php
namespace SerialNumberForWooCommerce\Pro\Warranty;

use SerialNumberForWooCommerce\Licensing;
use SerialNumberForWooCommerce\Orders\Assigner;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: starts warranty on an order's eligible line items once it's marked
 * Completed, per the "Activation trigger" setting in WooCommerce > Settings
 * > Serial Numbers — either immediately, or after a configured number of
 * days (scheduled via a single WP-Cron event per serial).
 */
final class ActivationTrigger {

	/**
	 * Must match Install::WARRANTY_CRON_HOOKS — see that constant for why
	 * the hook name is duplicated as a literal there instead of referencing
	 * this constant from Free-tier code.
	 */
	const DELAYED_ACTIVATION_HOOK = 'snw_activate_warranty_serial';

	public function __construct() {
		add_action( 'woocommerce_order_status_completed', array( $this, 'handle_order_completed' ) );
		add_action( self::DELAYED_ACTIVATION_HOOK, array( $this, 'handle_delayed_activation' ) );
	}

	public function handle_order_completed( int $order_id ): void {
		if ( ! Licensing::is_pro_active() ) {
			return;
		}

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		$mode = get_option( 'snw_warranty_activation_trigger', 'on_completed' );
		$days = 'days_after_completed' === $mode ? max( 0, absint( get_option( 'snw_warranty_activation_days', 0 ) ) ) : 0;

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product_id = $item->get_product_id();

			if ( ! $product_id || ! Warranty::is_enabled_for_product( $product_id ) ) {
				continue;
			}

			foreach ( Assigner::serial_ids( $item ) as $serial_id ) {
				if ( $days > 0 ) {
					wp_schedule_single_event( time() + ( $days * DAY_IN_SECONDS ), self::DELAYED_ACTIVATION_HOOK, array( $serial_id ) );
				} else {
					Warranty::activate_serial( $serial_id );
				}
			}
		}
	}

	public function handle_delayed_activation( int $serial_id ): void {
		Warranty::activate_serial( (int) $serial_id );
	}
}
