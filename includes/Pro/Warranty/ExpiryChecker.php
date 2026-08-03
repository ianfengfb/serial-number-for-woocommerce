<?php
namespace SerialNumberForWooCommerce\Pro\Warranty;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;
use SerialNumberForWooCommerce\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: a daily cron sweep that flips Activated serial numbers past their
 * expires_at to Expired.
 *
 * Self-schedules from its own constructor rather than on plugin activation,
 * since this class doesn't exist at all in the free zip — Free-tier code
 * (Install::activate()) can't reference it, and licensing can change at
 * runtime (SNW_DEV_UNLOCK_ALL) independent of when the plugin was last
 * activated. Plugin::init() only ever instantiates this when licensed, and
 * wp_next_scheduled() makes the check cheap to repeat on every such request.
 */
final class ExpiryChecker {

	/**
	 * Must match Install::WARRANTY_CRON_HOOKS — see that constant for why
	 * the hook name is duplicated as a literal there instead of referencing
	 * this constant from Free-tier code.
	 */
	const CRON_HOOK = 'snw_check_warranty_expirations';

	public function __construct() {
		add_action( self::CRON_HOOK, array( $this, 'check' ) );

		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), 'daily', self::CRON_HOOK );
		}
	}

	public function check(): void {
		if ( ! Licensing::is_pro_active() ) {
			return;
		}

		foreach ( Repository::find_activated_past_expiry() as $serial ) {
			Repository::expire( (int) $serial->id );

			/**
			 * Fires after a serial number's warranty expires. Backs the
			 * Warranty Expired customer email (Pro\Warranty\Emails\WarrantyExpiredEmail).
			 *
			 * @param int $serial_id
			 */
			do_action( 'snw_warranty_expired', (int) $serial->id );
		}
	}
}
