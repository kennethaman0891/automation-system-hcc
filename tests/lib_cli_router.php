<?php
/**
 * CLI-only PHP built-in server router for running live E2E tests against hcc_admin
 * when Apache/php-fpm uses a private launchd TMPDIR that CLI cannot write sessions to.
 * NEVER reachable from Apache (only invoked by `php -S` from tests/lib_cli_session.php).
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli-server') {
    http_response_code(403);
    exit('cli-server only');
}

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['admin_id'])) {
    $_SESSION['admin_id'] = 1;
    $_SESSION['admin_name'] = 'Administrator';
    $_SESSION['admin_role'] = 'admin';
    $_SESSION['initiated'] = true;
    $_SESSION['last_regeneration'] = time();
    $_SESSION['last_activity'] = time();
}
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$root = dirname(__DIR__);
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/');
$uri = preg_replace('#^/hcc/#', '/', $uri);
$file = $root . $uri;

if ($uri !== '/' && is_file($file)) {
    if (strtolower(substr($file, -4)) === '.php') {
        $_SERVER['SCRIPT_NAME'] = $uri;
        $_SERVER['PHP_SELF'] = $uri;
        chdir(dirname($file));
        require $file;
        return true;
    }
    return false;
}

http_response_code(404);
header('Content-Type: text/plain');
echo 'Not found: ' . $uri;
return true;
