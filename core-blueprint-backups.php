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

define( 'CB_BACKUPS_NAME', 'Core Blueprint Backups' );
define( 'CB_BACKUPS_VERSION', '1.0.0-rc1' );
define( 'CB_BACKUPS_MIN_PHP', '8.4' );
define( 'CB_BACKUPS_REQUIRED_API', '1.0' );
define( 'CB_BACKUPS_DB_VERSION', '1.0' );
define( 'CB_BACKUPS_FILE', __FILE__ );
define( 'CB_BACKUPS_DIR', plugin_dir_path( __FILE__ ) );
define( 'CB_BACKUPS_URL', plugin_dir_url( __FILE__ ) );
define( 'CB_BACKUPS_BASENAME', plugin_basename( __FILE__ ) );

/* Bootstrap v1 earliest-safe PHP boundary. */
if ( version_compare( PHP_VERSION, CB_BACKUPS_MIN_PHP, '<' ) ) {
	register_activation_hook( __FILE__, static function () {
		if ( ! function_exists( 'deactivate_plugins' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}
		deactivate_plugins( CB_BACKUPS_BASENAME );
		wp_die(
			esc_html( sprintf( '%s requires PHP %s or newer. This server runs PHP %s.', CB_BACKUPS_NAME, CB_BACKUPS_MIN_PHP, PHP_VERSION ) ),
			esc_html( 'Core Blueprint dependency required' ),
			[
				'link_url'  => admin_url( 'plugins.php' ),
				'link_text' => __( 'Plugins' ),
			]
		);
	} );

	add_action( 'admin_notices', static function () {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		printf(
			'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
			esc_html( CB_BACKUPS_NAME . ':' ),
			esc_html( sprintf( 'PHP %s or newer is required. This server runs PHP %s.', CB_BACKUPS_MIN_PHP, PHP_VERSION ) )
		);
	} );

	return;
}

spl_autoload_register( static function ( string $class ): void {
	$prefix = 'CB\\Backups\\';
	$len    = strlen( $prefix );
	if ( 0 !== strncmp( $class, $prefix, $len ) ) {
		return;
	}
	$relative = substr( $class, $len );
	$file     = CB_BACKUPS_DIR . 'src/' . str_replace( '\\', '/', $relative ) . '.php';
	if ( is_file( $file ) ) {
		require_once $file;
	}
} );

/* Backward-compatible product-prefixed wrappers around Bootstrap v1 semantics. */
function cb_backups_api_compatible( string $available, string $required ): bool {
	return \CB\Backups\Support\Requirements::api_compatible( $available, $required );
}

/** Product-specific public Base contracts; intentionally outside Bootstrap v1. */
function cb_backups_base_ready(): bool {
	return \CB\Backups\Support\Requirements::runtime_ready()
		&& class_exists( '\\CB\\Core\\Database\\SchemaRegistry' )
		&& interface_exists( '\\CB\\Core\\Admin\\Page' )
		&& class_exists( '\\CB\\Core\\Admin\\PageRegistry' )
		&& class_exists( '\\CB\\Core\\Admin\\SettingsRegistry' )
		&& class_exists( '\\CB\\Core\\ExtensionRegistry' )
		&& class_exists( '\\CB\\Core\\Governance\\Audit' )
		&& class_exists( '\\CB\\Core\\Governance\\EventRegistry' );
}

function cb_backups_fail_activation( string $message ): void {
	if ( ! function_exists( 'deactivate_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	deactivate_plugins( CB_BACKUPS_BASENAME );
	wp_die(
		esc_html( $message ),
		esc_html( 'Core Blueprint dependency required' ),
		[
			'link_url'  => admin_url( 'plugins.php' ),
			'link_text' => __( 'Plugins' ),
		]
	);
}

function cb_backups_activate(): void {
	if ( ! \CB\Backups\Support\Requirements::runtime_ready() ) {
		cb_backups_fail_activation( \CB\Backups\Support\Requirements::operator_message() );
	}
	if ( ! cb_backups_base_ready() ) {
		cb_backups_fail_activation( 'Core Blueprint Backups requires the public Base services used by Backups. Update Core Blueprint Base first.' );
	}
	\CB\Backups\Bootstrap::activate();
}

register_activation_hook( __FILE__, 'cb_backups_activate' );
register_deactivation_hook( __FILE__, [ \CB\Backups\Bootstrap::class, 'deactivate' ] );

add_action( 'init', static function (): void {
	load_plugin_textdomain( 'core-blueprint-backups', false, dirname( CB_BACKUPS_BASENAME ) . '/languages' );
}, 0 );

/* Lightweight suite/update integration starts after Bootstrap readiness. */
add_action( 'plugins_loaded', static function (): void {
	if ( ! \CB\Backups\Support\Requirements::runtime_ready() ) {
		if ( is_admin() ) {
			add_action( 'admin_notices', static function (): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				printf(
					'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
					esc_html__( 'Core Blueprint Backups:', 'core-blueprint-backups' ),
					esc_html( \CB\Backups\Support\Requirements::operator_message() )
				);
			} );
		}
		return;
	}

	\CB\Backups\Bootstrap::register_suite_integration();
	\CB\Backups\Integration\Updates::init();
}, 1 );

/* Backups retains its existing plugins_loaded:2 product runtime timing. */
add_action( 'plugins_loaded', static function (): void {
	if ( ! \CB\Backups\Support\Requirements::runtime_ready() ) {
		return;
	}

	$errors = [];
	if ( ! cb_backups_base_ready() ) {
		$errors[] = 'Required public Core Blueprint Base services are unavailable.';
	}
	if ( ! class_exists( 'ZipArchive' ) ) {
		$errors[] = 'The PHP ZIP extension (ZipArchive) is required.';
	}

	if ( $errors ) {
		if ( is_admin() ) {
			add_action( 'admin_notices', static function () use ( $errors ): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
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
