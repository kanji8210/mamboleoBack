"""Thin client for a local Ollama server (https://ollama.com).

Exposes a single helper, `chat_json()`, that sends a system+user prompt and
forces the model to return strict JSON (Ollama's `format: json` mode). We keep
this surface tiny on purpose so swapping providers later is a one-file change.
"""
from __future__ import annotations

import json
import logging
from typing import Any

import requests

from config import OLLAMA_HOST, OLLAMA_MODEL, OLLAMA_TIMEOUT

log = logging.getLogger("api.llm")


class LLMError(RuntimeError):
    """Raised when the local LLM is unreachable or returns malformed output."""


def chat_json(system: str, user: str, model: str | None = None) -> dict[str, Any]:
    """Send a chat request to Ollama and parse the response as JSON.

    Returns the parsed dict. Raises LLMError on transport failure, non-200
    response, empty body, or JSON parse failure — callers decide whether to
    fall back to the keyword classifier.
    """
    url     = f"{OLLAMA_HOST}/api/chat"
    payload = {
        "model":    model or OLLAMA_MODEL,
        "stream":   False,
        "format":   "json",
        "options":  {"temperature": 0.1, "num_ctx": 4096},
        "messages": [
            {"role": "system", "content": system},
            {"role": "user",   "content": user},
        ],
    }
    try:
        resp = requests.post(url, json=payload, timeout=OLLAMA_TIMEOUT)
    except requests.exceptions.RequestException as exc:
        raise LLMError(f"Ollama unreachable at {OLLAMA_HOST}: {exc}") from exc

    if resp.status_code != 200:
        raise LLMError(f"Ollama HTTP {resp.status_code}: {resp.text[:200]}")

    try:
        body = resp.json()
    except ValueError as exc:
        raise LLMError(f"Ollama returned non-JSON envelope: {resp.text[:200]}") from exc

    content = (body.get("message") or {}).get("content", "").strip()
    if not content:
        raise LLMError("Ollama returned empty content")

    try:
        return json.loads(content)
    except ValueError as exc:
        raise LLMError(f"Model output not valid JSON: {content[:300]}") from exc


def health_check() -> bool:
    """Return True iff the Ollama server is reachable. Logs failures at debug."""
    try:
        resp = requests.get(f"{OLLAMA_HOST}/api/tags", timeout=3)
        return resp.status_code == 200
    except requests.exceptions.RequestException as exc:
        log.debug("Ollama health check failed: %s", exc)
        return False
