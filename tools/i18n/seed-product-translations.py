#!/usr/bin/env python3
from __future__ import annotations

import ast
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
LANG = ROOT / "languages"
DOMAIN = "core-blueprint-backups"
LOCALES = ("nl_NL", "de_DE", "fr_FR", "es_ES", "it_IT", "pt_PT")

DESCRIPTION = (
    "Governed database and full-site backups for Core Blueprint, with local "
    "restore/migration, scheduling, CLI and optional Beacon remote orchestration."
)

TRANSLATIONS = {
    "nl_NL": {
        "Core Blueprint Backups": "Core Blueprint Backups",
        "https://coreblueprint.io": "https://coreblueprint.io",
        DESCRIPTION: "Beheerde database- en volledige siteback-ups voor Core Blueprint, met lokaal herstel/migratie, planning, CLI en optionele externe orkestratie via Beacon.",
        "Core Blueprint Backups:": "Core Blueprint Backups:",
        "none": "geen",
    },
    "de_DE": {
        "Core Blueprint Backups": "Core Blueprint Backups",
        "https://coreblueprint.io": "https://coreblueprint.io",
        DESCRIPTION: "Verwaltete Datenbank- und vollständige Website-Backups für Core Blueprint mit lokaler Wiederherstellung/Migration, Zeitplanung, CLI und optionaler Remote-Orchestrierung über Beacon.",
        "Core Blueprint Backups:": "Core Blueprint Backups:",
        "none": "keine",
    },
    "fr_FR": {
        "Core Blueprint Backups": "Core Blueprint Backups",
        "https://coreblueprint.io": "https://coreblueprint.io",
        DESCRIPTION: "Sauvegardes gérées de la base de données et du site complet pour Core Blueprint, avec restauration/migration locale, planification, CLI et orchestration à distance facultative via Beacon.",
        "Core Blueprint Backups:": "Core Blueprint Backups:",
        "none": "aucun",
    },
    "es_ES": {
        "Core Blueprint Backups": "Core Blueprint Backups",
        "https://coreblueprint.io": "https://coreblueprint.io",
        DESCRIPTION: "Copias de seguridad gestionadas de la base de datos y del sitio completo para Core Blueprint, con restauración/migración local, programación, CLI y orquestación remota opcional mediante Beacon.",
        "Core Blueprint Backups:": "Core Blueprint Backups:",
        "none": "ninguno",
    },
    "it_IT": {
        "Core Blueprint Backups": "Core Blueprint Backups",
        "https://coreblueprint.io": "https://coreblueprint.io",
        DESCRIPTION: "Backup gestiti del database e dell’intero sito per Core Blueprint, con ripristino/migrazione locale, pianificazione, CLI e orchestrazione remota opzionale tramite Beacon.",
        "Core Blueprint Backups:": "Core Blueprint Backups:",
        "none": "nessuno",
    },
    "pt_PT": {
        "Core Blueprint Backups": "Core Blueprint Backups",
        "https://coreblueprint.io": "https://coreblueprint.io",
        DESCRIPTION: "Cópias de segurança geridas da base de dados e do site completo para o Core Blueprint, com restauro/migração local, agendamento, CLI e orquestração remota opcional através do Beacon.",
        "Core Blueprint Backups:": "Core Blueprint Backups:",
        "none": "nenhum",
    },
}


def po_literal(fragment: str) -> str:
    value = ast.literal_eval(fragment.strip())
    if not isinstance(value, str):
        raise ValueError("invalid PO literal")
    return value


def msgid_from_block(block: str) -> str | None:
    lines = block.splitlines()
    for index, line in enumerate(lines):
        if not line.startswith("msgid "):
            continue
        value = po_literal(line[6:])
        cursor = index + 1
        while cursor < len(lines) and lines[cursor].startswith('"'):
            value += po_literal(lines[cursor])
            cursor += 1
        return value or None
    return None


def block(msgid: str, msgstr: str) -> str:
    return "msgid " + json.dumps(msgid, ensure_ascii=False) + "\nmsgstr " + json.dumps(msgstr, ensure_ascii=False)


for locale in LOCALES:
    po = LANG / f"{DOMAIN}-{locale}.po"
    text = po.read_text(encoding="utf-8")
    existing = {
        key
        for raw in re.split(r"\n\s*\n", text.strip())
        if (key := msgid_from_block(raw)) is not None
    }
    additions = [
        block(msgid, translation)
        for msgid, translation in TRANSLATIONS[locale].items()
        if msgid not in existing
    ]
    if additions:
        po.write_text(text.rstrip() + "\n\n" + "\n\n".join(additions) + "\n", encoding="utf-8")
        print(f"{locale}: seeded {len(additions)} reviewed product translations")
    else:
        print(f"{locale}: reviewed product translations already present")
