<?php
namespace SerialNumberForWooCommerce\Pro\LicenseKey\Emails;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: notifies the store admin when a customer's license activates —
 * useful when activation happens outside a normal checkout completion
 * (e.g. a manual or externally-triggered activation the seller wants to
 * know about). Fired from the same `snw_license_activated` action as
 * LicenseActivatedEmail; independently toggleable since it's a separate
 * WC_Email registration.
 */
final class LicenseActivatedAdminEmail extends \WC_Email {

	public function __construct() {
		$this->id             = 'snw_license_activated_admin';
		$this->customer_email = false;
		$this->title          = __( 'License Activated (Admin Notice)', 'serial-number-for-woocommerce' );
		$this->description    = __( 'Notifies the store admin when a customer\'s license activates.', 'serial-number-for-woocommerce' );
		$this->template_html  = 'emails/license-activated-admin.php';
		$this->template_plain = 'emails/plain/license-activated-admin.php';
		$this->template_base  = SNW_PLUGIN_DIR . 'templates/';

		add_action( 'snw_license_activated', array( $this, 'trigger' ), 10, 1 );

		parent::__construct();
	}

	public function get_default_subject(): string {
		return __( 'A license was activated: {serial_number}', 'serial-number-for-woocommerce' );
	}

	public function get_default_heading(): string {
		return __( 'License activated', 'serial-number-for-woocommerce' );
	}

	public function trigger( int $serial_id ): void {
		$this->setup_locale();

		$serial = Repository::find( $serial_id );

		if ( $serial ) {
			$this->object                          = $serial->order_id ? wc_get_order( $serial->order_id ) : false;
			$this->placeholders['{serial_number}'] = $serial->serial_number;
		}

		if ( $this->is_enabled() && $this->get_recipient() ) {
			$this->send( $this->get_recipient(), $this->get_subject(), $this->get_content(), $this->get_headers(), $this->get_attachments() );
		}

		$this->restore_locale();
	}

	public function get_content_html(): string {
		return wc_get_template_html( $this->template_html, $this->template_args( false ), '', $this->template_base );
	}

	public function get_content_plain(): string {
		return wc_get_template_html( $this->template_plain, $this->template_args( true ), '', $this->template_base );
	}

	private function template_args( bool $plain_text ): array {
		return array(
			'order'              => $this->object instanceof \WC_Order ? $this->object : null,
			'serial_number'      => $this->placeholders['{serial_number}'] ?? '',
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => true,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}
}
