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
- **api.php:** Proxy mot live SOS-API för index.html och veckorapport.html
- **?q=init:** Batch-endpoint som returnerar overview+geo+localities+species från cache

## Deploy-flöde
1. Push till main med ändringar i deploy/ → GitHub Actions FTP-deploy
2. Efter FTP: curl till cron-update.php?action=clear-cache (värmer automatiskt)
3. Secrets: FTP_HOST, FTP_USER, FTP_PASS, FTP_PATH, CRON_SECRET

## Viktigt att veta
- **SQLite på Websupport:** %G/%V (ISO-vecka) fungerar INTE. Beräkna datumintervall i PHP istället.
- **En PHP-worker:** Parallella browser-requests köas. Batch-endpoint (?q=init) löser detta.
- **WebFetch har 15 min cache:** Använd ALDRIG WebFetch för att verifiera efter deploy. Använd Chrome.
- **Cache-clear värmer automatiskt:** Standardbeteende sedan 2026-03-15. Skippa med &nowarm.
- **Fenologi:** Earliest = alla 20 år (inget januarifilter). Average = senaste 5 år (med januarifilter).
- **Recent observations:** Dedupliceras per datum+lokal, prioriterar URL-poster. Alla poster har source-etikett.
- **Rarity-filter:** Exkluderar hybrider (` x `), osäkra (`/`), morfer (`morf`), artgrupper (ej mellanslag i scientific_name)
- **Spring progress:** Filtrerar till genuina flyttfåglar (avg_first_doy 32-180, ±30/+60 dagars marginal)

## Säkerhet
- .htaccess blockerar takern_api_key.txt och cron_secret.txt
- Cron-nyckel roterad 2026-03-15 (gammal var exponerad via webbläsaren)
- API-nyckel och cron-secret exkluderas från FTP-deploy

## Utvecklingsplan
Se IDEAS.md för fullständig att-göra-lista. Nästa steg:
- statistik.html: trendpilar på artsidor (▲▼ senaste 5 åren)
- Fas 3: Artackumulering, Fenologikalender
- Fas 4: Artguide, Lokalsidor
