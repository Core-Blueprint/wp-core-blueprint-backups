<?php
declare(strict_types=1);

$root = dirname( __DIR__ );

function cb_backups_settings_hub_read( string $relative ): string {
	$contents = file_get_contents( $GLOBALS['root'] . '/' . $relative );
	if ( false === $contents ) {
		fwrite( STDERR, "FAIL: could not read {$relative}.\n" );
		exit( 1 );
	}
	return $contents;
}

function cb_backups_settings_hub_expect( bool $condition, string $message ): void {
	if ( $condition ) {
		return;
	}
	fwrite( STDERR, "FAIL: {$message}\n" );
	exit( 1 );
}

$plugin       = cb_backups_settings_hub_read( 'core-blueprint-backups.php' );
$bootstrap    = cb_backups_settings_hub_read( 'src/Bootstrap.php' );
$page         = cb_backups_settings_hub_read( 'src/Admin/Page.php' );
$settings     = cb_backups_settings_hub_read( 'src/Admin/Settings.php' );
$assets       = cb_backups_settings_hub_read( 'src/Admin/Assets.php' );
$actions      = cb_backups_settings_hub_read( 'src/Admin/Actions.php' );
$scheduler    = cb_backups_settings_hub_read( 'src/Schedule/Scheduler.php' );
$capabilities = cb_backups_settings_hub_read( 'src/Support/Capabilities.php' );

// Release/dependency baseline.
cb_backups_settings_hub_expect( str_contains( $plugin, 'Version:     1.0.0-rc1' ), 'Backups must remain on 1.0.0-rc1.' );
cb_backups_settings_hub_expect( str_contains( $plugin, "define( 'CB_BACKUPS_VERSION', '1.0.0-rc1' );" ), 'Runtime version must remain 1.0.0-rc1.' );
cb_backups_settings_hub_expect( str_contains( $plugin, 'SettingsRegistry' ), 'Runtime dependency gate must require the canonical SettingsRegistry contract.' );

// Operational Backups workspace remains registered and authoritative.
cb_backups_settings_hub_expect( str_contains( $bootstrap, "add_action( 'cb_core_register_pages'" ), 'Backups operational workspace must remain registered through PageRegistry.' );
cb_backups_settings_hub_expect( str_contains( $bootstrap, 'PageRegistry::register(' ), 'Backups operational workspace must remain a Base admin page.' );
cb_backups_settings_hub_expect( str_contains( $bootstrap, "'menu_url'     => admin_url( 'admin.php?page=core-blueprint-backups' )" ), 'Extension menu URL must remain operational.' );
cb_backups_settings_hub_expect( str_contains( $bootstrap, "'url'      => admin_url( 'admin.php?page=core-blueprint-backups' )" ), 'Health/status URL must remain operational.' );
cb_backups_settings_hub_expect( str_contains( $page, "[ 'backups', 'restore', 'schedules' ]" ), 'Operational page must retain Backups, Restore and Schedules routes.' );
cb_backups_settings_hub_expect( str_contains( $page, "case 'restore':" ) && str_contains( $page, "case 'schedules':" ), 'Restore and Schedules renderers must remain operational.' );
cb_backups_settings_hub_expect( ! str_contains( $page, "case 'settings':" ), 'Old operational Settings route must be removed.' );
cb_backups_settings_hub_expect( ! str_contains( $page, "'settings'  => __( 'Settings'" ), 'Old operational Settings tab must be removed.' );
cb_backups_settings_hub_expect( ! str_contains( $page, 'settings_tab()' ), 'Old settings renderer must not remain duplicated in the operational page.' );

// Canonical Extensions provider.
cb_backups_settings_hub_expect( str_contains( $bootstrap, "add_action( 'cb_core_register_settings'" ), 'Backups settings must register through the Base settings lifecycle.' );
cb_backups_settings_hub_expect( str_contains( $bootstrap, 'SettingsRegistry::register(' ), 'Backups settings must use SettingsRegistry.' );
cb_backups_settings_hub_expect( str_contains( $bootstrap, "'core-blueprint-backups'" ), 'Settings provider must use the canonical extension identity.' );
cb_backups_settings_hub_expect( str_contains( $bootstrap, 'SettingsRegistry::GROUP_INFRASTRUCTURE' ), 'Backups settings must remain in Infrastructure.' );
cb_backups_settings_hub_expect( str_contains( $bootstrap, "'capability'  => Capabilities::MANAGE" ), 'Settings provider must preserve the Backups capability.' );
cb_backups_settings_hub_expect( str_contains( $bootstrap, "'renderer'    => [ Settings::class, 'render' ]" ), 'Settings provider must use the Backups-owned diagnostics renderer.' );
cb_backups_settings_hub_expect( str_contains( $bootstrap, "SettingsRegistry::url( 'core-blueprint-backups' )" ), 'Settings-specific dashboard deep link must use the canonical SettingsRegistry URL.' );
cb_backups_settings_hub_expect( ! str_contains( $bootstrap, "add_query_arg( 'tab', 'settings', $base_url )" ), 'Old settings deep link must be removed.' );

// Existing diagnostic semantics are preserved in the new provider.
foreach ( [ 'Storage', 'Storage location', 'PHP ZIP', 'Background execution', 'WP-Cron', 'CB_BACKUPS_STORAGE_PATH' ] as $needle ) {
	cb_backups_settings_hub_expect( str_contains( $settings, $needle ), "Settings diagnostics must preserve {$needle}." );
}

// Assets: settings provider gets only plugin settings styling; operation monitor stays operational.
cb_backups_settings_hub_expect( str_contains( $assets, "PageRegistry::hook_suffix( 'core-blueprint-backups' )" ), 'Operational asset scope must remain PageRegistry-based.' );
cb_backups_settings_hub_expect( str_contains( $assets, "SettingsRegistry::url( 'core-blueprint-backups' )" ), 'Settings asset scope must derive from the canonical SettingsRegistry URL.' );
cb_backups_settings_hub_expect( str_contains( $assets, "'core-blueprint-backups' !== $extension" ), 'Settings assets must verify the Backups provider identity.' );
$settings_return = strpos( $assets, "if ( $is_settings ) {\n\t\t\treturn;" );
$job_monitor     = strpos( $assets, "'cb-backups-job-monitor'" );
cb_backups_settings_hub_expect( false !== $settings_return && false !== $job_monitor && $settings_return < $job_monitor, 'Settings provider must return before operational job-monitor assets are enqueued.' );

// Security and schedule authority remain unchanged and operational.
cb_backups_settings_hub_expect( str_contains( $capabilities, "public const MANAGE = 'cb_manage_backups';" ), 'Canonical high-impact capability must remain cb_manage_backups.' );
cb_backups_settings_hub_expect( str_contains( $actions, "add_action( 'admin_post_cb_backups_save_schedules'" ), 'Schedule save handler must remain operational.' );
cb_backups_settings_hub_expect( str_contains( $actions, 'Scheduler::save( $input );' ), 'Schedule validation/save authority must remain Scheduler::save().' );
cb_backups_settings_hub_expect( str_contains( $scheduler, "private const OPTION = 'cb_backups_schedules';" ), 'Schedule option key must remain unchanged.' );
cb_backups_settings_hub_expect( str_contains( $scheduler, "private const STATE_OPTION = 'cb_backups_scheduler_state';" ), 'Scheduler state option key must remain unchanged.' );

fwrite( STDOUT, "Backups Extensions Hub source regression: PASS\n" );
