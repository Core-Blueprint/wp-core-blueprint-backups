# Backups release tooling

`build-release.py` is the canonical production release builder for Core Blueprint Backups.

## Requirements

- Python 3.10 or newer
- Node.js for JavaScript syntax validation
- GNU gettext (`msgfmt`) for PO validation and deterministic MO compilation
- an actual PHP 8.4 CLI binary
- an actual PHP 8.5 CLI binary

## Usage

Run from the repository root:

```bash
python3 tools/build-release.py \
  --output dist \
  --php-bin /path/to/php8.4 \
  --php-bin /path/to/php8.5
```

The version is intentionally fixed at `1.0.0-rc1` during the current Golden release-candidate patch cycle.

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
- all six launch locales exist: `nl_NL`, `de_DE`, `fr_FR`, `es_ES`, `it_IT`, `pt_PT`;
- PO catalogs have no active fuzzy or untranslated entries;
- printf placeholders remain compatible with their English source strings;
- translation quality is not predominantly identical to English;
- MO catalogs are rebuilt from PO source with GNU gettext;
- every packaged PHP file lints on actual PHP 8.4 and PHP 8.5 runtimes;
- every packaged JavaScript file passes `node --check`;
- CSS passes structural brace/comment validation;
- the ZIP root and production-only boundary are canonical;
- the ZIP passes CRC verification;
- a SHA-256 sidecar is generated only after validation succeeds.

## Translation maintenance

`refresh-translations.py` is a maintainer aid, not release authority. It can create candidate translations from the English POT while protecting technical literals, HTML and printf placeholders.

Generated output must be reviewed in the target language before it is accepted into the release catalogs. CI must not automatically push machine-translated PO/MO files to a release branch.

The production builder remains the hard gate: a locale that is empty, fuzzy, placeholder-incompatible or predominantly identical to English cannot produce a Golden package.

## Failure handling

A failing build is not a releasable artifact. Fix the source, catalog or environment problem and rebuild. Never edit a generated ZIP manually to bypass a gate.

## Maintenance

When runtime directories, supported locales, the Base API contract or PHP support policy change, update `build-release.py` in the same patch. Keep tooling and documentation outside the release ZIP.
