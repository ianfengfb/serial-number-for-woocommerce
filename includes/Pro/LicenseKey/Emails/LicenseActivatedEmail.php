<?php
namespace SerialNumberForWooCommerce\Pro\LicenseKey\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: notifies the customer when a license activates. Fired from
 * Pro\LicenseKey\LicenseKey::activate_serial() via the `snw_license_activated`
 * action.
 */
final class LicenseActivatedEmail extends AbstractLicenseEmail {

	public function __construct() {
		$this->id             = 'snw_license_activated';
		$this->title          = __( 'License Activated', 'serial-number-for-woocommerce' );
		$this->description    = __( "Sent to the customer when their license activates.", 'serial-number-for-woocommerce' );
		$this->template_html  = 'emails/license-activated.php';
		$this->template_plain = 'emails/plain/license-activated.php';
		$this->configure_common();

		add_action( 'snw_license_activated', array( $this, 'trigger' ), 10, 1 );

		parent::__construct();
	}

	public function get_default_subject(): string {
		return __( 'Your license key {serial_number} is now active', 'serial-number-for-woocommerce' );
	}

	public function get_default_heading(): string {
		return __( 'Your license is active', 'serial-number-for-woocommerce' );
	}
}
