<?php
/**
 * Plugin Name: Core Blueprint Backups
 * Plugin URI:  https://coreblueprint.io
 * Description: Governed database and full-site backups for Core Blueprint, with local restore/migration, scheduling, CLI and optional Beacon remote orchestration.
 * Version:     1.0.0
 * Author:      Core Blueprint
 * Author URI:  https://coreblueprint.io
 * License:     GPL-2.0+
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: core-blueprint-backups
 * Domain Path: /languages
 * Requires at least: 7.0
 * Requires PHP:      8.4
 * Requires Plugins: core-blueprint
 *
 * @package Core_Blueprint_Backups
 */

declare(strict_types=1);

defined( 'ABSPATH' ) || exit;

if ( defined( 'CB_BACKUPS_FILE' ) ) {
	return;
}

define( 'CB_BACKUPS_NAME', 'Core Blueprint Backups' );
define( 'CB_BACKUPS_VERSION', '1.0.0' );
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
			esc_html( sprintf( 'PHP %1$s or newer is required. This server runs PHP %2$s.', CB_BACKUPS_MIN_PHP, PHP_VERSION ) ),
			esc_html( 'Core Blueprint requirements not met' ),
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
			esc_html( sprintf( 'PHP %1$s or newer is required. This server runs PHP %2$s.', CB_BACKUPS_MIN_PHP, PHP_VERSION ) )
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

function cb_backups_fail_activation( string $message ): void {
	if ( ! function_exists( 'deactivate_plugins' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}
	deactivate_plugins( CB_BACKUPS_BASENAME );
	wp_die(
		esc_html( $message ),
		esc_html( 'Core Blueprint requirements not met' ),
		[
			'link_url'  => admin_url( 'plugins.php' ),
			'link_text' => __( 'Plugins' ),
		]
	);
}

function cb_backups_activate(): void {
	if ( ! \CB\Backups\Support\Requirements::runtime_ready() ) {
		cb_backups_fail_activation( \CB\Backups\Support\Requirements::activation_message() );
	}
	if ( ! \CB\Backups\Support\Requirements::product_ready() ) {
		cb_backups_fail_activation( \CB\Backups\Support\Requirements::product_activation_message() );
	}
	\CB\Backups\Bootstrap::activate();
}

register_activation_hook( __FILE__, 'cb_backups_activate' );
register_deactivation_hook( __FILE__, [ \CB\Backups\Bootstrap::class, 'deactivate' ] );

add_action( 'init', static function (): void {
	load_plugin_textdomain( 'core-blueprint-backups', false, dirname( CB_BACKUPS_BASENAME ) . '/languages' );
}, 0 );

/* Suite integrations attach only after complete Backups readiness. */
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

	if ( ! \CB\Backups\Support\Requirements::product_ready() ) {
		if ( is_admin() ) {
			add_action( 'admin_notices', static function (): void {
				if ( ! current_user_can( 'activate_plugins' ) ) {
					return;
				}
				printf(
					'<div class="notice notice-error"><p><strong>%s</strong> %s</p></div>',
					esc_html__( 'Core Blueprint Backups:', 'core-blueprint-backups' ),
					esc_html( \CB\Backups\Support\Requirements::product_operator_message() )
				);
			} );
		}
		return;
	}

	\CB\Backups\Bootstrap::register_suite_integration();
	\CB\Backups\Bootstrap::boot();
}, 2 );
