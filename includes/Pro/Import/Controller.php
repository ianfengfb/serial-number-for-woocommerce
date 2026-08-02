<?php
namespace SerialNumberForWooCommerce\Pro\Import;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;
use SerialNumberForWooCommerce\Licensing;
use SerialNumberForWooCommerce\Pro\StockSync\StockSync;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: imports serial numbers from a CSV file in two steps — upload+parse,
 * then a preview (with problem rows flagged) that requires an explicit
 * confirm before anything is written to the database.
 *
 * The two steps are separate requests (a redirect sits between them, so
 * refreshing the preview never re-uploads), so the parsed rows are bridged
 * through a short-lived transient keyed by a one-time token rather than
 * being re-parsed or held in PHP session state.
 */
final class Controller {

	const MAX_ROWS        = 1000;
	const TRANSIENT_PREFIX = 'snw_import_';
	const TRANSIENT_TTL    = 15 * MINUTE_IN_SECONDS;

	private array $errors = array();

	public function handle(): void {
		if ( ! Licensing::is_pro_active() ) {
			return;
		}

		if ( isset( $_POST['snw_import_upload'] ) ) {
			$this->handle_upload();
		} elseif ( isset( $_POST['snw_import_commit'] ) ) {
			$this->handle_commit();
		}
	}

	private function handle_upload(): void {
		check_admin_referer( 'snw_import_serials' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'serial-number-for-woocommerce' ) );
		}

		if ( empty( $_FILES['snw_import_file']['tmp_name'] ) || UPLOAD_ERR_OK !== $_FILES['snw_import_file']['error'] ) {
			$this->errors[] = __( 'Please choose a CSV file to upload.', 'serial-number-for-woocommerce' );
			return;
		}

		$handle = fopen( $_FILES['snw_import_file']['tmp_name'], 'r' );

		if ( ! $handle ) {
			$this->errors[] = __( 'Could not read the uploaded file.', 'serial-number-for-woocommerce' );
			return;
		}

		$header = fgetcsv( $handle );

		if ( is_array( $header ) && isset( $header[0] ) ) {
			// Strip a UTF-8 BOM some spreadsheet apps prepend to the first cell.
			$header[0] = preg_replace( '/^\xEF\xBB\xBF/', '', $header[0] );
		}

		if ( ! $this->is_valid_header( $header ) ) {
			fclose( $handle );
			$this->errors[] = sprintf(
				/* translators: %s: the required header row */
				__( 'The file must start with this exact header row: %s', 'serial-number-for-woocommerce' ),
				implode( ', ', RowParser::EXPECTED_HEADERS )
			);
			return;
		}

		$data_rows = array();
		$truncated = false;

		while ( false !== ( $row = fgetcsv( $handle ) ) ) {
			if ( 1 === count( $row ) && null === $row[0] ) {
				continue; // blank line
			}

			if ( count( $data_rows ) >= self::MAX_ROWS ) {
				$truncated = true;
				break;
			}

			$data_rows[] = $row;
		}

		fclose( $handle );

		if ( empty( $data_rows ) ) {
			$this->errors[] = __( 'The file has no data rows.', 'serial-number-for-woocommerce' );
			return;
		}

		$token = wp_generate_password( 20, false );

		set_transient(
			self::TRANSIENT_PREFIX . $token,
			array(
				'rows'      => RowParser::parse_rows( $data_rows ),
				'truncated' => $truncated,
			),
			self::TRANSIENT_TTL
		);

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'   => 'serial-number-for-woocommerce',
					'action' => 'import',
					'token'  => $token,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	private function is_valid_header( $header ): bool {
		if ( ! is_array( $header ) ) {
			return false;
		}

		$normalized = array_map(
			static function ( $cell ) {
				return strtolower( trim( (string) $cell ) );
			},
			$header
		);

		return $normalized === RowParser::EXPECTED_HEADERS;
	}

	/**
	 * Re-checks each row against the database at commit time (not just at
	 * preview time) before inserting, since a row that was importable a
	 * preview ago may have since been created by other means (another
	 * import, manual add) — that re-check is why this loop, not a bulk
	 * insert, drives the commit.
	 */
	private function handle_commit(): void {
		check_admin_referer( 'snw_import_commit_serial_numbers' );

		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'You do not have permission to do this.', 'serial-number-for-woocommerce' ) );
		}

		$token  = isset( $_POST['snw_import_token'] ) ? sanitize_text_field( wp_unslash( $_POST['snw_import_token'] ) ) : '';
		$stored = $token ? get_transient( self::TRANSIENT_PREFIX . $token ) : false;

		if ( false === $stored ) {
			wp_safe_redirect(
				add_query_arg(
					array(
						'page'       => 'serial-number-for-woocommerce',
						'action'     => 'import',
						'snw_notice' => 'import_expired',
					),
					admin_url( 'admin.php' )
				)
			);
			exit;
		}

		$imported    = 0;
		$skipped     = 0;
		$product_ids = array();

		foreach ( $stored['rows'] as $row ) {
			if ( ! empty( $row['errors'] ) || Repository::exists( $row['serial_number'] ) ) {
				++$skipped;
				continue;
			}

			$inserted = Repository::insert(
				array(
					'serial_number' => $row['serial_number'],
					'status'        => $row['status'],
					'product_id'    => $row['product_id'] ?: 0,
					'order_id'      => 0,
					'expires_at'    => $row['expires_at'] ?: '',
				)
			);

			if ( ! $inserted ) {
				++$skipped;
				continue;
			}

			++$imported;

			if ( $row['product_id'] ) {
				$product_ids[ (int) $row['product_id'] ] = true;
			}
		}

		foreach ( array_keys( $product_ids ) as $product_id ) {
			StockSync::sync( $product_id );
		}

		delete_transient( self::TRANSIENT_PREFIX . $token );

		wp_safe_redirect(
			add_query_arg(
				array(
					'page'        => 'serial-number-for-woocommerce',
					'snw_notice'  => 'imported',
					'snw_count'   => $imported,
					'snw_skipped' => $skipped,
				),
				admin_url( 'admin.php' )
			)
		);
		exit;
	}

	public function render(): void {
		if ( ! Licensing::is_pro_active() ) {
			return;
		}

		$token = isset( $_GET['token'] ) ? sanitize_text_field( wp_unslash( $_GET['token'] ) ) : '';

		if ( '' !== $token ) {
			$this->render_preview( $token );
			return;
		}

		$this->render_upload_form();
	}

	private function render_upload_form(): void {
		$back_url = add_query_arg( array( 'page' => 'serial-number-for-woocommerce' ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Import Serial Numbers', 'serial-number-for-woocommerce' ); ?></h1>
			<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action"><?php esc_html_e( 'Back to list', 'serial-number-for-woocommerce' ); ?></a>

			<?php if ( ! empty( $this->errors ) ) : ?>
				<div class="notice notice-error">
					<?php foreach ( $this->errors as $error ) : ?>
						<p><?php echo esc_html( $error ); ?></p>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>

			<div class="notice notice-info" style="padding: 1em;">
				<p>
					<strong><?php esc_html_e( 'The file must be a CSV with this exact header row:', 'serial-number-for-woocommerce' ); ?></strong>
					<code><?php echo esc_html( implode( ',', RowParser::EXPECTED_HEADERS ) ); ?></code>
				</p>
				<ul style="list-style: disc; margin-left: 1.5em;">
					<li><?php esc_html_e( 'serial_number — required, and must not already exist.', 'serial-number-for-woocommerce' ); ?></li>
					<li><?php esc_html_e( 'status — one of the statuses on the Serial Numbers screen (case insensitive). Leave blank to use the configured default.', 'serial-number-for-woocommerce' ); ?></li>
					<li><?php esc_html_e( 'product_sku — the product\'s SKU. Leave blank for no product.', 'serial-number-for-woocommerce' ); ?></li>
					<li><?php esc_html_e( 'product_id — the product\'s ID. Overrides product_sku when both are given. Leave both blank for no product.', 'serial-number-for-woocommerce' ); ?></li>
					<li><?php esc_html_e( 'expires_at — format dd/mm/yyyy. Leave blank for no expiry.', 'serial-number-for-woocommerce' ); ?></li>
				</ul>
				<p>
					<?php
					printf(
						/* translators: %d: maximum number of data rows accepted per import */
						esc_html__( 'Order ID is not accepted on import. Up to %d data rows are read per file. Nothing is written to the database until you review a preview and confirm it — problem rows are flagged there.', 'serial-number-for-woocommerce' ),
						(int) self::MAX_ROWS
					);
					?>
				</p>
			</div>

			<form method="post" enctype="multipart/form-data">
				<?php wp_nonce_field( 'snw_import_serials' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th><label for="snw-import-file"><?php esc_html_e( 'CSV File', 'serial-number-for-woocommerce' ); ?></label></th>
						<td><input type="file" id="snw-import-file" name="snw_import_file" accept=".csv,text/csv" required /></td>
					</tr>
				</table>
				<?php submit_button( __( 'Upload & Preview', 'serial-number-for-woocommerce' ), 'primary', 'snw_import_upload' ); ?>
			</form>
		</div>
		<?php
	}

	private function render_preview( string $token ): void {
		$stored = get_transient( self::TRANSIENT_PREFIX . $token );

		if ( false === $stored ) {
			?>
			<div class="wrap">
				<h1 class="wp-heading-inline"><?php esc_html_e( 'Import Serial Numbers', 'serial-number-for-woocommerce' ); ?></h1>
				<div class="notice notice-error">
					<p><?php esc_html_e( 'This preview has expired. Please upload the file again.', 'serial-number-for-woocommerce' ); ?></p>
				</div>
			</div>
			<?php
			$this->render_upload_form();
			return;
		}

		$rows             = $stored['rows'];
		$importable_count = count(
			array_filter(
				$rows,
				static function ( $row ) {
					return empty( $row['errors'] );
				}
			)
		);
		$error_count = count( $rows ) - $importable_count;
		$back_url    = add_query_arg( array( 'page' => 'serial-number-for-woocommerce' ), admin_url( 'admin.php' ) );
		?>
		<div class="wrap">
			<h1 class="wp-heading-inline"><?php esc_html_e( 'Import Preview', 'serial-number-for-woocommerce' ); ?></h1>
			<a href="<?php echo esc_url( $back_url ); ?>" class="page-title-action"><?php esc_html_e( 'Back to list', 'serial-number-for-woocommerce' ); ?></a>

			<?php if ( ! empty( $stored['truncated'] ) ) : ?>
				<div class="notice notice-warning">
					<p>
						<?php
						printf(
							/* translators: %1$d: maximum number of data rows accepted per import */
							esc_html__( 'The file had more than %1$d data rows; only the first %1$d were read.', 'serial-number-for-woocommerce' ),
							(int) self::MAX_ROWS
						);
						?>
					</p>
				</div>
			<?php endif; ?>

			<p>
				<?php
				printf(
					/* translators: 1: number of rows that will be imported, 2: number of rows flagged with an error */
					esc_html__( '%1$d row(s) ready to import. %2$d row(s) flagged with an error will be skipped.', 'serial-number-for-woocommerce' ),
					(int) $importable_count,
					(int) $error_count
				);
				?>
			</p>

			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Line', 'serial-number-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Serial Number', 'serial-number-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Status', 'serial-number-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Product', 'serial-number-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Expires On', 'serial-number-for-woocommerce' ); ?></th>
						<th><?php esc_html_e( 'Result', 'serial-number-for-woocommerce' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php foreach ( $rows as $row ) : ?>
						<tr>
							<td><?php echo (int) $row['line']; ?></td>
							<td><?php echo esc_html( $row['serial_number'] ); ?></td>
							<td><?php echo esc_html( $row['status_label'] ); ?></td>
							<td><?php echo wp_kses_post( $row['product_label'] ); ?></td>
							<td><?php echo wp_kses_post( $row['expires_label'] ); ?></td>
							<td>
								<?php if ( ! empty( $row['errors'] ) ) : ?>
									<span style="color: #b32d2e;">&#10007; <?php echo esc_html( implode( ' ', $row['errors'] ) ); ?></span>
								<?php elseif ( ! empty( $row['warnings'] ) ) : ?>
									<span style="color: #b26200;">&#9888; <?php echo esc_html( implode( ' ', $row['warnings'] ) ); ?></span>
								<?php else : ?>
									<span style="color: #2a7a2a;">&#10003; <?php esc_html_e( 'OK', 'serial-number-for-woocommerce' ); ?></span>
								<?php endif; ?>
							</td>
						</tr>
					<?php endforeach; ?>
				</tbody>
			</table>

			<form method="post" style="margin-top: 1em;">
				<?php wp_nonce_field( 'snw_import_commit_serial_numbers' ); ?>
				<input type="hidden" name="snw_import_token" value="<?php echo esc_attr( $token ); ?>" />
				<?php submit_button( __( 'Confirm Import', 'serial-number-for-woocommerce' ), 'primary', 'snw_import_commit', true, $importable_count > 0 ? array() : array( 'disabled' => 'disabled' ) ); ?>
			</form>
		</div>
		<?php
	}
}
