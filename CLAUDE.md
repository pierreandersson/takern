# Tåkern Fågelobs – Projektkontext

## Vad detta är
Fågelobservationssajt för Tåkern (svensk fågelsjö) på pierrea.se/takern/. Sex sidor: index.html (senaste obs), veckorapport.html ("Tåkern i veckan"), arter.html (artbrowser + artsidor), lokaler.html (lokalbrowser + lokalsidor), statistik.html (20 års historik), om.html.

## Tech stack
- **Frontend:** Vanilla HTML/CSS/JS, Leaflet för kartor, Chart.js för grafer
- **Backend:** PHP på Websupport shared hosting (en PHP-worker = inga parallella requests)
- **Data:** SQLite-databas (~333k obs, 374 arter, 20 år) + live SOS-API (Artdatabanken)
- **Deploy:** GitHub Actions → FTP (SamKirkland/FTP-Deploy-Action) → auto cache-clear/warm
- **Caching:** Filbaserad JSON-cache i deploy/cache/, invalideras av cron-update.php

## Datakällor
All data kommer via SLU Artdatabankens SOS-API som aggregerar flera källor:
- **Artportalen** (~87%) – har URL, observatör, tid
- **Ringmärkningscentralen / NRM:RingedBirds** (~13%) – saknar URL, observatör, tid
- **Svensk Fågeltaxering** (liten andel) – saknar URL
- **iNaturalist** (minimal andel)

`dataset_name`-kolumnen är tom/null för Artportalen-poster. Använd `url`-fältet (innehåller "artportalen") för att identifiera källa.

## Nyckelarkitektur
- **stats-api.php:** Alla statistik-queries. Cache-filer per endpoint (overview.json, species.json, species_XXXXX.json, etc.)
- **cron-update.php:** Daglig datainhämtning + cache-rensning. Skyddad med nyckel i cron_secret.txt
- **api.php:** Hybrid-proxy för index.html: live SOS-API (idag+igår) + SQLite (äldre dagar, days ≥ 2). Fast 15 km radie.
- **?q=init:** Batch-endpoint som returnerar overview+geo+localities+species från cache

## Delat designsystem

### CSS (style.css)
Gemensam CSS i style.css, sidspecifik CSS i inline `<style>`. CSS-variabler:
- `--accent-bg: rgba(45,106,79,0.08)` – subtil grön bakgrund
- Designtokens: `border-radius` 4px (badges) / 8px (kort) / 10px (sektioner), liten text `0.85rem`, kort-padding `0.75rem 1rem` (listobjekt) / `1.25rem` (sektionskort)

Gemensamma CSS-komponenter i style.css:
- **`.section-nav`** – Sticky tab-navigation (lokaler, arter, statistik, veckorapport)
- **`.obs-card`** – Observationskort med `.obs-header`, `.obs-species`, `.obs-scientific`, `.obs-count`, `.obs-meta`
- **`.top-list` / `.top-item`** – Rankade listor med `.top-item-name`, `.top-item-sub`, `.top-item-count`
- **`.search-box`** – Sökfält med dropdown (lokaler, arter)
- **`.badge-*`** – Statusmärken (red, orange, green, blue, purple)
- **`.ap-link`** – Länkchip till Artportalen ("Artportalen ↗"), grön kantlinje med hover-bakgrund

### Render-funktioner (utils.js)
Delade render-funktioner producerar konsekvent HTML som matchar CSS-komponenterna:
- **`renderObsItem(obs, options)`** – Renderar `.obs-card`. Options: `showSpecies`, `showSpeciesLink`, `showLocalityLink`, `badges`, `highlight`, `showRemarks`
- **`renderSpeciesItem(species, options)`** – Renderar `.top-item` för artlistor. Options: `showLink`, `showCount`, `countLabel`, `showRedlist`
- **`renderReporterItem(reporter)`** – Renderar `.top-item` för rapportörlistor
- **`normalizeSosObs(obs)`** – SOS API-format → kanoniskt obs-format
- **`normalizeDbObs(r)`** – DB/cache-format → kanoniskt obs-format
- **`updateSectionNav(sections)`** – Bygger sticky section-nav med IntersectionObserver

Övriga utils.js-funktioner: `toSlug()`, `speciesLink()`, `localityLink()`, `formatDateSwedish()`, `formatDateTimeSv()`, `redlistBadgeHtml()`, `initMap()`, `createHeatLayer()`, `addLocalityMarkers()`, `addRadiusCircle()`

## Deploy-flöde
1. Push till main med ändringar i deploy/ eller .github/workflows/ → GitHub Actions FTP-deploy
2. Före FTP: sed lägger till `?v=<git-hash>` på lokala assets (utils.js, style.css) i HTML-filer → cache-busting
3. Efter FTP: curl till cron-update.php?action=clear-cache (värmer automatiskt, ~1 min blockerar PHP-worker)
4. Secrets: FTP_HOST, FTP_USER, FTP_PASS, FTP_PATH, CRON_SECRET

## Viktigt att veta
- **SQLite på Websupport:** %G/%V (ISO-vecka) fungerar INTE. Beräkna datumintervall i PHP istället.
- **En PHP-worker:** Parallella browser-requests köas. Batch-endpoint (?q=init) löser detta. Cache-värmning efter deploy blockerar sajten ~1 min.
- **WebFetch har 15 min cache:** Använd ALDRIG WebFetch för att verifiera efter deploy. Använd Chrome.
- **WebFetch tolkar data opålitligt:** Lita inte på WebFetch för exakta siffror från API-svar. Verifiera via eval/kod.
- **Cache-clear värmer automatiskt:** Standardbeteende sedan 2026-03-15. Skippa med &nowarm.
- **Fenologi:** Earliest = min dag-på-året (ej kronologiskt äldsta datumet), med januarifilter. Average = senaste 5 år (med januarifilter).
- **Recent observations:** Dedupliceras per datum+lokal, prioriterar URL-poster. Alla poster har source-etikett.
- **Rarity-filter:** Exkluderar hybrider (` x `), osäkra (`/`), morfer (`morf`), artgrupper (ej mellanslag i scientific_name)
- **Spring progress:** Filtrerar till genuina flyttfåglar (avg_first_doy 32-180, ±30/+60 dagars marginal)
- **Permalänkar:** Artsidor i statistik.html nås via `?art=slug` (t.ex. `?art=sangsvan`). Slug: å/ä→a, ö→o, é→e, specialtecken→bindestreck.

## Notable scoring (index.html)
Ranking baseras på tre transparenta faktorer – rödlistestatus visas som badge men påverkar INTE ranking:
1. **Fenologi:** Graderad bonus baserat på hur många dagar före historiskt snitt. Ovanligt tidig (före earliest) > Mycket tidig (30+ dagar) > Tidig (14+ dagar)
2. **Nyanlända:** Arter med ≤20 obs hittills i år får bonus (80–240 poäng, fler obs = lägre bonus)
3. **Sällsynthet:** Lokalt sällsynta arter vid Tåkern (<2 obs/år = 120p, <5 = 60p, <10 = 30p)

## "Tåkern i veckan" (veckorapport.html)
Sektioner: Sammanfattningskort (med förra årets jämförelse) → Heatmap-karta → Artackumulering (kurva: i år vs 5-årssnitt + dygnsmedeltemperatur, Chart.js time-axis) + Vårens framsteg (meter, vecka 8–22) → Nytt för säsongen (årets-första-arter) → Håll utkik efter (arter förväntade inom 21 dagar) → Veckans höjdpunkter (top 8 noterbara, poängbaserat) → Artfördelning (donut per fågelgrupp)
- **Fågelgrupper:** Mappas via `getBirdGroup()` i stats-api.php (taxonomic_order + family → svensk grupp)
- **Artackumulering:** Endpoint `?q=accumulation&year=YYYY` – kumulativt unika arter per dag, 5-årssnitt, SMHI-temperatur
- **Temperatur:** Dygnsmedeltemperatur från SMHI Härsnäs (station 85180, 26 km öster om Tåkern). Parameter 2, period latest-months.

## Säsongstidslinje och trendanalys (statistik.html artsidor)
Sektionen "Säsongens längd per år" på artsidor visar:
1. **Horisontell tidslinje:** Varje år som en grön bar från första till sista obs, med månadsrutnät. Alla år visas utan scroll.
2. **Trendanalys:** Tre scatterplots (Första obs, Sista obs, Säsongslängd) med Theil-Sen regressionslinjer.

### Theil-Sen estimator
- Beräknar medianen av alla parvis slopes – robust mot outliers (t.ex. övervintrande individer med jan-obs)
- R² beräknas mot Theil-Sen-linjen (intercept = median av residualer)
- Trender visas per decennium med färgkodning: grönt/↑ = positiv trend (tidigare första obs, senare sista obs, längre säsong), orange/↓ = negativ
- `goodDir`-parameter styr vad som räknas som positivt: -1 för första obs (tidigare = bra), +1 för sista obs och säsongslängd (senare/längre = bra)
- Trender <0.5 dagar/decennium visas som "Stabil", R² <0.15 markeras "(svag)"
- **Pågående år exkluderas** från trendberäkning (ofullständig data)

## Säkerhet
- .htaccess blockerar takern_api_key.txt och cron_secret.txt
- Cron-nyckel roterad 2026-03-15 (gammal var exponerad via webbläsaren)
- API-nyckel och cron-secret exkluderas från FTP-deploy

## Hybrid-arkitektur (api.php)
- **days 0–1:** Enbart live SOS-API (snabbt, under 1000-gränsen)
- **days 2–7:** Live API för idag+igår + SQLite för äldre dagar → merge + dedup på occurrence_id
- **Varför:** Cron körs ~04:00, SQLite kan sakna gårdagens sena obs → live API täcker gapet
- **Radie:** Fast 15 km från [58.35, 14.81], konsekvent med databasens nedladdningsradie. Ingen radieväljare. Radien visas som subtil grön cirkel på alla Leaflet-kartor via `addRadiusCircle()` i utils.js.

## Utvecklingsplan
Se IDEAS.md för fullständig att-göra-lista. Nästa steg:
- Fas 3: Artackumulering, Fenologikalender
- Fas 4: Om-sida, Artguide, Lokalsidor
