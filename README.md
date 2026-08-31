# Core Blueprint Backups

Governed database and full-site backups for the Core Blueprint suite.

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
