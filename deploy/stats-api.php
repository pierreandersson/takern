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

$cacheKey = $q;
if ($id !== null) $cacheKey .= "_$id";
if (isset($_GET['year'])) $cacheKey .= "_y" . intval($_GET['year']);
if (isset($_GET['name'])) $cacheKey .= "_n" . md5($_GET['name']);
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
    if ($doy === null || $doy < 1 || $doy > 366) return null;
    $months = ['jan','feb','mar','apr','maj','jun','jul','aug','sep','okt','nov','dec'];
    $ts = mktime(0, 0, 0, 1, intval($doy), 2024);
    return intval(date('j', $ts)) . ' ' . $months[intval(date('n', $ts)) - 1];
}

function getBirdGroup($order, $family) {
    // Map taxonomic order + family to Swedish bird group names
    $map = [
        'Anseriformes' => 'Andfåglar',
        'Accipitriformes' => 'Rovfåglar',
        'Falconiformes' => 'Rovfåglar',
        'Strigiformes' => 'Ugglor',
        'Passeriformes' => 'Tättingar',
        'Gruiformes' => 'Tran- & rallfåglar',
        'Podicipediformes' => 'Doppingar',
        'Piciformes' => 'Hackspettar',
        'Columbiformes' => 'Duvor',
        'Gaviiformes' => 'Lommar',
        'Galliformes' => 'Hönsfåglar',
        'Apodiformes' => 'Seglare',
        'Cuculiformes' => 'Gökar',
        'Suliformes' => 'Skarvar',
        'Ciconiiformes' => 'Storkar',
        'Caprimulgiformes' => 'Nattskärror',
        'Coraciiformes' => 'Kungsfiskare m.fl.',
    ];
    // Charadriiformes split by family: waders vs gulls/terns
    if ($order === 'Charadriiformes') {
        $waderFamilies = ['Scolopacidae','Charadriidae','Haematopodidae','Recurvirostridae','Burhinidae'];
        return in_array($family, $waderFamilies) ? 'Vadare' : 'Måsar & tärnor';
    }
    // Pelecaniformes: herons are the main group at Tåkern
    if ($order === 'Pelecaniformes') return 'Hägrar';
    return $map[$order] ?? 'Övriga';
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
        COUNT(DISTINCT SUBSTR(event_start_date,1,4)) years_present,
        taxonomic_order, family
        FROM observations WHERE vernacular_name IS NOT NULL
            AND scientific_name LIKE '% %'
            AND vernacular_name NOT LIKE '% x %'
            AND vernacular_name NOT LIKE '%/%'
            AND vernacular_name NOT LIKE '%, % morf'
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
            'bird_group' => getBirdGroup($row['taxonomic_order'] ?? '', $row['family'] ?? ''),
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
    $firstDates = []; // actual date strings keyed by doy
    $lastDates = [];  // actual date strings keyed by doy
    $res = $db->query("SELECT SUBSTR(event_start_date,1,4) y,
        MIN(event_start_date) first, MAX(event_start_date) last
        FROM observations WHERE taxon_id = $id GROUP BY y ORDER BY y");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $phenology[$row['y']] = ['first' => $row['first'], 'last' => $row['last']];
        $d = DateTime::createFromFormat('Y-m-d', $row['first']);
        if ($d) {
            $doy = intval($d->format('z')) + 1;
            $firstDays[] = $doy;
            // Track earliest actual date for this doy (chronologically first)
            if (!isset($firstDates[$doy]) || $row['first'] < $firstDates[$doy]) {
                $firstDates[$doy] = $row['first'];
            }
        }
        $d = DateTime::createFromFormat('Y-m-d', $row['last']);
        if ($d) {
            $doy = intval($d->format('z')) + 1;
            $lastDays[] = $doy;
            if (!isset($lastDates[$doy]) || $row['last'] > $lastDates[$doy]) {
                $lastDates[$doy] = $row['last'];
            }
        }
    }

    // Format earliest/latest from actual dates to avoid leap-year mismatch in doyToStr
    $months = ['jan','feb','mar','apr','maj','jun','jul','aug','sep','okt','nov','dec'];
    $fmtDate = function($dateStr) use ($months) {
        return intval(substr($dateStr, 8, 2)) . ' ' . $months[intval(substr($dateStr, 5, 2)) - 1];
    };
    $earliestFirstDate = !empty($firstDays) ? $firstDates[min($firstDays)] : null;
    $latestLastDate = !empty($lastDays) ? $lastDates[max($lastDays)] : null;

    $phenSummary = [
        'avg_first' => !empty($firstDays) ? doyToStr(intval(round(array_sum($firstDays)/count($firstDays)))) : null,
        'avg_last' => !empty($lastDays) ? doyToStr(intval(round(array_sum($lastDays)/count($lastDays)))) : null,
        'earliest_ever' => $earliestFirstDate ? $fmtDate($earliestFirstDate) : null,
        'latest_ever' => $latestLastDate ? $fmtDate($latestLastDate) : null,
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

    // Recent observations (last 20 unique date+locality combos)
    $recent = [];
    $res = $db->query("SELECT event_start_date, start_time, locality,
        individual_count, recorded_by, url, dataset_name
        FROM observations WHERE taxon_id = $id
        ORDER BY event_start_date DESC, (CASE WHEN url IS NOT NULL THEN 0 ELSE 1 END), start_time DESC LIMIT 80");
    $seen = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $key = $row['event_start_date'] . '|' . ($row['locality'] ?? '');
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        // Determine short source label
        $ds = $row['dataset_name'] ?? '';
        if (stripos($ds, 'Ring') !== false) $source = 'Ringmärkning';
        elseif (stripos($ds, 'Bird Survey') !== false || stripos($ds, 'Standardrutt') !== false) $source = 'Fågelinventering';
        elseif (stripos($ds, 'iNaturalist') !== false) $source = 'iNaturalist';
        elseif ($row['url'] && stripos($row['url'], 'artportalen') !== false) $source = 'Artportalen';
        elseif (stripos($ds, 'Artportalen') !== false) $source = 'Artportalen';
        else $source = $ds ?: ($row['url'] ? 'Artportalen' : 'Okänd');
        $recent[] = [
            'date' => $row['event_start_date'],
            'time' => $row['start_time'],
            'locality' => $row['locality'],
            'observer' => $row['recorded_by'],
            'url' => $row['url'],
            'source' => $source,
        ];
        if (count($recent) >= 20) break;
    }

    // ── Encounter rate (reporting frequency) per year ──
    // Total field visits per year (all species)
    $totalVisits = [];
    $tvRes = $db->query("
        SELECT strftime('%Y', event_start_date) AS yr,
               COUNT(DISTINCT event_start_date || '|' || locality) AS visits
        FROM observations
        WHERE event_start_date >= '2006-01-01'
        GROUP BY yr
    ");
    while ($r = $tvRes->fetchArray(SQLITE3_ASSOC)) {
        $totalVisits[$r['yr']] = intval($r['visits']);
    }

    // Species field visits per year
    // Handle taxonomic merges (sädgås = skogsgås + tundragås)
    $mergeGroups = [100009 => [232125, 205924]];
    $queryIds = [$id];
    foreach ($mergeGroups as $targetId => $sourceIds) {
        if ($id == $targetId) $queryIds = array_merge($queryIds, $sourceIds);
    }
    $idList = implode(',', $queryIds);
    $spVisits = [];
    $svRes = $db->query("
        SELECT strftime('%Y', event_start_date) AS yr,
               COUNT(DISTINCT event_start_date || '|' || locality) AS visits
        FROM observations
        WHERE taxon_id IN ($idList) AND event_start_date >= '2006-01-01'
        GROUP BY yr
    ");
    while ($r = $svRes->fetchArray(SQLITE3_ASSOC)) {
        $spVisits[$r['yr']] = intval($r['visits']);
    }

    // Compute rates and Theil-Sen trend
    $encounterRate = [];
    $erYears = array_keys($totalVisits);
    sort($erYears);
    $currentYear = date('Y');
    $trendPts = [];
    foreach ($erYears as $yr) {
        if ($yr == $currentYear) continue; // Exclude incomplete year
        $rate = ($totalVisits[$yr] > 0) ? ($spVisits[$yr] ?? 0) / $totalVisits[$yr] : 0;
        $encounterRate[$yr] = round($rate * 100, 2); // as percentage
        $trendPts[] = [intval($yr), $rate];
    }

    $erTrend = null;
    if (count($trendPts) >= 5) {
        // Theil-Sen: median of pairwise slopes
        $slopes = [];
        for ($i = 0; $i < count($trendPts); $i++) {
            for ($j = $i + 1; $j < count($trendPts); $j++) {
                $dx = $trendPts[$j][0] - $trendPts[$i][0];
                if ($dx != 0) $slopes[] = ($trendPts[$j][1] - $trendPts[$i][1]) / $dx;
            }
        }
        sort($slopes);
        $medianSlope = $slopes[intval(count($slopes) / 2)];

        // Intercept = median of (y - slope * x)
        $intercepts = array_map(fn($p) => $p[1] - $medianSlope * $p[0], $trendPts);
        sort($intercepts);
        $intercept = $intercepts[intval(count($intercepts) / 2)];

        // First and last fitted values (in percentage)
        $firstYr = $trendPts[0][0];
        $lastYr = $trendPts[count($trendPts) - 1][0];
        $erTrend = [
            'slope_per_decade' => round($medianSlope * 10 * 100, 2), // percentage points per decade
            'intercept' => $intercept,
            'first_year' => $firstYr,
            'last_year' => $lastYr,
        ];
    }

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
        'recent' => $recent,
        'encounter_rate' => $encounterRate,
        'encounter_rate_trend' => $erTrend,
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
    $oneYearAgo = date('Y-m-d', strtotime('-1 year'));
    $sql = "SELECT locality, ROUND(AVG(latitude),5) lat, ROUND(AVG(longitude),5) lng,
        COUNT(*) obs_count, COUNT(DISTINCT taxon_id) species_count,
        MAX(event_start_date) last_obs,
        SUM(CASE WHEN event_start_date >= '$oneYearAgo' THEN 1 ELSE 0 END) recent_obs
        FROM observations
        WHERE locality IS NOT NULL AND locality != '' AND latitude IS NOT NULL";
    if ($id) $sql .= " AND taxon_id = $id";
    $sql .= " GROUP BY locality HAVING obs_count >= 10 AND last_obs >= '$oneYearAgo' ORDER BY recent_obs DESC LIMIT 500";

    $res = $db->query($sql);
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $localities[] = [
            'name' => $row['locality'],
            'lat' => floatval($row['lat']),
            'lng' => floatval($row['lng']),
            'obs' => intval($row['obs_count']),
            'species' => intval($row['species_count']),
            'recent_obs' => intval($row['recent_obs']),
        ];
    }
    jsonOut(['localities' => $localities]);
}

// ── Locality detail ──
if ($q === 'locality' && isset($_GET['name'])) {
    $locName = $_GET['name'];

    // Basic info
    $stmt = $db->prepare("SELECT COUNT(*) obs_count, COUNT(DISTINCT taxon_id) species_count,
        COUNT(DISTINCT recorded_by) observer_count,
        MIN(event_start_date) first_obs, MAX(event_start_date) last_obs,
        ROUND(AVG(latitude),5) lat, ROUND(AVG(longitude),5) lng
        FROM observations WHERE locality = :name");
    $stmt->bindValue(':name', $locName, SQLITE3_TEXT);
    $info = $stmt->execute()->fetchArray(SQLITE3_ASSOC);

    if (!$info || intval($info['obs_count']) === 0) {
        jsonOut(['error' => 'Locality not found']);
    }

    // Top 10 species
    $topSpecies = [];
    $stmt = $db->prepare("SELECT taxon_id, vernacular_name, scientific_name, COUNT(*) n
        FROM observations WHERE locality = :name AND vernacular_name IS NOT NULL
        GROUP BY taxon_id ORDER BY n DESC LIMIT 10");
    $stmt->bindValue(':name', $locName, SQLITE3_TEXT);
    $res = $stmt->execute();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $topSpecies[] = ['taxon_id' => intval($row['taxon_id']), 'name' => $row['vernacular_name'],
            'scientific' => $row['scientific_name'], 'count' => intval($row['n'])];
    }

    // Season curve: average reports per week
    $stmt = $db->prepare("SELECT SUBSTR(event_start_date,1,4) y,
        CAST(STRFTIME('%W', event_start_date) AS INTEGER) w, COUNT(*) n
        FROM observations WHERE locality = :name GROUP BY y, w");
    $stmt->bindValue(':name', $locName, SQLITE3_TEXT);
    $res = $stmt->execute();
    $weekCounts = [];
    $locYears = [];
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $w = intval($row['w']);
        if (!isset($weekCounts[$w])) $weekCounts[$w] = [];
        $weekCounts[$w][] = intval($row['n']);
        $locYears[$row['y']] = true;
    }
    $numYears = count($locYears);
    $seasonCurve = [];
    for ($w = 0; $w < 53; $w++) {
        $vals = $weekCounts[$w] ?? [];
        $avg = $numYears > 0 ? array_sum($vals) / $numYears : 0;
        if ($avg > 0) $seasonCurve[$w] = round($avg, 1);
    }

    // Top 10 reporters
    $topReporters = [];
    $stmt = $db->prepare("SELECT recorded_by, COUNT(*) n FROM observations
        WHERE locality = :name AND recorded_by IS NOT NULL
        GROUP BY recorded_by ORDER BY n DESC LIMIT 10");
    $stmt->bindValue(':name', $locName, SQLITE3_TEXT);
    $res = $stmt->execute();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $topReporters[] = ['name' => $row['recorded_by'], 'count' => intval($row['n'])];
    }

    // Recent reports: last 20 unique date+species observations at this locality
    $recentReports = [];
    $seen = [];
    $stmt = $db->prepare("SELECT taxon_id, vernacular_name, scientific_name,
        event_start_date, start_time, individual_count, recorded_by, url, dataset_name
        FROM observations WHERE locality = :name AND vernacular_name IS NOT NULL
        ORDER BY event_start_date DESC, start_time DESC LIMIT 100");
    $stmt->bindValue(':name', $locName, SQLITE3_TEXT);
    $res = $stmt->execute();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $key = $row['event_start_date'] . '|' . $row['vernacular_name'];
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $recentReports[] = [
            'taxon_id' => intval($row['taxon_id']),
            'name' => $row['vernacular_name'],
            'scientific' => $row['scientific_name'],
            'date' => $row['event_start_date'],
            'time' => $row['start_time'],
            'count' => $row['individual_count'] ? intval($row['individual_count']) : null,
            'observer' => $row['recorded_by'],
            'url' => $row['url'],
        ];
        if (count($recentReports) >= 20) break;
    }

    jsonOut([
        'name' => $locName,
        'obs_count' => intval($info['obs_count']),
        'species_count' => intval($info['species_count']),
        'observer_count' => intval($info['observer_count']),
        'first_obs' => $info['first_obs'],
        'last_obs' => $info['last_obs'],
        'lat' => $info['lat'] ? floatval($info['lat']) : null,
        'lng' => $info['lng'] ? floatval($info['lng']) : null,
        'top_species' => $topSpecies,
        'recent_reports' => $recentReports,
        'season_curve' => $seasonCurve,
        'top_reporters' => $topReporters,
    ]);
}

// ── Week context (for weekly report) ──
if ($q === 'week_context') {
    $year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

    // First observation per species this year (with URL to first obs)
    $yearFirsts = [];
    $stmt = $db->prepare("SELECT f.taxon_id, f.vernacular_name, f.scientific_name,
        f.first_date, f.obs_count, o.url AS first_url
        FROM (
            SELECT taxon_id, vernacular_name, scientific_name,
                MIN(event_start_date) first_date, COUNT(*) obs_count
            FROM observations
            WHERE SUBSTR(event_start_date,1,4) = :year AND vernacular_name IS NOT NULL
            GROUP BY taxon_id
        ) f
        LEFT JOIN observations o ON o.taxon_id = f.taxon_id
            AND o.event_start_date = f.first_date
            AND SUBSTR(o.event_start_date,1,4) = :year2");
    $stmt->bindValue(':year', strval($year), SQLITE3_TEXT);
    $stmt->bindValue(':year2', strval($year), SQLITE3_TEXT);
    $res = $stmt->execute();
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $tid = strval($row['taxon_id']);
        if (isset($yearFirsts[$tid])) continue; // skip duplicates from JOIN
        $yearFirsts[$tid] = [
            'name' => $row['vernacular_name'],
            'scientific' => $row['scientific_name'],
            'first_date' => $row['first_date'],
            'obs_count' => intval($row['obs_count']),
            'first_url' => $row['first_url'],
        ];
    }

    // Historical phenology: average from last 5 years, earliest from ALL years
    $maxYear = $year - 1;
    $minYear5 = $year - 5;

    // 1) Last 5 years: for average first arrival
    $phenRecent = [];
    $res = $db->query("SELECT taxon_id, vernacular_name,
        SUBSTR(event_start_date,1,4) obs_year,
        CAST(STRFTIME('%j', MIN(event_start_date)) AS INTEGER) first_doy
        FROM observations
        WHERE event_start_date IS NOT NULL AND vernacular_name IS NOT NULL
            AND CAST(SUBSTR(event_start_date,1,4) AS INTEGER) >= $minYear5
            AND CAST(SUBSTR(event_start_date,1,4) AS INTEGER) <= $maxYear
            AND CAST(SUBSTR(event_start_date,6,2) AS INTEGER) >= 2
        GROUP BY taxon_id, SUBSTR(event_start_date,1,4)");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $tid = strval($row['taxon_id']);
        if (!isset($phenRecent[$tid])) $phenRecent[$tid] = ['name' => $row['vernacular_name'], 'years' => []];
        $phenRecent[$tid]['years'][$row['obs_year']] = intval($row['first_doy']);
    }

    // 2) All years: earliest observation by day-of-year (exclude January to avoid overwintering noise)
    // Subquery finds the min doy per species, outer query gets the actual earliest date
    // Uses MIN(event_start_date) to deterministically pick the chronologically first occurrence
    // and avoids doyToStr leap-year mismatch by storing the real date
    $phenAllTime = [];
    $res = $db->query("SELECT e.taxon_id, e.earliest_doy,
        MIN(o.event_start_date) AS earliest_actual_date
        FROM (
            SELECT taxon_id,
                MIN(CAST(STRFTIME('%j', event_start_date) AS INTEGER)) AS earliest_doy
            FROM observations
            WHERE event_start_date IS NOT NULL AND vernacular_name IS NOT NULL
                AND CAST(SUBSTR(event_start_date,6,2) AS INTEGER) >= 2
            GROUP BY taxon_id
        ) e
        JOIN observations o ON o.taxon_id = e.taxon_id
            AND CAST(STRFTIME('%j', o.event_start_date) AS INTEGER) = e.earliest_doy
            AND CAST(SUBSTR(o.event_start_date,6,2) AS INTEGER) >= 2
        GROUP BY e.taxon_id");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $tid = strval($row['taxon_id']);
        $phenAllTime[$tid] = [
            'earliest_doy' => intval($row['earliest_doy']),
            'earliest_actual_date' => $row['earliest_actual_date'],
        ];
    }

    $phenology = [];
    foreach ($phenRecent as $tid => $data) {
        if (count($data['years']) < 3) continue;
        $doys = array_values($data['years']);
        $avgDoy = intval(round(array_sum($doys) / count($doys)));

        // Earliest from all-time data (by day-of-year, not chronological)
        $earliestDoy = isset($phenAllTime[$tid]) ? $phenAllTime[$tid]['earliest_doy'] : min($doys);
        // Use actual date from DB to avoid leap-year mismatch in doyToStr
        $earliestActualDate = isset($phenAllTime[$tid]) ? $phenAllTime[$tid]['earliest_actual_date'] : null;
        if ($earliestActualDate) {
            $earliestYear = substr($earliestActualDate, 0, 4);
            $d = intval(substr($earliestActualDate, 8, 2));
            $m = intval(substr($earliestActualDate, 5, 2));
            $months = ['jan','feb','mar','apr','maj','jun','jul','aug','sep','okt','nov','dec'];
            $earliestDate = $d . ' ' . $months[$m - 1];
        } else {
            $earliestYear = null;
            $earliestDate = doyToStr($earliestDoy);
        }

        $phenology[$tid] = [
            'name' => $data['name'],
            'avg_first_doy' => $avgDoy,
            'avg_first_date' => doyToStr($avgDoy),
            'earliest_doy' => $earliestDoy,
            'earliest_date' => $earliestDate,
            'earliest_year' => $earliestYear,
            'years_seen' => count($data['years']),
            'avg_year_range' => $minYear5 . '–' . $maxYear,
        ];
    }

    // Rarity: average observations per year across all years
    // Exclude hybrids (x), uncertain IDs (/), morphs, and group-level names (no space in scientific name)
    $rarity = [];
    $res = $db->query("SELECT taxon_id,
        COUNT(*) AS total_obs,
        COUNT(DISTINCT SUBSTR(event_start_date,1,4)) AS years_present
        FROM observations
        WHERE vernacular_name IS NOT NULL AND event_start_date IS NOT NULL
            AND vernacular_name NOT LIKE '% x %'
            AND vernacular_name NOT LIKE '%/%'
            AND vernacular_name NOT LIKE '%, % morf'
            AND scientific_name LIKE '% %'
        GROUP BY taxon_id");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $tid = strval($row['taxon_id']);
        $yearsPresent = intval($row['years_present']);
        $totalObs = intval($row['total_obs']);
        $avgPerYear = $yearsPresent > 0 ? round($totalObs / $yearsPresent, 1) : 0;
        // Only include species that are genuinely rare (avg < 10 obs/year)
        if ($avgPerYear < 10) {
            $rarity[$tid] = [
                'avg_per_year' => $avgPerYear,
                'total_obs' => $totalObs,
                'years_present' => $yearsPresent,
            ];
        }
    }

    // Same period last year: compare same date range (e.g. Mar 9–15) one year back
    $lastYear = $year - 1;
    $daysBack = isset($_GET['days']) ? intval($_GET['days']) : 7;
    $today = new DateTime();
    $lyEnd = new DateTime();
    $lyEnd->modify('-1 year');
    $lyStart = clone $lyEnd;
    $lyStart->modify("-{$daysBack} days");
    $lyStartStr = $lyStart->format('Y-m-d');
    $lyEndStr = $lyEnd->format('Y-m-d');

    // All species observed during same period last year
    $res = $db->query("SELECT taxon_id, vernacular_name,
        COUNT(*) AS obs_count
        FROM observations
        WHERE vernacular_name IS NOT NULL
            AND event_start_date >= '$lyStartStr'
            AND event_start_date <= '$lyEndStr'
        GROUP BY taxon_id");
    $lySpecies = [];
    $lyObs = 0;
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $lySpecies[] = $row['vernacular_name'];
        $lyObs += intval($row['obs_count']);
    }

    // First-of-year count last year: species whose first obs last year fell in this date range
    $lyFirstOfYear = 0;
    $res = $db->query("SELECT COUNT(*) AS cnt FROM (
        SELECT taxon_id, MIN(event_start_date) AS first_date
        FROM observations
        WHERE SUBSTR(event_start_date,1,4) = '$lastYear' AND vernacular_name IS NOT NULL
        GROUP BY taxon_id
        HAVING first_date >= '$lyStartStr' AND first_date <= '$lyEndStr'
    )");
    $row = $res->fetchArray(SQLITE3_ASSOC);
    $lyFirstOfYear = intval($row['cnt']);

    $lastYearWeek = [
        'year' => $lastYear,
        'date_range' => $lyStartStr . ' – ' . $lyEndStr,
        'species_count' => count($lySpecies),
        'obs_count' => $lyObs,
        'species' => $lySpecies,
        'first_of_year' => $lyFirstOfYear,
    ];

    // Spring progress: how many of all spring migrants (Feb-Jun) have arrived? (week 8–22 only)
    $springProgress = null;
    $currentDoy = intval(date('z')) + 1; // current day of year
    $currentWeek = intval(date('W'));
    if ($currentWeek >= 8 && $currentWeek <= 22) {
        // Count all spring migrants (avg arrival Feb-Jun) and how many have been seen this year
        $totalMigrants = 0;
        $arrivedCount = 0;
        foreach ($phenology as $tid => $ph) {
            if ($ph['avg_first_doy'] < 32 || $ph['avg_first_doy'] > 180) continue;
            $totalMigrants++;
            if (isset($yearFirsts[$tid])) {
                $arrivedCount++;
            }
        }
        // Compare median arrival this year vs historical for species that have arrived
        // Filter: only species whose first obs is within ±30/+60 days of expected (skip winter residents)
        $thisYearDoys = [];
        foreach ($yearFirsts as $tid => $yf) {
            if (!isset($phenology[$tid])) continue;
            $avgDoy = $phenology[$tid]['avg_first_doy'];
            if ($avgDoy < 32 || $avgDoy > 180) continue;
            $obsDoy = intval(date('z', strtotime($yf['first_date']))) + 1;
            $diff = $obsDoy - $avgDoy;
            if ($diff < -30 || $diff > 60) continue;
            $thisYearDoys[] = $diff;
        }
        $medianDiff = null;
        if (count($thisYearDoys) > 5) {
            sort($thisYearDoys);
            $mid = intval(count($thisYearDoys) / 2);
            $medianDiff = $thisYearDoys[$mid];
        }

        $springProgress = [
            'total' => $totalMigrants,
            'arrived' => $arrivedCount,
            'pct' => $totalMigrants > 0 ? round(100 * $arrivedCount / $totalMigrants) : null,
            'median_diff_days' => $medianDiff, // negative = early, positive = late
        ];
    }

    // Bird group classification per taxon
    $birdGroups = [];
    $res = $db->query("SELECT DISTINCT taxon_id, taxonomic_order, family
        FROM observations
        WHERE vernacular_name IS NOT NULL AND taxonomic_order IS NOT NULL");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $birdGroups[strval($row['taxon_id'])] = getBirdGroup($row['taxonomic_order'], $row['family']);
    }

    jsonOut([
        'year' => $year,
        'year_firsts' => $yearFirsts,
        'phenology' => $phenology,
        'rarity' => $rarity,
        'last_year_week' => $lastYearWeek,
        'spring_progress' => $springProgress,
        'bird_groups' => $birdGroups,
    ]);
}

// ── Species accumulation: cumulative unique species per day ──
if ($q === 'accumulation') {
    $year = isset($_GET['year']) ? intval($_GET['year']) : intval(date('Y'));

    // Get first observation date per species for a single year
    function getFirstDates($db, $yr) {
        $stmt = $db->prepare("SELECT taxon_id, MIN(event_start_date) AS first_date
            FROM observations
            WHERE SUBSTR(event_start_date,1,4) = :year
              AND vernacular_name IS NOT NULL
            GROUP BY taxon_id
            ORDER BY first_date");
        $stmt->bindValue(':year', strval($yr), SQLITE3_TEXT);
        $res = $stmt->execute();
        $dates = [];
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $d = $row['first_date'];
            if (!isset($dates[$d])) $dates[$d] = 0;
            $dates[$d]++;
        }
        ksort($dates);
        $cumulative = [];
        $total = 0;
        foreach ($dates as $date => $count) {
            $total += $count;
            $cumulative[] = [$date, $total];
        }
        return $cumulative;
    }

    // 5-year average: accumulate per day-of-year, then average
    function getAvgAccumulation($db, $year, $numYears = 5) {
        $startYear = $year - $numYears;
        $endYear = $year - 1;

        // Get first DOY per species per year
        $stmt = $db->prepare("SELECT taxon_id, SUBSTR(event_start_date,1,4) AS yr,
            CAST(STRFTIME('%j', MIN(event_start_date)) AS INTEGER) AS first_doy
            FROM observations
            WHERE CAST(SUBSTR(event_start_date,1,4) AS INTEGER) >= :start
              AND CAST(SUBSTR(event_start_date,1,4) AS INTEGER) <= :end
              AND vernacular_name IS NOT NULL
            GROUP BY taxon_id, SUBSTR(event_start_date,1,4)");
        $stmt->bindValue(':start', $startYear, SQLITE3_INTEGER);
        $stmt->bindValue(':end', $endYear, SQLITE3_INTEGER);
        $res = $stmt->execute();

        // Group by year: doy => count of new species
        $yearData = []; // year => [doy => new_species_count]
        while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
            $yr = $row['yr'];
            $doy = intval($row['first_doy']);
            if (!isset($yearData[$yr])) $yearData[$yr] = [];
            if (!isset($yearData[$yr][$doy])) $yearData[$yr][$doy] = 0;
            $yearData[$yr][$doy]++;
        }

        if (empty($yearData)) return [];

        // Build cumulative curve per year, sampled at each DOY 1-366
        $yearCurves = [];
        foreach ($yearData as $yr => $doys) {
            ksort($doys);
            $curve = [];
            $total = 0;
            foreach ($doys as $doy => $count) {
                $total += $count;
                $curve[$doy] = $total;
            }
            $yearCurves[$yr] = $curve;
        }

        // Average across years at each DOY where at least one year has data
        $allDoys = [];
        foreach ($yearCurves as $curve) {
            foreach (array_keys($curve) as $doy) $allDoys[$doy] = true;
        }
        ksort($allDoys);

        $nYears = count($yearCurves);
        $result = [];
        foreach (array_keys($allDoys) as $doy) {
            $sum = 0;
            foreach ($yearCurves as $curve) {
                // Find the cumulative value at or before this DOY
                $val = 0;
                foreach ($curve as $d => $v) {
                    if ($d <= $doy) $val = $v;
                    else break;
                }
                $sum += $val;
            }
            $avg = round($sum / $nYears, 1);
            // Convert DOY to a date string (use current year for alignment)
            $dateStr = date('Y-m-d', mktime(0, 0, 0, 1, $doy, $year));
            $result[] = [$dateStr, $avg];
        }

        return $result;
    }

    // Daily mean temperature from SMHI (Härsnäs station 85180, 26km east of Tåkern)
    $tempData = [];
    $smhiUrl = 'https://opendata-download-metobs.smhi.se/api/version/1.0/parameter/2/station/85180/period/latest-months/data.json';
    $smhiCtx = stream_context_create(['http' => ['timeout' => 10]]);
    $smhiJson = @file_get_contents($smhiUrl, false, $smhiCtx);
    if ($smhiJson) {
        $smhi = json_decode($smhiJson, true);
        foreach ($smhi['value'] ?? [] as $v) {
            $date = $v['ref'] ?? '';
            if (substr($date, 0, 4) === strval($year)) {
                $tempData[] = [$date, floatval($v['value'])];
            }
        }
    }

    jsonOut([
        'this_year' => getFirstDates($db, $year),
        'avg_5yr' => getAvgAccumulation($db, $year, 5),
        'temperature' => $tempData,
        'temp_station' => 'Dygnsmedeltemperatur',
        'year' => $year,
        'avg_range' => ($year - 5) . '–' . ($year - 1),
    ]);
}

// ── Trends: encounter rate + Theil-Sen regression ──
if ($q === 'trends') {
    // 1. Total field visits (unique date+locality) per year
    $totalVisits = [];
    $res = $db->query("
        SELECT strftime('%Y', event_start_date) AS yr,
               COUNT(DISTINCT event_start_date || '|' || locality) AS visits
        FROM observations
        WHERE event_start_date >= '2006-01-01'
        GROUP BY yr
    ");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $totalVisits[$row['yr']] = intval($row['visits']);
    }

    // 2. Per-species visits per year (only species with scientific_name containing a space = real species)
    $speciesVisits = [];
    $speciesInfo = [];
    $res = $db->query("
        SELECT taxon_id, vernacular_name, scientific_name,
               taxonomic_order, family, redlist_category,
               strftime('%Y', event_start_date) AS yr,
               COUNT(DISTINCT event_start_date || '|' || locality) AS visits
        FROM observations
        WHERE event_start_date >= '2006-01-01'
          AND scientific_name LIKE '% %'
          AND scientific_name NOT LIKE '% x %'
          AND vernacular_name NOT LIKE '%/%'
          AND vernacular_name NOT LIKE '%morf%'
        GROUP BY taxon_id, yr
    ");
    while ($row = $res->fetchArray(SQLITE3_ASSOC)) {
        $tid = $row['taxon_id'];
        $yr = $row['yr'];
        if (!isset($speciesVisits[$tid])) $speciesVisits[$tid] = [];
        $speciesVisits[$tid][$yr] = intval($row['visits']);
        if (!isset($speciesInfo[$tid])) {
            $speciesInfo[$tid] = [
                'name' => $row['vernacular_name'],
                'scientific' => $row['scientific_name'],
                'group' => getBirdGroup($row['taxonomic_order'], $row['family']),
                'redlist' => $row['redlist_category'],
            ];
        }
    }

    // 2b. Merge taxonomic splits that inflate trends
    // Sädgås: skogsgås (232125) + tundragås (205924) → merge into sädgås (100009)
    $mergeGroups = [
        100009 => [232125, 205924],  // sädgås ← skogsgås, tundragås
    ];
    foreach ($mergeGroups as $targetId => $sourceIds) {
        if (!isset($speciesVisits[$targetId])) continue;
        // Merge visits using UNION of date|locality sets (need re-query for accurate dedup)
        // Approximation: use max(target, source) per year since most visits overlap
        // For accuracy, query the merged set directly
        $idList = implode(',', array_merge([$targetId], $sourceIds));
        $mergeRes = $db->query("
            SELECT strftime('%Y', event_start_date) AS yr,
                   COUNT(DISTINCT event_start_date || '|' || locality) AS visits
            FROM observations
            WHERE taxon_id IN ($idList) AND event_start_date >= '2006-01-01'
            GROUP BY yr
        ");
        $speciesVisits[$targetId] = [];
        while ($row = $mergeRes->fetchArray(SQLITE3_ASSOC)) {
            $speciesVisits[$targetId][$row['yr']] = intval($row['visits']);
        }
        // Remove source taxa from analysis
        foreach ($sourceIds as $sid) {
            unset($speciesVisits[$sid]);
            unset($speciesInfo[$sid]);
        }
    }

    // 3. Compute encounter rates and filter by 0.2% threshold in any 5-year period
    $years = array_keys($totalVisits);
    sort($years);
    $periods = [['2006','2010'], ['2011','2015'], ['2016','2020'], ['2021','2025']];

    $qualified = [];
    foreach ($speciesVisits as $tid => $yrVisits) {
        // Compute encounter rate per year
        $rates = [];
        foreach ($years as $yr) {
            $v = $yrVisits[$yr] ?? 0;
            $rates[$yr] = ($totalVisits[$yr] > 0) ? $v / $totalVisits[$yr] : 0;
        }

        // Check 0.2% threshold in any 5-year period
        $passes = false;
        foreach ($periods as $p) {
            $sum = 0; $cnt = 0;
            for ($y = intval($p[0]); $y <= intval($p[1]); $y++) {
                $yStr = strval($y);
                if (isset($rates[$yStr])) { $sum += $rates[$yStr]; $cnt++; }
            }
            if ($cnt > 0 && ($sum / $cnt) >= 0.002) { $passes = true; break; }
        }
        if ($passes) $qualified[$tid] = $rates;
    }

    // 4. Theil-Sen regression on encounter rates (exclude current incomplete year)
    $currentYear = date('Y');
    function theilSen($rates, $years) {
        global $currentYear;
        $pts = [];
        foreach ($years as $yr) {
            if ($yr == $currentYear) continue;  // Exclude incomplete year
            if (isset($rates[$yr])) $pts[] = [intval($yr), $rates[$yr]];
        }
        if (count($pts) < 5) return null;

        // All pairwise slopes
        $slopes = [];
        for ($i = 0; $i < count($pts); $i++) {
            for ($j = $i + 1; $j < count($pts); $j++) {
                $dx = $pts[$j][0] - $pts[$i][0];
                if ($dx != 0) $slopes[] = ($pts[$j][1] - $pts[$i][1]) / $dx;
            }
        }
        sort($slopes);
        $median = $slopes[intval(count($slopes) / 2)];

        // Intercept = median of (y - slope * x)
        $intercepts = array_map(fn($p) => $p[1] - $median * $p[0], $pts);
        sort($intercepts);
        $intercept = $intercepts[intval(count($intercepts) / 2)];

        // Mean encounter rate
        $meanRate = array_sum(array_column($pts, 1)) / count($pts);

        // R²
        $ssRes = 0; $ssTot = 0;
        foreach ($pts as $p) {
            $pred = $intercept + $median * $p[0];
            $ssRes += ($p[1] - $pred) ** 2;
            $ssTot += ($p[1] - $meanRate) ** 2;
        }
        $r2 = ($ssTot > 0) ? 1 - $ssRes / $ssTot : 0;

        // Relative change = slope / mean rate
        $relChange = ($meanRate > 0) ? $median / $meanRate : 0;

        // Mean first 5 years, mean last 5 years
        $firstYears = array_slice($pts, 0, 5);
        $lastYears = array_slice($pts, -5);
        $meanFirst = array_sum(array_column($firstYears, 1)) / count($firstYears);
        $meanLast = array_sum(array_column($lastYears, 1)) / count($lastYears);

        return [
            'slope' => $median,
            'r2' => $r2,
            'rel_change' => $relChange,
            'mean_rate' => $meanRate,
            'mean_first5' => $meanFirst,
            'mean_last5' => $meanLast,
        ];
    }

    $trends = [];
    foreach ($qualified as $tid => $rates) {
        $t = theilSen($rates, $years);
        if ($t === null) continue;
        $trends[$tid] = $t;
        $trends[$tid]['rates'] = $rates;
    }

    // 5. Sort by relative change, pick top 10 increasing + decreasing
    uasort($trends, fn($a, $b) => $b['rel_change'] <=> $a['rel_change']);
    $increasing = array_slice($trends, 0, 10, true);

    uasort($trends, fn($a, $b) => $a['rel_change'] <=> $b['rel_change']);
    $decreasing = array_slice($trends, 0, 10, true);

    // 6. Format output (exclude current incomplete year from sparklines)
    $completeYears = array_filter($years, fn($yr) => $yr != $currentYear);
    function formatTrendItem($tid, $t, $info, $years) {
        // Sparkline: yearly rates normalized to species' own min-max
        $vals = [];
        foreach ($years as $yr) $vals[] = $t['rates'][$yr] ?? 0;
        $mn = min($vals); $mx = max($vals);
        $range = $mx - $mn;
        $sparkline = array_map(fn($v) => $range > 0 ? round(($v - $mn) / $range, 3) : 0.5, $vals);

        return [
            'taxon_id' => intval($tid),
            'name' => $info['name'],
            'scientific' => $info['scientific'],
            'group' => $info['group'],
            'redlist' => $info['redlist'],
            'slope' => round($t['slope'] * 1000, 3),  // per mille per year
            'r2' => round($t['r2'], 3),
            'rel_change_pct' => round($t['rel_change'] * 100, 1),  // % per year
            'mean_first5_pct' => round($t['mean_first5'] * 100, 2),
            'mean_last5_pct' => round($t['mean_last5'] * 100, 2),
            'sparkline' => $sparkline,
        ];
    }

    $result = [
        'years' => array_values($completeYears),
        'increasing' => [],
        'decreasing' => [],
    ];
    foreach ($increasing as $tid => $t) {
        $result['increasing'][] = formatTrendItem($tid, $t, $speciesInfo[$tid], $completeYears);
    }
    foreach ($decreasing as $tid => $t) {
        $result['decreasing'][] = formatTrendItem($tid, $t, $speciesInfo[$tid], $completeYears);
    }

    jsonOut($result);
}

// ── Batch endpoint for statistik.html initial load ──
// Reads from cache files directly – avoids separate HTTP requests
if ($q === 'init') {
    $parts = ['overview', 'geo', 'localities', 'species', 'trends'];
    $result = [];
    $allCached = true;
    foreach ($parts as $part) {
        $partCache = "$CACHE_DIR/$part.json";
        if (file_exists($partCache)) {
            $result[$part] = json_decode(file_get_contents($partCache), true);
        } else {
            $allCached = false;
        }
    }
    if ($allCached) {
        jsonOut($result);
    }
    // If not all cached, fall through – client will use individual endpoints as fallback
}

// ── Unknown endpoint ──
echo json_encode(['error' => 'Unknown query. Use ?q=overview, ?q=species, ?q=species&id=X, ?q=geo, ?q=localities, ?q=locality&name=X, ?q=week_context, ?q=accumulation, or ?q=init']);

} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage(), 'line' => $e->getLine()]);
}
