<?php
namespace SerialNumberForWooCommerce\Pro\Warranty;

use SerialNumberForWooCommerce\Admin\Products\ProductTab;
use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;
use SerialNumberForWooCommerce\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: tracks a warranty against each serial number assigned for a product.
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

	/**
	 * Starts a serial number's warranty now: Activated, with expires_at set
	 * from its product's duration_for_product(). Idempotent — a serial that
	 * already has an activated_at is left untouched, so calling this more
	 * than once for the same serial (e.g. a re-fired order status hook)
	 * never resets the clock.
	 *
	 * Callers decide *when* to call this (immediately, after a delay, or —
	 * later — a manual customer action); this is only the "make it so" step,
	 * which is why it doesn't check is_enabled_for_product() itself: by the
	 * time something calls this, that decision has already been made.
	 */
	public static function activate_serial( int $serial_id ): bool {
		if ( ! Licensing::is_pro_active() ) {
			return false;
		}

		$serial = Repository::find( $serial_id );

		if ( ! $serial || $serial->activated_at ) {
			return false;
		}

		$duration    = self::duration_for_product( (int) $serial->product_id );
		$expires_ts  = strtotime( '+' . $duration['length'] . ' ' . $duration['period'], current_time( 'timestamp' ) );

		Repository::activate( $serial_id, gmdate( 'Y-m-d H:i:s', $expires_ts ) );

		return true;
	}
}
