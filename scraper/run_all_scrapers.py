"""Backward-compatible wrapper for the canonical pipeline entrypoint.

Prefer using run_pipeline.py directly. This file is kept so older scheduled
tasks and scripts continue to work unchanged.
"""
from __future__ import annotations

import argparse
import logging
import sys

from run_pipeline import build_argv as build_pipeline_argv


def parse_args():
    p = argparse.ArgumentParser(description="Mamboleo scraper scheduler entrypoint")
    p.add_argument("--cadence", choices=["fast", "slow"], default=None)
    p.add_argument("--all", action="store_true")
    p.add_argument("--limit", type=int, default=None)
    p.add_argument("--dry-run", action="store_true")
    return p.parse_args()


def build_argv(args) -> list[str]:
    """Translate legacy flags into run_pipeline.py-compatible argv."""
    cadence = "all"
    if args.all:
        cadence = "all"
    elif args.cadence:
        cadence = args.cadence

    class _Args:
        def __init__(self):
            self.cadence = cadence
            self.limit = args.limit
            self.workers = None
            self.dry_run = args.dry_run
            self.llm_all = False
            self.skip_preflight = False

    return build_pipeline_argv(_Args())


if __name__ == "__main__":
    logging.basicConfig(
        level=logging.INFO,
        format="%(asctime)s %(levelname)s %(name)s: %(message)s",
    )
    args = parse_args()
    sys.argv = build_argv(args)
    from main import main as run_main
    run_main()
