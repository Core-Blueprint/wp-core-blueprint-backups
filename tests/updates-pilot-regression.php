<?php
declare(strict_types=1);

$root      = dirname( __DIR__ );
$bootstrap = file_get_contents( $root . '/core-blueprint-backups.php' );
$readme    = file_get_contents( $root . '/readme.txt' );

$fail = static function ( string $message ): never {
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
};

if ( false === $bootstrap || false === $readme ) {
	$fail( 'WordPress.org distribution sources must be readable.' );
}
if ( ! str_contains( $bootstrap, 'Version:     1.0.0' ) || ! str_contains( $bootstrap, "define( 'CB_BACKUPS_VERSION', '1.0.0' );" ) ) {
	$fail( 'Plugin and runtime version declarations must both use 1.0.0.' );
}
if ( str_contains( $bootstrap, 'Update URI:' ) ) {
	$fail( 'WordPress.org builds must not declare an external Update URI.' );
}
if ( str_contains( $bootstrap, 'Integration\\Updates::init();' ) ) {
	$fail( 'WordPress.org builds must not attach the external Core Blueprint Updates adapter.' );
}
if ( is_file( $root . '/src/Integration/Updates.php' ) ) {
	$fail( 'External Updates adapter must not ship in the WordPress.org source.' );
}
if ( ! str_contains( $readme, 'Stable tag: 1.0.0' ) ) {
	$fail( 'WordPress.org readme Stable tag must match 1.0.0.' );
}

echo "Core Blueprint Backups WordPress.org distribution regression PASS\n";
