<?php
declare(strict_types=1);

namespace CB\Backups\Admin;

use CB\Backups\Jobs\Repository;
use CB\Core\Admin\PageRegistry;
use CB\Core\UI\Assets as CoreUiAssets;

defined( 'ABSPATH' ) || exit;

final class Assets {
	private static bool $module_data_registered = false;

	public static function boot(): void {
		add_action( 'admin_enqueue_scripts', [ self::class, 'enqueue' ] );
	}

	public static function enqueue( string $hook ): void {
		if ( $hook !== PageRegistry::hook_suffix( 'core-blueprint-backups' ) ) {
			return;
		}

		// Backups lives below the Core Blueprint admin menu. Consume the
		// shared Foundation primitives with the Core presentation instead of
		// shipping plugin-local modal/toast behavior.
		CoreUiAssets::enqueue_modals( CoreUiAssets::MODAL_PRESENTATION_CORE );
		CoreUiAssets::enqueue_toasts( CoreUiAssets::TOAST_PRESENTATION_CORE );

		// Time input remains a normal HH:MM text field. Base progressively
		// enhances the declarative markup with the shared 24-hour TimePicker.
		// Keep older compatible Base builds functional as plain text inputs.
		if ( method_exists( CoreUiAssets::class, 'enqueue_time_picker' ) ) {
			CoreUiAssets::enqueue_time_picker();
		}

		wp_enqueue_style(
			'cb-backups-admin',
			CB_BACKUPS_URL . 'assets/css/admin.css',
			[ 'cb-core-css-layout', 'cb-core-css-panels', 'cb-core-css-nav-tabs', 'cb-core-css-buttons', 'cb-core-css-form-controls', 'cb-core-css-notices' ],
			CB_BACKUPS_VERSION
		);

		wp_enqueue_script_module(
			'@cb-backups/admin',
			CB_BACKUPS_URL . 'assets/js/admin.js',
			[ '@cb-core/modal', '@cb-core/toast' ],
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
	}

	/** @return array<string,mixed> */
	private static function module_data(): array {
		return [
			'ajaxUrl' => admin_url( 'admin-ajax.php' ),
			'nonce'   => wp_create_nonce( 'cb_backups_admin' ),
			'jobId'   => self::current_job_id(),
			'flash'   => self::flash_message(),
			'labels'  => [
				'completed'        => __( 'Completed', 'core-blueprint-backups' ),
				'failed'           => __( 'Failed', 'core-blueprint-backups' ),
				'cancelled'        => __( 'Cancelled', 'core-blueprint-backups' ),
				'cancelling'       => __( 'Cancelling…', 'core-blueprint-backups' ),
				'cancel'           => __( 'Cancel backup', 'core-blueprint-backups' ),
				'modalCancel'      => __( 'Cancel', 'core-blueprint-backups' ),
				'cancelTitle'      => __( 'Cancel backup?', 'core-blueprint-backups' ),
				'cancelConfirm'    => __( 'The current safe chunk will finish first. Incomplete backup data will then be removed.', 'core-blueprint-backups' ),
				'cancelRequested'  => __( 'Backup cancellation requested.', 'core-blueprint-backups' ),
				'backupCompleted'  => __( 'Backup completed successfully.', 'core-blueprint-backups' ),
				'restoreCompleted' => __( 'Restore completed successfully.', 'core-blueprint-backups' ),
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
			'backup_started'   => [ __( 'Backup job started. It continues in the background if you leave this page.', 'core-blueprint-backups' ), 'info' ],
			'restore_started'  => [ __( 'Restore job started. Execution is server-owned; this page only monitors its status.', 'core-blueprint-backups' ), 'warning' ],
			'backup_deleted'   => [ __( 'Backup deleted.', 'core-blueprint-backups' ), 'success' ],
			'backup_verified'  => [ __( 'Backup integrity verified successfully.', 'core-blueprint-backups' ), 'success' ],
			'schedules_saved'  => [ __( 'Backup schedules saved.', 'core-blueprint-backups' ), 'success' ],
			'backup_completed' => [ __( 'Backup completed successfully.', 'core-blueprint-backups' ), 'success' ],
			'restore_completed'=> [ __( 'Restore completed successfully.', 'core-blueprint-backups' ), 'success' ],
			'import_prepared'  => [ __( 'Backup imported and prepared for restore.', 'core-blueprint-backups' ), 'success' ],
			'import_deleted'   => [ __( 'Prepared import deleted.', 'core-blueprint-backups' ), 'success' ],
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
}
