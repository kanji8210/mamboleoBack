"""Diagnose WordPress REST API reachability.

Usage:  python ping_api.py

Prints the full response for:
  1. GET  /wp-json/                            (is WP REST alive?)
  2. GET  /wp-json/mamboleo/v1/                (is our plugin loaded?)
  3. POST /wp-json/mamboleo/v1/articles        (tiny valid payload)
  4. POST /wp-json/mamboleo/v1/incidents       (tiny valid payload)

Anything other than JSON in the response body == a proxy/WAF is intercepting.
"""
from __future__ import annotations

import json

import requests

from config import WP_API_BASE, WP_API_KEY, USER_AGENT

HEADERS = {
    "X-API-Key":    WP_API_KEY,
    "Content-Type": "application/json",
    "Accept":       "application/json",
    "User-Agent":   USER_AGENT,
}


def show(label: str, resp: requests.Response) -> None:
    print(f"\n── {label} ──")
    print(f"  URL         : {resp.request.url}")
    print(f"  Status      : {resp.status_code}")
    print(f"  Server      : {resp.headers.get('Server', '?')}")
    print(f"  Content-Type: {resp.headers.get('Content-Type', '?')}")
    print(f"  X-Powered-By: {resp.headers.get('X-Powered-By', '?')}")
    body = resp.text[:400].replace("\n", " ")
    print(f"  Body[0:400] : {body}")


def main() -> None:
    print(f"Target: {WP_API_BASE}")
    print(f"API key: {'(set)' if WP_API_KEY else '(MISSING)'}")

    # 1. WP REST root
    r = requests.get(f"{WP_API_BASE}/wp-json/", headers=HEADERS, timeout=15)
    show("GET /wp-json/", r)

    # 2. Plugin namespace
    r = requests.get(f"{WP_API_BASE}/wp-json/mamboleo/v1/", headers=HEADERS, timeout=15)
    show("GET /wp-json/mamboleo/v1/", r)

    # 3. Article POST
    article = {
        "title": "Ping test",
        "source": "Diagnostic",
        "article_url": "https://example.com/ping",
    }
    r = requests.post(
        f"{WP_API_BASE}/wp-json/mamboleo/v1/articles",
        data=json.dumps(article),
        headers=HEADERS,
        timeout=15,
    )
    show("POST /wp-json/mamboleo/v1/articles", r)

    # 4. Incident POST
    incident = {
        "title": "Ping test",
        "type": "other",
        "latitude": "-1.2921",
        "longitude": "36.8219",
    }
    r = requests.post(
        f"{WP_API_BASE}/wp-json/mamboleo/v1/incidents",
        data=json.dumps(incident),
        headers=HEADERS,
        timeout=15,
    )
    show("POST /wp-json/mamboleo/v1/incidents", r)


if __name__ == "__main__":
    main()
