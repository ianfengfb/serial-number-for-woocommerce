<?php
namespace SerialNumberForWooCommerce\Pro\Warranty;

use SerialNumberForWooCommerce\Admin\Products\ProductTab;
use SerialNumberForWooCommerce\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: tracks a warranty against each serial number assigned for a product.
 *
 * Starting point only — "Enable warranty for this product" (Serial Number
 * tab) just persists the opt-in for now; warranty duration/terms and their
 * effect on individual serial numbers land in later features, gated the
 * same way as StockSync and CustomRules.
 */
final class Warranty {

	public static function is_enabled_for_product( int $product_id ): bool {
		return Licensing::is_pro_active()
			&& 'yes' === get_post_meta( $product_id, ProductTab::META_KEY, true )
			&& 'yes' === get_post_meta( $product_id, ProductTab::WARRANTY_ENABLED_META_KEY, true );
	}
}
