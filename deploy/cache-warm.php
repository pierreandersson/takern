<?php
// CLI-only helper: warms a stats-api.php cache endpoint without using HTTP.
// Usage: php cache-warm.php "overview"  or  php cache-warm.php "accumulation&year=2026"
if (php_sapi_name() !== 'cli') { http_response_code(403); exit; }
$ep = $argv[1] ?? '';
if ($ep === '') exit(1);
parse_str("q=$ep", $_GET);
ob_start();
include __DIR__ . '/stats-api.php';
