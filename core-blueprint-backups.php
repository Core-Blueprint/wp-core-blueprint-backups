<?php
/**
 * Plugin Name: Core Blueprint Backups
 * Plugin URI:  https://coreblueprint.io
 * Update URI:  https://coreblueprint.io/
 * Description: Governed database and full-site backups for Core Blueprint, with local restore/migration, scheduling, CLI and optional Beacon remote orchestration.
 * Version:     1.0.0-rc1
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

define( 'CB_BACKUPS_VERSION', '1.0.0-rc1' );
define( 'CB_BACKUPS_REQUIRED_API', '1.0' );
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

function cb_backups_api_compatible( string $available, string $required ): bool {
	if ( 1 !== preg_match( '/^(\d+)\.(\d+)$/', $available, $a ) || 1 !== preg_match( '/^(\d+)\.(\d+)$/', $required, $r ) ) {
		return false;
	}
	return (int) $a[1] === (int) $r[1] && (int) $a[2] >= (int) $r[2];
}

function cb_backups_base_ready(): bool {
	return defined( 'CB_CORE_API_VERSION' )
		&& cb_backups_api_compatible( (string) CB_CORE_API_VERSION, CB_BACKUPS_REQUIRED_API )
		&& class_exists( '\\CB\\Core\\Database\\SchemaRegistry' )
		&& interface_exists( '\\CB\\Core\\Admin\\Page' )
		&& class_exists( '\\CB\\Core\\Admin\\PageRegistry' )
		&& class_exists( '\\CB\\Core\\Admin\\SettingsRegistry' )
		&& class_exists( '\\CB\\Core\\ExtensionRegistry' )
		&& class_exists( '\\CB\\Core\\Governance\\Audit' )
		&& class_exists( '\\CB\\Core\\Governance\\EventRegistry' );
}

function cb_backups_activate(): void {
	if ( ! cb_backups_base_ready() ) {
		deactivate_plugins( CB_BACKUPS_BASENAME );
		wp_die(
			esc_html( 'Core Blueprint Backups requires an active Core Blueprint Base installation with a compatible public API.' ),
			esc_html( 'Core Blueprint Backups - Activation Error' ),
			[ 'back_link' => true ]
		);
	}

	\CB\Backups\Bootstrap::activate();
}

// Attach lightweight suite/update integrations before any feature-runtime
// dependency can stop the Backups boot sequence.
\CB\Backups\Bootstrap::register_suite_integration();
\CB\Backups\Integration\Updates::init();

register_activation_hook( __FILE__, 'cb_backups_activate' );
register_deactivation_hook( __FILE__, [ \CB\Backups\Bootstrap::class, 'deactivate' ] );

add_action( 'init', static function (): void {
	load_plugin_textdomain( 'core-blueprint-backups', false, dirname( CB_BACKUPS_BASENAME ) . '/languages' );
}, 0 );

add_action( 'plugins_loaded', static function (): void {
	$errors = [];
	if ( version_compare( PHP_VERSION, '8.4', '<' ) ) {
		$errors[] = sprintf( 'PHP 8.4 or newer is required; this server runs PHP %s.', PHP_VERSION );
	}
	if ( ! cb_backups_base_ready() ) {
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
