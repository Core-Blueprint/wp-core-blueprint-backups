<?php
declare(strict_types=1);

namespace CB\Backups\Restore;

use CB\Backups\Jobs\Repository;
use CB\Backups\Storage\LocalStorage;
use CB\Backups\Support\Audit;
use RuntimeException;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

final class Engine {
	/** @param array<string,mixed> $job */
	public static function tick( array $job ): void {
		$job_id  = (string) $job['job_id'];
		$type    = (string) $job['backup_type'];
		$stage   = (string) $job['stage'];
		$meta    = is_array( $job['meta'] ?? null ) ? $job['meta'] : [];
		$archive = wp_normalize_path( (string) ( $meta['archive_path'] ?? '' ) );
		$work    = LocalStorage::work_dir( $job_id );
		$staged  = $work . '/stage';

		if ( 'queued' === $stage ) {
			$manifest = ArchiveValidator::validate( $archive, false );
			clearstatcache( true, $archive );
			$size = filesize( $archive );
			$mtime = filemtime( $archive );
			if ( false === $size || false === $mtime ) {
				throw new RuntimeException( 'Restore archive metadata could not be read.' );
			}
			$checksum_index = $work . '/restore-checksums.jsonl';
			$count = ArchiveValidator::export_checksum_index( $archive, $checksum_index );
			if ( $count !== (int) ( $manifest['checksums_count'] ?? -1 ) ) {
				throw new RuntimeException( 'Restore checksum index does not match the verified manifest.' );
			}
			$meta['manifest']                    = $manifest;
			$meta['restore_archive_size']        = (int) $size;
			$meta['restore_archive_mtime']       = (int) $mtime;
			$meta['restore_checksum_index']      = $checksum_index;
			$meta['restore_verify_cursor']       = 0;
			$meta['restore_verify_done']         = 0;
			$meta['restore_verify_total']        = $count;
			$meta['restore_verify_current_file'] = '';
			Repository::update( $job_id, [ 'status' => 'running', 'stage' => 'verify_archive', 'progress' => 4, 'meta' => $meta ] );
			return;
		}

		if ( 'verify_archive' === $stage ) {
			$index = (string) ( $meta['restore_checksum_index'] ?? $work . '/restore-checksums.jsonl' );
			$result = ArchiveVerifier::tick( $archive, $index, $meta );
			$meta   = $result['meta'];
			if ( ! $result['done'] ) {
				$progress = 4 + (int) floor( $result['progress'] * 0.14 );
				Repository::update( $job_id, [ 'progress' => min( 18, $progress ), 'meta' => $meta ] );
				return;
			}
			wp_mkdir_p( $staged );
			$meta['zip_index']                    = 0;
			$meta['restore_extract_files_done']   = 0;
			$meta['restore_extract_bytes_done']   = 0;
			$meta['restore_extract_current_file'] = '';
			Repository::update( $job_id, [ 'stage' => 'extract', 'progress' => 20, 'meta' => $meta ] );
			return;
		}

		if ( 'extract' === $stage ) {
			ArchiveVerifier::assert_archive_stable( $archive, $meta );
			$result = self::extract_tick( $archive, $staged, $meta );
			$meta   = $result['meta'];
			if ( ! $result['done'] ) {
				Repository::update( $job_id, [ 'progress' => $result['progress'], 'meta' => $meta ] );
				return;
			}
			if ( 'website' === $type ) {
				$meta['stage_verify_cursor']       = 0;
				$meta['stage_verify_done']         = 0;
				$meta['stage_verify_current_file'] = '';
				Repository::update( $job_id, [ 'stage' => 'verify_stage', 'progress' => 65, 'meta' => $meta ] );
			} else {
				Repository::update( $job_id, [ 'stage' => 'restore_database', 'progress' => 66, 'meta' => $meta ] );
			}
			return;
		}

		if ( 'verify_stage' === $stage ) {
			$index = (string) ( $meta['restore_checksum_index'] ?? $work . '/restore-checksums.jsonl' );
			$result = StagedVerifier::tick( $staged, $index, $meta );
			$meta   = $result['meta'];
			if ( ! $result['done'] ) {
				$progress = 65 + (int) floor( $result['progress'] * 0.07 );
				Repository::update( $job_id, [ 'progress' => min( 72, $progress ), 'meta' => $meta ] );
				return;
			}
			Repository::update( $job_id, [ 'stage' => 'restore_database', 'progress' => 73, 'meta' => $meta ] );
			return;
		}

		if ( 'restore_database' === $stage ) {
			$manifest = is_array( $meta['manifest'] ?? null ) ? $meta['manifest'] : [];
			$database = is_array( $manifest['database'] ?? null ) ? $manifest['database'] : [];
			$tables   = isset( $database['tables'] ) && is_array( $database['tables'] ) ? array_values( $database['tables'] ) : [];
			try {
				$result = DatabaseImporter::tick( $staged . '/database/database.sql', $meta, $tables, $job_id );
				$meta   = $result['meta'];
			} catch ( \Throwable $e ) {
				DatabaseImporter::rollback( $meta, $tables, $job_id );
				throw $e;
			}
			if ( ! $result['done'] ) {
				$base = 'website' === $type ? 73 : 66;
				$span = 'website' === $type ? 22 : 29;
				$progress = $base + (int) floor( $result['progress'] * ( $span / 100 ) );
				Repository::update( $job_id, [ 'progress' => min( 95, $progress ), 'meta' => $meta ] );
				return;
			}
			Repository::update( $job_id, [ 'stage' => 'prepare_live', 'progress' => 96, 'meta' => $meta ] );
			return;
		}

		if ( 'prepare_live' === $stage ) {
			$manifest = is_array( $meta['manifest'] ?? null ) ? $meta['manifest'] : [];
			$database = is_array( $manifest['database'] ?? null ) ? $manifest['database'] : [];
			$tables   = isset( $database['tables'] ) && is_array( $database['tables'] ) ? array_values( $database['tables'] ) : [];
			$meta = DatabaseImporter::prepare_commit( $meta, $tables, $job_id );
			if ( 'website' === $type ) {
				$meta = FilesystemCommitter::prepare( $staged . '/files/wp-content', $work, $meta );
			}
			Repository::update( $job_id, [ 'stage' => 'commit_live', 'progress' => 98, 'meta' => $meta ] );
			return;
		}

		if ( 'commit_live' === $stage ) {
			$manifest = is_array( $meta['manifest'] ?? null ) ? $meta['manifest'] : [];
			$database = is_array( $manifest['database'] ?? null ) ? $manifest['database'] : [];
			$tables   = isset( $database['tables'] ) && is_array( $database['tables'] ) ? array_values( $database['tables'] ) : [];
			if ( 'website' === $type ) {
				CriticalRecovery::arm( $job_id );
				Maintenance::activate( $job_id );
				$meta = DatabaseImporter::commit_snapshot( $meta, $tables, $job_id );
				$meta = FilesystemCommitter::commit_all( $staged . '/files/wp-content', $work, $meta );
				DatabaseImporter::assert_restored_snapshot( $meta, $tables );
				self::assert_site_identity( $manifest );
				FilesystemCommitter::assert_committed( (string) $meta['recovery_path'] );
				FilesystemCommitter::assert_runtime_available();
				$index = (string) ( $meta['restore_checksum_index'] ?? $work . '/restore-checksums.jsonl' );
				$meta = LiveVerifier::prepare( $index, $meta );
				$meta['runtime_preserved'] = FilesystemCommitter::runtime_protected_paths();
				Repository::update( $job_id, [ 'stage' => 'verify_live', 'progress' => 98, 'meta' => $meta ] );
				return;
			}

			Maintenance::activate( $job_id );
			try {
				$meta = DatabaseImporter::commit_snapshot( $meta, $tables, $job_id );
				DatabaseImporter::assert_restored_snapshot( $meta, $tables );
				self::assert_site_identity( $manifest );
			} catch ( \Throwable $e ) {
				DatabaseImporter::rollback( $meta, $tables, $job_id );
				Maintenance::deactivate();
				throw $e;
			}
			self::complete_restore( $job, $meta, $tables, false );
			return;
		}

		if ( 'verify_live' === $stage ) {
			if ( 'website' !== $type || empty( $meta['recovery_path'] ) ) {
				throw new RuntimeException( 'Live restore verification state is incomplete.' );
			}
			Maintenance::activate( $job_id );
			if ( ! CriticalRecovery::is_armed( $job_id ) ) {
				CriticalRecovery::arm( $job_id );
			}
			$index = (string) ( $meta['restore_checksum_index'] ?? $work . '/restore-checksums.jsonl' );
			$result = LiveVerifier::tick( $index, $meta );
			$meta = $result['meta'];
			if ( ! $result['done'] ) {
				$progress = 98 + (int) floor( $result['progress'] * 0.01 );
				Repository::update( $job_id, [ 'progress' => min( 99, $progress ), 'meta' => $meta ] );
				return;
			}
			$manifest = is_array( $meta['manifest'] ?? null ) ? $meta['manifest'] : [];
			$database = is_array( $manifest['database'] ?? null ) ? $manifest['database'] : [];
			$tables = isset( $database['tables'] ) && is_array( $database['tables'] ) ? array_values( $database['tables'] ) : [];
			DatabaseImporter::assert_restored_snapshot( $meta, $tables );
			self::assert_site_identity( $manifest );
			FilesystemCommitter::assert_committed( (string) $meta['recovery_path'] );
			FilesystemCommitter::assert_runtime_available();
			Repository::update( $job_id, [ 'stage' => 'finalize_live', 'progress' => 99, 'meta' => $meta ] );
			return;
		}

		if ( 'finalize_live' === $stage ) {
			$manifest = is_array( $meta['manifest'] ?? null ) ? $meta['manifest'] : [];
			$database = is_array( $manifest['database'] ?? null ) ? $manifest['database'] : [];
			$tables = isset( $database['tables'] ) && is_array( $database['tables'] ) ? array_values( $database['tables'] ) : [];
			DatabaseImporter::assert_restored_snapshot( $meta, $tables );
			self::assert_site_identity( $manifest );
			FilesystemCommitter::assert_committed( (string) $meta['recovery_path'] );
			FilesystemCommitter::assert_runtime_available();
			self::complete_restore( $job, $meta, $tables, true );
			return;
		}

		throw new RuntimeException( sprintf( 'Unknown restore job stage: %s', $stage ) );
	}

	/** @param array<string,mixed> $job @param array<string,mixed> $meta @param string[] $tables */
	private static function complete_restore( array $job, array $meta, array $tables, bool $critical ): void {
		$job_id = (string) $job['job_id'];
		$type = (string) $job['backup_type'];
		wp_cache_flush();
		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( false );
		}
		$completed_timestamp = time();
		$meta['completed_timestamp'] = $completed_timestamp;
		$meta['duration_seconds'] = max( 0, $completed_timestamp - (int) ( $meta['started_timestamp'] ?? $completed_timestamp ) );
		Repository::update( $job_id, [ 'meta' => $meta ] );
		Repository::complete( $job_id );
		if ( $critical ) {
			CriticalRecovery::disarm( $job_id );
		}
		Maintenance::deactivate();
		Audit::log( 'backups.restore.completed', 'warning', [
			'job_id'            => $job_id,
			'type'              => $type,
			'trigger'           => (string) $job['trigger_source'],
			'duration'          => $meta['duration_seconds'],
			'runtime_preserved' => isset( $meta['runtime_preserved'] ) && is_array( $meta['runtime_preserved'] ) ? $meta['runtime_preserved'] : [],
		] );
		try {
			DatabaseImporter::cleanup_recovery( $meta, $tables, $job_id );
		} catch ( \Throwable $cleanup_error ) {
			Audit::log( 'backups.restore.cleanup.warning', 'warning', [ 'job_id' => $job_id, 'error' => $cleanup_error->getMessage() ] );
		}
		LocalStorage::remove_tree( LocalStorage::work_dir( $job_id ) );
	}

	/** @param array<string,mixed> $manifest */
	private static function assert_site_identity( array $manifest ): void {
		global $wpdb;
		$site = is_array( $manifest['site'] ?? null ) ? $manifest['site'] : [];
		$siteurl = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . str_replace( '`', '``', (string) $wpdb->options ) . '` WHERE option_name = %s LIMIT 1', 'siteurl' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$home = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . str_replace( '`', '``', (string) $wpdb->options ) . '` WHERE option_name = %s LIMIT 1', 'home' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( '' === $home ) {
			$home = $siteurl;
		}
		if ( untrailingslashit( $siteurl ) !== untrailingslashit( (string) ( $site['site_url'] ?? '' ) ) || untrailingslashit( $home ) !== untrailingslashit( (string) ( $site['home_url'] ?? '' ) ) ) {
			throw new RuntimeException( 'Restored WordPress site identity does not match the verified backup manifest.' );
		}
	}

	/** @param array<string,mixed> $meta @return array{done:bool,progress:int,meta:array<string,mixed>} */
	private static function extract_tick( string $archive, string $staged, array $meta ): array {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive, ZipArchive::CHECKCONS ) ) {
			throw new RuntimeException( 'Restore archive could not be opened for extraction.' );
		}
		try {
			$index = max( 0, (int) ( $meta['zip_index'] ?? 0 ) );
			$total = max( 1, $zip->numFiles );
			$processed = 0;
			$deadline = microtime( true ) + 1.25;
			while ( $index < $zip->numFiles && $processed < 50 && microtime( true ) < $deadline ) {
				$name = (string) $zip->getNameIndex( $index );
				$stat = $zip->statIndex( $index );
				++$index;
				++$processed;
				ArchiveValidator::assert_safe_entry( $name );
				if ( in_array( $name, [ 'manifest.json', 'checksums.json' ], true ) ) {
					continue;
				}
				$target = wp_normalize_path( $staged . '/' . $name );
				$stage_root = trailingslashit( wp_normalize_path( $staged ) );
				if ( ! str_starts_with( $target, $stage_root ) ) {
					throw new RuntimeException( 'Restore archive path escaped the staging directory.' );
				}
				if ( str_ends_with( $name, '/' ) ) {
					wp_mkdir_p( $target );
					continue;
				}
				$expected_size = is_array( $stat ) ? max( 0, (int) ( $stat['size'] ?? 0 ) ) : 0;
				$meta['restore_extract_current_file'] = $name;
				wp_mkdir_p( dirname( $target ) );
				$source = $zip->getStream( $name );
				if ( false === $source ) {
					throw new RuntimeException( sprintf( 'Could not read %s from restore archive.', $name ) );
				}
				$dest = fopen( $target, 'wb' );
				if ( false === $dest ) {
					fclose( $source );
					throw new RuntimeException( sprintf( 'Could not stage %s.', $name ) );
				}
				$written = stream_copy_to_stream( $source, $dest );
				fclose( $source );
				fclose( $dest );
				if ( false === $written || (int) $written !== $expected_size ) {
					throw new RuntimeException( sprintf( 'Staged restore payload size mismatch for %s.', $name ) );
				}
				$meta['restore_extract_files_done'] = (int) ( $meta['restore_extract_files_done'] ?? 0 ) + 1;
				$meta['restore_extract_bytes_done'] = (int) ( $meta['restore_extract_bytes_done'] ?? 0 ) + $expected_size;
			}
			$meta['zip_index'] = $index;
			$done = $index >= $zip->numFiles;
			if ( $done ) {
				$meta['restore_extract_current_file'] = '';
			}
			$progress = 20 + (int) floor( min( 1, $index / $total ) * 44 );
			return [ 'done' => $done, 'progress' => min( 64, $progress ), 'meta' => $meta ];
		} finally {
			$zip->close();
		}
	}
}
