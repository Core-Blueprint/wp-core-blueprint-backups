# Core Blueprint Backups — Remote API v1

The Backups Remote API is exposed only while Core Blueprint Beacon is active and paired. Beacon owns authentication and the generic remote transport boundary; Backups owns all backup jobs, archives, schedules, verification state and download streaming.

REST namespace: `core-blueprint/v1`

Remote schema version: `1`

## Beacon integration boundary

Backups registers authenticated control-plane routes through the canonical Golden Beacon contract:

```php
CB\Beacon\Rest\RemoteRouteRegistry
```

Browser-direct archive authorization uses:

```php
CB\Beacon\Tickets\Service
```

Registration occurs on the public Beacon lifecycle hook:

```text
cb_core_beacon_register_remote_routes
```

The hook is argument-free. Backups must not receive or duplicate Beacon's stored pairing secret and must not use the removed pre-v1 `CB\Core\Beacon` compatibility namespace.

Backups remains fully usable locally when Beacon is absent or disabled.

## Routes

- `GET /backups/status`
- `GET /backups`
- `POST /backups/jobs`
- `GET /backups/jobs/{job_id}`
- `POST /backups/jobs/{job_id}/cancel`
- `POST /backups/{backup_id}/verify`
- `GET /backups/schedules`
- `POST /backups/schedules`
- `POST /backups/schedules/{type}`
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

A complete schedule write may update both `database` and `website` definitions. The type-specific route may update one schedule definition. Hub edits local Backups configuration; the managed site continues to execute schedules independently when Hub is offline.

Retention applies to automatic scheduled backups only. Manual and Hub-triggered backups remain protected from automatic retention.

## Downloads

Hub first requests `/download-ticket` using normal Beacon authentication. The response contains a short-lived browser-direct URL. The browser then downloads the `.cbbackup` directly from the managed site.

The ticket is:

- exact-backup bound;
- short-lived;
- single-use;
- pairing-key bound;
- HTTPS-only in normal operation.

When a browser sends an `Origin` header, Backups also requires it to match the browser origin retained in the Beacon ticket context before exposing the streaming response cross-origin.

Hub must not permanently store or PHP-buffer the backup payload.

## Deliberately absent in v1

- remote restore;
- remote delete.
