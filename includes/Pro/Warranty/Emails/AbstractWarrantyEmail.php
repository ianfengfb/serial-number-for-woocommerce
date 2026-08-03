<?php
namespace SerialNumberForWooCommerce\Pro\Warranty\Emails;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: shared plumbing for the two warranty notification emails
 * (WarrantyActivatedEmail, WarrantyExpiredEmail) — both are triggered the
 * same way (a serial ID via an action hook) and render the same set of
 * template variables, so only id/title/description/subject/heading/hook
 * differ between the two subclasses.
 *
 * Registered like any other WC_Email via the `woocommerce_email_classes`
 * filter, so store admins get WooCommerce's own native "Enable this email
 * notification" toggle, subject/heading fields, and theme-overridable
 * template — nothing bespoke to build or maintain for that.
 */
abstract class AbstractWarrantyEmail extends \WC_Email {

	protected function configure_common(): void {
		$this->customer_email = true;
		$this->template_base  = SNW_PLUGIN_DIR . 'templates/';
	}

	/**
	 * @param int $serial_id The serial whose warranty just activated/expired.
	 */
	public function trigger( int $serial_id ): void {
		$this->setup_locale();

		$serial = Repository::find( $serial_id );
		$order  = $serial && $serial->order_id ? wc_get_order( $serial->order_id ) : false;

		if ( $serial && $order instanceof \WC_Order ) {
			$this->object                          = $order;
			$this->recipient                       = $order->get_billing_email();
			$this->placeholders['{serial_number}'] = $serial->serial_number;
			$this->placeholders['{expires_at}']    = $serial->expires_at
				? date_i18n( get_option( 'date_format' ), strtotime( $serial->expires_at ) )
				: '';
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
			'order'              => $this->object,
			'serial_number'      => $this->placeholders['{serial_number}'] ?? '',
			'expires_at'         => $this->placeholders['{expires_at}'] ?? '',
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}
}
