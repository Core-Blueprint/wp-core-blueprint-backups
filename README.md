# Core Blueprint Backups

Governed database and full-site backups for the Core Blueprint suite.

## v0.1.0-rc15.8 restore completion RC

Restores retain a server-rendered result panel after completion, failure or cancellation. The monitor reconnects after WordPress interim login, preserves the pending job across page/tab navigation, and reports permission loss without implying the restore failed or bypassing Base governance. Completion messages derive from the stored job, not a success query flag. The restore worker invalidates the rewrite cache so a fresh restored-site request rebuilds routes with its own registrations and locale.

Run `php tests/job-result-regression.php` for result-rendering and permission boundaries. Browser regressions use `npm install --no-save --package-lock=false playwright@1.62.1`, `npx playwright install --with-deps chromium`, then `node tests/job-monitor-browser.cjs`. These exercise the actual monitor scripts in Chromium with simulated server responses; they do not replace a disposable real WordPress login/restore acceptance test. See [RC15.8 validation](docs/rc15.8-validation.md).

## v0.1.0-rc15.6 database fidelity RC

RC15.6 uses one WordPress-aware SQL value codec in export and migration, removes request-local percent placeholders before writing SQL, and fails database reads explicitly. Configured site identity preserves its URL scheme across web/cron/CLI execution. It records source-derived content digests and row counts, then verifies the shadow snapshot before preparing or executing the live rename. Migration validates the source dump and derives target expectations before SQL re-encoding.

This is a release candidate. PR #4 remains open. Do not use production or the golden-source staging for restore acceptance tests. Finish/cancel existing jobs before updating the runtime. Create fresh backups after installation: archives without source-derived content metadata cannot pass the new restore preflight, and previously corrupted dumps are not automatically repaired.

Validation and remaining release gates are documented in [RC15.6 validation](docs/rc15.6-validation.md). Archive verification remains a checksum check; it is not a claim that a restore rehearsal or remote chain test was performed.

### Build and test

Use Python 3.10+ from a complete repository checkout: `python3 tools/build-release.py --output /tmp/cb-release`. The deterministic ZIP keeps the canonical `core-blueprint-backups/` root and excludes CI/tests/development state. It verifies the two version declarations, required files and archive CRCs; mismatches or unreadable files fail the build. Output is `core-blueprint-backups-<version>.zip`. Maintain the allowlist in the script when adding a runtime directory.

PHP smoke tests run with `php tests/rc15-migration-smoke.php` and the two filesystem smoke scripts. The database-fidelity workflow runs PHP 8.4, official WordPress 7.0, MySQL 8.0 and MariaDB 10.11 in disposable CI databases. Its integration bootstrap refuses any database not explicitly named `cb_backups_test` and marked disposable. See the workflow for the environment variables and setup; never point it at a real site.


### rc15.5 managed storage-root hardening

- Treats every canonical `wp-content/cb-backups-<20 hex token>` directory as operational Backups state rather than immutable website payload.
- Future full-site backups exclude both the active Backups storage root and stale/source managed Backups storage roots from the filesystem inventory.
- The restore commit plan preserves managed Backups storage roots and the shared operational exclusions instead of deleting or replacing them as part of the exact `wp-content` snapshot.
- Existing format-v1 archives that contain an older managed Backups storage root remain usable; live verification ignores those operational storage payloads while continuing to verify normal restored content.
- Custom `CB_BACKUPS_STORAGE_PATH` locations remain explicitly protected through the active LocalStorage path.
- The shared restore-commit policy now also preserves `wflogs`, completing the rc15.4 mutable Wordfence runtime boundary during the live filesystem switch.

### rc15.4 mutable runtime filesystem fix

- Treats `wp-content/wflogs` as operational firewall runtime state rather than immutable website payload.
- Future full-site backups exclude `wflogs` from the filesystem inventory and archive payload.
- Existing format-v1 backups that still contain `wflogs` remain restorable; post-commit live checksum verification skips those mutable runtime files while continuing to verify normal restored content.
- After a server/domain migration, re-optimize the Wordfence firewall / Extended Protection on the destination so its live WAF configuration is rebuilt for that environment.

### rc15.3 runtime code-integrity hardening

- Refreshes OPcache entries for Backups PHP files before the restore runtime boots after an update.
- Verifies that the installed `MigrationTransformer` on disk and the class actually loaded by PHP both contain the RC15 record-reader contract.
- Stops with an explicit code-build mismatch instead of allowing a mixed old/new restore runtime to continue.

### rc15.2 multiline INSERT migration fix

- Reads complete format-v1 SQL records instead of assuming every `INSERT` fits on one physical line.
- Supports large certificate artwork, SVG, JSON and other long-text payloads containing literal line breaks and semicolons inside quoted values.
- Checkpoints only after a complete SQL statement so resumable migration cannot restart in the middle of artwork data.
- Regression coverage includes a >1 MiB multiline certificate-artwork payload with URL replacement.

### rc15.1 large INSERT parser fix

- Removes the full-payload PCRE parser from migration `INSERT` handling.
- Prevents large long-text rows from failing because of PCRE backtrack limits.
- Keeps deterministic column/value parsing for format-v1 database exports.

### rc15 governed single-site migration restore

- Extends backup format v1 from same-site restore to governed single-site migration without changing the `.cbbackup` archive schema.
- Detects source/destination WordPress URL and table-prefix differences and builds a fingerprinted migration plan before live mutation.
- Keeps the original archive and verified `database/database.sql` immutable; migration writes a private `database-migrated.sql` staging copy.
- Remaps source table names to the destination prefix before the existing shadow-table/atomic-rename restore pipeline runs.
- Rewrites source home/site URLs in normal strings, JSON-escaped strings and PHP-serialized data while repairing serialized string lengths.
- Preserves post GUID values.
- Remaps prefix-bound WordPress role/capability keys such as `<prefix>user_roles`, `<prefix>capabilities` and `<prefix>user_level`.
- Rechecks destination identity before migration work starts and reserves additional disk space for the migrated SQL staging copy.
- Multisite migration remains explicitly unsupported in format v1.
- Prepared imports show Source → Destination URL/prefix identity and distinguish `Restore` from `Migrate & restore` before confirmation.

## v0.1.0-rc14.2 schema-contract patch

- Registers the backup jobs table through Core Blueprint Base's public `Database\SchemaRegistry` boundary.
- The installer remains idempotent and no longer advances its own schema version marker; Base owns reconciliation and verification.

## v0.1.0-rc14 scope

### rc14 Beacon remote-control contract v1

- Hub-facing backup routes now use Beacon's authenticated `RemoteRouteRegistry` contract.
- Remote responses expose stable v1 backup, job and schedule DTOs instead of local admin models.
- Hub can start database/full-site jobs, poll canonical states, request cooperative cancellation and update local schedules.
- Explicit remote re-verification is server-owned and resumable instead of holding one REST request open.
- Backup downloads use a short-lived, single-use Beacon resource ticket and stream directly from the managed site with bounded memory.
- Remote restore and remote delete remain intentionally unavailable.

## v0.1.0-rc13 scope

### rc13 production scheduling and retention

- Scheduler configuration and runtime state are separated so history survives schedule edits.
- Automatic database/full-site schedules support hourly, daily and weekly execution with an explicit weekly weekday.
- A filesystem scheduler lock prevents WP-Cron and server cron from starting the same due run concurrently.
- Delayed/missed schedule execution, stale-job recovery attempts and unusually long scheduled runs are governance events.
- Failed automatic jobs receive bounded retry scheduling; cancelled automatic jobs are not retried.
- Scheduler heartbeat, last successful run, next run and runtime errors are visible in wp-admin, Site Health and the optional Beacon API.
- Retention applies only to verified backups created by schedules. Manual restore points are never deleted automatically.
- Retention cleanup is audited and always keeps at least one automatic backup per enabled policy.
- Schedule time fields consume the shared Core Blueprint TimePicker Foundation while persisting the existing 24-hour `HH:MM` contract.


### rc12 full-site restore hardening

- Core Blueprint Base and Core Blueprint Backups are recovery-critical and remain at their currently installed code versions during a full-site restore; their database state is restored normally.
- Other plugins are restored individually instead of swapping the complete `plugins/` directory, so the recovery engine cannot replace itself mid-job.
- The live commit uses a durable critical marker and fatal-shutdown rollback protection.
- A hard worker interruption can resume from the filesystem/database recovery journals because the current recovery runtime remains available.
- After the live switch, every restored `wp-content` payload is SHA-256 verified again before the job may become Completed.
- Any live verification failure rolls the filesystem and database back to their pre-restore recovery snapshots.

### rc9 checksum checkpoint fix

- Fixes resumable checksum-index checkpoints across repeated append-mode file handles.
- Uses the actual locked file size (`fstat`) instead of append-handle-local `ftell()`.
- Initializes the database/environment checksum records in one atomic append/checkpoint.
- Prevents a later worker from truncating `checksums.jsonl` into an existing JSON record.

- Database backups with downloadable SQL.
- Full website backups (`database + wp-content`).
- Resumable `wp-content` inventory with file-count/byte telemetry and change detection.
- Faster full-site packaging: direct ZipArchive overwrite semantics, larger bounded worker windows, fast DEFLATE level 1 for text/code and stored already-compressed media.
- Live package throughput telemetry (files/s and bytes/s).
- Integrity verification uses larger bounded batches to reduce ZIP reopen overhead.
- Every archive payload is SHA-256 verified before a backup becomes a restore point.
- Full-site restore re-verifies staged payloads and uses recovery-journalled atomic `wp-content` switches.
- Open `.cbbackup` ZIP container with manifest and SHA-256 payload verification.
- Local `.cbbackup` import and restore.
- Server-owned background jobs: the browser is a read-only monitor and may be closed during backups.
- Time-budgeted, adaptive database export with resumable checkpoints and row/table telemetry.
- Chunked/resumable backup jobs and staged full-site restore.
- Resumable database restore using per-table shadow tables and rollback copies.
- Exact-snapshot finalization keeps extra tables as resumable recovery renames until restore health checks pass.
- Restore preflight rejects malformed/duplicate ZIP paths and insufficient local staging space before maintenance mode starts.
- Primary-key watermark pagination for stable database exports where supported.
- Binary/BLOB-safe SQL export using hex literals.
- Local schedules (hourly/daily/weekly) with WP-Cron support.
- Server-cron friendly Core Blueprint CLI commands.
- Live elapsed time, database rows/tables, output size, historical duration metadata and safe backup cancellation.
- Core Blueprint audit-log events and Site Health tests.
- Optional Beacon remote routes for Hub orchestration; no Hub dependency.
- Remote restore and remote delete are intentionally not exposed; rc14 adds ticketed browser-direct remote download.


### rc11 resumable large-file import

- Browser uploads `.cbbackup` archives in negotiated chunks instead of one large multipart request.
- Server-owned upload sessions store the exact committed byte offset in private import staging.
- Re-selecting the same file resumes an interrupted upload using name, size and last-modified fingerprinting.
- Completed uploads are structurally validated and shown as Prepared imports; upload alone never changes live site data.
- Restore remains a separate Core Blueprint-confirmed action and performs full payload verification before live changes.
- Incomplete upload sessions and stale prepared imports are covered by housekeeping.

### rc10 download and housekeeping hardening

- Streams archive and SQL downloads through a bounded 1 MiB PHP buffer after clearing output buffering.
- Disables response compression/buffering hints for authenticated backup downloads.
- Daily housekeeping removes only terminal/orphaned work directories older than 24 hours.
- Old imported restore archives are cleaned after 24 hours unless an active restore still references them.

## CLI

When using normal WP-CLI:

- `wp cb backup create database`
- `wp cb backup create website`
- `wp cb backup list`
- `wp cb backup run-due`
- `wp cb backup verify <archive.cbbackup>`

## Storage

The default storage directory is a randomised directory below `wp-content` with deny files for Apache and IIS. For strongest isolation define `CB_BACKUPS_STORAGE_PATH` in `wp-config.php` to a writable directory outside the public web root.

- Backup overview supports checkbox selection and confirmed bulk deletion through the shared Core Blueprint modal foundation.
