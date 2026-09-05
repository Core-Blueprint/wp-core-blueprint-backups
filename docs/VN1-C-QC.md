# UPD-VN1-C — Backups Vendor Identity Pilot QC

Repository: `wp-core-blueprint-backups`

Candidate: Core Blueprint Backups `1.0.0-rc4`

## Contract

- Backups declares durable vendor identity `core-blueprint` through the central Updates registration hook.
- The adapter does not declare vendor origin, canonical origin, service descriptors or endpoints.
- Marketplace `software_uuid` remains deliberately unhardcoded.
- The adapter remains registration-only and contains no HTTP, license-key, activation-token, Marketplace, License Manager or Repository runtime dependency.

## Repository-side evidence

- Backups Updates pilot conformance: PASS after the rc4/vendor-aware adapter change.
- Pilot conformance: PASS again after the VN1-C pilot documentation update.
- Branch is based on canonical Backups rc3 `main` and has no behind drift at closure review.

This is registration-contract acceptance only. Real vendor-level licensing, package routing and native WordPress update acceptance remain VN1-D/VN1-E/VN1-F.
