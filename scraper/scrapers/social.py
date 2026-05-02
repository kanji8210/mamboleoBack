"""Social handles scraper — Tier-1/2/3 Kenyan accounts.

Reads scraper/social_sources.yaml and pulls posts per handle:

  • twitter / x  → tweepy.Client.get_users_tweets via TWITTER_BEARER_TOKEN.
                   Skips silently when no token is configured.
  • facebook     → fetched via a self-hosted RSSHub bridge if RSSHUB_HOST
                   is set in .env. Pattern: {RSSHUB_HOST}/facebook/page/{handle}
  • youtube      → standard YouTube channel RSS (no auth needed).

Each post is yielded in the same article-dict shape used by HTML scrapers,
so the downstream pipeline (analyze → intelligence → locations → POST)
treats them uniformly.

Why per-handle rather than the generic search query in twitter.py? Two reasons:
  1) These specific handles are higher-signal and lower-noise than open search.
  2) The free Twitter v2 tier is read-quota-limited; per-handle pulls let us
     budget calls precisely (max_per_run per entry).
"""
from __future__ import annotations

import logging
from datetime import datetime, timezone
from typing import Iterator
from xml.etree import ElementTree as ET

import requests
import yaml

from config import ROOT_DIR, RSSHUB_HOST, TWITTER_BEARER, USER_AGENT

log = logging.getLogger("scraper.social")

SOCIAL_FILE = ROOT_DIR / "social_sources.yaml"


def load_social_sources(
    cadence: str | None = None,
    only_enabled: bool = True,
) -> list[dict]:
    if not SOCIAL_FILE.exists():
        log.warning("social_sources.yaml not found at %s", SOCIAL_FILE)
        return []
    try:
        data = yaml.safe_load(SOCIAL_FILE.read_text(encoding="utf-8")) or []
    except yaml.YAMLError as exc:
        log.error("social_sources.yaml invalid: %s", exc)
        return []
    out: list[dict] = []
    for entry in data or []:
        if not isinstance(entry, dict) or "id" not in entry:
            continue
        if only_enabled and not entry.get("enabled", True):
            continue
        if cadence and entry.get("cadence") != cadence:
            continue
        out.append(entry)
    return out


class SocialHandlesScraper:
    """Single scraper that walks the entire social_sources.yaml registry."""

    NAME = "social"

    def __init__(self, cadence: str | None = None) -> None:
        self._cadence = cadence
        self._twitter_client = None  # lazy

    # ── public entry point ────────────────────────────────────────────────
    def fetch_articles(self, limit: int = 30) -> Iterator[dict]:
        sources = load_social_sources(cadence=self._cadence)
        if not sources:
            log.info("No enabled social sources.")
            return

        budget = limit
        for src in sources:
            if budget <= 0:
                break
            per_run = min(int(src.get("max_per_run", 5)), budget)
            try:
                yielded = 0
                for item in self._fetch_one(src, per_run):
                    yield item
                    yielded += 1
                    budget -= 1
                    if yielded >= per_run or budget <= 0:
                        break
            except Exception as exc:  # pragma: no cover — defensive
                log.warning("Social source %s failed: %s", src.get("id"), exc)

    # ── per-platform dispatchers ──────────────────────────────────────────
    def _fetch_one(self, src: dict, limit: int) -> Iterator[dict]:
        platform = (src.get("platform") or "").lower()
        if platform in ("twitter", "x"):
            yield from self._fetch_twitter(src, limit)
        elif platform == "facebook":
            yield from self._fetch_facebook(src, limit)
        elif platform == "youtube":
            yield from self._fetch_youtube(src, limit)
        else:
            log.warning("Unknown platform '%s' for %s", platform, src.get("id"))

    # ── twitter ───────────────────────────────────────────────────────────
    def _twitter(self):
        if self._twitter_client is not None:
            return self._twitter_client
        if not TWITTER_BEARER:
            return None
        try:
            import tweepy
        except ImportError:
            log.warning("tweepy not installed — pip install tweepy")
            return None
        self._twitter_client = tweepy.Client(
            bearer_token=TWITTER_BEARER, wait_on_rate_limit=False
        )
        return self._twitter_client

    def _fetch_twitter(self, src: dict, limit: int) -> Iterator[dict]:
        client = self._twitter()
        if not client:
            return
        handle = src["handle"]
        try:
            user = client.get_user(username=handle)
            if not user or not user.data:
                log.info("X handle @%s not found", handle)
                return
            user_id = user.data.id
            resp = client.get_users_tweets(
                id=user_id,
                max_results=max(5, min(limit, 100)),
                tweet_fields=["created_at", "text", "public_metrics"],
                exclude=["retweets", "replies"],
            )
        except Exception as exc:
            log.warning("X fetch failed for @%s: %s", handle, exc)
            return
        if not resp.data:
            return
        for tw in resp.data[:limit]:
            url = f"https://twitter.com/{handle}/status/{tw.id}"
            created = (
                tw.created_at.isoformat()
                if getattr(tw, "created_at", None)
                else datetime.now(timezone.utc).isoformat()
            )
            yield {
                "url":           url,
                "title":         tw.text[:120],
                "excerpt":       tw.text,
                "content":       tw.text,
                "published_at":  created,
                "source":        f"x.com/@{handle}",
                "bias_baseline": int(src.get("bias_baseline", 0)),
                "social_handle": handle,
                "platform":      "twitter",
            }

    # ── facebook (via RSSHub bridge) ──────────────────────────────────────
    def _fetch_facebook(self, src: dict, limit: int) -> Iterator[dict]:
        if not RSSHUB_HOST:
            log.debug("RSSHUB_HOST not set — skipping FB %s", src.get("id"))
            return
        handle = src["handle"]
        feed_url = f"{RSSHUB_HOST}/facebook/page/{handle}"
        yield from self._fetch_rss(src, feed_url, limit, source_label=f"facebook.com/{handle}")

    # ── youtube (channel RSS) ─────────────────────────────────────────────
    def _fetch_youtube(self, src: dict, limit: int) -> Iterator[dict]:
        channel_id = src["handle"]
        feed_url = f"https://www.youtube.com/feeds/videos.xml?channel_id={channel_id}"
        yield from self._fetch_rss(src, feed_url, limit, source_label=f"youtube.com/{channel_id}")

    # ── shared RSS helper ─────────────────────────────────────────────────
    def _fetch_rss(self, src: dict, feed_url: str, limit: int, source_label: str) -> Iterator[dict]:
        try:
            r = requests.get(feed_url, headers={"User-Agent": USER_AGENT}, timeout=20)
            r.raise_for_status()
        except requests.RequestException as exc:
            log.warning("RSS fetch failed for %s: %s", src.get("id"), exc)
            return

        try:
            root = ET.fromstring(r.text)
        except ET.ParseError as exc:
            log.warning("RSS parse failed for %s: %s", src.get("id"), exc)
            return

        # Support both Atom (<entry>) and RSS 2.0 (<item>).
        ns = {"atom": "http://www.w3.org/2005/Atom"}
        items = root.findall(".//atom:entry", ns) or root.findall(".//item")
        for el in items[:limit]:
            link_el = el.find("atom:link", ns)
            link = link_el.get("href") if link_el is not None else (el.findtext("link") or "")
            title = (el.findtext("atom:title", default="", namespaces=ns)
                     or el.findtext("title", default="")).strip()
            published = (el.findtext("atom:published", default="", namespaces=ns)
                         or el.findtext("pubDate", default="")).strip()
            summary = (el.findtext("atom:summary", default="", namespaces=ns)
                       or el.findtext("description", default="")).strip()
            if not link:
                continue
            yield {
                "url":           link,
                "title":         title or "(untitled)",
                "excerpt":       summary[:500],
                "content":       summary,
                "published_at":  published,
                "source":        source_label,
                "bias_baseline": int(src.get("bias_baseline", 0)),
                "social_handle": src.get("handle"),
                "platform":      src.get("platform"),
            }
