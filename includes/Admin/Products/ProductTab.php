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

	const WARRANTY_LENGTH_META_KEY = '_snw_warranty_length';

	const WARRANTY_PERIOD_META_KEY = '_snw_warranty_period';

	const WARRANTY_EXTENSION_ENABLED_META_KEY = '_snw_warranty_extension_enabled';

	const WARRANTY_EXTENSION_LENGTH_META_KEY = '_snw_warranty_extension_length';

	const WARRANTY_EXTENSION_PERIOD_META_KEY = '_snw_warranty_extension_period';

	const WARRANTY_EXTENSION_PRICE_META_KEY = '_snw_warranty_extension_price';

	const LICENSE_ENABLED_META_KEY = '_snw_license_enabled';

	const LICENSE_LENGTH_META_KEY = '_snw_license_length';

	const LICENSE_PERIOD_META_KEY = '_snw_license_period';

	const LICENSE_ACTIVATION_TRIGGER_META_KEY = '_snw_license_activation_trigger';

	const LICENSE_INSTRUCTIONS_META_KEY = '_snw_license_instructions';

	const LICENSE_RENEWAL_PRICE_META_KEY = '_snw_license_renewal_price';

	public function __construct() {
		add_filter( 'woocommerce_product_data_tabs', array( $this, 'add_tab' ) );
		add_action( 'woocommerce_product_data_panels', array( $this, 'render_panel' ) );
		add_action( 'woocommerce_process_product_meta', array( $this, 'save' ) );
	}

	public function add_tab( array $tabs ): array {
		$tabs['snw_serial_number'] = array(
			'label'    => __( 'Serial Number', 'serial-number-for-woocommerce' ),
			'target'   => 'snw_product_data',
			// Hidden for Grouped/External via WooCommerce's own tab-visibility
			// convention — neither can ever generate an order line item on
			// this site (Grouped has no price/cart button of its own;
			// External redirects offsite to buy), so "Enable serial numbers"
			// on either is dead UI that could never do anything.
			'class'    => array( 'show_if_simple', 'show_if_variable' ),
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
			   doesn't depend on WC's internal tab-class naming convention.
			   Uses the same "WooCommerce" icon font as the built-in tabs
			   (general/inventory/shipping/etc.) — \e028 isn't used by any of
			   those, so it reads as a distinct tab rather than a duplicate. */
			#woocommerce-product-data ul.wc-tabs li a[href="#snw_product_data"]::before {
				font-family: 'WooCommerce' !important;
				content: '\e028' !important;
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
						// hide_if_variable: each variation manages its own stock
						// separately in WooCommerce (inside the Variations tab's
						// repeater) — this checkbox's #_stock-based sync can't
						// target the right field(s) for a Variable product, so
						// it's scoped to Simple only. save() enforces this
						// server-side too, since hiding via CSS doesn't stop a
						// previously-checked value from still submitting.
						woocommerce_wp_checkbox(
							array(
								'id'            => self::MANAGE_STOCK_META_KEY,
								'label'         => __( 'Manage product stock with Serial Number', 'serial-number-for-woocommerce' ),
								'description'   => __( "Keeps this product's stock quantity equal to the number of Available serial numbers in its pool. Saving with this on sets the stock to match right away, and keeps it in sync as serial numbers are added, generated, or assigned to orders.", 'serial-number-for-woocommerce' ),
								'desc_tip'      => true,
								'value'         => get_post_meta( $post->ID, self::MANAGE_STOCK_META_KEY, true ),
								'wrapper_class' => 'hide_if_variable',
							)
						);
						?>
					<?php else : ?>
						<p class="form-field hide_if_variable">
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
						<?php echo wc_help_tip( __( 'Generates this many serial numbers now, connected to this product, using the rule above (or the global rule if not overridden) — including any change to that rule you haven\'t saved yet.', 'serial-number-for-woocommerce' ) ); ?>
					</p>

					<?php if ( $is_pro ) : ?>
						<?php
						woocommerce_wp_checkbox(
							array(
								'id'          => self::WARRANTY_ENABLED_META_KEY,
								'label'       => __( 'Enable warranty for this product', 'serial-number-for-woocommerce' ),
								'description' => __( 'Tracks a warranty against each serial number assigned for this product. Mutually exclusive with License Key below — a serial can only track one expiration date.', 'serial-number-for-woocommerce' ),
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

					<div id="snw-warranty-fields">
						<p class="form-field">
							<label for="<?php echo esc_attr( self::WARRANTY_LENGTH_META_KEY ); ?>"><?php esc_html_e( 'Warranty length', 'serial-number-for-woocommerce' ); ?></label>
							<input
								type="number"
								id="<?php echo esc_attr( self::WARRANTY_LENGTH_META_KEY ); ?>"
								name="<?php echo esc_attr( self::WARRANTY_LENGTH_META_KEY ); ?>"
								min="1"
								step="1"
								class="small-text"
								value="<?php echo esc_attr( get_post_meta( $post->ID, self::WARRANTY_LENGTH_META_KEY, true ) ?: '1' ); ?>"
								<?php disabled( ! $is_pro ); ?>
							/>
							<select id="<?php echo esc_attr( self::WARRANTY_PERIOD_META_KEY ); ?>" name="<?php echo esc_attr( self::WARRANTY_PERIOD_META_KEY ); ?>" <?php disabled( ! $is_pro ); ?>>
								<?php $warranty_period = get_post_meta( $post->ID, self::WARRANTY_PERIOD_META_KEY, true ) ?: 'year'; ?>
								<option value="month" <?php selected( $warranty_period, 'month' ); ?>><?php esc_html_e( 'Month(s)', 'serial-number-for-woocommerce' ); ?></option>
								<option value="year" <?php selected( $warranty_period, 'year' ); ?>><?php esc_html_e( 'Year(s)', 'serial-number-for-woocommerce' ); ?></option>
							</select>
							<?php echo wc_help_tip( __( 'How long the warranty lasts for each serial number of this product.', 'serial-number-for-woocommerce' ) ); ?>
						</p>

						<?php if ( $is_pro ) : ?>
							<?php
							woocommerce_wp_checkbox(
								array(
									'id'          => self::WARRANTY_EXTENSION_ENABLED_META_KEY,
									'label'       => __( 'Enable warranty extension', 'serial-number-for-woocommerce' ),
									'description' => __( 'Lets customers pay to extend this product\'s warranty when they purchase it.', 'serial-number-for-woocommerce' ),
									'desc_tip'    => true,
									'value'       => get_post_meta( $post->ID, self::WARRANTY_EXTENSION_ENABLED_META_KEY, true ),
								)
							);
							?>
						<?php else : ?>
							<p class="form-field">
								<label for="<?php echo esc_attr( self::WARRANTY_EXTENSION_ENABLED_META_KEY ); ?>">
									<?php esc_html_e( 'Enable warranty extension', 'serial-number-for-woocommerce' ); ?>
								</label>
								<input
									type="checkbox"
									id="<?php echo esc_attr( self::WARRANTY_EXTENSION_ENABLED_META_KEY ); ?>"
									disabled
									<?php checked( get_post_meta( $post->ID, self::WARRANTY_EXTENSION_ENABLED_META_KEY, true ), 'yes' ); ?>
								/>
								<?php echo wc_help_tip( __( 'Upgrade to Pro to let customers pay to extend this product\'s warranty.', 'serial-number-for-woocommerce' ) ); ?>
							</p>
						<?php endif; ?>

						<div id="snw-warranty-extension-fields">
							<p class="form-field">
								<label for="<?php echo esc_attr( self::WARRANTY_EXTENSION_LENGTH_META_KEY ); ?>"><?php esc_html_e( 'Extension length', 'serial-number-for-woocommerce' ); ?></label>
								<input
									type="number"
									id="<?php echo esc_attr( self::WARRANTY_EXTENSION_LENGTH_META_KEY ); ?>"
									name="<?php echo esc_attr( self::WARRANTY_EXTENSION_LENGTH_META_KEY ); ?>"
									min="1"
									step="1"
									class="small-text"
									value="<?php echo esc_attr( get_post_meta( $post->ID, self::WARRANTY_EXTENSION_LENGTH_META_KEY, true ) ?: '1' ); ?>"
									<?php disabled( ! $is_pro ); ?>
								/>
								<select id="<?php echo esc_attr( self::WARRANTY_EXTENSION_PERIOD_META_KEY ); ?>" name="<?php echo esc_attr( self::WARRANTY_EXTENSION_PERIOD_META_KEY ); ?>" <?php disabled( ! $is_pro ); ?>>
									<?php $extension_period = get_post_meta( $post->ID, self::WARRANTY_EXTENSION_PERIOD_META_KEY, true ) ?: 'year'; ?>
									<option value="month" <?php selected( $extension_period, 'month' ); ?>><?php esc_html_e( 'Month(s)', 'serial-number-for-woocommerce' ); ?></option>
									<option value="year" <?php selected( $extension_period, 'year' ); ?>><?php esc_html_e( 'Year(s)', 'serial-number-for-woocommerce' ); ?></option>
								</select>
								<?php echo wc_help_tip( __( 'How much extra time the extension adds on top of the warranty length above.', 'serial-number-for-woocommerce' ) ); ?>
							</p>
							<p class="form-field">
								<label for="<?php echo esc_attr( self::WARRANTY_EXTENSION_PRICE_META_KEY ); ?>"><?php esc_html_e( 'Extension price', 'serial-number-for-woocommerce' ); ?></label>
								<input
									type="number"
									id="<?php echo esc_attr( self::WARRANTY_EXTENSION_PRICE_META_KEY ); ?>"
									name="<?php echo esc_attr( self::WARRANTY_EXTENSION_PRICE_META_KEY ); ?>"
									min="0"
									step="0.01"
									class="small-text"
									value="<?php echo esc_attr( get_post_meta( $post->ID, self::WARRANTY_EXTENSION_PRICE_META_KEY, true ) ?: '0' ); ?>"
									<?php disabled( ! $is_pro ); ?>
								/>
								<?php echo wc_help_tip( __( 'Added to the product price when a customer chooses to purchase the extension.', 'serial-number-for-woocommerce' ) ); ?>
							</p>
						</div>
					</div>

					<?php if ( $is_pro ) : ?>
						<?php
						woocommerce_wp_checkbox(
							array(
								'id'          => self::LICENSE_ENABLED_META_KEY,
								'label'       => __( 'This is a license product', 'serial-number-for-woocommerce' ),
								'description' => __( 'Treats each serial number assigned for this product as a license key, shown to the customer as such instead of a serial number. Mutually exclusive with Warranty above — a serial can only track one expiration date.', 'serial-number-for-woocommerce' ),
								'desc_tip'    => true,
								'value'       => get_post_meta( $post->ID, self::LICENSE_ENABLED_META_KEY, true ),
							)
						);
						?>
					<?php else : ?>
						<p class="form-field">
							<label for="<?php echo esc_attr( self::LICENSE_ENABLED_META_KEY ); ?>">
								<?php esc_html_e( 'This is a license product', 'serial-number-for-woocommerce' ); ?>
							</label>
							<input
								type="checkbox"
								id="<?php echo esc_attr( self::LICENSE_ENABLED_META_KEY ); ?>"
								disabled
								<?php checked( get_post_meta( $post->ID, self::LICENSE_ENABLED_META_KEY, true ), 'yes' ); ?>
							/>
							<?php echo wc_help_tip( __( 'Upgrade to Pro to treat this product\'s serial numbers as license keys.', 'serial-number-for-woocommerce' ) ); ?>
						</p>
					<?php endif; ?>

					<div id="snw-license-fields">
						<p class="form-field">
							<label for="<?php echo esc_attr( self::LICENSE_LENGTH_META_KEY ); ?>"><?php esc_html_e( 'License length', 'serial-number-for-woocommerce' ); ?></label>
							<input
								type="number"
								id="<?php echo esc_attr( self::LICENSE_LENGTH_META_KEY ); ?>"
								name="<?php echo esc_attr( self::LICENSE_LENGTH_META_KEY ); ?>"
								min="1"
								step="1"
								class="small-text"
								value="<?php echo esc_attr( get_post_meta( $post->ID, self::LICENSE_LENGTH_META_KEY, true ) ?: '1' ); ?>"
								<?php disabled( ! $is_pro ); ?>
							/>
							<select id="<?php echo esc_attr( self::LICENSE_PERIOD_META_KEY ); ?>" name="<?php echo esc_attr( self::LICENSE_PERIOD_META_KEY ); ?>" <?php disabled( ! $is_pro ); ?>>
								<?php $license_period = get_post_meta( $post->ID, self::LICENSE_PERIOD_META_KEY, true ) ?: 'year'; ?>
								<option value="month" <?php selected( $license_period, 'month' ); ?>><?php esc_html_e( 'Month(s)', 'serial-number-for-woocommerce' ); ?></option>
								<option value="year" <?php selected( $license_period, 'year' ); ?>><?php esc_html_e( 'Year(s)', 'serial-number-for-woocommerce' ); ?></option>
								<option value="lifetime" <?php selected( $license_period, 'lifetime' ); ?>><?php esc_html_e( 'Lifetime (never expires)', 'serial-number-for-woocommerce' ); ?></option>
							</select>
							<?php echo wc_help_tip( __( 'How long a license lasts once activated. Lifetime licenses never expire.', 'serial-number-for-woocommerce' ) ); ?>
						</p>
						<p class="form-field">
							<label for="<?php echo esc_attr( self::LICENSE_ACTIVATION_TRIGGER_META_KEY ); ?>"><?php esc_html_e( 'Activation trigger', 'serial-number-for-woocommerce' ); ?></label>
							<select id="<?php echo esc_attr( self::LICENSE_ACTIVATION_TRIGGER_META_KEY ); ?>" name="<?php echo esc_attr( self::LICENSE_ACTIVATION_TRIGGER_META_KEY ); ?>" <?php disabled( ! $is_pro ); ?>>
								<?php $activation_trigger = get_post_meta( $post->ID, self::LICENSE_ACTIVATION_TRIGGER_META_KEY, true ) ?: 'immediate'; ?>
								<option value="immediate" <?php selected( $activation_trigger, 'immediate' ); ?>><?php esc_html_e( 'Immediately at purchase', 'serial-number-for-woocommerce' ); ?></option>
								<option value="on_completed" <?php selected( $activation_trigger, 'on_completed' ); ?>><?php esc_html_e( 'When the order is marked Completed', 'serial-number-for-woocommerce' ); ?></option>
								<option value="manual" <?php selected( $activation_trigger, 'manual' ); ?>><?php esc_html_e( 'Manually by the customer (My Account)', 'serial-number-for-woocommerce' ); ?></option>
								<option value="api" <?php selected( $activation_trigger, 'api' ); ?>><?php esc_html_e( 'Externally, by your own system (API)', 'serial-number-for-woocommerce' ); ?></option>
							</select>
							<?php echo wc_help_tip( __( 'When a license\'s validity period starts counting down. "Manually" shows an Activate button on the customer\'s My Account order view. "Externally" waits for your own system to call the plugin\'s REST API (see WooCommerce > Settings > Serial Numbers).', 'serial-number-for-woocommerce' ) ); ?>
						</p>
						<p class="form-field" id="snw-license-api-notice" style="display: none;">
							<span class="description">
								<?php
								printf(
									/* translators: %s: link to WooCommerce > Settings > Serial Numbers */
									esc_html__( 'Activation is handled by your own system. See %s for the API key and endpoint it needs to call.', 'serial-number-for-woocommerce' ),
									'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=snw_serial_numbers' ) ) . '" target="_blank">' . esc_html__( 'WooCommerce > Settings > Serial Numbers', 'serial-number-for-woocommerce' ) . '</a>'
								);
								?>
							</span>
						</p>
						<?php
						woocommerce_wp_textarea_input(
							array(
								'id'                => self::LICENSE_INSTRUCTIONS_META_KEY,
								'label'             => __( 'License instructions', 'serial-number-for-woocommerce' ),
								'description'       => __( 'Shown to the customer in their license delivery email — activation steps, download links, support contact, etc.', 'serial-number-for-woocommerce' ),
								'desc_tip'          => true,
								'value'             => get_post_meta( $post->ID, self::LICENSE_INSTRUCTIONS_META_KEY, true ),
								'rows'              => 4,
								'custom_attributes' => $is_pro ? array() : array( 'disabled' => 'disabled' ),
							)
						);
						?>
						<?php $product_for_price = wc_get_product( $post->ID ); ?>
						<p class="form-field" id="snw-license-renewal-price-wrapper">
							<label for="<?php echo esc_attr( self::LICENSE_RENEWAL_PRICE_META_KEY ); ?>"><?php esc_html_e( 'Renewal price', 'serial-number-for-woocommerce' ); ?></label>
							<input
								type="number"
								id="<?php echo esc_attr( self::LICENSE_RENEWAL_PRICE_META_KEY ); ?>"
								name="<?php echo esc_attr( self::LICENSE_RENEWAL_PRICE_META_KEY ); ?>"
								min="0"
								step="0.01"
								class="small-text"
								placeholder="<?php echo esc_attr( $product_for_price ? $product_for_price->get_price() : '0' ); ?>"
								value="<?php echo esc_attr( get_post_meta( $post->ID, self::LICENSE_RENEWAL_PRICE_META_KEY, true ) ); ?>"
								<?php disabled( ! $is_pro ); ?>
							/>
							<?php echo wc_help_tip( __( 'Charged when a customer renews an existing license key from their My Account order view. Leave blank to charge the product\'s regular price.', 'serial-number-for-woocommerce' ) ); ?>
						</p>
					</div>
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

				function snwToggleWarrantyFields() {
					$( '#snw-warranty-fields' ).toggle(
						snwIsPro && $( '#<?php echo esc_js( self::WARRANTY_ENABLED_META_KEY ); ?>' ).is( ':checked' )
					);
				}

				function snwToggleWarrantyExtensionFields() {
					$( '#snw-warranty-extension-fields' ).toggle(
						snwIsPro && $( '#<?php echo esc_js( self::WARRANTY_EXTENSION_ENABLED_META_KEY ); ?>' ).is( ':checked' )
					);
				}

				function snwToggleLicenseFields() {
					$( '#snw-license-fields' ).toggle(
						snwIsPro && $( '#<?php echo esc_js( self::LICENSE_ENABLED_META_KEY ); ?>' ).is( ':checked' )
					);
				}

				function snwToggleLicenseLengthField() {
					// Only the number input, not its whole p.form-field — that
					// wrapper also contains the period <select> itself, and
					// hiding it along with the input would leave no visible
					// control to switch back off "Lifetime" again.
					$( '#<?php echo esc_js( self::LICENSE_LENGTH_META_KEY ); ?>' ).toggle(
						'lifetime' !== $( '#<?php echo esc_js( self::LICENSE_PERIOD_META_KEY ); ?>' ).val()
					);
				}

				function snwToggleApiTriggerNotice() {
					$( '#snw-license-api-notice' ).toggle(
						'api' === $( '#<?php echo esc_js( self::LICENSE_ACTIVATION_TRIGGER_META_KEY ); ?>' ).val()
					);
				}

				// A lifetime license is never renewable (Renewal::is_renewable()
				// excludes it), so a renewal price for it would never apply.
				function snwToggleRenewalPriceField() {
					$( '#snw-license-renewal-price-wrapper' ).toggle(
						'lifetime' !== $( '#<?php echo esc_js( self::LICENSE_PERIOD_META_KEY ); ?>' ).val()
					);
				}

				/*
				 * StockSync already wrote the new stock quantity to the DB by the
				 * time the AJAX response comes back, but the #_stock field (part
				 * of the Inventory tab, not ours) still shows whatever value the
				 * page loaded with — so without this, the admin would see a stale
				 * number until they save or refresh. Setting .val() directly
				 * works even though the field is disabled; disabled only blocks
				 * user input and form submission, not script changes.
				 */
				function snwApplyStockQuantity( stockQuantity ) {
					if ( 'number' !== typeof stockQuantity ) {
						return;
					}

					$( '#_stock' ).val( stockQuantity );
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
				$( '#<?php echo esc_js( self::WARRANTY_ENABLED_META_KEY ); ?>' ).on( 'change', snwToggleWarrantyFields );
				$( '#<?php echo esc_js( self::WARRANTY_EXTENSION_ENABLED_META_KEY ); ?>' ).on( 'change', snwToggleWarrantyExtensionFields );
				$( '#<?php echo esc_js( self::LICENSE_ENABLED_META_KEY ); ?>' ).on( 'change', snwToggleLicenseFields );
				$( '#<?php echo esc_js( self::LICENSE_PERIOD_META_KEY ); ?>' ).on( 'change', snwToggleLicenseLengthField );
				$( '#<?php echo esc_js( self::LICENSE_PERIOD_META_KEY ); ?>' ).on( 'change', snwToggleRenewalPriceField );
				$( '#<?php echo esc_js( self::LICENSE_ACTIVATION_TRIGGER_META_KEY ); ?>' ).on( 'change', snwToggleApiTriggerNotice );

				// Warranty and License are mutually exclusive (see save()'s own
				// server-side guard for why) — checking one immediately unchecks
				// and collapses the other, rather than letting both stay checked
				// until save.
				$( '#<?php echo esc_js( self::WARRANTY_ENABLED_META_KEY ); ?>' ).on( 'change', function () {
					if ( $( this ).is( ':checked' ) ) {
						$( '#<?php echo esc_js( self::LICENSE_ENABLED_META_KEY ); ?>' ).prop( 'checked', false );
						snwToggleLicenseFields();
					}
				} );
				$( '#<?php echo esc_js( self::LICENSE_ENABLED_META_KEY ); ?>' ).on( 'change', function () {
					if ( $( this ).is( ':checked' ) ) {
						$( '#<?php echo esc_js( self::WARRANTY_ENABLED_META_KEY ); ?>' ).prop( 'checked', false );
						snwToggleWarrantyFields();
						snwToggleWarrantyExtensionFields();
					}
				} );

				snwToggleConditionalFields();
				snwToggleCustomRuleFields();
				snwToggleWarrantyFields();
				snwToggleWarrantyExtensionFields();
				snwToggleLicenseFields();
				snwToggleLicenseLengthField();
				snwToggleRenewalPriceField();
				snwToggleApiTriggerNotice();
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
							snwApplyStockQuantity( data.stock_quantity );
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
						// Read straight from the form's own current fields —
						// including anything just checked/typed but not yet
						// saved — rather than relying on the product's saved
						// meta, so a rule change applies immediately.
						rule_enabled: $( '#<?php echo esc_js( self::CUSTOM_RULE_ENABLED_META_KEY ); ?>' ).is( ':checked' ) ? 'yes' : 'no',
						prefix: $( '#<?php echo esc_js( self::CUSTOM_PREFIX_META_KEY ); ?>' ).val(),
						suffix: $( '#<?php echo esc_js( self::CUSTOM_SUFFIX_META_KEY ); ?>' ).val(),
						charset: $( '#<?php echo esc_js( self::CUSTOM_CHARSET_META_KEY ); ?>' ).val(),
						length: $( '#<?php echo esc_js( self::CUSTOM_LENGTH_META_KEY ); ?>' ).val(),
					} )
						.done( function ( response ) {
							if ( ! response || ! response.success ) {
								$result.text(
									( response && response.data && response.data.message ) ||
									<?php echo wp_json_encode( __( 'Something went wrong.', 'serial-number-for-woocommerce' ) ); ?>
								);
								return;
							}

							// Plain text, not a link — clicking through to the list
							// page would navigate away and lose any unsaved
							// changes on this product edit screen.
							$result.text(
								response.data.message + ' ' +
								<?php echo wp_json_encode( __( 'Check them in the Serial Numbers list', 'serial-number-for-woocommerce' ) ); ?>
							);
							snwApplyStockQuantity( response.data.stock_quantity );
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
		$product                = wc_get_product( $product_id );
		$is_grouped_or_external = $product && $product->is_type( array( 'grouped', 'external' ) );
		$is_variable            = $product && $product->is_type( 'variable' );

		// Grouped/External products can never actually be ordered on this
		// site (see add_tab()'s show_if_simple/show_if_variable tab
		// classes) — this is the server-side guarantee behind that: the
		// tab's own CSS-hiding doesn't stop a previously-checked value
		// from still submitting once the product type is switched, so
		// "enabled" is never persisted for a type serial numbers can never
		// apply to, regardless of what was posted.
		$enabled = ( ! $is_grouped_or_external && isset( $_POST[ self::META_KEY ] ) ) ? 'yes' : 'no';
		update_post_meta( $product_id, self::META_KEY, $enabled );

		$is_pro = Licensing::is_pro_active();

		// Pro-only: never persist "yes" without a license, even if the field
		// were somehow submitted (it's rendered disabled when unlicensed, so
		// browsers don't submit it, but this keeps the meta honest either way).
		// Also never for a Variable product — each variation manages its own
		// stock separately in WooCommerce, so this checkbox's #_stock-based
		// sync can't work correctly at the parent level (see the matching
		// hide_if_variable wrapper_class in render_panel()).
		$manage_stock = ( 'yes' === $enabled && $is_pro && ! $is_variable && isset( $_POST[ self::MANAGE_STOCK_META_KEY ] ) ) ? 'yes' : 'no';
		update_post_meta( $product_id, self::MANAGE_STOCK_META_KEY, $manage_stock );

		$custom_rule_enabled = ( 'yes' === $enabled && $is_pro && isset( $_POST[ self::CUSTOM_RULE_ENABLED_META_KEY ] ) ) ? 'yes' : 'no';
		update_post_meta( $product_id, self::CUSTOM_RULE_ENABLED_META_KEY, $custom_rule_enabled );

		$warranty_enabled = ( 'yes' === $enabled && $is_pro && isset( $_POST[ self::WARRANTY_ENABLED_META_KEY ] ) ) ? 'yes' : 'no';
		$license_enabled  = ( 'yes' === $enabled && $is_pro && isset( $_POST[ self::LICENSE_ENABLED_META_KEY ] ) ) ? 'yes' : 'no';

		// Warranty and License are mutually exclusive: both ultimately write
		// the same activated_at/expires_at columns on a serial's row via
		// Repository::activate(), so enabling both would silently drop
		// whichever feature's activation trigger fires second (its own
		// activate_serial() call would just no-op against an already-set
		// activated_at). The product tab's own JS keeps the two checkboxes
		// from being checked together in normal use; this is the server-side
		// guarantee for a stale/JS-less submission that somehow posts both —
		// License wins, matching the checkbox order on the tab (License is
		// the more specific commitment: activation trigger, delivery email,
		// etc., where Warranty's revert-to-off costs nothing extra).
		if ( 'yes' === $warranty_enabled && 'yes' === $license_enabled ) {
			$warranty_enabled = 'no';
		}

		update_post_meta( $product_id, self::WARRANTY_ENABLED_META_KEY, $warranty_enabled );

		$warranty_extension_enabled = ( 'yes' === $enabled && $is_pro && isset( $_POST[ self::WARRANTY_EXTENSION_ENABLED_META_KEY ] ) ) ? 'yes' : 'no';
		update_post_meta( $product_id, self::WARRANTY_EXTENSION_ENABLED_META_KEY, $warranty_extension_enabled );

		update_post_meta( $product_id, self::LICENSE_ENABLED_META_KEY, $license_enabled );

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

			// Same "keep the value even while disabled" treatment as the rule
			// fields above — only Warranty::is_enabled_for_product() gates
			// whether the length/period take effect.
			$warranty_length = isset( $_POST[ self::WARRANTY_LENGTH_META_KEY ] ) ? absint( $_POST[ self::WARRANTY_LENGTH_META_KEY ] ) : 0;
			update_post_meta( $product_id, self::WARRANTY_LENGTH_META_KEY, $warranty_length ? (string) $warranty_length : '1' );

			$warranty_period = isset( $_POST[ self::WARRANTY_PERIOD_META_KEY ] ) ? sanitize_key( wp_unslash( $_POST[ self::WARRANTY_PERIOD_META_KEY ] ) ) : '';
			update_post_meta( $product_id, self::WARRANTY_PERIOD_META_KEY, in_array( $warranty_period, array( 'month', 'year' ), true ) ? $warranty_period : 'year' );

			$extension_length = isset( $_POST[ self::WARRANTY_EXTENSION_LENGTH_META_KEY ] ) ? absint( $_POST[ self::WARRANTY_EXTENSION_LENGTH_META_KEY ] ) : 0;
			update_post_meta( $product_id, self::WARRANTY_EXTENSION_LENGTH_META_KEY, $extension_length ? (string) $extension_length : '1' );

			$extension_period = isset( $_POST[ self::WARRANTY_EXTENSION_PERIOD_META_KEY ] ) ? sanitize_key( wp_unslash( $_POST[ self::WARRANTY_EXTENSION_PERIOD_META_KEY ] ) ) : '';
			update_post_meta( $product_id, self::WARRANTY_EXTENSION_PERIOD_META_KEY, in_array( $extension_period, array( 'month', 'year' ), true ) ? $extension_period : 'year' );

			$extension_price = isset( $_POST[ self::WARRANTY_EXTENSION_PRICE_META_KEY ] ) ? wc_format_decimal( wp_unslash( $_POST[ self::WARRANTY_EXTENSION_PRICE_META_KEY ] ) ) : '0';
			update_post_meta( $product_id, self::WARRANTY_EXTENSION_PRICE_META_KEY, $extension_price );

			// Same "keep the value even while disabled" treatment as the
			// warranty fields above — only LicenseKey::is_enabled_for_product()
			// gates whether these take effect.
			$license_length = isset( $_POST[ self::LICENSE_LENGTH_META_KEY ] ) ? absint( $_POST[ self::LICENSE_LENGTH_META_KEY ] ) : 0;
			update_post_meta( $product_id, self::LICENSE_LENGTH_META_KEY, $license_length ? (string) $license_length : '1' );

			$license_period = isset( $_POST[ self::LICENSE_PERIOD_META_KEY ] ) ? sanitize_key( wp_unslash( $_POST[ self::LICENSE_PERIOD_META_KEY ] ) ) : '';
			update_post_meta( $product_id, self::LICENSE_PERIOD_META_KEY, in_array( $license_period, array( 'month', 'year', 'lifetime' ), true ) ? $license_period : 'year' );

			$activation_trigger = isset( $_POST[ self::LICENSE_ACTIVATION_TRIGGER_META_KEY ] ) ? sanitize_key( wp_unslash( $_POST[ self::LICENSE_ACTIVATION_TRIGGER_META_KEY ] ) ) : '';
			update_post_meta( $product_id, self::LICENSE_ACTIVATION_TRIGGER_META_KEY, in_array( $activation_trigger, array( 'immediate', 'on_completed', 'manual', 'api' ), true ) ? $activation_trigger : 'immediate' );

			update_post_meta(
				$product_id,
				self::LICENSE_INSTRUCTIONS_META_KEY,
				isset( $_POST[ self::LICENSE_INSTRUCTIONS_META_KEY ] ) ? sanitize_textarea_field( wp_unslash( $_POST[ self::LICENSE_INSTRUCTIONS_META_KEY ] ) ) : ''
			);

			// Blank means "charge the product's regular price" (see
			// Renewal::renewal_price_for_product()) — stored as '' rather
			// than a computed number, so it keeps tracking the product's
			// current price if that changes later instead of freezing it.
			update_post_meta(
				$product_id,
				self::LICENSE_RENEWAL_PRICE_META_KEY,
				isset( $_POST[ self::LICENSE_RENEWAL_PRICE_META_KEY ] ) && '' !== $_POST[ self::LICENSE_RENEWAL_PRICE_META_KEY ]
					? wc_format_decimal( wp_unslash( $_POST[ self::LICENSE_RENEWAL_PRICE_META_KEY ] ) )
					: ''
			);
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
