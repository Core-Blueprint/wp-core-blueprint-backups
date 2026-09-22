<?php
declare(strict_types=1);

namespace CB\Backups\Restore;

use CB\Backups\Support\Audit;
use CB\Core\Security\Failsafe;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps cross-site migrations recoverable when imported security settings
 * depend on destination rewrite infrastructure that may not exist yet.
 */
final class MigrationAccessRecovery {
	private const OPTION = 'cb_backups_migration_access_recovery';

	public static function boot(): void {
		add_action( 'admin_init', [ self::class, 'refresh_destination_rewrites' ], 1 );
	}

	/**
	 * Open Base's bounded failsafe window after the migrated database becomes
	 * live. This keeps the canonical wp-login.php route available while the
	 * destination establishes fresh rewrite rules on its first admin request.
	 *
	 * @param array<string,mixed> $meta
	 * @return array<string,mixed>
	 */
	public static function arm( array $meta, string $job_id ): array {
		if ( 'migration' !== (string) ( $meta['restore_mode'] ?? '' ) ) {
			return $meta;
		}
		if ( ! class_exists( Failsafe::class ) ) {
			return $meta;
		}

		$existing = get_option( self::OPTION, [] );
		if ( is_array( $existing ) && $job_id === (string) ( $existing['job_id'] ?? '' ) ) {
			$meta['migration_access_recovery'] = $existing;
			return $meta;
		}

		set_transient( Failsafe::BYPASS_TRANSIENT, 'active', Failsafe::BYPASS_WINDOW );

		$state = [
			'job_id'     => $job_id,
			'armed_at'   => time(),
			'expires_at' => time() + Failsafe::BYPASS_WINDOW,
		];
		update_option( self::OPTION, $state, false );
		$meta['migration_access_recovery'] = $state;

		Audit::log(
			'backups.migration.access_recovery.armed',
			'warning',
			[
				'job_id'         => $job_id,
				'window_seconds' => Failsafe::BYPASS_WINDOW,
			]
		);

		return $meta;
	}

	/**
	 * A successful admin login proves that the destination can bootstrap under
	 * the restored database. Refresh rewrites now, with the fresh destination
	 * runtime fully loaded. The Base failsafe window remains bounded by its own
	 * expiry so an operator cannot be locked out again during final checks.
	 */
	public static function refresh_destination_rewrites(): void {
		$state = get_option( self::OPTION, [] );
		if ( ! is_array( $state ) || '' === (string) ( $state['job_id'] ?? '' ) ) {
			return;
		}
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}
		if ( ! function_exists( 'flush_rewrite_rules' ) ) {
			return;
		}

		flush_rewrite_rules( true );
		delete_option( self::OPTION );

		Audit::log(
			'backups.migration.destination_rewrites_refreshed',
			'notice',
			[
				'job_id' => (string) $state['job_id'],
			]
		);
	}
}
