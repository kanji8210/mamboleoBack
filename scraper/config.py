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
# Source of truth for these values is the WordPress admin (Mamboleo → AI
# Intelligence). We fetch them at process start via the authenticated
# /mamboleo/v1/llm-config endpoint so the API key never has to live in
# scraper/.env. Local env vars still override (handy for CI / offline runs).
# Set OLLAMA_ENABLED=0 to disable the LLM entirely and fall back to the
# legacy keyword classifier.
def _fetch_remote_llm_config() -> dict:
    """Pull provider settings from WP. Cached for the life of the process.

    Returns {} if the endpoint is unreachable, the key is wrong, or the
    plugin is too old — callers fall back to env vars / defaults.
    """
    try:
        import requests  # local import to keep config import cheap
        url = f"{WP_API_BASE.rstrip('/')}/wp-json/mamboleo/v1/llm-config"
        resp = requests.get(url, headers={"X-API-Key": WP_API_KEY}, timeout=4)
        if resp.status_code == 200:
            return resp.json() or {}
    except Exception:  # noqa: BLE001 — config must never crash imports
        pass
    return {}


_REMOTE = _fetch_remote_llm_config()
_R_OLLAMA = _REMOTE.get("ollama") or {}
_R_OPENAI = _REMOTE.get("openai") or {}

LLM_PROVIDER   = (os.getenv("LLM_PROVIDER") or _REMOTE.get("provider") or "ollama").strip().lower()
OLLAMA_ENABLED = os.getenv("OLLAMA_ENABLED", "1") not in ("0", "false", "False", "")
OLLAMA_HOST    = (os.getenv("OLLAMA_HOST") or _R_OLLAMA.get("host") or "http://localhost:11434").rstrip("/")
OLLAMA_MODEL   = os.getenv("OLLAMA_MODEL") or _R_OLLAMA.get("model") or "llama3.1:8b"
OLLAMA_TIMEOUT = float(os.getenv("OLLAMA_TIMEOUT") or _R_OLLAMA.get("timeout") or 45)

# OpenAI-compatible provider settings come from WP admin only — the API key
# is intentionally NOT read from env so it can't leak via .env files or
# process listings. Endpoint/model can still be overridden for local testing.
OPENAI_BASE_URL = (os.getenv("OPENAI_BASE_URL") or _R_OPENAI.get("base_url") or "https://api.openai.com/v1").rstrip("/")
OPENAI_API_KEY  = _R_OPENAI.get("api_key") or ""
OPENAI_MODEL    = os.getenv("OPENAI_MODEL") or _R_OPENAI.get("model") or "gpt-4o-mini"
OPENAI_TIMEOUT  = float(_R_OPENAI.get("timeout") or 60)

# ── Social handles ─────────────────────────────────────────────────────────
# Optional self-hosted RSSHub bridge for Facebook pages. When unset, Facebook
# entries in social_sources.yaml are silently skipped.
# Example: RSSHUB_HOST=https://rsshub.mydomain.com
RSSHUB_HOST    = os.getenv("RSSHUB_HOST", "").rstrip("/")
