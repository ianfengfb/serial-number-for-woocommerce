<?php
namespace SerialNumberForWooCommerce\Admin\SerialNumbers;

defined( 'ABSPATH' ) || exit;

/**
 * The lifecycle statuses a serial number can be in — the single place to
 * add, rename or reorder them.
 *
 * Meaning of each status:
 *
 * - Available   In the pool and not tied to an order yet, so it can be handed
 *               out. This is the only status auto-assignment picks from.
 * - Assigned    Attached to an order (and therefore a customer), but not yet
 *               put to use.
 * - Activated   The customer has redeemed/registered it and it is in use.
 * - Expired     Past its expiry date, so no longer valid.
 * - Unavailable Deliberately withheld — faulty, reserved, or otherwise parked
 *               by hand. Never handed out, but kept on record.
 * - Revoked     Deliberately withdrawn after an order was cancelled/refunded
 *               (see Orders\RefundHandler and each Pro feature's own
 *               CancellationHandler) — distinct from Unavailable so a seller
 *               can tell "revoked by a refund" apart from a manual park.
 *               Never handed out again automatically.
 * - Deleted     Soft-deleted via the list table's Delete action. A normal
 *               selectable status like any other (editable back to something
 *               else), kept rather than hard-deleted for audit/recoverability.
 *               Needs no special exclusion in Available-counting queries —
 *               they already filter for `status = 'available'` specifically.
 *
 * Stored values are the lowercase keys; labels are display-only and
 * translatable, so never persist or compare against a label.
 */
final class Status {

	const AVAILABLE   = 'available';
	const ASSIGNED    = 'assigned';
	const ACTIVATED   = 'activated';
	const EXPIRED     = 'expired';
	const UNAVAILABLE = 'unavailable';
	const REVOKED     = 'revoked';
	const DELETED     = 'deleted';

	/**
	 * Used when no default has been configured, or the configured one is no
	 * longer a valid status.
	 */
	const FALLBACK = self::AVAILABLE;

	/**
	 * All statuses as value => translated label, in display order.
	 *
	 * A method rather than a constant because the labels have to be translated
	 * at call time, once the text domain is loaded.
	 */
	public static function all(): array {
		return array(
			self::AVAILABLE   => __( 'Available', 'serial-number-for-woocommerce' ),
			self::ASSIGNED    => __( 'Assigned', 'serial-number-for-woocommerce' ),
			self::ACTIVATED   => __( 'Activated', 'serial-number-for-woocommerce' ),
			self::EXPIRED     => __( 'Expired', 'serial-number-for-woocommerce' ),
			self::UNAVAILABLE => __( 'Unavailable', 'serial-number-for-woocommerce' ),
			self::REVOKED     => __( 'Revoked', 'serial-number-for-woocommerce' ),
			self::DELETED     => __( 'Deleted', 'serial-number-for-woocommerce' ),
		);
	}

	public static function exists( string $status ): bool {
		return isset( self::all()[ $status ] );
	}

	/**
	 * Display label for a stored status, falling back to the raw value so rows
	 * written by an older version (or by third-party code) still show something.
	 */
	public static function label( string $status ): string {
		return self::all()[ $status ] ?? ucfirst( $status );
	}

	/**
	 * Status a newly created serial number should start in: whatever is set in
	 * WooCommerce > Settings > Serial Numbers, validated.
	 */
	public static function configured_default(): string {
		$status = (string) get_option( 'snw_default_status', self::FALLBACK );

		return self::exists( $status ) ? $status : self::FALLBACK;
	}
}
