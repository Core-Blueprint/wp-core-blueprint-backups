<?php
declare(strict_types=1);

namespace CB\Backups\Restore;

use CB\Backups\DB\Schema;
use CB\Backups\Support\SiteIdentity;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exceptions are internal diagnostics; UI/HTTP presentation boundaries escape them.

final class MigrationPlan {
	/** @param array<string,mixed> $manifest @return array<string,mixed> */
	public static function build( array $manifest ): array {
		global $wpdb;
		$identity = SiteIdentity::current();

		$site = is_array( $manifest['site'] ?? null ) ? $manifest['site'] : [];
		$database = is_array( $manifest['database'] ?? null ) ? $manifest['database'] : [];
		$source_tables = isset( $database['tables'] ) && is_array( $database['tables'] ) ? array_values( array_map( 'strval', $database['tables'] ) ) : [];
		$source_prefix = (string) ( $site['table_prefix'] ?? '' );
		$target_prefix = (string) $wpdb->prefix;
		$source_home = self::normalise_url( (string) ( $site['home_url'] ?? '' ), 'Backup home URL' );
		$source_site = self::normalise_url( (string) ( $site['site_url'] ?? '' ), 'Backup site URL' );
		$target_home = self::normalise_url( $identity['home_url'], 'Destination home URL' );
		$target_site = self::normalise_url( $identity['site_url'], 'Destination site URL' );

		self::assert_prefix( $source_prefix, 'backup' );
		self::assert_prefix( $target_prefix, 'destination' );
		if ( ! $source_tables ) {
			throw new RuntimeException( 'Backup manifest does not contain a database table inventory.' );
		}

		$table_map = [];
		$target_tables = [];
		foreach ( $source_tables as $source_table ) {
			self::assert_table( $source_table, $source_prefix );
			$suffix = substr( $source_table, strlen( $source_prefix ) );
			if ( false === $suffix || '' === $suffix ) {
				throw new RuntimeException( 'Backup manifest contains an invalid prefixed table name.' );
			}
			$target_table = $target_prefix . $suffix;
			self::assert_table( $target_table, $target_prefix );
			if ( strlen( $target_table ) > 64 ) {
				throw new RuntimeException( sprintf( 'Migrated database table name exceeds MySQL limits: %s', $target_table ) );
			}
			if ( $target_table === Schema::table() ) {
				throw new RuntimeException( 'Backup manifest may not map onto the operational backup job table.' );
			}
			if ( isset( $table_map[ $source_table ] ) || in_array( $target_table, $target_tables, true ) ) {
				throw new RuntimeException( 'Backup table migration map contains a collision.' );
			}
			$table_map[ $source_table ] = $target_table;
			$target_tables[] = $target_table;
		}

		$requires_migration = $source_prefix !== $target_prefix
			|| $source_home !== $target_home
			|| $source_site !== $target_site;

		$plan = [
			'mode'                => $requires_migration ? 'migration' : 'restore',
			'requires_migration'  => $requires_migration,
			'source_prefix'       => $source_prefix,
			'target_prefix'       => $target_prefix,
			'source_home_url'     => $source_home,
			'target_home_url'     => $target_home,
			'source_site_url'     => $source_site,
			'target_site_url'     => $target_site,
			'source_integrity'    => $database['content_integrity'] ?? [],
			'source_tables'       => $source_tables,
			'target_tables'       => $target_tables,
			'table_map'           => $table_map,
		];
		$plan['fingerprint'] = self::fingerprint( $plan );
		return $plan;
	}

	/** @param array<string,mixed> $manifest @param array<string,mixed> $plan @return array<string,mixed> */
	public static function target_manifest( array $manifest, array $plan ): array {
		self::assert_plan( $plan );
		$database = is_array( $manifest['database'] ?? null ) ? $manifest['database'] : [];
		$site = is_array( $manifest['site'] ?? null ) ? $manifest['site'] : [];
		$database['tables'] = array_values( array_map( 'strval', $plan['target_tables'] ) );
		$database['table_count'] = count( $database['tables'] );
		$site['table_prefix'] = (string) $plan['target_prefix'];
		$site['home_url'] = trailingslashit( (string) $plan['target_home_url'] );
		$site['site_url'] = trailingslashit( (string) $plan['target_site_url'] );
		$manifest['database'] = $database;
		$manifest['site'] = $site;
		return $manifest;
	}

	/** @param array<string,mixed> $plan */
	public static function assert_plan( array $plan ): void {
		$expected = self::fingerprint( $plan );
		$stored = (string) ( $plan['fingerprint'] ?? '' );
		if ( '' === $stored || ! hash_equals( $stored, $expected ) ) {
			throw new RuntimeException( 'Restore migration plan is incomplete or has changed.' );
		}
	}

	/** @param array<string,mixed> $plan */
	private static function fingerprint( array $plan ): string {
		$data = $plan;
		unset( $data['fingerprint'] );
		$json = wp_json_encode( $data, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			throw new RuntimeException( 'Restore migration plan could not be encoded.' );
		}
		return hash( 'sha256', $json );
	}

	private static function normalise_url( string $url, string $label ): string {
		$url = untrailingslashit( trim( $url ) );
		$parts = wp_parse_url( $url );
		$scheme = is_array( $parts ) ? strtolower( (string) ( $parts['scheme'] ?? '' ) ) : '';
		$host = is_array( $parts ) ? (string) ( $parts['host'] ?? '' ) : '';
		if ( '' === $url || ! in_array( $scheme, [ 'http', 'https' ], true ) || '' === $host ) {
			throw new RuntimeException( $label . ' is invalid.' );
		}
		return $url;
	}

	private static function assert_prefix( string $prefix, string $label ): void {
		if ( '' === $prefix || strlen( $prefix ) > 63 || ! preg_match( '/^[A-Za-z0-9_$-]+$/', $prefix ) ) {
			throw new RuntimeException( sprintf( 'The %s WordPress table prefix is unsafe.', $label ) );
		}
	}

	private static function assert_table( string $table, string $prefix ): void {
		if ( '' === $table || ! str_starts_with( $table, $prefix ) || ! preg_match( '/^[A-Za-z0-9_$-]+$/', $table ) ) {
			throw new RuntimeException( 'Backup manifest contains an unsafe database table name.' );
		}
	}
}
