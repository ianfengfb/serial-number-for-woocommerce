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
		new Admin\Support\Ajax();
		new Orders\Assigner();
		new Orders\ItemDisplay();
		new Orders\CustomerItemDisplay();
		new Orders\Ajax();
		new Orders\PrintSlip();
		new Orders\RefundHandler();

		if ( Licensing::is_pro_active() ) {
			new Pro\CustomRules\Ajax();
			new Pro\Export\Exporter();
			new Pro\Warranty\ActivationTrigger();
			new Pro\Warranty\ExpiryChecker();
			new Pro\Warranty\Extension();
			new Pro\Warranty\CancellationHandler();
			new Pro\LicenseKey\ActivationTrigger();
			new Pro\LicenseKey\CancellationHandler();
			new Pro\LicenseKey\CustomerActivation();
			new Pro\LicenseKey\Ajax();
			new Pro\LicenseKey\RestApi();
			new Pro\LicenseKey\AdminActivation();
			new Pro\LicenseKey\Renewal();
			new Pro\LicenseKey\CustomerRenewal();
			new Pro\LicenseKey\Webhooks();
			new Pro\PrintSlip\Printer();

			add_filter(
				'woocommerce_email_classes',
				function ( array $emails ): array {
					$emails['SNW_Warranty_Activated_Email']      = new Pro\Warranty\Emails\WarrantyActivatedEmail();
					$emails['SNW_Warranty_Expired_Email']        = new Pro\Warranty\Emails\WarrantyExpiredEmail();
					$emails['SNW_License_Delivered_Email']       = new Pro\LicenseKey\Emails\LicenseDeliveryEmail();
					$emails['SNW_License_Activated_Email']       = new Pro\LicenseKey\Emails\LicenseActivatedEmail();
					$emails['SNW_License_Expired_Email']         = new Pro\LicenseKey\Emails\LicenseExpiredEmail();
					$emails['SNW_License_Renewed_Email']         = new Pro\LicenseKey\Emails\LicenseRenewedEmail();
					$emails['SNW_License_Activated_Admin_Email'] = new Pro\LicenseKey\Emails\LicenseActivatedAdminEmail();

					return $emails;
				}
			);
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
