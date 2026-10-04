# Backups developer and release tooling

Core Blueprint Backups uses three canonical validation levels:

- Level 1: `tools/check`
- Level 2: `tools/check-integration 7.0` and `tools/check-integration 7.1`
- Level 3: `tools/build-release`

CI and release workflows should invoke these canonical entrypoints instead of duplicating their test logic.

`tools/build-release` is the canonical customer-package builder for Core Blueprint Backups.

## Requirements

- Python 3.10 or newer
- WP-CLI with the canonical `wp i18n` commands
- GNU gettext (`msgfmt`) for catalog validation and staged MO compilation
- Node.js for JavaScript syntax validation
- an actual PHP 8.4 CLI binary
- an actual PHP 8.5 CLI binary

Level 1 (`tools/check`) additionally requires:

- npm dependencies installed with `npm ci`
- the pinned Playwright Chromium runtime

Level 2 (`tools/check-integration`) additionally requires:

- Docker
- the canonical local Base source checkout at `~/Downloads/wp-core-blueprint`

The integration runner provisions its own disposable WordPress root and canonical MariaDB 10.11.19 test service. The operator does not provide a WordPress root manually.

By default, Level 2 resolves Base `main` to an exact Git commit and stages an immutable snapshot. Use `CB_TEST_BASE_SOURCE` or `CB_TEST_BASE_REF` only when intentionally validating another Base authority.

`tools/i18n/update` additionally requires GNU gettext `msgmerge` and `msgattrib` when catalogs are intentionally refreshed.

## Usage

Run from the repository root:

```bash
tools/build-release \
  --output dist \
  --php-bin /path/to/php8.4 \
  --php-bin /path/to/php8.5
```

Version `1.0.0` is the stable WordPress.org submission release.

## Localization authority

The only first-party catalog workflow is:

```text
tools/i18n/update   # mutating maintenance workflow
tools/i18n/check    # read-only release/conformance gate
```

POT and reviewed PO files remain translation authority. The release builder runs `tools/i18n/check` before packaging. Because Backups uses `commit_mo: true`, canonical check must prove committed MO files reproducible from reviewed PO sources before the release can be accepted. Packaging then compiles fresh MO files from those reviewed PO sources inside isolated staging.

There is no machine-translation refresh path, compatibility alias or second POT/PO authority.

## Outputs

A successful build creates:

- `dist/core-blueprint-backups-1.0.0.zip`
- `dist/core-blueprint-backups-1.0.0.zip.sha256`

The ZIP contains exactly one canonical WordPress plugin root:

```text
core-blueprint-backups/
```

Only production runtime material is packaged:

- `core-blueprint-backups.php`
- `uninstall.php`
- `readme.txt`
- `src/`
- `assets/`
- `languages/`

Repository-only material such as `.github/`, `docs/`, `tests` and `tools/` must never enter the customer package.

## Validation

The builder fails closed unless all applicable gates pass, including:

- canonical version/API/PHP metadata;
- canonical `tools/i18n/check`;
- reviewed six-locale catalogs and staged MO compilation;
- PHP syntax on actual PHP 8.4 and PHP 8.5 runtimes;
- JavaScript syntax and CSS structural validation;
- canonical ZIP root and explicit runtime boundary;
- required PO/MO artifacts and CRC integrity;
- SHA-256 sidecar generation after validation succeeds.

A failing localization check or build is not a releasable artifact. Never edit a generated ZIP manually to bypass a gate.

## Maintenance

When runtime directories, supported locales, the Base API contract or PHP support policy change, update the product config and `tools/build-release` in the same patch. Do not add alternate release builders, aliases or compatibility wrappers.
