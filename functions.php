<?php
declare(strict_types=1);

/**
 * Lädt alle Slug-Einträge aus DATA_FILE.
 * Nutzt APCu als In-Memory-Cache (60 s TTL), falls verfügbar.
 * Gibt das rohe Array zurück — Werte können string (Legacy) oder array sein.
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

/** Gibt die Ziel-URL eines Eintrags zurück (unterstützt Legacy-String-Format). */
function entryUrl(mixed $entry): string
{
    return is_array($entry) ? (string)($entry['url'] ?? '') : (string)$entry;
}

/** Gibt den Klick-Zähler eines Eintrags zurück. */
function entryHits(mixed $entry): int
{
    return is_array($entry) ? (int)($entry['hits'] ?? 0) : 0;
}

/** Gibt das Erstelldatum eines Eintrags zurück. */
function entryCreated(mixed $entry): string
{
    return is_array($entry) ? (string)($entry['created'] ?? '—') : '—';
}

/**
 * Schreibt einen neuen Slug atomisch in DATA_FILE.
 * Kollisionsprüfung innerhalb des flock-Locks.
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

    $stored[$slug] = ['url' => $url, 'hits' => 0, 'created' => date('Y-m-d')];

    ftruncate($fp, 0);
    fseek($fp, 0);
    $written = fwrite($fp, json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if ($written === false) {
        return 'Schreibfehler.';
    }

    if (function_exists('apcu_delete')) {
        apcu_delete('shortener_urls');
    }

    return null;
}

/**
 * Löscht einen Slug aus DATA_FILE.
 * Rückgabe: null = Erfolg | 'SLUG_NOT_FOUND' | Fehlermeldung
 */
function deleteSlug(string $slug): ?string
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
        flock($fp, LOCK_UN);
        fclose($fp);
        return 'Datenspeicher beschädigt.';
    }

    if (!isset($stored[$slug])) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return 'SLUG_NOT_FOUND';
    }

    unset($stored[$slug]);

    ftruncate($fp, 0);
    fseek($fp, 0);
    $written = fwrite($fp, json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if (function_exists('apcu_delete')) {
        apcu_delete('shortener_urls');
    }

    return $written === false ? 'Schreibfehler.' : null;
}

/**
 * Erhöht den Klick-Zähler für $slug um 1.
 * Fehler werden still ignoriert — ein fehlgeschlagener Counter darf keinen Redirect blockieren.
 */
function incrementHits(string $slug): void
{
    $fp = fopen(DATA_FILE, 'c+');
    if ($fp === false) {
        return;
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return;
    }

    fseek($fp, 0);
    $stored = json_decode(stream_get_contents($fp), true);

    if (!is_array($stored) || !isset($stored[$slug])) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return;
    }

    // Legacy-String-Format auf neues Format migrieren
    if (!is_array($stored[$slug])) {
        $stored[$slug] = ['url' => $stored[$slug], 'hits' => 0, 'created' => '—'];
    }

    $stored[$slug]['hits'] = ($stored[$slug]['hits'] ?? 0) + 1;

    ftruncate($fp, 0);
    fseek($fp, 0);
    fwrite($fp, json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    if (function_exists('apcu_delete')) {
        apcu_delete('shortener_urls');
    }
}

/**
 * Prüft, ob die aktuelle IP das Rate Limit überschreitet.
 * Nutzt APCu (pro IP) oder Session (Fallback) als Zähler-Speicher.
 * Gibt false zurück, wenn das Limit erreicht ist.
 */
function checkRateLimit(): bool
{
    if (RATE_LIMIT_MAX <= 0) {
        return true;
    }

    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'rl_' . md5($ip);

    if (function_exists('apcu_fetch')) {
        $count = apcu_fetch($key, $exists);
        if (!$exists) {
            apcu_store($key, 1, 60);
            return true;
        }
        if ((int)$count >= RATE_LIMIT_MAX) {
            return false;
        }
        apcu_inc($key);
        return true;
    }

    // Session-Fallback (per Session, nicht per IP)
    $now = time();
    if (!isset($_SESSION['rl_ts']) || ($now - (int)$_SESSION['rl_ts']) > 60) {
        $_SESSION['rl_ts']    = $now;
        $_SESSION['rl_count'] = 1;
        return true;
    }
    if ((int)$_SESSION['rl_count'] >= RATE_LIMIT_MAX) {
        return false;
    }
    $_SESSION['rl_count']++;
    return true;
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
