<?php
declare(strict_types=1);

// Base-URL der Installation — kein abschließender Slash
const BASE_URL = 'https://deinedomain.de';

// Pfad zur JSON-Datendatei
const DATA_FILE = __DIR__ . '/storage/data.json';

// Slug-Einstellungen
const SLUG_MAX_LEN    = 64;
const RANDOM_SLUG_LEN = 6;

// Admin-Zugangsdaten für HTTP Basic Auth
// Hash erzeugen: php -r "echo password_hash('deinPasswort', PASSWORD_BCRYPT);"
// Leer lassen = Auth deaktiviert (nur für lokale Entwicklung)
const ADMIN_USER          = 'admin';
const ADMIN_PASSWORD_HASH = '';
