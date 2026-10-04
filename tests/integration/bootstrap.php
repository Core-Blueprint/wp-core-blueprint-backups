<?php
declare(strict_types=1);

// Only a dedicated CI database is permitted. Never load an existing site's wp-config.php.
if ( '1' !== getenv( 'CB_BACKUPS_TEST_DISPOSABLE' ) || 'cb_backups_test' !== getenv( 'CB_TEST_DB_NAME' ) ) {
	throw new RuntimeException( 'An explicitly disposable cb_backups_test database is required.' );
}
$root = rtrim( (string) getenv( 'CB_TEST_WP_ROOT' ), '/' ) . '/';
if ( ! is_file( $root . 'wp-settings.php' ) ) throw new RuntimeException( 'Real WordPress source is required.' );
define( 'ABSPATH', $root );
define( 'WP_INSTALLING', true );
define( 'WP_DEBUG', true );
define( 'WP_DEBUG_DISPLAY', false );
define( 'DISABLE_WP_CRON', true );
define( 'DB_NAME', 'cb_backups_test' );
define( 'DB_USER', (string) getenv( 'CB_TEST_DB_USER' ) );
define( 'DB_PASSWORD', (string) getenv( 'CB_TEST_DB_PASSWORD' ) );
define( 'DB_HOST', (string) getenv( 'CB_TEST_DB_HOST' ) );
define( 'DB_CHARSET', 'utf8mb4' );
define( 'DB_COLLATE', '' );
define( 'WP_CONTENT_DIR', $root . 'wp-content' );
$_SERVER['HTTP_HOST'] = 'source.example.test';
$_SERVER['REQUEST_URI'] = '/';
$_SERVER['SERVER_PROTOCOL'] = 'HTTP/1.1';
$table_prefix = 'cbtest_';
require ABSPATH . 'wp-settings.php';
require_once ABSPATH . 'wp-admin/includes/upgrade.php';
if ( ! is_blog_installed() ) wp_install( 'Disposable fidelity test', 'test-admin', 'nobody@example.test', false, '', 'disposable-test-only-password' );
update_option( 'home', 'https://source.example.test' );
update_option( 'siteurl', 'https://source.example.test' );

$base_root = rtrim( (string) getenv( 'CB_TEST_BASE_ROOT' ), '/\\' );
if ( '' === $base_root || ! is_file( $base_root . '/core-blueprint.php' ) ) {
	throw new RuntimeException( 'CB_TEST_BASE_ROOT must point to the canonical Core Blueprint Base checkout.' );
}
require_once $base_root . '/core-blueprint.php';

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'CB\\Backups\\';
	if ( str_starts_with( $class, $prefix ) ) {
		$file = dirname( __DIR__, 2 ) . '/src/' . str_replace( '\\', '/', substr( $class, strlen( $prefix ) ) ) . '.php';
		if ( is_file( $file ) ) require_once $file;
	}
} );

function check( bool $condition, string $message ): void {
	if ( ! $condition ) throw new RuntimeException( $message );
}
function sql( string $query ): void {
	global $wpdb;
	if ( false === $wpdb->query( $query ) ) throw new RuntimeException( $wpdb->last_error );
}
function private_call( string $class, string $method, mixed ...$args ): mixed {
	return ( new ReflectionMethod( $class, $method ) )->invokeArgs( null, $args );
}
function run_ticks( callable $tick, array $meta = [] ): array {
	for ( $i = 0; $i < 10000; ++$i ) {
		$result = $tick( $meta );
		// Worker metadata must survive a JSON/database checkpoint, not just PHP memory.
		$meta = json_decode( json_encode( $result['meta'], JSON_THROW_ON_ERROR ), true, 512, JSON_THROW_ON_ERROR );
		if ( $result['done'] ) return $meta;
	}
	throw new RuntimeException( 'Tick limit exceeded.' );
}
