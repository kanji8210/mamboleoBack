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
# Set OLLAMA_ENABLED=0 to disable the LLM entirely and fall back to the
# legacy keyword classifier (useful for offline / CI runs).
LLM_PROVIDER   = os.getenv("LLM_PROVIDER", "ollama").strip().lower()
OLLAMA_ENABLED = os.getenv("OLLAMA_ENABLED", "1") not in ("0", "false", "False", "")
OLLAMA_HOST    = os.getenv("OLLAMA_HOST", "http://localhost:11434").rstrip("/")
OLLAMA_MODEL   = os.getenv("OLLAMA_MODEL", "llama3.1:8b")
OLLAMA_TIMEOUT = float(os.getenv("OLLAMA_TIMEOUT", "45"))

# OpenAI-compatible provider (Groq is the recommended free tier — fast & free).
# Common OPENAI_BASE_URL values:
#   OpenAI       : https://api.openai.com/v1
#   Groq         : https://api.groq.com/openai/v1   (free, fast)
#   Together.ai  : https://api.together.xyz/v1
#   OpenRouter   : https://openrouter.ai/api/v1
#   LM Studio    : http://localhost:1234/v1         (no key needed)
OPENAI_BASE_URL = os.getenv("OPENAI_BASE_URL", "https://api.openai.com/v1").rstrip("/")
OPENAI_API_KEY  = os.getenv("OPENAI_API_KEY", "")
OPENAI_MODEL    = os.getenv("OPENAI_MODEL", "gpt-4o-mini")
OPENAI_TIMEOUT  = float(os.getenv("OPENAI_TIMEOUT", "60"))

# ── Social handles ─────────────────────────────────────────────────────────
# Optional self-hosted RSSHub bridge for Facebook pages. When unset, Facebook
# entries in social_sources.yaml are silently skipped.
# Example: RSSHUB_HOST=https://rsshub.mydomain.com
RSSHUB_HOST    = os.getenv("RSSHUB_HOST", "").rstrip("/")
