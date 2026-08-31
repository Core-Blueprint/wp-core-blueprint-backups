<?php
declare(strict_types=1);

namespace CB\Backups\Restore;

use CB\Backups\DB\Schema;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class DatabaseImporter {
	private const STATEMENTS_PER_TICK = 8;
	private const BYTES_PER_TICK = 1048576;

	/**
	 * Build the entire verified database snapshot in shadow tables while the
	 * live database remains untouched. The live switch is performed later by
	 * commit_snapshot() as one short RENAME TABLE transaction-like operation.
	 *
	 * @param array<string,mixed> $meta
	 * @param string[]            $expected_tables
	 * @return array{done:bool,meta:array<string,mixed>,progress:int}
	 */
	public static function tick( string $sql_file, array $meta, array $expected_tables, string $job_id ): array {
		global $wpdb;

		if ( ! is_file( $sql_file ) || ! is_readable( $sql_file ) ) {
			throw new RuntimeException( 'Database export is missing from the staged restore.' );
		}
		$size = filesize( $sql_file );
		if ( false === $size || $size < 1 ) {
			throw new RuntimeException( 'Database export is empty.' );
		}

		$expected_tables = self::normalise_expected_tables( $expected_tables );
		if ( ! $expected_tables ) {
			throw new RuntimeException( 'Backup manifest does not contain a database table inventory.' );
		}

		$offset = max( 0, (int) ( $meta['db_import_offset'] ?? 0 ) );
		if ( $offset > $size ) {
			throw new RuntimeException( 'Database restore checkpoint is outside the SQL export.' );
		}

		$handle = fopen( $sql_file, 'rb' );
		if ( false === $handle ) {
			throw new RuntimeException( 'Database export could not be opened.' );
		}
		if ( $offset > 0 && 0 !== fseek( $handle, $offset ) ) {
			fclose( $handle );
			throw new RuntimeException( 'Database restore checkpoint could not be resumed.' );
		}

		$old_foreign_key_checks = (string) $wpdb->get_var( 'SELECT @@SESSION.FOREIGN_KEY_CHECKS' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$old_sql_mode           = (string) $wpdb->get_var( 'SELECT @@SESSION.SQL_MODE' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'SET SESSION FOREIGN_KEY_CHECKS=0' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( "SET SESSION SQL_MODE=REPLACE(@@SESSION.SQL_MODE,'NO_BACKSLASH_ESCAPES','')" ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared

		$statements = 0;
		$bytes      = 0;
		$finished_table = false;
		try {
			while ( false !== ( $line = fgets( $handle ) ) ) {
				$bytes += strlen( $line );
				$statement = trim( $line );
				if ( '' === $statement ) {
					continue;
				}

				if ( str_starts_with( $statement, '-- CB TABLE: ' ) ) {
					self::begin_table( substr( $statement, strlen( '-- CB TABLE: ' ) ), $meta, $expected_tables, $job_id );
					continue;
				}
				if ( str_starts_with( $statement, '-- CB END TABLE: ' ) ) {
					self::finish_table( substr( $statement, strlen( '-- CB END TABLE: ' ) ), $meta, $expected_tables, $job_id );
					$finished_table = true;
					break;
				}
				if ( str_starts_with( $statement, '--' ) || self::is_format_control_statement( $statement ) ) {
					continue;
				}

				$current = (string) ( $meta['db_restore_current_table'] ?? '' );
				if ( '' === $current ) {
					throw new RuntimeException( 'Database export contains SQL outside a table boundary.' );
				}
				$shadow = self::shadow_name( $job_id, self::current_index( $meta ) );
				$sql = self::rewrite_statement_for_shadow( $statement, $current, $shadow );
				$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- checksummed CB-generated SQL rewritten to a controlled shadow table.
				if ( false === $result ) {
					throw new RuntimeException( 'Database restore failed: ' . (string) $wpdb->last_error );
				}
				++$statements;

				if ( $statements >= self::STATEMENTS_PER_TICK || $bytes >= self::BYTES_PER_TICK ) {
					break;
				}
			}

			$position = ftell( $handle );
			if ( false === $position ) {
				throw new RuntimeException( 'Database restore checkpoint could not be recorded.' );
			}
			$meta['db_import_offset'] = $position;
			$done = feof( $handle );
		} finally {
			fclose( $handle );
			$wpdb->query( $wpdb->prepare( 'SET SESSION FOREIGN_KEY_CHECKS=%d', 0 === (int) $old_foreign_key_checks ? 0 : 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			$wpdb->query( $wpdb->prepare( 'SET SESSION SQL_MODE=%s', $old_sql_mode ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		if ( $done ) {
			if ( '' !== (string) ( $meta['db_restore_current_table'] ?? '' ) ) {
				throw new RuntimeException( 'Database export ended before the current table was completed.' );
			}
			if ( self::current_index( $meta ) !== count( $expected_tables ) ) {
				throw new RuntimeException( 'Database export does not contain every table declared in the manifest.' );
			}
			$meta['db_import_finished'] = true;
		}

		$progress = (int) floor( min( 1, (int) $meta['db_import_offset'] / max( 1, $size ) ) * 100 );
		if ( $done ) {
			$progress = 100;
		} elseif ( $finished_table ) {
			$progress = max( $progress, (int) floor( ( self::current_index( $meta ) / max( 1, count( $expected_tables ) ) ) * 100 ) );
		}

		return [ 'done' => $done, 'meta' => $meta, 'progress' => min( 100, $progress ) ];
	}

	/**
	 * Persist the entire live-commit plan before any live table is renamed. This
	 * checkpoint lets a later worker recover even if PHP disappears immediately
	 * after MySQL has atomically executed the RENAME TABLE statement.
	 *
	 * @param array<string,mixed> $meta
	 * @param string[]            $expected_tables
	 * @return array<string,mixed>
	 */
	public static function prepare_commit( array $meta, array $expected_tables, string $job_id ): array {
		$expected_tables = self::normalise_expected_tables( $expected_tables );
		if ( ! empty( $meta['db_commit_prepared'] ) ) {
			return $meta;
		}
		if ( empty( $meta['db_import_finished'] ) || self::current_index( $meta ) !== count( $expected_tables ) ) {
			throw new RuntimeException( 'Database shadow snapshot is incomplete and cannot be prepared for commit.' );
		}

		$committed = [];
		$protected = [ Schema::table() ];
		foreach ( $expected_tables as $index => $table ) {
			$shadow = self::shadow_name( $job_id, (int) $index );
			$recovery = self::recovery_name( $job_id, (int) $index );
			if ( ! self::table_exists( $shadow ) ) {
				throw new RuntimeException( sprintf( 'Restore shadow table is missing for %s.', $table ) );
			}
			if ( self::table_exists( $recovery ) ) {
				throw new RuntimeException( sprintf( 'Restore recovery collision detected for %s.', $table ) );
			}
			$committed[] = [
				'table'        => $table,
				'index'        => (int) $index,
				'had_original' => self::table_exists( $table ),
			];
			$protected[] = $table;
			$protected[] = $shadow;
			$protected[] = $recovery;
		}

		$extra_plan = self::extra_table_plan( $protected );
		foreach ( $extra_plan as $index => $table ) {
			if ( self::table_exists( self::extra_recovery_name( $job_id, (int) $index ) ) ) {
				throw new RuntimeException( sprintf( 'Restore recovery collision detected for extra table %s.', $table ) );
			}
		}

		$meta['db_restore_committed'] = $committed;
		$meta['db_restore_extra_plan'] = $extra_plan;
		$meta['db_restore_extra_index'] = 0;
		$meta['db_commit_prepared'] = true;
		return $meta;
	}

	/**
	 * Atomically expose the fully-built shadow snapshot. The commit plan must
	 * already have been persisted by prepare_commit().
	 *
	 * @param array<string,mixed> $meta
	 * @param string[]            $expected_tables
	 * @return array<string,mixed>
	 */
	public static function commit_snapshot( array $meta, array $expected_tables, string $job_id ): array {
		global $wpdb;
		$expected_tables = self::normalise_expected_tables( $expected_tables );
		if ( empty( $meta['db_commit_prepared'] ) ) {
			throw new RuntimeException( 'Database live commit plan was not durably prepared.' );
		}
		$state = self::commit_state( $meta, $expected_tables, $job_id );
		if ( 'committed' === $state ) {
			$meta['db_live_committed'] = true;
			$meta['db_restore_extra_index'] = count( is_array( $meta['db_restore_extra_plan'] ?? null ) ? $meta['db_restore_extra_plan'] : [] );
			return $meta;
		}
		if ( 'prepared' !== $state ) {
			throw new RuntimeException( 'Database live commit state is inconsistent.' );
		}

		$pairs = [];
		$committed = is_array( $meta['db_restore_committed'] ?? null ) ? $meta['db_restore_committed'] : [];
		foreach ( $committed as $entry ) {
			if ( ! is_array( $entry ) ) {
				throw new RuntimeException( 'Database live commit plan is malformed.' );
			}
			$table = (string) ( $entry['table'] ?? '' );
			$index = (int) ( $entry['index'] ?? -1 );
			if ( $index < 0 || ! isset( $expected_tables[ $index ] ) || $expected_tables[ $index ] !== $table ) {
				throw new RuntimeException( 'Database live commit plan does not match the verified manifest.' );
			}
			if ( ! empty( $entry['had_original'] ) ) {
				$pairs[] = self::rename_pair( $table, self::recovery_name( $job_id, $index ) );
			}
			$pairs[] = self::rename_pair( self::shadow_name( $job_id, $index ), $table );
		}
		$extra_plan = is_array( $meta['db_restore_extra_plan'] ?? null ) ? array_values( $meta['db_restore_extra_plan'] ) : [];
		foreach ( $extra_plan as $index => $table ) {
			$pairs[] = self::rename_pair( (string) $table, self::extra_recovery_name( $job_id, (int) $index ) );
		}
		if ( ! $pairs ) {
			throw new RuntimeException( 'Database restore has no live snapshot operations to commit.' );
		}
		$sql = 'RENAME TABLE ' . implode( ', ', $pairs );
		if ( strlen( $sql ) > 1048576 ) {
			throw new RuntimeException( 'Database restore commit plan is unexpectedly large.' );
		}

		$old_foreign_key_checks = (string) $wpdb->get_var( 'SELECT @@SESSION.FOREIGN_KEY_CHECKS' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'SET SESSION FOREIGN_KEY_CHECKS=0' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		try {
			$result = $wpdb->query( $sql ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- deterministic identifiers from verified manifest/job state.
			if ( false === $result ) {
				throw new RuntimeException( 'Database snapshot could not be committed: ' . (string) $wpdb->last_error );
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SET SESSION FOREIGN_KEY_CHECKS=%d', 0 === (int) $old_foreign_key_checks ? 0 : 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}

		$meta['db_live_committed'] = true;
		$meta['db_restore_extra_index'] = count( $extra_plan );
		return $meta;
	}

	/** @param array<string,mixed> $meta @param string[] $expected_tables */
	public static function rollback( array $meta, array $expected_tables, string $job_id ): void {
		global $wpdb;
		$expected_tables = self::normalise_expected_tables( $expected_tables );
		$old_foreign_key_checks = (string) $wpdb->get_var( 'SELECT @@SESSION.FOREIGN_KEY_CHECKS' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$wpdb->query( 'SET SESSION FOREIGN_KEY_CHECKS=0' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		try {
			$live_state = ! empty( $meta['db_commit_prepared'] ) ? self::commit_state( $meta, $expected_tables, $job_id ) : 'prepared';
			if ( 'inconsistent' === $live_state ) {
				throw new RuntimeException( 'Database rollback cannot continue from an inconsistent live commit state.' );
			}
			if ( 'committed' === $live_state ) {
				$pairs = [];
				$committed = isset( $meta['db_restore_committed'] ) && is_array( $meta['db_restore_committed'] ) ? $meta['db_restore_committed'] : [];
				foreach ( $committed as $entry ) {
					if ( ! is_array( $entry ) ) {
						continue;
					}
					$table = (string) ( $entry['table'] ?? '' );
					$index = (int) ( $entry['index'] ?? -1 );
					$had_original = ! empty( $entry['had_original'] );
					if ( $index < 0 || ! in_array( $table, $expected_tables, true ) || ! self::table_exists( $table ) ) {
						throw new RuntimeException( 'Database rollback state is incomplete.' );
					}
					$shadow = self::shadow_name( $job_id, $index );
					if ( self::table_exists( $shadow ) ) {
						throw new RuntimeException( sprintf( 'Database rollback shadow collision detected for %s.', $table ) );
					}
					$pairs[] = self::rename_pair( $table, $shadow );
					if ( $had_original ) {
						$recovery = self::recovery_name( $job_id, $index );
						if ( ! self::table_exists( $recovery ) ) {
							throw new RuntimeException( sprintf( 'Database rollback recovery table is missing for %s.', $table ) );
						}
						$pairs[] = self::rename_pair( $recovery, $table );
					}
				}

				$extra_plan = isset( $meta['db_restore_extra_plan'] ) && is_array( $meta['db_restore_extra_plan'] ) ? array_values( $meta['db_restore_extra_plan'] ) : [];
				foreach ( $extra_plan as $index => $table ) {
					$table = (string) $table;
					self::assert_safe_table( $table );
					$recovery = self::extra_recovery_name( $job_id, (int) $index );
					if ( self::table_exists( $recovery ) ) {
						if ( self::table_exists( $table ) ) {
							throw new RuntimeException( sprintf( 'Database rollback target already exists for extra table %s.', $table ) );
						}
						$pairs[] = self::rename_pair( $recovery, $table );
					}
				}

				if ( $pairs ) {
					$result = $wpdb->query( 'RENAME TABLE ' . implode( ', ', $pairs ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
					if ( false === $result ) {
						throw new RuntimeException( 'Database rollback could not restore the previous live snapshot: ' . (string) $wpdb->last_error );
					}
				}
			}

			// Drop any restored snapshot shadows after rollback, or all pre-commit
			// shadows when the live snapshot had not been touched yet.
			foreach ( array_keys( $expected_tables ) as $index ) {
				$shadow = self::shadow_name( $job_id, (int) $index );
				if ( self::table_exists( $shadow ) ) {
					$wpdb->query( 'DROP TABLE IF EXISTS `' . self::escape_identifier( $shadow ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				}
			}
		} finally {
			$wpdb->query( $wpdb->prepare( 'SET SESSION FOREIGN_KEY_CHECKS=%d', 0 === (int) $old_foreign_key_checks ? 0 : 1 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		}
	}

	/** @param array<string,mixed> $meta @param string[] $expected_tables */
	private static function begin_table( string $table, array &$meta, array $expected_tables, string $job_id ): void {
		global $wpdb;
		$table = trim( $table );
		$index = self::current_index( $meta );
		if ( isset( $expected_tables[ $index ] ) && $expected_tables[ $index ] !== $table ) {
			throw new RuntimeException( sprintf( 'Database table order does not match the backup manifest at %s.', $table ) );
		}
		if ( ! isset( $expected_tables[ $index ] ) ) {
			throw new RuntimeException( 'Database export contains more tables than declared in the manifest.' );
		}
		if ( '' !== (string) ( $meta['db_restore_current_table'] ?? '' ) ) {
			throw new RuntimeException( 'Database export opened a new table before completing the previous table.' );
		}

		$shadow = self::shadow_name( $job_id, $index );
		if ( self::table_exists( $shadow ) ) {
			$result = $wpdb->query( 'DROP TABLE IF EXISTS `' . self::escape_identifier( $shadow ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			if ( false === $result ) {
				throw new RuntimeException( sprintf( 'Could not reset restore shadow table for %s.', $table ) );
			}
		}
		$meta['db_restore_current_table'] = $table;
	}

	/** @param array<string,mixed> $meta @param string[] $expected_tables */
	private static function finish_table( string $table, array &$meta, array $expected_tables, string $job_id ): void {
		$table = trim( $table );
		$current = (string) ( $meta['db_restore_current_table'] ?? '' );
		$index = self::current_index( $meta );
		if ( '' === $current || $current !== $table || ! isset( $expected_tables[ $index ] ) || $expected_tables[ $index ] !== $table ) {
			throw new RuntimeException( sprintf( 'Database table boundary is invalid for %s.', $table ) );
		}
		$shadow = self::shadow_name( $job_id, $index );
		if ( ! self::table_exists( $shadow ) ) {
			throw new RuntimeException( sprintf( 'Restore shadow table is missing for %s.', $table ) );
		}
		$meta['db_restore_table_index'] = $index + 1;
		unset( $meta['db_restore_current_table'] );
	}

	private static function rewrite_statement_for_shadow( string $statement, string $table, string $shadow ): string {
		self::assert_safe_table( $table );
		self::assert_safe_table( $shadow );
		$quoted_table  = preg_quote( $table, '/' );
		$quoted_shadow = '`' . self::escape_identifier( $shadow ) . '`';

		if ( 1 === preg_match( '/^DROP TABLE IF EXISTS `' . $quoted_table . '`;$/i', $statement ) ) {
			return 'DROP TABLE IF EXISTS ' . $quoted_shadow . ';';
		}
		if ( 1 === preg_match( '/^CREATE TABLE `' . $quoted_table . '`\s+.+;$/is', $statement ) ) {
			return (string) preg_replace_callback( '/^CREATE TABLE `' . $quoted_table . '`/i', static fn (): string => 'CREATE TABLE ' . $quoted_shadow, $statement, 1 );
		}
		if ( 1 === preg_match( '/^INSERT INTO `' . $quoted_table . '`\s+\(.+;$/is', $statement ) ) {
			return (string) preg_replace_callback( '/^INSERT INTO `' . $quoted_table . '`/i', static fn (): string => 'INSERT INTO ' . $quoted_shadow, $statement, 1 );
		}
		throw new RuntimeException( sprintf( 'Database export contains an unsupported SQL statement for %s.', $table ) );
	}

	/** @param array<string,mixed> $meta @param string[] $expected_tables */
	public static function assert_restored_snapshot( array $meta, array $expected_tables ): void {
		$expected_tables = self::normalise_expected_tables( $expected_tables );
		if ( empty( $meta['db_live_committed'] ) ) {
			throw new RuntimeException( 'Database restore never reached the live snapshot commit boundary.' );
		}
		foreach ( $expected_tables as $table ) {
			if ( ! self::table_exists( $table ) ) {
				throw new RuntimeException( sprintf( 'Restored database table %s is missing after commit.', $table ) );
			}
		}
		if ( empty( $meta['db_import_finished'] ) || self::current_index( $meta ) !== count( $expected_tables ) ) {
			throw new RuntimeException( 'Database restore did not reach a complete snapshot boundary.' );
		}
		$extra_plan = isset( $meta['db_restore_extra_plan'] ) && is_array( $meta['db_restore_extra_plan'] ) ? $meta['db_restore_extra_plan'] : [];
		foreach ( $extra_plan as $table ) {
			if ( self::table_exists( (string) $table ) ) {
				throw new RuntimeException( sprintf( 'Extra database table %s is still live after snapshot commit.', (string) $table ) );
			}
		}
	}

	/** @param array<string,mixed> $meta @param string[] $expected_tables */
	public static function cleanup_recovery( array $meta, array $expected_tables, string $job_id ): void {
		global $wpdb;
		$expected_tables = self::normalise_expected_tables( $expected_tables );
		foreach ( array_keys( $expected_tables ) as $index ) {
			foreach ( [ self::recovery_name( $job_id, (int) $index ), self::shadow_name( $job_id, (int) $index ) ] as $temporary ) {
				if ( self::table_exists( $temporary ) ) {
					$wpdb->query( 'DROP TABLE IF EXISTS `' . self::escape_identifier( $temporary ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
				}
			}
		}
		$plan = isset( $meta['db_restore_extra_plan'] ) && is_array( $meta['db_restore_extra_plan'] ) ? array_values( $meta['db_restore_extra_plan'] ) : [];
		foreach ( array_keys( $plan ) as $index ) {
			$recovery = self::extra_recovery_name( $job_id, (int) $index );
			if ( self::table_exists( $recovery ) ) {
				$wpdb->query( 'DROP TABLE IF EXISTS `' . self::escape_identifier( $recovery ) . '`' ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
			}
		}
	}


	/** @param array<string,mixed> $meta @param string[] $expected_tables */
	private static function commit_state( array $meta, array $expected_tables, string $job_id ): string {
		$committed = is_array( $meta['db_restore_committed'] ?? null ) ? array_values( $meta['db_restore_committed'] ) : [];
		if ( count( $committed ) !== count( $expected_tables ) ) {
			return 'inconsistent';
		}
		$prepared_matches = 0;
		$committed_matches = 0;
		foreach ( $committed as $entry ) {
			if ( ! is_array( $entry ) ) {
				return 'inconsistent';
			}
			$table = (string) ( $entry['table'] ?? '' );
			$index = (int) ( $entry['index'] ?? -1 );
			$had_original = ! empty( $entry['had_original'] );
			if ( $index < 0 || ! isset( $expected_tables[ $index ] ) || $expected_tables[ $index ] !== $table ) {
				return 'inconsistent';
			}
			$shadow_exists = self::table_exists( self::shadow_name( $job_id, $index ) );
			$live_exists = self::table_exists( $table );
			$recovery_exists = self::table_exists( self::recovery_name( $job_id, $index ) );
			if ( $shadow_exists && ( $had_original ? $live_exists : ! $live_exists ) && ! $recovery_exists ) {
				++$prepared_matches;
				continue;
			}
			if ( ! $shadow_exists && $live_exists && ( $had_original ? $recovery_exists : ! $recovery_exists ) ) {
				++$committed_matches;
				continue;
			}
			return 'inconsistent';
		}
		$extra_plan = is_array( $meta['db_restore_extra_plan'] ?? null ) ? array_values( $meta['db_restore_extra_plan'] ) : [];
		foreach ( $extra_plan as $index => $table ) {
			$table = (string) $table;
			$live_exists = self::table_exists( $table );
			$recovery_exists = self::table_exists( self::extra_recovery_name( $job_id, (int) $index ) );
			if ( $live_exists && ! $recovery_exists ) {
				++$prepared_matches;
			} elseif ( ! $live_exists && $recovery_exists ) {
				++$committed_matches;
			} else {
				return 'inconsistent';
			}
		}
		if ( $prepared_matches > 0 && 0 === $committed_matches ) {
			return 'prepared';
		}
		if ( $committed_matches > 0 && 0 === $prepared_matches ) {
			return 'committed';
		}
		return 0 === count( $expected_tables ) ? 'inconsistent' : ( ! empty( $meta['db_live_committed'] ) ? 'committed' : 'prepared' );
	}

	/** @param string[] $protected @return string[] */
	private static function extra_table_plan( array $protected ): array {
		global $wpdb;
		$like = $wpdb->esc_like( $wpdb->prefix ) . '%';
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL TABLES LIKE %s', $like ), ARRAY_N );
		$current = [];
		foreach ( is_array( $rows ) ? $rows : [] as $row ) {
			$table = isset( $row[0] ) ? (string) $row[0] : '';
			$type  = isset( $row[1] ) ? strtoupper( (string) $row[1] ) : 'BASE TABLE';
			if ( '' !== $table && 'BASE TABLE' === $type && str_starts_with( $table, $wpdb->prefix ) ) {
				$current[] = $table;
			}
		}
		$plan = array_values( array_diff( $current, array_values( array_unique( $protected ) ) ) );
		sort( $plan, SORT_STRING );
		foreach ( $plan as $table ) {
			self::assert_safe_table( $table );
		}
		return $plan;
	}

	private static function rename_pair( string $from, string $to ): string {
		self::assert_safe_table( $from );
		self::assert_safe_table( $to );
		return '`' . self::escape_identifier( $from ) . '` TO `' . self::escape_identifier( $to ) . '`';
	}

	private static function table_exists( string $table ): bool {
		global $wpdb;
		self::assert_safe_table( $table );
		$found = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $table ) ) );
		return (string) $found === $table;
	}

	private static function shadow_name( string $job_id, int $index ): string {
		return self::temporary_name( 'cbtmp', $job_id, $index );
	}

	private static function recovery_name( string $job_id, int $index ): string {
		return self::temporary_name( 'cbold', $job_id, $index );
	}

	private static function extra_recovery_name( string $job_id, int $index ): string {
		return self::temporary_name( 'cbxtra', $job_id, $index );
	}

	private static function temporary_name( string $kind, string $job_id, int $index ): string {
		global $wpdb;
		$prefix = (string) $wpdb->prefix;
		$index  = max( 0, $index );
		$fixed  = strlen( $prefix ) + strlen( $kind ) + strlen( (string) $index ) + 3;
		$token_length = 64 - $fixed;
		if ( $token_length < 6 ) {
			throw new RuntimeException( 'The WordPress table prefix is too long for safe shadow-table restore.' );
		}
		$token = substr( hash( 'sha256', $job_id ), 0, min( 10, $token_length ) );
		$name = sprintf( '%s_%s_%s_%d', $prefix, $kind, $token, $index );
		self::assert_safe_table( $name );
		return $name;
	}

	/** @param array<string,mixed> $meta */
	private static function current_index( array $meta ): int {
		return max( 0, (int) ( $meta['db_restore_table_index'] ?? 0 ) );
	}

	private static function is_format_control_statement( string $statement ): bool {
		return in_array(
			strtoupper( preg_replace( '/\s+/', ' ', $statement ) ?? $statement ),
			[ 'SET FOREIGN_KEY_CHECKS=0;', 'SET FOREIGN_KEY_CHECKS=1;' ],
			true
		);
	}

	/** @param string[] $tables @return string[] */
	private static function normalise_expected_tables( array $tables ): array {
		$normalised = [];
		foreach ( $tables as $table ) {
			$table = (string) $table;
			self::assert_safe_table( $table );
			if ( $table === Schema::table() ) {
				throw new RuntimeException( 'Backup manifest may not include the operational backup job table.' );
			}
			$normalised[] = $table;
		}
		return array_values( array_unique( $normalised ) );
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
