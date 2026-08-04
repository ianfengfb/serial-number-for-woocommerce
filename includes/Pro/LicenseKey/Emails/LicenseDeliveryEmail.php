<?php
namespace SerialNumberForWooCommerce\Pro\LicenseKey\Emails;

use SerialNumberForWooCommerce\Orders\Assigner;
use SerialNumberForWooCommerce\Pro\LicenseKey\LicenseKey;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: delivers the customer's license key(s) — and the seller's own
 * per-product instructions — the moment an order containing a license
 * product is placed. Order-level (not per-serial) since one order can
 * contain several licensed items, and the customer should get everything
 * in one email. Fired from Pro\LicenseKey\ActivationTrigger via the
 * `snw_license_delivered` action, regardless of each item's own
 * activation trigger — delivery and activation are separate concerns.
 */
final class LicenseDeliveryEmail extends \WC_Email {

	public function __construct() {
		$this->id             = 'snw_license_delivered';
		$this->customer_email = true;
		$this->title          = __( 'License Delivery', 'serial-number-for-woocommerce' );
		$this->description    = __( "Sent to the customer with their license key(s) and any seller instructions, as soon as the order is placed.", 'serial-number-for-woocommerce' );
		$this->template_html  = 'emails/license-delivered.php';
		$this->template_plain = 'emails/plain/license-delivered.php';
		$this->template_base  = SNW_PLUGIN_DIR . 'templates/';

		add_action( 'snw_license_delivered', array( $this, 'trigger' ), 10, 1 );

		parent::__construct();
	}

	public function get_default_subject(): string {
		return __( 'Your license key for order {order_number}', 'serial-number-for-woocommerce' );
	}

	public function get_default_heading(): string {
		return __( 'Your license key', 'serial-number-for-woocommerce' );
	}

	public function trigger( int $order_id ): void {
		$this->setup_locale();

		$order = wc_get_order( $order_id );

		if ( $order instanceof \WC_Order ) {
			$this->object                        = $order;
			$this->recipient                     = $order->get_billing_email();
			$this->placeholders['{order_number}'] = $order->get_order_number();
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
			'licenses'           => $this->object instanceof \WC_Order ? self::collect_licenses( $this->object ) : array(),
			'email_heading'      => $this->get_heading(),
			'additional_content' => $this->get_additional_content(),
			'sent_to_admin'      => false,
			'plain_text'         => $plain_text,
			'email'              => $this,
		);
	}

	/**
	 * @return array<int, array{product_name: string, instructions: string, keys: string[]}>
	 */
	private static function collect_licenses( \WC_Order $order ): array {
		$licenses = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product_id = $item->get_product_id();

			if ( ! $product_id || ! LicenseKey::is_enabled_for_product( $product_id ) ) {
				continue;
			}

			$keys = Assigner::serial_numbers( $item );

			if ( empty( $keys ) ) {
				continue;
			}

			$licenses[] = array(
				'product_name' => $item->get_name(),
				'instructions' => LicenseKey::instructions_for_product( $product_id ),
				'keys'         => $keys,
			);
		}

		return $licenses;
	}
}
