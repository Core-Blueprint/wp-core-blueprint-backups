<?php
declare(strict_types=1);

namespace CB\Backups\CLI;

use CB\Backups\Restore\ArchiveValidator;
use CB\Backups\Storage\LocalStorage;

defined( 'ABSPATH' ) || exit;

final class Verify {
	public function __invoke( array $args, array $assoc_args ): void {
		$archive = sanitize_file_name( (string) ( $args[0] ?? '' ) );
		if ( '' === $archive ) {
			\WP_CLI::error( 'Provide an archive filename.' );
		}
		try {
			ArchiveValidator::validate( LocalStorage::archive_path( $archive ), true );
			\WP_CLI::success( 'Backup integrity verified.' );
		} catch ( \Throwable $e ) {
			\WP_CLI::error( $e->getMessage() );
		}
	}
}
