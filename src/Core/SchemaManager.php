<?php

declare(strict_types=1);

namespace ParsiReach\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SchemaManager {
	public const DB_VERSION_OPTION = 'parsireach_db_version';

	public static function maybe_upgrade(): void {
		$installed_version = get_option( self::DB_VERSION_OPTION, '0.0.0' );
		if ( version_compare( (string) $installed_version, PARSIREACH_DB_VERSION, '<' ) ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix . 'parsireach_';

		$sql = "CREATE TABLE {$prefix}plans (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			trigger_status VARCHAR(64) NOT NULL,
			initial_delay_val INT UNSIGNED NOT NULL DEFAULT 0,
			initial_delay_unit VARCHAR(15) NOT NULL DEFAULT 'days',
			repeat_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
			repeat_interval_val INT UNSIGNED NOT NULL DEFAULT 0,
			repeat_interval_unit VARCHAR(15) NOT NULL DEFAULT 'days',
			pattern_code VARCHAR(100) NOT NULL,
			pattern_values LONGTEXT NOT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_status_trigger (status, trigger_status)
		) $charset_collate;

		CREATE TABLE {$prefix}jobs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			plan_id BIGINT UNSIGNED NOT NULL,
			step_number TINYINT UNSIGNED NOT NULL DEFAULT 1,
			phone_hash CHAR(64) NOT NULL,
			scheduled_at DATETIME NOT NULL,
			status VARCHAR(25) NOT NULL DEFAULT 'pending',
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			last_error VARCHAR(255) DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_order_plan_step (order_id, plan_id, step_number),
			KEY idx_status_scheduled (status, scheduled_at),
			KEY idx_order_status (order_id, status),
			KEY idx_plan_status (plan_id, status)
		) $charset_collate;

		CREATE TABLE {$prefix}logs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			job_id BIGINT UNSIGNED DEFAULT NULL,
			plan_id BIGINT UNSIGNED DEFAULT NULL,
			order_id BIGINT UNSIGNED DEFAULT NULL,
			phone_masked VARCHAR(20) NOT NULL,
			phone_hash CHAR(64) NOT NULL,
			provider VARCHAR(30) NOT NULL DEFAULT 'farazsms',
			status VARCHAR(20) NOT NULL,
			message_id VARCHAR(100) DEFAULT NULL,
			error_code VARCHAR(50) DEFAULT NULL,
			api_response_summary VARCHAR(255) DEFAULT NULL,
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_created_at (created_at),
			KEY idx_status_created (status, created_at),
			KEY idx_plan_created (plan_id, created_at),
			KEY idx_phone_hash (phone_hash),
			KEY idx_job_id (job_id)
		) $charset_collate;";

		dbDelta( $sql );
		update_option( self::DB_VERSION_OPTION, PARSIREACH_DB_VERSION, false );
	}
}
