<?php
declare(strict_types=1);

namespace CB\Backups\Verification;

use CB\Backups\Jobs\Dispatcher;
use CB\Backups\Jobs\Repository;
use CB\Backups\Remote\BackupResource;
use CB\Backups\Storage\LocalStorage;
use CB\Backups\Support\Audit;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class Service {
	/** @return array<string,mixed> */
	public static function create( string $backup_id, string $trigger = 'hub', array $context = [] ): array {
		$backup = BackupResource::find( $backup_id );
		if ( null === $backup ) {
			throw new RuntimeException( 'Backup archive was not found.' );
		}
		if ( Repository::has_active() ) {
			throw new RuntimeException( 'Another backup, restore or verification job is already active.' );
		}

		$type = in_array( (string) ( $backup['backup_type'] ?? '' ), [ 'database', 'website' ], true ) ? (string) $backup['backup_type'] : 'database';
		$path = LocalStorage::archive_path( $backup_id );
		if ( ! is_file( $path ) || ! is_readable( $path ) ) {
			throw new RuntimeException( 'Backup archive is not readable.' );
		}

		$meta = [
			'request_context'   => self::safe_context( $context ),
			'started_timestamp' => time(),
			'archive_name'      => basename( sanitize_file_name( $backup_id ) ),
		];
		$job = Repository::create( 'verify', $type, sanitize_key( $trigger ), $meta );
		if ( ! $job ) {
			throw new RuntimeException( 'Verification job could not be created.' );
		}
		Repository::update( (string) $job['job_id'], [ 'archive_name' => $meta['archive_name'] ] );
		$job = Repository::get( (string) $job['job_id'] ) ?? $job;

		Audit::log( 'backups.backup.verify.started', 'notice', [
			'job_id'  => (string) $job['job_id'],
			'archive' => $meta['archive_name'],
			'trigger' => sanitize_key( $trigger ),
		] + self::safe_context( $context ) );

		Dispatcher::dispatch( (string) $job['job_id'] );
		wp_schedule_single_event( time() + 30, 'cb_backups_run_job', [ (string) $job['job_id'] ] );
		return $job;
	}

	/** @param array<string,mixed> $context @return array<string,string> */
	private static function safe_context( array $context ): array {
		$out = [];
		foreach ( [ 'actor', 'actor_type', 'actor_role', 'hub_origin', 'initiator', 'initiator_id', 'run_id' ] as $key ) {
			if ( isset( $context[ $key ] ) && is_scalar( $context[ $key ] ) ) {
				$out[ $key ] = substr( sanitize_text_field( (string) $context[ $key ] ), 0, 255 );
			}
		}
		return $out;
	}
}
