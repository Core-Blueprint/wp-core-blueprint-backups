<?php
declare(strict_types=1);

namespace CB\Backups\Restore;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class StagedVerifier {
	private const TIME_BUDGET_SECONDS = 1.25;
	private const MAX_ENTRIES_PER_TICK = 50;

	/**
	 * Re-verify extracted payloads before any live filesystem mutation. This
	 * protects the restore boundary from partial/corrupt staging writes even
	 * after the source archive itself has already passed SHA-256 verification.
	 *
	 * @param array<string,mixed> $meta
	 * @return array{done:bool,progress:int,meta:array<string,mixed>}
	 */
	public static function tick( string $staged, string $index_file, array $meta ): array {
		if ( ! is_dir( $staged ) || ! is_file( $index_file ) ) {
			throw new RuntimeException( 'Staged restore verification state is incomplete.' );
		}
		$handle = fopen( $index_file, 'rb' );
		if ( false === $handle ) {
			throw new RuntimeException( 'Restore checksum work index could not be opened for staged verification.' );
		}
		$cursor = max( 0, (int) ( $meta['stage_verify_cursor'] ?? 0 ) );
		if ( $cursor > 0 && 0 !== fseek( $handle, $cursor ) ) {
			fclose( $handle );
			throw new RuntimeException( 'Staged restore verification cursor could not be restored.' );
		}

		$root = trailingslashit( wp_normalize_path( $staged ) );
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
				$target = wp_normalize_path( $staged . '/' . $path );
				if ( ! str_starts_with( $target, $root ) || ! is_file( $target ) || is_link( $target ) ) {
					throw new RuntimeException( sprintf( 'Staged restore payload is missing or unsafe: %s', $path ) );
				}
				clearstatcache( true, $target );
				if ( (int) filesize( $target ) !== $size ) {
					throw new RuntimeException( sprintf( 'Staged restore payload size verification failed for %s.', $path ) );
				}
				$have = hash_file( 'sha256', $target );
				if ( ! is_string( $have ) || ! hash_equals( $want, $have ) ) {
					throw new RuntimeException( sprintf( 'Staged restore checksum verification failed for %s.', $path ) );
				}
				++$processed;
				$meta['stage_verify_done'] = (int) ( $meta['stage_verify_done'] ?? 0 ) + 1;
				$meta['stage_verify_current_file'] = $path;
			}
			$position = ftell( $handle );
			$meta['stage_verify_cursor'] = false === $position ? $cursor : (int) $position;
			$done = feof( $handle );
		} finally {
			fclose( $handle );
		}

		$total = max( 1, (int) ( $meta['restore_verify_total'] ?? 1 ) );
		$progress = (int) floor( min( 1, (int) ( $meta['stage_verify_done'] ?? 0 ) / $total ) * 100 );
		if ( $done ) {
			$meta['stage_verify_current_file'] = '';
			$meta['stage_payloads_verified'] = (int) ( $meta['stage_verify_done'] ?? 0 );
		}
		return [ 'done' => $done, 'progress' => $progress, 'meta' => $meta ];
	}
}
