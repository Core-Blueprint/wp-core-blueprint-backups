<?php
declare(strict_types=1);

namespace {
	defined( 'ABSPATH' ) || define( 'ABSPATH', __DIR__ . '/' );
	defined( 'CB_BACKUPS_BASENAME' ) || define( 'CB_BACKUPS_BASENAME', 'core-blueprint-backups/core-blueprint-backups.php' );

	function admin_url( string $path = '' ): string {
		return 'https://example.test/wp-admin/' . ltrim( $path, '/' );
	}

	function __( string $text, string $domain = 'default' ): string {
		return $text;
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
	use RuntimeException;

	final class LocalStorage {
		public static bool $fail = false;

		public static function ensure(): void {
			if ( self::$fail ) {
				throw new RuntimeException( 'storage unavailable' );
			}
		}

		public static function base_path(): string {
			return sys_get_temp_dir();
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

	LocalStorage::$fail = true;
	$status = Bootstrap::extension_status();
	expect( 'err' === $status['state'] && 'Backup storage unavailable' === $status['detail'], 'Unavailable storage must fail the dashboard health check.' );

	echo "Extension registration and dashboard health: PASS\n";
}
