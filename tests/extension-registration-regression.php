<?php
declare(strict_types=1);

namespace {
	defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
	defined( 'CB_BACKUPS_BASENAME' ) || define( 'CB_BACKUPS_BASENAME', 'core-blueprint-backups/core-blueprint-backups.php' );
	defined( 'CB_BACKUPS_REQUIRED_API' ) || define( 'CB_BACKUPS_REQUIRED_API', '1.0' );

	/** @var array<string,array<int,array{callback:mixed,priority:int}>> */
	$GLOBALS['cb_backups_test_actions'] = [];
	/** @var array<string,array<int,array{callback:mixed,priority:int}>> */
	$GLOBALS['cb_backups_test_filters'] = [];

	function add_action( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['cb_backups_test_actions'][ $hook ][] = [ 'callback' => $callback, 'priority' => $priority ];
		return true;
	}

	function add_filter( string $hook, mixed $callback, int $priority = 10, int $accepted_args = 1 ): bool {
		$GLOBALS['cb_backups_test_filters'][ $hook ][] = [ 'callback' => $callback, 'priority' => $priority ];
		return true;
	}

	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}

	function __( string $text, string $domain = 'default' ): string {
		return $text;
	}

	if ( ! class_exists( 'ZipArchive' ) ) {
		class ZipArchive {}
	}
}

namespace CB\Core {
	final class ExtensionRegistry {
		/** @var array<string,mixed>|null */
		public static ?array $registered = null;

		/** @param array<string,mixed> $definition */
		public static function register( array $definition ): bool {
			self::$registered = $definition;
			return true;
		}
	}
}

namespace CB\Backups\Storage {
	final class LocalStorage {
		public static ?string $path = null;

		public static function health_path(): ?string {
			return self::$path ?? sys_get_temp_dir();
		}
	}
}

namespace CB\Backups\Schedule {
	final class Scheduler {
		public static string $status = 'idle';

		/** @return array<string,mixed> */
		public static function health(): array {
			return [ 'status' => self::$status ];
		}
	}
}

namespace CB\Backups {
	use CB\Backups\Schedule\Scheduler;
	use CB\Backups\Storage\LocalStorage;
	use CB\Core\ExtensionRegistry;

	require_once __DIR__ . '/../src/Bootstrap.php';

	function expect( bool $condition, string $message ): void {
		if ( $condition ) {
			return;
		}
		fwrite( STDERR, "FAIL: {$message}\n" );
		exit( 1 );
	}

	Bootstrap::register_suite_integration();
	Bootstrap::register_suite_integration();

	$extension_hooks = $GLOBALS['cb_backups_test_actions']['cb_core_register_extensions'] ?? [];
	$init_hooks      = $GLOBALS['cb_backups_test_actions']['init'] ?? [];
	expect( 1 === count( $extension_hooks ), 'Suite integration must attach ExtensionRegistry registration exactly once.' );
	expect( [ Bootstrap::class, 'register_extension' ] === $extension_hooks[0]['callback'], 'Suite integration must attach the canonical extension registration callback.' );
	expect( 1 === count( $init_hooks ), 'Suite integration must attach presentation/status registration exactly once.' );
	expect( [ Bootstrap::class, 'register_presentation_hooks' ] === $init_hooks[0]['callback'], 'Suite integration must attach the presentation/status callback.' );
	expect( 1 === $init_hooks[0]['priority'], 'Presentation/status registration must run at the existing init priority.' );

	Bootstrap::register_extension();
	$definition = ExtensionRegistry::$registered;
	expect( is_array( $definition ), 'Backups must register with the canonical ExtensionRegistry.' );
	expect( 'core-blueprint-backups' === ( $definition['id'] ?? '' ), 'Extension ID must match the canonical plugin folder.' );
	expect( CB_BACKUPS_BASENAME === ( $definition['plugin_file'] ?? '' ), 'Extension registration must use the canonical plugin basename.' );
	expect( '1.0' === ( $definition['requires_api'] ?? '' ), 'Extension registration must target Core API 1.0.' );
	expect( 'backups' === ( $definition['status_id'] ?? '' ), 'Extension registration must expose the Backups health provider.' );

	$status_definitions = Bootstrap::register_status_definition( [] );
	expect( isset( $status_definitions['backups'] ), 'Backups status definition must be registered.' );
	expect( [ Bootstrap::class, 'extension_status' ] === $status_definitions['backups']['provider'], 'Dashboard status must use the Backups health provider.' );

	Scheduler::$status = 'idle';
	$status = Bootstrap::extension_status();
	expect( 'ok' === $status['state'] && 'Ready' === $status['detail'], 'Manual/idle Backups runtime should be healthy and ready.' );

	Scheduler::$status = 'warning';
	$status = Bootstrap::extension_status();
	expect( 'warn' === $status['state'], 'Delayed scheduler must surface a dashboard warning.' );

	Scheduler::$status = 'critical';
	$status = Bootstrap::extension_status();
	expect( 'err' === $status['state'], 'Broken scheduler must surface a dashboard error.' );

	Scheduler::$status = 'unexpected';
	$status = Bootstrap::extension_status();
	expect( 'warn' === $status['state'] && 'Backup scheduler requires attention' === $status['detail'], 'Unknown scheduler state must degrade to an intentional Backups warning.' );

	LocalStorage::$path = '/definitely/not/a/real/backups/path';
	$status = Bootstrap::extension_status();
	expect( 'err' === $status['state'] && 'Backup storage unavailable' === $status['detail'], 'Unavailable storage must fail the dashboard health check without creating storage.' );

	echo "Extension registration and dashboard health: PASS\n";
}
