<?php

declare(strict_types=1);

namespace ParsiReach\Repositories;

use ParsiReach\Providers\Contracts\SmsResult;
use ParsiReach\Support\DateHelper;
use ParsiReach\Support\PhoneNormalizer;
use ParsiReach\Support\PhoneHasher;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class LogRepository {

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'parsireach_logs';
	}

	public static function record(
		string $raw_phone,
		string $provider,
		SmsResult $result,
		?int $job_id = null,
		?int $plan_id = null,
		?int $order_id = null
	): int {
		global $wpdb;

		$wpdb->insert(
			self::table(),
			array(
				'job_id'               => $job_id,
				'plan_id'              => $plan_id,
				'order_id'             => $order_id,
				'phone_masked'         => PhoneNormalizer::mask( $raw_phone ),
				'phone_hash'           => PhoneHasher::hash( $raw_phone ),
				'provider'             => sanitize_key( $provider ),
				'status'               => sanitize_key( $result->status ),
				'message_id'           => $result->message_id ? sanitize_text_field( $result->message_id ) : null,
				'error_code'           => $result->error_code ? sanitize_text_field( $result->error_code ) : null,
				'api_response_summary' => sanitize_text_field( $result->summary ),
				'created_at'           => DateHelper::now_utc(),
			),
			array( '%d', '%d', '%d', '%s', '%s', '%s', '%s', '%s', '%s', '%s', '%s' )
		);

		return (int) $wpdb->insert_id;
	}

	public static function purge_older_than( int $days ): int {
		global $wpdb;

		if ( $days <= 0 ) {
			return 0;
		}

		$cutoff = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );
		$table  = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query(
			$wpdb->prepare(
				"DELETE FROM {$table} WHERE created_at < %s LIMIT 5000",
				$cutoff
			)
		);
	}

	/** @param int[] $ids */
	public static function delete_ids( array $ids ): int {
		global $wpdb;
		$ids = array_values( array_unique( array_filter( array_map( 'absint', $ids ) ) ) );
		if ( empty( $ids ) ) {
			return 0;
		}
		$placeholders = implode( ',', array_fill( 0, count( $ids ), '%d' ) );
		// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return (int) $wpdb->query( $wpdb->prepare( 'DELETE FROM ' . self::table() . " WHERE id IN ({$placeholders})", ...$ids ) );
	}

	public static function delete_all(): int {
		global $wpdb;
		return (int) $wpdb->query( 'DELETE FROM ' . self::table() );
	}

	/**
	 * @param array<string, mixed> $filters
	 * @return array{items: array<int, object>, total: int}
	 */
	public static function paginate( array $filters = array(), int $page = 1, int $per_page = 25 ): array {
		global $wpdb;

		$table  = self::table();
		$where  = array( '1=1' );
		$values = array();

		if ( ! empty( $filters['status'] ) ) {
			$where[]  = 'status = %s';
			$values[] = sanitize_key( (string) $filters['status'] );
		}

		if ( ! empty( $filters['plan_id'] ) ) {
			$where[]  = 'plan_id = %d';
			$values[] = (int) $filters['plan_id'];
		}

		if ( ! empty( $filters['phone'] ) ) {
			$phone_hash = PhoneHasher::hash( (string) $filters['phone'] );
			if ( '' !== $phone_hash ) {
				$where[]  = 'phone_hash = %s';
				$values[] = $phone_hash;
			} else {
				return array(
					'items' => array(),
					'total' => 0,
				);
			}
		}

		if ( ! empty( $filters['date_from'] ) ) {
			$where[]  = 'created_at >= %s';
			$values[] = sanitize_text_field( (string) $filters['date_from'] ) . ' 00:00:00';
		}

		if ( ! empty( $filters['date_to'] ) ) {
			$where[]  = 'created_at <= %s';
			$values[] = sanitize_text_field( (string) $filters['date_to'] ) . ' 23:59:59';
		}

		$where_sql = implode( ' AND ', $where );
		$offset    = max( 0, ( $page - 1 ) * $per_page );

		$count_sql = "SELECT COUNT(id) FROM {$table} WHERE {$where_sql}";
		$data_sql  = "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY id DESC LIMIT %d OFFSET %d";

		if ( ! empty( $values ) ) {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $wpdb->get_var( $wpdb->prepare( $count_sql, ...$values ) );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$items = $wpdb->get_results( $wpdb->prepare( $data_sql, ...array_merge( $values, array( $per_page, $offset ) ) ) );
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$total = (int) $wpdb->get_var( $count_sql );
			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$items = $wpdb->get_results( $wpdb->prepare( $data_sql, $per_page, $offset ) );
		}

		return array(
			'items' => is_array( $items ) ? $items : array(),
			'total' => $total,
		);
	}
}
