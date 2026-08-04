<?php
namespace SerialNumberForWooCommerce\Admin\Support;

defined( 'ABSPATH' ) || exit;

/**
 * Free tier: backs the Support page's contact form, sending a plain
 * wp_mail() to Support::support_email() rather than an external
 * helpdesk/API — no service to configure, works on any host. Reuses the
 * `snw_admin` nonce already localized (as SNWAdmin) on this same admin
 * page by Admin\Menu, same as every other AJAX action there.
 */
final class Ajax {

	public function __construct() {
		add_action( 'wp_ajax_snw_submit_support_request', array( $this, 'submit' ) );
	}

	public function submit(): void {
		check_ajax_referer( 'snw_admin', 'nonce' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'serial-number-for-woocommerce' ) ), 403 );
		}

		$name    = isset( $_POST['name'] ) ? sanitize_text_field( wp_unslash( $_POST['name'] ) ) : '';
		$email   = isset( $_POST['email'] ) ? sanitize_email( wp_unslash( $_POST['email'] ) ) : '';
		$type    = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$message = isset( $_POST['message'] ) ? sanitize_textarea_field( wp_unslash( $_POST['message'] ) ) : '';

		if ( '' === $message || ! is_email( $email ) ) {
			wp_send_json_error( array( 'message' => __( 'Please enter a valid email address and a message.', 'serial-number-for-woocommerce' ) ) );
		}

		if ( ! array_key_exists( $type, Support::types() ) ) {
			$type = 'question';
		}

		if ( self::send( $name, $email, $type, $message ) ) {
			wp_send_json_success( array( 'message' => __( 'Thanks! Your message has been sent.', 'serial-number-for-woocommerce' ) ) );
		}

		wp_send_json_error(
			array(
				'message' => sprintf(
					/* translators: %s: support email address, as a mailto link */
					__( 'Something went wrong sending your message. Please email us directly at %s.', 'serial-number-for-woocommerce' ),
					'<a href="mailto:' . esc_attr( Support::support_email() ) . '">' . esc_html( Support::support_email() ) . '</a>'
				),
			)
		);
	}

	private static function send( string $name, string $email, string $type, string $message ): bool {
		$subject = sprintf(
			'[Serial Number for WooCommerce] %s from %s',
			Support::types()[ $type ],
			home_url()
		);

		$body = array(
			'Name: ' . ( '' !== $name ? $name : '(not provided)' ),
			'Email: ' . $email,
			'Type: ' . Support::types()[ $type ],
			'',
			'Message:',
			$message,
			'',
			'--- Diagnostic info ---',
		);

		foreach ( Support::diagnostics() as $label => $value ) {
			$body[] = $label . ': ' . $value;
		}

		return wp_mail( Support::support_email(), $subject, implode( "\n", $body ), array( 'Reply-To: ' . $email ) );
	}
}
