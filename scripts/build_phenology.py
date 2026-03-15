#!/usr/bin/env python3
"""Build phenology.json from historical Artdatabanken SOS API data.

Queries first arrival dates for ~50 migratory bird species near Tåkern
across multiple years, then calculates earliest and typical (median)
spring arrival dates per species.

Usage:
    python scripts/build_phenology.py
"""

import json
import sys
import time
import urllib.error
import urllib.request
from datetime import datetime, timedelta
from pathlib import Path
from statistics import median

PROJECT_DIR = Path(__file__).resolve().parent.parent
CONFIG_PATH = PROJECT_DIR / "config.json"
OUTPUT_PATH = PROJECT_DIR / "phenology.json"

# ── Target migratory species ──
# Scientific name → Swedish name (for the output file)
TARGET_SPECIES = {
    # Rovfåglar
    "Circus aeruginosus": "brun kärrhök",
    "Pandion haliaetus": "fiskgjuse",
    "Pernis apivorus": "bivråk",
    "Falco subbuteo": "lärkfalk",
    # Vadare
    "Vanellus vanellus": "tofsvipa",
    "Numenius arquata": "storspov",
    "Calidris pugnax": "brushane",
    "Tringa totanus": "rödbena",
    "Tringa glareola": "grönbena",
    "Gallinago gallinago": "enkelbeckasin",
    "Tringa ochropus": "skogssnäppa",
    "Actitis hypoleucos": "drillsnäppa",
    "Thinornis dubius": "mindre strandpipare",
    # Änder
    "Anas acuta": "stjärtand",
    "Spatula clypeata": "skedand",
    "Spatula querquedula": "årta",
    "Anas crecca": "kricka",
    "Mareca penelope": "bläsand",
    "Mareca strepera": "snatterand",
    # Måsar/tärnor
    "Chroicocephalus ridibundus": "skrattmås",
    "Sterna hirundo": "fisktärna",
    # Vattenfåglar
    "Botaurus stellaris": "rördrom",
    # Skäggdopping excluded — resident species, not migratory
    # Stora fåglar
    "Grus grus": "trana",
    "Cuculus canorus": "gök",
    # Svalor
    "Hirundo rustica": "ladusvala",
    "Delichon urbicum": "hussvala",
    "Riparia riparia": "backsvala",
    # Seglare
    "Apus apus": "tornseglare",
    # Sångare
    "Acrocephalus arundinaceus": "trastsångare",
    "Acrocephalus scirpaceus": "rörsångare",
    "Acrocephalus schoenobaenus": "sävsångare",
    "Acrocephalus palustris": "kärrsångare",
    "Phylloscopus trochilus": "lövsångare",
    "Phylloscopus sibilatrix": "grönsångare",
    "Hippolais icterina": "härmsångare",
    "Sylvia atricapilla": "svarthätta",
    "Curruca communis": "törnsångare",
    "Curruca curruca": "ärtsångare",
    "Luscinia luscinia": "näktergal",
    # Flugsnappare m.fl.
    "Ficedula hypoleuca": "svartvit flugsnappare",
    "Muscicapa striata": "grå flugsnappare",
    "Phoenicurus phoenicurus": "rödstjärt",
    # Piplärkor/ärlor
    "Motacilla flava": "gulärla",
    "Anthus trivialis": "trädpiplärka",
    "Anthus pratensis": "ängspiplärka",
    # Övriga
    "Saxicola rubetra": "buskskvätta",
    "Oenanthe oenanthe": "stenskvätta",
    "Jynx torquilla": "göktyta",
    "Emberiza schoeniclus": "sävsparv",
    "Caprimulgus europaeus": "nattskärra",
}

YEARS = range(2020, 2026)
SEARCH_START = "01-01"
SEARCH_END = "06-15"
RADIUS_M = 15000
DELAY_S = 0.8


def load_config():
    with open(CONFIG_PATH) as f:
        return json.load(f)


def api_search(config, body, skip=0, take=1000):
    """Execute a search against the SOS API."""
    base_url = config["base_url"].rstrip("/")
    url = f"{base_url}/Observations/Search?skip={skip}&take={take}"
    req = urllib.request.Request(
        url,
        data=json.dumps(body).encode("utf-8"),
        method="POST",
        headers={
            "Content-Type": "application/json",
            "Ocp-Apim-Subscription-Key": config["api_key"],
            "Cache-Control": "no-cache",
        },
    )
    with urllib.request.urlopen(req, timeout=30) as resp:
        return json.loads(resp.read().decode("utf-8"))


def search_all_birds(config, date_from, date_to, skip=0, take=1000):
    """Search for all bird observations (for harvesting taxon IDs)."""
    return api_search(config, {
        "taxon": {"ids": [4000104], "includeUnderlyingTaxa": True},
        "date": {
            "startDate": date_from, "endDate": date_to,
            "dateFilterType": "BetweenStartDateAndEndDate",
        },
        "geographics": {
            "geometries": [{"type": "point", "coordinates": [config["takern_lng"], config["takern_lat"]]}],
            "maxDistanceFromPoint": RADIUS_M,
            "considerObservationAccuracy": False,
        },
        "output": {
            "fields": ["taxon.id", "taxon.vernacularName", "taxon.scientificName"],
            "sortBy": "event.startDate", "sortOrder": "Desc",
        },
    }, skip=skip, take=take)


def search_species(config, taxon_id, date_from, date_to):
    """Search for observations of a specific species.

    Returns all observation dates (up to 500) for client-side analysis.
    Note: The SOS API's sortOrder is unreliable, so we sort client-side.
    """
    data = api_search(config, {
        "taxon": {"ids": [taxon_id], "includeUnderlyingTaxa": True},
        "date": {
            "startDate": date_from, "endDate": date_to,
            "dateFilterType": "BetweenStartDateAndEndDate",
        },
        "geographics": {
            "geometries": [{"type": "point", "coordinates": [config["takern_lng"], config["takern_lat"]]}],
            "maxDistanceFromPoint": RADIUS_M,
            "considerObservationAccuracy": False,
        },
        "output": {
            "fields": ["event.startDate", "taxon.vernacularName", "taxon.scientificName"],
        },
    }, skip=0, take=500)
    return data


def confirmed_arrival(dates):
    """Find the first 'confirmed' arrival date.

    Returns the earliest date that starts a 14-day window containing
    at least 3 unique observation dates. This filters out isolated
    overwintering birds or strays — a single bird seen on one or two
    days doesn't count as spring arrival.

    If no date qualifies (e.g. very few obs all season), falls back
    to the absolute earliest date.
    """
    if not dates:
        return None
    sorted_dates = sorted(set(dates))
    for i, d in enumerate(sorted_dates):
        d_date = datetime.strptime(d, "%Y-%m-%d")
        window_end = d_date + timedelta(days=14)
        days_in_window = sum(
            1 for other in sorted_dates[i:]
            if datetime.strptime(other, "%Y-%m-%d") <= window_end
        )
        if days_in_window >= 3:
            return d
    # Fallback: fewer than 3 unique dates all season
    return sorted_dates[0]


def mm_dd_to_doy(mm_dd):
    """Convert 'MM-DD' to day-of-year (using 2024 as leap year reference)."""
    return datetime.strptime(f"2024-{mm_dd}", "%Y-%m-%d").timetuple().tm_yday


def doy_to_mm_dd(doy):
    """Convert day-of-year back to 'MM-DD'."""
    d = datetime(2024, 1, 1) + timedelta(days=doy - 1)
    return d.strftime("%m-%d")


def main():
    config = load_config()
    target_set = set(TARGET_SPECIES.keys())

    # ── Pass 1: Harvest taxon IDs from recent broad search ──
    print("=" * 50)
    print("  Pass 1: Hämtar taxon-ID:n")
    print("=" * 50)

    taxon_ids = {}  # scientific_name -> numeric taxon ID
    api_names = {}  # scientific_name -> Swedish name from API
    skip = 0
    while len(taxon_ids) < len(target_set):
        try:
            data = search_all_birds(config, "2024-01-01", "2025-12-31", skip=skip)
        except Exception as e:
            print(f"  \u26a0 Error: {e}")
            break
        records = data.get("records", [])
        if not records:
            break
        for rec in records:
            sci = rec.get("taxon", {}).get("scientificName", "")
            tid = rec.get("taxon", {}).get("id")
            if sci in target_set and sci not in taxon_ids and tid:
                taxon_ids[sci] = tid
                vern = rec.get("taxon", {}).get("vernacularName", "")
                if vern:
                    api_names[sci] = vern
                print(f"    {vern or sci}: taxon_id={tid}")
        if len(records) < 1000:
            break
        skip += 1000
        time.sleep(DELAY_S)

    missing_ids = target_set - set(taxon_ids.keys())
    if missing_ids:
        print(f"  \u26a0 Saknar taxon-ID för: {', '.join(TARGET_SPECIES[s] for s in missing_ids)}")
    print(f"  Hittade {len(taxon_ids)}/{len(target_set)} taxon-ID:n")

    # ── Pass 2: Per-species first observation per year ──
    print(f"\n{'='*50}")
    print("  Pass 2: Hämtar första obs per art och år")
    print(f"{'='*50}")

    first_obs = {}      # scientific_name -> {year: "MM-DD"} (confirmed arrival)
    raw_first_obs = {}   # scientific_name -> {year: "MM-DD"} (absolute earliest)

    for sci_name, tid in sorted(taxon_ids.items(), key=lambda x: TARGET_SPECIES.get(x[0], x[0])):
        sv_name = api_names.get(sci_name, TARGET_SPECIES.get(sci_name, sci_name))
        dates_str = []
        for year in YEARS:
            date_from = f"{year}-{SEARCH_START}"
            date_to = f"{year}-{SEARCH_END}"
            try:
                data = search_species(config, tid, date_from, date_to)
                records = data.get("records", [])
                if records:
                    # Collect all observation dates for this year
                    all_dates = sorted(set(
                        r.get("event", {}).get("startDate", "")[:10]
                        for r in records
                        if r.get("event", {}).get("startDate")
                    ))
                    raw_earliest = all_dates[0][5:] if all_dates else None
                    if raw_earliest:
                        raw_first_obs.setdefault(sci_name, {})[year] = raw_earliest
                    arrival = confirmed_arrival(all_dates)
                    if arrival:
                        mm_dd = arrival[5:]  # "YYYY-MM-DD" -> "MM-DD"
                        first_obs.setdefault(sci_name, {})[year] = mm_dd
                        if raw_earliest and raw_earliest != mm_dd:
                            dates_str.append(f"{year}:{mm_dd} (raw:{raw_earliest})")
                        else:
                            dates_str.append(f"{year}:{mm_dd}")
            except urllib.error.HTTPError as e:
                err_body = e.read().decode("utf-8", errors="replace")[:100]
                dates_str.append(f"{year}:HTTP{e.code}")
                if "429" in str(e.code):
                    print(f"    \u26a0 Rate limit! Väntar 5s...")
                    time.sleep(5)
            except Exception as e:
                dates_str.append(f"{year}:ERR({e})")
            time.sleep(DELAY_S)

        if dates_str:
            print(f"  {sv_name}: {', '.join(dates_str)}")

    # ── Build phenology.json ──
    print(f"\n{'='*50}")
    print("  Beräknar fenologi...")
    print(f"{'='*50}")

    phenology = {}
    missing = []

    for sci_name, sv_name in sorted(TARGET_SPECIES.items(), key=lambda x: x[1]):
        years_data = first_obs.get(sci_name, {})
        if not years_data:
            missing.append(f"{sv_name} ({sci_name})")
            continue

        doys = [mm_dd_to_doy(d) for d in years_data.values()]
        earliest_doy = min(doys)
        typical_doy = int(median(doys))

        raw_years = raw_first_obs.get(sci_name, {})
        phenology[sci_name] = {
            "sv": api_names.get(sci_name, sv_name),
            "earliest": doy_to_mm_dd(earliest_doy),
            "typical": doy_to_mm_dd(typical_doy),
            "years_found": len(years_data),
            "data": {str(y): d for y, d in sorted(years_data.items())},
            "raw_data": {str(y): d for y, d in sorted(raw_years.items())},
        }
        print(f"  {sv_name}: earliest {doy_to_mm_dd(earliest_doy)}, typical {doy_to_mm_dd(typical_doy)} ({len(years_data)} år)")

    with open(OUTPUT_PATH, "w", encoding="utf-8") as f:
        json.dump(phenology, f, ensure_ascii=False, indent=2)

    print(f"\n\u2705 {len(phenology)} arter sparade till {OUTPUT_PATH}")
    if missing:
        print(f"\u26a0 Ingen data hittades för: {', '.join(missing)}")


if __name__ == "__main__":
    main()
