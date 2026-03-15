<?php
/**
 * Statistics API – serves historical bird data from SQLite.
 * Results are cached as JSON files and invalidated by cron-update.php.
 *
 * Endpoints (via ?q= parameter):
 *   ?q=overview          → overview stats
 *   ?q=species           → species list
 *   ?q=species&id=12345  → species detail
 *   ?q=geo               → global geo heatmap
 *   ?q=geo&id=12345      → species geo heatmap
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

$DB_FILE = __DIR__ . '/takern_observations.db';
$CACHE_DIR = __DIR__ . '/cache';

$q = $_GET['q'] ?? '';
$id = isset($_GET['id']) ? intval($_GET['id']) : null;

// ── Cache layer ──
if (!is_dir($CACHE_DIR)) mkdir($CACHE_DIR, 0755, true);

$cacheKey = $id !== null ? "{$q}_{$id}" : $q;
$cacheFile = "$CACHE_DIR/$cacheKey.json";

if (file_exists($cacheFile)) {
    readfile($cacheFile);
    exit;
}

// No cache hit – query database
if (!file_exists($DB_FILE)) {
    echo json_encode(['error' => 'Database not found']);
    exit;
}

// Catch PHP errors and return as JSON
set_error_handler(function($severity, $msg, $file, $line) {
    throw new ErrorException($msg, 0, $severity, $file, $line);
});

try {

$db = new SQLite3($DB_FILE, SQLITE3_OPEN_READONLY);

function doyToStr($doy) {
    if ($doy === null) return null;
    $months = ['jan','feb','mar','apr','maj','jun','jul','aug','sep','okt','nov','dec'];
    $date = DateTime::createFromFormat('z Y', ($doy - 1) . ' 2024');
    return $date->format('j') . ' ' . $months[intval($date->format('n')) - 1];
}

function jsonOut($data) {
    global $cacheFile;
    $json = json_encode($data, JSON_UNESCAPED_UNICODE);
    file_put_contents($cacheFile, $json);
    echo $json;
    exit;
}

// ── Overview ──
if ($q === 'overview') {
    $total = $db->querySingle("SELECT COUNT(*) FROM observations");
    $species = $db->querySingle("SELECT COUNT(DISTINCT taxon_id) FROM observations");
    $observers = $db->querySingle("SELECT COUNT(DISTINCT recorded_by) FROM observations WHERE recorded_by IS NOT NULL");
    $r = $db->querySingle("SELECT MIN(event_start_date) mn, MAX(event_start_date) mx FROM observations", true);

    $perYear = [];
    $res = $db->query("SELECT SUBSTR(event_start_date,1,4) y, COUNT(*) n FROM observations GROUP BY y ORDER BY y");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $perYear[$row['y']] = intval($row['n']);

    $topSpecies = [];
    $res = $db->query("SELECT taxon_id, vernacular_name, scientific_name, COUNT(*) n
        FROM observations WHERE vernacular_name IS NOT NULL GROUP BY taxon_id ORDER BY n DESC LIMIT 20");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $topSpecies[] = ['taxon_id' => intval($row['taxon_id']), 'name' => $row['vernacular_name'],
            'scientific' => $row['scientific_name'], 'count' => intval($row['n'])];
    }

    $records = [];
    $res = $db->query("SELECT vernacular_name, taxon_id, individual_count, event_start_date, locality, url
        FROM observations WHERE individual_count IS NOT NULL ORDER BY individual_count DESC LIMIT 20");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $records[] = ['name' => $row['vernacular_name'], 'taxon_id' => intval($row['taxon_id']),
            'count' => intval($row['individual_count']), 'date' => $row['event_start_date'],
            'locality' => $row['locality'], 'url' => $row['url']];
    }

    $topLocalities = [];
    $res = $db->query("SELECT locality, COUNT(*) n FROM observations
        WHERE locality IS NOT NULL AND locality != '' GROUP BY locality ORDER BY n DESC LIMIT 15");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $topLocalities[] = ['name' => $row['locality'], 'count' => intval($row['n'])];
    }

    $heatmap = [];
    $res = $db->query("SELECT SUBSTR(event_start_date,1,4) y, CAST(SUBSTR(event_start_date,6,2) AS INTEGER) m, COUNT(*) n
        FROM observations WHERE event_start_date IS NOT NULL GROUP BY y, m ORDER BY y, m");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        if (!isset($heatmap[$row['y']])) $heatmap[$row['y']] = [];
        $heatmap[$row['y']][strval($row['m'])] = intval($row['n']);
    }

    $richness = [];
    $res = $db->query("SELECT m, ROUND(AVG(n),1) avg_species FROM (
        SELECT SUBSTR(event_start_date,1,4) y, CAST(SUBSTR(event_start_date,6,2) AS INTEGER) m,
        COUNT(DISTINCT taxon_id) n FROM observations WHERE event_start_date IS NOT NULL GROUP BY y, m
    ) GROUP BY m ORDER BY m");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $richness[intval($row['m'])] = floatval($row['avg_species']);

    $newSpecies = [];
    $res = $db->query("SELECT first_year, COUNT(*) n, GROUP_CONCAT(vernacular_name, ', ') names FROM (
        SELECT taxon_id, vernacular_name, MIN(SUBSTR(event_start_date,1,4)) first_year
        FROM observations WHERE vernacular_name IS NOT NULL GROUP BY taxon_id
    ) GROUP BY first_year ORDER BY first_year");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $newSpecies[$row['first_year']] = ['count' => intval($row['n']),
            'names' => substr($row['names'] ?? '', 0, 500)];
    }

    $topObservers = [];
    $res = $db->query("SELECT SUBSTR(event_start_date,1,4) y, recorded_by, COUNT(*) n
        FROM observations WHERE recorded_by IS NOT NULL GROUP BY y, recorded_by ORDER BY y, n DESC");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $y = $row['y'];
        if (!isset($topObservers[$y])) $topObservers[$y] = [];
        if (count($topObservers[$y]) < 5) {
            $topObservers[$y][] = ['name' => $row['recorded_by'], 'count' => intval($row['n'])];
        }
    }

    $timeOfDay = [];
    $res = $db->query("SELECT CAST(SUBSTR(start_time,1,2) AS INTEGER) h, COUNT(*) n
        FROM observations WHERE start_time IS NOT NULL AND start_time != '00:00' GROUP BY h ORDER BY h");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $timeOfDay[intval($row['h'])] = intval($row['n']);

    jsonOut([
        'total_observations' => $total,
        'total_species' => $species,
        'total_observers' => $observers,
        'date_range' => ['from' => $r['mn'], 'to' => $r['mx']],
        'observations_per_year' => $perYear,
        'top_species' => $topSpecies,
        'record_counts' => $records,
        'top_localities' => $topLocalities,
        'heatmap' => $heatmap,
        'richness_per_month' => $richness,
        'new_species_per_year' => $newSpecies,
        'top_observers' => $topObservers,
        'time_of_day' => $timeOfDay,
    ]);
}

// ── Species list ──
if ($q === 'species' && $id === null) {
    $species = [];
    $res = $db->query("SELECT taxon_id, vernacular_name, scientific_name,
        COUNT(*) obs_count, MAX(individual_count) max_count,
        MIN(event_start_date) first_obs, MAX(event_start_date) last_obs,
        COUNT(DISTINCT SUBSTR(event_start_date,1,4)) years_present
        FROM observations WHERE vernacular_name IS NOT NULL
        GROUP BY taxon_id ORDER BY obs_count DESC");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $species[] = [
            'taxon_id' => intval($row['taxon_id']),
            'name' => $row['vernacular_name'],
            'scientific' => $row['scientific_name'],
            'obs_count' => intval($row['obs_count']),
            'max_count' => $row['max_count'] !== null ? intval($row['max_count']) : null,
            'first_obs' => $row['first_obs'],
            'last_obs' => $row['last_obs'],
            'years_present' => intval($row['years_present']),
        ];
    }
    jsonOut(['species' => $species]);
}

// ── Species detail ──
if ($q === 'species' && $id !== null) {
    // Check which columns exist (server DB may lack newer columns)
    $cols = [];
    $pragma = $db->query("PRAGMA table_info(observations)");
    while ($r = $pragma->fetchArray(SQLITE3_ASSOC)) $cols[] = $r['name'];

    $extraCols = '';
    if (in_array('family', $cols)) $extraCols .= ', family';
    if (in_array('taxonomic_order', $cols)) $extraCols .= ', taxonomic_order';
    if (in_array('redlist_category', $cols)) $extraCols .= ', redlist_category';

    $info = $db->querySingle("SELECT vernacular_name, scientific_name $extraCols,
        COUNT(*) obs_count,
        MIN(event_start_date) first_obs, MAX(event_start_date) last_obs
        FROM observations WHERE taxon_id = $id", true);

    if (!$info || !$info['vernacular_name']) { jsonOut(['error' => 'Not found']); }

    $perYear = [];
    $res = $db->query("SELECT SUBSTR(event_start_date,1,4) y, COUNT(*) n
        FROM observations WHERE taxon_id = $id GROUP BY y ORDER BY y");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $perYear[$row['y']] = intval($row['n']);

    $numYears = count($perYear);

    $weekCounts = [];
    $res = $db->query("SELECT SUBSTR(event_start_date,1,4) y,
        CAST(STRFTIME('%W', event_start_date) AS INTEGER) w, COUNT(*) n
        FROM observations WHERE taxon_id = $id GROUP BY y, w");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $w = intval($row['w']);
        if (!isset($weekCounts[$w])) $weekCounts[$w] = [];
        $weekCounts[$w][] = intval($row['n']);
    }

    $seasonCurve = [];
    for ($w = 0; $w < 53; $w++) {
        $vals = $weekCounts[$w] ?? [];
        $avg = $numYears > 0 ? array_sum($vals) / $numYears : 0;
        if ($avg > 0) $seasonCurve[$w] = round($avg, 1);
    }

    $phenology = [];
    $firstDays = [];
    $lastDays = [];
    $res = $db->query("SELECT SUBSTR(event_start_date,1,4) y,
        MIN(event_start_date) first, MAX(event_start_date) last
        FROM observations WHERE taxon_id = $id GROUP BY y ORDER BY y");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $phenology[$row['y']] = ['first' => $row['first'], 'last' => $row['last']];
        $d = DateTime::createFromFormat('Y-m-d', $row['first']);
        if ($d) $firstDays[] = intval($d->format('z')) + 1;
        $d = DateTime::createFromFormat('Y-m-d', $row['last']);
        if ($d) $lastDays[] = intval($d->format('z')) + 1;
    }

    $phenSummary = [
        'avg_first' => !empty($firstDays) ? doyToStr(intval(round(array_sum($firstDays)/count($firstDays)))) : null,
        'avg_last' => !empty($lastDays) ? doyToStr(intval(round(array_sum($lastDays)/count($lastDays)))) : null,
        'earliest_ever' => !empty($firstDays) ? doyToStr(min($firstDays)) : null,
        'latest_ever' => !empty($lastDays) ? doyToStr(max($lastDays)) : null,
    ];

    $maxCounts = [];
    $res = $db->query("SELECT o.y, o.mx, o.tot, d.event_start_date AS date, d.locality, d.url
        FROM (
            SELECT SUBSTR(event_start_date,1,4) y, MAX(individual_count) mx, SUM(individual_count) tot
            FROM observations WHERE taxon_id = $id AND individual_count IS NOT NULL GROUP BY y
        ) o LEFT JOIN observations d ON d.taxon_id = $id
            AND SUBSTR(d.event_start_date,1,4) = o.y AND d.individual_count = o.mx
        ORDER BY o.y");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        if (!isset($maxCounts[$row['y']])) {
            $maxCounts[$row['y']] = ['max' => intval($row['mx']), 'total' => intval($row['tot']),
                'date' => $row['date'], 'locality' => $row['locality'], 'url' => $row['url']];
        }
    }

    $topLocalities = [];
    $res = $db->query("SELECT locality, COUNT(*) n FROM observations
        WHERE taxon_id = $id AND locality IS NOT NULL AND locality != ''
        GROUP BY locality ORDER BY n DESC LIMIT 5");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $topLocalities[] = ['name' => $row['locality'], 'count' => intval($row['n'])];
    }

    $timeOfDay = [];
    $res = $db->query("SELECT CAST(SUBSTR(start_time,1,2) AS INTEGER) h, COUNT(*) n
        FROM observations WHERE taxon_id = $id AND start_time IS NOT NULL AND start_time != '00:00'
        GROUP BY h ORDER BY h");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) $timeOfDay[intval($row['h'])] = intval($row['n']);

    jsonOut([
        'taxon_id' => $id,
        'name' => $info['vernacular_name'],
        'scientific' => $info['scientific_name'],
        'family' => $info['family'] ?? null,
        'order' => $info['taxonomic_order'] ?? null,
        'redlist_category' => $info['redlist_category'] ?? null,
        'obs_count' => intval($info['obs_count']),
        'first_obs' => $info['first_obs'],
        'last_obs' => $info['last_obs'],
        'observations_per_year' => $perYear,
        'season_curve' => $seasonCurve,
        'phenology' => $phenology,
        'phenology_summary' => $phenSummary,
        'max_counts_per_year' => $maxCounts,
        'top_localities' => $topLocalities,
        'time_of_day' => $timeOfDay,
    ]);
}

// ── Geo heatmap ──
if ($q === 'geo') {
    if ($id) {
        $res = $db->query("SELECT ROUND(latitude,3) lat, ROUND(longitude,3) lng, COUNT(*) n
            FROM observations WHERE latitude IS NOT NULL AND longitude IS NOT NULL AND taxon_id = $id
            GROUP BY lat, lng");
    } else {
        $res = $db->query("SELECT ROUND(latitude,3) lat, ROUND(longitude,3) lng, COUNT(*) n
            FROM observations WHERE latitude IS NOT NULL AND longitude IS NOT NULL GROUP BY lat, lng");
    }

    $points = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $points[] = [floatval($row['lat']), floatval($row['lng']), intval($row['n'])];
    }
    jsonOut(['points' => $points]);
}

// ── Localities ──
if ($q === 'localities') {
    $localities = [];
    $sql = "SELECT locality, ROUND(AVG(latitude),5) lat, ROUND(AVG(longitude),5) lng,
        COUNT(*) obs_count, COUNT(DISTINCT taxon_id) species_count
        FROM observations
        WHERE locality IS NOT NULL AND locality != '' AND latitude IS NOT NULL";
    if ($id) $sql .= " AND taxon_id = $id";
    $sql .= " GROUP BY locality HAVING obs_count >= 5 ORDER BY obs_count DESC LIMIT 500";

    $res = $db->query($sql);
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $localities[] = [
            'name' => $row['locality'],
            'lat' => floatval($row['lat']),
            'lng' => floatval($row['lng']),
            'obs' => intval($row['obs_count']),
            'species' => intval($row['species_count']),
        ];
    }
    jsonOut(['localities' => $localities]);
}

// ── Unknown endpoint ──
echo json_encode(['error' => 'Unknown query. Use ?q=overview, ?q=species, ?q=species&id=X, ?q=geo, or ?q=localities']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'line' => $e->getLine()]);
}
