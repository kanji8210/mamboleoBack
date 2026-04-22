"""Tuko.co.ke scraper — RSS-first, HTML category page fallback.

Tuko is Kenya's highest-traffic digital news site. Relevant sections:
  Crime  : https://www.tuko.co.ke/crime/
  Disaster: https://www.tuko.co.ke/kenya/ (mixed)
  RSS    : https://www.tuko.co.ke/rss.xml  (all)
           https://www.tuko.co.ke/crime/rss.xml
"""
from __future__ import annotations

import re
from datetime import datetime, timezone
from typing import Iterator

import feedparser
from bs4 import BeautifulSoup

from scrapers.base import BaseScraper

_SOURCE = "tuko.co.ke"

_RSS_FEEDS = [
    "https://www.tuko.co.ke/crime/rss.xml",
    "https://www.tuko.co.ke/kenya/rss.xml",
    "https://www.tuko.co.ke/rss.xml",
]

_CATEGORY_PAGES = [
    "https://www.tuko.co.ke/crime/",
    "https://www.tuko.co.ke/kenyan-disasters/",
]

# Selectors tried in order — Tuko has changed layout several times
_ARTICLE_LINK_SELECTORS = [
    "article a.c-article-card__link",
    "article h2 a",
    "div.c-article-card h2 a",
    "a.article-title",
    "h2.entry-title a",
]

_CONTENT_SELECTORS = [
    "div.c-article-body",
    "div.js-article-body",
    "div.article-body",
    "div[itemprop='articleBody']",
    "article .entry-content",
]


class TukoScraper(BaseScraper):
    NAME = "tuko"

    def fetch_articles(self, limit: int = 20) -> Iterator[dict]:
        seen_urls: set[str] = set()
        count = 0

        # ── 1. Try RSS feeds ──────────────────────────────────────────────────
        for feed_url in _RSS_FEEDS:
            if count >= limit:
                break
            feed = feedparser.parse(feed_url)
            if feed.bozo:
                self.log.debug("RSS parse issue for %s", feed_url)
            for entry in feed.entries:
                if count >= limit:
                    break
                url = entry.get("link", "").strip()
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

        # ── 2. HTML fallback — category pages ─────────────────────────────────
        for page_url in _CATEGORY_PAGES:
            if count >= limit:
                break
            resp = self.get(page_url)
            if not resp:
                continue
            soup = BeautifulSoup(resp.text, "lxml")

            links = _extract_links(soup, _ARTICLE_LINK_SELECTORS)
            for url in links:
                if count >= limit:
                    break
                if url in seen_urls:
                    continue
                seen_urls.add(url)

                article = self._enrich(url=url)
                if article:
                    count += 1
                    yield article

    # ── Per-article enrichment ────────────────────────────────────────────────

    def _enrich(
        self,
        url: str,
        title: str = "",
        excerpt: str = "",
        published_at: str = "",
    ) -> dict | None:
        """Fetch article page and extract full text, title, date."""
        resp = self.get(url)
        if not resp:
            return None
        soup = BeautifulSoup(resp.text, "lxml")

        if not title:
            og = soup.find("meta", property="og:title")
            title = (
                og["content"].strip()
                if og and og.get("content")
                else (soup.title.string or "").strip()
            )

        if not excerpt:
            og_desc = soup.find("meta", property="og:description")
            excerpt = og_desc["content"].strip() if og_desc and og_desc.get("content") else ""

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

def _extract_links(soup: BeautifulSoup, selectors: list[str]) -> list[str]:
    for sel in selectors:
        tags = soup.select(sel)
        if tags:
            return [t["href"] for t in tags if t.get("href")]
    # Fallback: any link matching Tuko article URL pattern (e.g. /XXXXXX-slug.html)
    return [
        a["href"]
        for a in soup.find_all("a", href=re.compile(r"/\d{6,}-"))
        if a.get("href")
    ]


def _extract_content(soup: BeautifulSoup, selectors: list[str]) -> str:
    for sel in selectors:
        el = soup.select_one(sel)
        if el:
            return el.get_text(" ", strip=True)[:3000]
    return ""


def _extract_date(soup: BeautifulSoup) -> str:
    # Try JSON-LD datePublished
    for script in soup.find_all("script", type="application/ld+json"):
        import json
        try:
            data = json.loads(script.string or "")
            if isinstance(data, dict) and data.get("datePublished"):
                return data["datePublished"]
        except Exception:
            pass
    # Try meta tags
    for attr in ("article:published_time", "datePublished", "pubdate"):
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
