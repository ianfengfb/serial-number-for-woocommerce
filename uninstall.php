<?php
/**
 * Uninstall handler. WordPress runs this file (never register_uninstall_hook,
 * since this is simpler and doesn't need the plugin's own classes loaded)
 * only when a user clicks "Delete" on this plugin from wp-admin — never on
 * a plain deactivation. Drops the plugin's own database table and deletes
 * every option/post-meta/order-item-meta key it ever wrote, so nothing is
 * left behind in the database.
 *
 * Written standalone (no dependency on the plugin's own autoloader/classes)
 * since WooCommerce itself may not even be active at uninstall time.
 */

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;

// The plugin's own table.
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}snw_serial_numbers" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- fixed table name, no user input.

// Every option this plugin ever wrote via update_option()/add_option().
$options = array(
	'snw_default_status',
	'snw_auto_prefix',
	'snw_auto_postfix',
	'snw_auto_length',
	'snw_auto_charset',
	'snw_show_serials_in_emails',
	'snw_show_serials_in_account',
	'snw_warranty_activation_trigger',
	'snw_warranty_activation_days',
	'snw_warranty_revoke_on_refund',
	'snw_license_revoke_on_refund',
	'snw_license_api_key',
	'snw_print_slip_message',
);

// WooCommerce's own WC_Settings_API auto-creates one "{id}_settings" option
// per registered email (enabled/recipient/subject/heading/etc.) — these IDs
// are every email this plugin has ever registered via woocommerce_email_classes.
$email_ids = array(
	'snw_warranty_activated',
	'snw_warranty_expired',
	'snw_license_delivered',
	'snw_license_activated',
	'snw_license_expired',
	'snw_license_renewed',
	'snw_license_activated_admin',
);

foreach ( $email_ids as $email_id ) {
	$options[] = 'woocommerce_' . $email_id . '_settings';
}

foreach ( $options as $option ) {
	delete_option( $option );
}

delete_transient( 'snw_missing_woocommerce_notice' );

// Product-level meta — products are always regular posts (unaffected by
// HPOS, which only changes order storage), so delete_post_meta_by_key()
// reaches every one regardless of how many products use it.
$product_meta_keys = array(
	'_snw_enabled',
	'_snw_manage_stock',
	'_snw_custom_rule_enabled',
	'_snw_custom_prefix',
	'_snw_custom_suffix',
	'_snw_custom_length',
	'_snw_custom_charset',
	'_snw_warranty_enabled',
	'_snw_warranty_length',
	'_snw_warranty_period',
	'_snw_warranty_extension_enabled',
	'_snw_warranty_extension_length',
	'_snw_warranty_extension_period',
	'_snw_warranty_extension_price',
	'_snw_license_enabled',
	'_snw_license_length',
	'_snw_license_period',
	'_snw_license_activation_trigger',
	'_snw_license_instructions',
	'_snw_license_renewal_price',
);

foreach ( $product_meta_keys as $meta_key ) {
	delete_post_meta_by_key( $meta_key );
}

// Order-item meta — order items always live in their own dedicated tables
// (woocommerce_order_items/woocommerce_order_itemmeta), regardless of
// whether HPOS is enabled for orders themselves, so a direct delete here
// is the correct approach rather than delete_post_meta_by_key().
$order_item_meta_keys = array(
	'_snw_serial_ids',
	'_snw_renewal_serial_id',
	'_snw_warranty_extension',
);

foreach ( $order_item_meta_keys as $meta_key ) {
	$wpdb->delete( $wpdb->prefix . 'woocommerce_order_itemmeta', array( 'meta_key' => $meta_key ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.SlowDBQueryMetaKey
}

// Order-level meta — the one order meta key this plugin writes
// (_snw_license_delivery_notified) can live in wp_postmeta (legacy CPT
// order storage) or wp_wc_orders_meta (HPOS), depending on the store's
// setting; delete from whichever table actually exists.
delete_post_meta_by_key( '_snw_license_delivery_notified' );

$hpos_meta_table = $wpdb->prefix . 'wc_orders_meta';

if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $hpos_meta_table ) ) === $hpos_meta_table ) {
	$wpdb->delete( $hpos_meta_table, array( 'meta_key' => '_snw_license_delivery_notified' ) ); // phpcs:ignore WordPress.DB.SlowDBQuery.SlowDBQueryMetaKey
}
