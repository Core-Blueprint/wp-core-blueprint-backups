<?php
declare(strict_types=1);

namespace CB\Backups\Integration;

use CB\Backups\Admin\Actions;
use CB\Backups\Admin\JobPresentation;
use CB\Backups\Jobs\Repository;
use CB\Backups\Support\Audit;
use CB\Backups\Support\Capabilities;
use CB\Core\Migration\Recovery as BaseRecovery;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

/**
 * Orchestrates Backups migration jobs against Base's public recovery authority.
 * Base owns trust and login recovery; Backups owns job/rewrite verification.
 */
final class MigrationRecovery {
	private const REQUIRED_BASE_API = '1.0';
	private const PROBE_PREFIX = 'cb-backups-migration-probe-';
	private const PROBE_TTL = 300;

	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		add_action( 'init', [ self::class, 'serve_probe' ], -1000 );
		add_action( 'admin_init', [ self::class, 'prepare_destination_rewrites' ], 1 );
		add_action( 'wp_ajax_cb_backups_finalize_migration_recovery', [ self::class, 'finalize_ajax' ] );
	}

	/** @param array<string,mixed> $plan @return array<string,mixed> */
	public static function prepare( array $plan ): array {
		if ( 'migration' !== (string) ( $plan['mode'] ?? '' ) ) {
			return [];
		}
		self::assert_supported();

		$recovery_id = 'cb-backups-' . wp_generate_uuid4();
		$ticket = BaseRecovery::issue_ticket(
			$recovery_id,
			(string) ( $plan['target_site_url'] ?? '' )
		);

		return [
			'recovery_id'     => (string) $ticket['recovery_id'],
			'ticket'          => (string) $ticket['ticket'],
			'issued_at'       => (int) $ticket['issued_at'],
			'expires_at'      => (int) $ticket['expires_at'],
			'target_site_url' => (string) $ticket['target_site_url'],
			'probe_token'     => bin2hex( random_bytes( 16 ) ),
			'status'          => 'prepared',
		];
	}

	/** @param array<string,mixed> $meta @return array<string,mixed> */
	public static function activate_destination( array $meta ): array {
		if ( ! self::required( $meta ) ) {
			return $meta;
		}
		self::assert_supported();
		$recovery = self::recovery_meta( $meta );
		$state = BaseRecovery::activate_destination( (string) $recovery['ticket'] );
		$recovery['status'] = (string) ( $state['status'] ?? 'pending_reconcile' );
		$recovery['activated_at'] = time();
		$meta['migration_recovery'] = $recovery;
		return $meta;
	}

	/** @param array<string,mixed> $meta @return array<string,mixed> */
	public static function reconcile_destination( array $meta ): array {
		if ( ! self::required( $meta ) ) {
			return $meta;
		}
		self::assert_supported();
		$recovery = self::recovery_meta( $meta );
		$state = BaseRecovery::reconcile_destination( (string) $recovery['ticket'] );
		$recovery['status'] = (string) ( $state['status'] ?? '' );
		$recovery['reconciled_at'] = time();
		$recovery['reviewed_users'] = (int) ( $state['reviewed_users'] ?? 0 );
		$meta['migration_recovery'] = $recovery;
		return $meta;
	}

	/** @param array<string,mixed> $meta */
	public static function required( array $meta ): bool {
		return 'migration' === (string) ( $meta['restore_mode'] ?? '' )
			&& is_array( $meta['migration_recovery'] ?? null )
			&& '' !== (string) ( $meta['migration_recovery']['ticket'] ?? '' );
	}

	/** @param array<string,mixed> $meta */
	public static function login_url( array $meta, string $redirect_to ): string {
		if ( ! self::required( $meta ) || ! self::supported() ) {
			return '';
		}
		try {
			return BaseRecovery::login_url( (string) $meta['migration_recovery']['ticket'], $redirect_to );
		} catch ( \Throwable ) {
			return '';
		}
	}

	/** @param array<string,mixed> $meta */
	public static function requires_probe( array $meta ): bool {
		return self::required( $meta )
			&& self::supported()
			&& BaseRecovery::requires_pretty_routing();
	}

	/** @param array<string,mixed> $meta */
	public static function probe_url( array $meta ): string {
		if ( ! self::requires_probe( $meta ) ) {
			return '';
		}
		$recovery = self::recovery_meta( $meta );
		$token = (string) ( $recovery['probe_token'] ?? '' );
		return '' !== $token ? home_url( '/' . self::PROBE_PREFIX . $token . '/' ) : '';
	}

	public static function prepare_destination_rewrites(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		foreach ( Repository::active( 5 ) as $job ) {
			if ( 'restore' !== (string) ( $job['kind'] ?? '' ) || 'await_recovery' !== (string) ( $job['stage'] ?? '' ) ) {
				continue;
			}
			$meta = is_array( $job['meta'] ?? null ) ? $job['meta'] : [];
			if ( ! self::required( $meta ) || ! self::supported() ) {
				continue;
			}
			$status = BaseRecovery::status( (string) $meta['migration_recovery']['ticket'] );
			if ( 'authenticated' !== (string) ( $status['status'] ?? '' )
				|| get_current_user_id() !== (int) ( $status['approved_user_id'] ?? 0 )
			) {
				continue;
			}
			if ( empty( $meta['migration_recovery']['rewrite_flushed_at'] ) ) {
				flush_rewrite_rules( true );
				$meta['migration_recovery']['rewrite_flushed_at'] = time();
				Repository::update( (string) $job['job_id'], [ 'meta' => $meta ] );
			}
		}
	}

	public static function serve_probe(): void {
		if ( ! is_user_logged_in() ) {
			return;
		}
		$path = self::request_path();
		if ( ! str_starts_with( $path, '/' . self::PROBE_PREFIX ) ) {
			return;
		}

		foreach ( Repository::active( 5 ) as $job ) {
			if ( 'restore' !== (string) ( $job['kind'] ?? '' ) || 'await_recovery' !== (string) ( $job['stage'] ?? '' ) ) {
				continue;
			}
			$meta = is_array( $job['meta'] ?? null ) ? $job['meta'] : [];
			if ( ! self::required( $meta ) || empty( $meta['migration_recovery']['rewrite_flushed_at'] ) ) {
				continue;
			}
			$expected = wp_parse_url( self::probe_url( $meta ), PHP_URL_PATH );
			if ( ! is_string( $expected ) || $path !== $expected ) {
				continue;
			}
			$status = self::supported()
				? BaseRecovery::status( (string) $meta['migration_recovery']['ticket'] )
				: [];
			$user_id = get_current_user_id();
			if ( 'authenticated' !== (string) ( $status['status'] ?? '' )
				|| $user_id !== (int) ( $status['approved_user_id'] ?? 0 )
			) {
				continue;
			}

			set_transient( self::probe_key( $meta ), $user_id, self::PROBE_TTL );
			status_header( 204 );
			nocache_headers();
			header( 'Cache-Control: no-store, no-cache, must-revalidate, max-age=0' );
			exit;
		}
	}

	public static function finalize_ajax(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_send_json_error( [ 'message' => __( 'You are not allowed to manage backups.', 'core-blueprint-backups' ) ], 403 );
		}
		check_ajax_referer( 'cb_backups_admin', 'nonce' );
		$job_id = sanitize_text_field( (string) ( isset( $_POST['job_id'] ) ? wp_unslash( $_POST['job_id'] ) : '' ) );

		try {
			$job = self::finalize( $job_id );
			wp_send_json_success( JobPresentation::present( $job, Actions::public_job( $job ) ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 409 );
		}
	}

	/** @return array<string,mixed> */
	public static function finalize( string $job_id ): array {
		self::assert_supported();
		$job = Repository::get( $job_id );
		if ( ! is_array( $job )
			|| 'restore' !== (string) ( $job['kind'] ?? '' )
			|| 'await_recovery' !== (string) ( $job['stage'] ?? '' )
		) {
			throw new RuntimeException( 'Migration recovery job is not awaiting destination verification.' );
		}

		$meta = is_array( $job['meta'] ?? null ) ? $job['meta'] : [];
		if ( ! self::required( $meta ) ) {
			throw new RuntimeException( 'Migration recovery metadata is incomplete.' );
		}
		$user_id = get_current_user_id();
		if ( $user_id < 1 ) {
			throw new RuntimeException( __( 'Migration recovery authentication is incomplete.', 'core-blueprint-backups' ) );
		}
		if ( self::requires_probe( $meta ) && $user_id !== (int) get_transient( self::probe_key( $meta ) ) ) {
			throw new RuntimeException( __( 'Destination rewrite verification has not completed yet. Retry the verification before finishing migration.', 'core-blueprint-backups' ) );
		}

		$ticket = (string) $meta['migration_recovery']['ticket'];
		$status = BaseRecovery::status( $ticket );
		if ( 'authenticated' !== (string) ( $status['status'] ?? '' )
			|| $user_id !== (int) ( $status['approved_user_id'] ?? 0 )
		) {
			throw new RuntimeException( __( 'Migration recovery authentication is incomplete.', 'core-blueprint-backups' ) );
		}
		$requires_probe = self::requires_probe( $meta );
		if ( ! BaseRecovery::finalize( $ticket ) ) {
			throw new RuntimeException( __( 'Core Blueprint could not finalize migration recovery.', 'core-blueprint-backups' ) );
		}

		delete_transient( self::probe_key( $meta ) );
		$meta['migration_recovery']['status'] = 'completed';
		$meta['migration_recovery']['rewrite_verification_required'] = $requires_probe;
		if ( $requires_probe ) {
			$meta['migration_recovery']['rewrite_verified_at'] = time();
		}
		$meta['migration_recovery']['approved_user_id'] = $user_id;
		$meta['completed_timestamp'] = time();
		Repository::update( $job_id, [ 'meta' => $meta ] );
		Repository::complete( $job_id );

		Audit::log( 'backups.restore.completed', 'warning', [
			'confirmation'           => $meta['restore_confirmation'] ?? [],
			'job_id'                 => $job_id,
			'type'                   => (string) ( $job['backup_type'] ?? '' ),
			'trigger'                => (string) ( $job['trigger_source'] ?? '' ),
			'duration'               => (int) ( $meta['duration_seconds'] ?? 0 ),
			'mode'                   => 'migration',
			'migration_replacements' => (int) ( $meta['migration_replacements'] ?? 0 ),
			'runtime_preserved'      => isset( $meta['runtime_preserved'] ) && is_array( $meta['runtime_preserved'] ) ? $meta['runtime_preserved'] : [],
			'recovery_user_id'       => $user_id,
			'rewrite_verified'       => $requires_probe,
		] );

		return Repository::get( $job_id ) ?? $job;
	}

	public static function supported(): bool {
		return class_exists( BaseRecovery::class )
			&& method_exists( BaseRecovery::class, 'api_version' )
			&& version_compare( BaseRecovery::api_version(), self::REQUIRED_BASE_API, '>=' );
	}

	private static function assert_supported(): void {
		if ( ! self::supported() ) {
			throw new RuntimeException(
				__( 'Cross-site migration requires a Core Blueprint Base version with Migration Recovery support.', 'core-blueprint-backups' )
			);
		}
	}

	/** @param array<string,mixed> $meta @return array<string,mixed> */
	private static function recovery_meta( array $meta ): array {
		$recovery = is_array( $meta['migration_recovery'] ?? null ) ? $meta['migration_recovery'] : [];
		if ( '' === (string) ( $recovery['ticket'] ?? '' ) ) {
			throw new RuntimeException( 'Migration recovery ticket is missing.' );
		}
		return $recovery;
	}

	/** @param array<string,mixed> $meta */
	private static function probe_key( array $meta ): string {
		$id = (string) ( $meta['migration_recovery']['recovery_id'] ?? '' );
		return 'cb_backups_migration_probe_' . substr( hash( 'sha256', $id ), 0, 24 );
	}

	private static function request_path(): string {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
		$path = wp_parse_url( $uri, PHP_URL_PATH );
		return is_string( $path ) ? $path : '';
	}
}
