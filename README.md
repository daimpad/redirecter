# Redirecter

[![CI](https://github.com/daimpad/redirecter/actions/workflows/ci.yml/badge.svg)](https://github.com/daimpad/redirecter/actions/workflows/ci.yml)

Schlanker URL-Shortener in Vanilla PHP 8 — ohne Datenbank, ohne Framework.  
Datenspeicher ist eine lokale JSON-Datei, die außerhalb des öffentlich zugänglichen Bereichs liegt.  
Admin-Oberfläche im [nozilla CI](https://github.com/daimpad/nozilla-ci) Neo-Brutalist-Design, vollständig mobil optimiert.

Die Schriften (Zilla Slab · Inter · Space Mono, alle SIL OFL) liegen subgesetzt
als WOFF2 in `assets/fonts/` — zusammen 32 KB, kein Dritt-CDN.

## Voraussetzungen

| | |
|---|---|
| PHP | 8.0 oder neuer |
| Webserver | Apache mit `mod_rewrite` |
| Schreibrechte | `storage/` muss durch den Webserver-Prozess beschreibbar sein |

## Installation

```bash
# Repository klonen oder Dateien hochladen
git clone https://github.com/daimpad/redirecter.git /var/www/html/shortener
cd /var/www/html/shortener

# storage-Verzeichnis beschreibbar machen
chmod 775 storage
chmod 664 storage/data.json
chown -R www-data:www-data storage
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
const ADMIN_PASSWORD_HASH = ''; // leer = nur über localhost erreichbar
```

### Passwortschutz aktivieren — Pflicht vor dem Deployment

```bash
# Hash erzeugen und in config.php eintragen
php -r "echo password_hash('deinPasswort', PASSWORD_BCRYPT);"
```

Den ausgegebenen Hash in `config.php` bei `ADMIN_PASSWORD_HASH` eintragen.  
Danach schützt HTTP Basic Auth das Formular; Redirects bleiben öffentlich erreichbar.

Ohne Hash antwortet das Formular über eine Netzwerkadresse mit **HTTP 503** und
einem Setup-Hinweis. Das ist Absicht: eine ungeschützte Installation wäre ein
offenes Relay, über das Fremde beliebige Weiterleitungen auf der eigenen Domain
anlegen könnten. Über `127.0.0.1` bzw. `::1` bleibt das Formular ohne Hash
erreichbar, damit lokale Entwicklung ohne Setup funktioniert.

## Dateistruktur

```
.
├── .htaccess          # Apache Rewrite-Regeln + Dateischutz
├── config.php         # Zentrale Konfiguration (nicht via HTTP erreichbar)
├── functions.php      # Geteilte Funktionen (nicht via HTTP erreichbar)
├── index.php          # Admin-Formular + Verwaltungslogik
├── redirect.php       # Slug → 302-Redirect
├── assets/
│   ├── app.js         # Kopier-Button + QR-Code (optional, progressiv)
│   ├── favicon.svg    # nozilla CI (+ favicon-32.png / favicon-180.png)
│   └── fonts/         # Zilla Slab · Inter · Space Mono, subgesetzt als WOFF2
└── storage/
    ├── .htaccess      # Blockiert HTTP-Zugriff auf das Verzeichnis
    ├── data.json      # Datenspeicher (Slug ↔ Ziel-URL)
    └── data.json.lock # Exklusive Lock-Datei für atomare Schreibvorgänge
```

## Verwendung

### Kurzlink erstellen

Rufe `https://deinedomain.de/` auf. Das Formular hat zwei Felder:

| Feld | Beschreibung |
|---|---|
| **Ziel-URL** | Die lange URL, die gekürzt werden soll (Pflichtfeld) |
| **Wunsch-Slug** | Gewünschtes Kürzel, z. B. `mein-link` (optional) |

Bleibt der Slug leer, wird ein zufälliger 6-stelliger Base62-String generiert (~56 Milliarden mögliche Werte).

### Kurzlinks verwalten

Gespeicherte Links können direkt in `storage/data.json` bearbeitet oder gelöscht werden.  
Legacy-Einträge im alten String-Format werden beim ersten Aufruf automatisch migriert.

### Kurzlink aufrufen

```
https://deinedomain.de/mein-link
```

Der Server antwortet mit einem **HTTP 302**-Redirect zur hinterlegten Ziel-URL.

Bewusst 302 statt 301: Browser cachen einen 301 praktisch dauerhaft. Der
Klick-Zähler würde ab dem zweiten Besuch desselben Browsers nicht mehr
hochlaufen, und ein geändertes Ziel käme bei Wiederkehrern nie an.

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
| **Fail-closed** | Fehlt der Hash, sperrt `requireAuth()` den Zugang (HTTP 503) statt ihn zu öffnen — Ausnahme nur über Loopback |
| **Post/Redirect/Get** | Erfolg und Fehler laufen über einen 303-Redirect; ein Reload sendet nichts erneut ab |
| **Kein Dritt-CDN** | Schriften selbst gehostet, CSP ohne externe Hosts (`default-src 'none'`); der QR-Dienst wird erst auf Klick kontaktiert |
| **Timing-sichere Auth** | `hash_equals()` für Benutzername + `password_verify()` immer ausgeführt (kein Short-Circuit, der Username-Enumeration via Timing ermöglicht) |
| **Rate Limiting** | Max. `RATE_LIMIT_MAX` Link-Erstellungen/Minute/IP; atomares `apcu_add`+`apcu_inc` (kein TOCTOU), Session als Fallback |
| **CSRF-Schutz** | Session-Token wird vor dem POST-Handler initialisiert; explizite Leer-Prüfung verhindert `hash_equals('','')` Bypass |
| **Atomare Schreibvorgänge** | Schreiben in Temp-Datei + `rename()` — kein Datenverlust bei Disk-Full, Leser sehen immer eine vollständige Datei |
| **Lock-Datei** | `data.json.lock` als stabiles Lock-Inode — verhindert Lock-Splitting beim Umbenennen der Datendatei |
| **Slug-Sanitizing** | Nur `[A-Za-z0-9_-]` erlaubt — Sonderzeichen werden entfernt |
| **URL-Validierung** | `filter_var(FILTER_VALIDATE_URL)` + Pflicht-Präfix `http://` oder `https://` |
| **XSS-Schutz** | Alle HTML-Ausgaben über `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` |
| **data.json-Schutz** | Liegt in `storage/` mit eigenem `.htaccess` (`Require all denied`) |
| **Config-Schutz** | `config.php` und `functions.php` via `<FilesMatch>` blockiert |
| **Open Redirect** | Nur gespeicherte, validierte URLs als Redirect-Ziel |
| **Directory Listing** | `Options -Indexes` in `.htaccess` |
| **Sichere Header** | `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY`, `Content-Security-Policy`, `Referrer-Policy` |

## CI

Die Pipeline läuft bei jedem Push auf `main` und bei Pull Requests.

| Schritt | Was wird geprüft |
|---|---|
| **PHP-Syntax** | `php -l` für alle `*.php`-Dateien |
| **data.json** | Datei muss gültiges JSON sein |
| **.htaccess** | `RewriteEngine`, `Options -Indexes` und Datei-Schutz vorhanden |
| **storage/.htaccess** | `Require all denied` — kein HTTP-Direktzugriff auf Datenspeicher |
| **Pflichtdateien** | Alle sieben Kerndateien müssen committed sein |

Lokal testen:

```bash
# PHP-Syntax aller Dateien prüfen
for f in *.php; do php -l "$f"; done

# JSON validieren
python3 -c "import json; json.load(open('storage/data.json'))"
```

## Einschränkungen

- Für sehr hohe Gleichzeitigkeit (viele hundert Requests/Sekunde) ist eine Datenbank besser geeignet.
- `incrementHits()` schreibt bei jedem Redirect in die Datei — bei sehr hohem Traffic ist APCu als Hit-Puffer empfehlenswert.
