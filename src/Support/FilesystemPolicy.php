<?php
declare(strict_types=1);

namespace CB\Backups\Support;

defined( 'ABSPATH' ) || exit;

final class FilesystemPolicy {
	/** @return string[] */
	public static function excluded_top_levels(): array {
		return [
			'cache',
			'upgrade',
			'ai1wm-backups',
			'.git',
			'.svn',
			'.cb-restore-work',
			'wflogs',
		];
	}

	public static function is_managed_storage_top_level( string $name ): bool {
		$name = trim( str_replace( '\\', '/', $name ), '/' );
		return 1 === preg_match( '/^cb-backups-[a-f0-9]{20}$/', $name );
	}

	public static function is_excluded_relative_path( string $relative ): bool {
		$relative = trim( str_replace( '\\', '/', $relative ), '/' );
		if ( '' === $relative ) {
			return false;
		}

		$top = strtok( $relative, '/' );
		if ( is_string( $top ) && self::is_managed_storage_top_level( $top ) ) {
			return true;
		}

		foreach ( self::excluded_top_levels() as $excluded_root ) {
			if ( $relative === $excluded_root || str_starts_with( $relative, $excluded_root . '/' ) ) {
				return true;
			}
		}
		return false;
	}
}
