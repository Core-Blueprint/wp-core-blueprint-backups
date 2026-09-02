<?php
declare(strict_types=1);

namespace CB\Backups\Admin;

use CB\Backups\Jobs\Repository;
use CB\Backups\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

/** Read-only AJAX boundary used by the wp-admin job monitor. */
final class JobMonitor {
	public static function boot(): void {
		add_action( 'wp_ajax_cb_backups_job_monitor', [ self::class, 'status' ] );
		add_action( 'wp_ajax_nopriv_cb_backups_job_monitor', [ self::class, 'auth_required' ] );
	}

	public static function status(): void {
		// Permission loss must remain visible after re-login, even when the old
		// page nonce also expired. No job data is read before both checks pass.
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_send_json_error(
				[
					'code'    => 'capability_required',
					'message' => __( 'Your account currently lacks permission to view this job. Core Blueprint privileged access review may be required. The server job is not cancelled; its result cannot be confirmed from this session.', 'core-blueprint-backups' ),
				],
				403
			);
		}

		$nonce = sanitize_text_field( (string) ( isset( $_POST['nonce'] ) ? wp_unslash( $_POST['nonce'] ) : '' ) );
		if ( false === wp_verify_nonce( $nonce, 'cb_backups_admin' ) ) {
			wp_send_json_error(
				[
					'code'    => 'nonce_expired',
					'message' => __( 'Your monitoring session expired. Reload this page to continue monitoring the server-owned job.', 'core-blueprint-backups' ),
				],
				403
			);
		}

		$job_id = sanitize_text_field( (string) ( isset( $_POST['job_id'] ) ? wp_unslash( $_POST['job_id'] ) : '' ) );
		$job = '' !== $job_id ? Repository::get( $job_id ) : null;
		if ( ! is_array( $job ) ) {
			wp_send_json_error(
				[
					'code'    => 'job_not_found',
					'message' => __( 'Backup job not found.', 'core-blueprint-backups' ),
				],
				404
			);
		}

		wp_send_json_success( JobPresentation::present( $job, Actions::public_job( $job ) ) );
	}

	public static function auth_required(): void {
		wp_send_json_error(
			[
				'code'    => 'auth_required',
				'message' => __( 'Your WordPress session changed during migration. Sign in again to continue monitoring. The restore continues safely in the background.', 'core-blueprint-backups' ),
			],
			401
		);
	}
}
