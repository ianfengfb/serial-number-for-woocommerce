<?php
/**
 * Serial Number Notice email (HTML). Overridable by copying this file to
 * yourtheme/woocommerce/emails/serial-number-notice.php.
 *
 * @var string    $serial_number
 * @var string    $product_name
 * @var string    $email_heading
 * @var string    $additional_content
 * @var bool      $sent_to_admin
 * @var bool      $plain_text
 * @var \WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p>
<?php
if ( $product_name ) {
	printf(
		/* translators: 1: product name, 2: serial number */
		esc_html__( 'Here is the serial number for %1$s: %2$s', 'serial-number-for-woocommerce' ),
		'<strong>' . esc_html( $product_name ) . '</strong>',
		'<strong>' . esc_html( $serial_number ) . '</strong>'
	);
} else {
	printf(
		/* translators: %s: serial number */
		esc_html__( 'Here is your serial number: %s', 'serial-number-for-woocommerce' ),
		'<strong>' . esc_html( $serial_number ) . '</strong>'
	);
}
?>
</p>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
