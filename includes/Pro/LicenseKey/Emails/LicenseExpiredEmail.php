<?php
namespace SerialNumberForWooCommerce\Pro\LicenseKey\Emails;

use SerialNumberForWooCommerce\Pro\LicenseKey\LicenseKey;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: notifies the customer when a license expires. Fired from
 * Pro\Warranty\ExpiryChecker via the generic `snw_serial_expired` action
 * (shared with Warranty), so is_relevant() filters out anything that isn't
 * actually license-governed. Also manually re-sendable from the Serial
 * Numbers list (Pro\SerialNumberNotice\Resend) via its own
 * `snw_resend_license_expired` action — that one is always license-only by
 * construction, so no is_relevant() check is needed for it.
 */
final class LicenseExpiredEmail extends AbstractLicenseEmail {

	public function __construct() {
		$this->id             = 'snw_license_expired';
		$this->title          = __( 'License Expired', 'serial-number-for-woocommerce' );
		$this->description    = __( "Sent to the customer when their license expires.", 'serial-number-for-woocommerce' );
		$this->template_html  = 'emails/license-expired.php';
		$this->template_plain = 'emails/plain/license-expired.php';
		$this->configure_common();

		add_action( 'snw_serial_expired', array( $this, 'trigger' ), 10, 1 );
		add_action( 'snw_resend_license_expired', array( $this, 'trigger' ), 10, 1 );

		parent::__construct();
	}

	public function get_default_subject(): string {
		return __( 'Your license key {serial_number} has expired', 'serial-number-for-woocommerce' );
	}

	public function get_default_heading(): string {
		return __( 'Your license has expired', 'serial-number-for-woocommerce' );
	}

	protected function is_relevant( object $serial ): bool {
		return LicenseKey::is_enabled_for_product( (int) $serial->product_id );
	}
}
