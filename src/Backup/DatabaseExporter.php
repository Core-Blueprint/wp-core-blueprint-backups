<?php
declare(strict_types=1);

namespace CB\Backups\Backup;

use CB\Backups\DB\SqlValueCodec;
use CB\Backups\DB\ContentDigest;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are internal diagnostics; UI/HTTP presentation boundaries escape them.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Backup processing uses bounded native streams/atomic filesystem primitives; WP_Filesystem is not suitable for these server-owned jobs.

final class DatabaseExporter {
	private const DEFAULT_ROWS_PER_CHUNK = 2000;
	private const MIN_ROWS_PER_CHUNK = 500;
	private const MAX_ROWS_PER_CHUNK = 5000;
	private const INSERT_ROWS_PER_STATEMENT = 50;
	private const INSERT_BYTES_PER_STATEMENT = 1048576;

	/**
	 * Advance the database export by one resumable chunk.
	 *
	 * @param array<string,mixed> $meta
	 * @return array{done:bool,meta:array<string,mixed>,progress:int}
	 */
	public static function tick( string $work_dir, array $meta ): array {
		return SqlValueCodec::with_dump_mode( static fn (): array => self::checkpoint_tick( $work_dir, $meta ) );
	}

	private static function checkpoint_tick( string $work_dir, array $meta ): array {
		if ( ! empty( $meta['db_tables'] ) && ! isset( $meta['db_sql_cursor'] ) ) throw new RuntimeException( 'This export started with an older runtime. Start a new backup job.' );
		$file = $work_dir . '/database.sql';
		$cursor = (int) ( $meta['db_sql_cursor'] ?? 0 );
		$handle = fopen( $file, 'c+b' );
		if ( false === $handle ) throw new RuntimeException( 'Cannot open database export checkpoint.' );
		try {
			if ( fstat( $handle )['size'] < $cursor || ! ftruncate( $handle, $cursor ) ) throw new RuntimeException( 'Database export checkpoint is incomplete.' );
		} finally { fclose( $handle ); }
		$result = self::advance( $work_dir, $meta );
		clearstatcache( true, $file );
		$result['meta']['db_sql_cursor'] = filesize( $file );
		return $result;
	}

	private static function advance( string $work_dir, array $meta ): array {
		global $wpdb;

		$sql_file = $work_dir . '/database.sql';
		$tables   = isset( $meta['db_tables'] ) && is_array( $meta['db_tables'] ) ? array_values( $meta['db_tables'] ) : [];
		if ( ! $tables ) {
			$inventory = self::discover_table_inventory();
			$tables    = $inventory['tables'];
			if ( ! $tables ) {
				throw new RuntimeException( 'No WordPress database tables were found.' );
			}
			$meta['db_tables']                  = $tables;
			$meta['db_table_row_estimates']     = $inventory['row_estimates'];
			$meta['db_rows_estimated_total']    = array_sum( $inventory['row_estimates'] );
			$meta['db_rows_done']               = 0;
			$meta['db_tables_total']            = count( $tables );
			$meta['db_tables_done']             = 0;
			$meta['db_table_index']             = 0;
			$meta['db_offset']                  = 0;
			$meta['db_chunk_rows']              = self::DEFAULT_ROWS_PER_CHUNK;
			$meta['db_estimates_are_approximate'] = true;
			self::write(
				$sql_file,
				"-- Core Blueprint Backups database export\n-- Format version: 1\nSET FOREIGN_KEY_CHECKS=0;\n",
				false
			);
		}

		$table_index = max( 0, (int) ( $meta['db_table_index'] ?? 0 ) );
		$offset      = max( 0, (int) ( $meta['db_offset'] ?? 0 ) );
		$total       = count( $tables );

		if ( $table_index >= $total ) {
			if ( empty( $meta['db_finished'] ) ) {
				self::write( $sql_file, "SET FOREIGN_KEY_CHECKS=1;\n", true );
				$meta['db_finished']   = true;
				$meta['db_rows_total'] = (int) ( $meta['db_rows_done'] ?? 0 );
				$meta['db_export_bytes'] = filesize( $sql_file ) ?: 0;
			}
			return [ 'done' => true, 'meta' => $meta, 'progress' => 100 ];
		}

		$table = (string) $tables[ $table_index ];
		self::assert_safe_table( $table );

		if ( (string) ( $meta['db_current_table'] ?? '' ) !== $table ) {
			$meta   = self::initialise_table( $sql_file, $table, $meta );
			$offset = 0;
		}

		$digest_dir = $work_dir . '/content-export-' . $table_index;
		if ( ! empty( $meta['db_content_pending'] ) ) {
			if ( ContentDigest::finish_tick( $digest_dir, $meta['db_content_state'] ) ) {
				$meta['db_content_integrity'][ $table ] = ContentDigest::summary( $meta['db_content_state'], $meta['db_content_columns'] );
				self::write( $sql_file, "-- CB END TABLE: {$table}\n", true );
				++$meta['db_table_index'];
				++$meta['db_tables_done'];
				$meta = self::clear_table_state( $meta );
				unset( $meta['db_content_pending'], $meta['db_content_state'], $meta['db_content_columns'] );
			}
			return [ 'done' => false, 'meta' => $meta, 'progress' => self::progress( $meta, $table_index, $total ) ];
		}

		$binary_columns = isset( $meta['db_binary_columns'] ) && is_array( $meta['db_binary_columns'] ) ? array_values( $meta['db_binary_columns'] ) : [];
		$primary_key    = isset( $meta['db_primary_key'] ) && is_array( $meta['db_primary_key'] ) ? array_values( $meta['db_primary_key'] ) : [];
		$strategy       = (string) ( $meta['db_strategy'] ?? 'offset' );
		$chunk_rows     = max( self::MIN_ROWS_PER_CHUNK, min( self::MAX_ROWS_PER_CHUNK, (int) ( $meta['db_chunk_rows'] ?? self::DEFAULT_ROWS_PER_CHUNK ) ) );
		$chunk_started  = microtime( true );

		if ( 'keyset' === $strategy ) {
			$rows = self::select_keyset_rows( $table, $primary_key, $meta, $chunk_rows );
		} else {
			$query = $wpdb->prepare(
				'SELECT * FROM `' . self::escape_identifier( $table ) . '` ORDER BY ' . ContentDigest::order_by( $meta['db_content_columns'] ) . ' LIMIT %d OFFSET %d', // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				$chunk_rows,
				$offset
			);
			$rows = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			throw new RuntimeException( sprintf( 'Could not export rows from %s.', $table ) );
		}

		if ( $rows ) {
			ContentDigest::append( $digest_dir, $meta['db_content_state'], $rows, $meta['db_content_columns'] );
			self::append_rows( $sql_file, $table, $rows, $binary_columns );
			$count = count( $rows );
			$meta['db_rows_done'] = (int) ( $meta['db_rows_done'] ?? 0 ) + $count;
			$meta['db_current_table_rows_done'] = (int) ( $meta['db_current_table_rows_done'] ?? 0 ) + $count;
		}

		$table_complete = count( $rows ) < $chunk_rows;
		if ( 'keyset' === $strategy && $rows ) {
			$last   = $rows[ array_key_last( $rows ) ];
			$cursor = [];
			foreach ( $primary_key as $column ) {
				$cursor[ $column ] = $last[ $column ] ?? null;
			}
			$meta['db_primary_last'] = $cursor;
			if ( self::cursor_reached_max( $cursor, isset( $meta['db_primary_max'] ) && is_array( $meta['db_primary_max'] ) ? $meta['db_primary_max'] : [] ) ) {
				$table_complete = true;
			}
		}

		if ( $table_complete ) {
			$meta['db_content_pending'] = true;
		} elseif ( 'offset' === $strategy ) {
			$offset += $chunk_rows;
		}

		$elapsed = max( 0.0001, microtime( true ) - $chunk_started );
		$meta['db_last_chunk_seconds'] = round( $elapsed, 4 );
		$meta['db_chunk_rows']          = self::next_chunk_size( $chunk_rows, $elapsed, count( $rows ) );
		$meta['db_table_index']         = $table_index;
		$meta['db_offset']              = $offset;
		$meta['db_export_bytes']        = filesize( $sql_file ) ?: 0;
		$meta['db_rows_estimated_total'] = self::adjusted_row_estimate( $meta, $tables, $table_index );

		return [
			'done'     => false,
			'meta'     => $meta,
			'progress' => self::progress( $meta, $table_index, $total ),
		];
	}

	/** @param array<string,mixed> $meta @return array<string,mixed> */
	private static function initialise_table( string $sql_file, string $table, array $meta ): array {
		global $wpdb;

		$create_row = $wpdb->get_row( $wpdb->prepare( 'SHOW CREATE TABLE %i', $table ), ARRAY_N );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $create_row ) || empty( $create_row[1] ) ) {
			throw new RuntimeException( sprintf( 'Could not read table definition for %s.', $table ) );
		}

		$columns = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i', $table ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $columns ) || ! $columns ) {
			throw new RuntimeException( sprintf( 'Could not read column metadata for %s.', $table ) );
		}

		$binary_columns = [];
		$column_types   = [];
		foreach ( $columns as $column ) {
			$name = isset( $column['Field'] ) ? (string) $column['Field'] : '';
			$type = strtolower( isset( $column['Type'] ) ? (string) $column['Type'] : '' );
			if ( '' === $name ) {
				continue;
			}
			if ( preg_match( '/\b(?:geometry|point|linestring|polygon|multipoint|multilinestring|multipolygon|geometrycollection)\b/i', $type ) ) {
				throw new RuntimeException( sprintf( 'Database table %s contains spatial column %s, which backup format v1 does not support yet.', $table, $name ) );
			}
			$column_types[ $name ] = $type;
			if ( preg_match( '/(?:binary|blob|bit)/i', $type ) ) {
				$binary_columns[] = $name;
			}
		}

		$primary_key = self::primary_key_columns( $table );
		$strategy    = 'offset';
		$watermark   = [];
		if ( $primary_key && ! array_intersect( $primary_key, $binary_columns ) ) {
			$watermark = self::primary_watermark( $table, $primary_key );
			$strategy  = 'keyset';
		}

		$create = str_replace( [ "\r", "\n" ], ' ', (string) $create_row[1] );
		if ( preg_match( '/\bFOREIGN\s+KEY\b/i', $create ) ) {
			throw new RuntimeException( sprintf( 'Database table %s uses foreign-key constraints, which backup format v1 does not support yet.', $table ) );
		}
		$header = "\n-- CB TABLE: {$table}\nDROP TABLE IF EXISTS `" . self::escape_identifier( $table ) . "`;\n{$create};\n";
		self::write( $sql_file, $header, true );

		$estimates = isset( $meta['db_table_row_estimates'] ) && is_array( $meta['db_table_row_estimates'] ) ? $meta['db_table_row_estimates'] : [];
		$meta['db_content_state'] = [];
		$meta['db_content_columns'] = ContentDigest::columns( $table );
		$meta['db_current_table']                = $table;
		$meta['db_current_table_rows_done']      = 0;
		$meta['db_current_table_rows_estimated'] = max( 0, (int) ( $estimates[ $table ] ?? 0 ) );
		$meta['db_binary_columns']               = $binary_columns;
		$meta['db_primary_key']                  = $primary_key;
		$meta['db_primary_max']                  = $watermark;
		$meta['db_primary_last']                 = [];
		$meta['db_strategy']                     = $strategy;
		$meta['db_column_types']                 = $column_types;
		$meta['db_offset']                       = 0;
		return $meta;
	}

	/** @param string[] $primary_key @param array<string,mixed> $meta @return array<int,array<string,mixed>> */
	private static function select_keyset_rows( string $table, array $primary_key, array $meta, int $limit ): array {
		global $wpdb;

		$maximum = isset( $meta['db_primary_max'] ) && is_array( $meta['db_primary_max'] ) ? $meta['db_primary_max'] : [];
		if ( ! $maximum ) {
			return [];
		}
		$last         = isset( $meta['db_primary_last'] ) && is_array( $meta['db_primary_last'] ) ? $meta['db_primary_last'] : [];
		$identifiers  = implode( ', ', array_map( static fn ( string $column ): string => '`' . self::escape_identifier( $column ) . '`', $primary_key ) );
		$placeholders = implode( ', ', array_fill( 0, count( $primary_key ), '%s' ) );
		$values       = [];

		$where = '(' . $identifiers . ') <= (' . $placeholders . ')';
		foreach ( $primary_key as $column ) {
			$values[] = (string) ( $maximum[ $column ] ?? '' );
		}
		if ( $last ) {
			$where  = '(' . $identifiers . ') > (' . $placeholders . ') AND ' . $where;
			$before = [];
			foreach ( $primary_key as $column ) {
				$before[] = (string) ( $last[ $column ] ?? '' );
			}
			$values = array_merge( $before, $values );
		}

		$sql   = 'SELECT * FROM `' . self::escape_identifier( $table ) . '` WHERE ' . $where . ' ORDER BY ' . $identifiers . ' ASC LIMIT ' . $limit; // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$query = $wpdb->prepare( $sql, ...$values ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- SQL template contains only verified identifiers and placeholders; values are prepared here.
		$rows  = $wpdb->get_results( $query, ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			throw new RuntimeException( sprintf( 'Could not export rows from %s: %s', $table, $wpdb->last_error ) );
		}
		return $rows;
	}

	/** @param string[] $primary_key @return array<string,mixed> */
	private static function primary_watermark( string $table, array $primary_key ): array {
		global $wpdb;
		$order = implode( ', ', array_map( static fn ( string $column ): string => '`' . self::escape_identifier( $column ) . '` DESC', $primary_key ) );
		$row   = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i ORDER BY ' . $order . ' LIMIT 1', $table ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- ORDER BY contains only verified primary-key identifiers.
		if ( '' !== (string) $wpdb->last_error ) throw new RuntimeException( 'Could not read database primary-key watermark: ' . $wpdb->last_error );
		if ( ! is_array( $row ) ) {
			return [];
		}
		$watermark = [];
		foreach ( $primary_key as $column ) {
			$watermark[ $column ] = $row[ $column ] ?? null;
		}
		return $watermark;
	}

	/** @return string[] */
	private static function primary_key_columns( string $table ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( "SHOW KEYS FROM %i WHERE Key_name = 'PRIMARY'", $table ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) {
			throw new RuntimeException( 'Could not inspect primary keys: ' . $wpdb->last_error );
		}
		usort( $rows, static fn ( array $a, array $b ): int => (int) ( $a['Seq_in_index'] ?? 0 ) <=> (int) ( $b['Seq_in_index'] ?? 0 ) );
		$columns = [];
		foreach ( $rows as $row ) {
			if ( ! empty( $row['Column_name'] ) ) {
				$columns[] = (string) $row['Column_name'];
			}
		}
		return $columns;
	}

	/** @param array<int,array<string,mixed>> $rows @param string[] $binary_columns */
	private static function append_rows( string $sql_file, string $table, array $rows, array $binary_columns ): void {
		global $wpdb;
		$columns    = array_keys( $rows[0] );
		$column_sql = implode( ', ', array_map( static fn ( string $column ): string => '`' . self::escape_identifier( $column ) . '`', $columns ) );
		$binary_map = array_fill_keys( $binary_columns, true );
		$handle     = fopen( $sql_file, 'ab' );
		if ( false === $handle || ! flock( $handle, LOCK_EX ) ) {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
			throw new RuntimeException( 'Database export could not be opened for writing.' );
		}

		try {
			$header = 'INSERT INTO `' . self::escape_identifier( $table ) . '` (' . $column_sql . ') VALUES ';
			$values_sql = []; $bytes = strlen( $header );
			$flush = static function () use ( $handle, $header, &$values_sql, &$bytes ): void {
				if ( ! $values_sql ) return;
				$insert = $header . implode( ', ', $values_sql ) . ";\n";
				if ( fwrite( $handle, $insert ) !== strlen( $insert ) ) throw new RuntimeException( 'Database export could not be written to disk.' );
				$values_sql = []; $bytes = strlen( $header );
			};
			foreach ( $rows as $row ) {
				$values = [];
				foreach ( $columns as $column ) {
					$value = $row[ $column ] ?? null;
					$values[] = SqlValueCodec::encode( null === $value ? null : (string) $value, isset( $binary_map[ $column ] ) );
				}
				$tuple = '(' . implode( ', ', $values ) . ')';
				if ( $values_sql && ( count( $values_sql ) >= self::INSERT_ROWS_PER_STATEMENT || $bytes + strlen( $tuple ) + 4 > self::INSERT_BYTES_PER_STATEMENT ) ) $flush();
				$values_sql[] = $tuple; $bytes += strlen( $tuple ) + 2;
			}
			$flush();
			if ( ! fflush( $handle ) ) throw new RuntimeException( 'Database export could not be flushed.' );
		} finally {
			flock( $handle, LOCK_UN );
			fclose( $handle );
		}
	}

	/** @return array{tables:string[],row_estimates:array<string,int>} */
	private static function discover_table_inventory(): array {
		global $wpdb;
		$like = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL TABLES LIKE %s', $like ), ARRAY_N );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $rows ) ) throw new RuntimeException( 'Could not discover database tables: ' . $wpdb->last_error );
		$tables = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$table = isset( $row[0] ) ? (string) $row[0] : '';
			$type  = isset( $row[1] ) ? strtoupper( (string) $row[1] ) : 'BASE TABLE';
			if ( '' === $table || 'BASE TABLE' !== $type || ! str_starts_with( $table, $wpdb->prefix ) ) {
				continue;
			}
			if ( class_exists( '\\CB\\Backups\\DB\\Schema' ) && $table === \CB\Backups\DB\Schema::table() ) {
				continue;
			}
			$tables[] = $table;
		}
		sort( $tables, SORT_STRING );

		$estimates = array_fill_keys( $tables, 0 );
		$status_rows = $wpdb->get_results( $wpdb->prepare( 'SHOW TABLE STATUS LIKE %s', $like ), ARRAY_A );
		if ( '' !== (string) $wpdb->last_error || ! is_array( $status_rows ) ) throw new RuntimeException( 'Could not read database table status: ' . $wpdb->last_error );
		foreach ( is_array( $status_rows ) ? $status_rows : [] as $status ) {
			$name = isset( $status['Name'] ) ? (string) $status['Name'] : '';
			if ( isset( $estimates[ $name ] ) ) {
				$estimates[ $name ] = max( 0, (int) ( $status['Rows'] ?? 0 ) );
			}
		}

		return [ 'tables' => $tables, 'row_estimates' => $estimates ];
	}

	/** @param array<string,mixed> $meta @return array<string,mixed> */
	private static function clear_table_state( array $meta ): array {
		foreach ( [ 'db_current_table', 'db_current_table_rows_done', 'db_current_table_rows_estimated', 'db_binary_columns', 'db_primary_key', 'db_primary_max', 'db_primary_last', 'db_strategy', 'db_column_types' ] as $key ) {
			unset( $meta[ $key ] );
		}
		return $meta;
	}

	/** @param array<string,mixed> $meta @param string[] $tables */
	private static function adjusted_row_estimate( array $meta, array $tables, int $table_index ): int {
		$done      = max( 0, (int) ( $meta['db_rows_done'] ?? 0 ) );
		$estimates = isset( $meta['db_table_row_estimates'] ) && is_array( $meta['db_table_row_estimates'] ) ? $meta['db_table_row_estimates'] : [];
		$remaining = 0;
		foreach ( $tables as $index => $table ) {
			if ( $index < $table_index ) {
				continue;
			}
			$estimate = max( 0, (int) ( $estimates[ $table ] ?? 0 ) );
			if ( $index === $table_index && (string) ( $meta['db_current_table'] ?? '' ) === $table ) {
				$estimate = max( 0, $estimate - (int) ( $meta['db_current_table_rows_done'] ?? 0 ) );
			}
			$remaining += $estimate;
		}
		return max( $done, $done + $remaining );
	}

	/** @param array<string,mixed> $meta */
	private static function progress( array $meta, int $table_index, int $total_tables ): int {
		$rows_done      = max( 0, (int) ( $meta['db_rows_done'] ?? 0 ) );
		$rows_estimated = max( 0, (int) ( $meta['db_rows_estimated_total'] ?? 0 ) );
		if ( $rows_estimated > 0 ) {
			$denominator = max( $rows_estimated, $rows_done + ( $table_index < $total_tables ? 1 : 0 ) );
			return min( 99, (int) floor( ( $rows_done / $denominator ) * 100 ) );
		}
		return min( 99, (int) floor( ( $table_index / max( 1, $total_tables ) ) * 100 ) );
	}

	private static function next_chunk_size( int $current, float $elapsed, int $rows_returned ): int {
		if ( $rows_returned < $current ) {
			return $current;
		}
		if ( $elapsed < 0.20 ) {
			return min( self::MAX_ROWS_PER_CHUNK, max( $current + 250, (int) floor( $current * 1.5 ) ) );
		}
		if ( $elapsed > 1.25 ) {
			return max( self::MIN_ROWS_PER_CHUNK, (int) floor( $current / 2 ) );
		}
		return $current;
	}

	/** @param array<string,mixed> $cursor @param array<string,mixed> $maximum */
	private static function cursor_reached_max( array $cursor, array $maximum ): bool {
		if ( ! $cursor || count( $cursor ) !== count( $maximum ) ) {
			return false;
		}
		foreach ( $cursor as $key => $value ) {
			if ( ! array_key_exists( $key, $maximum ) || (string) $value !== (string) $maximum[ $key ] ) {
				return false;
			}
		}
		return true;
	}

	private static function write( string $path, string $contents, bool $append ): void {
		$flags  = LOCK_EX | ( $append ? FILE_APPEND : 0 );
		$result = file_put_contents( $path, $contents, $flags );
		if ( false === $result || $result !== strlen( $contents ) ) {
			throw new RuntimeException( 'Database export could not be written to disk.' );
		}
	}

	private static function assert_safe_table( string $table ): void {
		global $wpdb;
		if ( '' === $table || ! str_starts_with( $table, $wpdb->prefix ) || ! preg_match( '/^[A-Za-z0-9_$-]+$/', $table ) ) {
			throw new RuntimeException( 'Unsafe database table name encountered.' );
		}
	}

	private static function escape_identifier( string $identifier ): string {
		return str_replace( '`', '``', $identifier );
	}
}
