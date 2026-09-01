<?php
declare(strict_types=1);

namespace CB\Backups\Backup;

defined( 'ABSPATH' ) || exit;

final class Manifest {
	/** @param array<string,mixed> $job @param array<int,array<string,mixed>>|int $checksums @param array<string,mixed> $meta @return array<string,mixed> */
	public static function build( array $job, array|int $checksums, array $meta = [] ): array {
		global $wpdb, $wp_version;
		$type = (string) ( $job['backup_type'] ?? 'database' );
		$checksums_count = is_int( $checksums ) ? $checksums : count( $checksums );
		return [
			'format'         => 'core-blueprint-backup',
			'schema_version' => 1,
			'plugin_version' => CB_BACKUPS_VERSION,
			'job_id'         => (string) ( $job['job_id'] ?? '' ),
			'backup_type'    => $type,
			'created_at'     => gmdate( 'c' ),
			'components'     => [
				'database'   => true,
				'filesystem' => 'website' === $type,
			],
			'database'       => [
				'tables'       => isset( $meta['db_tables'] ) && is_array( $meta['db_tables'] ) ? array_values( $meta['db_tables'] ) : [],
				'content_integrity' => $meta['db_content_integrity'] ?? [],
				'table_count'  => (int) ( $meta['db_tables_total'] ?? 0 ),
				'row_count'    => (int) ( $meta['db_rows_total'] ?? $meta['db_rows_done'] ?? 0 ),
			],
			'filesystem'     => [
				'file_count'    => (int) ( $meta['files_total'] ?? 0 ),
				'bytes'         => (int) ( $meta['files_bytes_total'] ?? 0 ),
				'top_levels'    => isset( $meta['top_levels'] ) && is_array( $meta['top_levels'] ) ? array_values( $meta['top_levels'] ) : [],
			],
			'site' => [
				'home_url'        => home_url( '/' ),
				'site_url'        => site_url( '/' ),
				'wordpress'       => (string) $wp_version,
				'php'             => PHP_VERSION,
				'database_server' => (string) $wpdb->db_version(),
				'table_prefix'    => (string) $wpdb->prefix,
				'multisite'       => is_multisite(),
			],
			'telemetry'      => [
				'started_timestamp' => (int) ( $meta['started_timestamp'] ?? 0 ),
				'database_rows'     => (int) ( $meta['db_rows_total'] ?? $meta['db_rows_done'] ?? 0 ),
				'database_tables'   => (int) ( $meta['db_tables_total'] ?? 0 ),
				'files'             => (int) ( $meta['files_total'] ?? 0 ),
				'filesystem_bytes'  => (int) ( $meta['files_bytes_total'] ?? 0 ),
			],
			'checksums_count' => $checksums_count,
			'exclusions'      => [
				'backup-storage',
				'wp-content/cache',
				'wp-content/upgrade',
				'wp-content/.cb-restore-work',
				'wp-content/.git',
				'wp-content/.svn',
				'symlinks',
			],
		];
	}
}
