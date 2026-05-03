"""Classify article text → incident type + severity via keyword matching.

Design:
    1. Metaphor stripping — phrases like "erupted in cheers",
       "broke out in laughter", "sparked debate", "fire of hope" are removed
       before scoring so they don't trigger fire/protest categories.
    2. Exclusion filter — if the text is clearly sports / entertainment /
       policy-talk / religious / opinion, return None immediately.
    3. Co-occurrence requirement — a category only matches when BOTH a topic
       keyword AND an event verb appear (or 3+ topic keywords). Title hits
       are weighted 2× because headlines are the strongest signal.
    4. Score = topic hits * 0.20 + event-verb hits * 0.40, capped at 1.0.
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
    "pope leo", "pope francis", "pope benedict", "pontiff",
    "papal visit", "papal", "vatican", "his holiness", "holy father",
    "pilgrimage", "canonization", "canonisation", "beatification",
    "bishop", "archbishop", "cardinal",
    "sermon", "homily", "holy mass", "christmas message", "easter message",
    "prayer rally", "crusade meeting", "church service",
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
    # Religious / dignitary visits — "Pope visits …", "Pope arrives in …".
    # Only excludes *ceremonial* pope coverage. A "stampede at Pope's mass"
    # still passes because the casualty-word override fires (line 336+).
    re.compile(r"\bpope\b.*\b(visit|visits|arrives|arrival|tour|meets|blesses|prays|homily|mass|message)\b", re.I),
]

# Metaphorical / idiomatic phrases that look like event verbs but are not.
# Stripped from text before topic/verb scoring.
METAPHOR_PHRASES: list[str] = [
    "erupted in cheers", "erupted in applause", "erupted in joy",
    "erupted in laughter", "erupted in celebration", "erupted in song",
    "broke out in cheers", "broke out in applause", "broke out in song",
    "broke out in laughter", "broke out in smiles",
    "caught fire on social media", "caught fire online",
    "set the internet ablaze", "set social media ablaze",
    "fire of hope", "fire of faith", "fire in the belly",
    "sparked debate", "sparked outrage", "sparked controversy",
    "sparked reactions", "sparked discussion",
    "trial by fire", "under fire from", "come under fire",
    "firing on all cylinders", "fired up the crowd",
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
    title_l = title.lower()
    body_l  = body.lower()

    # ── 1. Hard-exclusion filter ──────────────────────────────────────────────
    # Title alone is the strongest signal — if the title screams "sports" we
    # reject outright even if the body mentions police/fire metaphorically.
    if _is_excluded(title_l):
        return None
    # Two exclusion hits anywhere in the text → reject.
    full = title_l + " " + body_l
    body_excl_hits = sum(1 for p in EXCLUSION_PHRASES if p in full)
    if body_excl_hits >= 2:
        return None

    # ── 2. Strip metaphors so they don't fire topic/verb hits ────────────────
    title_clean = title_l
    body_clean  = body_l
    for phrase in METAPHOR_PHRASES:
        title_clean = title_clean.replace(phrase, " ")
        body_clean  = body_clean.replace(phrase, " ")

    # ── 3. Score each incident category ───────────────────────────────────────
    best_type: str | None = None
    best_score: float = 0.0

    for itype, topic_kws in INCIDENT_KEYWORDS.items():
        title_topic = _count_word_hits(title_clean, topic_kws)
        body_topic  = _count_word_hits(body_clean,  topic_kws)
        # Title weighted 2× because headlines are the strongest signal.
        topic_hits  = title_topic * 2 + body_topic
        verb_hits   = (
            _count_word_hits(title_clean, EVENT_VERBS.get(itype, [])) * 2
            + _count_word_hits(body_clean, EVENT_VERBS.get(itype, []))
        )

        # REQUIRE at least one topic keyword. A bare verb ("erupted",
        # "broke out", "destroyed") is too ambiguous on its own.
        if topic_hits == 0:
            continue
        # And require either a verb OR strong topic presence (3+ weighted).
        # Rejects "police chief suspended", "fire back at critics", etc.
        if verb_hits == 0 and topic_hits < 3:
            continue

        score = topic_hits * 0.20 + verb_hits * 0.40
        score = min(score, 1.0)

        if score > best_score:
            best_score = score
            best_type = itype

    if best_type is None or best_score < MIN_CONFIDENCE:
        return None

    # ── 4. Severity ───────────────────────────────────────────────────────────
    high = sum(1 for w in SEVERITY_HIGH_WORDS if w in full)
    med  = sum(1 for w in SEVERITY_MED_WORDS  if w in full)

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


# ── Broad LLM gate ────────────────────────────────────────────────────────────
# Headline-level signals that strongly imply a real-world event but might miss
# the strict topic+verb co-occurrence required by classify(). Used purely as a
# cheap router so the LLM gets a chance to look at the article.
_CANDIDATE_HEADLINE_WORDS: list[str] = [
    # casualties / harm
    "dead", "died", "dies", "killed", "kills", "deaths", "fatal", "fatalities",
    "casualties", "injured", "wounded", "hospitalised", "hospitalized",
    "perished", "missing", "feared dead", "bodies", "body found", "found dead",
    "trapped", "rescued", "evacuated", "displaced", "stranded", "marooned",
    # event verbs (broader than the strict EVENT_VERBS set)
    "crash", "crashed", "collide", "collided", "overturned", "rolled over",
    "burnt", "burned", "burns", "burning", "razed", "gutted", "ablaze",
    "explosion", "blast", "exploded", "flooded", "flooding", "swept away",
    "washed away", "submerged", "drowned", "drowning", "landslide", "mudslide",
    "shot", "shooting", "stabbed", "stabbing", "lynched", "robbed", "raided",
    "kidnapped", "kidnapping", "abducted", "abduction", "ambushed", "ambush",
    "looted", "torched", "clashed", "clashes", "riot", "rioting", "protest",
    "demonstration", "teargas", "running battles", "attack", "attacked",
    "outbreak", "epidemic", "contaminated", "poisoned", "poisoning",
    "struck by lightning",
    # editorial cues
    "tragedy", "horror", "carnage", "mayhem", "scene of", "mourning",
    "grief", "panic", "chaos", "stampede", "hostage", "siege",
    # weather hazards
    "storm", "hailstorm", "cyclone", "tornado", "heavy downpour", "flash flood",
    # crime / justice / GBV — narrative journalism often frames these as
    # explainers or features rather than straight news ("the untold story
    # of…", "justice for…", "…explained"). The LLM is the right judge here.
    "murder", "murdered", "homicide", "femicide", "feminicide", "victim",
    "victims", "suspect", "arrested", "convicted", "sentenced", "accused",
    "rape", "raped", "sexual assault", "gender violence", "domestic violence",
    "defilement", "manslaughter", "arson", "assaulted", "battered",
    "justice", "inquest", "autopsy", "postmortem", "exhumation",
    "revenge", "vendetta", "gang war", "extortion", "trafficking",
    "child abuse", "child labour", "eviction", "demolition", "squatter",
    "squatters", "land grab", "dispossessed", "adverse possession",
]

# Narrative / feature framing words — titles like "…explained", "the untold
# story of…" or "the high stakes of…" wrap real incidents in feature-style
# headlines that pass zero event-verb checks. If ANY of these appear in the
# title AND the body contains any incident-related vocabulary, route to the LLM.
_NARRATIVE_TITLE_CUES: list[str] = [
    "explained", "untold story", "high stakes", "inside the",
    "behind the", "the truth about", "what happened", "how it happened",
    "silence and", "what we know", "timeline of", "anatomy of",
    "the case of", "why it matters", "the cost of", "a deep dive",
    "in pictures", "in numbers", "special report", "investigation",
    "exclusive", "expose", "exposé", "cover-up", "revealed",
    "unanswered questions", "dark side", "deadly",
]


def looks_like_incident_candidate(title: str, body: str = "") -> bool:
    """Cheap, *inclusive* gate that decides whether the LLM should look at this.

    Returns True when ANY of the following hold:
      • the headline contains a casualty / harm / event / crime word
      • the title or body contains any incident topic keyword
      • the title or body contains any strict event verb
      • the title uses narrative/feature framing AND the body has incident vocab

    Returns False only when the title is clearly off-topic (sports / celebrity /
    policy speech / religious ceremony) AND no incident signal is present.

    This is *much* more permissive than `classify()` on purpose — its job is
    to filter obvious noise (sports recaps, music videos, opinion columns)
    while still routing borderline narrative pieces to the LLM, which is the
    authoritative judge.
    """
    title_l = (title or "").lower()
    body_l  = (body or "").lower()

    # Hard exclusion — only on the *headline*. Body matches are too noisy
    # (a celebrity may be quoted in an incident article).
    if _is_excluded(title_l):
        # Still allow through if a strong casualty / crime word appears in the title.
        for w in ("killed", "dead", "died", "shot", "stabbed", "crash",
                  "fatal", "explosion", "fire ", "ablaze", "murder",
                  "murdered", "victim", "femicide", "stampede", "lynched",
                  "drowning", "drowned", "rape", "arson", "homicide"):
            if w in title_l:
                return True
        return False

    # Headline-level event signal → always send to LLM.
    for w in _CANDIDATE_HEADLINE_WORDS:
        if w in title_l:
            return True

    # Narrative / feature framing in title + any incident vocabulary in body.
    # Catches: "The high stakes of adverse possession, explained" where the
    # title has zero event verbs but the body discusses actual violence/crime.
    for cue in _NARRATIVE_TITLE_CUES:
        if cue in title_l:
            # Body must show at least one incident-related word to avoid
            # sending pure policy explainers ("GDP explained") to the LLM.
            for kws in INCIDENT_KEYWORDS.values():
                if _count_word_hits(body_l, kws):
                    return True
            for w in _CANDIDATE_HEADLINE_WORDS:
                if w in body_l:
                    return True
            break  # matched the cue but body has no incident signal → skip

    # Any incident topic keyword anywhere → send to LLM.
    for kws in INCIDENT_KEYWORDS.values():
        if _count_word_hits(title_l, kws) or _count_word_hits(body_l, kws):
            return True

    # Any strict event verb anywhere → send to LLM.
    for verbs in EVENT_VERBS.values():
        if _count_word_hits(title_l, verbs) or _count_word_hits(body_l, verbs):
            return True

    return False
