# PILOT-1 — Core Blueprint Backups licensed update acceptance

Status: implementation candidate / Backups `1.0.0-rc2`

## Purpose

Core Blueprint Backups is the first premium plugin used to prove the complete customer update path through Core Blueprint Updates, Marketplace, License Manager and Repository.

Backups itself does **not** own license activation, update discovery, package delivery or checksum verification. It only registers an installed-product descriptor with the optional Core Blueprint Updates client.

## Canonical pilot identity

- Plugin basename: `core-blueprint-backups/core-blueprint-backups.php`
- Installed release candidate: `1.0.0-rc2`
- License Product key: `core-blueprint-backups`
- Marketplace software UUID: **not hard-coded in this plugin**

The License Product key is a bootstrap/transport identifier only. During the first successful licensed update handshake, Marketplace resolves that Product key through the public License Manager Product Catalog + Product Identity contracts and returns the canonical Marketplace software UUID. Core Blueprint Updates stores and uses that UUID for subsequent update checks.

## Dependency boundary

The Backups adapter:

1. attaches only to `cb_updates_register_products`;
2. checks whether `CB\Updates\ProductRegistry` exists;
3. registers name, plugin basename, installed version and Product key;
4. leaves `software_uuid` empty until the central Updates client learns the canonical identity.

It must never:

- store or transmit a license key;
- store an activation token;
- call coreblueprint.io directly;
- consume Marketplace, License Manager or Repository internals;
- implement WordPress updater hooks itself;
- make Core Blueprint Updates a hard activation dependency.

## Infused Academy acceptance sequence

1. Keep the currently installed Backups `1.0.0-rc1` available as the old-version baseline.
2. Merge and package this Backups `1.0.0-rc2` candidate without renaming the plugin root folder.
3. On `coreblueprint.io`, create or confirm a License Product whose Product key is exactly `core-blueprint-backups`.
4. Create/confirm the Marketplace Backups software entry and bind its immutable Marketplace software UUID to that License Product through Marketplace licensing.
5. Publish the reviewed Backups `1.0.0-rc2` ZIP through the Core Blueprint Repository provider so Marketplace records the exact immutable Repository release reference and SHA-256.
6. Install/activate the approved Core Blueprint Updates rc2 client on Infused Academy.
7. In Core Blueprint Updates, connect Backups using the real license key and Product key `core-blueprint-backups`.
8. Confirm the license key is not persisted and an activation credential is stored locally instead.
9. Trigger/check WordPress plugin updates. Backups rc1 must receive rc2 metadata from the native WordPress updater surface.
10. Run **Update now**. Core Blueprint Updates must obtain a fresh short-lived Marketplace package grant, download from `https://coreblueprint.io`, verify the package SHA-256 and hand the verified ZIP to the native upgrader.
11. Confirm Backups remains active after the update and reports `1.0.0-rc2`.
12. Re-run a normal Backups database backup and verify operation still succeeds.

## Fail-closed acceptance cases

Before declaring PILOT-1 complete, verify at least:

- invalid/revoked activation: no update package;
- wrong Product key: activation/update authorization denied;
- suspended Marketplace publisher or retired software: no update distribution;
- unavailable Repository delivery: metadata may report an update, but no installable package URL is issued;
- altered package bytes/checksum mismatch: Core Blueprint Updates refuses the ZIP;
- expired package grant: a fresh check is performed before download rather than trusting stale transient metadata.

PILOT-1 is complete only after the real Infused Academy rc1 → rc2 native update passes this matrix.
