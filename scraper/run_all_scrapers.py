"""
Run all Mamboleo scrapers for all mapped sources.

Scheduled-entrypoint wrapper. Two cadences:

    python run_all_scrapers.py --cadence fast   # every 15 min — breaking outlets
    python run_all_scrapers.py --cadence slow   # hourly       — everything else
    python run_all_scrapers.py --all            # one-off full sweep

Cadence is defined per-source in sources.yaml (`cadence: fast | slow`).

Windows Task Scheduler consumes this via:
    windows_scrape_task_fast.xml  → every 15 min
    windows_scrape_task_slow.xml  → every hour
"""
from __future__ import annotations

import argparse
import logging
import sys

from main import main as run_main


def parse_args():
    p = argparse.ArgumentParser(description="Mamboleo scraper scheduler entrypoint")
    p.add_argument("--cadence", choices=["fast", "slow"], default=None)
    p.add_argument("--all", action="store_true")
    p.add_argument("--limit", type=int, default=None)
    p.add_argument("--dry-run", action="store_true")
    return p.parse_args()


def build_argv(args) -> list:
    """Translate wrapper flags into main.py's argv."""
    argv = [sys.argv[0]]
    if args.all:
        argv.append("--all")
    elif args.cadence:
        argv.extend(["--cadence", args.cadence])
    else:
        argv.append("--all")  # back-compat default
    if args.limit:
        argv.extend(["--limit", str(args.limit)])
    if args.dry_run:
        argv.append("--dry-run")
    return argv


if __name__ == "__main__":
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
    )
    args = parse_args()
    sys.argv = build_argv(args)
    run_main()
