<?php
declare(strict_types=1);

namespace CB\Backups\Support;

use CB\Backups\Schedule\Scheduler;
use CB\Backups\Storage\LocalStorage;

defined( 'ABSPATH' ) || exit;

final class SiteHealth {
	public static function boot(): void {
		add_filter( 'site_status_tests', [ self::class, 'register_tests' ] );
	}

	/** @param array<string,mixed> $tests @return array<string,mixed> */
	public static function register_tests( array $tests ): array {
		$tests['direct']['cb_backups_storage'] = [
			'label' => __( 'Core Blueprint backup storage', 'core-blueprint-backups' ),
			'test'  => [ self::class, 'storage_test' ],
		];
		$tests['direct']['cb_backups_recent'] = [
			'label' => __( 'Core Blueprint recent backup', 'core-blueprint-backups' ),
			'test'  => [ self::class, 'recent_test' ],
		];
		$tests['direct']['cb_backups_scheduler'] = [
			'label' => __( 'Core Blueprint backup scheduler', 'core-blueprint-backups' ),
			'test'  => [ self::class, 'scheduler_test' ],
		];
		return $tests;
	}

	/** @return array<string,mixed> */
	public static function storage_test(): array {
		try {
			LocalStorage::ensure();
			$writable = wp_is_writable( LocalStorage::base_path() );
		} catch ( \Throwable ) {
			$writable = false;
		}
		return [
			'label'       => $writable ? __( 'Backup storage is writable', 'core-blueprint-backups' ) : __( 'Backup storage is not writable', 'core-blueprint-backups' ),
			'status'      => $writable ? 'good' : 'critical',
			'badge'       => [ 'label' => 'Core Blueprint', 'color' => 'blue' ],
			'description' => '<p>' . esc_html( $writable ? __( 'Core Blueprint can write backup archives to its configured local storage.', 'core-blueprint-backups' ) : __( 'Core Blueprint cannot write to the configured backup storage directory.', 'core-blueprint-backups' ) ) . '</p>',
			'actions'     => '',
			'test'        => 'cb_backups_storage',
		];
	}

	/** @return array<string,mixed> */
	public static function recent_test(): array {
		$backups = LocalStorage::list_backups();
		$last = $backups[0] ?? null;
		$timestamp = is_array( $last ) ? (int) ( $last['created_timestamp'] ?? $last['modified_at'] ?? 0 ) : 0;
		$age = $timestamp > 0 ? time() - $timestamp : PHP_INT_MAX;
		if ( PHP_INT_MAX === $age ) {
			$status = 'recommended';
			$label = __( 'No Core Blueprint backup exists yet', 'core-blueprint-backups' );
		} elseif ( $age > 14 * DAY_IN_SECONDS ) {
			$status = 'critical';
			$label = __( 'The latest Core Blueprint backup is older than 14 days', 'core-blueprint-backups' );
		} else {
			$status = 'good';
			$label = __( 'A recent Core Blueprint backup is available', 'core-blueprint-backups' );
		}
		if ( $timestamp > 0 ) {
			/* translators: %s: formatted date/time of the latest successful backup. */
			$description = sprintf( __( 'Latest successful backup: %s.', 'core-blueprint-backups' ), wp_date( 'Y-m-d H:i:s', $timestamp ) );
		} else {
			$description = __( 'Create a database or full website backup to establish the first restore point.', 'core-blueprint-backups' );
		}
		return [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [ 'label' => 'Core Blueprint', 'color' => 'blue' ],
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '',
			'test'        => 'cb_backups_recent',
		];
	}

	/** @return array<string,mixed> */
	public static function scheduler_test(): array {
		$health = Scheduler::health();
		$status_key = (string) ( $health['status'] ?? 'idle' );
		if ( 'idle' === $status_key ) {
			$status = 'good';
			$label = __( 'No automatic backup schedules are enabled', 'core-blueprint-backups' );
			$description = __( 'Manual backups remain available. Enable a schedule when automatic restore points are required.', 'core-blueprint-backups' );
		} elseif ( 'healthy' === $status_key ) {
			$status = 'good';
			$label = __( 'The Core Blueprint backup scheduler is healthy', 'core-blueprint-backups' );
			/* translators: %s: formatted date/time of the latest scheduler heartbeat. */
			$description = sprintf( __( 'Last scheduler heartbeat: %s.', 'core-blueprint-backups' ), wp_date( 'Y-m-d H:i:s', (int) $health['last_tick'] ) );
		} elseif ( 'warning' === $status_key ) {
			$status = 'recommended';
			$label = __( 'The Core Blueprint backup scheduler heartbeat is delayed', 'core-blueprint-backups' );
			$description = __( 'WP-Cron may not be receiving enough traffic. Configure a server cron to run the Core Blueprint backup scheduler reliably.', 'core-blueprint-backups' );
		} else {
			$status = 'critical';
			$label = __( 'The Core Blueprint backup scheduler is not running', 'core-blueprint-backups' );
			$description = __( 'Automatic schedules are enabled but no recent scheduler heartbeat was detected. Configure WP-Cron or a server cron before relying on automatic backups.', 'core-blueprint-backups' );
		}
		return [
			'label'       => $label,
			'status'      => $status,
			'badge'       => [ 'label' => 'Core Blueprint', 'color' => 'blue' ],
			'description' => '<p>' . esc_html( $description ) . '</p>',
			'actions'     => '',
			'test'        => 'cb_backups_scheduler',
		];
	}

}
