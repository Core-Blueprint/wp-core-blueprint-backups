<?php
declare(strict_types=1);

namespace CB\Backups\Restore;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class MigrationTransformer {
	private const BYTES_PER_TICK = 2097152;
	private const LINES_PER_TICK = 250;
	private const TIME_BUDGET = 1.5;

	/** @param array<string,mixed> $meta @param array<string,mixed> $plan @return array{done:bool,progress:int,meta:array<string,mixed>} */
	public static function tick( string $source_sql, string $target_sql, array $meta, array $plan ): array {
		MigrationPlan::assert_plan( $plan );
		if ( empty( $plan['requires_migration'] ) ) throw new RuntimeException( 'Database migration transform was requested for a same-site restore.' );
		if ( ! is_file( $source_sql ) || ! is_readable( $source_sql ) ) throw new RuntimeException( 'Verified database export is unavailable for migration.' );
		$source_size = filesize( $source_sql );
		if ( false === $source_size || $source_size < 1 ) throw new RuntimeException( 'Verified database export is empty.' );

		if ( empty( $meta['migration_transform_initialized'] ) ) {
			$handle = fopen( $target_sql, 'wb' );
			if ( false === $handle ) throw new RuntimeException( 'Migration database staging file could not be created.' );
			fclose( $handle );
			$meta['migration_transform_initialized'] = true;
			$meta['migration_source_size'] = (int) $source_size;
			$meta['migration_source_offset'] = 0;
			$meta['migration_target_cursor'] = 0;
			$meta['migration_table_index'] = 0;
			$meta['migration_current_table'] = '';
			$meta['migration_replacements'] = 0;
		}
		if ( (int) ( $meta['migration_source_size'] ?? -1 ) !== (int) $source_size ) throw new RuntimeException( 'Verified database export changed during migration preparation.' );

		$source_offset = max( 0, (int) ( $meta['migration_source_offset'] ?? 0 ) );
		$target_cursor = max( 0, (int) ( $meta['migration_target_cursor'] ?? 0 ) );
		if ( $source_offset > $source_size ) throw new RuntimeException( 'Migration source checkpoint is outside the verified database export.' );

		$in = fopen( $source_sql, 'rb' ); $out = fopen( $target_sql, 'c+b' );
		if ( false === $in || false === $out ) {
			if ( is_resource( $in ) ) fclose( $in ); if ( is_resource( $out ) ) fclose( $out );
			throw new RuntimeException( 'Migration database streams could not be opened.' );
		}
		$locked = false;
		try {
			$locked = flock( $out, LOCK_EX ); if ( ! $locked ) throw new RuntimeException( 'Migration database staging file could not be locked.' );
			if ( ! ftruncate( $out, $target_cursor ) || 0 !== fseek( $out, $target_cursor ) ) throw new RuntimeException( 'Migration database target checkpoint could not be restored.' );
			if ( $source_offset > 0 && 0 !== fseek( $in, $source_offset ) ) throw new RuntimeException( 'Migration database source checkpoint could not be restored.' );
			$bytes = 0; $lines = 0; $deadline = microtime( true ) + self::TIME_BUDGET;
			while ( $lines < self::LINES_PER_TICK && $bytes < self::BYTES_PER_TICK && microtime( true ) < $deadline && false !== ( $line = fgets( $in ) ) ) {
				$bytes += strlen( $line ); ++$lines; self::write_all( $out, self::transform_line( $line, $meta, $plan ) );
			}
			if ( ! fflush( $out ) ) throw new RuntimeException( 'Migration database staging file could not be flushed.' );
			if ( function_exists( 'fsync' ) ) @fsync( $out ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$source_position = ftell( $in ); $target_position = ftell( $out );
			if ( false === $source_position || false === $target_position ) throw new RuntimeException( 'Migration database checkpoint could not be recorded.' );
			$meta['migration_source_offset'] = (int) $source_position; $meta['migration_target_cursor'] = (int) $target_position; $done = feof( $in );
		} finally {
			if ( $locked ) flock( $out, LOCK_UN ); fclose( $in ); fclose( $out );
		}

		if ( $done ) {
			$source_tables = isset( $plan['source_tables'] ) && is_array( $plan['source_tables'] ) ? array_values( $plan['source_tables'] ) : [];
			if ( '' !== (string) ( $meta['migration_current_table'] ?? '' ) || (int) ( $meta['migration_table_index'] ?? 0 ) !== count( $source_tables ) ) throw new RuntimeException( 'Migration database transform did not reach a complete table boundary.' );
			$hash = hash_file( 'sha256', $target_sql ); if ( ! is_string( $hash ) || 64 !== strlen( $hash ) ) throw new RuntimeException( 'Migrated database checksum could not be calculated.' );
			$meta['migration_sql_sha256'] = $hash; $meta['migration_sql_bytes'] = filesize( $target_sql ) ?: 0; $meta['migration_transform_complete'] = true;
		}
		$progress = (int) floor( min( 1, (int) ( $meta['migration_source_offset'] ?? 0 ) / max( 1, (int) $source_size ) ) * 100 );
		return [ 'done' => $done, 'progress' => $done ? 100 : min( 99, $progress ), 'meta' => $meta ];
	}

	/** @param array<string,mixed> $meta @param array<string,mixed> $plan */
	private static function transform_line( string $line, array &$meta, array $plan ): string {
		$trimmed = trim( $line );
		if ( '' === $trimmed || str_starts_with( $trimmed, '-- Core Blueprint Backups' ) || str_starts_with( $trimmed, '-- Format version:' ) || str_starts_with( $trimmed, 'SET FOREIGN_KEY_CHECKS=' ) ) return $line;
		if ( str_starts_with( $trimmed, '-- CB TABLE: ' ) ) { $source = trim( substr( $trimmed, strlen( '-- CB TABLE: ' ) ) ); self::begin_table( $source, $meta, $plan ); return '-- CB TABLE: ' . self::target_table( $source, $plan ) . "\n"; }
		if ( str_starts_with( $trimmed, '-- CB END TABLE: ' ) ) { $source = trim( substr( $trimmed, strlen( '-- CB END TABLE: ' ) ) ); self::finish_table( $source, $meta, $plan ); return '-- CB END TABLE: ' . self::target_table( $source, $plan ) . "\n"; }
		$current = (string) ( $meta['migration_current_table'] ?? '' ); if ( '' === $current ) throw new RuntimeException( 'Migration database export contains SQL outside a table boundary.' );
		$target = self::target_table( $current, $plan ); $quoted = preg_quote( $current, '/' );
		if ( 1 === preg_match( '/^DROP TABLE IF EXISTS `' . $quoted . '`;$/i', $trimmed ) ) return 'DROP TABLE IF EXISTS `' . self::escape_identifier( $target ) . "`;\n";
		if ( 1 === preg_match( '/^CREATE TABLE `' . $quoted . '`\s+.+;$/is', $trimmed ) ) {
			$changed = preg_replace_callback( '/^CREATE TABLE `' . $quoted . '`/i', static fn (): string => 'CREATE TABLE `' . self::escape_identifier( $target ) . '`', $trimmed, 1 );
			if ( ! is_string( $changed ) ) throw new RuntimeException( 'Migration CREATE TABLE statement could not be rewritten.' ); return $changed . "\n";
		}
		if ( str_starts_with( strtoupper( $trimmed ), 'INSERT INTO ' ) ) return self::transform_insert( $trimmed, $current, $target, $meta, $plan ) . "\n";
		throw new RuntimeException( sprintf( 'Migration database export contains an unsupported SQL statement for %s.', $current ) );
	}

	/** @param array<string,mixed> $meta @param array<string,mixed> $plan */
	private static function transform_insert( string $statement, string $source_table, string $target_table, array &$meta, array $plan ): string {
		$quoted = preg_quote( $source_table, '/' );
		if ( 1 !== preg_match( '/^INSERT INTO `' . $quoted . '`\s+\((.+)\)\s+VALUES\s+(.+);$/is', $statement, $matches ) ) throw new RuntimeException( sprintf( 'Migration INSERT statement is malformed for %s.', $source_table ) );
		$column_segment = (string) $matches[1]; $values_segment = (string) $matches[2]; preg_match_all( '/`([^`]+)`/', $column_segment, $column_matches );
		$columns = isset( $column_matches[1] ) && is_array( $column_matches[1] ) ? array_values( $column_matches[1] ) : []; if ( ! $columns ) throw new RuntimeException( sprintf( 'Migration INSERT statement has no columns for %s.', $source_table ) );
		$rows = self::split_top_level( $values_segment, ',' ); $encoded_rows = [];
		foreach ( $rows as $row ) {
			$row = trim( $row ); if ( strlen( $row ) < 2 || '(' !== $row[0] || ')' !== $row[ strlen( $row ) - 1 ] ) throw new RuntimeException( sprintf( 'Migration INSERT row is malformed for %s.', $source_table ) );
			$tokens = self::split_top_level( substr( $row, 1, -1 ), ',' ); if ( count( $tokens ) !== count( $columns ) ) throw new RuntimeException( sprintf( 'Migration INSERT column/value count differs for %s.', $source_table ) );
			foreach ( $tokens as $index => $token ) $tokens[ $index ] = self::transform_token( trim( $token ), (string) $columns[ $index ], $source_table, $meta, $plan );
			$encoded_rows[] = '(' . implode( ', ', $tokens ) . ')';
		}
		return 'INSERT INTO `' . self::escape_identifier( $target_table ) . '` (' . $column_segment . ') VALUES ' . implode( ', ', $encoded_rows ) . ';';
	}

	/** @param array<string,mixed> $meta @param array<string,mixed> $plan */
	private static function transform_token( string $token, string $column, string $source_table, array &$meta, array $plan ): string {
		if ( 'NULL' === strtoupper( $token ) || str_starts_with( strtoupper( $token ), 'UNHEX(' ) ) return $token;
		if ( strlen( $token ) < 2 || "'" !== $token[0] || "'" !== $token[ strlen( $token ) - 1 ] ) throw new RuntimeException( sprintf( 'Migration database contains an unsupported value token in %s.', $source_table ) );
		$value = self::decode_sql_string( $token ); $original = $value; $source_prefix = (string) $plan['source_prefix']; $target_prefix = (string) $plan['target_prefix'];
		if ( $source_table === $source_prefix . 'options' && 'option_name' === $column && $value === $source_prefix . 'user_roles' ) $value = $target_prefix . 'user_roles';
		if ( $source_table === $source_prefix . 'usermeta' && 'meta_key' === $column && str_starts_with( $value, $source_prefix ) ) $value = $target_prefix . substr( $value, strlen( $source_prefix ) );
		if ( 'guid' !== $column ) $value = self::replace_urls( $value, $plan );
		if ( $value !== $original ) $meta['migration_replacements'] = (int) ( $meta['migration_replacements'] ?? 0 ) + 1;
		return self::encode_sql_string( $value );
	}

	/** @param array<string,mixed> $plan */
	private static function replace_urls( string $value, array $plan ): string {
		$pairs = [];
		foreach ( [ [ (string) $plan['source_site_url'], (string) $plan['target_site_url'] ], [ (string) $plan['source_home_url'], (string) $plan['target_home_url'] ] ] as $pair ) {
			[ $from, $to ] = $pair; if ( '' !== $from && $from !== $to ) $pairs[ $from ] = $to;
		}
		uksort( $pairs, static fn ( string $a, string $b ): int => strlen( $b ) <=> strlen( $a ) ); if ( ! $pairs ) return $value;
		$replace_value = null;
		$replace_value = static function ( string $string ) use ( &$replace_value, $pairs ): string {
			if ( function_exists( 'is_serialized' ) && is_serialized( $string ) ) {
				// Custom Serializable payloads use their own length framing. Keep
				// those opaque rather than risking corruption without executing
				// user-defined unserialization code from backup data.
				if ( str_starts_with( $string, 'C:' ) ) return $string;
				return self::replace_serialized_strings( $string, $replace_value );
			}
			foreach ( $pairs as $from => $to ) {
				$string = str_replace( $from, $to, $string );
				$string = str_replace( str_replace( '/', '\\/', $from ), str_replace( '/', '\\/', $to ), $string );
			}
			return $string;
		};
		return $replace_value( $value );
	}

	/** @param callable(string):string $callback */
	private static function replace_serialized_strings( string $serialized, callable $callback ): string {
		$length = strlen( $serialized ); $out = ''; $i = 0;
		while ( $i < $length ) {
			if ( 's' === $serialized[ $i ] && $i + 3 < $length && ':' === $serialized[ $i + 1 ] ) {
				$j = $i + 2; while ( $j < $length && ctype_digit( $serialized[ $j ] ) ) ++$j;
				if ( $j > $i + 2 && $j + 1 < $length && ':' === $serialized[ $j ] && '"' === $serialized[ $j + 1 ] ) {
					$declared = (int) substr( $serialized, $i + 2, $j - ( $i + 2 ) ); $start = $j + 2; $end = $start + $declared;
					if ( $end + 1 < $length && '"' === $serialized[ $end ] && ';' === $serialized[ $end + 1 ] ) {
						$content = substr( $serialized, $start, $declared ); $replacement = $callback( $content ); $out .= 's:' . strlen( $replacement ) . ':"' . $replacement . '";'; $i = $end + 2; continue;
					}
				}
			}
			$out .= $serialized[ $i ]; ++$i;
		}
		return $out;
	}

	/** @return string[] */
	private static function split_top_level( string $input, string $delimiter ): array {
		$parts = []; $start = 0; $depth = 0; $quoted = false; $escaped = false; $length = strlen( $input );
		for ( $i = 0; $i < $length; ++$i ) {
			$char = $input[ $i ];
			if ( $quoted ) { if ( $escaped ) { $escaped = false; continue; } if ( '\\' === $char ) { $escaped = true; continue; } if ( "'" === $char ) $quoted = false; continue; }
			if ( "'" === $char ) { $quoted = true; continue; } if ( '(' === $char ) { ++$depth; continue; } if ( ')' === $char ) { --$depth; if ( $depth < 0 ) throw new RuntimeException( 'Migration SQL contains unbalanced parentheses.' ); continue; }
			if ( $delimiter === $char && 0 === $depth ) { $parts[] = substr( $input, $start, $i - $start ); $start = $i + 1; }
		}
		if ( $quoted || 0 !== $depth ) throw new RuntimeException( 'Migration SQL contains an unterminated value.' ); $parts[] = substr( $input, $start ); return $parts;
	}

	private static function decode_sql_string( string $token ): string {
		$body = substr( $token, 1, -1 ); $out = ''; $length = strlen( $body );
		for ( $i = 0; $i < $length; ++$i ) {
			$char = $body[ $i ]; if ( '\\' !== $char || $i + 1 >= $length ) { $out .= $char; continue; }
			$next = $body[++$i]; $out .= match ( $next ) { '0' => "\0", 'n' => "\n", 'r' => "\r", 'Z' => chr( 26 ), default => $next };
		}
		return $out;
	}

	private static function encode_sql_string( string $value ): string {
		global $wpdb; $prepared = $wpdb->prepare( '%s', $value ); if ( ! is_string( $prepared ) || strlen( $prepared ) < 2 ) throw new RuntimeException( 'Migration database value could not be safely encoded.' ); return $prepared;
	}

	/** @param array<string,mixed> $meta @param array<string,mixed> $plan */
	private static function begin_table( string $source, array &$meta, array $plan ): void {
		$tables = isset( $plan['source_tables'] ) && is_array( $plan['source_tables'] ) ? array_values( $plan['source_tables'] ) : []; $index = max( 0, (int) ( $meta['migration_table_index'] ?? 0 ) );
		if ( '' !== (string) ( $meta['migration_current_table'] ?? '' ) || ! isset( $tables[ $index ] ) || (string) $tables[ $index ] !== $source ) throw new RuntimeException( sprintf( 'Migration database table order is invalid at %s.', $source ) );
		self::target_table( $source, $plan ); $meta['migration_current_table'] = $source;
	}

	/** @param array<string,mixed> $meta @param array<string,mixed> $plan */
	private static function finish_table( string $source, array &$meta, array $plan ): void {
		$current = (string) ( $meta['migration_current_table'] ?? '' ); if ( '' === $current || $current !== $source ) throw new RuntimeException( sprintf( 'Migration database table boundary is invalid for %s.', $source ) );
		self::target_table( $source, $plan ); $meta['migration_table_index'] = max( 0, (int) ( $meta['migration_table_index'] ?? 0 ) ) + 1; $meta['migration_current_table'] = '';
	}

	/** @param array<string,mixed> $plan */
	private static function target_table( string $source, array $plan ): string {
		$map = isset( $plan['table_map'] ) && is_array( $plan['table_map'] ) ? $plan['table_map'] : []; $target = isset( $map[ $source ] ) ? (string) $map[ $source ] : '';
		if ( '' === $target ) throw new RuntimeException( sprintf( 'Migration plan has no destination table for %s.', $source ) ); return $target;
	}

	/** @param resource $handle */
	private static function write_all( $handle, string $data ): void {
		$length = strlen( $data ); $written = 0;
		while ( $written < $length ) { $result = fwrite( $handle, substr( $data, $written ) ); if ( false === $result || 0 === $result ) throw new RuntimeException( 'Migration database staging file could not be written completely.' ); $written += $result; }
	}

	private static function escape_identifier( string $identifier ): string { return str_replace( '`', '``', $identifier ); }
}
