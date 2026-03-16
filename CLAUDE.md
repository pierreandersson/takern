# Tåkern Fågelobs – Projektkontext

## Vad detta är
Fågelobservationssajt för Tåkern (svensk fågelsjö) på pierrea.se/takern/. Tre sidor: index.html (senaste obs), veckorapport.html (veckosammanfattning), statistik.html (20 års historik med artsidor).

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

## Deploy-flöde
1. Push till main med ändringar i deploy/ → GitHub Actions FTP-deploy
2. Efter FTP: curl till cron-update.php?action=clear-cache (värmer automatiskt, ~1 min blockerar PHP-worker)
3. Secrets: FTP_HOST, FTP_USER, FTP_PASS, FTP_PATH, CRON_SECRET

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

## Veckorapport-sektioner
Ordning: Sammanfattningskort → Karta → Håll utkik efter (arter förväntade inom 21 dagar men ej rapporterade) → Nya observationer för året → Aktivitet per dag → Alla arter

## Säkerhet
- .htaccess blockerar takern_api_key.txt och cron_secret.txt
- Cron-nyckel roterad 2026-03-15 (gammal var exponerad via webbläsaren)
- API-nyckel och cron-secret exkluderas från FTP-deploy

## Hybrid-arkitektur (api.php)
- **days 0–1:** Enbart live SOS-API (snabbt, under 1000-gränsen)
- **days 2–6:** Live API för idag+igår + SQLite för äldre dagar → merge + dedup på occurrence_id
- **Varför:** Cron körs ~04:00, SQLite kan sakna gårdagens sena obs → live API täcker gapet
- **Radie:** Fast 15 km, konsekvent med databasens nedladdningsradie. Ingen radieväljare.

## Utvecklingsplan
Se IDEAS.md för fullständig att-göra-lista. Nästa steg:
- statistik.html: trendpilar på artsidor (▲▼ senaste 5 åren)
- Fas 3: Artackumulering, Fenologikalender
- Fas 4: Om-sida, Artguide, Lokalsidor
