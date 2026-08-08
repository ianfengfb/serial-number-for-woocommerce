<?php
namespace SerialNumberForWooCommerce\Pro\Warranty\Emails;

use SerialNumberForWooCommerce\Pro\Warranty\Warranty;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: notifies the customer when a serial number's warranty expires.
 * Fired from Pro\Warranty\ExpiryChecker via the generic `snw_serial_expired`
 * action (shared with License), so is_relevant() filters out anything that
 * isn't actually warranty-governed. Also manually re-sendable from the
 * Serial Numbers list (Pro\SerialNumberNotice\Resend) via its own
 * `snw_resend_warranty_expired` action — that one is always warranty-only
 * by construction, so no is_relevant() check is needed for it.
 */
final class WarrantyExpiredEmail extends AbstractWarrantyEmail {

	public function __construct() {
		$this->id             = 'snw_warranty_expired';
		$this->title          = __( 'Warranty Expired', 'serial-number-for-woocommerce' );
		$this->description    = __( "Sent to the customer when a serial number's warranty expires.", 'serial-number-for-woocommerce' );
		$this->template_html  = 'emails/warranty-expired.php';
		$this->template_plain = 'emails/plain/warranty-expired.php';
		$this->configure_common();

		add_action( 'snw_serial_expired', array( $this, 'trigger' ), 10, 1 );
		add_action( 'snw_resend_warranty_expired', array( $this, 'trigger' ), 10, 1 );

		parent::__construct();
	}

	public function get_default_subject(): string {
		return __( 'Your warranty for {serial_number} has expired', 'serial-number-for-woocommerce' );
	}

	public function get_default_heading(): string {
		return __( 'Your warranty has expired', 'serial-number-for-woocommerce' );
	}

	protected function is_relevant( object $serial ): bool {
		return Warranty::is_warranty_serial( $serial );
	}
}
