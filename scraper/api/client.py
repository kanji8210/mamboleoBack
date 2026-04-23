"""POST scraped data to the Mamboleo WordPress REST API.

Surfaces richer diagnostics when a WAF / proxy / host blocks the request
(typical symptom: HTML error page returned instead of JSON).
"""
from __future__ import annotations

import json
import logging
import re

import requests

from config import WP_API_BASE, WP_API_KEY, USER_AGENT

log = logging.getLogger("api.client")

# Use a friendlier browser-ish UA + explicit Accept → some shared-hosting
# WAFs (GoDaddy, cPanel Imunify) block requests that look like bots.
_HEADERS = {
    "X-API-Key":    WP_API_KEY,
    "Content-Type": "application/json",
    "Accept":       "application/json",
    "User-Agent":   USER_AGENT,
}

_ARTICLES_URL  = f"{WP_API_BASE}/wp-json/mamboleo/v1/articles"
_INCIDENTS_URL = f"{WP_API_BASE}/wp-json/mamboleo/v1/incidents"

# Max payload field sizes — keeps the POST body under typical WAF limits
# (ModSecurity default SecRequestBodyLimit ≈ 128 KB, some hosts set 32 KB).
_MAX_CONTENT_LEN = 4000   # Article body text
_MAX_TITLE_LEN   = 200


def _summarise_error_body(text: str) -> str:
    """Turn an HTML error page into a one-line diagnostic hint."""
    if not text:
        return "(empty body)"
    snippet = re.sub(r"\s+", " ", text).strip()[:300]
    low = snippet.lower()
    # Fingerprint common block pages so the user immediately knows who to call.
    if "cloudflare" in low:
        return f"[Cloudflare blocked] {snippet}"
    if "mod_security" in low or "modsecurity" in low or "not acceptable" in low:
        return f"[ModSecurity/WAF blocked] {snippet}"
    if "imunify" in low:
        return f"[Imunify360 blocked] {snippet}"
    if "page cannot be displayed" in low or "service provider" in low:
        return f"[Host firewall / ISP proxy blocked] {snippet}"
    if "<html" in low or "<!doctype" in low:
        return f"[HTML error page] {snippet}"
    return snippet


def _trim_payload(data: dict) -> dict:
    """Trim oversized fields that commonly trigger WAF rules."""
    out = dict(data)
    if "title" in out and isinstance(out["title"], str):
        out["title"] = out["title"][:_MAX_TITLE_LEN]
    for key in ("content", "excerpt"):
        if key in out and isinstance(out[key], str):
            out[key] = out[key][:_MAX_CONTENT_LEN]
    return out


def _extract_json(text: str):
    """Best-effort JSON extractor.

    WordPress in development mode often emits PHP notices / deprecation
    warnings before the JSON body, which breaks strict parsing. We try strict
    first, then fall back to locating the last `{...}` or `[...]` block in the
    response text.
    """
    if not text:
        return None
    try:
        return json.loads(text)
    except ValueError:
        pass
    # Find last top-level JSON object or array in the body.
    for open_c, close_c in (("{", "}"), ("[", "]")):
        end = text.rfind(close_c)
        if end == -1:
            continue
        start = text.rfind(open_c, 0, end)
        while start != -1:
            try:
                return json.loads(text[start:end + 1])
            except ValueError:
                start = text.rfind(open_c, 0, start)
    return None


def _post(url: str, data: dict, what: str) -> int | None:
    """Shared POST helper with rich diagnostics."""
    payload = _trim_payload(data)
    try:
        resp = requests.post(url, json=payload, headers=_HEADERS, timeout=15)
    except requests.exceptions.Timeout:
        log.error("%s POST timed out (>15s) → %s", what, url)
        return None
    except requests.exceptions.ConnectionError as exc:
        log.error("%s POST connection error → %s : %s", what, url, exc)
        return None
    except requests.exceptions.RequestException as exc:
        log.error("%s POST request failed: %s", what, exc)
        return None

    status = resp.status_code
    ctype  = resp.headers.get("Content-Type", "")
    server = resp.headers.get("Server", "?")

    # Happy path — valid JSON response from WordPress.
    # We do NOT rely on resp.json() alone: some WordPress hosts emit
    # PHP notices/warnings before the JSON body (e.g. "Deprecated: ..."),
    # which breaks the strict parser. Instead we fish the last JSON object
    # out of the body — this is robust to leading warnings or trailing newlines.
    if status < 400:
        body = _extract_json(resp.text)
        if isinstance(body, dict) and "id" in body:
            return int(body["id"])
        if isinstance(body, dict):
            # 2xx JSON without an id → non-fatal (e.g. "already exists" without id)
            log.debug("%s POST 2xx no id → %s", what, body)
            return None

    # Anything else → surface status + server + body fingerprint.
    hint = _summarise_error_body(resp.text)
    log.error(
        "%s POST failed → HTTP %s (server=%s, content-type=%s): %s",
        what, status, server, ctype or "none", hint,
    )
    return None


def post_article(data: dict) -> int | None:
    """
    POST an article record. Returns the WordPress post ID, or None on error.

    Required keys: title, source, article_url
    Optional keys: bias_score, sentiment, content, excerpt
    """
    return _post(_ARTICLES_URL, data, "Article")


def post_incident(data: dict) -> int | None:
    """
    POST an incident record. Returns the WordPress post ID, or None on error.

    Required keys: title, type, latitude, longitude
    Optional keys: severity, status, incident_time, location_name,
                   reporter_name, needs_review, review_reason, confidence
    """
    return _post(_INCIDENTS_URL, data, "Incident")
