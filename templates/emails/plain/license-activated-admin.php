<?php
/**
 * License Activated (Admin) email (plain text). Overridable by copying
 * this file to yourtheme/woocommerce/emails/plain/license-activated-admin.php.
 *
 * @var \WC_Order|null $order
 * @var string         $serial_number
 * @var string         $email_heading
 * @var string         $additional_content
 */

defined( 'ABSPATH' ) || exit;

echo wp_strip_all_tags( $email_heading ) . "\n\n";

echo wp_strip_all_tags(
	sprintf(
		/* translators: %s: license key */
		__( 'License key %s was just activated by a customer.', 'serial-number-for-woocommerce' ),
		$serial_number
	)
) . "\n\n";

if ( $order instanceof \WC_Order ) {
	echo wp_strip_all_tags(
		sprintf(
			/* translators: 1: order number, 2: customer name */
			__( 'Order #%1$s — %2$s', 'serial-number-for-woocommerce' ),
			$order->get_order_number(),
			trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() )
		)
	) . "\n\n";
}

if ( $additional_content ) {
	echo wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n\n";
}
