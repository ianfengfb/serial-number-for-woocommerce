<?php
namespace SerialNumberForWooCommerce\Pro\LicenseKey;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: lets a seller's own external system (their license server, a
 * fulfillment tool, whatever they run outside WordPress) activate a
 * license key by calling this plugin's REST API, for a product whose
 * activation trigger is set to 'api'. The counterpart to
 * CustomerActivation's manual trigger — same underlying activate_serial(),
 * different caller and different auth (a shared API key instead of a
 * logged-in customer session).
 *
 * Auth is a single store-wide shared secret (LicenseKey::get_or_create_api_key()),
 * sent as the X-SNW-Api-Key header — deliberately not WooCommerce's own
 * REST API consumer key/secret system, which would require the seller to
 * set up a WC API key with the right permissions just for this; a single
 * plugin-specific secret is simpler for a "call one endpoint" integration.
 */
final class RestApi {

	const NAMESPACE = 'snw/v1';

	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'admin_post_snw_regenerate_license_api_key', array( $this, 'regenerate_api_key' ) );
	}

	public function register_routes(): void {
		register_rest_route(
			self::NAMESPACE,
			'/license/activate',
			array(
				'methods'             => 'POST',
				'callback'            => array( $this, 'activate' ),
				'permission_callback' => array( $this, 'check_api_key' ),
				'args'                => array(
					'serial_number' => array(
						'required' => true,
						'type'     => 'string',
					),
				),
			)
		);
	}

	/**
	 * @return true|\WP_Error
	 */
	public function check_api_key( \WP_REST_Request $request ) {
		$provided = (string) $request->get_header( 'x-snw-api-key' );

		if ( '' === $provided || ! hash_equals( LicenseKey::get_or_create_api_key(), $provided ) ) {
			return new \WP_Error(
				'snw_invalid_api_key',
				__( 'Invalid or missing API key.', 'serial-number-for-woocommerce' ),
				array( 'status' => 401 )
			);
		}

		return true;
	}

	/**
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function activate( \WP_REST_Request $request ) {
		$serial_number = trim( (string) $request->get_param( 'serial_number' ) );

		if ( '' === $serial_number ) {
			return new \WP_Error(
				'snw_missing_serial_number',
				__( 'serial_number is required.', 'serial-number-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		$serial = Repository::find_by_serial_number( $serial_number );

		if ( ! $serial ) {
			return new \WP_Error(
				'snw_serial_not_found',
				__( 'No matching serial number was found.', 'serial-number-for-woocommerce' ),
				array( 'status' => 404 )
			);
		}

		if ( ! LicenseKey::is_enabled_for_product( (int) $serial->product_id ) ) {
			return new \WP_Error(
				'snw_not_a_license',
				__( 'This serial number is not a license key.', 'serial-number-for-woocommerce' ),
				array( 'status' => 400 )
			);
		}

		if ( 'api' !== LicenseKey::activation_trigger_for_product( (int) $serial->product_id ) ) {
			return new \WP_Error(
				'snw_wrong_trigger',
				__( "This license key's product is not set to external/API activation.", 'serial-number-for-woocommerce' ),
				array( 'status' => 409 )
			);
		}

		if ( ! empty( $serial->activated_at ) ) {
			return new \WP_Error(
				'snw_already_activated',
				__( 'This license key is already activated.', 'serial-number-for-woocommerce' ),
				array( 'status' => 409 )
			);
		}

		LicenseKey::activate_serial( (int) $serial->id );

		return new \WP_REST_Response(
			array(
				'success'       => true,
				'serial_number' => $serial->serial_number,
			),
			200
		);
	}

	/**
	 * Backs the "Regenerate" link in the License Key (Pro) settings section —
	 * a plain admin-post redirect, no AJAX, matching Export's own
	 * admin-post-as-a-link pattern.
	 */
	public function regenerate_api_key(): void {
		check_admin_referer( 'snw_regenerate_license_api_key' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'serial-number-for-woocommerce' ) );
		}

		LicenseKey::regenerate_api_key();

		wp_safe_redirect( wp_get_referer() ?: admin_url() );
		exit;
	}
}
