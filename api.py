#!/usr/bin/env python3
"""Flask API for Tåkern bird observation statistics.

Serves data from the SQLite database for the frontend.

Usage:
    python api.py                    # Dev server on port 5000
    python api.py --port 8080        # Custom port
"""

import sqlite3
from collections import defaultdict
from datetime import datetime, timedelta
from pathlib import Path

from flask import Flask, g, jsonify, request

DB_PATH = Path(__file__).resolve().parent / "takern_observations.db"

app = Flask(__name__, static_folder="public", static_url_path="")


def get_db():
    if "db" not in g:
        g.db = sqlite3.connect(str(DB_PATH))
        g.db.row_factory = sqlite3.Row
    return g.db


@app.teardown_appcontext
def close_db(exc):
    db = g.pop("db", None)
    if db is not None:
        db.close()


# ─── Helpers ─────────────────────────────────────────────────────────

def doy_to_str(doy):
    """Day-of-year number to 'DD mon' string."""
    if doy is None:
        return None
    d = datetime(2024, 1, 1) + timedelta(days=doy - 1)
    months_sv = ["jan", "feb", "mar", "apr", "maj", "jun",
                 "jul", "aug", "sep", "okt", "nov", "dec"]
    return f"{d.day} {months_sv[d.month - 1]}"


# ─── Routes ──────────────────────────────────────────────────────────

@app.route("/")
def index():
    return app.send_static_file("index.html")


@app.route("/api/overview")
def overview():
    db = get_db()

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

    # Heatmap: observations per month per year
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

    # Species richness per month (unique species per calendar month, averaged)
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

    # New species per year (first year each taxon was observed)
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

    # Top observers per year
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

    # Time of day (overview, exclude 00:00)
    time_of_day = {}
    for r in db.execute("""
        SELECT CAST(SUBSTR(start_time, 1, 2) AS INTEGER) h, COUNT(*) n
        FROM observations
        WHERE start_time IS NOT NULL AND start_time != '00:00'
        GROUP BY h ORDER BY h
    """):
        time_of_day[r["h"]] = r["n"]

    return jsonify({
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
    })


@app.route("/api/geo-heatmap")
def geo_heatmap():
    db = get_db()
    taxon_id = request.args.get("taxon_id", type=int)

    if taxon_id:
        rows = db.execute("""
            SELECT ROUND(latitude, 3) lat, ROUND(longitude, 3) lng, COUNT(*) n
            FROM observations
            WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND taxon_id = ?
            GROUP BY lat, lng
        """, (taxon_id,)).fetchall()
    else:
        rows = db.execute("""
            SELECT ROUND(latitude, 3) lat, ROUND(longitude, 3) lng, COUNT(*) n
            FROM observations
            WHERE latitude IS NOT NULL AND longitude IS NOT NULL
            GROUP BY lat, lng
        """).fetchall()

    points = [[r["lat"], r["lng"], r["n"]] for r in rows]
    return jsonify({"points": points})


@app.route("/api/species")
def species_list():
    db = get_db()
    q = request.args.get("q", "").strip().lower()

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

    species = []
    for r in rows:
        if q and q not in r["vernacular_name"].lower() and q not in r["scientific_name"].lower():
            continue
        species.append({
            "taxon_id": r["taxon_id"],
            "name": r["vernacular_name"],
            "scientific": r["scientific_name"],
            "obs_count": r["obs_count"],
            "max_count": r["max_count"],
            "first_obs": r["first_obs"],
            "last_obs": r["last_obs"],
            "years_present": r["years_present"],
        })

    return jsonify({"species": species})


@app.route("/api/species/<int:taxon_id>")
def species_detail(taxon_id):
    db = get_db()

    info = db.execute("""
        SELECT vernacular_name, scientific_name, family, taxonomic_order,
               redlist_category, COUNT(*) obs_count,
               MIN(event_start_date) first_obs, MAX(event_start_date) last_obs
        FROM observations WHERE taxon_id = ?
    """, (taxon_id,)).fetchone()

    if not info or not info["vernacular_name"]:
        return jsonify({"error": "Art ej hittad"}), 404

    # Trend: observations per year
    per_year = {
        r["y"]: r["n"] for r in db.execute("""
            SELECT SUBSTR(event_start_date, 1, 4) y, COUNT(*) n
            FROM observations WHERE taxon_id = ? GROUP BY y ORDER BY y
        """, (taxon_id,))
    }

    # Season curve: average observations per week
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

    # Phenology: first/last per year
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

    # Max counts per year (with details of the max observation)
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

    # Top localities
    top_localities = [
        {"name": r["locality"], "count": r["n"]}
        for r in db.execute("""
            SELECT locality, COUNT(*) n FROM observations
            WHERE taxon_id = ? AND locality IS NOT NULL AND locality != ''
            GROUP BY locality ORDER BY n DESC LIMIT 5
        """, (taxon_id,))
    ]

    # Time of day distribution (exclude 00:00 – likely missing time data)
    time_of_day = {}
    for r in db.execute("""
        SELECT CAST(SUBSTR(start_time, 1, 2) AS INTEGER) h, COUNT(*) n
        FROM observations
        WHERE taxon_id = ? AND start_time IS NOT NULL AND start_time != '00:00'
        GROUP BY h ORDER BY h
    """, (taxon_id,)):
        time_of_day[r["h"]] = r["n"]

    return jsonify({
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
    })


@app.route("/api/calendar")
def calendar():
    db = get_db()
    week = request.args.get("week", type=int)

    num_years = db.execute(
        "SELECT COUNT(DISTINCT SUBSTR(event_start_date, 1, 4)) FROM observations"
    ).fetchone()[0]

    if week is not None:
        weeks_to_query = [week]
    else:
        # Current week
        weeks_to_query = [int(datetime.now().strftime("%W"))]

    result = {}
    for w in weeks_to_query:
        top = [
            {
                "name": r["vernacular_name"],
                "taxon_id": r["taxon_id"],
                "obs_per_year": round(r["n"] / num_years, 1),
                "avg_count": round(r["avg_count"], 1) if r["avg_count"] else None,
                "max_count": r["max_count"],
            }
            for r in db.execute("""
                SELECT vernacular_name, taxon_id, COUNT(*) n,
                       AVG(CASE WHEN individual_count IS NOT NULL THEN individual_count END) avg_count,
                       MAX(individual_count) max_count
                FROM observations
                WHERE CAST(STRFTIME('%W', event_start_date) AS INTEGER) = ?
                  AND vernacular_name IS NOT NULL
                GROUP BY taxon_id ORDER BY n DESC LIMIT 10
            """, (w,))
        ]

        total_obs = db.execute("""
            SELECT COUNT(*) FROM observations
            WHERE CAST(STRFTIME('%W', event_start_date) AS INTEGER) = ?
        """, (w,)).fetchone()[0]

        species_count = db.execute("""
            SELECT COUNT(DISTINCT taxon_id) FROM observations
            WHERE CAST(STRFTIME('%W', event_start_date) AS INTEGER) = ?
        """, (w,)).fetchone()[0]

        result[str(w)] = {
            "total_obs_avg": round(total_obs / num_years, 1) if num_years else 0,
            "species_count": species_count,
            "top_species": top,
        }

    return jsonify({"num_years": num_years, "weeks": result})


if __name__ == "__main__":
    import argparse
    parser = argparse.ArgumentParser()
    parser.add_argument("--port", type=int, default=5000)
    parser.add_argument("--debug", action="store_true")
    args = parser.parse_args()
    app.run(host="0.0.0.0", port=args.port, debug=args.debug)
