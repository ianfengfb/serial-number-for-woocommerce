<?php
namespace SerialNumberForWooCommerce\Pro\LicenseKey\Emails;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;
use SerialNumberForWooCommerce\Pro\LicenseKey\LicenseKey;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: shared plumbing for the per-serial license notification emails
 * (LicenseActivatedEmail, LicenseExpiredEmail) — mirrors
 * Pro\Warranty\Emails\AbstractWarrantyEmail intentionally rather than
 * sharing it: License's own email needs (activation-trigger awareness,
 * external/webhook activation later) are expected to diverge from
 * Warranty's over time, so each feature keeps its own copy of this small
 * amount of plumbing rather than coupling the two namespaces together.
 */
abstract class AbstractLicenseEmail extends \WC_Email {

	/**
	 * Whether the serial this email is about is a lifetime license (its
	 * product's period is 'lifetime', so expires_at is deliberately null
	 * rather than "not yet computed") — lets the template say so explicitly
	 * instead of silently omitting the expiry line, indistinguishable from
	 * a plain "no expiry shown here" case.
	 */
	protected bool $is_lifetime = false;

	protected function configure_common(): void {
		$this->customer_email = true;
		$this->template_base  = SNW_PLUGIN_DIR . 'templates/';
	}

	/**
	 * @param int $serial_id The serial whose license just activated/expired.
	 */
	public function trigger( int $serial_id ): void {
		$this->setup_locale();

		$serial = Repository::find( $serial_id );
		$order  = $serial && $serial->order_id ? wc_get_order( $serial->order_id ) : false;

		if ( $serial && ! $this->is_relevant( $serial ) ) {
			$this->restore_locale();
			return;
		}

		if ( $serial && $order instanceof \WC_Order ) {
			$this->object                          = $order;
			$this->recipient                       = $order->get_billing_email();
			$this->placeholders['{serial_number}'] = $serial->serial_number;
			$this->placeholders['{expires_at}']    = $serial->expires_at
				? date_i18n( get_option( 'date_format' ), strtotime( $serial->expires_at ) )
				: '';
			$this->is_lifetime                     = ! $serial->expires_at
				&& 'lifetime' === LicenseKey::duration_for_product( (int) $serial->product_id )['period'];
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
			'is_lifetime'        => $this->is_lifetime,
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}

	/**
	 * Whether this email should react to a given serial. Overridden by
	 * LicenseExpiredEmail, since it listens on the generic
	 * `snw_serial_expired` event shared with Warranty — LicenseActivatedEmail
	 * doesn't need this, as `snw_license_activated` is only ever fired for
	 * license-enabled products in the first place.
	 *
	 * @param object $serial A snw_serial_numbers row.
	 */
	protected function is_relevant( object $serial ): bool {
		return true;
	}
}
