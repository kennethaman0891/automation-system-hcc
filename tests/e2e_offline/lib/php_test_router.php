<?php
/**
 * Offline test router for: php -S 127.0.0.1:9080 tests/e2e_offline/lib/php_test_router.php
 *
 * - Forces WA_TEST_MODE / hcc_wa_test via process env (cli-server only — see database.php).
 * - Seeds a real admin session so APIs do not need qa_test / X-Admin-Test.
 * - NEVER reachable from production Apache.
 */
putenv('WA_TEST_MODE=1');
putenv('HCC_DB_NAME=hcc_wa_test');
$_ENV['WA_TEST_MODE'] = '1';
$_ENV['HCC_DB_NAME'] = 'hcc_wa_test';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
if (empty($_SESSION['admin_id'])) {
    $_SESSION['admin_id'] = 1;
    $_SESSION['admin_name'] = 'E2E Test Admin';
    $_SESSION['admin_role'] = 'admin';
}

$root = dirname(__DIR__, 3); // .../hcc
$uri = urldecode(parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/');
$file = $root . $uri;
if ($uri !== '/' && is_file($file)) {
    if (substr($file, -4) === '.php') {
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
