"""Nation Africa (nation.africa) scraper — RSS-first.

Kenya's largest legacy media group. Security-relevant sections:
  News  : https://nation.africa/kenya/news
  Counties: https://nation.africa/kenya/counties
  RSS   : https://nation.africa/kenya/rss  (varies by section)
"""
from __future__ import annotations

import json
import re
from datetime import datetime, timezone
from typing import Iterator

import feedparser
from bs4 import BeautifulSoup

from scrapers.base import BaseScraper

_SOURCE = "nation.africa"

_RSS_FEEDS = [
    "https://nation.africa/kenya/rss",
    "https://nation.africa/kenya/news/rss",
    "https://nation.africa/kenya/counties/rss",
]

_CATEGORY_PAGES = [
    "https://nation.africa/kenya/news/",
    "https://nation.africa/kenya/counties/",
]

_ARTICLE_LINK_SELECTORS = [
    "article h3 a",
    "div.article-card a.article-link",
    "h2.title a",
    "a.story-link",
    "div.teaser h3 a",
]

_CONTENT_SELECTORS = [
    "div.body-copy",
    "div.article-content",
    "div[itemprop='articleBody']",
    "article .post-content",
    "div.story-body",
]


class NationScraper(BaseScraper):
    NAME = "nation"

    def fetch_articles(self, limit: int = 20) -> Iterator[dict]:
        seen_urls: set[str] = set()
        count = 0
        base = "https://nation.africa"

        # ── RSS ───────────────────────────────────────────────────────────────
        for feed_url in _RSS_FEEDS:
            if count >= limit:
                break
            feed = feedparser.parse(feed_url)
            for entry in feed.entries:
                if count >= limit:
                    break
                url = _abs(entry.get("link", ""), base)
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
            for url in _extract_links(soup, _ARTICLE_LINK_SELECTORS, base):
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

def _abs(href: str, base: str) -> str:
    if href.startswith("http"):
        return href
    if href.startswith("/"):
        return base + href
    return ""


def _extract_links(soup: BeautifulSoup, selectors: list[str], base: str) -> list[str]:
    for sel in selectors:
        tags = soup.select(sel)
        if tags:
            return [_abs(t["href"], base) for t in tags if t.get("href")]
    return [
        _abs(a["href"], base)
        for a in soup.find_all("a", href=re.compile(r"/kenya/"))
        if a.get("href") and len(a.get_text(strip=True)) > 20
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
