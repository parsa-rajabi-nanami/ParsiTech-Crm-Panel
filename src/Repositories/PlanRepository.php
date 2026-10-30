<?php

declare(strict_types=1);

namespace ParsiShop\CRM\Repositories;

use ParsiShop\CRM\Support\DateHelper;
use ParsiShop\CRM\Admin\SettingsPage;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class PlanRepository {
	private static function table(): string {
		global $wpdb;
		return $wpdb->prefix . 'parsishop_crm_plans';
	}

	public static function get_all_plans(): array {
		global $wpdb;
		$table = self::table();
		$rows  = $wpdb->get_results( "SELECT * FROM {$table} ORDER BY id DESC LIMIT 200" );
		return is_array( $rows ) ? $rows : array();
	}

	public static function get_active_plans( string $status_trigger ): array {
		global $wpdb;
		$table = self::table();
		$sql   = $wpdb->prepare(
			"SELECT * FROM {$table} WHERE status = 'active' AND trigger_statuses LIKE %s",
			'%' . $wpdb->esc_like( '"' . $status_trigger . '"' ) . '%'
		);
		$rows = $wpdb->get_results( $sql );
		return is_array( $rows ) ? $rows : array();
	}

	public static function find( int $id ): ?object {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . self::table() . ' WHERE id = %d', $id ) );
		return is_object( $row ) ? $row : null;
	}

	/** @param array<string, mixed> $data */
	public static function insert( array $data ): int {
		global $wpdb;
		$result = $wpdb->insert( self::table(), $data );
		return false === $result ? 0 : (int) $wpdb->insert_id;
	}

	public static function set_status( int $id, string $status ): bool {
		global $wpdb;
		if ( ! in_array( $status, array( 'active', 'paused' ), true ) ) {
			return false;
		}
		$result = $wpdb->update(
			self::table(),
			array( 'status' => $status, 'updated_at' => DateHelper::now_utc() ),
			array( 'id' => $id ),
			array( '%s', '%s' ),
			array( '%d' )
		);
		if ( false === $result ) {
			return false;
		}
		if ( 'paused' === $status ) {
			JobRepository::pause_by_plan( $id );
		} elseif ( ! empty( SettingsPage::get_settings()['enabled'] ) ) {
			JobRepository::resume_paused_jobs( $id );
		}
		return true;
	}

	public static function delete( int $id ): bool {
		global $wpdb;
		$jobs_table = $wpdb->prefix . 'parsishop_crm_jobs';
		$jobs = $wpdb->get_results( $wpdb->prepare( "SELECT id FROM {$jobs_table} WHERE plan_id = %d", $id ) );
		if ( is_array( $jobs ) ) {
			foreach ( $jobs as $job ) {
				$args = array( 'job_id' => (int) $job->id );
				if ( function_exists( 'as_unschedule_all_actions' ) ) {
					as_unschedule_all_actions( 'parsishop_crm_execute_sms_job', $args, 'parsishop-crm' );
				} else {
					wp_clear_scheduled_hook( 'parsishop_crm_execute_sms_job', $args );
				}
			}
		}
		$wpdb->delete( $jobs_table, array( 'plan_id' => $id ), array( '%d' ) );
		return false !== $wpdb->delete( self::table(), array( 'id' => $id ), array( '%d' ) );
	}
}
