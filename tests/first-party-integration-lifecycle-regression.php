<?php
declare(strict_types=1);

$root = file_get_contents( __DIR__ . '/../core-blueprint-backups.php' );
if ( false === $root ) {
	fwrite( STDERR, "FAIL: could not read plugin bootstrap.\n" );
	exit( 1 );
}

$expect = static function ( bool $condition, string $message ): void {
	if ( $condition ) {
		return;
	}
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
};

$integration_section = strpos( $root, '/* Suite integrations attach only after complete Backups readiness. */' );
$product_gate        = false !== $integration_section ? strpos( $root, 'Requirements::product_ready()', $integration_section ) : false;
$suite_registration  = false !== $integration_section ? strpos( $root, '\\CB\\Backups\\Bootstrap::register_suite_integration();', $integration_section ) : false;
$runtime_boot        = strpos( $root, '\\CB\\Backups\\Bootstrap::boot();' );

$expect( false !== $integration_section, 'Plugin bootstrap must retain the guarded integration section.' );
$expect( false !== $product_gate, 'Product readiness must be evaluated before integrations attach.' );
$expect( false !== $suite_registration, 'Plugin bootstrap must attach suite integration.' );
$expect( false !== $runtime_boot, 'Plugin bootstrap must retain the guarded feature-runtime boot.' );
$expect( $product_gate < $suite_registration, 'Suite integration must not attach before product Base contracts are ready.' );
$expect( $suite_registration < $runtime_boot, 'Feature runtime must remain after integration registration.' );
$expect( ! str_contains( $root, 'Update URI:' ), 'WordPress.org runtime must not declare an external update authority.' );

fwrite( STDOUT, "First-party integration lifecycle: PASS\n" );
