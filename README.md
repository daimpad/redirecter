# URL-Shortener

Schlanker URL-Shortener in Vanilla PHP 8 — ohne Datenbank, ohne Framework.  
Datenspeicher ist eine lokale JSON-Datei, die außerhalb des öffentlich zugänglichen Bereichs liegt.

## Voraussetzungen

| | |
|---|---|
| PHP | 8.0 oder neuer |
| Webserver | Apache mit `mod_rewrite` |
| Schreibrechte | `storage/data.json` muss durch den Webserver-Prozess beschreibbar sein |

## Installation

```bash
# Repository klonen oder Dateien hochladen
git clone https://github.com/daimpad/redirecter.git /var/www/html/shortener
cd /var/www/html/shortener

# Datenspeicher beschreibbar machen
chmod 664 storage/data.json
chown www-data:www-data storage/data.json
```

Stelle sicher, dass `mod_rewrite` aktiviert und `AllowOverride All` für das Verzeichnis gesetzt ist:

```apacheconf
<Directory /var/www/html/shortener>
    AllowOverride All
</Directory>
```

## Konfiguration

Alle Einstellungen befinden sich in **`config.php`**:

```php
// Base-URL der Installation (kein abschließender Slash)
const BASE_URL = 'https://deinedomain.de';

// Rate Limiting: max. Link-Erstellungen pro Minute pro IP (0 = deaktiviert)
const RATE_LIMIT_MAX = 10;

// Admin-Zugangsdaten
const ADMIN_USER          = 'admin';
const ADMIN_PASSWORD_HASH = ''; // leer = Auth deaktiviert
```

### Passwortschutz aktivieren

```bash
# Hash erzeugen und in config.php eintragen
php -r "echo password_hash('deinPasswort', PASSWORD_BCRYPT);"
```

Den ausgegebenen Hash in `config.php` bei `ADMIN_PASSWORD_HASH` eintragen.  
Danach schützt HTTP Basic Auth das Formular; Redirects bleiben öffentlich erreichbar.

## Dateistruktur

```
.
├── .htaccess          # Apache Rewrite-Regeln + Dateischutz
├── config.php         # Zentrale Konfiguration (nicht via HTTP erreichbar)
├── functions.php      # Geteilte Funktionen: loadData, saveData, deleteSlug, incrementHits, Auth, RateLimit (nicht via HTTP erreichbar)
├── index.php          # Admin-Formular + Speicher-Logik
├── redirect.php       # Slug → 301-Redirect
└── storage/
    ├── .htaccess      # Blockiert HTTP-Zugriff auf das Verzeichnis
    └── data.json      # Datenspeicher (Slug ↔ Ziel-URL)
```

## Verwendung

### Kurzlink erstellen

Rufe `https://deinedomain.de/` auf. Das Formular hat zwei Felder:

| Feld | Beschreibung |
|---|---|
| **Ziel-URL** | Die lange URL, die gekürzt werden soll (Pflichtfeld) |
| **Wunsch-Slug** | Gewünschtes Kürzel, z. B. `mein-link` (optional) |

Bleibt der Slug leer, wird ein zufälliger 6-stelliger Hex-String generiert.

### Kurzlinks verwalten

Unterhalb des Formulars zeigt die Admin-Seite alle gespeicherten Links mit Slug, Ziel-URL, Klick-Zähler und Erstelldatum. Jeder Link kann per Klick auf „Löschen" entfernt werden (mit Bestätigungsdialog).

### Kurzlink aufrufen

```
https://deinedomain.de/mein-link
```

Der Server antwortet mit einem **HTTP 301**-Redirect zur hinterlegten Ziel-URL.

### data.json — Format

```json
{
    "gh": {
        "url": "https://github.com",
        "hits": 42,
        "created": "2026-05-26"
    }
}
```

Einträge können auch manuell in `storage/data.json` gepflegt werden.  
Legacy-Einträge im alten String-Format (`"slug": "url"`) werden beim ersten Redirect automatisch in das neue Format migriert.

## Performance

`loadData()` nutzt **APCu** als In-Memory-Cache (60 s TTL), sofern die PHP-Extension verfügbar ist.  
Nach jedem Schreibvorgang wird der Cache automatisch invalidiert.  
Ohne APCu wird die JSON-Datei bei jedem Request gelesen — ausreichend für einige Tausend Einträge.

APCu aktivieren (Debian/Ubuntu):
```bash
apt install php-apcu
# php.ini: apc.enabled=1, apc.shm_size=32M
```

## Sicherheit

| Maßnahme | Details |
|---|---|
| **Passwortschutz** | HTTP Basic Auth via `requireAuth()`, bcrypt-Hash in `config.php` |
| **Rate Limiting** | Max. `RATE_LIMIT_MAX` Link-Erstellungen/Minute/IP; APCu wenn verfügbar, Session als Fallback |
| **CSRF-Schutz** | Session-Token, verglichen mit `hash_equals()` (Timing-safe) |
| **Slug-Sanitizing** | Nur `[A-Za-z0-9_-]` erlaubt — Sonderzeichen werden entfernt |
| **URL-Validierung** | `filter_var(FILTER_VALIDATE_URL)` + Pflicht-Präfix `http://` oder `https://` |
| **XSS-Schutz** | Alle HTML-Ausgaben über `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` |
| **Race Conditions** | Kollisionsprüfung und Schreiben innerhalb `flock(LOCK_EX)` |
| **data.json-Schutz** | Liegt in `storage/` mit eigenem `.htaccess` (`Require all denied`) |
| **Config-Schutz** | `config.php` und `functions.php` via `<FilesMatch>` blockiert |
| **Open Redirect** | Nur gespeicherte, validierte URLs als Redirect-Ziel |
| **Directory Listing** | `Options -Indexes` in `.htaccess` |
| **Sichere Header** | `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY` |

## Einschränkungen

- Für sehr hohe Gleichzeitigkeit (viele hundert Requests/Sekunde) ist eine Datenbank besser geeignet.
- `incrementHits()` schreibt bei jedem Redirect in die Datei — bei sehr hohem Traffic ist APCu als Hit-Puffer empfehlenswert.
