<?php
/**
 * api/upload.php?brand=mk
 * POST multipart/form-data, field "slides" (one or more image files)
 *
 * Logic:
 *  1. Scan slides/:brand/ → find highest existing number N
 *  2. Sort uploaded files by their original slide number
 *  3. Check for conflicts (SlideN+1.jpg already exists?)
 *  4. Conflicts → 409 with list
 *  5. OK → resize to max 1920px wide, JPEG quality 92, save as SlideN+1.jpg …
 *  6. Return { ok, saved, startedAt, count }
 */

header('Content-Type: application/json');

// ── Sanitise brand ────────────────────────────────────────────────────────────
$brand = isset($_GET['brand']) ? preg_replace('/[^a-zA-Z0-9_-]/', '', $_GET['brand']) : '';
if (!$brand) {
    http_response_code(400);
    echo json_encode(['error' => 'brand parameter required']);
    exit;
}

$slidesDir = __DIR__ . '/../slides/' . $brand;

// ── Check GD is available ─────────────────────────────────────────────────────
if (!function_exists('imagecreatefromstring')) {
    http_response_code(500);
    echo json_encode(['error' => 'PHP GD library not available on this server']);
    exit;
}

// ── Ensure folder exists ──────────────────────────────────────────────────────
if (!is_dir($slidesDir)) {
    mkdir($slidesDir, 0755, true);
}

// ── Find highest existing slide number ────────────────────────────────────────
$existing = array_filter(scandir($slidesDir), function($f) {
    return preg_match('/\.(jpg|jpeg|png)$/i', $f);
});
$numbers = array_map(function($f) {
    preg_match('/(\d+)/', $f, $m);
    return isset($m[1]) ? (int)$m[1] : 0;
}, $existing);
$numbers = array_filter($numbers, function($n) { return $n > 0; });
$nextNum = count($numbers) ? max($numbers) + 1 : 1;

// ── Normalise $_FILES['slides'] into a flat array ─────────────────────────────
if (empty($_FILES['slides'])) {
    http_response_code(400);
    echo json_encode(['error' => 'No files received']);
    exit;
}

$files = [];
if (is_array($_FILES['slides']['name'])) {
    for ($i = 0; $i < count($_FILES['slides']['name']); $i++) {
        if ($_FILES['slides']['error'][$i] !== UPLOAD_ERR_OK) continue;
        $files[] = [
            'name'     => $_FILES['slides']['name'][$i],
            'tmp_name' => $_FILES['slides']['tmp_name'][$i],
        ];
    }
} else {
    if ($_FILES['slides']['error'] === UPLOAD_ERR_OK) {
        $files[] = [
            'name'     => $_FILES['slides']['name'],
            'tmp_name' => $_FILES['slides']['tmp_name'],
        ];
    }
}

if (empty($files)) {
    http_response_code(400);
    echo json_encode(['error' => 'No valid files received']);
    exit;
}

// ── Sort uploads by their original slide number ───────────────────────────────
usort($files, function($a, $b) {
    preg_match('/(\d+)/', $a['name'], $ma);
    preg_match('/(\d+)/', $b['name'], $mb);
    return (isset($ma[1]) ? (int)$ma[1] : 0) - (isset($mb[1]) ? (int)$mb[1] : 0);
});

// ── Build renaming plan + check conflicts ─────────────────────────────────────
$plan      = [];
$conflicts = [];

foreach ($files as $i => $file) {
    $newName  = 'Slide' . ($nextNum + $i) . '.jpg';
    $fullPath = $slidesDir . '/' . $newName;
    $conflict = file_exists($fullPath);
    $plan[]   = [
        'originalName' => $file['name'],
        'newName'      => $newName,
        'relativePath' => "slides/$brand/$newName",
        'conflict'     => $conflict,
    ];
    if ($conflict) $conflicts[] = $newName;
}

if (!empty($conflicts)) {
    http_response_code(409);
    echo json_encode([
        'error'     => 'Filename conflicts detected — files NOT saved.',
        'conflicts' => $conflicts,
        'plan'      => $plan,
    ]);
    exit;
}

// ── Compress + save each file ─────────────────────────────────────────────────
$saved = [];

foreach ($files as $i => $file) {
    $fullPath = $slidesDir . '/' . $plan[$i]['newName'];

    // Load image into GD
    $src = @imagecreatefromstring(file_get_contents($file['tmp_name']));
    if (!$src) {
        http_response_code(500);
        echo json_encode(['error' => 'Could not read image: ' . $file['name']]);
        exit;
    }

    $origW = imagesx($src);
    $origH = imagesy($src);

    // Resize to max 1920px wide (no enlargement)
    if ($origW > 1920) {
        $newW = 1920;
        $newH = (int)round($origH * 1920 / $origW);
    } else {
        $newW = $origW;
        $newH = $origH;
    }

    $dst = imagecreatetruecolor($newW, $newH);

    // Preserve transparency for PNG sources
    imagealphablending($dst, false);
    imagesavealpha($dst, true);
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $white);

    imagecopyresampled($dst, $src, 0, 0, 0, 0, $newW, $newH, $origW, $origH);
    imagejpeg($dst, $fullPath, 92);

    imagedestroy($src);
    imagedestroy($dst);

    $saved[] = [
        'originalName' => $file['name'],
        'newName'      => $plan[$i]['newName'],
        'relativePath' => $plan[$i]['relativePath'],
    ];
}

echo json_encode([
    'ok'        => true,
    'saved'     => $saved,
    'startedAt' => $nextNum,
    'count'     => count($saved),
]);
