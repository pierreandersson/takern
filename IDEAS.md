# Tåkern Fågelobs – Idédokument & Utvecklingsplan

## Nuläge

Tre sidor:
- **index.html** – Senaste observationer (0–6 dagar, hybrid live API + SQLite), karta + lista, fenologi-scoring
- **veckorapport.html** – "Tåkern i veckan": veckoöversikt med artackumulering (vs 5-årssnitt + temperatur), artfördelning, höjdpunkter, heatmap
- **statistik.html** – 20 års historik (~333k obs, 374 arter), artsidor, grafer, heatmaps

Backend: PHP-proxy (api.php) mot Artdatabankens SOS-API + stats-api.php mot SQLite-databas. Filbaserad JSON-cache. GitHub Actions → FTP-deploy till Websupport med automatisk cache-clear/warm.

---

## A. Innehåll & Funktionalitet

### 1. Ny sida: Artguide / Artlexikon
Dedikerad sida där man kan bläddra bland alla 374 arter. Statistik-sidans artsökning är bra men gömd bakom en sökruta.

**Idé:**
- Filtrerbara kort per art (familj, rödlistestatus, vanlighet)
- Thumbnail/ikon per artgrupp (rovfåglar, vadare, andfåglar etc.)
- Klick → artsidan i statistik.html (eller egen dedikerad vy)
- "Chansen att se just nu" baserat på säsongskurva + senaste obs

### 2. Fenologikalender
Visualisering av hela årets fågelfenologi – när varje art brukar anlända och försvinna.

**Idé:**
- Gantt-liknande diagram: en rad per art, färgade staplar visar säsong
- Data finns redan (season_curve i stats-api)
- Sortera efter typisk ankomstdatum → visar vårens progression
- Markera "vi är här" med vertikal linje för aktuellt datum
- Filtrera per grupp (rovfåglar, sångare, vadare)

### 3. Jämförelse: "Samma vecka förra året"
Veckorapporten visar veckans observationer men jämför bara med 5-årssnitt.

**Idé:**
- Lägg till "Förra året denna vecka: X arter, Y obs"
- Visa vilka arter som setts nu men inte förra året (och tvärtom)
- Data finns i databasen – behöver bara en till query i week_context

### 4. Trender & rekord per art
Statistik-sidans artsidor har redan obs/år-graf, men det finns mer att gräva i.

**Idé:**
- Trendpil ▲▼ på artkortet (ökar/minskar senaste 5 åren)
- "Mest ovanliga observationen" – lägsta obs-frekvens per art
- Max-antal-rekord med datum och observatör
- Jämförelse med nationell data (om API tillåter)

### 5. Bättre "Mest noterbara" på index.html
Notable-poängen fungerar bra men är osynlig för användaren.

**Idé:**
- Visa varför en observation lyfts: "Rödlistad (VU)", "Tidig obs – normalt 2 veckor senare"
- Lägga till "Ovanlig art" – arter som ses <5 gånger/år i databasen

### 6. Lokalsidor
Data finns per lokal i databasen. Tåkern har kända fågelplatser.

**Idé:**
- Klickbara lokaler på kartan → visar artlista, bästa tid att besöka, trender
- "Tåkerns bästa fågellokaler" – rankade efter artrikedom
- Säsongsrekommendation: "Besök Svälinge i april för vadare"

### 7. Observatörsstatistik
Finns redan som "Top observers" i statistiken, men kan byggas ut.

**Idé:**
- "Veckans mest aktiva rapportörer"
- Inte en tävling – men roligt att se vem som bidrar

### 8. Veckorapport: Rödlistade arter-sektion
Vi visar antal i summary card men inte mer.

**Idé:**
- Egen sektion med kort per rödlistad art observerad denna vecka
- Visa rödlistekategori, antal obs, senaste observation
- Historisk kontext: "Ses normalt X gånger/år"

---

## B. Visualiseringar

### 9. Artrikedom-karta (heatmap per rutnät)
Nuvarande heatmap visar observationstäthet. Men fågelskådare bryr sig mer om artrikedom.

**Idé:**
- Alternativ kartvy: antal unika arter per rutnätscell
- Toggle: "Observationer" / "Artrikedom"
- Visar var det är mest varierat, inte bara mest rapporterat

### 10. Vår-tracker
En visualisering som visar vårens ankomst i realtid.

**Idé:**
- Tidslinje med alla årets-första-observationer längs x-axeln
- Jämför med historiska snitt (grå linje)
- "Våren är X dagar tidig/sen i år" – aggregerat mått
- Uppdateras löpande genom säsongen

### 11. Artackumulering (species accumulation curve)
Klassisk fågelskådarvisualisering.

**Idé:**
- Kurva: antal unika arter sett hittills i år, dag för dag
- Överlagra med 2–3 tidigare år
- Visar om det är ett bra eller dåligt år
- Data finns: year_firsts i week_context ger alla första-datum

### 12. Säsongshjul
Cirkeldiagram som visar årets gång.

**Idé:**
- 12 segment (månader), radie = antal arter eller obs
- Visa innerst: nuvarande period markerad
- Liknande design som klimatdiagram

### 13. Tåkerns fågelfauna i förändring – Trendanalys

Ny sektion på statistik.html som visar vilka arter som ökar respektive minskar vid Tåkern under 20 år (2006–2025). Bygger på samma Theil-Sen-metodik som redan finns på artsidorna.

**Metodik: Rapportfrekvens (encounter rate)**

Standardmått inom fågelövervakning (eBird, BirdLife). Kompenserar automatiskt för att antalet rapportörer vid Tåkern tredubblats under perioden.

- **Rapportfrekvens per art och år** = antal unika datum+lokal-kombinationer där arten rapporteras / totalt antal unika datum+lokal-kombinationer det året
- Mäter "hur stor andel av alla fältbesök som noterar arten" – inte råa antal
- När fler rapportörer tillkommer ökar både täljare och nämnare proportionellt → bias elimineras

**Trend: Theil-Sen regression**
- Beräknar slope mellan varje par av år (20 år → 190 par), tar medianen → robust mot outliers
- **Relativ förändring** = slope / medelrapportfrekvens → gör det möjligt att jämföra trender rättvist mellan vanliga och ovanliga arter
- Ranking baseras på relativ förändring (störst ökning resp minskning)

**Urval: Ett enda kriterium**
- Arten måste ha ≥0,2 % rapportfrekvens i minst en 5-årsperiod (2006–2010, 2011–2015, 2016–2020, 2021–2025)
- Denna enda regel ger automatiskt tillräcklig datatäthet – inga ytterligare filter behövs
- Fångar både arter som försvunnit (fanns tidigt men inte nu) och nyetablerare (saknades tidigt men finns nu)

**Visning:**
- 10 mest ökande + 10 mest minskande arter
- Sparkline per art: rapportfrekvens normaliserad till artens eget min–max (visar form, inte absolut nivå)
- Etikett: "X % → Y %" (medel första 5 år → medel sista 5 år)
- Klick → artsida med fullständig statistik

**Förväntade topplistor (validerade mot databasen):**
- *Ökande:* ägretthäger, röd glada, brandkronad kungsfågel, skogsgås, sydlig gulärla, rördrom, gransångare, kungsfiskare, svarthakedopping, större hackspett
- *Minskande:* prutgås, dvärgbeckasin, mindre sångsvan, kornknarr, nattskärra, silvertärna, gräshoppsångare, pungmes, morkulla, jorduggla

**Metodbeskrivning att publicera på sidan (utkast):**

> **Hur vi mäter trender**
>
> Att jämföra fågelobservationer rakt av mellan 2006 och 2025 är missvisande – antalet aktiva rapportörer vid Tåkern har tredubblats under perioden. Fler ögon i fält ger fler rapporter, oavsett om fåglarna blivit fler.
>
> Därför använder vi *rapportfrekvens* – samma mått som används av eBird och BirdLife International. Istället för att räkna observationer frågar vi: *hur stor andel av alla fältbesök noterade arten?* Ett fältbesök definieras som en unik kombination av datum och lokal. Om 200 besök gjordes ett år och sångsvan noterades vid 40 av dem, är rapportfrekvensen 20 %.
>
> Rapportfrekvens speglar hur ofta en art påträffas vid fältbesök, inte förändringar i faktiskt antal individer – det kräver standardiserade inventeringar. När fler rapportörer tillkommer ökar både antalet besök som noterar arten och det totala antalet besök – kvoten förblir stabil om artens faktiska förekomst inte förändrats. Måttet kompenserar därmed automatiskt för ökad rapporteringsaktivitet.
>
> Trenden beräknas med Theil-Sen-regression: för varje par av år (med 20 år blir det 190 par) beräknas hur snabbt rapportfrekvensen förändrades. Trenden är mittenvärdet (medianen) av alla dessa lutningar. Det gör att enstaka extremår – en sträng vinter eller en invasion – inte snedvrider resultatet.
>
> För att rättvist kunna jämföra vanliga och ovanliga arter rankas de efter *relativ förändring*: trendens storlek i förhållande till artens genomsnittliga rapportfrekvens. En art som gått från 0,5 % till 5 % har förändrats mer i relativa termer än en art som gått från 10 % till 15 %, trots att den absoluta ökningen är mindre.
>
> Arterna som visas har valts ut genom ett enda kriterium: rapportfrekvensen måste ha nått minst 0,2 % under någon femårsperiod. Det säkerställer att vi bara analyserar arter med tillräckligt dataunderlag, samtidigt som vi fångar både arter som försvunnit och arter som nyetablerat sig.
>
> Arter som genomgått taxonomiska uppdelningar (t.ex. sädgås → skogsgås + tundragås) slås ihop till den ursprungliga arten för att undvika att omklassificeringar tolkas som verkliga förändringar.

---

## C. Tekniska förbättringar

### 14. Gemensam CSS → style.css
~300 rader CSS duplicerade i alla tre HTML-filer. Samma variabler, cards, badges, tabeller.

**Vinst:** En ändring fixar alla sidor. Mindre filer. Cacheas av webbläsaren.

**Att göra:**
- Extrahera gemensam CSS till `deploy/style.css`
- Behåll sidspecifik CSS inline (kartstorlekar, layoutskillnader)

### 15. Gemensamma JS-hjälpare → utils.js
Duplicerad kod: `formatDate`, `redlistBadge`, kart-setup, heatmap-konfiguration.

**Att göra:**
- Extrahera till `deploy/utils.js`: datumformatering, badges, kartinitiering
- Import i varje sida via `<script src="utils.js">`

### 16. Slå ihop deploy/ och public/
Två parallella HTML-kopior som kan driva isär.

**Förslag:**
- Använd enbart `deploy/` som source of truth
- `dev-server.py` serverar redan från `deploy/`
- Ta bort `public/` (eller gör det till en symlink)
- server.py och api.py blir onödiga om dev-server.py + live API fungerar

### 17. Automatisera phenology.json
Byggs manuellt med `build_phenology.py`. Kan bli inaktuell.

**Alternativ:**
- veckorapport.html använder redan DB-fenologi (senaste 5 åren)
- index.html använder fortfarande phenology.json för notable-scoring
- **Förslag:** Migrera index.html till DB-fenologi via stats-api.php
- Då kan phenology.json och build_phenology.py tas bort helt

### 18. Batch-endpoint i stats-api.php
statistik.html gör 6–8 parallella API-anrop vid sidladdning.

**Förslag:**
- `?q=overview_full` som returnerar overview + geo + localities + richness i ett svar
- Sparar HTTP-overhead, enklare cachehantering
- Alternativt: Server-side rendering av hela overview-HTML

### 19. API-svar: komprimering
stats-api.php returnerar okomprimerad JSON.

**Förslag:**
- Lägg till `ob_start("ob_gzhandler")` i PHP
- Eller `.htaccess`: `AddOutputFilterByType DEFLATE application/json`
- Kan halvera svarstorleken

### 20. Databasindex: compound index
Vanligaste query-mönstret: `WHERE taxon_id = X AND event_start_date ...`

**Förslag:**
- `CREATE INDEX idx_taxon_date ON observations(taxon_id, event_start_date)`
- Snabbar upp artsidornas fenologi- och trendqueries

### 21. Cachestrategi: TTL istället för manuell invalidering
Nu rensas cachen manuellt via cron-update.php eller clear_cache.sh.

**Förslag:**
- Kolla filens `mtime` vid cache-hit
- Om äldre än X timmar → regenerera i bakgrunden
- Servera stale data direkt, uppdatera async (stale-while-revalidate)

---

## D. Prioriterad att-göra-lista

### Fas 1: Städa & konsolidera ✅ (klar 2026-03-15)
- [x] Extrahera gemensam CSS till style.css
- [x] Extrahera gemensamma JS-hjälpare till utils.js
- [x] Migrera index.html från phenology.json till DB-fenologi
- [x] Ta bort phenology.json + build_phenology.py
- [x] Rensa bort public/ (använd deploy/ + dev-server.py)
- [x] Compound-index i databasen (taxon_id + datum)
- [x] Ta bort oanvända server.py + api.py

### Fas 2: Förbättra befintliga sidor ✅ (klar 2026-03-15)
- [x] Veckorapport: förra årets jämförelse (samma datumintervall, delta i summary cards)
- [x] Veckorapport: vårens framsteg-mätare (vecka 8–22, % av flyttfåglar anlända)
- [x] Veckorapport: "Håll utkik efter" – arter förväntade inom 21 dagar men ej rapporterade
- [x] index.html: "Ovanlig art"-badge (sällsynt/ovanlig/fåtalig baserat på obs/år)
- [x] index.html: rarity i notable-scoring (30–120 poäng)
- [x] Fenologi: earliest from alla 20 år (min dag-på-året, ej kronologiskt), avg from senaste 5
- [x] Rarity-filter: exkluderar hybrider, morfer, artgrupper
- [x] statistik.html: batch-endpoint för snabbare laddning (?q=init)
- [x] statistik.html: senaste observationer på artsidor (med datakälla-etikett)
- [x] statistik.html: permalänkar för artsidor (?art=sangsvan)
- [x] Deploy: auto-clear + warm cache efter FTP-upload
- [x] Säkerhet: .htaccess blockerar cron_secret.txt, nyckel roterad
- [x] statistik.html: säsongstidslinje + trendanalys på artsidor (Theil-Sen regression)
- [x] index.html: tidsomfångs-väljare – knappar: Idag, +1...+6 dagar (hybrid live API + SQLite)
- [x] index.html: ta bort radieväljare (fast 15 km, konsekvent med DB)
- [x] index.html: Art A–Ö grupperad vy med expanderbara detaljer + Artportalen-länkar

### Fas 3: Nya visualiseringar ✅ (klar 2026-03-15)
- [x] Artackumulering: årets artlista dag för dag vs 5-årssnitt + dygnsmedeltemperatur (SMHI)

### Fas 4: Nya visualiseringar & sidor
- [x] Trendanalys: "Tåkerns fågelfauna i förändring" på statistik.html (se §13)
- [x] Sticky sektionsnavigering på statistik.html och veckorapport.html
- [x] Om-sida: bakgrund om projektet, datakällor, kontakt
- [ ] Artguide: bläddra bland arter med filter och "chansen att se nu"
- [ ] Lokalsidor: klickbara lokaler med artlista och bästa besökstid

### Teknisk skuld (löpande)
- [ ] Gzip-komprimering på stats-api.php
- [ ] Cache TTL istället för manuell rensning
- [x] ~~Rensa server.py / api.py~~ (borttagna i fas 1)
