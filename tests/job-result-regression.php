<?php
declare(strict_types=1);

namespace CB\Core\Admin { interface Page {} }
namespace CB\Core\UI {
	final class Notice {
		public const SUCCESS = 'success'; public const WARNING = 'warning'; public const ERROR = 'error';
		public static function render( array $args ): string { return '<aside class="' . $args['variant'] . '">' . htmlspecialchars( $args['message'] ) . '</aside>'; }
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
	function sanitize_text_field( string $text ): string { return $text; }
	function wp_unslash( string $text ): string { return $text; }
	function wp_kses( string $text, array $tags ): string { return $text; }
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
	$current = new ReflectionMethod(Page::class, 'current_job');
	$render = new ReflectionMethod(Page::class, 'job_progress');
	$_GET = ['job' => 'restore-1'];
	check_result( 'completed' === $current->invoke($page)['status'], 'Explicit terminal job must survive a page reload.' );
	ob_start(); $render->invoke($page); $html = ob_get_clean();
	check_result( str_contains($html, '<aside class="success">Migration completed</aside>'), 'Completion must render without JavaScript or a toast.' );
	Repository::$jobs['restore-1']['status'] = 'failed';
	Repository::$jobs['restore-1']['error_text'] = 'Database integrity fixture failure';
	$_GET['cb_notice'] = 'migration_completed';
	ob_start(); $render->invoke($page); $html = ob_get_clean();
	check_result( ! str_contains($html, '<aside class="success">') && str_contains($html, 'Database integrity fixture failure'), 'URL notice must not override the stored failure.' );
	$_GET = [];
	check_result( null === $current->invoke($page), 'Historical results must not reopen without an explicit job.' );
	Repository::$jobs['active-2'] = array_replace($job, ['job_id'=>'active-2','status'=>'running']);
	check_result( 'active-2' === $current->invoke($page)['job_id'], 'Active jobs must still appear automatically.' );
	echo "Job result rendering and authorization regressions: PASS\n";
}
