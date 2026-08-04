<?php
/**
 * License Renewed email (HTML). Overridable by copying this file to
 * yourtheme/woocommerce/emails/license-renewed.php.
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
	/* translators: %s: license key */
	esc_html__( 'Your license key %s has been renewed.', 'serial-number-for-woocommerce' ),
	'<strong>' . esc_html( $serial_number ) . '</strong>'
);
?>
</p>
<?php if ( $expires_at ) : ?>
<p>
<?php
printf(
	/* translators: %s: license expiry date */
	esc_html__( 'It is now valid until %s.', 'serial-number-for-woocommerce' ),
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
