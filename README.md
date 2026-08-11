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

Die Konfiguration ist zweigeteilt:

| Datei | Im Repository | Zweck |
|---|---|---|
| `config.php` | ja | Vorgaben für alle Installationen |
| `config.local.php` | **nein** | Serverspezifisches, allen voran der Passwort-Hash |

`config.php` lädt `config.local.php`, falls vorhanden. Jeder dort definierte Wert
gewinnt. Ohne diese Trennung würde jedes Deployment den Hash des Servers durch
den leeren Standard ersetzen und die Installation aussperren — `config.local.php`
ist deshalb von `.gitignore` und vom rsync-Ausschluss gedeckt.

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
# Hash erzeugen und in config.php eintragen
php -r "echo password_hash('deinPasswort', PASSWORD_BCRYPT);"
```

Den ausgegebenen Hash in `config.local.php` bei `ADMIN_PASSWORD_HASH` eintragen.  
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

`verwalten.php` listet alle Kurzlinks mit Slug, Ziel-URL, Klick-Zähler und
Erstelldatum — neueste zuerst. Jeder Eintrag lässt sich per „Löschen" entfernen
(mit Bestätigungsdialog). Erreichbar über den Link unter dem Formular.

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
| **Passwortschutz** | HTTP Basic Auth via `requireAuth()`, bcrypt-Hash in `config.php` |
| **Fail-closed** | Fehlt der Hash, sperrt `requireAuth()` den Zugang (HTTP 503) statt ihn zu öffnen — Ausnahme nur über Loopback |
| **Post/Redirect/Get** | Erfolg und Fehler laufen über einen 303-Redirect; ein Reload sendet nichts erneut ab |
| **Kein Dritt-CDN** | Schriften selbst gehostet, CSP ohne externe Hosts (`default-src 'none'`); der QR-Dienst wird erst auf Klick kontaktiert |
| **CSP ohne `unsafe-inline`** | Styles und Skripte liegen in eigenen Dateien (`style-src 'self'`, `script-src 'self'`) |
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

## Deployment

Produktion ist **https://url.nozilla.net**. Jeder Push auf `main` geht dorthin —
aber erst, nachdem die CI für denselben Commit grün ist
(`.github/workflows/deploy-server.yml`). Übertragen wird per `rsync` über SSH,
genau der Stand, den die CI geprüft hat. Manuell startbar über den Actions-Tab.

### Einmalige Einrichtung

> **Die Secrets werden nicht auf der Kommandozeile gesetzt**, sondern auf
> github.com unter Settings → Secrets and variables → Actions → *New repository
> secret*. Terminal bzw. PowerShell brauchst du nur, um den Schlüssel zu
> erzeugen und auf den Server zu bringen. (Wer lieber alles im Terminal macht,
> findet unten die Variante mit der GitHub CLI.)

**1. SSH-Schlüsselpaar für den Deploy erzeugen** — eigener Schlüssel nur für
diesen Zweck, **ohne Passphrase**: eine GitHub Action kann keine eintippen.

<details open>
<summary><b>Windows 11 (PowerShell)</b></summary>

OpenSSH ist in Windows 11 enthalten. Prüfen mit `ssh -V`; fehlt es, einmalig als
Administrator `Add-WindowsCapability -Online -Name OpenSSH.Client~~~~0.0.1.0`.

```powershell
ssh-keygen -t ed25519 -C "github-deploy-redirecter" -f "$env:USERPROFILE\.ssh\redirecter_deploy"
```

Bei der Frage nach der Passphrase **zweimal Enter** drücken. (`-N ""` funktioniert
in PowerShell nicht zuverlässig, weil leere Anführungszeichen verschluckt werden.)

Öffentlichen Teil auf dem Server hinterlegen — `ssh-copy-id` gibt es unter
Windows nicht, deshalb direkt anhängen:

```powershell
$pub = (Get-Content "$env:USERPROFILE\.ssh\redirecter_deploy.pub" -Raw).Trim()
ssh benutzer@url.nozilla.net "mkdir -p ~/.ssh && chmod 700 ~/.ssh && echo '$pub' >> ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys"
```

Prüfen, ob Anmeldung per Schlüssel klappt **und** `rsync` auf dem Server liegt:

```powershell
ssh -i "$env:USERPROFILE\.ssh\redirecter_deploy" benutzer@url.nozilla.net "echo Login-OK; rsync --version | Select-Object -First 1"
```

Der private Schlüssel für das Secret `SSH_KEY` — komplett, inklusive der
`BEGIN`/`END`-Zeilen:

```powershell
Get-Content "$env:USERPROFILE\.ssh\redirecter_deploy" -Raw | Set-Clipboard
```

</details>

<details>
<summary><b>Linux / macOS</b></summary>

```bash
ssh-keygen -t ed25519 -C "github-deploy-redirecter" -f ~/.ssh/redirecter_deploy -N ""
ssh-copy-id -i ~/.ssh/redirecter_deploy.pub benutzer@url.nozilla.net
ssh -i ~/.ssh/redirecter_deploy benutzer@url.nozilla.net 'echo Login-OK; rsync --version | head -1'
cat ~/.ssh/redirecter_deploy   # Inhalt für das Secret SSH_KEY
```

</details>

**2. Secrets im Repository anlegen** — Settings → Secrets and variables → Actions:

| Secret | Pflicht | Beispiel |
|---|---|---|
| `SSH_HOST` | ja | `url.nozilla.net` |
| `SSH_USER` | ja | dein SSH-Benutzer |
| `SSH_KEY` | ja | kompletter Inhalt von `~/.ssh/redirecter_deploy` (der **private** Teil) |
| `DEPLOY_PATH` | ja | z. B. `/var/www/vhosts/nozilla.net/url.nozilla.net` |
| `SSH_PORT` | nein | nur bei abweichendem Port (Standard 22) |
| `SSH_KNOWN_HOSTS` | nein | Ausgabe von `ssh-keyscan url.nozilla.net` |

Ohne `SSH_KNOWN_HOSTS` übernimmt der Workflow den Host-Key beim ersten Kontakt
ungeprüft. Das Secret zu setzen pinnt ihn und schließt eine Man-in-the-Middle-
Lücke zwischen Runner und Server:

```powershell
ssh-keyscan url.nozilla.net | Set-Clipboard      # Windows
ssh-keyscan url.nozilla.net                      # Linux/macOS
```

`DEPLOY_PATH` ist das Web-Verzeichnis der Domain — bei Plesk üblicherweise
`httpdocs`. Finden lässt es sich so:

```powershell
ssh benutzer@url.nozilla.net "ls -d ~/httpdocs ~/public_html /var/www/vhosts/*/url.nozilla.net 2>/dev/null; pwd"
```

<details>
<summary>Alternative: Secrets über die GitHub CLI setzen</summary>

Mit installiertem [`gh`](https://cli.github.com/) geht es auch ohne Browser.
PowerShell kennt keine `<`-Eingabeumleitung, deshalb per Pipe:

```powershell
gh auth login
gh secret set SSH_HOST    --body "url.nozilla.net"
gh secret set SSH_USER    --body "deinBenutzer"
gh secret set DEPLOY_PATH --body "/var/www/vhosts/nozilla.net/url.nozilla.net"
Get-Content "$env:USERPROFILE\.ssh\redirecter_deploy" -Raw | gh secret set SSH_KEY
ssh-keyscan url.nozilla.net | gh secret set SSH_KNOWN_HOSTS
gh secret list
```

</details>

**3. `config.local.php` auf dem Server anlegen** — siehe [Konfiguration](#konfiguration).
Ohne Hash antwortet die Seite nach dem Deploy mit HTTP 503.

**4. Deploy auslösen** — Actions → *Deploy auf url.nozilla.net* → *Run workflow*.
Fehlt ein Secret, bricht der Lauf gleich im ersten Schritt ab und nennt die
fehlenden Namen; übertragen wird dann nichts.

### Was nicht übertragen wird

`--delete` räumt Dateien mit weg, die im Repository verschwunden sind. Diese
Ausschlüsse sind deshalb sicherheitsrelevant, nicht kosmetisch:

| Ausgespart | Grund |
|---|---|
| `storage/data.json` | der Datenbestand — läge sonst nach jedem Deploy leer da |
| `storage/data.json.lock` · `.tmp.*` | Laufzeitdateien |
| `config.local.php` | der Passwort-Hash |
| `.git/` · `.github/` · `.gitignore` | gehört nicht auf den Webserver |
| `docs/` · `README.md` | nur für GitHub Pages bzw. Leser |

Der Workflow legt `storage/` und eine leere `data.json` an, falls sie fehlen,
und setzt die Schreibrechte. Ein erster Deploy auf ein leeres Verzeichnis
funktioniert damit ohne Vorarbeit. Danach prüft er, ob die Seite antwortet, und
weist auf einen fehlenden Passwort-Hash hin (HTTP 503).

**Voraussetzung auf dem Server:** SSH-Zugang und `rsync`. Fehlt `rsync`, bricht
der Deploy mit `rsync: command not found` ab.

Die zweite Pipeline (`deploy.yml`) veröffentlicht `docs/` als Vorschauseite auf
GitHub Pages und hat mit dem Server nichts zu tun.

## CI

Die Pipeline läuft bei jedem Push auf `main` und bei Pull Requests.

| Schritt | Was wird geprüft |
|---|---|
| **PHP-Syntax** | `php -l` für alle `*.php`-Dateien |
| **data.json** | Datei muss gültiges JSON sein |
| **.htaccess** | `RewriteEngine`, `Options -Indexes` und Datei-Schutz vorhanden |
| **storage/.htaccess** | `Require all denied` — kein HTTP-Direktzugriff auf Datenspeicher |
| **Pflichtdateien** | Alle Kerndateien inkl. Schriften und Stylesheet müssen committed sein |
| **Kein Dritt-CDN** | Keine Referenz auf `fonts.googleapis.com` / `fonts.gstatic.com` |
| **Keine inline-Styles** | Kein `style="…"` — die CSP (`style-src 'self'`) würde es verwerfen |
| **Fail-closed** | `requireAuth()` lässt ohne Hash keine Fremd-IP durch |
| **Dateischutz** | `.htaccess` sperrt `config.php`, `config.local.php` und `functions.php` |
| **Kein Hash im Repo** | `config.local.php` ist weder eingecheckt noch eincheckbar |

Lokal testen:

```bash
# PHP-Syntax aller Dateien prüfen
for f in *.php; do php -l "$f"; done

# JSON validieren
python3 -c "import json; json.load(open('storage/data.json'))"

# Fail-closed-Verhalten prüfen (darf nichts ausgeben ausser dem Setup-Hinweis)
php -r '$_SERVER["REMOTE_ADDR"]="203.0.113.7";
        require "config.php"; require "functions.php"; requireAuth();'
```

## Einschränkungen

- Für sehr hohe Gleichzeitigkeit (viele hundert Requests/Sekunde) ist eine Datenbank besser geeignet.
- `incrementHits()` schreibt bei jedem Redirect in die Datei — bei sehr hohem Traffic ist APCu als Hit-Puffer empfehlenswert.
