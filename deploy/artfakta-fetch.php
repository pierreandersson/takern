<?php
/**
 * Artfakta API client – fetches species descriptions from SLU Artdatabanken.
 *
 * Used by cron-update.php to pre-populate cache/artfakta_{taxonId}.json files.
 * These cache files are read by stats-api.php and included in species detail responses.
 *
 * API: Artfakta - Species information API v1
 * Docs: https://api-portal.artdatabanken.se/
 * Endpoint: speciesdata?taxa={comma-separated taxonIds}
 * Response structure per species:
 *   - speciesData.speciesFactText.characteristic  – Kännetecken
 *   - speciesData.speciesFactText.ecology         – Ekologi
 *   - speciesData.speciesFactText.spreadAndStatus  – Utbredning & status
 *   - speciesData.speciesFactText.threat           – Hot
 *   - speciesData.speciesFactText.conservationMeasures – Åtgärder
 *   - speciesData.taxonRelatedInformation.swedishPresence
 *   - speciesData.redlistInfo[].category, .criterionText, .period.name
 *
 * Note: Most LC species have no texts. Texts are primarily available
 * for red-listed species (NT, VU, EN, CR).
 */

$ARTFAKTA_BASE_URL = 'https://api.artdatabanken.se/information/v1/speciesdataservice/v1';
$ARTFAKTA_CACHE_DIR = __DIR__ . '/cache';

/**
 * GET request to Artfakta API.
 */
function artfaktaApiGet($endpoint, $apiKey, $timeout = 30) {
    global $ARTFAKTA_BASE_URL;
    $url = rtrim($ARTFAKTA_BASE_URL, '/') . '/' . ltrim($endpoint, '/');

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => $timeout,
        CURLOPT_HTTPHEADER => [
            'Accept: application/json',
            "Ocp-Apim-Subscription-Key: $apiKey",
            'Cache-Control: no-cache',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    unset($ch);

    if ($error) {
        logMsg("Artfakta cURL error: $error");
        return null;
    }
    if ($httpCode === 429) {
        logMsg("Artfakta rate limit (429) - waiting 10s...");
        sleep(10);
        return artfaktaApiGet($endpoint, $apiKey, $timeout);
    }
    if ($httpCode !== 200) {
        logMsg("Artfakta HTTP $httpCode for $endpoint: " . substr($response, 0, 200));
        return null;
    }

    return json_decode($response, true);
}

/**
 * Fetch species info for multiple taxon IDs in one API call.
 * Returns array of normalized species data keyed by taxon_id.
 */
function fetchArtfaktaBatch($taxonIds, $apiKey) {
    $ids = implode(',', $taxonIds);
    $data = artfaktaApiGet("speciesdata?taxa=$ids", $apiKey);
    if (!$data || !is_array($data)) return [];

    $results = [];
    foreach ($data as $species) {
        $tid = intval($species['taxonId'] ?? 0);
        if (!$tid) continue;
        $results[$tid] = normalizeArtfaktaSpecies($species);
    }
    return $results;
}

/**
 * Normalize a single Artfakta API response to our cache format.
 */
function normalizeArtfaktaSpecies($species) {
    $sd = $species['speciesData'] ?? [];
    $sft = $sd['speciesFactText'] ?? [];
    $tri = $sd['taxonRelatedInformation'] ?? [];
    $tid = intval($species['taxonId'] ?? 0);

    // Red list details (most recent assessment first)
    $redlist = null;
    if (!empty($sd['redlistInfo'])) {
        $latest = $sd['redlistInfo'][0];
        $redlist = [
            'category' => $latest['category'] ?? null,
            'criterion' => $latest['criterion'] ?? null,
            'criterion_text' => $latest['criterionText'] ?? null,
            'period' => $latest['period']['name'] ?? null,
        ];
    }

    $normalized = [
        'taxon_id' => $tid,
        'fetched_at' => date('Y-m-d'),
        'characteristic' => cleanText($sft['characteristic'] ?? null),
        'ecology' => cleanText($sft['ecology'] ?? null),
        'spread_and_status' => cleanText($sft['spreadAndStatus'] ?? null),
        'threat' => cleanText($sft['threat'] ?? null),
        'conservation_measures' => cleanText($sft['conservationMeasures'] ?? null),
        'swedish_presence' => $tri['swedishPresence'] ?? null,
        'immigration_history' => $tri['immigrationHistory'] ?? null,
        'redlist' => $redlist,
        'source_url' => "https://artfakta.se/artbestamning/taxon/$tid",
    ];

    // Flag whether this species has any text content
    $normalized['has_text'] = !empty($normalized['characteristic'])
        || !empty($normalized['ecology'])
        || !empty($normalized['spread_and_status'])
        || !empty($normalized['threat']);

    return $normalized;
}

/**
 * Strip HTML tags and clean whitespace from API text fields.
 */
function cleanText($text) {
    if (!is_string($text) || trim($text) === '') return null;
    $text = strip_tags($text);
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = preg_replace('/\s+/', ' ', $text);
    return trim($text) ?: null;
}

// ── Cache I/O ──

function getArtfaktaCache($taxonId) {
    global $ARTFAKTA_CACHE_DIR;
    $file = "$ARTFAKTA_CACHE_DIR/artfakta_$taxonId.json";
    if (!file_exists($file)) return null;
    $data = json_decode(file_get_contents($file), true);
    return $data ?: null;
}

function saveArtfaktaCache($taxonId, $data) {
    global $ARTFAKTA_CACHE_DIR;
    if (!is_dir($ARTFAKTA_CACHE_DIR)) mkdir($ARTFAKTA_CACHE_DIR, 0755, true);
    $file = "$ARTFAKTA_CACHE_DIR/artfakta_$taxonId.json";
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

function getArtfaktaCacheAge($taxonId) {
    global $ARTFAKTA_CACHE_DIR;
    $file = "$ARTFAKTA_CACHE_DIR/artfakta_$taxonId.json";
    if (!file_exists($file)) return PHP_INT_MAX;
    $data = json_decode(file_get_contents($file), true);
    if (!$data || empty($data['fetched_at'])) return PHP_INT_MAX;
    $fetchedAt = strtotime($data['fetched_at']);
    return (time() - $fetchedAt) / 86400; // days
}
