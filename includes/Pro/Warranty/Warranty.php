<?php
namespace SerialNumberForWooCommerce\Pro\Warranty;

use SerialNumberForWooCommerce\Admin\Products\ProductTab;
use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;
use SerialNumberForWooCommerce\Admin\SerialNumbers\Status;
use SerialNumberForWooCommerce\Licensing;
use SerialNumberForWooCommerce\Orders\Assigner;

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

		$months = self::duration_in_months( self::duration_for_product( (int) $serial->product_id ) );
		$order  = $serial->order_id ? wc_get_order( $serial->order_id ) : false;

		if ( $order instanceof \WC_Order ) {
			$item = Assigner::find_item_for_serial( $order, $serial_id );
			$extension = $item ? Extension::duration_for_order_item( $item ) : null;

			if ( $extension ) {
				$months += self::duration_in_months( $extension );
			}
		}

		$expires_ts = strtotime( "+{$months} months", current_time( 'timestamp' ) );

		Repository::activate( $serial_id, gmdate( 'Y-m-d H:i:s', $expires_ts ) );

		/**
		 * Fires after a serial number's warranty activates. Backs the
		 * Warranty Activated customer email (Pro\Warranty\Emails\WarrantyActivatedEmail).
		 *
		 * @param int $serial_id
		 */
		do_action( 'snw_warranty_activated', $serial_id );

		return true;
	}

	/**
	 * Withdraws an already-started warranty — Revoked, leaving
	 * activated_at/expires_at as the historical record. Only meaningful
	 * for a serial that actually is Activated (an idempotency guard, same
	 * shape as activate_serial()'s own); the store-wide
	 * `snw_warranty_revoke_on_refund` setting decides whether a caller
	 * ever calls this at all — see Pro\Warranty\CancellationHandler.
	 */
	public static function revoke_serial( int $serial_id ): bool {
		if ( ! Licensing::is_pro_active() ) {
			return false;
		}

		$serial = Repository::find( $serial_id );

		if ( ! $serial || Status::ACTIVATED !== $serial->status ) {
			return false;
		}

		Repository::mark_revoked( $serial_id );

		/**
		 * Fires after a warranty is revoked (order cancelled/refunded).
		 *
		 * @param int $serial_id
		 */
		do_action( 'snw_warranty_revoked', $serial_id );

		return true;
	}

	/**
	 * @param array{length: int, period: string} $duration
	 */
	private static function duration_in_months( array $duration ): int {
		return $duration['length'] * ( 'year' === $duration['period'] ? 12 : 1 );
	}
}
