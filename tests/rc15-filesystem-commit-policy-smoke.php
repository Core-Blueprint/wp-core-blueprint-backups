<?php
declare(strict_types=1);

namespace {
	$root = sys_get_temp_dir() . '/cb-backups-commit-policy-' . getmypid();
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'WP_CONTENT_DIR', $root . '/live' );

	function wp_normalize_path( string $path ): string { return str_replace( '\\', '/', $path ); }
	function trailingslashit( string $value ): string { return rtrim( $value, '/\\' ) . '/'; }
	function wp_mkdir_p( string $path ): bool { return is_dir( $path ) || mkdir( $path, 0777, true ); }
	function wp_json_encode( mixed $value, int $flags = 0 ): string|false { return json_encode( $value, $flags ); }
}

namespace CB\Backups\Storage {
	final class LocalStorage {
		public static function base_path(): string { return \WP_CONTENT_DIR . '/private-backups'; }
		public static function remove_tree( string $path ): void {}
	}
}

namespace {
	require dirname( __DIR__ ) . '/src/Support/FilesystemPolicy.php';
	require dirname( __DIR__ ) . '/src/Restore/FilesystemCommitter.php';

	use CB\Backups\Restore\FilesystemCommitter;

	function commit_policy_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	function commit_policy_remove_tree( string $path ): void {
		if ( ! file_exists( $path ) ) {
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
				commit_policy_remove_tree( $path . '/' . $item );
			}
		}
		rmdir( $path );
	}

	$test_root = dirname( WP_CONTENT_DIR );
	commit_policy_remove_tree( $test_root );
	$staged = $test_root . '/stage';

	$live_names = [
		'private-backups',
		'cb-backups-0123456789abcdefabcd',
		'cb-backups-fedcba9876543210abcd',
		'wflogs',
		'uploads',
	];
	$staged_names = [
		'cb-backups-aaaaaaaaaaaaaaaaaaaa',
		'wflogs',
		'uploads',
		'languages',
	];

	foreach ( $live_names as $name ) {
		mkdir( WP_CONTENT_DIR . '/' . $name, 0777, true );
	}
	foreach ( $staged_names as $name ) {
		mkdir( $staged . '/' . $name, 0777, true );
	}

	$build_plan = new \ReflectionMethod( FilesystemCommitter::class, 'build_plan' );
	$plan = $build_plan->invoke( null, $staged );
	$names = [];
	foreach ( $plan as $entry ) {
		$decoded = base64_decode( (string) ( $entry['name_b64'] ?? '' ), true );
		if ( is_string( $decoded ) ) {
			$names[] = $decoded;
		}
	}
	sort( $names, SORT_STRING );

	commit_policy_assert(
		[ 'languages', 'uploads' ] === $names,
		'Filesystem commit plan must preserve custom storage, every managed cb-backups token root, wflogs, and other excluded runtime roots.'
	);

	commit_policy_remove_tree( $test_root );
	echo "RC15 filesystem commit policy smoke: PASS\n";
}
