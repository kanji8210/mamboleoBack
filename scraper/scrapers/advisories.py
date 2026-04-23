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
            self._fetch_germany,
            self._fetch_japan,
            self._fetch_ireland,
            self._fetch_new_zealand,
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

    # ── US State Department ───────────────────────────────────────────────────
    # Strategy: fetch the canonical Kenya page directly (always available,
    # reflects current level) AND scan the RSS feed for recent reissues.
    # Both may yield the same advisory under different URLs; the WordPress
    # side dedupes by title + article_url.
    def _fetch_us_state_dept(self) -> Iterator[dict]:
        # ── 1. Canonical Kenya advisory page (primary) ───────────────────────
        canonical_url = (
            "https://travel.state.gov/content/travel/en/traveladvisories/"
            "traveladvisories/kenya-travel-advisory.html"
        )
        self.log.info("Fetching US State Dept canonical Kenya advisory...")
        resp = self.get(canonical_url)
        if resp:
            soup = BeautifulSoup(resp.text, "lxml")

            # Advisory level: badge near top, e.g. "Level 2: Exercise Increased Caution"
            level_el = (
                soup.select_one(".tsg-rwd-ta-level")
                or soup.select_one(".tsg-rwd-emergency-alert-level")
                or soup.find(attrs={"class": re.compile(r"level", re.I)})
            )
            level_text = level_el.get_text(" ", strip=True) if level_el else ""

            # Main body
            body_el = (
                soup.select_one("div.tsg-rwd-content-page-passcontent")
                or soup.select_one("article")
                or soup.select_one("main")
            )
            content = body_el.get_text(" ", strip=True)[:5000] if body_el else ""

            # Meta description for excerpt
            meta_desc = soup.find("meta", attrs={"name": "description"})
            excerpt = meta_desc["content"].strip() if meta_desc and meta_desc.get("content") else ""

            # Last-updated date — State Dept prints "Reissued with updates …" or "Updated …"
            published = datetime.now(timezone.utc).isoformat()
            if content:
                m = re.search(
                    r"(?:Reissued|Updated|Issued)(?:\s+with\s+[\w\s]+)?[:\s]+"
                    r"([A-Z][a-z]+\s+\d{1,2},\s*\d{4})",
                    content,
                )
                if m:
                    try:
                        dt = datetime.strptime(m.group(1), "%B %d, %Y").replace(tzinfo=timezone.utc)
                        published = dt.isoformat()
                    except ValueError:
                        pass

            title = "US State Dept: Kenya Travel Advisory"
            if level_text:
                title += f" — {level_text[:80]}"

            yield {
                "url":          canonical_url,
                "title":        title,
                "excerpt":      excerpt or (content[:280] if content else ""),
                "content":      content,
                "published_at": published,
                "source":       "US State Dept",
            }
        else:
            self.log.warning("US State Dept canonical page unreachable, falling back to RSS only")

        # ── 2. RSS feed (supplementary — catches reissue announcements) ──────
        try:
            import feedparser
        except ImportError:
            self.log.debug("feedparser not installed, skipping State Dept RSS")
            return

        feed_url = "https://travel.state.gov/_res/rss/TAs.xml"
        feed = feedparser.parse(feed_url)
        for entry in feed.entries:
            title = entry.get("title", "")
            summary = entry.get("summary", "")
            if "kenya" not in title.lower() and "kenya" not in summary.lower():
                continue

            url = entry.get("link", "")
            # Skip if RSS just points back to the canonical page — already yielded above.
            if url == canonical_url:
                continue

            published = _parse_rss_date(entry)
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

    # ── Germany (Auswärtiges Amt) ─────────────────────────────────────────────
    def _fetch_germany(self) -> Iterator[dict]:
        # English-language Kenya sicherheit / safety page.
        url = (
            "https://www.auswaertiges-amt.de/en/ReiseUndSicherheit/"
            "kenyasicherheit/203136"
        )
        self.log.info("Fetching Germany Auswärtiges Amt advisory...")
        resp = self.get(url)
        if not resp:
            return

        soup = BeautifulSoup(resp.text, "lxml")

        # Pull the hazard banner near the top (e.g. "Travel warning",
        # "Partial travel warning", "Exercise increased caution").
        banner = (
            soup.select_one(".c-teaser__title")
            or soup.select_one(".hint")
            or soup.select_one("h2")
        )
        banner_text = banner.get_text(" ", strip=True) if banner else ""

        body_el = (
            soup.select_one(".c-richtext")
            or soup.select_one("main")
            or soup.select_one("article")
        )
        content = body_el.get_text(" ", strip=True)[:5000] if body_el else ""

        meta_desc = soup.find("meta", attrs={"name": "description"})
        excerpt = meta_desc["content"].strip() if meta_desc and meta_desc.get("content") else ""

        # Published / last updated.
        published = datetime.now(timezone.utc).isoformat()
        if content:
            m = re.search(r"Last updated[:\s]+(\d{1,2}\.\d{1,2}\.\d{4})", content)
            if m:
                try:
                    dt = datetime.strptime(m.group(1), "%d.%m.%Y").replace(tzinfo=timezone.utc)
                    published = dt.isoformat()
                except ValueError:
                    pass

        title = "Germany Auswärtiges Amt: Kenya Travel & Safety"
        if banner_text and len(banner_text) < 120:
            title += f" — {banner_text}"

        yield {
            "url":          url,
            "title":        title,
            "excerpt":      excerpt or (content[:280] if content else ""),
            "content":      content,
            "published_at": published,
            "source":       "Germany Auswärtiges Amt",
        }

    # ── Japan (MOFA) ──────────────────────────────────────────────────────────
    def _fetch_japan(self) -> Iterator[dict]:
        # MOFA's Overseas Safety Info page for Kenya (Japanese, but the risk
        # level and dates are machine-readable; title is always consistent).
        url = "https://www.anzen.mofa.go.jp/info/pcinfectionspothazardinfo_028.html"
        self.log.info("Fetching Japan MOFA advisory...")
        resp = self.get(url)
        if not resp:
            return

        soup = BeautifulSoup(resp.text, "lxml")

        # Overall risk level badge — look for 危険レベル followed by number 1-4.
        raw_text = soup.get_text(" ", strip=True) if soup else ""
        level_match = re.search(r"(レベル\s*[1-4])", raw_text)
        level = level_match.group(1) if level_match else ""

        body_el = soup.select_one("#main") or soup.select_one("main") or soup.select_one("article")
        content = body_el.get_text(" ", strip=True)[:5000] if body_el else ""

        title = "Japan MOFA: Kenya Overseas Safety Info"
        if level:
            title += f" — {level}"

        yield {
            "url":          url,
            "title":        title,
            "excerpt":      f"Japan Ministry of Foreign Affairs safety information for Kenya. {level}".strip(),
            "content":      content,
            "published_at": datetime.now(timezone.utc).isoformat(),
            "source":       "Japan MOFA",
        }

    # ── Ireland (DFA) ─────────────────────────────────────────────────────────
    def _fetch_ireland(self) -> Iterator[dict]:
        url = "https://www.dfa.ie/travel/travel-advice/a-z-list-of-countries/kenya/"
        self.log.info("Fetching Ireland DFA advisory...")
        resp = self.get(url)
        if not resp:
            return

        soup = BeautifulSoup(resp.text, "lxml")

        # Irish DFA uses a color-coded banner ("High Degree of Caution",
        # "Avoid Non-Essential Travel", etc).
        level_el = (
            soup.select_one(".travel-advice-banner")
            or soup.select_one(".status-badge")
            or soup.find(class_=re.compile(r"security[-_]status", re.I))
        )
        level_text = level_el.get_text(" ", strip=True) if level_el else ""

        body_el = (
            soup.select_one(".entry-content")
            or soup.select_one("main")
            or soup.select_one("article")
        )
        content = body_el.get_text(" ", strip=True)[:5000] if body_el else ""

        meta_desc = soup.find("meta", attrs={"name": "description"})
        excerpt = meta_desc["content"].strip() if meta_desc and meta_desc.get("content") else ""

        title = "Ireland DFA: Kenya Travel Advice"
        if level_text and len(level_text) < 120:
            title += f" — {level_text}"

        yield {
            "url":          url,
            "title":        title,
            "excerpt":      excerpt or (content[:280] if content else ""),
            "content":      content,
            "published_at": datetime.now(timezone.utc).isoformat(),
            "source":       "Ireland DFA",
        }

    # ── New Zealand (SafeTravel) ──────────────────────────────────────────────
    def _fetch_new_zealand(self) -> Iterator[dict]:
        url = "https://www.safetravel.govt.nz/kenya"
        self.log.info("Fetching New Zealand SafeTravel advisory...")
        resp = self.get(url)
        if not resp:
            return

        soup = BeautifulSoup(resp.text, "lxml")

        level_el = (
            soup.select_one(".advisory-level")
            or soup.select_one(".current-level")
            or soup.find(class_=re.compile(r"risk[-_]level", re.I))
        )
        level_text = level_el.get_text(" ", strip=True) if level_el else ""

        body_el = soup.select_one("main") or soup.select_one("article")
        content = body_el.get_text(" ", strip=True)[:5000] if body_el else ""

        meta_desc = soup.find("meta", attrs={"name": "description"})
        excerpt = meta_desc["content"].strip() if meta_desc and meta_desc.get("content") else ""

        title = "New Zealand SafeTravel: Kenya"
        if level_text and len(level_text) < 120:
            title += f" — {level_text}"

        yield {
            "url":          url,
            "title":        title,
            "excerpt":      excerpt or (content[:280] if content else ""),
            "content":      content,
            "published_at": datetime.now(timezone.utc).isoformat(),
            "source":       "New Zealand SafeTravel",
        }


# ── Helpers ───────────────────────────────────────────────────────────────────

def _parse_rss_date(entry) -> str:
    if hasattr(entry, "published_parsed") and entry.published_parsed:
        import calendar
        ts = calendar.timegm(entry.published_parsed)
        return datetime.fromtimestamp(ts, tz=timezone.utc).isoformat()
    return datetime.now(timezone.utc).isoformat()
