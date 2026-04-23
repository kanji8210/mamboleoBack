"""Config-driven scraper for any outlet with an RSS feed and/or HTML index page.

Replaces the need to hand-write a new scraper class for every outlet.
All behaviour is read from a dict (loaded from `sources.yaml`):

    {
      "id": "nation",
      "name": "Nation Media Group",
      "source_domain": "nation.africa",
      "tier": 2,
      "bias_baseline": 0,        # -100 (left) .. +100 (right)
      "rss": [...],
      "scrape_pages": [...],
      "link_selectors": [...],   # CSS selectors for article <a> on index pages
      "link_pattern": "/article/",  # URL substring fallback
      "content_selectors": [...],
      "enabled": true,
    }
"""
from __future__ import annotations

import calendar
import json
import re
from datetime import datetime, timezone
from typing import Iterator
from urllib.parse import urljoin, urlparse

import feedparser
from bs4 import BeautifulSoup
import urllib3

from scrapers.base import BaseScraper

# Suppress the noisy per-request warning for sources with verify_ssl: false.
# We only flip that knob for known self-signed gov sites where it's the only option.
urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)

# Sensible defaults — most WP / Drupal news sites match these.
_DEFAULT_LINK_SELECTORS = [
    "article h2 a",
    "article h3 a",
    "h2.title a",
    "h3.title a",
    "div.article-card a",
    "a.story-link",
    "a.article-link",
]

_DEFAULT_CONTENT_SELECTORS = [
    "div.article-body",
    "div.article-content",
    "div.post-content",
    "div.entry-content",
    "div.story-body",
    "div[itemprop='articleBody']",
    "article",
]


class GenericScraper(BaseScraper):
    """One scraper instance per outlet. `NAME` and log scope come from config."""

    def __init__(self, config: dict) -> None:
        self.config = config
        self.NAME = config["id"]
        super().__init__()
        self.source_label: str = config.get("source_domain") or config["name"]
        self.rss_feeds: list[str] = config.get("rss") or []
        self.scrape_pages: list[str] = config.get("scrape_pages") or []
        self.link_selectors: list[str] = (
            config.get("link_selectors") or _DEFAULT_LINK_SELECTORS
        )
        self.link_pattern: str = config.get("link_pattern", "")
        self.content_selectors: list[str] = (
            config.get("content_selectors") or _DEFAULT_CONTENT_SELECTORS
        )
        self._base_url: str = self._compute_base(config)
        # Per-source HTTP tuning
        self._timeout: int = int(config.get("timeout", 25))
        self._verify_ssl: bool = bool(config.get("verify_ssl", True))
        # Some outlets (paywalls, bot-protected) need a browser-like UA
        ua = config.get("user_agent")
        if ua:
            self.session.headers["User-Agent"] = ua

    def get(self, url: str, timeout: int | None = None, **kwargs):  # noqa: D401
        """Override BaseScraper.get to apply per-source timeout + SSL settings."""
        if timeout is None:
            timeout = self._timeout
        if not self._verify_ssl and "verify" not in kwargs:
            kwargs["verify"] = False
        return super().get(url, timeout=timeout, **kwargs)

    @staticmethod
    def _compute_base(config: dict) -> str:
        for url in (config.get("rss") or []) + (config.get("scrape_pages") or []):
            if url.startswith("http"):
                p = urlparse(url)
                return f"{p.scheme}://{p.netloc}"
        return "https://" + (config.get("source_domain") or "example.com")

    # ── Main loop ─────────────────────────────────────────────────────────────

    def fetch_articles(self, limit: int = 20) -> Iterator[dict]:
        seen: set[str] = set()
        count = 0

        # 1. RSS first — cheap and fast
        for feed_url in self.rss_feeds:
            if count >= limit:
                return
            try:
                feed = feedparser.parse(feed_url)
            except Exception as exc:  # noqa: BLE001 — feedparser is broad
                self.log.warning("RSS parse failed %s : %s", feed_url, exc)
                continue
            for entry in feed.entries:
                if count >= limit:
                    return
                url = self._abs(entry.get("link", ""))
                if not url or url in seen:
                    continue
                seen.add(url)
                art = self._enrich(
                    url=url,
                    title=entry.get("title", ""),
                    excerpt=_strip_html(entry.get("summary", "")),
                    published_at=_rss_date(entry),
                )
                if art:
                    count += 1
                    yield art

        # 2. HTML index pages — fallback for sources without RSS
        for page_url in self.scrape_pages:
            if count >= limit:
                return
            resp = self.get(page_url)
            if not resp:
                continue
            soup = BeautifulSoup(resp.text, "lxml")
            for url in self._extract_links(soup):
                if count >= limit:
                    return
                if url in seen:
                    continue
                seen.add(url)
                art = self._enrich(url=url)
                if art:
                    count += 1
                    yield art

    # ── Helpers ───────────────────────────────────────────────────────────────

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
            if not title and soup.title:
                title = soup.title.get_text(strip=True)

        if not excerpt:
            od = soup.find("meta", property="og:description") or soup.find(
                "meta", attrs={"name": "description"}
            )
            excerpt = od["content"].strip() if od and od.get("content") else ""

        if not published_at:
            published_at = _extract_date(soup)

        content = _extract_content(soup, self.content_selectors)

        if not title:
            return None

        return {
            "url":          url,
            "title":        title,
            "excerpt":      excerpt,
            "content":      content,
            "published_at": published_at,
            "source":       self.source_label,
            # Analytic hints carried downstream to NLP/trends:
            "source_id":    self.config["id"],
            "tier":         self.config.get("tier", 3),
            "bias_baseline": self.config.get("bias_baseline", 0),
        }

    def _abs(self, href: str) -> str:
        if not href:
            return ""
        if href.startswith("http"):
            return href
        return urljoin(self._base_url + "/", href)

    def _extract_links(self, soup: BeautifulSoup) -> list[str]:
        for sel in self.link_selectors:
            tags = soup.select(sel)
            if tags:
                return [self._abs(t["href"]) for t in tags if t.get("href")]
        if self.link_pattern:
            return [
                self._abs(a["href"])
                for a in soup.find_all("a", href=re.compile(self.link_pattern))
                if a.get("href") and len(a.get_text(strip=True)) > 20
            ]
        return []


# ── Module-level helpers ──────────────────────────────────────────────────────

def _strip_html(html: str) -> str:
    if not html:
        return ""
    return BeautifulSoup(html, "lxml").get_text(" ", strip=True)


def _extract_content(soup: BeautifulSoup, selectors: list[str]) -> str:
    for sel in selectors:
        el = soup.select_one(sel)
        if el:
            return el.get_text(" ", strip=True)[:5000]
    return ""


def _extract_date(soup: BeautifulSoup) -> str:
    for script in soup.find_all("script", type="application/ld+json"):
        try:
            data = json.loads(script.string or "")
            if isinstance(data, list):
                data = next((d for d in data if isinstance(d, dict)), {})
            if isinstance(data, dict) and data.get("datePublished"):
                return data["datePublished"]
        except Exception:
            continue
    for attr in ("article:published_time", "datePublished", "pubdate"):
        tag = soup.find("meta", property=attr) or soup.find("meta", attrs={"name": attr})
        if tag and tag.get("content"):
            return tag["content"]
    t = soup.find("time")
    if t and t.get("datetime"):
        return t["datetime"]
    return datetime.now(timezone.utc).isoformat()


def _rss_date(entry) -> str:
    for key in ("published_parsed", "updated_parsed"):
        tp = getattr(entry, key, None)
        if tp:
            return datetime.fromtimestamp(
                calendar.timegm(tp), tz=timezone.utc
            ).isoformat()
    return datetime.now(timezone.utc).isoformat()
