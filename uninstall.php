<?php
/** Remove all ParsiReach data when the plugin is deleted. */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

/** @param string[] $tables */
$parsireach_cleanup_site = static function ( array $tables ): void {
	global $wpdb;

	foreach ( array( 'parsireach_settings', 'parsireach_db_version', 'parsireach_queue_rescheduled', 'parsireach_phone_hash_secret', 'parsishop_crm_settings', 'parsishop_crm_db_version', 'parsishop_crm_queue_rescheduled_2_0', 'parsishop_crm_low_credit_flag', 'parsireach_recipient_hash_secret' ) as $option ) {
		delete_option( $option );
	}
	delete_transient( 'parsireach_cached_credit_irr' );
	delete_transient( 'parsishop_crm_cached_credit_irr' );

	$hooks  = array( 'parsireach_execute_sms_job', 'parsireach_daily_maintenance', 'parsishop_crm_execute_sms_job', 'parsishop_crm_daily_maintenance' );
	$groups = array( 'parsireach', 'parsishop-crm' );
	if ( function_exists( 'as_unschedule_all_actions' ) ) {
		foreach ( $hooks as $hook ) {
			foreach ( $groups as $group ) {
				as_unschedule_all_actions( $hook, null, $group );
			}
		}
	}

	if ( function_exists( '_get_cron_array' ) ) {
		$cron = _get_cron_array();
		if ( is_array( $cron ) ) {
			foreach ( $cron as $timestamp => $events ) {
				foreach ( $hooks as $hook ) {
					foreach ( (array) ( $events[ $hook ] ?? array() ) as $event ) {
						wp_unschedule_event( (int) $timestamp, $hook, $event['args'] ?? array() );
					}
				}
			}
		}
	}

	$meta_keys = array( '_parsireach_marketing_consent', '_parsireach_marketing_consent_at', '_parsishop_crm_marketing_consent', '_parsishop_crm_marketing_consent_at' );
	foreach ( array( $wpdb->postmeta, $wpdb->prefix . 'wc_orders_meta' ) as $meta_table ) {
		$exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $meta_table ) ) );
		if ( $exists !== $meta_table ) {
			continue;
		}
		$placeholders = implode( ',', array_fill( 0, count( $meta_keys ), '%s' ) );
		$wpdb->query( $wpdb->prepare( "DELETE FROM `{$meta_table}` WHERE meta_key IN ({$placeholders})", ...$meta_keys ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}

	foreach ( $tables as $suffix ) {
		$table = $wpdb->prefix . $suffix;
		$wpdb->query( "DROP TABLE IF EXISTS `{$table}`" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
	}
};

$parsireach_tables = array(
	'parsireach_plans', 'parsireach_jobs', 'parsireach_logs',
	'parsishop_crm_plans', 'parsishop_crm_jobs', 'parsishop_crm_logs', 'parsishop_crm_optouts',
	'parsishop_crm_marketing_consents', 'parsishop_crm_email_campaigns', 'parsishop_crm_email_recipients',
);

if ( is_multisite() ) {
	$original_blog_id = get_current_blog_id();
	foreach ( get_sites( array( 'fields' => 'ids', 'number' => 0 ) ) as $blog_id ) {
		switch_to_blog( (int) $blog_id );
		$parsireach_cleanup_site( $parsireach_tables );
		restore_current_blog();
	}
	if ( get_current_blog_id() !== $original_blog_id ) {
		switch_to_blog( $original_blog_id );
	}
} else {
	$parsireach_cleanup_site( $parsireach_tables );
}
