<?php
namespace SerialNumberForWooCommerce\Admin;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Status;
use SerialNumberForWooCommerce\Admin\Support\Support;
use SerialNumberForWooCommerce\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * Free tier: adds a "Serial Numbers" tab to WooCommerce > Settings for
 * serial-number-related configuration.
 *
 * Extends WC_Settings_Page, so WooCommerce handles rendering, saving and
 * (optional) sub-section routing for us. Only instantiated from inside the
 * `woocommerce_get_settings_pages` filter, i.e. after WooCommerce — and thus
 * the WC_Settings_Page parent class — is fully loaded.
 */
final class Settings extends \WC_Settings_Page {

	public function __construct() {
		$this->id    = 'snw_serial_numbers';
		$this->label = __( 'Serial Numbers', 'serial-number-for-woocommerce' );

		parent::__construct();

		add_action( 'admin_footer', array( $this, 'print_activation_trigger_script' ) );
	}

	/**
	 * Hides the "Grace period (days)" row (and zeroes its value) while
	 * "Activation trigger" isn't set to the days-after-completed option —
	 * that field means nothing in "on order completed" mode. Targets fields
	 * by their own ID and walks up to the nearest `<tr>` rather than
	 * assuming a specific row class, so it doesn't depend on WooCommerce's
	 * exact settings-table markup.
	 */
	public function print_activation_trigger_script(): void {
		if ( ! isset( $_GET['page'], $_GET['tab'] ) || 'wc-settings' !== $_GET['page'] || $this->id !== $_GET['tab'] ) {
			return;
		}
		?>
		<script>
		jQuery( function ( $ ) {
			var $trigger = $( '#snw_warranty_activation_trigger' );
			var $days    = $( '#snw_warranty_activation_days' );

			function snwToggleActivationDays() {
				var showDays = 'days_after_completed' === $trigger.val();

				$days.closest( 'tr' ).toggle( showDays );

				if ( ! showDays ) {
					$days.val( 0 );
				}
			}

			$trigger.on( 'change', snwToggleActivationDays );
			snwToggleActivationDays();
		} );
		</script>
		<?php
	}

	/**
	 * Settings shown on the tab's default (only) section.
	 */
	public function get_settings_for_default_section(): array {
		$is_pro = Licensing::is_pro_active();

		return array(
			array(
				'title' => __( 'General', 'serial-number-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Configure how serial numbers behave across your store.', 'serial-number-for-woocommerce' ),
				'id'    => 'snw_general_settings',
			),
			array(
				'title'    => __( 'Default status', 'serial-number-for-woocommerce' ),
				'desc'     => __( 'Status pre-selected when creating a new serial number.', 'serial-number-for-woocommerce' ),
				'id'       => 'snw_default_status',
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'default'  => Status::FALLBACK,
				'options'  => Status::all(),
				'desc_tip' => true,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'snw_general_settings',
			),

			array(
				'title' => __( 'Auto-generation', 'serial-number-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Rules used by the "Generate" button when creating a serial number automatically. The generated value is <code>prefix-random-postfix</code> (dashes are only added around a prefix or postfix that is set).', 'serial-number-for-woocommerce' ),
				'id'    => 'snw_generation_settings',
			),
			array(
				'title'    => __( 'Prefix', 'serial-number-for-woocommerce' ),
				'desc'     => __( 'Text prepended to every generated serial number. Leave blank for none.', 'serial-number-for-woocommerce' ),
				'id'       => 'snw_auto_prefix',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Postfix', 'serial-number-for-woocommerce' ),
				'desc'     => __( 'Text appended to every generated serial number. Leave blank for none.', 'serial-number-for-woocommerce' ),
				'id'       => 'snw_auto_postfix',
				'type'     => 'text',
				'default'  => '',
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Character set', 'serial-number-for-woocommerce' ),
				'desc'     => __( 'Which characters the random part is built from.', 'serial-number-for-woocommerce' ),
				'id'       => 'snw_auto_charset',
				'type'     => 'select',
				'class'    => 'wc-enhanced-select',
				'default'  => 'alphanumeric',
				'options'  => array(
					'alphanumeric' => __( 'Letters and numbers', 'serial-number-for-woocommerce' ),
					'numeric'      => __( 'Numbers only', 'serial-number-for-woocommerce' ),
					'alpha'        => __( 'Letters only', 'serial-number-for-woocommerce' ),
				),
				'desc_tip' => true,
			),
			array(
				'title'             => __( 'Random part length', 'serial-number-for-woocommerce' ),
				'desc'              => __( 'Number of random characters generated between the prefix and postfix.', 'serial-number-for-woocommerce' ),
				'id'                => 'snw_auto_length',
				'type'              => 'number',
				'default'           => 12,
				'desc_tip'          => true,
				'custom_attributes' => array(
					'min'  => 1,
					'max'  => 64,
					'step' => 1,
				),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'snw_generation_settings',
			),

			array(
				'title' => __( 'Customer visibility', 'serial-number-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => __( 'Serial numbers are always visible to store admins on the order edit screen. These control whether customers see them too — some stores prefer to manage serial numbers internally instead, so turn both off to keep them admin-only.', 'serial-number-for-woocommerce' ),
				'id'    => 'snw_customer_visibility_settings',
			),
			array(
				'title'    => __( 'Show in order emails', 'serial-number-for-woocommerce' ),
				'desc'     => __( 'Include each item\'s assigned serial numbers in the customer\'s order emails.', 'serial-number-for-woocommerce' ),
				'id'       => 'snw_show_serials_in_emails',
				'type'     => 'checkbox',
				'default'  => 'yes',
				'desc_tip' => true,
			),
			array(
				'title'    => __( 'Show on order details page', 'serial-number-for-woocommerce' ),
				'desc'     => __( 'Include each item\'s assigned serial numbers on the thank-you page and the customer\'s My Account order view.', 'serial-number-for-woocommerce' ),
				'id'       => 'snw_show_serials_in_account',
				'type'     => 'checkbox',
				'default'  => 'yes',
				'desc_tip' => true,
			),
			array(
				'type' => 'sectionend',
				'id'   => 'snw_customer_visibility_settings',
			),

			array(
				'title' => __( 'Warranty (Pro)', 'serial-number-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => sprintf(
					/* translators: %s: link to WooCommerce > Settings > Emails */
					__( 'Controls when a serial number\'s warranty starts counting down, for products with warranty tracking enabled on their Serial Number tab. Requires a license. The "Warranty Activated" and "Warranty Expired" customer emails can be turned on/off and customised in %s.', 'serial-number-for-woocommerce' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=email' ) ) . '">' . esc_html__( 'WooCommerce > Settings > Emails', 'serial-number-for-woocommerce' ) . '</a>'
				),
				'id'    => 'snw_warranty_settings',
			),
			array(
				'title'             => __( 'Activation trigger', 'serial-number-for-woocommerce' ),
				'desc'              => __( 'When a serial number\'s warranty starts.', 'serial-number-for-woocommerce' ),
				'id'                => 'snw_warranty_activation_trigger',
				'type'              => 'select',
				'class'             => 'wc-enhanced-select',
				'default'           => 'on_completed',
				'options'           => array(
					'on_completed'         => __( 'When the order is marked Completed', 'serial-number-for-woocommerce' ),
					'days_after_completed' => __( 'A number of days after the order is marked Completed', 'serial-number-for-woocommerce' ),
				),
				'desc_tip'          => true,
				'custom_attributes' => $is_pro ? array() : array( 'disabled' => 'disabled' ),
			),
			array(
				'title'             => __( 'Grace period (days)', 'serial-number-for-woocommerce' ),
				'desc'              => __( 'Only used when the trigger above is "A number of days after...". Warranty starts this many days after the order is marked Completed.', 'serial-number-for-woocommerce' ),
				'id'                => 'snw_warranty_activation_days',
				'type'              => 'number',
				'default'           => 0,
				'desc_tip'          => true,
				'custom_attributes' => array_merge(
					array(
						'min'  => 0,
						'step' => 1,
					),
					$is_pro ? array() : array( 'disabled' => 'disabled' )
				),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'snw_warranty_settings',
			),

			array(
				'title' => __( 'License Key (Pro)', 'serial-number-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => sprintf(
					/* translators: %s: link to WooCommerce > Settings > Emails */
					__( 'License length, activation trigger, and instructions are configured per product on its Serial Number tab. The "License Delivery", "License Activated", and "License Expired" customer emails — plus the admin notice for license activations — can be turned on/off and customised in %s.', 'serial-number-for-woocommerce' ),
					'<a href="' . esc_url( admin_url( 'admin.php?page=wc-settings&tab=email' ) ) . '">' . esc_html__( 'WooCommerce > Settings > Emails', 'serial-number-for-woocommerce' ) . '</a>'
				),
				'id'    => 'snw_license_settings',
			),
			array(
				'title'             => __( 'API key', 'serial-number-for-woocommerce' ),
				'desc'              => $this->license_api_key_description( $is_pro ),
				'id'                => 'snw_license_api_key',
				'type'              => 'text',
				'default'           => $is_pro ? \SerialNumberForWooCommerce\Pro\LicenseKey\LicenseKey::get_or_create_api_key() : '',
				'custom_attributes' => array_merge(
					array(
						'readonly' => 'readonly',
						'onclick'  => 'this.select();',
						'size'     => 44,
					),
					$is_pro ? array() : array( 'disabled' => 'disabled' )
				),
			),
			array(
				'type' => 'sectionend',
				'id'   => 'snw_license_settings',
			),

			array(
				'title' => __( 'Support', 'serial-number-for-woocommerce' ),
				'type'  => 'title',
				'desc'  => sprintf(
					/* translators: 1: link to the plugin's own Support page, 2: support email address, as a mailto link */
					__( 'Have a question, found a bug, or want to request a feature? %1$s, or email us directly at %2$s.', 'serial-number-for-woocommerce' ),
					'<a href="' . esc_url( Support::page_url() ) . '">' . esc_html__( 'Send us a message', 'serial-number-for-woocommerce' ) . '</a>',
					'<a href="mailto:' . esc_attr( Support::support_email() ) . '">' . esc_html( Support::support_email() ) . '</a>'
				),
				'id'    => 'snw_support_settings',
			),
			array(
				'type' => 'sectionend',
				'id'   => 'snw_support_settings',
			),
		);
	}

	/**
	 * Explains the API key field and, when licensed, appends a "Regenerate"
	 * link straight to RestApi's admin-post handler — a plain link rather
	 * than AJAX, matching Export CSV's own admin-post-as-a-link pattern.
	 * Unlicensed, the field itself is disabled/empty (see the field array
	 * above), so there's nothing to regenerate and no link is shown.
	 */
	private function license_api_key_description( bool $is_pro ): string {
		$desc = sprintf(
			/* translators: 1: header name, 2: REST API endpoint */
			__( 'Sent as the %1$s header when your own external system calls %2$s to activate a license key for a product set to "Externally, by your own system (API)".', 'serial-number-for-woocommerce' ),
			'<code>X-SNW-Api-Key</code>',
			'<code>POST ' . esc_html( rest_url( 'snw/v1/license/activate' ) ) . '</code>'
		);

		if ( ! $is_pro ) {
			return $desc;
		}

		$regenerate_url = wp_nonce_url( admin_url( 'admin-post.php?action=snw_regenerate_license_api_key' ), 'snw_regenerate_license_api_key' );

		return $desc . ' <a href="' . esc_url( $regenerate_url ) . '" onclick="return confirm(\'' .
			esc_js( __( 'Regenerate the API key? Anything still using the old one will stop working immediately.', 'serial-number-for-woocommerce' ) ) .
			'\');">' . esc_html__( 'Regenerate', 'serial-number-for-woocommerce' ) . '</a>';
	}
}
