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
    . "style-src 'unsafe-inline'; font-src 'self'; script-src 'self'; "
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
    <style>
        /* nozilla CI — Schriften selbst gehostet, kein Dritt-CDN */
        @font-face {
            font-family: 'Zilla Slab'; font-style: normal; font-weight: 700; font-display: swap;
            src: url('assets/fonts/ZillaSlab-Bold.woff2') format('woff2');
        }
        @font-face {
            font-family: 'Inter'; font-style: normal; font-weight: 400; font-display: swap;
            src: url('assets/fonts/Inter-Regular.woff2') format('woff2');
        }
        @font-face {
            font-family: 'Space Mono'; font-style: normal; font-weight: 400; font-display: swap;
            src: url('assets/fonts/SpaceMono-Regular.woff2') format('woff2');
        }
        @font-face {
            font-family: 'Space Mono'; font-style: normal; font-weight: 700; font-display: swap;
            src: url('assets/fonts/SpaceMono-Bold.woff2') format('woff2');
        }

        :root {
            --nz-signal:        #00FF9C;
            --nz-signal-strong: #00E88D;
            --nz-ink:           #000000;
            --nz-ink-70:        rgba(0, 0, 0, .72);
            --nz-ink-20:        rgba(0, 0, 0, .18);
            --nz-bg:            #FFFFFF;

            --nz-f-display: 'Zilla Slab', Georgia, serif;
            --nz-f-body:    'Inter', system-ui, sans-serif;
            --nz-f-mono:    'Space Mono', 'Courier New', monospace;

            --nz-stroke-rule: 2px;
            --nz-shadow-sm: 3px 3px 0 0 var(--nz-ink);
            --nz-shadow-md: 6px 6px 0 0 var(--nz-ink);

            --nz-dur:  160ms;
            --nz-ease: cubic-bezier(.2, 0, 0, 1);
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--nz-f-body);
            background: var(--nz-bg);
            color: var(--nz-ink);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        /* Kopf */
        header {
            width: 100%;
            background: var(--nz-ink);
            color: var(--nz-bg);
            padding: 1.25rem 1.5rem;
            text-align: center;
        }

        header h1 {
            font-family: var(--nz-f-display);
            font-size: clamp(1.5rem, 5vw, 2rem);
            font-weight: 700;
            line-height: 1;
            letter-spacing: -.02em;
        }

        /* Formular */
        main {
            flex: 1;
            width: 100%;
            max-width: 480px;
            padding: 2.5rem 1rem;
            display: flex;
            flex-direction: column;
            justify-content: center;
        }

        .card {
            background: var(--nz-bg);
            border: var(--nz-stroke-rule) solid var(--nz-ink);
            box-shadow: var(--nz-shadow-md);
            padding: 2rem;
        }

        label, .label {
            display: block;
            font-family: var(--nz-f-mono);
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--nz-ink-70);
            margin-bottom: .45rem;
        }

        label .opt { font-weight: 400; letter-spacing: .06em; }

        input[type="url"],
        input[type="text"] {
            width: 100%;
            padding: .6rem .75rem;
            background: var(--nz-bg);
            border: var(--nz-stroke-rule) solid var(--nz-ink);
            border-radius: 0;
            /* 1rem, sonst zoomt iOS Safari beim Antippen in die Seite hinein */
            font-size: 1rem;
            font-family: var(--nz-f-mono);
            color: var(--nz-ink);
            outline: none;
            transition: box-shadow var(--nz-dur) var(--nz-ease);
            -webkit-appearance: none;
        }

        input:focus-visible { box-shadow: var(--nz-shadow-sm); }

        .hint {
            font-family: var(--nz-f-mono);
            font-size: .7rem;
            line-height: 1.5;
            color: var(--nz-ink-70);
            margin-top: .45rem;
        }

        .field + .field { margin-top: 1.1rem; }

        .btn {
            display: block;
            width: 100%;
            padding: .75rem;
            background: var(--nz-signal);
            color: var(--nz-ink);
            border: var(--nz-stroke-rule) solid var(--nz-ink);
            border-radius: 0;
            box-shadow: var(--nz-shadow-sm);
            font-family: var(--nz-f-mono);
            font-size: .78rem;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
            cursor: pointer;
            transition: box-shadow var(--nz-dur) var(--nz-ease),
                        transform  var(--nz-dur) var(--nz-ease);
            margin-top: 1.5rem;
        }

        .btn:hover { background: var(--nz-signal-strong); box-shadow: var(--nz-shadow-md); transform: translate(-2px, -2px); }
        .btn:active { box-shadow: none; transform: translate(3px, 3px); }

        /* Tastatur-Fokus muss sichtbar sein — vorher war der Button nicht erkennbar */
        .btn:focus-visible,
        .copy:focus-visible,
        summary:focus-visible,
        a:focus-visible {
            outline: 3px solid var(--nz-ink);
            outline-offset: 3px;
        }

        .domain {
            margin-top: 1rem;
            text-align: center;
            font-family: var(--nz-f-mono);
            font-size: .72rem;
            color: var(--nz-ink-70);
        }

        /* Meldungen */
        .alert {
            padding: .9rem 1rem;
            margin-bottom: 1.25rem;
            font-size: .9rem;
            line-height: 1.5;
            border: var(--nz-stroke-rule) solid var(--nz-ink);
            box-shadow: var(--nz-shadow-sm);
        }

        .alert-error   { background: #FFD6C0; }
        .alert-success { background: #C0FFE0; }

        .result {
            display: flex;
            align-items: center;
            gap: .6rem;
            margin-top: .6rem;
        }

        .result a {
            flex: 1;
            font-family: var(--nz-f-mono);
            font-weight: 700;
            font-size: .85rem;
            /* anywhere statt break-all: bricht an Trennstellen, nicht mitten im Wort */
            overflow-wrap: anywhere;
            color: var(--nz-ink);
            text-decoration: underline;
            text-decoration-thickness: 2px;
        }

        .copy {
            flex: none;
            padding: .4rem .7rem;
            background: var(--nz-bg);
            border: var(--nz-stroke-rule) solid var(--nz-ink);
            border-radius: 0;
            font-family: var(--nz-f-mono);
            font-size: .65rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            cursor: pointer;
            transition: background var(--nz-dur) var(--nz-ease);
        }

        .copy:hover     { background: var(--nz-signal); }
        .copy[data-done] { background: var(--nz-signal); }

        /* QR lädt erst auf Klick — sonst erführe api.qrserver.com jeden Kurzlink */
        .qr { margin-top: .9rem; }

        .qr summary {
            font-family: var(--nz-f-mono);
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
            cursor: pointer;
            color: var(--nz-ink-70);
        }

        .qr img { display: block; margin-top: .7rem; border: var(--nz-stroke-rule) solid var(--nz-ink); }

        .qr .fallback { margin-top: .7rem; font-family: var(--nz-f-mono); font-size: .68rem; color: var(--nz-ink-70); }

        code {
            font-family: var(--nz-f-mono);
            background: rgba(0, 0, 0, .06);
            border: 1.5px solid var(--nz-ink-20);
            padding: .1em .35em;
            font-size: .85em;
        }

        /* Fuß */
        footer {
            width: 100%;
            border-top: var(--nz-stroke-rule) solid var(--nz-ink);
            padding: .9rem 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            font-family: var(--nz-f-mono);
            font-size: .72rem;
        }

        footer a { color: var(--nz-ink-70); text-decoration: none; display: inline-flex; align-items: center; }
        footer a:hover { color: var(--nz-ink); background: var(--nz-signal); }

        .gh svg { display: block; width: 18px; height: 18px; fill: currentColor; }

        @media (prefers-reduced-motion: reduce) {
            * { transition: none !important; }
        }

        @media (max-width: 600px) {
            main  { padding: 1.5rem .75rem; }
            .card { padding: 1.25rem; box-shadow: 4px 4px 0 0 var(--nz-ink); }
        }
    </style>
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
