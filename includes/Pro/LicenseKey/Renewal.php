<?php
namespace SerialNumberForWooCommerce\Pro\LicenseKey;

use SerialNumberForWooCommerce\Admin\Products\ProductTab;
use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;
use SerialNumberForWooCommerce\Admin\SerialNumbers\Status;
use SerialNumberForWooCommerce\Licensing;
use SerialNumberForWooCommerce\Orders\Assigner;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: lets a customer pay to extend an existing (Activated or Expired,
 * non-lifetime) license key from their My Account order view — see
 * CustomerRenewal for the "Renew" link this backs. Modeled on
 * Warranty\Extension's cart-item-data mechanic, but the entry point is
 * different: there's no product-page checkbox to opt into, since renewal
 * only ever applies to a *specific* already-issued key, so the "Renew"
 * link carries the serial ID as a query var on the checkout URL instead.
 *
 * The line item this creates reuses the referenced serial rather than
 * wanting a fresh one, so Assigner::assign_for_order() skips any item
 * carrying Assigner::RENEWAL_ITEM_META_KEY — see that class.
 */
final class Renewal {

	/** Cart item data key holding the serial ID being renewed. */
	const CART_ITEM_KEY = 'snw_renewal_serial_id';

	/**
	 * Order-item meta marking that on_order() has already renewed this
	 * item's serial. The order-placed hooks below (classic, Store API, and
	 * the legacy-compat firing WooCommerce Blocks checkout does for the
	 * classic one alongside its own) can fire more than once for the same
	 * order — every other trigger in this codebase reacting to the same
	 * hook family already guards against that (Assigner::assign_for_order()
	 * only assigns the shortfall; LicenseKey::activate_serial() checks
	 * activated_at) — but is_renewable() has nothing that changes once a
	 * renewal applies (the serial stays Activated either way), so without
	 * this guard a second firing would extend expires_at a second time.
	 */
	const RENEWAL_APPLIED_META_KEY = '_snw_renewal_applied';

	public function __construct() {
		add_action( 'template_redirect', array( $this, 'maybe_start_renewal' ) );
		add_action( 'woocommerce_before_calculate_totals', array( $this, 'adjust_price' ) );
		add_filter( 'woocommerce_get_item_data', array( $this, 'display_cart_item_data' ), 10, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( $this, 'save_order_item_meta' ), 10, 4 );

		// Classic checkout.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_checkout_order_processed' ), 10, 3 );
		// Blocks / Store API checkout.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_order' ) );
		add_action( 'woocommerce_blocks_checkout_order_processed', array( $this, 'on_order' ) );
	}

	public static function renewal_price_for_product( int $product_id ): float {
		$price = get_post_meta( $product_id, ProductTab::LICENSE_RENEWAL_PRICE_META_KEY, true );

		if ( '' !== $price ) {
			return (float) $price;
		}

		$product = wc_get_product( $product_id );

		return $product ? (float) $product->get_price() : 0.0;
	}

	/**
	 * Whether a serial row is currently in a state renewal makes sense for:
	 * license-enabled, already activated at least once (Activated or
	 * Expired — not still pending), and not a lifetime license (which never
	 * expires, so there's nothing to extend).
	 */
	public static function is_renewable( object $serial ): bool {
		if ( ! LicenseKey::is_enabled_for_product( (int) $serial->product_id ) ) {
			return false;
		}

		if ( ! in_array( $serial->status, array( Status::ACTIVATED, Status::EXPIRED ), true ) ) {
			return false;
		}

		return 'lifetime' !== LicenseKey::duration_for_product( (int) $serial->product_id )['period'];
	}

	/**
	 * Entry point for the "Renew" link on the customer's My Account order
	 * view — a plain query var on the checkout URL rather than WooCommerce's
	 * generic add-to-cart URL convention, so validation and the post-add
	 * redirect (straight to checkout) are fully under our control.
	 */
	public function maybe_start_renewal(): void {
		if ( ! isset( $_GET['snw_renew_serial'] ) ) {
			return;
		}

		$serial_id = absint( $_GET['snw_renew_serial'] );
		$redirect  = remove_query_arg( 'snw_renew_serial' );
		$serial    = $serial_id ? Repository::find( $serial_id ) : null;

		if ( ! $serial || ! is_user_logged_in() ) {
			wp_safe_redirect( $redirect );
			exit;
		}

		$order = $serial->order_id ? wc_get_order( (int) $serial->order_id ) : false;

		if ( ! $order instanceof \WC_Order || ! current_user_can( 'view_order', $order->get_id() ) ) {
			wc_add_notice( __( 'That license key could not be found on your account.', 'serial-number-for-woocommerce' ), 'error' );
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( ! self::is_renewable( $serial ) ) {
			wc_add_notice( __( 'This license key cannot be renewed.', 'serial-number-for-woocommerce' ), 'error' );
			wp_safe_redirect( $redirect );
			exit;
		}

		if ( ! self::cart_already_has( $serial_id ) ) {
			WC()->cart->add_to_cart( (int) $serial->product_id, 1, 0, array(), array( self::CART_ITEM_KEY => $serial_id ) );
		}

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Guards against a repeat click bumping the cart item's quantity to 2 —
	 * WooCommerce merges an add-to-cart into an existing item sharing the
	 * same cart item data by incrementing quantity rather than duplicating
	 * the line, which would double the renewal.
	 */
	private static function cart_already_has( int $serial_id ): bool {
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( isset( $cart_item[ self::CART_ITEM_KEY ] ) && (int) $cart_item[ self::CART_ITEM_KEY ] === $serial_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Same "fresh lookup, don't compound" shape as Warranty\Extension's own
	 * adjust_price() — woocommerce_before_calculate_totals fires more than
	 * once per checkout, so the renewal price is always set outright rather
	 * than added onto whatever the cart item's price currently is.
	 */
	public function adjust_price( \WC_Cart $cart ): void {
		if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
			return;
		}

		foreach ( $cart->get_cart() as $cart_item ) {
			if ( empty( $cart_item[ self::CART_ITEM_KEY ] ) ) {
				continue;
			}

			$cart_item['data']->set_price( self::renewal_price_for_product( (int) $cart_item['product_id'] ) );
		}
	}

	public function display_cart_item_data( array $item_data, array $cart_item ): array {
		if ( ! empty( $cart_item[ self::CART_ITEM_KEY ] ) ) {
			$serial = Repository::find( (int) $cart_item[ self::CART_ITEM_KEY ] );

			if ( $serial ) {
				$item_data[] = array(
					'name'  => __( 'License renewal', 'serial-number-for-woocommerce' ),
					'value' => $serial->serial_number,
				);
			}
		}

		return $item_data;
	}

	/**
	 * @param array $values The cart item's full data array (including our
	 *                       CART_ITEM_KEY entry, if this is a renewal item).
	 */
	public function save_order_item_meta( \WC_Order_Item_Product $item, string $cart_item_key, array $values, \WC_Order $order ): void {
		if ( ! empty( $values[ self::CART_ITEM_KEY ] ) ) {
			$item->add_meta_data( Assigner::RENEWAL_ITEM_META_KEY, absint( $values[ self::CART_ITEM_KEY ] ) );
		}
	}

	/**
	 * @param mixed $order Third arg is the WC_Order on WC 3.0+, but stays loose
	 *                      because the hook has historically passed less.
	 */
	public function on_checkout_order_processed( int $order_id, array $posted_data, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );

		$this->on_order( $order );
	}

	/**
	 * @param mixed $order WC_Order, or anything else if a third party mis-fires the hook.
	 */
	public function on_order( $order ): void {
		if ( ! $order instanceof \WC_Order ) {
			return;
		}

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product || $item->get_meta( self::RENEWAL_APPLIED_META_KEY, true ) ) {
				continue;
			}

			$serial_id = (int) $item->get_meta( Assigner::RENEWAL_ITEM_META_KEY, true );

			if ( $serial_id && self::renew_serial( $serial_id ) ) {
				$item->add_meta_data( self::RENEWAL_APPLIED_META_KEY, 'yes' );
				$item->save_meta_data();
			}
		}
	}

	/**
	 * Extends expires_at from whichever is later — the serial's current
	 * expiry, or now — so renewing before expiry stacks the new term on top
	 * of what's left rather than wasting it, while renewing after expiry
	 * (the common case) just starts the new term from today. Re-validates
	 * is_renewable() itself rather than trusting the cart-time check, since
	 * a serial's eligibility could have changed while it sat in the cart.
	 */
	public static function renew_serial( int $serial_id ): bool {
		if ( ! Licensing::is_pro_active() ) {
			return false;
		}

		$serial = Repository::find( $serial_id );

		if ( ! $serial || ! self::is_renewable( $serial ) ) {
			return false;
		}

		$duration = LicenseKey::duration_for_product( (int) $serial->product_id );
		$months   = $duration['length'] * ( 'year' === $duration['period'] ? 12 : 1 );

		$current_expiry_ts = $serial->expires_at ? strtotime( $serial->expires_at ) : 0;
		$base_ts           = max( $current_expiry_ts, current_time( 'timestamp' ) );
		$new_expires_at    = gmdate( 'Y-m-d H:i:s', strtotime( "+{$months} months", $base_ts ) );

		Repository::renew( $serial_id, $new_expires_at );

		/**
		 * Fires after a license renews. Backs the license.renewed webhook
		 * topic.
		 *
		 * @param int    $serial_id
		 * @param string $new_expires_at
		 */
		do_action( 'snw_license_renewed', $serial_id, $new_expires_at );

		return true;
	}
}
