<?php

declare(strict_types=1);

namespace ParsiShop\CRM\Repositories;

use ParsiShop\CRM\Support\DateHelper;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class JobRepository {

	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'parsishop_crm_jobs';
	}

	public static function insert_idempotent(
		int $order_id,
		int $plan_id,
		int $step_number,
		string $phone_hash,
		string $scheduled_at_utc
	): int {
		global $wpdb;

		$table = self::table();
		$now   = DateHelper::now_utc();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->query(
			$wpdb->prepare(
				"INSERT IGNORE INTO {$table}
				(order_id, plan_id, step_number, customer_phone_hash, scheduled_at, status, attempts, created_at, updated_at)
				VALUES (%d, %d, %d, %s, %s, 'pending', 0, %s, %s)",
				$order_id,
				$plan_id,
				$step_number,
				$phone_hash,
				$scheduled_at_utc,
				$now,
				$now
			)
		);

		return ( 1 === $rows ) ? (int) $wpdb->insert_id : 0;
	}

	public static function acquire_lock( int $job_id ): bool {
		global $wpdb;

		$table = self::table();
		$now   = DateHelper::now_utc();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$updated = $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'processing', attempts = attempts + 1, updated_at = %s
				WHERE id = %d AND status IN ('pending', 'failed_retry')",
				$now,
				$job_id
			)
		);

		return 1 === $updated;
	}

	public static function find( int $job_id ): ?object {
		global $wpdb;
		$table = self::table();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", $job_id )
		);

		return is_object( $row ) ? $row : null;
	}

	/**
	 * @param array<string, mixed> $data
	 */
	public static function update( int $job_id, array $data ): bool {
		global $wpdb;

		$data['updated_at'] = DateHelper::now_utc();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$res = $wpdb->update(
			self::table(),
			$data,
			array( 'id' => $job_id )
		);

		return false !== $res;
	}

	public static function cancel_by_order( int $order_id, string $reason = 'لغو به دلیل تغییر وضعیت سفارش به لغوشده/مستردشده' ): int {
		global $wpdb;

		$table = self::table();
		$now   = DateHelper::now_utc();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$jobs = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT id FROM {$table} WHERE order_id = %d AND status IN ('pending', 'failed_retry', 'paused')",
				$order_id
			)
		);

		if ( empty( $jobs ) ) {
			return 0;
		}

		foreach ( $jobs as $job ) {
			$args = array( 'job_id' => (int) $job->id );
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( 'parsishop_crm_execute_sms_job', $args, 'parsishop-crm' );
			} else {
				wp_clear_scheduled_hook( 'parsishop_crm_execute_sms_job', $args );
			}
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table}
				SET status = 'cancelled', last_error = %s, updated_at = %s
				WHERE order_id = %d AND status IN ('pending', 'failed_retry', 'paused')",
				$reason,
				$now,
				$order_id
			)
		);
	}

	/** Pause pending jobs belonging to a paused automation. */
	public static function pause_by_plan( int $plan_id ): int {
		global $wpdb;
		$table = self::table();
		$jobs  = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$table} WHERE plan_id = %d AND status IN ('pending', 'failed_retry')", $plan_id ) );
		if ( empty( $jobs ) ) {
			return 0;
		}
		foreach ( $jobs as $job ) {
			$args = array( 'job_id' => (int) $job->id );
			if ( function_exists( 'as_unschedule_all_actions' ) ) {
				as_unschedule_all_actions( 'parsishop_crm_execute_sms_job', $args, 'parsishop-crm' );
			} else {
				wp_clear_scheduled_hook( 'parsishop_crm_execute_sms_job', $args );
			}
		}
		return (int) $wpdb->query(
			$wpdb->prepare(
				"UPDATE {$table} SET status = 'paused', last_error = %s, updated_at = %s WHERE plan_id = %d AND status IN ('pending', 'failed_retry')",
				'اتوماسیون توسط مدیر متوقف شده است.',
				DateHelper::now_utc(),
				$plan_id
			)
		);
	}

	/**
	 * Resume queued jobs without reactivating jobs paused for a different reason.
	 * With no plan ID, only jobs paused because the global service was disabled are resumed.
	 */
	public static function resume_paused_jobs( ?int $plan_id = null ): int {
		global $wpdb;
		$table   = self::table();
		$global_pause_reason = 'ارسال خودکار در تنظیمات غیرفعال است.';
		$plan_pause_reason   = 'اتوماسیون توسط مدیر متوقف شده است.';
		$last_id             = 0;
		$resumed             = 0;

		// With no plan ID, resume globally-paused jobs and plan-paused jobs only
		// when their plan is active. This prevents a global toggle from reviving
		// automations that the administrator intentionally left paused.
		while ( true ) {
			if ( null === $plan_id ) {
				$sql = "SELECT j.id, j.scheduled_at, j.last_error FROM {$table} j
					LEFT JOIN {$wpdb->prefix}parsishop_crm_plans p ON p.id = j.plan_id
					WHERE j.status = 'paused' AND j.id > %d
					AND (j.last_error = %s OR (j.last_error = %s AND p.status = 'active'))";
				$args = array( $last_id, $global_pause_reason, $plan_pause_reason );
			} else {
				$sql  = "SELECT id, scheduled_at, last_error FROM {$table} WHERE status = 'paused' AND last_error = %s AND id > %d AND plan_id = %d";
				$args = array( $plan_pause_reason, $last_id, $plan_id );
			}
			$sql .= null === $plan_id ? ' ORDER BY j.id ASC LIMIT 500' : ' ORDER BY id ASC LIMIT 500';
			$jobs = $wpdb->get_results( $wpdb->prepare( $sql, ...$args ) );
			if ( empty( $jobs ) ) {
				break;
			}

			foreach ( $jobs as $job ) {
				$job_id = (int) $job->id;
				$last_id = max( $last_id, $job_id );
				$when = strtotime( (string) $job->scheduled_at . ' UTC' );
				$when = max( time() + 15, false === $when ? time() + 15 : $when );
				$args_for_action = array( 'job_id' => $job_id );

				$row_reason = (string) ( $job->last_error ?? '' );
				$updated = $wpdb->update(
					$table,
					array( 'status' => 'pending', 'last_error' => null, 'updated_at' => DateHelper::now_utc() ),
					array( 'id' => $job_id, 'status' => 'paused', 'last_error' => $row_reason )
				);
				if ( 1 !== $updated ) {
					continue;
				}

				if ( function_exists( 'as_schedule_single_action' ) ) {
					if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( 'parsishop_crm_execute_sms_job', $args_for_action, 'parsishop-crm' ) ) {
						as_schedule_single_action( $when, 'parsishop_crm_execute_sms_job', $args_for_action, 'parsishop-crm' );
					}
				} elseif ( ! wp_next_scheduled( 'parsishop_crm_execute_sms_job', $args_for_action ) ) {
					wp_schedule_single_event( $when, 'parsishop_crm_execute_sms_job', $args_for_action );
				}
				++$resumed;
			}
		}

		return $resumed;
	}


	/** Rebuild missing scheduled actions after plugin reactivation or reinstall. */
	public static function reschedule_pending_jobs(): int {
		global $wpdb;
		$table   = self::table();
		$last_id = 0;
		$scheduled = 0;

		while ( true ) {
			$jobs = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT id, scheduled_at FROM {$table} WHERE status IN ('pending', 'failed_retry') AND id > %d ORDER BY id ASC LIMIT 500",
					$last_id
				)
			);
			if ( empty( $jobs ) ) {
				break;
			}

			foreach ( $jobs as $job ) {
				$job_id = (int) $job->id;
				$last_id = max( $last_id, $job_id );
				$when = strtotime( (string) $job->scheduled_at . ' UTC' );
				$when = max( time() + 15, false === $when ? time() + 15 : $when );
				$args = array( 'job_id' => $job_id );

				if ( function_exists( 'as_schedule_single_action' ) ) {
					if ( ! function_exists( 'as_has_scheduled_action' ) || ! as_has_scheduled_action( 'parsishop_crm_execute_sms_job', $args, 'parsishop-crm' ) ) {
						as_schedule_single_action( $when, 'parsishop_crm_execute_sms_job', $args, 'parsishop-crm' );
						++$scheduled;
					}
				} elseif ( ! wp_next_scheduled( 'parsishop_crm_execute_sms_job', $args ) ) {
					wp_schedule_single_event( $when, 'parsishop_crm_execute_sms_job', $args );
					++$scheduled;
				}
			}
		}

		return $scheduled;
	}

	public static function count_recent_sent_to_phone( string $phone_hash, int $days, string $legacy_hash = '' ): int {
		global $wpdb;

		if ( $days <= 0 || '' === $phone_hash ) {
			return 0;
		}

		$jobs_table = self::table();
		$logs_table = $wpdb->prefix . 'parsishop_crm_logs';
		$since      = gmdate( 'Y-m-d H:i:s', time() - ( $days * DAY_IN_SECONDS ) );

		// Only real provider-accepted sends count toward the cap; sandbox runs do not.
		if ( '' !== $legacy_hash && ! hash_equals( $phone_hash, $legacy_hash ) ) {
			$sql = "SELECT COUNT(j.id) FROM {$jobs_table} j
				WHERE j.customer_phone_hash IN (%s, %s)
				AND j.status = 'completed' AND j.updated_at >= %s
				AND EXISTS (SELECT 1 FROM {$logs_table} l WHERE l.job_id = j.id AND l.status = 'sent')";
			return (int) $wpdb->get_var( $wpdb->prepare( $sql, $phone_hash, $legacy_hash, $since ) );
		}

		$sql = "SELECT COUNT(j.id) FROM {$jobs_table} j
			WHERE j.customer_phone_hash = %s
			AND j.status = 'completed' AND j.updated_at >= %s
			AND EXISTS (SELECT 1 FROM {$logs_table} l WHERE l.job_id = j.id AND l.status = 'sent')";
		return (int) $wpdb->get_var( $wpdb->prepare( $sql, $phone_hash, $since ) );
	}

}
