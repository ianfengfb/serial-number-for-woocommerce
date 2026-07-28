<?php
namespace SerialNumberForWooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Core bootstrap. Wires up free-tier and (when licensed) Pro-tier features.
 */
final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}

		return self::$instance;
	}

	private function __construct() {}

	public function init(): void {
		new Admin\Menu();
		new Admin\Products\ProductTab();
		new Orders\Assigner();

		add_filter(
			'woocommerce_get_settings_pages',
			function ( array $pages ): array {
				$pages[] = new Admin\Settings();

				return $pages;
			}
		);
	}
}
