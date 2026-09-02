from __future__ import annotations

import re
import time
from pathlib import Path

import polib
from deep_translator import GoogleTranslator

ROOT = Path(__file__).resolve().parents[1]
POT_PATH = ROOT / 'languages' / 'core-blueprint-backups.pot'

LOCALES = {
    'nl_NL': ('nl', 'nplurals=2; plural=(n != 1);'),
    'de_DE': ('de', 'nplurals=2; plural=(n != 1);'),
    'fr_FR': ('fr', 'nplurals=2; plural=(n > 1);'),
    'es_ES': ('es', 'nplurals=2; plural=(n != 1);'),
    'it_IT': ('it', 'nplurals=2; plural=(n != 1);'),
    'pt_PT': ('pt', 'nplurals=2; plural=(n != 1);'),
}

PLACEHOLDER_RE = re.compile(r'%(?:\d+\$)?[-+0 #]*(?:\d+)?(?:\.\d+)?[bcdeEfFgGosuxX]')
TAG_RE = re.compile(r'<[^>]+>')
SEP = ' ZXQSEPCB2026QXZ '
MAX_BATCH_CHARS = 3000


def mask(text: str) -> tuple[str, dict[str, str]]:
    replacements: dict[str, str] = {}

    def replace(regex: re.Pattern[str], prefix: str, value: str) -> str:
        def repl(match: re.Match[str]) -> str:
            token = f'ZXQ{prefix}{len(replacements)}QXZ'
            replacements[token] = match.group(0)
            return token
        return regex.sub(repl, value)

    text = replace(PLACEHOLDER_RE, 'PH', text)
    text = replace(TAG_RE, 'TAG', text)
    return text, replacements


def restore(text: str, replacements: dict[str, str]) -> str:
    for token, original in replacements.items():
        text = text.replace(token, original)
    missing = [token for token in replacements if token in text]
    if missing:
        raise RuntimeError(f'Unrestored tokens: {missing}')
    return text


def no_translation_found(exc: Exception) -> bool:
    return 'No translation was found using the current translator' in str(exc)


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
                raise RuntimeError(f'batch split mismatch: {len(parts)} != {len(texts)}')
            return [restore(part.strip(), mapping) for part, mapping in zip(parts, maps)]
        except Exception as exc:
            last_error = exc
            time.sleep(1.5 * (attempt + 1))

    # Safe fallback: translate each string independently rather than accepting a
    # malformed batch. A translator response that explicitly says no translation
    # exists is allowed to preserve that one source term (typically product or
    # technical vocabulary such as "Backups"). All other failures stay fatal.
    out: list[str] = []
    for value, mapping in zip(masked, maps):
        for attempt in range(4):
            try:
                translated = translator.translate(value)
                out.append(restore(translated.strip(), mapping))
                break
            except Exception as exc:
                last_error = exc
                if no_translation_found(exc):
                    out.append(restore(value, mapping))
                    break
                time.sleep(1.5 * (attempt + 1))
        else:
            raise RuntimeError(f'translation failed after retries: {last_error}')
    return out


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


def placeholder_signature(text: str) -> list[str]:
    return sorted(PLACEHOLDER_RE.findall(text))


def main() -> None:
    pot = polib.pofile(str(POT_PATH))
    source_entries = [entry for entry in pot if not entry.obsolete and entry.msgid]
    print(f'SOURCE_ENTRIES={len(source_entries)}')

    for locale, (target, plural_forms) in LOCALES.items():
        translator = GoogleTranslator(source='en', target=target)
        unique: list[str] = []
        seen: set[str] = set()
        for entry in source_entries:
            for value in [entry.msgid, entry.msgid_plural]:
                if value and value not in seen:
                    seen.add(value)
                    unique.append(value)

        translations: dict[str, str] = {}
        groups = batch_strings(unique)
        for index, group in enumerate(groups, 1):
            values = translate_group(translator, group)
            if len(values) != len(group):
                raise RuntimeError('translation count mismatch')
            for source, translated in zip(group, values):
                if placeholder_signature(source) != placeholder_signature(translated):
                    raise RuntimeError(f'placeholder mismatch for {locale}: {source!r} -> {translated!r}')
                translations[source] = translated
            print(f'{locale}: batch {index}/{len(groups)}')
            time.sleep(0.2)

        po = polib.POFile()
        po.metadata = {
            'Project-Id-Version': 'Core Blueprint Backups 1.0.0-rc1',
            'Report-Msgid-Bugs-To': '',
            'POT-Creation-Date': pot.metadata.get('POT-Creation-Date', ''),
            'PO-Revision-Date': time.strftime('%Y-%m-%d %H:%M+0000', time.gmtime()),
            'Last-Translator': 'Core Blueprint',
            'Language-Team': locale,
            'Language': locale,
            'MIME-Version': '1.0',
            'Content-Type': 'text/plain; charset=UTF-8',
            'Content-Transfer-Encoding': '8bit',
            'Plural-Forms': plural_forms,
            'Generated-By': 'Core Blueprint launch QC catalog refresh',
        }

        for source in source_entries:
            entry = polib.POEntry(
                msgid=source.msgid,
                msgctxt=source.msgctxt,
                occurrences=list(source.occurrences),
                comment=source.comment,
                tcomment=source.tcomment,
                flags=list(source.flags),
            )
            if source.msgid_plural:
                entry.msgid_plural = source.msgid_plural
                entry.msgstr_plural[0] = translations[source.msgid]
                entry.msgstr_plural[1] = translations[source.msgid_plural]
            else:
                entry.msgstr = translations[source.msgid]
            po.append(entry)

        po_path = ROOT / 'languages' / f'core-blueprint-backups-{locale}.po'
        mo_path = ROOT / 'languages' / f'core-blueprint-backups-{locale}.mo'
        po.save(str(po_path))
        po.save_as_mofile(str(mo_path))
        print(f'{locale}: ENTRIES={len(source_entries)} PO_MO_WRITTEN')

    print('BACKUPS_I18N_REFRESH_OK')


if __name__ == '__main__':
    main()
