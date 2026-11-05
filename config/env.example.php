<?php
// Direct-access guard: this file must only be loaded via require/include, never via HTTP.
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

/**
 * Environment Configuration — TEMPLATE for HCC.
 *
 * SETUP: copy this file to config/env.php in the same directory and fill in
 * the real values. config/env.php is gitignored — never commit real secrets.
 *
 * SECURITY — ROTATE BEFORE PROD:
 *  - JWT_SECRET: MUST be replaced with a strong random string, e.g.:
 *      php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"
 *    The placeholder 'CHANGE_ME_...' value below is intentionally rejected as
 *    invalid by config/database.php (fail-closed) — the app will not run until
 *    a real secret is set.
 *  - DB_PASS: MUST be a strong, unique password in production. Generate with
 *    `openssl rand -base64 24` (or a password manager).
 */

return [
    'DB_HOST'     => 'localhost',
    'DB_NAME'     => 'hcc_admin',
    'DB_USER'     => 'hcc_app',
    'DB_PASS'     => '',                       // set a strong password
    'DB_CHARSET'  => 'utf8mb4',
    'DB_SOCKET'   => '',                       // macOS XAMPP: /Applications/XAMPP/xamppfiles/var/mysql/mysql.sock (leave '' on Windows or if using TCP)
    'JWT_SECRET'  => 'CHANGE_ME_php_-r_echo_bin2hex_random_bytes_32_EOL', // fail-closed until replaced
    'WHATSAPP_BRIDGE_SECRET' => '', // bridge .env must use the same value
    'CORS_ORIGIN' => 'http://localhost',
];
