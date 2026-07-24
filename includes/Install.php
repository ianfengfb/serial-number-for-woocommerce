<?php
namespace SerialNumberForWooCommerce;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the plugin's database table on activation.
 */
final class Install {

	public static function activate(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'snw_serial_numbers';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			serial_number varchar(191) NOT NULL,
			status varchar(20) NOT NULL DEFAULT 'active',
			product_id bigint(20) unsigned DEFAULT NULL,
			order_id bigint(20) unsigned DEFAULT NULL,
			created_at datetime NOT NULL,
			expires_at datetime DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY serial_number (serial_number),
			KEY product_id (product_id),
			KEY order_id (order_id),
			KEY status (status)
		) {$charset_collate};";

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
