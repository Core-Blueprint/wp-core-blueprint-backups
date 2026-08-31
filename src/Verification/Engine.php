<?php
declare(strict_types=1);

namespace CB\Backups\Verification;

use CB\Backups\Jobs\Repository;
use CB\Backups\Restore\ArchiveValidator;
use CB\Backups\Restore\ArchiveVerifier;
use CB\Backups\Storage\LocalStorage;
use CB\Backups\Support\Audit;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class Engine {
	/** @param array<string,mixed> $job */
	public static function tick( array $job ): void {
		$job_id  = (string) ( $job['job_id'] ?? '' );
		$archive = (string) ( $job['archive_name'] ?? '' );
		$meta    = is_array( $job['meta'] ?? null ) ? $job['meta'] : [];
		if ( '' === $job_id || '' === $archive ) {
			throw new RuntimeException( 'Verification job state is incomplete.' );
		}
		$path = LocalStorage::archive_path( $archive );
		$work = LocalStorage::work_dir( $job_id );

		if ( 'queued' === (string) ( $job['status'] ?? '' ) ) {
			ArchiveValidator::validate( $path, false );
			$size  = filesize( $path );
			$mtime = filemtime( $path );
			if ( false === $size || false === $mtime ) {
				throw new RuntimeException( 'Backup archive metadata could not be read.' );
			}
			$index = $work . '/checksums.jsonl';
			$count = ArchiveValidator::export_checksum_index( $path, $index );
			$meta['restore_archive_size']        = (int) $size;
			$meta['restore_archive_mtime']       = (int) $mtime;
			$meta['restore_verify_cursor']       = 0;
			$meta['restore_verify_done']         = 0;
			$meta['restore_verify_total']        = max( 1, $count );
			$meta['restore_verify_current_file'] = '';
			Repository::update( $job_id, [ 'status' => 'running', 'stage' => 'verify', 'progress' => 0, 'meta' => $meta ] );
			return;
		}

		$index = $work . '/checksums.jsonl';
		$result = ArchiveVerifier::tick( $path, $index, $meta );
		$meta   = $result['meta'];
		if ( ! $result['done'] ) {
			Repository::update( $job_id, [ 'stage' => 'verify', 'progress' => min( 99, (int) $result['progress'] ), 'meta' => $meta ] );
			return;
		}

		$now = time();
		$sidecar = LocalStorage::read_meta( $archive );
		if ( null === $sidecar ) {
			throw new RuntimeException( 'Backup metadata is missing.' );
		}
		$sidecar['verified']           = true;
		$sidecar['integrity']          = 'payload-sha256';
		$sidecar['verified_at']        = gmdate( 'c', $now );
		$sidecar['verified_timestamp'] = $now;
		$sidecar['payloads_verified']  = (int) ( $meta['restore_payloads_verified'] ?? $meta['restore_verify_done'] ?? 0 );
		unset( $sidecar['verification_failed_at'], $sidecar['verification_failed_timestamp'] );
		LocalStorage::write_meta( $archive, $sidecar );

		$started = max( 0, (int) ( $meta['started_timestamp'] ?? $now ) );
		$meta['duration_seconds'] = max( 0, $now - $started );
		Repository::update( $job_id, [ 'meta' => $meta ] );
		Repository::complete( $job_id, $archive );

		$context = is_array( $meta['request_context'] ?? null ) ? $meta['request_context'] : [];
		Audit::log( 'backups.backup.verified', 'notice', [
			'job_id'            => $job_id,
			'archive'           => $archive,
			'trigger'           => (string) ( $job['trigger_source'] ?? '' ),
			'payloads_verified' => (int) $sidecar['payloads_verified'],
		] + $context );
		LocalStorage::remove_tree( $work );
	}

	/** Mark the archive fail-closed when an explicit verification cannot confirm integrity. */
	public static function mark_failed( array $job ): void {
		$archive = (string) ( $job['archive_name'] ?? '' );
		if ( '' === $archive ) {
			return;
		}
		$sidecar = LocalStorage::read_meta( $archive );
		if ( null === $sidecar ) {
			return;
		}
		$sidecar['verified']                 = false;
		$sidecar['integrity']                = 'unverified';
		$sidecar['verification_failed_at']   = gmdate( 'c' );
		$sidecar['verification_failed_timestamp'] = time();
		LocalStorage::write_meta( $archive, $sidecar );
	}
}
