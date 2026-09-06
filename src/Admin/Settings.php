<?php
declare(strict_types=1);

namespace CB\Backups\Admin;

use CB\Backups\Storage\LocalStorage;
use CB\Backups\Support\Capabilities;

defined( 'ABSPATH' ) || exit;

final class Settings {
	public static function render(): void {
		if ( ! current_user_can( Capabilities::MANAGE ) ) {
			wp_die( esc_html__( 'You are not allowed to manage backups.', 'core-blueprint-backups' ) );
		}

		LocalStorage::ensure();
		$path           = LocalStorage::base_path();
		$inside_content = str_starts_with( wp_normalize_path( $path ), trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) );

		echo '<section class="cb-core-panel cb-backups-settings-panel">';
		echo '<table class="widefat cb-core-kv"><tbody>';
		echo '<tr><th scope="row">' . esc_html__( 'Storage', 'core-blueprint-backups' ) . '</th><td><code>' . esc_html( $path ) . '</code><p class="description">' . esc_html__( 'Archives use random filenames and the directory includes Apache and IIS deny rules. For the strongest isolation, define CB_BACKUPS_STORAGE_PATH to a writable directory outside the public web root.', 'core-blueprint-backups' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Storage location', 'core-blueprint-backups' ) . '</th><td>' . ( $inside_content ? '<span class="dashicons dashicons-warning"></span> ' . esc_html__( 'Inside wp-content; protected where the web server honours supplied deny rules.', 'core-blueprint-backups' ) : '<span class="dashicons dashicons-yes-alt"></span> ' . esc_html__( 'Outside wp-content.', 'core-blueprint-backups' ) ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'PHP ZIP', 'core-blueprint-backups' ) . '</th><td>' . ( class_exists( 'ZipArchive' ) ? esc_html__( 'Available', 'core-blueprint-backups' ) : esc_html__( 'Missing', 'core-blueprint-backups' ) ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'Background execution', 'core-blueprint-backups' ) . '</th><td>' . esc_html__( 'Server-owned loopback worker with WP-Cron/CLI recovery watchdog. The browser is only a monitor and may be closed during backups.', 'core-blueprint-backups' ) . '</td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'WP-Cron', 'core-blueprint-backups' ) . '</th><td>' . ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ? esc_html__( 'Disabled — the loopback worker still runs started jobs; use a server cron/CLI runner as watchdog and for schedules.', 'core-blueprint-backups' ) : esc_html__( 'Enabled', 'core-blueprint-backups' ) ) . '</td></tr>';
		echo '</tbody></table>';
		echo '</section>';
	}
}
