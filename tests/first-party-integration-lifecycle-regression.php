<?php
declare(strict_types=1);

$root = file_get_contents( __DIR__ . '/../core-blueprint-backups.php' );
if ( false === $root ) {
	fwrite( STDERR, "FAIL: could not read plugin bootstrap.\n" );
	exit( 1 );
}

function cb_backups_lifecycle_expect( bool $condition, string $message ): void {
	if ( $condition ) {
		return;
	}
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

$integration_section = strpos( $root, '/* Suite/update integrations attach only after complete Backups readiness. */' );
$product_gate        = false !== $integration_section ? strpos( $root, 'Requirements::product_ready()', $integration_section ) : false;
$suite_registration  = false !== $integration_section ? strpos( $root, '\\CB\\Backups\\Bootstrap::register_suite_integration();', $integration_section ) : false;
$updates_registration = false !== $integration_section ? strpos( $root, '\\CB\\Backups\\Integration\\Updates::init();', $integration_section ) : false;
$runtime_boot        = strpos( $root, '\\CB\\Backups\\Bootstrap::boot();' );

cb_backups_lifecycle_expect( false !== $integration_section, 'Plugin bootstrap must retain the guarded integration section.' );
cb_backups_lifecycle_expect( false !== $product_gate, 'Product readiness must be evaluated before integrations attach.' );
cb_backups_lifecycle_expect( false !== $suite_registration, 'Plugin bootstrap must attach suite integration.' );
cb_backups_lifecycle_expect( false !== $updates_registration, 'Plugin bootstrap must attach the Updates integration.' );
cb_backups_lifecycle_expect( false !== $runtime_boot, 'Plugin bootstrap must retain the guarded feature-runtime boot.' );
cb_backups_lifecycle_expect( $product_gate < $suite_registration, 'Suite integration must not attach before product Base contracts are ready.' );
cb_backups_lifecycle_expect( $product_gate < $updates_registration, 'Updates integration must not attach before product Base contracts are ready.' );
cb_backups_lifecycle_expect( $suite_registration < $runtime_boot, 'Feature runtime must remain after integration registration.' );

$bootstrap = file_get_contents( __DIR__ . '/../src/Bootstrap.php' );
cb_backups_lifecycle_expect( false !== $bootstrap, 'Could not read Backups Bootstrap class.' );
cb_backups_lifecycle_expect( str_contains( $bootstrap, "add_action( 'core_blueprint_register_extensions', [ self::class, 'register_extension' ] );" ), 'Suite integration must own ExtensionRegistry hook attachment.' );
cb_backups_lifecycle_expect( str_contains( $bootstrap, "add_action( 'init', [ self::class, 'register_presentation_hooks' ], 1 );" ), 'Suite integration must own status/presentation hook attachment.' );
cb_backups_lifecycle_expect( ! str_contains( $bootstrap, "LocalStorage::ensure();\n\t\t\t\$storage_path" ), 'Dashboard health must not initialize or mutate storage.' );
cb_backups_lifecycle_expect( str_contains( $bootstrap, 'LocalStorage::health_path();' ), 'Dashboard health must use the read-only storage projection.' );

echo "First-party integration lifecycle: PASS\n";
