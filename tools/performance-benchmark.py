"""Synthetic query-shape benchmark for Access Analytics Plus.

SQLite timings are comparative evidence, not WordPress/MySQL production timings.
The temporary database is always removed after the run.
"""

from __future__ import annotations

import json
import sqlite3
import statistics
import time
from datetime import datetime, timedelta, timezone
from pathlib import Path


DB_PATH = Path(__file__).with_name(".phase2-5-benchmark.sqlite")
CHECKPOINTS = (100_000, 500_000, 1_000_000)
NOW = datetime(2026, 8, 26, 12, 0, tzinfo=timezone.utc)


def stamp(day_offset: int, minute: int = 0) -> str:
    value = NOW - timedelta(days=day_offset, minutes=minute)
    return value.strftime("%Y-%m-%d %H:%M:%S")


def setup(connection: sqlite3.Connection) -> None:
    connection.executescript(
        """
        PRAGMA journal_mode=OFF;
        PRAGMA synchronous=OFF;
        PRAGMA temp_store=MEMORY;
        CREATE TABLE sessions (
            id INTEGER PRIMARY KEY,
            visitor_key TEXT NOT NULL,
            started_at TEXT NOT NULL,
            last_seen_at TEXT NOT NULL,
            pageview_count INTEGER NOT NULL,
            referrer_type TEXT NOT NULL,
            referrer_host TEXT NOT NULL,
            search_source TEXT NOT NULL,
            device_type TEXT NOT NULL
        );
        CREATE INDEX visitor_started ON sessions(visitor_key, started_at);
        CREATE INDEX sessions_started_at ON sessions(started_at);
        CREATE INDEX sessions_last_seen_at ON sessions(last_seen_at);
        CREATE INDEX referrer_started ON sessions(referrer_type, started_at);
        CREATE INDEX started_report ON sessions(started_at, visitor_key, device_type, referrer_type);
        CREATE INDEX quality_period ON sessions(started_at, last_seen_at, pageview_count, id);
        CREATE TABLE pageviews (
            id INTEGER PRIMARY KEY,
            session_id INTEGER NOT NULL,
            page_id INTEGER NOT NULL,
            viewed_at TEXT NOT NULL,
            engaged_seconds INTEGER NOT NULL
        );
        CREATE INDEX pageviews_session_id ON pageviews(session_id);
        CREATE INDEX pageviews_viewed_at ON pageviews(viewed_at);
        CREATE INDEX page_viewed ON pageviews(page_id, viewed_at);
        CREATE INDEX viewed_report ON pageviews(viewed_at, session_id, page_id);
        CREATE INDEX session_engagement ON pageviews(session_id, engaged_seconds);
        CREATE TABLE pages (id INTEGER PRIMARY KEY, title TEXT NOT NULL, path TEXT NOT NULL);
        CREATE TABLE daily (stat_date TEXT PRIMARY KEY, visitors INTEGER, visits INTEGER, pageviews INTEGER);
        CREATE TABLE exclusions_daily (stat_date TEXT, reason TEXT, excluded_count INTEGER, PRIMARY KEY(stat_date, reason));
        """
    )
    connection.executemany(
        "INSERT INTO pages(id, title, path) VALUES (?, ?, ?)",
        ((number, f"Page {number}", f"/page-{number}/") for number in range(1, 501)),
    )
    connection.executemany(
        "INSERT INTO daily VALUES (?, ?, ?, ?)",
        (
            ((NOW - timedelta(days=day)).strftime("%Y-%m-%d"), 1000 + day, 1200 + day, 3000 + day)
            for day in range(120)
        ),
    )
    reasons = ("bot", "user_role", "ip", "rate_limit", "origin")
    connection.executemany(
        "INSERT INTO exclusions_daily VALUES (?, ?, ?)",
        (
            ((NOW - timedelta(days=day)).strftime("%Y-%m-%d"), reason, (day + index) % 23)
            for day in range(120)
            for index, reason in enumerate(reasons)
        ),
    )
    connection.commit()


def grow(connection: sqlite3.Connection, previous: int, target: int) -> None:
    previous_sessions = (previous + 2) // 3
    target_sessions = (target + 2) // 3
    sources = ("direct", "search", "social", "external")
    devices = ("mobile", "desktop", "mobile", "tablet")
    connection.executemany(
        "INSERT INTO sessions VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)",
        (
            (
                session_id,
                f"v{session_id // 2:064d}",
                stamp(session_id % 90, session_id % 1440),
                stamp(session_id % 90, max(0, session_id % 1440 - 4)),
                3,
                sources[session_id % len(sources)],
                "example-referrer.test" if session_id % 4 == 3 else "",
                "Google" if session_id % 4 == 1 else "",
                devices[session_id % len(devices)],
            )
            for session_id in range(previous_sessions + 1, target_sessions + 1)
        ),
    )
    connection.executemany(
        "INSERT INTO pageviews VALUES (?, ?, ?, ?, ?)",
        (
            (
                pageview_id,
                (pageview_id + 2) // 3,
                1 + pageview_id % 500,
                stamp(((pageview_id + 2) // 3) % 90, pageview_id % 1440),
                pageview_id % 181,
            )
            for pageview_id in range(previous + 1, target + 1)
        ),
    )
    connection.commit()
    connection.execute("ANALYZE")


def median_ms(connection: sqlite3.Connection, sql: str, parameters: tuple[str, ...]) -> float:
    timings = []
    for _ in range(3):
        started = time.perf_counter()
        connection.execute(sql, parameters).fetchall()
        timings.append((time.perf_counter() - started) * 1000)
    return round(statistics.median(timings), 2)


def measure(connection: sqlite3.Connection) -> dict[str, float]:
    start_30 = stamp(29)[:10] + " 00:00:00"
    end = stamp(-1)[:10] + " 00:00:00"
    ended = stamp(0, 30)
    start_7 = stamp(6)[:10] + " 00:00:00"
    start_day = start_30[:10]
    end_day = end[:10]
    queries = {
        "metrics_30d": (
            "SELECT COUNT(*), COUNT(DISTINCT visitor_key) FROM sessions WHERE started_at>=? AND started_at<?",
            (start_30, end),
        ),
        "pageviews_30d": (
            "SELECT COUNT(*) FROM pageviews WHERE viewed_at>=? AND viewed_at<?",
            (start_30, end),
        ),
        "quality_30d": (
            "SELECT COUNT(*), COALESCE(SUM(ended.is_bounce),0), COALESCE(SUM(ended.engaged_seconds),0) FROM ("
            "SELECT s.id, CASE WHEN s.pageview_count=1 THEN 1 ELSE 0 END is_bounce, COALESCE(SUM(pv.engaged_seconds),0) engaged_seconds "
            "FROM sessions s LEFT JOIN pageviews pv ON pv.session_id=s.id "
            "WHERE s.started_at>=? AND s.started_at<? AND s.last_seen_at<? GROUP BY s.id,s.pageview_count) ended",
            (start_30, end, ended),
        ),
        "popular_pages_30d": (
            "SELECT p.title,p.path,COUNT(*) total FROM pageviews pv JOIN pages p ON p.id=pv.page_id "
            "WHERE pv.viewed_at>=? AND pv.viewed_at<? GROUP BY pv.page_id,p.title,p.path ORDER BY total DESC LIMIT 5",
            (start_30, end),
        ),
        "sources_30d": (
            "SELECT referrer_type,COUNT(*) total FROM sessions WHERE started_at>=? AND started_at<? "
            "GROUP BY referrer_type ORDER BY total DESC",
            (start_30, end),
        ),
        "devices_30d": (
            "SELECT device_type,COUNT(*) total FROM sessions WHERE started_at>=? AND started_at<? GROUP BY device_type",
            (start_30, end),
        ),
        "dashboard_top_page_7d": (
            "SELECT p.title,p.path,COUNT(*) total FROM pageviews pv JOIN pages p ON p.id=pv.page_id "
            "WHERE pv.viewed_at>=? AND pv.viewed_at<? GROUP BY pv.page_id,p.title,p.path ORDER BY total DESC LIMIT 5",
            (start_7, end),
        ),
        "daily_timeseries": (
            "SELECT stat_date,visitors,visits,pageviews FROM daily WHERE stat_date>=? AND stat_date<? ORDER BY stat_date",
            (start_day, end_day),
        ),
        "exclusions": (
            "SELECT reason,SUM(excluded_count) FROM exclusions_daily WHERE stat_date>=? AND stat_date<? GROUP BY reason",
            (start_day, end_day),
        ),
    }
    return {name: median_ms(connection, sql, params) for name, (sql, params) in queries.items()}


def main() -> None:
    if DB_PATH.exists():
        DB_PATH.unlink()
    results: dict[str, dict[str, float]] = {}
    try:
        connection = sqlite3.connect(DB_PATH)
        setup(connection)
        previous = 0
        for checkpoint in CHECKPOINTS:
            grow(connection, previous, checkpoint)
            results[str(checkpoint)] = measure(connection)
            previous = checkpoint
        connection.close()
        print(json.dumps(results, ensure_ascii=False, indent=2))
    finally:
        if DB_PATH.exists():
            DB_PATH.unlink()


if __name__ == "__main__":
    main()
