"""Load scraper source configs from sources.yaml.

Kept separate from `generic.py` so the pure scraper class has no file-system
dependencies — useful for unit testing.
"""
from __future__ import annotations

import logging
from pathlib import Path

import yaml

from config import ROOT_DIR

log = logging.getLogger("sources")

SOURCES_FILE = ROOT_DIR / "sources.yaml"


def load_sources(
    cadence: str | None = None,
    only_enabled: bool = True,
) -> list[dict]:
    """Return the list of source configs.

    cadence       — optional filter: "fast" | "slow"
    only_enabled  — if True (default) skip entries with enabled: false
    """
    if not SOURCES_FILE.exists():
        log.warning("sources.yaml not found at %s", SOURCES_FILE)
        return []
    try:
        data = yaml.safe_load(SOURCES_FILE.read_text(encoding="utf-8")) or []
    except yaml.YAMLError as exc:
        log.error("sources.yaml invalid: %s", exc)
        return []
    if not isinstance(data, list):
        log.error("sources.yaml must be a YAML list at the top level")
        return []

    out: list[dict] = []
    seen_ids: set[str] = set()
    for entry in data:
        if not isinstance(entry, dict) or "id" not in entry:
            continue
        if only_enabled and not entry.get("enabled", True):
            continue
        if cadence and entry.get("cadence") != cadence:
            continue
        if entry["id"] in seen_ids:
            log.warning("Duplicate source id '%s' — skipping", entry["id"])
            continue
        seen_ids.add(entry["id"])
        out.append(entry)
    return out


def get_source(source_id: str) -> dict | None:
    for entry in load_sources(only_enabled=False):
        if entry["id"] == source_id:
            return entry
    return None
