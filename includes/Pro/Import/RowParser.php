<?php
namespace SerialNumberForWooCommerce\Pro\Import;

use SerialNumberForWooCommerce\Admin\SerialNumbers\Repository;
use SerialNumberForWooCommerce\Admin\SerialNumbers\Status;

defined( 'ABSPATH' ) || exit;

/**
 * Pro: pure parsing/validation of CSV import rows — no file handling and no
 * DB writes, so Controller's preview and commit steps can both run the exact
 * same resolution logic against the exact same row data.
 */
final class RowParser {

	/**
	 * The only header row Import accepts, in order — lowercase, trimmed.
	 * Controller rejects any upload whose first line doesn't match this
	 * exactly, rather than guessing at column order.
	 */
	const EXPECTED_HEADERS = array( 'serial_number', 'status', 'product_sku', 'product_id', 'expires_at' );

	/**
	 * @param array[] $data_rows Each an fgetcsv() row (no header), aligned to
	 *                           EXPECTED_HEADERS column order.
	 * @return array[] One result per input row, in the same order.
	 */
	public static function parse_rows( array $data_rows ): array {
		$results      = array();
		$seen_in_file = array();

		foreach ( $data_rows as $index => $row ) {
			$results[] = self::parse_row( $row, $index + 2, $seen_in_file );
		}

		return $results;
	}

	/**
	 * @param array $row          fgetcsv() row.
	 * @param int   $line_number  1-based file line (data starts at line 2).
	 * @param array $seen_in_file Serial numbers (lowercased) already seen in
	 *                            this file, keyed by value — passed by
	 *                            reference so duplicates within the same
	 *                            upload are flagged from the second one on.
	 */
	private static function parse_row( array $row, int $line_number, array &$seen_in_file ): array {
		$serial_number  = isset( $row[0] ) ? sanitize_text_field( trim( (string) $row[0] ) ) : '';
		$status_raw     = isset( $row[1] ) ? trim( (string) $row[1] ) : '';
		$sku_raw        = isset( $row[2] ) ? trim( (string) $row[2] ) : '';
		$product_id_raw = isset( $row[3] ) ? trim( (string) $row[3] ) : '';
		$expires_raw    = isset( $row[4] ) ? trim( (string) $row[4] ) : '';

		$errors   = array();
		$warnings = array();

		if ( '' === $serial_number ) {
			$errors[] = __( 'Serial number is required.', 'serial-number-for-woocommerce' );
		} else {
			$key = strtolower( $serial_number );

			if ( isset( $seen_in_file[ $key ] ) ) {
				$errors[] = __( 'Duplicate serial number within this file.', 'serial-number-for-woocommerce' );
			} elseif ( Repository::exists( $serial_number ) ) {
				$errors[] = __( 'This serial number already exists.', 'serial-number-for-woocommerce' );
			}

			$seen_in_file[ $key ] = true;
		}

		$status = self::resolve_status( $status_raw );
		if ( $status['warning'] ) {
			$warnings[] = $status['warning'];
		}

		$product = self::resolve_product( $product_id_raw, $sku_raw );
		if ( $product['error'] ) {
			$errors[] = $product['error'];
		}

		$expiry = self::resolve_expiry( $expires_raw );
		if ( $expiry['warning'] ) {
			$warnings[] = $expiry['warning'];
		}

		return array(
			'line'          => $line_number,
			'serial_number' => $serial_number,
			'status'        => $status['status'],
			'status_label'  => Status::label( $status['status'] ),
			'product_id'    => $product['product_id'],
			'product_label' => $product['label'],
			'expires_at'    => $expiry['expires_at'],
			'expires_label' => $expiry['label'],
			'errors'        => $errors,
			'warnings'      => $warnings,
		);
	}

	/**
	 * Blank -> the configured default status. Otherwise matched case
	 * insensitively against Status::all(); an unrecognized value also falls
	 * back to the configured default, flagged as a warning rather than an
	 * error since the row is still importable.
	 */
	private static function resolve_status( string $raw ): array {
		if ( '' === $raw ) {
			return array(
				'status'  => Status::configured_default(),
				'warning' => null,
			);
		}

		foreach ( Status::all() as $key => $label ) {
			if ( 0 === strcasecmp( $key, $raw ) ) {
				return array(
					'status'  => $key,
					'warning' => null,
				);
			}
		}

		$default = Status::configured_default();

		return array(
			'status'  => $default,
			'warning' => sprintf(
				/* translators: 1: the unrecognized status value from the file, 2: the default status it was replaced with */
				__( 'Unrecognized status "%1$s", defaulted to %2$s.', 'serial-number-for-woocommerce' ),
				$raw,
				Status::label( $default )
			),
		);
	}

	/**
	 * product_id is checked first and, when present, acts as an override —
	 * SKU is otherwise the primary lookup. Both blank means no product for
	 * this row; a given-but-unresolvable value is an error, not a fallback
	 * to "no product", since that would silently drop the row's intended
	 * product assignment.
	 */
	private static function resolve_product( string $product_id_raw, string $sku_raw ): array {
		if ( '' !== $product_id_raw ) {
			$product_id = absint( $product_id_raw );
			$product    = $product_id ? wc_get_product( $product_id ) : false;

			if ( $product ) {
				return array(
					'product_id' => $product_id,
					'label'      => $product->get_name() . ' (#' . $product_id . ')',
					'error'      => null,
				);
			}

			return array(
				'product_id' => null,
				'label'      => '&mdash;',
				'error'      => sprintf(
					/* translators: %s: the product ID value from the file */
					__( 'Product ID "%s" not found.', 'serial-number-for-woocommerce' ),
					$product_id_raw
				),
			);
		}

		if ( '' !== $sku_raw ) {
			$product_id = wc_get_product_id_by_sku( $sku_raw );

			if ( $product_id ) {
				$product = wc_get_product( $product_id );

				return array(
					'product_id' => $product_id,
					'label'      => ( $product ? $product->get_name() : '' ) . ' (#' . $product_id . ')',
					'error'      => null,
				);
			}

			return array(
				'product_id' => null,
				'label'      => '&mdash;',
				'error'      => sprintf(
					/* translators: %s: the SKU value from the file */
					__( 'SKU "%s" not found.', 'serial-number-for-woocommerce' ),
					$sku_raw
				),
			);
		}

		return array(
			'product_id' => null,
			'label'      => '&mdash;',
			'error'      => null,
		);
	}

	/**
	 * Expects dd/mm/yyyy. Blank means no expiry (no warning). An unparseable
	 * or calendar-invalid value is treated as no expiry too, but flagged as a
	 * warning so it surfaces in the preview rather than silently changing
	 * what the user typed.
	 */
	private static function resolve_expiry( string $raw ): array {
		if ( '' === $raw ) {
			return array(
				'expires_at' => null,
				'label'      => '&mdash;',
				'warning'    => null,
			);
		}

		if ( preg_match( '#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $raw, $matches ) ) {
			$day   = (int) $matches[1];
			$month = (int) $matches[2];
			$year  = (int) $matches[3];

			if ( checkdate( $month, $day, $year ) ) {
				return array(
					'expires_at' => sprintf( '%04d-%02d-%02d 00:00:00', $year, $month, $day ),
					'label'      => sprintf( '%04d-%02d-%02d', $year, $month, $day ),
					'warning'    => null,
				);
			}
		}

		return array(
			'expires_at' => null,
			'label'      => __( 'No expiry (unparseable date)', 'serial-number-for-woocommerce' ),
			'warning'    => sprintf(
				/* translators: %s: the date value from the file */
				__( 'Unparseable date "%s" (expected dd/mm/yyyy), treated as no expiry.', 'serial-number-for-woocommerce' ),
				$raw
			),
		);
	}
}
