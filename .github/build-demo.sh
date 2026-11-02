#!/usr/bin/env bash
# Baut die Spielwiese für GitHub Pages aus index.php: rendert die Seite mit
# PHP und schaltet das Formular in den Demo-Modus, in dem assets/app.js die
# Antworten des Servers nachspielt. So gibt es das Markup nur einmal und die
# Spielwiese kann der echten Seite nicht davonlaufen.
#
#   .github/build-demo.sh [Zielordner]      Standard: _site
set -euo pipefail
cd "$(dirname "$0")/.."

out="${1:-_site}"
tmp="$(mktemp -d)"
trap 'rm -rf "$tmp"' EXIT

rm -rf "$out"
mkdir -p "$out" "$tmp/storage"
cp -r assets "$out/"
cp ./*.php "$tmp/"

# Wegwerf-Zugang, nur damit requireAuth() die Seite rendert
php -r 'printf("<?php\ndefine(\"ADMIN_PASSWORD_HASH\", %s);\n", var_export(password_hash("demo", PASSWORD_BCRYPT), true));' \
    > "$tmp/config.local.php"

( cd "$tmp" && php -r '
    $_SERVER["REQUEST_METHOD"] = "GET";
    $_SERVER["SCRIPT_NAME"]    = "/index.php";
    $_SERVER["PHP_AUTH_USER"]  = "admin";
    $_SERVER["PHP_AUTH_PW"]    = "demo";
    require "index.php";' ) > "$tmp/index.html"

python3 - "$tmp/index.html" "$out/index.html" <<'PY'
import re, sys
src, dst = sys.argv[1], sys.argv[2]
s = open(src, encoding='utf-8').read()

def rep(pattern, new):
    global s
    s, n = re.subn(pattern, new, s, count=1)
    if not n:
        sys.exit(f'build-demo: Muster nicht gefunden — index.php geändert? {pattern!r}')

rep(r'\s*<input type="hidden" name="csrf_token" value="[a-f0-9]+">', '')
rep(r'<form method="POST" action=""', '<form method="POST" action="" data-demo')
rep(r'(<div class="result-slot">)',
    '<div class="alert alert-info" role="note">\n'
    '            Spielwiese — hier wird nichts gespeichert. Der echte Redirecter\n'
    '            läuft auf dem eigenen Server; Quellcode im\n'
    '            <a href="https://github.com/daimpad/redirecter" rel="noopener">Repository</a>.\n'
    '        </div>\n\n        \\1')
rep(r'data-count="\d+">\d+</span> <span class="counter-label">[^<]*',
    'data-count="42">42</span> <span class="counter-label">Kurzlinks')
rep(r'\s*<p class="nav"><a href="verwalten.php">[^<]*</a></p>', '')
open(dst, 'w', encoding='utf-8').write(s)
PY

touch "$out/.nojekyll"
echo "Spielwiese gebaut: $out/index.html"
