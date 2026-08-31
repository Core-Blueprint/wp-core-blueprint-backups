# Core Blueprint Backups — Remote API v1

The Backups Remote API is exposed only while Core Blueprint Beacon is active and paired. Beacon owns authentication; Backups owns all backup jobs, archives, schedules and integrity state.

REST namespace: `core-blueprint/v1`

Remote schema version: `1`

## Routes

- `GET /backups/status`
- `GET /backups`
- `POST /backups/jobs`
- `GET /backups/jobs/{job_id}`
- `POST /backups/jobs/{job_id}/cancel`
- `POST /backups/{backup_id}/verify`
- `GET /backups/schedules`
- `POST /backups/schedules`
- `POST /backups/{backup_id}/download-ticket`
- `GET /backups/download?ticket=...`

All control-plane routes use Beacon Bearer authentication through `RemoteRouteRegistry`. The final download route consumes a Beacon single-use resource ticket instead.

## Job states

Canonical remote states:

- `queued`
- `running`
- `cancelling`
- `cancelled`
- `completed`
- `failed`

Hub must not infer state from human-readable messages or progress percentages.

## Backup job creation

```json
{
  "schema_version": 1,
  "type": "database"
}
```

`type` is `database` or `website`. A successful request returns `202` with the local job DTO. The managed site owns execution after that point; no browser or Hub connection is required to remain open.

## Verification

The backup list exposes the latest known integrity state from the local sidecar metadata. An explicit remote Verify action starts a server-owned `verify` job and returns `202`.

A failed explicit verification degrades the archive to `unverified` (fail closed). A later successful verification can restore `verified` state.

## Schedules

Schedule time is stored as `HH:MM` wall-clock time in the managed WordPress site's timezone. `timezone` is returned explicitly so Hub must never reinterpret the local time as Hub time.

A write sends the complete v1 schedule document with both `database` and `website` definitions. Hub edits local Backups configuration; the managed site continues to execute schedules independently when Hub is offline.

Retention applies to automatic scheduled backups only. Manual and Hub-triggered backups remain protected from automatic retention.

## Downloads

Hub first requests `/download-ticket` using normal Beacon authentication. The response contains a short-lived browser-direct URL. The browser then downloads the `.cbbackup` directly from the managed site.

The ticket is:

- exact-backup bound;
- short-lived;
- single-use;
- pairing-key bound;
- HTTPS-only in normal operation.

Hub must not permanently store or PHP-buffer the backup payload.

## Deliberately absent in v1

- remote restore;
- remote delete.
