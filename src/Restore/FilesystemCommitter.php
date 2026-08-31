<?php
declare(strict_types=1);

namespace CB\Backups\Restore;

use CB\Backups\Storage\LocalStorage;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class FilesystemCommitter {
	private const PLAN_FILE = 'filesystem-commit-plan.json';
	private const TIME_BUDGET_SECONDS = 1.25;

	/**
	 * Prepare the live filesystem commit without touching wp-content. Restore
	 * staging/recovery must be on the same filesystem so the final switch can
	 * use bounded atomic rename operations instead of recursively copying large
	 * trees while the live site is in transition.
	 *
	 * @param array<string,mixed> $meta
	 * @return array<string,mixed>
	 */
	public static function prepare( string $staged_content, string $work_dir, array $meta ): array {
		if ( ! is_dir( $staged_content ) ) {
			throw new RuntimeException( 'Staged wp-content payload is missing.' );
		}
		self::assert_same_filesystem( $staged_content, WP_CONTENT_DIR );
		$recovery = $work_dir . '/recovery-wp-content';
		$plan_file = $work_dir . '/' . self::PLAN_FILE;
		wp_mkdir_p( $recovery );
		$plan = is_file( $plan_file ) ? self::read_plan( $plan_file ) : self::build_plan( $staged_content );
		if ( ! is_file( $plan_file ) ) {
			self::write_plan( $plan_file, $plan );
		}
		$meta['fs_commit_initialized'] = true;
		$meta['fs_commit_total']       = count( $plan );
		$meta['recovery_path']         = $recovery;
		$meta['fs_commit_plan']        = $plan_file;
		return $meta;
	}

	/**
	 * Perform the already-prepared top-level switch in the current request.
	 * The operations are renames only; no recursive cross-filesystem copy is
	 * allowed at the live commit boundary.
	 *
	 * @param array<string,mixed> $meta
	 * @return array<string,mixed>
	 */
	public static function commit_all( string $staged_content, string $work_dir, array $meta ): array {
		$meta = self::prepare( $staged_content, $work_dir, $meta );
		$plan_file = (string) $meta['fs_commit_plan'];
		$recovery  = (string) $meta['recovery_path'];
		$plan = self::read_plan( $plan_file );
		foreach ( $plan as $index => $entry ) {
			if ( 'committed' === (string) ( $entry['state'] ?? '' ) ) {
				$meta['fs_commit_index'] = $index + 1;
				continue;
			}
			$name = self::decode_path( (string) ( $entry['name_b64'] ?? '' ) );
			$source = $staged_content . '/' . $name;
			$target = WP_CONTENT_DIR . '/' . $name;
			$old    = $recovery . '/' . $name;
			$had_old = ! empty( $entry['had_old'] );
			$has_new = ! empty( $entry['has_new'] );
			$state = (string) ( $entry['state'] ?? 'pending' );
			$meta['fs_current_item'] = $name;

			if ( 'pending' === $state ) {
				if ( $had_old ) {
					if ( self::exists( $old ) ) {
						$state = 'old_moved';
					} elseif ( self::exists( $target ) ) {
						self::move_atomic( $target, $old );
						$state = 'old_moved';
					} else {
						throw new RuntimeException( sprintf( 'Restore recovery state is inconsistent for wp-content/%s.', $name ) );
					}
				} else {
					$state = 'old_moved';
				}
				$plan[ $index ]['state'] = $state;
				self::write_plan( $plan_file, $plan );
			}

			if ( 'old_moved' === $state ) {
				if ( $has_new ) {
					if ( self::exists( $source ) ) {
						if ( self::exists( $target ) ) {
							throw new RuntimeException( sprintf( 'Restore target unexpectedly exists for wp-content/%s.', $name ) );
						}
						self::move_atomic( $source, $target );
					} elseif ( ! self::exists( $target ) ) {
						throw new RuntimeException( sprintf( 'Staged restore payload disappeared for wp-content/%s.', $name ) );
					}
				} elseif ( self::exists( $target ) ) {
					LocalStorage::remove_tree( $target );
				}
				$state = 'new_moved';
				$plan[ $index ]['state'] = $state;
				self::write_plan( $plan_file, $plan );
			}

			if ( 'new_moved' === $state ) {
				$plan[ $index ]['state'] = 'committed';
				self::write_plan( $plan_file, $plan );
			}
			$meta['fs_commit_index'] = $index + 1;
		}
		$meta['fs_current_item'] = '';
		return $meta;
	}

	/**
	 * Commit staged wp-content top-level entries incrementally with a durable
	 * recovery journal. Excluded operational/cache paths remain untouched.
	 *
	 * @param array<string,mixed> $meta
	 * @return array{done:bool,progress:int,meta:array<string,mixed>}
	 */
	public static function tick( string $staged_content, string $work_dir, array $meta ): array {
		if ( ! is_dir( $staged_content ) ) {
			throw new RuntimeException( 'Staged wp-content payload is missing.' );
		}
		$recovery = $work_dir . '/recovery-wp-content';
		$plan_file = $work_dir . '/' . self::PLAN_FILE;
		if ( empty( $meta['fs_commit_initialized'] ) ) {
			$meta = self::prepare( $staged_content, $work_dir, $meta );
		}

		$plan = self::read_plan( $plan_file );
		$journal_index = 0;
		foreach ( $plan as $position => $entry ) {
			if ( 'committed' === (string) ( $entry['state'] ?? '' ) ) {
				$journal_index = $position + 1;
				continue;
			}
			break;
		}
		$index = max( $journal_index, max( 0, (int) ( $meta['fs_commit_index'] ?? 0 ) ) );
		$total = count( $plan );
		$deadline = microtime( true ) + self::TIME_BUDGET_SECONDS;
		$processed = 0;

		while ( $index < $total && $processed < 4 && microtime( true ) < $deadline ) {
			$entry = $plan[ $index ];
			$name = self::decode_path( (string) ( $entry['name_b64'] ?? '' ) );
			$source = $staged_content . '/' . $name;
			$target = WP_CONTENT_DIR . '/' . $name;
			$old = $recovery . '/' . $name;
			$had_old = ! empty( $entry['had_old'] );
			$has_new = ! empty( $entry['has_new'] );
			$state = (string) ( $entry['state'] ?? 'pending' );
			$meta['fs_current_item'] = $name;

			if ( 'pending' === $state ) {
				if ( $had_old ) {
					if ( self::exists( $old ) ) {
						// Previous request crashed after moving the live entry.
						$state = 'old_moved';
					} elseif ( self::exists( $target ) ) {
						self::move_atomic( $target, $old );
						$state = 'old_moved';
					} else {
						throw new RuntimeException( sprintf( 'Restore recovery state is inconsistent for wp-content/%s.', $name ) );
					}
				} else {
					$state = 'old_moved';
				}
				$plan[ $index ]['state'] = $state;
				self::write_plan( $plan_file, $plan );
			}

			if ( 'old_moved' === $state ) {
				if ( $has_new ) {
					if ( self::exists( $source ) ) {
						if ( self::exists( $target ) ) {
							throw new RuntimeException( sprintf( 'Restore target unexpectedly exists for wp-content/%s.', $name ) );
						}
						self::move_atomic( $source, $target );
					} elseif ( ! self::exists( $target ) ) {
						throw new RuntimeException( sprintf( 'Staged restore payload disappeared for wp-content/%s.', $name ) );
					}
				} elseif ( self::exists( $target ) ) {
					LocalStorage::remove_tree( $target );
				}
				$state = 'new_moved';
				$plan[ $index ]['state'] = $state;
				self::write_plan( $plan_file, $plan );
			}

			if ( 'new_moved' === $state ) {
				$plan[ $index ]['state'] = 'committed';
				self::write_plan( $plan_file, $plan );
			}

			++$index;
			++$processed;
			$meta['fs_commit_index'] = $index;
		}

		$done = $index >= $total;
		if ( $done ) {
			$meta['fs_current_item'] = '';
		}
		$progress = $total > 0 ? (int) floor( min( 1, $index / $total ) * 100 ) : 100;
		return [ 'done' => $done, 'progress' => $progress, 'meta' => $meta ];
	}

	public static function assert_committed( string $recovery ): void {
		$plan_file = dirname( $recovery ) . '/' . self::PLAN_FILE;
		$plan = self::read_plan( $plan_file );
		foreach ( $plan as $entry ) {
			$name = self::decode_path( (string) ( $entry['name_b64'] ?? '' ) );
			$target_exists = self::exists( WP_CONTENT_DIR . '/' . $name );
			$should_exist = ! empty( $entry['has_new'] );
			if ( $target_exists !== $should_exist || 'committed' !== (string) ( $entry['state'] ?? '' ) ) {
				throw new RuntimeException( sprintf( 'Restored wp-content snapshot is incomplete for %s.', $name ) );
			}
		}
	}

	public static function rollback( string $recovery ): void {
		$work_dir = dirname( $recovery );
		$plan_file = $work_dir . '/' . self::PLAN_FILE;
		if ( ! is_file( $plan_file ) ) {
			return;
		}
		$plan = self::read_plan( $plan_file );
		foreach ( array_reverse( $plan ) as $entry ) {
			$name = self::decode_path( (string) ( $entry['name_b64'] ?? '' ) );
			$target = WP_CONTENT_DIR . '/' . $name;
			$old = $recovery . '/' . $name;
			$state = (string) ( $entry['state'] ?? 'pending' );
			if ( 'pending' === $state ) {
				continue;
			}
			if ( self::exists( $target ) ) {
				LocalStorage::remove_tree( $target );
			}
			if ( self::exists( $old ) ) {
				self::move_atomic( $old, $target );
			}
		}
	}

	/** @return array<int,array{name_b64:string,had_old:bool,has_new:bool,state:string}> */
	private static function build_plan( string $staged_content ): array {
		$staged = self::top_level_names( $staged_content );
		$live   = self::top_level_names( WP_CONTENT_DIR );
		$protected = array_fill_keys( self::protected_top_levels(), true );
		$names = array_values( array_unique( array_merge( $staged, $live ) ) );
		sort( $names, SORT_STRING );
		$plan = [];
		foreach ( $names as $name ) {
			if ( isset( $protected[ $name ] ) ) {
				continue;
			}
			if ( 'plugins' === $name ) {
				$plan = array_merge( $plan, self::build_plugin_plan( $staged_content . '/plugins', WP_CONTENT_DIR . '/plugins' ) );
				continue;
			}
			$plan[] = self::plan_entry( $name, $staged_content );
		}
		return $plan;
	}

	/** @return array<int,array{name_b64:string,had_old:bool,has_new:bool,state:string}> */
	private static function build_plugin_plan( string $staged_plugins, string $live_plugins ): array {
		$staged = self::top_level_names( $staged_plugins );
		$live   = self::top_level_names( $live_plugins );
		$names = array_values( array_unique( array_merge( $staged, $live ) ) );
		sort( $names, SORT_STRING );
		$plan = [];
		foreach ( $names as $name ) {
			$relative = 'plugins/' . $name;
			if ( self::is_runtime_protected_path( $relative ) ) {
				continue;
			}
			$plan[] = self::plan_entry( $relative, dirname( $staged_plugins ) );
		}
		return $plan;
	}

	/** @return array{name_b64:string,had_old:bool,has_new:bool,state:string} */
	private static function plan_entry( string $relative, string $staged_content ): array {
		$relative = self::assert_safe_relative_path( $relative );
		return [
			'name_b64' => base64_encode( $relative ),
			'had_old'  => self::exists( WP_CONTENT_DIR . '/' . $relative ),
			'has_new'  => self::exists( $staged_content . '/' . $relative ),
			'state'    => 'pending',
		];
	}

	/** @return string[] */
	public static function runtime_protected_paths(): array {
		$paths = [];
		foreach ( [ defined( 'CB_BACKUPS_BASENAME' ) ? (string) CB_BACKUPS_BASENAME : '', defined( 'CB_CORE_BASENAME' ) ? (string) CB_CORE_BASENAME : '' ] as $basename ) {
			$basename = trim( wp_normalize_path( $basename ), '/' );
			if ( '' === $basename ) {
				continue;
			}
			$parts = explode( '/', $basename );
			$root = count( $parts ) > 1 ? $parts[0] : $basename;
			$paths[] = 'plugins/' . $root;
		}
		return array_values( array_unique( $paths ) );
	}

	public static function is_runtime_protected_path( string $relative ): bool {
		$relative = trim( wp_normalize_path( $relative ), '/' );
		foreach ( self::runtime_protected_paths() as $protected ) {
			if ( $relative === $protected || str_starts_with( $relative, $protected . '/' ) ) {
				return true;
			}
		}
		return false;
	}

	public static function assert_runtime_available(): void {
		foreach ( self::runtime_protected_paths() as $protected ) {
			if ( ! self::exists( WP_CONTENT_DIR . '/' . $protected ) ) {
				throw new RuntimeException( sprintf( 'Recovery-critical runtime path disappeared during restore: wp-content/%s.', $protected ) );
			}
		}
	}

	/** @return string[] */
	private static function protected_top_levels(): array {
		$protected = [ 'cache', 'upgrade', 'ai1wm-backups', '.git', '.svn', '.cb-restore-work' ];
		$content = trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$storage = wp_normalize_path( LocalStorage::base_path() );
		if ( str_starts_with( $storage, $content ) ) {
			$relative = ltrim( substr( $storage, strlen( $content ) ), '/' );
			$top = strtok( $relative, '/' );
			if ( is_string( $top ) && '' !== $top ) {
				$protected[] = $top;
			}
		}
		return array_values( array_unique( $protected ) );
	}

	/** @return string[] */
	private static function top_level_names( string $dir ): array {
		$items = is_dir( $dir ) ? scandir( $dir ) : false;
		if ( ! is_array( $items ) ) {
			return [];
		}
		$out = [];
		foreach ( $items as $item ) {
			if ( '.' === $item || '..' === $item || '' === $item || str_contains( $item, '/' ) || str_contains( $item, '\\' ) ) {
				continue;
			}
			$out[] = $item;
		}
		return $out;
	}

	/** @param array<int,array<string,mixed>> $plan */
	private static function write_plan( string $file, array $plan ): void {
		$json = wp_json_encode( $plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			throw new RuntimeException( 'Filesystem restore journal could not be encoded.' );
		}
		$tmp = $file . '.tmp';
		if ( false === file_put_contents( $tmp, $json, LOCK_EX ) || ! rename( $tmp, $file ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new RuntimeException( 'Filesystem restore journal could not be written atomically.' );
		}
	}

	/** @return array<int,array<string,mixed>> */
	private static function read_plan( string $file ): array {
		$decoded = is_file( $file ) ? json_decode( (string) file_get_contents( $file ), true ) : null;
		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException( 'Filesystem restore journal is missing or invalid.' );
		}
		return array_values( array_filter( $decoded, 'is_array' ) );
	}

	private static function decode_path( string $encoded ): string {
		$path = base64_decode( $encoded, true );
		if ( false === $path ) {
			throw new RuntimeException( 'Filesystem restore journal contains an invalid encoded path.' );
		}
		return self::assert_safe_relative_path( $path );
	}

	private static function assert_safe_relative_path( string $path ): string {
		$path = trim( wp_normalize_path( $path ), '/' );
		if ( '' === $path || str_contains( $path, "\0" ) || str_contains( $path, '\\' ) ) {
			throw new RuntimeException( 'Filesystem restore journal contains an unsafe relative path.' );
		}
		foreach ( explode( '/', $path ) as $segment ) {
			if ( '' === $segment || '.' === $segment || '..' === $segment ) {
				throw new RuntimeException( 'Filesystem restore journal contains an unsafe relative path.' );
			}
		}
		return $path;
	}

	private static function move_atomic( string $source, string $target ): void {
		$parent = dirname( $target );
		if ( ! is_dir( $parent ) && ! wp_mkdir_p( $parent ) ) {
			throw new RuntimeException( 'Could not create restore target parent directory.' );
		}
		if ( ! @rename( $source, $target ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new RuntimeException( sprintf( 'Could not atomically switch restore path %s.', basename( $target ) ) );
		}
	}

	private static function exists( string $path ): bool {
		return file_exists( $path ) || is_link( $path );
	}

	private static function assert_same_filesystem( string $a, string $b ): void {
		$stat_a = @stat( $a ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		$stat_b = @stat( $b ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		if ( is_array( $stat_a ) && is_array( $stat_b ) && isset( $stat_a['dev'], $stat_b['dev'] ) && (int) $stat_a['dev'] !== (int) $stat_b['dev'] ) {
			throw new RuntimeException( 'Full website restore requires its private staging area to be on the same filesystem as wp-content for an atomic live switch.' );
		}
	}

}
