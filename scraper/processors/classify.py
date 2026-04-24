"""Classify article text → incident type + severity via keyword matching.

Design:
    1. Exclusion filter — if the text is clearly sports / entertainment /
       policy-talk / religious / opinion, return None immediately.
    2. Event-verb requirement — incidents need a concrete past-tense event
       verb (killed, crashed, arrested, looted, burned, flooded…). A topic
       keyword alone ("police", "fire", "storm") in a headline without an
       event verb is ignored — that rejects "police chief suspended",
       "fire back at critics", "weather forecast", etc.
    3. Score = topic hits * 0.25 + event-verb hits * 0.5, capped at 1.0.
       Below MIN_CONFIDENCE (0.30) → treated as non-incident by the caller.

No ML dependencies — works offline.
"""
from __future__ import annotations

import re
from dataclasses import dataclass

# ── Exclusion categories (short-circuit to None) ──────────────────────────────
# Any hit on a phrase below strongly suggests this is NOT a real-world incident.
EXCLUSION_PHRASES: list[str] = [
    # Sports
    "premier league", "afcon", "harambee stars", "world cup",
    "olympics", "marathon", "athletics", "goal", "goals", "striker",
    "midfielder", "defender", "goalkeeper", "scored", "hat-trick",
    "trophy", "trophies", "fixtures", "league table", "title race",
    "final score", "man of the match", "squad", "transfer window",
    "fifa", "uefa", "caf", "boxing match", "rugby", "cricket match",
    "chelsea", "arsenal", "manchester", "liverpool",
    # Celebrity / entertainment
    "gospel singer", "bongo", "nominated for", "red carpet",
    "album", "music video", "rapper", "celebrity", "trending on",
    "gold digger", "influencer", "beauty queen", "miss world",
    "hollywood", "nollywood", "netflix", "tv show", "reality show",
    # Religion / opinion / speeches
    "pope leo", "pope francis", "pontiff", "bishop", "archbishop",
    "sermon", "holy mass", "christmas message", "easter message",
    "opinion:", "editorial:", "op-ed", "column:",
    # Politics-as-policy / governance talk (not events)
    "sworn in", "swearing-in ceremony", "takes oath", "appointed",
    "nominated to", "confirmed as", "unveils manifesto",
    "launches policy", "launches report", "signs agreement",
    "signs mou", "signs memorandum", "tables bill", "tabled bill",
    "second reading", "third reading", "passes bill", "passed bill",
    "budget statement", "state of the nation",
    # Commerce / markets / corporate PR
    "annual report", "financial results", "quarterly earnings",
    "ipo", "listed on", "share price", "dividend", "profit warning",
    "appointed ceo", "new ceo", "launches product", "launches app",
    # Weather forecasts (not weather events)
    "weather forecast", "weather outlook", "expected rainfall",
    # Ceremonies / fundraisers
    "tree planting", "plants trees", "planted trees",
    "fundraiser", "fundraising drive", "guinness world",
]

# Additional exclusion regex patterns — statements / launches, not events.
EXCLUSION_PATTERNS: list[re.Pattern] = [
    re.compile(r"\b(urges|calls on|criticises|criticizes|praises|condemns|warns against)\b", re.I),
    re.compile(r"\b(launches|unveils|announces)\b.*\b(programme|program|initiative|campaign)\b", re.I),
]


# ── Incident topic keywords (noun-ish) ────────────────────────────────────────
INCIDENT_KEYWORDS: dict[str, list[str]] = {
    "fire": [
        "fire", "blaze", "inferno", "arson", "explosion", "blast",
        "gas explosion",
    ],
    "accident": [
        "accident", "crash", "collision", "pile-up", "pileup",
        "matatu", "boda boda", "road carnage", "lorry accident",
        "truck accident", "vehicle collision", "fatal crash",
    ],
    "flood": [
        "flood", "floods", "flooding", "flash flood", "mudslide",
        "landslide", "dam burst", "burst banks", "heavy downpour",
    ],
    "protest": [
        "protest", "demonstration", "riot", "unrest", "demonstrators",
        "protesters", "teargas", "running battles", "picketing", "sit-in",
    ],
    "police": [
        "robbery", "theft", "murder", "stabbing", "assault",
        "kidnapping", "abduction", "gang", "carjacking", "bandit",
        "ambush", "raid", "suspect",
    ],
    "weather": [
        "storm", "hailstorm", "lightning strike", "cyclone",
        "tornado", "strong winds",
    ],
    "medical": [
        "outbreak", "cholera", "malaria outbreak", "epidemic",
        "contamination", "poisoning", "food poisoning", "mass illness",
    ],
}

# ── Event verbs by category (past-tense, concrete action) ─────────────────────
# One of these MUST appear in combination with a topic keyword for a match.
EVENT_VERBS: dict[str, list[str]] = {
    "fire": [
        "burned", "burnt", "burns", "gutted", "razed", "destroyed by",
        "set ablaze", "caught fire", "broke out", "exploded", "erupted",
    ],
    "accident": [
        "crashed", "collided", "overturned", "rolled over", "knocked down",
        "killed in", "died in", "injured in", "survived", "hit and run",
        "ran over", "rammed into",
    ],
    "flood": [
        "swept away", "sweep away", "sweeps away", "submerged",
        "washed away", "inundated", "marooned", "flooded",
        "cut off by", "displaced",
    ],
    "protest": [
        "protested", "demonstrated", "marched", "clashed", "barricaded",
        "blocked", "looted", "torched", "tear-gassed", "teargassed",
        "lobbed", "hurled stones",
    ],
    "police": [
        "arrested", "detained", "shot", "killed", "wounded", "robbed",
        "attacked", "ambushed", "kidnapped", "abducted", "raided",
        "seized", "recovered", "stabbed", "hacked", "lynched",
    ],
    "weather": [
        "struck by lightning", "destroyed", "uprooted", "damaged",
        "flattened", "battered",
    ],
    "medical": [
        "infected", "hospitalised", "hospitalized", "died of", "died from",
        "sickened", "contaminated", "quarantined",
    ],
}

# Severity cues
SEVERITY_HIGH_WORDS = [
    "dead", "killed", "deaths", "fatalities", "casualties",
    "critical condition", "massive", "dozens", "multiple deaths",
    "bodies", "perished", "feared dead",
]
SEVERITY_MED_WORDS = [
    "injured", "wounded", "hospitalised", "hospitalized",
    "displaced", "evacuated", "trapped", "missing",
]

MIN_CONFIDENCE = 0.30  # below this → not an incident


# ── Output type ───────────────────────────────────────────────────────────────

@dataclass
class Classification:
    incident_type: str   # matches CPT meta 'type' allowed values
    severity: str        # 'low' | 'medium' | 'high'
    confidence: float    # 0.0–1.0


# ── Helpers ───────────────────────────────────────────────────────────────────

def _is_excluded(text: str) -> bool:
    """Return True if the text is clearly non-incident (sports / politics / etc.)."""
    for phrase in EXCLUSION_PHRASES:
        if phrase in text:
            return True
    for pat in EXCLUSION_PATTERNS:
        if pat.search(text):
            return True
    return False


def _count_word_hits(text: str, words: list[str]) -> int:
    """Whole-word / phrase match count."""
    total = 0
    for w in words:
        if " " in w or "-" in w:
            total += text.count(w)
        else:
            total += len(re.findall(r"\b" + re.escape(w) + r"\b", text))
    return total


# ── Public API ────────────────────────────────────────────────────────────────

def classify(title: str, body: str = "") -> Classification | None:
    """Return Classification or None if the text isn't an incident."""
    text = (title + " " + body).lower()

    # ── 1. Hard-exclusion filter ──────────────────────────────────────────────
    # Title alone is the strongest signal — if the title screams "sports" we
    # reject outright even if the body mentions police/fire metaphorically.
    if _is_excluded(title.lower()):
        return None
    # Two exclusion hits anywhere in the text → reject.
    body_excl_hits = sum(1 for p in EXCLUSION_PHRASES if p in text)
    if body_excl_hits >= 2:
        return None

    # ── 2. Score each incident category ───────────────────────────────────────
    best_type: str | None = None
    best_score: float = 0.0

    for itype, topic_kws in INCIDENT_KEYWORDS.items():
        topic_hits = _count_word_hits(text, topic_kws)
        verb_hits  = _count_word_hits(text, EVENT_VERBS.get(itype, []))

        # REQUIRE an event verb OR at least two topic keyword hits.
        # Rejects "police chief suspended", "fire back at critics",
        # "weather forecast", etc.
        if verb_hits == 0 and topic_hits < 2:
            continue

        score = topic_hits * 0.25 + verb_hits * 0.50
        score = min(score, 1.0)

        if score > best_score:
            best_score = score
            best_type = itype

    if best_type is None or best_score < MIN_CONFIDENCE:
        return None

    # ── 3. Severity ───────────────────────────────────────────────────────────
    high = sum(1 for w in SEVERITY_HIGH_WORDS if w in text)
    med  = sum(1 for w in SEVERITY_MED_WORDS  if w in text)

    if high >= 2:
        severity = "high"
    elif high >= 1 or med >= 2:
        severity = "medium"
    else:
        severity = "low"

    return Classification(
        incident_type=best_type,
        severity=severity,
        confidence=round(best_score, 2),
    )
