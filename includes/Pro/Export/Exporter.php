<?php
namespace SerialNumberForWooCommerce\Pro\Export;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: exports the (optionally search/product-filtered) Serial Numbers list
 * to a CSV file, streamed directly rather than rendered as an admin page.
 */
final class Exporter {

	public function __construct() {
		add_action( 'admin_post_snw_export_serials', array( $this, 'export' ) );
	}

	public function export(): void {
		check_admin_referer( 'snw_export_serials' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'serial-number-for-woocommerce' ) );
		}

		$search  = isset( $_GET['s'] ) ? sanitize_text_field( wp_unslash( $_GET['s'] ) ) : '';
		$filters = array(
			'no_product' => isset( $_GET['snw_filter_no_product'] ) ? 1 : 0,
			'product_id' => isset( $_GET['snw_filter_product_id'] ) ? absint( $_GET['snw_filter_product_id'] ) : 0,
		);

		$rows = Repository::search_all( $search, $filters );

		if ( ob_get_level() ) {
			ob_end_clean();
		}

		nocache_headers();
		header( 'Content-Type: text/csv; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename=serial-numbers-' . gmdate( 'Y-m-d' ) . '.csv' );

		$output = fopen( 'php://output', 'w' );

		// UTF-8 BOM so Excel opens accented/non-ASCII characters correctly.
		fwrite( $output, "\xEF\xBB\xBF" );

		fputcsv(
			$output,
			array( 'serial_number', 'status', 'product_id', 'product_sku', 'product_name', 'order_id', 'created_at', 'expires_at' )
		);

		foreach ( $rows as $row ) {
			$product = $row->product_id ? wc_get_product( $row->product_id ) : null;

			fputcsv(
				$output,
				array(
					$row->serial_number,
					$row->status,
					$row->product_id ?: '',
					$product ? $product->get_sku() : '',
					$product ? $product->get_name() : '',
					$row->order_id ?: '',
					$row->created_at,
					$row->expires_at ?: '',
				)
			);
		}

		fclose( $output );
		exit;
	}
}
