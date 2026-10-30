<?php
/**
 * ParsiReach uninstall handler.
 * Historical tables, consent/suppression records and the stable hash secret are
 * intentionally retained to avoid destructive data loss on accidental removal.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

delete_option( 'parsishop_crm_settings' );
delete_option( 'parsishop_crm_db_version' );
delete_option( 'parsishop_crm_low_credit_flag' );
delete_transient( 'parsishop_crm_cached_credit_irr' );

if ( function_exists( 'as_unschedule_all_actions' ) ) {
    // null means all argument combinations for this hook.
    as_unschedule_all_actions( 'parsishop_crm_execute_sms_job', null, 'parsishop-crm' );
    as_unschedule_all_actions( 'parsishop_crm_daily_maintenance', null, 'parsishop-crm' );
} else {
    // Remove individual WP-Cron events, including events with job_id arguments.
    $cron = _get_cron_array();
    if ( is_array( $cron ) ) {
        foreach ( $cron as $timestamp => $hooks ) {
            if ( empty( $hooks['parsishop_crm_execute_sms_job'] ) || ! is_array( $hooks['parsishop_crm_execute_sms_job'] ) ) {
                continue;
            }
            foreach ( $hooks['parsishop_crm_execute_sms_job'] as $event ) {
                wp_unschedule_event( (int) $timestamp, 'parsishop_crm_execute_sms_job', $event['args'] ?? array() );
            }
        }
    }
    wp_clear_scheduled_hook( 'parsishop_crm_daily_maintenance' );
}
