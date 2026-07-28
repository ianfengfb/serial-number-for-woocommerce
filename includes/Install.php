<?php
namespace SerialNumberForWooCommerce;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Creates the plugin's database table on activation.
 */
final class Install {

	/**
	 * Status values used before the Available/Assigned/Activated/Expired/
	 * Unavailable set, mapped to their replacement. Applied on activation so
	 * rows written by an earlier version keep a status the UI still knows.
	 */
	const LEGACY_STATUS_MAP = array(
		'active'   => Status::AVAILABLE,
		'inactive' => Status::UNAVAILABLE,
		'revoked'  => Status::UNAVAILABLE,
	);

	public static function activate(): void {
		global $wpdb;

		$table_name      = $wpdb->prefix . 'snw_serial_numbers';
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE {$table_name} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			serial_number varchar(191) NOT NULL,
			status varchar(20) NOT NULL DEFAULT '" . Status::FALLBACK . "',
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

		self::migrate_legacy_statuses( $table_name );
		self::migrate_legacy_default_status();
	}

	private static function migrate_legacy_statuses( string $table_name ): void {
		global $wpdb;

		foreach ( self::LEGACY_STATUS_MAP as $old => $new ) {
			$wpdb->update(
				$table_name,
				array( 'status' => $new ),
				array( 'status' => $old ),
				array( '%s' ),
				array( '%s' )
			);
		}
	}

	private static function migrate_legacy_default_status(): void {
		$default = get_option( 'snw_default_status' );

		if ( is_string( $default ) && isset( self::LEGACY_STATUS_MAP[ $default ] ) ) {
			update_option( 'snw_default_status', self::LEGACY_STATUS_MAP[ $default ] );
		}
	}
}
