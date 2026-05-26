<?php
declare(strict_types=1);

const DATA_FILE = __DIR__ . '/data.json';
const SLUG_MAX_LEN = 64;
const RANDOM_SLUG_LEN = 6;

// ── Hilfsfunktionen ───────────────────────────────────────────────────────────

function loadData(): array
{
    if (!is_file(DATA_FILE)) {
        return [];
    }
    $json = file_get_contents(DATA_FILE);
    $data = json_decode($json, true);
    return is_array($data) ? $data : [];
}

/**
 * Schreibt $data atomar in DATA_FILE.
 * Gibt null bei Erfolg zurück, andernfalls eine Fehlermeldung.
 */
function saveData(array $data): ?string
{
    $fp = fopen(DATA_FILE, 'c+');
    if ($fp === false) {
        return 'Datei konnte nicht geöffnet werden.';
    }

    if (!flock($fp, LOCK_EX)) {
        fclose($fp);
        return 'Datei ist gesperrt – bitte erneut versuchen.';
    }

    // Aktuellen Inhalt erneut lesen (nach Lock-Erwerb)
    fseek($fp, 0);
    $existing = stream_get_contents($fp);
    $stored   = json_decode($existing, true);
    if (is_array($stored)) {
        $data = array_merge($stored, $data);
    }

    ftruncate($fp, 0);
    fseek($fp, 0);
    $written = fwrite($fp, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    fflush($fp);
    flock($fp, LOCK_UN);
    fclose($fp);

    return $written === false ? 'Schreibfehler.' : null;
}

function generateSlug(): string
{
    return substr(bin2hex(random_bytes(RANDOM_SLUG_LEN)), 0, RANDOM_SLUG_LEN);
}

// ── Formular-Verarbeitung ─────────────────────────────────────────────────────

$error   = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // CSRF-Schutz: einfaches Token-Muster via Session
    session_start();

    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'] ?? '', $token)) {
        $error = 'Ungültige Anfrage (CSRF).';
    } else {
        $rawUrl  = trim($_POST['url']  ?? '');
        $rawSlug = trim($_POST['slug'] ?? '');

        // URL validieren
        if ($rawUrl === '') {
            $error = 'Bitte eine Ziel-URL angeben.';
        } elseif (!filter_var($rawUrl, FILTER_VALIDATE_URL) || !preg_match('/^https?:\/\//i', $rawUrl)) {
            $error = 'Bitte eine gültige HTTP/HTTPS-URL eingeben.';
        } else {
            // Slug normalisieren
            if ($rawSlug === '') {
                $slug = generateSlug();
                // Kollisionen bei zufälligem Slug vermeiden
                $existing = loadData();
                $attempts = 0;
                while (isset($existing[$slug]) && $attempts++ < 10) {
                    $slug = generateSlug();
                }
            } else {
                // Nur alphanumerische Zeichen, Bindestrich und Unterstrich
                $slug = preg_replace('/[^A-Za-z0-9_-]/', '', $rawSlug);
                $slug = substr($slug, 0, SLUG_MAX_LEN);
            }

            if ($slug === '') {
                $error = 'Der Slug enthält keine gültigen Zeichen.';
            } else {
                $data = loadData();

                if (isset($data[$slug])) {
                    $error = 'Dieser Slug ist bereits vergeben. Bitte einen anderen wählen.';
                } else {
                    $data[$slug] = $rawUrl;
                    $writeError  = saveData([$slug => $rawUrl]);

                    if ($writeError !== null) {
                        $error = 'Speicherfehler: ' . $writeError;
                    } else {
                        $scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                        $host    = htmlspecialchars($_SERVER['HTTP_HOST'], ENT_QUOTES, 'UTF-8');
                        $success = $scheme . '://' . $host . '/' . htmlspecialchars($slug, ENT_QUOTES, 'UTF-8');
                    }
                }
            }
        }
    }
} else {
    session_start();
}

// CSRF-Token für diesen Request erzeugen / erneuern
$_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrfToken = $_SESSION['csrf_token'];

// ── Ausgabe ───────────────────────────────────────────────────────────────────
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

        .short-url {
            font-weight: 700;
            word-break: break-all;
        }

        a { color: #4f46e5; }
    </style>
</head>
<body>
<main class="card">
    <h1>URL-Shortener</h1>

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
