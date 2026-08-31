<?php
declare(strict_types=1);

namespace CB\Backups\Backup;

use CB\Backups\Storage\LocalStorage;
use RuntimeException;

defined( 'ABSPATH' ) || exit;

final class FilesystemInventory {
	private const MAX_DIRECTORIES_PER_TICK = 40;
	private const MAX_ENTRIES_PER_TICK = 2000;
	private const TIME_BUDGET_SECONDS = 1.25;

	/**
	 * Build the wp-content inventory incrementally with a durable per-directory
	 * journal. If PHP dies mid-directory, appended queue/inventory bytes are
	 * truncated back to the last committed checkpoint before resuming.
	 *
	 * @param array<string,mixed> $meta
	 * @return array{done:bool,progress:int,meta:array<string,mixed>}
	 */
	public static function tick( string $work, array $meta ): array {
		$root      = untrailingslashit( wp_normalize_path( WP_CONTENT_DIR ) );
		$storage   = wp_normalize_path( LocalStorage::base_path() );
		$inventory = $work . '/inventory.jsonl';
		$dirs      = $work . '/inventory-dirs.txt';
		$state_file = $work . '/inventory-state.json';

		if ( ! is_file( $state_file ) ) {
			if ( false === file_put_contents( $inventory, '', LOCK_EX ) || false === file_put_contents( $dirs, ".\n", LOCK_EX ) ) {
				throw new RuntimeException( 'Could not initialize filesystem inventory.' );
			}
			$state = [
				'dir_cursor'     => 0,
				'dirs_done'      => 0,
				'files_total'    => 0,
				'bytes_total'    => 0,
				'top_levels_b64' => [],
				'inflight'       => null,
			];
			self::write_state( $state_file, $state );
		} else {
			$state = self::read_state( $state_file );
		}

		if ( is_array( $state['inflight'] ?? null ) ) {
			$inflight = $state['inflight'];
			self::truncate_to( $inventory, max( 0, (int) ( $inflight['inventory_size'] ?? 0 ) ) );
			self::truncate_to( $dirs, max( 0, (int) ( $inflight['dirs_size'] ?? 0 ) ) );
			$state['dir_cursor'] = max( 0, (int) ( $inflight['dir_cursor'] ?? $state['dir_cursor'] ?? 0 ) );
			$state['inflight'] = null;
			self::write_state( $state_file, $state );
		}

		$read = fopen( $dirs, 'rb' );
		$append = fopen( $dirs, 'ab' );
		$list = fopen( $inventory, 'ab' );
		if ( false === $read || false === $append || false === $list ) {
			foreach ( [ $read, $append, $list ] as $handle ) {
				if ( is_resource( $handle ) ) {
					fclose( $handle );
				}
			}
			throw new RuntimeException( 'Could not open filesystem inventory state.' );
		}

		$cursor = max( 0, (int) ( $state['dir_cursor'] ?? 0 ) );
		if ( $cursor > 0 && 0 !== fseek( $read, $cursor ) ) {
			fclose( $read );
			fclose( $append );
			fclose( $list );
			throw new RuntimeException( 'Filesystem inventory cursor could not be restored.' );
		}

		$deadline = microtime( true ) + self::TIME_BUDGET_SECONDS;
		$directories = 0;
		$entries = 0;
		$meta['inventory_current_dir'] = '';

		try {
			while ( $directories < self::MAX_DIRECTORIES_PER_TICK && $entries < self::MAX_ENTRIES_PER_TICK && microtime( true ) < $deadline ) {
				$line_start = ftell( $read );
				$line = fgets( $read );
				if ( false === $line ) {
					break;
				}
				$next_cursor = ftell( $read );
				$line_start = false === $line_start ? $cursor : $line_start;
				$next_cursor = false === $next_cursor ? $line_start : $next_cursor;

				$line = trim( $line );
				$relative_dir = '.' === $line ? '' : base64_decode( $line, true );
				if ( false === $relative_dir ) {
					throw new RuntimeException( 'Filesystem inventory directory state is invalid.' );
				}

				clearstatcache( true, $inventory );
				clearstatcache( true, $dirs );
				$inventory_size = filesize( $inventory );
				$dirs_size = filesize( $dirs );
				$state['inflight'] = [
					'dir_cursor'     => (int) $line_start,
					'inventory_size' => false === $inventory_size ? 0 : (int) $inventory_size,
					'dirs_size'      => false === $dirs_size ? 0 : (int) $dirs_size,
				];
				self::write_state( $state_file, $state );

				$absolute_dir = '' === $relative_dir ? $root : wp_normalize_path( $root . '/' . $relative_dir );
				$added_files = 0;
				$added_bytes = 0;
				$added_tops = [];
				if ( is_dir( $absolute_dir ) && ! is_link( $absolute_dir ) ) {
					$items = scandir( $absolute_dir );
					if ( ! is_array( $items ) ) {
						throw new RuntimeException( sprintf( 'Could not read wp-content directory: %s', $relative_dir ?: '.' ) );
					}
					$meta['inventory_current_dir'] = $relative_dir;
					foreach ( $items as $item ) {
						if ( '.' === $item || '..' === $item ) {
							continue;
						}
						++$entries;
						$relative = ltrim( ( '' === $relative_dir ? '' : $relative_dir . '/' ) . $item, '/' );
						$absolute = wp_normalize_path( $root . '/' . $relative );
						if ( ! self::inside_root( $absolute, $root ) || self::excluded( $absolute, $root, $storage ) ) {
							continue;
						}
						if ( is_link( $absolute ) ) {
							throw new RuntimeException( sprintf( 'Full website backup format v1 does not support symbolic links inside wp-content: %s', $relative ) );
						}
						if ( is_dir( $absolute ) ) {
							if ( false === fwrite( $append, base64_encode( $relative ) . "\n" ) ) {
								throw new RuntimeException( 'Could not extend filesystem inventory directory queue.' );
							}
							continue;
						}
						if ( ! is_file( $absolute ) ) {
							continue;
						}
						$size = max( 0, (int) filesize( $absolute ) );
						$mtime = max( 0, (int) filemtime( $absolute ) );
						$record = wp_json_encode( [ 'p' => base64_encode( $relative ), 's' => $size, 'm' => $mtime ], JSON_UNESCAPED_SLASHES );
						if ( ! is_string( $record ) || false === fwrite( $list, $record . "\n" ) ) {
							throw new RuntimeException( 'Could not write filesystem inventory.' );
						}
						++$added_files;
						$added_bytes += $size;
						$top = strtok( $relative, '/' );
						if ( is_string( $top ) && '' !== $top ) {
							$added_tops[ base64_encode( $top ) ] = true;
						}
					}
				}

				$tops = [];
				foreach ( is_array( $state['top_levels_b64'] ?? null ) ? $state['top_levels_b64'] : [] as $encoded ) {
					if ( is_string( $encoded ) && '' !== $encoded ) {
						$tops[ $encoded ] = true;
					}
				}
				foreach ( array_keys( $added_tops ) as $encoded ) {
					$tops[ $encoded ] = true;
				}
				$state['dir_cursor']     = (int) $next_cursor;
				$state['dirs_done']      = (int) ( $state['dirs_done'] ?? 0 ) + 1;
				$state['files_total']    = (int) ( $state['files_total'] ?? 0 ) + $added_files;
				$state['bytes_total']    = (int) ( $state['bytes_total'] ?? 0 ) + $added_bytes;
				$state['top_levels_b64'] = array_keys( $tops );
				$state['inflight']       = null;
				self::write_state( $state_file, $state );
				$cursor = (int) $next_cursor;
				++$directories;
			}
		} finally {
			fclose( $read );
			fclose( $append );
			fclose( $list );
		}

		clearstatcache( true, $dirs );
		$queue_size = filesize( $dirs );
		$done = false !== $queue_size && (int) ( $state['dir_cursor'] ?? 0 ) >= (int) $queue_size;
		$top_levels = [];
		foreach ( is_array( $state['top_levels_b64'] ?? null ) ? $state['top_levels_b64'] : [] as $encoded ) {
			$decoded = is_string( $encoded ) ? base64_decode( $encoded, true ) : false;
			if ( is_string( $decoded ) && '' !== $decoded ) {
				$top_levels[] = $decoded;
			}
		}
		sort( $top_levels, SORT_STRING );

		$meta['inventory_initialized'] = true;
		$meta['inventory_dir_cursor']  = (int) ( $state['dir_cursor'] ?? 0 );
		$meta['inventory_dirs_done']   = (int) ( $state['dirs_done'] ?? 0 );
		$meta['files_total']           = (int) ( $state['files_total'] ?? 0 );
		$meta['files_bytes_total']     = (int) ( $state['bytes_total'] ?? 0 );
		$meta['files_done']            = (int) ( $meta['files_done'] ?? 0 );
		$meta['files_bytes_done']      = (int) ( $meta['files_bytes_done'] ?? 0 );
		$meta['inventory_cursor']      = (int) ( $meta['inventory_cursor'] ?? 0 );
		$meta['top_levels']            = $top_levels;
		if ( $done ) {
			$meta['inventory_current_dir'] = '';
		}

		$progress = $done ? 100 : min( 95, 5 + (int) ( $state['dirs_done'] ?? 0 ) );
		return [ 'done' => $done, 'progress' => $progress, 'meta' => $meta ];
	}

	/** @param array<string,mixed> $state */
	private static function write_state( string $file, array $state ): void {
		$json = wp_json_encode( $state, JSON_UNESCAPED_SLASHES );
		if ( ! is_string( $json ) ) {
			throw new RuntimeException( 'Filesystem inventory state could not be encoded.' );
		}
		$tmp = $file . '.tmp';
		if ( false === file_put_contents( $tmp, $json, LOCK_EX ) || ! rename( $tmp, $file ) ) {
			@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			throw new RuntimeException( 'Filesystem inventory state could not be written atomically.' );
		}
	}

	/** @return array<string,mixed> */
	private static function read_state( string $file ): array {
		$decoded = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $decoded ) ) {
			throw new RuntimeException( 'Filesystem inventory state is invalid.' );
		}
		return $decoded;
	}

	private static function truncate_to( string $file, int $size ): void {
		$handle = fopen( $file, 'c+b' );
		if ( false === $handle || ! ftruncate( $handle, $size ) ) {
			if ( is_resource( $handle ) ) {
				fclose( $handle );
			}
			throw new RuntimeException( 'Filesystem inventory recovery could not truncate partial state.' );
		}
		fclose( $handle );
	}

	private static function inside_root( string $path, string $root ): bool {
		return str_starts_with( $path, trailingslashit( $root ) );
	}

	private static function excluded( string $path, string $root, string $storage ): bool {
		if ( str_starts_with( $path, trailingslashit( $storage ) ) || $path === $storage ) {
			return true;
		}
		$relative = ltrim( substr( $path, strlen( $root ) ), '/' );
		foreach ( [ 'cache', 'upgrade', 'ai1wm-backups', '.git', '.svn', '.cb-restore-work' ] as $excluded_root ) {
			if ( $relative === $excluded_root || str_starts_with( $relative, $excluded_root . '/' ) ) {
				return true;
			}
		}
		return false;
	}
}
