"""Classify article text → incident type + severity via keyword matching.
No ML dependencies — works offline.
"""
from __future__ import annotations

import re
from dataclasses import dataclass

# ── Keyword tables ────────────────────────────────────────────────────────────

INCIDENT_KEYWORDS: dict[str, list[str]] = {
    "fire": [
        "fire", "blaze", "inferno", "burning", "burnt down", "arson",
        "explosion", "blast", "detonation", "gas explosion",
    ],
    "accident": [
        "accident", "crash", "collision", "knocked down", "hit and run",
        "pile-up", "pileup", "matatu", "boda boda", "overturned",
        "rolled over", "road carnage", "lorry", "truck accident",
        "vehicle collision", "fatal crash",
    ],
    "flood": [
        "flood", "flooding", "submerged", "flash flood", "swept away",
        "mudslide", "landslide", "erosion", "overflow", "dam burst",
        "burst banks", "heavy downpour",
    ],
    "protest": [
        "protest", "demonstration", "riot", "march", "strike",
        "unrest", "demonstrators", "protesters", "teargas",
        "running battles", "procession", "picketing", "sit-in",
    ],
    "police": [
        "police", "arrested", "crackdown", "gunfire", "shooting",
        "robbery", "theft", "murder", "stabbing", "assault",
        "kidnapping", "abduction", "gang", "carjacking", "bandit",
        "ambush", "raid", "crime", "suspect",
    ],
    "weather": [
        "storm", "hailstorm", "heavy rain", "drought", "lightning strike",
        "thunder", "cyclone", "tornado", "strong winds", "El Niño",
    ],
    "medical": [
        "outbreak", "cholera", "malaria", "epidemic", "contamination",
        "poisoning", "food poisoning", "mass illness", "disease",
    ],
}

SEVERITY_HIGH_WORDS = [
    "dead", "killed", "deaths", "fatalities", "casualties",
    "critical condition", "major", "massive", "dozens", "multiple deaths",
    "bodies", "perished",
]
SEVERITY_MED_WORDS = [
    "injured", "wounded", "hospitalised", "hospitalized",
    "displaced", "evacuated", "trapped", "missing",
]


# ── Output type ───────────────────────────────────────────────────────────────

@dataclass
class Classification:
    incident_type: str   # matches CPT meta 'type' allowed values
    severity: str        # 'low' | 'medium' | 'high'
    confidence: float    # 0.0–1.0


# ── Public API ────────────────────────────────────────────────────────────────

def classify(title: str, body: str = "") -> Classification | None:
    """Return Classification or None if the text isn't an incident."""
    text = (title + " " + body).lower()

    scores: dict[str, int] = {}
    for itype, kws in INCIDENT_KEYWORDS.items():
        count = sum(
            len(re.findall(r"\b" + re.escape(kw) + r"\b", text))
            for kw in kws
        )
        scores[itype] = count

    best_type, best_score = max(scores.items(), key=lambda x: x[1])
    if best_score == 0:
        return None

    confidence = min(best_score / 5.0, 1.0)

    high = sum(1 for w in SEVERITY_HIGH_WORDS if w in text)
    med  = sum(1 for w in SEVERITY_MED_WORDS  if w in text)

    if high >= 2:
        severity = "high"
    elif high >= 1 or med >= 2:
        severity = "medium"
    else:
        severity = "low"

    return Classification(incident_type=best_type, severity=severity, confidence=confidence)
