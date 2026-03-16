# Tåkern Fågelobs

Webbsida med fågelobservationer från området kring Tåkern, baserat på data från [Artportalen](https://www.artportalen.se) via [SLU Artdatabankens API](https://www.artdatabanken.se).

## Sidor

| Sida | Beskrivning |
|------|-------------|
| `index.html` | Senaste observationerna (live från API) |
| `veckorapport.html` | Veckorapport – senaste veckans obs med historisk jämförelse |
| `monitor.html` | Realtidsmonitor |
| `statistik.html` | Historisk statistik 2006–2026 (från SQLite-databas) |

---

## Funktionsbeskrivning per sida

### index.html – Senaste observationer

Visar de senaste 1–2 dagarnas fågelobservationer live från SOS-API:t. Tvåkolumnslayout med karta till vänster och observationslista till höger (kollapsar till en kolumn på mobil ≤900px).

#### Sortering och filter

Fyra sorteringslägen:
- **Senaste** – kronologisk ordning
- **Mest noterbara** – sorterat efter notable-poäng (se nedan)
- **Högst antal** – flest individer
- **Art A–Ö** – alfabetiskt på artnamn

Radie-väljare: 8 km, 10 km (standard), 12 km, 15 km.

#### Karta (Leaflet)

- Centrerad på Tåkern (58.35, 14.81), zoom 12
- Cirkelmarkör per observation, färgkodad efter notable-poäng:
  - **Röd** (≥150 poäng) – mycket noterbar
  - **Orange** (80–149 poäng) – noterbar
  - **Grön** (<80 poäng) – ordinär
- Markörstorlek skalas med antal individer (4–12 px)
- Radie-cirkel ritas med streckad linje runt valt avstånd
- Klick på observationskort i listan panorerar kartan till motsvarande markör

#### Poängsystem för noterbara observationer

Ranking baseras på tre huvudfaktorer. Rödlistestatus visas som badge men påverkar **inte** poängen.

**1. Fenologi (tidig ankomst)**

Jämför observationens dag-på-året med artens historiska ankomstmönster (snitt senaste 5 åren + allra tidigaste från 20 år):

| Kategori | Villkor | Poäng |
|----------|---------|-------|
| Ovanligt tidig | Före historiskt tidigaste (earliest_doy) | 300 + dagar_tidigt × 5 (max 800) |
| Mycket tidig | 30+ dagar före snitt | 300 + dagar_tidigt × 3 |
| Tidig (14–29 dagar) | 14–29 dagar före snitt | 200 + dagar_tidigt × 2 |
| Tidig (0–13 dagar) | 0–13 dagar före snitt | 100 + dagar_tidigt × 2 |
| Ej tidig | Efter snitt + 10 dagar | 0 |

Fenologidata: `avg_first_doy` = snitt av första obs senaste 5 år (exklusive januari), `earliest_doy` = min dag-på-året alla 20 år.

**2. Nyanlända arter**

Arter med ≤20 observationer hittills i år får bonus:
- Poäng: 80 + (20 − antal_obs) × 8
- Spann: **80–240 poäng** (första obs i år ger max)

**3. Lokal sällsynthet**

Baserat på genomsnittligt antal observationer per år vid Tåkern:

| Kategori | Villkor | Poäng |
|----------|---------|-------|
| Sällsynt | <2 obs/år | 120 |
| Ovanlig | 2–4 obs/år | 60 |
| Fåtalig | 5–9 obs/år | 30 |

**Sekundära faktorer:**
- Högt antal individer: ≥500 → 15p, ≥100 → 10p, ≥50 → 7p, ≥20 → 4p
- Observationskommentar finns: 5p

**Tröskelvärden:**
- ≥80 poäng: Kortet markeras med röd vänsterkant
- ≥150 poäng: Kartmarkör blir röd

#### Badges

- **Sällsynthetsbadge:** "Sällsynt · X obs/år", "Ovanlig · X obs/år", eller "Fåtalig · X obs/år" – baserat på snitt obs/år
- **Rödlistebadge:** CR/EN (röd), VU/NT (orange) – visas men påverkar inte poängen
- **Fenologibadge:** "Ovanligt tidig obs", "Mycket tidig obs", "Tidig obs" – med historisk jämförelseinfo

#### Deduplicering

Observationer dedupliceras i backend (api.php) per datum + lokal, med prioritet för poster med Artportalen-URL. Alla poster har en datakälle-etikett.

---

### veckorapport.html – Veckorapport

Sammanfattar de senaste 7 dagarnas observationer med historisk kontext. Kombinerar live-data (SOS-API via api.php) med 20 års historik (SQLite via stats-api.php).

#### Datahämtning

Två parallella API-anrop vid sidladdning:
1. `api.php?radius=10&days=7` – live-observationer
2. `stats-api.php?q=week_context&year=YYYY&days=7` – historisk kontext (fenologi, sällsynthet, förra årets data, vårframsteg)

#### Sektioner (i visningsordning)

**1. Sammanfattningskort**

Fyra (eller fem) kort med nyckeltal:

| Kort | Visar | Jämförelse |
|------|-------|------------|
| Observationer | Totalt antal obs senaste 7 dagarna | ▲/▼ mot samma vecka förra året |
| Arter | Antal unika arter | ▲/▼ mot förra året |
| Årets första | Arter nya för säsongen | ▲/▼ mot förra året |
| Rödlistade | Unika rödlistade arter (ej LC) | Visar artnamn |
| Vårens framsteg | % av flyttfåglar anlända (vecka 8–22) | Medianförskjutning i dagar (tidig/sen/i fas) |

Delta-visning: Grönt ▲ vid ökning, rött ▼ vid minskning, "Samma som YYYY" vid oförändrat.

**Vårens framsteg (vecka 8–22):**
- Räknar genuina flyttfåglar (avg_first_doy 32–180, ≥3 års historik)
- Visar: "X av Y flyttfåglar" och procentandel
- Medianförskjutning: beräknas från arter vars årets-första-obs ligger inom rimligt fönster (±30/+60 dagar från förväntat)
- Negativt = våren tidig, positivt = våren sen

**2. Karta – veckans observationer**

- Leaflet-karta centrerad på Tåkern, zoom 11
- Gröna cirkelmarkörer (5 px, 60% opacitet) vid varje observations koordinater
- Popup: artnamn, vetenskapligt namn, antal, datum, lokal, observatör
- Lokalmarkörer vid zoom ≥15 (storlek baserad på log₁₀ av obs-antal)

**3. Håll utkik efter**

Prediktiv sektion som listar arter som förväntas anlända inom 21 dagar men ännu inte rapporterats i år.

Urvalskriterier:
- Artens genomsnittliga ankomstdag (avg_first_doy) ligger i intervallet 32–180 (feb–jun, genuina flyttfåglar)
- Minst 3 års historisk data
- Ej rapporterad i år
- Förväntad inom [aktuell dag, aktuell dag + 21 dagar]

Visar: Artnamn (länk till statistik.html) + "Brukar komma runt DATUM – inte sedd i år ännu".

**4. Nya observationer för året**

Arter vars första observation i år inföll under denna vecka.

Urvalskriterier:
- Artens första obs i år faller inom veckans datumintervall
- Januariobservationer exkluderas (för att undvika övervintrare)
- Berikad med fenologidata: normalt ankomstdatum, historiskt tidigaste datum + år

Visar: Artnamn (länk till statistik), "Årets första"-badge, datum för första obs, historisk jämförelse, Artportalen-länk om tillgänglig.

**5. Aktivitet per dag**

Chart.js-diagram med dubbla axlar:
- **Staplar** (vänster y-axel): antal observationer per dag
- **Linje** (höger y-axel): antal arter per dag
- X-axel: veckodagar ("Mån 9", "Tis 10", etc.)

**6. Alla arter**

Komplett artlista sorterad efter totalt antal individer (fallande).

Tabell med kolumner:
- **Art** – namn + vetenskapligt namn + rödlistebadge
- **Antal** – totalt antal individer (summerat över alla obs)
- **Dagar** – 7 små cirklar som visar vilka dagar arten observerats (grön = aktiv, grå = inaktiv)
- **Status** – badges: "Årets 1:a" (lila), sällsynthetsbadge (Sällsynt/Ovanlig/Fåtalig), rödlistebadge

---

### statistik.html – Historisk statistik

Visar 20 års historisk data (~333 000 observationer, 374 arter) från SQLite-databasen. Två vyer: översikt och artsida.

#### Datahämtning

Batch-endpoint `stats-api.php?q=init` returnerar overview + geo + localities + species i ett enda svar (optimering för en-PHP-worker-begränsning).

#### Översikt

**Sammanfattningskort:**
- Totalt antal observationer
- Antal arter
- Antal observatörer
- Snitt observationer per år

**Artsökning:**
- Sökfält med autocomplete (triggas vid ≥2 tecken)
- Filtrerar på svenskt namn och vetenskapligt namn (case-insensitive)
- Visar upp till 15 träffar med artnamn, vetenskapligt namn och obs-antal
- Klick laddar artsidan

**Geografisk heatmap:**
- Leaflet-karta med leaflet-heat plugin
- Datapunkter: [lat, lng, antal] från stats-api.php?q=geo
- **Logskalning:** Intensitet baseras på log(antal + 1) för bättre kontrast
- **Normalisering:** Medianen (p50) av log-värden används som maxintensitet
- Gradient: blå (glest) → röd (tätt)
- Konfiguration: radie 22 px, blur 12 px, maxZoom 17, minOpacity 0.15
- Lokalmarkörer visas vid zoom ≥15 (cirkelstorlek: 3 + log₁₀(obs) × 2)

**Artrikedom per månad:**
- Stapeldiagram – genomsnittligt antal arter per månad (12 staplar)

**Topp 20 arter:**
- Numrerad lista med artnamn, vetenskapligt namn, obs-antal
- Klickbara – öppnar artsidan

**Rapporteringsaktivitet (heatmap-tabell):**
- Rader: år, Kolumner: månader (jan–dec)
- Cellfärg: intensitet baserad på antal observationer den månaden
- Adaptiv RGB-skalning från vitt till mörkt grönt
- Textfärg byter vid 50% intensitet för läsbarhet

**Observationer per år:**
- Stapeldiagram med alla år

**Nya arter per år:**
- Stapeldiagram + expanderbar lista med artnamnen per år

**Största rapporterade antal:**
- Topp 10 enskilda observationer (högst antal individer)
- Visar: artnamn (klickbart), datum, lokal, Artportalen-länk, antal

**Mest aktiva observatörer:**
- Grupperat per år (nyast först), numrerad lista med namn och obs-antal

**Tid på dygnet:**
- Stapeldiagram (24 timmar, 00:00–23:00) med obs-fördelning

#### Artsidor

Nås via sökning eller permalänk (`?art=slug`). Slug-format: å/ä→a, ö→o, é→e, övriga specialtecken→bindestreck.

**Rubrik:**
- Artnamn med rödlistebadge (CR/EN röd, VU/NT orange)
- Vetenskapligt namn, familj, ordning

**Nyckeltal (4 kort):**
- Snitt första obs (senaste 5 år, exkl. januari)
- Snitt sista obs
- Allra tidigaste obs (minimum dag-på-året alla 20 år)
- Totalt antal observationer

**Senaste observationer:**
- Upp till 5 senaste obs med lokal, observatör, datakällebadge (Artportalen/NRM:RingedBirds etc.), datum, tid, extern länk

**Geografisk heatmap (artspecifik):**
- Samma teknik som översiktens heatmap men med adaptiv radie/blur beroende på antal punkter:
  - <10 punkter: radie 40, blur 22
  - 10–29: radie 34, blur 19
  - 30–79: radie 28, blur 15
  - 80–199: radie 24, blur 13
  - 200+: radie 18, blur 10

**Säsongskurva:**
- Linjediagram med fyllning: genomsnittliga observationer per vecka (53 veckor)
- X-axeln visar datum (veckonummer konverterat till "D mån"-format)

**Tid på dygnet (artspecifik):**
- Stapeldiagram (24 timmar) om data finns

**Mest rapporterade lokaler:**
- Lista med lokalnamn och obs-antal

**Första och sista obs per år:**
- Tabell: år, första obs-datum, sista obs-datum (nyast först)

**Största noterade antal per år:**
- Stapeldiagram med högsta enskilda obs per år
- Tooltip visar datum, lokal och årssumma
- Klick på stapel öppnar Artportalen-länk om tillgänglig

**Trend (rapporter per år):**
- Stapeldiagram med totalt antal observationer per år

**Navigering:**
- Bakåtknapp → tillbaka till översikt
- Browser-historik med pushState/popstate för bakåt/framåt-navigering
- Permalänkar uppdateras automatiskt i URL:en

---

## Datakällor

All data kommer via SLU Artdatabankens SOS-API som aggregerar flera källor:

| Källa | Andel | Har URL | Har observatör | Har tid |
|-------|-------|---------|----------------|---------|
| Artportalen | ~87% | Ja | Ja | Ja |
| Ringmärkningscentralen (NRM:RingedBirds) | ~13% | Nej | Nej | Nej |
| Svensk Fågeltaxering | Liten | Nej | – | – |
| iNaturalist | Minimal | – | – | – |

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
- **`stats-api.php`** – Läser historisk data direkt från SQLite-databasen. Filbaserad JSON-cache per endpoint
- **`cron-update.php`** – Inkrementell uppdatering av databasen + cache-rensning. Körs via cron, skyddad med nyckel

### Tech stack
- **Frontend:** Vanilla HTML/CSS/JS, Leaflet för kartor, Chart.js för grafer
- **Backend:** PHP på Websupport shared hosting (en PHP-worker)
- **Data:** SQLite-databas (~333k obs, 374 arter, 20 år) + live SOS-API
- **Deploy:** GitHub Actions → FTP → auto cache-clear/warm

## Mappar

```
deploy/          ← Allt som laddas upp till webbservern (Websupport)
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
