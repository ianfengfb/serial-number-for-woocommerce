<?php
/**
 * License Activated (Admin) email (HTML). Overridable by copying this
 * file to yourtheme/woocommerce/emails/license-activated-admin.php.
 *
 * @var \WC_Order|null $order
 * @var string         $serial_number
 * @var string         $email_heading
 * @var string         $additional_content
 * @var bool           $sent_to_admin
 * @var bool           $plain_text
 * @var \WC_Email      $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<p>
<?php
printf(
	/* translators: %s: license key */
	esc_html__( 'License key %s was just activated by a customer.', 'serial-number-for-woocommerce' ),
	'<strong>' . esc_html( $serial_number ) . '</strong>'
);
?>
</p>
<?php if ( $order instanceof \WC_Order ) : ?>
<p>
<?php
printf(
	/* translators: 1: order number, 2: customer name */
	esc_html__( 'Order #%1$s — %2$s', 'serial-number-for-woocommerce' ),
	esc_html( $order->get_order_number() ),
	esc_html( trim( $order->get_billing_first_name() . ' ' . $order->get_billing_last_name() ) )
);
?>
</p>
<?php endif; ?>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
