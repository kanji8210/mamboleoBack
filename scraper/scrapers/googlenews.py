"""Google News RSS scraper.
Bypasses individual site bot protection by reading Google's aggregated feed.
"""
from __future__ import annotations

import re
import urllib.parse
from datetime import datetime, timezone
from typing import Iterator

import feedparser
import requests as _requests
from bs4 import BeautifulSoup

from scrapers.base import BaseScraper

_SOURCE = "google-news"

# Curated searches for Kenya security and trending news
_QUERIES = [
    "Kenya breaking news",
    "Kenya crime police",
    "Kenya protest demonstration",
    "Kenya accident fire",
]


def _resolve_redirect(google_url: str) -> str | None:
    """Follow one HTTP redirect to extract the canonical publisher URL.

    Google News RSS links (`news.google.com/rss/articles/CBMi…`) redirect
    via a 302/303 to the real publisher page.  Some newer links use a
    JavaScript-only decoder page that returns 200 with an HTML body — we
    detect those and give up (return None).

    Uses a HEAD request with a tight 5 s timeout and `allow_redirects=False`
    so we can inspect the Location header without downloading the full page.
    """
    if "news.google.com" not in google_url:
        return google_url  # already a direct URL

    try:
        resp = _requests.head(
            google_url,
            allow_redirects=False,
            timeout=5,
            headers={
                "User-Agent": "Mozilla/5.0 (compatible; MamboleoBot/1.0)",
            },
        )
        if resp.status_code in (301, 302, 303, 307, 308):
            location = resp.headers.get("Location", "")
            if location and "news.google.com" not in location:
                return location
    except _requests.exceptions.RequestException:
        pass
    return None


class GoogleNewsScraper(BaseScraper):
    NAME = "googlenews"

    def fetch_articles(self, limit: int = 20) -> Iterator[dict]:
        seen_urls: set[str] = set()
        count = 0

        for query in _QUERIES:
            if count >= limit:
                break
            
            encoded_query = urllib.parse.quote(query)
            # hl=en-KE, gl=KE, ceid=KE:en ensures Kenya-centric results
            feed_url = f"https://news.google.com/rss/search?q={encoded_query}+when:24h&hl=en-KE&gl=KE&ceid=KE:en"
            
            self.log.info("Searching Google News: %r", query)
            feed = self.fetch_feed(feed_url)
            if feed is None:
                continue
            self.log.info("  → %d entries from Google News", len(feed.entries))
            for entry in feed.entries:
                if count >= limit:
                    break
                
                # Google News links are often redirects — try to resolve to
                # the canonical publisher URL so dedup matches site-specific
                # scrapers (Nation, Standard, Tuko etc.).
                raw_url = entry.get("link", "").strip()
                if not raw_url:
                    continue

                resolved = _resolve_redirect(raw_url)
                url = resolved or raw_url

                if url in seen_urls:
                    continue
                seen_urls.add(url)
                # Also mark the raw Google URL as seen so we don't re-resolve
                if raw_url != url:
                    seen_urls.add(raw_url)

                # Title is usually "Title - Source"
                raw_title = entry.get("title", "")
                title_parts = raw_title.rsplit(" - ", 1)
                title = title_parts[0] if title_parts else raw_title
                source_name = title_parts[1] if len(title_parts) > 1 else "Unknown"

                article = self._enrich(
                    url=url,
                    title=title,
                    source=f"Google News ({source_name})",
                    published_at=_parse_rss_date(entry),
                    summary=entry.get("summary", ""),
                )
                
                if article:
                    count += 1
                    yield article

    def _enrich(self, url: str, title: str, source: str, published_at: str,
                summary: str = "") -> dict | None:
        """Build an article dict from the RSS entry metadata.

        We resolve Google News redirect URLs to canonical publisher URLs
        (via _resolve_redirect) for dedup, but we still don't fetch the
        full article body — the RSS title + summary provides enough signal
        for the LLM intelligence layer to decide is_incident.
        """
        content = ""
        if summary:
            try:
                content = BeautifulSoup(summary, "lxml").get_text(" ", strip=True)[:1500]
            except Exception:  # noqa: BLE001
                content = summary[:1500]

        return {
            "url":          url,
            "title":        title,
            "excerpt":      content[:280],
            "content":      content,
            "published_at": published_at,
            "source":       source,
        }

def _parse_rss_date(entry) -> str:
    if hasattr(entry, "published_parsed") and entry.published_parsed:
        import calendar
        ts = calendar.timegm(entry.published_parsed)
        return datetime.fromtimestamp(ts, tz=timezone.utc).isoformat()
    return datetime.now(timezone.utc).isoformat()
