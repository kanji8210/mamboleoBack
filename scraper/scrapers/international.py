"""International news scrapers — Al Jazeera, France 24, LCI.

These outlets have reliable RSS feeds covering Africa/Kenya,
bypassing the bot-protection issues seen with local Kenyan sites.
"""
from __future__ import annotations

import json
import re
from datetime import datetime, timezone
from typing import Iterator

import feedparser
from bs4 import BeautifulSoup

from scrapers.base import BaseScraper

# ── Feed definitions ──────────────────────────────────────────────────────────
# Each entry: (feed_url, source_label, kenya_filter)
# kenya_filter=True means we only yield articles mentioning Kenya/East Africa

_FEEDS = [
    # Al Jazeera — Africa section + main feed
    ("https://www.aljazeera.com/xml/rss/all.xml",                      "Al Jazeera",   True),

    # France 24 — English Africa section
    ("https://www.france24.com/en/africa/rss",                         "France 24",    True),
    ("https://www.france24.com/en/latest-news/rss",                    "France 24",    True),

    # France 24 — French Africa section
    ("https://www.france24.com/fr/afrique/rss",                        "France 24 FR", True),

    # LCI (TF1 group) — main feed, filtered for Kenya mentions
    ("https://www.lci.fr/rss/international.xml",                       "LCI",          True),
    ("https://www.lci.fr/rss/actualite.xml",                           "LCI",          True),
]

# Keywords to match Kenya-relevant articles (case-insensitive)
_KENYA_KEYWORDS = re.compile(
    r"\b(?:Kenya|Nairobi|Mombasa|Kisumu|Nakuru|Kenyan|Kenyans|Kenyatta|Ruto"
    r"|East\s+Africa|Garissa|Turkana|Rift\s+Valley|Mt\.?\s*Kenya|Lamu"
    r"|KDF|Kenyan\s+police|Kenyan\s+military)\b",
    re.IGNORECASE
)

# Content selectors per source (tried in order)
_CONTENT_SELECTORS = {
    "Al Jazeera":   ["div.wysiwyg", "article .article-body", "main article"],
    "France 24":    ["div.t-content__body", "div.o-article-body", "article .text--default"],
    "France 24 FR": ["div.t-content__body", "div.o-article-body", "article .text--default"],
    "LCI":          ["div.article-body", "div.text-article", "article .content"],
}


class InternationalScraper(BaseScraper):
    NAME = "international"

    def fetch_articles(self, limit: int = 20) -> Iterator[dict]:
        seen_urls: set[str] = set()
        count = 0

        for feed_url, source_label, kenya_filter in _FEEDS:
            if count >= limit:
                break

            self.log.info("Fetching %s: %s", source_label, feed_url)
            try:
                feed = feedparser.parse(feed_url)
            except Exception as exc:
                self.log.warning("Feed parse error for %s: %s", source_label, exc)
                continue

            if feed.bozo:
                self.log.debug("Feed issue for %s: %s", source_label, feed.bozo_exception)

            for entry in feed.entries:
                if count >= limit:
                    break

                url = entry.get("link", "").strip()
                if not url or url in seen_urls:
                    continue

                title = entry.get("title", "").strip()
                summary = BeautifulSoup(
                    entry.get("summary", ""), "lxml"
                ).get_text(" ", strip=True)

                # Kenya relevance filter
                if kenya_filter:
                    combined = f"{title} {summary}"
                    if not _KENYA_KEYWORDS.search(combined):
                        continue

                seen_urls.add(url)

                article = self._enrich(
                    url=url,
                    title=title,
                    excerpt=summary,
                    source=source_label,
                    published_at=_parse_rss_date(entry),
                )
                if article:
                    count += 1
                    yield article

        self.log.info("International feeds done: %d articles yielded", count)

    def _enrich(
        self,
        url: str,
        title: str,
        excerpt: str,
        source: str,
        published_at: str,
    ) -> dict | None:
        """Fetch article page and extract full text."""
        content = ""
        resp = self.get(url)
        if resp:
            soup = BeautifulSoup(resp.text, "lxml")

            # Try source-specific selectors first
            selectors = _CONTENT_SELECTORS.get(source, [])
            for sel in selectors:
                el = soup.select_one(sel)
                if el:
                    content = el.get_text(" ", strip=True)[:3000]
                    break

            # Generic fallback
            if not content:
                for sel in ["article", "[itemprop='articleBody']", ".entry-content"]:
                    el = soup.select_one(sel)
                    if el:
                        content = el.get_text(" ", strip=True)[:3000]
                        break

            # Try to get a better title from OG tags
            if not title:
                og = soup.find("meta", property="og:title")
                title = og["content"].strip() if og and og.get("content") else ""

            # Better excerpt from OG description
            if not excerpt:
                og_d = soup.find("meta", property="og:description")
                excerpt = og_d["content"].strip() if og_d and og_d.get("content") else ""

            # Better date from structured data
            if not published_at:
                published_at = _extract_date(soup)

        if not title:
            return None

        return {
            "url":          url,
            "title":        title,
            "excerpt":      excerpt,
            "content":      content,
            "published_at": published_at,
            "source":       source,
        }


# ── Helpers ───────────────────────────────────────────────────────────────────

def _parse_rss_date(entry) -> str:
    if hasattr(entry, "published_parsed") and entry.published_parsed:
        import calendar
        ts = calendar.timegm(entry.published_parsed)
        return datetime.fromtimestamp(ts, tz=timezone.utc).isoformat()
    return datetime.now(timezone.utc).isoformat()


def _extract_date(soup: BeautifulSoup) -> str:
    for script in soup.find_all("script", type="application/ld+json"):
        try:
            data = json.loads(script.string or "")
            if isinstance(data, dict) and data.get("datePublished"):
                return data["datePublished"]
        except Exception:
            pass
    for attr in ("article:published_time", "datePublished"):
        tag = soup.find("meta", property=attr) or soup.find("meta", attrs={"name": attr})
        if tag and tag.get("content"):
            return tag["content"]
    return datetime.now(timezone.utc).isoformat()
