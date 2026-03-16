<?php
/**
 * API proxy for Artdatabanken SOS API.
 * Keeps the API key server-side.
 *
 * For days >= 2: hybrid approach – live SOS-API for today+yesterday,
 * SQLite database for older days. Merged and deduplicated server-side.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ── Config ──
$key_path = __DIR__ . '/takern_api_key.txt';
if (!file_exists($key_path)) {
    echo json_encode(['error' => 'API key not configured']);
    exit;
}
$API_KEY = trim(file_get_contents($key_path));
$BASE_URL = 'https://api.artdatabanken.se/species-observation-system/v1';
$TAKERN_LAT = 58.35;
$TAKERN_LNG = 14.81;
$RADIUS_M = 15000;
$DB_FILE = __DIR__ . '/takern_observations.db';

$DAYS_BACK = 1;
$ALLOWED_DAYS = [0, 1, 2, 3, 4, 5, 6, 7];

if (isset($_GET['days'])) {
    $d = intval($_GET['days']);
    if (in_array($d, $ALLOWED_DAYS)) {
        $DAYS_BACK = $d;
    }
}

// Optional taxon filter – when set, search for this specific taxon instead of all birds
$TAXON_ID = isset($_GET['taxon']) ? intval($_GET['taxon']) : null;

// ── Helper: convert SQLite row to SOS-API record format ──
function sqliteToRecord($row) {
    $startDate = $row['event_start_date'];
    if (!empty($row['start_time']) && $row['start_time'] !== '00:00') {
        $startDate .= 'T' . $row['start_time'] . ':00';
    }
    return [
        'event' => ['startDate' => $startDate, 'endDate' => $row['event_end_date']],
        'location' => [
            'decimalLatitude' => $row['latitude'] !== null ? floatval($row['latitude']) : null,
            'decimalLongitude' => $row['longitude'] !== null ? floatval($row['longitude']) : null,
            'locality' => $row['locality'],
        ],
        'taxon' => [
            'id' => intval($row['taxon_id']),
            'vernacularName' => $row['vernacular_name'],
            'scientificName' => $row['scientific_name'],
            'attributes' => [
                'isRedlisted' => $row['is_redlisted'] ? true : false,
                'redlistCategory' => $row['redlist_category'],
            ],
        ],
        'occurrence' => [
            'occurrenceId' => $row['occurrence_id'],
            'recordedBy' => $row['recorded_by'],
            'individualCount' => $row['individual_count'] !== null ? intval($row['individual_count']) : null,
            'occurrenceRemarks' => $row['remarks'],
        ],
    ];
}

// ── Helper: query SQLite for observations in date range ──
function queryLocalDb($dbFile, $dateFrom, $dateTo) {
    if (!file_exists($dbFile)) return [];

    $db = new SQLite3($dbFile, SQLITE3_OPEN_READONLY);
    $db->busyTimeout(5000);

    $stmt = $db->prepare(
        'SELECT occurrence_id, taxon_id, scientific_name, vernacular_name,
                individual_count, event_start_date, event_end_date, start_time,
                latitude, longitude, locality, recorded_by, remarks,
                is_redlisted, redlist_category
         FROM observations
         WHERE event_start_date >= :date_from
           AND event_start_date <= :date_to
           AND latitude IS NOT NULL
         ORDER BY event_start_date DESC'
    );
    $stmt->bindValue(':date_from', $dateFrom, SQLITE3_TEXT);
    $stmt->bindValue(':date_to', $dateTo, SQLITE3_TEXT);
    $result = $stmt->execute();

    $records = [];
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        $records[] = sqliteToRecord($row);
    }

    $db->close();
    return $records;
}

// ── Determine date ranges ──
$date_to = date('Y-m-d');

if ($DAYS_BACK <= 1) {
    // Simple case: live API only (today, or today+yesterday)
    $api_date_from = date('Y-m-d', strtotime("-{$DAYS_BACK} days"));
    $use_hybrid = false;
} else {
    // Hybrid: live API for today+yesterday, SQLite for older days
    $api_date_from = date('Y-m-d', strtotime('-1 days'));
    $sqlite_date_from = date('Y-m-d', strtotime("-{$DAYS_BACK} days"));
    $sqlite_date_to = date('Y-m-d', strtotime('-2 days'));
    $use_hybrid = true;
}

// ── Fetch from live SOS-API ──
$search_body = [
    'taxon' => $TAXON_ID
        ? ['ids' => [$TAXON_ID], 'includeUnderlyingTaxa' => false]
        : ['ids' => [4000104], 'includeUnderlyingTaxa' => true],
    'date' => [
        'startDate' => $api_date_from,
        'endDate' => $date_to,
        'dateFilterType' => 'BetweenStartDateAndEndDate',
    ],
    'geographics' => [
        'geometries' => [
            [
                'type' => 'point',
                'coordinates' => [$TAKERN_LNG, $TAKERN_LAT],
            ]
        ],
        'maxDistanceFromPoint' => $RADIUS_M,
        'considerObservationAccuracy' => false,
    ],
    'output' => [
        'fields' => [
            'event.startDate',
            'event.endDate',
            'location.decimalLatitude',
            'location.decimalLongitude',
            'location.locality',
            'taxon.id',
            'taxon.vernacularName',
            'taxon.scientificName',
            'occurrence.occurrenceId',
            'occurrence.recordedBy',
            'occurrence.individualCount',
            'occurrence.occurrenceRemarks',
            'taxon.attributes.isRedlisted',
            'taxon.attributes.redlistCategory',
        ],
        'sortBy' => 'event.startDate',
        'sortOrder' => 'Desc',
    ],
];

$ch = curl_init("{$BASE_URL}/Observations/Search?skip=0&take=1000");
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($search_body),
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        "Ocp-Apim-Subscription-Key: {$API_KEY}",
        'Cache-Control: no-cache',
    ],
]);

$response = curl_exec($ch);
$http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);

if ($error) {
    echo json_encode(['error' => $error]);
    exit;
}

if ($http_code !== 200) {
    echo json_encode(['error' => "API returned {$http_code}", 'detail' => $response]);
    exit;
}

$data = json_decode($response, true);
$records = $data['records'] ?? [];

// ── Hybrid merge: add SQLite records for older days ──
if ($use_hybrid) {
    $sqliteRecords = queryLocalDb($DB_FILE, $sqlite_date_from, $sqlite_date_to);

    // Dedup: build set of occurrence IDs from live API
    $seenIds = [];
    foreach ($records as $rec) {
        $id = $rec['occurrence']['occurrenceId'] ?? null;
        if ($id) $seenIds[$id] = true;
    }

    // Add SQLite records that aren't already in the live set
    foreach ($sqliteRecords as $rec) {
        $id = $rec['occurrence']['occurrenceId'] ?? null;
        if ($id && isset($seenIds[$id])) continue;
        $records[] = $rec;
        if ($id) $seenIds[$id] = true;
    }

    // Sort all records by date descending
    usort($records, function ($a, $b) {
        return strcmp($b['event']['startDate'] ?? '', $a['event']['startDate'] ?? '');
    });
}

$result = json_encode(['records' => $records, 'totalCount' => count($records)], JSON_UNESCAPED_UNICODE);

// Archive one snapshot per day for historical comparison
$archive_dir = __DIR__ . '/data';
if (!is_dir($archive_dir)) {
    mkdir($archive_dir, 0755, true);
}
$archive_file = $archive_dir . '/' . date('Y-m-d') . "_d{$DAYS_BACK}.json";
if (!file_exists($archive_file)) {
    file_put_contents($archive_file, $result);
}

echo $result;
