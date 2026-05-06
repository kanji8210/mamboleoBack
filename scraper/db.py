"""SQLite-backed deduplication — tracks article URLs already processed.

Optimisations vs naïve "open + SELECT + close" per call:
  • One persistent connection per (process, db_path) — saves the SQLite
    open/parse-schema overhead on every URL check.
  • In-memory set primed once from disk → is_seen() is O(1), zero SQL.
  • mark_seen() is a write-through: updates set immediately, hits disk once.
  • WAL journal + synchronous=NORMAL → ~5× faster writes vs defaults,
    while still durable across process crashes (loses at most last txn).
"""
from __future__ import annotations

import sqlite3
import threading
from pathlib import Path

# _seen_set() populates the cache while calling _conn(), so the same thread
# can legitimately re-enter this lock during first-use initialisation.
_lock = threading.RLock()
_conn_cache: dict[str, sqlite3.Connection] = {}
_seen_cache: dict[str, set[str]] = {}


def _conn(db_path: Path) -> sqlite3.Connection:
    key = str(db_path)
    con = _conn_cache.get(key)
    if con is not None:
        return con
    with _lock:
        con = _conn_cache.get(key)
        if con is None:
            db_path.parent.mkdir(parents=True, exist_ok=True)
            con = sqlite3.connect(str(db_path), check_same_thread=False, isolation_level=None)
            con.execute(
                "CREATE TABLE IF NOT EXISTS seen "
                "(url TEXT PRIMARY KEY, created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP)"
            )
            con.execute("PRAGMA journal_mode=WAL")
            con.execute("PRAGMA synchronous=NORMAL")
            _conn_cache[key] = con
        return con


def _seen_set(db_path: Path) -> set[str]:
    """Return the in-memory seen-URL set, lazily populated from disk."""
    key = str(db_path)
    cache = _seen_cache.get(key)
    if cache is not None:
        return cache
    with _lock:
        cache = _seen_cache.get(key)
        if cache is None:
            con = _conn(db_path)
            cache = {row[0] for row in con.execute("SELECT url FROM seen")}
            _seen_cache[key] = cache
        return cache


def is_seen(url: str, db_path: Path) -> bool:
    return url in _seen_set(db_path)


def mark_seen(url: str, db_path: Path) -> None:
    cache = _seen_set(db_path)
    if url in cache:
        return
    _conn(db_path).execute("INSERT OR IGNORE INTO seen (url) VALUES (?)", (url,))
    cache.add(url)


def count(db_path: Path) -> int:
    return len(_seen_set(db_path))
