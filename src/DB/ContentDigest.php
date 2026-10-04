<?php
declare(strict_types=1);

namespace CB\Backups\DB;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

/** Ordered hash of the sorted multiset of row hashes; duplicates are retained. */
final class ContentDigest {
	public const ALGORITHM = 'sha256-sorted-row-chain-v1';
	private const RECORD_BYTES = 65;
	private const RECORDS_PER_TICK = 8192;

	/** Full-value ordering also works for BLOBs beyond MySQL's max_sort_length. */
	public static function order_by( array $columns ): string {
		$parts = [];
		foreach ( $columns as $column ) $parts[] = "IFNULL(SHA2(CAST(`" . str_replace( '`', '``', $column['name'] ) . "` AS BINARY),256),'N')";
		return "SHA2(CONCAT_WS(':'," . implode( ',', $parts ) . '),256) ASC';
	}

	public static function table_order_by( string $table, array $columns ): string {
		global $wpdb;
		$keys = $wpdb->get_results( $wpdb->prepare( "SHOW KEYS FROM %i WHERE Key_name = 'PRIMARY'", $table ), ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $keys ) ) throw new RuntimeException( 'Cannot inspect shadow primary key.' );
		if ( ! $keys ) return self::order_by( $columns );
		usort( $keys, static fn ( array $a, array $b ): int => (int) $a['Seq_in_index'] <=> (int) $b['Seq_in_index'] );
		return implode( ', ', array_map( static fn ( array $key ): string => '`' . str_replace( '`', '``', $key['Column_name'] ) . '` ASC', $keys ) );
	}

	public static function columns( string $table ): array {
		global $wpdb;
		$rows = $wpdb->get_results( $wpdb->prepare( 'SHOW FULL COLUMNS FROM %i', $table ), ARRAY_A );
		if ( '' !== $wpdb->last_error || ! is_array( $rows ) || ! $rows ) throw new RuntimeException( 'Could not inspect database content schema: ' . $wpdb->last_error );
		$columns = [];
		foreach ( $rows as $row ) $columns[] = [ 'name' => (string) $row['Field'], 'type' => strtolower( (string) $row['Type'] ), 'collation' => $row['Collation'], 'nullable' => (string) $row['Null'] ];
		return $columns;
	}

	public static function row_hash( array $row, array $columns ): string {
		$ctx = hash_init( 'sha256' );
		hash_update( $ctx, "CBDB-ROW-v1\0" . json_encode( $columns, JSON_THROW_ON_ERROR ) . "\0" );
		foreach ( $columns as $column ) {
			$name = $column['name'];
			if ( ! array_key_exists( $name, $row ) ) throw new RuntimeException( 'Database row is missing a declared column.' );
			$value = $row[ $name ];
			if ( null === $value ) { hash_update( $ctx, 'N;' ); continue; }
			$value = (string) $value;
			$tag = preg_match( '/(?:binary|blob|bit)/i', $column['type'] ) ? 'B' : 'S';
			hash_update( $ctx, $tag . strlen( $value ) . ':' );
			hash_update( $ctx, $value );
		}
		return hash_final( $ctx );
	}

	public static function append( string $dir, array &$state, array $rows, array $columns ): void {
		if ( ! empty( $state['sealing'] ) ) throw new RuntimeException( 'Cannot append to a sealed content digest.' );
		if ( ! $rows ) return;
		if ( ! is_dir( $dir ) && ! mkdir( $dir, 0700, true ) ) throw new RuntimeException( 'Cannot create content digest workspace.' );
		$hashes = [];
		foreach ( $rows as $row ) $hashes[] = self::row_hash( $row, $columns );
		sort( $hashes, SORT_STRING );
		$id = (int) ( $state['next'] ?? 0 );
		$data = implode( "\n", $hashes ) . "\n";
		if ( file_put_contents( self::path( $dir, $id ), $data, LOCK_EX ) !== strlen( $data ) ) throw new RuntimeException( 'Could not write content digest run.' );
		$state['runs'][] = $id;
		$state['next'] = $id + 1;
		$state['rows'] = (int) ( $state['rows'] ?? 0 ) + count( $rows );
	}

	/** Each call performs bounded merge/hash work. Old runs survive checkpoint replay. */
	public static function finish_tick( string $dir, array &$state ): bool {
		$state['sealing'] = true;
		$runs = $state['runs'] ?? [];
		if ( count( $runs ) > 1 ) {
			if ( ! isset( $state['merge'] ) ) {
				$state['merge'] = [ 'left' => $runs[0], 'right' => $runs[1], 'out' => (int) $state['next'], 'a' => 0, 'b' => 0, 'written' => 0 ];
				++$state['next'];
			}
			$m = &$state['merge'];
			$a = self::open( self::path( $dir, $m['left'] ), 'rb', $m['a'] );
			$b = null; $out = null;
			try {
				$b = self::open( self::path( $dir, $m['right'] ), 'rb', $m['b'] );
				$out = self::open( self::path( $dir, $m['out'] ), 'c+b', $m['written'], true );
				$left = self::read( $a ); $right = self::read( $b );
				for ( $i = 0; $i < self::RECORDS_PER_TICK && ( null !== $left || null !== $right ); ++$i ) {
					$take_left = null === $right || ( null !== $left && strcmp( $left, $right ) <= 0 );
					$record = $take_left ? $left : $right;
					if ( fwrite( $out, $record ) !== self::RECORD_BYTES ) throw new RuntimeException( 'Short content digest merge write.' );
					$m['written'] += self::RECORD_BYTES;
					if ( $take_left ) { $m['a'] += self::RECORD_BYTES; $left = self::read( $a ); }
					else { $m['b'] += self::RECORD_BYTES; $right = self::read( $b ); }
				}
				if ( ! fflush( $out ) ) throw new RuntimeException( 'Could not flush content digest merge.' );
				if ( null === $left && null === $right ) {
					$state['runs'] = array_merge( array_slice( $runs, 2 ), [ $m['out'] ] );
					unset( $state['merge'] );
				}
			} finally {
				fclose( $a ); if ( is_resource( $b ) ) fclose( $b ); if ( is_resource( $out ) ) fclose( $out );
			}
			return false;
		}
		$state['chain'] ??= hash( 'sha256', "CBDB-CONTENT-v1\0" );
		$state['hashed'] ??= 0;
		if ( $runs ) {
			$in = self::open( self::path( $dir, $runs[0] ), 'rb', $state['hashed'] * self::RECORD_BYTES );
			try {
				for ( $i = 0; $i < self::RECORDS_PER_TICK; ++$i ) {
					$record = self::read( $in );
					if ( null === $record ) break;
					$state['chain'] = hash( 'sha256', hex2bin( $state['chain'] ) . hex2bin( substr( $record, 0, 64 ) ) );
					++$state['hashed'];
				}
				if ( ! feof( $in ) ) return false;
			} finally { fclose( $in ); }
		}
		if ( $state['hashed'] !== (int) ( $state['rows'] ?? 0 ) ) throw new RuntimeException( 'Content digest row count is incomplete.' );
		$state['digest'] = hash( 'sha256', "CBDB-FINAL-v1\0" . $state['chain'] . ':' . $state['hashed'] );
		return true;
	}

	public static function summary( array $state, array $columns ): array {
		if ( ! isset( $state['digest'] ) ) throw new RuntimeException( 'Content digest is not complete.' );
		return [ 'algorithm' => self::ALGORITHM, 'columns' => $columns, 'rows' => (int) ( $state['rows'] ?? 0 ), 'digest' => $state['digest'] ];
	}

	public static function assert_equal( array $expected, array $actual, string $table ): void {
		if ( ( $expected['algorithm'] ?? '' ) !== self::ALGORITHM || $expected['columns'] !== $actual['columns'] || $expected['rows'] !== $actual['rows'] || ! hash_equals( $expected['digest'], $actual['digest'] ) ) {
			throw new RuntimeException( sprintf( 'Database content verification failed for %s. Live tables have not been approved for replacement.', $table ) );
		}
	}

	public static function validate_inventory( array $tables, array $inventory ): void {
		if ( count( $tables ) !== count( $inventory ) ) throw new RuntimeException( 'Backup requires complete source-derived database integrity metadata. Create a new backup with RC15.6 or later.' );
		foreach ( $tables as $table ) {
			$entry = $inventory[ $table ] ?? [];
			if ( ( $entry['algorithm'] ?? '' ) !== self::ALGORITHM || ! is_int( $entry['rows'] ?? null ) || $entry['rows'] < 0 || ! preg_match( '/^[a-f0-9]{64}$/D', (string) ( $entry['digest'] ?? '' ) ) || empty( $entry['columns'] ) || ! is_array( $entry['columns'] ) ) throw new RuntimeException( 'Invalid database content integrity metadata.' );
			$seen = [];
			foreach ( $entry['columns'] as $column ) {
				if ( ! is_array( $column ) || ! is_string( $column['name'] ?? null ) || '' === $column['name'] || isset( $seen[ $column['name'] ] ) || ! is_string( $column['type'] ?? null ) || ! array_key_exists( 'collation', $column ) || ! in_array( $column['nullable'] ?? '', [ 'YES', 'NO' ], true ) ) throw new RuntimeException( 'Invalid content digest column schema.' );
				$seen[ $column['name'] ] = true;
			}
		}
	}

	private static function path( string $dir, int $id ): string { return $dir . '/run-' . $id; }
	private static function open( string $path, string $mode, int $offset, bool $truncate = false ) {
		$in = fopen( $path, $mode );
		if ( false === $in ) throw new RuntimeException( 'Cannot open content digest run.' );
		$size = fstat( $in )['size'];
		if ( $offset < 0 || $size < $offset || ( $truncate && ! ftruncate( $in, $offset ) ) || 0 !== fseek( $in, $offset ) ) { fclose( $in ); throw new RuntimeException( 'Invalid content digest checkpoint.' ); }
		return $in;
	}
	private static function read( $in ): ?string {
		$record = fgets( $in, self::RECORD_BYTES + 1 );
		if ( false === $record && feof( $in ) ) return null;
		if ( ! is_string( $record ) || ! preg_match( '/^[a-f0-9]{64}\n$/D', $record ) ) throw new RuntimeException( 'Truncated or invalid content digest run.' );
		return $record;
	}
}
