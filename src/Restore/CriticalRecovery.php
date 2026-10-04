<?php
declare(strict_types=1);

namespace CB\Backups\Restore;

use CB\Backups\Jobs\Repository;
use CB\Backups\Storage\LocalStorage;
use CB\Backups\Support\Audit;
use Throwable;

\defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Backup processing uses bounded native streams/atomic filesystem primitives; WP_Filesystem is not suitable for these server-owned jobs.

final class CriticalRecovery {
	private const MARKER = 'restore-critical.json';
	private static string $armed_job_id = '';
	private static bool $shutdown_registered = false;
	private static string $emergency_reserve = '';

	public static function arm( string $job_id ): void {
		$work = LocalStorage::work_dir( $job_id );
		$marker = $work . '/' . self::MARKER;
		$payload = wp_json_encode( [
			'job_id'     => $job_id,
			'version'    => CB_BACKUPS_VERSION,
			'armed_at'   => time(),
		], JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $payload ) ) {
			throw new \RuntimeException( 'Restore critical recovery marker could not be encoded.' );
		}
		$tmp = $marker . '.tmp';
		if ( false === file_put_contents( $tmp, $payload, LOCK_EX ) || ! @rename( $tmp, $marker ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new \RuntimeException( 'Restore critical recovery marker could not be written atomically.' );
		}

		self::$armed_job_id = $job_id;
		if ( ! self::$shutdown_registered ) {
			self::$shutdown_registered = true;
			self::$emergency_reserve = str_repeat( 'R', 262144 );
			register_shutdown_function( [ self::class, 'shutdown' ] );
		}
	}

	public static function disarm( string $job_id ): void {
		$marker = LocalStorage::work_dir( $job_id ) . '/' . self::MARKER;
		if ( is_file( $marker ) ) {
			@unlink( $marker ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		if ( self::$armed_job_id === $job_id ) {
			self::$armed_job_id = '';
		}
	}

	public static function is_armed( string $job_id ): bool {
		return is_file( LocalStorage::work_dir( $job_id ) . '/' . self::MARKER );
	}

	public static function fail_and_rollback( string $job_id, Throwable $cause ): string {
		$errors = self::rollback( $job_id );
		$message = $cause->getMessage();
		if ( $errors ) {
			$message .= ' ' . implode( ' ', $errors );
		}
		Repository::fail( $job_id, $message );
		if ( $errors ) {
			self::mark_rollback_incomplete( $job_id, $errors );
		} else {
			self::disarm( $job_id );
			Maintenance::deactivate();
		}
		Audit::log( 'backups.restore.failed', 'critical', [
			'job_id'              => $job_id,
			'error'               => $message,
			'rollback_incomplete' => ! empty( $errors ),
		] );
		return $message;
	}

	/** @return string[] */
	private static function rollback( string $job_id ): array {
		$errors = [];
		$job = Repository::get( $job_id );
		if ( ! is_array( $job ) ) {
			return [ 'Restore rollback could not reload the active job.' ];
		}
		$meta = is_array( $job['meta'] ?? null ) ? $job['meta'] : [];
		$manifest = is_array( $meta['manifest'] ?? null ) ? $meta['manifest'] : [];
		$database = is_array( $manifest['database'] ?? null ) ? $manifest['database'] : [];
		$tables = isset( $database['tables'] ) && is_array( $database['tables'] ) ? array_values( $database['tables'] ) : [];

		if ( 'website' === (string) ( $job['backup_type'] ?? '' ) && ! empty( $meta['recovery_path'] ) ) {
			try {
				FilesystemCommitter::rollback( (string) $meta['recovery_path'] );
			} catch ( Throwable $e ) {
				$errors[] = 'Filesystem rollback failed: ' . $e->getMessage();
			}
		}
		try {
			DatabaseImporter::rollback( $meta, $tables, $job_id );
		} catch ( Throwable $e ) {
			$errors[] = 'Database rollback failed: ' . $e->getMessage();
		}

		return $errors;
	}

	/** @param string[] $errors */
	private static function mark_rollback_incomplete( string $job_id, array $errors ): void {
		$marker = LocalStorage::work_dir( $job_id ) . '/' . self::MARKER;
		$payload = [
			'job_id'              => $job_id,
			'version'             => CB_BACKUPS_VERSION,
			'armed_at'            => time(),
			'rollback_incomplete' => true,
			'rollback_errors'     => array_values( $errors ),
		];
		$json = wp_json_encode( $payload, JSON_UNESCAPED_SLASHES );
		if ( is_string( $json ) ) {
			@file_put_contents( $marker, $json, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
	}

	public static function recover_terminal_markers(): void {
		$root = LocalStorage::base_path() . '/.work';
		$markers = glob( $root . '/*/' . self::MARKER ) ?: [];
		foreach ( $markers as $marker ) {
			$decoded = json_decode( (string) file_get_contents( $marker ), true );
			$job_id = is_array( $decoded ) ? (string) ( $decoded['job_id'] ?? '' ) : '';
			if ( is_array( $decoded ) && ! empty( $decoded['rollback_incomplete'] ) ) {
				continue;
			}
			if ( '' === $job_id ) {
				continue;
			}
			$job = Repository::get( $job_id );
			if ( is_array( $job ) && in_array( (string) ( $job['status'] ?? '' ), [ 'completed', 'failed', 'cancelled' ], true ) ) {
				@unlink( $marker ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				Maintenance::deactivate();
			}
		}
	}

	public static function shutdown(): void {
		$error = error_get_last();
		$fatal_types = [ E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR, E_RECOVERABLE_ERROR ];
		if ( '' === self::$armed_job_id || ! is_array( $error ) || ! in_array( (int) ( $error['type'] ?? 0 ), $fatal_types, true ) ) {
			return;
		}
		self::$emergency_reserve = '';
		$job_id = self::$armed_job_id;
		$job = Repository::get( $job_id );
		if ( ! is_array( $job ) || in_array( (string) ( $job['status'] ?? '' ), [ 'completed', 'failed', 'cancelled' ], true ) ) {
			self::disarm( $job_id );
			return;
		}
		try {
			self::fail_and_rollback( $job_id, new \RuntimeException( 'Restore critical section was interrupted by a fatal PHP error and was rolled back.' ) );
		} catch ( Throwable ) {
			// The durable journal remains on disk for the next worker if even the
			// emergency rollback cannot finish inside PHP shutdown handling.
		}
	}
}
