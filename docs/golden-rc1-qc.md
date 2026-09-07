# Core Blueprint Backups — Golden RC1 QC

**Release line:** `1.0.0-rc1`  
**Golden branch:** `golden/backups-v1-rc1`  
**Base API contract:** `1.0`

## Source QC

### PASS — extension ownership

- PHP domain namespace remains extension-owned under `CB\Backups`.
- WordPress Admin remains the canonical management interface.
- Backup, restore, migration, scheduling, storage and verification logic remain Backups-owned.
- Hub and Beacon remain optional integrations; local Backups operation has no Hub dependency.

### PASS — Base public contracts

- Plugin activation and runtime explicitly require a compatible `CB_CORE_API_VERSION` for Base API `1.0`.
- `ExtensionRegistry` remains the canonical extension inventory route.
- `PageRegistry` remains the operational page boundary.
- `SettingsRegistry` remains the settings ownership boundary.
- Backups consumes public schema/governance contracts rather than Base-private implementation handles.

### PASS — Design Foundation

The Backups operational page declares shared semantic requirements through PageRegistry. Extension CSS remains scoped to Backups composition/telemetry and consumes Base design tokens instead of restyling shared cards, tables, buttons or form controls.

No builder adapter is required because Backups has no builder-rendered frontend product surface.

### PASS — Golden Beacon compatibility

The optional remote integration now consumes the canonical Golden Beacon contracts:

```php
CB\Beacon\Rest\RemoteRouteRegistry
CB\Beacon\Tickets\Service
```

The existing public lifecycle hook remains argument-free:

```text
cb_core_beacon_register_remote_routes
```

Remote schema namespace/version, download scope, job routes, schedule routes, browser-origin binding and ticket semantics are unchanged.

### PASS — Updates identity

The existing thin Updates adapter remains registration-only and advertises:

```text
product_key: core-blueprint-backups
vendor_id: core-blueprint
plugin: actual Backups basename
version: installed Backups version
```

No licensing, Marketplace, Repository or network authority is duplicated inside Backups.

### PASS — uninstall/data ownership

Uninstall removes Backups-owned job/schedule database state and cron hooks while deliberately preserving the private storage token and backup archives. Uninstalling the plugin must not silently destroy restore points.

### PASS — repository/release documentation

- README is canonical for `1.0.0-rc1` rather than the old pre-v1 RC15 development chronology.
- `CHANGELOG.md` records the current release-facing Golden hardening.
- `tools/README.md` documents release setup, usage, outputs, gates, failures and maintenance.
- Historical RC validation material remains engineering history only and is excluded from customer packages.

### PASS — package contract in source

The canonical builder now enforces:

- immutable `1.0.0-rc1` release line;
- Base API `1.0`;
- canonical `core-blueprint-backups/` ZIP root;
- runtime-only customer payload;
- actual PHP 8.4 and PHP 8.5 lint requirements;
- JavaScript syntax QC;
- CSS structural QC;
- gettext PO validation and MO compilation;
- translation placeholder and quality checks;
- ZIP CRC validation;
- SHA-256 sidecar output.

## Release holds

### HOLD — launch translations

The six existing locale catalogs currently contain many English `msgstr` values. Non-empty catalogs are not sufficient for Golden localization.

Required before release:

- `nl_NL`
- `de_DE`
- `fr_FR`
- `es_ES`
- `it_IT`
- `pt_PT`

must contain real localized copy with no active fuzzy/untranslated entries and valid placeholders. The release builder fails when a non-English catalog is predominantly identical to English.

`tools/refresh-translations.py` is a maintainer aid only. Translation output must be reviewed before it becomes release input; CI does not auto-write machine translations to the branch.

### HOLD — runtime/package execution evidence

GitHub Actions created jobs for the current PR but the jobs terminated before the first workflow step. The API reports zero executed steps and no job logs for both existing Backups workflows and the new Golden QC workflow. This is treated as runner/infrastructure unavailability, not as a code-test failure.

The Golden package still requires a successful execution of the documented PHP 8.4 + PHP 8.5 release gates before merge/release approval.

### HOLD — Beacon ↔ Backups field validation

After Golden Beacon and this Backups branch are installed together, staging must verify at minimum:

1. Backups registers its remote routes through `CB\Beacon\Rest\RemoteRouteRegistry`.
2. Beacon-authenticated status/list/schedule/job calls remain functional.
3. A download ticket can be minted through `CB\Beacon\Tickets\Service`.
4. The browser-direct download consumes the ticket once and remains origin-bound where an Origin header is present.
5. Local backup and restore workflows remain usable with Beacon absent/disabled.

## Merge status

**SOURCE QC: PASS**  
**GOLDEN RELEASE: HOLD**

Do not merge or publish a stable release until launch translations, executable package QC and joint Beacon↔Backups staging are complete.
