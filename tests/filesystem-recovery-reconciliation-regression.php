<?php
declare(strict_types=1);

namespace {
	$root = sys_get_temp_dir() . '/cb-backups-fs-recovery-' . getmypid();
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'WP_CONTENT_DIR', $root . '/live' );

	function wp_normalize_path( string $path ): string { return str_replace( '\\', '/', $path ); }
	function trailingslashit( string $value ): string { return rtrim( $value, '/\\' ) . '/'; }
	function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0777, true ); }
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }

	function fs_recovery_remove_tree( string $path ): void {
		if ( ! file_exists( $path ) && ! is_link( $path ) ) {
			return;
		}
		if ( is_file( $path ) || is_link( $path ) ) {
			unlink( $path );
			return;
		}
		$items = scandir( $path );
		if ( is_array( $items ) ) {
			foreach ( $items as $item ) {
				if ( '.' === $item || '..' === $item ) {
					continue;
				}
				fs_recovery_remove_tree( $path . '/' . $item );
			}
		}
		rmdir( $path );
	}
}

namespace CB\Backups\Storage {
	final class LocalStorage {
		public static function base_path(): string { return \WP_CONTENT_DIR . '/private-backups'; }
		public static function remove_tree( string $path ): void { \fs_recovery_remove_tree( $path ); }
	}
}

namespace {
	require dirname( __DIR__ ) . '/src/Support/FilesystemPolicy.php';
	require dirname( __DIR__ ) . '/src/Restore/FilesystemCommitter.php';

	use CB\Backups\Restore\FilesystemCommitter;

	function fs_recovery_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	function fs_recovery_prepare_case( string $case, bool $had_old, bool $has_new, string $state = 'pending' ): array {
		$case_root = dirname( WP_CONTENT_DIR ) . '/' . $case;
		$work = $case_root . '/work';
		$recovery = $work . '/recovery-wp-content';
		wp_mkdir_p( WP_CONTENT_DIR );
		wp_mkdir_p( $recovery );
		$plan = [ [
			'name_b64' => base64_encode( 'uploads' ),
			'had_old'  => $had_old,
			'has_new'  => $has_new,
			'state'    => $state,
		] ];
		file_put_contents( $work . '/filesystem-commit-plan.json', wp_json_encode( $plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		return [ $case_root, $recovery ];
	}

	$test_root = dirname( WP_CONTENT_DIR );
	fs_recovery_remove_tree( $test_root );
	wp_mkdir_p( WP_CONTENT_DIR );

	// Proven crash window: journal is still pending after live -> recovery rename succeeded.
	[ $case_root, $recovery ] = fs_recovery_prepare_case( 'crash-window', true, true );
	wp_mkdir_p( WP_CONTENT_DIR . '/uploads' );
	file_put_contents( WP_CONTENT_DIR . '/uploads/original.txt', 'original-crash-window' );
	wp_mkdir_p( dirname( $recovery . '/uploads' ) );
	rename( WP_CONTENT_DIR . '/uploads', $recovery . '/uploads' );
	FilesystemCommitter::rollback( $recovery );
	fs_recovery_assert( is_file( WP_CONTENT_DIR . '/uploads/original.txt' ), 'Pending crash-window rollback must restore the original live entry.' );
	fs_recovery_assert( 'original-crash-window' === file_get_contents( WP_CONTENT_DIR . '/uploads/original.txt' ), 'Pending crash-window rollback must preserve original contents.' );
	fs_recovery_assert( ! file_exists( $recovery . '/uploads' ), 'Pending crash-window rollback must consume the recovery copy after restoring it.' );
	fs_recovery_remove_tree( WP_CONTENT_DIR . '/uploads' );
	fs_recovery_remove_tree( $case_root );

	// Case A: pending + live target present + no recovery copy means the rename did not happen.
	[ $case_root, $recovery ] = fs_recovery_prepare_case( 'untouched-live', true, true );
	wp_mkdir_p( WP_CONTENT_DIR . '/uploads' );
	file_put_contents( WP_CONTENT_DIR . '/uploads/original.txt', 'original-still-live' );
	FilesystemCommitter::rollback( $recovery );
	fs_recovery_assert( 'original-still-live' === file_get_contents( WP_CONTENT_DIR . '/uploads/original.txt' ), 'Untouched pending live entry must remain untouched.' );
	fs_recovery_assert( ! file_exists( $recovery . '/uploads' ), 'Untouched pending live entry must not create recovery data.' );
	fs_recovery_remove_tree( WP_CONTENT_DIR . '/uploads' );
	fs_recovery_remove_tree( $case_root );

	// Ambiguous pending state must fail closed without deleting either copy.
	[ $case_root, $recovery ] = fs_recovery_prepare_case( 'ambiguous-pending', true, true );
	wp_mkdir_p( WP_CONTENT_DIR . '/uploads' );
	wp_mkdir_p( $recovery . '/uploads' );
	file_put_contents( WP_CONTENT_DIR . '/uploads/live.txt', 'live-ambiguous' );
	file_put_contents( $recovery . '/uploads/recovery.txt', 'recovery-ambiguous' );
	$threw = false;
	try {
		FilesystemCommitter::rollback( $recovery );
	} catch ( \RuntimeException ) {
		$threw = true;
	}
	fs_recovery_assert( $threw, 'Ambiguous pending filesystem state must fail closed.' );
	fs_recovery_assert( 'live-ambiguous' === file_get_contents( WP_CONTENT_DIR . '/uploads/live.txt' ), 'Fail-closed reconciliation must not delete the live ambiguous copy.' );
	fs_recovery_assert( 'recovery-ambiguous' === file_get_contents( $recovery . '/uploads/recovery.txt' ), 'Fail-closed reconciliation must not delete the recovery ambiguous copy.' );
	fs_recovery_remove_tree( WP_CONTENT_DIR . '/uploads' );
	fs_recovery_remove_tree( $case_root );

	// had_old=false: a truly untouched pending entry is already at its pre-restore state.
	[ $case_root, $recovery ] = fs_recovery_prepare_case( 'no-old-untouched', false, true );
	FilesystemCommitter::rollback( $recovery );
	fs_recovery_assert( ! file_exists( WP_CONTENT_DIR . '/uploads' ), 'Pending entry with no original live path must remain absent.' );
	fs_recovery_remove_tree( $case_root );

	// had_old=false + pending + unexpected live entry is ambiguous; preserve it and fail closed.
	[ $case_root, $recovery ] = fs_recovery_prepare_case( 'no-old-ambiguous', false, true );
	wp_mkdir_p( WP_CONTENT_DIR . '/uploads' );
	file_put_contents( WP_CONTENT_DIR . '/uploads/unexpected.txt', 'do-not-delete' );
	$threw = false;
	try {
		FilesystemCommitter::rollback( $recovery );
	} catch ( \RuntimeException ) {
		$threw = true;
	}
	fs_recovery_assert( $threw, 'Pending entry with no original path and an unexpected live target must fail closed.' );
	fs_recovery_assert( 'do-not-delete' === file_get_contents( WP_CONTENT_DIR . '/uploads/unexpected.txt' ), 'Fail-closed no-old reconciliation must preserve the unexpected live entry.' );
	fs_recovery_remove_tree( WP_CONTENT_DIR . '/uploads' );
	fs_recovery_remove_tree( $case_root );

	// Once old_moved is durable and there was no original, restored new content belongs to the restore and rollback removes it.
	[ $case_root, $recovery ] = fs_recovery_prepare_case( 'no-old-new-moved', false, true, 'old_moved' );
	wp_mkdir_p( WP_CONTENT_DIR . '/uploads' );
	file_put_contents( WP_CONTENT_DIR . '/uploads/restored.txt', 'restored-new' );
	FilesystemCommitter::rollback( $recovery );
	fs_recovery_assert( ! file_exists( WP_CONTENT_DIR . '/uploads' ), 'Rollback must restore absence when no original existed and restore-created content is live.' );
	fs_recovery_remove_tree( $case_root );

	fs_recovery_remove_tree( $test_root );
	echo "Filesystem recovery reconciliation regression: PASS\n";
}
