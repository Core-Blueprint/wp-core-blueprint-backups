<?php
declare(strict_types=1);

namespace CB\Backups\Schedule;

use CB\Backups\Backup\Service;
use CB\Backups\Jobs\Dispatcher;
use CB\Backups\Jobs\Repository;
use CB\Backups\Jobs\Runner;
use CB\Backups\Storage\LocalStorage;
use CB\Backups\Support\Audit;
use CB\Backups\Support\Housekeeping;
use DateTimeImmutable;
use Throwable;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Backup processing uses bounded native streams/atomic filesystem primitives; WP_Filesystem is not suitable for these server-owned jobs.

final class Scheduler {
	private const OPTION = 'cb_backups_schedules';
	private const STATE_OPTION = 'cb_backups_scheduler_state';
	private const CRON_HOOK = 'cb_backups_scheduler_tick';
	private const CRON_RECURRENCE = 'cb_backups_every_minute';
	private const DELAY_GRACE_SECONDS = 300;
	private const STALE_JOB_SECONDS = 300;
	private const RETRY_BASE_SECONDS = 900;

	public static function boot(): void {
		add_filter( 'cron_schedules', [ self::class, 'cron_schedules' ] );
		add_action( 'init', [ self::class, 'ensure_cron' ], 20 );
	}

	/** @param array<string,array<string,mixed>> $schedules @return array<string,array<string,mixed>> */
	public static function cron_schedules( array $schedules ): array {
		$schedules[ self::CRON_RECURRENCE ] = [ 'interval' => 60, 'display' => 'Core Blueprint Backups: every minute' ];
		return $schedules;
	}

	public static function ensure_cron(): void {
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, self::CRON_RECURRENCE, self::CRON_HOOK );
		}
	}

	public static function clear_cron(): void {
		wp_clear_scheduled_hook( self::CRON_HOOK );
	}

	/** @return array<string,array<string,mixed>> */
	public static function all(): array {
		$config = self::config();
		$state = self::state();
		$out = [];
		foreach ( [ 'database', 'website' ] as $type ) {
			$out[ $type ] = array_merge( $config[ $type ], $state[ $type ] );
		}
		return $out;
	}

	/** @return array<string,mixed> */
	public static function health(): array {
		$all = self::all();
		$state = self::state();
		$enabled = array_values( array_filter( $all, static fn ( array $row ): bool => ! empty( $row['enabled'] ) ) );
		$last_tick = (int) ( $state['_scheduler']['last_tick'] ?? 0 );
		$age = $last_tick > 0 ? max( 0, time() - $last_tick ) : null;
		$next_runs = array_values( array_filter( array_map( static fn ( array $row ): int => (int) ( $row['next_run'] ?? 0 ), $enabled ) ) );
		$last_successes = array_values( array_filter( array_map( static fn ( array $row ): int => (int) ( $row['last_success'] ?? 0 ), $enabled ) ) );

		$status = 'idle';
		if ( $enabled ) {
			if ( '' !== (string) ( $state['_scheduler']['last_error'] ?? '' ) ) {
				$status = 'critical';
			} elseif ( 0 === $last_tick || ( null !== $age && $age > HOUR_IN_SECONDS ) ) {
				$status = 'critical';
			} elseif ( null !== $age && $age > 10 * MINUTE_IN_SECONDS ) {
				$status = 'warning';
			} else {
				$status = 'healthy';
			}
		}

		return [
			'status'           => $status,
			'enabled_count'    => count( $enabled ),
			'last_tick'        => $last_tick,
			'last_tick_source' => (string) ( $state['_scheduler']['last_tick_source'] ?? '' ),
			'heartbeat_age'    => $age,
			'next_run'         => $next_runs ? min( $next_runs ) : 0,
			'last_success'     => $last_successes ? max( $last_successes ) : 0,
			'wp_cron_disabled' => defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON,
			'last_error'       => (string) ( $state['_scheduler']['last_error'] ?? '' ),
		];
	}

	/** @param array<string,array<string,mixed>> $input */
	public static function save( array $input, string $source = 'admin', array $context = [] ): void {
		$current = self::config();
		$state = self::state();
		$out = [];
		foreach ( [ 'database', 'website' ] as $type ) {
			$row = isset( $input[ $type ] ) && is_array( $input[ $type ] ) ? $input[ $type ] : [];
			$frequency = sanitize_key( (string) ( $row['frequency'] ?? $current[ $type ]['frequency'] ) );
			if ( ! in_array( $frequency, [ 'hourly', 'daily', 'weekly' ], true ) ) {
				$frequency = 'daily';
			}
			$time = self::normalise_time( (string) ( $row['time'] ?? $current[ $type ]['time'] ) );
			$weekday = max( 1, min( 7, (int) ( $row['weekday'] ?? $current[ $type ]['weekday'] ?? 1 ) ) );
			$enabled = ! empty( $row['enabled'] );
			$out[ $type ] = [
				'enabled'   => $enabled,
				'frequency' => $frequency,
				'time'      => $time,
				'weekday'   => $weekday,
				'keep'      => max( 1, min( 100, (int) ( $row['keep'] ?? $current[ $type ]['keep'] ) ) ),
			];

			$changed_timing = $enabled !== ! empty( $current[ $type ]['enabled'] )
				|| $frequency !== (string) $current[ $type ]['frequency']
				|| $time !== (string) $current[ $type ]['time']
				|| $weekday !== (int) ( $current[ $type ]['weekday'] ?? 1 );
			if ( ! $enabled ) {
				$state[ $type ]['next_run'] = 0;
				$state[ $type ]['delayed_since'] = 0;
				$state[ $type ]['retry_count'] = 0;
			} elseif ( $changed_timing || (int) ( $state[ $type ]['next_run'] ?? 0 ) <= 0 ) {
				$state[ $type ]['next_run'] = self::next_timestamp( $frequency, $time, null, $weekday );
				$state[ $type ]['delayed_since'] = 0;
				$state[ $type ]['retry_count'] = 0;
			}
		}
		update_option( self::OPTION, $out, false );
		self::write_state( $state );
		$audit_context = [ 'source' => sanitize_key( $source ) ];
		foreach ( [ 'actor', 'actor_type', 'actor_role', 'hub_origin', 'initiator', 'initiator_id', 'run_id' ] as $key ) {
			if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) ) {
				$audit_context[ $key ] = substr( sanitize_text_field( (string) $context[ $key ] ), 0, 255 );
			}
		}
		Audit::log( 'backups.schedules.updated', 'notice', $audit_context );
	}

	public static function run_due( string $source = 'cron' ): void {
		$now = time();
		$state = self::state();
		$state['_scheduler']['last_tick'] = $now;
		$state['_scheduler']['last_tick_source'] = sanitize_key( $source );
		$state['_scheduler']['last_error'] = '';
		self::write_state( $state );

		try {
			LocalStorage::ensure();
		} catch ( Throwable $e ) {
			$state = self::state();
			$state['_scheduler']['last_error'] = wp_strip_all_tags( $e->getMessage() );
			self::write_state( $state );
			Audit::log( 'backups.scheduler.error', 'critical', [ 'source' => sanitize_key( $source ), 'error' => $e->getMessage() ] );
			return;
		}

		$lock = fopen( LocalStorage::base_path() . '/scheduler.lock', 'c' );
		if ( false === $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			if ( is_resource( $lock ) ) {
				fclose( $lock );
			}
			return;
		}

		try {
			self::observe_active_jobs( $now );

			$config = self::config();
			$state = self::state();
			foreach ( [ 'database', 'website' ] as $type ) {
				$schedule = $config[ $type ];
				if ( empty( $schedule['enabled'] ) ) {
					continue;
				}
				$next = (int) ( $state[ $type ]['next_run'] ?? 0 );
				if ( $next <= 0 ) {
					$state[ $type ]['next_run'] = self::next_timestamp( (string) $schedule['frequency'], (string) $schedule['time'], null, (int) $schedule['weekday'] );
					continue;
				}
				if ( $next > $now ) {
					continue;
				}

				$delay = max( 0, $now - $next );
				$state[ $type ]['last_attempt'] = $now;
				if ( $delay >= self::DELAY_GRACE_SECONDS && (int) ( $state[ $type ]['delay_logged_for'] ?? 0 ) !== $next ) {
					$state[ $type ]['delay_logged_for'] = $next;
					$state[ $type ]['delayed_since'] = $next;
					$state[ $type ]['missed_count'] = (int) ( $state[ $type ]['missed_count'] ?? 0 ) + 1;
					$state[ $type ]['last_delay_seconds'] = $delay;
					Audit::log( 'backups.schedule.delayed', 'warning', [ 'type' => $type, 'scheduled_for' => $next, 'delay_seconds' => $delay, 'source' => sanitize_key( $source ) ] );
				}

				if ( Repository::has_active() ) {
					$state[ $type ]['last_error'] = 'Waiting for another backup or restore job to finish.';
					continue;
				}

				try {
					$job = Service::create( $type, 'schedule' );
					$state[ $type ]['last_started'] = $now;
					$state[ $type ]['last_job_id'] = (string) ( $job['job_id'] ?? '' );
					$state[ $type ]['last_scheduled_for'] = $next;
					$state[ $type ]['last_delay_seconds'] = $delay;
					$state[ $type ]['delayed_since'] = 0;
					$state[ $type ]['last_error'] = '';
					$state[ $type ]['next_run'] = self::next_timestamp( (string) $schedule['frequency'], (string) $schedule['time'], $now + 60, (int) $schedule['weekday'] );
				} catch ( Throwable $e ) {
					$state[ $type ]['last_failure'] = $now;
					$state[ $type ]['last_error'] = wp_strip_all_tags( $e->getMessage() );
					$state[ $type ]['consecutive_failures'] = (int) ( $state[ $type ]['consecutive_failures'] ?? 0 ) + 1;
					$state[ $type ]['retry_count'] = (int) ( $state[ $type ]['retry_count'] ?? 0 ) + 1;
					$retry_delay = min( HOUR_IN_SECONDS, self::RETRY_BASE_SECONDS * max( 1, (int) $state[ $type ]['retry_count'] ) );
					$state[ $type ]['next_run'] = $now + $retry_delay;
					Audit::log( 'backups.schedule.retry.scheduled', 'warning', [ 'type' => $type, 'error' => $e->getMessage(), 'retry_in' => $retry_delay ] );
				}
			}
			self::write_state( $state );
			Runner::advance_active( 3 );
			Housekeeping::maybe_run();
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}
	}

	public static function record_success( string $type, string $job_id ): void {
		if ( ! in_array( $type, [ 'database', 'website' ], true ) ) {
			return;
		}
		$state = self::state();
		$state[ $type ]['last_success'] = time();
		$state[ $type ]['last_job_id'] = sanitize_text_field( $job_id );
		$state[ $type ]['last_error'] = '';
		$state[ $type ]['consecutive_failures'] = 0;
		$state[ $type ]['retry_count'] = 0;
		$state[ $type ]['delayed_since'] = 0;
		self::write_state( $state );
	}

	public static function record_failure( string $type, string $job_id, string $error ): void {
		if ( ! in_array( $type, [ 'database', 'website' ], true ) ) {
			return;
		}
		$now = time();
		$state = self::state();
		$state[ $type ]['last_failure'] = $now;
		$state[ $type ]['last_job_id'] = sanitize_text_field( $job_id );
		$state[ $type ]['last_error'] = wp_strip_all_tags( $error );
		$state[ $type ]['consecutive_failures'] = (int) ( $state[ $type ]['consecutive_failures'] ?? 0 ) + 1;
		$state[ $type ]['retry_count'] = (int) ( $state[ $type ]['retry_count'] ?? 0 ) + 1;
		$config = self::config();
		if ( ! empty( $config[ $type ]['enabled'] ) ) {
			$retry_delay = min( HOUR_IN_SECONDS, self::RETRY_BASE_SECONDS * max( 1, (int) $state[ $type ]['retry_count'] ) );
			$current_next = (int) ( $state[ $type ]['next_run'] ?? 0 );
			$retry_at = $now + $retry_delay;
			if ( $current_next <= 0 || $retry_at < $current_next ) {
				$state[ $type ]['next_run'] = $retry_at;
			}
		}
		self::write_state( $state );
	}

	public static function record_cancelled( string $type, string $job_id ): void {
		if ( ! in_array( $type, [ 'database', 'website' ], true ) ) {
			return;
		}
		$state = self::state();
		$state[ $type ]['last_cancelled'] = time();
		$state[ $type ]['last_job_id'] = sanitize_text_field( $job_id );
		$state[ $type ]['last_error'] = 'Scheduled backup was cancelled.';
		$state[ $type ]['retry_count'] = 0;
		self::write_state( $state );
	}

	public static function next_timestamp( string $frequency, string $time, ?int $from = null, int $weekday = 1 ): int {
		$timezone = wp_timezone();
		$now = ( new DateTimeImmutable( '@' . ( $from ?? time() ) ) )->setTimezone( $timezone );
		[ $hour, $minute ] = array_map( 'intval', explode( ':', self::normalise_time( $time ) ) );

		if ( 'hourly' === $frequency ) {
			$candidate = $now->setTime( (int) $now->format( 'H' ), $minute, 0 );
			if ( $candidate <= $now ) {
				$candidate = $candidate->modify( '+1 hour' );
			}
			return $candidate->getTimestamp();
		}

		$candidate = $now->setTime( $hour, $minute, 0 );
		if ( 'weekly' === $frequency ) {
			$weekday = max( 1, min( 7, $weekday ) );
			$current_weekday = (int) $candidate->format( 'N' );
			$days = $weekday - $current_weekday;
			if ( 0 !== $days ) {
				$candidate = $candidate->modify( ( $days > 0 ? '+' : '' ) . $days . ' days' );
			}
			if ( $candidate <= $now ) {
				$candidate = $candidate->modify( '+1 week' );
			}
			return $candidate->getTimestamp();
		}
		if ( $candidate <= $now ) {
			$candidate = $candidate->modify( '+1 day' );
		}
		return $candidate->getTimestamp();
	}

	private static function observe_active_jobs( int $now ): void {
		foreach ( Repository::active( 5 ) as $job ) {
			$job_id = (string) ( $job['job_id'] ?? '' );
			$meta = is_array( $job['meta'] ?? null ) ? $job['meta'] : [];
			if ( 'backup' === (string) ( $job['kind'] ?? '' ) && 'schedule' === (string) ( $job['trigger_source'] ?? '' ) ) {
				$started = (int) ( $meta['started_timestamp'] ?? 0 );
				$typical = (int) ( $meta['typical_duration_seconds'] ?? 0 );
				$threshold = $typical > 0 ? max( $typical + 60, (int) ceil( $typical * 1.5 ) ) : 0;
				$long_key = 'cb_backups_long_' . substr( str_replace( '-', '', $job_id ), 0, 24 );
				if ( $threshold > 0 && $started > 0 && $now - $started > $threshold && ! get_transient( $long_key ) ) {
					set_transient( $long_key, 1, DAY_IN_SECONDS );
					Audit::log( 'backups.schedule.long.running', 'warning', [ 'job_id' => $job_id, 'type' => (string) $job['backup_type'], 'elapsed' => $now - $started, 'typical' => $typical ] );
				}
			}

			$updated = self::mysql_utc_timestamp( (string) ( $job['updated_at'] ?? '' ) );
			if ( $updated <= 0 || $now - $updated < self::STALE_JOB_SECONDS ) {
				continue;
			}
			$recovery_key = 'cb_backups_stale_' . substr( str_replace( '-', '', $job_id ), 0, 24 );
			if ( get_transient( $recovery_key ) ) {
				continue;
			}
			set_transient( $recovery_key, 1, self::STALE_JOB_SECONDS );
			Dispatcher::dispatch( $job_id );
			Audit::log( 'backups.stale.job.recovery', 'warning', [ 'job_id' => $job_id, 'kind' => (string) $job['kind'], 'type' => (string) $job['backup_type'], 'stale_seconds' => $now - $updated ] );
		}
	}

	/** @return array<string,array<string,mixed>> */
	private static function config(): array {
		$defaults = [
			'database' => [ 'enabled' => false, 'frequency' => 'daily', 'time' => '02:00', 'weekday' => 1, 'keep' => 14 ],
			'website'  => [ 'enabled' => false, 'frequency' => 'daily', 'time' => '03:00', 'weekday' => 1, 'keep' => 7 ],
		];
		$stored = get_option( self::OPTION, [] );
		if ( ! is_array( $stored ) ) {
			return $defaults;
		}
		foreach ( $defaults as $type => $default ) {
			$stored[ $type ] = isset( $stored[ $type ] ) && is_array( $stored[ $type ] ) ? array_merge( $default, $stored[ $type ] ) : $default;
			$stored[ $type ]['weekday'] = max( 1, min( 7, (int) ( $stored[ $type ]['weekday'] ?? 1 ) ) );
		}
		return [ 'database' => $stored['database'], 'website' => $stored['website'] ];
	}

	/** @return array<string,mixed> */
	private static function state(): array {
		$runtime_default = [
			'next_run' => 0,
			'last_attempt' => 0,
			'last_started' => 0,
			'last_success' => 0,
			'last_failure' => 0,
			'last_cancelled' => 0,
			'last_job_id' => '',
			'last_error' => '',
			'last_scheduled_for' => 0,
			'last_delay_seconds' => 0,
			'delayed_since' => 0,
			'delay_logged_for' => 0,
			'missed_count' => 0,
			'consecutive_failures' => 0,
			'retry_count' => 0,
		];
		$defaults = [
			'database' => $runtime_default,
			'website' => $runtime_default,
			'_scheduler' => [ 'last_tick' => 0, 'last_tick_source' => '', 'last_error' => '' ],
		];
		$stored = get_option( self::STATE_OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];
		foreach ( [ 'database', 'website' ] as $type ) {
			$stored[ $type ] = isset( $stored[ $type ] ) && is_array( $stored[ $type ] ) ? array_merge( $runtime_default, $stored[ $type ] ) : $runtime_default;
		}
		$stored['_scheduler'] = isset( $stored['_scheduler'] ) && is_array( $stored['_scheduler'] ) ? array_merge( $defaults['_scheduler'], $stored['_scheduler'] ) : $defaults['_scheduler'];

		// One-time compatibility with pre-rc13 next_run values stored in config.
		$config = get_option( self::OPTION, [] );
		if ( is_array( $config ) ) {
			foreach ( [ 'database', 'website' ] as $type ) {
				if ( 0 === (int) $stored[ $type ]['next_run'] && isset( $config[ $type ]['next_run'] ) ) {
					$stored[ $type ]['next_run'] = max( 0, (int) $config[ $type ]['next_run'] );
				}
			}
		}
		return $stored;
	}

	/** @param array<string,mixed> $state */
	private static function write_state( array $state ): void {
		update_option( self::STATE_OPTION, $state, false );
	}

	private static function normalise_time( string $time ): string {
		if ( ! preg_match( '/^(?:[01]\d|2[0-3]):[0-5]\d$/', $time ) ) {
			return '02:00';
		}
		return $time;
	}

	private static function mysql_utc_timestamp( string $value ): int {
		if ( '' === $value ) {
			return 0;
		}
		$timestamp = strtotime( $value . ' UTC' );
		return false === $timestamp ? 0 : $timestamp;
	}
}
