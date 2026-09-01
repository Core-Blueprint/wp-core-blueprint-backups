<?php
declare(strict_types=1);

namespace CB\Backups\Admin;

defined( 'ABSPATH' ) || exit;

/**
 * Read-only presentation contract for backup/restore job telemetry.
 *
 * Recovery state remains owned by the backup/restore engines. This class only
 * translates internal stages and already-recorded counters for wp-admin.
 */
final class JobPresentation {
	/** @param array<string,mixed> $job @param array<string,mixed> $data @return array<string,mixed> */
	public static function present( array $job, array $data ): array {
		$meta = is_array( $job['meta'] ?? null ) ? $job['meta'] : [];
		$kind = (string) ( $job['kind'] ?? '' );
		$stage = (string) ( $job['stage'] ?? '' );

		$data['stage_label'] = self::stage_label( $kind, $stage );
		$data['status_label'] = self::status_label( (string) ( $job['status'] ?? '' ) );
		$data['restore_mode'] = 'restore' === $kind ? (string) ( $meta['restore_mode'] ?? 'restore' ) : '';

		if ( 'restore' === $kind ) {
			[ $file_metric, $byte_metric ] = self::restore_metrics( $stage, $meta );
			$data['file_metric'] = $file_metric;
			$data['byte_metric'] = $byte_metric;
		} else {
			$data['file_metric'] = self::metric(
				__( 'Files', 'core-blueprint-backups' ),
				(int) ( $data['files_done'] ?? 0 ),
				(int) ( $data['files_total'] ?? 0 )
			);
			$data['byte_metric'] = self::metric(
				__( 'Data processed', 'core-blueprint-backups' ),
				(int) ( $data['files_bytes_done'] ?? 0 ),
				(int) ( $data['files_bytes_total'] ?? 0 )
			);
		}

		return $data;
	}

	private static function stage_label( string $kind, string $stage ): string {
		if ( 'restore' === $kind ) {
			return match ( $stage ) {
				'queued'                  => __( 'Preparing restore…', 'core-blueprint-backups' ),
				'verify_archive'          => __( 'Verifying backup archive…', 'core-blueprint-backups' ),
				'extract'                 => __( 'Preparing restore files…', 'core-blueprint-backups' ),
				'verify_stage'            => __( 'Verifying prepared files…', 'core-blueprint-backups' ),
				'migrate_database'        => __( 'Preparing migrated database…', 'core-blueprint-backups' ),
				'restore_database'        => __( 'Preparing restored database…', 'core-blueprint-backups' ),
				'verify_database_content' => __( 'Verifying restored database…', 'core-blueprint-backups' ),
				'prepare_live'            => __( 'Preparing safe switch…', 'core-blueprint-backups' ),
				'commit_live'             => __( 'Applying restored website…', 'core-blueprint-backups' ),
				'verify_live'             => __( 'Verifying restored files and runtime…', 'core-blueprint-backups' ),
				'finalize_live'           => __( 'Running final safety checks…', 'core-blueprint-backups' ),
				'completed'               => __( 'Migration completed', 'core-blueprint-backups' ),
				default                   => __( 'Processing restore…', 'core-blueprint-backups' ),
			};
		}

		return match ( $stage ) {
			'queued'    => __( 'Preparing backup…', 'core-blueprint-backups' ),
			'database'  => __( 'Exporting database…', 'core-blueprint-backups' ),
			'inventory' => __( 'Scanning website files…', 'core-blueprint-backups' ),
			'package'   => __( 'Creating backup archive…', 'core-blueprint-backups' ),
			'verify'    => __( 'Verifying backup archive…', 'core-blueprint-backups' ),
			'completed' => __( 'Backup completed', 'core-blueprint-backups' ),
			default     => __( 'Processing backup…', 'core-blueprint-backups' ),
		};
	}

	private static function status_label( string $status ): string {
		return match ( $status ) {
			'queued'     => __( 'Queued', 'core-blueprint-backups' ),
			'running'    => __( 'Running', 'core-blueprint-backups' ),
			'cancelling' => __( 'Cancelling…', 'core-blueprint-backups' ),
			'completed'  => __( 'Completed', 'core-blueprint-backups' ),
			'failed'     => __( 'Failed', 'core-blueprint-backups' ),
			'cancelled'  => __( 'Cancelled', 'core-blueprint-backups' ),
			default      => ucfirst( $status ),
		};
	}

	/** @param array<string,mixed> $meta @return array{0:array<string,mixed>,1:array<string,mixed>} */
	private static function restore_metrics( string $stage, array $meta ): array {
		$manifest = is_array( $meta['manifest'] ?? null ) ? $meta['manifest'] : [];
		$filesystem = is_array( $manifest['filesystem'] ?? null ) ? $manifest['filesystem'] : [];
		$files = max( 0, (int) ( $filesystem['file_count'] ?? 0 ) );
		$bytes = max( 0, (int) ( $filesystem['bytes'] ?? 0 ) );
		$payloads = max( 0, (int) ( $meta['restore_verify_total'] ?? 0 ) );

		if ( 'verify_archive' === $stage ) {
			return [
				self::metric( __( 'Backup payloads verified', 'core-blueprint-backups' ), (int) ( $meta['restore_verify_done'] ?? 0 ), $payloads ),
				self::metric( __( 'Backup archive size', 'core-blueprint-backups' ), (int) ( $meta['restore_archive_size'] ?? 0 ), 0 ),
			];
		}

		if ( 'extract' === $stage ) {
			return [
				self::metric( __( 'Payloads extracted', 'core-blueprint-backups' ), (int) ( $meta['restore_extract_files_done'] ?? 0 ), $payloads ),
				self::metric( __( 'Data extracted', 'core-blueprint-backups' ), (int) ( $meta['restore_extract_bytes_done'] ?? 0 ), 0 ),
			];
		}

		if ( 'verify_stage' === $stage ) {
			return [
				self::metric( __( 'Prepared payloads verified', 'core-blueprint-backups' ), (int) ( $meta['stage_verify_done'] ?? 0 ), $payloads ),
				self::metric( __( 'Filesystem snapshot', 'core-blueprint-backups' ), $bytes, 0 ),
			];
		}

		if ( 'verify_live' === $stage ) {
			return [
				self::metric( __( 'Restored files verified', 'core-blueprint-backups' ), (int) ( $meta['live_verify_done'] ?? 0 ), (int) ( $meta['live_verify_total'] ?? 0 ) ),
				self::metric( __( 'Restored filesystem data', 'core-blueprint-backups' ), $bytes, 0 ),
			];
		}

		if ( 'finalize_live' === $stage || 'completed' === $stage ) {
			$verified = max( 0, (int) ( $meta['live_verify_total'] ?? $files ) );
			return [
				self::metric( __( 'Restored files verified', 'core-blueprint-backups' ), $verified, $verified ),
				self::metric( __( 'Restored filesystem data', 'core-blueprint-backups' ), $bytes, 0 ),
			];
		}

		if ( in_array( $stage, [ 'migrate_database', 'restore_database', 'verify_database_content', 'prepare_live', 'commit_live' ], true ) ) {
			return [
				self::metric( __( 'Files staged', 'core-blueprint-backups' ), $files, $files ),
				self::metric( __( 'Filesystem snapshot', 'core-blueprint-backups' ), $bytes, 0 ),
			];
		}

		return [
			self::metric( __( 'Files', 'core-blueprint-backups' ), 0, $files ),
			self::metric( __( 'Filesystem snapshot', 'core-blueprint-backups' ), $bytes, 0 ),
		];
	}

	/** @return array{label:string,done:int,total:int} */
	private static function metric( string $label, int $done, int $total ): array {
		$done = max( 0, $done );
		$total = max( 0, $total );

		// A ratio with a numerator above its denominator is semantically invalid.
		// Do not cosmetically clamp it: fall back to an absolute value so the UI
		// never hides a telemetry-definition mismatch behind a fake 100%.
		if ( $total > 0 && $done > $total ) {
			$total = 0;
		}

		return [
			'label' => $label,
			'done'  => $done,
			'total' => $total,
		];
	}
}
