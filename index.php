<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

requireAuth();

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'samesite' => 'Lax',
        'secure'   => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    ]);
    session_start();
}

// CSRF-Token vor der POST-Behandlung anlegen, sonst würde hash_equals('','') greifen
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Ziel für den Redirect nach dem POST. Bewusst aus SCRIPT_NAME statt REQUEST_URI:
// REQUEST_URI ist clientkontrolliert und könnte als "//fremde-domain" ankommen.
$selfPath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['error' => '', 'success' => '', 'url' => '', 'slug' => ''];

    $token = $_POST['csrf_token'] ?? '';
    if ($token === '' || !hash_equals($_SESSION['csrf_token'], $token)) {
        $flash['error'] = 'Die Sitzung ist abgelaufen. Bitte erneut absenden.';
    } else {
        $rawUrl  = trim($_POST['url']  ?? '');
        $rawSlug = trim($_POST['slug'] ?? '');

        $flash['url']  = $rawUrl;
        $flash['slug'] = $rawSlug;

        if ($rawUrl === '') {
            $flash['error'] = 'Bitte eine Ziel-URL angeben.';
        } elseif (!filter_var($rawUrl, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $rawUrl)) {
            $flash['error'] = 'Bitte eine gültige HTTP/HTTPS-URL eingeben.';
        } elseif (!checkRateLimit()) {
            // Erst hier prüfen: ein Tippfehler soll kein Kontingent verbrauchen.
            $flash['error'] = 'Zu viele Anfragen. Bitte warte eine Minute.';
        } else {
            $autoSlug = ($rawSlug === '');

            if ($autoSlug) {
                $slug     = generateSlug();
                $existing = loadData();
                $attempts = 0;
                while (isset($existing[$slug]) && $attempts++ < 10) {
                    $slug = generateSlug();
                }
            } else {
                $slug = preg_replace('/[^A-Za-z0-9_-]/', '', $rawSlug);
                $slug = substr($slug, 0, SLUG_MAX_LEN);
            }

            if ($slug === '') {
                $flash['error'] = 'Der Slug enthält keine gültigen Zeichen.';
            } else {
                $writeError = saveData($slug, $rawUrl);

                // Automatischer Slug hat ein Rennen verloren — still neu würfeln
                $retries = 0;
                while ($autoSlug && $writeError === 'SLUG_EXISTS' && $retries++ < 3) {
                    $slug       = generateSlug();
                    $writeError = saveData($slug, $rawUrl);
                }

                if ($writeError === 'SLUG_EXISTS') {
                    $flash['error'] = 'Dieser Slug ist bereits vergeben. Bitte einen anderen wählen.';
                } elseif ($writeError !== null) {
                    $flash['error'] = 'Speicherfehler: ' . $writeError;
                } else {
                    $flash['success'] = rtrim(BASE_URL, '/') . '/' . $slug;
                    $flash['url']     = '';
                    $flash['slug']    = '';
                }
            }
        }
    }

    // Post/Redirect/Get: ohne diesen Umweg löst jedes F5 ein erneutes Absenden
    // aus und läuft dann in den rotierten CSRF-Token.
    $_SESSION['flash'] = $flash;
    header('Location: ' . $selfPath, true, 303);
    exit;
}

$flash = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);

$error      = (string)($flash['error']   ?? '');
$successRaw = (string)($flash['success'] ?? '');
$formUrl    = (string)($flash['url']     ?? '');
$formSlug   = (string)($flash['slug']    ?? '');

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin');
header(
    "Content-Security-Policy: default-src 'none'; "
    . "style-src 'self'; font-src 'self'; script-src 'self'; "
    . "img-src 'self' data: https://api.qrserver.com; "
    . "base-uri 'none'; form-action 'self'; frame-ancestors 'none'"
);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redirecter</title>
    <meta name="description" content="Weiterleitungen erstellen.">
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="assets/favicon-32.png" sizes="32x32">
    <link rel="apple-touch-icon" href="assets/favicon-180.png">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<header>
    <h1>Redirecter</h1>
</header>

<main>
    <div class="card">
        <?php if ($error !== ''): ?>
            <div class="alert alert-error" role="alert"><?= $e($error) ?></div>
        <?php endif; ?>

        <?php if ($successRaw !== ''): ?>
            <div class="alert alert-success" role="alert">
                <span class="label">Kurzlink erstellt</span>
                <div class="result">
                    <a href="<?= $e($successRaw) ?>" target="_blank" rel="noopener"><?= $e($successRaw) ?></a>
                    <button type="button" class="copy" data-copy="<?= $e($successRaw) ?>">Kopieren</button>
                </div>
                <details class="qr">
                    <summary>QR-Code anzeigen</summary>
                    <img alt="QR-Code für <?= $e($successRaw) ?>" width="140" height="140"
                         referrerpolicy="no-referrer"
                         data-qr="https://api.qrserver.com/v1/create-qr-code/?size=140x140&amp;data=<?= rawurlencode($successRaw) ?>">
                </details>
            </div>
        <?php endif; ?>

        <form method="POST" action="">
            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">

            <div class="field">
                <label for="url">Ziel-URL</label>
                <input type="url" id="url" name="url"
                       placeholder="https://example.com/langer/pfad"
                       required autocomplete="off" autofocus
                       value="<?= $e($formUrl) ?>">
            </div>

            <div class="field">
                <label for="slug">Wunsch-Slug <span class="opt">(optional)</span></label>
                <input type="text" id="slug" name="slug"
                       placeholder="mein-link"
                       maxlength="<?= SLUG_MAX_LEN ?>"
                       autocomplete="off" pattern="[A-Za-z0-9_\-]+"
                       value="<?= $e($formSlug) ?>">
                <p class="hint">Erlaubt: Buchstaben, Ziffern, <code>-</code> und <code>_</code>. Leer lassen für automatische Generierung.</p>
            </div>

            <button type="submit" class="btn">Kurzlink erstellen</button>
        </form>

        <p class="domain"><?= $e(rtrim(BASE_URL, '/')) ?></p>
        <p class="nav"><a href="verwalten.php">Kurzlinks verwalten →</a></p>
    </div>
</main>

<footer>
    <a href="https://nozilla.de" rel="noopener">nozilla | bits &amp; bytes mit ❤</a>
    <a href="https://github.com/daimpad/redirecter" rel="noopener" class="gh" aria-label="Repository auf GitHub">
        <svg viewBox="0 0 24 24" aria-hidden="true">
            <path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61-.546-1.385-1.335-1.755-1.335-1.755-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/>
        </svg>
    </a>
</footer>

<script src="assets/app.js" defer></script>
</body>
</html>
