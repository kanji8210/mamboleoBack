"""Per-source health tracker — auto-disables consistently-failing scrapers.

Goal: when a source returns zero articles for several consecutive runs
(403 wall, dead URL, paywall), stop wasting a worker thread on it for a
cooldown window. After the window the source is retried automatically.

State lives in ``scraper/data/source_health.json`` and is keyed by the
scraper label (e.g. ``"feed:france24_africa"``, ``"international"``).

Public API
----------
    health.should_run(label)            -> bool
    health.record(label, articles_seen) -> None
    health.snapshot()                   -> dict   # for diagnostics
"""
from __future__ import annotations

import json
import logging
import threading
from datetime import datetime, timedelta, timezone
from pathlib import Path

from config import DATA_DIR

log = logging.getLogger("health")

# Tunables. Conservative on purpose — a source that yields 0 articles
# legitimately (slow news day) shouldn't get blacklisted.
ZERO_RUNS_BEFORE_DISABLE = 5            # consecutive zero-yield runs
COOLDOWN_HOURS           = 24 * 7       # disable for 7 days, then retry

_PATH  = Path(DATA_DIR) / "source_health.json"
_LOCK  = threading.Lock()
_STATE: dict[str, dict] | None = None


def _now_iso() -> str:
    return datetime.now(timezone.utc).isoformat()


def _load() -> dict[str, dict]:
    global _STATE
    if _STATE is not None:
        return _STATE
    try:
        if _PATH.exists():
            _STATE = json.loads(_PATH.read_text() or "{}")
        else:
            _STATE = {}
    except Exception as exc:  # noqa: BLE001 — never crash the scraper over telemetry
        log.warning("health.json unreadable (%s); starting fresh", exc)
        _STATE = {}
    return _STATE


def _save() -> None:
    try:
        _PATH.write_text(json.dumps(_STATE, indent=2, sort_keys=True))
    except Exception as exc:  # noqa: BLE001
        log.warning("Could not persist health.json: %s", exc)


def should_run(label: str) -> bool:
    """Return False if this label is in cooldown, True otherwise."""
    with _LOCK:
        entry = _load().get(label)
        if not entry:
            return True
        until = entry.get("disabled_until")
        if not until:
            return True
        try:
            until_dt = datetime.fromisoformat(until)
        except ValueError:
            return True
        if datetime.now(timezone.utc) < until_dt:
            return False
        # Cooldown expired — clear it so the next failure starts fresh.
        entry["disabled_until"] = None
        entry["zero_runs"] = 0
        _save()
        return True


def record(label: str, articles_seen: int) -> None:
    """Update the counter for one scraper run."""
    with _LOCK:
        state = _load()
        entry = state.setdefault(label, {"zero_runs": 0, "disabled_until": None})
        entry["last_run"] = _now_iso()

        if articles_seen > 0:
            # Healthy run — reset the streak.
            if entry["zero_runs"] or entry.get("disabled_until"):
                log.info("[health] %s recovered (%d articles)", label, articles_seen)
            entry["zero_runs"] = 0
            entry["disabled_until"] = None
            entry["last_articles"] = articles_seen
        else:
            entry["zero_runs"] = int(entry.get("zero_runs", 0)) + 1
            if entry["zero_runs"] >= ZERO_RUNS_BEFORE_DISABLE:
                until = datetime.now(timezone.utc) + timedelta(hours=COOLDOWN_HOURS)
                entry["disabled_until"] = until.isoformat()
                log.warning(
                    "[health] %s disabled for %dh after %d consecutive zero-yield runs",
                    label, COOLDOWN_HOURS, entry["zero_runs"],
                )
        _save()


def snapshot() -> dict[str, dict]:
    """Return a copy of the current state — useful for the admin UI."""
    with _LOCK:
        return dict(_load())
