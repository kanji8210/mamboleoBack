"""Base HTTP session with rate limiting and automatic retry."""
from __future__ import annotations

import logging
import time
from typing import Iterator

import requests
from requests.adapters import HTTPAdapter
from urllib3.util.retry import Retry

from config import REQUEST_DELAY, USER_AGENT


def _make_session() -> requests.Session:
    session = requests.Session()
    retry = Retry(
        total=3,
        backoff_factor=2,
        status_forcelist=[429, 500, 502, 503, 504],
        allowed_methods=["GET"],
        raise_on_status=False,
    )
    adapter = HTTPAdapter(max_retries=retry)
    session.mount("https://", adapter)
    session.mount("http://", adapter)
    session.headers.update(
        {
            "User-Agent": USER_AGENT,
            "Accept": "text/html,application/xhtml+xml;q=0.9,*/*;q=0.8",
            "Accept-Language": "en-US,en;q=0.5",
            "DNT": "1",
        }
    )
    return session


class BaseScraper:
    NAME: str = "base"

    def __init__(self) -> None:
        self.session = _make_session()
        self.log = logging.getLogger(f"scraper.{self.NAME}")
        self._last_request: float = 0.0

    # ── HTTP helpers ──────────────────────────────────────────────────────────

    def get(self, url: str, **kwargs) -> requests.Response | None:
        """Rate-limited GET. Returns None on any error."""
        elapsed = time.time() - self._last_request
        if elapsed < REQUEST_DELAY:
            time.sleep(REQUEST_DELAY - elapsed)
        try:
            resp = self.session.get(url, timeout=15, **kwargs)
            self._last_request = time.time()
            resp.raise_for_status()
            return resp
        except requests.exceptions.HTTPError as exc:
            self.log.warning("HTTP %s → %s", exc.response.status_code, url)
        except requests.exceptions.RequestException as exc:
            self.log.warning("Request failed → %s : %s", url, exc)
        return None

    # ── Interface ─────────────────────────────────────────────────────────────

    def fetch_articles(self, limit: int = 20) -> Iterator[dict]:
        """
        Yield article dicts:
          url, title, excerpt, content, published_at (ISO str), source (domain)
        """
        raise NotImplementedError
