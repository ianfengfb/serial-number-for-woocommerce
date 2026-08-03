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
		new Orders\ItemDisplay();
		new Orders\CustomerItemDisplay();
		new Orders\Ajax();

		if ( Licensing::is_pro_active() ) {
			new Pro\CustomRules\Ajax();
			new Pro\Export\Exporter();
			new Pro\Warranty\ActivationTrigger();
			new Pro\Warranty\ExpiryChecker();
		}

		add_filter(
			'woocommerce_get_settings_pages',
			function ( array $pages ): array {
				$pages[] = new Admin\Settings();

				return $pages;
			}
		);
	}
}
