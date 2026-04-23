"""Travel advisory scraper — official government sources.

Fetches Kenya-specific travel advisories from:
  - US State Department (RSS feed)
  - UK FCDO (GOV.UK API)
  - France MAE (Diplomatie.gouv.fr)
  - Canada Global Affairs
  - Australia Smartraveller

These are stored as 'article' posts with source = advisory issuer.
"""
from __future__ import annotations

import hashlib
import json
import logging
import re
from datetime import datetime, timezone
from typing import Iterator

import requests
from bs4 import BeautifulSoup

from scrapers.base import BaseScraper
from config import USER_AGENT

log = logging.getLogger(__name__)

_HEADERS = {"User-Agent": USER_AGENT}


# ── Advisory sources ──────────────────────────────────────────────────────────

class AdvisoryScraper(BaseScraper):
    NAME = "advisories"

    def fetch_articles(self, limit: int = 20) -> Iterator[dict]:
        count = 0
        for fetcher in [
            self._fetch_uk_fcdo,
            self._fetch_us_state_dept,
            self._fetch_france_mae,
            self._fetch_canada,
            self._fetch_australia,
        ]:
            if count >= limit:
                break
            try:
                for article in fetcher():
                    if count >= limit:
                        break
                    count += 1
                    yield article
            except Exception as exc:
                self.log.error("Advisory fetcher %s failed: %s", fetcher.__name__, exc)

    # ── UK FCDO (structured JSON API) ─────────────────────────────────────────
    def _fetch_uk_fcdo(self) -> Iterator[dict]:
        url = "https://www.gov.uk/api/content/foreign-travel-advice/kenya"
        self.log.info("Fetching UK FCDO advisory...")
        resp = self.get(url)
        if not resp:
            return

        try:
            data = resp.json()
        except Exception:
            self.log.warning("UK FCDO: non-JSON response")
            return

        updated = data.get("updated_at", datetime.now(timezone.utc).isoformat())
        title = "UK FCDO Travel Advisory: Kenya"
        
        # Extract body text from the parts
        parts = data.get("details", {}).get("parts", [])
        body_parts = []
        for part in parts:
            part_title = part.get("title", "")
            part_body = BeautifulSoup(
                part.get("body", ""), "lxml"
            ).get_text(" ", strip=True)
            if part_body:
                body_parts.append(f"## {part_title}\n{part_body}")
        
        content = "\n\n".join(body_parts)[:5000]
        
        # Extract the summary/overview
        description = data.get("description", "")

        yield {
            "url":          "https://www.gov.uk/foreign-travel-advice/kenya",
            "title":        title,
            "excerpt":      description,
            "content":      content,
            "published_at": updated,
            "source":       "UK FCDO",
        }

    # ── US State Department (RSS) ─────────────────────────────────────────────
    def _fetch_us_state_dept(self) -> Iterator[dict]:
        import feedparser
        
        self.log.info("Fetching US State Dept advisories...")
        feed_url = "https://travel.state.gov/_res/rss/TAs.xml"
        feed = feedparser.parse(feed_url)

        for entry in feed.entries:
            title = entry.get("title", "")
            # Filter for Kenya mentions
            summary = entry.get("summary", "")
            if "kenya" not in title.lower() and "kenya" not in summary.lower():
                continue

            url = entry.get("link", "")
            published = _parse_rss_date(entry)

            # Try to fetch full content
            content = ""
            resp = self.get(url)
            if resp:
                soup = BeautifulSoup(resp.text, "lxml")
                body = soup.select_one("div.tsg-rwd-content-page-passcontent")
                if body:
                    content = body.get_text(" ", strip=True)[:5000]

            yield {
                "url":          url,
                "title":        f"US State Dept: {title}",
                "excerpt":      BeautifulSoup(summary, "lxml").get_text(" ", strip=True),
                "content":      content,
                "published_at": published,
                "source":       "US State Dept",
            }

    # ── France MAE ────────────────────────────────────────────────────────────
    def _fetch_france_mae(self) -> Iterator[dict]:
        url = "https://www.diplomatie.gouv.fr/fr/conseils-aux-voyageurs/conseils-par-pays-destination/kenya/"
        self.log.info("Fetching France MAE advisory...")
        resp = self.get(url)
        if not resp:
            return

        soup = BeautifulSoup(resp.text, "lxml")
        
        title_tag = soup.find("h1")
        title = f"France MAE: {title_tag.get_text(strip=True)}" if title_tag else "France MAE Travel Advisory: Kenya"
        
        # Get the main advisory content
        content_el = soup.select_one("div.txt-cntnr") or soup.select_one("article") or soup.select_one("main")
        content = content_el.get_text(" ", strip=True)[:5000] if content_el else ""
        
        # Get meta description
        meta_desc = soup.find("meta", attrs={"name": "description"})
        excerpt = meta_desc["content"].strip() if meta_desc and meta_desc.get("content") else ""

        # Get last updated date
        date_el = soup.select_one("time") or soup.select_one(".date")
        published = date_el.get("datetime", "") if date_el else datetime.now(timezone.utc).isoformat()
        if not published:
            published = datetime.now(timezone.utc).isoformat()

        yield {
            "url":          url,
            "title":        title,
            "excerpt":      excerpt,
            "content":      content,
            "published_at": published,
            "source":       "France MAE",
        }

    # ── Canada Global Affairs ─────────────────────────────────────────────────
    def _fetch_canada(self) -> Iterator[dict]:
        url = "https://travel.gc.ca/destinations/kenya"
        self.log.info("Fetching Canada travel advisory...")
        resp = self.get(url)
        if not resp:
            return

        soup = BeautifulSoup(resp.text, "lxml")
        
        # Risk level
        risk_el = soup.select_one(".advisory-warning") or soup.select_one(".travel-advisory")
        risk_text = risk_el.get_text(" ", strip=True) if risk_el else ""
        
        title = f"Canada Travel Advisory: Kenya"
        if risk_text:
            title += f" — {risk_text[:80]}"
        
        content_el = soup.select_one("#main") or soup.select_one("main") or soup.select_one("article")
        content = content_el.get_text(" ", strip=True)[:5000] if content_el else ""

        meta_desc = soup.find("meta", attrs={"name": "description"})
        excerpt = meta_desc["content"].strip() if meta_desc and meta_desc.get("content") else ""

        yield {
            "url":          url,
            "title":        title,
            "excerpt":      excerpt,
            "content":      content,
            "published_at": datetime.now(timezone.utc).isoformat(),
            "source":       "Canada Global Affairs",
        }

    # ── Australia Smartraveller ───────────────────────────────────────────────
    def _fetch_australia(self) -> Iterator[dict]:
        url = "https://www.smartraveller.gov.au/destinations/africa/kenya"
        self.log.info("Fetching Australia Smartraveller advisory...")
        resp = self.get(url)
        if not resp:
            return

        soup = BeautifulSoup(resp.text, "lxml")
        
        # Advisory level
        level_el = soup.select_one(".overall-advice-level") or soup.select_one(".current-advice-level")
        level_text = level_el.get_text(" ", strip=True) if level_el else ""
        
        title = "Australia Smartraveller: Kenya"
        if level_text:
            title += f" — {level_text[:80]}"

        content_el = soup.select_one(".field--name-body") or soup.select_one("article") or soup.select_one("main")
        content = content_el.get_text(" ", strip=True)[:5000] if content_el else ""

        meta_desc = soup.find("meta", attrs={"name": "description"})
        excerpt = meta_desc["content"].strip() if meta_desc and meta_desc.get("content") else ""

        # Try to find last updated date
        date_el = soup.select_one(".date-display-single") or soup.select_one("time")
        published = date_el.get("datetime", "") if date_el else ""
        if not published:
            published = datetime.now(timezone.utc).isoformat()

        yield {
            "url":          url,
            "title":        title,
            "excerpt":      excerpt,
            "content":      content,
            "published_at": published,
            "source":       "Australia Smartraveller",
        }


# ── Helpers ───────────────────────────────────────────────────────────────────

def _parse_rss_date(entry) -> str:
    if hasattr(entry, "published_parsed") and entry.published_parsed:
        import calendar
        ts = calendar.timegm(entry.published_parsed)
        return datetime.fromtimestamp(ts, tz=timezone.utc).isoformat()
    return datetime.now(timezone.utc).isoformat()
