<?php
namespace SerialNumberForWooCommerce\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Free tier: registers the "Serial Numbers" submenu under WooCommerce.
 */
final class Menu {

	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_menu' ) );
	}

	public function register_menu(): void {
		add_submenu_page(
			'woocommerce',
			__( 'Serial Numbers', 'serial-number-for-woocommerce' ),
			__( 'Serial Numbers', 'serial-number-for-woocommerce' ),
			'manage_woocommerce',
			'serial-number-for-woocommerce',
			array( $this, 'render_page' )
		);
	}

	public function render_page(): void {
		echo '<div class="wrap"><h1>' . esc_html__( 'Serial Numbers', 'serial-number-for-woocommerce' ) . '</h1></div>';
	}
}
