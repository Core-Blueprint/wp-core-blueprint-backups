<?php
declare(strict_types=1);

namespace CB\Backups\Restore;

use CB\Backups\DB\ContentDigest;
use RuntimeException;
use ZipArchive;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are internal diagnostics; UI/HTTP presentation boundaries escape them.
// phpcs:disable WordPress.WP.AlternativeFunctions -- Backup restore uses bounded native streams/atomic filesystem primitives; WP_Filesystem is not suitable for these server-owned jobs.

final class ArchiveValidator {
	/** @return array<string,mixed> */
	public static function validate( string $archive_path, bool $verify_payloads = true ): array {
		if ( ! is_file( $archive_path ) || ! is_readable( $archive_path ) ) throw new RuntimeException( 'Backup archive is not readable.' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive_path, ZipArchive::CHECKCONS ) ) throw new RuntimeException( 'Backup archive could not be opened.' );
		try {
			$entries = self::validate_entry_index( $zip );
			$raw = $zip->getFromName( 'manifest.json' );
			if ( false === $raw || false === $zip->locateName( 'checksums.json' ) ) throw new RuntimeException( 'Backup manifest or checksums are missing.' );
			$manifest = json_decode( $raw, true );
			if ( ! is_array( $manifest ) ) throw new RuntimeException( 'Backup manifest is invalid JSON.' );
			self::assert_manifest( $manifest );
			self::assert_required_payloads( $zip, $manifest, $entries );
			$count = self::walk_checksums( $zip, $verify_payloads );
			if ( (int) ( $manifest['checksums_count'] ?? -1 ) !== $count ) throw new RuntimeException( 'Backup checksum count does not match the manifest.' );
			return $manifest;
		} finally { $zip->close(); }
	}

	public static function export_checksum_index( string $archive_path, string $target ): int {
		$zip = new ZipArchive();
		if ( true !== $zip->open( $archive_path, ZipArchive::CHECKCONS ) ) throw new RuntimeException( 'Backup archive could not be opened.' );
		$out = fopen( $target, 'wb' );
		if ( false === $out ) { $zip->close(); throw new RuntimeException( 'Restore checksum work index could not be created.' ); }
		$locked = false;
		try {
			$locked = flock( $out, LOCK_EX );
			if ( ! $locked ) throw new RuntimeException( 'Restore checksum work index could not be locked.' );
			$count = self::walk_checksums( $zip, false, static function ( array $entry ) use ( $out ): void {
				$json = wp_json_encode( $entry, JSON_UNESCAPED_SLASHES );
				if ( ! is_string( $json ) ) throw new RuntimeException( 'Restore checksum record could not be encoded.' );
				self::write_all( $out, $json . "\n" );
			} );
			if ( ! fflush( $out ) ) throw new RuntimeException( 'Restore checksum work index could not be flushed.' );
			return $count;
		} finally {
			if ( $locked ) flock( $out, LOCK_UN );
			fclose( $out ); $zip->close();
		}
	}

	public static function estimate_uncompressed_bytes( string $archive_path ): int {
		$zip = self::open_readable( $archive_path );
		try {
			self::validate_entry_index( $zip );
			$total = 0;
			for ( $i = 0; $i < $zip->numFiles; ++$i ) {
				$stat = $zip->statIndex( $i );
				if ( ! is_array( $stat ) || ! isset( $stat['size'] ) ) throw new RuntimeException( 'Backup archive contains an unreadable entry.' );
				$size = max( 0, (int) $stat['size'] );
				if ( $total > PHP_INT_MAX - $size ) throw new RuntimeException( 'Backup archive is too large to inspect safely on this platform.' );
				$total += $size;
			}
			return $total;
		} finally { $zip->close(); }
	}

	public static function database_payload_bytes( string $archive_path ): int {
		$zip = self::open_readable( $archive_path );
		try {
			$stat = $zip->statName( 'database/database.sql' );
			if ( ! is_array( $stat ) || ! isset( $stat['size'] ) ) throw new RuntimeException( 'Database export size metadata is unavailable.' );
			return max( 0, (int) $stat['size'] );
		} finally { $zip->close(); }
	}

	private static function open_readable( string $path ): ZipArchive {
		if ( ! is_file( $path ) || ! is_readable( $path ) ) throw new RuntimeException( 'Backup archive is not readable.' );
		$zip = new ZipArchive();
		if ( true !== $zip->open( $path, ZipArchive::CHECKCONS ) ) throw new RuntimeException( 'Backup archive could not be opened.' );
		return $zip;
	}

	/** @return array<string,true> */
	private static function validate_entry_index( ZipArchive $zip ): array {
		$entries = []; $portable = [];
		$max = max( 1000, (int) apply_filters( 'cb_backups_max_archive_entries', 500000 ) );
		if ( $zip->numFiles > $max ) throw new RuntimeException( 'Backup archive contains too many entries.' );
		for ( $i = 0; $i < $zip->numFiles; ++$i ) {
			$name = str_replace( '\\', '/', (string) $zip->getNameIndex( $i ) ); self::assert_safe_entry( $name );
			$key = rtrim( strtolower( $name ), '/' );
			if ( isset( $entries[ $name ] ) || isset( $portable[ $key ] ) ) throw new RuntimeException( sprintf( 'Backup archive contains a duplicate path: %s', $name ) );
			$entries[ $name ] = true; $portable[ $key ] = true;
		}
		return $entries;
	}

	/** @param array<string,mixed> $manifest @param array<string,true> $entries */
	private static function assert_required_payloads( ZipArchive $zip, array $manifest, array $entries ): void {
		foreach ( [ 'manifest.json', 'checksums.json', 'database/database.sql', 'site/environment.json' ] as $required ) if ( ! isset( $entries[ $required ] ) ) throw new RuntimeException( sprintf( 'Required backup payload is missing: %s', $required ) );
		$type = (string) ( $manifest['backup_type'] ?? '' );
		$components = is_array( $manifest['components'] ?? null ) ? $manifest['components'] : [];
		if ( true !== ( $components['database'] ?? null ) ) throw new RuntimeException( 'Backup manifest does not declare the required database component.' );
		$has_files = true === ( $components['filesystem'] ?? null );
		if ( ( 'website' === $type ) !== $has_files ) throw new RuntimeException( 'Backup component declaration does not match its backup type.' );

		$file_count = 0; $file_bytes = 0; $tops = [];
		foreach ( array_keys( $entries ) as $entry ) {
			if ( ! str_starts_with( $entry, 'files/wp-content/' ) || str_ends_with( $entry, '/' ) ) continue;
			++$file_count; $stat = $zip->statName( $entry );
			if ( ! is_array( $stat ) || ! isset( $stat['size'] ) ) throw new RuntimeException( sprintf( 'Backup filesystem payload metadata is unreadable: %s', $entry ) );
			$file_bytes += max( 0, (int) $stat['size'] );
			$top = strtok( substr( $entry, strlen( 'files/wp-content/' ) ), '/' ); if ( is_string( $top ) && '' !== $top ) $tops[ $top ] = true;
		}
		if ( 'database' === $type && $file_count > 0 ) throw new RuntimeException( 'Database-only backup contains an unexpected filesystem payload.' );
		if ( 'website' === $type ) {
			$fs = is_array( $manifest['filesystem'] ?? null ) ? $manifest['filesystem'] : [];
			if ( isset( $fs['file_count'] ) && max( 0, (int) $fs['file_count'] ) !== $file_count ) throw new RuntimeException( 'Backup filesystem payload count does not match the manifest.' );
			if ( isset( $fs['bytes'] ) && max( 0, (int) $fs['bytes'] ) !== $file_bytes ) throw new RuntimeException( 'Backup filesystem payload bytes do not match the manifest.' );
			$declared = isset( $fs['top_levels'] ) && is_array( $fs['top_levels'] ) ? array_map( 'strval', $fs['top_levels'] ) : []; $actual = array_keys( $tops ); sort( $declared, SORT_STRING ); sort( $actual, SORT_STRING );
			if ( $declared !== $actual ) throw new RuntimeException( 'Backup wp-content top-level inventory does not match the filesystem payload.' );
		}
		$raw = $zip->getFromName( 'site/environment.json' ); $environment = false !== $raw ? json_decode( $raw, true ) : null;
		if ( ! is_array( $environment ) ) throw new RuntimeException( 'Backup environment metadata is invalid JSON.' );
		$site = is_array( $manifest['site'] ?? null ) ? $manifest['site'] : [];
		foreach ( [ 'home_url', 'site_url', 'table_prefix' ] as $key ) if ( isset( $environment[ $key ] ) && (string) $environment[ $key ] !== (string) ( $site[ $key ] ?? '' ) ) throw new RuntimeException( 'Backup environment metadata does not match the manifest.' );
	}

	public static function assert_safe_entry( string $name ): void {
		$name = str_replace( '\\', '/', $name );
		if ( '' === $name || str_contains( $name, "\0" ) || str_starts_with( $name, '/' ) || preg_match( '#^[A-Za-z]:/#', $name ) ) throw new RuntimeException( 'Unsafe path found inside backup archive.' );
		foreach ( explode( '/', rtrim( $name, '/' ) ) as $segment ) if ( '' === $segment || '.' === $segment || '..' === $segment ) throw new RuntimeException( 'Unsafe path found inside backup archive.' );
		if ( in_array( $name, [ 'manifest.json', 'checksums.json' ], true ) ) return;
		if ( ! str_starts_with( $name, 'database/' ) && ! str_starts_with( $name, 'files/wp-content/' ) && ! str_starts_with( $name, 'site/' ) ) throw new RuntimeException( sprintf( 'Unsupported backup archive entry: %s', $name ) );
	}

	/** @param array<string,mixed> $manifest */
	private static function assert_manifest( array $manifest ): void {
		if ( 'core-blueprint-backup' !== ( $manifest['format'] ?? null ) || 1 !== (int) ( $manifest['schema_version'] ?? 0 ) ) throw new RuntimeException( 'Unsupported Core Blueprint backup format.' );
		$type = (string) ( $manifest['backup_type'] ?? '' ); if ( ! in_array( $type, [ 'database', 'website' ], true ) ) throw new RuntimeException( 'Backup type is invalid.' );
		$site = is_array( $manifest['site'] ?? null ) ? $manifest['site'] : [];
		if ( ! empty( $site['multisite'] ) || is_multisite() ) throw new RuntimeException( 'Backup format v1 does not support multisite restore or migration.' );
		$prefix = (string) ( $site['table_prefix'] ?? '' );
		if ( '' === $prefix || strlen( $prefix ) > 63 || ! preg_match( '/^[A-Za-z0-9_$-]+$/', $prefix ) ) throw new RuntimeException( 'Backup manifest contains an unsafe WordPress table prefix.' );
		$db = is_array( $manifest['database'] ?? null ) ? $manifest['database'] : []; $tables = isset( $db['tables'] ) && is_array( $db['tables'] ) ? array_values( $db['tables'] ) : [];
		if ( ! $tables ) throw new RuntimeException( 'Backup manifest does not contain a database table inventory.' );
		ContentDigest::validate_inventory( $tables, $db['content_integrity'] ?? [] );
		foreach ( $tables as $table ) { $table = (string) $table; if ( '' === $table || ! str_starts_with( $table, $prefix ) || ! preg_match( '/^[A-Za-z0-9_$-]+$/', $table ) ) throw new RuntimeException( 'Backup manifest contains an unsafe database table name.' ); }
		MigrationPlan::build( $manifest );
		$fs = is_array( $manifest['filesystem'] ?? null ) ? $manifest['filesystem'] : []; $tops = isset( $fs['top_levels'] ) && is_array( $fs['top_levels'] ) ? $fs['top_levels'] : [];
		foreach ( $tops as $top ) { $top = (string) $top; if ( '' === $top || in_array( $top, [ '.', '..' ], true ) || str_contains( $top, '/' ) || str_contains( $top, '\\' ) || str_contains( $top, "\0" ) ) throw new RuntimeException( 'Backup manifest contains an unsafe wp-content top-level path.' ); }
	}

	/** @param callable(array<string,mixed>):void|null $consumer */
	private static function walk_checksums( ZipArchive $zip, bool $verify, ?callable $consumer = null ): int {
		$stream = $zip->getStream( 'checksums.json' ); if ( false === $stream ) throw new RuntimeException( 'Backup checksum metadata is missing.' );
		$count = 0; $covered = []; $buffer = ''; $started = false; $ended = false;
		try {
			while ( false !== ( $line = fgets( $stream ) ) ) {
				$line = trim( $line ); if ( '' === $line ) continue;
				if ( ! $started ) { if ( '[' !== $line ) throw new RuntimeException( 'Backup checksum metadata is malformed.' ); $started = true; continue; }
				if ( ']' === $line && '' === $buffer ) { $ended = true; break; }
				$buffer .= ( '' === $buffer ? '' : "\n" ) . $line; $entry = json_decode( rtrim( trim( $buffer ), ',' ), true ); if ( ! is_array( $entry ) ) continue; $buffer = '';
				$path = (string) ( $entry['path'] ?? '' ); $want = strtolower( (string) ( $entry['sha256'] ?? '' ) ); $size = isset( $entry['size'] ) ? (int) $entry['size'] : -1;
				if ( '' === $path || ! preg_match( '/^[a-f0-9]{64}$/', $want ) || $size < 0 || isset( $covered[ $path ] ) ) throw new RuntimeException( 'Backup checksum metadata is malformed.' );
				self::assert_safe_entry( $path ); $covered[ $path ] = true;
				if ( $verify ) {
					$stat = $zip->statName( $path ); if ( false === $stat || ! isset( $stat['size'] ) || (int) $stat['size'] !== $size ) throw new RuntimeException( sprintf( 'Backup payload size verification failed for %s.', $path ) );
					$payload = $zip->getStream( $path ); if ( false === $payload ) throw new RuntimeException( sprintf( 'Backup payload is missing: %s', $path ) );
					$ctx = hash_init( 'sha256' ); hash_update_stream( $ctx, $payload ); fclose( $payload ); if ( ! hash_equals( $want, hash_final( $ctx ) ) ) throw new RuntimeException( sprintf( 'Checksum verification failed for %s.', $path ) );
				}
				if ( null !== $consumer ) $consumer( [ 'path' => $path, 'sha256' => $want, 'size' => $size ] ); ++$count;
			}
		} finally { fclose( $stream ); }
		if ( ! $started || ! $ended || '' !== trim( $buffer ) ) throw new RuntimeException( 'Backup checksum metadata is malformed.' );
		for ( $i = 0; $i < $zip->numFiles; ++$i ) { $name = (string) $zip->getNameIndex( $i ); if ( '' === $name || str_ends_with( $name, '/' ) || in_array( $name, [ 'manifest.json', 'checksums.json' ], true ) ) continue; if ( ! isset( $covered[ $name ] ) ) throw new RuntimeException( sprintf( 'Backup payload is not covered by checksums: %s.', $name ) ); }
		if ( ! isset( $covered['database/database.sql'] ) ) throw new RuntimeException( 'Database export is not covered by the backup checksums.' );
		return $count;
	}

	/** @param resource $handle */
	private static function write_all( $handle, string $data ): void {
		$length = strlen( $data ); $written = 0;
		while ( $written < $length ) { $result = fwrite( $handle, substr( $data, $written ) ); if ( false === $result || 0 === $result ) throw new RuntimeException( 'Restore checksum work index could not be written completely.' ); $written += $result; }
	}
}
