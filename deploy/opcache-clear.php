<?php
// Temporary script to clear PHP opcache
if (function_exists('opcache_reset')) {
    opcache_reset();
    echo json_encode(['ok' => true, 'message' => 'opcache cleared']);
} else {
    echo json_encode(['ok' => true, 'message' => 'opcache not enabled']);
}
