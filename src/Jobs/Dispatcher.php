<?php
declare(strict_types=1);

namespace CB\Backups\Jobs;

defined( 'ABSPATH' ) || exit;

final class Dispatcher {
	private const ACTION = 'cb_backups_worker';

	public static function boot(): void {
		add_action( 'wp_ajax_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'wp_ajax_nopriv_' . self::ACTION, [ self::class, 'handle' ] );
		add_action( 'init', [ self::class, 'recover_active' ], 30 );
	}

	public static function dispatch( string $job_id ): bool {
		$job = Repository::get( $job_id );
		if ( ! $job || ! in_array( (string) $job['status'], [ 'queued', 'running', 'cancelling' ], true ) ) {
			return false;
		}

		set_transient( self::recovery_key( $job_id ), 1, 15 );

		$response = wp_remote_post(
			admin_url( 'admin-ajax.php' ),
			[
				'timeout'   => 0.01,
				'blocking'  => false,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ),
				'body'      => [
					'action' => self::ACTION,
					'job_id' => $job_id,
					'token'  => self::token( $job_id ),
				],
			]
		);

		return ! is_wp_error( $response );
	}

	public static function handle(): never {
		$job_id = sanitize_text_field( (string) ( isset( $_POST['job_id'] ) ? wp_unslash( $_POST['job_id'] ) : '' ) );
		$token  = sanitize_text_field( (string) ( isset( $_POST['token'] ) ? wp_unslash( $_POST['token'] ) : '' ) );

		if ( '' === $job_id || '' === $token || ! hash_equals( self::token( $job_id ), $token ) ) {
			status_header( 403 );
			exit;
		}

		$result = Runner::run_budget( $job_id, 3.0 );
		$job    = $result['job'];

		if ( $result['processed'] && is_array( $job ) && in_array( (string) $job['status'], [ 'queued', 'running', 'cancelling' ], true ) ) {
			self::dispatch( $job_id );
		}

		status_header( 204 );
		exit;
	}

	public static function recover_active(): void {
		foreach ( Repository::active( 2 ) as $job ) {
			$job_id = (string) $job['job_id'];
			if ( get_transient( self::recovery_key( $job_id ) ) ) {
				continue;
			}
			self::dispatch( $job_id );
		}
	}

	private static function recovery_key( string $job_id ): string {
		return 'cb_backups_dispatch_' . substr( str_replace( '-', '', $job_id ), 0, 24 );
	}

	private static function token( string $job_id ): string {
		return hash_hmac( 'sha256', 'core-blueprint-backups-worker|' . $job_id, wp_salt( 'auth' ) );
	}
}
