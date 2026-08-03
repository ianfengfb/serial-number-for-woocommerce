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

	/**
	 * A product's configured warranty length, regardless of whether warranty
	 * is actually enabled for it — callers that need to act on the duration
	 * should check is_enabled_for_product() themselves first, same pattern as
	 * CustomRules.
	 *
	 * @return array{length: int, period: string} period is 'month' or 'year'.
	 */
	public static function duration_for_product( int $product_id ): array {
		$length = absint( get_post_meta( $product_id, ProductTab::WARRANTY_LENGTH_META_KEY, true ) );
		$period = get_post_meta( $product_id, ProductTab::WARRANTY_PERIOD_META_KEY, true );

		return array(
			'length' => $length > 0 ? $length : 1,
			'period' => in_array( $period, array( 'month', 'year' ), true ) ? $period : 'year',
		);
	}
}
