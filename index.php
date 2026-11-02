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

// CSRF-Token vor der POST-Behandlung anlegen, sonst würde hash_equals('','') greifen.
// Ein Token pro Sitzung, nicht pro Seitenaufruf: Ein neues bei jedem Aufruf
// machte Formulare in anderen Tabs ungültig — und eine Löschung, die beim
// Verlassen der Verwaltung noch per sendBeacon nachgereicht wird, käme erst
// nach dem Aufruf der nächsten Seite an und würde abgelehnt.
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Ziel für den Redirect nach dem POST. Bewusst aus SCRIPT_NAME statt REQUEST_URI:
// REQUEST_URI ist clientkontrolliert und könnte als "//fremde-domain" ankommen.
$selfPath = rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/'), '/') . '/';

// app.js schickt das Formular per fetch und will JSON zurück, damit die
// Erfolgsanimation ohne Neuladen durchlaufen kann. Ohne JavaScript bleibt es
// beim klassischen Post/Redirect/Get.
$wantsJson = str_contains($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // 'field' sagt, zu welchem Eingabefeld ein Fehler gehört (url, slug oder leer)
    $flash = ['error' => '', 'field' => '', 'success' => '', 'url' => '', 'slug' => ''];

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
            $flash['field'] = 'url';
        } elseif (!filter_var($rawUrl, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $rawUrl)) {
            $flash['error'] = 'Bitte eine gültige HTTP/HTTPS-URL eingeben.';
            $flash['field'] = 'url';
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
                $flash['field'] = 'slug';
            } elseif (file_exists(__DIR__ . '/' . $slug)) {
                // .htaccess liefert vorhandene Dateien und Ordner direkt aus —
                // ein Slug wie "assets" oder "storage" wäre nie erreichbar.
                $flash['error'] = 'Dieser Slug ist reserviert. Bitte einen anderen wählen.';
                $flash['field'] = 'slug';
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
                    $flash['field'] = 'slug';
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

    if ($wantsJson) {
        header('Content-Type: application/json; charset=UTF-8');
        header('Cache-Control: no-store');
        echo json_encode([
            'ok'    => $flash['success'] !== '',
            'short' => $flash['success'],
            'error' => $flash['error'],
            'field' => $flash['field'],
            'count' => count(loadData()),
            'csrf'  => $_SESSION['csrf_token'],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
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
$errorField = (string)($flash['field']   ?? '');
$successRaw = (string)($flash['success'] ?? '');
$formUrl    = (string)($flash['url']     ?? '');
$formSlug   = (string)($flash['slug']    ?? '');

$csrfToken = $_SESSION['csrf_token'];

$linkCount = count(loadData());
$baseHost  = preg_replace('#^https?://#i', '', rtrim(BASE_URL, '/'));

$e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

// Ergebnis-Karte. Wird nach einem klassischen POST direkt gerendert und steckt
// leer im <template>, aus dem app.js sie nach einem fetch-POST befüllt — so
// gibt es das Markup nur einmal.
$renderResult = static function (string $short) use ($e): void {
    $qr = 'https://api.qrserver.com/v1/create-qr-code/?size=140x140&data=' . rawurlencode($short);
    ?>
    <div class="alert alert-success result-card" role="status">
        <span class="label">Kurzlink erstellt</span>
        <div class="result">
            <a class="result-link" href="<?= $e($short) ?>" target="_blank" rel="noopener"><?= $e($short) ?></a>
            <button type="button" class="copy" data-copy="<?= $e($short) ?>"><span class="copy-label">Kopieren</span> <kbd class="kbd-hint" aria-hidden="true">C</kbd></button>
        </div>
        <details class="qr">
            <summary>QR-Code anzeigen</summary>
            <img alt="QR-Code für den Kurzlink" width="140" height="140"
                 referrerpolicy="no-referrer" data-qr="<?= $e($qr) ?>">
        </details>
    </div>
    <?php
};

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin');
header(
    "Content-Security-Policy: default-src 'none'; "
    . "style-src 'self'; font-src 'self'; script-src 'self'; connect-src 'self'; "
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
    <h1>Redirecter<span class="dot" aria-hidden="true"></span></h1>
</header>

<main>
    <div class="card">
        <?php if ($error !== '' && $errorField === ''): ?>
            <div class="alert alert-error" role="alert"><?= $e($error) ?></div>
        <?php endif; ?>

        <div class="result-slot">
            <?php if ($successRaw !== ''): ?>
                <?php $renderResult($successRaw); ?>
            <?php endif; ?>
        </div>

        <form method="POST" action="" data-base="<?= $e($baseHost) ?>" data-max="<?= SLUG_MAX_LEN ?>">
            <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">

            <div class="field">
                <label for="url">Ziel-URL</label>
                <input type="url" id="url" name="url"
                       placeholder="https://example.com/langer/pfad"
                       required autocomplete="off" autofocus
                       aria-describedby="url-status"
                       <?= $errorField === 'url' ? 'class="is-invalid" aria-invalid="true"' : '' ?>
                       value="<?= $e($formUrl) ?>">
                <p class="field-status" id="url-status" aria-live="polite"><?php if ($errorField === 'url'): ?><span class="field-error"><?= $e($error) ?></span><?php endif; ?></p>
            </div>

            <div class="field">
                <label for="slug">Wunsch-Slug <span class="opt">(optional)</span></label>
                <div class="slug-row">
                    <input type="text" id="slug" name="slug"
                           placeholder="mein-link"
                           maxlength="<?= SLUG_MAX_LEN ?>"
                           autocomplete="off" pattern="[A-Za-z0-9_\-]+"
                           aria-describedby="slug-status slug-hint"
                           <?= $errorField === 'slug' ? 'class="is-invalid" aria-invalid="true"' : '' ?>
                           value="<?= $e($formSlug) ?>">
                    <button type="button" class="dice" aria-label="Zufälligen Slug würfeln" title="Würfeln" hidden>
                        <svg viewBox="0 0 24 24" aria-hidden="true"><rect x="2" y="2" width="20" height="20"/><rect class="pip" x="5.5" y="5.5" width="4" height="4"/><rect class="pip" x="14.5" y="5.5" width="4" height="4"/><rect class="pip" x="10" y="10" width="4" height="4"/><rect class="pip" x="5.5" y="14.5" width="4" height="4"/><rect class="pip" x="14.5" y="14.5" width="4" height="4"/></svg>
                    </button>
                </div>
                <p class="preview" hidden><span class="preview-host"><?= $e($baseHost) ?>/</span><span class="preview-slug"></span></p>
                <p class="field-status" id="slug-status" aria-live="polite"><?php if ($errorField === 'slug'): ?><span class="field-error"><?= $e($error) ?></span><?php endif; ?></p>
                <p class="hint" id="slug-hint">Erlaubt: Buchstaben, Ziffern, <code>-</code> und <code>_</code>. Leer lassen für automatische Generierung.</p>
            </div>

            <button type="submit" class="btn">
                <span class="btn-label">Kurzlink erstellen</span>
                <span class="btn-busy" aria-hidden="true"><i></i><i></i><i></i></span>
                <kbd class="kbd-hint" aria-hidden="true">↵</kbd>
            </button>
        </form>

        <p class="domain"><?= $e($baseHost) ?> · <span class="counter" data-count="<?= $linkCount ?>"><?= $linkCount ?></span> <span class="counter-label"><?= $linkCount === 1 ? 'Kurzlink' : 'Kurzlinks' ?></span></p>
        <p class="nav"><a href="verwalten.php">Kurzlinks verwalten →</a></p>

        <template id="tpl-result"><?php $renderResult(''); ?></template>
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

<div class="toasts" aria-live="polite"></div>

<script src="assets/app.js" defer></script>
</body>
</html>
