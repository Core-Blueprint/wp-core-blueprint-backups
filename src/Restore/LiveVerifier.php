<?php
declare(strict_types=1);

namespace CB\Backups\Restore;

use CB\Backups\Support\FilesystemPolicy;
use RuntimeException;

\defined( 'ABSPATH' ) || exit;

final class LiveVerifier {
	private const TIME_BUDGET_SECONDS = 4.0;
	private const MAX_ENTRIES_PER_TICK = 1000;

	/** @param array<string,mixed> $meta @return array<string,mixed> */
	public static function prepare( string $index_file, array $meta ): array {
		if ( ! is_file( $index_file ) ) {
			throw new RuntimeException( 'Restore checksum work index is missing before live verification.' );
		}
		$handle = fopen( $index_file, 'rb' );
		if ( false === $handle ) {
			throw new RuntimeException( 'Restore checksum work index could not be opened before live verification.' );
		}
		$total = 0;
		try {
			while ( false !== ( $line = fgets( $handle ) ) ) {
				$entry = json_decode( trim( $line ), true );
				$path = is_array( $entry ) ? (string) ( $entry['path'] ?? '' ) : '';
				if ( self::live_relative_path( $path ) !== null ) {
					++$total;
				}
			}
		} finally {
			fclose( $handle );
		}
		$meta['live_verify_cursor'] = 0;
		$meta['live_verify_done'] = 0;
		$meta['live_verify_total'] = $total;
		$meta['live_verify_current_file'] = '';
		return $meta;
	}

	/** @param array<string,mixed> $meta @return array{done:bool,progress:int,meta:array<string,mixed>} */
	public static function tick( string $index_file, array $meta ): array {
		$handle = fopen( $index_file, 'rb' );
		if ( false === $handle ) {
			throw new RuntimeException( 'Restore checksum work index could not be opened for live verification.' );
		}
		$cursor = max( 0, (int) ( $meta['live_verify_cursor'] ?? 0 ) );
		if ( $cursor > 0 && 0 !== fseek( $handle, $cursor ) ) {
			fclose( $handle );
			throw new RuntimeException( 'Live restore verification cursor could not be resumed.' );
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
					throw new RuntimeException( 'Restore checksum work index is malformed during live verification.' );
				}
				$relative = self::live_relative_path( $path );
				if ( null === $relative ) {
					continue;
				}
				$target = wp_normalize_path( WP_CONTENT_DIR . '/' . $relative );
				if ( ! is_file( $target ) || is_link( $target ) ) {
					throw new RuntimeException( sprintf( 'Restored live payload is missing or unsafe: %s', $relative ) );
				}
				clearstatcache( true, $target );
				if ( (int) filesize( $target ) !== $size ) {
					throw new RuntimeException( sprintf( 'Restored live payload size mismatch for %s.', $relative ) );
				}
				$have = hash_file( 'sha256', $target );
				if ( ! is_string( $have ) || ! hash_equals( $want, $have ) ) {
					throw new RuntimeException( sprintf( 'Restored live payload checksum mismatch for %s.', $relative ) );
				}
				++$processed;
				$meta['live_verify_done'] = (int) ( $meta['live_verify_done'] ?? 0 ) + 1;
				$meta['live_verify_current_file'] = $relative;
			}
			$position = ftell( $handle );
			$meta['live_verify_cursor'] = false === $position ? $cursor : (int) $position;
			$done = feof( $handle );
		} finally {
			fclose( $handle );
		}
		$total = max( 1, (int) ( $meta['live_verify_total'] ?? 1 ) );
		$progress = (int) floor( min( 1, (int) ( $meta['live_verify_done'] ?? 0 ) / $total ) * 100 );
		if ( $done ) {
			$meta['live_verify_current_file'] = '';
			$meta['live_payloads_verified'] = (int) ( $meta['live_verify_done'] ?? 0 );
		}
		return [ 'done' => $done, 'progress' => $progress, 'meta' => $meta ];
	}

	private static function live_relative_path( string $archive_path ): ?string {
		$prefix = 'files/wp-content/';
		if ( ! str_starts_with( $archive_path, $prefix ) ) {
			return null;
		}
		$relative = substr( $archive_path, strlen( $prefix ) );
		if ( '' === $relative || FilesystemPolicy::is_excluded_relative_path( $relative ) || FilesystemCommitter::is_runtime_protected_path( $relative ) ) {
			return null;
		}
		ArchiveValidator::assert_safe_entry( $archive_path );
		return $relative;
	}
}
