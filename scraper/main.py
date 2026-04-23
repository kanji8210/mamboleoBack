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
import sys
from datetime import datetime, timezone

import db
from api import client as api
from config import DB_PATH, MAX_ARTICLES
from processors import classify, geocoder, locations
from scrapers.advisories import AdvisoryScraper
from scrapers.googlenews import GoogleNewsScraper
from scrapers.international import InternationalScraper
from scrapers.nation import NationScraper
from scrapers.standard import StandardScraper
from scrapers.tuko import TukoScraper
from scrapers.twitter import TwitterScraper

# ── Logging ───────────────────────────────────────────────────────────────────
logging.basicConfig(
    level=logging.INFO,
    format="%(asctime)s  %(levelname)-8s  %(name)s  %(message)s",
    datefmt="%H:%M:%S",
)
log = logging.getLogger("main")

# ── Review thresholds ─────────────────────────────────────────────────────────
# Below MIN: discard (not an incident).
# Between MIN and REVIEW: auto-park for admin review.
# At or above REVIEW: auto-publish (unless location is low-specificity fallback).
CONFIDENCE_MIN     = 0.20
CONFIDENCE_REVIEW  = 0.50

# ── Scraper registry ──────────────────────────────────────────────────────────
ALL_SCRAPERS = {
    "advisories":    AdvisoryScraper,
    "googlenews":    GoogleNewsScraper,
    "international": InternationalScraper,
    "tuko":          TukoScraper,
    "nation":        NationScraper,
    "standard":      StandardScraper,
    "twitter":       TwitterScraper,
}



# ── Pipeline ──────────────────────────────────────────────────────────────────

def process_article(raw: dict, dry_run: bool) -> bool:
    """
    Full pipeline for one article:
      1. Deduplicate via SQLite
      2. Classify incident type + severity
      3. Extract Kenya location
      4. POST article + incident to WordPress REST API
    Returns True if an incident was created.
    """
    url = raw["url"]

    if db.is_seen(url, DB_PATH):
        log.debug("Skip (seen): %s", url)
        return False

    # ── Classify ──────────────────────────────────────────────────────────────
    text = raw["title"] + " " + raw.get("excerpt", "") + " " + raw.get("content", "")
    cls = classify.classify(raw["title"], raw.get("content", ""))

    if cls is None or cls.confidence < CONFIDENCE_MIN:
        log.debug("Not an incident (conf=%.2f): %s", cls.confidence if cls else 0, raw["title"][:80])
        db.mark_seen(url, DB_PATH)
        return False

    # ── Location ──────────────────────────────────────────────────────────────
    loc = locations.best_location(text)
    location_fallback = False

    if loc is None:
        # Try Nominatim as fallback
        import re
        # Pull candidate place names from title
        words = re.findall(r"[A-Z][a-z]{3,}", raw["title"])
        for word in words:
            coords = geocoder.geocode(word)
            if coords:
                from processors.locations import Location
                loc = Location(name=word, lat=coords[0], lng=coords[1], specificity=1)
                location_fallback = True
                break

    if loc is None:
        log.info("No Kenya location found, skipping: %s", raw["title"][:80])
        db.mark_seen(url, DB_PATH)
        return False

    # ── Decide whether this needs human review ────────────────────────────────
    review_reasons: list[str] = []
    if cls.confidence < CONFIDENCE_REVIEW:
        review_reasons.append(f"low classification confidence ({cls.confidence:.2f})")
    if location_fallback or loc.specificity <= 1:
        review_reasons.append(f"imprecise location ({loc.name})")
    needs_review = bool(review_reasons)
    review_reason = "; ".join(review_reasons)

    log.info(
        "[%s] %-12s  conf=%.2f  sev=%-6s  loc=%s%s",
        raw["source"],
        cls.incident_type,
        cls.confidence,
        cls.severity,
        loc.name,
        "  [REVIEW]" if needs_review else "",
    )
    log.info("  → %s", raw["title"][:100])

    if dry_run:
        db.mark_seen(url, DB_PATH)
        return True

    # ── POST article ──────────────────────────────────────────────────────────
    article_id = api.post_article(
        {
            "title":       raw["title"],
            "source":      raw["source"],
            "article_url": url,
            "sentiment":   "neutral",
            "bias_score":  0,
        }
    )
    if article_id:
        log.info("  ✓ article #%d", article_id)

    # ── POST incident ─────────────────────────────────────────────────────────
    incident_id = api.post_incident(
        {
            "title":         raw["title"],
            "type":          cls.incident_type,
            "latitude":      str(loc.lat),
            "longitude":     str(loc.lng),
            "severity":      cls.severity,
            "status":        "unsafe",
            "incident_time": raw.get("published_at", datetime.now(timezone.utc).isoformat()),
            "location_name": loc.name,
            "reporter_name": raw["source"],
            "article_url":   url,
            "needs_review":  needs_review,
            "review_reason": review_reason,
            "confidence":    round(cls.confidence, 2),
        }
    )
    if incident_id:
        log.info(
            "  %s incident #%d  @ %.4f, %.4f",
            "⏸ pending" if needs_review else "✓",
            incident_id, loc.lat, loc.lng,
        )

    db.mark_seen(url, DB_PATH)
    return incident_id is not None


# ── CLI ───────────────────────────────────────────────────────────────────────

def main() -> None:
    parser = argparse.ArgumentParser(description="Mamboleo web scraper")
    parser.add_argument(
        "--sources", nargs="+",
        choices=list(ALL_SCRAPERS),
        default=list(ALL_SCRAPERS),
        help="Which scrapers to run (default: all)",
    )
    parser.add_argument(
        "--limit", type=int, default=MAX_ARTICLES,
        help="Max articles per source (default: %(default)s)",
    )
    parser.add_argument(
        "--dry-run", action="store_true",
        help="Classify and locate but do NOT post to WordPress",
    )
    args = parser.parse_args()

    log.info("=== Mamboleo scraper starting — sources: %s ===", ", ".join(args.sources))
    if args.dry_run:
        log.info("DRY-RUN mode: nothing will be posted to WordPress")

    total_incidents = 0

    for name in args.sources:
        scraper_cls = ALL_SCRAPERS[name]
        scraper = scraper_cls()
        log.info("--- %s (limit=%d) ---", name.upper(), args.limit)
        source_incidents = 0

        try:
            for raw in scraper.fetch_articles(limit=args.limit):
                if process_article(raw, dry_run=args.dry_run):
                    source_incidents += 1
        except KeyboardInterrupt:
            log.info("Interrupted by user.")
            break
        except Exception as exc:
            log.error("Scraper %s crashed: %s", name, exc, exc_info=True)

        log.info("--- %s done: %d incidents ---", name.upper(), source_incidents)
        total_incidents += source_incidents

    log.info("=== Done. Total new incidents: %d ===", total_incidents)


if __name__ == "__main__":
    main()
