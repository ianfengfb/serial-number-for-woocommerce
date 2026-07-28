<?php
namespace SerialNumberForWooCommerce\Admin;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Status;

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
	}

	/**
	 * Settings shown on the tab's default (only) section.
	 */
	public function get_settings_for_default_section(): array {
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
		);
	}
}
