<?php
declare(strict_types=1);

namespace CB\Backups\Remote;

use DateTimeImmutable;
use DateTimeZone;

defined( 'ABSPATH' ) || exit;

final class JobResource {
	/** @param array<string,mixed> $job @return array<string,mixed> */
	public static function from_job( array $job ): array {
		$kind            = (string) ( $job['kind'] ?? '' );
		$status          = (string) ( $job['status'] ?? '' );
		$meta            = is_array( $job['meta'] ?? null ) ? $job['meta'] : [];
		$request_context = is_array( $meta['request_context'] ?? null ) ? $meta['request_context'] : [];
		$type            = in_array( (string) ( $job['backup_type'] ?? '' ), [ 'database', 'website' ], true ) ? (string) $job['backup_type'] : 'database';
		$run_id          = trim( sanitize_text_field( (string) ( $request_context['run_id'] ?? '' ) ) );

		$metrics = [
			'database_rows_done'    => max( 0, (int) ( $meta['db_rows_done'] ?? 0 ) ),
			'database_rows_total'   => max( 0, (int) ( $meta['db_rows_total'] ?? 0 ) ),
			'database_tables_done'  => max( 0, (int) ( $meta['db_tables_done'] ?? 0 ) ),
			'database_tables_total' => max( 0, (int) ( $meta['db_tables_total'] ?? 0 ) ),
			'files_done'            => max( 0, (int) ( $meta['files_done'] ?? 0 ) ),
			'files_total'           => max( 0, (int) ( $meta['files_total'] ?? 0 ) ),
			'files_bytes_done'      => max( 0, (int) ( $meta['files_bytes_done'] ?? 0 ) ),
			'files_bytes_total'     => max( 0, (int) ( $meta['files_bytes_total'] ?? 0 ) ),
			'verification_done'     => max( 0, (int) ( $meta['restore_verify_done'] ?? $meta['verify_done'] ?? 0 ) ),
			'verification_total'    => max( 0, (int) ( $meta['restore_verify_total'] ?? $meta['verify_total'] ?? 0 ) ),
		];

		return [
			'schema_version'   => Contract::SCHEMA_VERSION,
			'job_id'           => (string) ( $job['job_id'] ?? '' ),
			'kind'             => 'verify' === $kind ? 'verify' : 'backup',
			'type'             => $type,
			'run_id'           => '' !== $run_id ? substr( $run_id, 0, 64 ) : null,
			'state'            => self::state( $status ),
			'stage'            => self::stage( $kind, (string) ( $job['stage'] ?? '' ), $status ),
			'progress_percent' => max( 0, min( 100, (int) ( $job['progress'] ?? 0 ) ) ),
			'created_at'       => self::utc_iso( (string) ( $job['created_at'] ?? '' ) ),
			'updated_at'       => self::utc_iso( (string) ( $job['updated_at'] ?? '' ) ),
			'elapsed_seconds'  => self::elapsed( $job, $meta ),
			'result_backup_id' => 'completed' === $status && ! empty( $job['archive_name'] ) ? (string) $job['archive_name'] : null,
			'metrics'          => $metrics,
			'error'            => self::public_error( $kind, $status ),
			'cancellable'      => 'backup' === $kind && in_array( $status, [ 'queued', 'running' ], true ),
		];
	}

	public static function is_remote_kind( array $job ): bool {
		return in_array( (string) ( $job['kind'] ?? '' ), [ 'backup', 'verify' ], true );
	}

	private static function state( string $state ): string {
		return in_array( $state, [ 'queued', 'running', 'cancelling', 'cancelled', 'completed', 'failed' ], true ) ? $state : 'failed';
	}

	private static function stage( string $kind, string $stage, string $state ): string {
		if ( 'verify' === $kind ) {
			return 'completed' === $state ? 'completed' : ( 'failed' === $state ? 'verify' : 'verify' );
		}
		$allowed = [ 'queued', 'database', 'inventory', 'package', 'verify', 'completed', 'cancelled' ];
		return in_array( $stage, $allowed, true ) ? $stage : ( 'failed' === $state ? 'verify' : 'queued' );
	}

	/** @return array{code:string,message:string,retryable:bool}|null */
	private static function public_error( string $kind, string $status ): ?array {
		if ( 'failed' !== $status ) {
			return null;
		}
		return [
			'code'      => 'verify' === $kind ? 'cb_backups_verify_failed' : 'cb_backups_job_failed',
			'message'   => 'verify' === $kind
				? 'Backup verification failed. Review the managed site logs for details.'
				: 'Backup creation failed. Review the managed site logs for details.',
			'retryable' => false,
		];
	}

	/** @param array<string,mixed> $job @param array<string,mixed> $meta */
	private static function elapsed( array $job, array $meta ): int {
		if ( isset( $meta['duration_seconds'] ) ) {
			return max( 0, (int) $meta['duration_seconds'] );
		}
		$created = self::mysql_utc_timestamp( (string) ( $job['created_at'] ?? '' ) );
		$end     = self::mysql_utc_timestamp( (string) ( $job['completed_at'] ?? '' ) );
		if ( $created <= 0 ) {
			return 0;
		}
		return max( 0, ( $end > 0 ? $end : time() ) - $created );
	}

	private static function utc_iso( string $value ): ?string {
		$timestamp = self::mysql_utc_timestamp( $value );
		return $timestamp > 0 ? gmdate( 'c', $timestamp ) : null;
	}

	private static function mysql_utc_timestamp( string $value ): int {
		if ( '' === $value ) {
			return 0;
		}
		$date = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $value, new DateTimeZone( 'UTC' ) );
		return false === $date ? 0 : $date->getTimestamp();
	}
}
