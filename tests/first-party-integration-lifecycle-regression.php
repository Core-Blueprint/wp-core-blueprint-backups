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

$suite_registration = strpos( $root, '\\CB\\Backups\\Bootstrap::register_suite_integration();' );
$runtime_gate       = strpos( $root, "add_action( 'plugins_loaded'" );
$runtime_boot       = strpos( $root, '\\CB\\Backups\\Bootstrap::boot();' );

cb_backups_lifecycle_expect( false !== $suite_registration, 'Plugin bootstrap must attach lightweight suite integration.' );
cb_backups_lifecycle_expect( false !== $runtime_gate, 'Plugin bootstrap must retain the plugins_loaded runtime gate.' );
cb_backups_lifecycle_expect( false !== $runtime_boot, 'Plugin bootstrap must retain the guarded feature-runtime boot.' );
cb_backups_lifecycle_expect( $suite_registration < $runtime_gate, 'Suite integration must attach before runtime dependency evaluation.' );
cb_backups_lifecycle_expect( $runtime_gate < $runtime_boot, 'Feature runtime must remain behind the dependency gate.' );

$bootstrap = file_get_contents( __DIR__ . '/../src/Bootstrap.php' );
cb_backups_lifecycle_expect( false !== $bootstrap, 'Could not read Backups Bootstrap class.' );
cb_backups_lifecycle_expect( str_contains( $bootstrap, "add_action( 'cb_core_register_extensions', [ self::class, 'register_extension' ] );" ), 'Lightweight integration must own ExtensionRegistry hook attachment.' );
cb_backups_lifecycle_expect( str_contains( $bootstrap, "add_action( 'init', [ self::class, 'register_presentation_hooks' ], 1 );" ), 'Lightweight integration must own status/presentation hook attachment.' );
cb_backups_lifecycle_expect( ! str_contains( $bootstrap, "LocalStorage::ensure();\n\t\t\t\$storage_path" ), 'Dashboard health must not initialize or mutate storage.' );
cb_backups_lifecycle_expect( str_contains( $bootstrap, 'LocalStorage::health_path();' ), 'Dashboard health must use the read-only storage projection.' );

echo "First-party integration lifecycle: PASS\n";
