<?php
/**
 * Warranty Activated email (HTML). Overridable by copying this file to
 * yourtheme/woocommerce/emails/warranty-activated.php.
 *
 * @var string    $serial_number
 * @var string    $expires_at
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
printf(
	/* translators: %s: serial number */
	esc_html__( 'The warranty for serial number %s is now active.', 'serial-number-for-woocommerce' ),
	'<strong>' . esc_html( $serial_number ) . '</strong>'
);
?>
</p>
<?php if ( $expires_at ) : ?>
<p>
<?php
printf(
	/* translators: %s: warranty expiry date */
	esc_html__( 'It will expire on %s.', 'serial-number-for-woocommerce' ),
	'<strong>' . esc_html( $expires_at ) . '</strong>'
);
?>
</p>
<?php endif; ?>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
