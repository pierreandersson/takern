#!/usr/bin/env python3
"""Build a SQLite database of ALL bird observations near Tåkern.

Downloads historical data from Artdatabanken's SOS API using the
/Exports/Download/Csv endpoint (up to 25,000 obs per request).
If a time period has more than 25,000 observations, it is automatically
split into smaller chunks.

The data is stored in a local SQLite database for easy querying.

Usage:
    python scripts/build_database.py              # Last 5 years
    python scripts/build_database.py --years 3    # Last 3 years
    python scripts/build_database.py --count-only # Just count, don't download

Requires config.json with api_key and base_url.
"""

import argparse
import csv
import io
import json
import sqlite3
import sys
import time
import urllib.error
import urllib.request
import zipfile
from datetime import datetime, timedelta
from pathlib import Path

PROJECT_DIR = Path(__file__).resolve().parent.parent
CONFIG_PATH = PROJECT_DIR / "config.json"
DB_PATH = PROJECT_DIR / "takern_observations.db"

# Tåkern coordinates and search radius
TAKERN_LNG = 14.81
TAKERN_LAT = 58.35
RADIUS_M = 15000  # 15 km – same as phenology script

# API limits
EXPORT_LIMIT = 25000
DELAY_S = 1.0  # Be polite between API calls


def load_config():
    with open(CONFIG_PATH) as f:
        return json.load(f)


def build_search_filter(date_from, date_to):
    """Build the search filter body used for both Count and Export."""
    return {
        "taxon": {
            "ids": [4000104],  # Aves
            "includeUnderlyingTaxa": True,
        },
        "date": {
            "startDate": date_from,
            "endDate": date_to,
            "dateFilterType": "BetweenStartDateAndEndDate",
        },
        "geographics": {
            "geometries": [
                {"type": "point", "coordinates": [TAKERN_LNG, TAKERN_LAT]}
            ],
            "maxDistanceFromPoint": RADIUS_M,
            "considerObservationAccuracy": False,
        },
    }


def api_post(config, endpoint, body, accept="application/json", timeout=120):
    """Make a POST request to the SOS API."""
    base_url = config["base_url"].rstrip("/")
    url = f"{base_url}{endpoint}"
    req = urllib.request.Request(
        url,
        data=json.dumps(body).encode("utf-8"),
        method="POST",
        headers={
            "Content-Type": "application/json",
            "Accept": accept,
            "Ocp-Apim-Subscription-Key": config["api_key"],
            "Cache-Control": "no-cache",
        },
    )
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return resp.read()


def get_count(config, date_from, date_to):
    """Count observations for a date range."""
    body = build_search_filter(date_from, date_to)
    raw = api_post(config, "/Observations/Count", body)
    return json.loads(raw.decode("utf-8"))


def download_csv(config, date_from, date_to):
    """Download observations as CSV via the sync export endpoint.

    Returns a list of dicts (one per observation row).
    """
    # Filter parameters go at top level (NOT inside "searchFilter" wrapper)
    body = build_search_filter(date_from, date_to)
    body["output"] = {"fieldSet": "AllWithValues"}
    body["propertyLabelType"] = "PropertyName"
    body["cultureCode"] = "sv-SE"
    raw = api_post(
        config,
        "/Exports/Download/Csv",
        body,
        accept="application/octet-stream",
        timeout=300,  # Exports can take a while
    )

    # The response might be a zip file containing the CSV
    try:
        zf = zipfile.ZipFile(io.BytesIO(raw))
        csv_names = [n for n in zf.namelist() if n.endswith(".csv")]
        if csv_names:
            csv_data = zf.read(csv_names[0]).decode("utf-8-sig")
        else:
            csv_data = raw.decode("utf-8-sig")
    except zipfile.BadZipFile:
        csv_data = raw.decode("utf-8-sig")

    reader = csv.DictReader(io.StringIO(csv_data), delimiter="\t")
    return list(reader), reader.fieldnames


def init_db():
    """Create the SQLite database and tables."""
    conn = sqlite3.connect(str(DB_PATH))
    conn.execute("PRAGMA journal_mode=WAL")
    conn.execute("""
        CREATE TABLE IF NOT EXISTS observations (
            occurrence_id TEXT PRIMARY KEY,
            taxon_id INTEGER,
            scientific_name TEXT,
            vernacular_name TEXT,
            individual_count INTEGER,
            event_start_date TEXT,
            event_end_date TEXT,
            start_time TEXT,
            latitude REAL,
            longitude REAL,
            locality TEXT,
            municipality TEXT,
            parish TEXT,
            county TEXT,
            recorded_by TEXT,
            reported_by TEXT,
            remarks TEXT,
            activity TEXT,
            bird_nest_activity_id INTEGER,
            sex TEXT,
            life_stage TEXT,
            family TEXT,
            taxonomic_order TEXT,
            is_redlisted INTEGER,
            redlist_category TEXT,
            verification_status TEXT,
            url TEXT,
            dataset_name TEXT,
            raw_data TEXT
        )
    """)
    conn.execute("""
        CREATE INDEX IF NOT EXISTS idx_obs_date
        ON observations(event_start_date)
    """)
    conn.execute("""
        CREATE INDEX IF NOT EXISTS idx_obs_taxon
        ON observations(scientific_name)
    """)
    conn.execute("""
        CREATE INDEX IF NOT EXISTS idx_obs_taxon_id
        ON observations(taxon_id)
    """)
    conn.execute("""
        CREATE INDEX IF NOT EXISTS idx_obs_locality
        ON observations(locality)
    """)
    conn.commit()
    return conn


# ── Column name mapping ──
# The CSV export uses property names as column headers.
# We need to map them to our database columns.
# These are educated guesses – the script will print actual column names
# on first run so we can adjust if needed.

# Now using AllWithValues, column names are known from the API.
COLUMN_MAP = {
    "occurrence_id": "OccurrenceId",
    "taxon_id": "DyntaxaTaxonId",
    "scientific_name": "ScientificName",
    "vernacular_name": "VernacularName",
    "individual_count": "IndividualCount",
    "event_start_date": "StartDate",
    "event_end_date": "EndDate",
    "start_time": "PlainStartTime",
    "latitude": "DecimalLatitude",
    "longitude": "DecimalLongitude",
    "locality": "Locality",
    "municipality": "Municipality",
    "parish": "Parish",
    "county": "County",
    "recorded_by": "RecordedBy",
    "reported_by": "ReportedBy",
    "remarks": "OccurrenceRemarks",
    "activity": "Activity",
    "bird_nest_activity_id": "BirdNestActivityId",
    "sex": "Sex",
    "life_stage": "LifeStage",
    "family": "Family",
    "taxonomic_order": "Order",
    "is_redlisted": "TaxonIsRedlisted",
    "redlist_category": "RedlistCategory",
    "verification_status": "VerificationStatus",
    "url": "Url",
    "dataset_name": "DatasetName",
}


def extract_row(row):
    """Extract a database row from a CSV row using COLUMN_MAP."""

    def get(db_col, default=None):
        csv_col = COLUMN_MAP.get(db_col)
        if csv_col and csv_col in row:
            val = row[csv_col]
            if val == "" or val is None:
                return default
            return val
        return default

    # Parse individual count (try OrganismQuantityInt as fallback)
    count_raw = get("individual_count")
    if not count_raw:
        count_raw = row.get("OrganismQuantityInt")
    try:
        count = int(float(count_raw)) if count_raw else None
    except (ValueError, TypeError):
        count = None

    # Parse redlisted
    redlisted_raw = get("is_redlisted")
    if redlisted_raw is not None:
        redlisted = 1 if str(redlisted_raw).lower() in ("true", "1", "yes") else 0
    else:
        redlisted = None

    # Parse latitude/longitude
    try:
        lat = float(get("latitude")) if get("latitude") else None
    except (ValueError, TypeError):
        lat = None
    try:
        lng = float(get("longitude")) if get("longitude") else None
    except (ValueError, TypeError):
        lng = None

    # Parse bird nest activity id
    nest_raw = get("bird_nest_activity_id")
    try:
        nest_id = int(nest_raw) if nest_raw else None
    except (ValueError, TypeError):
        nest_id = None

    return {
        "occurrence_id": get("occurrence_id", ""),
        "taxon_id": get("taxon_id"),
        "scientific_name": get("scientific_name"),
        "vernacular_name": get("vernacular_name"),
        "individual_count": count,
        "event_start_date": get("event_start_date"),
        "event_end_date": get("event_end_date"),
        "start_time": get("start_time"),
        "latitude": lat,
        "longitude": lng,
        "locality": get("locality"),
        "municipality": get("municipality"),
        "parish": get("parish"),
        "county": get("county"),
        "recorded_by": get("recorded_by"),
        "reported_by": get("reported_by"),
        "remarks": get("remarks"),
        "activity": get("activity"),
        "bird_nest_activity_id": nest_id,
        "sex": get("sex"),
        "life_stage": get("life_stage"),
        "family": get("family"),
        "taxonomic_order": get("taxonomic_order"),
        "is_redlisted": redlisted,
        "redlist_category": get("redlist_category"),
        "verification_status": get("verification_status"),
        "url": get("url"),
        "dataset_name": get("dataset_name"),
        "raw_data": json.dumps(row, ensure_ascii=False),
    }


def insert_rows(conn, rows):
    """Insert rows into the database, skipping duplicates."""
    inserted = 0
    skipped = 0
    for row in rows:
        data = extract_row(row)
        if not data["occurrence_id"]:
            skipped += 1
            continue
        try:
            conn.execute("""
                INSERT OR IGNORE INTO observations
                (occurrence_id, taxon_id, scientific_name, vernacular_name,
                 individual_count, event_start_date, event_end_date, start_time,
                 latitude, longitude, locality, municipality, parish, county,
                 recorded_by, reported_by, remarks,
                 activity, bird_nest_activity_id, sex, life_stage,
                 family, taxonomic_order,
                 is_redlisted, redlist_category,
                 verification_status, url, dataset_name, raw_data)
                VALUES
                (:occurrence_id, :taxon_id, :scientific_name, :vernacular_name,
                 :individual_count, :event_start_date, :event_end_date, :start_time,
                 :latitude, :longitude, :locality, :municipality, :parish, :county,
                 :recorded_by, :reported_by, :remarks,
                 :activity, :bird_nest_activity_id, :sex, :life_stage,
                 :family, :taxonomic_order,
                 :is_redlisted, :redlist_category,
                 :verification_status, :url, :dataset_name, :raw_data)
            """, data)
            if conn.total_changes:
                inserted += 1
        except sqlite3.IntegrityError:
            skipped += 1
    conn.commit()
    return inserted, skipped


def split_period(date_from, date_to):
    """Split a date range in half."""
    d1 = datetime.strptime(date_from, "%Y-%m-%d")
    d2 = datetime.strptime(date_to, "%Y-%m-%d")
    mid = d1 + (d2 - d1) / 2
    mid_str = mid.strftime("%Y-%m-%d")
    # First half: date_from to mid, second half: mid+1 to date_to
    next_day = (mid + timedelta(days=1)).strftime("%Y-%m-%d")
    return (date_from, mid_str), (next_day, date_to)


def download_period(config, conn, date_from, date_to, depth=0):
    """Download and store observations for a date range.

    If the count exceeds EXPORT_LIMIT, the period is split recursively.
    """
    indent = "  " * depth
    print(f"{indent}📅 {date_from} → {date_to}", end="", flush=True)

    # First, count
    try:
        count = get_count(config, date_from, date_to)
    except urllib.error.HTTPError as e:
        if e.code == 429:
            print(f" ⚠ Rate limit – väntar 10s...")
            time.sleep(10)
            return download_period(config, conn, date_from, date_to, depth)
        raise
    print(f" → {count:,} observationer", flush=True)
    time.sleep(DELAY_S)

    if count == 0:
        return 0

    if count > EXPORT_LIMIT:
        print(f"{indent}  ↳ Överskrider {EXPORT_LIMIT:,} – delar upp perioden...")
        (from1, to1), (from2, to2) = split_period(date_from, date_to)
        n1 = download_period(config, conn, from1, to1, depth + 1)
        n2 = download_period(config, conn, from2, to2, depth + 1)
        return n1 + n2

    # Download CSV
    print(f"{indent}  ↳ Laddar ner CSV...", end="", flush=True)
    try:
        rows, fieldnames = download_csv(config, date_from, date_to)
    except urllib.error.HTTPError as e:
        if e.code == 429:
            print(f" ⚠ Rate limit – väntar 10s...")
            time.sleep(10)
            return download_period(config, conn, date_from, date_to, depth)
        print(f" ❌ HTTP {e.code}")
        raise

    if not rows:
        print(f" (tomt svar)")
        return 0

    # On first download, print column names for debugging
    if fieldnames and depth == 0:
        print(f"\n  CSV-kolumner ({len(fieldnames)} st):")
        for fn in fieldnames[:10]:
            print(f"    • {fn}")
        if len(fieldnames) > 10:
            print(f"    ... och {len(fieldnames) - 10} till")
        # Verify key columns exist
        has_locality = "Locality" in fieldnames
        print(f"    Locality-fält: {'✓' if has_locality else '✗ SAKNAS'}")
        print()

    inserted, skipped = insert_rows(conn, rows)
    print(f" ✓ {len(rows)} rader ({inserted} nya, {skipped} dubbletter)")
    time.sleep(DELAY_S)
    return inserted


def print_db_summary(conn):
    """Print a summary of the database contents."""
    print(f"\n{'='*60}")
    print("  DATABASÖVERSIKT")
    print(f"{'='*60}")

    total = conn.execute("SELECT COUNT(*) FROM observations").fetchone()[0]
    print(f"\n  Totalt antal observationer: {total:,}")

    if total == 0:
        return

    # Date range
    row = conn.execute("""
        SELECT MIN(event_start_date), MAX(event_start_date)
        FROM observations
    """).fetchone()
    print(f"  Datumspann: {row[0]} → {row[1]}")

    # Number of species
    species_count = conn.execute("""
        SELECT COUNT(DISTINCT scientific_name)
        FROM observations
        WHERE scientific_name IS NOT NULL
    """).fetchone()[0]
    print(f"  Antal arter: {species_count}")

    # Top 10 species
    print(f"\n  Topp 10 vanligaste arter:")
    rows = conn.execute("""
        SELECT vernacular_name, scientific_name, COUNT(*) as n
        FROM observations
        WHERE vernacular_name IS NOT NULL
        GROUP BY scientific_name
        ORDER BY n DESC
        LIMIT 10
    """).fetchall()
    for i, (sv, sci, n) in enumerate(rows, 1):
        print(f"    {i:2}. {sv} ({sci}) – {n:,} obsar")

    # Observations per year
    print(f"\n  Observationer per år:")
    rows = conn.execute("""
        SELECT SUBSTR(event_start_date, 1, 4) as year, COUNT(*) as n
        FROM observations
        WHERE event_start_date IS NOT NULL
        GROUP BY year
        ORDER BY year
    """).fetchall()
    for year, n in rows:
        print(f"    {year}: {n:,}")

    print(f"\n  Databas: {DB_PATH}")
    size_mb = DB_PATH.stat().st_size / (1024 * 1024)
    print(f"  Storlek: {size_mb:.1f} MB")


def main():
    parser = argparse.ArgumentParser(
        description="Bygg SQLite-databas med fågelobservationer från Tåkern"
    )
    parser.add_argument(
        "--years", type=int, default=5,
        help="Antal år bakåt att hämta (default: 5)"
    )
    parser.add_argument(
        "--count-only", action="store_true",
        help="Räkna bara observationer, ladda inte ner"
    )
    parser.add_argument(
        "--db", type=str, default=None,
        help="Sökväg till databasfilen (default: takern_observations.db)"
    )
    args = parser.parse_args()

    global DB_PATH
    if args.db:
        DB_PATH = Path(args.db)

    config = load_config()
    today = datetime.now()

    # Calculate date ranges per year
    periods = []
    for i in range(args.years):
        year_end = today - timedelta(days=365 * i)
        year_start = today - timedelta(days=365 * (i + 1))
        # Align to Jan 1 / Dec 31 for clean years
        y_start = year_end.year - 1 if year_end.month == 1 and year_end.day == 1 else year_end.year
        if i == 0:
            # Current partial year: Jan 1 this year → today
            periods.append((f"{today.year}-01-01", today.strftime("%Y-%m-%d")))
        else:
            # Full previous years
            y = today.year - i
            periods.append((f"{y}-01-01", f"{y}-12-31"))

    # Add one more year to fill out the range
    oldest_year = today.year - args.years
    if not any(p[0].startswith(str(oldest_year)) for p in periods):
        periods.append((f"{oldest_year}-01-01", f"{oldest_year}-12-31"))

    # Sort chronologically
    periods.sort()

    print("=" * 60)
    print("  TÅKERN FÅGELOBSERVATIONER – DATABASBYGGARE")
    print("=" * 60)
    print(f"\n  Radie: {RADIUS_M/1000:.0f} km från Tåkern ({TAKERN_LAT}, {TAKERN_LNG})")
    print(f"  Perioder att hämta:")
    for date_from, date_to in periods:
        print(f"    • {date_from} → {date_to}")

    # ── Step 1: Count total observations ──
    print(f"\n{'─'*60}")
    print("  Steg 1: Räknar observationer per period")
    print(f"{'─'*60}\n")

    total_count = 0
    period_counts = []
    for date_from, date_to in periods:
        try:
            count = get_count(config, date_from, date_to)
            total_count += count
            period_counts.append((date_from, date_to, count))
            print(f"  {date_from} → {date_to}: {count:,} observationer")
            time.sleep(DELAY_S)
        except urllib.error.HTTPError as e:
            print(f"  {date_from} → {date_to}: ❌ HTTP {e.code}")
            if e.code == 429:
                print(f"  ⚠ Rate limit – väntar 10s...")
                time.sleep(10)
            period_counts.append((date_from, date_to, -1))

    print(f"\n  TOTALT: {total_count:,} observationer")

    if args.count_only:
        print("\n  (--count-only angett, avslutar utan nedladdning)")
        return

    if total_count == 0:
        print("\n  Inga observationer hittades. Kontrollera API-nyckel och konfiguration.")
        return

    # ── Step 2: Download and store ──
    print(f"\n{'─'*60}")
    print("  Steg 2: Laddar ner och sparar till databas")
    print(f"{'─'*60}\n")

    conn = init_db()
    total_inserted = 0

    for date_from, date_to, count in period_counts:
        if count == 0:
            print(f"  📅 {date_from} → {date_to} → 0 observationer, hoppar över")
            continue
        if count == -1:
            print(f"  📅 {date_from} → {date_to} → räkning misslyckades, försöker ändå...")

        try:
            n = download_period(config, conn, date_from, date_to)
            total_inserted += n
        except Exception as e:
            print(f"  ❌ Fel vid {date_from}–{date_to}: {e}")
            continue

    print(f"\n  ✅ Totalt {total_inserted:,} nya observationer sparade")

    # ── Step 3: Summary ──
    print_db_summary(conn)
    conn.close()


if __name__ == "__main__":
    main()
