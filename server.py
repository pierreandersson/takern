#!/usr/bin/env python3
"""Backend proxy for Artdatabanken SOS API – keeps API key server-side."""

import json
import os
import urllib.request
import urllib.error
from datetime import datetime, timedelta, timezone
from http.server import HTTPServer, SimpleHTTPRequestHandler
from pathlib import Path
from urllib.parse import urlparse, parse_qs

CONFIG_PATH = Path(__file__).parent / "config.json"
PHENOLOGY_PATH = Path(__file__).parent / "phenology.json"

with open(CONFIG_PATH) as f:
    CONFIG = json.load(f)

PHENOLOGY = {}
if PHENOLOGY_PATH.exists():
    with open(PHENOLOGY_PATH) as f:
        PHENOLOGY = json.load(f)

API_KEY = CONFIG["api_key"]
BASE_URL = CONFIG["base_url"].rstrip("/")
TAKERN_LAT = CONFIG["takern_lat"]
TAKERN_LNG = CONFIG["takern_lng"]
RADIUS_M = CONFIG["radius_km"] * 1000
DAYS_BACK = CONFIG["days_back"]


ALLOWED_RADII = {8, 10, 12, 15}


ALLOWED_DAYS = {1, 7, 14}


def build_search_body(radius_m=None, days_back=None):
    """Build the POST body for Observations/Search."""
    if radius_m is None:
        radius_m = RADIUS_M
    if days_back is None:
        days_back = DAYS_BACK
    now = datetime.now(timezone.utc)
    date_from = (now - timedelta(days=days_back)).strftime("%Y-%m-%d")
    return {
        "taxon": {
            "ids": [4000104],  # Aves (birds)
            "includeUnderlyingTaxa": True,
        },
        "date": {
            "startDate": date_from,
            "endDate": now.strftime("%Y-%m-%d"),
            "dateFilterType": "BetweenStartDateAndEndDate",
        },
        "geographics": {
            "geometries": [
                {
                    "type": "point",
                    "coordinates": [TAKERN_LNG, TAKERN_LAT],
                }
            ],
            "maxDistanceFromPoint": radius_m,
            "considerObservationAccuracy": False,
        },
        "output": {
            "fields": [
                "event.startDate",
                "event.endDate",
                "location.decimalLatitude",
                "location.decimalLongitude",
                "location.locality",
                "taxon.id",
                "taxon.vernacularName",
                "taxon.scientificName",
                "occurrence.occurrenceId",
                "occurrence.recordedBy",
                "occurrence.individualCount",
                "occurrence.occurrenceRemarks",
                "artpieces.sensitivityCategory",
                "taxon.attributes.isRedlisted",
                "taxon.attributes.redlistCategory",
            ],
            "sortBy": "event.startDate",
            "sortOrder": "Desc",
        },
    }


CACHE_DIR = Path(__file__).parent / "cache"
ARCHIVE_DIR = Path(__file__).parent / "data"


def _cache_path(radius_m, days_back):
    r = radius_m or RADIUS_M
    d = days_back or DAYS_BACK
    return CACHE_DIR / f"obs_r{r}_d{d}.json"


def _archive_snapshot(records, radius_m, days_back):
    """Save a weekly snapshot to data/ for historical comparison."""
    today = datetime.now().strftime("%Y-%m-%d")
    r = radius_m or RADIUS_M
    d = days_back or DAYS_BACK
    ARCHIVE_DIR.mkdir(exist_ok=True)
    archive_file = ARCHIVE_DIR / f"{today}_r{r}_d{d}.json"
    if not archive_file.exists():
        with open(archive_file, "w", encoding="utf-8") as f:
            json.dump({"date": today, "records": records, "totalCount": len(records)}, f, ensure_ascii=False)
        print(f"  📁 Archived {len(records)} records → {archive_file.name}")


def fetch_observations(radius_m=None, days_back=None):
    """Fetch observations from the SOS API, with local file cache for offline dev."""
    cache_file = _cache_path(radius_m, days_back)

    url = f"{BASE_URL}/Observations/Search?skip=0&take=1000"
    search_body = build_search_body(radius_m, days_back)
    body = json.dumps(search_body).encode("utf-8")
    req = urllib.request.Request(
        url,
        data=body,
        method="POST",
        headers={
            "Content-Type": "application/json",
            "Ocp-Apim-Subscription-Key": API_KEY,
            "Cache-Control": "no-cache",
        },
    )
    try:
        with urllib.request.urlopen(req, timeout=30) as resp:
            data = json.loads(resp.read().decode("utf-8"))
        records = data.get("records", [])
        result = {"records": records, "totalCount": len(records)}
        # Save to cache for offline development
        CACHE_DIR.mkdir(exist_ok=True)
        with open(cache_file, "w", encoding="utf-8") as f:
            json.dump(result, f, ensure_ascii=False)
        print(f"  💾 Cached {len(records)} records → {cache_file.name}")
        # Archive daily snapshot for historical comparison
        _archive_snapshot(records, radius_m, days_back)
        return result
    except Exception as e:
        # Fall back to cache if API is unreachable
        if cache_file.exists():
            print(f"  ⚠ API error ({e}), using cached data from {cache_file.name}")
            with open(cache_file, encoding="utf-8") as f:
                return json.load(f)
        if isinstance(e, urllib.error.HTTPError):
            error_body = e.read().decode("utf-8", errors="replace")
            return {"error": f"API returned {e.code}", "detail": error_body}
        return {"error": str(e)}


class Handler(SimpleHTTPRequestHandler):
    """Serve static files from /public and proxy API calls on /api/*."""

    def __init__(self, *args, **kwargs):
        super().__init__(*args, directory=str(Path(__file__).parent / "public"), **kwargs)

    def do_GET(self):
        parsed = urlparse(self.path)
        params = parse_qs(parsed.query)
        if parsed.path == "/api/observations":
            radius_km = None
            try:
                r = int(params.get("radius", [None])[0])
                if r in ALLOWED_RADII:
                    radius_km = r
            except (TypeError, ValueError):
                pass
            days_back = None
            try:
                d = int(params.get("days", [None])[0])
                if d in ALLOWED_DAYS:
                    days_back = d
            except (TypeError, ValueError):
                pass
            radius_m = radius_km * 1000 if radius_km else None
            self._send_json(fetch_observations(radius_m, days_back))
        elif parsed.path == "/api/phenology":
            self._send_json(PHENOLOGY)
        elif parsed.path == "/api/config":
            self._send_json({
                "lat": TAKERN_LAT,
                "lng": TAKERN_LNG,
                "radius_km": CONFIG["radius_km"],
                "days_back": DAYS_BACK,
            })
        else:
            super().do_GET()

    def _send_json(self, data):
        body = json.dumps(data, ensure_ascii=False).encode("utf-8")
        self.send_response(200)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, fmt, *args):
        print(f"[{datetime.now():%H:%M:%S}] {fmt % args}")


def main():
    port = int(os.environ.get("PORT", 8080))
    server = HTTPServer(("0.0.0.0", port), Handler)
    print(f"🐦 Tåkern Birds running on http://localhost:{port}")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        print("\nShutting down.")
        server.server_close()


if __name__ == "__main__":
    main()
