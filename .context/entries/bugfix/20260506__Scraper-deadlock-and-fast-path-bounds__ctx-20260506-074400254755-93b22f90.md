---
entry_id: "ctx-20260506-074400254755-93b22f90"
title: "Scraper deadlock and fast-path bounds"
category: "bugfix"
tags: ["scraper", "bugfix", "sqlite", "deadlock", "llm", "performance", "wordpress"]
files: ["scraper/db.py", "scraper/config.py", "scraper/main.py", "scraper/processors/analyze.py", "scraper/processors/intelligence.py"]
commits: ["2039151"]
status: "active"
importance: "high"
created_at: "2026-05-06T07:44:00Z"
updated_at: "2026-05-06T07:44:00Z"
summary: "Fixed a scraper hang caused by a dedupe deadlock in scraper/db.py and added fast-fail bounds so source runs degrade quickly instead of stalling on heavy enrichment or LLM latency."
retrieval_hints: "scraper hang sqlite deadlock seen.db is_seen _seen_set RLock LLM timeout fast path spaCy disabled source budget"
---

## What
Changed scraper/db.py to use a re-entrant lock so first-time cache population no longer deadlocks inside _seen_set -> _conn. Added configurable LLM timeout caps, max attempts, and per-source runtime budgets. Disabled spaCy enrichment on the default scraper fast path unless ENABLE_SPACY_ENRICHMENT=1 is set.

## Why
The run appeared to hang after initial fetch logs because process_article hit db.is_seen(), which blocked forever on a nested lock acquisition during first DB cache warmup. Even after removing that blocker, the pipeline still needed tighter bounds so one slow source or model call could not monopolize a run.

## Impact
Single-source dry-run for googlenews with limit=1 now completes in about 3 seconds instead of stalling. db.is_seen() returns in milliseconds, process_article on a fetched item returns promptly, and the scraper now fails fast under slow enrichment conditions.

## Notes
Posting to WordPress is still subject to the separate Cloudflare/WP API 403 issue; this entry records the local hang fix and runtime-bound strategy only.
