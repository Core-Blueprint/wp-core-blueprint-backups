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
			$plan = MigrationPlan::build( $manifest );
			$stored_plan = is_array( $meta['migration_plan'] ?? null ) ? $meta['migration_plan'] : [];
			if ( $stored_plan ) {
				MigrationPlan::assert_plan( $stored_plan );
				if ( ! hash_equals( (string) $stored_plan['fingerprint'], (string) $plan['fingerprint'] ) ) throw new RuntimeException( 'Restore destination changed after migration preflight.' );
			}
			clearstatcache( true, $archive );
			$size = filesize( $archive ); $mtime = filemtime( $archive );
			if ( false === $size || false === $mtime ) throw new RuntimeException( 'Restore archive metadata could not be read.' );
			$checksum_index = $work . '/restore-checksums.jsonl';
			$count = ArchiveValidator::export_checksum_index( $archive, $checksum_index );
			if ( $count !== (int) ( $manifest['checksums_count'] ?? -1 ) ) throw new RuntimeException( 'Restore checksum index does not match the verified manifest.' );
			$meta['manifest'] = $manifest; $meta['migration_plan'] = $plan; $meta['restore_mode'] = (string) $plan['mode'];
			$meta['restore_archive_size'] = (int) $size; $meta['restore_archive_mtime'] = (int) $mtime; $meta['restore_checksum_index'] = $checksum_index;
			$meta['restore_verify_cursor'] = 0; $meta['restore_verify_done'] = 0; $meta['restore_verify_total'] = $count; $meta['restore_verify_current_file'] = '';
			Repository::update( $job_id, [ 'status' => 'running', 'stage' => 'verify_archive', 'progress' => 4, 'meta' => $meta ] ); return;
		}

		if ( 'verify_archive' === $stage ) {
			$index = (string) ( $meta['restore_checksum_index'] ?? $work . '/restore-checksums.jsonl' ); $result = ArchiveVerifier::tick( $archive, $index, $meta ); $meta = $result['meta'];
			if ( ! $result['done'] ) { Repository::update( $job_id, [ 'progress' => min( 18, 4 + (int) floor( $result['progress'] * 0.14 ) ), 'meta' => $meta ] ); return; }
			wp_mkdir_p( $staged ); $meta['zip_index'] = 0; $meta['restore_extract_files_done'] = 0; $meta['restore_extract_bytes_done'] = 0; $meta['restore_extract_current_file'] = '';
			Repository::update( $job_id, [ 'stage' => 'extract', 'progress' => 20, 'meta' => $meta ] ); return;
		}

		if ( 'extract' === $stage ) {
			ArchiveVerifier::assert_archive_stable( $archive, $meta ); $result = self::extract_tick( $archive, $staged, $meta ); $meta = $result['meta'];
			if ( ! $result['done'] ) { Repository::update( $job_id, [ 'progress' => $result['progress'], 'meta' => $meta ] ); return; }
			if ( 'website' === $type ) {
				$meta['stage_verify_cursor'] = 0; $meta['stage_verify_done'] = 0; $meta['stage_verify_current_file'] = '';
				Repository::update( $job_id, [ 'stage' => 'verify_stage', 'progress' => 65, 'meta' => $meta ] );
			} else self::advance_to_database_work( $job_id, $meta, 66 );
			return;
		}

		if ( 'verify_stage' === $stage ) {
			$index = (string) ( $meta['restore_checksum_index'] ?? $work . '/restore-checksums.jsonl' ); $result = StagedVerifier::tick( $staged, $index, $meta ); $meta = $result['meta'];
			if ( ! $result['done'] ) { Repository::update( $job_id, [ 'progress' => min( 72, 65 + (int) floor( $result['progress'] * 0.07 ) ), 'meta' => $meta ] ); return; }
			self::advance_to_database_work( $job_id, $meta, 73 ); return;
		}

		if ( 'migrate_database' === $stage ) {
			$plan = self::migration_plan( $meta ); $source = $staged . '/database/database.sql'; $target = $staged . '/database/database-migrated.sql';
			$result = MigrationTransformer::tick( $source, $target, $meta, $plan ); $meta = $result['meta'];
			$base = 'website' === $type ? 73 : 66; $end = 'website' === $type ? 79 : 72;
			if ( ! $result['done'] ) { $progress = $base + (int) floor( $result['progress'] * ( ( $end - $base ) / 100 ) ); Repository::update( $job_id, [ 'progress' => min( $end - 1, $progress ), 'meta' => $meta ] ); return; }
			$source_manifest = is_array( $meta['manifest'] ?? null ) ? $meta['manifest'] : []; $meta['source_manifest'] = $source_manifest; $meta['manifest'] = MigrationPlan::target_manifest( $source_manifest, $plan );
			$meta['manifest']['database']['content_integrity'] = $meta['migration_target_integrity'];
			$meta['migration_applied'] = true; $meta['migration_sql_path'] = $target;
			Repository::update( $job_id, [ 'stage' => 'restore_database', 'progress' => $end, 'meta' => $meta ] ); return;
		}

		if ( 'restore_database' === $stage ) {
			$tables = self::manifest_tables( $meta ); $sql = ! empty( $meta['migration_applied'] ) ? (string) ( $meta['migration_sql_path'] ?? '' ) : $staged . '/database/database.sql';
			if ( ! empty( $meta['migration_applied'] ) ) self::assert_migrated_sql( $sql, $meta );
			try { $result = DatabaseImporter::tick( $sql, $meta, $tables, $job_id ); $meta = $result['meta']; } catch ( \Throwable $e ) { DatabaseImporter::rollback( $meta, $tables, $job_id ); throw $e; }
			if ( ! $result['done'] ) {
				$base = 'website' === $type ? ( ! empty( $meta['migration_applied'] ) ? 79 : 73 ) : ( ! empty( $meta['migration_applied'] ) ? 72 : 66 );
				$progress = $base + (int) floor( $result['progress'] * ( ( 95 - $base ) / 100 ) ); Repository::update( $job_id, [ 'progress' => min( 95, $progress ), 'meta' => $meta ] ); return;
			}
			Repository::update( $job_id, [ 'stage' => 'verify_database_content', 'progress' => 95, 'meta' => $meta ] ); return;
		}

		if ( 'verify_database_content' === $stage ) {
			$tables = self::manifest_tables( $meta );
			$inventory = $meta['manifest']['database']['content_integrity'] ?? [];
			try { $result = DatabaseContentVerifier::tick( $work, $meta, $tables, $inventory, $job_id ); }
			catch ( \Throwable $e ) { DatabaseImporter::rollback( $meta, $tables, $job_id ); throw $e; }
			$meta = $result['meta'];
			Repository::update( $job_id, [ 'stage' => $result['done'] ? 'prepare_live' : 'verify_database_content', 'progress' => $result['done'] ? 96 : 95, 'meta' => $meta ] );
			return;
		}

		if ( 'prepare_live' === $stage ) {
			$tables = self::manifest_tables( $meta ); $meta = DatabaseImporter::prepare_commit( $meta, $tables, $job_id ); if ( 'website' === $type ) $meta = FilesystemCommitter::prepare( $staged . '/files/wp-content', $work, $meta );
			Repository::update( $job_id, [ 'stage' => 'commit_live', 'progress' => 98, 'meta' => $meta ] ); return;
		}

		if ( 'commit_live' === $stage ) {
			$manifest = is_array( $meta['manifest'] ?? null ) ? $meta['manifest'] : []; $tables = self::manifest_tables( $meta );
			if ( 'website' === $type ) {
				CriticalRecovery::arm( $job_id ); Maintenance::activate( $job_id ); $meta = DatabaseImporter::commit_snapshot( $meta, $tables, $job_id ); $meta = FilesystemCommitter::commit_all( $staged . '/files/wp-content', $work, $meta );
				DatabaseImporter::assert_restored_snapshot( $meta, $tables ); self::assert_site_identity( $manifest ); FilesystemCommitter::assert_committed( (string) $meta['recovery_path'] ); FilesystemCommitter::assert_runtime_available();
				$index = (string) ( $meta['restore_checksum_index'] ?? $work . '/restore-checksums.jsonl' ); $meta = LiveVerifier::prepare( $index, $meta ); $meta['runtime_preserved'] = FilesystemCommitter::runtime_protected_paths();
				Repository::update( $job_id, [ 'stage' => 'verify_live', 'progress' => 98, 'meta' => $meta ] ); return;
			}
			Maintenance::activate( $job_id );
			try { $meta = DatabaseImporter::commit_snapshot( $meta, $tables, $job_id ); DatabaseImporter::assert_restored_snapshot( $meta, $tables ); self::assert_site_identity( $manifest ); } catch ( \Throwable $e ) { DatabaseImporter::rollback( $meta, $tables, $job_id ); Maintenance::deactivate(); throw $e; }
			self::complete_restore( $job, $meta, $tables, false ); return;
		}

		if ( 'verify_live' === $stage ) {
			if ( 'website' !== $type || empty( $meta['recovery_path'] ) ) throw new RuntimeException( 'Live restore verification state is incomplete.' );
			Maintenance::activate( $job_id ); if ( ! CriticalRecovery::is_armed( $job_id ) ) CriticalRecovery::arm( $job_id ); $index = (string) ( $meta['restore_checksum_index'] ?? $work . '/restore-checksums.jsonl' ); $result = LiveVerifier::tick( $index, $meta ); $meta = $result['meta'];
			if ( ! $result['done'] ) { Repository::update( $job_id, [ 'progress' => min( 99, 98 + (int) floor( $result['progress'] * 0.01 ) ), 'meta' => $meta ] ); return; }
			$manifest = is_array( $meta['manifest'] ?? null ) ? $meta['manifest'] : []; $tables = self::manifest_tables( $meta ); DatabaseImporter::assert_restored_snapshot( $meta, $tables ); self::assert_site_identity( $manifest ); FilesystemCommitter::assert_committed( (string) $meta['recovery_path'] ); FilesystemCommitter::assert_runtime_available();
			Repository::update( $job_id, [ 'stage' => 'finalize_live', 'progress' => 99, 'meta' => $meta ] ); return;
		}

		if ( 'finalize_live' === $stage ) {
			$manifest = is_array( $meta['manifest'] ?? null ) ? $meta['manifest'] : []; $tables = self::manifest_tables( $meta ); DatabaseImporter::assert_restored_snapshot( $meta, $tables ); self::assert_site_identity( $manifest ); FilesystemCommitter::assert_committed( (string) $meta['recovery_path'] ); FilesystemCommitter::assert_runtime_available(); self::complete_restore( $job, $meta, $tables, true ); return;
		}
		throw new RuntimeException( sprintf( 'Unknown restore job stage: %s', $stage ) );
	}

	/** @param array<string,mixed> $meta */
	private static function advance_to_database_work( string $job_id, array $meta, int $progress ): void {
		$plan = self::migration_plan( $meta ); $stage = ! empty( $plan['requires_migration'] ) ? 'migrate_database' : 'restore_database'; Repository::update( $job_id, [ 'stage' => $stage, 'progress' => $progress, 'meta' => $meta ] );
	}
	/** @param array<string,mixed> $meta @return array<string,mixed> */
	private static function migration_plan( array $meta ): array {
		$plan = is_array( $meta['migration_plan'] ?? null ) ? $meta['migration_plan'] : []; if ( ! $plan ) throw new RuntimeException( 'Restore migration plan is missing.' ); MigrationPlan::assert_plan( $plan ); return $plan;
	}
	/** @param array<string,mixed> $meta @return string[] */
	private static function manifest_tables( array $meta ): array {
		$manifest = is_array( $meta['manifest'] ?? null ) ? $meta['manifest'] : []; $database = is_array( $manifest['database'] ?? null ) ? $manifest['database'] : []; return isset( $database['tables'] ) && is_array( $database['tables'] ) ? array_values( array_map( 'strval', $database['tables'] ) ) : [];
	}
	/** @param array<string,mixed> $meta */
	private static function assert_migrated_sql( string $path, array $meta ): void {
		if ( empty( $meta['migration_transform_complete'] ) || ! is_file( $path ) ) throw new RuntimeException( 'Migrated database staging snapshot is incomplete.' ); $expected = (string) ( $meta['migration_sql_sha256'] ?? '' ); $have = hash_file( 'sha256', $path ); if ( 64 !== strlen( $expected ) || ! is_string( $have ) || ! hash_equals( $expected, $have ) ) throw new RuntimeException( 'Migrated database staging snapshot changed after preparation.' );
	}

	/** @param array<string,mixed> $job @param array<string,mixed> $meta @param string[] $tables */
	private static function complete_restore( array $job, array $meta, array $tables, bool $critical ): void {
		$job_id = (string) $job['job_id']; $type = (string) $job['backup_type']; wp_cache_flush(); if ( function_exists( 'flush_rewrite_rules' ) ) flush_rewrite_rules( false );
		$completed = time(); $meta['completed_timestamp'] = $completed; $meta['duration_seconds'] = max( 0, $completed - (int) ( $meta['started_timestamp'] ?? $completed ) ); Repository::update( $job_id, [ 'meta' => $meta ] ); Repository::complete( $job_id ); if ( $critical ) CriticalRecovery::disarm( $job_id ); Maintenance::deactivate();
		Audit::log( 'backups.restore.completed', 'warning', [ 'job_id' => $job_id, 'type' => $type, 'trigger' => (string) $job['trigger_source'], 'duration' => $meta['duration_seconds'], 'mode' => (string) ( $meta['restore_mode'] ?? 'restore' ), 'migration_replacements' => (int) ( $meta['migration_replacements'] ?? 0 ), 'runtime_preserved' => isset( $meta['runtime_preserved'] ) && is_array( $meta['runtime_preserved'] ) ? $meta['runtime_preserved'] : [] ] );
		try { DatabaseImporter::cleanup_recovery( $meta, $tables, $job_id ); } catch ( \Throwable $e ) { Audit::log( 'backups.restore.cleanup.warning', 'warning', [ 'job_id' => $job_id, 'error' => $e->getMessage() ] ); }
		LocalStorage::remove_tree( LocalStorage::work_dir( $job_id ) );
	}

	/** @param array<string,mixed> $manifest */
	private static function assert_site_identity( array $manifest ): void {
		global $wpdb; $site = is_array( $manifest['site'] ?? null ) ? $manifest['site'] : [];
		$siteurl = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . str_replace( '`', '``', (string) $wpdb->options ) . '` WHERE option_name = %s LIMIT 1', 'siteurl' ) ); $home = (string) $wpdb->get_var( $wpdb->prepare( 'SELECT option_value FROM `' . str_replace( '`', '``', (string) $wpdb->options ) . '` WHERE option_name = %s LIMIT 1', 'home' ) ); if ( '' === $home ) $home = $siteurl;
		if ( untrailingslashit( $siteurl ) !== untrailingslashit( (string) ( $site['site_url'] ?? '' ) ) || untrailingslashit( $home ) !== untrailingslashit( (string) ( $site['home_url'] ?? '' ) ) ) throw new RuntimeException( 'Restored WordPress site identity does not match the verified restore target.' );
	}

	/** @param array<string,mixed> $meta @return array{done:bool,progress:int,meta:array<string,mixed>} */
	private static function extract_tick( string $archive, string $staged, array $meta ): array {
		$zip = new ZipArchive(); if ( true !== $zip->open( $archive, ZipArchive::CHECKCONS ) ) throw new RuntimeException( 'Restore archive could not be opened for extraction.' );
		try {
			$index = max( 0, (int) ( $meta['zip_index'] ?? 0 ) ); $total = max( 1, $zip->numFiles ); $processed = 0; $deadline = microtime( true ) + 1.25;
			while ( $index < $zip->numFiles && $processed < 50 && microtime( true ) < $deadline ) {
				$name = (string) $zip->getNameIndex( $index ); $stat = $zip->statIndex( $index ); ++$index; ++$processed; ArchiveValidator::assert_safe_entry( $name ); if ( in_array( $name, [ 'manifest.json', 'checksums.json' ], true ) ) continue;
				$target = wp_normalize_path( $staged . '/' . $name ); $root = trailingslashit( wp_normalize_path( $staged ) ); if ( ! str_starts_with( $target, $root ) ) throw new RuntimeException( 'Restore archive path escaped the staging directory.' ); if ( str_ends_with( $name, '/' ) ) { wp_mkdir_p( $target ); continue; }
				$expected = is_array( $stat ) ? max( 0, (int) ( $stat['size'] ?? 0 ) ) : 0; $meta['restore_extract_current_file'] = $name; wp_mkdir_p( dirname( $target ) ); $source = $zip->getStream( $name ); if ( false === $source ) throw new RuntimeException( sprintf( 'Could not read %s from restore archive.', $name ) );
				$dest = fopen( $target, 'wb' ); if ( false === $dest ) { fclose( $source ); throw new RuntimeException( sprintf( 'Could not stage %s.', $name ) ); } $written = stream_copy_to_stream( $source, $dest ); fclose( $source ); fclose( $dest ); if ( false === $written || (int) $written !== $expected ) throw new RuntimeException( sprintf( 'Staged restore payload size mismatch for %s.', $name ) );
				$meta['restore_extract_files_done'] = (int) ( $meta['restore_extract_files_done'] ?? 0 ) + 1; $meta['restore_extract_bytes_done'] = (int) ( $meta['restore_extract_bytes_done'] ?? 0 ) + $expected;
			}
			$meta['zip_index'] = $index; $done = $index >= $zip->numFiles; if ( $done ) $meta['restore_extract_current_file'] = ''; return [ 'done' => $done, 'progress' => min( 64, 20 + (int) floor( min( 1, $index / $total ) * 44 ) ), 'meta' => $meta ];
		} finally { $zip->close(); }
	}
}
