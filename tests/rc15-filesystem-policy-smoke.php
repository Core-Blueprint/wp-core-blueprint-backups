<?php
declare(strict_types=1);

namespace {
	define( 'ABSPATH', __DIR__ . '/' );
	define( 'WP_CONTENT_DIR', sys_get_temp_dir() . '/cb-backups-policy-wp-content' );

	function wp_normalize_path( string $path ): string { return str_replace( '\\', '/', $path ); }
	function trailingslashit( string $value ): string { return rtrim( $value, '/\\' ) . '/'; }
}

namespace CB\Backups\Storage {
	final class LocalStorage {
		public static function base_path(): string { return \WP_CONTENT_DIR . '/private-backups'; }
	}
}

namespace CB\Backups\Restore {
	final class FilesystemCommitter {
		public static function is_runtime_protected_path( string $relative ): bool { return false; }
	}

	final class ArchiveValidator {
		public static function assert_safe_entry( string $path ): void {}
	}
}

namespace {
	require dirname( __DIR__ ) . '/src/Support/FilesystemPolicy.php';
	require dirname( __DIR__ ) . '/src/Backup/FilesystemInventory.php';
	require dirname( __DIR__ ) . '/src/Restore/LiveVerifier.php';

	use CB\Backups\Backup\FilesystemInventory;
	use CB\Backups\Restore\LiveVerifier;
	use CB\Backups\Support\FilesystemPolicy;

	function policy_assert( bool $condition, string $message ): void {
		if ( ! $condition ) {
			throw new \RuntimeException( $message );
		}
	}

	$current_storage = 'cb-backups-0123456789abcdefabcd';
	$stale_storage = 'cb-backups-fedcba9876543210abcd';

	policy_assert( FilesystemPolicy::is_excluded_relative_path( 'wflogs' ), 'wflogs root must be excluded.' );
	policy_assert( FilesystemPolicy::is_excluded_relative_path( 'wflogs/config-livewaf.php' ), 'Wordfence live WAF config must be excluded.' );
	policy_assert( FilesystemPolicy::is_excluded_relative_path( 'wflogs/config.php' ), 'Wordfence runtime config must be excluded.' );
	policy_assert( FilesystemPolicy::is_managed_storage_top_level( $current_storage ), 'Managed backup storage root must be recognized.' );
	policy_assert( FilesystemPolicy::is_excluded_relative_path( $stale_storage . '/imports/example.cbbackup' ), 'Stale managed backup storage descendants must be excluded.' );
	policy_assert( ! FilesystemPolicy::is_managed_storage_top_level( 'cb-backups-not-a-token' ), 'Arbitrary cb-backups names must not be classified as managed storage.' );
	policy_assert( ! FilesystemPolicy::is_managed_storage_top_level( 'cb-backups-0123456789abcdefabcde' ), 'Managed storage token length must remain exact.' );
	policy_assert( ! FilesystemPolicy::is_excluded_relative_path( 'uploads/example.jpg' ), 'Normal uploads must remain backup payload.' );

	$excluded = new \ReflectionMethod( FilesystemInventory::class, 'excluded' );
	$root = wp_normalize_path( WP_CONTENT_DIR );
	$storage = wp_normalize_path( WP_CONTENT_DIR . '/private-backups' );
	policy_assert(
		true === $excluded->invoke( null, $root . '/wflogs/config-livewaf.php', $root, $storage ),
		'Backup inventory must exclude Wordfence runtime files.'
	);
	policy_assert(
		true === $excluded->invoke( null, $root . '/' . $stale_storage . '/imports/example.cbbackup', $root, $storage ),
		'Backup inventory must exclude stale managed backup storage roots.'
	);
	policy_assert(
		false === $excluded->invoke( null, $root . '/uploads/example.jpg', $root, $storage ),
		'Backup inventory must retain normal content files.'
	);

	$live_relative = new \ReflectionMethod( LiveVerifier::class, 'live_relative_path' );
	policy_assert(
		null === $live_relative->invoke( null, 'files/wp-content/wflogs/config-livewaf.php' ),
		'Live restore verification must ignore Wordfence runtime files from older archives.'
	);
	policy_assert(
		null === $live_relative->invoke( null, 'files/wp-content/' . $stale_storage . '/imports/example.cbbackup' ),
		'Live restore verification must ignore managed backup storage from older archives.'
	);
	policy_assert(
		'uploads/example.jpg' === $live_relative->invoke( null, 'files/wp-content/uploads/example.jpg' ),
		'Live restore verification must retain normal restored payload checks.'
	);

	echo "RC15 filesystem policy smoke: PASS\n";
}
