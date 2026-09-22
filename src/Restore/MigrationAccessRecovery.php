<?php
declare(strict_types=1);

namespace CB\Backups\Restore;

use CB\Backups\Support\Audit;
use CB\Core\Security\Failsafe;

defined( 'ABSPATH' ) || exit;

/**
 * Keeps cross-site migrations recoverable while the destination establishes
 * its own rewrite infrastructure.
 */
final class MigrationAccessRecovery {
	private const OPTION = 'cb_backups_migration_access_recovery';
	private const PROBE_PREFIX = 'cb-backups-migration-probe-';
	private const PROBE_RETRY_SECONDS = 30;

	public static function boot(): void {
		if ( class_exists( Failsafe::class ) ) {
			add_filter(
				'pre_transient_' . Failsafe::BYPASS_TRANSIENT,
				[ self::class, 'filter_recovery_bypass' ],
				1,
				2
			);
		}
		add_action( 'init', [ self::class, 'serve_rewrite_probe' ], -1000 );
		add_action( 'admin_init', [ self::class, 'refresh_destination_rewrites' ], 1 );
	}

	/**
	 * Persist a destination-owned recovery marker after the migrated database
	 * becomes live. Login Shield configuration itself is never mutated.
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

		$state = [
			'job_id'          => $job_id,
			'armed_at'        => time(),
			'probe_token'     => bin2hex( random_bytes( 16 ) ),
			'last_attempt_at' => 0,
			'attempts'        => 0,
		];
		update_option( self::OPTION, $state, false );
		$meta['migration_access_recovery'] = $state;

		Audit::log(
			'backups.migration.access_recovery.armed',
			'warning',
			[
				'job_id' => $job_id,
			]
		);

		return $meta;
	}

	/**
	 * Base Failsafe remains the authority. While the migration marker exists,
	 * report its transient as active only for canonical WordPress recovery
	 * routes. Frontend requests and the migrated custom login alias are not
	 * globally bypassed.
	 */
	public static function filter_recovery_bypass( mixed $pre, string $transient = '' ): mixed {
		if ( false !== $pre || Failsafe::BYPASS_TRANSIENT !== $transient ) {
			return $pre;
		}

		$state = get_option( self::OPTION, [] );
		if ( ! is_array( $state ) || '' === (string) ( $state['job_id'] ?? '' ) ) {
			return $pre;
		}

		return self::is_recovery_request() ? 'active' : $pre;
	}

	/**
	 * First authenticated admin request after migration refreshes rewrites with
	 * the fresh destination runtime. The recovery marker is only removed after
	 * a loopback request proves an arbitrary pretty path reaches WordPress.
	 */
	public static function refresh_destination_rewrites(): void {
		$state = get_option( self::OPTION, [] );
		if ( ! is_array( $state ) || '' === (string) ( $state['job_id'] ?? '' ) ) {
			return;
		}
		if ( ! is_user_logged_in() || ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$now = time();
		$last_attempt = max( 0, (int) ( $state['last_attempt_at'] ?? 0 ) );
		if ( $last_attempt > 0 && $now - $last_attempt < self::PROBE_RETRY_SECONDS ) {
			return;
		}

		$state['last_attempt_at'] = $now;
		$state['attempts'] = max( 0, (int) ( $state['attempts'] ?? 0 ) ) + 1;
		update_option( self::OPTION, $state, false );

		if ( function_exists( 'flush_rewrite_rules' ) ) {
			flush_rewrite_rules( true );
		}

		if ( ! self::destination_rewrites_reachable( $state ) ) {
			return;
		}

		delete_option( self::OPTION );

		Audit::log(
			'backups.migration.destination_rewrites_refreshed',
			'notice',
			[
				'job_id'   => (string) $state['job_id'],
				'attempts' => (int) $state['attempts'],
			]
		);
	}

	/**
	 * Private loopback endpoint used only while a migration recovery marker is
	 * present. Reaching this handler proves the web server routed a pretty path
	 * into the destination WordPress bootstrap.
	 */
	public static function serve_rewrite_probe(): void {
		$state = get_option( self::OPTION, [] );
		$token = is_array( $state ) ? (string) ( $state['probe_token'] ?? '' ) : '';
		if ( '' === $token ) {
			return;
		}

		$expected = '/' . self::PROBE_PREFIX . $token . '/';
		if ( self::request_path() !== $expected ) {
			return;
		}

		status_header( 204 );
		nocache_headers();
		header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
		exit;
	}

	/** @param array<string,mixed> $state */
	private static function destination_rewrites_reachable( array $state ): bool {
		$token = (string) ( $state['probe_token'] ?? '' );
		if ( '' === $token ) {
			return false;
		}

		$url = home_url( '/' . self::PROBE_PREFIX . $token . '/' );
		$response = wp_safe_remote_get(
			$url,
			[
				'timeout'     => 5,
				'redirection' => 0,
				'cookies'     => [],
				'headers'     => [ 'Cache-Control' => 'no-cache' ],
			]
		);
		if ( is_wp_error( $response ) ) {
			return false;
		}

		return 204 === (int) wp_remote_retrieve_response_code( $response );
	}

	private static function is_recovery_request(): bool {
		$script = isset( $_SERVER['SCRIPT_NAME'] ) ? basename( (string) wp_unslash( $_SERVER['SCRIPT_NAME'] ) ) : '';
		if ( 'wp-login.php' === $script ) {
			return true;
		}

		$path = self::request_path();
		return '/wp-admin' === $path || str_starts_with( $path, '/wp-admin/' );
	}

	private static function request_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		$path = is_string( $path ) ? $path : '';

		$home_path = wp_parse_url( home_url( '/' ), PHP_URL_PATH );
		$home_path = is_string( $home_path ) ? rtrim( $home_path, '/' ) : '';
		if ( '' !== $home_path && str_starts_with( $path, $home_path ) ) {
			$path = substr( $path, strlen( $home_path ) );
		}

		return '/' . ltrim( $path, '/' );
	}
}
