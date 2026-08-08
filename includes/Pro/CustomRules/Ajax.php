<?php
namespace SerialNumberForWooCommerce\Pro\CustomRules;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Generator;
use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;
use SerialNumberForWooCommerce\Admin\SerialNumbers\Status;
use SerialNumberForWooCommerce\Pro\StockSync\StockSync;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: generates a given amount of serial numbers for one product from the
 * product edit screen, using its own custom rule if enabled (else the
 * global rule). Backs the Serial Number tab's "Bulk generate" button. Reads
 * the rule fields exactly as posted with this request (see
 * overrides_from_request()) rather than the product's saved meta, so a
 * rule that was just checked/typed but not yet saved still applies.
 */
final class Ajax {

	const MAX_AMOUNT = 500;

	public function __construct() {
		add_action( 'wp_ajax_snw_bulk_generate_for_product', array( $this, 'bulk_generate_for_product' ) );
	}

	public function bulk_generate_for_product(): void {
		check_ajax_referer( 'snw_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array(), 403 );
		}

		$product_id = isset( $_POST['product_id'] ) ? absint( $_POST['product_id'] ) : 0;
		$amount     = isset( $_POST['amount'] ) ? absint( $_POST['amount'] ) : 0;

		if ( ! $product_id || ! wc_get_product( $product_id ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid product.', 'serial-number-for-woocommerce' ) ) );
		}

		if ( $amount < 1 || $amount > self::MAX_AMOUNT ) {
			wp_send_json_error(
				array(
					/* translators: %d: maximum amount allowed */
					'message' => sprintf( __( 'Amount must be between 1 and %d.', 'serial-number-for-woocommerce' ), self::MAX_AMOUNT ),
				)
			);
		}

		$overrides = $this->overrides_from_request();
		$status    = Status::configured_default();
		$created   = 0;

		for ( $i = 0; $i < $amount; $i++ ) {
			$inserted = Repository::insert(
				array(
					'serial_number' => Generator::generate( $overrides ),
					'status'        => $status,
					'product_id'    => $product_id,
					'order_id'      => 0,
					'expires_at'    => '',
				)
			);

			if ( $inserted ) {
				++$created;
			}
		}

		$stock_quantity = $created ? StockSync::sync( $product_id ) : null;

		wp_send_json_success(
			array(
				/* translators: %d: number of serial numbers generated */
				'message'        => sprintf( _n( '%d serial number generated.', '%d serial numbers generated.', $created, 'serial-number-for-woocommerce' ), $created ),
				'created'        => $created,
				'stock_quantity' => $stock_quantity,
			)
		);
	}

	/**
	 * Builds the effective Generator overrides from the product tab's own
	 * rule fields exactly as posted with this request — i.e. whatever's
	 * currently in the form, including anything just checked/typed but not
	 * yet saved — rather than CustomRules::resolve_overrides()'s saved-meta
	 * lookup. This is what lets the "Bulk generate" button apply a rule
	 * change immediately without saving the product first. When the
	 * checkbox itself isn't posted as checked, no product-specific
	 * override applies at all, matching is_enabled_for_product()'s own
	 * "off means off" behavior for the saved case.
	 */
	private function overrides_from_request(): array {
		if ( ! isset( $_POST['rule_enabled'] ) || 'yes' !== $_POST['rule_enabled'] ) {
			return array();
		}

		return array(
			'prefix'  => isset( $_POST['prefix'] ) ? sanitize_text_field( wp_unslash( $_POST['prefix'] ) ) : '',
			'suffix'  => isset( $_POST['suffix'] ) ? sanitize_text_field( wp_unslash( $_POST['suffix'] ) ) : '',
			'charset' => isset( $_POST['charset'] ) ? sanitize_key( wp_unslash( $_POST['charset'] ) ) : '',
			'length'  => isset( $_POST['length'] ) ? absint( $_POST['length'] ) : 0,
		);
	}
}
