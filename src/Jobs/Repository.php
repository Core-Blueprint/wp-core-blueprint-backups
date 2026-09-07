<?php
declare(strict_types=1);

namespace CB\Backups\Jobs;

use CB\Backups\DB\Schema;

defined( 'ABSPATH' ) || exit;

final class Repository {
	/** @return array<string,mixed> */
	public static function create( string $kind, string $type, string $trigger, array $meta = [] ): array {
		global $wpdb;
		$now    = current_time( 'mysql', true );
		$job_id = wp_generate_uuid4();
		$wpdb->insert(
			Schema::table(),
			[
				'job_id'         => $job_id,
				'kind'           => sanitize_key( $kind ),
				'backup_type'    => sanitize_key( $type ),
				'trigger_source' => sanitize_key( $trigger ),
				'status'         => 'queued',
				'stage'          => 'queued',
				'progress'       => 0,
				'meta'           => wp_json_encode( $meta ),
				'created_at'     => $now,
				'updated_at'     => $now,
			],
			[ '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s' ]
		);
		return self::get( $job_id ) ?? [];
	}

	/** @return array<string,mixed>|null */
	public static function get( string $job_id ): ?array {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . Schema::table() . ' WHERE job_id = %s LIMIT 1', $job_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return is_array( $row ) ? self::normalise( $row ) : null;
	}

	/**
	 * Find the newest Hub-created backup for one persistent Hub run.
	 *
	 * The Hub run UUID is stored inside request_context metadata rather than in
	 * its own column. Match the exact JSON key/value token so a lost HTTP start
	 * response can safely adopt a queued, running or already completed job
	 * without creating a duplicate backup. The UUID is validated before it is
	 * used in the bounded LIKE lookup and the returned metadata is verified
	 * again after normalisation.
	 *
	 * @return array<string,mixed>|null
	 */
	public static function find_hub_backup_by_run_id( string $run_id, string $type ): ?array {
		global $wpdb;

		$run_id = strtolower( trim( $run_id ) );
		$type   = sanitize_key( $type );
		if (
			! preg_match( '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $run_id )
			|| ! in_array( $type, [ 'database', 'website' ], true )
		) {
			return null;
		}

		$needle = '%"run_id":"' . $wpdb->esc_like( $run_id ) . '"%';
		$needle = str_replace( '\\"', '"', $needle );
		$sql    = $wpdb->prepare(
			'SELECT * FROM ' . Schema::table() . ' WHERE kind = %s AND backup_type = %s AND trigger_source = %s AND meta LIKE %s ORDER BY id DESC LIMIT 1', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			'backup',
			$type,
			'hub',
			$needle
		);
		$row = $wpdb->get_row( $sql, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $row ) ) {
			return null;
		}

		$job     = self::normalise( $row );
		$context = is_array( $job['meta']['request_context'] ?? null ) ? $job['meta']['request_context'] : [];
		return hash_equals( $run_id, strtolower( (string) ( $context['run_id'] ?? '' ) ) ) ? $job : null;
	}

	/** @param array<string,mixed> $fields */
	public static function update( string $job_id, array $fields ): void {
		global $wpdb;
		$allowed = [ 'status', 'stage', 'progress', 'archive_name', 'meta', 'error_text', 'completed_at' ];
		$data    = [];
		$formats = [];
		foreach ( $fields as $key => $value ) {
			if ( ! in_array( $key, $allowed, true ) ) {
				continue;
			}
			if ( 'meta' === $key && is_array( $value ) ) {
				$value = wp_json_encode( $value );
			}
			$data[ $key ] = $value;
			$formats[] = 'progress' === $key ? '%d' : '%s';
		}
		$data['updated_at'] = current_time( 'mysql', true );
		$formats[] = '%s';
		if ( ! $data ) {
			return;
		}
		$wpdb->update( Schema::table(), $data, [ 'job_id' => $job_id ], $formats, [ '%s' ] );
	}

	public static function fail( string $job_id, string $message ): void {
		self::update( $job_id, [
			'status'       => 'failed',
			'error_text'   => wp_strip_all_tags( $message ),
			'completed_at' => current_time( 'mysql', true ),
		] );
	}

	public static function complete( string $job_id, string $archive_name = '' ): void {
		self::update( $job_id, [
			'status'       => 'completed',
			'stage'        => 'completed',
			'progress'     => 100,
			'archive_name' => $archive_name,
			'completed_at' => current_time( 'mysql', true ),
		] );
	}

	public static function request_cancel( string $job_id ): bool {
		$job = self::get( $job_id );
		if ( ! $job || 'backup' !== (string) $job['kind'] || ! in_array( (string) $job['status'], [ 'queued', 'running' ], true ) ) {
			return false;
		}
		self::update( $job_id, [ 'status' => 'cancelling' ] );
		return true;
	}

	public static function cancelled( string $job_id ): void {
		self::update( $job_id, [
			'status'       => 'cancelled',
			'stage'        => 'cancelled',
			'completed_at' => current_time( 'mysql', true ),
		] );
	}

	public static function has_active( string $kind = '' ): bool {
		global $wpdb;
		$sql = "SELECT COUNT(*) FROM " . Schema::table() . " WHERE status IN ('queued','running','cancelling')"; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( '' !== $kind ) {
			$sql .= $wpdb->prepare( ' AND kind = %s', $kind );
		}
		return (int) $wpdb->get_var( $sql ) > 0; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
	}

	/** @return array<int,array<string,mixed>> */
	public static function active( int $limit = 10 ): array {
		global $wpdb;
		$limit = max( 1, min( 50, $limit ) );
		$rows  = $wpdb->get_results( "SELECT * FROM " . Schema::table() . " WHERE status IN ('queued','running','cancelling') ORDER BY id ASC LIMIT {$limit}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( [ self::class, 'normalise' ], is_array( $rows ) ? $rows : [] );
	}

	/** @return array<int,array<string,mixed>> */
	public static function recent( int $limit = 20 ): array {
		global $wpdb;
		$limit = max( 1, min( 100, $limit ) );
		$rows  = $wpdb->get_results( "SELECT * FROM " . Schema::table() . " ORDER BY id DESC LIMIT {$limit}", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		return array_map( [ self::class, 'normalise' ], is_array( $rows ) ? $rows : [] );
	}

	/**
	 * Latest attempt for each exact archive path, including already completed imports.
	 * Project only presentation fields; restore metadata can contain large digests.
	 * @param string[] $archive_paths
	 * @return array<string,array<string,mixed>>
	 */
	public static function latest_restores_for_archives( array $archive_paths ): array {
		global $wpdb;
		$paths = array_values( array_unique( array_filter( array_map( 'wp_normalize_path', $archive_paths ) ) ) );
		if ( ! $paths ) return [];
		$table = Schema::table();
		$document = "CASE WHEN JSON_VALID(meta) THEN meta ELSE '{}' END";
		// Filesystem identity is case-sensitive, regardless of the table collation.
		$path = "CAST(JSON_UNQUOTE(JSON_EXTRACT({$document}, '$.archive_path')) AS BINARY)";
		$mode = "JSON_UNQUOTE(JSON_EXTRACT({$document}, '$.restore_mode'))";
		$placeholders = implode( ',', array_fill( 0, count( $paths ), '%s' ) );
		$sql = "SELECT job_id, status, progress, created_at, completed_at,
			{$path} AS restore_archive_path, {$mode} AS restore_mode
			FROM {$table} WHERE id IN (
				SELECT MAX(id) FROM {$table}
				WHERE kind = 'restore' AND {$path} IN ({$placeholders})
				GROUP BY {$path}
			)";
		$rows = $wpdb->get_results( $wpdb->prepare( $sql, ...$paths ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( ! is_array( $rows ) || '' !== (string) $wpdb->last_error ) {
			throw new \RuntimeException( 'Restore history could not be read.' );
		}
		$latest = [];
		foreach ( $rows as $row ) {
			$row['progress'] = (int) $row['progress'];
			$latest[ (string) $row['restore_archive_path'] ] = $row;
		}
		return $latest;
	}

	/** @param array<string,mixed> $row @return array<string,mixed> */
	private static function normalise( array $row ): array {
		$meta = [];
		if ( ! empty( $row['meta'] ) ) {
			$decoded = json_decode( (string) $row['meta'], true );
			$meta = is_array( $decoded ) ? $decoded : [];
		}
		$row['meta']     = $meta;
		$row['progress'] = (int) ( $row['progress'] ?? 0 );
		return $row;
	}
}
