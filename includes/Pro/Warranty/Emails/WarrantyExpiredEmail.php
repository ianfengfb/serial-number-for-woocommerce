<?php
namespace SerialNumberForWooCommerce\Pro\Warranty\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: notifies the customer when a serial number's warranty expires.
 * Fired from Pro\Warranty\ExpiryChecker via the `snw_warranty_expired`
 * action.
 */
final class WarrantyExpiredEmail extends AbstractWarrantyEmail {

	public function __construct() {
		$this->id             = 'snw_warranty_expired';
		$this->title          = __( 'Warranty Expired', 'serial-number-for-woocommerce' );
		$this->description    = __( "Sent to the customer when a serial number's warranty expires.", 'serial-number-for-woocommerce' );
		$this->template_html  = 'emails/warranty-expired.php';
		$this->template_plain = 'emails/plain/warranty-expired.php';
		$this->configure_common();

		add_action( 'snw_warranty_expired', array( $this, 'trigger' ), 10, 1 );

		parent::__construct();
	}

	public function get_default_subject(): string {
		return __( 'Your warranty for {serial_number} has expired', 'serial-number-for-woocommerce' );
	}

	public function get_default_heading(): string {
		return __( 'Your warranty has expired', 'serial-number-for-woocommerce' );
	}
}
