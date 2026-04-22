"""Standard Media (standardmedia.co.ke) scraper.

Kenya's second-largest legacy print/digital group (The Standard, KTN).
Security-relevant sections:
  National : https://www.standardmedia.co.ke/national
  Crime    : https://www.standardmedia.co.ke/crime
  Counties : https://www.standardmedia.co.ke/counties
  RSS      : https://www.standardmedia.co.ke/rss/national
"""
from __future__ import annotations

import json
import re
from datetime import datetime, timezone
from typing import Iterator

import feedparser
from bs4 import BeautifulSoup

from scrapers.base import BaseScraper

_SOURCE = "standardmedia.co.ke"
_BASE   = "https://www.standardmedia.co.ke"

_RSS_FEEDS = [
    f"{_BASE}/rss/national",
    f"{_BASE}/rss/crime",
    f"{_BASE}/rss/counties",
]

_CATEGORY_PAGES = [
    f"{_BASE}/national",
    f"{_BASE}/crime",
]

_ARTICLE_LINK_SELECTORS = [
    "article.story h2 a",
    "div.story-content h2 a",
    "h3.title a",
    "a.story-title",
]

_CONTENT_SELECTORS = [
    "div.article-body",
    "div#article-body",
    "div[itemprop='articleBody']",
    "div.story-body",
    "section.article-content",
]


class StandardScraper(BaseScraper):
    NAME = "standard"

    def fetch_articles(self, limit: int = 20) -> Iterator[dict]:
        seen_urls: set[str] = set()
        count = 0

        # ── RSS ───────────────────────────────────────────────────────────────
        for feed_url in _RSS_FEEDS:
            if count >= limit:
                break
            feed = feedparser.parse(feed_url)
            for entry in feed.entries:
                if count >= limit:
                    break
                url = _abs(entry.get("link", ""))
                if not url or url in seen_urls:
                    continue
                seen_urls.add(url)
                article = self._enrich(
                    url=url,
                    title=entry.get("title", ""),
                    excerpt=BeautifulSoup(
                        entry.get("summary", ""), "lxml"
                    ).get_text(" ", strip=True),
                    published_at=_parse_rss_date(entry),
                )
                if article:
                    count += 1
                    yield article

        if count >= limit:
            return

        # ── HTML fallback ─────────────────────────────────────────────────────
        for page_url in _CATEGORY_PAGES:
            if count >= limit:
                break
            resp = self.get(page_url)
            if not resp:
                continue
            soup = BeautifulSoup(resp.text, "lxml")
            for url in _extract_links(soup, _ARTICLE_LINK_SELECTORS):
                if count >= limit:
                    break
                if url in seen_urls:
                    continue
                seen_urls.add(url)
                article = self._enrich(url=url)
                if article:
                    count += 1
                    yield article

    def _enrich(
        self,
        url: str,
        title: str = "",
        excerpt: str = "",
        published_at: str = "",
    ) -> dict | None:
        resp = self.get(url)
        if not resp:
            return None
        soup = BeautifulSoup(resp.text, "lxml")

        if not title:
            og = soup.find("meta", property="og:title")
            title = og["content"].strip() if og and og.get("content") else ""

        if not excerpt:
            og_d = soup.find("meta", property="og:description")
            excerpt = og_d["content"].strip() if og_d and og_d.get("content") else ""

        if not published_at:
            published_at = _extract_date(soup)

        content = _extract_content(soup, _CONTENT_SELECTORS)

        if not title:
            return None

        return {
            "url":          url,
            "title":        title,
            "excerpt":      excerpt,
            "content":      content,
            "published_at": published_at,
            "source":       _SOURCE,
        }


# ── Helpers ───────────────────────────────────────────────────────────────────

def _abs(href: str) -> str:
    if href.startswith("http"):
        return href
    if href.startswith("/"):
        return _BASE + href
    return ""


def _extract_links(soup: BeautifulSoup, selectors: list[str]) -> list[str]:
    for sel in selectors:
        tags = soup.select(sel)
        if tags:
            return [_abs(t["href"]) for t in tags if t.get("href")]
    # Generic fallback — Standard article URLs contain /article/
    return [
        _abs(a["href"])
        for a in soup.find_all("a", href=re.compile(r"/article/"))
        if a.get("href")
    ]


def _extract_content(soup: BeautifulSoup, selectors: list[str]) -> str:
    for sel in selectors:
        el = soup.select_one(sel)
        if el:
            return el.get_text(" ", strip=True)[:3000]
    return ""


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


def _parse_rss_date(entry) -> str:
    if hasattr(entry, "published_parsed") and entry.published_parsed:
        import calendar
        ts = calendar.timegm(entry.published_parsed)
        return datetime.fromtimestamp(ts, tz=timezone.utc).isoformat()
    return datetime.now(timezone.utc).isoformat()
