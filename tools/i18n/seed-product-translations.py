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
        "Plugins": "Plugins",
        "https://coreblueprint.io": "https://coreblueprint.io",
        DESCRIPTION: "Beheerde database- en volledige siteback-ups voor Core Blueprint, met lokaal herstel/migratie, planning, CLI en optionele externe orkestratie via Beacon.",
        "Core Blueprint Backups:": "Core Blueprint Backups:",
        "none": "geen",
        "Continue to secure sign-in": "Doorgaan naar beveiligd inloggen",
        "Verifying destination access and rewrite routing…": "Toegang tot de doelsite en rewrite-routing verifiëren…",
        "Destination rewrite verification did not reach WordPress. You remain signed in safely; check the destination permalink or web-server rewrite configuration and retry.": "De rewrite-verificatie van de doelsite bereikte WordPress niet. Je blijft veilig ingelogd; controleer de permalink- of rewrite-configuratie van de webserver op de doelsite en probeer het opnieuw.",
        "Destination recovery could not be finalized. Retry verification before completing the migration.": "Het herstel van de doelsite kon niet worden afgerond. Probeer de verificatie opnieuw voordat je de migratie voltooit.",
        "Reconciling destination…": "Doelsite afstemmen…",
        "Secure sign-in required": "Beveiligd inloggen vereist",
        "Secure sign-in is required before destination recovery can continue.": "Beveiligd inloggen is vereist voordat het herstel van de doelsite kan doorgaan.",
        "Destination rewrite verification has not completed yet. Retry the verification before finishing migration.": "De rewrite-verificatie van de doelsite is nog niet voltooid. Probeer de verificatie opnieuw voordat je de migratie afrondt.",
        "Migration recovery authentication is incomplete.": "De authenticatie voor migratieherstel is niet voltooid.",
        "Core Blueprint could not finalize migration recovery.": "Core Blueprint kon het migratieherstel niet afronden.",
        "Cross-site migration requires a Core Blueprint Base version with Migration Recovery support.": "Migratie tussen sites vereist een versie van Core Blueprint Base met ondersteuning voor Migration Recovery.",
        "Migration applied, awaiting destination recovery": "Migratie toegepast, wachten op herstel van de doelsite",
    },
    "de_DE": {
        "Core Blueprint Backups": "Core Blueprint Backups",
        "Plugins": "Plugins",
        "https://coreblueprint.io": "https://coreblueprint.io",
        DESCRIPTION: "Verwaltete Datenbank- und vollständige Website-Backups für Core Blueprint mit lokaler Wiederherstellung/Migration, Zeitplanung, CLI und optionaler Remote-Orchestrierung über Beacon.",
        "Core Blueprint Backups:": "Core Blueprint Backups:",
        "none": "keine",
        "Continue to secure sign-in": "Zur sicheren Anmeldung",
        "Verifying destination access and rewrite routing…": "Zugriff auf das Ziel und Rewrite-Routing werden geprüft…",
        "Destination rewrite verification did not reach WordPress. You remain signed in safely; check the destination permalink or web-server rewrite configuration and retry.": "Die Rewrite-Prüfung des Ziels hat WordPress nicht erreicht. Sie bleiben sicher angemeldet; prüfen Sie die Permalink- oder Rewrite-Konfiguration des Webservers am Ziel und versuchen Sie es erneut.",
        "Destination recovery could not be finalized. Retry verification before completing the migration.": "Die Wiederherstellung des Ziels konnte nicht abgeschlossen werden. Wiederholen Sie die Prüfung, bevor Sie die Migration abschließen.",
        "Reconciling destination…": "Ziel wird abgeglichen…",
        "Secure sign-in required": "Sichere Anmeldung erforderlich",
        "Secure sign-in is required before destination recovery can continue.": "Eine sichere Anmeldung ist erforderlich, bevor die Wiederherstellung des Ziels fortgesetzt werden kann.",
        "Destination rewrite verification has not completed yet. Retry the verification before finishing migration.": "Die Rewrite-Prüfung des Ziels ist noch nicht abgeschlossen. Wiederholen Sie die Prüfung, bevor Sie die Migration abschließen.",
        "Migration recovery authentication is incomplete.": "Die Authentifizierung für die Migrationswiederherstellung ist unvollständig.",
        "Core Blueprint could not finalize migration recovery.": "Core Blueprint konnte die Migrationswiederherstellung nicht abschließen.",
        "Cross-site migration requires a Core Blueprint Base version with Migration Recovery support.": "Die Migration zwischen Websites erfordert eine Core Blueprint Base-Version mit Unterstützung für Migration Recovery.",
        "Migration applied, awaiting destination recovery": "Migration angewendet, Zielwiederherstellung steht aus",
    },
    "fr_FR": {
        "Core Blueprint Backups": "Core Blueprint Backups",
        "Plugins": "Extensions",
        "https://coreblueprint.io": "https://coreblueprint.io",
        DESCRIPTION: "Sauvegardes gérées de la base de données et du site complet pour Core Blueprint, avec restauration/migration locale, planification, CLI et orchestration à distance facultative via Beacon.",
        "Core Blueprint Backups:": "Core Blueprint Backups:",
        "none": "aucun",
        "Continue to secure sign-in": "Continuer vers la connexion sécurisée",
        "Verifying destination access and rewrite routing…": "Vérification de l’accès à la destination et du routage des réécritures…",
        "Destination rewrite verification did not reach WordPress. You remain signed in safely; check the destination permalink or web-server rewrite configuration and retry.": "La vérification des réécritures de la destination n’a pas atteint WordPress. Vous restez connecté en toute sécurité ; vérifiez la configuration des permaliens ou des réécritures du serveur web de destination, puis réessayez.",
        "Destination recovery could not be finalized. Retry verification before completing the migration.": "La récupération de la destination n’a pas pu être finalisée. Relancez la vérification avant de terminer la migration.",
        "Reconciling destination…": "Réconciliation de la destination…",
        "Secure sign-in required": "Connexion sécurisée requise",
        "Secure sign-in is required before destination recovery can continue.": "Une connexion sécurisée est requise avant de poursuivre la récupération de la destination.",
        "Destination rewrite verification has not completed yet. Retry the verification before finishing migration.": "La vérification des réécritures de la destination n’est pas encore terminée. Relancez-la avant de finaliser la migration.",
        "Migration recovery authentication is incomplete.": "L’authentification de récupération de migration est incomplète.",
        "Core Blueprint could not finalize migration recovery.": "Core Blueprint n’a pas pu finaliser la récupération de migration.",
        "Cross-site migration requires a Core Blueprint Base version with Migration Recovery support.": "La migration entre sites nécessite une version de Core Blueprint Base prenant en charge Migration Recovery.",
        "Migration applied, awaiting destination recovery": "Migration appliquée, récupération de la destination en attente",
    },
    "es_ES": {
        "Core Blueprint Backups": "Core Blueprint Backups",
        "Plugins": "Plugins",
        "https://coreblueprint.io": "https://coreblueprint.io",
        DESCRIPTION: "Copias de seguridad gestionadas de la base de datos y del sitio completo para Core Blueprint, con restauración/migración local, programación, CLI y orquestación remota opcional mediante Beacon.",
        "Core Blueprint Backups:": "Core Blueprint Backups:",
        "none": "ninguno",
        "Continue to secure sign-in": "Continuar al inicio de sesión seguro",
        "Verifying destination access and rewrite routing…": "Verificando el acceso al destino y el enrutamiento de reescrituras…",
        "Destination rewrite verification did not reach WordPress. You remain signed in safely; check the destination permalink or web-server rewrite configuration and retry.": "La verificación de reescrituras del destino no llegó a WordPress. Sigues conectado de forma segura; revisa la configuración de enlaces permanentes o de reescrituras del servidor web de destino y vuelve a intentarlo.",
        "Destination recovery could not be finalized. Retry verification before completing the migration.": "No se pudo finalizar la recuperación del destino. Repite la verificación antes de completar la migración.",
        "Reconciling destination…": "Reconciliando el destino…",
        "Secure sign-in required": "Se requiere inicio de sesión seguro",
        "Secure sign-in is required before destination recovery can continue.": "Se requiere un inicio de sesión seguro antes de continuar con la recuperación del destino.",
        "Destination rewrite verification has not completed yet. Retry the verification before finishing migration.": "La verificación de reescrituras del destino aún no ha finalizado. Repítela antes de terminar la migración.",
        "Migration recovery authentication is incomplete.": "La autenticación de recuperación de migración está incompleta.",
        "Core Blueprint could not finalize migration recovery.": "Core Blueprint no pudo finalizar la recuperación de migración.",
        "Cross-site migration requires a Core Blueprint Base version with Migration Recovery support.": "La migración entre sitios requiere una versión de Core Blueprint Base compatible con Migration Recovery.",
        "Migration applied, awaiting destination recovery": "Migración aplicada, esperando la recuperación del destino",
    },
    "it_IT": {
        "Core Blueprint Backups": "Core Blueprint Backups",
        "Plugins": "Plugin",
        "https://coreblueprint.io": "https://coreblueprint.io",
        DESCRIPTION: "Backup gestiti del database e dell’intero sito per Core Blueprint, con ripristino/migrazione locale, pianificazione, CLI e orchestrazione remota opzionale tramite Beacon.",
        "Core Blueprint Backups:": "Core Blueprint Backups:",
        "none": "nessuno",
        "Continue to secure sign-in": "Continua con l’accesso sicuro",
        "Verifying destination access and rewrite routing…": "Verifica dell’accesso alla destinazione e del routing delle riscritture…",
        "Destination rewrite verification did not reach WordPress. You remain signed in safely; check the destination permalink or web-server rewrite configuration and retry.": "La verifica delle riscritture della destinazione non ha raggiunto WordPress. Resti connesso in modo sicuro; controlla la configurazione dei permalink o delle riscritture del server web di destinazione e riprova.",
        "Destination recovery could not be finalized. Retry verification before completing the migration.": "Non è stato possibile finalizzare il ripristino della destinazione. Ripeti la verifica prima di completare la migrazione.",
        "Reconciling destination…": "Riconciliazione della destinazione…",
        "Secure sign-in required": "Accesso sicuro richiesto",
        "Secure sign-in is required before destination recovery can continue.": "È richiesto un accesso sicuro prima di poter continuare il ripristino della destinazione.",
        "Destination rewrite verification has not completed yet. Retry the verification before finishing migration.": "La verifica delle riscritture della destinazione non è ancora completata. Ripetila prima di terminare la migrazione.",
        "Migration recovery authentication is incomplete.": "L’autenticazione per il ripristino della migrazione è incompleta.",
        "Core Blueprint could not finalize migration recovery.": "Core Blueprint non ha potuto finalizzare il ripristino della migrazione.",
        "Cross-site migration requires a Core Blueprint Base version with Migration Recovery support.": "La migrazione tra siti richiede una versione di Core Blueprint Base con supporto per Migration Recovery.",
        "Migration applied, awaiting destination recovery": "Migrazione applicata, in attesa del ripristino della destinazione",
    },
    "pt_PT": {
        "Core Blueprint Backups": "Core Blueprint Backups",
        "Plugins": "Plugins",
        "https://coreblueprint.io": "https://coreblueprint.io",
        DESCRIPTION: "Cópias de segurança geridas da base de dados e do site completo para o Core Blueprint, com restauro/migração local, agendamento, CLI e orquestração remota opcional através do Beacon.",
        "Core Blueprint Backups:": "Core Blueprint Backups:",
        "none": "nenhum",
        "Continue to secure sign-in": "Continuar para o início de sessão seguro",
        "Verifying destination access and rewrite routing…": "A verificar o acesso ao destino e o encaminhamento de reescritas…",
        "Destination rewrite verification did not reach WordPress. You remain signed in safely; check the destination permalink or web-server rewrite configuration and retry.": "A verificação das reescritas do destino não chegou ao WordPress. Continua com sessão iniciada em segurança; verifique a configuração das ligações permanentes ou das reescritas do servidor web de destino e tente novamente.",
        "Destination recovery could not be finalized. Retry verification before completing the migration.": "Não foi possível finalizar a recuperação do destino. Repita a verificação antes de concluir a migração.",
        "Reconciling destination…": "A reconciliar o destino…",
        "Secure sign-in required": "É necessário um início de sessão seguro",
        "Secure sign-in is required before destination recovery can continue.": "É necessário um início de sessão seguro antes de continuar a recuperação do destino.",
        "Destination rewrite verification has not completed yet. Retry the verification before finishing migration.": "A verificação das reescritas do destino ainda não terminou. Repita-a antes de finalizar a migração.",
        "Migration recovery authentication is incomplete.": "A autenticação da recuperação da migração está incompleta.",
        "Core Blueprint could not finalize migration recovery.": "O Core Blueprint não conseguiu finalizar a recuperação da migração.",
        "Cross-site migration requires a Core Blueprint Base version with Migration Recovery support.": "A migração entre sites requer uma versão do Core Blueprint Base com suporte para Migration Recovery.",
        "Migration applied, awaiting destination recovery": "Migração aplicada, a aguardar a recuperação do destino",
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

    repaired = 0
    for msgid, translation in TRANSLATIONS[locale].items():
        empty = block(msgid, "")
        filled = block(msgid, translation)
        if empty in text and empty != filled:
            text = text.replace(empty, filled, 1)
            repaired += 1

    if additions:
        text = text.rstrip() + "\n\n" + "\n\n".join(additions) + "\n"

    if additions or repaired:
        po.write_text(text, encoding="utf-8")
        print(
            f"{locale}: seeded {len(additions)} reviewed product translations"
            f"; repaired {repaired} empty translations"
        )
    else:
        print(f"{locale}: reviewed product translations already present")
