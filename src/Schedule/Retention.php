<?php
declare(strict_types=1);

namespace CB\Backups\Schedule;

use CB\Backups\Storage\LocalStorage;
use CB\Backups\Support\Audit;

use Throwable;

defined( 'ABSPATH' ) || exit;

final class Retention {
	public static function apply( string $type ): void {
		try {
			$schedules = Scheduler::all();
			$keep = max( 1, (int) ( $schedules[ $type ]['keep'] ?? 10 ) );
			$matching = array_values( array_filter(
				LocalStorage::list_backups(),
				static fn ( array $backup ): bool => (string) ( $backup['backup_type'] ?? '' ) === $type
					&& 'schedule' === (string) ( $backup['trigger_source'] ?? '' )
					&& ! empty( $backup['verified'] )
			) );
			if ( count( $matching ) <= $keep ) {
				return;
			}

			$deleted = 0;
			$bytes = 0;
			foreach ( array_slice( $matching, $keep ) as $backup ) {
				$name = (string) ( $backup['archive_name'] ?? '' );
				if ( '' === $name ) {
					continue;
				}
				$size = (int) ( $backup['size'] ?? 0 );
				if ( LocalStorage::delete_archive( $name ) ) {
					++$deleted;
					$bytes += $size;
				}
			}
			if ( $deleted > 0 ) {
				Audit::log( 'backups.retention.applied', 'notice', [ 'type' => $type, 'keep' => $keep, 'deleted' => $deleted, 'bytes' => $bytes ] );
			}
		} catch ( Throwable $e ) {
			Audit::log( 'backups.retention.failed', 'warning', [ 'type' => $type, 'error' => $e->getMessage() ] );
		}
	}
}
