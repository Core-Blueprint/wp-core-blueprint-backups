<?php
declare(strict_types=1);

namespace CoreBlueprint\Core\Admin { interface Page {} }
namespace CoreBlueprint\Core\UI {
	final class Notice {
		public const SUCCESS = 'success'; public const WARNING = 'warning'; public const ERROR = 'error';
		public static array $calls = [];
		public static function render( array $args ): string {
			self::$calls[] = $args;
			$title = empty($args['title']) ? '' : '<strong>' . htmlspecialchars($args['title']) . '</strong>';
			$items = implode('', array_map(static fn($item) => '<li>' . htmlspecialchars($item) . '</li>', $args['items'] ?? []));
			return '<aside class="' . $args['variant'] . '">' . $title . htmlspecialchars( $args['message'] ) . $items . '</aside>';
		}
	}
}
namespace CB\Backups\Jobs {
	final class Repository {
		public static array $jobs = []; public static int $reads = 0;
		public static function get( string $id ): ?array { ++self::$reads; return self::$jobs[$id] ?? null; }
		public static function active( int $limit ): array { return array_slice( array_values( array_filter( self::$jobs, static fn( $j ) => in_array( $j['status'], ['queued', 'running', 'cancelling'], true ) ) ), 0, $limit ); }
	}
}
namespace CB\Backups\Support {
	final class Telemetry { public static function format_duration( int $seconds ): string { return (string) $seconds; } }
}
namespace CB\Backups\Admin {
	final class Actions {
		public static function public_job( array $job ): array {
			return $job + ['error' => $job['error_text'] ?? '', 'current_table' => '', 'current_file' => '', 'elapsed_seconds' => 1, 'output_bytes' => 0, 'typical_duration_seconds' => null];
		}
	}
}
namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	function __( string $text, string $domain = '' ): string { return $text; }
	function esc_html__( string $text, string $domain = '' ): string { return htmlspecialchars( $text ); }
	function esc_attr( string $text ): string { return htmlspecialchars( $text ); }
	function esc_html( string $text ): string { return htmlspecialchars( $text ); }
	function esc_url( string $url ): string { return htmlspecialchars( $url ); }
	function admin_url( string $path = '' ): string { return 'https://destination.test/wordpress/wp-admin/' . $path; }
	function sanitize_text_field( string $text ): string { return $text; }
	function wp_unslash( string $text ): string { return $text; }
	function wp_kses( string $text, array $tags ): string { return $text; }
	function wp_kses_post( string $text ): string { return $text; }
	function size_format( int $bytes ): string { return (string) $bytes; }
	function number_format_i18n( int|float $value, int $decimals = 0 ): string { return number_format( $value, $decimals ); }
	function current_user_can( string $cap ): bool { return $GLOBALS['allowed']; }
	function wp_verify_nonce( string $nonce, string $action ): int|false { return $GLOBALS['valid_nonce'] ? 1 : false; }
	final class JsonResponse extends RuntimeException {
		public function __construct( public bool $success, public array $data, public int $status ) { parent::__construct('JSON response'); }
	}
	function wp_send_json_error( array $data, int $status ): never { throw new JsonResponse( false, $data, $status ); }
	function wp_send_json_success( array $data ): never { throw new JsonResponse( true, $data, 200 ); }
	function check_result( bool $condition, string $message ): void { if ( ! $condition ) throw new RuntimeException( $message ); }
	function response( callable $call ): JsonResponse {
		try { $call(); } catch ( JsonResponse $r ) { return $r; }
		throw new RuntimeException( 'Expected JSON response.' );
	}
	function metric_has( string $html, string $label, string $value_id, string $value ): bool {
		return str_contains( $html, 'class="cb-core-tile__label">' . $label . '</dt>' )
			&& str_contains( $html, '<span id="' . $value_id . '">' . $value . '</span>' );
	}

	$root = dirname(__DIR__);
	require $root . '/src/Support/Capabilities.php';
	require $root . '/src/Admin/JobPresentation.php';
	require $root . '/src/Admin/JobMonitor.php';
	require $root . '/src/Admin/Page.php';

	use CB\Backups\Jobs\Repository;
	use CB\Backups\Admin\JobMonitor;
	use CB\Backups\Admin\Page;

	$job = ['job_id' => 'restore-1', 'kind' => 'restore', 'backup_type' => 'database', 'status' => 'completed', 'stage' => 'completed', 'progress' => 100, 'error_text' => '', 'meta' => ['restore_mode' => 'migration']];
	Repository::$jobs['restore-1'] = $job;
	$_POST = ['nonce' => 'old', 'job_id' => 'restore-1'];
	$GLOBALS['allowed'] = false; $GLOBALS['valid_nonce'] = false;
	$r = response( [JobMonitor::class, 'status'] );
	check_result( 'capability_required' === $r->data['code'] && 0 === Repository::$reads, 'Lost permission must win over stale nonce and never read job data.' );
	$GLOBALS['allowed'] = true;
	$r = response( [JobMonitor::class, 'status'] );
	check_result( 'nonce_expired' === $r->data['code'] && 0 === Repository::$reads, 'Stale nonce must not expose job data.' );
	$GLOBALS['valid_nonce'] = true;
	$r = response( [JobMonitor::class, 'status'] );
	check_result( $r->success && 'Migration completed' === $r->data['stage_label'], 'Authorized request must return the stored migration outcome.' );
	$_POST['job_id'] = 'missing';
	$r = response( [JobMonitor::class, 'status'] );
	check_result( 404 === $r->status && 'job_not_found' === $r->data['code'], 'Missing job must not become completion.' );
	$r = response( [JobMonitor::class, 'auth_required'] );
	check_result( 401 === $r->status && 'auth_required' === $r->data['code'], 'Logged-out request must require authentication.' );

	$page = new Page();
	$ack = new ReflectionMethod(Page::class, 'restore_acknowledgement');
	foreach (['database', 'website'] as $type) {
		ob_start(); $ack->invoke($page, $type, 'https://target.test'); $ack_html = ob_get_clean();
		check_result(str_contains($ack_html, 'value=""') && str_contains($ack_html, 'https://target.test'), 'Restore acknowledgement must start empty and identify the destination.');
		check_result(('website' === $type) === str_contains($ack_html, 'site files'), 'Database-only consent must not claim files are replaced.');
	}
	$current = new ReflectionMethod(Page::class, 'current_job');
	$render = new ReflectionMethod(Page::class, 'job_progress');
	$_GET = ['job' => 'restore-1'];
	check_result( 'completed' === $current->invoke($page)['status'], 'Explicit terminal job must survive a page reload.' );
	ob_start(); $render->invoke($page); $html = ob_get_clean();
	check_result( str_contains($html, '<aside class="success">Migration completed</aside>'), 'Completion must render without JavaScript or a toast.' );
	check_result( str_contains($html, 'Next steps: save permalinks and clear caches') && str_contains($html, 'without changing your permalink structure') && str_contains($html, 'CDN caches'), 'Completed migrations must retain actionable permalink and cache guidance after reload.' );
	$followup_notices = array_values(array_filter(\CoreBlueprint\Core\UI\Notice::$calls, static fn($args) => ($args['title'] ?? '') === 'Next steps: save permalinks and clear caches'));
	check_result(1 === count($followup_notices) && 'warning' === $followup_notices[0]['variant'] && 2 === count($followup_notices[0]['items']), 'Migration follow-up must use the prominent Base attention notice with both steps.');
	check_result(str_contains($html, 'class="button button-primary cb-core-button cb-core-button--primary"'), 'Permalink action must use the Base primary button.');
	check_result( str_contains($html, 'href="https://destination.test/wordpress/wp-admin/options-permalink.php"'), 'Permalink action must use the destination admin URL, including subdirectory installations.' );
	foreach ( [array_replace($job, ['kind' => 'backup']), array_replace($job, ['meta' => ['restore_mode' => 'restore']]), array_replace($job, ['status' => 'cancelled'])] as $other_job ) {
		Repository::$jobs['restore-1'] = $other_job;
		ob_start(); $render->invoke($page); $other_html = ob_get_clean();
		check_result( ! str_contains($other_html, 'cb-backups-next-steps'), 'Migration follow-up must not appear for backups, same-site restores or cancelled jobs.' );
	}
	Repository::$jobs['restore-1'] = $job;
	// Regression from a real completed migration: extraction has two extra
	// archive payloads plus database bytes; filesystem verification is separate.
	$mb = 1024 * 1024;
	$website = array_replace($job, [
		'backup_type' => 'website', 'files_done' => 15804, 'files_total' => 15802,
		'files_bytes_done' => 494 * $mb, 'files_bytes_total' => 463 * $mb,
		'meta' => [
			'restore_mode' => 'migration', 'restore_verify_total' => 15804,
			'restore_extract_files_done' => 15804, 'restore_extract_bytes_done' => 494 * $mb,
			'live_verify_done' => 14900, 'live_verify_total' => 14900,
			'manifest' => ['filesystem' => ['file_count' => 15802, 'bytes' => 463 * $mb]],
		],
	]);
	Repository::$jobs['restore-1'] = $website;
	ob_start(); $render->invoke($page); $html = ob_get_clean();
	check_result( metric_has( $html, 'Restored files verified', 'cb-backups-files', '14,900 / 14,900' ), 'Terminal HTML must use verified filesystem counts and their label.' );
	check_result( metric_has( $html, 'Restored filesystem data', 'cb-backups-file-bytes', (string) (463 * $mb) ), 'Terminal HTML must show filesystem bytes without the extraction-byte ratio.' );
	check_result( ! str_contains($html, '15,804 / 15,802'), 'Terminal HTML mixed archive and filesystem counts.' );
	Repository::$jobs['restore-1']['stage'] = 'extract';
	Repository::$jobs['restore-1']['status'] = 'running';
	ob_start(); $render->invoke($page); $html = ob_get_clean();
	check_result( metric_has( $html, 'Payloads extracted', 'cb-backups-files', '15,804 / 15,804' ), 'Active server-rendered extraction must use the archive payload domain.' );
	check_result( metric_has( $html, 'Data extracted', 'cb-backups-file-bytes', (string) (494 * $mb) ), 'Extraction bytes must be absolute before polling starts.' );
	check_result( ! str_contains($html, 'cb-backups-next-steps'), 'Follow-up guidance must not invite navigation before migration completes.' );
	Repository::$jobs['restore-1'] = $job;
	Repository::$jobs['restore-1']['status'] = 'failed';
	Repository::$jobs['restore-1']['error_text'] = 'Database integrity fixture failure';
	$_GET['cb_notice'] = 'migration_completed';
	ob_start(); $render->invoke($page); $html = ob_get_clean();
	check_result( ! str_contains($html, '<aside class="success">') && str_contains($html, 'Database integrity fixture failure'), 'URL notice must not override the stored failure.' );
	check_result( ! str_contains($html, 'cb-backups-next-steps'), 'Failed migrations must not show completion guidance, even with a success URL flag.' );
	$_GET = [];
	check_result( null === $current->invoke($page), 'Historical results must not reopen without an explicit job.' );
	Repository::$jobs['active-2'] = array_replace($job, ['job_id'=>'active-2','status'=>'running']);
	check_result( 'active-2' === $current->invoke($page)['job_id'], 'Active jobs must still appear automatically.' );
	echo "Job result rendering and authorization regressions: PASS\n";
}
