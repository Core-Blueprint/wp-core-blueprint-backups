<?php
declare(strict_types=1);

define( 'ABSPATH', __DIR__ . '/' );

function __( string $text, ?string $domain = null ): string { return $text; }

require dirname( __DIR__ ) . '/src/Admin/JobPresentation.php';

use CB\Backups\Admin\JobPresentation;

function monitor_assert( bool $condition, string $message ): void {
	if ( ! $condition ) {
		throw new RuntimeException( $message );
	}
}

$mb = 1024 * 1024;
$base_meta = [
	'restore_mode'               => 'migration',
	'restore_verify_total'       => 15803,
	'restore_verify_done'        => 15803,
	'restore_extract_files_done' => 15803,
	'restore_extract_bytes_done' => 494 * $mb,
	'stage_verify_done'          => 15803,
	'live_verify_done'           => 12000,
	'live_verify_total'          => 15801,
	'manifest'                   => [
		'filesystem' => [
			'file_count' => 15801,
			'bytes'      => 462 * $mb,
		],
	],
];
$base_data = [
	'files_done'       => 15803,
	'files_total'      => 15801,
	'files_bytes_done' => 494 * $mb,
	'files_bytes_total'=> 462 * $mb,
];

$job = [
	'kind'        => 'restore',
	'backup_type' => 'website',
	'status'      => 'running',
	'stage'       => 'verify_database_content',
	'meta'        => $base_meta,
];
$presented = JobPresentation::present( $job, $base_data );
monitor_assert( 'Verifying restored database…' === $presented['stage_label'], 'Database verification stage must be human-readable.' );
monitor_assert( 'Files staged' === $presented['file_metric']['label'], 'Database verification must present staged filesystem files.' );
monitor_assert( 15801 === $presented['file_metric']['done'] && 15801 === $presented['file_metric']['total'], 'Filesystem metric must not compare extraction payloads with wp-content totals.' );
monitor_assert( 462 * $mb === $presented['byte_metric']['done'] && 0 === $presented['byte_metric']['total'], 'Filesystem snapshot bytes must be an absolute metric outside extraction.' );

$job['stage'] = 'extract';
$presented = JobPresentation::present( $job, $base_data );
monitor_assert( 'Payloads extracted' === $presented['file_metric']['label'], 'Extraction metric must describe archive payloads.' );
monitor_assert( 15803 === $presented['file_metric']['done'] && 15803 === $presented['file_metric']['total'], 'Extraction payload count must use the payload-domain denominator.' );
monitor_assert( 494 * $mb === $presented['byte_metric']['done'] && 0 === $presented['byte_metric']['total'], 'Extraction bytes must not be divided by filesystem-only bytes.' );

$job['stage'] = 'verify_live';
$presented = JobPresentation::present( $job, $base_data );
monitor_assert( 'Restored files verified' === $presented['file_metric']['label'], 'Live verification metric label is wrong.' );
monitor_assert( 12000 === $presented['file_metric']['done'] && 15801 === $presented['file_metric']['total'], 'Live verification must use its own verifier counters.' );

$anomaly_meta = $base_meta;
$anomaly_meta['live_verify_done'] = 15802;
$anomaly_meta['live_verify_total'] = 15801;
$job['meta'] = $anomaly_meta;
$presented = JobPresentation::present( $job, $base_data );
monitor_assert( 15802 === $presented['file_metric']['done'] && 0 === $presented['file_metric']['total'], 'Invalid ratios must fall back to absolute telemetry instead of being cosmetically clamped.' );

$job['meta'] = $base_meta;
$job['stage'] = 'finalize_live';
$job['status'] = 'completed';
$presented = JobPresentation::present( $job, $base_data );
monitor_assert( 'Migration completed' === $presented['stage_label'], 'Completed migration must override the last internal stage label.' );

$job['meta']['restore_mode'] = 'restore';
$presented = JobPresentation::present( $job, $base_data );
monitor_assert( 'Restore completed' === $presented['stage_label'], 'Completed same-site restore label is wrong.' );

echo "RC15 job presentation smoke: PASS\n";
