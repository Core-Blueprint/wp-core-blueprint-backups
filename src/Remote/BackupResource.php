<?php
declare(strict_types=1);

namespace CB\Backups\Remote;

use CB\Backups\Storage\LocalStorage;

defined( 'ABSPATH' ) || exit;

final class BackupResource {
	/** @param array<string,mixed> $backup @return array<string,mixed> */
	public static function from_backup( array $backup ): array {
		$id       = (string) ( $backup['archive_name'] ?? '' );
		$trigger  = sanitize_key( (string) ( $backup['trigger_source'] ?? '' ) );
		$verified = ! empty( $backup['verified'] );
		$verified_at = (string) ( $backup['verified_at'] ?? '' );
		if ( $verified && '' === $verified_at ) {
			$verified_at = (string) ( $backup['completed_at'] ?? '' );
		}

		return [
			'schema_version'    => Contract::SCHEMA_VERSION,
			'id'                => $id,
			'type'              => in_array( (string) ( $backup['backup_type'] ?? '' ), [ 'database', 'website' ], true ) ? (string) $backup['backup_type'] : 'database',
			'created_at'        => self::nullable_string( $backup['created_at'] ?? null ),
			'completed_at'      => self::nullable_string( $backup['completed_at'] ?? null ),
			'size_bytes'        => max( 0, (int) ( $backup['size'] ?? 0 ) ),
			'integrity'         => $verified ? 'verified' : 'unverified',
			'verified'          => $verified,
			'verified_at'       => $verified && '' !== $verified_at ? $verified_at : null,
			'origin'            => 'schedule' === $trigger ? 'automatic' : 'manual',
			'source'            => self::source( $trigger ),
			'job_id'            => self::nullable_string( $backup['job_id'] ?? null ),
			'duration_seconds'  => max( 0, (int) ( $backup['duration_seconds'] ?? 0 ) ),
			'downloadable'      => '' !== $id && is_file( LocalStorage::archive_path( $id ) ) && is_readable( LocalStorage::archive_path( $id ) ),
		];
	}

	/** @return array<string,mixed>|null */
	public static function find( string $backup_id ): ?array {
		$backup_id = basename( sanitize_file_name( $backup_id ) );
		if ( '' === $backup_id || ! str_ends_with( strtolower( $backup_id ), '.cbbackup' ) ) {
			return null;
		}
		foreach ( LocalStorage::list_backups() as $backup ) {
			if ( hash_equals( (string) ( $backup['archive_name'] ?? '' ), $backup_id ) ) {
				return $backup;
			}
		}
		return null;
	}

	private static function source( string $trigger ): string {
		return match ( $trigger ) {
			'hub'      => 'hub',
			'schedule' => 'schedule',
			'cli'      => 'cli',
			default    => 'local',
		};
	}

	private static function nullable_string( mixed $value ): ?string {
		$value = is_scalar( $value ) ? trim( (string) $value ) : '';
		return '' !== $value ? $value : null;
	}
}
