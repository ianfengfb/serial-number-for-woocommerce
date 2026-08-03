<?php
/**
 * Plugin Name:          Serial Number for WooCommerce
 * Description:          Assign and manage serial numbers for WooCommerce products and orders.
 * Version:              0.9.0
 * Requires at least:    6.3
 * Requires PHP:         7.4
 * WC requires at least: 8.0
 * WC tested up to:      9.4
 * Author:               Felix Digital Shop
 * License:              GPL-2.0-or-later
 * License URI:          https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:          serial-number-for-woocommerce
 * Domain Path:          /languages
 */

defined( 'ABSPATH' ) || exit;

define( 'SNW_VERSION', '0.9.0' );
define( 'SNW_PLUGIN_FILE', __FILE__ );
define( 'SNW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'SNW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'SNW_PLUGIN_BASENAME', plugin_basename( __FILE__ ) );

if ( ! file_exists( SNW_PLUGIN_DIR . 'vendor/autoload.php' ) ) {
	add_action(
		'admin_notices',
		function () {
			echo '<div class="notice notice-error"><p>' .
				esc_html__( 'Serial Number for WooCommerce: run "composer install" in the plugin directory before activating.', 'serial-number-for-woocommerce' ) .
				'</p></div>';
		}
	);
	return;
}

require_once SNW_PLUGIN_DIR . 'vendor/autoload.php';

register_activation_hook( SNW_PLUGIN_FILE, array( '\SerialNumberForWooCommerce\Install', 'activate' ) );
register_deactivation_hook( SNW_PLUGIN_FILE, array( '\SerialNumberForWooCommerce\Install', 'deactivate' ) );

// Declare compatibility with WooCommerce High-Performance Order Storage.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SNW_PLUGIN_FILE, true );
		}
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action(
				'admin_notices',
				function () {
					echo '<div class="notice notice-error"><p>' .
						esc_html__( 'Serial Number for WooCommerce requires WooCommerce to be installed and active.', 'serial-number-for-woocommerce' ) .
						'</p></div>';
				}
			);
			return;
		}

		\SerialNumberForWooCommerce\Plugin::instance()->init();
	}
);
