<?php
namespace SerialNumberForWooCommerce\Pro\LicenseKey\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: notifies the customer when their license renews. Fired from
 * Pro\LicenseKey\Renewal::renew_serial() via the `snw_license_renewed`
 * action — only the serial ID is bound (1 accepted arg), since
 * AbstractLicenseEmail::trigger() re-resolves the serial's current
 * expires_at fresh from the database rather than relying on the action's
 * second argument.
 */
final class LicenseRenewedEmail extends AbstractLicenseEmail {

	public function __construct() {
		$this->id             = 'snw_license_renewed';
		$this->title          = __( 'License Renewed', 'serial-number-for-woocommerce' );
		$this->description    = __( 'Sent to the customer when their license renews.', 'serial-number-for-woocommerce' );
		$this->template_html  = 'emails/license-renewed.php';
		$this->template_plain = 'emails/plain/license-renewed.php';
		$this->configure_common();

		add_action( 'snw_license_renewed', array( $this, 'trigger' ), 10, 1 );

		parent::__construct();
	}

	public function get_default_subject(): string {
		return __( 'Your license key {serial_number} has been renewed', 'serial-number-for-woocommerce' );
	}

	public function get_default_heading(): string {
		return __( 'Your license has been renewed', 'serial-number-for-woocommerce' );
	}
}
