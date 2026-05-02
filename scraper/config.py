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

# ── Intelligence layer (Ollama) ───────────────────────────────────────────────
# Local LLM-based incident analysis. Set OLLAMA_ENABLED=0 to fall back to the
# legacy keyword classifier (useful for offline runs / CI).
OLLAMA_ENABLED = os.getenv("OLLAMA_ENABLED", "1") not in ("0", "false", "False", "")
OLLAMA_HOST    = os.getenv("OLLAMA_HOST", "http://localhost:11434").rstrip("/")
OLLAMA_MODEL   = os.getenv("OLLAMA_MODEL", "llama3.1:8b")
OLLAMA_TIMEOUT = float(os.getenv("OLLAMA_TIMEOUT", "45"))
