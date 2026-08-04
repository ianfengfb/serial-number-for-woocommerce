<?php
namespace SerialNumberForWooCommerce\Pro\LicenseKey;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: adds License-specific topics to WooCommerce's native Webhooks
 * system (WooCommerce > Settings > Advanced > Webhooks), rather than
 * building a bespoke outbound-notification UI — sellers pick "License
 * activated" etc. from the same Topic dropdown as any built-in WooCommerce
 * topic, get the same delivery log/retry/signing behaviour for free, and
 * there's nothing new for them to learn.
 *
 * Three extension points make this possible for a resource WooCommerce
 * doesn't know about natively:
 * - `woocommerce_webhook_topics` lists our topics in the Topic dropdown.
 * - `woocommerce_webhook_topic_hooks` says which WordPress action(s) should
 *   trigger a delivery attempt for each topic.
 * - `woocommerce_webhook_payload` builds the actual payload, since
 *   WooCommerce's built-in payload builders only know about its own
 *   resources (order/product/customer/coupon).
 */
final class Webhooks {

	const TOPIC_ACTIVATED = 'license.activated';
	const TOPIC_EXPIRED   = 'license.expired';
	const TOPIC_DELIVERED = 'license.delivered';
	const TOPIC_RENEWED   = 'license.renewed';

	/**
	 * `snw_serial_expired` also fires for an expired Warranty serial, which
	 * would otherwise trigger a delivery attempt for every webhook
	 * subscribed to license.expired regardless of relevance — the same
	 * problem LicenseExpiredEmail solves with its is_relevant() override.
	 * A webhook's topic-to-hook mapping has no equivalent filter to skip a
	 * delivery once triggered, so instead this relays the generic event
	 * into a License-only one that license.expired maps to instead.
	 */
	const RELAYED_EXPIRED_HOOK = 'snw_license_expired';

	public function __construct() {
		add_filter( 'woocommerce_webhook_topics', array( $this, 'register_topics' ) );
		add_filter( 'woocommerce_webhook_topic_hooks', array( $this, 'register_topic_hooks' ) );
		add_filter( 'woocommerce_webhook_payload', array( $this, 'build_payload' ), 10, 4 );
		add_action( 'snw_serial_expired', array( $this, 'relay_expired' ) );
	}

	public function register_topics( array $topics ): array {
		$topics[ self::TOPIC_ACTIVATED ] = __( 'License activated', 'serial-number-for-woocommerce' );
		$topics[ self::TOPIC_EXPIRED ]   = __( 'License expired', 'serial-number-for-woocommerce' );
		$topics[ self::TOPIC_DELIVERED ] = __( 'License delivered', 'serial-number-for-woocommerce' );
		$topics[ self::TOPIC_RENEWED ]   = __( 'License renewed', 'serial-number-for-woocommerce' );

		return $topics;
	}

	public function register_topic_hooks( array $hooks ): array {
		$hooks[ self::TOPIC_ACTIVATED ][] = 'snw_license_activated';
		$hooks[ self::TOPIC_EXPIRED ][]   = self::RELAYED_EXPIRED_HOOK;
		$hooks[ self::TOPIC_DELIVERED ][] = 'snw_license_delivered';
		$hooks[ self::TOPIC_RENEWED ][]   = 'snw_license_renewed';

		return $hooks;
	}

	public function relay_expired( int $serial_id ): void {
		$serial = Repository::find( $serial_id );

		if ( $serial && LicenseKey::is_enabled_for_product( (int) $serial->product_id ) ) {
			do_action( self::RELAYED_EXPIRED_HOOK, $serial_id );
		}
	}

	/**
	 * @param mixed $payload     WooCommerce's own built-in payload, null for
	 *                           any resource it doesn't recognise (ours).
	 * @param string $resource   The topic's resource — 'license' for all
	 *                           four topics above; anything else is left
	 *                           untouched.
	 * @param mixed  $resource_id The hook's own single argument — a serial
	 *                           ID for activated/expired/renewed, an order
	 *                           ID for delivered.
	 * @param int    $webhook_id
	 * @return mixed
	 */
	public function build_payload( $payload, $resource, $resource_id, $webhook_id ) {
		if ( 'license' !== $resource ) {
			return $payload;
		}

		$webhook = function_exists( 'wc_get_webhook' ) ? wc_get_webhook( $webhook_id ) : null;
		$event   = $webhook ? $webhook->get_event() : '';

		if ( 'delivered' === $event ) {
			return $this->delivered_payload( (int) $resource_id );
		}

		return $this->serial_payload( (int) $resource_id );
	}

	private function serial_payload( int $serial_id ): array {
		$serial = Repository::find( $serial_id );

		if ( ! $serial ) {
			return array();
		}

		$product = wc_get_product( $serial->product_id );

		return array(
			'serial_number' => $serial->serial_number,
			'product_id'    => (int) $serial->product_id,
			'product_name'  => $product ? $product->get_name() : '',
			'order_id'      => (int) $serial->order_id,
			'activated_at'  => $serial->activated_at,
			'expires_at'    => $serial->expires_at,
		);
	}

	private function delivered_payload( int $order_id ): array {
		$order = wc_get_order( $order_id );

		return array(
			'order_id' => $order_id,
			'licenses' => $order instanceof \WC_Order ? LicenseKey::collect_for_order( $order ) : array(),
		);
	}
}
