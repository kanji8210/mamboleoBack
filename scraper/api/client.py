"""POST scraped data to the Mamboleo WordPress REST API.

Surfaces richer diagnostics when a WAF / proxy / host blocks the request
(typical symptom: HTML error page returned instead of JSON).

Uses stdlib `urllib` for the actual HTTP transport because Cloudflare
fingerprints urllib3's TLS/cipher order and returns 403 even when headers
look browser-like. Stdlib urllib uses Python's default SSL context which
gets through. Same trick as `config._fetch_remote_llm_config()`.
"""
from __future__ import annotations

import json
import logging
import re
import socket
import ssl
import urllib.error
import urllib.request

from config import WP_API_BASE, WP_API_KEY, USER_AGENT

log = logging.getLogger("api.client")

# Use a friendlier browser-ish UA + explicit Accept → some shared-hosting
# WAFs (GoDaddy, cPanel Imunify) block requests that look like bots.
_HEADERS = {
    "X-API-Key":       WP_API_KEY,
    "Content-Type":    "application/json",
    "Accept":          "application/json",
    "Accept-Language": "en-US,en;q=0.9",
    "User-Agent":      USER_AGENT,
}

_ARTICLES_URL  = f"{WP_API_BASE}/wp-json/mamboleo/v1/articles"
_INCIDENTS_URL = f"{WP_API_BASE}/wp-json/mamboleo/v1/incidents"

# Default SSL context — Cloudflare's bot manager flags urllib3's specific
# cipher ordering. Python's stdlib context uses the OpenSSL default which
# matches what curl/browsers send and gets through.
_SSL_CTX = ssl.create_default_context()
_TIMEOUT = 8.0  # seconds

# ── WP-side circuit breaker ───────────────────────────────────────────────
# When Cloudflare/WAF blocks POSTs we used to wait the full timeout for
# every single article (4 workers × 30 articles × 15s ≈ 30 min hang).
# After this many consecutive failures we stop hitting the endpoint and
# return None immediately. The flag resets on the next process start.
import threading
_FAIL_LIMIT = 3
_fail_lock  = threading.Lock()
_fail_count = 0
_tripped    = False


def _trip_breaker(reason: str) -> None:
    global _tripped
    with _fail_lock:
        if not _tripped:
            _tripped = True
            log.error(
                "[circuit-breaker] WP API unreachable after %d failures (%s). "
                "Suppressing further POSTs for this run; locally-classified "
                "articles will not be persisted.",
                _FAIL_LIMIT, reason,
            )


def _record_failure(reason: str) -> None:
    global _fail_count
    with _fail_lock:
        _fail_count += 1
        if _fail_count >= _FAIL_LIMIT:
            _trip_breaker(reason)


def _record_success() -> None:
    global _fail_count
    with _fail_lock:
        _fail_count = 0

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
    """Shared POST helper with rich diagnostics.

    Uses stdlib urllib (see module docstring) so Cloudflare doesn't 403 on
    urllib3's TLS fingerprint. Hard 8s timeout — one slow WAF must never
    stall a worker thread for minutes.
    """
    if _tripped:
        return None
    payload = _trim_payload(data)
    body_bytes = json.dumps(payload).encode("utf-8")

    req = urllib.request.Request(
        url,
        data=body_bytes,
        headers=_HEADERS,
        method="POST",
    )

    try:
        with urllib.request.urlopen(req, timeout=_TIMEOUT, context=_SSL_CTX) as resp:
            status = resp.status
            headers = resp.headers
            text = resp.read().decode("utf-8", errors="replace")
    except urllib.error.HTTPError as he:
        status = he.code
        headers = he.headers if he.headers is not None else {}
        try:
            text = he.read().decode("utf-8", errors="replace")
        except Exception:
            text = ""
    except socket.timeout:
        log.error("%s POST timed out (>%.0fs) → %s", what, _TIMEOUT, url)
        _record_failure("timeout")
        return None
    except urllib.error.URLError as exc:
        reason = getattr(exc, "reason", exc)
        if isinstance(reason, socket.timeout):
            log.error("%s POST timed out (>%.0fs) → %s", what, _TIMEOUT, url)
            _record_failure("timeout")
        else:
            log.error("%s POST connection error → %s : %s", what, url, reason)
            _record_failure("connection")
        return None
    except Exception as exc:  # noqa: BLE001
        log.error("%s POST request failed: %s", what, exc)
        _record_failure(type(exc).__name__)
        return None

    ctype  = headers.get("Content-Type", "") if headers else ""
    server = headers.get("Server", "?") if headers else "?"

    # Happy path — valid JSON response from WordPress.
    # We do NOT rely on strict json.loads alone: some WordPress hosts emit
    # PHP notices/warnings before the JSON body (e.g. "Deprecated: ..."),
    # which breaks the strict parser. _extract_json fishes the last JSON
    # object out of the body, robust to leading warnings or trailing newlines.
    if status < 400:
        _record_success()
        body = _extract_json(text)
        if isinstance(body, dict) and "id" in body:
            return int(body["id"])
        if isinstance(body, dict):
            # 2xx JSON without an id → non-fatal (e.g. "already exists" without id)
            log.debug("%s POST 2xx no id → %s", what, body)
            return None

    # Anything else → surface status + server + body fingerprint.
    hint = _summarise_error_body(text)
    log.error(
        "%s POST failed → HTTP %s (server=%s, content-type=%s): %s",
        what, status, server, ctype or "none", hint,
    )
    _record_failure(f"HTTP {status}")
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
