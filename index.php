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
    <title>Redirecter</title>
    <style>
        /* Mozilla Photon Design System — dark theme */
        :root {
            --bg:         #1B1B1B;
            --surface:    #2A2A2E;
            --surface2:   #38383D;
            --border:     #4A4A4F;
            --text:       #F9F9FA;
            --muted:      #B1B1B3;
            --accent:     #0080FF;
            --accent-h:   #45A1FF;
            --mono:       'Fira Code', 'Cascadia Code', 'Consolas', monospace;
            --green:      #30E60B;
            --green-bg:   #0D2600;
            --green-b:    #12BC00;
            --red:        #FF3750;
            --red-bg:     #3E0000;
            --red-b:      #D70022;
            --orange:     #FF9400;
            --orange-bg:  #3E1A00;
            --orange-b:   #C45A27;
            --blue:       #45A1FF;
            --blue-bg:    #002050;
            --blue-b:     #0060DF;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Ubuntu, sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 2rem 1rem;
            gap: 1.5rem;
        }

        .card, .table-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 2rem;
            width: 100%;
        }

        .card      { max-width: 480px; }
        .table-card{ max-width: 860px; }

        h1, h2 {
            font-weight: 600;
            letter-spacing: .04em;
            text-transform: uppercase;
            border-bottom: 1px solid var(--border);
            padding-bottom: .65rem;
            color: var(--text);
        }
        h1 { font-size: 1.1rem; margin-bottom: 1.5rem; }
        h2 { font-size: .9rem;  margin-bottom: 1.25rem; color: var(--muted); }

        label {
            display: block;
            font-size: .75rem;
            font-weight: 700;
            color: var(--muted);
            letter-spacing: .07em;
            text-transform: uppercase;
            margin-bottom: .35rem;
        }

        input[type="url"],
        input[type="text"] {
            width: 100%;
            padding: .6rem .75rem;
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 3px;
            font-size: .9rem;
            color: var(--text);
            font-family: var(--mono);
            transition: border-color .15s, box-shadow .15s;
            outline: none;
        }

        input:focus {
            border-color: var(--accent);
            box-shadow: 0 0 0 1px var(--accent);
        }

        .hint { font-size: .75rem; color: var(--muted); margin-top: .35rem; }
        .field { margin-bottom: 1.1rem; }

        button[type="submit"].btn-create {
            width: 100%;
            padding: .65rem;
            background: var(--accent);
            color: #fff;
            border: none;
            border-radius: 3px;
            font-size: .8rem;
            font-weight: 700;
            letter-spacing: .05em;
            text-transform: uppercase;
            cursor: pointer;
            transition: background .15s;
            margin-top: .5rem;
        }

        button[type="submit"].btn-create:hover { background: var(--accent-h); }

        .alert {
            padding: .7rem 1rem;
            border-radius: 3px;
            margin-bottom: 1.1rem;
            font-size: .85rem;
            border-left: 3px solid;
        }

        .alert-error   { background: var(--red-bg);    color: var(--red);    border-color: var(--red-b); }
        .alert-success { background: var(--green-bg);  color: var(--green);  border-color: var(--green-b); }
        .alert-warning { background: var(--orange-bg); color: var(--orange); border-color: var(--orange-b); }
        .alert-info    { background: var(--blue-bg);   color: var(--blue);   border-color: var(--blue-b); }

        .short-url {
            font-weight: 700;
            word-break: break-all;
            font-family: var(--mono);
        }

        a { color: var(--accent); text-decoration: none; }
        a:hover { color: var(--accent-h); text-decoration: underline; }

        code {
            font-family: var(--mono);
            background: var(--bg);
            border: 1px solid var(--border);
            border-radius: 2px;
            padding: .1em .3em;
            font-size: .85em;
        }

        /* Links-Tabelle */
        .links-table {
            width: 100%;
            border-collapse: collapse;
            font-size: .85rem;
        }

        .links-table th {
            text-align: left;
            padding: .5rem .75rem;
            background: var(--bg);
            border-bottom: 2px solid var(--border);
            color: var(--muted);
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
            white-space: nowrap;
        }

        .links-table td {
            padding: .5rem .75rem;
            border-bottom: 1px solid var(--surface2);
            vertical-align: middle;
        }

        .links-table tr:last-child td { border-bottom: none; }
        .links-table tr:hover td { background: var(--surface2); }

        .col-url {
            max-width: 320px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-family: var(--mono);
            font-size: .8rem;
            color: var(--muted);
        }

        .col-hits {
            white-space: nowrap;
            color: var(--accent);
            font-variant-numeric: tabular-nums;
            font-weight: 600;
        }

        .col-date { white-space: nowrap; color: var(--muted); font-size: .8rem; }

        .slug-link { font-family: var(--mono); }

        .btn-delete {
            padding: .25rem .55rem;
            background: transparent;
            color: var(--red);
            border: 1px solid var(--red-b);
            border-radius: 3px;
            cursor: pointer;
            font-size: .7rem;
            font-weight: 700;
            letter-spacing: .04em;
            text-transform: uppercase;
            transition: background .15s;
        }

        .btn-delete:hover { background: var(--red-bg); }

        .empty-state {
            text-align: center;
            color: var(--muted);
            padding: 1.5rem 0;
            font-size: .875rem;
        }
    </style>
</head>
<body>

<main class="card">
    <h1>Redirecter</h1>

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
                        <td><a class="slug-link" href="<?= $shortUrl ?>" rel="noopener" target="_blank"><?= $safeSlug ?></a></td>
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
