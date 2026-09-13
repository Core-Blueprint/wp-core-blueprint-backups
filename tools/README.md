# Backups release tooling

`build-release.py` is the canonical production release builder for Core Blueprint Backups.

## Requirements

- Python 3.10 or newer
- WP-CLI with the `wp i18n` commands
- GNU gettext (`msgfmt`) for catalog validation and deterministic MO compilation
- Node.js for JavaScript syntax validation
- an actual PHP 8.4 CLI binary
- an actual PHP 8.5 CLI binary

`tools/i18n/update` additionally requires GNU gettext `msgmerge` and `msgattrib` when catalogs are intentionally refreshed.

## Usage

Run from the repository root:

```bash
python3 tools/build-release.py \
  --output dist \
  --php-bin /path/to/php8.4 \
  --php-bin /path/to/php8.5
```

The version is intentionally fixed at `1.0.0-rc1` during the current Golden release-candidate patch cycle.

## Localization authority

The only first-party catalog workflow is:

```text
tools/i18n/update   # mutating maintenance workflow
tools/i18n/check    # read-only release/conformance gate
```

POT and reviewed PO files are translation authority. The release builder runs `tools/i18n/check` before packaging and compiles fresh MO files from reviewed PO sources into the staged release.

There is no machine-translation refresh path, compatibility alias or second POT/PO authority. Translation drafting may happen outside the canonical workflow, but only deliberately reviewed PO content may enter release catalogs.

## Outputs

A successful build creates:

- `dist/core-blueprint-backups-1.0.0-rc1.zip`
- `dist/core-blueprint-backups-1.0.0-rc1.zip.sha256`

The ZIP contains exactly one WordPress plugin root:

```text
core-blueprint-backups/
```

Only production runtime material is packaged:

- `core-blueprint-backups.php`
- `uninstall.php`
- `src/`
- `assets/`
- `languages/`

Repository-only material such as `.github/`, `docs/`, `tests/` and `tools/` must never enter the customer package.

## Validation

The builder fails unless all of these gates pass:

- plugin header and `CB_BACKUPS_VERSION` are exactly `1.0.0-rc1`;
- `CB_BACKUPS_REQUIRED_API` is exactly `1.0`;
- `Requires PHP` remains `8.4`;
- canonical `tools/i18n/check` proves current source/POT/PO coverage, locale completeness, no fuzzy or untranslated release entries, placeholder compatibility and configured runtime-artifact reproducibility;
- fresh MO catalogs are compiled from reviewed PO source with GNU gettext;
- every packaged PHP file lints on actual PHP 8.4 and PHP 8.5 runtimes;
- every packaged JavaScript file passes `node --check`;
- CSS passes structural brace/comment validation;
- the ZIP root and production-only boundary are canonical;
- all six PO/MO locale artifacts are present in the ZIP;
- the ZIP passes CRC verification;
- a SHA-256 sidecar is generated only after validation succeeds.

## Failure handling

A failing localization check or build is not a releasable artifact. Fix the source, reviewed catalog or environment problem and rebuild. Never edit a generated ZIP manually to bypass a gate.

## Maintenance

When runtime directories, supported locales, the Base API contract or PHP support policy change, update the product config and release builder in the same patch. Keep tooling and documentation outside the release ZIP.
