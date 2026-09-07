#!/usr/bin/env python3
from __future__ import annotations

import argparse
import re
import time
from pathlib import Path

import polib
from deep_translator import GoogleTranslator

ROOT = Path(__file__).resolve().parents[1]
POT_PATH = ROOT / "languages" / "core-blueprint-backups.pot"
LOCALES = {
    "nl_NL": ("nl", "nplurals=2; plural=(n != 1);"),
    "de_DE": ("de", "nplurals=2; plural=(n != 1);"),
    "fr_FR": ("fr", "nplurals=2; plural=(n > 1);"),
    "es_ES": ("es", "nplurals=2; plural=(n != 1);"),
    "it_IT": ("it", "nplurals=2; plural=(n != 1);"),
    "pt_PT": ("pt", "nplurals=2; plural=(n != 1);"),
}
PLACEHOLDER_RE = re.compile(r"%(?:\d+\$)?[-+0 #]*(?:\d+)?(?:\.\d+)?[bcdeEfFgGosuxX%]")
TAG_RE = re.compile(r"<[^>]+>")
LITERAL_RE = re.compile(
    r"Core Blueprint(?: Backups| Hub)?|WordPress|WP-Cron|WP-CLI|Beacon|ZipArchive|AuditLog|\.cbbackup|REST|PHP|JSON|CSV|SQL|HTTPS|HTTP"
)
SEP = " ZXQSEPCB2026QXZ "
MAX_BATCH_CHARS = 2600


def mask(text: str) -> tuple[str, dict[str, str]]:
    replacements: dict[str, str] = {}

    def replace(regex: re.Pattern[str], prefix: str, value: str) -> str:
        def repl(match: re.Match[str]) -> str:
            token = f"ZXQ{prefix}{len(replacements)}QXZ"
            replacements[token] = match.group(0)
            return token
        return regex.sub(repl, value)

    text = replace(PLACEHOLDER_RE, "PH", text)
    text = replace(TAG_RE, "TAG", text)
    text = replace(LITERAL_RE, "LIT", text)
    return text, replacements


def restore(text: str, replacements: dict[str, str]) -> str:
    for token, original in replacements.items():
        text = text.replace(token, original)
    if any(token in text for token in replacements):
        raise RuntimeError("translation left protected tokens unresolved")
    return text


def placeholder_signature(text: str) -> list[str]:
    return sorted(PLACEHOLDER_RE.findall(text))


def translate_group(translator: GoogleTranslator, texts: list[str]) -> list[str]:
    masked: list[str] = []
    maps: list[dict[str, str]] = []
    for text in texts:
        value, mapping = mask(text)
        masked.append(value)
        maps.append(mapping)

    joined = SEP.join(masked)
    last_error: Exception | None = None
    for attempt in range(4):
        try:
            output = translator.translate(joined)
            parts = output.split(SEP)
            if len(parts) != len(texts):
                raise RuntimeError(f"batch split mismatch: {len(parts)} != {len(texts)}")
            return [restore(part.strip(), mapping) for part, mapping in zip(parts, maps)]
        except Exception as exc:
            last_error = exc
            time.sleep(1.5 * (attempt + 1))

    output: list[str] = []
    for value, mapping in zip(masked, maps):
        for attempt in range(4):
            try:
                translated = translator.translate(value)
                output.append(restore(translated.strip(), mapping))
                break
            except Exception as exc:
                last_error = exc
                time.sleep(1.5 * (attempt + 1))
        else:
            raise RuntimeError(f"translation failed after retries: {last_error}")
    return output


def batch_strings(strings: list[str]) -> list[list[str]]:
    groups: list[list[str]] = []
    current: list[str] = []
    size = 0
    for text in strings:
        projected = size + len(text) + (len(SEP) if current else 0)
        if current and projected > MAX_BATCH_CHARS:
            groups.append(current)
            current = []
            size = 0
        current.append(text)
        size += len(text) + (len(SEP) if len(current) > 1 else 0)
    if current:
        groups.append(current)
    return groups


def current_quality_ok() -> bool:
    for locale in LOCALES:
        path = ROOT / "languages" / f"core-blueprint-backups-{locale}.po"
        if not path.is_file():
            return False
        po = polib.pofile(str(path))
        substantial = 0
        identical = 0
        for entry in po:
            if entry.obsolete or not entry.msgid:
                continue
            values = []
            if entry.msgid_plural:
                values = [(entry.msgid, entry.msgstr_plural.get(0, "")), (entry.msgid_plural, entry.msgstr_plural.get(1, ""))]
            else:
                values = [(entry.msgid, entry.msgstr)]
            for source, translated in values:
                if not translated.strip():
                    return False
                alpha = re.sub(r"[^A-Za-zÀ-ÿ]", "", source)
                if len(alpha) >= 5 and source not in {"WordPress", "Core Blueprint", "Core Blueprint Hub", "Core Blueprint Backups", "WP-Cron", "WP-CLI"}:
                    substantial += 1
                    if source.casefold() == translated.casefold():
                        identical += 1
        if not substantial or identical / substantial > 0.35:
            return False
    return True


def refresh() -> None:
    pot = polib.pofile(str(POT_PATH))
    source_entries = [entry for entry in pot if not entry.obsolete and entry.msgid]
    print(f"SOURCE_ENTRIES={len(source_entries)}")

    for locale, (target, plural_forms) in LOCALES.items():
        translator = GoogleTranslator(source="en", target=target)
        unique: list[str] = []
        seen: set[str] = set()
        for entry in source_entries:
            for value in (entry.msgid, entry.msgid_plural):
                if value and value not in seen:
                    seen.add(value)
                    unique.append(value)

        translations: dict[str, str] = {}
        groups = batch_strings(unique)
        for index, group in enumerate(groups, 1):
            translated_values = translate_group(translator, group)
            if len(translated_values) != len(group):
                raise RuntimeError("translation count mismatch")
            for source, translated in zip(group, translated_values):
                if placeholder_signature(source) != placeholder_signature(translated):
                    raise RuntimeError(f"placeholder mismatch for {locale}: {source!r} -> {translated!r}")
                translations[source] = translated
            print(f"{locale}: batch {index}/{len(groups)}")
            time.sleep(0.25)

        po = polib.POFile()
        po.metadata = {
            "Project-Id-Version": "Core Blueprint Backups 1.0.0-rc1",
            "Report-Msgid-Bugs-To": "",
            "POT-Creation-Date": pot.metadata.get("POT-Creation-Date", ""),
            "PO-Revision-Date": time.strftime("%Y-%m-%d %H:%M+0000", time.gmtime()),
            "Last-Translator": "Core Blueprint",
            "Language-Team": locale,
            "Language": locale,
            "MIME-Version": "1.0",
            "Content-Type": "text/plain; charset=UTF-8",
            "Content-Transfer-Encoding": "8bit",
            "Plural-Forms": plural_forms,
            "Generated-By": "Core Blueprint Golden translation refresh",
        }

        for source in source_entries:
            entry = polib.POEntry(
                msgid=source.msgid,
                msgctxt=source.msgctxt,
                occurrences=list(source.occurrences),
                comment=source.comment,
                tcomment=source.tcomment,
                flags=[flag for flag in source.flags if flag != "fuzzy"],
            )
            if source.msgid_plural:
                entry.msgid_plural = source.msgid_plural
                entry.msgstr_plural[0] = translations[source.msgid]
                entry.msgstr_plural[1] = translations[source.msgid_plural]
            else:
                entry.msgstr = translations[source.msgid]
            po.append(entry)

        po_path = ROOT / "languages" / f"core-blueprint-backups-{locale}.po"
        mo_path = ROOT / "languages" / f"core-blueprint-backups-{locale}.mo"
        po.save(str(po_path))
        po.save_as_mofile(str(mo_path))
        print(f"{locale}: ENTRIES={len(source_entries)} PO_MO_WRITTEN")

    if not current_quality_ok():
        raise RuntimeError("translation quality gate still fails after refresh")
    print("BACKUPS_GOLDEN_I18N_REFRESH_OK")


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    if args.check:
        raise SystemExit(0 if current_quality_ok() else 1)
    refresh()


if __name__ == "__main__":
    main()
