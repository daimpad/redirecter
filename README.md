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
# (data.json entsteht beim ersten Kurzlink von selbst)
chmod 775 storage
chown -R www-data:www-data storage
```

Stelle sicher, dass `mod_rewrite` aktiviert und `AllowOverride All` für das Verzeichnis gesetzt ist:

```apacheconf
<Directory /var/www/html/shortener>
    AllowOverride All
</Directory>
```

## Konfiguration

Die Konfiguration ist zweigeteilt:

| Datei | Im Repository | Zweck |
|---|---|---|
| `config.php` | ja | Vorgaben für alle Installationen |
| `config.local.php` | **nein** | Serverspezifisches, allen voran der Passwort-Hash |

`config.php` lädt `config.local.php`, falls vorhanden. Jeder dort definierte Wert
gewinnt. Ohne diese Trennung würde jedes Deployment den Hash des Servers durch
den leeren Standard ersetzen und die Installation aussperren — `config.local.php`
steht deshalb in `.gitignore` und wird von keinem Deploy angefasst.

```php
// config.php — Vorgaben
defined('BASE_URL')            || define('BASE_URL', 'https://url.nozilla.net');
defined('RATE_LIMIT_MAX')      || define('RATE_LIMIT_MAX', 10);
defined('ADMIN_USER')          || define('ADMIN_USER', 'admin');
defined('ADMIN_PASSWORD_HASH') || define('ADMIN_PASSWORD_HASH', '');
```

Einrichtung auf dem Server (einmalig):

```bash
cp config.local.example.php config.local.php
php -r "echo password_hash('deinPasswort', PASSWORD_BCRYPT), PHP_EOL;"
# Ausgabe in config.local.php bei ADMIN_PASSWORD_HASH eintragen
```

### Passwortschutz aktivieren — Pflicht vor dem Deployment

```bash
# Hash erzeugen und in config.local.php eintragen
php -r "echo password_hash('deinPasswort', PASSWORD_BCRYPT);"
```

Den ausgegebenen Hash in `config.local.php` bei `ADMIN_PASSWORD_HASH` eintragen.  
Danach schützt HTTP Basic Auth das Formular; Redirects bleiben öffentlich erreichbar.

Ohne Hash antworten Formular und Verwaltung mit **HTTP 503** und einem
Setup-Hinweis — ausnahmslos, auch lokal. Eine ungeschützte Installation wäre ein
offenes Relay, über das Fremde beliebige Weiterleitungen auf der eigenen Domain
anlegen könnten. Eine Ausnahme für `127.0.0.1` gibt es bewusst nicht: hinter dem
nginx-Proxy von Plesk kann jede Anfrage von dort zu kommen scheinen. Für lokale
Tests einfach auch dort eine `config.local.php` anlegen.

## Dateistruktur

```
.
├── .htaccess          # Apache Rewrite-Regeln + Dateischutz
├── config.php         # Vorgaben (nicht via HTTP erreichbar)
├── config.local.php   # Serverspezifisch, nicht im Repository, überlebt Deploys
├── config.local.example.php  # Vorlage dafür
├── functions.php      # Geteilte Funktionen (nicht via HTTP erreichbar)
├── index.php          # Formular zum Anlegen
├── verwalten.php      # Liste aller Kurzlinks + Löschen
├── redirect.php       # Slug → 302-Redirect
├── assets/
│   ├── style.css      # nozilla CI, geteilt von beiden Seiten
│   ├── app.js         # Kopier-Button + QR-Code (optional, progressiv)
│   ├── favicon.svg    # nozilla CI (+ favicon-32.png / favicon-180.png)
│   └── fonts/         # Zilla Slab · Inter · Space Mono, subgesetzt als WOFF2
└── storage/
    ├── .htaccess      # Blockiert HTTP-Zugriff auf das Verzeichnis
    ├── data.json      # Datenspeicher (Slug ↔ Ziel-URL) — nur auf dem Server, nicht im Repository
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

### Was beim Bedienen passiert — und warum

Die Oberfläche soll Spaß machen, ohne zu stören. Jede Rückmeldung folgt einem
UX-Prinzip; nichts davon ist nötig, damit der Redirecter funktioniert. Ohne
JavaScript bleibt alles bei normalen Formularen, und wer im Betriebssystem
„Bewegung reduzieren" eingestellt hat, bekommt die Rückmeldung ohne Wackeln,
Hüpfen und Konfetti.

| Was passiert | Prinzip |
|---|---|
| Eine gültige Ziel-URL wird sofort mit ✓ und ihrem Host bestätigt; Unfertiges wird erst beim Verlassen des Feldes angemahnt | Früh loben, spät meckern |
| `nozilla.de/x` wird zu `https://nozilla.de/x` ergänzt, statt abgelehnt zu werden | Fehlertoleranz |
| Umlaute, `&` und Leerzeichen im Slug werden beim Tippen übersetzt (`Kurz & gut` → `Kurz-und-gut`) | Fehler vermeiden statt melden |
| Unter dem Slug-Feld steht live der fertige Link | Sichtbarkeit, das Ergebnis vor Augen |
| Der Würfel erzeugt aussprechbare Slugs (`dani-vira`) — merkbar und diktierbar | Spieltrieb mit Nutzen |
| Der Knopf zeigt einen Ladezustand und lässt sich nicht doppelt auslösen | Systemstatus, Fehlervermeidung |
| Erfolg: Die Karte wird aufgestempelt, Konfetti in 6 × 6 (die Marke aus den nozilla-Icons), der grüne Punkt im Kopf hüpft, der Zähler rollt | Peak-End-Regel |
| Danach liegt der Fokus auf „Kopieren" | Der nächste Schritt liegt schon bereit |
| Fehler stehen direkt am Feld, der Inhalt ist markiert; die Karte schüttelt kurz den Kopf | Fehler erkennen und beheben |
| Löschen geschieht sofort, fünf Sekunden lang mit „Rückgängig" | Rückgängig statt Rückfrage |
| Filterfeld und Klick-Balken in der Verwaltung | Wiedererkennen statt Erinnern |

**Tastenkürzel** — angezeigt nur, wo es eine Tastatur gibt:

| Taste | Wirkung |
|---|---|
| `/` | ins URL- bzw. Filterfeld |
| `↵` | Kurzlink erstellen |
| `C` | Ergebnis kopieren |
| `Esc` | Ergebnis schließen, neu anfangen |
| `Strg`/`⌘` + `Z` | Löschen rückgängig machen |
| `?` | Übersicht der Tasten |

Das Formular schickt per `fetch` ab und bekommt JSON zurück (`Accept:
application/json`), damit die Animationen ohne Neuladen durchlaufen. Ohne
JavaScript bleibt es beim klassischen Post/Redirect/Get.

### Kurzlinks verwalten

`verwalten.php` listet alle Kurzlinks mit Slug, Ziel-URL, Klick-Zähler und
Erstelldatum — neueste zuerst. Erreichbar über den Link unter dem Formular.

Löschen geschieht ohne Rückfrage, dafür fünf Sekunden lang mit „Rückgängig"
(oder `Strg`/`⌘` + `Z`). Erst danach geht die Anfrage an den Server. Wer die
Seite in dieser Zeit verlässt, hat sich entschieden: Die Löschung wird dann per
`sendBeacon` nachgereicht.

Die Seite liegt hinter demselben Passwortschutz wie das Formular und trägt
`noindex, nofollow`. Löschen läuft CSRF-geschützt und über einen 303-Redirect,
ein Reload versucht also nicht erneut zu löschen.

Alternativ können Einträge weiterhin direkt in `storage/data.json` bearbeitet
werden. Legacy-Einträge im alten String-Format (`"slug": "url"`) werden korrekt
angezeigt und beim ersten Redirect automatisch migriert.

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
| **Passwortschutz** | HTTP Basic Auth via `requireAuth()`, bcrypt-Hash in `config.local.php`. Funktioniert mit `mod_php` und unter PHP-FPM (Plesk): `.htaccess` reicht den Authorization-Header durch, `basicAuthCredentials()` liest ihn aus |
| **Fail-closed** | Fehlt der Hash, sperrt `requireAuth()` den Zugang (HTTP 503) statt ihn zu öffnen — ohne Ausnahme |
| **Repo-Dateien** | `README.md`, `.gitignore`, `.github/` liefert `.htaccess` nicht aus (HTTP 404), obwohl Plesk sie in den Web-Root legt |
| **Reservierte Slugs** | Slugs, die wie eine vorhandene Datei oder ein Ordner heißen (`assets`, `storage` …), werden abgelehnt — sie wären nie erreichbar |
| **Post/Redirect/Get** | Erfolg und Fehler laufen über einen 303-Redirect; ein Reload sendet nichts erneut ab |
| **Kein Dritt-CDN** | Schriften selbst gehostet, CSP ohne externe Hosts (`default-src 'none'`); der QR-Dienst wird erst auf Klick kontaktiert |
| **CSP ohne `unsafe-inline`** | Styles und Skripte liegen in eigenen Dateien (`style-src 'self'`, `script-src 'self'`) |
| **Timing-sichere Auth** | `hash_equals()` für Benutzername + `password_verify()` immer ausgeführt (kein Short-Circuit, der Username-Enumeration via Timing ermöglicht) |
| **Rate Limiting** | Max. `RATE_LIMIT_MAX` Link-Erstellungen/Minute/IP; atomares `apcu_add`+`apcu_inc` (kein TOCTOU), Session als Fallback |
| **CSRF-Schutz** | Ein Token pro Sitzung, vor dem POST-Handler initialisiert; explizite Leer-Prüfung verhindert `hash_equals('','')` Bypass. Bewusst nicht pro Seitenaufruf erneuert: Das machte Formulare in anderen Tabs ungültig und ließ eine beim Verlassen nachgereichte Löschung scheitern |
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

## Deployment

Produktion ist **https://url.nozilla.net**. Der Server bezieht `main` selbst per
Git (Plesk-Git-Anbindung) — je nach Einstellung dort automatisch oder per Klick
auf *Pull*. Es gibt keinen eigenen Deploy-Workflow, keine SSH-Schlüssel und keine
Secrets.

Damit ein Deploy nichts zerstört, liegt alles, was nur auf dem Server existieren
darf, außerhalb des Repositorys:

| Datei | Warum nicht im Repository |
|---|---|
| `config.local.php` | der Passwort-Hash — ein Deploy würde ihn sonst überschreiben |
| `storage/data.json` | die Kurzlinks — ein Deploy würde sie sonst auf `{}` zurücksetzen |

Beide stehen in `.gitignore`; die CI schlägt an, falls eine davon doch
eingecheckt wird.

### Einmalig auf dem Server

1. **`config.local.php` anlegen** — im Plesk-Dateimanager
   `config.local.example.php` kopieren, in `config.local.php` umbenennen und den
   Hash eintragen. Erzeugen lässt er sich im SSH-Terminal von Plesk:
   `php -r "echo password_hash('deinPasswort', PASSWORD_BCRYPT);"`.
   Ohne Hash antwortet die Seite mit HTTP 503.
2. **Plesk-Platzhalter** — das `index.html` („Hier entsteht eine neue Webseite")
   stört nicht mehr, `.htaccess` setzt `DirectoryIndex index.php`. Löschen
   schadet trotzdem nicht.
3. **`storage/` beschreibbar** — bei Plesk der Normalfall, weil PHP als
   Abonnement-Benutzer läuft.

### Spielwiese auf GitHub Pages

`deploy.yml` veröffentlicht eine spielbare Fassung des Formulars auf GitHub
Pages. Sie wird bei jedem Push von `.github/build-demo.sh` aus `index.php`
gerendert und in den Demo-Modus geschaltet: `assets/app.js` spielt dann die
Antworten des Servers nach, gespeichert wird nichts. Eine eingecheckte Kopie
gibt es nicht, die Spielwiese kann der echten Seite also nicht davonlaufen.
Lokal bauen:

```bash
.github/build-demo.sh _site    # danach z. B.: python3 -m http.server -d _site
```

Mit dem Server hat die Spielwiese nichts zu tun.

## CI

Die Pipeline läuft bei jedem Push auf `main` und bei Pull Requests.

| Schritt | Was wird geprüft |
|---|---|
| **PHP-Syntax** | `php -l` für alle `*.php`-Dateien |
| **data.json nicht eingecheckt** | Sonst setzte jeder Deploy die Kurzlinks zurück |
| **.htaccess** | `RewriteEngine`, `Options -Indexes` und Datei-Schutz vorhanden |
| **storage/.htaccess** | `Require all denied` — kein HTTP-Direktzugriff auf Datenspeicher |
| **Pflichtdateien** | Alle Kerndateien inkl. Schriften und Stylesheet müssen committed sein |
| **Kein Dritt-CDN** | Keine Referenz auf `fonts.googleapis.com` / `fonts.gstatic.com` |
| **Keine inline-Styles** | Kein `style="…"` — die CSP (`style-src 'self'`) würde es verwerfen |
| **Fail-closed** | `requireAuth()` lässt ohne Hash niemanden durch, auch nicht `127.0.0.1` |
| **Anmeldung unter PHP-FPM** | `requireAuth()` akzeptiert die Zugangsdaten auch, wenn nur der rohe Header ankommt |
| **Spielwiese baut** | `.github/build-demo.sh` läuft durch, Ergebnis im Demo-Modus und ohne CSRF-Token |
| **Keine Inline-Handler** | Kein `onsubmit="…"` o. ä. — die CSP würde ihn still ignorieren |
| **Dateischutz** | `.htaccess` sperrt `config.php`, `config.local.php` und `functions.php` |
| **Kein Hash im Repo** | `config.local.php` ist weder eingecheckt noch eincheckbar |

Lokal testen:

```bash
# PHP-Syntax aller Dateien prüfen
for f in *.php; do php -l "$f"; done

# Fail-closed-Verhalten prüfen (darf nichts ausgeben ausser dem Setup-Hinweis)
php -r '$_SERVER["REMOTE_ADDR"]="203.0.113.7";
        require "config.php"; require "functions.php"; requireAuth();'
```

## Einschränkungen

- Für sehr hohe Gleichzeitigkeit (viele hundert Requests/Sekunde) ist eine Datenbank besser geeignet.
- `incrementHits()` schreibt bei jedem Redirect in die Datei — bei sehr hohem Traffic ist APCu als Hit-Puffer empfehlenswert.
