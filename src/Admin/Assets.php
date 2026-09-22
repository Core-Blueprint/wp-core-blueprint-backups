<?php
declare(strict_types=1);

namespace CB\Backups\Admin;

use CB\Backups\Integration\MigrationRecovery;
use CB\Backups\Jobs\Repository;
use CB\Core\Admin\PageRegistry;
use CB\Core\Admin\SettingsRegistry;

defined( 'ABSPATH' ) || exit;

final class Assets {
	private static bool $module_data_registered = false;
	private static bool $job_monitor_data_registered = false;

	public static function boot(): void {
		JobMonitor::boot();
		add_filter( 'wp_auth_check_load', [ self::class, 'filter_wp_auth_check_load' ], 20, 2 );
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function enqueue( string $hook ): void {
		$is_operational = $hook === PageRegistry::hook_suffix( 'core-blueprint-backups' );
		$is_settings    = self::is_settings_request();
		if ( ! $is_operational && ! $is_settings ) {
			return;
		}

		wp_enqueue_style(
			'cb-backups-admin',
			CB_BACKUPS_URL . 'assets/css/admin.css',
			[],
			CB_BACKUPS_VERSION
		);

		if ( $is_settings ) {
			return;
		}

		wp_enqueue_style(
			'cb-backups-job-monitor',
			CB_BACKUPS_URL . 'assets/css/job-monitor.css',
			[ 'cb-backups-admin' ],
			CB_BACKUPS_VERSION
		);

		wp_enqueue_script_module(
			'@cb-backups/admin',
			CB_BACKUPS_URL . 'assets/js/admin.js',
			[],
			CB_BACKUPS_VERSION
		);
		wp_enqueue_script_module(
			'@cb-backups/job-monitor',
			CB_BACKUPS_URL . 'assets/js/job-monitor.js',
			[],
			CB_BACKUPS_VERSION
		);
		wp_enqueue_script_module(
			'@cb-backups/job-handoff',
			CB_BACKUPS_URL . 'assets/js/job-handoff.js',
			[ '@cb-backups/job-monitor' ],
			CB_BACKUPS_VERSION
		);
		wp_enqueue_script_module(
			'@cb-backups/job-terminal-recovery',
			CB_BACKUPS_URL . 'assets/js/job-terminal-recovery.js',
			[],
			CB_BACKUPS_VERSION
		);

		if ( ! self::$module_data_registered ) {
			add_filter(
				'script_module_data_@cb-backups/admin',
				static function ( array $existing ): array {
					return array_merge( $existing, self::module_data() );
				}
			);
			self::$module_data_registered = true;
		}

		if ( ! self::$job_monitor_data_registered ) {
			add_filter(
				'script_module_data_@cb-backups/job-monitor',
				static function ( array $existing ): array {
					return array_merge( $existing, self::job_monitor_data() );
				}
			);
			self::$job_monitor_data_registered = true;
		}
	}

	public static function filter_wp_auth_check_load( bool $show, \WP_Screen $screen ): bool {
		unset( $screen );
		if ( ! $show ) {
			return false;
		}
		$page = isset( $_GET['page'] ) ? sanitize_key( (string) wp_unslash( $_GET['page'] ) ) : '';
		if ( 'core-blueprint-backups' !== $page ) {
			return $show;
		}
		$job_id = self::current_job_id();
		$job = '' !== $job_id ? Repository::get( $job_id ) : null;
		$meta = is_array( $job ) && is_array( $job['meta'] ?? null ) ? $job['meta'] : [];
		return MigrationRecovery::required( $meta ) ? false : $show;
	}

	/** @return array<string,mixed> */
	private static function module_data(): array {
		return [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'cb_backups_admin' ),
			// Job polling is owned by the dedicated auth-aware monitor module.
			'jobId'   => '',
			'flash'   => self::flash_message(),
			'labels'  => [
				'cancelling'       => __( 'Cancelling…', 'core-blueprint-backups' ),
				'modalCancel'      => __( 'Cancel', 'core-blueprint-backups' ),
				'requestFailed'      => __( 'Backup status request failed.', 'core-blueprint-backups' ),
				'importStart'        => __( 'Upload backup', 'core-blueprint-backups' ),
				'importUploading'    => __( 'Uploading…', 'core-blueprint-backups' ),
				'importPreparing'    => __( 'Preparing upload…', 'core-blueprint-backups' ),
				'importValidating'   => __( 'Validating backup…', 'core-blueprint-backups' ),
				'importResume'       => __( 'Resume upload', 'core-blueprint-backups' ),
				'importResuming'     => __( 'Resuming previous import upload.', 'core-blueprint-backups' ),
				'importInterrupted'  => __( 'Upload interrupted — select the same file to resume.', 'core-blueprint-backups' ),
				'importPrepared'     => __( 'Backup imported and prepared for restore.', 'core-blueprint-backups' ),
				'importCancelled'    => __( 'Import cancelled.', 'core-blueprint-backups' ),
				'importCancel'       => __( 'Cancel import', 'core-blueprint-backups' ),
				'importCancelTitle'  => __( 'Cancel import?', 'core-blueprint-backups' ),
				'importCancelBody'   => __( 'The uploaded chunks for this incomplete import will be removed.', 'core-blueprint-backups' ),
				'importSelectFile'   => __( 'Select a .cbbackup file first.', 'core-blueprint-backups' ),
				'importOnlyBackup'   => __( 'Only .cbbackup files can be imported.', 'core-blueprint-backups' ),
				'importNoProgress'   => __( 'Import upload did not advance.', 'core-blueprint-backups' ),
				'importRequestFailed'=> __( 'Import request failed.', 'core-blueprint-backups' ),
				'bulkDeleteTitle'   => __( 'Delete selected backups?', 'core-blueprint-backups' ),
				'bulkDeleteOne'     => __( 'Permanently delete 1 selected backup? This restore point will be removed from local backup storage and cannot be recovered.', 'core-blueprint-backups' ),
				'bulkDeleteMany'    => __( 'Permanently delete %d selected backups? These restore points will be removed from local backup storage and cannot be recovered.', 'core-blueprint-backups' ),
				'bulkDeleteConfirm' => __( 'Delete selected', 'core-blueprint-backups' ),
				'bulkSelectedOne'    => __( '1 backup selected', 'core-blueprint-backups' ),
				'bulkSelectedMany'   => __( '%d backups selected', 'core-blueprint-backups' ),
			],
		];
	}

	/** @return array<string,mixed> */
	private static function job_monitor_data(): array {
		$job_id = self::current_job_id();
		$job = '' !== $job_id ? Repository::get( $job_id ) : null;
		$meta = is_array( $job ) && is_array( $job['meta'] ?? null ) ? $job['meta'] : [];
		$reconnect_url = self::reconnect_url( $job_id );
		$awaiting_recovery = is_array( $job )
			&& 'restore' === (string) ( $job['kind'] ?? '' )
			&& 'await_recovery' === (string) ( $job['stage'] ?? '' )
			&& MigrationRecovery::required( $meta );

		return [
			'ajaxUrl'                => admin_url( 'admin-ajax.php' ),
			'nonce'                  => wp_create_nonce( 'cb_backups_admin' ),
			'jobId'                  => $job_id,
			'jobKind'                => is_array( $job ) ? (string) ( $job['kind'] ?? '' ) : '',
			'restoreMode'            => (string) ( $meta['restore_mode'] ?? '' ),
			'reconnectUrl'           => $reconnect_url,
			'loginUrl'               => MigrationRecovery::login_url( $meta, $reconnect_url ),
			'recoveryRequiresProbe'  => $awaiting_recovery && MigrationRecovery::requires_probe( $meta ),
			'recoveryProbeUrl'       => $awaiting_recovery ? MigrationRecovery::probe_url( $meta ) : '',
			'recoveryFinalizeAction' => 'cb_backups_finalize_migration_recovery',
			'labels'                 => [
				'failed'                   => __( 'Failed', 'core-blueprint-backups' ),
				'cancelled'                => __( 'Cancelled', 'core-blueprint-backups' ),
				'cancelling'               => __( 'Cancelling…', 'core-blueprint-backups' ),
				'cancel'                   => __( 'Cancel backup', 'core-blueprint-backups' ),
				'modalCancel'              => __( 'Cancel', 'core-blueprint-backups' ),
				'cancelTitle'              => __( 'Cancel backup?', 'core-blueprint-backups' ),
				'cancelConfirm'            => __( 'The current safe chunk will finish first. Incomplete backup data will then be removed.', 'core-blueprint-backups' ),
				'cancelRequested'          => __( 'Backup cancellation requested.', 'core-blueprint-backups' ),
				'requestFailed'            => __( 'Backup status request failed.', 'core-blueprint-backups' ),
				'networkInterrupted'       => __( 'Connection to the restore monitor was interrupted. Retrying… The restore continues on the server.', 'core-blueprint-backups' ),
				'authRequired'             => __( 'Your WordPress session changed during migration. Sign in again to continue monitoring. The restore continues safely in the background.', 'core-blueprint-backups' ),
				'migrationSwitching'       => __( 'The migrated site is taking over. Your WordPress session may change while final safety checks finish.', 'core-blueprint-backups' ),
				'restoreSwitching'         => __( 'The restored site is taking over. Your WordPress session may change while final safety checks finish.', 'core-blueprint-backups' ),
				'migrationSignIn'          => __( 'The migrated site is now active. Sign in again to confirm the final migration result.', 'core-blueprint-backups' ),
				'restoreSignIn'            => __( 'The restored site is now active. Sign in again to confirm the final restore result.', 'core-blueprint-backups' ),
				'finalizing'               => __( 'Finalizing', 'core-blueprint-backups' ),
				'migrationFinalizingTitle' => __( 'Finalizing migration', 'core-blueprint-backups' ),
				'migrationFinalizingBody'  => __( 'The migrated database has been restored and is being verified. Core Blueprint will switch the website next. Your WordPress session may expire during that final switch — this is expected.', 'core-blueprint-backups' ),
				'restoreFinalizingTitle'   => __( 'Finalizing restore', 'core-blueprint-backups' ),
				'restoreFinalizingBody'    => __( 'The restored database has been staged and is being verified. Core Blueprint will switch the website next. Your WordPress session may expire during that final switch — this is expected.', 'core-blueprint-backups' ),
				'migrationAppliedTitle'    => __( 'Migration applied successfully', 'core-blueprint-backups' ),
				'migrationAppliedBody'     => __( 'The migrated site is now active. Your previous WordPress session was replaced as expected. Sign in again to complete the final verification and view the migration result.', 'core-blueprint-backups' ),
				'restoreAppliedTitle'      => __( 'Restore applied successfully', 'core-blueprint-backups' ),
				'restoreAppliedBody'       => __( 'The restored site is now active. Your previous WordPress session was replaced as expected. Sign in again to complete the final verification and view the restore result.', 'core-blueprint-backups' ),
				'migrationAppliedToast'    => __( 'Migration applied successfully. Sign in again to complete final verification.', 'core-blueprint-backups' ),
				'restoreAppliedToast'      => __( 'Restore applied successfully. Sign in again to complete final verification.', 'core-blueprint-backups' ),
				'dismissResult'            => __( 'Dismiss', 'core-blueprint-backups' ),
				'nonceExpired'             => __( 'Your monitoring session expired. Reload this page to continue monitoring the server-owned job.', 'core-blueprint-backups' ),
				'signInAgain'              => __( 'Sign in again', 'core-blueprint-backups' ),
				'secureSignIn'             => __( 'Continue to secure sign-in', 'core-blueprint-backups' ),
				'recoveryVerifying'        => __( 'Verifying destination access and rewrite routing…', 'core-blueprint-backups' ),
				'recoveryProbeFailed'      => __( 'Destination rewrite verification did not reach WordPress. You remain signed in safely; check the destination permalink or web-server rewrite configuration and retry.', 'core-blueprint-backups' ),
				'recoveryFinalizeFailed'   => __( 'Destination recovery could not be finalized. Retry verification before completing the migration.', 'core-blueprint-backups' ),
				'reloadMonitor'            => __( 'Reload monitoring', 'core-blueprint-backups' ),
				'reconnected'              => __( 'Restore monitoring reconnected.', 'core-blueprint-backups' ),
			],
		];
	}

	/** @return array{message:string,variant:string}|null */
	private static function flash_message(): ?array {
		if ( isset( $_GET['cb_error'] ) ) {
			$message = sanitize_text_field( (string) wp_unslash( $_GET['cb_error'] ) );
			return '' !== $message ? [ 'message' => $message, 'variant' => 'error' ] : null;
		}

		$notice = sanitize_key( (string) ( isset( $_GET['cb_notice'] ) ? wp_unslash( $_GET['cb_notice'] ) : '' ) );

		if ( 'backups_bulk_deleted' === $notice ) {
			$count = max( 0, (int) ( $_GET['deleted'] ?? 0 ) );
			$message = sprintf(
				/* translators: %d: number of deleted backups. */
				_n( '%d backup deleted.', '%d backups deleted.', $count, 'core-blueprint-backups' ),
				$count
			);
			return [ 'message' => $message, 'variant' => 'success' ];
		}

		$messages = [
			'backup_started'      => [ __( 'Backup job started. It continues in the background if you leave this page.', 'core-blueprint-backups' ), 'info' ],
			'restore_started'     => [ __( 'Restore job started. Execution is server-owned; this page only monitors its status.', 'core-blueprint-backups' ), 'warning' ],
			'backup_deleted'      => [ __( 'Backup deleted.', 'core-blueprint-backups' ), 'success' ],
			'backup_verified'     => [ __( 'Backup integrity verified successfully.', 'core-blueprint-backups' ), 'success' ],
			'schedules_saved'     => [ __( 'Backup schedules saved.', 'core-blueprint-backups' ), 'success' ],
			'import_prepared'     => [ __( 'Backup imported and prepared for restore.', 'core-blueprint-backups' ), 'success' ],
			'import_deleted'      => [ __( 'Prepared import deleted.', 'core-blueprint-backups' ), 'success' ],
		];

		if ( ! isset( $messages[ $notice ] ) ) {
			return null;
		}

		return [
			'message' => (string) $messages[ $notice ][0],
			'variant' => (string) $messages[ $notice ][1],
		];
	}

	private static function current_job_id(): string {
		$job_id = isset( $_GET['job'] ) ? sanitize_text_field( (string) wp_unslash( $_GET['job'] ) ) : '';
		if ( '' !== $job_id && Repository::get( $job_id ) ) {
			return $job_id;
		}
		$active = Repository::active( 1 );
		return isset( $active[0]['job_id'] ) ? (string) $active[0]['job_id'] : '';
	}

	private static function reconnect_url( string $job_id ): string {
		$args = [ 'page' => 'core-blueprint-backups', 'cb_monitor_reconnect' => '1' ];
		if ( '' !== $job_id ) {
			$args['job'] = $job_id;
			$job = Repository::get( $job_id );
			$args['tab'] = is_array( $job ) && 'restore' === (string) ( $job['kind'] ?? '' ) ? 'restore' : 'backups';
		}
		return add_query_arg( $args, admin_url( 'admin.php' ) );
	}

	private static function is_settings_request(): bool {
		$extension = isset( $_GET['extension'] ) ? sanitize_key( wp_unslash( (string) $_GET['extension'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin routing.
		if ( 'core-blueprint-backups' !== $extension ) {
			return false;
		}

		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( (string) $_GET['page'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only admin routing.
		$query = wp_parse_url( SettingsRegistry::url( 'core-blueprint-backups' ), PHP_URL_QUERY );
		if ( ! is_string( $query ) || '' === $query ) {
			return false;
		}

		parse_str( $query, $args );
		return isset( $args['page'] ) && $page === sanitize_key( (string) $args['page'] );
	}
}
