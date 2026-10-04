<?php
declare(strict_types=1);

namespace CB\Backups\Admin;

use CB\Backups\Backup\Service as BackupService;
use CB\Backups\Import\ChunkedUploader;
use CB\Backups\Jobs\Dispatcher;
use CB\Backups\Jobs\Repository;
use CB\Backups\Restore\ArchiveValidator;
use CB\Backups\Restore\Service as RestoreService;
use CB\Backups\Schedule\Scheduler;
use CB\Backups\Storage\LocalStorage;
use CB\Backups\Support\Audit;
use CB\Backups\Support\DownloadStreamer;
use CB\Backups\Support\Telemetry;
use ZipArchive;
use CB\Backups\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.WP.AlternativeFunctions -- Backup processing uses bounded native streams/atomic filesystem primitives; WP_Filesystem is not suitable for these server-owned jobs.

final class Actions {
	private const PAGE = 'core-blueprint-backups';

	public static function boot(): void {
		add_action( 'admin_post_cb_backups_start', [ self::class, 'start' ] );
		add_action( 'admin_post_cb_backups_upload_restore', [ self::class, 'upload_restore' ] );
		add_action( 'admin_post_cb_backups_restore', [ self::class, 'restore' ] );
		add_action( 'admin_post_cb_backups_download', [ self::class, 'download' ] );
		add_action( 'admin_post_cb_backups_delete', [ self::class, 'delete' ] );
		add_action( 'admin_post_cb_backups_bulk_delete', [ self::class, 'bulk_delete' ] );
		add_action( 'admin_post_cb_backups_verify', [ self::class, 'verify' ] );
		add_action( 'admin_post_cb_backups_save_schedules', [ self::class, 'save_schedules' ] );
		add_action( 'wp_ajax_cb_backups_cancel_job', [ self::class, 'cancel_job' ] );
		add_action( 'wp_ajax_cb_backups_import_init', [ self::class, 'import_init' ] );
		add_action( 'wp_ajax_cb_backups_import_chunk', [ self::class, 'import_chunk' ] );
		add_action( 'wp_ajax_cb_backups_import_finish', [ self::class, 'import_finish' ] );
		add_action( 'wp_ajax_cb_backups_import_abort', [ self::class, 'import_abort' ] );
		add_action( 'admin_post_cb_backups_restore_import', [ self::class, 'restore_import' ] );
		add_action( 'admin_post_cb_backups_delete_import', [ self::class, 'delete_import' ] );
	}

	public static function start(): void {
		self::authorize();
		check_admin_referer( 'cb_backups_start' );
		$type = sanitize_key( (string) ( isset( $_POST['backup_type'] ) ? wp_unslash( $_POST['backup_type'] ) : '' ) );
		try {
			$job = BackupService::create( $type, 'manual' );
			self::redirect( [ 'job' => (string) $job['job_id'], 'cb_notice' => 'backup_started' ] );
		} catch ( \Throwable $e ) {
			self::redirect( [ 'cb_error' => $e->getMessage() ] );
		}
	}

	public static function upload_restore(): void {
		self::authorize();
		check_admin_referer( 'cb_backups_upload_restore' );
		$file = $_FILES['backup_file'] ?? null;
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || empty( $file['tmp_name'] ) ) {
			self::redirect( [ 'tab' => 'restore', 'cb_error' => 'Backup upload failed.' ] );
		}
		$name = sanitize_file_name( (string) ( $file['name'] ?? '' ) );
		if ( ! str_ends_with( strtolower( $name ), '.cbbackup' ) ) {
			self::redirect( [ 'tab' => 'restore', 'cb_error' => 'Only .cbbackup files can be imported.' ] );
		}
		LocalStorage::ensure();
		$archive_name = 'import-' . wp_generate_uuid4() . '.cbbackup';
		$target = LocalStorage::import_path( $archive_name );
		// phpcs:ignore Generic.PHP.ForbiddenFunctions.Found -- move_uploaded_file() preserves PHP upload provenance before archive validation.
		if ( ! is_uploaded_file( (string) $file['tmp_name'] ) || ! move_uploaded_file( (string) $file['tmp_name'], $target ) ) {
			self::redirect( [ 'tab' => 'restore', 'cb_error' => 'Uploaded backup could not be moved into private storage.' ] );
		}
		try {
			$manifest = ArchiveValidator::validate( $target, false );
			$database = is_array( $manifest['database'] ?? null ) ? $manifest['database'] : [];
			$filesystem = is_array( $manifest['filesystem'] ?? null ) ? $manifest['filesystem'] : [];
			$meta = [
				'archive_name'      => $archive_name,
				'original_name'     => $name,
				'created_timestamp' => time(),
				'backup_type'       => (string) ( $manifest['backup_type'] ?? 'database' ),
				'database_rows'     => (int) ( $database['row_count'] ?? 0 ),
				'database_tables'   => isset( $database['tables'] ) && is_array( $database['tables'] ) ? count( $database['tables'] ) : 0,
				'files'             => (int) ( $filesystem['file_count'] ?? 0 ),
				'filesystem_bytes'  => (int) ( $filesystem['bytes'] ?? 0 ),
				'site_url'          => (string) ( $manifest['site']['site_url'] ?? '' ),
				'size'              => filesize( $target ) ?: 0,
				'prepared'          => true,
			];
			LocalStorage::write_import_meta( $archive_name, $meta );
			Audit::log( 'backups.import.prepared', 'notice', [ 'archive' => $archive_name, 'type' => (string) $meta['backup_type'], 'size' => (int) $meta['size'] ] );
			self::redirect( [ 'tab' => 'restore', 'cb_notice' => 'import_prepared' ] );
		} catch ( \Throwable $e ) {
			wp_delete_file( $target );
			wp_delete_file( $target . '.json' );
			self::redirect( [ 'tab' => 'restore', 'cb_error' => $e->getMessage() ] );
		}
	}

	public static function import_init(): void {
		self::authorize();
		check_ajax_referer( 'cb_backups_admin', 'nonce' );
		try {
			$name = sanitize_file_name( (string) ( isset( $_POST['name'] ) ? wp_unslash( $_POST['name'] ) : '' ) );
			$size = max( 0, (int) ( isset( $_POST['size'] ) ? wp_unslash( $_POST['size'] ) : 0 ) );
			$last_modified = max( 0, (int) ( isset( $_POST['last_modified'] ) ? wp_unslash( $_POST['last_modified'] ) : 0 ) );
			$upload_id = sanitize_text_field( (string) ( isset( $_POST['upload_id'] ) ? wp_unslash( $_POST['upload_id'] ) : '' ) );
			wp_send_json_success( ChunkedUploader::initialise( $name, $size, $last_modified, $upload_id ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 400 );
		}
	}

	public static function import_chunk(): void {
		self::authorize();
		check_ajax_referer( 'cb_backups_admin', 'nonce' );
		try {
			$upload_id = sanitize_text_field( (string) ( isset( $_POST['upload_id'] ) ? wp_unslash( $_POST['upload_id'] ) : '' ) );
			$offset = max( 0, (int) ( isset( $_POST['offset'] ) ? wp_unslash( $_POST['offset'] ) : 0 ) );
			$file = $_FILES['chunk'] ?? null;
			if ( ! is_array( $file ) || UPLOAD_ERR_OK !== (int) ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) || empty( $file['tmp_name'] ) || ! is_uploaded_file( (string) $file['tmp_name'] ) ) {
				throw new \RuntimeException( 'Import chunk upload failed.' );
			}
			wp_send_json_success( ChunkedUploader::append_chunk( $upload_id, $offset, (string) $file['tmp_name'] ) );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 400 );
		}
	}

	public static function import_finish(): void {
		self::authorize();
		check_ajax_referer( 'cb_backups_admin', 'nonce' );
		try {
			$upload_id = sanitize_text_field( (string) ( isset( $_POST['upload_id'] ) ? wp_unslash( $_POST['upload_id'] ) : '' ) );
			$meta = ChunkedUploader::finish( $upload_id );
			Audit::log( 'backups.import.prepared', 'notice', [
				'archive' => (string) ( $meta['archive_name'] ?? '' ),
				'type'    => (string) ( $meta['backup_type'] ?? '' ),
				'size'    => (int) ( $meta['size'] ?? 0 ),
			] );
			wp_send_json_success( $meta );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 400 );
		}
	}

	public static function import_abort(): void {
		self::authorize();
		check_ajax_referer( 'cb_backups_admin', 'nonce' );
		try {
			$upload_id = sanitize_text_field( (string) ( isset( $_POST['upload_id'] ) ? wp_unslash( $_POST['upload_id'] ) : '' ) );
			ChunkedUploader::abort( $upload_id );
			Audit::log( 'backups.import.cancelled', 'notice', [ 'upload_id' => $upload_id ] );
			wp_send_json_success( [ 'cancelled' => true ] );
		} catch ( \Throwable $e ) {
			wp_send_json_error( [ 'message' => $e->getMessage() ], 400 );
		}
	}

	public static function restore_import(): void {
		self::authorize();
		$archive = sanitize_file_name( (string) ( isset( $_POST['archive'] ) ? wp_unslash( $_POST['archive'] ) : '' ) );
		check_admin_referer( 'cb_backups_restore_import_' . $archive );
		try {
			$job = RestoreService::create( LocalStorage::import_path( $archive ), 'manual_import', '1' === ( $_POST['restore_acknowledged'] ?? null ) );
			self::redirect( [ 'tab' => 'restore', 'job' => (string) $job['job_id'], 'cb_notice' => 'restore_started' ] );
		} catch ( \Throwable $e ) {
			self::redirect( [ 'tab' => 'restore', 'cb_error' => $e->getMessage() ] );
		}
	}

	public static function delete_import(): void {
		self::authorize();
		$archive = sanitize_file_name( (string) ( isset( $_POST['archive'] ) ? wp_unslash( $_POST['archive'] ) : '' ) );
		check_admin_referer( 'cb_backups_delete_import_' . $archive );
		if ( LocalStorage::delete_import( $archive ) ) {
			Audit::log( 'backups.import.deleted', 'warning', [ 'archive' => $archive ] );
			self::redirect( [ 'tab' => 'restore', 'cb_notice' => 'import_deleted' ] );
		}
		self::redirect( [ 'tab' => 'restore', 'cb_error' => 'Prepared import could not be deleted.' ] );
	}

	public static function restore(): void {
		self::authorize();
		$archive = sanitize_file_name( (string) ( isset( $_POST['archive'] ) ? wp_unslash( $_POST['archive'] ) : '' ) );
		check_admin_referer( 'cb_backups_restore_' . $archive );
		try {
			$job = RestoreService::create( LocalStorage::archive_path( $archive ), 'manual', '1' === ( $_POST['restore_acknowledged'] ?? null ) );
			self::redirect( [ 'tab' => 'restore', 'job' => (string) $job['job_id'], 'cb_notice' => 'restore_started' ] );
		} catch ( \Throwable $e ) {
			self::redirect( [ 'tab' => 'restore', 'cb_error' => $e->getMessage() ] );
		}
	}

	public static function download(): void {
		self::authorize();
		$archive = sanitize_file_name( (string) ( isset( $_GET['archive'] ) ? wp_unslash( $_GET['archive'] ) : '' ) );
		$part    = sanitize_key( (string) ( isset( $_GET['part'] ) ? wp_unslash( $_GET['part'] ) : 'archive' ) );
		check_admin_referer( 'cb_backups_download_' . $archive );
		$path = LocalStorage::archive_path( $archive );
		if ( ! is_file( $path ) ) {
			wp_die( esc_html__( 'Backup file not found.', 'core-blueprint-backups' ) );
		}
		Audit::log( 'backups.backup.downloaded', 'notice', [ 'archive' => $archive, 'part' => $part ] );
		if ( 'database' === $part ) {
			$zip = new ZipArchive();
			if ( true !== $zip->open( $path ) ) {
				wp_die( esc_html__( 'Backup archive could not be opened.', 'core-blueprint-backups' ) );
			}
			$stat   = $zip->statName( 'database/database.sql' );
			$stream = $zip->getStream( 'database/database.sql' );
			if ( false === $stream ) {
				$zip->close();
				wp_die( esc_html__( 'Database export is missing from this backup.', 'core-blueprint-backups' ) );
			}
			$filename = sanitize_file_name( (string) preg_replace( '/\.cbbackup$/i', '', $archive ) . '.sql' );
			$length = is_array( $stat ) && isset( $stat['size'] ) ? (int) $stat['size'] : null;
			try {
				DownloadStreamer::send( $stream, $filename, 'application/sql; charset=utf-8', $length );
			} finally {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- ZipArchive returns a native stream resource.
				fclose( $stream );
				$zip->close();
			}
			exit;
		}

		$size = filesize( $path );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Downloads are streamed to avoid loading backup archives into memory.
		$stream = fopen( $path, 'rb' );
		if ( false === $stream ) {
			wp_die( esc_html__( 'Backup archive could not be opened for download.', 'core-blueprint-backups' ) );
		}
		try {
			DownloadStreamer::send( $stream, $archive, 'application/octet-stream', false === $size ? null : (int) $size );
		} finally {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Native download stream cleanup.
			fclose( $stream );
		}
		exit;
	}

	public static function delete(): void {
		self::authorize();
		$archive = sanitize_file_name( (string) ( isset( $_POST['archive'] ) ? wp_unslash( $_POST['archive'] ) : '' ) );
		check_admin_referer( 'cb_backups_delete_' . $archive );
		if ( LocalStorage::delete_archive( $archive ) ) {
			Audit::log( 'backups.backup.deleted', 'warning', [ 'archive' => $archive ] );
			self::redirect( [ 'cb_notice' => 'backup_deleted' ] );
		}
		self::redirect( [ 'cb_error' => 'Backup file could not be deleted.' ] );
	}


	public static function bulk_delete(): void {
		self::authorize();
		check_admin_referer( 'cb_backups_bulk_delete' );

		$raw = isset( $_POST['archives'] ) && is_array( $_POST['archives'] ) ? wp_unslash( $_POST['archives'] ) : [];
		$archives = [];
		foreach ( $raw as $archive ) {
			$name = sanitize_file_name( (string) $archive );
			if ( '' !== $name && str_ends_with( strtolower( $name ), '.cbbackup' ) ) {
				$archives[ $name ] = $name;
			}
		}
		$archives = array_values( $archives );

		if ( ! $archives ) {
			self::redirect( [ 'cb_error' => __( 'Select at least one backup to delete.', 'core-blueprint-backups' ) ] );
		}

		$deleted = 0;
		$failed  = 0;
		foreach ( $archives as $archive ) {
			if ( LocalStorage::delete_archive( $archive ) ) {
				++$deleted;
			} else {
				++$failed;
			}
		}

		Audit::log( 'backups.backup.bulk.deleted', 'warning', [
			'requested_count' => count( $archives ),
			'deleted_count'   => $deleted,
			'failed_count'    => $failed,
		] );

		if ( $failed > 0 ) {
			$message = sprintf(
				/* translators: 1: deleted backup count, 2: failed backup count. */
				__( '%1$d backups deleted; %2$d could not be deleted.', 'core-blueprint-backups' ),
				$deleted,
				$failed
			);
			self::redirect( [ 'cb_error' => $message ] );
		}

		self::redirect( [ 'cb_notice' => 'backups_bulk_deleted', 'deleted' => $deleted ] );
	}

	public static function verify(): void {
		self::authorize();
		$archive = sanitize_file_name( (string) ( isset( $_POST['archive'] ) ? wp_unslash( $_POST['archive'] ) : '' ) );
		check_admin_referer( 'cb_backups_verify_' . $archive );
		try {
			ArchiveValidator::validate( LocalStorage::archive_path( $archive ), true );
			Audit::log( 'backups.backup.verified', 'notice', [ 'archive' => $archive ] );
			self::redirect( [ 'cb_notice' => 'backup_verified' ] );
		} catch ( \Throwable $e ) {
			self::redirect( [ 'cb_error' => $e->getMessage() ] );
		}
	}

	public static function save_schedules(): void {
		self::authorize();
		check_admin_referer( 'cb_backups_save_schedules' );
		$input = isset( $_POST['schedules'] ) && is_array( $_POST['schedules'] ) ? wp_unslash( $_POST['schedules'] ) : [];
		Scheduler::save( $input );
		self::redirect( [ 'tab' => 'schedules', 'cb_notice' => 'schedules_saved' ] );
	}

	public static function cancel_job(): void {
		self::authorize();
		check_ajax_referer( 'cb_backups_admin', 'nonce' );
		$job_id = sanitize_text_field( (string) ( isset( $_POST['job_id'] ) ? wp_unslash( $_POST['job_id'] ) : '' ) );
		if ( ! Repository::request_cancel( $job_id ) ) {
			wp_send_json_error( [ 'message' => 'This backup can no longer be cancelled.' ], 409 );
		}
		Dispatcher::dispatch( $job_id );
		$job = Repository::get( $job_id );
		wp_send_json_success( is_array( $job ) ? self::public_job( $job ) : [ 'job_id' => $job_id, 'status' => 'cancelling' ] );
	}

	/** @param array<string,mixed> $job @return array<string,mixed> */
	public static function public_job( array $job ): array {
		$meta      = is_array( $job['meta'] ?? null ) ? $job['meta'] : [];
		$type      = (string) $job['backup_type'];
		$status    = (string) $job['status'];
		$size      = (int) ( $meta['archive_size'] ?? $meta['archive_bytes'] ?? $meta['db_export_bytes'] ?? 0 );
		$rows_done = (int) ( $meta['db_rows_done'] ?? 0 );
		$rows_exact = (int) ( $meta['db_rows_total'] ?? 0 );
		$rows_estimated = (int) ( $meta['db_rows_estimated_total'] ?? 0 );
		$tables_done = (int) ( $meta['db_tables_done'] ?? 0 );
		$tables_total = (int) ( $meta['db_tables_total'] ?? 0 );
		$duration = isset( $meta['duration_seconds'] ) ? (int) $meta['duration_seconds'] : null;
		$files_done = (int) ( $meta['files_done'] ?? 0 );
		$files_total = (int) ( $meta['files_total'] ?? 0 );
		$files_bytes_done = (int) ( $meta['files_bytes_done'] ?? 0 );
		$files_bytes_total = (int) ( $meta['files_bytes_total'] ?? 0 );
		$current_file = (string) ( $meta['files_current_file'] ?? '' );
		if ( '' === $current_file ) {
			$current_file = (string) ( $meta['verify_current_file'] ?? '' );
		}

		if ( 'restore' === (string) $job['kind'] ) {
			$manifest = is_array( $meta['manifest'] ?? null ) ? $meta['manifest'] : [];
			$database = is_array( $manifest['database'] ?? null ) ? $manifest['database'] : [];
			$manifest_tables = isset( $database['tables'] ) && is_array( $database['tables'] ) ? $database['tables'] : [];
			$tables_total = count( $manifest_tables );
			$tables_done  = min( $tables_total, max( 0, (int) ( $meta['db_restore_table_index'] ?? 0 ) ) );
			$rows_exact   = max( 0, (int) ( $database['row_count'] ?? 0 ) );
			$filesystem = is_array( $manifest['filesystem'] ?? null ) ? $manifest['filesystem'] : [];
			$files_total = max( $files_total, (int) ( $filesystem['file_count'] ?? 0 ) );
			$files_bytes_total = max( $files_bytes_total, (int) ( $filesystem['bytes'] ?? 0 ) );
			$files_done = max( $files_done, (int) ( $meta['restore_extract_files_done'] ?? 0 ) );
			$files_bytes_done = max( $files_bytes_done, (int) ( $meta['restore_extract_bytes_done'] ?? 0 ) );
			$current_file = (string) ( $meta['live_verify_current_file'] ?? '' );
			if ( '' === $current_file ) {
				$current_file = (string) ( $meta['restore_verify_current_file'] ?? '' );
			}
			if ( '' === $current_file ) {
				$current_file = (string) ( $meta['restore_extract_current_file'] ?? '' );
			}
			if ( '' === $current_file ) {
				$current_file = (string) ( $meta['stage_verify_current_file'] ?? '' );
			}
			if ( '' === $current_file ) {
				$current_file = (string) ( $meta['fs_current_item'] ?? '' );
			}
			$current_path = (string) ( $meta['archive_path'] ?? '' );
			if ( '' !== $current_path && is_file( $current_path ) ) {
				$size = filesize( $current_path ) ?: $size;
			}
		}

		// Once a backup is completed, its verified sidecar metadata is the
		// canonical immutable summary. Use it to fill any transient job fields
		// that may no longer be present after the worker has finalized/cleaned up.
		if ( 'completed' === $status && 'backup' === (string) $job['kind'] && ! empty( $job['archive_name'] ) ) {
			$archive_name = (string) $job['archive_name'];
			foreach ( LocalStorage::list_backups() as $backup ) {
				if ( (string) ( $backup['archive_name'] ?? '' ) !== $archive_name ) {
					continue;
				}
				$size         = (int) ( $backup['size'] ?? $size );
				$rows_exact   = (int) ( $backup['database_rows'] ?? $rows_exact );
				$rows_done    = max( $rows_done, $rows_exact );
				$tables_total = (int) ( $backup['database_tables'] ?? $tables_total );
				$tables_done  = max( $tables_done, $tables_total );
				$duration     = (int) ( $backup['duration_seconds'] ?? $duration ?? 0 );
				$files_total = (int) ( $backup['files'] ?? $files_total );
				$files_done = max( $files_done, $files_total );
				$files_bytes_total = (int) ( $backup['filesystem_bytes'] ?? $files_bytes_total );
				$files_bytes_done = max( $files_bytes_done, $files_bytes_total );
				break;
			}
		}

		return [
			'job_id'                       => (string) $job['job_id'],
			'kind'                         => (string) $job['kind'],
			'backup_type'                  => $type,
			'status'                       => $status,
			'stage'                        => (string) $job['stage'],
			'progress'                     => (int) $job['progress'],
			'error'                        => (string) ( $job['error_text'] ?? '' ),
			'archive'                      => (string) ( $job['archive_name'] ?? '' ),
			'elapsed_seconds'              => null !== $duration ? $duration : Telemetry::elapsed_seconds( $job ),
			'duration_seconds'             => $duration,
			'typical_duration_seconds'     => 'backup' === (string) $job['kind'] ? ( isset( $meta['typical_duration_seconds'] ) && null !== $meta['typical_duration_seconds'] ? (int) $meta['typical_duration_seconds'] : Telemetry::median_duration( $type ) ) : null,
			'database_rows_done'           => $rows_done,
			'database_rows_total'          => $rows_exact,
			'database_rows_estimated'      => $rows_estimated,
			'database_tables_done'         => $tables_done,
			'database_tables_total'        => $tables_total,
			'current_table'                => (string) ( $meta['db_current_table'] ?? '' ),
			'current_table_rows_done'      => (int) ( $meta['db_current_table_rows_done'] ?? 0 ),
			'current_table_rows_estimated' => (int) ( $meta['db_current_table_rows_estimated'] ?? 0 ),
			'files_done'                   => $files_done,
			'files_total'                  => $files_total,
			'files_bytes_done'             => $files_bytes_done,
			'files_bytes_total'            => $files_bytes_total,
			'package_files_per_second'     => (float) ( $meta['package_files_per_second'] ?? 0 ),
			'package_bytes_per_second'     => (int) ( $meta['package_bytes_per_second'] ?? 0 ),
			'current_file'                 => $current_file,
			'output_bytes'                 => $size,
			'row_estimate_is_approximate'  => $rows_exact <= 0 && $rows_estimated > 0,
		];
	}

	private static function authorize(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to manage backups.', 'core-blueprint-backups' ), 403 );
		}
	}

	/** @param array<string,string> $args */
	private static function redirect( array $args = [] ): never {
		$url = add_query_arg( array_merge( [ 'page' => self::PAGE ], $args ), admin_url( 'admin.php' ) );
		wp_safe_redirect( $url );
		exit;
	}
}
