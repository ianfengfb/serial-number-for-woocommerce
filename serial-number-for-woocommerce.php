<?php
/**
 * Plugin Name:          Serial Number for WooCommerce
 * Description:          Assign and manage serial numbers for WooCommerce products and orders.
 * Version:              1.9.0
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

define( 'SNW_VERSION', '1.9.0' );
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

register_activation_hook( SNW_PLUGIN_FILE, 'snw_activate_plugin' );
register_deactivation_hook( SNW_PLUGIN_FILE, array( '\SerialNumberForWooCommerce\Install', 'deactivate' ) );

/**
 * Refuses activation outright when WooCommerce isn't active, so the
 * plugin's own database table is never created for a site that can't use
 * it. Checked here rather than relying on the plugins_loaded gate below:
 * register_activation_hook's callback runs immediately as part of the
 * "activate this plugin" request, before this same request's own
 * plugins_loaded has a chance to run for a plugin that wasn't already
 * active — so by the time the later gate would fire, Install::activate()
 * would already have run.
 */
function snw_activate_plugin() {
	if ( ! class_exists( 'WooCommerce' ) ) {
		wp_die(
			esc_html__( 'Serial Number for WooCommerce requires WooCommerce to be installed and active. Please install/activate WooCommerce, then activate this plugin again.', 'serial-number-for-woocommerce' ),
			esc_html__( 'Plugin Activation Error', 'serial-number-for-woocommerce' ),
			array( 'back_link' => true )
		);
	}

	\SerialNumberForWooCommerce\Install::activate();
}

// Declare compatibility with WooCommerce High-Performance Order Storage.
add_action(
	'before_woocommerce_init',
	function () {
		if ( class_exists( \Automattic\WooCommerce\Utilities\FeaturesUtil::class ) ) {
			\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', SNW_PLUGIN_FILE, true );
		}
	}
);

/**
 * Covers the case snw_activate_plugin() above doesn't: WooCommerce being
 * deactivated LATER, while this plugin is already active. Rather than
 * staying active in a permanently non-functional state (the previous
 * behavior — an admin_notices callback that just nagged forever),
 * self-deactivates and queues a one-time notice via a transient, since by
 * the time admin_init runs it's too late in the request to reliably still
 * register a same-pageload admin_notices callback after deactivating.
 */
add_action(
	'admin_init',
	function () {
		if ( class_exists( 'WooCommerce' ) || ! is_plugin_active( SNW_PLUGIN_BASENAME ) ) {
			return;
		}

		deactivate_plugins( SNW_PLUGIN_BASENAME );
		set_transient( 'snw_missing_woocommerce_notice', true, MINUTE_IN_SECONDS );
	}
);

add_action(
	'admin_notices',
	function () {
		if ( ! get_transient( 'snw_missing_woocommerce_notice' ) ) {
			return;
		}

		delete_transient( 'snw_missing_woocommerce_notice' );

		echo '<div class="notice notice-error"><p>' .
			esc_html__( 'Serial Number for WooCommerce requires WooCommerce to be installed and active, and has been deactivated.', 'serial-number-for-woocommerce' ) .
			'</p></div>';
	}
);

add_action(
	'plugins_loaded',
	function () {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return;
		}

		\SerialNumberForWooCommerce\Plugin::instance()->init();
	}
);
