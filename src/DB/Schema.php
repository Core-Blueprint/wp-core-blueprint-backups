<?php
declare(strict_types=1);

namespace CB\Backups\DB;

use CoreBlueprint\Core\Database\SchemaRegistry;

defined( 'ABSPATH' ) || exit;

final class Schema {
	public const TABLE = 'cb_backup_jobs';
	private const OPTION = 'cb_backups_db_version';

	public static function register(): void {
		SchemaRegistry::register( [
			'id'         => 'backups-jobs',
			'version'    => CB_BACKUPS_DB_VERSION,
			'option_key' => self::OPTION,
			'tables'     => [ [ self::class, 'table' ] ],
			'install'    => [ self::class, 'install' ],
		] );
	}

	public static function table(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE;
	}

	public static function install(): void {
		global $wpdb;
		$table   = self::table();
		$charset = $wpdb->get_charset_collate();
		$sql = "CREATE TABLE {$table} (
  id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
  job_id VARCHAR(36) NOT NULL,
  kind VARCHAR(20) NOT NULL DEFAULT 'backup',
  backup_type VARCHAR(20) NOT NULL DEFAULT 'database',
  trigger_source VARCHAR(20) NOT NULL DEFAULT 'manual',
  status VARCHAR(20) NOT NULL DEFAULT 'queued',
  stage VARCHAR(40) NOT NULL DEFAULT 'queued',
  progress SMALLINT(5) UNSIGNED NOT NULL DEFAULT 0,
  archive_name VARCHAR(255) DEFAULT NULL,
  meta LONGTEXT DEFAULT NULL,
  error_text TEXT DEFAULT NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  completed_at DATETIME DEFAULT NULL,
  PRIMARY KEY  (id),
  UNIQUE KEY job_id (job_id),
  KEY status (status),
  KEY kind (kind),
  KEY created_at (created_at)
) {$charset};";
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		dbDelta( $sql );
	}
}
