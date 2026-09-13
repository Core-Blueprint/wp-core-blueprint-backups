#!/usr/bin/env python3
from __future__ import annotations

import argparse
import ast
import json
from pathlib import Path
import re
import shutil
import subprocess
import tempfile

SCRIPT_DIR = Path(__file__).resolve().parent
ROOT = SCRIPT_DIR.parents[1]
CONFIG_PATH = SCRIPT_DIR / "config.json"
LOCALES = ("nl_NL", "de_DE", "fr_FR", "es_ES", "it_IT", "pt_PT")
SHARED_TRANSLATIONS = {
    "PHP %1$s or newer is required. This server runs PHP %2$s.": {"nl_NL": "PHP %1$s of nieuwer is vereist. Deze server draait PHP %2$s.", "de_DE": "PHP %1$s oder neuer ist erforderlich. Auf diesem Server läuft PHP %2$s.", "fr_FR": "PHP %1$s ou une version ultérieure est requis. Ce serveur utilise PHP %2$s.", "es_ES": "Se requiere PHP %1$s o posterior. Este servidor ejecuta PHP %2$s.", "it_IT": "È richiesto PHP %1$s o una versione successiva. Questo server utilizza PHP %2$s.", "pt_PT": "É necessário PHP %1$s ou mais recente. Este servidor utiliza PHP %2$s."},
    "Core Blueprint must be installed and active.": {"nl_NL": "Core Blueprint moet geïnstalleerd en actief zijn.", "de_DE": "Core Blueprint muss installiert und aktiviert sein.", "fr_FR": "Core Blueprint doit être installé et activé.", "es_ES": "Core Blueprint debe estar instalado y activo.", "it_IT": "Core Blueprint deve essere installato e attivo.", "pt_PT": "O Core Blueprint tem de estar instalado e ativo."},
    "Core API %1$s or a newer compatible minor version is required. Available Core API: %2$s.": {"nl_NL": "Core API %1$s of een nieuwere compatibele minorversie is vereist. Beschikbare Core API: %2$s.", "de_DE": "Core API %1$s oder eine neuere kompatible Minor-Version ist erforderlich. Verfügbare Core API: %2$s.", "fr_FR": "Core API %1$s ou une version mineure compatible plus récente est requise. Core API disponible : %2$s.", "es_ES": "Se requiere Core API %1$s o una versión menor compatible más reciente. Core API disponible: %2$s.", "it_IT": "È richiesta Core API %1$s o una versione minor compatibile più recente. Core API disponibile: %2$s.", "pt_PT": "É necessária a Core API %1$s ou uma versão minor compatível mais recente. Core API disponível: %2$s."},
    "Required Core Blueprint Base contracts are unavailable.": {"nl_NL": "Vereiste Core Blueprint Base-contracten zijn niet beschikbaar.", "de_DE": "Erforderliche Core Blueprint Base-Verträge sind nicht verfügbar.", "fr_FR": "Les contrats Core Blueprint Base requis ne sont pas disponibles.", "es_ES": "Los contratos requeridos de Core Blueprint Base no están disponibles.", "it_IT": "I contratti Core Blueprint Base richiesti non sono disponibili.", "pt_PT": "Os contratos Core Blueprint Base necessários não estão disponíveis."},
    "Ready": {"nl_NL": "Gereed", "de_DE": "Bereit", "fr_FR": "Prêt", "es_ES": "Listo", "it_IT": "Pronto", "pt_PT": "Pronto"},
    "none": {"nl_NL": "geen", "de_DE": "keine", "fr_FR": "aucune", "es_ES": "ninguna", "it_IT": "nessuna", "pt_PT": "nenhuma"},
}


def fail(message: str) -> None: raise SystemExit(f"ERROR: {message}")
def config() -> dict:
    try: data = json.loads(CONFIG_PATH.read_text(encoding="utf-8"))
    except (OSError, ValueError) as exc: fail(f"could not read tools/i18n/config.json: {exc}")
    required = {"product", "domain", "main_file", "version_constant", "exclude", "skip_js", "commit_mo", "source_paths"}; missing = sorted(required - set(data))
    if missing: fail("missing i18n config keys: " + ", ".join(missing))
    return data
def command(name: str) -> str:
    resolved = shutil.which(name)
    if not resolved: fail(f"required i18n command is unavailable: {name}")
    return resolved
def run(args: list[str], *, capture: bool = False) -> str:
    result = subprocess.run(args, cwd=ROOT, text=True, capture_output=capture)
    if result.returncode:
        detail = (result.stderr or result.stdout).strip() if capture else ""; fail(f"command failed ({result.returncode}): {' '.join(args)}" + (f": {detail}" if detail else ""))
    return result.stdout if capture else ""
def source_version(cfg: dict) -> str:
    text = (ROOT / cfg["main_file"]).read_text(encoding="utf-8"); header = re.search(r"^\s*\*\s*Version:\s*([^\s]+)", text, re.M); const = re.search(r"define\(\s*['\"]" + re.escape(cfg["version_constant"]) + r"['\"]\s*,\s*['\"]([^'\"]+)['\"]\s*\)", text)
    if not header or not const or header.group(1) != const.group(1): fail("plugin Version header and version constant must exist and match")
    return header.group(1)
def source_stamp(cfg: dict, git_bin: str) -> str:
    raw = run([git_bin, "log", "-1", "--format=%ct", "--", *[str(path) for path in cfg["source_paths"]]], capture=True).strip()
    if not raw.isdigit(): fail("could not resolve deterministic source timestamp from git")
    import datetime; return datetime.datetime.fromtimestamp(int(raw), datetime.timezone.utc).strftime("%Y-%m-%d %H:%M+0000")
def generate_pot(cfg: dict, output: Path, wp_bin: str, version: str) -> None:
    args = [wp_bin, "i18n", "make-pot", ".", str(output), f"--domain={cfg['domain']}", "--exclude=" + ",".join(cfg["exclude"]), "--headers=" + json.dumps({"Project-Id-Version": f"{cfg['product']} {version}"}, separators=(",", ":")), "--quiet"]
    if cfg["skip_js"]: args.append("--skip-js")
    run(args)
def rewrite_header(path: Path, values: dict[str, str], *, insert_missing: bool = False) -> None:
    lines = path.read_text(encoding="utf-8").splitlines(); found: set[str] = set()
    for index, line in enumerate(lines):
        for key, value in values.items():
            if line.startswith(f'"{key}:'): lines[index] = f'"{key}: {value}\\n"'; found.add(key)
    missing = [key for key in values if key not in found]
    if missing and insert_missing:
        header_end = next((i for i, line in enumerate(lines[1:], start=1) if line == ""), None)
        if header_end is None: fail(f"could not locate PO metadata header in {path.relative_to(ROOT)}")
        for key in reversed(missing): lines.insert(header_end, f'"{key}: {values[key]}\\n"')
        missing = []
    if missing: fail(f"catalog header missing {missing} in {path.relative_to(ROOT)}")
    path.write_text("\n".join(lines).rstrip() + "\n", encoding="utf-8")
def header_value(path: Path, key: str) -> str | None:
    prefix = f'"{key}: '
    for line in path.read_text(encoding="utf-8").splitlines():
        if line.startswith(prefix) and line.endswith('\\n"'): return line[len(prefix):-3]
    return None
def po_literal(fragment: str) -> str:
    try: value = ast.literal_eval(fragment.strip())
    except (SyntaxError, ValueError) as exc: fail(f"invalid PO string literal: {fragment}: {exc}")
    if not isinstance(value, str): fail(f"invalid PO string value: {fragment}")
    return value
def directive(lines: list[str], keyword: str) -> str | None:
    prefix = keyword + " "
    for index, line in enumerate(lines):
        if not line.startswith(prefix): continue
        value = po_literal(line[len(prefix):]); cursor = index + 1
        while cursor < len(lines) and lines[cursor].startswith('"'): value += po_literal(lines[cursor]); cursor += 1
        return value
    return None
def plural_values(lines: list[str]) -> list[str]:
    values: dict[int, str] = {}; index = 0
    while index < len(lines):
        match = re.match(r'^msgstr\[(\d+)\]\s+(".*")$', lines[index])
        if not match: index += 1; continue
        plural_index = int(match.group(1)); value = po_literal(match.group(2)); index += 1
        while index < len(lines) and lines[index].startswith('"'): value += po_literal(lines[index]); index += 1
        values[plural_index] = value
    return [values[key] for key in sorted(values)]
def blocks(path: Path) -> list[str]: return re.split(r"\n\s*\n", path.read_text(encoding="utf-8").strip())
def entry_key(block: str) -> str | None:
    lines = block.splitlines(); msgid = directive(lines, "msgid")
    if msgid is None or msgid == "": return None
    context = directive(lines, "msgctxt"); plural = directive(lines, "msgid_plural"); key = msgid if plural is None else msgid + "\x00" + plural
    return key if context is None else context + "\x04" + key
def key_set(path: Path) -> set[str]: return {key for block in blocks(path) if (key := entry_key(block)) is not None}
def has_fuzzy(block: str) -> bool: return any(line.startswith("#,") and "fuzzy" in {flag.strip() for flag in line[2:].split(",")} for line in block.splitlines())
def translation_values(block: str) -> list[str]:
    lines = block.splitlines()
    if directive(lines, "msgid_plural") is not None: return plural_values(lines)
    value = directive(lines, "msgstr"); return [] if value is None else [value]
def replace_singular_translation(block: str, translation: str) -> str:
    lines = block.splitlines()
    if directive(lines, "msgid_plural") is not None: fail("shared translation target unexpectedly became plural")
    cleaned = []
    for line in lines:
        if line.startswith("#,"):
            flags = [flag.strip() for flag in line[2:].split(",") if flag.strip() and flag.strip() != "fuzzy"]
            if flags: cleaned.append("#, " + ", ".join(flags))
        else: cleaned.append(line)
    for index, line in enumerate(cleaned):
        if line.startswith("msgstr "):
            end = index + 1
            while end < len(cleaned) and cleaned[end].startswith('"'): end += 1
            return "\n".join(cleaned[:index] + ["msgstr " + json.dumps(translation, ensure_ascii=False)] + cleaned[end:])
    fail("shared translation target has no msgstr")
def apply_shared_translations(po_path: Path, locale: str, pot_keys: set[str]) -> None:
    current = blocks(po_path); by_msgid: dict[str, int] = {}
    for index, block in enumerate(current):
        lines = block.splitlines(); msgid = directive(lines, "msgid")
        if msgid and directive(lines, "msgctxt") is None and directive(lines, "msgid_plural") is None: by_msgid[msgid] = index
    for msgid, translations in SHARED_TRANSLATIONS.items():
        if msgid not in pot_keys: continue
        if msgid not in by_msgid: fail(f"{locale} PO missing shared translation target: {msgid}")
        current[by_msgid[msgid]] = replace_singular_translation(current[by_msgid[msgid]], translations[locale])
    po_path.write_text("\n\n".join(current).rstrip() + "\n", encoding="utf-8")
def validate_po(po_path: Path, pot_keys: set[str], msgfmt_bin: str, mo_output: Path | None = None) -> None:
    po_blocks = blocks(po_path); po_keys = {key for block in po_blocks if (key := entry_key(block)) is not None}; missing = sorted(pot_keys - po_keys); stale = sorted(po_keys - pot_keys)
    if missing: fail(f"{po_path.name} missing {len(missing)} current POT entries; first: {missing[0]}")
    if stale: fail(f"{po_path.name} contains {len(stale)} stale non-obsolete entries; first: {stale[0]}")
    if re.search(r"^#~", po_path.read_text(encoding="utf-8"), re.M): fail(f"obsolete entries remain in {po_path.name}")
    for block in po_blocks:
        key = entry_key(block)
        if key is None: continue
        if has_fuzzy(block): fail(f"fuzzy translation in {po_path.name}: {key}")
        values = translation_values(block)
        if not values or any(not value.strip() for value in values): fail(f"untranslated entry in {po_path.name}: {key}")
    if mo_output is None:
        with tempfile.NamedTemporaryFile(prefix="cb-i18n-", suffix=".mo") as handle: run([msgfmt_bin, "--check-format", "--check-header", "-o", handle.name, str(po_path)])
    else: run([msgfmt_bin, "--check-format", "--check-header", "-o", str(mo_output), str(po_path)])
def normalize_po_headers(po: Path, cfg: dict, version: str, stamp: str, locale: str) -> None: rewrite_header(po, {"Project-Id-Version": f"{cfg['product']} {version}", "POT-Creation-Date": stamp, "PO-Revision-Date": stamp, "Last-Translator": "Core Blueprint", "Language-Team": locale, "Language": locale, "Generated-By": "Core Blueprint i18n tooling v1"}, insert_missing=True)
def do_update(args: argparse.Namespace, cfg: dict) -> None:
    wp = command(args.wp_bin); msgmerge = command(args.msgmerge_bin); msgattrib = command(args.msgattrib_bin); msgfmt = command(args.msgfmt_bin); git_bin = command(args.git_bin); version = source_version(cfg); stamp = source_stamp(cfg, git_bin); lang = ROOT / "languages"; lang.mkdir(parents=True, exist_ok=True); pot = lang / f"{cfg['domain']}.pot"; generate_pot(cfg, pot, wp, version); rewrite_header(pot, {"Project-Id-Version": f"{cfg['product']} {version}", "POT-Creation-Date": stamp}); pot_keys = key_set(pot)
    if not pot_keys: fail("generated POT contains no translatable messages")
    for locale in LOCALES:
        po = lang / f"{cfg['domain']}-{locale}.po"
        if not po.is_file(): fail(f"missing reviewed translation source: {po.relative_to(ROOT)}")
        run([msgmerge, "--update", "--backup=none", "--no-fuzzy-matching", str(po), str(pot)]); tmp = po.with_suffix(".po.tmp"); run([msgattrib, "--no-obsolete", "-o", str(tmp), str(po)]); tmp.replace(po); apply_shared_translations(po, locale, pot_keys); normalize_po_headers(po, cfg, version, stamp, locale); validate_po(po, pot_keys, msgfmt, lang / f"{cfg['domain']}-{locale}.mo" if cfg["commit_mo"] else None)
    print(f"PASS: {cfg['product']} catalogs updated from source; locales={len(LOCALES)}")
def do_check(args: argparse.Namespace, cfg: dict) -> None:
    wp = command(args.wp_bin); msgfmt = command(args.msgfmt_bin); version = source_version(cfg); lang = ROOT / "languages"; pot = lang / f"{cfg['domain']}.pot"
    if not pot.is_file(): fail(f"missing committed POT: {pot.relative_to(ROOT)}")
    committed_keys = key_set(pot); expected_project = f"{cfg['product']} {version}"
    if header_value(pot, "Project-Id-Version") != expected_project: fail(f"POT Project-Id-Version does not match current release: {expected_project}")
    with tempfile.TemporaryDirectory(prefix="cb-i18n-check-") as tmpdir:
        fresh = Path(tmpdir) / pot.name; generate_pot(cfg, fresh, wp, version); fresh_keys = key_set(fresh)
        if committed_keys != fresh_keys: fail(f"POT/source drift: missing={len(fresh_keys - committed_keys)} stale={len(committed_keys - fresh_keys)}")
        for locale in LOCALES:
            po = lang / f"{cfg['domain']}-{locale}.po"
            if not po.is_file(): fail(f"missing locale PO: {po.relative_to(ROOT)}")
            if header_value(po, "Project-Id-Version") != expected_project: fail(f"{po.name} Project-Id-Version does not match current release: {expected_project}")
            if header_value(po, "Language") != locale: fail(f"{po.name} Language header must be {locale}")
            validate_po(po, committed_keys, msgfmt); by_msgid = {}
            for block in blocks(po):
                lines = block.splitlines(); msgid = directive(lines, "msgid")
                if msgid and directive(lines, "msgctxt") is None and directive(lines, "msgid_plural") is None:
                    vals = translation_values(block); by_msgid[msgid] = vals[0] if vals else ""
            for msgid in {m for m in SHARED_TRANSLATIONS if m in committed_keys}:
                if by_msgid.get(msgid) != SHARED_TRANSLATIONS[msgid][locale]: fail(f"shared translation drift in {po.name}: {msgid}")
            if cfg["commit_mo"]:
                committed_mo = lang / f"{cfg['domain']}-{locale}.mo"
                if not committed_mo.is_file(): fail(f"missing committed MO: {committed_mo.relative_to(ROOT)}")
                generated_mo = Path(tmpdir) / committed_mo.name; validate_po(po, committed_keys, msgfmt, generated_mo)
                if generated_mo.read_bytes() != committed_mo.read_bytes(): fail(f"committed MO is not reproducible from {po.name}")
    print(f"PASS: {cfg['product']} i18n check source=POT=PO locales={len(LOCALES)}")
def main() -> None:
    p = argparse.ArgumentParser(); p.add_argument("mode", choices=("update", "check")); p.add_argument("--wp-bin", default="wp"); p.add_argument("--msgmerge-bin", default="msgmerge"); p.add_argument("--msgattrib-bin", default="msgattrib"); p.add_argument("--msgfmt-bin", default="msgfmt"); p.add_argument("--git-bin", default="git"); args = p.parse_args(); cfg = config(); do_update(args, cfg) if args.mode == "update" else do_check(args, cfg)
if __name__ == "__main__": main()