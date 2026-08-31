<?php
declare(strict_types=1);

namespace CB\Backups\Support;

use CB\Backups\Jobs\Repository;
use CB\Backups\Storage\LocalStorage;

final class Housekeeping {
	private const OPTION_LAST_RUN = 'cb_backups_housekeeping_last_run';
	private const INTERVAL = DAY_IN_SECONDS;
	private const GRACE = DAY_IN_SECONDS;

	public static function maybe_run(): void {
		$now  = time();
		$last = (int) get_option( self::OPTION_LAST_RUN, 0 );
		if ( $last > 0 && ( $now - $last ) < self::INTERVAL ) {
			return;
		}

		// Claim the daily slot before filesystem work so concurrent cron requests
		// cannot run housekeeping at the same time.
		update_option( self::OPTION_LAST_RUN, $now, false );
		$result = self::run( $now, self::GRACE );
		if ( $result['work_dirs'] > 0 || $result['imports'] > 0 || $result['upload_sessions'] > 0 ) {
			Audit::log( 'backups.housekeeping.cleanup', 'notice', $result );
		}
	}

	/** @return array{work_dirs:int,imports:int,upload_sessions:int} */
	public static function run( ?int $now = null, int $grace = self::GRACE ): array {
		LocalStorage::ensure();
		$now   = $now ?? time();
		$grace = max( HOUR_IN_SECONDS, $grace );
		return [
			'work_dirs'       => self::cleanup_work_dirs( $now, $grace ),
			'imports'         => self::cleanup_imports( $now, $grace ),
			'upload_sessions' => self::cleanup_upload_sessions( $now, $grace ),
		];
	}

	private static function cleanup_work_dirs( int $now, int $grace ): int {
		$root  = LocalStorage::base_path() . '/.work';
		$items = is_dir( $root ) ? scandir( $root ) : false;
		if ( ! is_array( $items ) ) {
			return 0;
		}

		$removed = 0;
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			$path = wp_normalize_path( $root . '/' . $item );
			if ( ! is_dir( $path ) || is_link( $path ) ) {
				continue;
			}

			$job = Repository::get( $item );
			if ( is_array( $job ) && in_array( (string) ( $job['status'] ?? '' ), [ 'queued', 'running', 'cancelling' ], true ) ) {
				continue;
			}

			$reference = self::job_reference_timestamp( $job );
			if ( $reference <= 0 ) {
				$mtime = filemtime( $path );
				$reference = false === $mtime ? $now : (int) $mtime;
			}
			if ( ( $now - $reference ) < $grace ) {
				continue;
			}

			LocalStorage::remove_tree( $path );
			if ( ! file_exists( $path ) ) {
				++$removed;
			}
		}
		return $removed;
	}

	private static function cleanup_imports( int $now, int $grace ): int {
		$root = LocalStorage::base_path() . '/imports';
		$active_paths = [];
		foreach ( Repository::active( 50 ) as $job ) {
			if ( 'restore' !== (string) ( $job['kind'] ?? '' ) ) {
				continue;
			}
			$meta = is_array( $job['meta'] ?? null ) ? $job['meta'] : [];
			$path = wp_normalize_path( (string) ( $meta['archive_path'] ?? '' ) );
			if ( '' !== $path ) {
				$active_paths[ $path ] = true;
			}
		}

		$removed = 0;
		$files = glob( $root . '/*.cbbackup' ) ?: [];
		foreach ( $files as $file ) {
			$file = wp_normalize_path( $file );
			if ( ! is_file( $file ) || isset( $active_paths[ $file ] ) ) {
				continue;
			}
			$mtime = filemtime( $file );
			if ( false === $mtime || ( $now - (int) $mtime ) < $grace ) {
				continue;
			}
			if ( LocalStorage::delete_import( basename( $file ) ) ) {
				++$removed;
			}
		}
		return $removed;
	}


	private static function cleanup_upload_sessions( int $now, int $grace ): int {
		$root = LocalStorage::import_upload_root();
		$dirs = is_dir( $root ) ? ( glob( $root . '/*', GLOB_ONLYDIR ) ?: [] ) : [];
		$removed = 0;
		foreach ( $dirs as $dir ) {
			$state_file = $dir . '/state.json';
			$reference = 0;
			if ( is_file( $state_file ) ) {
				$decoded = json_decode( (string) file_get_contents( $state_file ), true );
				if ( is_array( $decoded ) ) {
					$reference = (int) ( $decoded['updated_at'] ?? $decoded['created_at'] ?? 0 );
				}
			}
			if ( $reference <= 0 ) {
				$mtime = filemtime( $dir );
				$reference = false === $mtime ? $now : (int) $mtime;
			}
			if ( ( $now - $reference ) < $grace ) {
				continue;
			}
			LocalStorage::remove_tree( $dir );
			if ( ! file_exists( $dir ) ) {
				++$removed;
			}
		}
		return $removed;
	}

	/** @param array<string,mixed>|null $job */
	private static function job_reference_timestamp( ?array $job ): int {
		if ( ! is_array( $job ) ) {
			return 0;
		}
		foreach ( [ 'completed_at', 'updated_at', 'created_at' ] as $field ) {
			$value = trim( (string) ( $job[ $field ] ?? '' ) );
			if ( '' === $value ) {
				continue;
			}
			$timestamp = strtotime( $value . ' UTC' );
			if ( false !== $timestamp ) {
				return $timestamp;
			}
		}
		return 0;
	}
}
