"""Article-level NLP enrichment for the Media Monitor pipeline.

Runs on EVERY article (unlike classify.py which looks only for incidents).
Produces:
    sentiment        : "positive" | "neutral" | "negative"
    sentiment_score  : float in [-1, 1]
    bias_score       : int  in [-100, 100]  (negative = left, positive = right)
    entities         : {"persons": [...], "orgs": [...], "places": [...]}
    topics           : list[str]  from a fixed taxonomy
    keywords         : list[str]  top 5 content nouns

spaCy model is loaded lazily — first call downloads/loads, subsequent
calls reuse. If spaCy fails to import (fresh venv without model),
entity/keyword extraction falls back to regex heuristics so the pipeline
never blocks.
"""
from __future__ import annotations

import logging
import re
from collections import Counter
from dataclasses import dataclass, field
from functools import lru_cache

log = logging.getLogger("analyze")

# ── Topic taxonomy ────────────────────────────────────────────────────────────
# Keyword buckets. An article can belong to multiple topics.
TOPIC_LEXICON: dict[str, list[str]] = {
    "politics":    ["president", "parliament", "senator", "mp ", "cabinet",
                    "election", "azimio", "kenya kwanza", "opposition",
                    "ruto", "raila", "gachagua", "kindiki"],
    "security":    ["police", "terror", "attack", "killed", "shooting",
                    "bandits", "al-shabaab", "kdf", "military", "armed",
                    "insecurity", "robbery", "gang"],
    "economy":     ["shilling", "inflation", "budget", "tax", "cbk",
                    "treasury", "gdp", "economy", "kra", "finance bill",
                    "debt", "imf", "world bank"],
    "health":      ["hospital", "disease", "cholera", "covid", "vaccin",
                    "doctors", "ministry of health", "outbreak", "malaria"],
    "education":   ["school", "university", "cbc", "knec", "kcse", "kcpe",
                    "students", "teachers", "tsc"],
    "environment": ["drought", "flood", "climate", "rain", "kws", "wildlife",
                    "forest", "pollution", "nema"],
    "sports":      ["harambee stars", "kpl", "olympic", "athlet", "football",
                    "rugby", "marathon"],
    "business":    ["safaricom", "kcb", "equity bank", "ipo", "nse", "stock",
                    "company", "ceo", "profit", "revenue"],
    "tech":        ["startup", "fintech", "mpesa", "ai ", "digital",
                    "cybersecurity", "data", "app launch"],
    "regional":    ["uganda", "tanzania", "rwanda", "ethiopia", "somalia",
                    "south sudan", "east africa", "eac"],
}

# ── Bias lexicon ──────────────────────────────────────────────────────────────
# Word choices that consistently correlate with editorial lean.
# Drift is measured per-article; the delta is added to the outlet's baseline.
_LEFT_TERMS = [
    "regime", "crackdown", "oppression", "marginalised", "marginalized",
    "inequality", "workers rights", "austerity", "neoliberal", "impunity",
    "human rights abuse", "climate crisis",
]
_RIGHT_TERMS = [
    "administration", "law and order", "free market", "sovereignty",
    "traditional values", "illegal immigrant", "radical", "lawful",
    "national security", "fiscal discipline", "job creators",
]

# ── VADER sentiment (lazy) ────────────────────────────────────────────────────
@lru_cache(maxsize=1)
def _vader():
    try:
        from vaderSentiment.vaderSentiment import SentimentIntensityAnalyzer
        return SentimentIntensityAnalyzer()
    except Exception as exc:  # noqa: BLE001
        log.warning("VADER not available (%s) — sentiment will default to neutral", exc)
        return None


# ── spaCy NER (lazy) ──────────────────────────────────────────────────────────
@lru_cache(maxsize=1)
def _nlp():
    try:
        import spacy
        try:
            return spacy.load("en_core_web_sm", disable=["lemmatizer"])
        except OSError:
            log.warning(
                "spaCy model 'en_core_web_sm' not installed. Run: "
                "python -m spacy download en_core_web_sm"
            )
            return None
    except Exception as exc:  # noqa: BLE001
        log.warning("spaCy not available (%s) — entities via regex fallback", exc)
        return None


# ── Result type ───────────────────────────────────────────────────────────────
@dataclass
class Analysis:
    sentiment: str = "neutral"
    sentiment_score: float = 0.0
    bias_score: int = 0
    topics: list[str] = field(default_factory=list)
    keywords: list[str] = field(default_factory=list)
    persons: list[str] = field(default_factory=list)
    orgs: list[str] = field(default_factory=list)
    places: list[str] = field(default_factory=list)

    def to_payload(self) -> dict:
        """Shape for the /articles REST endpoint."""
        return {
            "sentiment":       self.sentiment,
            "sentiment_score": round(self.sentiment_score, 3),
            "bias_score":      int(self.bias_score),
            "topics":          self.topics,
            "keywords":        self.keywords,
            "entities":        {
                "persons": self.persons[:10],
                "orgs":    self.orgs[:10],
                "places":  self.places[:10],
            },
        }


# ── Public API ────────────────────────────────────────────────────────────────

def analyze(
    title: str,
    content: str = "",
    bias_baseline: int = 0,
) -> Analysis:
    """Full NLP enrichment for one article."""
    text = f"{title}\n{content}".strip()
    if not text:
        return Analysis(bias_score=bias_baseline)

    sent_label, sent_score = _sentiment(text)
    bias = _bias_score(text, bias_baseline)
    topics = _topics(text)
    persons, orgs, places, keywords = _entities_and_keywords(text)

    return Analysis(
        sentiment=sent_label,
        sentiment_score=sent_score,
        bias_score=bias,
        topics=topics,
        keywords=keywords,
        persons=persons,
        orgs=orgs,
        places=places,
    )


# ── Internals ─────────────────────────────────────────────────────────────────

def _sentiment(text: str) -> tuple[str, float]:
    v = _vader()
    if v is None:
        return "neutral", 0.0
    score = v.polarity_scores(text[:5000])["compound"]
    if score >= 0.15:
        return "positive", score
    if score <= -0.15:
        return "negative", score
    return "neutral", score


def _bias_score(text: str, baseline: int) -> int:
    low = text.lower()
    left_hits = sum(low.count(t) for t in _LEFT_TERMS)
    right_hits = sum(low.count(t) for t in _RIGHT_TERMS)
    # Each term net shifts bias ±3; clamp total drift to ±25.
    drift = max(-25, min(25, (right_hits - left_hits) * 3))
    return max(-100, min(100, baseline + drift))


def _topics(text: str) -> list[str]:
    low = text.lower()
    hits: list[tuple[str, int]] = []
    for topic, terms in TOPIC_LEXICON.items():
        count = sum(low.count(t) for t in terms)
        if count:
            hits.append((topic, count))
    hits.sort(key=lambda x: x[1], reverse=True)
    return [t for t, _ in hits[:4]]


# Words we don't want bubbling up as keywords
_STOP_KEYWORDS = {
    "kenya", "kenyans", "kenyan", "said", "says", "also", "will", "would",
    "could", "may", "year", "years", "day", "days", "people", "country",
    "news", "report", "according", "including", "however", "nairobi",
}


def _entities_and_keywords(
    text: str,
) -> tuple[list[str], list[str], list[str], list[str]]:
    nlp = _nlp()
    if nlp is None:
        return _regex_entities(text)

    # Truncate very long articles — spaCy gets slow past ~5k chars.
    doc = nlp(text[:5000])

    persons = _dedupe(ent.text.strip() for ent in doc.ents if ent.label_ == "PERSON")
    orgs    = _dedupe(ent.text.strip() for ent in doc.ents if ent.label_ == "ORG")
    places  = _dedupe(ent.text.strip() for ent in doc.ents if ent.label_ in ("GPE", "LOC"))

    # Keywords: most common nouns excluding stopwords and short tokens
    nouns = [
        tok.text.lower() for tok in doc
        if tok.pos_ in ("NOUN", "PROPN")
        and len(tok.text) > 3
        and tok.text.lower() not in _STOP_KEYWORDS
        and tok.is_alpha
    ]
    keywords = [w for w, _ in Counter(nouns).most_common(5)]

    return persons[:15], orgs[:15], places[:15], keywords


def _regex_entities(text: str) -> tuple[list[str], list[str], list[str], list[str]]:
    """Cheap fallback when spaCy isn't available."""
    # Capitalised multi-word spans → approximate named entities
    spans = re.findall(r"\b(?:[A-Z][a-z]+(?:\s+[A-Z][a-z]+){0,2})\b", text)
    seen: list[str] = []
    for s in spans:
        if s.lower() in _STOP_KEYWORDS:
            continue
        if s not in seen:
            seen.append(s)
        if len(seen) >= 20:
            break

    # Keywords: most common lowercase words 4+ chars, not stopwords
    words = re.findall(r"\b[a-z]{4,}\b", text.lower())
    keywords = [
        w for w, _ in Counter(words).most_common(30)
        if w not in _STOP_KEYWORDS
    ][:5]

    # Without POS tagging we can't split persons/orgs/places cleanly —
    # dump everything into "persons" so the payload shape stays valid.
    return seen[:10], [], [], keywords


def _dedupe(items) -> list[str]:
    out: list[str] = []
    seen: set[str] = set()
    for x in items:
        if not x or len(x) < 2:
            continue
        key = x.lower()
        if key in seen:
            continue
        seen.add(key)
        out.append(x)
    return out
