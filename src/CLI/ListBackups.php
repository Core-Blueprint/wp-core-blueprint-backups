<?php
declare(strict_types=1);

namespace CB\Backups\CLI;

use CB\Backups\Storage\LocalStorage;

defined( 'ABSPATH' ) || exit;

final class ListBackups {
	public function __invoke( array $args, array $assoc_args ): void {
		$rows = array_map( static fn ( array $backup ): array => [
			'archive'  => (string) ( $backup['archive_name'] ?? '' ),
			'type'     => (string) ( $backup['backup_type'] ?? '' ),
			'created'  => (string) ( $backup['created_at'] ?? '' ),
			'size'     => size_format( (int) ( $backup['size'] ?? 0 ) ),
			'verified' => ! empty( $backup['verified'] ) ? 'yes' : 'no',
		], LocalStorage::list_backups() );
		if ( ! $rows ) {
			\WP_CLI::line( 'No backups found.' );
			return;
		}
		\WP_CLI\Utils\format_items( 'table', $rows, [ 'archive', 'type', 'created', 'size', 'verified' ] );
	}
}
