<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

requireAuth();
if (session_status() === PHP_SESSION_NONE) session_start();

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if ($token === '' || !hash_equals($_SESSION['csrf_token'], $token)) {
        $error = 'Ungültige Anfrage (CSRF).';
    } else {
        if (!checkRateLimit()) {
            $error = 'Zu viele Anfragen. Bitte warte eine Minute.';
        } else {
            $rawUrl  = trim($_POST['url']  ?? '');
            $rawSlug = trim($_POST['slug'] ?? '');

            if ($rawUrl === '') {
                $error = 'Bitte eine Ziel-URL angeben.';
            } elseif (!filter_var($rawUrl, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $rawUrl)) {
                $error = 'Bitte eine gültige HTTP/HTTPS-URL eingeben.';
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
                    $error = 'Der Slug enthält keine gültigen Zeichen.';
                } else {
                    $writeError = saveData($slug, $rawUrl);

                    $retries = 0;
                    while ($autoSlug && $writeError === 'SLUG_EXISTS' && $retries++ < 3) {
                        $slug       = generateSlug();
                        $writeError = saveData($slug, $rawUrl);
                    }

                    if ($writeError === 'SLUG_EXISTS') {
                        $error = 'Dieser Slug ist bereits vergeben. Bitte einen anderen wählen.';
                    } elseif ($writeError !== null) {
                        $error = 'Speicherfehler: ' . $writeError;
                    } else {
                        $successRaw = rtrim(BASE_URL, '/') . '/' . $slug;
                        $success    = htmlspecialchars($successRaw, ENT_QUOTES, 'UTF-8');
                    }
                }
            }
        }
    }
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com; script-src 'unsafe-inline'; img-src https: data:; form-action 'self'; frame-ancestors 'none'");
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Redirecter</title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600&family=Space+Mono:wght@400;700&display=swap');

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: 'Inter', system-ui, sans-serif;
            background: #ffffff;
            color: #000;
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
        }

        header {
            width: 100%;
            background: #000;
            color: #fff;
            padding: 1rem 1.5rem;
            text-align: center;
        }

        header h1 {
            font-family: 'Inter', system-ui, sans-serif;
            font-size: 1.1rem;
            font-weight: 600;
            letter-spacing: .12em;
            text-transform: uppercase;
        }

        main {
            flex: 1;
            width: 100%;
            max-width: 480px;
            padding: 2.5rem 1rem;
        }

        .card {
            border: 2px solid #000;
            box-shadow: 6px 6px 0 0 #000;
            padding: 2rem;
            background: #fff;
        }

        label {
            display: block;
            font-family: 'Space Mono', monospace;
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: rgba(0,0,0,.65);
            margin-bottom: .4rem;
        }

        input[type="url"],
        input[type="text"] {
            width: 100%;
            padding: .6rem .75rem;
            background: #fff;
            border: 2px solid #000;
            border-radius: 0;
            font-size: .9rem;
            font-family: 'Space Mono', monospace;
            color: #000;
            outline: none;
            transition: box-shadow .1s;
        }

        input:focus { box-shadow: 3px 3px 0 0 #000; }

        .hint {
            font-family: 'Space Mono', monospace;
            font-size: .68rem;
            color: rgba(0,0,0,.65);
            margin-top: .4rem;
        }

        .field { margin-bottom: 1.1rem; }

        button[type="submit"].btn-create {
            width: 100%;
            padding: .7rem;
            background: #00FF9C;
            color: #000;
            border: 2px solid #000;
            border-radius: 0;
            box-shadow: 3px 3px 0 0 #000;
            font-family: 'Space Mono', monospace;
            font-size: .75rem;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
            cursor: pointer;
            transition: box-shadow .1s, transform .1s;
            margin-top: .5rem;
        }

        button[type="submit"].btn-create:hover {
            box-shadow: 6px 6px 0 0 #000;
            transform: translate(-2px, -2px);
        }

        button[type="submit"].btn-create:active {
            box-shadow: none;
            transform: translate(3px, 3px);
        }

        .domain-hint {
            margin-top: .85rem;
            text-align: center;
            font-family: 'Space Mono', monospace;
            font-size: .72rem;
            color: rgba(0,0,0,.45);
        }

        .alert {
            padding: .8rem 1rem;
            margin-bottom: 1.1rem;
            font-size: .875rem;
            border: 2px solid #000;
            box-shadow: 3px 3px 0 0 #000;
        }

        .alert-error   { background: #FFD6C0; }
        .alert-success { background: #C0FFE0; }
        .alert-warning { background: #FFF0C0; }

        .short-url {
            font-family: 'Space Mono', monospace;
            font-weight: 700;
            word-break: break-all;
        }

        a { color: #000; text-decoration: underline; text-decoration-thickness: 2px; }
        a:hover { background: #00FF9C; text-decoration: none; }

        code {
            font-family: 'Space Mono', monospace;
            background: rgba(0,0,0,.06);
            border: 1.5px solid rgba(0,0,0,.18);
            padding: .1em .3em;
            font-size: .85em;
        }

        footer {
            width: 100%;
            border-top: 2px solid #000;
            padding: .75rem 1rem;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 1rem;
            font-family: 'Space Mono', monospace;
            font-size: .72rem;
        }

        footer a {
            color: rgba(0,0,0,.6);
            text-decoration: none;
        }

        footer a:hover {
            background: #00FF9C;
            color: #000;
        }

        .footer-github svg {
            display: block;
            width: 18px;
            height: 18px;
            fill: rgba(0,0,0,.6);
            transition: fill .1s;
        }

        .footer-github:hover svg { fill: #000; }

        @media (max-width: 600px) {
            main { padding: 1.5rem .75rem; }
            .card { padding: 1.25rem; box-shadow: 4px 4px 0 0 #000; }
        }
    </style>
</head>
<body>

<header>
    <h1>Redirecter</h1>
</header>

<main>
    <div class="card">
        <?php if (ADMIN_PASSWORD_HASH === ''): ?>
            <div class="alert alert-warning" role="alert">
                Kein Passwortschutz aktiv. <code>ADMIN_PASSWORD_HASH</code> in <code>config.php</code> setzen.
            </div>
        <?php endif; ?>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error" role="alert">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <?php if ($success !== ''): ?>
            <div class="alert alert-success" role="alert">
                Kurzlink erstellt:&nbsp;
                <a class="short-url" href="<?= $success ?>" rel="noopener"><?= $success ?></a>
                <img src="https://api.qrserver.com/v1/create-qr-code/?size=120x120&data=<?= urlencode($successRaw) ?>"
                     alt="QR-Code" width="120" height="120"
                     style="display:block;margin-top:.85rem;border-radius:6px;">
            </div>
        <?php endif; ?>

        <form method="POST" action="" novalidate>
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="create">

            <div class="field">
                <label for="url">Ziel-URL</label>
                <input
                    type="url"
                    id="url"
                    name="url"
                    placeholder="https://example.com/langer/pfad"
                    required
                    autocomplete="off"
                    value="<?= isset($_POST['url']) && ($_POST['action'] ?? '') === 'create' ? htmlspecialchars($_POST['url'], ENT_QUOTES, 'UTF-8') : '' ?>"
                >
            </div>

            <div class="field">
                <label for="slug">Wunsch-Slug <span style="font-weight:400">(optional)</span></label>
                <input
                    type="text"
                    id="slug"
                    name="slug"
                    placeholder="mein-link"
                    maxlength="<?= SLUG_MAX_LEN ?>"
                    autocomplete="off"
                    pattern="[A-Za-z0-9_\-]+"
                    value="<?= isset($_POST['slug']) && ($_POST['action'] ?? '') === 'create' ? htmlspecialchars($_POST['slug'], ENT_QUOTES, 'UTF-8') : '' ?>"
                >
                <p class="hint">Erlaubt: Buchstaben, Ziffern, <code>-</code> und <code>_</code>. Leer lassen für automatische Generierung.</p>
            </div>

            <button type="submit" class="btn-create">Kurzlink erstellen</button>
        </form>

        <p class="domain-hint"><?= htmlspecialchars(rtrim(BASE_URL, '/'), ENT_QUOTES, 'UTF-8') ?></p>
    </div>
</main>

<footer>
    <a href="https://nozilla.de" rel="noopener">nozilla | bits &amp; bytes mit ❤</a>
    <a href="https://github.com/daimpad/redirecter" rel="noopener" class="footer-github" title="GitHub">
        <svg viewBox="0 0 24 24" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
            <path d="M12 .297c-6.63 0-12 5.373-12 12 0 5.303 3.438 9.8 8.205 11.385.6.113.82-.258.82-.577 0-.285-.01-1.04-.015-2.04-3.338.724-4.042-1.61-4.042-1.61-.546-1.385-1.335-1.755-1.335-1.755-1.087-.744.084-.729.084-.729 1.205.084 1.838 1.236 1.838 1.236 1.07 1.835 2.809 1.305 3.495.998.108-.776.417-1.305.76-1.605-2.665-.3-5.466-1.332-5.466-5.93 0-1.31.465-2.38 1.235-3.22-.135-.303-.54-1.523.105-3.176 0 0 1.005-.322 3.3 1.23.96-.267 1.98-.399 3-.405 1.02.006 2.04.138 3 .405 2.28-1.552 3.285-1.23 3.285-1.23.645 1.653.24 2.873.12 3.176.765.84 1.23 1.91 1.23 3.22 0 4.61-2.805 5.625-5.475 5.92.42.36.81 1.096.81 2.22 0 1.606-.015 2.896-.015 3.286 0 .315.21.69.825.57C20.565 22.092 24 17.592 24 12.297c0-6.627-5.373-12-12-12"/>
        </svg>
    </a>
</footer>

</body>
</html>
