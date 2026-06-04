---
entry_id: "ctx-20260604-103132303275-cba7d7a9"
title: "Single clear local-first scraper pipeline with post-run categorization"
category: "feature"
tags: ["scraper", "pipeline", "scheduling", "reporting", "operations"]
files: ["README.md", "SETUP.md", "scraper/main.py", "scraper/run_all_scrapers.py", "scraper/run_pipeline.py", "scraper/run_scheduled.bat"]
commits: ["03396f1"]
status: "active"
importance: "high"
created_at: "2026-06-04T10:31:32Z"
updated_at: "2026-06-04T10:31:32Z"
summary: "Standardized operations on one canonical run_pipeline entrypoint, kept Windows scheduled automation, and added automatic categorized post-run summaries written as JSON."
retrieval_hints: "run_pipeline canonical entrypoint local scrape analyze push scheduled task wrapper post-run summary categories success low_yield analyzed_no_incident no_data skipped failed last_run_summary.json"
---

## What
Added scraper/run_pipeline.py as canonical CLI wrapper over main.py, routed scheduled batch execution through it, documented a single local-first flow, and extended scraper/main.py to emit structured categorized run summaries.

## Why
Team requested one clear scraper path while preserving intelligence-layer analysis and scheduled scraping, plus immediate categorization of outcomes after each run.

## Impact
Operators now have one stable command for manual and scheduled runs, and each run produces machine-readable categorized metrics for faster triage and monitoring.

## Notes
Categories include success, low_yield, analyzed_no_incident, no_data, skipped, and failed; output is written to scraper/data/last_run_summary.json.
