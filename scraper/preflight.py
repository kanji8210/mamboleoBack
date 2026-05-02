"""Dependency preflight — verify every package the scraper needs is importable.

Prints a pass/fail line for each requirement. Returns a (ok, report) tuple
so callers (main.py, run_all_scrapers.py) can decide whether to abort.

This runs in the scraper's own venv — it does NOT install anything. The
goal is fast, friendly diagnostics: "you're missing lxml, run pip install -r
requirements.txt" instead of an opaque ImportError 30s into the run.
"""
from __future__ import annotations

import importlib
import sys
from dataclasses import dataclass

# (import_name, pip_name, required, purpose)
# `required=False` → printed as a warning, not a failure.
_DEPENDENCIES: list[tuple[str, str, bool, str]] = [
    # Core HTTP + parsing
    ("requests",              "requests",          True,  "HTTP client"),
    ("bs4",                   "beautifulsoup4",    True,  "HTML parsing"),
    ("lxml",                  "lxml",              True,  "Fast HTML/XML parser backend"),
    ("feedparser",            "feedparser",        True,  "RSS / Atom feeds"),
    ("yaml",                  "PyYAML",            True,  "sources.yaml loader"),
    ("dotenv",                "python-dotenv",     True,  ".env file loader"),
    # NLP / Media Monitor
    ("vaderSentiment",        "vaderSentiment",    True,  "Sentiment scoring"),
    ("spacy",                 "spacy",             False, "Named-entity extraction (optional — falls back to regex)"),
    # Social
    ("tweepy",                "tweepy",            False, "Twitter API client (optional — only if TWITTER_BEARER_TOKEN set)"),
]

_GREEN = "\033[32m"
_RED   = "\033[31m"
_YEL   = "\033[33m"
_DIM   = "\033[2m"
_RST   = "\033[0m"


@dataclass
class CheckResult:
    name:     str
    pip_name: str
    ok:       bool
    required: bool
    detail:   str  # version string on success, error message on failure


def _check_one(import_name: str, pip_name: str, required: bool) -> CheckResult:
    try:
        mod = importlib.import_module(import_name)
        ver = getattr(mod, "__version__", "unknown")
        return CheckResult(import_name, pip_name, True, required, str(ver))
    except ImportError as exc:
        return CheckResult(import_name, pip_name, False, required, str(exc))
    except Exception as exc:  # noqa: BLE001  pragma: no cover
        # Some packages explode at import time (spacy with broken model, etc.)
        return CheckResult(import_name, pip_name, False, required, f"{type(exc).__name__}: {exc}")


def _check_spacy_model() -> CheckResult | None:
    """Bonus check: spaCy needs the en_core_web_sm model downloaded separately."""
    try:
        import spacy  # noqa: F401
    except ImportError:
        return None
    try:
        importlib.import_module("en_core_web_sm")
        return CheckResult("en_core_web_sm", "spacy model", True, False, "loaded")
    except ImportError:
        return CheckResult(
            "en_core_web_sm", "spacy model", False, False,
            "run: python -m spacy download en_core_web_sm",
        )


def run(verbose: bool = True) -> tuple[bool, list[CheckResult]]:
    """Verify dependencies. Returns (all_required_ok, results)."""
    results: list[CheckResult] = [
        _check_one(name, pip_name, required)
        for name, pip_name, required, _ in _DEPENDENCIES
    ]
    spacy_model = _check_spacy_model()
    if spacy_model is not None:
        results.append(spacy_model)

    if verbose:
        _print_report(results)

    all_required_ok = all(r.ok for r in results if r.required)
    return all_required_ok, results


def _print_report(results: list[CheckResult]) -> None:
    use_color = sys.stdout.isatty()
    def c(code: str, s: str) -> str:
        return f"{code}{s}{_RST}" if use_color else s

    purpose_by_name = {name: purpose for name, _, _, purpose in _DEPENDENCIES}

    ok_count   = sum(1 for r in results if r.ok)
    fail_count = sum(1 for r in results if not r.ok and r.required)
    warn_count = sum(1 for r in results if not r.ok and not r.required)

    print(c(_DIM, "── Dependency check ──────────────────────────────────────────"))
    for r in results:
        if r.ok:
            mark   = c(_GREEN, "✓")
            status = c(_DIM, f"v{r.detail}")
        elif r.required:
            mark   = c(_RED, "✗")
            status = c(_RED, f"MISSING — pip install {r.pip_name}")
        else:
            mark   = c(_YEL, "○")
            status = c(_YEL, f"optional — {r.detail}")

        purpose = purpose_by_name.get(r.name, "")
        print(f"  {mark} {r.name:<22}  {status}")
        if purpose and not r.ok:
            print(f"    {c(_DIM, purpose)}")

    print(c(_DIM, "──────────────────────────────────────────────────────────────"))
    summary = f"{ok_count} ok"
    if warn_count:
        summary += f", {warn_count} optional missing"
    if fail_count:
        summary += f", {c(_RED, str(fail_count) + ' REQUIRED MISSING')}"
    print(f"  {summary}")
    if fail_count:
        print(c(_RED, "  → run: pip install -r requirements.txt"))


if __name__ == "__main__":
    ok, _ = run(verbose=True)
    sys.exit(0 if ok else 1)
