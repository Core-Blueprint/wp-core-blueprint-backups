<?php
declare(strict_types=1);

namespace CB\Backups;

use CB\Backups\Admin\Actions;
use CB\Backups\Admin\Assets;
use CB\Backups\Admin\Page;
use CB\Backups\DB\Schema;
use CB\Backups\Jobs\Dispatcher;
use CB\Backups\Jobs\Runner;
use CB\Backups\Remote\Routes as RemoteRoutes;
use CB\Backups\Restore\Maintenance;
use CB\Backups\Restore\CriticalRecovery;
use CB\Backups\Schedule\Scheduler;
use CB\Backups\Storage\LocalStorage;
use CB\Backups\Support\SiteHealth;
use CB\Backups\Support\Capabilities;
use CB\Core\Admin\PageRegistry;
use CB\Core\Dashboard\CardRegistry;

defined( 'ABSPATH' ) || exit;

final class Bootstrap {
	private static bool $booted = false;

	public static function boot(): void {
		if ( self::$booted ) {
			return;
		}
		self::$booted = true;

		Schema::register();
		Capabilities::boot();
		Scheduler::boot();
		Dispatcher::boot();
		Actions::boot();
		Assets::boot();
		RemoteRoutes::boot();
		Maintenance::boot();
		CriticalRecovery::recover_terminal_markers();
		SiteHealth::boot();

		add_action( 'cb_core_register_pages', static function (): void {
			PageRegistry::register(
				new Page(),
				[
					'foundations' => [ 'modal', 'toast', 'time-picker' ],
					'components'  => [ 'nav-tabs', 'panels', 'notices', 'form-controls' ],
				]
			);
		} );

		add_action( 'cb_backups_run_job', [ Runner::class, 'scheduled_tick' ], 10, 1 );
		add_action( 'cb_backups_scheduler_tick', [ Scheduler::class, 'run_due' ] );
		add_filter( 'cb_core_cli_register_commands', [ self::class, 'register_cli_commands' ] );
		add_action( 'cb_core_dashboard_register_cards', [ self::class, 'register_dashboard_shortcuts' ] );
		add_action( 'init', [ self::class, 'register_presentation_hooks' ], 1 );
	}


	public static function register_dashboard_shortcuts(): void {
		if ( ! class_exists( CardRegistry::class ) ) {
			return;
		}

		$base_url = admin_url( 'admin.php?page=core-blueprint-backups' );
		CardRegistry::register_shortcuts( 'core-blueprint-backups', [
			[
				'id'         => 'restore',
				'label'      => __( 'Import & Restore', 'core-blueprint-backups' ),
				'url'        => add_query_arg( 'tab', 'restore', $base_url ),
				'capability' => Capabilities::MANAGE,
				'order'      => 10,
			],
			[
				'id'         => 'schedules',
				'label'      => __( 'Schedules', 'core-blueprint-backups' ),
				'url'        => add_query_arg( 'tab', 'schedules', $base_url ),
				'capability' => Capabilities::MANAGE,
				'order'      => 20,
			],
			[
				'id'         => 'settings',
				'label'      => __( 'Settings', 'core-blueprint-backups' ),
				'url'        => add_query_arg( 'tab', 'settings', $base_url ),
				'capability' => Capabilities::MANAGE,
				'order'      => 30,
			],
		] );
	}

	public static function activate(): void {
		if ( ! defined( 'CB_CORE_FILE' ) || ! class_exists( '\\CB\\Core\\Database\\SchemaRegistry' ) || ! interface_exists( '\\CB\\Core\\Admin\\Page' ) || ! class_exists( '\\CB\\Core\\Governance\\Audit' ) || ! class_exists( '\\CB\\Core\\Governance\\EventRegistry' ) ) {
			deactivate_plugins( CB_BACKUPS_BASENAME );
			wp_die( esc_html( 'Core Blueprint Backups requires an active, compatible Core Blueprint Base installation.' ) );
		}
		if ( version_compare( PHP_VERSION, '8.4', '<' ) ) {
			deactivate_plugins( CB_BACKUPS_BASENAME );
			wp_die( esc_html__( 'Core Blueprint Backups requires PHP 8.4 or newer.', 'core-blueprint-backups' ) );
		}
		if ( ! class_exists( 'ZipArchive' ) ) {
			deactivate_plugins( CB_BACKUPS_BASENAME );
			wp_die( esc_html__( 'Core Blueprint Backups requires the PHP ZIP extension.', 'core-blueprint-backups' ) );
		}
		LocalStorage::ensure();
		// Activation occurs after Base's normal schema sweep; late registration
		// is reconciled immediately by the public SchemaRegistry boundary.
		Schema::register();
		Scheduler::ensure_cron();
	}

	public static function deactivate(): void {
		Scheduler::clear_cron();
	}

	public static function register_presentation_hooks(): void {
		foreach ( self::event_labels( [] ) as $id => $label ) {
			\CB\Core\Governance\EventRegistry::register( [ 'id' => (string) $id, 'label' => (string) $label ] );
		}
	}

	/** @param array<string,string> $labels @return array<string,string> */
	public static function event_labels( array $labels ): array {
		$labels['backups.backup.started']       = __( 'Backup started', 'core-blueprint-backups' );
		$labels['backups.backup.completed']     = __( 'Backup completed', 'core-blueprint-backups' );
		$labels['backups.backup.failed']        = __( 'Backup failed', 'core-blueprint-backups' );
		$labels['backups.backup.cancelled']     = __( 'Backup cancelled', 'core-blueprint-backups' );
		$labels['backups.backup.verify.started'] = __( 'Backup verification started', 'core-blueprint-backups' );
		$labels['backups.backup.verified']      = __( 'Backup verified', 'core-blueprint-backups' );
		$labels['backups.backup.verify.failed'] = __( 'Backup verification failed', 'core-blueprint-backups' );
		$labels['backups.backup.downloaded']    = __( 'Backup downloaded', 'core-blueprint-backups' );
		$labels['backups.backup.deleted']       = __( 'Backup deleted', 'core-blueprint-backups' );
		$labels['backups.backup.bulk.deleted']  = __( 'Backups deleted in bulk', 'core-blueprint-backups' );
		$labels['backups.restore.started']      = __( 'Restore started', 'core-blueprint-backups' );
		$labels['backups.restore.completed']       = __( 'Restore completed', 'core-blueprint-backups' );
		$labels['backups.restore.failed']          = __( 'Restore failed', 'core-blueprint-backups' );
		$labels['backups.restore.cleanup.warning'] = __( 'Restore recovery cleanup warning', 'core-blueprint-backups' );
		$labels['backups.schedules.updated']    = __( 'Backup schedules updated', 'core-blueprint-backups' );
		$labels['backups.schedule.delayed']     = __( 'Scheduled backup delayed', 'core-blueprint-backups' );
		$labels['backups.schedule.retry.scheduled'] = __( 'Scheduled backup retry planned', 'core-blueprint-backups' );
		$labels['backups.schedule.long.running'] = __( 'Scheduled backup running longer than baseline', 'core-blueprint-backups' );
		$labels['backups.stale.job.recovery']    = __( 'Backup stale job recovery attempted', 'core-blueprint-backups' );
		$labels['backups.retention.applied']      = __( 'Automatic backup retention applied', 'core-blueprint-backups' );
		$labels['backups.retention.failed']       = __( 'Automatic backup retention failed', 'core-blueprint-backups' );
		$labels['backups.scheduler.error']        = __( 'Backup scheduler error', 'core-blueprint-backups' );
		$labels['backups.housekeeping.cleanup'] = __( 'Backup housekeeping cleanup', 'core-blueprint-backups' );
		$labels['backups.import.prepared']       = __( 'Backup import prepared', 'core-blueprint-backups' );
		$labels['backups.import.deleted']        = __( 'Prepared backup import deleted', 'core-blueprint-backups' );
		$labels['backups.import.cancelled']      = __( 'Backup import cancelled', 'core-blueprint-backups' );
		return $labels;
	}

	/** @param array<int,array<string,string>> $commands @return array<int,array<string,string>> */
	public static function register_cli_commands( array $commands ): array {
		$commands[] = [ 'name' => 'backup create', 'class' => CLI\Create::class, 'description' => 'Create a Core Blueprint backup.' ];
		$commands[] = [ 'name' => 'backup list', 'class' => CLI\ListBackups::class, 'description' => 'List local Core Blueprint backups.' ];
		$commands[] = [ 'name' => 'backup run-due', 'class' => CLI\RunDue::class, 'description' => 'Start due backup schedules and advance active jobs.' ];
		$commands[] = [ 'name' => 'backup verify', 'class' => CLI\Verify::class, 'description' => 'Verify a local Core Blueprint backup.' ];
		return $commands;
	}
}
