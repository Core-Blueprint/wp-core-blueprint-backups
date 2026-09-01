<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use CB\Backups\Backup\DatabaseExporter;
use CB\Backups\DB\SqlValueCodec;
use CB\Backups\Restore\DatabaseImporter;
use CB\Backups\Restore\MigrationPlan;
use CB\Backups\Restore\MigrationTransformer;

$work = sys_get_temp_dir() . '/cb-fidelity-' . bin2hex( random_bytes( 5 ) );
check( mkdir( $work, 0700 ), 'Cannot create test work directory.' );
$values = [
	'100%', '/%category%/%postname%/', 'calc(100% - 20px)',
	"single ' double \" backslash \\ trailing \\", "NUL\0CR\rLF\nCRLF\r\nSUB" . chr( 26 ),
	'UTF-8 café 日本語 😀', null, '',
	'{"url":"https://source.example.test/a","width":"100%"}',
	'{"url":"https:\\/\\/source.example.test\\/api"}',
	serialize( [ 'url' => 'https://source.example.test/a', 'css' => '100%' ] ),
	serialize( [ 'nested' => serialize( [ 'url' => 'https://source.example.test/deep', 'css' => '100%' ] ) ] ),
	str_repeat( "https://source.example.test/large 100% 😀\n", 35000 ),
];
$expected_migration = $values;
$expected_migration[8] = '{"url":"https://destination.example.test/a","width":"100%"}';
$expected_migration[9] = '{"url":"https:\\/\\/destination.example.test\\/api"}';
$expected_migration[10] = serialize( [ 'url' => 'https://destination.example.test/a', 'css' => '100%' ] );
$expected_migration[11] = serialize( [ 'nested' => serialize( [ 'url' => 'https://destination.example.test/deep', 'css' => '100%' ] ) ] );
$expected_migration[12] = str_repeat( "https://destination.example.test/large 100% 😀\n", 35000 );
$binary = implode( '', array_map( 'chr', range( 0, 255 ) ) );
sql( 'CREATE TABLE cbtest_fidelity (id BIGINT UNSIGNED PRIMARY KEY, payload LONGTEXT NULL, raw LONGBLOB NULL) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
foreach ( $values as $i => $value ) {
	// Populate through real WordPress query execution, independently of the dump codec.
	$query = null === $value
		? $wpdb->prepare( "INSERT INTO cbtest_fidelity VALUES (%d, NULL, UNHEX(%s))", $i + 1, bin2hex( $binary ) )
		: $wpdb->prepare( "INSERT INTO cbtest_fidelity VALUES (%d, %s, UNHEX(%s))", $i + 1, $value, bin2hex( $binary ) );
	sql( $query );
}
check( str_contains( $wpdb->prepare( '%s', '100%' ), $wpdb->placeholder_escape() ), 'Real WordPress placeholder mechanism is not active.' );
check( "'100%'" === SqlValueCodec::encode( '100%' ), 'Dump codec retained placeholder state.' );
$export = run_ticks( static fn ( array $meta ): array => DatabaseExporter::tick( $work, $meta ) );
$tables = $export['db_tables'];
$job = 'fidelity-same';
$import = run_ticks( static fn ( array $meta ): array => DatabaseImporter::tick( $work . '/database.sql', $meta, $tables, $job ) );
$index = array_search( 'cbtest_fidelity', $tables, true );
check( false !== $index, 'Fixture table was not exported.' );
$shadow = private_call( DatabaseImporter::class, 'shadow_name', $job, $index );
$rows = $wpdb->get_results( "SELECT * FROM `{$shadow}` ORDER BY id", ARRAY_A );
check( count( $rows ) === count( $values ), 'Shadow row count differs.' );
foreach ( $rows as $i => $row ) {
	check( $values[ $i ] === $row['payload'], 'Same-site value mismatch at fixture ' . $i );
	check( $binary === $row['raw'], 'Binary mismatch at fixture ' . $i );
}
echo "Real wpdb -> exporter -> importer/shadow: PASS\n";

$manifest = [ 'database' => [ 'tables' => $tables ], 'site' => [ 'table_prefix' => 'cbtest_', 'home_url' => 'https://source.example.test', 'site_url' => 'https://source.example.test' ] ];
$wpdb->set_prefix( 'cbtarget_' );
add_filter( 'pre_option_home', static fn (): string => 'https://destination.example.test' );
add_filter( 'pre_option_siteurl', static fn (): string => 'https://destination.example.test' );
$plan = MigrationPlan::build( $manifest );
$migrated = run_ticks( static fn ( array $meta ): array => MigrationTransformer::tick( $work . '/database.sql', $work . '/migrated.sql', $meta, $plan ) );
$targets = $plan['target_tables'];
$migration_job = 'fidelity-migration';
$migration_import = run_ticks( static fn ( array $meta ): array => DatabaseImporter::tick( $work . '/migrated.sql', $meta, $targets, $migration_job ) );
$shadow = private_call( DatabaseImporter::class, 'shadow_name', $migration_job, $index );
$rows = $wpdb->get_results( "SELECT * FROM `{$shadow}` ORDER BY id", ARRAY_A );
check( count( $rows ) === count( $values ), 'Migration row count differs.' );
foreach ( $rows as $i => $row ) {
	check( $expected_migration[ $i ] === $row['payload'], 'Migration value mismatch at fixture ' . $i );
	check( $binary === $row['raw'], 'Migration changed binary data.' );
}
echo "Migration against independently specified values -> shadow: PASS\n";

$wpdb->set_prefix( 'cbtest_' );
$fail = static function ( string $query ): string {
	return str_starts_with( $query, 'SELECT * FROM `cbtest_fidelity` WHERE' ) ? 'SELECT * FROM cbtest_deliberately_missing_table' : $query;
};
check( mkdir( $work . '/failure', 0700 ), 'Cannot create failure test directory.' );
add_filter( 'query', $fail );
$wpdb->suppress_errors( true );
$failed = false;
try { run_ticks( static fn ( array $meta ): array => DatabaseExporter::tick( $work . '/failure', $meta ) ); }
catch ( RuntimeException $e ) { $failed = str_contains( $e->getMessage(), 'Could not export rows from cbtest_fidelity' ); }
finally { remove_filter( 'query', $fail ); $wpdb->suppress_errors( false ); }
check( $failed, 'A database read failure must fail the export.' );
echo "Injected SELECT failure: PASS\n";
