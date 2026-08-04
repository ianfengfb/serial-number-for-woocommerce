<?php
namespace SerialNumberForWooCommerce\Pro\LicenseKey;

use SerialNumberForWooCommerce\Admin\Products\ProductTab;
use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;
use SerialNumberForWooCommerce\Licensing;
use SerialNumberForWooCommerce\Orders\Assigner;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: treats a product's serial numbers as license keys — its own
 * per-product opt-in, independent of (and can coexist with) Warranty.
 */
final class LicenseKey {

	/** Store-wide shared secret for the external activation REST API (see RestApi). */
	const API_KEY_OPTION = 'snw_license_api_key';

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
	 * @return string 'immediate', 'on_completed', 'manual', or 'api'.
	 */
	public static function activation_trigger_for_product( int $product_id ): string {
		$trigger = get_post_meta( $product_id, ProductTab::LICENSE_ACTIVATION_TRIGGER_META_KEY, true );

		return in_array( $trigger, array( 'immediate', 'on_completed', 'manual', 'api' ), true ) ? $trigger : 'immediate';
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

	/**
	 * The API key RestApi requires on its external-activation endpoint,
	 * generating and persisting one on first use so a stable value always
	 * exists — the Settings page and the REST permission check both call
	 * this rather than reading the option directly, so neither ever sees
	 * an empty/missing key.
	 */
	public static function get_or_create_api_key(): string {
		$key = get_option( self::API_KEY_OPTION );

		return $key ? $key : self::regenerate_api_key();
	}

	public static function regenerate_api_key(): string {
		$key = wp_generate_password( 40, false );

		update_option( self::API_KEY_OPTION, $key, false );

		return $key;
	}

	/**
	 * Every license-enabled item in an order, paired with its keys and the
	 * product's own instructions — shared by LicenseDeliveryEmail's template
	 * and Webhooks' license.delivered payload, so the two can never drift
	 * apart on what counts as "this order's licenses".
	 *
	 * @return array<int, array{product_name: string, instructions: string, keys: string[]}>
	 */
	public static function collect_for_order( \WC_Order $order ): array {
		$licenses = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product_id = $item->get_product_id();

			if ( ! $product_id || ! self::is_enabled_for_product( $product_id ) ) {
				continue;
			}

			$keys = Assigner::serial_numbers( $item );

			if ( empty( $keys ) ) {
				continue;
			}

			$licenses[] = array(
				'product_name' => $item->get_name(),
				'instructions' => self::instructions_for_product( $product_id ),
				'keys'         => $keys,
			);
		}

		return $licenses;
	}
}
