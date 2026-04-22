"""POST scraped data to the Mamboleo WordPress REST API."""
from __future__ import annotations

import logging

import requests

from config import WP_API_BASE, WP_API_KEY

log = logging.getLogger("api.client")

_HEADERS = {
    "X-API-Key":    WP_API_KEY,
    "Content-Type": "application/json",
}

_ARTICLES_URL  = f"{WP_API_BASE}/wp-json/mamboleo/v1/articles"
_INCIDENTS_URL = f"{WP_API_BASE}/wp-json/mamboleo/v1/incidents"


def post_article(data: dict) -> int | None:
    """
    POST an article record. Returns the WordPress post ID, or None on error.

    Required keys: title, source, article_url
    Optional keys: bias_score (int 0-100), sentiment ('positive'|'neutral'|'negative')
    """
    try:
        resp = requests.post(_ARTICLES_URL, json=data, headers=_HEADERS, timeout=10)
        resp.raise_for_status()
        result = resp.json()
        return result.get("id")
    except requests.exceptions.HTTPError as exc:
        log.error("Article POST failed (%s): %s", exc.response.status_code, exc.response.text[:200])
    except Exception as exc:
        log.error("Article POST error: %s", exc)
    return None


def post_incident(data: dict) -> int | None:
    """
    POST an incident record. Returns the WordPress post ID, or None on error.

    Required keys: title, type, latitude (str/float), longitude (str/float)
    Optional keys: severity, status, incident_time, location_name, reporter_name
    """
    try:
        resp = requests.post(_INCIDENTS_URL, json=data, headers=_HEADERS, timeout=10)
        resp.raise_for_status()
        result = resp.json()
        return result.get("id")
    except requests.exceptions.HTTPError as exc:
        log.error("Incident POST failed (%s): %s", exc.response.status_code, exc.response.text[:200])
    except Exception as exc:
        log.error("Incident POST error: %s", exc)
    return None
