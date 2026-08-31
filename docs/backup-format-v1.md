# Core Blueprint Backup Format v1

`*.cbbackup` is a ZIP container. The format is intentionally open so an operator can inspect a backup without proprietary tooling.

## Required entries

- `manifest.json` — versioned backup contract and source-site identity.
- `checksums.json` — SHA-256 for every payload entry.
- `database/database.sql` — Core Blueprint generated SQL using a deliberately small statement whitelist and explicit table boundary markers.
- `site/environment.json` — non-secret environment metadata.

A `website` backup additionally contains `files/wp-content/...`.

## Backup types

- `database`: database only.
- `website`: database plus `wp-content` files.

WordPress core is not archived. `wp-config.php` is not archived. A restore therefore starts from an installed WordPress environment with Core Blueprint Base and Core Blueprint Backups available. The installed Base and Backups plugin code is treated as recovery infrastructure and is not downgraded/replaced by format-v1 full-site restore; all other restorable `wp-content` payloads follow the snapshot.

## Exclusions

Format v1 excludes:

- Core Blueprint's own backup storage directory (prevents recursive backups).
- `wp-content/cache`.
- `wp-content/upgrade`.
- filesystem symlinks.
- the `cb_backup_jobs` operational table. Restore state must survive while the rest of the database is replaced.
- spatial/geometry database columns.
- database tables with foreign-key constraints; support can be added in a later schema version without weakening v1 restore guarantees.

## Restore boundary

Format v1 is a restore format, not a migration format. Restore is rejected when:

- the source `home_url` differs from the current site;
- the database table prefix differs;
- either source or target is WordPress Multisite.

URL replacement, serialized-data migration and site cloning are deliberately outside v1.

## Restore lifecycle

1. Validate paths and manifest.
2. Verify every payload SHA-256.
3. Extract into a private staging directory.
4. Build the complete database snapshot in shadow tables while the live database remains available.
5. Prepare durable database and filesystem recovery journals before any live switch.
6. Enter maintenance mode, atomically expose the prepared database snapshot, then switch verified `wp-content` payloads while retaining rollback copies. Plugin payloads are switched per plugin; the currently installed Core Blueprint Base and Core Blueprint Backups code are deliberately preserved as the recovery runtime.
7. Re-verify the resulting live `wp-content` payloads against the archive SHA-256 index before the restore can complete.
8. Roll the committed database snapshot and filesystem back if the live commit or post-commit verification fails.
9. Stage tables that are not part of the restored snapshot under deterministic recovery names; do not irreversibly drop them until post-restore identity and live-payload checks pass.
10. Flush WordPress caches and rewrite rules, mark the restore complete, disarm the critical recovery marker and leave maintenance mode.
11. Clean up recovery/shadow data best-effort.

## Beacon / Hub boundary

Backups is fully standalone. If Beacon is installed and paired, the plugin contributes authenticated routes under `core-blueprint/v1/backups/*`. Beacon owns authentication and remote request logging; Backups owns the job, archive, schedule and integrity logic. Hub may orchestrate jobs but never needs to receive backup payload data.

Remote v1 intentionally exposes create/status/list/verify/schedules plus cooperative cancellation of an active backup job. Restore, download and delete remain local operations.

## Database inventory

`manifest.json` contains the exact database table inventory for the snapshot. Restore rejects SQL that targets a table not declared in that inventory. The operational `cb_backup_jobs` table is never part of the inventory.

## Database export consistency

Where a table has a usable primary key, export uses keyset pagination with a start watermark instead of OFFSET pagination. This prevents rows inserted after the table export starts from extending the snapshot and avoids OFFSET drift when rows are deleted during a long-running backup. Tables without a usable primary key use a bounded OFFSET fallback in format v1.


## Operational telemetry

Operational job metadata is not part of restore state. Backups records exact processed database rows, table counts, elapsed/duration values, file counts and archive size for monitoring and historical baselines. InnoDB row totals shown while an export is running are estimates from server metadata and are marked approximate; the completed backup stores the exact exported row count.
