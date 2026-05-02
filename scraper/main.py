"""Mamboleo scraper — main entry point.

Usage:
    python main.py                     # run all scrapers, 30 articles max each
    python main.py --sources tuko      # run only Tuko
    python main.py --sources tuko nation standard
    python main.py --limit 10          # fetch up to 10 articles per source
    python main.py --dry-run           # classify + locate but don't POST to WP
"""
from __future__ import annotations

import argparse
import logging
import re
import sys
from concurrent.futures import ThreadPoolExecutor, as_completed
from datetime import datetime, timezone

import db
import health
from api import client as api
from config import DB_PATH, MAX_ARTICLES
from processors import analyze, classify as legacy, geocoder, intelligence, locations
from scrapers.advisories import AdvisoryScraper
from scrapers.generic import GenericScraper
from scrapers.googlenews import GoogleNewsScraper
from scrapers.nation import NationScraper
from scrapers.social import SocialHandlesScraper
from scrapers.sources_loader import load_sources
from scrapers.standard import StandardScraper
from scrapers.tuko import TukoScraper
from scrapers.twitter import TwitterScraper

# ── Logging ───────────────────────────────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s  %(levelname)-8s  %(name)s  %(message)s",
    datefmt="%H:%M:%S",
)
# Silence urllib3's transport-level retry warnings — they fire on slow
# government sites (Smartraveller, MOFA) and add noise. Our own get()
# wrapper already logs a single, actionable warning per failed URL.
logging.getLogger("urllib3.connectionpool").setLevel(logging.ERROR)

log = logging.getLogger("main")

# ── Review thresholds ─────────────────────────────────────────────────────────
# Below MIN: discard (not an incident).
# Between MIN and REVIEW: auto-park for admin review.
# At or above REVIEW: auto-publish (unless location is low-specificity fallback).
#
# Tuned to the new classify.py scoring (topic*0.25 + verb*0.5, capped 1.0):
#   0.30  = one event verb + one topic hit  → review
#   0.50  = one verb + two topic hits, OR two verbs → strong
#   0.60+ = multi-verb / multi-keyword match → auto-publish
CONFIDENCE_MIN     = 0.30
CONFIDENCE_REVIEW  = 0.60

# ── Scraper registry ──────────────────────────────────────────────────────────
ALL_SCRAPERS = {
    "advisories":    AdvisoryScraper,
    "googlenews":    GoogleNewsScraper,
    "tuko":          TukoScraper,
    "nation":        NationScraper,
    "standard":      StandardScraper,
    "twitter":       TwitterScraper,
    "social":        SocialHandlesScraper,
}



# ── Pipeline ──────────────────────────────────────────────────────────────────

def process_article(raw: dict, dry_run: bool) -> bool:
    """
    Full pipeline for one article:
      1. Deduplicate via SQLite
      2. NLP enrich (sentiment / bias / entities / topics / keywords)
      3. POST enriched article to WordPress (always — this feeds the Media Monitor)
      4. Classify as incident — if confident, locate + POST incident too
    Returns True if an incident was created.
    """
    url = raw["url"]

    if db.is_seen(url, DB_PATH):
        log.debug("Skip (seen): %s", url)
        return False

    # ── Step 2: article-level NLP (runs on EVERYTHING) ────────────────────────
    nlp = analyze.analyze(
        title=raw["title"],
        content=raw.get("content", "") or raw.get("excerpt", ""),
        bias_baseline=int(raw.get("bias_baseline", 0)),
    )

    # ── Step 3: POST enriched article ─────────────────────────────────────────
    if not dry_run:
        article_payload = {
            "title":        raw["title"],
            "source":       raw["source"],
            "article_url":  url,
            "excerpt":      raw.get("excerpt", ""),
            "content":      raw.get("content", ""),
            "published_at": raw.get("published_at", datetime.now(timezone.utc).isoformat()),
            "tier":         raw.get("tier", 3),
            **nlp.to_payload(),
        }
        article_id = api.post_article(article_payload)
        if article_id:
            log.info("  ✓ article #%d [%s] %s", article_id, ",".join(nlp.topics) or "-", raw["title"][:80])

    # ── Step 4: is this also an incident? ─────────────────────────────────────
    text = raw["title"] + " " + raw.get("excerpt", "") + " " + raw.get("content", "")

    # Fast keyword pre-filter: if the legacy classifier finds no incident
    # signal at all (no event verbs + no incident topic keywords), skip the
    # expensive LLM call entirely. Behaviour preserved: we still mark the
    # URL seen and POST the article record above — only the per-article
    # 5–45 s LLM round-trip is short-circuited for obvious non-incidents.
    body_for_check = raw.get("content", "") or raw.get("excerpt", "")
    if legacy.classify(raw["title"], body_for_check) is None:
        if not dry_run:
            db.mark_seen(url, DB_PATH)
        return False

    intel = intelligence.analyze(raw["title"], body_for_check)

    if not intel.is_incident or intel.confidence < CONFIDENCE_MIN:
        log.debug(
            "Not an incident (model=%s conf=%.2f): %s",
            intel.model, intel.confidence, raw["title"][:80],
        )
        if not dry_run:
            db.mark_seen(url, DB_PATH)
        return False

    # ── Location ──────────────────────────────────────────────────────────────
    # Prefer the LLM's narrative location hint when the regex gazetteer
    # comes up short — handles "junction of X and Y road" style descriptions.
    loc = locations.best_location(text)
    location_fallback = False

    if (loc is None or loc.name == "Kenya") and intel.location_hint:
        hint_loc = locations.best_location(intel.location_hint)
        if hint_loc is not None and hint_loc.name != "Kenya":
            loc = hint_loc

    if loc is None or loc.name == "Kenya":
        # No specific place matched — try Nominatim on the LLM hint first,
        # then on capitalised words from the title. Dedupe so we don't
        # hit Nominatim twice for the same word (each call costs ~1.1s).
        seen_words: set[str] = set()
        candidates: list[str] = []
        if intel.location_hint:
            candidates.append(intel.location_hint)
        candidates.extend(re.findall(r"[A-Z][a-z]{3,}", raw["title"]))
        for word in candidates:
            key = word.strip().lower()
            if not key or key in seen_words:
                continue
            seen_words.add(key)
            coords = geocoder.geocode(word)
            if coords:
                from processors.locations import Location
                loc = Location(name=word, lat=coords[0], lng=coords[1], specificity=1)
                location_fallback = True
                break

    if loc is None:
        # Last-resort: country-level centre. Item goes on the map roughly
        # over Kenya and is flagged for manual review.
        loc = locations.KENYA_FALLBACK
        location_fallback = True

    # ── Decide whether this needs human review ────────────────────────────────
    review_reasons: list[str] = []
    if intel.confidence < CONFIDENCE_REVIEW:
        review_reasons.append(f"low classification confidence ({intel.confidence:.2f})")
    if location_fallback or loc.specificity <= 1:
        review_reasons.append(f"imprecise location ({loc.name})")
    if intel.used_fallback:
        review_reasons.append("LLM unavailable — keyword fallback used")
    if any(f in intel.flags for f in ("unverified", "speculative", "rumor", "single_source")):
        review_reasons.append("editorial flag: " + ", ".join(intel.flags))
    needs_review = bool(review_reasons)
    review_reason = "; ".join(review_reasons)

    log.info(
        "[%s] %-12s  conf=%.2f  sev=%-6s  loc=%s%s",
        raw["source"],
        intel.incident_type,
        intel.confidence,
        intel.severity,
        loc.name,
        "  [REVIEW]" if needs_review else "",
    )
    log.info("  → %s", raw["title"][:100])

    if dry_run:
        return True

    # ── POST incident ─────────────────────────────────────────────────────────
    incident_payload = {
        "title":         raw["title"],
        "type":          intel.incident_type,
        "latitude":      str(loc.lat),
        "longitude":     str(loc.lng),
        "severity":      intel.severity,
        "status":        "unsafe",
        "incident_time": raw.get("published_at", datetime.now(timezone.utc).isoformat()),
        "location_name": loc.name,
        "reporter_name": raw["source"],
        "article_url":   url,
        "needs_review":  needs_review,
        "review_reason": review_reason,
        "confidence":    round(intel.confidence, 2),
        **intel.to_payload(),
    }
    incident_id = api.post_incident(incident_payload)
    if incident_id:
        log.info(
            "  %s incident #%d  @ %.4f, %.4f",
            "⏸ pending" if needs_review else "✓",
            incident_id, loc.lat, loc.lng,
        )

    db.mark_seen(url, DB_PATH)
    return incident_id is not None


# ── CLI ───────────────────────────────────────────────────────────────────────

def _build_runnables(
    sources_arg: list[str] | None,
    feeds_arg: list[str] | None,
    cadence: str | None,
    run_all: bool,
) -> list[tuple[str, object]]:
    """Return a list of (label, scraper_instance) to run, in order.

    Hand-written scrapers always win over YAML entries of the same id.
    """
    runnables: list[tuple[str, object]] = []

    # 1. Hand-written scrapers (custom logic — Nation, Tuko, Twitter, etc.)
    if run_all or sources_arg:
        names = sources_arg if sources_arg else list(ALL_SCRAPERS)
        for name in names:
            if name not in ALL_SCRAPERS:
                log.warning("Unknown hand-written source '%s' — skipping", name)
                continue
            runnables.append((name, ALL_SCRAPERS[name]()))

    # 2. Config-driven feeds from sources.yaml
    if run_all or feeds_arg is not None or cadence:
        configs = load_sources(cadence=cadence)
        wanted = set(feeds_arg) if feeds_arg else None
        for cfg in configs:
            if wanted and cfg["id"] not in wanted:
                continue
            # Don't double-run if a hand-written scraper already covers it
            if cfg["id"] in ALL_SCRAPERS and (run_all or not feeds_arg):
                continue
            runnables.append((f"feed:{cfg['id']}", GenericScraper(cfg)))

    return runnables


def main() -> None:
    parser = argparse.ArgumentParser(description="Mamboleo web scraper")
    parser.add_argument(
        "--sources", nargs="+",
        help="Hand-written scrapers to run (e.g. nation standard advisories). "
             "Valid: " + ", ".join(ALL_SCRAPERS),
    )
    parser.add_argument(
        "--feeds", nargs="+",
        help="Config-driven source IDs from sources.yaml to run "
             "(e.g. bbc_africa citizen_digital). Use --feeds with no args "
             "via --all to run every enabled feed.",
    )
    parser.add_argument(
        "--cadence", choices=["fast", "slow"],
        help="Restrict YAML feeds to this cadence bucket.",
    )
    parser.add_argument(
        "--all", action="store_true",
        help="Run ALL hand-written scrapers + every enabled YAML feed.",
    )
    parser.add_argument(
        "--limit", type=int, default=MAX_ARTICLES,
        help="Max articles per source (default: %(default)s)",
    )
    parser.add_argument(
        "--dry-run", action="store_true",
        help="Classify and locate but do NOT post to WordPress",
    )
    parser.add_argument(
        "--workers", type=int, default=4,
        help="Run up to N scrapers concurrently (default: %(default)s; "
             "use 1 for serial behaviour). Each scraper has its own HTTP "
             "session and rate limit, so parallelism is safe per-host.",
    )
    parser.add_argument(
        "--skip-preflight", action="store_true",
        help="Skip the dependency check at startup.",
    )
    args = parser.parse_args()

    # Dependency preflight — exits early with a friendly diagnostic if any
    # required package is missing, instead of failing 30s in with ImportError.
    if not args.skip_preflight:
        import preflight
        ok, _ = preflight.run(verbose=True)
        if not ok:
            log.error("Required dependencies missing — aborting.")
            sys.exit(2)

    # Sensible default: if user passed nothing at all, behave like --all
    if not (args.sources or args.feeds or args.cadence or args.all):
        args.all = True

    runnables = _build_runnables(
        sources_arg=args.sources,
        feeds_arg=args.feeds,
        cadence=args.cadence,
        run_all=args.all,
    )
    if not runnables:
        log.error("No scrapers selected — nothing to do.")
        return

    log.info(
        "=== Mamboleo scraper starting — %d source(s): %s ===",
        len(runnables), ", ".join(label for label, _ in runnables),
    )

    # Surface the resolved LLM provider so misconfigurations (e.g. WP /llm-config
    # blocked by WAF → silent fallback to ollama) show up at the top of the log
    # instead of as a "Connection refused" warning later in the run.
    try:
        from api import llm_client
        info = llm_client.provider_info()
        log.info("LLM provider: %s", info)
    except Exception as exc:  # noqa: BLE001
        log.warning("Could not resolve LLM provider info: %s", exc)

    if args.dry_run:
        log.info("DRY-RUN mode: nothing will be posted to WordPress")

    def _run_one(label: str, scraper) -> int:
        """Drain one scraper. Returns number of new incidents posted."""
        if not health.should_run(label):
            log.info("--- %s skipped (in cooldown) ---", label.upper())
            return 0
        log.info("--- %s (limit=%d) ---", label.upper(), args.limit)
        local = 0
        seen = 0
        crashed = False
        try:
            for raw in scraper.fetch_articles(limit=args.limit):
                seen += 1
                if process_article(raw, dry_run=args.dry_run):
                    local += 1
        except Exception as exc:
            crashed = True
            log.error("Scraper %s crashed: %s", label, exc, exc_info=True)
        # Only credit the source if it actually produced articles. A crash
        # or 0-yield counts as a failure for circuit-breaker purposes.
        health.record(label, 0 if crashed else seen)
        log.info("--- %s done: %d articles, %d incidents ---", label.upper(), seen, local)
        return local

    total_incidents = 0
    workers = max(1, min(args.workers, len(runnables)))

    if workers == 1:
        # Serial path — preserves the old log ordering when debugging.
        try:
            for label, scraper in runnables:
                total_incidents += _run_one(label, scraper)
        except KeyboardInterrupt:
            log.info("Interrupted by user.")
    else:
        log.info("Running %d scrapers across %d worker threads", len(runnables), workers)
        with ThreadPoolExecutor(max_workers=workers, thread_name_prefix="scraper") as pool:
            futures = {pool.submit(_run_one, label, scraper): label
                       for label, scraper in runnables}
            try:
                for fut in as_completed(futures):
                    try:
                        total_incidents += fut.result()
                    except Exception as exc:
                        log.error("Scraper %s failed: %s", futures[fut], exc, exc_info=True)
            except KeyboardInterrupt:
                log.info("Interrupted by user — cancelling pending scrapers.")
                for fut in futures:
                    fut.cancel()

    log.info("=== Done. Total new incidents: %d ===", total_incidents)


if __name__ == "__main__":
    main()
