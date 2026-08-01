<?php
namespace SerialNumberForWooCommerce\Admin\Products;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;
use SerialNumberForWooCommerce\Licensing;
use SerialNumberForWooCommerce\Pro\StockSync\StockSync;

defined( 'ABSPATH' ) || exit;

/**
 * Free tier: adds a "Serial Number" tab to the product edit screen. Once
 * "Enable serial numbers" is checked, the tab splits into a Free Features
 * area and a Pro Features area — the latter shown but disabled with a PRO
 * badge when not licensed.
 */
final class ProductTab {

	const META_KEY = '_snw_enabled';

	const MANAGE_STOCK_META_KEY = '_snw_manage_stock';

	const BULK_ADD_FIELD = 'snw_bulk_serials';

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
		$is_pro = Licensing::is_pro_active();
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
				?>
			</div>

			<div id="snw-conditional-fields">
				<h4><?php esc_html_e( 'Free Features', 'serial-number-for-woocommerce' ); ?></h4>
				<div class="options_group">
					<?php
					woocommerce_wp_textarea_input(
						array(
							'id'          => self::BULK_ADD_FIELD,
							'label'       => __( 'Add Serial Numbers', 'serial-number-for-woocommerce' ),
							'description' => __( 'One serial number per line. Click "Add to Pool" to create them now, connected to this product — or just save the product and any left here are created automatically.', 'serial-number-for-woocommerce' ),
							'desc_tip'    => true,
							'placeholder' => "SN-0001\nSN-0002\nSN-0003",
							'rows'        => 6,
						)
					);
					?>
					<p class="form-field">
						<label>&nbsp;</label>
						<button type="button" id="snw-add-bulk-serials" class="button"><?php esc_html_e( 'Add to Pool', 'serial-number-for-woocommerce' ); ?></button>
						<span id="snw-bulk-serials-result" style="margin-left: 8px;"></span>
					</p>
				</div>

				<h4>
					<?php esc_html_e( 'Pro Features', 'serial-number-for-woocommerce' ); ?>
					<?php if ( ! $is_pro ) : ?>
						<span style="background: #7f54b3; color: #fff; font-size: 10px; font-weight: 600; padding: 2px 6px; border-radius: 3px; margin-left: 6px; vertical-align: middle;">
							<?php esc_html_e( 'PRO', 'serial-number-for-woocommerce' ); ?>
						</span>
					<?php endif; ?>
				</h4>
				<div class="options_group">
					<?php if ( $is_pro ) : ?>
						<?php
						woocommerce_wp_checkbox(
							array(
								'id'          => self::MANAGE_STOCK_META_KEY,
								'label'       => __( 'Manage product stock with Serial Number', 'serial-number-for-woocommerce' ),
								'description' => __( "Keeps this product's stock quantity equal to the number of Available serial numbers in its pool. Saving with this on sets the stock to match right away, and keeps it in sync as serial numbers are added, generated, or assigned to orders.", 'serial-number-for-woocommerce' ),
								'desc_tip'    => true,
								'value'       => get_post_meta( $post->ID, self::MANAGE_STOCK_META_KEY, true ),
							)
						);
						?>
					<?php else : ?>
						<p class="form-field">
							<label for="<?php echo esc_attr( self::MANAGE_STOCK_META_KEY ); ?>">
								<?php esc_html_e( 'Manage product stock with Serial Number', 'serial-number-for-woocommerce' ); ?>
							</label>
							<input
								type="checkbox"
								id="<?php echo esc_attr( self::MANAGE_STOCK_META_KEY ); ?>"
								disabled
								<?php checked( get_post_meta( $post->ID, self::MANAGE_STOCK_META_KEY, true ), 'yes' ); ?>
							/>
							<?php echo wc_help_tip( __( "Upgrade to Pro to automatically keep this product's stock in sync with its Serial Number pool.", 'serial-number-for-woocommerce' ) ); ?>
						</p>
					<?php endif; ?>
				</div>
			</div>
			<script>
			( function ( $ ) {
				var snwAjaxUrl    = <?php echo wp_json_encode( admin_url( 'admin-ajax.php' ) ); ?>;
				var snwNonce      = <?php echo wp_json_encode( wp_create_nonce( 'snw_admin' ) ); ?>;
				var snwProductId  = <?php echo (int) $post->ID; ?>;
				var snwIsPro      = <?php echo wp_json_encode( $is_pro ); ?>;

				function snwIsEnabled() {
					return $( '#<?php echo esc_js( self::META_KEY ); ?>' ).is( ':checked' );
				}

				function snwToggleConditionalFields() {
					$( '#snw-conditional-fields' ).toggle( snwIsEnabled() );
				}

				function snwToggleStockQuantityLock() {
					var lockedBySN = snwIsPro && snwIsEnabled() &&
						$( '#<?php echo esc_js( self::MANAGE_STOCK_META_KEY ); ?>' ).is( ':checked' );
					var $stock  = $( '#_stock' );
					var $notice = $( '#snw-stock-locked-notice' );

					if ( ! $notice.length ) {
						$notice = $( '<span id="snw-stock-locked-notice" class="description" style="display:block;"></span>' )
							.text( <?php echo wp_json_encode( __( 'Stock is managed automatically from the Serial Number pool while "Manage product stock with Serial Number" is enabled.', 'serial-number-for-woocommerce' ) ); ?> )
							.insertAfter( $stock );
					}

					$stock.prop( 'disabled', lockedBySN );
					$notice.toggle( lockedBySN );
				}

				$( '#<?php echo esc_js( self::META_KEY ); ?>, #<?php echo esc_js( self::MANAGE_STOCK_META_KEY ); ?>' )
					.on( 'change', function () {
						snwToggleConditionalFields();
						snwToggleStockQuantityLock();
					} );

				snwToggleConditionalFields();
				snwToggleStockQuantityLock();

				$( '#snw-add-bulk-serials' ).on( 'click', function ( e ) {
					e.preventDefault();

					var $button   = $( this );
					var $textarea = $( '#<?php echo esc_js( self::BULK_ADD_FIELD ); ?>' );
					var $result   = $( '#snw-bulk-serials-result' );

					if ( ! $.trim( $textarea.val() ) ) {
						return;
					}

					$button.prop( 'disabled', true );
					$result.text( <?php echo wp_json_encode( __( 'Adding…', 'serial-number-for-woocommerce' ) ); ?> );

					$.post( snwAjaxUrl, {
						action: 'snw_import_serials',
						nonce: snwNonce,
						product_id: snwProductId,
						serials: $textarea.val(),
					} )
						.done( function ( response ) {
							if ( ! response || ! response.success ) {
								$result.text( <?php echo wp_json_encode( __( 'Something went wrong.', 'serial-number-for-woocommerce' ) ); ?> );
								return;
							}

							var data = response.data;
							$result.text( data.message );
							$textarea.val( data.skipped && data.skipped.length ? data.skipped.join( "\n" ) : '' );
						} )
						.fail( function () {
							$result.text( <?php echo wp_json_encode( __( 'Something went wrong.', 'serial-number-for-woocommerce' ) ); ?> );
						} )
						.always( function () {
							$button.prop( 'disabled', false );
						} );
				} );
			} )( jQuery );
			</script>
		</div>
		<?php
	}

	public function save( int $product_id ): void {
		$enabled = isset( $_POST[ self::META_KEY ] ) ? 'yes' : 'no';
		update_post_meta( $product_id, self::META_KEY, $enabled );

		// Pro-only: never persist "yes" without a license, even if the field
		// were somehow submitted (it's rendered disabled when unlicensed, so
		// browsers don't submit it, but this keeps the meta honest either way).
		$manage_stock = ( 'yes' === $enabled && Licensing::is_pro_active() && isset( $_POST[ self::MANAGE_STOCK_META_KEY ] ) ) ? 'yes' : 'no';
		update_post_meta( $product_id, self::MANAGE_STOCK_META_KEY, $manage_stock );

		// Fallback for whatever's still in the bulk-add textarea at save time —
		// covers both "didn't click Add to Pool" and "AJAX unavailable". Safe to
		// re-run over lines Add to Pool already created: duplicates are skipped.
		if ( 'yes' === $enabled && isset( $_POST[ self::BULK_ADD_FIELD ] ) ) {
			$raw = (string) wp_unslash( $_POST[ self::BULK_ADD_FIELD ] );

			if ( '' !== trim( $raw ) ) {
				Repository::import_for_product( $product_id, $raw );
			}
		}

		if ( Licensing::is_pro_active() ) {
			StockSync::sync( $product_id );
		}
	}
}
