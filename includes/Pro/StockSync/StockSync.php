<?php
namespace SerialNumberForWooCommerce\Pro\StockSync;

use SerialNumberForWooCommerce\Admin\Products\ProductTab;
use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;
use SerialNumberForWooCommerce\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: keeps a product's WooCommerce stock quantity equal to the count of
 * Available serial numbers in its pool, when "Manage product stock with
 * Serial Number" is switched on for that product (Serial Number tab).
 */
final class StockSync {

	/**
	 * sync() below always turns _manage_stock on (needed so WooCommerce
	 * displays the numeric count at all) — which also makes WooCommerce's
	 * own native stock reduction (wc_reduce_stock_levels(), fired on
	 * payment-complete and several order-status transitions) treat this
	 * product exactly like any normal stock-managed one, independently
	 * decrementing _stock by the ordered quantity with no idea this
	 * plugin already recomputed the correct post-purchase count from the
	 * serial pool. Left alone, that's a double reduction on top of ours.
	 *
	 * Rather than trying to intercept WooCommerce's own reduction (its
	 * exact trigger hook varies by payment gateway/order flow), this
	 * re-runs sync() itself — an idempotent, absolute recompute from
	 * count_available(), not a relative decrement — on every event that
	 * could plausibly be the one WooCommerce reduces stock on, so
	 * whatever WooCommerce's native logic just did to _stock is always
	 * immediately corrected back to the true pool count.
	 */
	const RESYNC_HOOKS = array(
		'woocommerce_payment_complete',
		'woocommerce_order_status_processing',
		'woocommerce_order_status_on-hold',
		'woocommerce_order_status_completed',
	);

	public function __construct() {
		foreach ( self::RESYNC_HOOKS as $hook ) {
			add_action( $hook, array( $this, 'resync_order_items' ), 20 );
		}
	}

	public function resync_order_items( int $order_id ): void {
		$order = wc_get_order( $order_id );

		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && $item->get_product_id() ) {
				self::sync( $item->get_product_id() );
			}
		}
	}

	public static function is_enabled_for_product( int $product_id ): bool {
		return 'yes' === get_post_meta( $product_id, ProductTab::META_KEY, true )
			&& 'yes' === get_post_meta( $product_id, ProductTab::MANAGE_STOCK_META_KEY, true );
	}

	/**
	 * Recomputes and writes the product's stock from its Available pool count.
	 * Safe to call unconditionally from anywhere the pool count may have
	 * changed — it no-ops unless licensed and stock-by-serial-number is
	 * switched on for the product.
	 *
	 * @return int|null The newly synced stock quantity, or null if it no-opped
	 *                   (unlicensed, not enabled for this product, or the
	 *                   product couldn't be loaded) — callers that need to
	 *                   reflect the new number back to the browser (e.g. an
	 *                   AJAX response updating the on-screen stock field) can
	 *                   use this instead of re-querying count_available().
	 */
	public static function sync( int $product_id ): ?int {
		if ( ! Licensing::is_pro_active() || ! self::is_enabled_for_product( $product_id ) ) {
			return null;
		}

		$product = wc_get_product( $product_id );

		if ( ! $product ) {
			return null;
		}

		$count = Repository::count_available( $product_id );

		$product->set_manage_stock( true );
		$product->set_stock_quantity( $count );
		$product->set_stock_status( $count > 0 ? 'instock' : 'outofstock' );
		$product->save();

		return $count;
	}
}
