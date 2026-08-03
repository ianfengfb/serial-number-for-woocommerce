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
