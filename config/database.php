<?php
// Direct-access guard: this file must only be loaded via require/include, never via HTTP.
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}
// Solapur. Mail timestamps, logs, and date() follow India Standard Time.
date_default_timezone_set('Asia/Kolkata');
// Admin-only DB + session bootstrap (no frontend dependencies).

// SECURITY: fail-closed env loading. No fail-open defaults for credentials.
//  - Rotate JWT_SECRET + DB_PASS before prod; generate with bin2hex(random_bytes(32)).
//  - If required keys are missing/placeholder, we log server-side and die with a
//    GENERIC message (no secret/DSN leak). Local dev keeps working because
//    config/env.php ships all keys; only a missing/misconfigured env dies here.
$_env = [];
$_envFile = __DIR__ . '/env.php';
if (is_readable($_envFile)) {
    $_tmp = require $_envFile;
    if (is_array($_tmp)) {
        $_env = $_tmp;
    }
}

// Required keys: must be present and non-empty (except DB_PASS which may be ''
// locally but the KEY must exist — distinguishes "explicitly empty" from "missing").
$_missing = [];
foreach (['DB_HOST', 'DB_NAME', 'DB_USER'] as $_k) {
    if (!isset($_env[$_k]) || trim((string)$_env[$_k]) === '') {
        $_missing[] = $_k;
    }
}
if (!array_key_exists('DB_PASS', $_env)) {
    $_missing[] = 'DB_PASS';
}
// JWT_SECRET must exist and be non-empty. Placeholder values are allowed to keep
// local dev working, but log a loud warning so prod rotation is not forgotten.
if (!isset($_env['JWT_SECRET']) || trim((string)$_env['JWT_SECRET']) === '') {
    $_missing[] = 'JWT_SECRET';
}
if ($_missing) {
    error_log('HCC config error: missing/invalid env keys: ' . implode(',', $_missing));
    http_response_code(500);
    // Generic on purpose — never echo which key or its value.
    die('A configuration error occurred. Please try again later.');
}
// Warn (no die, to keep local dev working) if shipped placeholder secrets are still in use.
if (isset($_env['JWT_SECRET']) && strpos((string)$_env['JWT_SECRET'], 'CHANGE_ME') !== false) {
    error_log('HCC SECURITY WARNING: JWT_SECRET still uses the shipped CHANGE_ME placeholder. Rotate before prod: php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"');
}

define('DB_HOST', $_env['DB_HOST']);
define('DB_NAME', $_env['DB_NAME']);
define('DB_USER', $_env['DB_USER']);
define('DB_PASS', $_env['DB_PASS']);
define('DB_CHARSET', $_env['DB_CHARSET'] ?? 'utf8mb4');
$dbSocket = $_env['DB_SOCKET'] ?? '/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock';
define('DB_SOCKET', $dbSocket);
define('JWT_SECRET', $_env['JWT_SECRET']);

define('BASE_URL', '/hcc/');

function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $options = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        $candidates = [];
        if (defined('DB_SOCKET') && DB_SOCKET) {
            $candidates[] = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET . ";unix_socket=" . DB_SOCKET;
        }
        foreach (['/Applications/XAMPP/xamppfiles/var/mysql/mysql.sock', '/tmp/mysql.sock'] as $sock) {
            if (!defined('DB_SOCKET') || $sock !== DB_SOCKET) {
                $candidates[] = "mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=" . DB_CHARSET . ";unix_socket=" . $sock;
            }
        }
        $candidates[] = "mysql:host=127.0.0.1;port=3306;dbname=" . DB_NAME . ";charset=" . DB_CHARSET;

        $lastError = '';
        foreach ($candidates as $dsn) {
            try {
                $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
                break;
            } catch (PDOException $e) {
                $lastError = $e->getMessage();
                $pdo = null;
            }
        }
        if ($pdo === null) {
            error_log('Database connection failed: ' . $lastError);
            die("A database error occurred. Please try again later.");
        }
    }
    return $pdo;
}

$isHttps = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 80) == 443);

// Explicit dev-host allowlist (no substring sniffing): localhost, 127.0.0.1, ::1 only.
$rawHost = $_SERVER['HTTP_HOST'] ?? '';
$hostOnly = strtolower(trim((string)$rawHost));
if ($hostOnly !== '' && $hostOnly[0] === '[') {
    $bracketEnd = strpos($hostOnly, ']');
    $hostOnly = $bracketEnd !== false ? substr($hostOnly, 1, $bracketEnd - 1) : trim($hostOnly, '[]');
} else {
    // Strip :port only when a single colon is present (preserves bare ::1).
    if (substr_count($hostOnly, ':') === 1) {
        $hostOnly = explode(':', $hostOnly, 2)[0];
    }
    $hostOnly = trim($hostOnly);
}
$isDev = in_array($hostOnly, ['localhost', '127.0.0.1', '::1'], true);

if (session_status() === PHP_SESSION_NONE) {
    ini_set('session.cookie_httponly', 1);
    ini_set('session.use_strict_mode', 1);
    ini_set('session.use_only_cookies', 1);
    ini_set('session.cookie_samesite', 'Strict');
    // Secure flag: HTTPS => 1. Dev over plain HTTP keeps 0 so localhost login works;
    // prod (non-dev) always forces 1 — prod must be served over HTTPS.
    $cookieSecure = $isHttps ? 1 : ($isDev ? 0 : 1);
    if (!$isDev && $isHttps && !headers_sent()) {
        // NOTE (HSTS): enforce HTTPS for 1 year incl. subdomains. Keep only when prod is HTTPS-only.
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains', false);
    }
    ini_set('session.cookie_secure', $cookieSecure);
    // NOTE: directive is session.gc_maxlifetime (the old 'gc_max_lifetime' typo
    // silently no-op'd, leaving GC at the 1440s default). Align with the 1800s idle timeout.
    ini_set('session.gc_maxlifetime', 1800);
    session_start();
    if (empty($_SESSION['initiated'])) {
        session_regenerate_id(true);
        $_SESSION['initiated'] = true;
        $_SESSION['last_regeneration'] = time();
    }
    // Periodic session ID rotation every 15 min to limit fixation window.
    if (!empty($_SESSION['initiated']) && (time() - (int)($_SESSION['last_regeneration'] ?? 0)) > 900) {
        session_regenerate_id(true);
        $_SESSION['last_regeneration'] = time();
    }
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    if (!empty($_SESSION['admin_id'])) {
        $lastActivity = $_SESSION['last_activity'] ?? 0;
        if ($lastActivity > 0 && (time() - $lastActivity) > 1800) {
            // Idle timeout: wipe session data + delete cookie (SameSite=Strict) then destroy.
            $_SESSION = [];
            if (ini_get('session.use_cookies')) {
                $idleParams = session_get_cookie_params();
                if (PHP_VERSION_ID >= 70300) {
                    setcookie(session_name(), '', [
                        'expires' => time() - 42000,
                        'path' => $idleParams['path'] ?: '/',
                        'domain' => $idleParams['domain'],
                        'secure' => $idleParams['secure'],
                        'httponly' => $idleParams['httponly'],
                        'samesite' => 'Strict',
                    ]);
                } else {
                    setcookie(session_name(), '', time() - 42000, ($idleParams['path'] ?: '/') . '; samesite=Strict', $idleParams['domain'], $idleParams['secure'], $idleParams['httponly']);
                }
            }
            session_destroy();
            header('Location: login.php?err=session_timeout');
            exit;
        }
        $_SESSION['last_activity'] = time();
    }
}

if (!$isDev) {
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
    // PROD NOTE: logs/php-error.log stays here for XAMPP parity, but in production
    // this file MUST live outside the docroot (e.g. /var/log/hcc/php-error.log) so
    // error output is never web-reachable. Root + logs/.htaccess deny HTTP access
    // as defense-in-depth, but outside-docroot is the real fix.
    @mkdir(__DIR__ . '/../logs', 0755, true);
    ini_set('error_log', __DIR__ . '/../logs/php-error.log');
} else {
    // Dev only: keep errors visible locally; prod path above always silences display + logs.
    ini_set('display_errors', 1);
}
error_reporting(E_ALL & ~E_DEPRECATED);
