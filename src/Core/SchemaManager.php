<?php

declare(strict_types=1);

namespace ParsiShop\CRM\Core;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class SchemaManager {

	public const DB_VERSION_OPTION = 'parsishop_crm_db_version';

	public static function maybe_upgrade(): void {
		$installed_version = get_option( self::DB_VERSION_OPTION, '0.0.0' );
		if ( version_compare( (string) $installed_version, PARSISHOP_CRM_DB_VERSION, '<' ) ) {
			self::install();
		}
	}

	public static function install(): void {
		global $wpdb;

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$prefix          = $wpdb->prefix . 'parsishop_crm_';

		$sql = "CREATE TABLE {$prefix}plans (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'active',
			trigger_statuses TEXT NOT NULL,
			delay_base VARCHAR(30) NOT NULL DEFAULT 'status_change',
			initial_delay_val INT UNSIGNED NOT NULL DEFAULT 1,
			initial_delay_unit VARCHAR(15) NOT NULL DEFAULT 'days',
			repeat_count TINYINT UNSIGNED NOT NULL DEFAULT 1,
			repeat_interval_val INT UNSIGNED NOT NULL DEFAULT 0,
			repeat_interval_unit VARCHAR(15) NOT NULL DEFAULT 'days',
			send_type VARCHAR(20) NOT NULL DEFAULT 'pattern',
			pattern_code VARCHAR(100) DEFAULT NULL,
			message_template TEXT NOT NULL,
			targeting_rules LONGTEXT NOT NULL,
			coupon_code VARCHAR(100) DEFAULT NULL,
			stop_rules LONGTEXT NOT NULL,
			frequency_cap_count TINYINT UNSIGNED NOT NULL DEFAULT 0,
			frequency_cap_days SMALLINT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_status (status)
		) $charset_collate;

		CREATE TABLE {$prefix}jobs (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			order_id BIGINT UNSIGNED NOT NULL,
			plan_id BIGINT UNSIGNED NOT NULL,
			step_number TINYINT UNSIGNED NOT NULL DEFAULT 1,
			customer_phone_hash CHAR(64) NOT NULL,
			scheduled_at DATETIME NOT NULL,
			status VARCHAR(25) NOT NULL DEFAULT 'pending',
			attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
			action_id BIGINT UNSIGNED DEFAULT NULL,
			last_error VARCHAR(255) DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_order_plan_step (order_id, plan_id, step_number),
			KEY idx_status_scheduled (status, scheduled_at),
			KEY idx_order_status (order_id, status),
			KEY idx_phone_hash_status (customer_phone_hash, status, updated_at),
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
		) $charset_collate;

		CREATE TABLE {$prefix}optouts (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			channel VARCHAR(10) NOT NULL,
			recipient_hash CHAR(64) NOT NULL,
			recipient_masked VARCHAR(191) NOT NULL,
			source VARCHAR(30) NOT NULL DEFAULT 'user_link',
			created_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_channel_recipient (channel, recipient_hash),
			KEY idx_created_at (created_at)
		) $charset_collate;

		CREATE TABLE {$prefix}marketing_consents (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			channel VARCHAR(10) NOT NULL,
			recipient_hash CHAR(64) NOT NULL,
			recipient_masked VARCHAR(191) NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'unsubscribed',
			source VARCHAR(30) NOT NULL DEFAULT 'checkout',
			consented_at DATETIME DEFAULT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_channel_recipient (channel, recipient_hash),
			KEY idx_status_channel (status, channel),
			KEY idx_updated_at (updated_at)
		) $charset_collate;

		CREATE TABLE {$prefix}email_campaigns (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			name VARCHAR(191) NOT NULL,
			subject VARCHAR(255) NOT NULL DEFAULT '',
			html_body LONGTEXT NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'draft',
			batch_size SMALLINT UNSIGNED NOT NULL DEFAULT 50,
			total_recipients INT UNSIGNED NOT NULL DEFAULT 0,
			sent_count INT UNSIGNED NOT NULL DEFAULT 0,
			failed_count INT UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			KEY idx_status (status)
		) $charset_collate;

		CREATE TABLE {$prefix}email_recipients (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			campaign_id BIGINT UNSIGNED NOT NULL,
			email VARCHAR(191) NOT NULL,
			customer_name VARCHAR(191) DEFAULT '',
			order_id BIGINT UNSIGNED DEFAULT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'pending',
			sent_at DATETIME DEFAULT NULL,
			error_message VARCHAR(255) DEFAULT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY uq_campaign_email (campaign_id, email),
			KEY idx_campaign_status (campaign_id, status)
		) $charset_collate;";

		dbDelta( $sql );

		update_option( self::DB_VERSION_OPTION, PARSISHOP_CRM_DB_VERSION, false );
	}
}
