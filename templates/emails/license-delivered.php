<?php
/**
 * License Delivery email (HTML). Overridable by copying this file to
 * yourtheme/woocommerce/emails/license-delivered.php.
 *
 * @var array     $licenses Array of ['product_name' => string, 'instructions' => string, 'activation_note' => string, 'keys' => string[]].
 * @var string    $email_heading
 * @var string    $additional_content
 * @var bool      $sent_to_admin
 * @var bool      $plain_text
 * @var \WC_Email $email
 */

defined( 'ABSPATH' ) || exit;

do_action( 'woocommerce_email_header', $email_heading, $email );
?>
<?php foreach ( $licenses as $license ) : ?>
	<h2 style="margin-top: 1.5em;"><?php echo esc_html( $license['product_name'] ); ?></h2>
	<p>
		<strong><?php echo esc_html( _n( 'License Key', 'License Keys', count( $license['keys'] ), 'serial-number-for-woocommerce' ) ); ?>:</strong>
		<?php echo esc_html( implode( ', ', $license['keys'] ) ); ?>
	</p>
	<?php if ( $license['activation_note'] ) : ?>
		<p><em><?php echo esc_html( $license['activation_note'] ); ?></em></p>
	<?php endif; ?>
	<?php if ( $license['instructions'] ) : ?>
		<div>
			<?php echo wp_kses_post( wpautop( wptexturize( $license['instructions'] ) ) ); ?>
		</div>
	<?php endif; ?>
<?php endforeach; ?>
<?php
if ( $additional_content ) {
	echo wp_kses_post( wpautop( wptexturize( $additional_content ) ) );
}

do_action( 'woocommerce_email_footer', $email );
