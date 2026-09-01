<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use CB\Backups\Backup\DatabaseExporter;
use CB\Backups\DB\ContentDigest;
use CB\Backups\Restore\DatabaseImporter;
use CB\Backups\Restore\DatabaseContentVerifier;

$wpdb->set_prefix( 'cbstress_' );
$work = sys_get_temp_dir() . '/cb-robustness-' . bin2hex( random_bytes( 5 ) );
check( mkdir( $work, 0700 ), 'Cannot create work directory.' );
sql( 'CREATE TABLE cbstress_numeric (id BIGINT UNSIGNED PRIMARY KEY, amount DECIMAL(30,10), payload LONGTEXT) CHARACTER SET utf8mb4' );
sql( 'CREATE TABLE cbstress_string (id VARCHAR(191) PRIMARY KEY, payload LONGTEXT) CHARACTER SET utf8mb4 COLLATE utf8mb4_bin' );
sql( 'CREATE TABLE cbstress_composite (a INT, b VARCHAR(100), payload LONGTEXT, PRIMARY KEY(a,b)) CHARACTER SET utf8mb4' );
sql( 'CREATE TABLE cbstress_no_pk (payload LONGTEXT NULL, raw BLOB NULL) CHARACTER SET utf8mb4' );
sql( 'CREATE TABLE cbstress_binary_pk (id VARBINARY(20) PRIMARY KEY, payload LONGTEXT) CHARACTER SET utf8mb4' );
sql( 'CREATE TABLE cbstress_empty (id BIGINT PRIMARY KEY, payload LONGTEXT) CHARACTER SET utf8mb4' );
sql( 'CREATE TABLE cbstress_packets (id INT PRIMARY KEY, payload LONGTEXT) CHARACTER SET utf8mb4' );

for ( $i = 0; $i < 2107; ++$i ) {
	$payload = 'fixture ' . $i . " 100% ' \\ 😀\r\n";
	sql( $wpdb->prepare( 'INSERT INTO cbstress_numeric VALUES (%s, %s, %s)', (string) ( 9007199254740993 + $i ), '12345678901234567890.0123456789', $payload ) );
	sql( $wpdb->prepare( 'INSERT INTO cbstress_string VALUES (%s, %s)', sprintf( 'key-%05d-%%', $i ), $payload ) );
	sql( $wpdb->prepare( 'INSERT INTO cbstress_composite VALUES (%d, %s, %s)', (int) floor( $i / 3 ), 'part-' . ( $i % 3 ), $payload ) );
	// Deliberately repeated rows; no deduplication is allowed by the digest.
	sql( $wpdb->prepare( 'INSERT INTO cbstress_no_pk VALUES (%s, UNHEX(%s))', 'duplicate-' . ( $i % 7 ), '00ff25' ) );
}
sql( "INSERT INTO cbstress_no_pk VALUES (NULL, NULL), ('', UNHEX(''))" );
for ( $i = 0; $i < 20; ++$i ) sql( $wpdb->prepare( 'INSERT INTO cbstress_binary_pk VALUES (UNHEX(%s),%s)', bin2hex( pack( 'N', $i ) . "\0\xff" ), 'binary-pk 100%' ) );
for ( $i = 0; $i < 60; ++$i ) sql( $wpdb->prepare( 'INSERT INTO cbstress_packets VALUES (%d,%s)', $i, str_repeat( '100% ' . $i . "\n", 10000 ) ) );

$original = [];
foreach ( [ 'numeric', 'string', 'composite', 'no_pk', 'binary_pk', 'empty', 'packets' ] as $suffix ) {
	$table = 'cbstress_' . $suffix;
	$rows = $wpdb->get_results( "SELECT * FROM `{$table}`", ARRAY_A );
	$original[ $table ] = array_map( 'serialize', $rows );
	sort( $original[ $table ], SORT_STRING );
}

$initial_mode = (string) $wpdb->get_var( 'SELECT @@SESSION.SQL_MODE' );
sql( "SET SESSION SQL_MODE='STRICT_TRANS_TABLES,NO_BACKSLASH_ESCAPES'" );
$test_mode = (string) $wpdb->get_var( 'SELECT @@SESSION.SQL_MODE' );
$meta = [];
$replayed = false;
for ( $i = 0; $i < 10000; ++$i ) {
	$before = $meta;
	$result = DatabaseExporter::tick( $work, $meta );
	if ( ! $replayed && ! empty( $before['db_current_table'] ) && empty( $before['db_content_pending'] ) ) {
		// Simulate lost acknowledgement: bytes were written but metadata was not saved.
		$result = DatabaseExporter::tick( $work, $before );
		$replayed = true;
	}
	$meta = json_decode( json_encode( $result['meta'], JSON_THROW_ON_ERROR ), true, 512, JSON_THROW_ON_ERROR );
	check( $test_mode === $wpdb->get_var( 'SELECT @@SESSION.SQL_MODE' ), 'Exporter leaked SQL mode.' );
	if ( $result['done'] ) break;
}
check( $result['done'] && $replayed, 'Exporter replay did not finish.' );
$tables = $meta['db_tables'];
$inventory = $meta['db_content_integrity'];
$job = 'robustness';
$import = run_ticks( static fn ( array $state ): array => DatabaseImporter::tick( $work . '/database.sql', $state, $tables, $job ) );
check( $test_mode === $wpdb->get_var( 'SELECT @@SESSION.SQL_MODE' ), 'Importer leaked SQL mode.' );
$import['manifest']['database']['content_integrity'] = $inventory;
$import = run_ticks( static fn ( array $state ): array => DatabaseContentVerifier::tick( $work, $state, $tables, $inventory, $job ), $import );
foreach ( $tables as $index => $table ) {
	$shadow = DatabaseImporter::shadow_name( $job, $index );
	$rows = $wpdb->get_results( "SELECT * FROM `{$shadow}`", ARRAY_A );
	$actual = array_map( 'serialize', $rows ); sort( $actual, SORT_STRING );
	check( $original[ $table ] === $actual, 'Typed round-trip mismatch for ' . $table );
}
echo "Numeric/string/composite/binary/no PK, duplicates, decimals, SQL modes, exporter checkpoint replay: PASS\n";

$in = fopen( $work . '/database.sql', 'rb' );
while ( false !== ( $line = fgets( $in ) ) ) {
	if ( str_starts_with( $line, 'INSERT INTO `cbstress_packets`' ) ) check( strlen( $line ) <= 1048576, 'Multi-row INSERT exceeds byte budget.' );
}
fclose( $in );
$packet_filter = static fn ( string $query ): string => 'SELECT @@SESSION.max_allowed_packet' === $query ? 'SELECT 2048' : $query;
add_filter( 'query', $packet_filter );
$failed = false;
try { run_ticks( static fn ( array $state ): array => DatabaseImporter::tick( $work . '/database.sql', $state, $tables, 'packet-failure' ) ); }
catch ( RuntimeException $e ) { $failed = str_contains( $e->getMessage(), 'max_allowed_packet' ); }
finally { remove_filter( 'query', $packet_filter ); }
check( $failed, 'Destination packet limit must fail before live commit.' );
echo "INSERT byte budget and destination packet-limit rejection: PASS\n";

// Replaying an unacknowledged INSERT tick on a no-PK shadow must not pass the gate.
$replay_job = 'import-replay'; $state = []; $replayed = false;
for ( $i = 0; $i < 10000; ++$i ) {
	$before = $state;
	$result = DatabaseImporter::tick( $work . '/database.sql', $state, $tables, $replay_job );
	if ( ! $replayed && ( $before['db_restore_current_table'] ?? '' ) === 'cbstress_no_pk' ) {
		$result = DatabaseImporter::tick( $work . '/database.sql', $before, $tables, $replay_job );
		$replayed = true;
	}
	$state = $result['meta'];
	if ( $result['done'] ) break;
}
check( $replayed && $result['done'], 'No-PK import replay was not exercised.' );
$state['manifest']['database']['content_integrity'] = $inventory;
$failed = false;
try { run_ticks( static fn ( array $s ): array => DatabaseContentVerifier::tick( $work . '/replay', $s, $tables, $inventory, $replay_job ), $state ); }
catch ( RuntimeException $e ) { $failed = str_contains( $e->getMessage(), 'extra rows' ) || str_contains( $e->getMessage(), 'content verification failed' ); }
check( $failed, 'Replayed INSERTs incorrectly passed content verification.' );
DatabaseImporter::rollback( $state, $tables, $replay_job );
check( 2109 === (int) $wpdb->get_var( 'SELECT COUNT(*) FROM cbstress_no_pk' ), 'Failed replay changed the live source.' );
echo "Unacknowledged no-PK import replay fails closed before live commit: PASS\n";

// External merge must preserve all duplicates regardless of append/row order.
$columns = [ [ 'name' => 'v', 'type' => 'longtext', 'collation' => null, 'nullable' => 'YES' ] ];
$a = []; $b = [];
$rows = array_map( static fn ( int $i ): array => [ 'v' => 'value-' . ( $i % 700 ) ], range( 0, 18000 ) );
foreach ( array_chunk( $rows, 137 ) as $chunk ) ContentDigest::append( $work . '/sort-a', $a, $chunk, $columns );
foreach ( array_chunk( array_reverse( $rows ), 991 ) as $chunk ) ContentDigest::append( $work . '/sort-b', $b, $chunk, $columns );
$merge_replayed = false;
while ( true ) {
	$old = $a;
	$done = ContentDigest::finish_tick( $work . '/sort-a', $a );
	if ( ! $merge_replayed && isset( $old['merge'] ) ) { $a = $old; $done = ContentDigest::finish_tick( $work . '/sort-a', $a ); $merge_replayed = true; }
	if ( $done ) break;
}
while ( ! ContentDigest::finish_tick( $work . '/sort-b', $b ) ) {}
check( $merge_replayed, 'External merge checkpoint replay was not exercised.' );
ContentDigest::assert_equal( ContentDigest::summary( $a, $columns ), ContentDigest::summary( $b, $columns ), 'sort-fixture' );
echo "Order-independent duplicate-preserving digest and merge replay: PASS\n";
sql( $wpdb->prepare( 'SET SESSION SQL_MODE=%s', $initial_mode ) );
