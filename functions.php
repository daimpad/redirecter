<?php
declare(strict_types=1);

/**
 * Lädt alle Slug-URL-Paare.
 * Nutzt APCu als In-Memory-Cache (60 s TTL), falls verfügbar.
 */
function loadData(): array
{
    if (function_exists('apcu_fetch')) {
        $data = apcu_fetch('shortener_urls', $hit);
        if ($hit) {
            return $data;
        }
    }

    if (!is_file(DATA_FILE)) {
        return [];
    }

    $raw  = file_get_contents(DATA_FILE);
    $data = json_decode($raw, true);
    $data = is_array($data) ? $data : [];

    if (function_exists('apcu_store')) {
        apcu_store('shortener_urls', $data, 60);
    }

    return $data;
}

/**
 * Schreibt einen neuen Slug atomisch in DATA_FILE.
 * Kollisionsprüfung erfolgt innerhalb des flock-Locks.
 * Rückgabe: null = Erfolg | 'SLUG_EXISTS' | Fehlermeldung
 */
function saveData(string $slug, string $url): ?string
{
    $fp = fopen(DATA_FILE, 'c+');
    if ($fp === false) {
        return 'Datei konnte nicht geöffnet werden.';
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return 'Datei ist gesperrt – bitte erneut versuchen.';
    }

    fseek($fp, 0);
    $stored = json_decode(stream_get_contents($fp), true);
    if (!is_array($stored)) {
        $stored = [];
    }

    if (isset($stored[$slug])) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return 'SLUG_EXISTS';
    }

    $stored[$slug] = $url;

    ftruncate($fp, 0);
    fseek($fp, 0);
    $written = fwrite($fp, json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($written === false) {
        return 'Schreibfehler.';
    }

    // APCu-Cache nach Schreibvorgang invalidieren
    if (function_exists('apcu_delete')) {
        apcu_delete('shortener_urls');
    }

    return null;
}

/**
 * Erzeugt einen zufälligen Hex-Slug.
 */
function generateSlug(): string
{
    return substr(bin2hex(random_bytes(RANDOM_SLUG_LEN)), 0, RANDOM_SLUG_LEN);
}

/**
 * Erzwingt HTTP Basic Auth anhand der Werte aus config.php.
 * Ist ADMIN_PASSWORD_HASH leer, wird Auth übersprungen.
 */
function requireAuth(): void
{
    if (ADMIN_PASSWORD_HASH === '') {
        return;
    }

    $user = $_SERVER['PHP_AUTH_USER'] ?? '';
    $pass = $_SERVER['PHP_AUTH_PW']   ?? '';

    if (!hash_equals(ADMIN_USER, $user) || !password_verify($pass, ADMIN_PASSWORD_HASH)) {
        header('WWW-Authenticate: Basic realm="URL Shortener Admin"');
        http_response_code(401);
        exit('Zugriff verweigert.');
    }
}
