"""Backfill AI intelligence metadata onto existing incidents.

Walks the WordPress REST endpoint `/mamboleo/v1/incidents/needs-ai`, runs each
incident's title + body through the local Ollama-based intelligence layer,
and patches the resulting summary / severity reasoning / flags / model name
back via `/mamboleo/v1/incidents/{id}/ai`.

USAGE
    python backfill_ai.py                  # process all incidents missing ai_model
    python backfill_ai.py --limit 20       # cap how many to process this run
    python backfill_ai.py --batch 25       # tune REST page size
    python backfill_ai.py --dry-run        # analyse + log, do not PATCH
    python backfill_ai.py --update-severity  # also overwrite severity if model differs
    python backfill_ai.py --update-type      # also overwrite type if model differs

The script is idempotent: incidents that already have `ai_model` set are
filtered out by the REST endpoint, so re-running picks up where it left off.

EXIT CODES
    0 — finished cleanly
    1 — Ollama unreachable (refuses to run a backfill on the keyword fallback)
    2 — REST listing endpoint failed
"""
from __future__ import annotations

import argparse
import logging
import sys
import time

import requests

from api import llm_client
from config import WP_API_BASE, WP_API_KEY, USER_AGENT
from processors import intelligence

logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s  %(levelname)-8s  %(message)s",
    datefmt="%H:%M:%S",
)
log = logging.getLogger("backfill")

_LIST_URL  = f"{WP_API_BASE}/wp-json/mamboleo/v1/incidents/needs-ai"
_PATCH_URL = f"{WP_API_BASE}/wp-json/mamboleo/v1/incidents/{{id}}/ai"
_HEADERS = {
    "X-API-Key":    WP_API_KEY,
    "Content-Type": "application/json",
    "Accept":       "application/json",
    "User-Agent":   USER_AGENT,
}


def _fetch_batch(limit: int, offset: int) -> dict:
    resp = requests.get(
        _LIST_URL,
        headers=_HEADERS,
        params={"limit": limit, "offset": offset},
        timeout=30,
    )
    resp.raise_for_status()
    return resp.json()


def _patch_one(incident_id: int, payload: dict) -> bool:
    url = _PATCH_URL.format(id=incident_id)
    try:
        resp = requests.post(url, json=payload, headers=_HEADERS, timeout=20)
    except requests.exceptions.RequestException as exc:
        log.warning("  ! PATCH failed (transport): %s", exc)
        return False
    if resp.status_code != 200:
        log.warning("  ! PATCH failed HTTP %s: %s", resp.status_code, resp.text[:200])
        return False
    return True


def _process_one(item: dict, *, dry_run: bool, update_severity: bool, update_type: bool) -> str:
    """Return one of: 'updated', 'skipped', 'failed', 'fallback'."""
    title   = item.get("title", "") or ""
    body    = item.get("content", "") or ""
    iid     = int(item["id"])

    intel = intelligence.analyze(title, body)

    if intel.used_fallback:
        # Don't pollute the DB with keyword-fallback metadata during a
        # backfill — the whole point is to add the LLM's reasoning.
        log.warning("  ⏭  #%d: LLM fallback (skipping)", iid)
        return "fallback"

    payload = intel.to_payload()
    payload["update_severity"] = update_severity
    payload["update_type"]     = update_type
    if update_severity:
        payload["severity"] = intel.severity
    if update_type:
        payload["type"]     = intel.incident_type

    log.info(
        "  #%d  type=%-12s sev=%-6s conf=%.2f  %s",
        iid, intel.incident_type, intel.severity, intel.confidence, title[:70],
    )

    if dry_run:
        return "skipped"

    return "updated" if _patch_one(iid, payload) else "failed"


def main() -> int:
    p = argparse.ArgumentParser(description="Backfill AI metadata on existing incidents")
    p.add_argument("--limit",  type=int, default=0,
                   help="Stop after processing this many incidents (0 = all)")
    p.add_argument("--batch",  type=int, default=25,
                   help="REST page size when listing candidates")
    p.add_argument("--dry-run", action="store_true",
                   help="Run intelligence.analyze but do not PATCH back")
    p.add_argument("--update-severity", action="store_true",
                   help="Also overwrite severity meta when the model differs")
    p.add_argument("--update-type", action="store_true",
                   help="Also overwrite type meta when the model differs")
    p.add_argument("--sleep", type=float, default=0.0,
                   help="Seconds to wait between incidents (rate-limit Ollama)")
    args = p.parse_args()

    # ── Pre-flight: refuse to run without Ollama (avoid mass keyword-fallback) ──
    if not llm_client.health_check():
        log.error("Ollama is unreachable — aborting backfill. Start `ollama serve` first.")
        return 1

    log.info("Backfill starting against %s", _LIST_URL)
    if args.dry_run:
        log.info("DRY RUN — no PATCH requests will be sent")

    stats = {"updated": 0, "skipped": 0, "failed": 0, "fallback": 0}
    processed = 0
    offset = 0

    while True:
        try:
            page = _fetch_batch(args.batch, offset)
        except requests.exceptions.RequestException as exc:
            log.error("Listing endpoint failed: %s", exc)
            return 2

        items = page.get("items", [])
        total = page.get("total", 0)
        if not items:
            break

        log.info("Page offset=%d  fetched=%d  total_remaining=%d",
                 offset, len(items), total)

        for item in items:
            outcome = _process_one(
                item,
                dry_run        = args.dry_run,
                update_severity= args.update_severity,
                update_type    = args.update_type,
            )
            stats[outcome] += 1
            processed += 1
            if args.limit and processed >= args.limit:
                break
            if args.sleep:
                time.sleep(args.sleep)

        if args.limit and processed >= args.limit:
            break

        # Endpoint filters processed items out, so we don't advance offset —
        # next call returns the next un-AI'd incidents naturally. But if dry
        # run, items remain in the list, so advance offset.
        if args.dry_run:
            offset += len(items)
        if len(items) < args.batch:
            break

    log.info(
        "Done: updated=%d  skipped=%d  failed=%d  fallback=%d  (total processed=%d)",
        stats["updated"], stats["skipped"], stats["failed"], stats["fallback"], processed,
    )
    return 0


if __name__ == "__main__":
    sys.exit(main())
