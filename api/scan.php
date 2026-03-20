<?php
/**
 * api/scan.php?brand=mk
 * Returns the highest existing slide number + file list for slides/:brand/
 * e.g. { "highest": 116, "files": ["Slide1.jpg", ...] }
 */

header('Content-Type: application/json');

// Sanitise brand — letters, numbers, dash, underscore only
$brand = isset($_GET['brand']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['brand']) : '';

if (!$brand) {
    http_response_code(400);
    echo json_encode(['error' => 'brand parameter required']);
    exit;
}

$slidesDir = __DIR__ . '/../slides/' . $brand;

if (!is_dir($slidesDir)) {
    echo json_encode(['highest' => 0, 'files' => []]);
    exit;
}

// List image files
$all   = scandir($slidesDir);
$files = array_values(array_filter($all, function($f) {
    return preg_match('/\.(jpg|jpeg|png)$/i', $f);
}));

// Natural sort (Slide2 before Slide10)
natsort($files);
$files = array_values($files);

// Extract numbers
$numbers = array_map(function($f) {
    preg_match('/(\d+)/', $f, $m);
    return isset($m[1]) ? (int)$m[1] : 0;
}, $files);
$numbers  = array_filter($numbers, function($n) { return $n > 0; });
$highest  = count($numbers) ? max($numbers) : 0;

echo json_encode(['highest' => $highest, 'files' => $files]);
