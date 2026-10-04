<?php
declare(strict_types=1);

require __DIR__ . '/bootstrap.php';

use CB\Backups\DB\Schema;
use CB\Backups\Integration\MigrationRecovery as BackupsMigrationRecovery;
use CB\Backups\Jobs\Repository;
use CB\Backups\Jobs\Runner;
use CB\Backups\Storage\LocalStorage;
use CB\Backups\Restore\ArchiveValidator;
use CB\Backups\Restore\Service as RestoreService;
use CoreBlueprint\Core\Core as BaseCore;
use CoreBlueprint\Core\DB as BaseDB;
use CoreBlueprint\Core\Migration\Recovery as BaseMigrationRecovery;

// This exercises real database jobs/archive I/O against the real Base migration
// recovery authority. It does not claim full-site, browser, Beacon or Hub coverage.
$header = (string) file_get_contents( dirname( __DIR__, 2 ) . '/core-blueprint-backups.php' );
preg_match( "/define\\( 'CB_BACKUPS_VERSION', '([^']+)' \\)/", $header, $version );
define( 'CB_BACKUPS_VERSION', $version[1] );
define( 'CB_BACKUPS_STORAGE_PATH', sys_get_temp_dir() . '/cb-engine-e2e-' . bin2hex( random_bytes( 5 ) ) );
LocalStorage::ensure();
Schema::install();

$source_admin = get_user_by( 'login', 'test-admin' );
check( $source_admin instanceof WP_User, 'Source administrator fixture is missing.' );
wp_set_current_user( (int) $source_admin->ID );
BaseCore::activate();

$source = serialize( [ 'url' => 'https://source.example.test/course', 'css' => 'calc(100% - 20px)', 'nested' => serialize( [ 'percent' => '100%' ] ) ] );
$expected = serialize( [ 'url' => 'https://destination.example.test/course', 'css' => 'calc(100% - 20px)', 'nested' => serialize( [ 'percent' => '100%' ] ) ] );
update_option( 'cb_e2e_payload', $source );
update_option( 'rewrite_rules', [ '^source-route/?$' => 'index.php?source_route=1' ] );
$post_id = wp_insert_post( [ 'post_title' => 'Fidelity E2E', 'post_status' => 'publish', 'post_content' => '100% https://source.example.test/course', 'guid' => 'https://source.example.test/?e2e=1' ], true );
check( ! is_wp_error( $post_id ), 'Cannot create WordPress post fixture.' );
$source_guid = $wpdb->get_var( $wpdb->prepare( 'SELECT guid FROM cbtest_posts WHERE ID=%d', $post_id ) );

function run_job( array $job, array $stop_stages = [] ): array {
	check( ! empty( $job['job_id'] ), 'Cannot create real database job.' );
	for ( $i = 0; $i < 20000; ++$i ) {
		$job = Runner::tick( $job['job_id'] );
		check( is_array( $job ), 'Job disappeared.' );
		if ( 'failed' === $job['status'] ) throw new RuntimeException( 'Engine failure at ' . $job['stage'] . ': ' . $job['error_text'] );
		if ( 'completed' === $job['status'] ) return $job;
		if ( in_array( (string) $job['stage'], $stop_stages, true ) ) return $job;
	}
	throw new RuntimeException( 'Engine job tick limit exceeded.' );
}

function current_test_admin(): WP_User {
	wp_set_current_user( 0 );
	wp_cache_flush();
	wp_roles()->for_site();

	$user = get_user_by( 'login', 'test-admin' );
	check( $user instanceof WP_User, 'Current-site administrator fixture is missing.' );

	wp_set_current_user( (int) $user->ID );
	$current = wp_get_current_user();
	check( $current instanceof WP_User && $current->ID === $user->ID, 'Current-site administrator runtime could not be established.' );

	return $current;
}

$backup = run_job( Repository::create( 'backup', 'database', 'manual' ) );
$archive = LocalStorage::archive_path( $backup['archive_name'] );
$manifest = ArchiveValidator::validate( $archive, false );
check( ! is_ssl() && 'https://source.example.test/' === $manifest['site']['site_url'], 'Backup identity must retain configured HTTPS under CLI.' );
check( ! empty( $manifest['database']['content_integrity']['cbtest_options'] ), 'Packaged source integrity metadata is missing.' );
update_option( 'cb_e2e_payload', 'changed after backup' );
wp_set_current_user( (int) get_user_by( 'login', 'test-admin' )->ID );
// Drive the real worker synchronously; do not send loopback requests to fixture hosts.
add_filter( 'pre_http_request', static fn() => new WP_Error( 'test_loopback', 'Test runner drives the worker.' ) );
$created_restore = RestoreService::create( $archive, 'manual', true );
$confirmation = $created_restore['meta']['restore_confirmation'];
$restore = run_job( $created_restore );
check( $confirmation === $restore['meta']['restore_confirmation'] && true === $confirmation['accepted'], 'Same-site restore lost the persisted acknowledgement.' );
check( $source === get_option( 'cb_e2e_payload' ), 'Engine same-site restore did not recover the source value.' );
check( ! empty( $restore['meta']['db_content_verified'] ), 'Engine completed without content proof.' );
check( ! is_file( ABSPATH . '.maintenance' ), 'Same-site restore left maintenance enabled.' );
check( false === get_option( 'rewrite_rules' ), 'Restore must invalidate rewrite cache without persisting the worker registrations.' );
echo "Database engine same-site E2E: create -> archive -> verify -> stage -> shadow proof -> live -> Completed: PASS\n";

wp_set_current_user( 0 );
$wpdb->set_prefix( 'cbe2etarget_' );
wp_cache_flush();

BaseDB::create_audit_log_table();

wp_install(
	'Disposable migration destination',
	'test-admin',
	'nobody@example.test',
	false,
	'',
	'disposable-test-only-password'
);
update_option( 'home', 'https://destination.example.test' );
update_option( 'siteurl', 'https://destination.example.test' );

$destination_admin = current_test_admin();
check( current_user_can( 'manage_options' ), 'Destination administrator lacks native management authority before Base activation.' );

BaseCore::activate();

$destination_admin = current_test_admin();
check(
	current_user_can( 'manage_options' ) || current_user_can( 'cb_manage_permissions' ),
	'Destination Base activation did not establish governed site-management authority.'
);
Schema::install();
wp_cache_flush();

$created_restore = RestoreService::create( $archive, 'manual_import', true );
$confirmation = $created_restore['meta']['restore_confirmation'];
$restore = run_job( $created_restore, [ 'await_recovery' ] );

check( 'await_recovery' === $restore['stage'], 'Migration must pause for destination recovery authentication.' );
$ticket = (string) ( $restore['meta']['migration_recovery']['ticket'] ?? '' );
check( '' !== $ticket, 'Migration recovery ticket is missing.' );

$destination_admin = current_test_admin();
check(
	in_array( 'administrator', (array) $destination_admin->roles, true ),
	'Migrated destination administrator role is missing.'
);
check(
	! current_user_can( 'manage_options' ),
	'Migrated privileged identity must remain fail-closed until recovery authentication completes.'
);

$_REQUEST[ BaseMigrationRecovery::PARAM ] = $ticket;
try {
	BaseMigrationRecovery::complete_authenticated_login(
		(string) $destination_admin->user_login,
		$destination_admin
	);
} finally {
	unset( $_REQUEST[ BaseMigrationRecovery::PARAM ] );
}

$recovery_status = BaseMigrationRecovery::status( $ticket );
check( 'authenticated' === (string) ( $recovery_status['status'] ?? '' ), 'Destination recovery authentication did not complete.' );

BackupsMigrationRecovery::prepare_destination( (string) $restore['job_id'] );
$restore = BackupsMigrationRecovery::finalize( (string) $restore['job_id'] );
check( 'completed' === $restore['status'], 'Migration recovery did not finalize the server-owned job.' );
check( $confirmation === $restore['meta']['restore_confirmation'] && 'https://destination.example.test' === $confirmation['target_site'], 'Migration lost the original acknowledgement or destination.' );
check( 'migration' === $restore['meta']['restore_mode'], 'Engine did not select migration.' );
check( $expected === get_option( 'cb_e2e_payload' ), 'Engine migration did not produce independently expected values.' );
check( $source_guid === $wpdb->get_var( $wpdb->prepare( 'SELECT guid FROM cbe2etarget_posts WHERE ID=%d', $post_id ) ), 'Migration changed the post GUID.' );
check( 1 === (int) $wpdb->get_var( "SELECT COUNT(*) FROM cbe2etarget_options WHERE option_name='cbe2etarget_user_roles'" ), 'Role option prefix was not migrated.' );
check( 0 < (int) $wpdb->get_var( "SELECT COUNT(*) FROM cbe2etarget_usermeta WHERE meta_key='cbe2etarget_capabilities'" ), 'User capability prefix was not migrated.' );
check( ! is_file( ABSPATH . '.maintenance' ), 'Migration left maintenance enabled.' );
check( false === get_option( 'rewrite_rules' ), 'Migration must defer rewrite generation to the restored-site runtime.' );
echo "Database engine migration E2E with URL/prefix, serialized values, GUID and role keys: PASS\n";
