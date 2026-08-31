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
