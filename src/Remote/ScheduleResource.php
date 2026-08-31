<?php
declare(strict_types=1);

namespace CB\Backups\Remote;

use CB\Backups\Schedule\Scheduler;
use WP_Error;

defined( 'ABSPATH' ) || exit;

final class ScheduleResource {
	/** @return array<string,mixed> */
	public static function current(): array {
		$all = Scheduler::all();
		return [
			'schema_version'    => Contract::SCHEMA_VERSION,
			'timezone'          => wp_timezone_string(),
			'database'          => self::schedule( is_array( $all['database'] ?? null ) ? $all['database'] : [] ),
			'website'           => self::schedule( is_array( $all['website'] ?? null ) ? $all['website'] : [] ),
			'scheduler_health'  => self::health( Scheduler::health() ),
		];
	}

	/** @return array<string,mixed> */
	public static function summary(): array {
		$all = Scheduler::all();
		return [
			'schema_version' => Contract::SCHEMA_VERSION,
			'timezone'       => wp_timezone_string(),
			'database'       => self::summary_schedule( is_array( $all['database'] ?? null ) ? $all['database'] : [] ),
			'website'        => self::summary_schedule( is_array( $all['website'] ?? null ) ? $all['website'] : [] ),
		];
	}

	/** @param array<string,mixed> $input @return array<string,array<string,mixed>>|WP_Error */
	public static function to_scheduler_input( array $input ): array|WP_Error {
		if ( 1 !== (int) ( $input['schema_version'] ?? 0 ) ) {
			return self::error( 'cb_backups_schema_version', 'Unsupported Backups remote schema version.', 400 );
		}
		$out = [];
		foreach ( [ 'database', 'website' ] as $type ) {
			$row = $input[ $type ] ?? null;
			if ( ! is_array( $row ) ) {
				return self::error( 'cb_backups_invalid_schedules', 'Both database and website schedules are required.', 400 );
			}
			$frequency = sanitize_key( (string) ( $row['frequency'] ?? '' ) );
			$time      = (string) ( $row['time'] ?? '' );
			$weekday   = (int) ( $row['weekday'] ?? 0 );
			$retention = (int) ( $row['retention'] ?? 0 );
			if ( ! array_key_exists( 'enabled', $row ) || ! is_bool( $row['enabled'] ) ) {
				return self::error( 'cb_backups_invalid_schedule_enabled', 'Schedule enabled must be a boolean.', 400 );
			}
			if ( ! in_array( $frequency, [ 'hourly', 'daily', 'weekly' ], true ) ) {
				return self::error( 'cb_backups_invalid_schedule_frequency', 'Schedule frequency is invalid.', 400 );
			}
			if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
				return self::error( 'cb_backups_invalid_schedule_time', 'Schedule time must use HH:MM in the managed site timezone.', 400 );
			}
			if ( $weekday < 1 || $weekday > 7 ) {
				return self::error( 'cb_backups_invalid_schedule_weekday', 'Schedule weekday must be an ISO weekday from 1 through 7.', 400 );
			}
			if ( $retention < 1 || $retention > 100 ) {
				return self::error( 'cb_backups_invalid_schedule_retention', 'Schedule retention must be between 1 and 100.', 400 );
			}
			$out[ $type ] = [
				'enabled'   => $row['enabled'],
				'frequency' => $frequency,
				'time'      => $time,
				'weekday'   => $weekday,
				'keep'      => $retention,
			];
		}
		return $out;
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private static function summary_schedule( array $row ): array {
		return [
			'enabled'   => ! empty( $row['enabled'] ),
			'frequency' => (string) ( $row['frequency'] ?? 'daily' ),
			'time'      => (string) ( $row['time'] ?? '02:00' ),
			'weekday'   => max( 1, min( 7, (int) ( $row['weekday'] ?? 1 ) ) ),
		];
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private static function schedule( array $row ): array {
		return [
			'enabled'         => ! empty( $row['enabled'] ),
			'frequency'       => (string) ( $row['frequency'] ?? 'daily' ),
			'time'            => (string) ( $row['time'] ?? '02:00' ),
			'weekday'         => max( 1, min( 7, (int) ( $row['weekday'] ?? 1 ) ) ),
			'retention'       => max( 1, min( 100, (int) ( $row['keep'] ?? 1 ) ) ),
			'next_run_at'     => self::timestamp( (int) ( $row['next_run'] ?? 0 ) ),
			'last_success_at' => self::timestamp( (int) ( $row['last_success'] ?? 0 ) ),
			'last_failure_at' => self::timestamp( (int) ( $row['last_failure'] ?? 0 ) ),
			'last_job_id'     => self::nullable_string( $row['last_job_id'] ?? null ),
		];
	}

	/** @param array<string,mixed> $health @return array<string,mixed> */
	private static function health( array $health ): array {
		return [
			'status'            => in_array( (string) ( $health['status'] ?? '' ), [ 'idle', 'healthy', 'warning', 'critical' ], true ) ? (string) $health['status'] : 'critical',
			'enabled_count'     => max( 0, (int) ( $health['enabled_count'] ?? 0 ) ),
			'last_tick_at'      => self::timestamp( (int) ( $health['last_tick'] ?? 0 ) ),
			'last_tick_source'  => self::nullable_string( $health['last_tick_source'] ?? null ),
			'heartbeat_age'     => isset( $health['heartbeat_age'] ) ? max( 0, (int) $health['heartbeat_age'] ) : null,
			'next_run_at'       => self::timestamp( (int) ( $health['next_run'] ?? 0 ) ),
			'last_success_at'   => self::timestamp( (int) ( $health['last_success'] ?? 0 ) ),
			'wp_cron_disabled'  => ! empty( $health['wp_cron_disabled'] ),
		];
	}

	private static function timestamp( int $timestamp ): ?string {
		return $timestamp > 0 ? gmdate( 'c', $timestamp ) : null;
	}

	private static function nullable_string( mixed $value ): ?string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return '' !== $value ? $value : null;
	}

	private static function error( string $code, string $message, int $status ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => $status, 'retryable' => false ] );
	}
}
