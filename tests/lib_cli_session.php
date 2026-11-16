<?php
/**
 * CLI-only Test Session Bootstrapper
 * Creates a real authenticated admin session inside PHP's active session save
 * directory (php-fpm TMPDIR + XAMPP temp) so live HTTP tests run without
 * insecure HTTP auth-bypass flags.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function hccCandidateSessionDirs(): array {
    $candidates = [];
    if (getenv('HCC_SESSION_DIR')) {
        $candidates[] = (string)getenv('HCC_SESSION_DIR');
    }
    $iniPath = (string)ini_get('session.save_path');
    if ($iniPath !== '') {
        $candidates[] = $iniPath;
    }
    $candidates[] = sys_get_temp_dir();
    if (PHP_OS_FAMILY === 'Windows') {
        $candidates[] = 'C:\\xampp\\tmp';
    } else {
        $candidates[] = '/tmp';
        $candidates[] = '/Applications/XAMPP/xamppfiles/temp';
        $candidates[] = '/opt/lampp/temp';
    }

    $valid = [];
    foreach ($candidates as $dir) {
        $norm = rtrim($dir, '/\\');
        if ($norm !== '' && is_dir($norm) && is_writable($norm) && !in_array($norm, $valid, true)) {
            $valid[] = $norm;
        }
    }
    return $valid ?: [sys_get_temp_dir()];
}

function hccCreateCliAdminSession(string $cookieJarPath = '', string $host = '127.0.0.1'): array {
    $dirs = hccCandidateSessionDirs();
    $primaryDir = $dirs[0];
    // 26 hex chars matches both XAMPP/Homebrew PHP 8.2 (sid_length=26, 5-bit) and 4-bit hex
    $sid = bin2hex(random_bytes(13));
    $csrf = bin2hex(random_bytes(32));

    if (session_status() === PHP_SESSION_ACTIVE) {
        session_write_close();
    }

    $prevSavePath = session_save_path();
    $prevStrict = ini_get('session.use_strict_mode');
    ini_set('session.use_strict_mode', '0');
    session_save_path($primaryDir);
    session_id($sid);
    @session_start();
    $_SESSION['admin_id'] = 1;
    $_SESSION['admin_name'] = 'Administrator';
    $_SESSION['admin_role'] = 'admin';
    $_SESSION['initiated'] = true;
    $_SESSION['last_regeneration'] = time();
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf_token'] = $csrf;
    session_write_close();
    if ($prevSavePath !== '') {
        @session_save_path($prevSavePath);
    }
    if ($prevStrict !== false) {
        @ini_set('session.use_strict_mode', (string)$prevStrict);
    }

    $primaryFile = $primaryDir . DIRECTORY_SEPARATOR . 'sess_' . $sid;
    $createdFiles = [];
    if (is_file($primaryFile)) {
        @chmod($primaryFile, 0666);
        $createdFiles[] = $primaryFile;
        $raw = @file_get_contents($primaryFile);
        if ($raw !== false) {
            foreach (array_slice($dirs, 1) as $extraDir) {
                $target = $extraDir . DIRECTORY_SEPARATOR . 'sess_' . $sid;
                if (@file_put_contents($target, $raw) !== false) {
                    @chmod($target, 0666);
                    $createdFiles[] = $target;
                }
            }
        }
    }

    if ($cookieJarPath !== '') {
        $exp = time() + 3600;
        $line = "# Netscape HTTP Cookie File\n{$host}\tFALSE\t/\tFALSE\t{$exp}\tPHPSESSID\t{$sid}\n";
        file_put_contents($cookieJarPath, $line);
    }

    return [
        'session_id' => $sid,
        'session_file' => $primaryFile,
        'session_files' => $createdFiles,
        'csrf_token' => $csrf,
        'cookie_header' => 'PHPSESSID=' . $sid,
    ];
}

function hccCleanupCliSession(array $sess): void {
    $files = $sess['session_files'] ?? [];
    if (!empty($sess['session_file'])) {
        $files[] = $sess['session_file'];
    }
    foreach (array_unique($files) as $f) {
        if (is_string($f) && $f !== '' && is_file($f)) {
            @unlink($f);
        }
    }
    if (!empty($sess['server_pid'])) {
        $pid = (int)$sess['server_pid'];
        if ($pid > 0) {
            if (PHP_OS_FAMILY === 'Windows') {
                @exec("taskkill /F /PID {$pid} 2>NUL");
            } else {
                @exec("kill {$pid} 2>/dev/null");
            }
        }
    }
}

/**
 * Resolves the admin API base URL for CLI E2E tests:
 * 1) Tries the provided Apache URL with our CLI-seeded session cookie.
 * 2) If Apache/php-fpm isolates session TMPDIR (401), spins up an ephemeral
 *    local `php -S` server running tests/lib_cli_router.php against the same DB.
 */
function hccResolveAdminBase(string $preferredBase, array &$cliSess, string $cookieJarPath = ''): string {
    $ch = curl_init(rtrim($preferredBase, '/') . '/api_admission_enquiry.php?action=csrf');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_COOKIE => $cliSess['cookie_header'] ?? '',
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($code === 200) {
        return rtrim($preferredBase, '/');
    }

    // Fallback: start ephemeral PHP built-in server for CLI tests
    $root = dirname(__DIR__);
    $router = $root . DIRECTORY_SEPARATOR . 'tests' . DIRECTORY_SEPARATOR . 'lib_cli_router.php';
    $phpBin = PHP_BINARY ?: 'php';
    $port = 19080 + random_int(1, 500);
    $log = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hcc_cli_srv_' . $port . '.log';

    if (PHP_OS_FAMILY === 'Windows') {
        $cmd = 'start /B "" ' . escapeshellarg($phpBin) . " -S 127.0.0.1:{$port} " . escapeshellarg($router) . ' > ' . escapeshellarg($log) . ' 2>&1';
        @pclose(@popen($cmd, 'r'));
    } else {
        $cmd = 'nohup ' . escapeshellarg($phpBin) . " -S 127.0.0.1:{$port} " . escapeshellarg($router) . ' > ' . escapeshellarg($log) . ' 2>&1 & echo $!';
        $pidOut = trim((string)@shell_exec($cmd));
        if (ctype_digit($pidOut)) {
            $cliSess['server_pid'] = (int)$pidOut;
        }
    }

    $ephemeralBase = "http://127.0.0.1:{$port}/admin";
    for ($i = 0; $i < 25; $i++) {
        usleep(120000);
        $probe = curl_init($ephemeralBase . '/api_admission_enquiry.php?action=csrf');
        curl_setopt_array($probe, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 2,
            CURLOPT_COOKIE => $cliSess['cookie_header'] ?? '',
            CURLOPT_COOKIEJAR => $cookieJarPath ?: '/dev/null',
        ]);
        $pRaw = curl_exec($probe);
        $pCode = (int)curl_getinfo($probe, CURLINFO_HTTP_CODE);
        curl_close($probe);
        if ($pCode === 200) {
            $pJson = json_decode((string)$pRaw, true);
            if (!empty($pJson['csrf_token'])) {
                $cliSess['csrf_token'] = (string)$pJson['csrf_token'];
            }
            return $ephemeralBase;
        }
    }
    return rtrim($preferredBase, '/');
}
