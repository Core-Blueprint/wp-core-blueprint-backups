# PILOT-1 — Core Blueprint Backups licensed update acceptance

Status: **VN1-C repository candidate / Backups `1.0.0-rc4`**. Real customer-side native-update acceptance remains deferred to **UPD-VN1-F**.

## Purpose

Core Blueprint Backups is the first premium plugin used to prove the complete customer update path through Core Blueprint Updates, Marketplace, License Manager and Repository.

Backups itself does **not** own vendor discovery, license activation, update discovery, package delivery or checksum verification. It only registers an installed-product descriptor with the optional Core Blueprint Updates client.

## Canonical pilot identity

- Plugin basename: `core-blueprint-backups/core-blueprint-backups.php`
- VN1-C release candidate: `1.0.0-rc4`
- WordPress Update URI: `https://coreblueprint.io/`
- Durable vendor ID: `core-blueprint`
- License Product key: `core-blueprint-backups`
- Marketplace software UUID: **not hard-coded in this plugin**

The WordPress Update URI prevents wordpress.org from becoming update authority for this premium plugin. The generic managed-software authority is not supplied by the Backups adapter: Core Blueprint Updates resolves `vendor_id = core-blueprint` against the operator-discovered and pinned Vendor Connection.

The adapter therefore declares identity only. It must not declare `vendor_origin`, `canonical_origin`, service URLs or endpoint maps. A vendor identity may be associated with only the canonical origin that Core Blueprint Updates has pinned through the public discovery contract.

The License Product key remains a bootstrap/transport identifier. Backups advertises that key to the central Updates client. During the first successful licensed update handshake, Marketplace resolves the Product key through the public License Manager Product Catalog + Product Identity contracts and returns the canonical Marketplace software UUID. Core Blueprint Updates verifies the returned Marketplace software slug/type against the installed plugin before storing the UUID. After that first successful bootstrap the UUID is pinned: a missing, malformed or different UUID fails closed and cannot silently replace the established software identity.

**VN1-C does not yet change licensing or native updater routing.** The existing Core Blueprint product flow remains fixed to its current service authority until VN1-D/VN1-E move licensing and managed update routing onto the pinned vendor connection.

## Dependency boundary

The Backups adapter:

1. attaches only to `cb_updates_register_products`;
2. checks whether `CB\Updates\ProductRegistry` exists;
3. registers name, plugin basename, installed version, Product key and durable vendor ID;
4. leaves `software_uuid` empty until the central Updates client learns the canonical Marketplace identity;
5. declares no vendor origin, service route or endpoint authority.

It must never:

- store or transmit a license key;
- store an activation token;
- call coreblueprint.io or another vendor endpoint directly;
- consume Marketplace, License Manager or Repository internals;
- implement WordPress updater hooks itself;
- make Core Blueprint Updates a hard activation dependency.

## VN1-C repository acceptance

VN1-C is repository-complete for Backups only when:

1. the Backups package/runtime version is `1.0.0-rc4`;
2. the adapter registers `vendor_id = core-blueprint` alongside the existing product identity;
3. the adapter does not include vendor origin/service authority fields;
4. the Marketplace software UUID remains empty in the adapter;
5. the adapter remains registration-only and contains no HTTP, licensing or server-module dependency;
6. the Backups Updates pilot conformance workflow passes.

This proves the product-registration boundary only. It does **not** prove vendor-level licensing, vendor-aware package routing or a real WordPress update installation.

## VN1-F Infused Academy acceptance sequence

After VN1-D and VN1-E are merged and independently green:

1. Use an older approved Backups build on Infused Academy as the source-version baseline and keep the reviewed `1.0.0-rc4` package available as the VN1-C pilot target.
2. Confirm the packaged plugin root remains `core-blueprint-backups/` and its header contains `Update URI: https://coreblueprint.io/`.
3. On `coreblueprint.io`, confirm a License Product whose Product key is exactly `core-blueprint-backups`.
4. Confirm the Marketplace Backups software entry is bound to that License Product and to its immutable Marketplace software UUID.
5. Publish the reviewed Backups target ZIP through Core Blueprint Repository so Marketplace records the exact immutable Repository release reference and SHA-256.
6. Install the then-current approved Core Blueprint Updates client on Infused Academy.
7. In Core Blueprint Updates, discover `https://coreblueprint.io` and confirm the pinned Vendor Connection identifies durable vendor ID `core-blueprint`.
8. Confirm Backups resolves to that pinned vendor through its registered `vendor_id` without exposing or accepting a vendor origin in the product adapter.
9. Assign/connect the valid Backups license under the VN1-D vendor-level licensing model. The raw license key must not be persisted.
10. Trigger/check native WordPress plugin updates and confirm the older Backups build receives the reviewed target metadata through the vendor-aware Updates client.
11. Run **Update now**. Core Blueprint Updates must obtain fresh protected-package authorization, download from the pinned vendor authority, verify the package SHA-256 and hand the verified ZIP to WordPress.
12. Confirm Backups remains active after the update and reports the reviewed target version.
13. Re-run a normal Backups database backup and verify operation still succeeds.

## Fail-closed acceptance cases

Before declaring VN1-F complete, verify at least:

- the registered `vendor_id` has no pinned Vendor Connection: managed licensing/update routing is refused;
- vendor identity/origin drift: the product cannot silently move to another origin;
- invalid/revoked activation: no update package;
- the registered Product key has no matching License Product or Marketplace binding: activation/update authorization fails closed;
- a mismatched Marketplace software slug/type is returned: the Updates client refuses the identity;
- first authorized response omits a valid Marketplace UUID: refused;
- after UUID bootstrap, a different valid Marketplace UUID is returned for the same slug/type: refused without replacing the pinned local UUID;
- suspended Marketplace publisher or retired software: no update distribution;
- unavailable Repository delivery: metadata may report an update, but no installable package URL is issued;
- altered package bytes/checksum mismatch: Core Blueprint Updates refuses the ZIP;
- expired package grant: a fresh check is performed before download rather than trusting stale transient metadata.

PILOT-1 is complete only after the real VN1-F Infused Academy native-update path passes this matrix.
