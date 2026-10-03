<?php
declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	$GLOBALS['cb_test_user_id'] = 13;
	$GLOBALS['cb_test_transients'] = [];
	$GLOBALS['cb_test_rewrite_flushes'] = 0;

	function add_action( string $hook, callable $callback, int $priority = 10, int $accepted_args = 1 ): void {}
	function wp_generate_uuid4(): string { return '11111111-2222-4333-8444-555555555555'; }
	function home_url( string $path = '' ): string { return 'https://destination.test' . $path; }
	function get_current_user_id(): int { return (int) $GLOBALS['cb_test_user_id']; }
	function get_transient( string $key ): mixed { return $GLOBALS['cb_test_transients'][ $key ] ?? false; }
	function delete_transient( string $key ): bool { unset( $GLOBALS['cb_test_transients'][ $key ] ); return true; }
	function flush_rewrite_rules( bool $hard = true ): void { if ( $hard ) ++$GLOBALS['cb_test_rewrite_flushes']; }
	function __( string $text, string $domain = 'default' ): string { return $text; }

	function migration_recovery_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}
}

namespace CoreBlueprint\Core\Migration {
	final class Recovery {
		public static bool $pretty = true;
		public static array $calls = [];
		public static array $status = [ 'status' => 'authenticated', 'approved_user_id' => 13 ];

		public static function api_version(): string { return '1.0'; }
		public static function issue_ticket( string $id, string $target ): array {
			self::$calls[] = [ 'issue', $id, $target ];
			return [
				'ticket' => 'signed-ticket',
				'recovery_id' => $id,
				'issued_at' => 100,
				'expires_at' => 200,
				'target_site_url' => $target,
			];
		}
		public static function activate_destination( string $ticket ): array {
			self::$calls[] = [ 'activate', $ticket ];
			return [ 'status' => 'pending_reconcile' ];
		}
		public static function reconcile_destination( string $ticket ): array {
			self::$calls[] = [ 'reconcile', $ticket ];
			return [ 'status' => 'pending_auth', 'reviewed_users' => 2 ];
		}
		public static function login_url( string $ticket, string $redirect ): string {
			return 'https://destination.test/wp-login.php?ticket=' . rawurlencode( $ticket ) . '&redirect=' . rawurlencode( $redirect );
		}
		public static function requires_pretty_routing(): bool { return self::$pretty; }
		public static function status( string $ticket ): array { self::$calls[] = [ 'status', $ticket ]; return self::$status; }
		public static function finalize( string $ticket ): bool { self::$calls[] = [ 'finalize', $ticket ]; return true; }
	}
}

namespace CB\Backups\Jobs {
	final class Repository {
		public static array $job = [];
		public static function get( string $job_id ): ?array {
			return ( self::$job['job_id'] ?? '' ) === $job_id ? self::$job : null;
		}
		public static function update( string $job_id, array $fields ): void {
			if ( ( self::$job['job_id'] ?? '' ) !== $job_id ) return;
			foreach ( $fields as $key => $value ) self::$job[ $key ] = $value;
		}
		public static function complete( string $job_id, string $archive_name = '' ): void {
			if ( ( self::$job['job_id'] ?? '' ) !== $job_id ) return;
			self::$job['status'] = 'completed';
			self::$job['stage'] = 'completed';
			self::$job['progress'] = 100;
		}
		public static function active( int $limit = 10 ): array { return self::$job ? [ self::$job ] : []; }
	}
}

namespace CB\Backups\Support {
	final class Audit {
		public static array $events = [];
		public static function log( string $event, string $severity = 'notice', array $context = [] ): void {
			self::$events[] = [ $event, $severity, $context ];
		}
	}
	final class Capabilities {
		public const MANAGE = 'cb_manage_backups';
	}
}

namespace CB\Backups\Admin {
	final class Actions {
		public static function public_job( array $job ): array { return $job; }
	}
	final class JobPresentation {
		public static function present( array $job, array $public ): array { return $public; }
	}
}

namespace {
	require dirname( __DIR__ ) . '/src/Integration/MigrationRecovery.php';

	use CB\Backups\Integration\MigrationRecovery;
	use CB\Backups\Jobs\Repository;
	use CoreBlueprint\Core\Migration\Recovery as BaseRecovery;

	migration_recovery_assert( [] === MigrationRecovery::prepare( [ 'mode' => 'restore' ] ), 'Same-site restore must not prepare migration recovery.' );

	$prepared = MigrationRecovery::prepare( [
		'mode' => 'migration',
		'target_site_url' => 'https://destination.test',
	] );
	migration_recovery_assert( 'signed-ticket' === ( $prepared['ticket'] ?? '' ), 'Migration must obtain its ticket from Base.' );
	migration_recovery_assert( str_starts_with( (string) ( $prepared['recovery_id'] ?? '' ), 'cb-backups-' ), 'Backups must assign a scoped recovery ID.' );

	$meta = [
		'restore_mode' => 'migration',
		'migration_recovery' => $prepared,
		'duration_seconds' => 5,
	];
	migration_recovery_assert( MigrationRecovery::required( $meta ), 'Prepared migration must require destination recovery.' );

	$meta = MigrationRecovery::activate_destination( $meta );
	migration_recovery_assert( 'pending_reconcile' === $meta['migration_recovery']['status'], 'Live destination activation must remain pending reconciliation.' );

	$meta = MigrationRecovery::reconcile_destination( $meta );
	migration_recovery_assert( 'pending_auth' === $meta['migration_recovery']['status'], 'Fresh runtime reconciliation must require authentication next.' );
	migration_recovery_assert( 2 === $meta['migration_recovery']['reviewed_users'], 'Backups must retain Base review count as evidence.' );

	migration_recovery_assert( MigrationRecovery::requires_probe( $meta ), 'Pretty-routing destination must require browser rewrite proof.' );
	migration_recovery_assert( str_contains( MigrationRecovery::probe_url( $meta ), '/cb-backups-migration-probe-' ), 'Pretty-routing recovery must expose a unique browser probe URL.' );

	Repository::$job = [
		'job_id' => 'restore-1',
		'kind' => 'restore',
		'backup_type' => 'website',
		'trigger_source' => 'manual_import',
		'status' => 'running',
		'stage' => 'await_recovery',
		'progress' => 99,
		'meta' => $meta,
	];

	$prepared_job = MigrationRecovery::prepare_destination( 'restore-1' );
	migration_recovery_assert( 1 === $GLOBALS['cb_test_rewrite_flushes'], 'Pretty-routing recovery must flush destination rewrites in the explicit preparation request.' );
	migration_recovery_assert( ! empty( $prepared_job['meta']['migration_recovery']['rewrite_flushed_at'] ), 'Preparation must persist rewrite evidence before the browser probe.' );

	try {
		MigrationRecovery::finalize( 'restore-1' );
		throw new \RuntimeException( 'Finalize unexpectedly accepted missing browser rewrite proof.' );
	} catch ( \RuntimeException $e ) {
		migration_recovery_assert(
			str_contains( $e->getMessage(), 'rewrite verification' ),
			'Pretty-routing migration must fail closed before browser proof.'
		);
	}

	$key = 'cb_backups_migration_probe_' . substr( hash( 'sha256', (string) $prepared['recovery_id'] ), 0, 24 );
	$GLOBALS['cb_test_transients'][ $key ] = 13;
	$completed = MigrationRecovery::finalize( 'restore-1' );
	migration_recovery_assert( 'completed' === $completed['status'], 'Verified recovery must complete the Backups job.' );
	migration_recovery_assert(
		in_array( [ 'finalize', 'signed-ticket' ], BaseRecovery::$calls, true ),
		'Backups must finalize destination trust through Base.'
	);

	BaseRecovery::$pretty = false;
	$plain = [
		'restore_mode' => 'migration',
		'migration_recovery' => $prepared,
	];
	migration_recovery_assert( ! MigrationRecovery::requires_probe( $plain ), 'Plain destination must not require pretty-path verification.' );
	migration_recovery_assert( '' === MigrationRecovery::probe_url( $plain ), 'Plain destination must not expose a meaningless pretty-path probe.' );

	echo "Migration recovery integration regression: PASS\n";
}
