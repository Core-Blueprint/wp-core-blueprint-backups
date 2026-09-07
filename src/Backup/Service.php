<?php
declare(strict_types=1);

namespace CB\Backups\Backup;

use CB\Backups\Jobs\Dispatcher;
use CB\Backups\Jobs\Repository;
use CB\Backups\Storage\LocalStorage;
use CB\Backups\Support\Audit;
use CB\Backups\Support\Telemetry;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class Service {
	/** @return array<string,mixed> */
	public static function create( string $type, string $trigger = 'manual', array $context = [] ): array {
		$type = sanitize_key( $type );
		if ( ! in_array( $type, [ 'database', 'website' ], true ) ) {
			throw new RuntimeException( 'Unsupported backup type.' );
		}
		if ( is_multisite() ) {
			throw new RuntimeException( 'Multisite backups are not supported in backup format v1.' );
		}

		$safe_context = self::safe_context( $context );
		if ( 'hub' === $trigger && ! empty( $safe_context['run_id'] ) ) {
			$existing = Repository::find_hub_backup_by_run_id( (string) $safe_context['run_id'], $type );
			if ( is_array( $existing ) ) {
				return $existing;
			}
		}

		if ( Repository::has_active() ) {
			throw new RuntimeException( 'Another backup or restore job is already active.' );
		}
		LocalStorage::ensure();
		$meta = [
			'request_context'          => $safe_context,
			'started_timestamp'        => time(),
			'typical_duration_seconds' => Telemetry::median_duration( $type ) ?? 0,
		];
		$job = Repository::create( 'backup', $type, $trigger, $meta );
		if ( ! $job ) {
			throw new RuntimeException( 'Backup job could not be created.' );
		}
		Audit::log( 'backups.backup.started', 'notice', array_merge( [ 'job_id' => $job['job_id'], 'type' => $type, 'trigger' => $trigger ], $safe_context ) );

		// The server owns execution. The browser only monitors state. A single
		// cron event remains as a recovery watchdog if loopback dispatch fails.
		if ( 'cli' !== $trigger ) {
			Dispatcher::dispatch( (string) $job['job_id'] );
			wp_schedule_single_event( time() + 30, 'cb_backups_run_job', [ (string) $job['job_id'] ] );
		}
		return $job;
	}

	/** @param array<string,mixed> $context @return array<string,mixed> */
	private static function safe_context( array $context ): array {
		$out = [];
		foreach ( [ 'actor', 'actor_type', 'actor_role', 'hub_origin', 'initiator', 'initiator_id', 'run_id' ] as $key ) {
			if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) ) {
				$out[ $key ] = sanitize_text_field( (string) $context[ $key ] );
			}
		}
		return $out;
	}
}
