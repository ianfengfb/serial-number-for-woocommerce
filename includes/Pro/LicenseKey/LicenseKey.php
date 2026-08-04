<?php
namespace SerialNumberForWooCommerce\Pro\LicenseKey;

use SerialNumberForWooCommerce\Admin\Products\ProductTab;
use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;
use SerialNumberForWooCommerce\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: treats a product's serial numbers as license keys — its own
 * per-product opt-in, independent of (and can coexist with) Warranty.
 */
final class LicenseKey {

	public static function is_enabled_for_product( int $product_id ): bool {
		return Licensing::is_pro_active()
			&& 'yes' === get_post_meta( $product_id, ProductTab::META_KEY, true )
			&& 'yes' === get_post_meta( $product_id, ProductTab::LICENSE_ENABLED_META_KEY, true );
	}

	/**
	 * A product's configured license length, regardless of whether
	 * licensing is actually enabled for it — callers that need to act on
	 * the duration should check is_enabled_for_product() themselves first,
	 * same pattern as Warranty.
	 *
	 * @return array{length: int, period: string} period is 'month', 'year',
	 *                                             or 'lifetime' (never expires).
	 */
	public static function duration_for_product( int $product_id ): array {
		$length = absint( get_post_meta( $product_id, ProductTab::LICENSE_LENGTH_META_KEY, true ) );
		$period = get_post_meta( $product_id, ProductTab::LICENSE_PERIOD_META_KEY, true );

		return array(
			'length' => $length > 0 ? $length : 1,
			'period' => in_array( $period, array( 'month', 'year', 'lifetime' ), true ) ? $period : 'year',
		);
	}

	/**
	 * @return string 'immediate' or 'on_completed'.
	 */
	public static function activation_trigger_for_product( int $product_id ): string {
		$trigger = get_post_meta( $product_id, ProductTab::LICENSE_ACTIVATION_TRIGGER_META_KEY, true );

		return in_array( $trigger, array( 'immediate', 'on_completed' ), true ) ? $trigger : 'immediate';
	}

	/**
	 * The seller's own instructions for this product's license, shown to
	 * the customer in their delivery email — activation steps, download
	 * links, support contact, etc. Empty string if none set.
	 */
	public static function instructions_for_product( int $product_id ): string {
		return (string) get_post_meta( $product_id, ProductTab::LICENSE_INSTRUCTIONS_META_KEY, true );
	}

	/**
	 * Starts a license now: Activated, with expires_at computed from the
	 * product's duration_for_product() — or left null for a lifetime
	 * license. Idempotent — a serial that already has an activated_at is
	 * left untouched, same guard as Warranty::activate_serial().
	 *
	 * Doesn't check is_enabled_for_product() itself: by the time something
	 * calls this, that decision belongs to the caller.
	 */
	public static function activate_serial( int $serial_id ): bool {
		if ( ! Licensing::is_pro_active() ) {
			return false;
		}

		$serial = Repository::find( $serial_id );

		if ( ! $serial || $serial->activated_at ) {
			return false;
		}

		$duration = self::duration_for_product( (int) $serial->product_id );

		$expires_at = null;

		if ( 'lifetime' !== $duration['period'] ) {
			$months     = $duration['length'] * ( 'year' === $duration['period'] ? 12 : 1 );
			$expires_ts = strtotime( "+{$months} months", current_time( 'timestamp' ) );
			$expires_at = gmdate( 'Y-m-d H:i:s', $expires_ts );
		}

		Repository::activate( $serial_id, $expires_at );

		/**
		 * Fires after a license activates. Backs any future License
		 * notification email/webhook.
		 *
		 * @param int $serial_id
		 */
		do_action( 'snw_license_activated', $serial_id );

		return true;
	}
}
