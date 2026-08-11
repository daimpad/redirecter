<?php
declare(strict_types=1);

/**
 * Vorlage für config.local.php — die serverspezifische Konfiguration.
 *
 * Einrichtung auf dem Server (einmalig, nicht per Deploy):
 *
 *   cp config.local.example.php config.local.php
 *   php -r "echo password_hash('deinPasswort', PASSWORD_BCRYPT), PHP_EOL;"
 *   # Ausgabe unten bei ADMIN_PASSWORD_HASH eintragen
 *
 * Die Datei ist per .gitignore vom Repository und per rsync-Ausschluss vom
 * Deploy ausgenommen; sie überlebt also jedes Deployment. Der Webserver
 * blockiert den direkten HTTP-Zugriff darauf (siehe .htaccess).
 *
 * Hier nur eintragen, was vom Standard in config.php abweichen soll.
 */

// Pflicht — ohne Hash ist das Formular von außen mit HTTP 503 gesperrt
define('ADMIN_PASSWORD_HASH', '');

// Optional
// define('ADMIN_USER', 'admin');
// define('BASE_URL', 'https://url.nozilla.net');
// define('RATE_LIMIT_MAX', 10);
