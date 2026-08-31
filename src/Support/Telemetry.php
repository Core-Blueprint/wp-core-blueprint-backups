<?php
declare(strict_types=1);

namespace CB\Backups\Support;

use CB\Backups\Storage\LocalStorage;

defined( 'ABSPATH' ) || exit;

final class Telemetry {
	/** @param array<string,mixed> $job */
	public static function started_timestamp( array $job ): int {
		$meta = is_array( $job['meta'] ?? null ) ? $job['meta'] : [];
		$timestamp = (int) ( $meta['started_timestamp'] ?? 0 );
		if ( $timestamp > 0 ) {
			return $timestamp;
		}
		$created = isset( $job['created_at'] ) ? strtotime( (string) $job['created_at'] . ' UTC' ) : false;
		return false !== $created ? (int) $created : time();
	}

	/** @param array<string,mixed> $job */
	public static function elapsed_seconds( array $job ): int {
		$started = self::started_timestamp( $job );
		$meta    = is_array( $job['meta'] ?? null ) ? $job['meta'] : [];
		if ( isset( $meta['duration_seconds'] ) ) {
			return max( 0, (int) $meta['duration_seconds'] );
		}
		$completed = isset( $job['completed_at'] ) && $job['completed_at'] ? strtotime( (string) $job['completed_at'] . ' UTC' ) : false;
		$end       = false !== $completed ? (int) $completed : time();
		return max( 0, $end - $started );
	}

	public static function median_duration( string $type, int $limit = 10 ): ?int {
		$durations = [];
		foreach ( LocalStorage::list_backups() as $backup ) {
			if ( (string) ( $backup['backup_type'] ?? '' ) !== $type ) {
				continue;
			}
			$duration = (int) ( $backup['duration_seconds'] ?? 0 );
			if ( $duration <= 0 ) {
				continue;
			}
			$durations[] = $duration;
			if ( count( $durations ) >= max( 2, $limit ) ) {
				break;
			}
		}
		if ( count( $durations ) < 2 ) {
			return null;
		}
		sort( $durations, SORT_NUMERIC );
		$count = count( $durations );
		$mid   = intdiv( $count, 2 );
		if ( 1 === $count % 2 ) {
			return $durations[ $mid ];
		}
		return (int) round( ( $durations[ $mid - 1 ] + $durations[ $mid ] ) / 2 );
	}

	public static function format_duration( int $seconds ): string {
		$seconds = max( 0, $seconds );
		$hours   = intdiv( $seconds, 3600 );
		$minutes = intdiv( $seconds % 3600, 60 );
		$secs    = $seconds % 60;
		if ( $hours > 0 ) {
			return sprintf( '%d:%02d:%02d', $hours, $minutes, $secs );
		}
		return sprintf( '%02d:%02d', $minutes, $secs );
	}
}
