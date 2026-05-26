<?php
declare(strict_types=1);

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

requireAuth();
if (session_status() === PHP_SESSION_NONE) session_start();

$error   = '';
$success = '';
$info    = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $error = 'Ungültige Anfrage (CSRF).';
    } else {
        $action = $_POST['action'] ?? 'create';

        if ($action === 'delete') {
            $slugToDel = preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['slug'] ?? '');
            if ($slugToDel !== '') {
                $delError = deleteSlug($slugToDel);
                if ($delError === null) {
                    $info = 'Kurzlink „' . $slugToDel . '" wurde gelöscht.';
                } elseif ($delError !== 'SLUG_NOT_FOUND') {
                    $error = 'Löschen fehlgeschlagen: ' . $delError;
                }
            }
        } else {
            // Link erstellen
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
                            $successRaw = rtrim(BASE_URL, '/') . '/' . $slug;
                        $success    = htmlspecialchars($successRaw, ENT_QUOTES, 'UTF-8');
                        }
                    }
                }
            }
        }
    }
}

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken  = $_SESSION['csrf_token'];
$allLinks   = loadData();

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; script-src 'unsafe-inline'; img-src https: data:; form-action 'self'; frame-ancestors 'none'");
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
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 2rem 1rem;
            gap: 1.5rem;
        }

        .card, .table-card {
            background: #fff;
            border-radius: 12px;
            box-shadow: 0 4px 24px rgba(0,0,0,.08);
            padding: 2.5rem 2rem;
            width: 100%;
        }

        .card      { max-width: 480px; }
        .table-card{ max-width: 860px; }

        h1 { font-size: 1.5rem; margin-bottom: 1.75rem; color: #1a1a2e; }
        h2 { font-size: 1.1rem; margin-bottom: 1.25rem; color: #1a1a2e; }

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

        .hint { font-size: .78rem; color: #6b7280; margin-top: .3rem; }
        .field { margin-bottom: 1.25rem; }

        button[type="submit"].btn-create {
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

        button[type="submit"].btn-create:hover { background: #4338ca; }

        .alert {
            padding: .85rem 1rem;
            border-radius: 8px;
            margin-bottom: 1.25rem;
            font-size: .9rem;
        }

        .alert-error   { background: #fef2f2; color: #b91c1c; border: 1px solid #fca5a5; }
        .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #86efac; }
        .alert-warning { background: #fffbeb; color: #92400e; border: 1px solid #fcd34d; }
        .alert-info    { background: #eff6ff; color: #1e40af; border: 1px solid #93c5fd; }

        .short-url { font-weight: 700; word-break: break-all; }
        a { color: #4f46e5; }

        /* Links-Tabelle */
        .links-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .875rem;
        }

        .links-table th {
            text-align: left;
            padding: .6rem .75rem;
            background: #f4f6f9;
            border-bottom: 2px solid #e5e7eb;
            color: #374151;
            font-weight: 600;
            white-space: nowrap;
        }

        .links-table td {
            padding: .55rem .75rem;
            border-bottom: 1px solid #e5e7eb;
            vertical-align: middle;
        }

        .links-table tr:last-child td { border-bottom: none; }

        .links-table tr:hover td { background: #f9fafb; }

        .col-url {
            max-width: 320px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
        }

        .col-hits, .col-date { white-space: nowrap; color: #6b7280; }

        .btn-delete {
            padding: .3rem .65rem;
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fca5a5;
            border-radius: 6px;
            cursor: pointer;
            font-size: .8rem;
            transition: background .15s;
        }

        .btn-delete:hover { background: #fecaca; }

        .empty-state { text-align: center; color: #9ca3af; padding: 1.5rem 0; }
    </style>
</head>
<body>

<main class="card">
    <h1>URL-Shortener</h1>

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

    <?php if ($info !== ''): ?>
        <div class="alert alert-info" role="alert">
            <?= htmlspecialchars($info, ENT_QUOTES, 'UTF-8') ?>
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
</main>

<section class="table-card">
    <h2>Alle Kurzlinks (<?= count($allLinks) ?>)</h2>

    <?php if (empty($allLinks)): ?>
        <p class="empty-state">Noch keine Kurzlinks vorhanden.</p>
    <?php else: ?>
        <table class="links-table">
            <thead>
                <tr>
                    <th>Slug</th>
                    <th>Ziel-URL</th>
                    <th>Klicks</th>
                    <th>Erstellt</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($allLinks as $s => $entry): ?>
                    <?php
                        $safeSlug   = htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
                        $targetUrl  = entryUrl($entry);
                        $safeTarget = htmlspecialchars($targetUrl, ENT_QUOTES, 'UTF-8');
                        $shortUrl   = htmlspecialchars(rtrim(BASE_URL, '/'), ENT_QUOTES, 'UTF-8') . '/' . $safeSlug;
                    ?>
                    <tr>
                        <td><a href="<?= $shortUrl ?>" rel="noopener" target="_blank"><?= $safeSlug ?></a></td>
                        <td class="col-url" title="<?= $safeTarget ?>">
                            <?php if (preg_match('/^https?:\/\//i', $targetUrl)): ?>
                                <a href="<?= $safeTarget ?>" rel="noopener noreferrer" target="_blank"><?= $safeTarget ?></a>
                            <?php else: ?>
                                <?= $safeTarget ?>
                            <?php endif; ?>
                        </td>
                        <td class="col-hits"><?= entryHits($entry) ?></td>
                        <td class="col-date"><?= htmlspecialchars(entryCreated($entry), ENT_QUOTES, 'UTF-8') ?></td>
                        <td>
                            <form method="POST" action="" onsubmit="return confirm('Kurzlink «<?= $safeSlug ?>» wirklich löschen?')">
                                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="slug"   value="<?= $safeSlug ?>">
                                <button type="submit" class="btn-delete">Löschen</button>
                            </form>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</section>

</body>
</html>
