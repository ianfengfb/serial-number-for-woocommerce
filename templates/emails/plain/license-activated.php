<?php
/**
 * License Activated email (plain text). Overridable by copying this file
 * to yourtheme/woocommerce/emails/plain/license-activated.php.
 *
 * @var string $serial_number
 * @var string $expires_at
 * @var string $email_heading
 * @var string $additional_content
 */

defined( 'ABSPATH' ) || exit;

echo wp_strip_all_tags( $email_heading ) . "\n\n";

echo wp_strip_all_tags(
	sprintf(
		/* translators: %s: license key */
		__( 'Your license key %s is now active.', 'serial-number-for-woocommerce' ),
		$serial_number
	)
) . "\n\n";

if ( $expires_at ) {
	echo wp_strip_all_tags(
		sprintf(
			/* translators: %s: license expiry date */
			__( 'It will expire on %s.', 'serial-number-for-woocommerce' ),
			$expires_at
		)
	) . "\n\n";
}

if ( $additional_content ) {
	echo wp_strip_all_tags( wptexturize( $additional_content ) ) . "\n\n";
}
