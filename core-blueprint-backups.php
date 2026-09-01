<?php
/**
 * Plugin Name: Core Blueprint Backups
 * Plugin URI:  https://coreblueprint.io
 * Description: Governed database and full-site backups for Core Blueprint, with local restore/migration, scheduling, CLI and optional Beacon remote orchestration.
 * Version:     0.1.0-rc15.1
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

define( 'CB_BACKUPS_VERSION', '0.1.0-rc15.1' );
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
