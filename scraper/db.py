"""SQLite-backed deduplication — tracks article URLs already processed."""
from __future__ import annotations

import sqlite3
from pathlib import Path


def _conn(db_path: Path) -> sqlite3.Connection:
    con = sqlite3.connect(db_path)
    con.execute(
        "CREATE TABLE IF NOT EXISTS seen "
        "(url TEXT PRIMARY KEY, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)"
    )
    con.commit()
    return con


def is_seen(url: str, db_path: Path) -> bool:
    with _conn(db_path) as con:
        row = con.execute("SELECT 1 FROM seen WHERE url = ?", (url,)).fetchone()
        return row is not None


def mark_seen(url: str, db_path: Path) -> None:
    with _conn(db_path) as con:
        con.execute("INSERT OR IGNORE INTO seen (url) VALUES (?)", (url,))
        con.commit()
