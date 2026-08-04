<?php
namespace SerialNumberForWooCommerce\Admin\Support;

use SerialNumberForWooCommerce\Licensing;

defined( 'ABSPATH' ) || exit;

/**
 * Free tier: "Contact Support" page under WooCommerce > Serial Numbers,
 * letting a seller email feedback, a support request, or a bug report
 * straight to us — this is support *from* the seller *to* us, not a
 * customer-facing helpdesk. Reachable from two places: its own page
 * (Menu routes `?action=support` here) and a short section on the
 * Settings page linking to it (see Admin\Settings) — one form, not two,
 * since a second `<form>` can't cleanly nest inside the Settings page's
 * own save form anyway.
 *
 * Stateless — every method is static, since rendering needs nothing but
 * the current request. Ajax (sibling class) handles the actual submission.
 */
final class Support {

	/**
	 * Fixed destination address, hardcoded rather than a setting — this is
	 * our own support inbox, not something a seller would ever need to
	 * change. Also shown in the UI as a standing fallback, since wp_mail()
	 * can silently fail to send on a misconfigured host.
	 */
	public static function support_email(): string {
		return 'felixdigitalshop@gmail.com';
	}

	public static function page_url(): string {
		return add_query_arg(
			array(
				'page'   => 'serial-number-for-woocommerce',
				'action' => 'support',
			),
			admin_url( 'admin.php' )
		);
	}

	/**
	 * @return array<string, string> value => translated label.
	 */
	public static function types(): array {
		return array(
			'question' => __( 'General question', 'serial-number-for-woocommerce' ),
			'bug'      => __( 'Bug report', 'serial-number-for-woocommerce' ),
			'feature'  => __( 'Feature request', 'serial-number-for-woocommerce' ),
			'billing'  => __( 'License / billing question', 'serial-number-for-woocommerce' ),
		);
	}

	/**
	 * Environment details attached to every request automatically, so a
	 * seller doesn't have to describe their setup by hand and we don't have
	 * to ask a round of "what version are you on" follow-up questions.
	 *
	 * @return array<string, string>
	 */
	public static function diagnostics(): array {
		global $wp_version;

		return array(
			'Site URL'       => home_url(),
			'Plugin version' => SNW_VERSION,
			'License'        => Licensing::is_pro_active() ? 'Pro' : 'Free',
			'WordPress'      => $wp_version,
			'WooCommerce'    => defined( 'WC_VERSION' ) ? WC_VERSION : '',
			'PHP'            => PHP_VERSION,
		);
	}

	public static function render(): void {
		$current_user  = wp_get_current_user();
		$default_name  = $current_user->display_name;
		$default_email = $current_user->user_email ? $current_user->user_email : get_option( 'admin_email' );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Support', 'serial-number-for-woocommerce' ); ?></h1>
			<p><?php esc_html_e( 'Have a question, found a bug, or want to request a feature? Send us a message and we\'ll get back to you.', 'serial-number-for-woocommerce' ); ?></p>

			<div id="snw-support-result" style="margin: 12px 0; max-width: 600px;"></div>

			<form id="snw-support-form">
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="snw-support-name"><?php esc_html_e( 'Name', 'serial-number-for-woocommerce' ); ?></label></th>
						<td><input type="text" id="snw-support-name" name="name" class="regular-text" value="<?php echo esc_attr( $default_name ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="snw-support-email"><?php esc_html_e( 'Email', 'serial-number-for-woocommerce' ); ?></label></th>
						<td><input type="email" id="snw-support-email" name="email" class="regular-text" required="required" value="<?php echo esc_attr( $default_email ); ?>" /></td>
					</tr>
					<tr>
						<th scope="row"><label for="snw-support-type"><?php esc_html_e( 'Type', 'serial-number-for-woocommerce' ); ?></label></th>
						<td>
							<select id="snw-support-type" name="type">
								<?php foreach ( self::types() as $key => $label ) : ?>
									<option value="<?php echo esc_attr( $key ); ?>"><?php echo esc_html( $label ); ?></option>
								<?php endforeach; ?>
							</select>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="snw-support-message"><?php esc_html_e( 'Message', 'serial-number-for-woocommerce' ); ?></label></th>
						<td><textarea id="snw-support-message" name="message" class="large-text" rows="6" required="required"></textarea></td>
					</tr>
				</table>

				<p class="description">
					<?php esc_html_e( 'Your site URL, plugin version, license status, and WordPress/WooCommerce/PHP versions are included automatically to help us help you faster.', 'serial-number-for-woocommerce' ); ?>
				</p>

				<p class="submit">
					<button type="submit" class="button button-primary"><?php esc_html_e( 'Send Message', 'serial-number-for-woocommerce' ); ?></button>
				</p>
			</form>

			<p class="description">
				<?php
				printf(
					/* translators: %s: support email address, as a mailto link */
					esc_html__( 'Prefer email? You can always reach us directly at %s.', 'serial-number-for-woocommerce' ),
					'<a href="mailto:' . esc_attr( self::support_email() ) . '">' . esc_html( self::support_email() ) . '</a>'
				);
				?>
			</p>
		</div>
		<?php
	}
}
