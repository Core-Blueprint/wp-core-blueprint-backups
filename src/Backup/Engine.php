<?php
declare(strict_types=1);

namespace CB\Backups\Backup;

use CB\Backups\Jobs\Repository;
use CB\Backups\Restore\ArchiveValidator;
use CB\Backups\Schedule\Retention;
use CB\Backups\Schedule\Scheduler;
use CB\Backups\Storage\LocalStorage;
use CB\Backups\Support\Audit;
use CB\Backups\Support\SiteIdentity;
use RuntimeException;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

final class Engine {
	private const PACKAGE_TIME_BUDGET = 6.0;
	private const PACKAGE_FILE_BUDGET = 4000;
	private const PACKAGE_BYTE_BUDGET = 268435456; // 256 MiB per resumable package tick.
	private const VERIFY_TIME_BUDGET = 6.0;
	private const VERIFY_FILE_BUDGET = 2000;
	private const VERIFY_BYTE_BUDGET = 268435456; // 256 MiB of uncompressed payload per verify tick.

	/** @param array<string,mixed> $job */
	public static function tick( array $job ): void {
		$job_id = (string) $job['job_id'];
		$type   = (string) $job['backup_type'];
		$stage  = (string) $job['stage'];
		$meta   = is_array( $job['meta'] ?? null ) ? $job['meta'] : [];
		$work   = LocalStorage::work_dir( $job_id );

		if ( 'queued' === $stage ) {
			$meta['work_dir']          = $work;
			$meta['started_timestamp'] = (int) ( $meta['started_timestamp'] ?? time() );
			Repository::update( $job_id, [ 'status' => 'running', 'stage' => 'database', 'progress' => 2, 'meta' => $meta ] );
			return;
		}

		if ( 'database' === $stage ) {
			$result = DatabaseExporter::tick( $work, $meta );
			$meta   = $result['meta'];
			$scaled = 2 + (int) floor( $result['progress'] * ( 'website' === $type ? 0.28 : 0.78 ) );
			if ( $result['done'] ) {
				Repository::update( $job_id, [
					'stage'    => 'website' === $type ? 'inventory' : 'package',
					'progress' => 'website' === $type ? 30 : 80,
					'meta'     => $meta,
				] );
				return;
			}
			Repository::update( $job_id, [ 'progress' => $scaled, 'meta' => $meta ] );
			return;
		}

		if ( 'inventory' === $stage ) {
			$result = FilesystemInventory::tick( $work, $meta );
			$meta   = $result['meta'];
			$progress = 30 + (int) floor( min( 100, $result['progress'] ) * 0.04 );
			if ( $result['done'] ) {
				self::assert_backup_space( $meta );
				Repository::update( $job_id, [ 'stage' => 'package', 'progress' => 34, 'meta' => $meta ] );
				return;
			}
			Repository::update( $job_id, [ 'progress' => min( 33, $progress ), 'meta' => $meta ] );
			return;
		}

		if ( 'package' === $stage ) {
			$result = self::package_tick( $job, $work, $meta );
			Repository::update( $job_id, $result );
			return;
		}

		if ( 'verify' === $stage ) {
			$result = self::verify_tick( $job, $work, $meta );
			if ( ! $result['done'] ) {
				Repository::update( $job_id, [ 'progress' => $result['progress'], 'meta' => $result['meta'] ] );
				return;
			}
			self::complete_verified_backup( $job, $work, $result['meta'] );
			return;
		}

		throw new RuntimeException( sprintf( 'Unknown backup job stage: %s', $stage ) );
	}

	/** @param array<string,mixed> $meta */
	private static function assert_backup_space( array $meta ): void {
		$payload_bytes = max( 0, (int) ( $meta['files_bytes_total'] ?? 0 ) ) + max( 0, (int) ( $meta['db_export_bytes'] ?? 0 ) );
		$free = disk_free_space( LocalStorage::base_path() );
		if ( false === $free ) {
			return;
		}
		$reserve = max( 104857600, (int) ceil( $payload_bytes * 0.05 ) );
		$required = $payload_bytes + $reserve;
		if ( $free < $required ) {
			throw new RuntimeException( 'Full website backup requires more free local disk space for safe archive creation.' );
		}
	}

	/** @param array<string,mixed> $job @param array<string,mixed> $meta @return array<string,mixed> */
	private static function package_tick( array $job, string $work, array $meta ): array {
		$type         = (string) $job['backup_type'];
		$temp_archive = $work . '/backup.cbbackup.tmp';
		$checks_file  = $work . '/checksums.jsonl';
		$zip          = new ZipArchive();

		if ( empty( $meta['archive_initialized'] ) ) {
			if ( true !== $zip->open( $temp_archive, ZipArchive::CREATE | ZipArchive::OVERWRITE ) ) {
				throw new RuntimeException( 'Backup archive could not be created.' );
			}
			$sql_file = $work . '/database.sql';
			if ( ! is_file( $sql_file ) || ! $zip->addFile( $sql_file, 'database/database.sql' ) ) {
				$zip->close();
				throw new RuntimeException( 'Database export could not be added to the backup archive.' );
			}
			global $wpdb, $wp_version;
			$identity = SiteIdentity::current();
			$environment = [
				'home_url'        => $identity['home_url'],
				'site_url'        => $identity['site_url'],
				'content_dir'     => 'wp-content',
				'table_prefix'    => (string) $wpdb->prefix,
				'wordpress'       => (string) $wp_version,
				'php'             => PHP_VERSION,
				'database_server' => (string) $wpdb->db_version(),
				'multisite'       => is_multisite(),
				'generated_at'    => gmdate( 'c' ),
			];
			$environment_json = wp_json_encode( $environment, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
			if ( ! is_string( $environment_json ) || ! $zip->addFromString( 'site/environment.json', $environment_json ) ) {
				$zip->close();
				throw new RuntimeException( 'Backup environment metadata could not be added to the archive.' );
			}
			if ( ! $zip->close() ) {
				throw new RuntimeException( 'Backup archive could not persist its initial payloads.' );
			}

			if ( false === file_put_contents( $checks_file, '', LOCK_EX ) ) {
				throw new RuntimeException( 'Backup checksum index could not be initialized.' );
			}
			$sql_hash = hash_file( 'sha256', $sql_file );
			if ( ! is_string( $sql_hash ) || '' === $sql_hash ) {
				throw new RuntimeException( 'Database export checksum could not be calculated.' );
			}
			$checks_cursor = ChecksumFile::append_many( $checks_file, [
				[ 'path' => 'database/database.sql', 'sha256' => $sql_hash, 'size' => filesize( $sql_file ) ?: 0 ],
				[ 'path' => 'site/environment.json', 'sha256' => hash( 'sha256', $environment_json ), 'size' => strlen( $environment_json ) ],
			] );
			if ( $checks_cursor <= 0 ) {
				throw new RuntimeException( 'Backup checksum checkpoint could not be read.' );
			}
			$meta['checksums_cursor'] = $checks_cursor;
			$meta['archive_initialized'] = true;
			$meta['archive_bytes']       = filesize( $temp_archive ) ?: 0;
			$meta['checksums_count']     = 2;
			if ( 'database' === $type ) {
				return self::finalize( $job, $work, $meta, $temp_archive, $checks_file );
			}
			return [ 'progress' => 35, 'meta' => $meta ];
		}

		if ( 'website' === $type && empty( $meta['files_packaged'] ) ) {
			$inventory = $work . '/inventory.jsonl';
			$checksum_cursor = max( 0, (int) ( $meta['checksums_cursor'] ?? 0 ) );
			if ( $checksum_cursor > 0 ) {
				self::truncate_file( $checks_file, $checksum_cursor );
			}
			$handle = fopen( $inventory, 'rb' );
			if ( false === $handle ) {
				throw new RuntimeException( 'Backup filesystem inventory could not be opened.' );
			}
			$cursor = max( 0, (int) ( $meta['inventory_cursor'] ?? 0 ) );
			if ( $cursor > 0 && 0 !== fseek( $handle, $cursor ) ) {
				fclose( $handle );
				throw new RuntimeException( 'Backup filesystem inventory cursor could not be restored.' );
			}
			if ( true !== $zip->open( $temp_archive ) ) {
				fclose( $handle );
				throw new RuntimeException( 'Backup archive could not be reopened.' );
			}

			$processed = 0;
			$done      = false;
			$batch_started = microtime( true );
			$deadline  = $batch_started + self::bounded_time_budget( self::PACKAGE_TIME_BUDGET );
			$root      = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
			$checksum_records = [];
			$bytes_added = 0;
			$position = $cursor;
			$close_ok = false;
			try {
				while ( $processed < self::PACKAGE_FILE_BUDGET && microtime( true ) < $deadline && ( 0 === $processed || $bytes_added < self::PACKAGE_BYTE_BUDGET ) && false !== ( $line = fgets( $handle ) ) ) {
					if ( 0 === $processed % 32 ) {
						$current_job = Repository::get( (string) $job['job_id'] );
						if ( is_array( $current_job ) && 'cancelling' === (string) ( $current_job['status'] ?? '' ) ) {
							throw new RuntimeException( 'Backup cancellation requested.' );
						}
					}
					$line = trim( $line );
					if ( '' === $line ) {
						continue;
					}
					$record = json_decode( $line, true );
					$relative = is_array( $record ) && isset( $record['p'] ) ? base64_decode( (string) $record['p'], true ) : false;
					if ( false === $relative || '' === $relative || ! self::safe_relative_path( $relative ) ) {
						throw new RuntimeException( 'Backup filesystem inventory contains an invalid path.' );
					}
					$absolute = wp_normalize_path( $root . '/' . $relative );
					$expected_size = max( 0, (int) ( $record['s'] ?? 0 ) );
					$expected_mtime = max( 0, (int) ( $record['m'] ?? 0 ) );
					if ( ! is_file( $absolute ) || is_link( $absolute ) ) {
						throw new RuntimeException( sprintf( 'A wp-content file disappeared during backup: %s', $relative ) );
					}
					clearstatcache( true, $absolute );
					$size_before = max( 0, (int) filesize( $absolute ) );
					$mtime_before = max( 0, (int) filemtime( $absolute ) );
					if ( $size_before !== $expected_size || $mtime_before !== $expected_mtime ) {
						throw new RuntimeException( sprintf( 'A wp-content file changed during backup: %s. Start a new backup to capture a consistent snapshot.', $relative ) );
					}

					$meta['files_current_file'] = $relative;
					$archive_path = 'files/wp-content/' . str_replace( '\\', '/', $relative );
					// PHP 8+ addFile() overwrites an existing entry name by default, so a
					// replayed checkpoint does not need a locate/delete pass through the ZIP.
					if ( ! $zip->addFile( $absolute, $archive_path ) ) {
						throw new RuntimeException( sprintf( 'Could not add %s to the backup archive.', $relative ) );
					}
					self::apply_compression_policy( $zip, $archive_path, $relative );
					$hash = hash_file( 'sha256', $absolute );
					if ( ! is_string( $hash ) || '' === $hash ) {
						throw new RuntimeException( sprintf( 'Could not checksum %s during backup.', $relative ) );
					}
					clearstatcache( true, $absolute );
					if ( max( 0, (int) filesize( $absolute ) ) !== $size_before || max( 0, (int) filemtime( $absolute ) ) !== $mtime_before ) {
						throw new RuntimeException( sprintf( 'A wp-content file changed while it was being packaged: %s.', $relative ) );
					}
					$checksum_records[] = [ 'path' => $archive_path, 'sha256' => $hash, 'size' => $size_before ];
					++$processed;
					$bytes_added += $size_before;
				}
				$done = feof( $handle );
				$current = ftell( $handle );
				$position = false === $current ? $cursor : (int) $current;
			} finally {
				fclose( $handle );
				$close_ok = $zip->close();
			}
			if ( ! $close_ok ) {
				throw new RuntimeException( 'Backup archive could not persist the current filesystem chunk.' );
			}

			if ( $checksum_records ) {
				$new_checksum_cursor = ChecksumFile::append_many( $checks_file, $checksum_records );
				if ( $new_checksum_cursor <= 0 ) {
					throw new RuntimeException( 'Backup checksum checkpoint could not be recorded.' );
				}
				$meta['checksums_cursor'] = $new_checksum_cursor;
			}
			$meta['inventory_cursor'] = $position;
			$meta['files_done']       = (int) ( $meta['files_done'] ?? 0 ) + $processed;
			$meta['files_bytes_done'] = (int) ( $meta['files_bytes_done'] ?? 0 ) + $bytes_added;
			$meta['checksums_count']  = (int) ( $meta['checksums_count'] ?? 2 ) + $processed;
			$meta['archive_bytes']    = filesize( $temp_archive ) ?: 0;
			$batch_seconds = max( 0.001, microtime( true ) - $batch_started );
			$meta['package_last_batch_seconds'] = round( $batch_seconds, 3 );
			$meta['package_files_per_second']    = round( $processed / $batch_seconds, 2 );
			$meta['package_bytes_per_second']    = (int) round( $bytes_added / $batch_seconds );

			$bytes_total = max( 0, (int) ( $meta['files_bytes_total'] ?? 0 ) );
			$files_total = max( 1, (int) ( $meta['files_total'] ?? 1 ) );
			$file_pct = $bytes_total > 0
				? min( 1, (int) ( $meta['files_bytes_done'] ?? 0 ) / $bytes_total )
				: min( 1, (int) ( $meta['files_done'] ?? 0 ) / $files_total );
			$progress = 35 + (int) floor( $file_pct * 50 );
			if ( ! $done ) {
				return [ 'progress' => min( 85, $progress ), 'meta' => $meta ];
			}
			if ( (int) ( $meta['files_done'] ?? 0 ) !== (int) ( $meta['files_total'] ?? 0 ) || (int) ( $meta['files_bytes_done'] ?? 0 ) !== (int) ( $meta['files_bytes_total'] ?? 0 ) ) {
				throw new RuntimeException( 'Filesystem package totals do not match the verified inventory.' );
			}
			$meta['files_packaged']     = true;
			$meta['files_current_file'] = '';
		}

		return self::finalize( $job, $work, $meta, $temp_archive, $checks_file );
	}

	/** @param array<string,mixed> $job @param array<string,mixed> $meta @return array<string,mixed> */
	private static function finalize( array $job, string $work, array $meta, string $temp_archive, string $checks_file ): array {
		$short = substr( str_replace( '-', '', (string) $job['job_id'] ), 0, 8 );
		$created = max( 1, (int) ( $meta['started_timestamp'] ?? time() ) );
		$name  = sprintf( 'cb-%s-%s-%s.cbbackup', (string) $job['backup_type'], gmdate( 'Ymd-His', $created ), $short );
		$final = LocalStorage::archive_path( $name );

		// rename() is atomic on the normal local-storage filesystem. If PHP dies
		// immediately afterwards, the deterministic filename lets the next worker
		// recognize that finalization already committed instead of creating a
		// duplicate or orphan archive.
		if ( is_file( $final ) && ! is_file( $temp_archive ) ) {
			$checksum_count = max( 1, (int) ( $meta['checksums_count'] ?? 1 ) );
			$meta['archive_name']  = $name;
			$meta['archive_bytes'] = filesize( $final ) ?: 0;
			$meta['verify_cursor'] = 0;
			$meta['verify_done']   = 0;
			$meta['verify_total']  = $checksum_count;
			return [ 'stage' => 'verify', 'progress' => 90, 'archive_name' => $name, 'meta' => $meta ];
		}
		if ( is_file( $final ) && is_file( $temp_archive ) ) {
			throw new RuntimeException( 'Backup finalization found both temporary and final archives for the same job.' );
		}

		$portable_checks = $work . '/checksums.json';
		$checksum_count  = ChecksumFile::build_portable_json( $checks_file, $portable_checks );
		if ( $checksum_count !== (int) ( $meta['checksums_count'] ?? $checksum_count ) ) {
			throw new RuntimeException( 'Backup checksum count changed while finalizing the archive.' );
		}
		$meta['checksums_count'] = $checksum_count;
		$manifest = Manifest::build( $job, $checksum_count, $meta );
		$manifest_json = wp_json_encode( $manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $manifest_json ) ) {
			throw new RuntimeException( 'Backup manifest could not be encoded.' );
		}
		$zip = new ZipArchive();
		if ( true !== $zip->open( $temp_archive ) ) {
			throw new RuntimeException( 'Backup archive could not be finalized.' );
		}
		if ( false !== $zip->locateName( 'checksums.json' ) ) {
			$zip->deleteName( 'checksums.json' );
		}
		if ( false !== $zip->locateName( 'manifest.json' ) ) {
			$zip->deleteName( 'manifest.json' );
		}
		if ( ! $zip->addFile( $portable_checks, 'checksums.json' ) || ! $zip->addFromString( 'manifest.json', $manifest_json ) ) {
			$zip->close();
			throw new RuntimeException( 'Backup metadata could not be added to the archive.' );
		}
		if ( ! $zip->close() ) {
			throw new RuntimeException( 'Backup archive could not persist its final metadata.' );
		}

		if ( ! rename( $temp_archive, $final ) ) {
			throw new RuntimeException( 'Backup archive could not be moved into local storage.' );
		}
		$meta['archive_name']  = $name;
		$meta['archive_bytes'] = filesize( $final ) ?: 0;
		$meta['verify_cursor'] = 0;
		$meta['verify_done']   = 0;
		$meta['verify_total']  = $checksum_count;
		return [ 'stage' => 'verify', 'progress' => 90, 'archive_name' => $name, 'meta' => $meta ];
	}

	/** @param array<string,mixed> $job @param array<string,mixed> $meta @return array{done:bool,progress:int,meta:array<string,mixed>} */
	private static function verify_tick( array $job, string $work, array $meta ): array {
		$archive_name = (string) ( $job['archive_name'] ?? $meta['archive_name'] ?? '' );
		if ( '' === $archive_name ) {
			throw new RuntimeException( 'Backup archive name is missing.' );
		}
		$path = LocalStorage::archive_path( $archive_name );
		$checks_file = $work . '/checksums.jsonl';
		if ( ! is_file( $checks_file ) ) {
			throw new RuntimeException( 'Backup verification index is missing.' );
		}

		if ( empty( $meta['verify_initialized'] ) ) {
			ArchiveValidator::validate( $path, false );
			$meta['verify_initialized'] = true;
			$meta['verify_cursor']      = 0;
			$meta['verify_done']        = 0;
			$meta['verify_total']       = max( 1, (int) ( $meta['checksums_count'] ?? 1 ) );
		}

		$handle = fopen( $checks_file, 'rb' );
		$zip = new ZipArchive();
		if ( false === $handle || true !== $zip->open( $path, ZipArchive::CHECKCONS ) ) {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
			throw new RuntimeException( 'Backup archive could not be opened for integrity verification.' );
		}
		$cursor = max( 0, (int) ( $meta['verify_cursor'] ?? 0 ) );
		if ( $cursor > 0 && 0 !== fseek( $handle, $cursor ) ) {
			fclose( $handle );
			$zip->close();
			throw new RuntimeException( 'Backup verification cursor could not be restored.' );
		}

		$processed = 0;
		$bytes_verified = 0;
		$deadline = microtime( true ) + self::bounded_time_budget( self::VERIFY_TIME_BUDGET );
		try {
			while ( $processed < self::VERIFY_FILE_BUDGET && microtime( true ) < $deadline && ( 0 === $processed || $bytes_verified < self::VERIFY_BYTE_BUDGET ) && false !== ( $line = fgets( $handle ) ) ) {
				$record = json_decode( trim( $line ), true );
				$entry = is_array( $record ) ? (string) ( $record['path'] ?? '' ) : '';
				$want  = is_array( $record ) ? strtolower( (string) ( $record['sha256'] ?? '' ) ) : '';
				$size  = is_array( $record ) ? max( 0, (int) ( $record['size'] ?? 0 ) ) : -1;
				if ( '' === $entry || 64 !== strlen( $want ) || ! ctype_xdigit( $want ) ) {
					throw new RuntimeException( 'Backup verification index contains an invalid checksum record.' );
				}
				$stat = $zip->statName( $entry );
				if ( ! is_array( $stat ) || (int) ( $stat['size'] ?? -1 ) !== $size ) {
					throw new RuntimeException( sprintf( 'Backup payload size verification failed for %s.', $entry ) );
				}
				$stream = $zip->getStream( $entry );
				if ( false === $stream ) {
					throw new RuntimeException( sprintf( 'Backup payload is missing: %s', $entry ) );
				}
				$context = hash_init( 'sha256' );
				hash_update_stream( $context, $stream );
				fclose( $stream );
				$have = hash_final( $context );
				if ( ! hash_equals( $want, $have ) ) {
					throw new RuntimeException( sprintf( 'Checksum verification failed for %s.', $entry ) );
				}
				++$processed;
				$bytes_verified += $size;
				$meta['verify_done'] = (int) ( $meta['verify_done'] ?? 0 ) + 1;
				$meta['verify_current_file'] = $entry;
			}
			$position = ftell( $handle );
			$meta['verify_cursor'] = false === $position ? $cursor : $position;
			$done = feof( $handle );
		} finally {
			fclose( $handle );
			$zip->close();
		}

		$total = max( 1, (int) ( $meta['verify_total'] ?? 1 ) );
		$progress = 90 + (int) floor( min( 1, (int) ( $meta['verify_done'] ?? 0 ) / $total ) * 9 );
		if ( $done ) {
			$meta['verify_current_file'] = '';
			$meta['payloads_verified'] = (int) ( $meta['verify_done'] ?? 0 );
			return [ 'done' => true, 'progress' => 99, 'meta' => $meta ];
		}
		return [ 'done' => false, 'progress' => min( 99, $progress ), 'meta' => $meta ];
	}

	/** @param array<string,mixed> $job @param array<string,mixed> $meta */
	private static function complete_verified_backup( array $job, string $work, array $meta ): void {
		$job_id = (string) $job['job_id'];
		$type   = (string) $job['backup_type'];
		$archive_name = (string) ( $job['archive_name'] ?? $meta['archive_name'] ?? '' );
		$path = LocalStorage::archive_path( $archive_name );
		if ( '' === $archive_name || ! is_file( $path ) ) {
			throw new RuntimeException( 'Verified backup archive is missing.' );
		}

		$completed_timestamp         = time();
		$started_timestamp           = max( 0, (int) ( $meta['started_timestamp'] ?? $completed_timestamp ) );
		$meta['completed_timestamp'] = $completed_timestamp;
		$meta['duration_seconds']    = max( 0, $completed_timestamp - $started_timestamp );
		$meta['archive_size']        = filesize( $path ) ?: 0;
		$meta['db_rows_total']       = (int) ( $meta['db_rows_total'] ?? $meta['db_rows_done'] ?? 0 );
		$meta['db_tables_total']     = (int) ( $meta['db_tables_total'] ?? ( isset( $meta['db_tables'] ) && is_array( $meta['db_tables'] ) ? count( $meta['db_tables'] ) : 0 ) );

		// Payload SHA-256 is the canonical integrity guarantee. A whole-archive
		// SHA-256 is useful for smaller files, but hashing a multi-GB ZIP in one
		// PHP request would undermine resumability, so it is intentionally capped.
		$archive_hash = '';
		$hash_limit = max( 0, (int) apply_filters( 'cb_backups_archive_hash_max_bytes', 536870912 ) );
		if ( $hash_limit > 0 && $meta['archive_size'] <= $hash_limit ) {
			$calculated = hash_file( 'sha256', $path );
			$archive_hash = is_string( $calculated ) ? $calculated : '';
		}
		$meta['archive_sha256'] = $archive_hash;

		LocalStorage::write_meta( $archive_name, [
			'job_id'              => $job_id,
			'backup_type'         => $type,
			'trigger_source'      => (string) $job['trigger_source'],
			'created_at'          => gmdate( 'c', $started_timestamp ),
			'created_timestamp'   => $started_timestamp,
			'completed_at'        => gmdate( 'c', $completed_timestamp ),
			'completed_timestamp' => $completed_timestamp,
			'duration_seconds'    => $meta['duration_seconds'],
			'database_rows'       => $meta['db_rows_total'],
			'database_tables'     => $meta['db_tables_total'],
			'files'               => (int) ( $meta['files_total'] ?? 0 ),
			'filesystem_bytes'    => (int) ( $meta['files_bytes_total'] ?? 0 ),
			'archive_sha256'      => $archive_hash,
			'integrity'           => 'payload-sha256',
			'payloads_verified'   => (int) ( $meta['payloads_verified'] ?? 0 ),
			'size'                => $meta['archive_size'],
			'verified'            => true,
		] );
		Repository::update( $job_id, [ 'meta' => $meta ] );
		Repository::complete( $job_id, $archive_name );
		if ( 'schedule' === (string) $job['trigger_source'] ) {
			Scheduler::record_success( $type, $job_id );
			Retention::apply( $type );
		}
		Audit::log( 'backups.backup.completed', 'notice', [
			'job_id'   => $job_id,
			'type'     => $type,
			'trigger'  => (string) $job['trigger_source'],
			'size'     => $meta['archive_size'],
			'duration' => $meta['duration_seconds'],
			'rows'     => $meta['db_rows_total'],
			'files'    => (int) ( $meta['files_total'] ?? 0 ),
		] );
		self::cleanup_work( $work );
	}

	private static function safe_relative_path( string $relative ): bool {
		$relative = str_replace( '\\', '/', $relative );
		if ( '' === $relative || str_starts_with( $relative, '/' ) || str_contains( $relative, "\0" ) ) {
			return false;
		}
		foreach ( explode( '/', $relative ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				return false;
			}
		}
		return true;
	}

	private static function apply_compression_policy( ZipArchive $zip, string $archive_path, string $relative ): void {
		$extension = strtolower( pathinfo( $relative, PATHINFO_EXTENSION ) );
		$already_compressed = [
			'7z', 'avif', 'bz2', 'flac', 'gif', 'gz', 'heic', 'heif', 'jpeg', 'jpg', 'm4a', 'm4v',
			'mkv', 'mov', 'mp3', 'mp4', 'ogg', 'ogv', 'pdf', 'png', 'rar', 'tgz', 'webm', 'webp',
			'woff', 'woff2', 'xz', 'zip',
		];
		if ( in_array( $extension, $already_compressed, true ) ) {
			$zip->setCompressionName( $archive_path, ZipArchive::CM_STORE );
			return;
		}
		// Level 1 keeps text/code payloads compact while prioritising backup speed.
		$zip->setCompressionName( $archive_path, ZipArchive::CM_DEFLATE, 1 );
	}

	private static function bounded_time_budget( float $preferred ): float {
		$execution_limit = (int) ini_get( 'max_execution_time' );
		if ( $execution_limit <= 0 ) {
			return $preferred;
		}
		return max( 1.0, min( $preferred, $execution_limit * 0.20 ) );
	}

	private static function truncate_file( string $file, int $size ): void {
		$handle = fopen( $file, 'c+b' );
		if ( false === $handle || ! ftruncate( $handle, max( 0, $size ) ) ) {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
			throw new RuntimeException( 'Backup checksum recovery checkpoint could not be restored.' );
		}
		fclose( $handle );
	}

	private static function cleanup_work( string $work ): void {
		LocalStorage::remove_tree( $work );
	}
}
