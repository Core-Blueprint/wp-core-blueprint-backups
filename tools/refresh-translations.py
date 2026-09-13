#!/usr/bin/env python3
"""Compatibility entrypoint. Canonical workflow: tools/i18n/update.

The former live machine-translation refresh is intentionally retired. Reviewed
PO files remain translation authority and are synchronized from current source.
"""
from pathlib import Path
import os
import sys

TARGET = Path(__file__).resolve().parent / "i18n" / "update"
os.execv(str(TARGET), [str(TARGET), *sys.argv[1:]])
