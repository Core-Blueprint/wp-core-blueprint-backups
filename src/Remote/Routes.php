<?php
declare(strict_types=1);

namespace CB\Backups\Remote;

use CB\Backups\Backup\Service as BackupService;
use CB\Backups\Jobs\Dispatcher;
use CB\Backups\Jobs\Repository;
use CB\Backups\Schedule\Scheduler;
use CB\Backups\Storage\LocalStorage;
use CB\Backups\Support\Audit;
use CB\Backups\Support\DownloadStreamer;
use CB\Backups\Verification\Service as VerificationService;
use CB\Beacon\Rest\RemoteRouteRegistry;
use CB\Beacon\Tickets\Service as TicketService;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

defined( 'ABSPATH' ) || exit;

final class Routes {
	private const PREFIX = '/backups';

	public static function boot(): void {
		add_action( 'cb_core_beacon_register_remote_routes', [ self::class, 'register' ], 10, 0 );
		add_filter( 'cb_core_beacon_status_extensions', [ self::class, 'status_extension' ], 10, 2 );
	}

	/** @param array<string,mixed> $extensions @return array<string,mixed> */
	public static function status_extension( array $extensions, ?WP_REST_Request $request = null ): array {
		unset( $request );
		$extensions['backups'] = [
			'plugin_version' => CB_BACKUPS_VERSION,
			'schedules'      => ScheduleResource::summary(),
		];
		return $extensions;
	}

	public static function register(): void {
		self::control_route( '/backups/status', [
			'methods'  => WP_REST_Server::READABLE,
			'callback' => [ self::class, 'status' ],
		] );

		self::control_route( '/backups', [
			'methods'  => WP_REST_Server::READABLE,
			'callback' => [ self::class, 'list_backups' ],
		] );

		self::control_route( '/backups/jobs', [
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => [ self::class, 'start' ],
			'args'     => [
				'schema_version' => [ 'required' => true, 'type' => 'integer', 'enum' => [ Contract::SCHEMA_VERSION ] ],
				'type'           => [ 'required' => true, 'type' => 'string', 'enum' => [ 'database', 'website' ] ],
			],
		] );

		self::control_route( '/backups/jobs/(?P<job_id>[a-f0-9-]{36})', [
			'methods'  => WP_REST_Server::READABLE,
			'callback' => [ self::class, 'job' ],
		] );

		self::control_route( '/backups/jobs/(?P<job_id>[a-f0-9-]{36})/cancel', [
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => [ self::class, 'cancel' ],
		] );

		self::control_route( '/backups/(?P<backup_id>[A-Za-z0-9._-]+\.cbbackup)/verify', [
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => [ self::class, 'verify' ],
		] );

		self::control_route( '/backups/schedules', [
			[
				'methods'  => WP_REST_Server::READABLE,
				'callback' => [ self::class, 'schedules' ],
			],
			[
				'methods'  => WP_REST_Server::CREATABLE,
				'callback' => [ self::class, 'update_schedules' ],
			],
		] );

		self::control_route( '/backups/schedules/(?P<type>database|website)', [
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => [ self::class, 'update_schedule_type' ],
		] );

		self::control_route( '/backups/(?P<backup_id>[A-Za-z0-9._-]+\.cbbackup)/download-ticket', [
			'methods'  => WP_REST_Server::CREATABLE,
			'callback' => [ self::class, 'download_ticket' ],
		] );

		// Browser-direct file delivery cannot carry Hub's Bearer header. The
		// callback therefore consumes Beacon's short-lived single-use ticket.
		register_rest_route( Contract::NAMESPACE, '/backups/download', [
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => [ self::class, 'download' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'ticket' => [ 'required' => true, 'type' => 'string' ],
			],
		] );
	}

	public static function status(): WP_REST_Response {
		$active_jobs = [];
		foreach ( Repository::active( 10 ) as $job ) {
			if ( JobResource::is_remote_kind( $job ) ) {
				$active_jobs[] = JobResource::from_job( $job );
			}
		}
		$backups = array_map( [ BackupResource::class, 'from_backup' ], LocalStorage::list_backups() );
		$free = @disk_free_space( LocalStorage::base_path() ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		return new WP_REST_Response( [
			'schema_version'     => Contract::SCHEMA_VERSION,
			'plugin_version'     => CB_BACKUPS_VERSION,
			'format_version'     => 1,
			'active_jobs'        => $active_jobs,
			'last_backup'        => $backups[0] ?? null,
			'backup_count'       => count( $backups ),
			'schedules'          => ScheduleResource::current(),
			'storage_free_bytes' => false === $free ? null : max( 0, (int) $free ),
		], 200 );
	}

	public static function list_backups(): WP_REST_Response {
		return new WP_REST_Response( [
			'schema_version' => Contract::SCHEMA_VERSION,
			'backups'        => array_map( [ BackupResource::class, 'from_backup' ], LocalStorage::list_backups() ),
		], 200 );
	}

	public static function start( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		if ( is_multisite() ) {
			return self::error( 'cb_backups_multisite_unsupported', 'Backup format v1 does not support multisite.', 409, false );
		}
		if ( Repository::has_active() ) {
			return self::error( 'cb_backups_busy', 'A backup, restore or verification job is already active.', 409, true );
		}

		try {
			$job = BackupService::create( sanitize_key( (string) $request->get_param( 'type' ) ), 'hub', self::actor_context( $request ) );
			return new WP_REST_Response( JobResource::from_job( $job ), 202 );
		} catch ( \Throwable ) {
			return self::error( 'cb_backups_start_failed', 'The backup job could not be started. Review the managed site logs for details.', 500, true );
		}
	}

	public static function job( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$job = Repository::get( sanitize_text_field( (string) $request['job_id'] ) );
		if ( ! is_array( $job ) || ! JobResource::is_remote_kind( $job ) ) {
			return self::error( 'cb_backups_job_not_found', 'Backup job not found.', 404, false );
		}
		return new WP_REST_Response( JobResource::from_job( $job ), 200 );
	}

	public static function cancel( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$job_id = sanitize_text_field( (string) $request['job_id'] );
		$job    = Repository::get( $job_id );
		if ( ! is_array( $job ) || 'backup' !== (string) ( $job['kind'] ?? '' ) ) {
			return self::error( 'cb_backups_job_not_found', 'Backup job not found.', 404, false );
		}

		$status = (string) ( $job['status'] ?? '' );
		if ( 'cancelling' === $status || in_array( $status, [ 'cancelled', 'completed', 'failed' ], true ) ) {
			return new WP_REST_Response( JobResource::from_job( $job ), 200 );
		}
		if ( ! Repository::request_cancel( $job_id ) ) {
			$latest = Repository::get( $job_id );
			if ( is_array( $latest ) ) {
				return new WP_REST_Response( JobResource::from_job( $latest ), 200 );
			}
			return self::error( 'cb_backups_cancel_failed', 'This backup can no longer be cancelled.', 409, false );
		}

		Dispatcher::dispatch( $job_id );
		$job = Repository::get( $job_id );
		return new WP_REST_Response( is_array( $job ) ? JobResource::from_job( $job ) : [ 'schema_version' => Contract::SCHEMA_VERSION, 'job_id' => $job_id, 'state' => 'cancelling' ], 202 );
	}

	public static function verify( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$backup_id = basename( sanitize_file_name( (string) $request['backup_id'] ) );
		if ( null === BackupResource::find( $backup_id ) ) {
			return self::error( 'cb_backups_backup_not_found', 'Backup not found.', 404, false );
		}
		if ( Repository::has_active() ) {
			return self::error( 'cb_backups_busy', 'A backup, restore or verification job is already active.', 409, true );
		}
		try {
			$job = VerificationService::create( $backup_id, 'hub', self::actor_context( $request ) );
			return new WP_REST_Response( JobResource::from_job( $job ), 202 );
		} catch ( \Throwable ) {
			return self::error( 'cb_backups_verify_start_failed', 'Backup verification could not be started. Review the managed site logs for details.', 500, true );
		}
	}

	public static function schedules(): WP_REST_Response {
		return new WP_REST_Response( ScheduleResource::current(), 200 );
	}

	public static function update_schedules( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$input = $request->get_json_params();
		if ( ! is_array( $input ) ) {
			return self::error( 'cb_backups_invalid_schedules', 'A complete schedules object is required.', 400, false );
		}
		$mapped = ScheduleResource::to_scheduler_input( $input );
		if ( is_wp_error( $mapped ) ) {
			return $mapped;
		}
		Scheduler::save( $mapped, 'hub', self::actor_context( $request ) );
		return new WP_REST_Response( ScheduleResource::current(), 200 );
	}

	public static function update_schedule_type( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$input = $request->get_json_params();
		if ( ! is_array( $input ) ) {
			return self::error( 'cb_backups_invalid_schedule', 'A backup schedule object is required.', 400, false );
		}

		$type   = sanitize_key( (string) $request['type'] );
		$mapped = ScheduleResource::to_scheduler_type_input( $type, $input );
		if ( is_wp_error( $mapped ) ) {
			return $mapped;
		}

		$context                  = self::actor_context( $request );
		$context['schedule_type'] = $type;
		Scheduler::save( $mapped, 'hub', $context );
		return new WP_REST_Response( ScheduleResource::current(), 200 );
	}

	public static function download_ticket( WP_REST_Request $request ): WP_REST_Response|WP_Error {
		$backup_id = basename( sanitize_file_name( (string) $request['backup_id'] ) );
		$backup    = BackupResource::find( $backup_id );
		if ( null === $backup ) {
			return self::error( 'cb_backups_backup_not_found', 'Backup not found.', 404, false );
		}
		$resource = BackupResource::from_backup( $backup );
		if ( empty( $resource['downloadable'] ) ) {
			return self::error( 'cb_backups_not_downloadable', 'Backup is not currently downloadable.', 409, true );
		}
		if ( ! class_exists( TicketService::class ) ) {
			return self::error( 'cb_backups_ticket_service_unavailable', 'Beacon resource tickets are unavailable.', 503, true );
		}

		try {
			$ticket = TicketService::mint( Contract::DOWNLOAD_SCOPE, $backup_id, $request, 60 );
		} catch ( \Throwable ) {
			return self::error( 'cb_backups_ticket_failed', 'A secure download ticket could not be created.', 503, true );
		}

		return new WP_REST_Response( [
			'schema_version' => Contract::SCHEMA_VERSION,
			'download_url'   => add_query_arg( 'ticket', $ticket['token'], rest_url( Contract::NAMESPACE . '/backups/download' ) ),
			'expires_at'     => gmdate( 'c', $ticket['expires_at'] ),
		], 201 );
	}

	/** Browser-direct streaming endpoint. */
	public static function download( WP_REST_Request $request ) {
		if ( ! class_exists( TicketService::class ) ) {
			return self::error( 'cb_backups_ticket_service_unavailable', 'Secure download authorization is unavailable.', 503, true );
		}
		$ticket = trim( (string) $request->get_param( 'ticket' ) );
		$claim  = TicketService::consume( $ticket, Contract::DOWNLOAD_SCOPE );
		if ( null === $claim ) {
			return self::error( 'cb_backups_download_forbidden', 'Download authorization is invalid or expired.', 403, false );
		}

		$cors = self::authorize_browser_origin( $claim );
		if ( is_wp_error( $cors ) ) {
			return $cors;
		}

		$backup_id = basename( sanitize_file_name( (string) ( $claim['resource'] ?? '' ) ) );
		$backup    = BackupResource::find( $backup_id );
		if ( null === $backup ) {
			return self::error( 'cb_backups_backup_not_found', 'Backup not found.', 404, false );
		}
		$path   = LocalStorage::archive_path( $backup_id );
		$size   = filesize( $path );
		$stream = fopen( $path, 'rb' );
		if ( false === $stream || false === $size ) {
			if ( is_resource( $stream ) ) {
				fclose( $stream );
			}
			return self::error( 'cb_backups_download_unavailable', 'Backup download stream is unavailable.', 503, true );
		}

		try {
			$sent = DownloadStreamer::send( $stream, $backup_id, 'application/zip', (int) $size );
		} finally {
			fclose( $stream );
		}
		$context = is_array( $claim['context'] ?? null ) ? $claim['context'] : [];
		Audit::log( 'backups.backup.downloaded', 'notice', [
			'archive'        => $backup_id,
			'trigger'        => 'hub',
			'bytes_sent'     => $sent,
			'expected_bytes' => (int) $size,
			'complete'       => $sent === (int) $size,
		] + $context );
		exit;
	}

	/**
	 * Permit cross-origin Fetch streaming only from the authenticated Hub
	 * browser origin stored inside the single-use Beacon ticket. Ordinary
	 * browser navigation downloads have no Origin header and remain supported.
	 *
	 * @param array{resource?:string,context?:array<string,mixed>} $claim
	 */
	private static function authorize_browser_origin( array $claim ): true|WP_Error {
		$origin = isset( $_SERVER['HTTP_ORIGIN'] )
			? trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) )
			: '';
		if ( '' === $origin ) {
			return true;
		}

		$context  = is_array( $claim['context'] ?? null ) ? $claim['context'] : [];
		$expected = self::normalize_origin( (string) ( $context['browser_origin'] ?? '' ) );
		$actual   = self::normalize_origin( $origin );
		if ( '' === $expected || '' === $actual || ! hash_equals( $expected, $actual ) ) {
			return self::error( 'cb_backups_download_origin_forbidden', 'Download authorization is not valid for this browser origin.', 403, false );
		}

		// WordPress REST may have emitted permissive CORS headers earlier in
		// the request. Replace them with the exact Hub origin bound to the ticket.
		header_remove( 'Access-Control-Allow-Origin' );
		header_remove( 'Access-Control-Allow-Credentials' );
		header( 'Access-Control-Allow-Origin: ' . $expected );
		header( 'Access-Control-Expose-Headers: Content-Length, Content-Type' );
		header( 'Vary: Origin', false );
		return true;
	}

	private static function normalize_origin( string $origin ): string {
		$origin = trim( $origin );
		if ( '' === $origin || 'null' === strtolower( $origin ) ) {
			return '';
		}
		$parts = wp_parse_url( $origin );
		if ( ! is_array( $parts ) ) {
			return '';
		}
		$scheme = strtolower( (string) ( $parts['scheme'] ?? '' ) );
		$host   = strtolower( (string) ( $parts['host'] ?? '' ) );
		if ( ! in_array( $scheme, [ 'http', 'https' ], true ) || '' === $host ) {
			return '';
		}
		$port         = isset( $parts['port'] ) ? (int) $parts['port'] : 0;
		$default_port = ( 'https' === $scheme ) ? 443 : 80;
		return $scheme . '://' . $host . ( $port > 0 && $port !== $default_port ? ':' . $port : '' );
	}

	/** @param array<string,mixed> $args */
	private static function control_route( string $route, array $args ): void {
		if ( class_exists( RemoteRouteRegistry::class ) ) {
			RemoteRouteRegistry::register( $route, $args, self::PREFIX );
		}
	}

	/** @return array<string,string> */
	private static function actor_context( WP_REST_Request $request ): array {
		$headers = [
			'actor'        => 'x-cb-hub-actor',
			'actor_type'   => 'x-cb-hub-actor-type',
			'actor_role'   => 'x-cb-hub-actor-role',
			'hub_origin'   => 'x-cb-hub-origin',
			'initiator'    => 'x-cb-hub-initiator',
			'initiator_id' => 'x-cb-hub-initiator-id',
			'run_id'       => 'x-cb-hub-run-id',
		];
		$context = [];
		foreach ( $headers as $key => $header ) {
			$value = trim( sanitize_text_field( (string) $request->get_header( $header ) ) );
			if ( '' !== $value ) {
				$context[ $key ] = substr( $value, 0, 255 );
			}
		}
		return $context;
	}

	private static function error( string $code, string $message, int $status, bool $retryable ): WP_Error {
		return new WP_Error( $code, $message, [ 'status' => $status, 'retryable' => $retryable ] );
	}
}
