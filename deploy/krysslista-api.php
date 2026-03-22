<?php
header('Content-Type: application/json; charset=utf-8');

$dataFile = __DIR__ . '/krysslista-data.json';
$seedFile = __DIR__ . '/krysslista-seed.json';

// Initialize data file from seed if it doesn't exist
if (!file_exists($dataFile)) {
    if (file_exists($seedFile)) {
        copy($seedFile, $dataFile);
    } else {
        file_put_contents($dataFile, json_encode(['observations' => [], 'nextId' => 1], JSON_UNESCAPED_UNICODE));
    }
}

function readData() {
    global $dataFile;
    $json = file_get_contents($dataFile);
    return json_decode($json, true) ?: ['observations' => [], 'nextId' => 1];
}

function writeData($data) {
    global $dataFile;
    $fp = fopen($dataFile, 'w');
    if (flock($fp, LOCK_EX)) {
        fwrite($fp, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        flock($fp, LOCK_UN);
    }
    fclose($fp);
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET' && $action === 'list') {
    echo file_get_contents($dataFile);
    exit;
}

if ($method === 'POST') {
    $body = json_decode(file_get_contents('php://input'), true);
    if (!$body) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }

    $data = readData();

    if ($action === 'add') {
        $obs = [
            'id' => $data['nextId'],
            'species' => trim($body['species'] ?? ''),
            'group' => trim($body['group'] ?? ''),
            'date' => trim($body['date'] ?? ''),
            'place' => trim($body['place'] ?? ''),
            'country' => trim($body['country'] ?? 'SE'),
            'notes' => trim($body['notes'] ?? '')
        ];
        if (!$obs['species']) {
            http_response_code(400);
            echo json_encode(['error' => 'Species required']);
            exit;
        }
        $data['observations'][] = $obs;
        $data['nextId']++;
        writeData($data);
        echo json_encode(['ok' => true, 'observation' => $obs]);
        exit;
    }

    if ($action === 'edit') {
        $id = intval($body['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID required']);
            exit;
        }
        $found = false;
        foreach ($data['observations'] as &$obs) {
            if ($obs['id'] === $id) {
                if (isset($body['species'])) $obs['species'] = trim($body['species']);
                if (isset($body['group'])) $obs['group'] = trim($body['group']);
                if (isset($body['date'])) $obs['date'] = trim($body['date']);
                if (isset($body['place'])) $obs['place'] = trim($body['place']);
                if (isset($body['country'])) $obs['country'] = trim($body['country']);
                if (isset($body['notes'])) $obs['notes'] = trim($body['notes']);
                $found = true;
                writeData($data);
                echo json_encode(['ok' => true, 'observation' => $obs]);
                exit;
            }
        }
        http_response_code(404);
        echo json_encode(['error' => 'Not found']);
        exit;
    }

    if ($action === 'delete') {
        $id = intval($body['id'] ?? 0);
        if (!$id) {
            http_response_code(400);
            echo json_encode(['error' => 'ID required']);
            exit;
        }
        $before = count($data['observations']);
        $data['observations'] = array_values(array_filter($data['observations'], function($o) use ($id) {
            return $o['id'] !== $id;
        }));
        if (count($data['observations']) === $before) {
            http_response_code(404);
            echo json_encode(['error' => 'Not found']);
            exit;
        }
        writeData($data);
        echo json_encode(['ok' => true]);
        exit;
    }
}

http_response_code(400);
echo json_encode(['error' => 'Invalid action']);
