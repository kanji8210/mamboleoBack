"""Geocode a location name → (lat, lng) via Nominatim (free, no API key).

Respects the Nominatim Usage Policy: max 1 request/second.
Used only when the static locations table has no match.
"""
from __future__ import annotations

import logging
import time

import requests

log = logging.getLogger(__name__)

_NOMINATIM = "https://nominatim.openstreetmap.org/search"
_USER_AGENT = (
    "MamboleoBot/1.0 (+https://github.com/kanji8210/mamboleo; "
    "Kenya security research)"
)
_last_call: float = 0.0


def geocode(location_name: str) -> tuple[float, float] | None:
    """Return (lat, lng) for a Kenya location, or None on failure."""
    global _last_call
    # Enforce ≥ 1.1 s between calls as required by Nominatim policy
    wait = 1.1 - (time.time() - _last_call)
    if wait > 0:
        time.sleep(wait)

    try:
        resp = requests.get(
            _NOMINATIM,
            params={
                "q":            f"{location_name}, Kenya",
                "format":       "json",
                "limit":        1,
                "countrycodes": "ke",
            },
            headers={"User-Agent": _USER_AGENT},
            timeout=8,
        )
        resp.raise_for_status()
        _last_call = time.time()
        results = resp.json()
        if results:
            return float(results[0]["lat"]), float(results[0]["lon"])
    except Exception as exc:
        log.warning("Nominatim error for %r: %s", location_name, exc)

    return None
