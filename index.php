<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

requireAuth();

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    session_start();

    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $error = 'Ungültige Anfrage (CSRF).';
    } else {
        $rawUrl  = trim($_POST['url']  ?? '');
        $rawSlug = trim($_POST['slug'] ?? '');

        if ($rawUrl === '') {
            $error = 'Bitte eine Ziel-URL angeben.';
        } elseif (!filter_var($rawUrl, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $rawUrl)) {
            $error = 'Bitte eine gültige HTTP/HTTPS-URL eingeben.';
        } else {
            if ($rawSlug === '') {
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

                if ($writeError === 'SLUG_EXISTS') {
                    $error = 'Dieser Slug ist bereits vergeben. Bitte einen anderen wählen.';
                } elseif ($writeError !== null) {
                    $error = 'Speicherfehler: ' . $writeError;
                } else {
                    $success = rtrim(BASE_URL, '/') . '/' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
                }
            }
        }
    }
} else {
    session_start();
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>URL-Shortener</title>
    <style>
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: system-ui, -apple-system, sans-serif;
            background: #f4f6f9;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 1.5rem;
        }

        .card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,.08);
            padding: 2.5rem 2rem;
            width: 100%;
            max-width: 480px;
        }

        h1 {
            font-size: 1.5rem;
            margin-bottom: 1.75rem;
            color: #1a1a2e;
        }

        label {
            display: block;
            font-size: .875rem;
            font-weight: 600;
            color: #374151;
            margin-bottom: .35rem;
        }

        input[type="url"],
        input[type="text"] {
            width: 100%;
            padding: .65rem .85rem;
            border: 1.5px solid #d1d5db;
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color .2s;
            outline: none;
        }

        input:focus { border-color: #4f46e5; }

        .hint {
            font-size: .78rem;
            color: #6b7280;
            margin-top: .3rem;
        }

        .field { margin-bottom: 1.25rem; }

        button[type="submit"] {
            width: 100%;
            padding: .75rem;
            background: #4f46e5;
            color: #fff;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: background .2s;
            margin-top: .5rem;
        }

        button[type="submit"]:hover { background: #4338ca; }

        .alert {
            padding: .85rem 1rem;
            border-radius: 8px;
            margin-bottom: 1.25rem;
            font-size: .9rem;
        }

        .alert-error   { background: #fef2f2; color: #b91c1c; border: 1px solid #fca5a5; }
        .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #86efac; }
        .alert-warning { background: #fffbeb; color: #92400e; border: 1px solid #fcd34d; }

        .short-url { font-weight: 700; word-break: break-all; }

        a { color: #4f46e5; }
    </style>
</head>
<body>
<main class="card">
    <h1>URL-Shortener</h1>

    <?php if (ADMIN_PASSWORD_HASH === ''): ?>
        <div class="alert alert-warning" role="alert">
            Kein Passwortschutz aktiv. Bitte <code>ADMIN_PASSWORD_HASH</code> in <code>config.php</code> setzen.
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
        </div>
    <?php endif; ?>

    <form method="POST" action="" novalidate>
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">

        <div class="field">
            <label for="url">Ziel-URL</label>
            <input
                type="url"
                id="url"
                name="url"
                placeholder="https://example.com/langer/pfad"
                required
                autocomplete="off"
                value="<?= isset($_POST['url']) ? htmlspecialchars($_POST['url'], ENT_QUOTES, 'UTF-8') : '' ?>"
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
                value="<?= isset($_POST['slug']) ? htmlspecialchars($_POST['slug'], ENT_QUOTES, 'UTF-8') : '' ?>"
            >
            <p class="hint">Erlaubt: Buchstaben, Ziffern, <code>-</code> und <code>_</code>. Leer lassen für automatische Generierung.</p>
        </div>

        <button type="submit">Kurzlink erstellen</button>
    </form>
</main>
</body>
</html>
