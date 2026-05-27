<?php
declare(strict_types=1);

/**
 * Atomically writes $stored to DATA_FILE via a temp-file rename.
 * Readers always see either the old or the new complete file — never a partial write.
 * Clears APCu cache on success.
 */
function atomicWriteData(array $stored): bool
{
    $json = json_encode($stored, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $tmp  = DATA_FILE . '.tmp.' . getmypid();
    if (file_put_contents($tmp, $json) === false) {
        return false;
    }
    if (!rename($tmp, DATA_FILE)) {
        @unlink($tmp);
        return false;
    }
    if (function_exists('apcu_delete')) {
        apcu_delete('shortener_urls');
    }
    return true;
}

/**
 * Lädt alle Slug-Einträge aus DATA_FILE.
 * Nutzt APCu als In-Memory-Cache (60 s TTL), falls verfügbar.
 * atomicWriteData() stellt sicher, dass DATA_FILE immer vollständig ist —
 * kein LOCK_SH nötig.
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
    $data = json_decode($raw !== false ? $raw : '', true);
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
 * Serialisierung über .lock-Datei (stabiles Inode, wird nie umbenannt).
 * Kollisionsprüfung innerhalb des Locks.
 * Rückgabe: null = Erfolg | 'SLUG_EXISTS' | Fehlermeldung
 */
function saveData(string $slug, string $url): ?string
{
    $fp = fopen(DATA_FILE . '.lock', 'c');
    if ($fp === false) {
        return 'Lock-Datei konnte nicht geöffnet werden.';
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return 'Datei ist gesperrt – bitte erneut versuchen.';
    }

    $raw    = is_file(DATA_FILE) ? file_get_contents(DATA_FILE) : '';
    $stored = json_decode($raw !== false ? $raw : '', true);
    if (!is_array($stored)) {
        $stored = [];
    }

    if (isset($stored[$slug])) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return 'SLUG_EXISTS';
    }

    $stored[$slug] = ['url' => $url, 'hits' => 0, 'created' => date('Y-m-d')];
    $ok = atomicWriteData($stored);

    flock($fp, LOCK_UN);
    fclose($fp);

    return $ok ? null : 'Schreibfehler.';
}

/**
 * Löscht einen Slug aus DATA_FILE.
 * Rückgabe: null = Erfolg | 'SLUG_NOT_FOUND' | Fehlermeldung
 */
function deleteSlug(string $slug): ?string
{
    $fp = fopen(DATA_FILE . '.lock', 'c');
    if ($fp === false) {
        return 'Lock-Datei konnte nicht geöffnet werden.';
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return 'Datei ist gesperrt – bitte erneut versuchen.';
    }

    $raw    = is_file(DATA_FILE) ? file_get_contents(DATA_FILE) : '';
    $stored = json_decode($raw !== false ? $raw : '', true);
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
    $ok = atomicWriteData($stored);

    flock($fp, LOCK_UN);
    fclose($fp);

    return $ok ? null : 'Schreibfehler.';
}

/**
 * Erhöht den Klick-Zähler für $slug um 1.
 * Fehler werden still ignoriert — ein fehlgeschlagener Counter darf keinen Redirect blockieren.
 */
function incrementHits(string $slug): void
{
    $fp = fopen(DATA_FILE . '.lock', 'c');
    if ($fp === false) {
        return;
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return;
    }

    $raw    = is_file(DATA_FILE) ? file_get_contents(DATA_FILE) : '';
    $stored = json_decode($raw !== false ? $raw : '', true);

    if (!is_array($stored) || !isset($stored[$slug])) {
        flock($fp, LOCK_UN);
        fclose($fp);
        return;
    }

    if (!is_array($stored[$slug])) {
        $stored[$slug] = ['url' => $stored[$slug], 'hits' => 0, 'created' => '—'];
    }

    $stored[$slug]['hits'] = ($stored[$slug]['hits'] ?? 0) + 1;

    atomicWriteData($stored);

    flock($fp, LOCK_UN);
    fclose($fp);
}

/**
 * Prüft, ob die aktuelle IP das Rate Limit überschreitet.
 * APCu: atomisches apcu_add + apcu_inc verhindert Race Condition.
 * Session: Fallback für Server ohne APCu.
 * Gibt false zurück, wenn das Limit erreicht ist.
 */
function checkRateLimit(): bool
{
    if (RATE_LIMIT_MAX <= 0) {
        return true;
    }

    $ip  = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
    $key = 'rl_' . md5($ip);

    if (function_exists('apcu_add')) {
        apcu_add($key, 0, 60);          // Erstellt Key mit TTL, falls noch nicht vorhanden
        $count = apcu_inc($key);        // Atomisches Increment
        return $count !== false && (int)$count <= RATE_LIMIT_MAX;
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
 * Erzeugt einen zufälligen alphanumerischen Slug (Base62, ~36 Bit Entropie bei 6 Zeichen).
 */
function generateSlug(): string
{
    $chars  = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789';
    $bytes  = random_bytes(RANDOM_SLUG_LEN);
    $result = '';
    for ($i = 0; $i < RANDOM_SLUG_LEN; $i++) {
        $result .= $chars[ord($bytes[$i]) % 62];
    }
    return $result;
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

    // Beide Prüfungen immer ausführen um Timing-Side-Channel zu verhindern
    $validUser = hash_equals(ADMIN_USER, $user);
    $validPass = password_verify($pass, ADMIN_PASSWORD_HASH);

    if (!$validUser || !$validPass) {
        header('WWW-Authenticate: Basic realm="URL Shortener Admin"');
        http_response_code(401);
        exit('Zugriff verweigert.');
    }
}
