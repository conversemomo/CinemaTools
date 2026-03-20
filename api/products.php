<?php
/**
 * api/products.php
 * GET  → read  data/products.json
 * POST → write data/products.json (atomic via temp file)
 */

header('Content-Type: application/json');

$filePath = __DIR__ . '/../data/products.json';

// ── GET ───────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (file_exists($filePath)) {
        echo file_get_contents($filePath);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'products.json not found']);
    }
    exit;
}

// ── POST ──────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $body = file_get_contents('php://input');
    $data = json_decode($body);

    if ($data === null) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid JSON']);
        exit;
    }

    $tmpPath = $filePath . '.tmp';

    if (file_put_contents($tmpPath, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) === false) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not write file — check folder permissions']);
        exit;
    }

    rename($tmpPath, $filePath);
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(405);
echo json_encode(['error' => 'Method not allowed']);
