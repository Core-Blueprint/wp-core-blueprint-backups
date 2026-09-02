<?php
/**
 * Plugin Name: Core Blueprint Backups
 * Plugin URI:  https://coreblueprint.io
 * Description: Governed database and full-site backups for Core Blueprint, with local restore/migration, scheduling, CLI and optional Beacon remote orchestration.
 * Version:     0.1.0-rc15.13
 * Author:      Core Blueprint
 * Author URI:  https://coreblueprint.io
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: core-blueprint-backups
 * Domain Path: /languages
 * Requires at least: 7.0
 * Requires PHP:      8.4
 *
 * @package Core_Blueprint_Backups
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( defined( 'CB_BACKUPS_FILE' ) ) {
	return;
}

define( 'CB_BACKUPS_VERSION', '0.1.0-rc15.13' );
define( 'CB_BACKUPS_DB_VERSION', '1.0' );
define( 'CB_BACKUPS_FILE', __FILE__ );
define( 'CB_BACKUPS_DIR', plugin_dir_path( __FILE__ ) );
define( 'CB_BACKUPS_URL', plugin_dir_url( __FILE__ ) );
define( 'CB_BACKUPS_BASENAME', plugin_basename( __FILE__ ) );

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'CB\\Backups\\';
	if ( ! str_starts_with( $class, $prefix ) ) {
		return;
	}
	$relative = substr( $class, strlen( $prefix ) );
	$file     = CB_BACKUPS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_file( $file ) ) {
		require_once $file;
	}
} );

register_activation_hook( __FILE__, [ \CB\Backups\Bootstrap::class, 'activate' ] );
register_deactivation_hook( __FILE__, [ \CB\Backups\Bootstrap::class, 'deactivate' ] );

add_action( 'init', static function (): void {
	load_plugin_textdomain( 'core-blueprint-backups', false, dirname( CB_BACKUPS_BASENAME ) . '/languages' );
}, 0 );

// A plugin update can leave PHP-FPM serving stale OPcache bytecode while the
// plugin header already reflects the new files on disk. Refresh the plugin's
// cached PHP files once per code version, then verify that the migration
// transformer actually exposes the RC15 record reader before Backups boots.
add_action( 'plugins_loaded', static function (): void {
	$stored_version = (string) get_option( 'cb_backups_runtime_code_version', '' );
	if ( CB_BACKUPS_VERSION !== $stored_version ) {
		if ( function_exists( 'opcache_invalidate' ) ) {
			$iterator = new RecursiveIteratorIterator(
				new RecursiveDirectoryIterator( CB_BACKUPS_DIR, FilesystemIterator::SKIP_DOTS )
			);
			foreach ( $iterator as $file_info ) {
				if ( ! $file_info instanceof SplFileInfo || ! $file_info->isFile() || 'php' !== strtolower( $file_info->getExtension() ) ) {
					continue;
				}
				$path = $file_info->getPathname();
				clearstatcache( true, $path );
				@opcache_invalidate( $path, true ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}
		}
	}

	$transformer_file = CB_BACKUPS_DIR . 'src/Restore/MigrationTransformer.php';
	$source = is_readable( $transformer_file ) ? file_get_contents( $transformer_file ) : false;
	$source_is_current = is_string( $source )
		&& str_contains( $source, 'private static function read_record' )
		&& ! str_contains( $source, 'Migration INSERT statement is malformed for' );

	$runtime_is_current = false;
	if ( $source_is_current && class_exists( '\\CB\\Backups\\Restore\\MigrationTransformer' ) ) {
		$reflection = new ReflectionClass( '\\CB\\Backups\\Restore\\MigrationTransformer' );
		$runtime_is_current = $reflection->hasMethod( 'read_record' );
	}

	if ( ! $source_is_current || ! $runtime_is_current ) {
		define( 'CB_BACKUPS_CODE_INTEGRITY_ERROR', 'Core Blueprint Backups code files are out of sync with ' . CB_BACKUPS_VERSION . '. The migration runtime was not started. Reinstall the complete plugin package; if the files on disk are current, restart PHP-FPM/OPcache once.' );
		return;
	}

	if ( CB_BACKUPS_VERSION !== $stored_version ) {
		update_option( 'cb_backups_runtime_code_version', CB_BACKUPS_VERSION, false );
	}
}, 1 );

// RC15 broadens format-v1 restore from same-site recovery to governed
// single-site migration. Keep the existing admin template truthful while its
// translation catalogue catches up with the new restore contract.
add_filter( 'gettext_core-blueprint-backups', static function ( string $translation, string $text ): string {
	return match ( $text ) {
		'Backup format v1 only restores to the same site URL and table prefix. Migration and URL replacement are intentionally blocked. Core Blueprint Base and Backups code remain at their currently installed versions during recovery so the restore engine cannot replace itself mid-operation.' => 'Backup format v1 supports same-site restore and single-site migration. If the source URL or table prefix differs, Core Blueprint prepares a verified migration copy, remaps WordPress table names, replaces URLs in serialized data, and preserves post GUID values. Core Blueprint Base and Backups code remain at their currently installed versions during recovery so the restore engine cannot replace itself mid-operation.',
		'Restore imported backup?' => 'Restore or migrate imported backup?',
		'Restore %s? The archive will be fully verified first. If verification succeeds, live site files and database data will be replaced.' => 'Restore or migrate %s? The archive will be fully verified first. If its WordPress URL or table prefix differs, Core Blueprint will prepare a migration copy before live site files and database data are replaced.',
		'Restore backup' => 'Restore / migrate',
		default => $translation,
	};
}, 10, 2 );

add_action( 'plugins_loaded', static function (): void {
	$errors = [];
	if ( defined( 'CB_BACKUPS_CODE_INTEGRITY_ERROR' ) ) {
		$errors[] = (string) CB_BACKUPS_CODE_INTEGRITY_ERROR;
	}
	if ( version_compare( PHP_VERSION, '8.4', '<' ) ) {
		$errors[] = sprintf( 'PHP 8.4 or newer is required; this server runs PHP %s.', PHP_VERSION );
	}
	if ( ! defined( 'CB_CORE_FILE' ) || ! class_exists( '\\CB\\Core\\Database\\SchemaRegistry' ) || ! interface_exists( '\\CB\\Core\\Admin\\Page' ) || ! class_exists( '\\CB\\Core\\Governance\\Audit' ) || ! class_exists( '\\CB\\Core\\Governance\\EventRegistry' ) ) {
		$errors[] = 'A compatible Core Blueprint Base installation is required.';
	}
	if ( ! class_exists( 'ZipArchive' ) ) {
		$errors[] = 'The PHP ZIP extension (ZipArchive) is required.';
	}

	if ( $errors ) {
		if ( is_admin() ) {
			add_action( 'admin_notices', static function () use ( $errors ): void {
				echo '<div class="notice notice-error"><p><strong>Core Blueprint Backups:</strong></p><ul>';
				foreach ( $errors as $error ) {
					echo '<li>' . esc_html( $error ) . '</li>';
				}
				echo '</ul></div>';
			} );
		}
		return;
	}

	\CB\Backups\Bootstrap::boot();
}, 2 );
