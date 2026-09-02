<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use CB\Backups\Backup\DatabaseExporter;
use CB\Backups\DB\SqlValueCodec;
use CB\Backups\Restore\DatabaseContentVerifier;
use CB\Backups\Restore\DatabaseImporter;

$work = sys_get_temp_dir() . '/cb-timezone-' . bin2hex( random_bytes( 5 ) );
check( mkdir( $work, 0700 ), 'Cannot create timezone test work directory.' );

$original_time_zone = $wpdb->get_var( 'SELECT @@SESSION.time_zone' );
check( is_string( $original_time_zone ) && '' !== $original_time_zone, 'Cannot inspect original database time zone.' );

try {
	check( false !== $wpdb->query( "SET SESSION time_zone='+03:00'" ), 'Cannot set preflight database time zone.' );
	$callback_failed = false;
	try {
		SqlValueCodec::with_dump_mode(
			static function (): array {
				global $wpdb;
				check( '+00:00' === $wpdb->get_var( 'SELECT @@SESSION.time_zone' ), 'Dump callback is not running in UTC.' );
				throw new RuntimeException( 'timezone-restore-sentinel' );
			}
		);
	} catch ( RuntimeException $e ) {
		$callback_failed = 'timezone-restore-sentinel' === $e->getMessage();
	}
	check( $callback_failed, 'Dump wrapper did not preserve the callback failure.' );
	check( '+03:00' === $wpdb->get_var( 'SELECT @@SESSION.time_zone' ), 'Dump wrapper did not restore the time zone after failure.' );

	check( false !== $wpdb->query( "SET SESSION time_zone='+02:00'" ), 'Cannot set source database time zone.' );
	sql( 'DROP TABLE IF EXISTS cbtest_timezone_portability' );
	sql( 'CREATE TABLE cbtest_timezone_portability (id BIGINT UNSIGNED PRIMARY KEY, stamp TIMESTAMP NOT NULL) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci' );
	sql( "INSERT INTO cbtest_timezone_portability (id, stamp) VALUES (1, '2026-09-02 16:30:00')" );

	$source_epoch = (int) $wpdb->get_var( 'SELECT UNIX_TIMESTAMP(stamp) FROM cbtest_timezone_portability WHERE id=1' );
	check( $source_epoch > 0, 'Cannot read source TIMESTAMP epoch.' );

	$export = run_ticks( static fn ( array $meta ): array => DatabaseExporter::tick( $work, $meta ) );
	$tables = $export['db_tables'];
	check( '+02:00' === $wpdb->get_var( 'SELECT @@SESSION.time_zone' ), 'Exporter did not restore the source session time zone.' );

	$dump = file_get_contents( $work . '/database.sql' );
	check( is_string( $dump ), 'Cannot read generated database export.' );
	check( str_contains( $dump, "'2026-09-02 14:30:00'" ), 'Export did not serialize the TIMESTAMP in UTC.' );
	check( ! str_contains( $dump, "'2026-09-02 16:30:00'" ), 'Export retained the source session-local TIMESTAMP.' );

	check( false !== $wpdb->query( "SET SESSION time_zone='-05:00'" ), 'Cannot set destination database time zone.' );
	$job = 'timezone-portability';
	$import = run_ticks( static fn ( array $meta ): array => DatabaseImporter::tick( $work . '/database.sql', $meta, $tables, $job ) );
	check( ! empty( $import['db_import_finished'] ), 'Timezone restore import did not finish.' );
	check( '-05:00' === $wpdb->get_var( 'SELECT @@SESSION.time_zone' ), 'Importer did not restore the destination session time zone.' );

	$verified = run_ticks(
		static fn ( array $meta ): array => DatabaseContentVerifier::tick( $work . '/verified-shadow', $meta, $tables, $export['db_content_integrity'], $job ),
		$import
	);
	check( ! empty( $verified['db_content_verified'] ), 'Timezone restore shadow content was not verified.' );
	check( '-05:00' === $wpdb->get_var( 'SELECT @@SESSION.time_zone' ), 'Content verifier did not restore the destination session time zone.' );

	$index = array_search( 'cbtest_timezone_portability', $tables, true );
	check( false !== $index, 'Timezone fixture table was not exported.' );
	$shadow = private_call( DatabaseImporter::class, 'shadow_name', $job, (int) $index );
	$restored_epoch = (int) $wpdb->get_var( "SELECT UNIX_TIMESTAMP(stamp) FROM `{$shadow}` WHERE id=1" );
	check( $source_epoch === $restored_epoch, 'TIMESTAMP epoch changed across different source and destination session time zones.' );

	echo "Database TIMESTAMP export/import/content-verification portability: PASS\n";
} finally {
	$wpdb->query( $wpdb->prepare( 'SET SESSION time_zone=%s', $original_time_zone ) );
}
