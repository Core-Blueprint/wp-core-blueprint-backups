<?php
declare(strict_types=1);

namespace CB\Backups\Restore;

use CB\Backups\DB\ContentDigest;
use CB\Backups\DB\SqlValueCodec;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/** Re-read the immutable shadow snapshot before any live table is renamed. */
final class DatabaseContentVerifier {
	public static function tick( string $work, array $meta, array $tables, array $inventory, string $job_id ): array {
		return SqlValueCodec::with_dump_mode( static fn (): array => self::checkpoint_tick( $work, $meta, $tables, $inventory, $job_id ) );
	}

	private static function checkpoint_tick( string $work, array $meta, array $tables, array $inventory, string $job_id ): array {
		global $wpdb;
		ContentDigest::validate_inventory( $tables, $inventory );
		if ( empty( $meta['db_import_finished'] ) ) throw new RuntimeException( 'Cannot verify an incomplete shadow snapshot.' );
		$index = (int) ( $meta['db_content_verify_index'] ?? 0 );
		if ( $index >= count( $tables ) ) {
			$meta['db_content_verified'] = self::fingerprint( $tables, $inventory, $job_id );
			return [ 'done' => true, 'meta' => $meta, 'progress' => 100 ];
		}
		$table = $tables[ $index ];
		$shadow = DatabaseImporter::shadow_name( $job_id, $index );
		$expected = $inventory[ $table ];
		$dir = $work . '/content-shadow-' . $index;
		if ( ! isset( $meta['db_content_verify_state'] ) ) {
			if ( ContentDigest::columns( $shadow ) !== $expected['columns'] ) throw new RuntimeException( 'Shadow database column schema differs for ' . $table );
			$meta['db_content_verify_state'] = [];
			$meta['db_content_verify_offset'] = 0;
			$meta['db_content_verify_order'] = ContentDigest::table_order_by( $shadow, $expected['columns'] );
		}
		if ( empty( $meta['db_content_verify_read_done'] ) ) {
			$offset = (int) $meta['db_content_verify_offset'];
			$query = 'SELECT * FROM `' . str_replace( '`', '``', $shadow ) . '` ORDER BY ' . $meta['db_content_verify_order'] . ' LIMIT 500 OFFSET ' . $offset;
			$rows = $wpdb->get_results( $query, ARRAY_A );
			if ( '' !== $wpdb->last_error || ! is_array( $rows ) ) throw new RuntimeException( 'Could not read shadow content: ' . $wpdb->last_error );
			ContentDigest::append( $dir, $meta['db_content_verify_state'], $rows, $expected['columns'] );
			$meta['db_content_verify_offset'] += count( $rows );
			if ( $meta['db_content_verify_offset'] > $expected['rows'] ) throw new RuntimeException( 'Shadow snapshot contains extra rows in ' . $table );
			$meta['db_content_verify_read_done'] = count( $rows ) < 500;
		} elseif ( ContentDigest::finish_tick( $dir, $meta['db_content_verify_state'] ) ) {
			ContentDigest::assert_equal( $expected, ContentDigest::summary( $meta['db_content_verify_state'], $expected['columns'] ), $table );
			$meta['db_content_verify_index'] = $index + 1;
			unset( $meta['db_content_verify_state'], $meta['db_content_verify_offset'], $meta['db_content_verify_order'], $meta['db_content_verify_read_done'] );
		}
		return [ 'done' => false, 'meta' => $meta, 'progress' => (int) floor( 100 * $index / count( $tables ) ) ];
	}

	public static function assert_verified( array $meta, array $tables, string $job_id ): void {
		$inventory = $meta['manifest']['database']['content_integrity'] ?? [];
		ContentDigest::validate_inventory( $tables, $inventory );
		if ( ! hash_equals( self::fingerprint( $tables, $inventory, $job_id ), (string) ( $meta['db_content_verified'] ?? '' ) ) ) throw new RuntimeException( 'Database content must be verified before live commit.' );
	}

	private static function fingerprint( array $tables, array $inventory, string $job_id ): string {
		return hash( 'sha256', json_encode( [ $job_id, $tables, $inventory ], JSON_THROW_ON_ERROR ) );
	}
}
