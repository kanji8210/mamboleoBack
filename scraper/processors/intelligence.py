"""Intelligence layer — structured LLM-based incident analysis.

This module replaces the keyword engine in `classify.py` for the production
pipeline. A single Ollama call returns:

  * is_incident          — final yes/no on whether this is a real-world event
  * incident_type        — one of the 11 frontend-supported categories
  * severity             — low | medium | high (context-aware)
  * severity_reasoning   — short rationale, surfaced in the admin Review Queue
  * summary              — ≤200 char plain-English summary
  * location_hint        — narrative location text fed into the geocoder
  * is_followup          — True if the article updates a prior incident
  * confidence           — calibrated 0–1, replaces keyword score
  * flags                — editorial flags (e.g. single_source, historical)

If the LLM is unreachable or returns malformed output, the module falls back
to the legacy keyword classifier so the pipeline never grinds to a halt.
"""
from __future__ import annotations

import json
import logging
import time
from dataclasses import dataclass, field
from datetime import datetime, timezone

from api import llm_client
from config import (
    OLLAMA_ENABLED,
    OLLAMA_MODEL,
    LLM_PROVIDER,
    OPENAI_MODEL,
    LLM_MAX_ATTEMPTS,
    LLM_RETRY_BACKOFF,
)
from processors import classify as legacy

log = logging.getLogger("intelligence")

# ── Allowed enum values (kept in sync with frontend src/types/incident.ts) ────
INCIDENT_TYPES: tuple[str, ...] = (
    "fire", "accident", "flood", "protest", "police", "weather",
    "medical", "military", "info", "health", "environmental",
    "homicide", "femicide",
)
SEVERITIES: tuple[str, ...] = ("low", "medium", "high")

# Articles longer than this are truncated before being sent to the model.
# Ollama with llama3.1:8b has a 4K context window (num_ctx=4096) so we keep
# the body short. Cloud LLMs (Groq, OpenAI, etc.) have 8K–128K context
# windows, so we can afford to send more signal.
_MAX_BODY_CHARS = 3500 if LLM_PROVIDER == "ollama" else 8000


# ── Public dataclass ──────────────────────────────────────────────────────────

@dataclass
class Intelligence:
    """Structured incident analysis returned to the pipeline."""
    is_incident:        bool
    incident_type:      str               # one of INCIDENT_TYPES
    severity:           str               # one of SEVERITIES
    severity_reasoning: str = ""
    summary:            str = ""
    location_hint:      str = ""
    is_followup:        bool = False
    confidence:         float = 0.0       # 0.0–1.0
    flags:              list[str] = field(default_factory=list)
    model:              str = ""          # which model produced this
    used_fallback:      bool = False      # True when keyword engine was used

    def to_payload(self) -> dict:
        """Subset of fields posted to the /incidents REST endpoint."""
        return {
            "ai_summary":            self.summary[:500],
            "ai_severity_reasoning": self.severity_reasoning[:500],
            "ai_flags":              ",".join(self.flags)[:300],
            "ai_model":              self.model,
            "ai_is_followup":        self.is_followup,
            "ai_processed_at":       datetime.now(timezone.utc).isoformat(timespec="seconds"),
        }


# ── Prompt construction ───────────────────────────────────────────────────────

_SYSTEM_PROMPT = (
    "You are an editorial assistant for Mamboleo, a Kenyan public-safety "
    "incident map. You analyze news articles and decide whether each one "
    "describes a real-world incident the public should be aware of "
    "(disaster, crime, accident, protest, outbreak, etc.). You are strict: "
    "speeches, policy launches, sports, religious ceremonies, opinion "
    "columns, and corporate announcements are NOT incidents. Output ONLY "
    "valid JSON matching the schema the user provides. No prose, no markdown."
)

_USER_TEMPLATE = """\
Analyse the article below and return JSON with exactly these keys:

{{
  "is_incident":        boolean,
  "incident_type":      one of {types},
  "severity":           one of ["low","medium","high"],
  "severity_reasoning": short string (≤200 chars) explaining the severity choice,
  "summary":            short plain-English summary (≤200 chars),
    "location_hint":      concise place string (city/state/country/landmark) anywhere in the world, or "" if none,
  "is_followup":        true if this article updates an earlier event,
  "confidence":         number 0–1 (your certainty this is a real incident),
  "flags":              array of strings, any of [
                          "single_source", "unverified", "historical",
                          "speculative", "rumor", "graphic", "minor"
                        ]
}}

Rules:
- If is_incident is false, set incident_type to "info", severity to "low",
  and confidence to your certainty that it is NOT an incident.
- Pick the SINGLE best incident_type even if multiple are mentioned.
- Severity "high" requires reported deaths / mass casualties / major
  displacement. "medium" for injuries, evacuations, significant damage.
  "low" for minor incidents or threats without confirmed harm.
- Use location_hint to return the best mappable place string from the story:
    city, county, state, province, district, landmark, or country.
- Do NOT restrict location_hint to Kenya. If the story is in Texas, return
    "Texas" or a more specific city such as "Fort Worth, Texas" when present.
- Keep location_hint concise and geocodable. Prefer just the place name over
    prose such as "at her Texas home".
- Output ONLY the JSON object. No markdown fences, no commentary.

ARTICLE TITLE:
{title}

ARTICLE BODY:
{body}
"""


def _build_user_prompt(title: str, body: str) -> str:
    return _USER_TEMPLATE.format(
        types=list(INCIDENT_TYPES),
        title=title.strip(),
        body=(body or "").strip()[:_MAX_BODY_CHARS] or "(no body)",
    )


# ── Coercion / validation ─────────────────────────────────────────────────────

# Resolve model name once — reflects the *actual* provider, not always Ollama.
_ACTIVE_MODEL = OPENAI_MODEL if LLM_PROVIDER == "openai" else OLLAMA_MODEL

def _coerce(raw: dict, fallback_title: str) -> Intelligence:
    """Validate and normalise the raw JSON dict from the model."""
    incident_type = str(raw.get("incident_type", "info")).lower().strip()
    if incident_type not in INCIDENT_TYPES:
        incident_type = "info"

    severity = str(raw.get("severity", "low")).lower().strip()
    if severity not in SEVERITIES:
        severity = "low"

    try:
        confidence = float(raw.get("confidence", 0.0))
    except (TypeError, ValueError):
        confidence = 0.0
    confidence = max(0.0, min(1.0, confidence))

    flags_raw = raw.get("flags") or []
    if isinstance(flags_raw, str):
        flags_raw = [flags_raw]
    flags = [str(f).lower().strip() for f in flags_raw if str(f).strip()][:8]

    return Intelligence(
        is_incident        = bool(raw.get("is_incident", False)),
        incident_type      = incident_type,
        severity           = severity,
        severity_reasoning = str(raw.get("severity_reasoning", ""))[:500],
        summary            = (str(raw.get("summary", "")) or fallback_title)[:500],
        location_hint      = str(raw.get("location_hint", ""))[:300],
        is_followup        = bool(raw.get("is_followup", False)),
        confidence         = round(confidence, 2),
        flags              = flags,
        model              = _ACTIVE_MODEL,
    )


def _from_legacy(title: str, body: str) -> Intelligence:
    """Wrap the legacy keyword classifier in an Intelligence record."""
    cls = legacy.classify(title, body)
    if cls is None:
        return Intelligence(
            is_incident   = False,
            incident_type = "info",
            severity      = "low",
            confidence    = 0.0,
            summary       = title[:200],
            model         = "keyword-fallback",
            used_fallback = True,
            flags         = ["llm_unavailable"],
        )
    return Intelligence(
        is_incident   = True,
        incident_type = cls.incident_type,
        severity      = cls.severity,
        confidence    = cls.confidence,
        summary       = title[:200],
        model         = "keyword-fallback",
        used_fallback = True,
        flags         = ["llm_unavailable"],
    )


# ── Public API ────────────────────────────────────────────────────────────────

def analyze(title: str, body: str = "") -> Intelligence:
    """Analyse an article and return structured incident intelligence.

    Falls back to the legacy keyword classifier if the LLM is disabled,
    unreachable, or returns malformed output. One retry with backoff is
    attempted before giving up — prevents transient 429 / timeout from
    silently degrading to the keyword classifier.
    """
    if not OLLAMA_ENABLED:
        return _from_legacy(title, body)

    prompt = _build_user_prompt(title, body)
    last_exc: Exception | None = None

    for attempt in range(LLM_MAX_ATTEMPTS):
        try:
            raw = llm_client.chat_json(
                system=_SYSTEM_PROMPT,
                user=prompt,
            )
            break
        except llm_client.LLMError as exc:
            last_exc = exc
            if attempt + 1 < LLM_MAX_ATTEMPTS:
                log.info(
                    "LLM attempt %d/%d failed (%s) — retrying in %.1fs",
                    attempt + 1,
                    LLM_MAX_ATTEMPTS,
                    exc,
                    LLM_RETRY_BACKOFF,
                )
                time.sleep(LLM_RETRY_BACKOFF)
            else:
                log.warning(
                    "LLM unavailable after %d attempt(s) (%s) — falling back to keyword classifier",
                    LLM_MAX_ATTEMPTS,
                    exc,
                )
                return _from_legacy(title, body)

    try:
        return _coerce(raw, fallback_title=title)
    except (TypeError, ValueError, KeyError) as exc:
        log.warning("LLM returned unparseable JSON (%s): %s — falling back", exc, json.dumps(raw)[:300])
        return _from_legacy(title, body)
