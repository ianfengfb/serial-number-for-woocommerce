<?php
namespace SerialNumberForWooCommerce\Pro\Warranty\Emails;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: notifies the customer when a serial number's warranty activates.
 * Fired from Warranty::activate_serial() via the `snw_warranty_activated`
 * action, or manually re-sent from the Serial Numbers list
 * (Pro\SerialNumberNotice\Resend) via `snw_resend_warranty_activated` — same
 * `trigger()` either way, since it only reads and sends, it never mutates
 * the serial's own state.
 */
final class WarrantyActivatedEmail extends AbstractWarrantyEmail {

	public function __construct() {
		$this->id             = 'snw_warranty_activated';
		$this->title          = __( 'Warranty Activated', 'serial-number-for-woocommerce' );
		$this->description    = __( "Sent to the customer when a serial number's warranty activates.", 'serial-number-for-woocommerce' );
		$this->template_html  = 'emails/warranty-activated.php';
		$this->template_plain = 'emails/plain/warranty-activated.php';
		$this->configure_common();

		add_action( 'snw_warranty_activated', array( $this, 'trigger' ), 10, 1 );
		add_action( 'snw_resend_warranty_activated', array( $this, 'trigger' ), 10, 1 );

		parent::__construct();
	}

	public function get_default_subject(): string {
		return __( 'Your warranty for {serial_number} is now active', 'serial-number-for-woocommerce' );
	}

	public function get_default_heading(): string {
		return __( 'Your warranty is active', 'serial-number-for-woocommerce' );
	}
}
