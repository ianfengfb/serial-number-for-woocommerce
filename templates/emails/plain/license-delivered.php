<?php
/**
 * License Delivery email (plain text). Overridable by copying this file
 * to yourtheme/woocommerce/emails/plain/license-delivered.php.
 *
 * @var array  $licenses Array of ['product_name' => string, 'instructions' => string, 'activation_note' => string, 'keys' => string[]].
 * @var string $email_heading
 * @var string $additional_content
 */

defined( 'ABSPATH' ) || exit;

echo wp_strip_all_tags( $email_heading ) . "\n\n";

foreach ( $licenses as $license ) {
	echo wp_strip_all_tags( $license['product_name'] ) . "\n";

	$label = _n( 'License Key', 'License Keys', count( $license['keys'] ), 'serial-number-for-woocommerce' );
	echo $label . ': ' . implode( ', ', $license['keys'] ) . "\n";

	if ( $license['activation_note'] ) {
		echo wp_strip_all_tags( $license['activation_note'] ) . "\n";
	}

	if ( $license['instructions'] ) {
		echo wp_strip_all_tags( wptexturize( $license['instructions'] ) ) . "\n";
	}

	echo "\n";
}

if ( $additional_content ) {
	echo wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n\n";
}
