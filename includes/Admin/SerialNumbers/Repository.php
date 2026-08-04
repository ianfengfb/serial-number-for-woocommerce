<?php
namespace SerialNumberForWooCommerce\Admin\SerialNumbers;

defined( 'ABSPATH' ) || exit;

/**
 * Data access for the snw_serial_numbers table.
 */
final class Repository {

	/**
	 * How many times claim_available() re-reads the pool after losing a row to
	 * a concurrent claim before giving up and letting the caller generate one.
	 */
	const CLAIM_ATTEMPTS = 5;

	public static function table_name(): string {
		global $wpdb;

		return $wpdb->prefix . 'snw_serial_numbers';
	}

	public static function exists( string $serial_number, int $exclude_id = 0 ): bool {
		global $wpdb;

		$table = self::table_name();

		if ( $exclude_id > 0 ) {
			$count = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM {$table} WHERE serial_number = %s AND id != %d",
					$serial_number,
					$exclude_id
				)
			);
		} else {
			$count = $wpdb->get_var(
				$wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE serial_number = %s", $serial_number )
			);
		}

		return $count > 0;
	}

	public static function find( int $id ): ?object {
		global $wpdb;

		$table = self::table_name();

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $id ) );

		return $row ?: null;
	}

	/**
	 * Looks up a row by its exact serial number string — used by the order
	 * edit screen's "Add Serial Number" control to decide whether a typed
	 * value is brand new or an existing row to validate/reuse.
	 */
	public static function find_by_serial_number( string $serial_number ): ?object {
		global $wpdb;

		$table = self::table_name();

		$row = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$table} WHERE serial_number = %s", $serial_number ) );

		return $row ?: null;
	}

	public static function update( int $id, array $data ): void {
		global $wpdb;

		$wpdb->update(
			self::table_name(),
			array(
				'serial_number' => $data['serial_number'],
				'status'        => $data['status'],
				'product_id'    => ! empty( $data['product_id'] ) ? absint( $data['product_id'] ) : null,
				'order_id'      => ! empty( $data['order_id'] ) ? absint( $data['order_id'] ) : null,
				'expires_at'    => ! empty( $data['expires_at'] ) ? $data['expires_at'] : null,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%d', '%d', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Returns the new row's ID, or 0 when the insert failed — e.g. because the
	 * serial number collided with the unique key. `$wpdb->insert_id` keeps its
	 * previous value on a failed query, so it is only trustworthy after a
	 * successful one.
	 */
	public static function insert( array $data ): int {
		global $wpdb;

		$inserted = $wpdb->insert(
			self::table_name(),
			array(
				'serial_number' => $data['serial_number'],
				'status'        => $data['status'],
				'product_id'    => ! empty( $data['product_id'] ) ? absint( $data['product_id'] ) : null,
				'order_id'      => ! empty( $data['order_id'] ) ? absint( $data['order_id'] ) : null,
				'created_at'    => current_time( 'mysql' ),
				'expires_at'    => ! empty( $data['expires_at'] ) ? $data['expires_at'] : null,
			),
			array( '%s', '%s', '%d', '%d', '%s', '%s' )
		);

		return $inserted ? (int) $wpdb->insert_id : 0;
	}

	/**
	 * Takes one available serial number out of the pool for a product and marks
	 * it Assigned to the given order, returning its ID (0 when the pool is empty).
	 *
	 * The claim is a compare-and-swap: the UPDATE only matches while the row is
	 * still Available, so two simultaneous checkouts can never be handed the same
	 * serial — the loser sees 0 affected rows and reads the next candidate.
	 * Rows already past their expiry date are skipped rather than handed out.
	 */
	public static function claim_available( int $product_id, int $order_id ): int {
		global $wpdb;

		$table = self::table_name();
		$now   = current_time( 'mysql' );

		for ( $attempt = 0; $attempt < self::CLAIM_ATTEMPTS; $attempt++ ) {
			$id = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT id FROM {$table}
					WHERE status = %s
						AND product_id = %d
						AND ( order_id IS NULL OR order_id = 0 )
						AND ( expires_at IS NULL OR expires_at > %s )
					ORDER BY id ASC
					LIMIT 1",
					Status::AVAILABLE,
					$product_id,
					$now
				)
			);

			if ( ! $id ) {
				return 0;
			}

			$claimed = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$table} SET status = %s, order_id = %d WHERE id = %d AND status = %s",
					Status::ASSIGNED,
					$order_id,
					$id,
					Status::AVAILABLE
				)
			);

			if ( $claimed ) {
				return $id;
			}
		}

		return 0;
	}

	/**
	 * Returns a serial to the pool: Available, order_id cleared. The
	 * inverse of claim_available()/a fresh Assigned insert — used when an
	 * order carrying the serial is cancelled or refunded and the serial
	 * was never put to use (see Orders\RefundHandler, which only ever
	 * calls this for a row still in Assigned status). Leaves product_id,
	 * expires_at, and activated_at untouched.
	 */
	public static function release( int $id ): void {
		global $wpdb;

		$wpdb->update(
			self::table_name(),
			array(
				'status'   => Status::AVAILABLE,
				'order_id' => null,
			),
			array( 'id' => $id ),
			array( '%s', '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Counts a product's Available, unexpired serial numbers — the number
	 * StockSync mirrors onto WooCommerce stock when that's switched on.
	 */
	public static function count_available( int $product_id ): int {
		global $wpdb;

		$table = self::table_name();
		$now   = current_time( 'mysql' );

		return (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$table}
				WHERE status = %s
					AND product_id = %d
					AND ( expires_at IS NULL OR expires_at > %s )",
				Status::AVAILABLE,
				$product_id,
				$now
			)
		);
	}

	/**
	 * Creates one row per non-empty line of $raw_text, all tied to
	 * $product_id — used by both the Serial Number tab's "Add to Pool" AJAX
	 * button and its save-time fallback for whatever's left in the textarea,
	 * so pasted serials are never lost whether or not that button is used.
	 *
	 * Duplicates (against existing rows, or repeated within the same paste)
	 * are skipped rather than erroring, so calling this twice on the same
	 * input — e.g. AJAX already created some, then the product is saved with
	 * those same lines still in the field — is always safe.
	 *
	 * @return array{created: int, skipped: string[]}
	 */
	public static function import_for_product( int $product_id, string $raw_text ): array {
		$lines   = preg_split( '/\r\n|\r|\n/', $raw_text );
		$seen    = array();
		$created = 0;
		$skipped = array();

		foreach ( $lines as $line ) {
			$serial_number = sanitize_text_field( trim( (string) $line ) );

			if ( '' === $serial_number ) {
				continue;
			}

			if ( isset( $seen[ $serial_number ] ) || self::exists( $serial_number ) ) {
				$skipped[] = $serial_number;
				continue;
			}

			$seen[ $serial_number ] = true;

			$inserted = self::insert(
				array(
					'serial_number' => $serial_number,
					'status'        => Status::configured_default(),
					'product_id'    => $product_id,
					'order_id'      => 0,
					'expires_at'    => '',
				)
			);

			if ( $inserted ) {
				++$created;
			} else {
				$skipped[] = $serial_number;
			}
		}

		return array(
			'created' => $created,
			'skipped' => $skipped,
		);
	}

	/**
	 * Builds the shared WHERE clause + params for $search/$filters, used by
	 * both search() (paginated, for the list table) and search_all()
	 * (unpaginated, for CSV export) so the two never drift apart.
	 *
	 * @param array $filters Optional: 'product_id' (int) filters to that
	 *                       product; 'no_product' (truthy) filters to rows
	 *                       with no product at all and wins over 'product_id'
	 *                       if both are given.
	 */
	private static function build_where( string $search, array $filters ): array {
		global $wpdb;

		$where  = array();
		$params = array();

		if ( '' !== $search ) {
			$where[]  = 'serial_number LIKE %s';
			$params[] = '%' . $wpdb->esc_like( $search ) . '%';
		}

		if ( ! empty( $filters['no_product'] ) ) {
			$where[] = 'product_id IS NULL';
		} elseif ( ! empty( $filters['product_id'] ) ) {
			$where[]  = 'product_id = %d';
			$params[] = (int) $filters['product_id'];
		}

		return array(
			'sql'    => $where ? ( 'WHERE ' . implode( ' AND ', $where ) ) : '',
			'params' => $params,
		);
	}

	public static function search( string $search, int $per_page, int $page, array $filters = array() ): array {
		global $wpdb;

		$table  = self::table_name();
		$offset = ( max( 1, $page ) - 1 ) * $per_page;
		$built  = self::build_where( $search, $filters );

		$items = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} {$built['sql']} ORDER BY id DESC LIMIT %d OFFSET %d",
				array_merge( $built['params'], array( $per_page, $offset ) )
			)
		);

		$total_sql = "SELECT COUNT(*) FROM {$table} {$built['sql']}";
		$total     = $built['params']
			? (int) $wpdb->get_var( $wpdb->prepare( $total_sql, $built['params'] ) )
			: (int) $wpdb->get_var( $total_sql );

		return array(
			'items' => $items,
			'total' => $total,
		);
	}

	/**
	 * All rows matching $search/$filters, no pagination — backs CSV export.
	 */
	public static function search_all( string $search, array $filters = array() ): array {
		global $wpdb;

		$table = self::table_name();
		$built = self::build_where( $search, $filters );

		$sql = "SELECT * FROM {$table} {$built['sql']} ORDER BY id DESC";

		return $built['params']
			? $wpdb->get_results( $wpdb->prepare( $sql, $built['params'] ) )
			: $wpdb->get_results( $sql );
	}

	/**
	 * Soft-deletes a serial number by setting its status to Deleted, kept for
	 * audit/recoverability rather than actually removing the row. Needs no
	 * special handling elsewhere: Available-counting queries already filter
	 * for `status = 'available'` specifically, so a deleted row is naturally
	 * excluded, exactly like Unavailable is today.
	 */
	public static function mark_deleted( int $id ): void {
		global $wpdb;

		$wpdb->update(
			self::table_name(),
			array( 'status' => Status::DELETED ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Marks a serial number Revoked — a warranty/license that had already
	 * activated, deliberately withdrawn because its order was later
	 * cancelled or refunded (see each Pro feature's own
	 * revoke_serial()/CancellationHandler). Leaves activated_at/expires_at
	 * alone, same "only flip the terminal status" shape as expire().
	 */
	public static function mark_revoked( int $id ): void {
		global $wpdb;

		$wpdb->update(
			self::table_name(),
			array( 'status' => Status::REVOKED ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Marks a serial number Activated (warranty/license starting now), with
	 * the given expiry — a generic "set these three columns" primitive,
	 * agnostic of how $expires_at was computed, so it's reusable by whichever
	 * activation trigger calls it (order-completed, delayed, a future manual
	 * customer flow, or a lifetime license that never expires).
	 *
	 * @param string|null $expires_at MySQL datetime string, or null for a
	 *                                license/warranty that never expires.
	 */
	public static function activate( int $id, ?string $expires_at ): void {
		global $wpdb;

		$wpdb->update(
			self::table_name(),
			array(
				'status'       => Status::ACTIVATED,
				'activated_at' => current_time( 'mysql' ),
				'expires_at'   => $expires_at,
			),
			array( 'id' => $id ),
			array( '%s', '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Extends a license's validity — Activated (flipping it back from
	 * Expired if that's what it was) with a new expires_at, leaving
	 * activated_at untouched since a renewal isn't a fresh activation, just
	 * a continuation of the one that already happened.
	 */
	public static function renew( int $id, string $expires_at ): void {
		global $wpdb;

		$wpdb->update(
			self::table_name(),
			array(
				'status'     => Status::ACTIVATED,
				'expires_at' => $expires_at,
			),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Marks a serial number Expired. Leaves activated_at/expires_at alone —
	 * this only flips the terminal status, backing the warranty-expiry cron.
	 */
	public static function expire( int $id ): void {
		global $wpdb;

		$wpdb->update(
			self::table_name(),
			array( 'status' => Status::EXPIRED ),
			array( 'id' => $id ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Activated serial numbers whose expiry has passed — candidates for the
	 * warranty-expiry cron to flip to Expired.
	 */
	public static function find_activated_past_expiry(): array {
		global $wpdb;

		$table = self::table_name();

		return $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$table} WHERE status = %s AND expires_at IS NOT NULL AND expires_at <= %s",
				Status::ACTIVATED,
				current_time( 'mysql' )
			)
		);
	}
}
