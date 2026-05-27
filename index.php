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
        /* nozilla CI — neo-brutalist design system */
        @import url('https://fonts.googleapis.com/css2?family=Zilla+Slab:wght@700&family=Inter:wght@400;600&family=Space+Mono:wght@400;700&display=swap');

        :root {
            --nz-paper:   #FFFEE5;
            --nz-ink:     #000000;
            --nz-ink-70:  rgba(0,0,0,.65);
            --nz-ink-20:  rgba(0,0,0,.18);
            --nz-signal:  #00FF9C;
            --nz-error:   #FF5F1F;
            --nz-good:    #00C075;
            --nz-shadow:  6px 6px 0 0 #000;
            --nz-shadow-s:3px 3px 0 0 #000;
            --nz-border:  2px solid #000;
            --nz-f-disp:  'Zilla Slab', Georgia, serif;
            --nz-f-body:  'Inter', system-ui, sans-serif;
            --nz-f-mono:  'Space Mono', 'Courier New', monospace;
        }

        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

        body {
            font-family: var(--nz-f-body);
            background: var(--nz-paper);
            color: var(--nz-ink);
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: flex-start;
            padding: 2.5rem 1rem;
            gap: 2rem;
        }

        .card, .table-card {
            background: var(--nz-paper);
            border: var(--nz-border);
            box-shadow: var(--nz-shadow);
            padding: 2rem;
            width: 100%;
        }

        .card      { max-width: 480px; }
        .table-card{ max-width: 860px; }

        h1 {
            font-family: var(--nz-f-disp);
            font-weight: 700;
            font-size: 2.25rem;
            line-height: 1;
            letter-spacing: -0.02em;
            margin-bottom: 1.5rem;
            padding-bottom: .75rem;
            border-bottom: var(--nz-border);
        }

        h2 {
            font-family: var(--nz-f-mono);
            font-weight: 700;
            font-size: .7rem;
            letter-spacing: .14em;
            text-transform: uppercase;
            margin-bottom: 1.25rem;
            padding-bottom: .6rem;
            border-bottom: var(--nz-border);
            color: var(--nz-ink-70);
        }

        label {
            display: block;
            font-family: var(--nz-f-mono);
            font-size: .68rem;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--nz-ink-70);
            margin-bottom: .4rem;
        }

        input[type="url"],
        input[type="text"] {
            width: 100%;
            padding: .6rem .75rem;
            background: #fff;
            border: var(--nz-border);
            border-radius: 0;
            font-size: .9rem;
            font-family: var(--nz-f-mono);
            color: var(--nz-ink);
            outline: none;
            transition: box-shadow .1s;
        }

        input:focus { box-shadow: var(--nz-shadow-s); }

        .hint {
            font-family: var(--nz-f-mono);
            font-size: .68rem;
            color: var(--nz-ink-70);
            margin-top: .4rem;
        }

        .field { margin-bottom: 1.1rem; }

        button[type="submit"].btn-create {
            width: 100%;
            padding: .7rem;
            background: var(--nz-signal);
            color: var(--nz-ink);
            border: var(--nz-border);
            border-radius: 0;
            box-shadow: var(--nz-shadow-s);
            font-family: var(--nz-f-mono);
            font-size: .75rem;
            font-weight: 700;
            letter-spacing: .1em;
            text-transform: uppercase;
            cursor: pointer;
            transition: box-shadow .1s, transform .1s;
            margin-top: .5rem;
        }

        button[type="submit"].btn-create:hover {
            box-shadow: var(--nz-shadow);
            transform: translate(-2px, -2px);
        }

        button[type="submit"].btn-create:active {
            box-shadow: none;
            transform: translate(3px, 3px);
        }

        .alert {
            padding: .8rem 1rem;
            margin-bottom: 1.1rem;
            font-size: .875rem;
            border: var(--nz-border);
            box-shadow: var(--nz-shadow-s);
        }

        .alert-error   { background: #FFD6C0; }
        .alert-success { background: #C0FFE0; }
        .alert-warning { background: #FFF0C0; }
        .alert-info    { background: #C0E8FF; }

        .short-url {
            font-family: var(--nz-f-mono);
            font-weight: 700;
            word-break: break-all;
        }

        a { color: var(--nz-ink); text-decoration: underline; text-decoration-thickness: 2px; }
        a:hover { background: var(--nz-signal); text-decoration: none; }

        code {
            font-family: var(--nz-f-mono);
            background: rgba(0,0,0,.06);
            border: 1.5px solid var(--nz-ink-20);
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
            padding: .55rem .75rem;
            background: var(--nz-paper);
            border-bottom: var(--nz-border);
            font-family: var(--nz-f-mono);
            font-size: .65rem;
            font-weight: 700;
            letter-spacing: .12em;
            text-transform: uppercase;
            color: var(--nz-ink-70);
            white-space: nowrap;
        }

        .links-table td {
            padding: .55rem .75rem;
            border-bottom: 1.5px solid var(--nz-ink-20);
            vertical-align: middle;
        }

        .links-table tr:last-child td { border-bottom: none; }

        .links-table tr:hover td { background: rgba(0,255,156,.2); }

        .col-url {
            max-width: 320px;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-family: var(--nz-f-mono);
            font-size: .78rem;
            color: var(--nz-ink-70);
        }

        .col-hits {
            white-space: nowrap;
            font-family: var(--nz-f-mono);
            font-weight: 700;
            font-variant-numeric: tabular-nums;
        }

        .col-date {
            white-space: nowrap;
            font-family: var(--nz-f-mono);
            font-size: .75rem;
            color: var(--nz-ink-70);
        }

        .slug-link { font-family: var(--nz-f-mono); font-weight: 700; }

        .btn-delete {
            padding: .28rem .6rem;
            background: transparent;
            color: var(--nz-error);
            border: 2px solid var(--nz-error);
            border-radius: 0;
            cursor: pointer;
            font-family: var(--nz-f-mono);
            font-size: .65rem;
            font-weight: 700;
            letter-spacing: .08em;
            text-transform: uppercase;
            transition: background .1s, box-shadow .1s, transform .1s;
        }

        .btn-delete:hover {
            background: var(--nz-error);
            color: #fff;
            box-shadow: 2px 2px 0 0 var(--nz-ink);
            transform: translate(-1px, -1px);
        }

        .btn-delete:active {
            transform: translate(1px, 1px);
            box-shadow: none;
        }

        .empty-state {
            text-align: center;
            color: var(--nz-ink-70);
            padding: 2rem 0;
            font-family: var(--nz-f-mono);
            font-size: .8rem;
            letter-spacing: .08em;
            text-transform: uppercase;
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
