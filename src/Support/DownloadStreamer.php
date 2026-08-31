<?php
declare(strict_types=1);

namespace CB\Backups\Support;

use RuntimeException;

final class DownloadStreamer {
	private const DEFAULT_CHUNK_BYTES = 1048576;

	/** @param resource $stream */
	public static function send( $stream, string $filename, string $content_type, ?int $content_length = null ): int {
		if ( ! is_resource( $stream ) ) {
			throw new RuntimeException( 'Download stream is not available.' );
		}
		if ( headers_sent( $file, $line ) ) {
			throw new RuntimeException( sprintf( 'Backup download headers were already sent at %s:%d.', $file, $line ) );
		}

		self::clear_output_buffers();
		if ( function_exists( 'session_status' ) && PHP_SESSION_ACTIVE === session_status() ) {
			session_write_close();
		}
		@ini_set( 'zlib.output_compression', '0' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		header_remove( 'Content-Encoding' );
		nocache_headers();
		header( 'X-Content-Type-Options: nosniff' );
		header( 'X-Accel-Buffering: no' );
		header( 'Accept-Ranges: none' );
		header( 'Content-Type: ' . $content_type );
		header( 'Content-Disposition: attachment; filename="' . self::safe_filename( $filename ) . '"' );
		if ( null !== $content_length && $content_length >= 0 ) {
			header( 'Content-Length: ' . (string) $content_length );
		}

		$chunk_size = (int) apply_filters( 'cb_backups_download_chunk_bytes', self::DEFAULT_CHUNK_BYTES );
		$chunk_size = max( 65536, min( 8388608, $chunk_size ) );
		return self::pump( $stream, static function ( string $chunk ): void {
			echo $chunk; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Raw authenticated file download.
			flush();
		}, $chunk_size );
	}

	/**
	 * Pump a stream through a bounded buffer. Public for deterministic runtime tests.
	 *
	 * @param resource         $stream
	 * @param callable(string):void $writer
	 */
	public static function pump( $stream, callable $writer, int $chunk_size = self::DEFAULT_CHUNK_BYTES ): int {
		if ( ! is_resource( $stream ) ) {
			throw new RuntimeException( 'Download stream is not available.' );
		}
		$chunk_size = max( 65536, min( 8388608, $chunk_size ) );
		$total = 0;
		while ( ! feof( $stream ) ) {
			$chunk = fread( $stream, $chunk_size );
			if ( false === $chunk ) {
				throw new RuntimeException( 'Backup download stream could not be read.' );
			}
			if ( '' === $chunk ) {
				if ( feof( $stream ) ) {
					break;
				}
				continue;
			}
			$writer( $chunk );
			$total += strlen( $chunk );
			if ( connection_aborted() ) {
				break;
			}
		}
		return $total;
	}

	private static function clear_output_buffers(): void {
		while ( ob_get_level() > 0 ) {
			$level = ob_get_level();
			@ob_end_clean(); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ob_get_level() >= $level ) {
				break;
			}
		}
	}

	private static function safe_filename( string $filename ): string {
		$filename = sanitize_file_name( $filename );
		return str_replace( [ '"', "\r", "\n" ], '', $filename );
	}
}
