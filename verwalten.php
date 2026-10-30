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

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Wie in index.php aus SCRIPT_NAME statt REQUEST_URI — letzteres ist
// clientkontrolliert und könnte als "//fremde-domain" ankommen.
$selfPath = ($_SERVER['SCRIPT_NAME'] ?? '/verwalten.php');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flash = ['error' => '', 'info' => ''];

    $token = $_POST['csrf_token'] ?? '';
    if ($token === '' || !hash_equals($_SESSION['csrf_token'], $token)) {
        $flash['error'] = 'Die Sitzung ist abgelaufen. Bitte erneut versuchen.';
    } else {
        $slug = preg_replace('/[^A-Za-z0-9_-]/', '', $_POST['slug'] ?? '');

        if ($slug === '') {
            $flash['error'] = 'Kein gültiger Slug angegeben.';
        } else {
            $delError = deleteSlug($slug);

            if ($delError === null) {
                $flash['info'] = 'Kurzlink „' . $slug . '" wurde gelöscht.';
            } elseif ($delError === 'SLUG_NOT_FOUND') {
                $flash['error'] = 'Kurzlink „' . $slug . '" existiert nicht (mehr).';
            } else {
                $flash['error'] = 'Löschen fehlgeschlagen: ' . $delError;
            }
        }
    }

    // Post/Redirect/Get, damit ein Reload nicht erneut zu löschen versucht
    $_SESSION['flash'] = $flash;
    header('Location: ' . $selfPath, true, 303);
    exit;
}

$flash = $_SESSION['flash'] ?? [];
unset($_SESSION['flash']);

$error = (string)($flash['error'] ?? '');
$info  = (string)($flash['info']  ?? '');

$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];

$links = loadData();

// Neueste zuerst — beim Anlegen wird hinten angehängt
$links = array_reverse($links, true);

$base = rtrim(BASE_URL, '/');
$e    = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');

header('Content-Type: text/html; charset=UTF-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: strict-origin');
header(
    "Content-Security-Policy: default-src 'none'; "
    . "style-src 'self'; font-src 'self'; script-src 'self'; img-src 'self' data:; "
    . "base-uri 'none'; form-action 'self'; frame-ancestors 'none'"
);
?>
<!DOCTYPE html>
<html lang="de">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verwaltung — Redirecter</title>
    <meta name="robots" content="noindex, nofollow">
    <link rel="icon" href="assets/favicon.svg" type="image/svg+xml">
    <link rel="icon" href="assets/favicon-32.png" sizes="32x32">
    <link rel="apple-touch-icon" href="assets/favicon-180.png">
    <link rel="stylesheet" href="assets/style.css">
</head>
<body>

<header>
    <h1>Redirecter</h1>
</header>

<main class="wide">
    <div class="card">
        <div class="card-head">
            <h2>Kurzlinks</h2>
            <span class="count"><?= count($links) ?></span>
        </div>

        <?php if ($error !== ''): ?>
            <div class="alert alert-error" role="alert"><?= $e($error) ?></div>
        <?php endif; ?>

        <?php if ($info !== ''): ?>
            <div class="alert alert-info" role="status"><?= $e($info) ?></div>
        <?php endif; ?>

        <?php if ($links === []): ?>
            <p class="empty">Noch keine Kurzlinks vorhanden.</p>
        <?php else: ?>
            <div class="table-wrap">
                <table class="links">
                    <thead>
                        <tr>
                            <th scope="col">Slug</th>
                            <th scope="col">Ziel-URL</th>
                            <th scope="col">Klicks</th>
                            <th scope="col">Erstellt</th>
                            <th scope="col"><span class="visually-hidden">Aktion</span></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($links as $slug => $entry): ?>
                        <?php
                            $slug     = (string)$slug;
                            $target   = entryUrl($entry);
                            $shortUrl = $base . '/' . $slug;
                            $isHttp   = (bool)preg_match('/^https?:\/\//i', $target);
                        ?>
                        <tr>
                            <td class="col-slug">
                                <a href="<?= $e($shortUrl) ?>" target="_blank" rel="noopener"><?= $e($slug) ?></a>
                            </td>
                            <td class="col-target" title="<?= $e($target) ?>">
                                <?php if ($isHttp): ?>
                                    <a href="<?= $e($target) ?>" target="_blank" rel="noopener noreferrer"><?= $e($target) ?></a>
                                <?php else: ?>
                                    <?= $e($target) ?>
                                <?php endif; ?>
                            </td>
                            <td class="col-hits"><?= entryHits($entry) ?></td>
                            <td class="col-date"><?= $e(entryCreated($entry)) ?></td>
                            <td class="col-act">
                                <form method="POST" action=""
                                      data-confirm="Kurzlink «<?= $e($slug) ?>» wirklich löschen?">
                                    <input type="hidden" name="csrf_token" value="<?= $e($csrfToken) ?>">
                                    <input type="hidden" name="slug" value="<?= $e($slug) ?>">
                                    <button type="submit" class="btn-del">Löschen</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <p class="nav"><a href="./">← Kurzlink erstellen</a></p>
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
