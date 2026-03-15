<?php
/**
 * API proxy for Artdatabanken SOS API.
 * Keeps the API key server-side.
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

// ── Config ──
// API key loaded from file outside web root for security.
// Create this file at the path below with just the key, nothing else.
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
$DAYS_BACK = 1;
$ALLOWED_RADII = [8, 10, 12, 15];
$ALLOWED_DAYS = [1, 7, 14];

if (isset($_GET['radius'])) {
    $r = intval($_GET['radius']);
    if (in_array($r, $ALLOWED_RADII)) {
        $RADIUS_M = $r * 1000;
    }
}

if (isset($_GET['days'])) {
    $d = intval($_GET['days']);
    if (in_array($d, $ALLOWED_DAYS)) {
        $DAYS_BACK = $d;
    }
}

$date_from = date('Y-m-d', strtotime("-{$DAYS_BACK} days"));
$date_to = date('Y-m-d');

$search_body = [
    'taxon' => [
        'ids' => [4000104],
        'includeUnderlyingTaxa' => true,
    ],
    'date' => [
        'startDate' => $date_from,
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
curl_close($ch);

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
$result = json_encode(['records' => $records, 'totalCount' => count($records)], JSON_UNESCAPED_UNICODE);

// Archive one snapshot per day for historical comparison
$archive_dir = __DIR__ . '/data';
if (!is_dir($archive_dir)) {
    mkdir($archive_dir, 0755, true);
}
$archive_file = $archive_dir . '/' . date('Y-m-d') . "_r{$RADIUS_M}_d{$DAYS_BACK}.json";
if (!file_exists($archive_file)) {
    file_put_contents($archive_file, $result);
}

echo $result;
