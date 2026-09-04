<?php
declare(strict_types=1);

// Only the Base page/UI contracts are doubled. WordPress, SQL, Repository and
// Page rendering are real; no archive is restored by this presentation regression.
namespace CB\Core\Admin { interface Page {} }
namespace CB\Core\UI {
	final class StateBadge {
		public const NEUTRAL = 'neutral';
		public const SUCCESS = 'success';
		public const ERROR = 'error';
		public const INFO = 'info';
		public const WARNING = 'warning';

		/** @param array<string,mixed> $args */
		public static function render( string $label, array $args = [] ): string {
			$variant = (string) ( $args['variant'] ?? self::NEUTRAL );
			return '<span class="cb-core-state-badge cb-core-state-badge--' . htmlspecialchars( $variant ) . '">' . htmlspecialchars( $label ) . '</span>';
		}
	}
}
namespace {
	require __DIR__ . '/bootstrap.php';

	use CB\Backups\Admin\Page;
	use CB\Backups\DB\Schema;
	use CB\Backups\Jobs\Repository;
	use CB\Backups\Storage\LocalStorage;

	Schema::install();
	update_option( 'timezone_string', 'Europe/Amsterdam' );
	$tag = 'history-' . bin2hex( random_bytes( 4 ) );
	$imports = [];
	foreach ( [ 'migration', 'restore', 'unused' ] as $mode ) {
		$imports[ $mode ] = [
			'archive_name' => $tag . '-' . $mode . '.cbbackup',
			// Deliberately identical display names; only the storage identity counts.
			'original_name' => 'same-upload-name.cbbackup',
			'backup_type' => 'database', 'size' => 123,
			'created_timestamp' => strtotime( '2026-09-02 12:00:00 UTC' ),
			'source_site_url' => 'restore' === $mode ? 'https://source.example.test' : 'https://different.example.test',
			'source_prefix' => 'restore' === $mode ? $wpdb->prefix : 'different_',
		];
	}
	function history_job( string $archive, string $mode = 'migration', string $status = 'completed' ): array {
		$job = Repository::create( 'restore', 'database', 'manual_import', [ 'archive_path' => $archive, 'restore_mode' => $mode, 'large_unused_metadata' => str_repeat( 'x', 4096 ) ] );
		check( ! empty( $job['job_id'] ), 'History fixture job missing.' );
		Repository::update( $job['job_id'], [ 'status' => $status, 'progress' => 'running' === $status ? 47 : 100, 'completed_at' => in_array( $status, [ 'completed', 'failed', 'cancelled' ], true ) ? '2026-09-02 14:10:50' : null ] );
		return Repository::get( $job['job_id'] );
	}
	function history_render( array $imports ): string {
		ob_start();
		try { ( new \ReflectionMethod( Page::class, 'prepared_import_table' ) )->invoke( new Page(), $imports ); return ob_get_contents(); }
		finally { ob_end_clean(); }
	}
	function history_cells( string $html ): array {
		$doc = new \DOMDocument();
		$previous = libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="UTF-8">' . $html );
		libxml_clear_errors(); libxml_use_internal_errors( $previous );
		$xpath = new \DOMXPath( $doc );
		$rows = [];
		foreach ( $xpath->query( '//tbody/tr' ) as $row ) {
			$cells = [];
			foreach ( $xpath->query( './td', $row ) as $cell ) $cells[] = trim( $cell->textContent );
			$rows[] = $cells;
		}
		return $rows;
	}

	$a = LocalStorage::import_path( $imports['migration']['archive_name'] );
	$b = LocalStorage::import_path( $imports['restore']['archive_name'] );
	$first = history_job( $a );
	$second = history_job( $b, 'restore' );
	// A local archive with the same filename and backup jobs must not match.
	history_job( LocalStorage::archive_path( $imports['migration']['archive_name'] ), 'restore', 'failed' );
	Repository::create( 'backup', 'database', 'manual', [ 'archive_path' => $a, 'restore_mode' => 'restore' ] );
	// History is not restricted to the latest 100 jobs.
	for ( $i = 0; $i < 105; ++$i ) history_job( LocalStorage::import_path( $tag . '-other-' . $i . '.cbbackup' ) );
	$before = $wpdb->num_queries;
	$latest = Repository::latest_restores_for_archives( [ $a, $b, $a ] );
	check( $wpdb->num_queries === $before + 1, 'Import histories must be read in one batch.' );
	check( 2 === count( $latest ) && $first['job_id'] === $latest[$a]['job_id'] && $second['job_id'] === $latest[$b]['job_id'], 'History matched a different archive or lost an older relevant job.' );
	check( ! array_key_exists( 'meta', $latest[$a] ), 'History query fetched large private job metadata.' );
	$html = history_render( array_values( $imports ) );
	$cells = history_cells( $html );
	check( 3 === count( $cells ) && 7 === count( $cells[0] ), 'Prepared imports table markup is malformed.' );
	check( '2026-09-02 14:00:00' === $cells[0][0], 'Imported must remain the original upload time in the site timezone.' );
	check( str_contains( $cells[0][5], 'Migrated successfully' ) && str_contains( $cells[0][5], 'Last migrated: 2026-09-02 16:10:50' ), 'Migration completion/date/timezone is wrong.' );
	check( str_contains( $cells[1][5], 'Restored successfully' ) && str_contains( $cells[1][5], 'Last restored:' ), 'Same-site restore was labeled as migration.' );
	check( str_contains( $cells[2][5], 'Migration ready' ) && ! str_contains( $cells[2][6], 'View result' ), 'Unused import must remain ready.' );
	check( str_contains( $html, 'job=' . $first['job_id'] ) && str_contains( $cells[0][6], 'View result' ) && str_contains( $cells[0][6], 'Migrate again' ) && str_contains( $cells[1][6], 'Restore again' ), 'Result/repeat actions do not refer to the matching jobs.' );
	check( str_contains( $html, 'name="restore_acknowledged" value=""' ) && str_contains( $html, 'data-cb-modal-confirm="1"' ), 'Repeated restore lost its required fresh acknowledgement.' );

	foreach ( [ 'failed' => 'Migration failed', 'queued' => 'Migration queued', 'running' => 'Migrating… 47%', 'cancelled' => 'Migration cancelled' ] as $state => $expected ) {
		$new = history_job( $a, 'migration', $state );
		// Force a timestamp tie: numeric creation order still selects the new attempt.
		$wpdb->update( Schema::table(), [ 'created_at' => $first['created_at'] ], [ 'job_id' => $new['job_id'] ] );
		$html = history_render( [ $imports['migration'] ] );
		$row = history_cells( $html )[0];
		check( str_contains( $row[5], $expected ) && ! str_contains( $row[5], 'Migrated successfully' ) && ! str_contains( $row[5], 'Last migrated:' ), 'Earlier success concealed the latest attempt.' );
		check( str_contains( $html, 'job=' . $new['job_id'] ), 'View result/progress points to an older attempt.' );
		if ( in_array( $state, [ 'queued', 'running' ], true ) ) {
			check( str_contains( $row[6], 'View progress' ) && ! str_contains( $html, 'value="cb_backups_restore_import"' ) && ! str_contains( $html, 'value="cb_backups_delete_import"' ), 'Active import must offer monitoring rather than rerun/delete.' );
		}
	}
	$winter = history_job( $a );
	Repository::update( $winter['job_id'], [ 'completed_at' => '2026-01-02 14:10:50' ] );
	check( str_contains( history_render( [ $imports['migration'] ] ), 'Last migrated: 2026-01-02 15:10:50' ), 'Winter timezone conversion reused the summer offset.' );

	$case_a = LocalStorage::import_path( $tag . '-Case.cbbackup' );
	$case_b = LocalStorage::import_path( $tag . '-case.cbbackup' );
	$case_job_a = history_job( $case_a ); $case_job_b = history_job( $case_b, 'restore', 'failed' );
	$case_rows = Repository::latest_restores_for_archives( [ $case_a, $case_b ] );
	check( 2 === count( $case_rows ) && $case_rows[$case_a]['job_id'] === $case_job_a['job_id'] && $case_rows[$case_b]['job_id'] === $case_job_b['job_id'], 'Case-insensitive SQL collation combined different archive paths.' );
	$literal_path = '/fixture/' . $tag . '/100%_backup.cbbackup';
	$literal_job = history_job( $literal_path ); history_job( str_replace( '%_', 'ZZ', $literal_path ), 'restore', 'failed' );
	check( Repository::latest_restores_for_archives( [ $literal_path ] )[$literal_path]['job_id'] === $literal_job['job_id'], 'Exact archive identity was treated as a LIKE pattern.' );

	// A database read error must never become a misleading Ready/success state.
	$break_history = static fn( string $sql ): string => str_contains( $sql, 'AS restore_archive_path' ) ? 'SELECT nonexistent_history_column FROM ' . Schema::table() : $sql;
	$old_errors = $wpdb->suppress_errors( true );
	add_filter( 'query', $break_history );
	try {
		$html = history_render( [ $imports['migration'] ] );
		check( str_contains( $html, 'Status unavailable' ) && ! str_contains( $html, 'Migration ready' ) && ! str_contains( $html, 'Migrated successfully' ) && ! str_contains( $html, 'value="cb_backups_restore_import"' ), 'Query failure was misreported as a safe ready state.' );
	} finally { remove_filter( 'query', $break_history ); $wpdb->suppress_errors( $old_errors ); }
	echo "Imported restore history: exact identity, latest attempt, timestamps/DST, actions, required acknowledgement and query failure: PASS\n";
}
