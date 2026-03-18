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
$EXPORT_LIMIT = 25000;

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

function getCount($dateFrom, $dateTo) {
    $body = buildSearchFilter($dateFrom, $dateTo);
    $response = apiPost('/Observations/Count', $body);
    return intval(json_decode($response, true));
}

function downloadCsv($dateFrom, $dateTo) {
    $body = buildSearchFilter($dateFrom, $dateTo);
    $body['output'] = ['fieldSet' => 'AllWithValues'];
    $body['propertyLabelType'] = 'PropertyName';
    $body['cultureCode'] = 'sv-SE';

    $raw = apiPost('/Exports/Download/Csv', $body, 'application/octet-stream', 300);

    if (empty($raw)) {
        logMsg("  WARNING: Empty raw response from /Exports/Download/Csv");
        return [[], []];
    }

    logMsg("  Raw response: " . strlen($raw) . " bytes, starts with: " . bin2hex(substr($raw, 0, 8)));

    // Response may be a ZIP containing a CSV
    $tmpFile = tempnam(sys_get_temp_dir(), 'sos_');
    file_put_contents($tmpFile, $raw);

    $csvData = null;
    $zip = new ZipArchive();
    if ($zip->open($tmpFile) === true) {
        $zipFiles = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $zipFiles[] = $zip->getNameIndex($i);
        }
        logMsg("  ZIP contains " . count($zipFiles) . " file(s): " . implode(', ', $zipFiles));
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $name = $zip->getNameIndex($i);
            if (str_ends_with($name, '.csv')) {
                $csvData = $zip->getFromIndex($i);
                if ($csvData === false) {
                    logMsg("  getFromIndex($i) returned false");
                } else {
                    logMsg("  CSV extracted: " . strlen($csvData) . " bytes");
                }
                break;
            }
        }
        $zip->close();
    } else {
        // Not a ZIP – log first 500 chars to diagnose unexpected formats
        logMsg("  Not a ZIP. Raw preview: " . substr($raw, 0, 500));
    }

    if ($csvData === null || $csvData === false) {
        $csvData = $raw;
    }
    unlink($tmpFile);

    // Remove UTF-8 BOM if present
    if (substr($csvData, 0, 3) === "\xEF\xBB\xBF") {
        $csvData = substr($csvData, 3);
    }

    // Parse tab-separated CSV using fgetcsv (handles multi-line quoted fields)
    $csvTmp = tempnam(sys_get_temp_dir(), 'csv_');
    file_put_contents($csvTmp, $csvData);
    $fh = fopen($csvTmp, 'r');
    $headers = fgetcsv($fh, 0, "\t");

    logMsg("  CSV headers (" . count($headers) . "): " . implode(' | ', array_slice($headers, 0, 5)) . " ...");

    $rows = [];
    $skipped = 0;
    while (($fields = fgetcsv($fh, 0, "\t")) !== false) {
        if (count($fields) === count($headers)) {
            $rows[] = array_combine($headers, $fields);
        } else {
            if ($skipped === 0) logMsg("  First skipped row has " . count($fields) . " fields (expected " . count($headers) . ")");
            $skipped++;
        }
    }
    fclose($fh);
    unlink($csvTmp);
    if ($skipped > 0) logMsg("  Skipped $skipped rows (field count mismatch, expected " . count($headers) . ")");

    return [$rows, $headers];
}

// ── Column mapping (CSV column → DB column) ──
$COLUMN_MAP = [
    'occurrence_id'         => 'OccurrenceId',
    'taxon_id'              => 'DyntaxaTaxonId',
    'scientific_name'       => 'ScientificName',
    'vernacular_name'       => 'VernacularName',
    'individual_count'      => 'IndividualCount',
    'event_start_date'      => 'StartDate',
    'event_end_date'        => 'EndDate',
    'start_time'            => 'PlainStartTime',
    'latitude'              => 'DecimalLatitude',
    'longitude'             => 'DecimalLongitude',
    'locality'              => 'Locality',
    'municipality'          => 'Municipality',
    'parish'                => 'Parish',
    'county'                => 'County',
    'recorded_by'           => 'RecordedBy',
    'reported_by'           => 'ReportedBy',
    'remarks'               => 'OccurrenceRemarks',
    'activity'              => 'Activity',
    'bird_nest_activity_id' => 'BirdNestActivityId',
    'sex'                   => 'Sex',
    'life_stage'            => 'LifeStage',
    'family'                => 'Family',
    'taxonomic_order'       => 'Order',
    'is_redlisted'          => 'TaxonIsRedlisted',
    'redlist_category'      => 'RedlistCategory',
    'verification_status'   => 'VerificationStatus',
    'url'                   => 'Url',
    'dataset_name'          => 'DatasetName',
];

function extractRow($csvRow) {
    global $COLUMN_MAP;

    $get = function($dbCol, $default = null) use ($csvRow, $COLUMN_MAP) {
        $csvCol = $COLUMN_MAP[$dbCol] ?? null;
        if ($csvCol && isset($csvRow[$csvCol]) && $csvRow[$csvCol] !== '') {
            return $csvRow[$csvCol];
        }
        return $default;
    };

    // Parse individual count
    $countRaw = $get('individual_count') ?? ($csvRow['OrganismQuantityInt'] ?? null);
    $count = ($countRaw !== null && $countRaw !== '') ? intval($countRaw) : null;

    // Parse redlisted
    $redRaw = $get('is_redlisted');
    $redlisted = ($redRaw !== null) ? (in_array(strtolower($redRaw), ['true', '1', 'yes']) ? 1 : 0) : null;

    // Parse lat/lng
    $lat = $get('latitude') !== null ? floatval($get('latitude')) : null;
    $lng = $get('longitude') !== null ? floatval($get('longitude')) : null;

    // Parse nest activity
    $nestRaw = $get('bird_nest_activity_id');
    $nestId = ($nestRaw !== null && $nestRaw !== '') ? intval($nestRaw) : null;

    return [
        'occurrence_id'         => $get('occurrence_id', ''),
        'taxon_id'              => $get('taxon_id'),
        'scientific_name'       => $get('scientific_name'),
        'vernacular_name'       => $get('vernacular_name'),
        'individual_count'      => $count,
        'event_start_date'      => $get('event_start_date'),
        'event_end_date'        => $get('event_end_date'),
        'start_time'            => $get('start_time'),
        'latitude'              => $lat,
        'longitude'             => $lng,
        'locality'              => $get('locality'),
        'municipality'          => $get('municipality'),
        'parish'                => $get('parish'),
        'county'                => $get('county'),
        'recorded_by'           => $get('recorded_by'),
        'reported_by'           => $get('reported_by'),
        'remarks'               => $get('remarks'),
        'activity'              => $get('activity'),
        'bird_nest_activity_id' => $nestId,
        'sex'                   => $get('sex'),
        'life_stage'            => $get('life_stage'),
        'family'                => $get('family'),
        'taxonomic_order'       => $get('taxonomic_order'),
        'is_redlisted'          => $redlisted,
        'redlist_category'      => $get('redlist_category'),
        'verification_status'   => $get('verification_status'),
        'url'                   => $get('url'),
        'dataset_name'          => $get('dataset_name'),
        'raw_data'              => json_encode($csvRow, JSON_UNESCAPED_UNICODE),
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
         verification_status, url, dataset_name, raw_data)
        VALUES
        (:occurrence_id, :taxon_id, :scientific_name, :vernacular_name,
         :individual_count, :event_start_date, :event_end_date, :start_time,
         :latitude, :longitude, :locality, :municipality, :parish, :county,
         :recorded_by, :reported_by, :remarks,
         :activity, :bird_nest_activity_id, :sex, :life_stage,
         :family, :taxonomic_order,
         :is_redlisted, :redlist_category,
         :verification_status, :url, :dataset_name, :raw_data)";

    $stmt = $db->prepare($sql);
    $inserted = 0;

    foreach ($rows as $csvRow) {
        $data = extractRow($csvRow);
        if (empty($data['occurrence_id'])) continue;

        $stmt->execute($data);
        if ($stmt->rowCount() > 0) $inserted++;
    }

    return $inserted;
}

function downloadPeriod($db, $dateFrom, $dateTo, $depth = 0) {
    global $EXPORT_LIMIT;
    $indent = str_repeat('  ', $depth);

    logMsg("{$indent}Period: $dateFrom -> $dateTo");

    $count = getCount($dateFrom, $dateTo);
    logMsg("{$indent}  Count: $count");
    sleep(1);

    if ($count === 0) return 0;

    if ($count > $EXPORT_LIMIT) {
        logMsg("{$indent}  Exceeds limit, splitting...");
        $d1 = new DateTime($dateFrom);
        $d2 = new DateTime($dateTo);
        $mid = clone $d1;
        $diff = $d1->diff($d2);
        $mid->modify('+' . intval($diff->days / 2) . ' days');
        $midStr = $mid->format('Y-m-d');
        $nextDay = (clone $mid)->modify('+1 day')->format('Y-m-d');

        $n1 = downloadPeriod($db, $dateFrom, $midStr, $depth + 1);
        $n2 = downloadPeriod($db, $nextDay, $dateTo, $depth + 1);
        return $n1 + $n2;
    }

    logMsg("{$indent}  Downloading CSV...");
    [$rows, $headers] = downloadCsv($dateFrom, $dateTo);

    if (empty($rows)) {
        logMsg("{$indent}  Empty response");
        return 0;
    }

    $inserted = insertRows($db, $rows);
    logMsg("{$indent}  Got " . count($rows) . " rows, $inserted new");
    sleep(1);

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
        foreach ($files as $f) { unlink($f); $cleared++; }
    }
    $warm = !isset($_GET['nowarm']);
    $warmed = 0;
    if ($warm) {
        $baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']);
        foreach (['overview', 'species', 'geo', 'localities', 'week_context&year=' . date('Y') . '&days=7'] as $ep) {
            @file_get_contents("$baseUrl/stats-api.php?q=$ep", false, stream_context_create(['http' => ['timeout' => 120]]));
            $warmed++;
        }
    }
    echo json_encode(['ok' => true, 'cleared' => $cleared, 'warmed' => $warmed]);
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
} catch (Exception $e) {
    logMsg("ERROR during download: " . $e->getMessage());
    $db->close();
    exit(1);
}

$countAfter = $db->querySingle("SELECT COUNT(*) FROM observations");
$netNew = $countAfter - $countBefore;
logMsg("Download complete: $netNew new observations (total: $countAfter)");

// ── Ensure indexes exist (idempotent) ──
$db->exec("CREATE INDEX IF NOT EXISTS idx_obs_locality_date ON observations(locality, event_start_date DESC, start_time DESC)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_obs_locality ON observations(locality)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_obs_date ON observations(event_start_date)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_obs_taxon_id ON observations(taxon_id)");
$db->exec("CREATE INDEX IF NOT EXISTS idx_obs_taxon_date ON observations(taxon_id, event_start_date)");

$db->close();

// ── Clear stats-api cache so fresh data is served ──
$cacheDir = __DIR__ . '/cache';
$warmEndpoints = ['overview', 'species', 'week_context&year=' . date('Y') . '&days=7', 'accumulation&year=' . date('Y')];

// Mondays: also clear and re-warm the slow geo/localities caches
$isWeeklyRefresh = (date('N') == 1); // 1 = Monday

if (is_dir($cacheDir)) {
    $files = glob("$cacheDir/*.json");
    $cleared = 0;
    $slowCaches = ['localities.json', 'geo.json'];
    foreach ($files as $f) {
        if (!$isWeeklyRefresh && in_array(basename($f), $slowCaches)) continue;
        unlink($f);
        $cleared++;
    }
    logMsg("Cleared $cleared cached files" . ($isWeeklyRefresh ? " (weekly full refresh)" : ""));
}

if ($isWeeklyRefresh) {
    $warmEndpoints = array_merge($warmEndpoints, ['geo', 'localities']);
}

// ── Pre-warm caches so first visitor doesn't wait ──
$baseUrl = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['REQUEST_URI']);
foreach ($warmEndpoints as $ep) {
    $url = "$baseUrl/stats-api.php?q=$ep";
    @file_get_contents($url, false, stream_context_create(['http' => ['timeout' => 120]]));
}
logMsg("Pre-warmed " . count($warmEndpoints) . " cache endpoints");

logMsg("=== Update complete: $netNew new observations ===");
