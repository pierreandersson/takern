#!/usr/bin/env python3
"""Incremental update of the Tåkern bird observation database.

Fetches only new observations since the last date in the database,
making it fast and API-friendly for scheduled runs.

Usage:
    python scripts/update_database.py              # Update since last obs
    python scripts/update_database.py --days 30    # Force last 30 days
    python scripts/update_database.py --dry-run    # Just show what would be fetched

Designed to run as a cron job or scheduled task on the server.
"""

import argparse
import json
import sqlite3
import sys
import time
from datetime import datetime, timedelta
from pathlib import Path

# Reuse the heavy lifting from build_database
PROJECT_DIR = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(PROJECT_DIR / "scripts"))

from build_database import (
    DB_PATH,
    CONFIG_PATH,
    download_period,
    init_db,
    load_config,
    print_db_summary,
)

LOG_PATH = PROJECT_DIR / "update.log"


def log(msg):
    ts = datetime.now().strftime("%Y-%m-%d %H:%M:%S")
    line = f"[{ts}] {msg}"
    print(line)
    with open(LOG_PATH, "a", encoding="utf-8") as f:
        f.write(line + "\n")


def get_last_date(conn):
    """Get the most recent observation date in the database."""
    row = conn.execute(
        "SELECT MAX(event_start_date) FROM observations"
    ).fetchone()
    if row and row[0]:
        return row[0][:10]  # YYYY-MM-DD
    return None


def main():
    parser = argparse.ArgumentParser(
        description="Inkrementell uppdatering av Tåkern-databasen"
    )
    parser.add_argument(
        "--days", type=int, default=None,
        help="Tvinga hämtning av senaste N dagar (annars sedan sista obs)"
    )
    parser.add_argument(
        "--dry-run", action="store_true",
        help="Visa vad som skulle hämtas utan att ladda ner"
    )
    parser.add_argument(
        "--overlap", type=int, default=3,
        help="Antal dagars överlapp för att fånga sena rapporter (default: 3)"
    )
    args = parser.parse_args()

    if not CONFIG_PATH.exists():
        log(f"Saknar {CONFIG_PATH} – kan inte köra uppdatering")
        sys.exit(1)

    if not DB_PATH.exists():
        log("Databasen finns inte. Kör build_database.py först.")
        sys.exit(1)

    config = load_config()
    conn = sqlite3.connect(str(DB_PATH))
    conn.row_factory = sqlite3.Row

    today = datetime.now().strftime("%Y-%m-%d")

    if args.days:
        date_from = (datetime.now() - timedelta(days=args.days)).strftime("%Y-%m-%d")
        log(f"Tvingad hämtning: senaste {args.days} dagar ({date_from} → {today})")
    else:
        last_date = get_last_date(conn)
        if not last_date:
            log("Databasen är tom. Kör build_database.py först.")
            conn.close()
            sys.exit(1)

        # Go back a few days to catch late-reported observations
        overlap_date = datetime.strptime(last_date, "%Y-%m-%d") - timedelta(days=args.overlap)
        date_from = overlap_date.strftime("%Y-%m-%d")
        log(f"Senaste obs i DB: {last_date}")
        log(f"Hämtar från: {date_from} (med {args.overlap} dagars överlapp)")

    if date_from == today:
        log("Databasen är redan uppdaterad.")
        conn.close()
        return

    count_before = conn.execute("SELECT COUNT(*) FROM observations").fetchone()[0]

    if args.dry_run:
        from build_database import get_count
        count = get_count(config, date_from, today)
        log(f"Dry run: {count:,} observationer att hämta ({date_from} → {today})")
        conn.close()
        return

    # Re-open through init_db to ensure schema is current
    conn.close()
    conn = init_db()

    log(f"Startar uppdatering: {date_from} → {today}")
    try:
        inserted = download_period(config, conn, date_from, today)
    except Exception as e:
        log(f"Fel vid uppdatering: {e}")
        conn.close()
        sys.exit(1)

    count_after = conn.execute("SELECT COUNT(*) FROM observations").fetchone()[0]
    net_new = count_after - count_before

    log(f"Klart! {net_new:,} nya observationer tillagda (totalt: {count_after:,})")
    conn.close()


if __name__ == "__main__":
    main()
