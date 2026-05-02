"""Geocode a location name → (lat, lng) via Nominatim (free, no API key).

Respects the Nominatim Usage Policy: max 1 request/second.
Used only when the static locations table has no match.

Optimisations:
  • In-process LRU cache so repeated names within a run are free.
  • Negative results are cached too (None) — saves the 1.1s sleep on the
    second occurrence of a place that Nominatim doesn't resolve.
  • Persistent requests.Session reuses the TLS connection across calls.
"""
from __future__ import annotations

import logging
import threading
import time
from functools import lru_cache

import requests

log = logging.getLogger(__name__)

_NOMINATIM = "https://nominatim.openstreetmap.org/search"
_USER_AGENT = (
    "MamboleoBot/1.0 (+https://github.com/kanji8210/mamboleo; "
    "Kenya security research)"
)
_session = requests.Session()
_session.headers.update({"User-Agent": _USER_AGENT})

# Nominatim policy: max 1 req/sec across the whole process. The lock
# serialises concurrent threads so the policy still holds when scrapers
# run in parallel (see main.py ThreadPoolExecutor).
_rate_lock = threading.Lock()
_last_call: float = 0.0


@lru_cache(maxsize=2048)
def geocode(location_name: str) -> tuple[float, float] | None:
    """Return (lat, lng) for a Kenya location, or None on failure.

    Memoised — same string in a single run hits Nominatim at most once.
    """
    global _last_call
    if not location_name or not location_name.strip():
        return None

    # Enforce ≥ 1.1 s between calls as required by Nominatim policy.
    # Lock held across the sleep + the actual GET so only one request
    # is in-flight at a time, even from concurrent scraper threads.
    with _rate_lock:
        wait = 1.1 - (time.time() - _last_call)
        if wait > 0:
            time.sleep(wait)
        try:
            resp = _session.get(
                _NOMINATIM,
                params={
                    "q":            f"{location_name}, Kenya",
                    "format":       "json",
                    "limit":        1,
                    "countrycodes": "ke",
                },
                timeout=8,
            )
            _last_call = time.time()
            resp.raise_for_status()
            results = resp.json()
            if results:
                return float(results[0]["lat"]), float(results[0]["lon"])
        except Exception as exc:
            log.warning("Nominatim error for %r: %s", location_name, exc)

    return None
