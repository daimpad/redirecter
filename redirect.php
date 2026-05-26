<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

$slug = isset($_GET['s']) ? trim($_GET['s']) : '';

if ($slug === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
    http_response_code(400);
    exit('Ungültiger oder fehlender Slug.');
}

$data   = loadData();
$target = $data[$slug] ?? null;

if ($target === null) {
    http_response_code(404);
    exit('Kurzlink nicht gefunden.');
}

if (!filter_var($target, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $target)) {
    http_response_code(500);
    exit('Ungültiges Ziel-URL.');
}

header('Location: ' . $target, true, 301);
exit();
