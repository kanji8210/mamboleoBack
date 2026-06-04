# Mamboleo Backend

This plugin powers the backend for the Mamboleo public safety platform.

## Purpose

It provides the data and management layer behind the public app:

- custom post types and taxonomies for incidents,
- REST and GraphQL endpoints,
- ingestion and scraper integration,
- AI-assisted enrichment and monitoring tools,
- moderation and operational admin screens.

## How It Fits the Public App

1. Receives reports and source data.
2. Stores and organizes incidents in WordPress.
3. Exposes data to the frontend map and feed experience.
4. Supports review, cleanup, updates, and analytics workflows.

## Scraper Model (Single Clear Flow)

The project uses one canonical scraper pipeline:

1. Scrape locally.
2. Run analysis locally (including the intelligence layer).
3. Push results to the backend API.

Primary command:

```powershell
cd scraper
python run_pipeline.py --cadence all
```

For scheduled automation on Windows, keep using Task Scheduler with:

- `windows_scrape_task_fast.xml`
- `windows_scrape_task_slow.xml`

Both tasks call the same pipeline through `run_scheduled.bat`.

## Setup

For full install and operations setup, see [SETUP.md](SETUP.md).

## Public Mission

Mamboleo is intended to support public awareness and community safety through transparent, collaborative information sharing.

## Free and Open Source Direction

This backend is part of the broader Mamboleo free and open-source effort.

Community help is valuable for:

- code quality,
- security hardening,
- data integrity,
- source coverage,
- and long-term sustainability.
