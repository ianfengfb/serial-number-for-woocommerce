<?php
namespace SerialNumberForWooCommerce\Orders;

use SerialNumberForWooCommerce\Admin\Products\ProductTab;
use SerialNumberForWooCommerce\Admin\SerialNumbers\Generator;
use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;
use SerialNumberForWooCommerce\Admin\SerialNumbers\Status;
use SerialNumberForWooCommerce\Licensing;
use SerialNumberForWooCommerce\Pro\StockSync\StockSync;

defined( 'ABSPATH' ) || exit;

/**
 * Free tier: hands serial numbers to order line items when an order is placed.
 *
 * For every line item whose product has "Enable serial numbers" ticked on the
 * product edit screen, one serial per ordered unit is taken from that product's
 * pool of Available serials; when the pool runs dry the shortfall is generated
 * on the spot from the rules in WooCommerce > Settings > Serial Numbers. Either
 * way the serial ends up Assigned to the order.
 *
 * The IDs of the serials handed to an item are stored on the item itself, which
 * both records what was given out and makes assign_for_order() safe to call
 * again: an item that already holds enough serials is skipped.
 */
final class Assigner {

	/** Order item meta holding the assigned serial numbers' row IDs. */
	const ITEM_META_KEY = '_snw_serial_ids';

	public function __construct() {
		// Classic checkout.
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'on_checkout_order_processed' ), 10, 3 );
		// Blocks / Store API checkout — hook renamed in WooCommerce 8.3, so both are bound.
		add_action( 'woocommerce_store_api_checkout_order_processed', array( $this, 'on_order' ) );
		add_action( 'woocommerce_blocks_checkout_order_processed', array( $this, 'on_order' ) );
	}

	/**
	 * @param mixed $order Third arg is the WC_Order on WC 3.0+, but stays loose
	 *                     because the hook has historically passed less.
	 */
	public function on_checkout_order_processed( int $order_id, array $posted_data, $order = null ): void {
		$order = $order instanceof \WC_Order ? $order : wc_get_order( $order_id );

		$this->on_order( $order );
	}

	/**
	 * @param mixed $order WC_Order, or anything else if a third party mis-fires the hook.
	 */
	public function on_order( $order ): void {
		if ( $order instanceof \WC_Order ) {
			self::assign_for_order( $order );
		}
	}

	/**
	 * Tops every eligible line item up to one serial number per ordered unit.
	 *
	 * Safe to run repeatedly — only the shortfall between the item's quantity
	 * and the serials it already holds is assigned. Returns how many serials
	 * were handed out on this run.
	 */
	public static function assign_for_order( \WC_Order $order ): int {
		$assigned         = 0;
		$claimed_products = array();

		foreach ( $order->get_items() as $item ) {
			if ( ! $item instanceof \WC_Order_Item_Product ) {
				continue;
			}

			$product_id = $item->get_product_id();

			if ( ! $product_id || ! self::is_enabled_for_product( $product_id ) ) {
				continue;
			}

			$serial_ids = self::serial_ids( $item );
			$needed     = max( 0, $item->get_quantity() - count( $serial_ids ) );

			if ( ! $needed ) {
				continue;
			}

			$added = 0;

			for ( $i = 0; $i < $needed; $i++ ) {
				$serial_id = Repository::claim_available( $product_id, $order->get_id() );

				if ( $serial_id ) {
					$claimed_products[ $product_id ] = true;
				} else {
					$serial_id = self::generate_assigned( $product_id, $order->get_id() );
				}

				// Nothing left in the pool and generation failed too; don't spin.
				if ( ! $serial_id ) {
					break;
				}

				$serial_ids[] = $serial_id;
				++$added;
				++$assigned;
			}

			if ( $added ) {
				$item->update_meta_data( self::ITEM_META_KEY, $serial_ids );
				$item->save();
			}
		}

		// Only claiming from the pool changes a product's Available count —
		// generate_assigned() never touches it, so it's excluded here.
		if ( Licensing::is_pro_active() ) {
			foreach ( array_keys( $claimed_products ) as $product_id ) {
				StockSync::sync( $product_id );
			}
		}

		return $assigned;
	}

	/**
	 * Whether the product has serial numbers switched on. Read from the parent
	 * product, which is where the Serial Number tab's checkbox lives, so all
	 * variations of a variable product share its setting and its pool.
	 */
	public static function is_enabled_for_product( int $product_id ): bool {
		return 'yes' === get_post_meta( $product_id, ProductTab::META_KEY, true );
	}

	/**
	 * Row IDs of the serial numbers already assigned to a line item.
	 */
	public static function serial_ids( \WC_Order_Item_Product $item ): array {
		$ids = $item->get_meta( self::ITEM_META_KEY, true );

		return is_array( $ids ) ? array_values( array_filter( array_map( 'absint', $ids ) ) ) : array();
	}

	/**
	 * Serial number strings assigned to a line item, resolved from
	 * serial_ids() — shared by every read-only display of an item's
	 * serials (admin order edit screen, customer emails/account view).
	 */
	public static function serial_numbers( \WC_Order_Item_Product $item ): array {
		$serial_numbers = array();

		foreach ( self::serial_ids( $item ) as $serial_id ) {
			$serial = Repository::find( $serial_id );

			if ( $serial ) {
				$serial_numbers[] = $serial->serial_number;
			}
		}

		return $serial_numbers;
	}

	/**
	 * The reverse of serial_ids(): which of an order's line items holds a
	 * given serial. Used by Warranty::activate_serial() (Pro) to look up an
	 * order-item-level warranty extension for a specific serial.
	 */
	public static function find_item_for_serial( \WC_Order $order, int $serial_id ): ?\WC_Order_Item_Product {
		foreach ( $order->get_items() as $item ) {
			if ( $item instanceof \WC_Order_Item_Product && in_array( $serial_id, self::serial_ids( $item ), true ) ) {
				return $item;
			}
		}

		return null;
	}

	/**
	 * Backs the "Add Serial Number" control on the admin order edit screen,
	 * for orders whose item never got auto-assigned (e.g. the product wasn't
	 * serial-number-enabled yet at checkout):
	 *
	 * - Unknown serial number: created fresh, Assigned to this item's product/order.
	 * - Known, unassigned, and matching this item's product: updated to Assigned
	 *   and tied to this order.
	 * - Known but already assigned to an order, or tied to a different product:
	 *   rejected rather than silently reassigned or detached from its owner.
	 *
	 * @return array{success: bool, message: string}
	 */
	public static function add_manual_serial( \WC_Order_Item_Product $item, string $serial_number ): array {
		$serial_number = trim( $serial_number );

		if ( '' === $serial_number ) {
			return array(
				'success' => false,
				'message' => __( 'Please enter a serial number.', 'serial-number-for-woocommerce' ),
			);
		}

		$product_id = $item->get_product_id();
		$order_id   = $item->get_order_id();
		$existing   = Repository::find_by_serial_number( $serial_number );

		if ( $existing ) {
			$existing_order_id   = (int) ( $existing->order_id ?? 0 );
			$existing_product_id = (int) ( $existing->product_id ?? 0 );

			if ( $existing_order_id ) {
				return array(
					'success' => false,
					'message' => sprintf(
						/* translators: %s: the serial number that was typed in */
						__( '"%s" is already assigned to another order.', 'serial-number-for-woocommerce' ),
						$serial_number
					),
				);
			}

			if ( $existing_product_id !== $product_id ) {
				return array(
					'success' => false,
					'message' => sprintf(
						/* translators: %s: the serial number that was typed in */
						__( '"%s" belongs to a different product.', 'serial-number-for-woocommerce' ),
						$serial_number
					),
				);
			}

			Repository::update(
				$existing->id,
				array(
					'serial_number' => $existing->serial_number,
					'status'        => Status::ASSIGNED,
					'product_id'    => $product_id,
					'order_id'      => $order_id,
					'expires_at'    => $existing->expires_at,
				)
			);

			$serial_id = (int) $existing->id;
		} else {
			$serial_id = Repository::insert(
				array(
					'serial_number' => $serial_number,
					'status'        => Status::ASSIGNED,
					'product_id'    => $product_id,
					'order_id'      => $order_id,
					'expires_at'    => '',
				)
			);

			if ( ! $serial_id ) {
				return array(
					'success' => false,
					'message' => __( 'Could not save this serial number.', 'serial-number-for-woocommerce' ),
				);
			}
		}

		$serial_ids = self::serial_ids( $item );

		if ( ! in_array( $serial_id, $serial_ids, true ) ) {
			$serial_ids[] = $serial_id;
			$item->update_meta_data( self::ITEM_META_KEY, $serial_ids );
			$item->save();
		}

		if ( Licensing::is_pro_active() && $product_id ) {
			StockSync::sync( $product_id );
		}

		return array(
			'success' => true,
			'message' => __( 'Serial number added.', 'serial-number-for-woocommerce' ),
		);
	}

	/**
	 * Creates a brand new serial number already Assigned to the order, for when
	 * the product's pool has nothing left to hand out. Returns 0 if the
	 * generated value still collided with an existing one.
	 */
	private static function generate_assigned( int $product_id, int $order_id ): int {
		$serial_number = Generator::generate();

		if ( '' === $serial_number || Repository::exists( $serial_number ) ) {
			return 0;
		}

		return Repository::insert(
			array(
				'serial_number' => $serial_number,
				'status'        => Status::ASSIGNED,
				'product_id'    => $product_id,
				'order_id'      => $order_id,
				'expires_at'    => '',
			)
		);
	}
}
