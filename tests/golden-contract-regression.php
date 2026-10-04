<?php
declare(strict_types=1);

$root = dirname( __DIR__ );

function cb_backups_golden_expect( bool $condition, string $message ): void {
	if ( $condition ) {
		return;
	}
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

function cb_backups_golden_read( string $relative ): string {
	global $root;
	$path = $root . '/' . $relative;
	cb_backups_golden_expect( is_file( $path ), "Missing expected file: {$relative}" );
	$content = file_get_contents( $path );
	cb_backups_golden_expect( is_string( $content ), "Could not read: {$relative}" );
	return $content;
}

$main         = cb_backups_golden_read( 'core-blueprint-backups.php' );
$bootstrap    = cb_backups_golden_read( 'src/Bootstrap.php' );
$requirements = cb_backups_golden_read( 'src/Support/Requirements.php' );
$schema       = cb_backups_golden_read( 'src/DB/Schema.php' );
$capabilities = cb_backups_golden_read( 'src/Support/Capabilities.php' );
$routes       = cb_backups_golden_read( 'src/Remote/Routes.php' );
$assets       = cb_backups_golden_read( 'src/Admin/Assets.php' );
$admin_page   = cb_backups_golden_read( 'src/Admin/Page.php' );
$actions      = cb_backups_golden_read( 'src/Admin/Actions.php' );
$uploader     = cb_backups_golden_read( 'src/Import/ChunkedUploader.php' );
$validator    = cb_backups_golden_read( 'src/Restore/ArchiveValidator.php' );
$migration    = cb_backups_golden_read( 'src/Restore/MigrationPlan.php' );
$transformer  = cb_backups_golden_read( 'src/Restore/MigrationTransformer.php' );
$restore      = cb_backups_golden_read( 'src/Restore/Engine.php' );
$restore_service = cb_backups_golden_read( 'src/Restore/Service.php' );
$migration_recovery = cb_backups_golden_read( 'src/Integration/MigrationRecovery.php' );
$runner       = cb_backups_golden_read( 'src/Jobs/Runner.php' );
$css          = cb_backups_golden_read( 'assets/css/admin.css' );
$builder      = cb_backups_golden_read( 'tools/build-release' );
$readme       = cb_backups_golden_read( 'readme.txt' );

cb_backups_golden_expect( str_contains( $main, 'Version:     1.0.0' ), 'Plugin header must remain 1.0.0.' );
cb_backups_golden_expect( str_contains( $main, "define( 'CB_BACKUPS_VERSION', '1.0.0' );" ), 'Runtime version must remain 1.0.0.' );
cb_backups_golden_expect( str_contains( $main, "define( 'CB_BACKUPS_REQUIRED_API', '1.0' );" ), 'Backups must require Base API 1.0.' );
cb_backups_golden_expect( str_contains( $main, 'Requires Plugins: core-blueprint' ), 'Backups must declare the native Base dependency.' );
cb_backups_golden_expect( ! str_contains( $main, 'function cb_backups_api_compatible' ), 'Pre-v1 API compatibility wrapper must stay removed.' );
cb_backups_golden_expect( ! str_contains( $main, 'function cb_backups_base_ready' ), 'Pre-v1 Base readiness wrapper must stay removed.' );
cb_backups_golden_expect( str_contains( $requirements, 'public static function base_contracts_ready()' ), 'Product Base contracts must have one canonical readiness boundary.' );
cb_backups_golden_expect( str_contains( $requirements, 'public static function product_ready()' ), 'Product readiness must be owned by Requirements.' );
cb_backups_golden_expect( str_contains( $main, "register_activation_hook( __FILE__, 'cb_backups_activate' );" ), 'Activation must pass through the readiness gate.' );

$integration_section = strpos( $main, '/* Suite integrations attach only after complete Backups readiness. */' );
$product_gate        = false !== $integration_section ? strpos( $main, 'Requirements::product_ready()', $integration_section ) : false;
$suite_init          = false !== $integration_section ? strpos( $main, 'Bootstrap::register_suite_integration();', $integration_section ) : false;
cb_backups_golden_expect( false !== $product_gate && false !== $suite_init && $product_gate < $suite_init, 'Product Base contracts must be proven before suite integration.' );

foreach ( [
	'cb_core_register_extensions',
	'cb_core_register_pages',
	'cb_core_register_settings',
	'cb_core_cli_register_commands',
	'cb_core_dashboard_register_cards',
	'cb_core_module_status_definitions',
] as $legacy_base_hook ) {
	cb_backups_golden_expect(
		! str_contains( $bootstrap, $legacy_base_hook ),
		"Legacy Base public contract must not return: {$legacy_base_hook}"
	);
}

foreach ( [
	'core_blueprint_register_extensions',
	'core_blueprint_register_pages',
	'core_blueprint_register_settings',
	'core_blueprint_cli_register_commands',
	'core_blueprint_dashboard_register_cards',
	'core_blueprint_module_status_definitions',
] as $canonical_base_hook ) {
	cb_backups_golden_expect(
		str_contains( $bootstrap, $canonical_base_hook ),
		"Canonical Base public contract must remain registered: {$canonical_base_hook}"
	);
}

cb_backups_golden_expect( str_contains( $schema, 'use CoreBlueprint\\Core\\Database\\SchemaRegistry;' ), 'Backups schema must consume the canonical Base SchemaRegistry namespace.' );
cb_backups_golden_expect( ! str_contains( $schema, 'use CB\\Core\\Database\\SchemaRegistry;' ), 'Legacy Base SchemaRegistry namespace must not return.' );
cb_backups_golden_expect( str_contains( $capabilities, "add_filter( 'core_blueprint_capability_catalog'" ), 'Backups capabilities must register through the canonical Base capability catalog hook.' );
cb_backups_golden_expect( ! str_contains( $capabilities, "add_filter( 'cb_core_capability_catalog'" ), 'Legacy Base capability catalog hook must not return.' );

cb_backups_golden_expect( str_contains( $routes, 'use CB\\Beacon\\Rest\\RemoteRouteRegistry;' ), 'Backups must use canonical Beacon RemoteRouteRegistry.' );
cb_backups_golden_expect( str_contains( $routes, 'use CB\\Beacon\\Tickets\\Service as TicketService;' ), 'Backups must use canonical Beacon ticket service.' );
cb_backups_golden_expect( ! str_contains( $routes, 'CB\\Core\\Beacon' ), 'Legacy Beacon namespace must not return.' );
cb_backups_golden_expect( str_contains( $routes, "add_action( 'cb_core_beacon_register_remote_routes', [ self::class, 'register' ], 10, 0 );" ), 'Beacon remote registration hook must remain argument-free.' );

cb_backups_golden_expect( ! str_contains( $main, 'Update URI:' ), 'WordPress.org builds must use WordPress.org as update authority.' );
cb_backups_golden_expect( ! is_file( $root . '/src/Integration/Updates.php' ), 'External Updates adapter must not ship in the WordPress.org source.' );
cb_backups_golden_expect( str_contains( $readme, 'Stable tag: 1.0.0' ), 'WordPress.org readme Stable tag must match the plugin version.' );

cb_backups_golden_expect( str_contains( $bootstrap, "'foundations' => [ 'modal', 'toast', 'time-picker' ]" ), 'Operational page must declare its Foundation requirements.' );
cb_backups_golden_expect( str_contains( $bootstrap, "'nav-tabs'" ) && str_contains( $bootstrap, "'form-controls'" ), 'Operational page must declare shared component requirements.' );
cb_backups_golden_expect( str_contains( $css, 'var(--cb-' ), 'Backups admin CSS must consume Base design tokens.' );
cb_backups_golden_expect( ! str_contains( $assets, 'cb-core-css-' ), 'Backups must not depend on Base-private CSS handles.' );

cb_backups_golden_expect( str_contains( $admin_page, 'Full-site backups can be restored on the original site or migrated to another single-site WordPress installation.' ), 'Restore warning must expose the portable full-site migration workflow.' );
cb_backups_golden_expect( ! str_contains( $admin_page, 'Migration and URL replacement are intentionally blocked.' ), 'Pre-migration blocked copy must not return.' );
cb_backups_golden_expect( ! str_contains( $admin_page, 'only restores to the same site URL and table prefix' ), 'Same-site-only restore copy must not return.' );
cb_backups_golden_expect( str_contains( $validator, 'MigrationPlan::build( $manifest );' ), 'Archive validation must retain destination-aware migration preflight.' );
cb_backups_golden_expect( str_contains( $migration, "'requires_migration'" ) && str_contains( $migration, "'table_map'" ), 'Migration plan must retain explicit mode detection and table mapping.' );
cb_backups_golden_expect( str_contains( $transformer, 'replace_serialized_strings' ) && str_contains( $transformer, "'guid' !== \$column" ), 'Migration transform must remain serialization-aware and preserve post GUIDs.' );
cb_backups_golden_expect( str_contains( $restore, "'migrate_database'" ) && str_contains( $restore, 'assert_site_identity' ), 'Restore engine must retain a dedicated migration stage and destination identity verification.' );
cb_backups_golden_expect( str_contains( $uploader, 'MigrationPlan::build( $manifest )' ) && str_contains( $uploader, "'source_site_url'" ) && str_contains( $uploader, "'source_prefix'" ) && str_contains( $uploader, "'requires_migration'" ), 'Chunked imports must retain source identity and destination-aware migration metadata.' );
cb_backups_golden_expect( str_contains( $actions, "RestoreService::create( LocalStorage::import_path( \$archive ), 'manual_import'" ), 'Prepared imports must always enter the migration-aware restore service.' );
cb_backups_golden_expect( str_contains( $restore_service, 'MigrationRecovery::prepare( $plan )' ), 'Cross-site migration must prepare Base-owned destination recovery before destructive work starts.' );
cb_backups_golden_expect( str_contains( $restore, 'MigrationRecovery::activate_destination( $meta )' ) && str_contains( $restore, 'MigrationRecovery::reconcile_destination( $meta )' ), 'Restore engine must delegate destination trust recovery to the Base-backed integration.' );
cb_backups_golden_expect( str_contains( $restore, "'await_recovery'" ) && str_contains( $restore, "'progress' => 99" ), 'Migration must remain non-terminal until destination recovery is completed.' );
cb_backups_golden_expect( str_contains( $runner, "'reconcile_destination'" ) && str_contains( $runner, "'await_recovery'" ), 'Restore runner must enforce a fresh runtime boundary after the live migration switch.' );
cb_backups_golden_expect( str_contains( $migration_recovery, 'use CoreBlueprint\\Core\\Migration\\Recovery as BaseRecovery;' ), 'Backups migration recovery must consume Base authority instead of owning privileged trust.' );
cb_backups_golden_expect( str_contains( $migration_recovery, 'BaseRecovery::finalize' ) && str_contains( $migration_recovery, 'BaseRecovery::requires_pretty_routing' ), 'Backups must finalize through Base and defer rewrite requirements to Base.' );
cb_backups_golden_expect( str_contains( $migration_recovery, "wp_ajax_cb_backups_prepare_migration_recovery" ) && str_contains( $migration_recovery, 'prepare_destination( string $job_id )' ) && str_contains( $migration_recovery, 'flush_rewrite_rules( true )' ), 'Destination rewrites must be prepared through an explicit fresh authenticated request before browser verification.' );
cb_backups_golden_expect( str_contains( $migration_recovery, "'migration_recovery_auth_required'" ), 'Quarantined destination recovery must expose an explicit secure-sign-in handoff instead of a generic permission failure.' );
cb_backups_golden_expect( str_contains( $bootstrap, 'MigrationRecovery::boot();' ), 'Base-backed migration recovery integration must boot on destination requests.' );
cb_backups_golden_expect( ! is_file( $root . '/src/Restore/MigrationAccessRecovery.php' ), 'Backups-owned security recovery authority must stay removed.' );

cb_backups_golden_expect( ! is_file( $root . '/tools/build-release.py' ), 'Superseded build-release.py must stay removed.' );
cb_backups_golden_expect( str_contains( $builder, 'EXPECTED_VERSION = "1.0.0"' ), 'Release builder must pin stable 1.0.0.' );
cb_backups_golden_expect( str_contains( $builder, 'EXPECTED_API = "1.0"' ), 'Release builder must pin Base API 1.0.' );
cb_backups_golden_expect( str_contains( $builder, 'REQUIRED_PHP_MINORS = {(8, 4), (8, 5)}' ), 'Release builder must require PHP 8.4 and 8.5.' );
cb_backups_golden_expect( str_contains( $builder, 'RUNTIME_FILES = ("core-blueprint-backups.php", "uninstall.php", "readme.txt")' ), 'Release builder must package readme.txt.' );
cb_backups_golden_expect( str_contains( $builder, 'RUNTIME_DIRS = ("src", "assets", "languages")' ), 'Release builder must package runtime directories only.' );
cb_backups_golden_expect( str_contains( $builder, 'run_i18n_check()' ), 'Release builder must use canonical tools/i18n/check authority.' );
cb_backups_golden_expect( str_contains( $builder, 'msgfmt' ), 'Release builder must compile staged MO catalogs with GNU gettext.' );
cb_backups_golden_expect( str_contains( $builder, 'ZIP_TIMESTAMP' ) && str_contains( $builder, 'ZIP_FILE_MODE' ), 'Release builder must enforce deterministic ZIP metadata.' );
cb_backups_golden_expect( str_contains( $builder, 'write_checksum(target)' ), 'Release builder must generate SHA256 output.' );

echo "Backups Golden contract regression PASS\n";
