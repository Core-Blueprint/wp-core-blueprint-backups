<?php
declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['options'] = [];
	$GLOBALS['transients'] = [];
	$GLOBALS['logged_in'] = false;
	$GLOBALS['can_manage'] = false;
	$GLOBALS['flushes'] = [];

	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {}
	function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['options'][ $key ] ?? $default; }
	function update_option( string $key, mixed $value, bool $autoload = true ): bool { $GLOBALS['options'][ $key ] = $value; return true; }
	function delete_option( string $key ): bool { unset( $GLOBALS['options'][ $key ] ); return true; }
	function get_transient( string $key ): mixed { return $GLOBALS['transients'][ $key ]['value'] ?? false; }
	function set_transient( string $key, mixed $value, int $expiration = 0 ): bool {
		$GLOBALS['transients'][ $key ] = [ 'value' => $value, 'expiration' => $expiration ];
		return true;
	}
	function is_user_logged_in(): bool { return (bool) $GLOBALS['logged_in']; }
	function current_user_can( string $capability ): bool { return 'manage_options' === $capability && (bool) $GLOBALS['can_manage']; }
	function flush_rewrite_rules( bool $hard = true ): void { $GLOBALS['flushes'][] = $hard; }
}

namespace CB\Core\Security {
	final class Failsafe {
		public const BYPASS_TRANSIENT = 'cb_core_bypass_window';
		public const BYPASS_WINDOW = 3600;
	}
}

namespace CB\Backups\Support {
	final class Audit {
		public static array $events = [];
		public static function log( string $event, string $severity = 'notice', array $context = [] ): void {
			self::$events[] = [ $event, $severity, $context ];
		}
	}
}

namespace {
	require dirname( __DIR__ ) . '/src/Restore/MigrationAccessRecovery.php';

	use CB\Backups\Restore\MigrationAccessRecovery;
	use CB\Core\Security\Failsafe;
	use CB\Backups\Support\Audit;

	function access_recovery_assert( bool $condition, string $message ): void {
		if ( ! $condition ) throw new \RuntimeException( $message );
	}

	$same_site = MigrationAccessRecovery::arm( [ 'restore_mode' => 'restore' ], 'restore-1' );
	access_recovery_assert( ! isset( $same_site['migration_access_recovery'] ), 'Same-site restore must not arm migration access recovery.' );

	$meta = MigrationAccessRecovery::arm( [ 'restore_mode' => 'migration' ], 'migration-1' );
	access_recovery_assert( 'active' === get_transient( Failsafe::BYPASS_TRANSIENT ), 'Migration must open the Base failsafe window.' );
	access_recovery_assert( Failsafe::BYPASS_WINDOW === $GLOBALS['transients'][ Failsafe::BYPASS_TRANSIENT ]['expiration'], 'Failsafe window must use the canonical Base duration.' );
	access_recovery_assert( 'migration-1' === (string) ( $GLOBALS['options']['cb_backups_migration_access_recovery']['job_id'] ?? '' ), 'Migration recovery state must retain the owning job.' );
	access_recovery_assert( 'migration-1' === (string) ( $meta['migration_access_recovery']['job_id'] ?? '' ), 'Job metadata must record access recovery.' );

	MigrationAccessRecovery::refresh_destination_rewrites();
	access_recovery_assert( [] === $GLOBALS['flushes'], 'Destination rewrites must not refresh before an administrator signs in.' );

	$GLOBALS['logged_in'] = true;
	$GLOBALS['can_manage'] = true;
	MigrationAccessRecovery::refresh_destination_rewrites();
	access_recovery_assert( [ true ] === $GLOBALS['flushes'], 'First authenticated admin request must hard-refresh destination rewrite rules.' );
	access_recovery_assert( ! isset( $GLOBALS['options']['cb_backups_migration_access_recovery'] ), 'Recovery marker must clear after rewrite refresh.' );
	access_recovery_assert( 'active' === get_transient( Failsafe::BYPASS_TRANSIENT ), 'Bounded Base failsafe window must remain available after rewrite refresh.' );
	access_recovery_assert(
		in_array( 'backups.migration.destination_rewrites_refreshed', array_column( Audit::$events, 0 ), true ),
		'Rewrite recovery must be audit logged.'
	);

	echo "Migration access recovery regression: PASS\n";
}
