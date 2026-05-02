"""Provider-agnostic JSON-mode LLM client.

Two providers are supported, selected via `LLM_PROVIDER` in `.env`:

    "ollama"  — local Ollama server (default, fully offline). Free, private,
                requires a model pulled locally (e.g. `ollama pull llama3.1:8b`).
    "openai"  — any OpenAI Chat-Completions-compatible endpoint
                (OpenAI, Groq, Together.ai, OpenRouter, Mistral, LM Studio).
                Fast cloud option; Groq has a generous free tier.

`chat_json()` always returns a parsed dict and raises `LLMError` on transport
failure, non-200 response, empty body, or JSON parse failure. Callers decide
whether to fall back to the keyword classifier.
"""
from __future__ import annotations

import json
import logging
from typing import Any

import requests

from config import (
    LLM_PROVIDER,
    OLLAMA_HOST, OLLAMA_MODEL, OLLAMA_TIMEOUT,
    OPENAI_API_KEY, OPENAI_BASE_URL, OPENAI_MODEL, OPENAI_TIMEOUT,
)

log = logging.getLogger("api.llm")


class LLMError(RuntimeError):
    """Raised when the LLM provider is unreachable or returns malformed output."""


# ── Public API ────────────────────────────────────────────────────────────────

def chat_json(system: str, user: str, model: str | None = None) -> dict[str, Any]:
    """Send a chat request to the active provider and parse the response as JSON."""
    if LLM_PROVIDER == "openai":
        return _chat_openai(system, user, model)
    return _chat_ollama(system, user, model)


def health_check() -> bool:
    """Return True iff the active provider is reachable."""
    if LLM_PROVIDER == "openai":
        return _health_openai()
    return _health_ollama()


def provider_info() -> dict[str, Any]:
    """Diagnostic helper for admin UIs."""
    if LLM_PROVIDER == "openai":
        return {
            "provider": "openai",
            "endpoint": OPENAI_BASE_URL,
            "model":    OPENAI_MODEL,
            "has_key":  bool(OPENAI_API_KEY),
        }
    return {
        "provider": "ollama",
        "endpoint": OLLAMA_HOST,
        "model":    OLLAMA_MODEL,
        "has_key":  True,
    }


# ── Ollama backend ────────────────────────────────────────────────────────────

def _chat_ollama(system: str, user: str, model: str | None) -> dict[str, Any]:
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

    return _parse_json_loose(content)


def _health_ollama() -> bool:
    try:
        resp = requests.get(f"{OLLAMA_HOST}/api/tags", timeout=3)
        return resp.status_code == 200
    except requests.exceptions.RequestException as exc:
        log.debug("Ollama health check failed: %s", exc)
        return False


# ── OpenAI-compatible backend ─────────────────────────────────────────────────

def _chat_openai(system: str, user: str, model: str | None) -> dict[str, Any]:
    if not OPENAI_API_KEY and "localhost" not in OPENAI_BASE_URL and "127.0.0.1" not in OPENAI_BASE_URL:
        raise LLMError(
            "LLM API key is not configured. Set it in WP admin → Mamboleo → "
            "AI Intelligence (or switch LLM_PROVIDER to ollama for local runs)."
        )

    url     = f"{OPENAI_BASE_URL}/chat/completions"
    payload = {
        "model":           model or OPENAI_MODEL,
        "temperature":     0.1,
        "response_format": {"type": "json_object"},
        "messages": [
            {"role": "system", "content": system},
            {"role": "user",   "content": user},
        ],
    }
    headers = {"Content-Type": "application/json"}
    if OPENAI_API_KEY:
        headers["Authorization"] = f"Bearer {OPENAI_API_KEY}"

    try:
        resp = requests.post(url, json=payload, headers=headers, timeout=OPENAI_TIMEOUT)
    except requests.exceptions.RequestException as exc:
        raise LLMError(f"OpenAI-compatible endpoint unreachable at {OPENAI_BASE_URL}: {exc}") from exc

    # Some providers (e.g. Together) reject `response_format` — retry without it.
    if resp.status_code == 400 and "response_format" in resp.text:
        payload.pop("response_format", None)
        # Force JSON via the system prompt instead.
        payload["messages"][0]["content"] = (
            system.rstrip() + "\n\nRespond with ONLY a valid JSON object — no prose, no markdown fences."
        )
        try:
            resp = requests.post(url, json=payload, headers=headers, timeout=OPENAI_TIMEOUT)
        except requests.exceptions.RequestException as exc:
            raise LLMError(f"OpenAI-compatible endpoint unreachable at {OPENAI_BASE_URL}: {exc}") from exc

    if resp.status_code != 200:
        raise LLMError(f"LLM HTTP {resp.status_code}: {resp.text[:300]}")

    try:
        body = resp.json()
    except ValueError as exc:
        raise LLMError(f"LLM returned non-JSON envelope: {resp.text[:200]}") from exc

    choices = body.get("choices") or []
    if not choices:
        raise LLMError(f"LLM returned no choices: {body}")
    content = (choices[0].get("message") or {}).get("content", "").strip()
    if not content:
        raise LLMError("LLM returned empty content")

    return _parse_json_loose(content)


def _health_openai() -> bool:
    if not OPENAI_API_KEY and "localhost" not in OPENAI_BASE_URL and "127.0.0.1" not in OPENAI_BASE_URL:
        return False
    headers = {"Authorization": f"Bearer {OPENAI_API_KEY}"} if OPENAI_API_KEY else {}
    try:
        resp = requests.get(f"{OPENAI_BASE_URL}/models", headers=headers, timeout=4)
        # Some providers return 401/403 even when reachable — treat <500 as up.
        return resp.status_code < 500
    except requests.exceptions.RequestException as exc:
        log.debug("OpenAI health check failed: %s", exc)
        return False


# ── Shared helpers ────────────────────────────────────────────────────────────

def _parse_json_loose(content: str) -> dict[str, Any]:
    """Parse JSON tolerantly — strips ``` fences and leading prose if present."""
    txt = content.strip()
    # Strip ```json … ``` fences a chatty model may add.
    if txt.startswith("```"):
        txt = txt.strip("`")
        if txt.lower().startswith("json"):
            txt = txt[4:]
        txt = txt.strip()
    # Find the first { … last } block in case the model added prose.
    if not txt.startswith("{"):
        start = txt.find("{")
        end   = txt.rfind("}")
        if start != -1 and end != -1 and end > start:
            txt = txt[start : end + 1]
    try:
        return json.loads(txt)
    except ValueError as exc:
        raise LLMError(f"Model output not valid JSON: {content[:300]}") from exc

