<?php
namespace SerialNumberForWooCommerce\Admin\Products;

use SerialNumberForWooCommerce\Products\StockSync;

defined( 'ABSPATH' ) || exit;

/**
 * Free tier: adds a "Serial Number" tab to the product edit screen.
 */
final class ProductTab {

	const META_KEY = '_snw_enabled';

	const MANAGE_STOCK_META_KEY = '_snw_manage_stock';

	public function __construct() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ) );
	}

	public function add_tab( array $tabs ): array {
		$tabs['snw_serial_number'] = array(
			'label'    => __( 'Serial Number', 'serial-number-for-woocommerce' ),
			'target'   => 'snw_product_data',
			'class'    => array(),
			'priority' => 70,
		);

		return $tabs;
	}

	public function render_panel(): void {
		global $post;
		?>
		<div id="snw_product_data" class="panel woocommerce_options_panel">
			<div class="options_group">
				<?php
				woocommerce_wp_checkbox(
					array(
						'id'          => self::META_KEY,
						'label'       => __( 'Enable serial numbers', 'serial-number-for-woocommerce' ),
						'description' => __( 'When an order is placed for this product, a serial number is assigned automatically — one already in the pool if available, otherwise a new one is generated using the rules in WooCommerce > Settings > Serial Numbers.', 'serial-number-for-woocommerce' ),
						'desc_tip'    => true,
						'value'       => get_post_meta( $post->ID, self::META_KEY, true ),
					)
				);

				woocommerce_wp_checkbox(
					array(
						'id'            => self::MANAGE_STOCK_META_KEY,
						'label'         => __( 'Manage product stock with Serial Number', 'serial-number-for-woocommerce' ),
						'description'   => __( "Keeps this product's stock quantity equal to the number of Available serial numbers in its pool. Saving with this on sets the stock to match right away, and keeps it in sync as serial numbers are added, generated, or assigned to orders.", 'serial-number-for-woocommerce' ),
						'desc_tip'      => true,
						'value'         => get_post_meta( $post->ID, self::MANAGE_STOCK_META_KEY, true ),
						'wrapper_class' => 'snw_manage_stock_field',
					)
				);
				?>
			</div>
			<script>
			( function ( $ ) {
				function snwToggleManageStockField() {
					$( '.snw_manage_stock_field' ).toggle( $( '#<?php echo esc_js( self::META_KEY ); ?>' ).is( ':checked' ) );
				}

				$( '#<?php echo esc_js( self::META_KEY ); ?>' ).on( 'change', snwToggleManageStockField );
				snwToggleManageStockField();
			} )( jQuery );
			</script>
		</div>
		<?php
	}

	public function save( int $product_id ): void {
		$enabled = isset( $_POST[ self::META_KEY ] ) ? 'yes' : 'no';
		update_post_meta( $product_id, self::META_KEY, $enabled );

		// The manage-stock checkbox is only meaningful while serial numbers
		// are enabled, so a stale posted value from a hidden field can't stick.
		$manage_stock = ( 'yes' === $enabled && isset( $_POST[ self::MANAGE_STOCK_META_KEY ] ) ) ? 'yes' : 'no';
		update_post_meta( $product_id, self::MANAGE_STOCK_META_KEY, $manage_stock );

		StockSync::sync( $product_id );
	}
}
