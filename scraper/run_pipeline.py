"""Canonical Mamboleo scraper pipeline entrypoint.

One clear flow:
  scrape locally -> analyze locally (intelligence layer) -> push to WordPress.

This script is a thin CLI wrapper over main.py so operations teams can use a
single command for manual runs and scheduled tasks.
"""
from __future__ import annotations

import argparse
import logging
import sys

from main import main as run_main


def parse_args():
    p = argparse.ArgumentParser(
        description="Run the local scraping + analysis + backend push pipeline"
    )
    p.add_argument(
        "--cadence",
        choices=["fast", "slow", "all"],
        default="all",
        help="Source bucket to run (default: all enabled sources)",
    )
    p.add_argument(
        "--limit",
        type=int,
        default=None,
        help="Optional max articles per source",
    )
    p.add_argument(
        "--workers",
        type=int,
        default=None,
        help="Optional max concurrent scrapers",
    )
    p.add_argument(
        "--dry-run",
        action="store_true",
        help="Scrape + analyze only, do not push to backend",
    )
    p.add_argument(
        "--llm-all",
        action="store_true",
        help="Send every scraped article to the LLM intelligence layer",
    )
    p.add_argument(
        "--skip-preflight",
        action="store_true",
        help="Skip dependency checks",
    )
    return p.parse_args()


def build_argv(args) -> list[str]:
    argv = [sys.argv[0]]
    if args.cadence == "all":
        argv.append("--all")
    else:
        argv.extend(["--cadence", args.cadence])

    if args.limit is not None:
        argv.extend(["--limit", str(args.limit)])
    if args.workers is not None:
        argv.extend(["--workers", str(args.workers)])
    if args.dry_run:
        argv.append("--dry-run")
    if args.llm_all:
        argv.append("--llm-all")
    if args.skip_preflight:
        argv.append("--skip-preflight")
    return argv


if __name__ == "__main__":
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
    )
    args = parse_args()
    sys.argv = build_argv(args)
    run_main()
