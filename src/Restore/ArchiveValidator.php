<?php
declare(strict_types=1);

namespace CB\Backups\Restore;

use CB\Backups\DB\Schema;
use RuntimeException;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

final class ArchiveValidator {
	/** @return array<string,mixed> */
	public static function validate( string $archive_path, bool $verify_payloads = true ): array {
		if ( ! is_file( $archive_path ) || ! is_readable( $archive_path ) ) {
			throw new RuntimeException( 'Backup archive is not readable.' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive_path, ZipArchive::CHECKCONS ) ) {
			throw new RuntimeException( 'Backup archive could not be opened.' );
		}
		try {
			$entries = self::validate_entry_index( $zip );
			$manifest_raw = $zip->getFromName( 'manifest.json' );
			if ( false === $manifest_raw || false === $zip->locateName( 'checksums.json' ) ) {
				throw new RuntimeException( 'Backup manifest or checksums are missing.' );
			}
			$manifest = json_decode( $manifest_raw, true );
			if ( ! is_array( $manifest ) ) {
				throw new RuntimeException( 'Backup manifest is invalid JSON.' );
			}
			self::assert_manifest( $manifest );
			self::assert_required_payloads( $zip, $manifest, $entries );
			$count = self::walk_checksums( $zip, $verify_payloads );
			if ( (int) ( $manifest['checksums_count'] ?? -1 ) !== $count ) {
				throw new RuntimeException( 'Backup checksum count does not match the manifest.' );
			}
			return $manifest;
		} finally {
			$zip->close();
		}
	}

	/**
	 * Export the portable checksum array to a line-oriented work index without
	 * loading it into memory. Used by the resumable restore verifier.
	 */
	public static function export_checksum_index( string $archive_path, string $target ): int {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive_path, ZipArchive::CHECKCONS ) ) {
			throw new RuntimeException( 'Backup archive could not be opened.' );
		}
		$out = fopen( $target, 'wb' );
		if ( false === $out ) {
			$zip->close();
			throw new RuntimeException( 'Restore checksum work index could not be created.' );
		}
		$count = 0;
		$locked = false;
		try {
			$locked = flock( $out, LOCK_EX );
			if ( ! $locked ) {
				throw new RuntimeException( 'Restore checksum work index could not be locked.' );
			}
			$count = self::walk_checksums( $zip, false, static function ( array $entry ) use ( $out ): void {
				$encoded = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES );
				if ( ! is_string( $encoded ) ) {
					throw new RuntimeException( 'Restore checksum record could not be encoded.' );
				}
				self::write_all( $out, $encoded . "\n" );
			} );
			if ( ! fflush( $out ) ) {
				throw new RuntimeException( 'Restore checksum work index could not be flushed.' );
			}
		} finally {
			if ( $locked ) {
				flock( $out, LOCK_UN );
			}
			fclose( $out );
			$zip->close();
		}
		return $count;
	}

	public static function estimate_uncompressed_bytes( string $archive_path ): int {
		if ( ! is_file( $archive_path ) || ! is_readable( $archive_path ) ) {
			throw new RuntimeException( 'Backup archive is not readable.' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive_path ) ) {
			throw new RuntimeException( 'Backup archive could not be opened.' );
		}
		try {
			self::validate_entry_index( $zip );
			$total = 0;
			for ( $i = 0; $i < $zip->numFiles; ++$i ) {
				$stat = $zip->statIndex( $i );
				if ( ! is_array( $stat ) || ! isset( $stat['size'] ) ) {
					throw new RuntimeException( 'Backup archive contains an unreadable entry.' );
				}
				$size = max( 0, (int) $stat['size'] );
				if ( $total > PHP_INT_MAX - $size ) {
					throw new RuntimeException( 'Backup archive is too large to inspect safely on this platform.' );
				}
				$total += $size;
			}
			return $total;
		} finally {
			$zip->close();
		}
	}

	/** @return array<string,true> */
	private static function validate_entry_index( ZipArchive $zip ): array {
		$entries = [];
		$portable = [];
		$max_entries = max( 1000, (int) apply_filters( 'cb_backups_max_archive_entries', 500000 ) );
		if ( $zip->numFiles > $max_entries ) {
			throw new RuntimeException( 'Backup archive contains too many entries.' );
		}
		for ( $i = 0; $i < $zip->numFiles; ++$i ) {
			$name = str_replace( '\\', '/', (string) $zip->getNameIndex( $i ) );
			self::assert_safe_entry( $name );
			$key = rtrim( strtolower( $name ), '/' );
			if ( isset( $entries[ $name ] ) || isset( $portable[ $key ] ) ) {
				throw new RuntimeException( sprintf( 'Backup archive contains a duplicate path: %s', $name ) );
			}
			$entries[ $name ] = true;
			$portable[ $key ] = true;
		}
		return $entries;
	}

	/** @param array<string,mixed> $manifest @param array<string,true> $entries */
	private static function assert_required_payloads( ZipArchive $zip, array $manifest, array $entries ): void {
		foreach ( [ 'manifest.json', 'checksums.json', 'database/database.sql', 'site/environment.json' ] as $required ) {
			if ( ! isset( $entries[ $required ] ) ) {
				throw new RuntimeException( sprintf( 'Required backup payload is missing: %s', $required ) );
			}
		}

		$type = (string) ( $manifest['backup_type'] ?? '' );
		$components = is_array( $manifest['components'] ?? null ) ? $manifest['components'] : [];
		if ( true !== ( $components['database'] ?? null ) ) {
			throw new RuntimeException( 'Backup manifest does not declare the required database component.' );
		}
		$filesystem = true === ( $components['filesystem'] ?? null );
		if ( ( 'website' === $type ) !== $filesystem ) {
			throw new RuntimeException( 'Backup component declaration does not match its backup type.' );
		}
		$file_entries = 0;
		$file_bytes   = 0;
		$actual_tops  = [];
		foreach ( array_keys( $entries ) as $entry ) {
			if ( ! str_starts_with( $entry, 'files/wp-content/' ) || str_ends_with( $entry, '/' ) ) {
				continue;
			}
			++$file_entries;
			$stat = $zip->statName( $entry );
			if ( ! is_array( $stat ) || ! isset( $stat['size'] ) ) {
				throw new RuntimeException( sprintf( 'Backup filesystem payload metadata is unreadable: %s', $entry ) );
			}
			$file_bytes += max( 0, (int) $stat['size'] );
			$relative = substr( $entry, strlen( 'files/wp-content/' ) );
			$top = strtok( $relative, '/' );
			if ( is_string( $top ) && '' !== $top ) {
				$actual_tops[ $top ] = true;
			}
		}
		if ( 'database' === $type && $file_entries > 0 ) {
			throw new RuntimeException( 'Database-only backup contains an unexpected filesystem payload.' );
		}
		if ( 'website' === $type ) {
			$filesystem_meta = is_array( $manifest['filesystem'] ?? null ) ? $manifest['filesystem'] : [];
			$declared_files = isset( $filesystem_meta['file_count'] ) ? max( 0, (int) $filesystem_meta['file_count'] ) : null;
			$declared_bytes = isset( $filesystem_meta['bytes'] ) ? max( 0, (int) $filesystem_meta['bytes'] ) : null;
			if ( null !== $declared_files && $declared_files !== $file_entries ) {
				throw new RuntimeException( 'Backup filesystem payload count does not match the manifest.' );
			}
			if ( null !== $declared_bytes && $declared_bytes !== $file_bytes ) {
				throw new RuntimeException( 'Backup filesystem payload bytes do not match the manifest.' );
			}
			$declared_tops = isset( $filesystem_meta['top_levels'] ) && is_array( $filesystem_meta['top_levels'] ) ? array_map( 'strval', $filesystem_meta['top_levels'] ) : [];
			$actual_top_list = array_keys( $actual_tops );
			sort( $declared_tops, SORT_STRING );
			sort( $actual_top_list, SORT_STRING );
			if ( $declared_tops !== $actual_top_list ) {
				throw new RuntimeException( 'Backup wp-content top-level inventory does not match the filesystem payload.' );
			}
		}

		$environment_raw = $zip->getFromName( 'site/environment.json' );
		$environment = false !== $environment_raw ? json_decode( $environment_raw, true ) : null;
		if ( ! is_array( $environment ) ) {
			throw new RuntimeException( 'Backup environment metadata is invalid JSON.' );
		}
		$site = is_array( $manifest['site'] ?? null ) ? $manifest['site'] : [];
		foreach ( [ 'home_url', 'site_url', 'table_prefix' ] as $key ) {
			if ( isset( $environment[ $key ] ) && (string) $environment[ $key ] !== (string) ( $site[ $key ] ?? '' ) ) {
				throw new RuntimeException( 'Backup environment metadata does not match the manifest.' );
			}
		}
	}

	public static function assert_safe_entry( string $name ): void {
		$name = str_replace( '\\', '/', $name );
		if ( '' === $name || str_contains( $name, "\0" ) || str_starts_with( $name, '/' ) || preg_match( '#^[A-Za-z]:/#', $name ) ) {
			throw new RuntimeException( 'Unsafe path found inside backup archive.' );
		}
		$segments = explode( '/', rtrim( $name, '/' ) );
		foreach ( $segments as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				throw new RuntimeException( 'Unsafe path found inside backup archive.' );
			}
		}
		$allowed = [ 'manifest.json', 'checksums.json' ];
		if ( in_array( $name, $allowed, true ) ) {
			return;
		}
		if ( ! str_starts_with( $name, 'database/' ) && ! str_starts_with( $name, 'files/wp-content/' ) && ! str_starts_with( $name, 'site/' ) ) {
			throw new RuntimeException( sprintf( 'Unsupported backup archive entry: %s', $name ) );
		}
	}

	/** @param array<string,mixed> $manifest */
	private static function assert_manifest( array $manifest ): void {
		if ( 'core-blueprint-backup' !== ( $manifest['format'] ?? null ) || 1 !== (int) ( $manifest['schema_version'] ?? 0 ) ) {
			throw new RuntimeException( 'Unsupported Core Blueprint backup format.' );
		}
		$type = (string) ( $manifest['backup_type'] ?? '' );
		if ( ! in_array( $type, [ 'database', 'website' ], true ) ) {
			throw new RuntimeException( 'Backup type is invalid.' );
		}
		$site = is_array( $manifest['site'] ?? null ) ? $manifest['site'] : [];
		if ( ! empty( $site['multisite'] ) || is_multisite() ) {
			throw new RuntimeException( 'Backup format v1 does not support multisite restore.' );
		}
		global $wpdb;
		if ( (string) ( $site['table_prefix'] ?? '' ) !== (string) $wpdb->prefix ) {
			throw new RuntimeException( 'This backup uses a different WordPress table prefix. Migration is not supported in format v1.' );
		}
		if ( untrailingslashit( (string) ( $site['home_url'] ?? '' ) ) !== untrailingslashit( home_url( '/' ) ) ) {
			throw new RuntimeException( 'This backup belongs to a different site URL. Migration/URL replacement is not supported in format v1.' );
		}
		if ( untrailingslashit( (string) ( $site['site_url'] ?? '' ) ) !== untrailingslashit( site_url( '/' ) ) ) {
			throw new RuntimeException( 'This backup belongs to a different WordPress installation URL. Migration/URL replacement is not supported in format v1.' );
		}

		$database = is_array( $manifest['database'] ?? null ) ? $manifest['database'] : [];
		$tables   = isset( $database['tables'] ) && is_array( $database['tables'] ) ? array_values( $database['tables'] ) : [];
		if ( ! $tables ) {
			throw new RuntimeException( 'Backup manifest does not contain a database table inventory.' );
		}
		foreach ( $tables as $table ) {
			$table = (string) $table;
			if ( '' === $table || ! str_starts_with( $table, $wpdb->prefix ) || ! preg_match( '/^[A-Za-z0-9_$-]+$/', $table ) ) {
				throw new RuntimeException( 'Backup manifest contains an unsafe database table name.' );
			}
			if ( $table === Schema::table() ) {
				throw new RuntimeException( 'Backup manifest may not include the operational backup job table.' );
			}
		}

		$filesystem = is_array( $manifest['filesystem'] ?? null ) ? $manifest['filesystem'] : [];
		$top_levels = isset( $filesystem['top_levels'] ) && is_array( $filesystem['top_levels'] ) ? $filesystem['top_levels'] : [];
		foreach ( $top_levels as $top ) {
			$top = (string) $top;
			if ( '' === $top || in_array( $top, [ '.', '..' ], true ) || str_contains( $top, '/' ) || str_contains( $top, '\\' ) || str_contains( $top, "\0" ) ) {
				throw new RuntimeException( 'Backup manifest contains an unsafe wp-content top-level path.' );
			}
		}
	}

	/**
	 * Stream checksum records from checksums.json. The parser supports both the
	 * compact one-object-per-line rc6 representation and older pretty-printed
	 * format-v1 archives.
	 *
	 * @param callable(array<string,mixed>):void|null $consumer
	 */
	private static function walk_checksums( ZipArchive $zip, bool $verify_payloads, ?callable $consumer = null ): int {
		$stream = $zip->getStream( 'checksums.json' );
		if ( false === $stream ) {
			throw new RuntimeException( 'Backup checksum metadata is missing.' );
		}
		$count = 0;
		$covered = [];
		$buffer = '';
		$started = false;
		$ended = false;
		try {
			while ( false !== ( $line = fgets( $stream ) ) ) {
				$trimmed = trim( $line );
				if ( '' === $trimmed ) {
					continue;
				}
				if ( ! $started ) {
					if ( '[' !== $trimmed ) {
						throw new RuntimeException( 'Backup checksum metadata is malformed.' );
					}
					$started = true;
					continue;
				}
				if ( ']' === $trimmed && '' === $buffer ) {
					$ended = true;
					break;
				}

				$buffer .= ( '' === $buffer ? '' : "\n" ) . $trimmed;
				$candidate = rtrim( trim( $buffer ), ',' );
				$entry = json_decode( $candidate, true );
				if ( ! is_array( $entry ) ) {
					continue;
				}
				$buffer = '';
				$path = (string) ( $entry['path'] ?? '' );
				$want = strtolower( (string) ( $entry['sha256'] ?? '' ) );
				$size = isset( $entry['size'] ) ? (int) $entry['size'] : -1;
				if ( '' === $path || ! preg_match( '/^[a-f0-9]{64}$/', $want ) || $size < 0 || isset( $covered[ $path ] ) ) {
					throw new RuntimeException( 'Backup checksum metadata is malformed.' );
				}
				self::assert_safe_entry( $path );
				$covered[ $path ] = true;
				if ( $verify_payloads ) {
					$stat = $zip->statName( $path );
					if ( false === $stat || ! isset( $stat['size'] ) || (int) $stat['size'] !== $size ) {
						throw new RuntimeException( sprintf( 'Backup payload size verification failed for %s.', $path ) );
					}
					$payload = $zip->getStream( $path );
					if ( false === $payload ) {
						throw new RuntimeException( sprintf( 'Backup payload is missing: %s', $path ) );
					}
					$context = hash_init( 'sha256' );
					hash_update_stream( $context, $payload );
					fclose( $payload );
					if ( ! hash_equals( $want, hash_final( $context ) ) ) {
						throw new RuntimeException( sprintf( 'Checksum verification failed for %s.', $path ) );
					}
				}
				if ( null !== $consumer ) {
					$consumer( [ 'path' => $path, 'sha256' => $want, 'size' => $size ] );
				}
				++$count;
			}
		} finally {
			fclose( $stream );
		}
		if ( ! $started || ! $ended || '' !== trim( $buffer ) ) {
			throw new RuntimeException( 'Backup checksum metadata is malformed.' );
		}

		for ( $i = 0; $i < $zip->numFiles; ++$i ) {
			$name = (string) $zip->getNameIndex( $i );
			if ( '' === $name || str_ends_with( $name, '/' ) || in_array( $name, [ 'manifest.json', 'checksums.json' ], true ) ) {
				continue;
			}
			if ( ! isset( $covered[ $name ] ) ) {
				throw new RuntimeException( sprintf( 'Backup payload is not covered by checksums: %s.', $name ) );
			}
		}
		if ( ! isset( $covered['database/database.sql'] ) ) {
			throw new RuntimeException( 'Database export is not covered by the backup checksums.' );
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
				throw new RuntimeException( 'Restore checksum work index could not be written completely.' );
			}
			$written += $result;
		}
	}

}
