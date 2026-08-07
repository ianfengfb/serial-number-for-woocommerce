<?php
namespace SerialNumberForWooCommerce\Pro\SerialNumberNotice;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;
use SerialNumberForWooCommerce\Orders\Assigner;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: notifies a customer of one specific serial number already assigned
 * to one of their orders. Unlike every other email in this plugin, this one
 * is never fired automatically by any internal event — it exists solely for
 * a store admin to manually send/resend from the Serial Numbers list (see
 * Resend), for a plain (non-License, non-Warranty) serial number: e.g.
 * something went wrong at checkout, or the store's own "Show in order
 * emails"/"Show on order details page" settings were off at the time.
 */
final class SerialNumberNoticeEmail extends \WC_Email {

	public function __construct() {
		$this->id             = 'snw_serial_number_notice';
		$this->customer_email = true;
		$this->title          = __( 'Serial Number Notice', 'serial-number-for-woocommerce' );
		$this->description    = __( 'Manually sent from the Serial Numbers list to give a customer their serial number for a specific order.', 'serial-number-for-woocommerce' );
		$this->template_html  = 'emails/serial-number-notice.php';
		$this->template_plain = 'emails/plain/serial-number-notice.php';
		$this->template_base  = SNW_PLUGIN_DIR . 'templates/';

		add_action( 'snw_serial_number_notice_requested', array( $this, 'trigger' ), 10, 1 );

		parent::__construct();
	}

	public function get_default_subject(): string {
		return __( 'Your serial number for order {order_number}', 'serial-number-for-woocommerce' );
	}

	public function get_default_heading(): string {
		return __( 'Your serial number', 'serial-number-for-woocommerce' );
	}

	public function trigger( int $serial_id ): void {
		$this->setup_locale();

		$serial = Repository::find( $serial_id );
		$order  = $serial && $serial->order_id ? wc_get_order( $serial->order_id ) : false;

		if ( $serial && $order instanceof \WC_Order ) {
			$item = Assigner::find_item_for_serial( $order, $serial_id );

			$this->object                          = $order;
			$this->recipient                       = $order->get_billing_email();
			$this->placeholders['{serial_number}'] = $serial->serial_number;
			$this->placeholders['{order_number}']  = $order->get_order_number();
			$this->placeholders['{product_name}']  = $item ? $item->get_name() : '';
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
			'product_name'       => $this->placeholders['{product_name}'] ?? '',
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}
}
