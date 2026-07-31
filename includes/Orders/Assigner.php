<?php
namespace SerialNumberForWooCommerce\Orders;

use SerialNumberForWooCommerce\Admin\Products\ProductTab;
use SerialNumberForWooCommerce\Admin\SerialNumbers\Generator;
use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;
use SerialNumberForWooCommerce\Admin\SerialNumbers\Status;

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
		$assigned = 0;

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

				if ( ! $serial_id ) {
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
