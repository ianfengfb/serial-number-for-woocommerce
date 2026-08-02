<?php
namespace SerialNumberForWooCommerce\Admin\Products;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;
use SerialNumberForWooCommerce\Licensing;
use SerialNumberForWooCommerce\Pro\StockSync\StockSync;

defined( 'ABSPATH' ) || exit;

/**
 * Free tier: adds a "Serial Number" tab to the product edit screen. Once
 * "Enable serial numbers" is checked, an unlabeled free-features area and a
 * "Pro Features" area appear — the latter shown but disabled with a PRO
 * badge when not licensed.
 */
final class ProductTab {

	const META_KEY = '_snw_enabled';

	const MANAGE_STOCK_META_KEY = '_snw_manage_stock';

	const BULK_ADD_FIELD = 'snw_bulk_serials';

	const CUSTOM_RULE_ENABLED_META_KEY = '_snw_custom_rule_enabled';

	const CUSTOM_PREFIX_META_KEY = '_snw_custom_prefix';

	const CUSTOM_SUFFIX_META_KEY = '_snw_custom_suffix';

	const CUSTOM_LENGTH_META_KEY = '_snw_custom_length';

	const CUSTOM_CHARSET_META_KEY = '_snw_custom_charset';

	const WARRANTY_ENABLED_META_KEY = '_snw_warranty_enabled';

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
		<style>
			/* Matched by the tab's href (always #snw_product_data, our own target)
			   rather than a guessed WooCommerce-generated class name, so this
			   doesn't depend on WC's internal tab-class naming convention. */
			#woocommerce-product-data ul.wc-tabs li a[href="#snw_product_data"]::before {
				font-family: 'WooCommerce' !important;
				content: '' !important;
				display: inline-block;
				width: 16px;
				height: 16px;
				vertical-align: text-bottom;
				background-color: currentColor;
				-webkit-mask-image: url("<?php echo self::tab_icon_data_uri(); ?>");
				mask-image: url("<?php echo self::tab_icon_data_uri(); ?>");
				-webkit-mask-repeat: no-repeat;
				mask-repeat: no-repeat;
				-webkit-mask-position: center;
				mask-position: center;
				-webkit-mask-size: 16px 16px;
				mask-size: 16px 16px;
			}
		</style>
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

					<?php if ( $is_pro ) : ?>
						<?php
						woocommerce_wp_checkbox(
							array(
								'id'          => self::CUSTOM_RULE_ENABLED_META_KEY,
								'label'       => __( 'Use a custom auto-generation rule for this product', 'serial-number-for-woocommerce' ),
								'description' => __( 'Overrides the global prefix/suffix/character-set/length rule from WooCommerce > Settings > Serial Numbers for this product only.', 'serial-number-for-woocommerce' ),
								'desc_tip'    => true,
								'value'       => get_post_meta( $post->ID, self::CUSTOM_RULE_ENABLED_META_KEY, true ),
							)
						);
						?>
					<?php else : ?>
						<p class="form-field">
							<label for="<?php echo esc_attr( self::CUSTOM_RULE_ENABLED_META_KEY ); ?>">
								<?php esc_html_e( 'Use a custom auto-generation rule for this product', 'serial-number-for-woocommerce' ); ?>
							</label>
							<input
								type="checkbox"
								id="<?php echo esc_attr( self::CUSTOM_RULE_ENABLED_META_KEY ); ?>"
								disabled
								<?php checked( get_post_meta( $post->ID, self::CUSTOM_RULE_ENABLED_META_KEY, true ), 'yes' ); ?>
							/>
							<?php echo wc_help_tip( __( 'Upgrade to Pro to set a custom prefix/suffix/character-set/length rule for this product only.', 'serial-number-for-woocommerce' ) ); ?>
						</p>
					<?php endif; ?>

					<div id="snw-custom-rule-fields">
						<?php
						$this->rule_text_field(
							self::CUSTOM_PREFIX_META_KEY,
							__( 'Prefix', 'serial-number-for-woocommerce' ),
							__( 'Leave blank to use the global prefix.', 'serial-number-for-woocommerce' ),
							get_post_meta( $post->ID, self::CUSTOM_PREFIX_META_KEY, true ),
							$is_pro
						);

						$this->rule_text_field(
							self::CUSTOM_SUFFIX_META_KEY,
							__( 'Suffix', 'serial-number-for-woocommerce' ),
							__( 'Leave blank to use the global suffix.', 'serial-number-for-woocommerce' ),
							get_post_meta( $post->ID, self::CUSTOM_SUFFIX_META_KEY, true ),
							$is_pro
						);

						woocommerce_wp_select(
							array(
								'id'                => self::CUSTOM_CHARSET_META_KEY,
								'label'             => __( 'Character set', 'serial-number-for-woocommerce' ),
								'description'       => __( 'Leave as "Use global setting" to use the global character set.', 'serial-number-for-woocommerce' ),
								'desc_tip'          => true,
								'value'             => get_post_meta( $post->ID, self::CUSTOM_CHARSET_META_KEY, true ),
								'options'           => array(
									''             => __( 'Use global setting', 'serial-number-for-woocommerce' ),
									'alphanumeric' => __( 'Letters and numbers', 'serial-number-for-woocommerce' ),
									'numeric'      => __( 'Numbers only', 'serial-number-for-woocommerce' ),
									'alpha'        => __( 'Letters only', 'serial-number-for-woocommerce' ),
								),
								'custom_attributes' => $is_pro ? array() : array( 'disabled' => 'disabled' ),
							)
						);

						woocommerce_wp_text_input(
							array(
								'id'                => self::CUSTOM_LENGTH_META_KEY,
								'label'             => __( 'Random part length', 'serial-number-for-woocommerce' ),
								'description'       => __( 'Leave blank to use the global length.', 'serial-number-for-woocommerce' ),
								'desc_tip'          => true,
								'type'              => 'number',
								'value'             => get_post_meta( $post->ID, self::CUSTOM_LENGTH_META_KEY, true ),
								'custom_attributes' => array_merge(
									array(
										'min'  => 1,
										'max'  => 64,
										'step' => 1,
									),
									$is_pro ? array() : array( 'disabled' => 'disabled' )
								),
							)
						);
						?>
					</div>

					<?php if ( $is_pro ) : ?>
						<?php
						woocommerce_wp_checkbox(
							array(
								'id'          => self::WARRANTY_ENABLED_META_KEY,
								'label'       => __( 'Enable warranty for this product', 'serial-number-for-woocommerce' ),
								'description' => __( 'Tracks a warranty against each serial number assigned for this product.', 'serial-number-for-woocommerce' ),
								'desc_tip'    => true,
								'value'       => get_post_meta( $post->ID, self::WARRANTY_ENABLED_META_KEY, true ),
							)
						);
						?>
					<?php else : ?>
						<p class="form-field">
							<label for="<?php echo esc_attr( self::WARRANTY_ENABLED_META_KEY ); ?>">
								<?php esc_html_e( 'Enable warranty for this product', 'serial-number-for-woocommerce' ); ?>
							</label>
							<input
								type="checkbox"
								id="<?php echo esc_attr( self::WARRANTY_ENABLED_META_KEY ); ?>"
								disabled
								<?php checked( get_post_meta( $post->ID, self::WARRANTY_ENABLED_META_KEY, true ), 'yes' ); ?>
							/>
							<?php echo wc_help_tip( __( 'Upgrade to Pro to track a warranty against each serial number assigned for this product.', 'serial-number-for-woocommerce' ) ); ?>
						</p>
					<?php endif; ?>

					<p class="form-field">
						<label for="snw-bulk-generate-amount"><?php esc_html_e( 'Bulk generate this amount of serial numbers', 'serial-number-for-woocommerce' ); ?></label>
						<input
							type="number"
							id="snw-bulk-generate-amount"
							min="1"
							max="500"
							value="1"
							class="small-text"
							<?php disabled( ! $is_pro ); ?>
						/>
						<button type="button" id="snw-bulk-generate-product" class="button" <?php disabled( ! $is_pro ); ?>><?php esc_html_e( 'Generate', 'serial-number-for-woocommerce' ); ?></button>
						<span id="snw-bulk-generate-product-result" style="margin-left: 8px;"></span>
						<?php echo wc_help_tip( __( 'Generates this many serial numbers now, connected to this product, using the rule above (or the global rule if not overridden). Uses the saved rule — save the product first if you just changed it.', 'serial-number-for-woocommerce' ) ); ?>
					</p>
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

				function snwToggleCustomRuleFields() {
					$( '#snw-custom-rule-fields' ).toggle(
						snwIsPro && $( '#<?php echo esc_js( self::CUSTOM_RULE_ENABLED_META_KEY ); ?>' ).is( ':checked' )
					);
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

				$( '#<?php echo esc_js( self::CUSTOM_RULE_ENABLED_META_KEY ); ?>' ).on( 'change', snwToggleCustomRuleFields );

				snwToggleConditionalFields();
				snwToggleCustomRuleFields();
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

				$( '#snw-bulk-generate-product' ).on( 'click', function ( e ) {
					e.preventDefault();

					var $button = $( this );
					var $amount = $( '#snw-bulk-generate-amount' );
					var $result = $( '#snw-bulk-generate-product-result' );

					var amount = parseInt( $amount.val(), 10 );

					if ( ! amount || amount < 1 ) {
						return;
					}

					$button.prop( 'disabled', true );
					$result.text( <?php echo wp_json_encode( __( 'Generating…', 'serial-number-for-woocommerce' ) ); ?> );

					$.post( snwAjaxUrl, {
						action: 'snw_bulk_generate_for_product',
						nonce: snwNonce,
						product_id: snwProductId,
						amount: amount,
					} )
						.done( function ( response ) {
							if ( ! response || ! response.success ) {
								$result.text(
									( response && response.data && response.data.message ) ||
									<?php echo wp_json_encode( __( 'Something went wrong.', 'serial-number-for-woocommerce' ) ); ?>
								);
								return;
							}

							$result.text( response.data.message );
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

	/**
	 * A small barcode icon for the Serial Number tab, as a mask-image data URI
	 * rather than a WooCommerce/dashicons font glyph — avoids depending on a
	 * specific icon font having a matching character at a guessed codepoint,
	 * and `background-color: currentColor` still lets it recolor with the tab
	 * exactly like WooCommerce's own font-icon tabs do.
	 */
	private static function tab_icon_data_uri(): string {
		$svg = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24">'
			. '<rect x="2" y="4" width="2" height="16"/>'
			. '<rect x="6" y="4" width="1" height="16"/>'
			. '<rect x="9" y="4" width="3" height="16"/>'
			. '<rect x="14" y="4" width="1" height="16"/>'
			. '<rect x="17" y="4" width="2" height="16"/>'
			. '<rect x="21" y="4" width="1" height="16"/>'
			. '</svg>';

		return 'data:image/svg+xml,' . rawurlencode( $svg );
	}

	/**
	 * Renders a text field for the custom-rule fields row (prefix/suffix),
	 * disabled when not licensed but always shown, matching the Pro area's
	 * "visible but greyed out" teaser convention.
	 */
	private function rule_text_field( string $id, string $label, string $description, string $value, bool $is_pro ): void {
		woocommerce_wp_text_input(
			array(
				'id'                => $id,
				'label'             => $label,
				'description'       => $description,
				'desc_tip'          => true,
				'value'             => $value,
				'custom_attributes' => $is_pro ? array() : array( 'disabled' => 'disabled' ),
			)
		);
	}

	public function save( int $product_id ): void {
		$enabled = isset( $_POST[ self::META_KEY ] ) ? 'yes' : 'no';
		update_post_meta( $product_id, self::META_KEY, $enabled );

		$is_pro = Licensing::is_pro_active();

		// Pro-only: never persist "yes" without a license, even if the field
		// were somehow submitted (it's rendered disabled when unlicensed, so
		// browsers don't submit it, but this keeps the meta honest either way).
		$manage_stock = ( 'yes' === $enabled && $is_pro && isset( $_POST[ self::MANAGE_STOCK_META_KEY ] ) ) ? 'yes' : 'no';
		update_post_meta( $product_id, self::MANAGE_STOCK_META_KEY, $manage_stock );

		$custom_rule_enabled = ( 'yes' === $enabled && $is_pro && isset( $_POST[ self::CUSTOM_RULE_ENABLED_META_KEY ] ) ) ? 'yes' : 'no';
		update_post_meta( $product_id, self::CUSTOM_RULE_ENABLED_META_KEY, $custom_rule_enabled );

		$warranty_enabled = ( 'yes' === $enabled && $is_pro && isset( $_POST[ self::WARRANTY_ENABLED_META_KEY ] ) ) ? 'yes' : 'no';
		update_post_meta( $product_id, self::WARRANTY_ENABLED_META_KEY, $warranty_enabled );

		// The rule field values themselves are kept even while the checkbox is
		// off, so re-enabling it later doesn't lose what was typed in — only
		// CustomRules::is_enabled_for_product() gates whether they take effect.
		if ( $is_pro ) {
			update_post_meta(
				$product_id,
				self::CUSTOM_PREFIX_META_KEY,
				isset( $_POST[ self::CUSTOM_PREFIX_META_KEY ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::CUSTOM_PREFIX_META_KEY ] ) ) : ''
			);
			update_post_meta(
				$product_id,
				self::CUSTOM_SUFFIX_META_KEY,
				isset( $_POST[ self::CUSTOM_SUFFIX_META_KEY ] ) ? sanitize_text_field( wp_unslash( $_POST[ self::CUSTOM_SUFFIX_META_KEY ] ) ) : ''
			);

			$length = isset( $_POST[ self::CUSTOM_LENGTH_META_KEY ] ) ? absint( $_POST[ self::CUSTOM_LENGTH_META_KEY ] ) : 0;
			update_post_meta( $product_id, self::CUSTOM_LENGTH_META_KEY, $length ? (string) max( 1, min( 64, $length ) ) : '' );

			$charset = isset( $_POST[ self::CUSTOM_CHARSET_META_KEY ] ) ? sanitize_key( wp_unslash( $_POST[ self::CUSTOM_CHARSET_META_KEY ] ) ) : '';
			update_post_meta( $product_id, self::CUSTOM_CHARSET_META_KEY, in_array( $charset, array( 'alphanumeric', 'numeric', 'alpha' ), true ) ? $charset : '' );
		}

		// Fallback for whatever's still in the bulk-add textarea at save time —
		// covers both "didn't click Add to Pool" and "AJAX unavailable". Safe to
		// re-run over lines Add to Pool already created: duplicates are skipped.
		if ( 'yes' === $enabled && isset( $_POST[ self::BULK_ADD_FIELD ] ) ) {
			$raw = (string) wp_unslash( $_POST[ self::BULK_ADD_FIELD ] );

			if ( '' !== trim( $raw ) ) {
				Repository::import_for_product( $product_id, $raw );
			}
		}

		if ( $is_pro ) {
			StockSync::sync( $product_id );
		}
	}
}
