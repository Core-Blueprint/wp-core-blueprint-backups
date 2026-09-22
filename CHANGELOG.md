# Changelog

All notable release-facing changes to Core Blueprint Backups are recorded here.

The current pre-release line remains `1.0.0-rc1`; Golden hardening commits do not increment the version.

## 1.0.0-rc1

### Golden hardening

- Normalized the release contract around Core Blueprint Base public API `1.0`.
- Added explicit Base API compatibility checks for activation and runtime boot.
- Migrated optional remote integration to the canonical extension-owned Beacon namespaces:
  - `CB\Beacon\Rest\RemoteRouteRegistry`
  - `CB\Beacon\Tickets\Service`
- Preserved the existing server-owned backup, verification, restore, migration, scheduling, remote-ticket and browser-direct streaming behavior.
- Confirmed the operational admin page delegates shared presentation to the Core Blueprint Design Foundation.
- Added a Golden source-contract regression covering version, Base API, Beacon integration, Updates identity, Foundation requirements and package boundaries.
- Replaced the development-oriented release packager with a production-only deterministic builder.
- Added mandatory PHP 8.4 and PHP 8.5 lint gates, JavaScript syntax QC, CSS structure QC, gettext validation and SHA-256 release output.
- Added translation quality validation so non-English catalogs cannot pass merely by copying the English source into `msgstr`.
- Added documented release tooling and a controlled maintainer translation-refresh helper.
- Replaced the old pre-v1 RC-focused README with the canonical `1.0.0-rc1` product, ownership, security and release contract.
- Reconciled the Import & Restore interface with the existing portable single-site migration contract and added Golden regression coverage so stale same-site-only messaging cannot return.
- Hardened cross-domain migration handoff so imported Login Shield settings cannot lock out the operator before destination rewrites are verified, and kept the interim sign-in overlay within the viewport.

### Existing v1 capability retained

- Verified database and full-site backup creation.
- Open `.cbbackup` format-v1 archives with payload checksums.
- Server-owned resumable background jobs.
- Local import preparation, same-site restore and governed single-site migration restore.
- Recovery-journalled filesystem/database commit behavior and post-commit verification.
- Explicit restore acknowledgement and audit attribution.
- Hourly/daily/weekly scheduling and automatic-backup retention.
- WordPress Site Health and Core Blueprint audit integration.
- Optional Hub orchestration through Beacon without making Hub or Beacon a local-use dependency.
- Short-lived single-use Beacon resource tickets for browser-direct archive downloads.
- Remote restore and remote delete remain intentionally outside the v1 remote contract.

### Release holds

A build is not Golden/releasable until all six launch-locale catalogs contain real localized copy and the runtime/package acceptance gates have been executed successfully on PHP 8.4 and PHP 8.5.
