<?php
declare(strict_types=1);

namespace CB\Backups\CLI;

use CB\Backups\Backup\Service;
use CB\Backups\Jobs\Runner;

defined( 'ABSPATH' ) || exit;

final class Create {
	/**
	 * Create a backup and run it to completion.
	 *
	 * ## OPTIONS
	 *
	 * [<type>]
	 * : database or website. Default: website.
	 *
	 * ## EXAMPLES
	 *
	 *     wp cb backup create database
	 *     wp cb backup create website
	 */
	public function __invoke( array $args, array $assoc_args ): void {
		$type = sanitize_key( (string) ( $args[0] ?? 'website' ) );
		try {
			$job = Service::create( $type, 'cli' );
			$job_id = (string) $job['job_id'];
			\WP_CLI::line( 'Backup job: ' . $job_id );
			do {
				$result = Runner::run_budget( $job_id, 3.0 );
				$job    = $result['job'];
				if ( ! $job ) {
					\WP_CLI::error( 'Backup job disappeared.' );
				}
				\WP_CLI::line( sprintf( '%3d%%  %s', (int) $job['progress'], (string) $job['stage'] ) );
			} while ( in_array( (string) $job['status'], [ 'queued', 'running', 'cancelling' ], true ) );
			if ( 'completed' !== (string) $job['status'] ) {
				\WP_CLI::error( (string) ( $job['error_text'] ?? 'Backup failed.' ) );
			}
			\WP_CLI::success( 'Backup completed: ' . (string) $job['archive_name'] );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}
}
