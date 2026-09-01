<?php
declare(strict_types=1);

namespace CB\Backups\Restore;

use CB\Backups\Jobs\Dispatcher;
use CB\Backups\Jobs\Repository;
use CB\Backups\Storage\LocalStorage;
use CB\Backups\Support\Audit;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class Service {
	/** @return array<string,mixed> */
	public static function create( string $archive_path, string $trigger = 'manual' ): array {
		$archive_path = wp_normalize_path( $archive_path );
		if ( ! LocalStorage::is_inside_storage( $archive_path ) || ! is_file( $archive_path ) ) {
			throw new RuntimeException( 'Restore archive is not available in Core Blueprint backup storage.' );
		}
		if ( Repository::has_active() ) {
			throw new RuntimeException( 'Another backup or restore job is already active.' );
		}
		$manifest = ArchiveValidator::validate( $archive_path, false );
		$plan     = MigrationPlan::build( $manifest );
		$type     = (string) ( $manifest['backup_type'] ?? 'database' );
		if ( 'website' === $type ) {
			$storage_stat = stat( dirname( $archive_path ) );
			$content_stat = stat( WP_CONTENT_DIR );
			if ( is_array( $storage_stat ) && is_array( $content_stat ) && isset( $storage_stat['dev'], $content_stat['dev'] ) && (int) $storage_stat['dev'] !== (int) $content_stat['dev'] ) {
				throw new RuntimeException( 'Full website restore requires Core Blueprint local restore storage and wp-content to be on the same filesystem for atomic recovery swaps.' );
			}
		}
		$staging_bytes = ArchiveValidator::estimate_uncompressed_bytes( $archive_path );
		$migration_bytes = ! empty( $plan['requires_migration'] ) ? ArchiveValidator::database_payload_bytes( $archive_path ) : 0;
		$required_bytes = $staging_bytes + $migration_bytes;
		$free_bytes = disk_free_space( LocalStorage::base_path() );
		$reserve_bytes = max( 104857600, (int) ceil( $required_bytes * 0.15 ) );
		if ( false !== $free_bytes && $free_bytes < $required_bytes + $reserve_bytes ) {
			throw new RuntimeException( 'Restore requires more free local disk space for verified staging and migration preparation.' );
		}

		$job = Repository::create( 'restore', $type, $trigger, [
			'archive_path'              => $archive_path,
			'manifest'                  => $manifest,
			'migration_plan'            => $plan,
			'restore_mode'              => (string) $plan['mode'],
			'started_timestamp'         => time(),
			'preflight_staging_bytes'   => $staging_bytes,
			'preflight_migration_bytes' => $migration_bytes,
			'preflight_free_bytes'      => false === $free_bytes ? null : (int) $free_bytes,
		] );
		if ( ! $job ) {
			throw new RuntimeException( 'Restore job could not be created.' );
		}
		Audit::log( 'backups.restore.started', 'warning', [
			'job_id'        => $job['job_id'],
			'type'          => $type,
			'trigger'       => $trigger,
			'mode'          => (string) $plan['mode'],
			'source_site'   => (string) $plan['source_site_url'],
			'target_site'   => (string) $plan['target_site_url'],
			'source_prefix' => (string) $plan['source_prefix'],
			'target_prefix' => (string) $plan['target_prefix'],
		] );
		Dispatcher::dispatch( (string) $job['job_id'] );
		wp_schedule_single_event( time() + 30, 'cb_backups_run_job', [ (string) $job['job_id'] ] );
		return $job;
	}
}
