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

$main      = cb_backups_golden_read( 'core-blueprint-backups.php' );
$bootstrap = cb_backups_golden_read( 'src/Bootstrap.php' );
$routes    = cb_backups_golden_read( 'src/Remote/Routes.php' );
$updates   = cb_backups_golden_read( 'src/Integration/Updates.php' );
$assets    = cb_backups_golden_read( 'src/Admin/Assets.php' );
$css       = cb_backups_golden_read( 'assets/css/admin.css' );
$packager  = cb_backups_golden_read( 'tools/build-release.py' );

cb_backups_golden_expect( str_contains( $main, 'Version:     1.0.0-rc1' ), 'Plugin header must remain 1.0.0-rc1.' );
cb_backups_golden_expect( str_contains( $main, "define( 'CB_BACKUPS_VERSION', '1.0.0-rc1' );" ), 'Runtime version must remain 1.0.0-rc1.' );
cb_backups_golden_expect( str_contains( $main, "define( 'CB_BACKUPS_REQUIRED_API', '1.0' );" ), 'Backups must require Base API 1.0.' );
cb_backups_golden_expect( str_contains( $main, 'cb_backups_api_compatible' ), 'Base API compatibility helper is required.' );
cb_backups_golden_expect( str_contains( $main, 'cb_backups_base_ready' ), 'Canonical Base readiness helper is required.' );
cb_backups_golden_expect( str_contains( $main, "register_activation_hook( __FILE__, 'cb_backups_activate' );" ), 'Activation must pass through the Base API gate.' );

cb_backups_golden_expect( str_contains( $routes, 'use CB\\Beacon\\Rest\\RemoteRouteRegistry;' ), 'Backups must use canonical Beacon RemoteRouteRegistry.' );
cb_backups_golden_expect( str_contains( $routes, 'use CB\\Beacon\\Tickets\\Service as TicketService;' ), 'Backups must use canonical Beacon ticket service.' );
cb_backups_golden_expect( ! str_contains( $routes, 'CB\\Core\\Beacon' ), 'Legacy Beacon namespace must not return.' );
cb_backups_golden_expect( str_contains( $routes, "add_action( 'cb_core_beacon_register_remote_routes', [ self::class, 'register' ], 10, 0 );" ), 'Beacon remote registration hook must remain argument-free.' );

cb_backups_golden_expect( str_contains( $updates, "PRODUCT_KEY = 'core-blueprint-backups'" ), 'Updates product key must remain canonical.' );
cb_backups_golden_expect( str_contains( $updates, "VENDOR_ID   = 'core-blueprint'" ), 'Updates vendor identity must remain canonical.' );
cb_backups_golden_expect( str_contains( $main, 'Update URI:  https://coreblueprint.io/' ), 'Canonical Update URI must remain declared.' );

cb_backups_golden_expect( str_contains( $bootstrap, "'foundations' => [ 'modal', 'toast', 'time-picker' ]" ), 'Operational page must declare its Foundation requirements.' );
cb_backups_golden_expect( str_contains( $bootstrap, "'nav-tabs'" ) && str_contains( $bootstrap, "'form-controls'" ), 'Operational page must declare shared component requirements.' );
cb_backups_golden_expect( str_contains( $css, 'var(--cb-' ), 'Backups admin CSS must consume Base design tokens.' );
cb_backups_golden_expect( ! str_contains( $assets, 'cb-core-css-' ), 'Backups must not depend on Base-private CSS handles.' );

cb_backups_golden_expect( str_contains( $packager, 'EXPECTED_VERSION = "1.0.0-rc1"' ), 'Release builder must pin RC1.' );
cb_backups_golden_expect( str_contains( $packager, 'EXPECTED_API = "1.0"' ), 'Release builder must pin Base API 1.0.' );
cb_backups_golden_expect( str_contains( $packager, 'REQUIRED_PHP_MINORS = {(8, 4), (8, 5)}' ), 'Release builder must require PHP 8.4 and 8.5.' );
cb_backups_golden_expect( str_contains( $packager, 'RUNTIME_DIRS = ("src", "assets", "languages")' ), 'Release builder must package runtime directories only.' );
cb_backups_golden_expect( str_contains( $packager, 'write_checksum(target)' ), 'Release builder must generate SHA256 output.' );
cb_backups_golden_expect( str_contains( $packager, 'translation quality gate failed' ), 'Release builder must reject predominantly-English locale catalogs.' );

echo "Backups Golden contract regression PASS\n";
