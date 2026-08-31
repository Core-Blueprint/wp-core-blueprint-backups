<?php
declare(strict_types=1);

namespace CB\Backups\Import;

use CB\Backups\Restore\ArchiveValidator;
use CB\Backups\Storage\LocalStorage;
use RuntimeException;

final class ChunkedUploader {
	private const MAX_CHUNK_BYTES = 4194304;
	private const MIN_CHUNK_BYTES = 65536;

	/** @return array<string,mixed> */
	public static function initialise( string $name, int $size, int $last_modified = 0, string $upload_id = '' ): array {
		LocalStorage::ensure();
		$name = sanitize_file_name( $name );
		if ( '' === $name || ! str_ends_with( strtolower( $name ), '.cbbackup' ) ) {
			throw new RuntimeException( 'Only .cbbackup files can be imported.' );
		}
		if ( $size <= 0 ) {
			throw new RuntimeException( 'The selected backup file is empty.' );
		}
		$user_id = get_current_user_id();
		if ( $user_id <= 0 ) {
			throw new RuntimeException( 'A signed-in administrator is required to import backups.' );
		}

		$state = null;
		if ( '' !== $upload_id ) {
			$state = self::load_state( $upload_id, $user_id );
			if ( $state && ! self::matches( $state, $name, $size, $last_modified ) ) {
				throw new RuntimeException( 'The selected file does not match the resumable import session.' );
			}
		}
		if ( ! $state ) {
			$state = self::find_matching( $user_id, $name, $size, $last_modified );
		}

		if ( ! $state ) {
			self::assert_disk_space( $size );
			$upload_id = wp_generate_uuid4();
			$dir = LocalStorage::import_upload_dir( $upload_id, true );
			$part = $dir . '/payload.part';
			$handle = fopen( $part, 'xb' );
			if ( false === $handle ) {
				throw new RuntimeException( 'Import staging file could not be created.' );
			}
			fclose( $handle );
			$now = time();
			$state = [
				'upload_id'       => $upload_id,
				'user_id'         => $user_id,
				'original_name'   => $name,
				'expected_size'   => $size,
				'last_modified'   => max( 0, $last_modified ),
				'uploaded_bytes'  => 0,
				'chunk_size'      => self::recommended_chunk_size(),
				'status'          => 'uploading',
				'created_at'      => $now,
				'updated_at'      => $now,
			];
			self::write_state( $upload_id, $state );
		}

		return self::public_state( $state );
	}

	/** @return array<string,mixed> */
	public static function append_chunk( string $upload_id, int $offset, string $chunk_path ): array {
		$state = self::require_state( $upload_id, get_current_user_id() );
		if ( 'uploading' !== (string) ( $state['status'] ?? '' ) ) {
			throw new RuntimeException( 'This import session is no longer accepting chunks.' );
		}
		if ( ! is_file( $chunk_path ) || ! is_readable( $chunk_path ) ) {
			throw new RuntimeException( 'Import chunk is not readable.' );
		}
		$chunk_bytes = filesize( $chunk_path );
		if ( false === $chunk_bytes || $chunk_bytes <= 0 ) {
			throw new RuntimeException( 'Import chunk is empty.' );
		}
		$max_allowed = max( self::MIN_CHUNK_BYTES, (int) ( $state['chunk_size'] ?? self::recommended_chunk_size() ) ) + 65536;
		if ( $chunk_bytes > $max_allowed ) {
			throw new RuntimeException( 'Import chunk exceeds the negotiated chunk size.' );
		}

		$part = LocalStorage::import_upload_part_path( $upload_id );
		$out = fopen( $part, 'c+b' );
		$in  = fopen( $chunk_path, 'rb' );
		if ( false === $out || false === $in ) {
			if ( is_resource( $out ) ) fclose( $out );
			if ( is_resource( $in ) ) fclose( $in );
			throw new RuntimeException( 'Import chunk streams could not be opened.' );
		}
		$locked = false;
		try {
			$locked = flock( $out, LOCK_EX );
			if ( ! $locked ) {
				throw new RuntimeException( 'Import staging file could not be locked.' );
			}
			$stat = fstat( $out );
			$current = is_array( $stat ) ? (int) ( $stat['size'] ?? 0 ) : 0;
			if ( $offset !== $current ) {
				$state['uploaded_bytes'] = $current;
				$state['updated_at'] = time();
				self::write_state( $upload_id, $state );
				return self::public_state( $state );
			}
			$expected = (int) ( $state['expected_size'] ?? 0 );
			if ( $current + (int) $chunk_bytes > $expected ) {
				throw new RuntimeException( 'Import chunk would exceed the expected backup size.' );
			}
			if ( 0 !== fseek( $out, $current ) ) {
				throw new RuntimeException( 'Import staging cursor could not be positioned.' );
			}
			$copied = stream_copy_to_stream( $in, $out, (int) $chunk_bytes );
			if ( false === $copied || (int) $copied !== (int) $chunk_bytes ) {
				throw new RuntimeException( 'Import chunk could not be written completely.' );
			}
			if ( ! fflush( $out ) ) {
				throw new RuntimeException( 'Import chunk could not be flushed to storage.' );
			}
			if ( function_exists( 'fsync' ) ) {
				@fsync( $out ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
			$stat = fstat( $out );
			$current = is_array( $stat ) ? (int) ( $stat['size'] ?? 0 ) : $current + (int) $chunk_bytes;
			$state['uploaded_bytes'] = $current;
			$state['updated_at'] = time();
			self::write_state( $upload_id, $state );
			return self::public_state( $state );
		} finally {
			if ( $locked ) flock( $out, LOCK_UN );
			fclose( $in );
			fclose( $out );
		}
	}

	/** @return array<string,mixed> */
	public static function finish( string $upload_id ): array {
		$state = self::require_state( $upload_id, get_current_user_id() );
		if ( 'prepared' === (string) ( $state['status'] ?? '' ) && is_array( $state['prepared_meta'] ?? null ) ) {
			$prepared = $state['prepared_meta'];
			$archive = LocalStorage::import_path( (string) ( $prepared['archive_name'] ?? '' ) );
			if ( is_file( $archive ) ) {
				return $prepared;
			}
			throw new RuntimeException( 'Prepared import metadata exists but the staged archive is missing.' );
		}
		$part = LocalStorage::import_upload_part_path( $upload_id );
		$lock = fopen( $part, 'c+b' );
		if ( false === $lock ) {
			throw new RuntimeException( 'Import staging file could not be opened for finalization.' );
		}
		$locked = false;
		try {
			$locked = flock( $lock, LOCK_EX );
			if ( ! $locked ) {
				throw new RuntimeException( 'Import staging file could not be locked for finalization.' );
			}
			$stat = fstat( $lock );
			$actual = is_array( $stat ) ? (int) ( $stat['size'] ?? -1 ) : -1;
			$expected = (int) ( $state['expected_size'] ?? 0 );
			if ( $actual !== $expected ) {
				throw new RuntimeException( 'Import is incomplete and cannot be prepared yet.' );
			}
			$archive_name = 'import-' . $upload_id . '.cbbackup';
			$target = LocalStorage::import_path( $archive_name );
			if ( is_file( $target ) || ! rename( $part, $target ) ) {
				throw new RuntimeException( 'Completed import could not be moved into private restore storage.' );
			}
		} finally {
			if ( $locked ) flock( $lock, LOCK_UN );
			fclose( $lock );
		}
		try {
			$manifest = ArchiveValidator::validate( $target, false );
			$database = is_array( $manifest['database'] ?? null ) ? $manifest['database'] : [];
			$filesystem = is_array( $manifest['filesystem'] ?? null ) ? $manifest['filesystem'] : [];
			$meta = [
				'archive_name'      => $archive_name,
				'original_name'     => (string) ( $state['original_name'] ?? '' ),
				'created_timestamp' => time(),
				'backup_type'       => (string) ( $manifest['backup_type'] ?? 'database' ),
				'database_rows'     => (int) ( $database['row_count'] ?? 0 ),
				'database_tables'   => isset( $database['tables'] ) && is_array( $database['tables'] ) ? count( $database['tables'] ) : 0,
				'files'             => (int) ( $filesystem['file_count'] ?? 0 ),
				'filesystem_bytes'  => (int) ( $filesystem['bytes'] ?? 0 ),
				'site_url'          => (string) ( $manifest['site']['site_url'] ?? '' ),
				'size'              => $expected,
				'prepared'          => true,
			];
			LocalStorage::write_import_meta( $archive_name, $meta );
			$state['status'] = 'prepared';
			$state['uploaded_bytes'] = $expected;
			$state['prepared_meta'] = $meta;
			$state['updated_at'] = time();
			self::write_state( $upload_id, $state );
			return $meta;
		} catch ( \Throwable $e ) {
			@unlink( $target ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $target . '.json' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			LocalStorage::remove_tree( LocalStorage::import_upload_dir( $upload_id, false ) );
			throw $e;
		}
	}

	public static function abort( string $upload_id ): void {
		self::require_state( $upload_id, get_current_user_id() );
		$part = LocalStorage::import_upload_part_path( $upload_id );
		$lock = is_file( $part ) ? fopen( $part, 'c+b' ) : false;
		if ( is_resource( $lock ) ) {
			$locked = flock( $lock, LOCK_EX );
			try {
				LocalStorage::remove_tree( LocalStorage::import_upload_dir( $upload_id, false ) );
			} finally {
				if ( $locked ) flock( $lock, LOCK_UN );
				fclose( $lock );
			}
			return;
		}
		LocalStorage::remove_tree( LocalStorage::import_upload_dir( $upload_id, false ) );
	}

	/** @return array<string,mixed>|null */
	private static function find_matching( int $user_id, string $name, int $size, int $last_modified ): ?array {
		$dirs = glob( LocalStorage::import_upload_root() . '/*', GLOB_ONLYDIR ) ?: [];
		foreach ( $dirs as $dir ) {
			$upload_id = basename( $dir );
			$state = self::load_state( $upload_id, $user_id );
			if ( ! $state || ! self::matches( $state, $name, $size, $last_modified ) ) {
				continue;
			}
			$status = (string) ( $state['status'] ?? '' );
			if ( 'prepared' === $status && is_array( $state['prepared_meta'] ?? null ) ) {
				$archive = LocalStorage::import_path( (string) ( $state['prepared_meta']['archive_name'] ?? '' ) );
				if ( is_file( $archive ) ) {
					$state['uploaded_bytes'] = $size;
					return $state;
				}
			}
			if ( 'uploading' === $status ) {
				$part = LocalStorage::import_upload_part_path( $upload_id );
				$actual = is_file( $part ) ? filesize( $part ) : 0;
				$state['uploaded_bytes'] = false === $actual ? 0 : min( $size, (int) $actual );
				return $state;
			}
		}
		return null;
	}

	/** @param array<string,mixed> $state */
	private static function matches( array $state, string $name, int $size, int $last_modified ): bool {
		return (string) ( $state['original_name'] ?? '' ) === $name
			&& (int) ( $state['expected_size'] ?? 0 ) === $size
			&& (int) ( $state['last_modified'] ?? 0 ) === max( 0, $last_modified );
	}

	/** @return array<string,mixed> */
	private static function require_state( string $upload_id, int $user_id ): array {
		$state = self::load_state( $upload_id, $user_id );
		if ( ! $state ) {
			throw new RuntimeException( 'Import upload session was not found or has expired.' );
		}
		return $state;
	}

	/** @return array<string,mixed>|null */
	private static function load_state( string $upload_id, int $user_id ): ?array {
		if ( ! self::valid_upload_id( $upload_id ) ) return null;
		$path = LocalStorage::import_upload_state_path( $upload_id );
		if ( ! is_file( $path ) ) return null;
		$decoded = json_decode( (string) file_get_contents( $path ), true );
		if ( ! is_array( $decoded ) || (int) ( $decoded['user_id'] ?? 0 ) !== $user_id || (string) ( $decoded['upload_id'] ?? '' ) !== $upload_id ) return null;
		return $decoded;
	}

	/** @param array<string,mixed> $state */
	private static function write_state( string $upload_id, array $state ): void {
		$dir = LocalStorage::import_upload_dir( $upload_id, true );
		$target = $dir . '/state.json';
		$temp = $dir . '/state-' . bin2hex( random_bytes( 5 ) ) . '.tmp';
		$json = wp_json_encode( $state, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) || false === file_put_contents( $temp, $json, LOCK_EX ) || ! rename( $temp, $target ) ) {
			@unlink( $temp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new RuntimeException( 'Import upload state could not be committed.' );
		}
	}

	private static function assert_disk_space( int $expected_size ): void {
		$free = disk_free_space( LocalStorage::base_path() );
		$reserve = max( 67108864, (int) ceil( $expected_size * 0.10 ) );
		if ( false !== $free && (int) $free < $expected_size + $reserve ) {
			throw new RuntimeException( 'Not enough free local disk space to stage this backup import.' );
		}
	}

	private static function recommended_chunk_size(): int {
		$limits = [];
		foreach ( [ 'upload_max_filesize', 'post_max_size' ] as $key ) {
			$value = self::ini_bytes( (string) ini_get( $key ) );
			if ( $value > 0 ) $limits[] = $value;
		}
		$limit = $limits ? min( $limits ) : 0;
		if ( $limit <= 0 ) return self::MAX_CHUNK_BYTES;
		return max( self::MIN_CHUNK_BYTES, min( self::MAX_CHUNK_BYTES, (int) floor( $limit * 0.75 ) ) );
	}

	private static function ini_bytes( string $value ): int {
		$value = trim( $value );
		if ( '' === $value || '-1' === $value ) return 0;
		$last = strtolower( substr( $value, -1 ) );
		$number = (float) $value;
		return match ( $last ) {
			'g' => (int) ( $number * 1073741824 ),
			'm' => (int) ( $number * 1048576 ),
			'k' => (int) ( $number * 1024 ),
			default => (int) $number,
		};
	}

	private static function valid_upload_id( string $upload_id ): bool {
		return 1 === preg_match( '/^[a-f0-9]{8}-[a-f0-9]{4}-[1-5][a-f0-9]{3}-[89ab][a-f0-9]{3}-[a-f0-9]{12}$/i', $upload_id );
	}

	/** @param array<string,mixed> $state @return array<string,mixed> */
	private static function public_state( array $state ): array {
		$expected = max( 1, (int) ( $state['expected_size'] ?? 1 ) );
		$uploaded = max( 0, min( $expected, (int) ( $state['uploaded_bytes'] ?? 0 ) ) );
		return [
			'upload_id'      => (string) ( $state['upload_id'] ?? '' ),
			'original_name'  => (string) ( $state['original_name'] ?? '' ),
			'expected_size'  => $expected,
			'uploaded_bytes' => $uploaded,
			'chunk_size'     => max( self::MIN_CHUNK_BYTES, (int) ( $state['chunk_size'] ?? self::recommended_chunk_size() ) ),
			'progress'       => (int) floor( min( 1, $uploaded / $expected ) * 100 ),
			'status'         => (string) ( $state['status'] ?? 'uploading' ),
			'archive_name'   => is_array( $state['prepared_meta'] ?? null ) ? (string) ( $state['prepared_meta']['archive_name'] ?? '' ) : '',
		];
	}
}
