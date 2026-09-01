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

Format v1 supports same-site recovery and single-site URL/table-prefix migration. Multisite remains unsupported. A fingerprinted plan fixes the source and destination identities before restore starts. Migration preserves post GUIDs and updates the WordPress role option and prefix-bound usermeta keys.

From RC15.6, every table must have `database.content_integrity[table]` containing `algorithm`, ordered `columns`, exact `rows` and `digest`. Archives without this metadata are rejected before restore. This is a deliberate pre-v1 contract tightening; no legacy restore bypass is provided.

The algorithm `sha256-sorted-row-chain-v1` hashes source values before SQL encoding. Each row includes the ordered column schema and tagged, byte-length-framed values; NULL and empty values differ. Row hashes are externally sorted, retaining duplicates, and folded into a domain-separated SHA-256 chain with a final row count. Bounded merge/hash steps persist their cursors and can replay without appending duplicates. Temporary runs require additional local disk space; a write failure stops the operation.

For migration, source SQL values must reproduce the source digest. Target expectations are derived from the transformed values before SQL encoding. The importer re-reads target shadow tables and compares their schema, row count and content digest before live commit. Both commit preparation and execution require a proof bound to the job, table inventory and expected content. This detects transport/encoding/import corruption; the correctness of intended transformation rules is additionally covered by independently specified fixtures.

## Restore lifecycle

1. Validate paths and manifest.
2. Verify every payload SHA-256.
3. Extract into a private staging directory.
4. Prepare and verify the migration copy when needed. Build the database snapshot in shadow tables and verify source-derived content expectations while live tables remain untouched.
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

Where a table has a usable primary key, export uses keyset pagination with a start watermark instead of OFFSET pagination. The watermark prevents keys above the initial maximum from extending that table scan; it does not freeze updates, deletes or inserts below that maximum. Tables without a usable primary key use an OFFSET fallback with full-value hash ordering. Concurrent source writes can still produce a mixed-time snapshot. Content digests describe the rows actually read, not a cross-table transaction or an application-consistent filesystem/database instant.


## Operational telemetry

Operational job metadata is not part of restore state. Backups records exact processed database rows, table counts, elapsed/duration values, file counts and archive size for monitoring and historical baselines. InnoDB row totals shown while an export is running are estimates from server metadata and are marked approximate; the completed backup stores the exact exported row count.

## Verification meanings and supported SQL

Archive verification checks payload SHA-256 and required metadata. It does not query restored values or run a restore rehearsal. The additional database content gate runs against shadows during restore. Current post-commit checks cover table/commit state, site identity and (for website restore) live file hashes; there is no second full live database digest scan after WordPress resumes normal writes.

SQL is generated by the plugin: NULL, quoted strings and UNHEX binary values, with one statement per physical line and table boundary markers. The shared codec uses WordPress connection-aware prepare/remove-placeholder-escape under a controlled session SQL mode, restored after each tick. Multi-row INSERTs target at most 1 MiB/50 rows; a single larger row is kept intact. A destination packet limit that cannot accommodate a statement is a hard pre-commit failure. This is not a general-purpose SQL dump importer.

Normal persisted checkpoints resume work. If SQL INSERTs completed but their checkpoint was lost, retry may fail on duplicate keys or be rejected by the content gate for a no-PK table. Start a fresh restore job after such a safe failure; universal exactly-once recovery is not claimed.
