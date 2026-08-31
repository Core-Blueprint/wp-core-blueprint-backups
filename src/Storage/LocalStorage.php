<?php
declare(strict_types=1);

namespace CB\Backups\Storage;

use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class LocalStorage {
	public static function base_path(): string {
		if ( defined( 'CB_BACKUPS_STORAGE_PATH' ) && is_string( CB_BACKUPS_STORAGE_PATH ) && '' !== trim( CB_BACKUPS_STORAGE_PATH ) ) {
			return untrailingslashit( wp_normalize_path( CB_BACKUPS_STORAGE_PATH ) );
		}

		$token = (string) get_option( 'cb_backups_storage_token', '' );
		if ( '' === $token ) {
			$token = bin2hex( random_bytes( 10 ) );
			update_option( 'cb_backups_storage_token', $token, false );
		}
		return untrailingslashit( wp_normalize_path( WP_CONTENT_DIR . '/cb-backups-' . $token ) );
	}

	public static function ensure(): void {
		$base = self::base_path();
		foreach ( [ $base, $base . '/.work', $base . '/imports', $base . '/imports/.uploads' ] as $dir ) {
			if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
				throw new RuntimeException( 'Backup storage directory could not be created.' );
			}
		}

		$protections = [
			$base . '/index.php'  => "<?php\n// Silence is golden.\n",
			$base . '/.htaccess'  => "Deny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n",
			$base . '/web.config' => "<?xml version=\"1.0\" encoding=\"UTF-8\"?><configuration><system.webServer><authorization><remove users=\"*\" roles=\"\" verbs=\"\"/><add accessType=\"Deny\" users=\"*\"/></authorization></system.webServer></configuration>",
		];
		foreach ( $protections as $file => $contents ) {
			if ( ! is_file( $file ) ) {
				@file_put_contents( $file, $contents, LOCK_EX ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	public static function work_dir( string $job_id ): string {
		$dir = self::base_path() . '/.work/' . sanitize_file_name( $job_id );
		if ( ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( 'Backup job work directory could not be created.' );
		}
		return $dir;
	}

	public static function archive_path( string $archive_name ): string {
		$archive_name = basename( sanitize_file_name( $archive_name ) );
		return self::base_path() . '/' . $archive_name;
	}

	public static function import_path( string $name ): string {
		return self::base_path() . '/imports/' . basename( sanitize_file_name( $name ) );
	}

	public static function import_upload_root(): string {
		return self::base_path() . '/imports/.uploads';
	}

	public static function import_upload_dir( string $upload_id, bool $create = false ): string {
		$dir = self::import_upload_root() . '/' . basename( sanitize_file_name( $upload_id ) );
		if ( $create && ! is_dir( $dir ) && ! wp_mkdir_p( $dir ) ) {
			throw new RuntimeException( 'Import upload staging directory could not be created.' );
		}
		return $dir;
	}

	public static function import_upload_part_path( string $upload_id ): string {
		return self::import_upload_dir( $upload_id, false ) . '/payload.part';
	}

	public static function import_upload_state_path( string $upload_id ): string {
		return self::import_upload_dir( $upload_id, false ) . '/state.json';
	}

	/** @param array<string,mixed> $meta */
	public static function write_import_meta( string $archive_name, array $meta ): void {
		$file = self::import_path( $archive_name ) . '.json';
		$result = file_put_contents( $file, wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX );
		if ( false === $result ) {
			throw new RuntimeException( 'Prepared import metadata could not be written.' );
		}
	}

	/** @return array<int,array<string,mixed>> */
	public static function list_imports(): array {
		self::ensure();
		$files = glob( self::base_path() . '/imports/*.cbbackup' ) ?: [];
		$out = [];
		foreach ( $files as $file ) {
			$meta = [];
			if ( is_file( $file . '.json' ) ) {
				$decoded = json_decode( (string) file_get_contents( $file . '.json' ), true );
				$meta = is_array( $decoded ) ? $decoded : [];
			}
			$meta['archive_name'] = basename( $file );
			$meta['size'] = filesize( $file ) ?: 0;
			$meta['modified_at'] = filemtime( $file ) ?: 0;
			$out[] = $meta;
		}
		usort( $out, static fn ( array $a, array $b ): int => (int) ( $b['created_timestamp'] ?? $b['modified_at'] ?? 0 ) <=> (int) ( $a['created_timestamp'] ?? $a['modified_at'] ?? 0 ) );
		return $out;
	}

	public static function delete_import( string $archive_name ): bool {
		$file = self::import_path( $archive_name );
		if ( ! is_file( $file ) ) {
			return false;
		}
		$ok = unlink( $file );
		if ( is_file( $file . '.json' ) ) {
			@unlink( $file . '.json' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return $ok;
	}

	public static function is_inside_storage( string $path ): bool {
		$base = trailingslashit( wp_normalize_path( self::base_path() ) );
		$path = wp_normalize_path( $path );
		return str_starts_with( trailingslashit( $path ), $base ) || str_starts_with( $path, $base );
	}

	/** @return array<string,mixed>|null */
	public static function read_meta( string $archive_name ): ?array {
		$file = self::archive_path( $archive_name ) . '.json';
		if ( ! is_file( $file ) || ! is_readable( $file ) ) {
			return null;
		}
		$decoded = json_decode( (string) file_get_contents( $file ), true );
		return is_array( $decoded ) ? $decoded : null;
	}

	/** @param array<string,mixed> $meta */
	public static function write_meta( string $archive_name, array $meta ): void {
		$file = self::archive_path( $archive_name ) . '.json';
		$result = file_put_contents( $file, wp_json_encode( $meta, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ), LOCK_EX );
		if ( false === $result ) {
			throw new RuntimeException( 'Backup metadata could not be written.' );
		}
	}

	/** @return array<int,array<string,mixed>> */
	public static function list_backups(): array {
		self::ensure();
		$files = glob( self::base_path() . '/*.cbbackup' ) ?: [];
		$out   = [];
		foreach ( $files as $file ) {
			$meta_file = $file . '.json';
			$meta      = [];
			if ( is_file( $meta_file ) ) {
				$decoded = json_decode( (string) file_get_contents( $meta_file ), true );
				$meta = is_array( $decoded ) ? $decoded : [];
			}
			$meta['archive_name'] = basename( $file );
			$meta['size']         = filesize( $file ) ?: 0;
			$meta['modified_at']  = filemtime( $file ) ?: 0;
			$out[] = $meta;
		}
		usort( $out, static fn ( array $a, array $b ): int => (int) ( $b['created_timestamp'] ?? $b['modified_at'] ?? 0 ) <=> (int) ( $a['created_timestamp'] ?? $a['modified_at'] ?? 0 ) );
		return $out;
	}

	public static function delete_archive( string $archive_name ): bool {
		$file = self::archive_path( $archive_name );
		if ( ! is_file( $file ) ) {
			return false;
		}
		$ok = unlink( $file );
		if ( is_file( $file . '.json' ) ) {
			@unlink( $file . '.json' ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		return $ok;
	}

	public static function remove_tree( string $path ): void {
		$path = wp_normalize_path( $path );
		if ( ! file_exists( $path ) ) {
			return;
		}
		if ( is_link( $path ) || is_file( $path ) ) {
			@unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			return;
		}
		$items = scandir( $path );
		if ( ! is_array( $items ) ) {
			return;
		}
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item ) {
				continue;
			}
			self::remove_tree( $path . '/' . $item );
		}
		@rmdir( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
