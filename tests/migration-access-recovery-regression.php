<?php
declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/' );

	$GLOBALS['options'] = [];
	$GLOBALS['logged_in'] = false;
	$GLOBALS['can_manage'] = false;
	$GLOBALS['flushes'] = [];
	$GLOBALS['probe_code'] = 204;
	$_SERVER['SCRIPT_NAME'] = '/index.php';
	$_SERVER['REQUEST_URI'] = '/';

	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {}
	function add_filter( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {}
	function get_option( string $key, mixed $default = false ): mixed { return $GLOBALS['options'][ $key ] ?? $default; }
	function update_option( string $key, mixed $value, bool $autoload = true ): bool { $GLOBALS['options'][ $key ] = $value; return true; }
	function delete_option( string $key ): bool { unset( $GLOBALS['options'][ $key ] ); return true; }
	function is_user_logged_in(): bool { return (bool) $GLOBALS['logged_in']; }
	function current_user_can( string $capability ): bool { return 'manage_options' === $capability && (bool) $GLOBALS['can_manage']; }
	function flush_rewrite_rules( bool $hard = true ): void { $GLOBALS['flushes'][] = $hard; }
	function wp_unslash( string $value ): string { return $value; }
	function wp_parse_url( string $url, int $component = -1 ): mixed { return parse_url( $url, $component ); }
	function home_url( string $path = '' ): string { return 'https://destination.test' . $path; }
	function wp_safe_remote_get( string $url, array $args = [] ): array { return [ 'response' => [ 'code' => (int) $GLOBALS['probe_code'] ], 'url' => $url ]; }
	function is_wp_error( mixed $value ): bool { return false; }
	function wp_remote_retrieve_response_code( array $response ): int { return (int) ( $response['response']['code'] ?? 0 ); }
}

namespace CB\Core\Security {
	final class Failsafe {
		public const BYPASS_TRANSIENT = 'cb_core_bypass_window';
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
	access_recovery_assert( 'migration-1' === (string) ( $GLOBALS['options']['cb_backups_migration_access_recovery']['job_id'] ?? '' ), 'Migration recovery state must retain the owning job.' );
	access_recovery_assert( '' !== (string) ( $GLOBALS['options']['cb_backups_migration_access_recovery']['probe_token'] ?? '' ), 'Migration recovery state must contain a private rewrite probe token.' );
	access_recovery_assert( 'migration-1' === (string) ( $meta['migration_access_recovery']['job_id'] ?? '' ), 'Job metadata must record access recovery.' );

	$_SERVER['SCRIPT_NAME'] = '/index.php';
	$_SERVER['REQUEST_URI'] = '/shop/';
	access_recovery_assert(
		false === MigrationAccessRecovery::filter_recovery_bypass( false, Failsafe::BYPASS_TRANSIENT ),
		'Migration recovery must not globally bypass Base safeguards on frontend requests.'
	);

	$_SERVER['SCRIPT_NAME'] = '/wp-login.php';
	$_SERVER['REQUEST_URI'] = '/wp-login.php';
	access_recovery_assert(
		'active' === MigrationAccessRecovery::filter_recovery_bypass( false, Failsafe::BYPASS_TRANSIENT ),
		'Canonical wp-login.php must remain reachable while migration access recovery is armed.'
	);

	$GLOBALS['probe_code'] = 404;
	$GLOBALS['logged_in'] = true;
	$GLOBALS['can_manage'] = true;
	$_SERVER['SCRIPT_NAME'] = '/wp-admin/admin.php';
	$_SERVER['REQUEST_URI'] = '/wp-admin/admin.php';
	MigrationAccessRecovery::refresh_destination_rewrites();
	access_recovery_assert( [ true ] === $GLOBALS['flushes'], 'First authenticated admin request must hard-refresh destination rewrite rules.' );
	access_recovery_assert( isset( $GLOBALS['options']['cb_backups_migration_access_recovery'] ), 'Recovery marker must remain when the destination pretty-path probe does not reach WordPress.' );

	$state = $GLOBALS['options']['cb_backups_migration_access_recovery'];
	$state['last_attempt_at'] = 0;
	$GLOBALS['options']['cb_backups_migration_access_recovery'] = $state;
	$GLOBALS['probe_code'] = 204;
	MigrationAccessRecovery::refresh_destination_rewrites();
	access_recovery_assert( [ true, true ] === $GLOBALS['flushes'], 'Rewrite refresh must retry after an unverified destination attempt.' );
	access_recovery_assert( ! isset( $GLOBALS['options']['cb_backups_migration_access_recovery'] ), 'Recovery marker must clear only after the pretty-path probe reaches WordPress.' );
	access_recovery_assert(
		in_array( 'backups.migration.destination_rewrites_refreshed', array_column( Audit::$events, 0 ), true ),
		'Verified destination rewrite recovery must be audit logged.'
	);

	echo "Migration access recovery regression: PASS\n";
}
