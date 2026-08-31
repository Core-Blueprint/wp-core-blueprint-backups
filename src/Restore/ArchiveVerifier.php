<?php
declare(strict_types=1);

namespace CB\Backups\Restore;

use RuntimeException;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

final class ArchiveVerifier {
	private const TIME_BUDGET_SECONDS = 1.25;
	private const MAX_ENTRIES_PER_TICK = 50;

	/**
	 * Verify payload checksums incrementally from a line-oriented work index.
	 *
	 * @param array<string,mixed> $meta
	 * @return array{done:bool,progress:int,meta:array<string,mixed>}
	 */
	public static function tick( string $archive, string $index_file, array $meta ): array {
		if ( ! is_file( $archive ) || ! is_file( $index_file ) ) {
			throw new RuntimeException( 'Restore verification state is incomplete.' );
		}
		self::assert_archive_stable( $archive, $meta );

		$handle = fopen( $index_file, 'rb' );
		$zip = new ZipArchive();
		if ( false === $handle || true !== $zip->open( $archive, ZipArchive::CHECKCONS ) ) {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
			throw new RuntimeException( 'Restore archive could not be opened for integrity verification.' );
		}

		$cursor = max( 0, (int) ( $meta['restore_verify_cursor'] ?? 0 ) );
		if ( $cursor > 0 && 0 !== fseek( $handle, $cursor ) ) {
			fclose( $handle );
			$zip->close();
			throw new RuntimeException( 'Restore verification cursor could not be restored.' );
		}

		$processed = 0;
		$deadline = microtime( true ) + self::TIME_BUDGET_SECONDS;
		try {
			while ( $processed < self::MAX_ENTRIES_PER_TICK && microtime( true ) < $deadline && false !== ( $line = fgets( $handle ) ) ) {
				$entry = json_decode( trim( $line ), true );
				$path = is_array( $entry ) ? (string) ( $entry['path'] ?? '' ) : '';
				$want = is_array( $entry ) ? strtolower( (string) ( $entry['sha256'] ?? '' ) ) : '';
				$size = is_array( $entry ) ? (int) ( $entry['size'] ?? -1 ) : -1;
				if ( '' === $path || ! preg_match( '/^[a-f0-9]{64}$/', $want ) || $size < 0 ) {
					throw new RuntimeException( 'Restore checksum work index is malformed.' );
				}
				ArchiveValidator::assert_safe_entry( $path );
				$stat = $zip->statName( $path );
				if ( ! is_array( $stat ) || (int) ( $stat['size'] ?? -1 ) !== $size ) {
					throw new RuntimeException( sprintf( 'Backup payload size verification failed for %s.', $path ) );
				}
				$stream = $zip->getStream( $path );
				if ( false === $stream ) {
					throw new RuntimeException( sprintf( 'Backup payload is missing: %s', $path ) );
				}
				$context = hash_init( 'sha256' );
				hash_update_stream( $context, $stream );
				fclose( $stream );
				if ( ! hash_equals( $want, hash_final( $context ) ) ) {
					throw new RuntimeException( sprintf( 'Checksum verification failed for %s.', $path ) );
				}
				++$processed;
				$meta['restore_verify_done'] = (int) ( $meta['restore_verify_done'] ?? 0 ) + 1;
				$meta['restore_verify_current_file'] = $path;
			}
			$position = ftell( $handle );
			$meta['restore_verify_cursor'] = false === $position ? $cursor : $position;
			$done = feof( $handle );
		} finally {
			fclose( $handle );
			$zip->close();
		}

		$total = max( 1, (int) ( $meta['restore_verify_total'] ?? 1 ) );
		$progress = (int) floor( min( 1, (int) ( $meta['restore_verify_done'] ?? 0 ) / $total ) * 100 );
		if ( $done ) {
			$meta['restore_verify_current_file'] = '';
			$meta['restore_payloads_verified'] = (int) ( $meta['restore_verify_done'] ?? 0 );
		}
		return [ 'done' => $done, 'progress' => $progress, 'meta' => $meta ];
	}

	/** @param array<string,mixed> $meta */
	public static function assert_archive_stable( string $archive, array $meta ): void {
		clearstatcache( true, $archive );
		$size = filesize( $archive );
		$mtime = filemtime( $archive );
		if ( false === $size || false === $mtime ) {
			throw new RuntimeException( 'Restore archive metadata could not be read.' );
		}
		if ( isset( $meta['restore_archive_size'] ) && (int) $meta['restore_archive_size'] !== (int) $size ) {
			throw new RuntimeException( 'Restore archive changed after verification started.' );
		}
		if ( isset( $meta['restore_archive_mtime'] ) && (int) $meta['restore_archive_mtime'] !== (int) $mtime ) {
			throw new RuntimeException( 'Restore archive changed after verification started.' );
		}
	}
}
