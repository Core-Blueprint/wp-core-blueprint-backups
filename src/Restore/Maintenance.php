<?php
declare(strict_types=1);

namespace CB\Backups\Restore;

use CB\Backups\Storage\LocalStorage;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Backup processing uses bounded native streams/atomic filesystem primitives; WP_Filesystem is not suitable for these server-owned jobs.

final class Maintenance {
	public static function boot(): void {
		add_action( 'template_redirect', [ self::class, 'protect_frontend' ], -1000 );
	}

	public static function activate( string $job_id ): void {
		LocalStorage::ensure();
		file_put_contents( self::marker(), sanitize_text_field( $job_id ), LOCK_EX );
	}

	public static function deactivate(): void {
		$marker = self::marker();
		if ( is_file( $marker ) ) {
			@unlink( $marker ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	public static function protect_frontend(): void {
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ! is_file( self::marker() ) ) {
			return;
		}
		status_header( 503 );
		header( 'Retry-After: 60' );
		wp_die(
			esc_html__( 'This site is temporarily unavailable while Core Blueprint restores a verified backup.', 'core-blueprint-backups' ),
			esc_html__( 'Restore in progress', 'core-blueprint-backups' ),
			[ 'response' => 503 ]
		);
	}

	private static function marker(): string {
		return LocalStorage::base_path() . '/.restore-active';
	}
}
