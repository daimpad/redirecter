# URL-Shortener

Schlanker URL-Shortener in Vanilla PHP 8 — ohne Datenbank, ohne Framework.  
Datenspeicher ist eine lokale `data.json`.

## Voraussetzungen

| | |
|---|---|
| PHP | 8.0 oder neuer |
| Webserver | Apache mit `mod_rewrite` |
| Schreibrechte | `data.json` muss durch den Webserver-Prozess beschreibbar sein |

## Installation

```bash
# Repository klonen oder Dateien hochladen
git clone https://github.com/daimpad/redirecter.git /var/www/html/shortener
cd /var/www/html/shortener

# data.json beschreibbar machen
chmod 664 data.json
chown www-data:www-data data.json
```

Stelle sicher, dass `mod_rewrite` aktiviert und `AllowOverride All` für das Verzeichnis gesetzt ist:

```apacheconf
<Directory /var/www/html/shortener>
    AllowOverride All
</Directory>
```

## Dateistruktur

```
.
├── .htaccess      # Apache Rewrite-Regeln
├── index.php      # Formular + Speicher-Logik
├── redirect.php   # Slug → 301-Redirect
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

### Kurzlink aufrufen

```
https://deinedomain.de/mein-link
```

Der Server antwortet mit einem **HTTP 301**-Redirect zur hinterlegten Ziel-URL.

### data.json — Format

```json
{
    "gh": "https://github.com",
    "mein-link": "https://example.com/sehr/langer/pfad"
}
```

Einträge können auch manuell in dieser Datei gepflegt werden.

## Sicherheit

| Maßnahme | Details |
|---|---|
| **CSRF-Schutz** | Session-Token, verglichen mit `hash_equals()` (Timing-safe) |
| **Slug-Sanitizing** | Nur `[A-Za-z0-9_-]` erlaubt — Sonderzeichen werden entfernt |
| **URL-Validierung** | `filter_var(FILTER_VALIDATE_URL)` + Pflicht-Präfix `http://` oder `https://` |
| **XSS-Schutz** | Alle HTML-Ausgaben über `htmlspecialchars(..., ENT_QUOTES, 'UTF-8')` |
| **Race Conditions** | Kollisionsprüfung und Schreiben erfolgen innerhalb eines `flock(LOCK_EX)` |
| **Open Redirect** | Nur gespeicherte, validierte URLs werden als Redirect-Ziel verwendet |
| **Directory Listing** | `Options -Indexes` in `.htaccess` |
| **Sichere Header** | `X-Content-Type-Options: nosniff`, `X-Frame-Options: DENY` |

## Einschränkungen

- Für sehr hohe Gleichzeitigkeit (viele hundert Requests/Sekunde) ist eine Datenbank besser geeignet.
- Es gibt keine Admin-Oberfläche zum Löschen oder Auflisten von Links — das erfordert direkten Zugriff auf `data.json`.
