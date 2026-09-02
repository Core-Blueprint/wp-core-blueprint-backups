from pathlib import Path


def read(path: str) -> str:
    return Path(path).read_text(encoding='utf-8')


def write(path: str, text: str) -> None:
    Path(path).write_text(text, encoding='utf-8')


def replace_once(text: str, old: str, new: str, label: str) -> str:
    count = text.count(old)
    if count != 1:
        raise RuntimeError(f'{label}: expected one match, found {count}')
    return text.replace(old, new, 1)


# Entrypoint: remove development-only OPcache/source introspection and gettext rewrites.
path = 'core-blueprint-backups.php'
text = read(path)
start_marker = '// A plugin update can leave PHP-FPM serving stale OPcache bytecode while the\n'
final_boot_marker = "add_action( 'plugins_loaded', static function (): void {\n\t$errors = [];"
start = text.find(start_marker)
end = text.find(final_boot_marker, start)
if start < 0 or end < 0 or end <= start:
    raise RuntimeError('entrypoint cleanup markers not found')
text = text[:start] + text[end:]
write(path, text)

# Admin JS: the auth-aware dedicated job-monitor module owns polling now.
path = 'assets/js/admin.js'
text = read(path)
legacy_start = text.find("\n  const jobBox = document.getElementById('cb-backups-job');")
legacy_end = text.rfind('\n})();')
if legacy_start < 0 or legacy_end < 0 or legacy_end <= legacy_start:
    raise RuntimeError('legacy admin monitor block markers not found')
text = text[:legacy_start] + '\n})();\n'
write(path, text)

# Remove obsolete AJAX monitor aliases; JobMonitor is the canonical read endpoint.
path = 'src/Admin/Actions.php'
text = read(path)
for line in [
    "\t\tadd_action( 'wp_ajax_cb_backups_tick_job', [ self::class, 'job_status' ] ); // Backward-compatible alias.\n",
    "\t\tadd_action( 'wp_ajax_cb_backups_job_status', [ self::class, 'job_status' ] );\n",
]:
    if line not in text:
        raise RuntimeError(f'missing obsolete AJAX registration: {line.strip()}')
    text = text.replace(line, '', 1)
method_start = text.find('\n\tpublic static function job_status(): void {')
method_end = text.find('\n\tpublic static function cancel_job(): void {', method_start)
if method_start < 0 or method_end < 0:
    raise RuntimeError('obsolete job_status method markers not found')
text = text[:method_start] + text[method_end:]
write(path, text)

# Assets: remove labels that only served the retired monitor embedded in admin.js.
path = 'src/Admin/Assets.php'
text = read(path)
for line in [
    "\t\t\t\t'completed'        => __( 'Completed', 'core-blueprint-backups' ),\n",
    "\t\t\t\t'failed'           => __( 'Failed', 'core-blueprint-backups' ),\n",
    "\t\t\t\t'cancelled'        => __( 'Cancelled', 'core-blueprint-backups' ),\n",
    "\t\t\t\t'cancel'           => __( 'Cancel backup', 'core-blueprint-backups' ),\n",
    "\t\t\t\t'cancelTitle'      => __( 'Cancel backup?', 'core-blueprint-backups' ),\n",
    "\t\t\t\t'cancelConfirm'    => __( 'The current safe chunk will finish first. Incomplete backup data will then be removed.', 'core-blueprint-backups' ),\n",
    "\t\t\t\t'cancelRequested'  => __( 'Backup cancellation requested.', 'core-blueprint-backups' ),\n",
    "\t\t\t\t'backupCompleted'  => __( 'Backup completed successfully.', 'core-blueprint-backups' ),\n",
    "\t\t\t\t'restoreCompleted' => __( 'Restore completed successfully.', 'core-blueprint-backups' ),\n",
]:
    if line not in text:
        raise RuntimeError(f'missing retired admin label: {line.strip()}')
    text = text.replace(line, '', 1)
write(path, text)

# Remote routes: current Beacon RemoteRouteRegistry is the sole v1 bridge.
path = 'src/Remote/Routes.php'
text = read(path)
text = replace_once(
    text,
    "\t\tadd_action( 'cb_core_beacon_register_remote_routes', [ self::class, 'register' ], 10, 1 );\n\t\tadd_filter( 'cb_core_beacon_remote_route_prefixes', [ self::class, 'legacy_route_prefixes' ] );\n",
    "\t\tadd_action( 'cb_core_beacon_register_remote_routes', [ self::class, 'register' ], 10, 0 );\n",
    'remote boot bridge',
)
legacy_method_start = text.find('\n\t/** @param string[] $prefixes @return string[] */\n\tpublic static function legacy_route_prefixes')
register_start = text.find('\n\tpublic static function register(', legacy_method_start)
if legacy_method_start < 0 or register_start < 0:
    raise RuntimeError('legacy route-prefix method markers not found')
text = text[:legacy_method_start] + text[register_start:]
text = replace_once(text, 'public static function register( ?callable $legacy_permission_callback = null ): void {', 'public static function register(): void {', 'remote register signature')
text = text.replace(', $legacy_permission_callback );', ' );')
control_start = text.find('\n\t/** @param array<string,mixed> $args */\n\tprivate static function control_route(')
actor_start = text.find('\n\t/** @return array<string,string> */\n\tprivate static function actor_context', control_start)
if control_start < 0 or actor_start < 0:
    raise RuntimeError('control_route block markers not found')
replacement = "\n\t/** @param array<string,mixed> $args */\n\tprivate static function control_route( string $route, array $args ): void {\n\t\tif ( class_exists( RemoteRouteRegistry::class ) ) {\n\t\t\tRemoteRouteRegistry::register( $route, $args, self::PREFIX );\n\t\t}\n\t}\n"
text = text[:control_start] + replacement + text[actor_start:]
if 'legacy_permission_callback' in text or 'legacy_route_prefixes' in text or 'cb_core_beacon_remote_route_prefixes' in text:
    raise RuntimeError('legacy Beacon route bridge still present')
write(path, text)

# Permanent CI should follow the supported release line rather than old feature branches.
path = '.github/workflows/php-lint.yml'
text = read(path)
text = replace_once(text, "    branches:\n      - rc15-migration-restore-v1", "    branches:\n      - main", 'php-lint push branch')
text = text.replace('Run RC15 migration smoke test', 'Run migration smoke test')
text = text.replace('Run RC15 filesystem policy smoke test', 'Run filesystem policy smoke test')
text = text.replace('Run RC15 filesystem commit policy smoke test', 'Run filesystem commit policy smoke test')
text = text.replace('Run RC15 job presentation smoke test', 'Run job presentation smoke test')
text = text.replace('Run RC15 job monitor contract smoke test', 'Run job monitor contract smoke test')
write(path, text)

path = '.github/workflows/database-fidelity.yml'
text = read(path)
text = replace_once(text, '    branches: [rc15-migration-restore-v1]', '    branches: [main]', 'database fidelity push branch')
write(path, text)

path = '.github/workflows/database-timezone-portability.yml'
text = read(path)
text = replace_once(text, '    branches: [fix/database-timezone-portability]', '    branches: [main]', 'timezone push branch')
text = replace_once(text, "      - name: Install PHP database extensions\n        run: sudo apt-get update && sudo apt-get install -y php-cli php-mysql php-mbstring php-xml php-zip", "      - name: Set up supported PHP runtime\n        uses: shivammathur/setup-php@v2\n        with:\n          php-version: '8.4'\n          extensions: mysqli, mbstring, dom, zip\n          coverage: none", 'timezone PHP setup')
text = replace_once(text, 'https://wordpress.org/wordpress-6.8.3.tar.gz', 'https://wordpress.org/wordpress-7.0.tar.gz', 'timezone WordPress baseline')
write(path, text)

print('BACKUPS_LAUNCH_QC_REFRESH_OK')
