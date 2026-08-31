<?php
/** Core Blueprint Backups uninstall cleanup. */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

global $wpdb;
wp_clear_scheduled_hook( 'cb_backups_scheduler_tick' );
wp_clear_scheduled_hook( 'cb_backups_run_job' );
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}cb_backup_jobs" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

delete_option( 'cb_backups_db_version' );
delete_option( 'cb_backups_schedules' );

// Deliberately preserve cb_backups_storage_token and the backup archives.
// Uninstalling a plugin must never silently destroy the user's restore points.
