"""Google News RSS scraper.
Bypasses individual site bot protection by reading Google's aggregated feed.
"""
from __future__ import annotations

import re
import urllib.parse
from datetime import datetime, timezone
from typing import Iterator

import feedparser
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
            feed = feedparser.parse(feed_url)
            
            for entry in feed.entries:
                if count >= limit:
                    break
                
                # Google News links are often redirects
                url = entry.get("link", "").strip()
                if not url or url in seen_urls:
                    continue
                seen_urls.add(url)

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
                )
                
                if article:
                    count += 1
                    yield article

    def _enrich(self, url: str, title: str, source: str, published_at: str) -> dict | None:
        """
        For Google News, we mostly rely on the feed data since follow-through scraping 
        of the target site might still hit 403s.
        """
        # We still try to get the real content if possible, but we won't fail if we can't
        resp = self.get(url)
        content = ""
        if resp:
            soup = BeautifulSoup(resp.text, "lxml")
            # Try to find common content blocks
            for sel in ["article", ".entry-content", ".article-body", ".story-body"]:
                el = soup.select_one(sel)
                if el:
                    content = el.get_text(" ", strip=True)[:3000]
                    break
        
        return {
            "url":          url,
            "title":        title,
            "excerpt":      "", # RSS excerpt is often just a link
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
