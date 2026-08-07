<?php
/**
 * Serial Number Notice email (plain text). Overridable by copying this
 * file to yourtheme/woocommerce/emails/plain/serial-number-notice.php.
 *
 * @var string $serial_number
 * @var string $product_name
 * @var string $email_heading
 * @var string $additional_content
 */

defined( 'ABSPATH' ) || exit;

echo wp_strip_all_tags( $email_heading ) . "\n\n";

if ( $product_name ) {
	echo wp_strip_all_tags(
		sprintf(
			/* translators: 1: product name, 2: serial number */
			__( 'Here is the serial number for %1$s: %2$s', 'serial-number-for-woocommerce' ),
			$product_name,
			$serial_number
		)
	) . "\n\n";
} else {
	echo wp_strip_all_tags(
		sprintf(
			/* translators: %s: serial number */
			__( 'Here is your serial number: %s', 'serial-number-for-woocommerce' ),
			$serial_number
		)
	) . "\n\n";
}

if ( $additional_content ) {
	echo wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n\n";
}
