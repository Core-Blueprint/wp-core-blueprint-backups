<?php
declare(strict_types=1);

namespace CB\Backups\Backup;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Backup processing uses bounded native streams/atomic filesystem primitives; WP_Filesystem is not suitable for these server-owned jobs.

final class ChecksumFile {
	/** @param array<string,mixed> $record */
	public static function append( string $jsonl, array $record ): void {
		self::append_many( $jsonl, [ $record ] );
	}

	/** @param array<int,array<string,mixed>> $records */
	public static function append_many( string $jsonl, array $records ): int {
		$handle = fopen( $jsonl, 'ab' );
		if ( false === $handle ) {
			throw new RuntimeException( 'Backup checksum index could not be opened.' );
		}
		$locked = false;
		try {
			$locked = flock( $handle, LOCK_EX );
			if ( ! $locked ) {
				throw new RuntimeException( 'Backup checksum index could not be locked.' );
			}
			foreach ( $records as $record ) {
				$encoded = wp_json_encode( $record, JSON_UNESCAPED_SLASHES );
				if ( ! is_string( $encoded ) ) {
					throw new RuntimeException( 'Backup checksum record could not be encoded.' );
				}
				self::write_all( $handle, $encoded . "\n" );
			}
			if ( ! fflush( $handle ) ) {
				throw new RuntimeException( 'Backup checksum index could not be flushed.' );
			}
			// In append mode ("ab"), ftell() reports the handle-local write
			// position rather than the absolute end-of-file offset. Recovery
			// checkpoints must use the actual file size or a later worker could
			// truncate the JSONL index in the middle of an existing record.
			$stat = fstat( $handle );
			if ( ! is_array( $stat ) || ! isset( $stat['size'] ) ) {
				throw new RuntimeException( 'Backup checksum checkpoint could not be read.' );
			}
			return max( 0, (int) $stat['size'] );
		} finally {
			if ( $locked ) {
				flock( $handle, LOCK_UN );
			}
			fclose( $handle );
		}
	}

	/**
	 * Convert the resumable JSONL work index into the portable JSON array that
	 * lives inside .cbbackup without loading every checksum into PHP memory.
	 */
	public static function build_portable_json( string $jsonl, string $target ): int {
		$input = fopen( $jsonl, 'rb' );
		$output = fopen( $target, 'wb' );
		if ( false === $input || false === $output ) {
			foreach ( [ $input, $output ] as $handle ) {
				if ( is_resource( $handle ) ) {
					fclose( $handle );
				}
			}
			throw new RuntimeException( 'Backup checksum metadata could not be finalized.' );
		}

		$count = 0;
		$line_number = 0;
		try {
			self::write_all( $output, "[\n" );
			while ( false !== ( $line = fgets( $input ) ) ) {
				++$line_number;
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				$decoded = json_decode( $line, true );
				if ( ! is_array( $decoded ) ) {
					throw new RuntimeException( sprintf( 'Backup checksum work index contains invalid JSON at line %d.', $line_number ) );
				}
				if ( $count > 0 ) {
					self::write_all( $output, ",\n" );
				}
				self::write_all( $output, $line );
				++$count;
			}
			self::write_all( $output, "\n]\n" );
			if ( ! fflush( $output ) ) {
				throw new RuntimeException( 'Backup checksum metadata could not be flushed.' );
			}
		} finally {
			fclose( $input );
			fclose( $output );
		}
		return $count;
	}

	/** @param resource $handle */
	private static function write_all( $handle, string $data ): void {
		$length  = strlen( $data );
		$written = 0;
		while ( $written < $length ) {
			$result = fwrite( $handle, substr( $data, $written ) );
			if ( false === $result || 0 === $result ) {
				throw new RuntimeException( 'Backup checksum index could not be written completely.' );
			}
			$written += $result;
		}
	}

}
