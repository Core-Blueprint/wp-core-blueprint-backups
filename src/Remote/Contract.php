<?php
declare(strict_types=1);

namespace CB\Backups\Remote;

defined( 'ABSPATH' ) || exit;

final class Contract {
	public const NAMESPACE = 'core-blueprint/v1';
	public const SCHEMA_VERSION = 1;
	public const DOWNLOAD_SCOPE = 'backups.download';

}