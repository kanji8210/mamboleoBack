"""Central configuration — reads from scraper/.env."""
from __future__ import annotations

import os
from pathlib import Path

from dotenv import load_dotenv

ROOT_DIR = Path(__file__).parent
DATA_DIR = ROOT_DIR / "data"
DATA_DIR.mkdir(exist_ok=True)

load_dotenv(ROOT_DIR / ".env")

WP_API_BASE    = os.getenv("MAMBOLEO_WP_URL",  "http://localhost/wordpress")
WP_API_KEY     = os.getenv("MAMBOLEO_API_KEY", "mamboleo-dev-key-change-in-production")
TWITTER_BEARER = os.getenv("TWITTER_BEARER_TOKEN", "")

REQUEST_DELAY = float(os.getenv("SCRAPER_DELAY", "2.5"))
MAX_ARTICLES  = int(os.getenv("MAX_ARTICLES_PER_RUN", "30"))
DB_PATH       = DATA_DIR / "seen.db"

USER_AGENT = "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36"

# ── Intelligence layer (LLM) ──────────────────────────────────────────────────
# LLM-based incident analysis. Two providers are supported out of the box:
#   • "ollama"  — local Ollama server (default, fully offline)
#   • "openai"  — any OpenAI Chat-Completions-compatible endpoint
#                 (OpenAI, Groq, Together.ai, OpenRouter, Mistral, LM Studio…)
#
# **Source of truth is the WordPress admin** (Mamboleo → AI Intelligence).
# Provider, endpoint, model, and API key are fetched at process start via
# the authenticated /mamboleo/v1/llm-config endpoint and cached on disk so a
# transient WAF block doesn't downgrade the run. Nothing related to the LLM
# provider lives in scraper/.env any more — keeping secrets out of the
# filesystem is intentional.
#
# Set OLLAMA_ENABLED=0 in .env to disable the LLM entirely and fall back to
# the legacy keyword classifier.
def _fetch_remote_llm_config() -> tuple[dict, str]:
    """Pull provider settings from WP. Cached for the life of the process.

    Cached on disk too — if Cloudflare/WAF blocks a request once, we keep
    using the last good value rather than silently falling back to Ollama
    defaults (which would show up as "Connection refused" much later).

    Returns (config, source) where source is one of:
      "wp"     — fresh fetch from WP succeeded
      "cache"  — WP fetch failed but we had a previous good config on disk
      "none"   — never fetched, no cache, running on hard-coded defaults
    """
    cache_file = DATA_DIR / "llm_config.json"
    try:
        import json
        # Use stdlib urllib (not requests) — Cloudflare's bot management
        # fingerprints urllib3's TLS/cipher order and returns 403 even when
        # headers look browser-like. Stdlib urllib uses Python's default SSL
        # context which gets through with the headers below.
        import urllib.request
        url = f"{WP_API_BASE.rstrip('/')}/wp-json/mamboleo/v1/llm-config"
        req = urllib.request.Request(
            url,
            headers={
                "X-API-Key":      WP_API_KEY,
                "User-Agent":     USER_AGENT,
                "Accept":         "application/json",
                "Accept-Language": "en-US,en;q=0.9",
            },
        )
        with urllib.request.urlopen(req, timeout=8) as resp:
            status = resp.status
            ctype = resp.headers.get("Content-Type", "")
            body = resp.read()
        if status == 200:
            if "json" in ctype:
                cfg = json.loads(body.decode("utf-8") or "{}") or {}
                try:
                    cache_file.write_text(json.dumps(cfg))
                except Exception:  # noqa: BLE001
                    pass
                return cfg, "wp"
            # 200 OK but HTML body = WAF challenge page (Cloudflare etc.)
            print(
                f"[config] /llm-config returned non-JSON ({ctype}); "
                f"likely WAF challenge on {url}; using cached/fallback values."
            )
        else:
            print(f"[config] /llm-config HTTP {status}; using cached/fallback values.")
    except Exception as exc:  # noqa: BLE001 — config must never crash imports
        print(f"[config] /llm-config unreachable ({type(exc).__name__}: {exc}); using cached/fallback values.")

    # Fall back to disk cache so a transient WAF block doesn't downgrade us
    # to Ollama (which then fails with "Connection refused" 30s into a run).
    try:
        import json
        if cache_file.exists():
            return (json.loads(cache_file.read_text()) or {}), "cache"
    except Exception:  # noqa: BLE001
        pass
    return {}, "none"


_REMOTE, LLM_CONFIG_SOURCE = _fetch_remote_llm_config()
_R_OLLAMA = _REMOTE.get("ollama") or {}
_R_OPENAI = _REMOTE.get("openai") or {}

# Provider + model + key all come from WP admin. .env is no longer consulted
# for any LLM credential — the only env knobs are debug toggles below.
LLM_PROVIDER   = (_REMOTE.get("provider") or "ollama").strip().lower()
OLLAMA_ENABLED = os.getenv("OLLAMA_ENABLED", "1") not in ("0", "false", "False", "")

# When set to 1/true, every scraped article is sent to the LLM intelligence
# layer (no keyword pre-filter). Useful when the keyword gate is suspected
# of dropping real incidents written in narrative prose. Costs more LLM calls.
LLM_ALL_ARTICLES = os.getenv("LLM_ALL_ARTICLES", "0") not in ("0", "false", "False", "")

OLLAMA_HOST    = (_R_OLLAMA.get("host") or "http://localhost:11434").rstrip("/")
OLLAMA_MODEL   = _R_OLLAMA.get("model") or "llama3.1:8b"
OLLAMA_TIMEOUT = float(_R_OLLAMA.get("timeout") or 45)

# OpenAI-compatible provider settings (Groq, OpenAI, Together, OpenRouter…).
# Sourced exclusively from WP admin so the API key cannot leak via .env or
# process listings. If /llm-config is unreachable AND the disk cache is
# empty, OPENAI_API_KEY is "" and llm_client.chat_json() raises a clear
# error — the pipeline then falls back to the keyword classifier.
OPENAI_BASE_URL = (_R_OPENAI.get("base_url") or "https://api.openai.com/v1").rstrip("/")
OPENAI_API_KEY  = _R_OPENAI.get("api_key") or ""
OPENAI_MODEL    = _R_OPENAI.get("model") or "gpt-4o-mini"
OPENAI_TIMEOUT  = float(_R_OPENAI.get("timeout") or 60)

# ── Social handles ─────────────────────────────────────────────────────────
# Optional self-hosted RSSHub bridge for Facebook pages. When unset, Facebook
# entries in social_sources.yaml are silently skipped.
# Example: RSSHUB_HOST=https://rsshub.mydomain.com
RSSHUB_HOST    = os.getenv("RSSHUB_HOST", "").rstrip("/")
