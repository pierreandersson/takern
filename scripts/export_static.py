#!/usr/bin/env python3
"""Export database data as static JSON files for deployment on shared hosting.

Generates all the JSON files that statistik.html needs, so it can run
without a Python backend (e.g., on Websupport alongside WordPress).

Usage:
    python scripts/export_static.py                    # Export to public/data/
    python scripts/export_static.py --output /path/    # Custom output dir

Run this after update_database.py to refresh the static files.
"""

import argparse
import json
import sqlite3
import sys
from collections import defaultdict
from datetime import datetime, timedelta
from pathlib import Path

PROJECT_DIR = Path(__file__).resolve().parent.parent
DB_PATH = PROJECT_DIR / "takern_observations.db"
DEFAULT_OUTPUT = PROJECT_DIR / "public" / "data"


def get_db(db_path):
    conn = sqlite3.connect(str(db_path))
    conn.row_factory = sqlite3.Row
    return conn


def doy_to_str(doy):
    if doy is None:
        return None
    d = datetime(2024, 1, 1) + timedelta(days=doy - 1)
    months_sv = ["jan", "feb", "mar", "apr", "maj", "jun",
                 "jul", "aug", "sep", "okt", "nov", "dec"]
    return f"{d.day} {months_sv[d.month - 1]}"


def export_overview(db, output_dir):
    """Export /api/overview as overview.json."""
    total = db.execute("SELECT COUNT(*) c FROM observations").fetchone()["c"]
    species = db.execute(
        "SELECT COUNT(DISTINCT taxon_id) c FROM observations"
    ).fetchone()["c"]
    observers = db.execute(
        "SELECT COUNT(DISTINCT recorded_by) c FROM observations WHERE recorded_by IS NOT NULL"
    ).fetchone()["c"]
    date_range = db.execute(
        "SELECT MIN(event_start_date) mn, MAX(event_start_date) mx FROM observations"
    ).fetchone()

    per_year = {
        r["y"]: r["n"] for r in db.execute("""
            SELECT SUBSTR(event_start_date, 1, 4) y, COUNT(*) n
            FROM observations GROUP BY y ORDER BY y
        """)
    }

    top_species = [
        {"taxon_id": r["taxon_id"], "name": r["vernacular_name"],
         "scientific": r["scientific_name"], "count": r["n"]}
        for r in db.execute("""
            SELECT taxon_id, vernacular_name, scientific_name, COUNT(*) n
            FROM observations WHERE vernacular_name IS NOT NULL
            GROUP BY taxon_id ORDER BY n DESC LIMIT 20
        """)
    ]

    records = [
        {"name": r["vernacular_name"], "taxon_id": r["taxon_id"],
         "count": r["individual_count"], "date": r["event_start_date"],
         "locality": r["locality"], "url": r["url"]}
        for r in db.execute("""
            SELECT vernacular_name, taxon_id, individual_count,
                   event_start_date, locality, url
            FROM observations WHERE individual_count IS NOT NULL
            ORDER BY individual_count DESC LIMIT 20
        """)
    ]

    top_localities = [
        {"name": r["locality"], "count": r["n"]}
        for r in db.execute("""
            SELECT locality, COUNT(*) n FROM observations
            WHERE locality IS NOT NULL AND locality != ''
            GROUP BY locality ORDER BY n DESC LIMIT 15
        """)
    ]

    heatmap = {}
    for r in db.execute("""
        SELECT SUBSTR(event_start_date, 1, 4) y,
               CAST(SUBSTR(event_start_date, 6, 2) AS INTEGER) m,
               COUNT(*) n
        FROM observations
        WHERE event_start_date IS NOT NULL
        GROUP BY y, m ORDER BY y, m
    """):
        heatmap.setdefault(r["y"], {})[str(r["m"])] = r["n"]

    richness_per_month = {}
    for r in db.execute("""
        SELECT m, ROUND(AVG(n), 1) avg_species FROM (
            SELECT SUBSTR(event_start_date, 1, 4) y,
                   CAST(SUBSTR(event_start_date, 6, 2) AS INTEGER) m,
                   COUNT(DISTINCT taxon_id) n
            FROM observations WHERE event_start_date IS NOT NULL
            GROUP BY y, m
        ) GROUP BY m ORDER BY m
    """):
        richness_per_month[r["m"]] = r["avg_species"]

    new_species_per_year = {}
    for r in db.execute("""
        SELECT first_year, COUNT(*) n, GROUP_CONCAT(vernacular_name, ', ') names
        FROM (
            SELECT taxon_id, vernacular_name,
                   MIN(SUBSTR(event_start_date, 1, 4)) first_year
            FROM observations
            WHERE vernacular_name IS NOT NULL
            GROUP BY taxon_id
        )
        GROUP BY first_year ORDER BY first_year
    """):
        new_species_per_year[r["first_year"]] = {
            "count": r["n"],
            "names": r["names"][:500] if r["names"] else "",
        }

    top_observers = {}
    for r in db.execute("""
        SELECT SUBSTR(event_start_date, 1, 4) y,
               recorded_by, COUNT(*) n
        FROM observations
        WHERE recorded_by IS NOT NULL
        GROUP BY y, recorded_by
        ORDER BY y, n DESC
    """):
        year_list = top_observers.setdefault(r["y"], [])
        if len(year_list) < 5:
            year_list.append({"name": r["recorded_by"], "count": r["n"]})

    time_of_day = {}
    for r in db.execute("""
        SELECT CAST(SUBSTR(start_time, 1, 2) AS INTEGER) h, COUNT(*) n
        FROM observations
        WHERE start_time IS NOT NULL AND start_time != '00:00'
        GROUP BY h ORDER BY h
    """):
        time_of_day[r["h"]] = r["n"]

    data = {
        "total_observations": total,
        "total_species": species,
        "total_observers": observers,
        "date_range": {"from": date_range["mn"], "to": date_range["mx"]},
        "observations_per_year": per_year,
        "top_species": top_species,
        "record_counts": records,
        "top_localities": top_localities,
        "heatmap": heatmap,
        "richness_per_month": richness_per_month,
        "new_species_per_year": new_species_per_year,
        "top_observers": top_observers,
        "time_of_day": time_of_day,
    }

    write_json(output_dir / "overview.json", data)
    return data


def export_species_list(db, output_dir):
    """Export /api/species as species.json."""
    rows = db.execute("""
        SELECT taxon_id, vernacular_name, scientific_name,
               COUNT(*) obs_count,
               MAX(individual_count) max_count,
               MIN(event_start_date) first_obs,
               MAX(event_start_date) last_obs,
               COUNT(DISTINCT SUBSTR(event_start_date, 1, 4)) years_present
        FROM observations
        WHERE vernacular_name IS NOT NULL
        GROUP BY taxon_id
        ORDER BY obs_count DESC
    """).fetchall()

    species = [
        {
            "taxon_id": r["taxon_id"],
            "name": r["vernacular_name"],
            "scientific": r["scientific_name"],
            "obs_count": r["obs_count"],
            "max_count": r["max_count"],
            "first_obs": r["first_obs"],
            "last_obs": r["last_obs"],
            "years_present": r["years_present"],
        }
        for r in rows
    ]

    write_json(output_dir / "species.json", {"species": species})
    return species


def export_species_detail(db, output_dir, taxon_id):
    """Export /api/species/<taxon_id> as species/<taxon_id>.json."""
    info = db.execute("""
        SELECT vernacular_name, scientific_name, family, taxonomic_order,
               redlist_category, COUNT(*) obs_count,
               MIN(event_start_date) first_obs, MAX(event_start_date) last_obs
        FROM observations WHERE taxon_id = ?
    """, (taxon_id,)).fetchone()

    if not info or not info["vernacular_name"]:
        return None

    per_year = {
        r["y"]: r["n"] for r in db.execute("""
            SELECT SUBSTR(event_start_date, 1, 4) y, COUNT(*) n
            FROM observations WHERE taxon_id = ? GROUP BY y ORDER BY y
        """, (taxon_id,))
    }

    num_years = len(per_year)
    week_counts = defaultdict(list)
    for r in db.execute("""
        SELECT SUBSTR(event_start_date, 1, 4) y,
               CAST(STRFTIME('%W', event_start_date) AS INTEGER) w,
               COUNT(*) n
        FROM observations WHERE taxon_id = ? GROUP BY y, w
    """, (taxon_id,)):
        week_counts[r["w"]].append(r["n"])

    season_curve = {}
    for week in range(53):
        vals = week_counts.get(week, [])
        avg = sum(vals) / num_years if num_years else 0
        if avg > 0:
            season_curve[week] = round(avg, 1)

    phenology = {}
    first_days, last_days = [], []
    for r in db.execute("""
        SELECT SUBSTR(event_start_date, 1, 4) y,
               MIN(event_start_date) first, MAX(event_start_date) last
        FROM observations WHERE taxon_id = ? GROUP BY y ORDER BY y
    """, (taxon_id,)):
        phenology[r["y"]] = {"first": r["first"], "last": r["last"]}
        try:
            first_days.append(datetime.strptime(r["first"], "%Y-%m-%d").timetuple().tm_yday)
        except (ValueError, TypeError):
            pass
        try:
            last_days.append(datetime.strptime(r["last"], "%Y-%m-%d").timetuple().tm_yday)
        except (ValueError, TypeError):
            pass

    phenology_summary = {
        "avg_first": doy_to_str(round(sum(first_days) / len(first_days))) if first_days else None,
        "avg_last": doy_to_str(round(sum(last_days) / len(last_days))) if last_days else None,
        "earliest_ever": doy_to_str(min(first_days)) if first_days else None,
        "latest_ever": doy_to_str(max(last_days)) if last_days else None,
    }

    max_counts = {}
    for r in db.execute("""
        SELECT o.y, o.mx, o.tot, d.event_start_date AS date,
               d.locality, d.url
        FROM (
            SELECT SUBSTR(event_start_date, 1, 4) y,
                   MAX(individual_count) mx, SUM(individual_count) tot
            FROM observations
            WHERE taxon_id = ? AND individual_count IS NOT NULL
            GROUP BY y
        ) o
        LEFT JOIN observations d ON d.taxon_id = ?
            AND SUBSTR(d.event_start_date, 1, 4) = o.y
            AND d.individual_count = o.mx
        ORDER BY o.y
    """, (taxon_id, taxon_id)):
        if r["y"] not in max_counts:
            max_counts[r["y"]] = {
                "max": r["mx"], "total": r["tot"],
                "date": r["date"], "locality": r["locality"],
                "url": r["url"],
            }

    top_localities = [
        {"name": r["locality"], "count": r["n"]}
        for r in db.execute("""
            SELECT locality, COUNT(*) n FROM observations
            WHERE taxon_id = ? AND locality IS NOT NULL AND locality != ''
            GROUP BY locality ORDER BY n DESC LIMIT 5
        """, (taxon_id,))
    ]

    time_of_day = {}
    for r in db.execute("""
        SELECT CAST(SUBSTR(start_time, 1, 2) AS INTEGER) h, COUNT(*) n
        FROM observations
        WHERE taxon_id = ? AND start_time IS NOT NULL AND start_time != '00:00'
        GROUP BY h ORDER BY h
    """, (taxon_id,)):
        time_of_day[r["h"]] = r["n"]

    data = {
        "taxon_id": taxon_id,
        "name": info["vernacular_name"],
        "scientific": info["scientific_name"],
        "family": info["family"],
        "order": info["taxonomic_order"],
        "redlist_category": info["redlist_category"],
        "obs_count": info["obs_count"],
        "first_obs": info["first_obs"],
        "last_obs": info["last_obs"],
        "observations_per_year": per_year,
        "season_curve": season_curve,
        "phenology": phenology,
        "phenology_summary": phenology_summary,
        "max_counts_per_year": max_counts,
        "top_localities": top_localities,
        "time_of_day": time_of_day,
    }

    species_dir = output_dir / "species"
    species_dir.mkdir(exist_ok=True)
    write_json(species_dir / f"{taxon_id}.json", data)
    return data


def export_geo_heatmap(db, output_dir, taxon_id=None):
    """Export /api/geo-heatmap as geo-heatmap.json (or per species)."""
    if taxon_id:
        rows = db.execute("""
            SELECT ROUND(latitude, 3) lat, ROUND(longitude, 3) lng, COUNT(*) n
            FROM observations
            WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND taxon_id = ?
            GROUP BY lat, lng
        """, (taxon_id,)).fetchall()
        fname = f"geo-heatmap-{taxon_id}.json"
    else:
        rows = db.execute("""
            SELECT ROUND(latitude, 3) lat, ROUND(longitude, 3) lng, COUNT(*) n
            FROM observations
            WHERE latitude IS NOT NULL AND longitude IS NOT NULL
            GROUP BY lat, lng
        """).fetchall()
        fname = "geo-heatmap.json"

    points = [[r["lat"], r["lng"], r["n"]] for r in rows]
    write_json(output_dir / fname, {"points": points})


def write_json(path, data):
    with open(path, "w", encoding="utf-8") as f:
        json.dump(data, f, ensure_ascii=False, separators=(",", ":"))
    size_kb = path.stat().st_size / 1024
    print(f"  {path.name} ({size_kb:.0f} KB)")


def main():
    parser = argparse.ArgumentParser(
        description="Exportera databasdata som statiska JSON-filer"
    )
    parser.add_argument(
        "--output", type=str, default=None,
        help=f"Utdatakatalog (default: {DEFAULT_OUTPUT})"
    )
    parser.add_argument(
        "--db", type=str, default=None,
        help=f"Sökväg till databasen (default: {DB_PATH})"
    )
    args = parser.parse_args()

    db_path = Path(args.db) if args.db else DB_PATH
    output_dir = Path(args.output) if args.output else DEFAULT_OUTPUT

    if not db_path.exists():
        print(f"Databasen saknas: {db_path}")
        sys.exit(1)

    output_dir.mkdir(parents=True, exist_ok=True)

    db = get_db(db_path)

    print("Exporterar statiska JSON-filer...\n")

    # 1. Overview
    print("1/4 Overview:")
    export_overview(db, output_dir)

    # 2. Species list
    print("\n2/4 Artlista:")
    species = export_species_list(db, output_dir)

    # 3. Geo heatmap (global)
    print("\n3/4 Geografisk heatmap:")
    export_geo_heatmap(db, output_dir)

    # 4. Per-species detail + heatmap
    taxon_ids = [s["taxon_id"] for s in species]
    print(f"\n4/4 Artdetaljer ({len(taxon_ids)} arter):")
    for i, tid in enumerate(taxon_ids):
        export_species_detail(db, output_dir, tid)
        export_geo_heatmap(db, output_dir, taxon_id=tid)
        if (i + 1) % 50 == 0:
            print(f"  ... {i + 1}/{len(taxon_ids)}")

    print(f"\nKlart! Filer sparade i {output_dir}")

    # Total size
    total_size = sum(f.stat().st_size for f in output_dir.rglob("*.json"))
    print(f"Total storlek: {total_size / (1024 * 1024):.1f} MB")

    db.close()


if __name__ == "__main__":
    main()
