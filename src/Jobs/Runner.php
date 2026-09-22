<?php
declare(strict_types=1);

namespace CB\Backups\Jobs;

use CB\Backups\Backup\Engine as BackupEngine;
use CB\Backups\Restore\Engine as RestoreEngine;
use CB\Backups\Verification\Engine as VerificationEngine;
use CB\Backups\Restore\CriticalRecovery;
use CB\Backups\Schedule\Scheduler;
use CB\Backups\Storage\LocalStorage;
use CB\Backups\Support\Audit;
use CB\Backups\Support\Telemetry;

use Throwable;

defined( 'ABSPATH' ) || exit;

final class Runner {
	/** @return array<string,mixed>|null */
	public static function tick( string $job_id ): ?array {
		$result = self::run_budget( $job_id, 0.0, 1 );
		return $result['job'];
	}

	/**
	 * Run as much work as safely fits inside a bounded server-side time budget.
	 *
	 * @return array{job:array<string,mixed>|null,processed:bool,iterations:int}
	 */
	public static function run_budget( string $job_id, float $seconds = 3.0, int $max_iterations = 100 ): array {
		$job = Repository::get( $job_id );
		if ( ! $job || in_array( (string) $job['status'], [ 'completed', 'failed', 'cancelled' ], true ) ) {
			return [ 'job' => $job, 'processed' => false, 'iterations' => 0 ];
		}

		$work = LocalStorage::work_dir( $job_id );
		$lock = fopen( $work . '/job.lock', 'c' );
		if ( false === $lock || ! flock( $lock, LOCK_EX | LOCK_NB ) ) {
			if ( is_resource( $lock ) ) {
				fclose( $lock );
			}
			return [ 'job' => Repository::get( $job_id ), 'processed' => false, 'iterations' => 0 ];
		}

		$started    = microtime( true );
		$processed  = false;
		$iterations = 0;
		$deadline   = $seconds > 0 ? $started + $seconds : PHP_FLOAT_MAX;

		try {
			while ( $iterations < max( 1, $max_iterations ) && microtime( true ) < $deadline && self::memory_safe() ) {
				$job = Repository::get( $job_id );
				if ( ! $job || in_array( (string) $job['status'], [ 'completed', 'failed', 'cancelled' ], true ) ) {
					break;
				}

				if ( 'cancelling' === (string) $job['status'] ) {
					self::cancel( $job );
					$processed = true;
					++$iterations;
					break;
				}

				if ( 'restore' === (string) $job['kind'] ) {
					if ( 'await_recovery' === (string) ( $job['stage'] ?? '' ) ) {
						break;
					}
					RestoreEngine::tick( $job );
					$after_restore = Repository::get( $job_id );
					if ( is_array( $after_restore ) && in_array( (string) ( $after_restore['stage'] ?? '' ), [ 'reconcile_destination', 'await_recovery' ], true ) ) {
						$processed = true;
						++$iterations;
						break;
					}
				} elseif ( 'verify' === (string) $job['kind'] ) {
					VerificationEngine::tick( $job );
				} else {
					BackupEngine::tick( $job );
				}

				$processed = true;
				++$iterations;

				if ( 0.0 === $seconds ) {
					break;
				}
			}
		} catch ( Throwable $e ) {
			$current = Repository::get( $job_id );
			if ( is_array( $current ) && 'cancelling' === (string) $current['status'] && 'backup' === (string) $current['kind'] ) {
				self::cancel( $current );
			} elseif ( is_array( $current ) && 'restore' === (string) $current['kind'] && CriticalRecovery::is_armed( $job_id ) ) {
				CriticalRecovery::fail_and_rollback( $job_id, $e );
			} else {
				Repository::fail( $job_id, $e->getMessage() );
				if ( 'verify' === (string) ( $job['kind'] ?? '' ) ) {
					VerificationEngine::mark_failed( is_array( $current ) ? $current : $job );
				}
				if ( 'backup' === (string) ( $job['kind'] ?? '' ) && 'schedule' === (string) ( $job['trigger_source'] ?? '' ) ) {
					Scheduler::record_failure( (string) ( $job['backup_type'] ?? '' ), $job_id, $e->getMessage() );
				}
				$kind = (string) ( $job['kind'] ?? '' );
				$event = match ( $kind ) {
					'restore' => 'backups.restore.failed',
					'verify'  => 'backups.backup.verify.failed',
					default   => 'backups.backup.failed',
				};
				Audit::log( $event, 'critical', [
					'job_id'  => $job_id,
					'type'    => (string) ( $job['backup_type'] ?? '' ),
					'trigger' => (string) ( $job['trigger_source'] ?? '' ),
					'error'   => $e->getMessage(),
				] );
			}
			$processed = true;
		} finally {
			flock( $lock, LOCK_UN );
			fclose( $lock );
		}

		$final_job = Repository::get( $job_id );
		if ( is_array( $final_job ) && 'backup' === (string) $final_job['kind'] && in_array( (string) $final_job['status'], [ 'cancelled', 'failed' ], true ) ) {
			$final_meta = is_array( $final_job['meta'] ?? null ) ? $final_job['meta'] : [];
			$archive_name = (string) ( $final_job['archive_name'] ?? $final_meta['archive_name'] ?? '' );
			if ( '' !== $archive_name ) {
				LocalStorage::delete_archive( $archive_name );
			}
			LocalStorage::remove_tree( $work );
		}

		if ( is_array( $final_job ) && 'verify' === (string) $final_job['kind'] && 'failed' === (string) $final_job['status'] ) {
			LocalStorage::remove_tree( $work );
		}

		return [ 'job' => $final_job, 'processed' => $processed, 'iterations' => $iterations ];
	}

	public static function scheduled_tick( string $job_id ): void {
		$result = self::run_budget( $job_id, 2.0 );
		$job    = $result['job'];
		if ( $result['processed'] && is_array( $job ) && in_array( (string) $job['status'], [ 'queued', 'running', 'cancelling' ], true ) ) {
			Dispatcher::dispatch( $job_id );
			wp_schedule_single_event( time() + 60, 'cb_backups_run_job', [ $job_id ] );
		}
	}

	public static function advance_active( int $limit = 5 ): void {
		foreach ( Repository::active( $limit ) as $job ) {
			$job_id = (string) $job['job_id'];
			self::run_budget( $job_id, 2.0 );
			Dispatcher::dispatch( $job_id );
		}
	}

	/** @param array<string,mixed> $job */
	private static function cancel( array $job ): void {
		$job_id = (string) $job['job_id'];
		if ( 'backup' !== (string) $job['kind'] ) {
			Repository::update( $job_id, [ 'status' => 'running' ] );
			return;
		}

		$meta         = is_array( $job['meta'] ?? null ) ? $job['meta'] : [];
		$archive_name = (string) ( $job['archive_name'] ?? $meta['archive_name'] ?? '' );
		if ( '' !== $archive_name ) {
			LocalStorage::delete_archive( $archive_name );
		}
		Repository::cancelled( $job_id );
		if ( 'schedule' === (string) ( $job['trigger_source'] ?? '' ) ) {
			Scheduler::record_cancelled( (string) $job['backup_type'], $job_id );
		}
		Audit::log( 'backups.backup.cancelled', 'warning', [
			'job_id'        => $job_id,
			'type'          => (string) $job['backup_type'],
			'trigger'       => (string) $job['trigger_source'],
			'duration'      => Telemetry::elapsed_seconds( $job ),
			'rows'          => (int) ( $meta['db_rows_done'] ?? 0 ),
			'current_table' => (string) ( $meta['db_current_table'] ?? '' ),
		] );
	}

	private static function memory_safe(): bool {
		$limit = wp_convert_hr_to_bytes( (string) ini_get( 'memory_limit' ) );
		if ( $limit <= 0 ) {
			return true;
		}
		return memory_get_usage( true ) < (int) floor( $limit * 0.75 );
	}
}
