# Tåkern Fågelobs

Webbsida med fågelobservationer från området kring Tåkern, baserat på data från [Artportalen](https://www.artportalen.se) via [SLU Artdatabankens API](https://www.artdatabanken.se).

## Sidor

| Sida | Beskrivning |
|------|-------------|
| `index.html` | Senaste observationerna (live från API) |
| `veckorapport.html` | Veckorapport – senaste veckans obs med historisk jämförelse |
| `monitor.html` | Realtidsmonitor |
| `statistik.html` | Historisk statistik 2006–2026 (från SQLite-databas) |

## Arkitektur

```
┌─────────────┐     ┌──────────────┐     ┌──────────────────┐
│  Webbläsare │────▶│   api.php    │────▶│  SOS API (SLU)   │
│             │     │  stats-api.php│────▶│  SQLite-databas  │
└─────────────┘     └──────────────┘     └──────────────────┘
                           ▲
                    ┌──────┴───────┐
                    │ cron-update  │  (nattetid)
                    │    .php      │
                    └──────────────┘
```

- **`api.php`** – Proxy som vidarebefordrar anrop till SOS API med API-nyckeln server-side
- **`stats-api.php`** – Läser historisk data direkt från SQLite-databasen
- **`cron-update.php`** – Inkrementell uppdatering av databasen, körs via cron

## Mappar

```
deploy/          ← Allt som laddas upp till webbservern (Websupport)
public/          ← Lokal utvecklingsversion
scripts/         ← Python-scripts för databashantering
```

## Lokal utveckling

```bash
# Skapa config.json med API-nyckel
cat > config.json << 'EOF'
{
  "api_key": "DIN_API_NYCKEL",
  "base_url": "https://api.artdatabanken.se/species-observation-system/v1",
  "takern_lat": 58.35,
  "takern_lng": 14.81,
  "radius_km": 15,
  "days_back": 1
}
EOF

# Bygg databasen (första gången)
python scripts/build_database.py --years 20

# Exportera statiska JSON-filer (för lokal utveckling)
python scripts/export_static.py

# Starta lokal server
python server.py
```

## Deploy (Websupport)

1. Ladda upp innehållet i `deploy/` till webbservern
2. Ladda upp `takern_observations.db` till samma mapp
3. Skapa `takern_api_key.txt` med API-nyckeln
4. Sätt upp cron job: `0 4 * * * php /sökväg/cron-update.php`

## Inkrementell uppdatering

Databasen uppdateras inkrementellt – bara nya observationer sedan senaste datumet i databasen hämtas (med 3 dagars överlapp för sena rapporter).

- **På servern:** `cron-update.php` körs automatiskt varje natt
- **Lokalt:** `python scripts/update_database.py`

## Data

- **Källa:** Artportalen via SLU Artdatabankens Species Observation System API
- **Område:** 15 km radie från Tåkern (58.35, 14.81)
- **Taxon:** Fåglar (Aves, taxon ID 4000104)
- **Databasformat:** SQLite

## Filer som inte versionshanteras

- `config.json` – API-nyckel
- `takern_api_key.txt` – API-nyckel (deploy)
- `*.db` – SQLite-databas (för stor för git, laddas upp separat)
- `public/data/` – Genererade JSON-filer
