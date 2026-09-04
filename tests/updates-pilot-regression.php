<?php
declare(strict_types=1);

namespace CB\Updates {
	final class ProductRegistry {
		/** @var array<int,array<string,mixed>> */
		public static array $registered = [];
		public static function register( array $descriptor ): true {
			self::$registered[] = $descriptor;
			return true;
		}
	}
}

namespace {
	define( 'ABSPATH', __DIR__ );
	define( 'CB_BACKUPS_BASENAME', 'core-blueprint-backups/core-blueprint-backups.php' );
	define( 'CB_BACKUPS_VERSION', '1.0.0-rc2' );

	$GLOBALS['cb_backups_pilot_actions'] = [];
	function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		unset( $accepted_args );
		$GLOBALS['cb_backups_pilot_actions'][ $hook ][ $priority ][] = $callback;
		return true;
	}

	require_once dirname( __DIR__ ) . '/src/Integration/Updates.php';

	$fail = static function ( string $message ): never {
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	};

	\CB\Backups\Integration\Updates::init();
	$hook = $GLOBALS['cb_backups_pilot_actions']['cb_updates_register_products'][10][0] ?? null;
	if ( ! is_callable( $hook ) ) {
		$fail( 'Backups must register lazily through the central Updates product hook.' );
	}

	$hook();
	$descriptor = \CB\Updates\ProductRegistry::$registered[0] ?? null;
	if ( ! is_array( $descriptor ) ) {
		$fail( 'Backups must publish one Updates product descriptor when the Updates registry is available.' );
	}
	if ( 'Core Blueprint Backups' !== ( $descriptor['name'] ?? null ) ) {
		$fail( 'Backups update descriptor name drifted.' );
	}
	if ( CB_BACKUPS_BASENAME !== ( $descriptor['plugin'] ?? null ) ) {
		$fail( 'Backups update descriptor must use the real plugin basename.' );
	}
	if ( CB_BACKUPS_VERSION !== ( $descriptor['version'] ?? null ) ) {
		$fail( 'Backups update descriptor must expose the installed runtime version.' );
	}
	if ( 'core-blueprint-backups' !== ( $descriptor['product_key'] ?? null ) ) {
		$fail( 'Backups must use the canonical pilot License Product key.' );
	}
	if ( '' !== ( $descriptor['software_uuid'] ?? null ) ) {
		$fail( 'The Marketplace software UUID must not be invented or hard-coded into Backups.' );
	}

	$source = file_get_contents( dirname( __DIR__ ) . '/src/Integration/Updates.php' );
	if ( false === $source ) {
		$fail( 'Updates adapter source must be readable.' );
	}
	foreach ( [ 'wp_remote_', 'license_key', 'activation_token', 'LicenseManager', 'Marketplace', 'Repository\\' ] as $forbidden ) {
		if ( str_contains( $source, $forbidden ) ) {
			$fail( 'Backups pilot adapter must remain a thin registration-only integration: ' . $forbidden );
		}
	}

	echo "Core Blueprint Backups PILOT-1 Updates registration regression PASS\n";
}
