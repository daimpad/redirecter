<?php
declare(strict_types=1);

const DATA_FILE = __DIR__ . '/data.json';

$slug = isset($_GET['s']) ? trim($_GET['s']) : '';

// Slug auf erlaubte Zeichen beschränken
if ($slug === '' || !preg_match('/^[A-Za-z0-9_-]+$/', $slug)) {
    http_response_code(400);
    exit('Ungültiger oder fehlender Slug.');
}

if (!is_file(DATA_FILE) || !is_readable(DATA_FILE)) {
    http_response_code(500);
    exit('Datenspeicher nicht verfügbar.');
}

$json = file_get_contents(DATA_FILE);
$data = json_decode($json, true);

if (!is_array($data)) {
    http_response_code(500);
    exit('Datenspeicher beschädigt.');
}

if (!isset($data[$slug])) {
    http_response_code(404);
    exit('Kurzlink nicht gefunden.');
}

$target = $data[$slug];

// Sicherstellen, dass das Ziel ein gültiges HTTP/HTTPS-URL ist
if (!filter_var($target, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $target)) {
    http_response_code(500);
    exit('Ungültiges Ziel-URL.');
}

header('Location: ' . $target, true, 301);
exit();
