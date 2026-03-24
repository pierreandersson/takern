<?php
/**
 * Automated incremental update of Tåkern bird observation database.
 *
 * Runs on Websupport via cron job. Fetches new observations
 * from SOS API (Artdatabanken) and stores them in a local
 * SQLite database. stats-api.php reads from the same database
 * to serve data to statistik.html – no JSON export needed.
 *
 * Cron setup (Websupport control panel):
 *   0 4 * * * php /path/to/cron-update.php
 *   (runs every night at 04:00)
 *
 * First run: upload takern_observations.db alongside this file.
 * The script will then keep it updated incrementally.
 */

// ── Configuration ──
$API_KEY_FILE = __DIR__ . '/takern_api_key.txt';
$DB_FILE      = __DIR__ . '/takern_observations.db';
$LOG_FILE     = __DIR__ . '/cron-update.log';
$BASE_URL     = 'https://api.artdatabanken.se/species-observation-system/v1';

$TAKERN_LAT = 58.35;
$TAKERN_LNG = 14.81;
$RADIUS_M   = 15000;
$OVERLAP_DAYS = 3;  // Re-fetch last N days to catch late reports

// ── Logging ──
function logMsg($msg) {
    global $LOG_FILE;
    $ts = date('Y-m-d H:i:s');
    $line = "[$ts] $msg\n";
    echo $line;
    file_put_contents($LOG_FILE, $line, FILE_APPEND);
}

// ── API helpers ──
function apiPost($endpoint, $body, $accept = 'application/json', $timeout = 120) {
    global $BASE_URL, $API_KEY;
    $url = rtrim($BASE_URL, '/') . $endpoint;

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($body),
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: ' . $accept,
            "Ocp-Apim-Subscription-Key: $API_KEY",
            'Cache-Control: no-cache',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error) throw new Exception("cURL error: $error");
    if ($httpCode === 429) {
        logMsg("Rate limit (429) - waiting 10s...");
        sleep(10);
        return apiPost($endpoint, $body, $accept, $timeout);
    }
    if ($httpCode !== 200) throw new Exception("HTTP $httpCode: " . substr($response, 0, 500));

    return $response;
}

function buildSearchFilter($dateFrom, $dateTo) {
    global $TAKERN_LAT, $TAKERN_LNG, $RADIUS_M;
    return [
        'taxon' => ['ids' => [4000104], 'includeUnderlyingTaxa' => true],
        'date' => [
            'startDate' => $dateFrom,
            'endDate' => $dateTo,
            'dateFilterType' => 'BetweenStartDateAndEndDate',
        ],
        'geographics' => [
            'geometries' => [['type' => 'point', 'coordinates' => [$TAKERN_LNG, $TAKERN_LAT]]],
            'maxDistanceFromPoint' => $RADIUS_M,
            'considerObservationAccuracy' => false,
        ],
    ];
}


// ── Fetch observations via JSON Search API (replaces CSV export) ──
function searchObservations($dateFrom, $dateTo) {
    $body = buildSearchFilter($dateFrom, $dateTo);
    $body['output'] = [
        'fields' => [
            'occurrence.occurrenceId',
            'taxon.id',
            'taxon.scientificName',
            'taxon.vernacularName',
            'occurrence.individualCount',
            'event.startDate',
            'event.endDate',
            'event.plainStartTime',
            'location.decimalLatitude',
            'location.decimalLongitude',
            'location.locality',
            'location.municipality',
            'location.parish',
            'location.county',
            'occurrence.recordedBy',
            'occurrence.reportedBy',
            'occurrence.occurrenceRemarks',
            'occurrence.activity',
            'occurrence.birdNestActivityId',
            'occurrence.sex',
            'occurrence.lifeStage',
            'taxon.family',
            'taxon.order',
            'taxon.attributes.isRedlisted',
            'taxon.attributes.redlistCategory',
            'occurrence.verificationStatus',
            'occurrence.url',
            'datasetName',
        ],
    ];

    $allRecords = [];
    $skip = 0;
    $take = 1000;

    while (true) {
        $raw = apiPost("/Observations/Search?skip=$skip&take=$take", $body);
        $data = json_decode($raw, true);
        $records = $data['records'] ?? [];

        if (empty($records)) break;

        $allRecords = array_merge($allRecords, $records);
        logMsg("  Fetched " . count($records) . " records (total: " . count($allRecords) . ")");

        if (count($records) < $take) break;
        $skip += $take;
        sleep(1);
    }

    return $allRecords;
}

// ── Extract DB row from JSON record ──
function extractRow($rec) {
    $occ = $rec['occurrence'] ?? [];
    $taxon = $rec['taxon'] ?? [];
    $event = $rec['event'] ?? [];
    $loc = $rec['location'] ?? [];
    $attrs = $taxon['attributes'] ?? [];

    $count = $occ['individualCount'] ?? null;
    if ($count !== null) $count = intval($count);

    $redlisted = $attrs['isRedlisted'] ?? null;
    if ($redlisted !== null) $redlisted = $redlisted ? 1 : 0;

    $nestId = $occ['birdNestActivityId'] ?? null;
    if ($nestId !== null && $nestId !== '') $nestId = intval($nestId);
    else $nestId = null;

    $lat = $loc['decimalLatitude'] ?? null;
    $lng = $loc['decimalLongitude'] ?? null;
    if ($lat !== null) $lat = floatval($lat);
    if ($lng !== null) $lng = floatval($lng);

    // Extract date portion from ISO datetime
    $startDate = $event['startDate'] ?? null;
    if ($startDate && strlen($startDate) > 10) $startDate = substr($startDate, 0, 10);
    $endDate = $event['endDate'] ?? null;
    if ($endDate && strlen($endDate) > 10) $endDate = substr($endDate, 0, 10);

    return [
        'occurrence_id'         => $occ['occurrenceId'] ?? '',
        'taxon_id'              => $taxon['id'] ?? null,
        'scientific_name'       => $taxon['scientificName'] ?? null,
        'vernacular_name'       => $taxon['vernacularName'] ?? null,
        'individual_count'      => $count,
        'event_start_date'      => $startDate,
        'event_end_date'        => $endDate,
        'start_time'            => $event['plainStartTime'] ?? null,
        'latitude'              => $lat,
        'longitude'             => $lng,
        'locality'              => $loc['locality'] ?? null,
        'municipality'          => $loc['municipality'] ?? null,
        'parish'                => $loc['parish'] ?? null,
        'county'                => $loc['county'] ?? null,
        'recorded_by'           => $occ['recordedBy'] ?? null,
        'reported_by'           => $occ['reportedBy'] ?? null,
        'remarks'               => $occ['occurrenceRemarks'] ?? null,
        'activity'              => $occ['activity'] ?? null,
        'bird_nest_activity_id' => $nestId,
        'sex'                   => $occ['sex'] ?? null,
        'life_stage'            => $occ['lifeStage'] ?? null,
        'family'                => $taxon['family'] ?? null,
        'taxonomic_order'       => $taxon['order'] ?? null,
        'is_redlisted'          => $redlisted,
        'redlist_category'      => $attrs['redlistCategory'] ?? null,
        'verification_status'   => $occ['verificationStatus'] ?? null,
        'url'                   => $occ['url'] ?? null,
        'dataset_name'          => $rec['datasetName'] ?? null,
    ];
}

function insertRows($db, $rows) {
    $sql = "INSERT OR IGNORE INTO observations
        (occurrence_id, taxon_id, scientific_name, vernacular_name,
         individual_count, event_start_date, event_end_date, start_time,
         latitude, longitude, locality, municipality, parish, county,
         recorded_by, reported_by, remarks,
         activity, bird_nest_activity_id, sex, life_stage,
         family, taxonomic_order,
         is_redlisted, redlist_category,
         verification_status, url, dataset_name)
        VALUES
        (:occurrence_id, :taxon_id, :scientific_name, :vernacular_name,
         :individual_count, :event_start_date, :event_end_date, :start_time,
         :latitude, :longitude, :locality, :municipality, :parish, :county,
         :recorded_by, :reported_by, :remarks,
         :activity, :bird_nest_activity_id, :sex, :life_stage,
         :family, :taxonomic_order,
         :is_redlisted, :redlist_category,
         :verification_status, :url, :dataset_name)";

    $stmt = $db->prepare($sql);
    $inserted = 0;

    foreach ($rows as $rec) {
        $data = extractRow($rec);
        if (empty($data['occurrence_id'])) continue;

        $stmt->reset();
        foreach ($data as $key => $value) {
            $stmt->bindValue(":$key", $value);
        }
        $stmt->execute();
        if ($db->changes() > 0) $inserted++;
    }

    return $inserted;
}

function downloadPeriod($db, $dateFrom, $dateTo) {
    logMsg("Period: $dateFrom -> $dateTo");

    $records = searchObservations($dateFrom, $dateTo);

    if (empty($records)) {
        logMsg("  No records found");
        return 0;
    }

    $inserted = insertRows($db, $records);
    logMsg("  Got " . count($records) . " records, $inserted new");

    return $inserted;
}


// ══════════════════════════════════════════════════════════════
//  MAIN
// ══════════════════════════════════════════════════════════════

// Prevent web access - cron only
if (php_sapi_name() !== 'cli' && !isset($_GET['key'])) {
    // Allow manual trigger with a secret key
    http_response_code(403);
    echo "Cron only. Add ?key=YOUR_SECRET to run manually.";
    exit;
}

// Optional manual trigger key (set in config)
if (isset($_GET['key'])) {
    $expectedKey = trim(file_get_contents(__DIR__ . '/cron_secret.txt'));
    if ($_GET['key'] !== $expectedKey) {
        http_response_code(403);
        echo "Invalid key.";
        exit;
    }
}

// ── Manual cache clear ──
if (isset($_GET['action']) && $_GET['action'] === 'clear-cache') {
    $cacheDir = __DIR__ . '/cache';
    $cleared = 0;
    if (is_dir($cacheDir)) {
        $files = glob("$cacheDir/*.json");
        foreach ($files as $f) {
            if (strpos(basename($f), 'artfakta_') === 0) continue; // Preserve Artfakta cache
            unlink($f); $cleared++;
        }
    }
    $warm = !isset($_GET['nowarm']);
    $warmed = 0;
    if ($warm) {
        $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']);
        foreach (['overview', 'species', 'top_weekly', 'top_yearly', 'trends', 'geo', 'localities', 'week_context&year=' . date('Y') . '&days=7', 'accumulation&year=' . date('Y')] as $ep) {
            @file_get_contents("$baseUrl/stats-api.php?q=$ep", false, stream_context_create(['http' => ['timeout' => 120]]));
            $warmed++;
        }
    }
    echo json_encode(['ok' => true, 'cleared' => $cleared, 'warmed' => $warmed]);
    exit;
}

// ── Artfakta bootstrap: fetch all uncached species in one run ──
if (isset($_GET['action']) && $_GET['action'] === 'artfakta-bootstrap') {
    require_once __DIR__ . '/artfakta-fetch.php';
    $artfaktaKeyFile = __DIR__ . '/artfakta_api_key.txt';
    if (!file_exists($artfaktaKeyFile)) {
        echo json_encode(['ok' => false, 'error' => 'artfakta_api_key.txt missing']);
        exit;
    }
    $artfaktaKey = trim(file_get_contents($artfaktaKeyFile));
    if (!file_exists($DB_FILE)) {
        echo json_encode(['ok' => false, 'error' => 'Database missing']);
        exit;
    }
    $db = new SQLite3($DB_FILE, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(5000);
    $res = $db->query("SELECT DISTINCT taxon_id FROM observations WHERE vernacular_name IS NOT NULL ORDER BY taxon_id");
    $taxonIds = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $taxonIds[] = intval($row['taxon_id']);
    $db->close();

    // Filter to uncached species
    $uncached = array_filter($taxonIds, function($tid) { return getArtfaktaCache($tid) === null; });
    $skipped = count($taxonIds) - count($uncached);

    // Batch fetch in groups of 10
    $fetched = 0; $failed = 0;
    foreach (array_chunk(array_values($uncached), 10) as $batch) {
        $results = fetchArtfaktaBatch($batch, $artfaktaKey);
        foreach ($batch as $tid) {
            if (isset($results[$tid])) {
                saveArtfaktaCache($tid, $results[$tid]);
                $fetched++;
            } else {
                $failed++;
            }
        }
        sleep(1); // Pause between batches
    }
    echo json_encode(['ok' => true, 'fetched' => $fetched, 'skipped' => $skipped, 'failed' => $failed, 'total' => count($taxonIds)]);
    exit;
}

// ── Import Rödlista 2025: update artfakta cache + SQLite from JSON ──
if (isset($_GET['action']) && $_GET['action'] === 'import-rodlista-2025') {
    $jsonFile = __DIR__ . '/rodlista_2025_takern.json';
    if (!file_exists($jsonFile)) {
        echo json_encode(['ok' => false, 'error' => 'rodlista_2025_takern.json missing – upload it first']);
        exit;
    }
    $rodlista = json_decode(file_get_contents($jsonFile), true);
    if (!$rodlista) {
        echo json_encode(['ok' => false, 'error' => 'Could not parse JSON']);
        exit;
    }

    // Build lookup: taxon_id → new data
    $newRL = [];
    foreach ($rodlista as $r) {
        $newRL[$r['taxon_id']] = $r;
    }

    // Part 1: Update artfakta cache files
    $cacheDir = __DIR__ . '/cache';
    $cacheUpdated = 0; $cacheDownlisted = 0;
    $artfaktaFiles = glob("$cacheDir/artfakta_*.json");
    foreach ($artfaktaFiles as $f) {
        $data = json_decode(file_get_contents($f), true);
        if (!$data || !isset($data['taxon_id'])) continue;
        $tid = $data['taxon_id'];

        if (isset($newRL[$tid])) {
            // Species is in Rödlista 2025 (rödlistad)
            $r = $newRL[$tid];
            $data['redlist'] = [
                'category' => $r['category'],
                'criterion' => $r['criterion'],
                'criterion_text' => $r['criterion_text'],
                'period' => 'Rödlistning 2025',
            ];
            file_put_contents($f, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
            $cacheUpdated++;
        } else {
            // Species NOT in CSV → LC in 2025
            if (isset($data['redlist']) && $data['redlist'] && isset($data['redlist']['category']) && $data['redlist']['category'] !== 'LC') {
                $data['redlist'] = [
                    'category' => 'LC',
                    'criterion' => null,
                    'criterion_text' => null,
                    'period' => 'Rödlistning 2025',
                ];
                file_put_contents($f, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                $cacheDownlisted++;
            }
        }
    }

    // Part 2: Update SQLite observations table
    $dbUpdated = 0;
    if (file_exists($DB_FILE)) {
        $db = new SQLite3($DB_FILE);
        $db->busyTimeout(10000);
        $db->exec('BEGIN TRANSACTION');

        // Update species in the CSV
        $stmt = $db->prepare('UPDATE observations SET redlist_category = :cat, is_redlisted = :rl WHERE taxon_id = :tid');
        foreach ($newRL as $tid => $r) {
            $isRedlisted = in_array($r['category'], ['CR', 'EN', 'VU', 'NT', 'DD', 'RE']) ? 1 : 0;
            $stmt->bindValue(':cat', $r['category'], SQLITE3_TEXT);
            $stmt->bindValue(':rl', $isRedlisted, SQLITE3_INTEGER);
            $stmt->bindValue(':tid', $tid, SQLITE3_INTEGER);
            $stmt->execute();
            $stmt->reset();
            $dbUpdated += $db->changes();
        }

        // Downlist species NOT in CSV that currently have non-LC category
        $currentRL = $db->query("SELECT DISTINCT taxon_id, redlist_category FROM observations WHERE redlist_category IS NOT NULL AND redlist_category != 'LC' AND redlist_category != 'NA' AND redlist_category != 'NE'");
        $stmtDown = $db->prepare('UPDATE observations SET redlist_category = :cat, is_redlisted = 0 WHERE taxon_id = :tid');
        while ($row = $currentRL->fetchArray(SQLITE3_ASSOC)) {
            $tid = intval($row['taxon_id']);
            if (isset($newRL[$tid])) continue; // Already handled above
            $stmtDown->bindValue(':cat', 'LC', SQLITE3_TEXT);
            $stmtDown->bindValue(':tid', $tid, SQLITE3_INTEGER);
            $stmtDown->execute();
            $stmtDown->reset();
            $dbUpdated += $db->changes();
        }

        $db->exec('COMMIT');
        $db->close();
    }

    // Part 3: Clear stats-api cache
    $cacheCleared = 0;
    if (is_dir($cacheDir)) {
        foreach (glob("$cacheDir/*.json") as $f) {
            if (strpos(basename($f), 'artfakta_') === 0) continue;
            if (strpos(basename($f), 'smhi_') === 0) continue;
            unlink($f); $cacheCleared++;
        }
    }

    echo json_encode([
        'ok' => true,
        'artfakta_updated' => $cacheUpdated,
        'artfakta_downlisted' => $cacheDownlisted,
        'db_rows_updated' => $dbUpdated,
        'cache_cleared' => $cacheCleared,
        'species_in_json' => count($newRL),
    ]);
    exit;
}

logMsg("=== Update started ===");

// Load API key
if (!file_exists($API_KEY_FILE)) {
    logMsg("ERROR: API key file missing: $API_KEY_FILE");
    exit(1);
}
$API_KEY = trim(file_get_contents($API_KEY_FILE));

// Open database
if (!file_exists($DB_FILE)) {
    logMsg("ERROR: Database missing: $DB_FILE. Upload takern_observations.db first.");
    exit(1);
}

// ── Step 0: Backup database before any changes ──
$backupDir = __DIR__ . '/backups';
if (!is_dir($backupDir)) mkdir($backupDir, 0755, true);
$backupFile = "$backupDir/takern_observations_" . date('Y-m-d') . ".db";
if (!file_exists($backupFile)) {
    copy($DB_FILE, $backupFile);
    logMsg("Backup created: " . basename($backupFile) . " (" . round(filesize($backupFile)/1024/1024, 1) . " MB)");
} else {
    logMsg("Backup already exists for today");
}
// Keep only last 7 backups
$backups = glob("$backupDir/takern_observations_*.db");
rsort($backups);
foreach (array_slice($backups, 7) as $old) {
    unlink($old);
    logMsg("Removed old backup: " . basename($old));
}

// Backup krysslista data (if it exists)
$kryssFile = __DIR__ . '/krysslista-data.json';
if (file_exists($kryssFile)) {
    $kryssBackup = "$backupDir/krysslista_" . date('Y-m-d') . ".json";
    if (!file_exists($kryssBackup)) {
        copy($kryssFile, $kryssBackup);
        logMsg("Krysslista backup created: " . basename($kryssBackup));
    }
    // Keep only last 10 krysslista backups
    $kryssBackups = glob("$backupDir/krysslista_*.json");
    rsort($kryssBackups);
    foreach (array_slice($kryssBackups, 10) as $old) {
        unlink($old);
        logMsg("Removed old krysslista backup: " . basename($old));
    }
}

$db = new SQLite3($DB_FILE);
$db->exec("PRAGMA journal_mode=WAL");

// ── Step 1: Determine date range ──
$lastDate = $db->querySingle("SELECT MAX(event_start_date) FROM observations");
if (!$lastDate) {
    logMsg("ERROR: Database is empty. Upload a populated database first.");
    $db->close();
    exit(1);
}

$today = date('Y-m-d');
$overlapDate = date('Y-m-d', strtotime("$lastDate -$OVERLAP_DAYS days"));

logMsg("Last obs in DB: $lastDate");
logMsg("Fetching from: $overlapDate (with $OVERLAP_DAYS days overlap)");

if ($overlapDate >= $today) {
    logMsg("Database is already up to date.");
    $db->close();
    exit(0);
}

// ── Step 2: Fetch new observations ──
$countBefore = $db->querySingle("SELECT COUNT(*) FROM observations");

try {
    $inserted = downloadPeriod($db, $overlapDate, $today);
} catch (Throwable $e) {
    logMsg("ERROR during download: " . $e->getMessage() . " in " . $e->getFile() . ":" . $e->getLine());
    $db->close();
    exit(1);
}

$countAfter = $db->querySingle("SELECT COUNT(*) FROM observations");
$netNew = $countAfter - $countBefore;
logMsg("Download complete: $netNew new observations (total: $countAfter)");

// ── Integrity check ──
$integrity = $db->querySingle("PRAGMA quick_check");
if ($integrity !== 'ok') {
    logMsg("CRITICAL: Database integrity check failed: $integrity");
    logMsg("Restore from backup: $backupDir");
    $db->close();
    exit(1);
}
logMsg("Integrity check: ok");

// ── Ensure indexes exist (idempotent) ──
$db->exec("CREATE INDEX IF NOT EXISTS idx_obs_locality_date ON observations(locality, event_start_date DESC, start_time DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_obs_locality ON observations(locality)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_obs_date ON observations(event_start_date)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_obs_taxon_id ON observations(taxon_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_obs_taxon_date ON observations(taxon_id, event_start_date)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_obs_recorded_by ON observations(recorded_by)");

$db->close();

// ── Clear stats-api cache so fresh data is served ──
$cacheDir = __DIR__ . '/cache';
$warmEndpoints = ['overview', 'species', 'top_weekly', 'top_yearly', 'trends', 'week_context&year=' . date('Y') . '&days=7', 'accumulation&year=' . date('Y')];

// Mondays: also clear and re-warm the slow geo/localities caches
$isWeeklyRefresh = (date('N') == 1); // 1 = Monday

if (is_dir($cacheDir)) {
    $files = glob("$cacheDir/*.json");
    $cleared = 0;
    $slowCaches = ['localities.json', 'geo.json'];
    foreach ($files as $f) {
        if (strpos(basename($f), 'artfakta_') === 0) continue; // Preserve Artfakta cache
        if (!$isWeeklyRefresh && in_array(basename($f), $slowCaches)) continue;
        unlink($f);
        $cleared++;
    }
    logMsg("Cleared $cleared cached files" . ($isWeeklyRefresh ? " (weekly full refresh)" : ""));
}

if ($isWeeklyRefresh) {
    $warmEndpoints = array_merge($warmEndpoints, ['geo', 'localities']);
}

// ── Pre-warm caches ──
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']);
foreach ($warmEndpoints as $ep) {
    @file_get_contents("$baseUrl/stats-api.php?q=$ep", false, stream_context_create(['http' => ['timeout' => 120]]));
}
logMsg("Pre-warmed " . count($warmEndpoints) . " cache endpoints");

// ── Artfakta: refresh stale species descriptions ──
$artfaktaKeyFile = __DIR__ . '/artfakta_api_key.txt';
if (file_exists($artfaktaKeyFile)) {
    require_once __DIR__ . '/artfakta-fetch.php';
    $artfaktaKey = trim(file_get_contents($artfaktaKeyFile));
    $afDb = new SQLite3($DB_FILE, SQLITE3_OPEN_READONLY);
    $afDb->busyTimeout(5000);
    $res = $afDb->query("SELECT DISTINCT taxon_id FROM observations WHERE vernacular_name IS NOT NULL ORDER BY taxon_id");
    $taxonIds = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $taxonIds[] = intval($row['taxon_id']);
    $afDb->close();

    // Find species needing refresh: uncached, or cache older than 90 days
    $needRefresh = [];
    foreach ($taxonIds as $tid) {
        if (getArtfaktaCacheAge($tid) > 90) $needRefresh[] = $tid;
    }

    // Batch fetch up to 10 species per cron run (1 API call)
    $maxPerRun = 10;
    $toFetch = array_slice($needRefresh, 0, $maxPerRun);
    $fetched = 0;
    foreach (array_chunk($toFetch, 10) as $batch) {
        $results = fetchArtfaktaBatch($batch, $artfaktaKey);
        foreach ($batch as $tid) {
            if (isset($results[$tid])) {
                $newData = $results[$tid];
                // Guard: don't overwrite newer Rödlista 2025 with stale 2020 from API
                $existing = getArtfaktaCache($tid);
                if ($existing && isset($existing['redlist']['period'])
                    && $existing['redlist']['period'] === 'Rödlistning 2025'
                    && isset($newData['redlist']['period'])
                    && $newData['redlist']['period'] !== 'Rödlistning 2025') {
                    $newData['redlist'] = $existing['redlist'];
                }
                saveArtfaktaCache($tid, $newData);
                $fetched++;
            }
        }
        sleep(1);
    }
    $remaining = max(0, count($needRefresh) - $maxPerRun);
    if ($fetched > 0 || $remaining > 0) {
        logMsg("Artfakta: updated $fetched species" . ($remaining > 0 ? " ($remaining remaining)" : " (all up to date)"));
    }
}

logMsg("=== Update complete: $netNew new observations ===");
