# Core Blueprint Backups

Core Blueprint Backups provides governed database and full-site backups for the Core Blueprint WordPress suite.

The plugin is designed around one rule: backup and restore execution belongs to the managed WordPress site. Browsers, Core Blueprint Hub and remote connections may observe or orchestrate work, but long-running jobs remain server-owned and recoverable.

## Release candidate

Canonical release line: **`1.0.0`**.

Version `1.0.0` is the first stable WordPress.org submission release.

Requirements:

- WordPress 7.0 or newer
- PHP 8.4 or newer
- PHP ZIP / `ZipArchive`
- Core Blueprint Base with public API `1.0`

PHP 8.5 is part of release QC even though PHP 8.4 remains the minimum runtime.

## What Backups owns

Backups owns the complete local backup domain:

- database backups;
- full-site backups containing the database and governed `wp-content` payload;
- local `.cbbackup` storage and archive verification;
- import preparation;
- same-site restore;
- governed single-site migration restore;
- server-owned backup, restore and verification jobs;
- schedules and automatic-backup retention;
- local operational history, audit context and Site Health integration;
- browser-direct download streaming after authorization.

Core Blueprint Base owns shared admin infrastructure, Design Foundation components, schema orchestration and governance primitives. Backups consumes those public contracts instead of copying them.

Core Blueprint Beacon owns remote authentication and the generic remote-route/ticket boundary. Backups remains fully usable locally when Beacon is absent or disabled.

## Backup types

### Database

Creates a verified database restore point using the Backups format-v1 SQL representation.

### Full site

Creates a verified restore point containing the database plus the governed `wp-content` filesystem payload. Operational/runtime paths that must not be restored as immutable site content are excluded or preserved by the restore policy.

## Restore and migration

Uploading or preparing an archive never changes the live site.

A restore or migration is a separate privileged action and requires explicit operator acknowledgement. Long-running restore work is server-owned and may continue while the browser is closed or re-authenticating.

The format-v1 restore pipeline includes preflight validation, staged verification, recovery journals, database shadow/rename handling and live post-commit verification. A failed critical commit must fail closed rather than report success.

Single-site migration may transform source URL/table-prefix identity for the destination while preserving the original archive. Multisite migration is intentionally outside the v1 contract.

## Scheduling

Database and full-site schedules may run independently. Supported cadence is hourly, daily or weekly where applicable.

Schedule times use the managed WordPress site's wall-clock timezone. Core Blueprint Hub may edit schedules remotely, but the managed site remains responsible for executing them when Hub is offline.

Automatic retention applies only to eligible scheduled backups. Manual restore points and Hub-triggered backups are not silently removed by schedule retention.

## Optional Beacon and Hub integration

The remote chain is:

```text
Backups -> Beacon -> Hub
```

Backups registers Hub-facing routes through the canonical Beacon boundary:

```php
CB\Beacon\Rest\RemoteRouteRegistry
```

Browser-direct downloads use:

```php
CB\Beacon\Tickets\Service
```

Control-plane calls remain Bearer-authenticated by Beacon. Large archive downloads use short-lived, single-use, pairing-bound tickets so Hub does not need to buffer backup payloads.

Remote restore and remote delete are deliberately not exposed in the v1 remote API.

See [`docs/remote-api-v1.md`](docs/remote-api-v1.md) for the wire contract.

## WordPress admin

WordPress Admin is the canonical management interface.

The operational page contains:

- **Backups**
- **Import & Restore**
- **Schedules**

Configuration that belongs to shared suite settings is registered through Core Blueprint Base's Settings Registry rather than duplicated on the operational page.

Backups consumes Base Design Foundation requirements such as Modal, Toast and Time Picker. Extension CSS is limited to Backups-specific layout and operational presentation.

## Builder architecture

Backups has no builder-rendered frontend product surface. A Bricks adapter is therefore intentionally not part of the plugin.

If a future frontend use case is introduced, its domain contract must remain builder-neutral and any builder integration must be a thin optional adapter.

## Localization

English is the source language. The release contract includes:

- Dutch (`nl_NL`)
- German (`de_DE`)
- French (`fr_FR`)
- Spanish (`es_ES`)
- Italian (`it_IT`)
- Portuguese (`pt_PT`)

A Golden release requires complete PO catalogs, valid printf placeholders and compiled MO catalogs. Catalogs that are merely English text under another locale do not pass release QC.

## Development and validation

The repository includes source and integration regressions for backup creation, SQL fidelity, migration, filesystem policy, restore acknowledgement, result presentation, job monitoring, WordPress.org distribution authority and extension lifecycle.

The Golden contract regression is:

```bash
php tests/golden-contract-regression.php
```

The canonical release builder is documented in [`tools/README.md`](tools/README.md).

A release build requires actual PHP 8.4 and PHP 8.5 CLI runtimes and GNU gettext. It emits a production-only ZIP with the canonical plugin root plus a SHA-256 sidecar.

## Production package boundary

Release archives contain only:

```text
core-blueprint-backups.php
uninstall.php
readme.txt
src/
assets/
languages/
```

Repository documentation, tests, CI configuration and release tooling are never shipped in the customer ZIP.

## Data removal policy

Uninstall removes Backups-owned job/schedule database state, but intentionally preserves backup archives and the private storage token. Uninstalling a backup plugin must not silently destroy the user's restore points.

## Release history

Current release-facing changes are tracked in [`CHANGELOG.md`](CHANGELOG.md). Historical pre-v1 RC experiments and validation notes remain available in Git history and the `docs/` directory for engineering reference; they are not the canonical v1 product contract.
