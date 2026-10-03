<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Governance {
	final class Audit {
		public static array $events = [];
		public static function record(string $event, string $severity, array $context): bool { self::$events[] = compact('event', 'severity', 'context'); return true; }
	}
}
namespace CB\Backups\Jobs {
	final class Repository {
		public static array $jobs = [];
		public static function has_active(): bool { return false; }
		public static function create(string $kind, string $type, string $trigger, array $meta): array {
			$job = ['job_id' => 'job-' . count(self::$jobs), 'kind' => $kind, 'backup_type' => $type, 'trigger_source' => $trigger, 'meta' => $meta];
			self::$jobs[$job['job_id']] = $job; return $job;
		}
		public static function update(string $id, array $data): void { self::$jobs[$id] = array_replace(self::$jobs[$id], $data); }
		public static function complete(string $id): void { self::$jobs[$id]['status'] = 'completed'; }
	}
	final class Dispatcher { public static int $calls = 0; public static function dispatch(string $id): void { ++self::$calls; } }
}
namespace CB\Backups\Storage {
	final class LocalStorage {
		public static string $archive;
		public static function archive_path(string $name): string { return self::$archive; }
		public static function import_path(string $name): string { return self::$archive; }
		public static function is_inside_storage(string $path): bool { return $path === self::$archive; }
		public static function base_path(): string { return dirname(self::$archive); }
		public static function work_dir(string $id): string { return dirname(self::$archive); }
		public static function remove_tree(string $dir): void {}
	}
}
namespace CB\Backups\Restore {
	final class ArchiveValidator {
		public static function validate(string $path, bool $full): array { return ['backup_type' => $GLOBALS['type']]; }
		public static function estimate_uncompressed_bytes(string $path): int { return 0; }
		public static function database_payload_bytes(string $path): int { return 0; }
	}
	final class MigrationPlan {
		public static function build(array $manifest): array { return ['mode' => $GLOBALS['mode'], 'requires_migration' => 'migration' === $GLOBALS['mode'], 'source_site_url' => 'https://source.test', 'target_site_url' => 'https://target.test', 'source_prefix' => 'src_', 'target_prefix' => 'dst_']; }
	}
	final class DatabaseImporter { public static function cleanup_recovery(array $meta, array $tables, string $id): void {} }
	final class Maintenance { public static function deactivate(): void {} }
}
namespace {
	define('ABSPATH', __DIR__ . '/'); define('WP_CONTENT_DIR', sys_get_temp_dir());
	function __(string $s, string $domain = ''): string { return $s; }
	function esc_html__(string $s, string $domain = ''): string { return $s; }
	function get_current_user_id(): int { return $GLOBALS['actor']; }
	function wp_get_current_user(): object { return (object) ['user_login' => 'original-operator']; }
	function wp_normalize_path(string $s): string { return $s; }
	function current_user_can(string $cap): bool { return $GLOBALS['allowed']; }
	function check_admin_referer(string $action): void { if (!$GLOBALS['nonce']) throw new RuntimeException('Invalid nonce'); }
	function wp_die(string $message, mixed $code = null): never { throw new RuntimeException('Permission denied'); }
	function sanitize_file_name(string $s): string { return basename($s); }
	function wp_unslash(string $s): string { return $s; }
	function admin_url(string $path): string { return 'https://target.test/wp-admin/' . $path; }
	function add_query_arg(array $args, string $url): string { return $url . '?' . http_build_query($args); }
	function wp_safe_redirect(string $url): never { throw new RuntimeException('Test redirect'); }
	function wp_schedule_single_event(int $at, string $hook, array $args): void {}
	function wp_cache_flush(): void {}
	function delete_option(string $key): void {}
	function verify(bool $condition, string $message): void { if (!$condition) throw new RuntimeException($message); }

	$root = dirname(__DIR__);
	require $root . '/src/Support/Capabilities.php';
	require $root . '/src/Support/Audit.php';
	require $root . '/src/Restore/Service.php';
	require $root . '/src/Restore/Engine.php';
	require $root . '/src/Admin/Actions.php';

	use CB\Backups\Admin\Actions;
	use CB\Backups\Jobs\Repository;
	use CB\Backups\Jobs\Dispatcher;
	use CB\Backups\Restore\Service;
	use CB\Backups\Restore\Engine;
	use CB\Backups\Storage\LocalStorage;
	use CoreBlueprint\Core\Governance\Audit;

	$GLOBALS['actor'] = 42; $GLOBALS['allowed'] = true; $GLOBALS['nonce'] = true;
	$GLOBALS['type'] = 'database'; $GLOBALS['mode'] = 'restore';
	LocalStorage::$archive = tempnam(sys_get_temp_dir(), 'cb-confirm-');
	try {
		foreach (['restore', 'restore_import'] as $action) {
			foreach ([null, '', '0', 'on', 'true', 1, true, ['1']] as $invalid) {
				$_POST = ['archive' => 'fixture.cbbackup', 'restore_acknowledged' => $invalid];
				try { Actions::$action(); } catch (RuntimeException $e) {}
				verify([] === Repository::$jobs && [] === Audit::$events && 0 === Dispatcher::$calls, 'Missing/malformed acknowledgement created or dispatched a restore.');
			}
			foreach (['allowed', 'nonce'] as $boundary) {
				$GLOBALS[$boundary] = false; $_POST['restore_acknowledged'] = '1';
				try { Actions::$action(); } catch (RuntimeException $e) {}
				verify([] === Repository::$jobs && [] === Audit::$events, 'Acknowledgement bypassed permissions or nonce.');
				$GLOBALS[$boundary] = true;
			}
		}
		$GLOBALS['actor'] = 0;
		try { Service::create(LocalStorage::$archive, 'manual', true); } catch (RuntimeException $e) {}
		verify([] === Repository::$jobs, 'Acknowledgement without a signed-in actor was accepted.');
		$GLOBALS['actor'] = 42;
		foreach (['restore', 'restore_import'] as $action) {
			$GLOBALS['mode'] = 'restore_import' === $action ? 'migration' : 'restore';
			$GLOBALS['type'] = 'restore_import' === $action ? 'website' : 'database';
			$_POST = ['archive' => 'fixture.cbbackup', 'restore_acknowledged' => '1', 'user_id' => 999, 'confirmed_at' => 'forged', 'target_site' => 'https://forged.test'];
			$before = count(Repository::$jobs);
			try { Actions::$action(); } catch (RuntimeException $e) {}
			verify(count(Repository::$jobs) === $before + 1, 'Explicit agreement did not create exactly one job.');
			$job = array_values(Repository::$jobs)[$before];
			$receipt = $job['meta']['restore_confirmation'];
			verify(true === $receipt['accepted'] && 42 === $receipt['user_id'] && 'original-operator' === $receipt['user_login'], 'Receipt actor must come from the authenticated request.');
			verify('replace-live-data-v1' === $receipt['version'] && abs(time() - strtotime($receipt['confirmed_at'])) < 5, 'Receipt must record statement version and server UTC time.');
			verify('https://target.test' === $receipt['target_site'] && basename(LocalStorage::$archive) === $receipt['archive'], 'Receipt scope must come from the validated archive and server plan.');
			verify($GLOBALS['type'] === $receipt['type'] && $GLOBALS['mode'] === $receipt['mode'], 'Receipt has wrong restore scope.');
			$event = Audit::$events[array_key_last(Audit::$events)];
			verify('backups.restore.started' === $event['event'] && $receipt === $event['context']['confirmation'], 'Start audit event lost the explicit agreement.');
			// Simulate the old audit table disappearing and a different worker actor.
			Audit::$events = []; $GLOBALS['actor'] = 99;
			(new ReflectionMethod(Engine::class, 'complete_restore'))->invoke(null, $job, $job['meta'], [], false);
			$event = Audit::$events[0];
			verify('backups.restore.completed' === $event['event'] && $receipt === $event['context']['confirmation'], 'Completion must re-record the original agreement after the database swap.');
			verify($receipt === Repository::$jobs[$job['job_id']]['meta']['restore_confirmation'], 'Completion lost the durable job receipt.');
			$GLOBALS['actor'] = 42;
		}
		echo "Restore acknowledgement: handler rejection, authenticated scope, job receipt and post-switch audit: PASS\n";
	} finally { unlink(LocalStorage::$archive); }
}
