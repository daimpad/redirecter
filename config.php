<?php
declare(strict_types=1);

/**
 * Zentrale Konfiguration.
 *
 * Serverspezifische Werte — allen voran ADMIN_PASSWORD_HASH — gehören nicht ins
 * Repository. Sie stehen in config.local.php, die weder eingecheckt noch vom
 * Deploy angefasst wird. Jeder dort definierte Wert gewinnt gegen die Vorgaben
 * unten; ohne diese Trennung würde jedes Deployment den Hash des Servers durch
 * den leeren Standard ersetzen und die Installation aussperren.
 *
 * Vorlage: config.local.example.php
 */

$localConfig = __DIR__ . '/config.local.php';
if (is_file($localConfig)) {
    require_once $localConfig;
}

// Base-URL der Installation — kein abschließender Slash
defined('BASE_URL') || define('BASE_URL', 'https://url.nozilla.net');

// Pfad zur JSON-Datendatei
defined('DATA_FILE') || define('DATA_FILE', __DIR__ . '/storage/data.json');

// Slug-Einstellungen
defined('SLUG_MAX_LEN')    || define('SLUG_MAX_LEN', 64);
defined('RANDOM_SLUG_LEN') || define('RANDOM_SLUG_LEN', 6);

// Rate Limiting für Link-Erstellung (Anfragen pro Minute pro IP, 0 = deaktiviert)
defined('RATE_LIMIT_MAX') || define('RATE_LIMIT_MAX', 10);

// Admin-Zugangsdaten für HTTP Basic Auth
// Hash erzeugen: php -r "echo password_hash('deinPasswort', PASSWORD_BCRYPT);"
// Leer = Formular und Verwaltung antworten mit HTTP 503
defined('ADMIN_USER')          || define('ADMIN_USER', 'admin');
defined('ADMIN_PASSWORD_HASH') || define('ADMIN_PASSWORD_HASH', '');
