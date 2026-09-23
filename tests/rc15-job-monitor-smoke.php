<?php
declare(strict_types=1);

function monitor_contract_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$root = dirname( __DIR__ );
$endpoint = (string) file_get_contents( $root . '/src/Admin/JobMonitor.php' );
$assets = (string) file_get_contents( $root . '/src/Admin/Assets.php' );
$monitor = (string) file_get_contents( $root . '/assets/js/job-monitor.js' );
$terminal = (string) file_get_contents( $root . '/assets/js/job-terminal-recovery.js' );
$handoff = (string) file_get_contents( $root . '/assets/js/job-handoff.js' );
$monitor_css = (string) file_get_contents( $root . '/assets/css/job-monitor.css' );
$migration_recovery = (string) file_get_contents( $root . '/src/Integration/MigrationRecovery.php' );

monitor_contract_assert( str_contains( $endpoint, 'wp_ajax_nopriv_cb_backups_job_monitor' ), 'Monitor must expose an unauthenticated JSON boundary for auth-loss detection.' );
monitor_contract_assert( str_contains( $endpoint, "'auth_required'" ), 'Monitor must classify lost WordPress authentication.' );
monitor_contract_assert( str_contains( $endpoint, "'nonce_expired'" ), 'Monitor must classify stale monitor nonces separately.' );
monitor_contract_assert( str_contains( $endpoint, "'capability_required'" ), 'Monitor must classify permission loss separately.' );
monitor_contract_assert( str_contains( $assets, "'jobId'   => ''," ), 'Legacy admin.js job polling must remain disabled when the dedicated monitor is active.' );
monitor_contract_assert( str_contains( $assets, '@cb-backups/job-monitor' ), 'Dedicated job monitor module is not enqueued.' );
monitor_contract_assert( str_contains( $assets, '@cb-backups/job-terminal-recovery' ), 'Terminal re-login recovery module is not enqueued.' );
monitor_contract_assert( str_contains( $assets, 'cb_monitor_reconnect' ), 'Reconnect URL must preserve a monitor handoff marker.' );
monitor_contract_assert( str_contains( $assets, "'loginUrl'" ) && str_contains( $assets, 'MigrationRecovery::login_url' ), 'Migration handoff must receive its canonical sign-in URL from Base-owned recovery.' );
monitor_contract_assert( str_contains( $assets, "add_filter( 'wp_auth_check_load'" ) && str_contains( $assets, 'MigrationRecovery::required( $meta ) ? false : $show' ), 'Cross-site migration must disable the native interim-login iframe in favour of top-level recovery.' );

$auth_branch = strpos( $monitor, "code === 'auth_required'" );
$network_retry = strpos( $monitor, 'schedulePoll(3000)' );
monitor_contract_assert( false !== $auth_branch && str_contains( substr( $monitor, $auth_branch, 260 ), 'pollingStopped = true' ), 'Auth loss must stop the invalid polling loop.' );
monitor_contract_assert( false !== $network_retry, 'Transient monitor failures must retain an automatic retry path.' );
monitor_contract_assert( str_contains( $monitor, "code === 'nonce_expired'" ), 'Nonce expiry must have its own monitor branch.' );
monitor_contract_assert( str_contains( $monitor, 'config.reconnectUrl' ), 'Auth handoff must return through the same job reconnect URL.' );
monitor_contract_assert( str_contains( $monitor, "config.loginUrl || ''" ) && str_contains( $monitor, 'config.labels?.secureSignIn' ), 'Migration authentication loss must offer Base recovery instead of depending on a migrated custom login path.' );
monitor_contract_assert( str_contains( $monitor, 'config.recoveryPrepareAction' ) && str_contains( $monitor, 'config.recoveryFinalizeAction' ) && str_contains( $monitor, 'recovery.requires_probe' ) && str_contains( $monitor, 'recovery.probe_url' ), 'Migration monitor must prepare and verify the destination before final completion.' );
monitor_contract_assert( str_contains( $migration_recovery, "'migration_recovery_auth_required'" ), 'Quarantined migration recovery requests must return an explicit secure-sign-in code.' );
monitor_contract_assert( str_contains( $monitor, "['migration_recovery_auth_required', 'auth_required', 'capability_required']" ) && str_contains( $monitor, 'showSecureMigrationSignIn' ), 'Await-recovery permission loss must hand off to the Base secure login instead of the generic reconnect URL.' );
monitor_contract_assert( str_contains( $monitor, "actionHref = null" ) && str_contains( $monitor, "actionHref === null ? (config.reconnectUrl || '') : actionHref" ), 'Explicit migration handoff URLs must never silently fall back to the generic reconnect URL.' );
monitor_contract_assert( str_contains( $monitor, "if (!config.loginUrl)" ) && str_contains( $monitor, "config.loginUrl\n    );" ), 'Secure migration sign-in must fail closed when the Base recovery login URL is unavailable.' );

monitor_contract_assert( str_contains( $monitor, 'probe.status !== 204' ), 'Pretty-routing migrations must require a successful browser rewrite probe.' );
monitor_contract_assert( str_contains( $migration_recovery, 'BaseRecovery::requires_pretty_routing' ) && str_contains( $migration_recovery, 'BaseRecovery::finalize' ), 'Backups must defer routing policy and trust finalization to Base.' );
monitor_contract_assert( ! str_contains( $handoff, 'wp-auth-check' ) && ! str_contains( $monitor_css, 'cb-backups-auth-context' ), 'Migration handoff must not depend on the clipped WordPress interim-login overlay.' );
monitor_contract_assert( str_contains( $monitor, "event.persisted" ) && str_contains( $monitor, 'window.location.reload()' ), 'BFCache restoration must refresh stale job/nonces.' );

monitor_contract_assert( str_contains( $terminal, "if (document.getElementById('cb-backups-job')) return;" ), 'Terminal probe must never compete with the active job monitor.' );
monitor_contract_assert( str_contains( $terminal, "'completed', 'failed', 'cancelled'" ), 'Terminal probe must recover every terminal outcome after re-login.' );
monitor_contract_assert( str_contains( $terminal, "url.searchParams.set('job', jobId)" ), 'Terminal probe must preserve the job for the server-rendered result.' );
monitor_contract_assert( str_contains( $terminal, "action: 'cb_backups_job_monitor'" ), 'Terminal probe must fetch fresh server status with the new nonce.' );

echo "RC15 job monitor smoke: PASS\n";
