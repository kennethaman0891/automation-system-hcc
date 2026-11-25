<?php
/**
 * HCC debug doctor — CLI only.
 * Run: php scripts/debug-doctor.php
 *
 * Checks PHP version/extensions, config, writable dirs, admin completeness,
 * and flags any forbidden runtime/session/secret artifacts.
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("Forbidden: run this script from the command line.\n");
}

$root = dirname(__DIR__);
$fail = 0;
$warn = 0;

function ok(string $msg): void   { echo "  [OK]   $msg\n"; }
function warn(string $msg): void { echo "  [WARN] $msg\n"; $GLOBALS['warn']++; }
function bad(string $msg): void  { echo "  [FAIL] $msg\n"; $GLOBALS['fail']++; }

echo "HCC DEBUG DOCTOR — $root\n\n";

// 1. PHP version.
echo "PHP\n";
if (version_compare(PHP_VERSION, '8.3.0', '>=')) {
    ok('version ' . PHP_VERSION . ' (>= 8.3)');
} else {
    bad('version ' . PHP_VERSION . ' is below required 8.3');
}

// 2. Extensions required by PhpSpreadsheet / the app.
echo "\nExtensions\n";
foreach (['pdo', 'pdo_mysql', 'mbstring', 'ctype', 'dom', 'fileinfo', 'iconv', 'libxml', 'simplexml', 'xml', 'xmlreader', 'xmlwriter', 'zip', 'zlib', 'gd', 'bcmath'] as $ext) {
    if (extension_loaded($ext)) {
        ok($ext);
    } else {
        bad("$ext is not loaded");
    }
}

// 3. Config.
echo "\nConfig\n";
$env = "$root/config/env.php";
if (!is_file($env)) {
    bad('config/env.php missing — copy config/env.example.php and set JWT_SECRET/DB credentials');
} else {
    ok('config/env.php present');
    $cfg = require $env;
    if (isset($cfg['JWT_SECRET']) && strpos((string)$cfg['JWT_SECRET'], 'CHANGE_ME') !== false) {
        bad('JWT_SECRET still uses the CHANGE_ME placeholder — run php -r "echo bin2hex(random_bytes(32)), PHP_EOL;"');
    } else {
        ok('JWT_SECRET is set (no CHANGE_ME placeholder)');
    }
    if (empty($cfg['DB_USER']) || empty($cfg['DB_NAME'])) {
        warn('DB_USER or DB_NAME is empty — database auth may fail');
    } else {
        ok('DB_USER/DB_NAME configured: ' . $cfg['DB_USER'] . ' / ' . $cfg['DB_NAME']);
    }
}

// 4. Writable dirs.
echo "\nWritable directories\n";
foreach (['logs', 'storage', 'storage/whatsapp', 'storage/whatsapp/media', 'storage/whatsapp/ipc'] as $dir) {
    $path = "$root/$dir";
    if (!is_dir($path)) {
        @mkdir($path, 0775, true);
    }
    if (is_dir($path) && is_writable($path)) {
        ok($dir);
    } else {
        bad("$dir is not writable");
    }
}

// 5. Admin completeness.
echo "\nAdmin files\n";
$manifest = "$root/MANIFEST-ADMIN.txt";
if (!is_file($manifest)) {
    bad('MANIFEST-ADMIN.txt missing');
} else {
    $rels = array_values(array_filter(array_map('trim', file($manifest, FILE_IGNORE_NEW_LINES)), 'strlen'));
    $missing = [];
    foreach ($rels as $rel) {
        if (!is_file("$root/$rel")) {
            $missing[] = $rel;
        }
    }
    if ($missing) {
        bad(count($missing) . ' admin file(s) missing: ' . implode(', ', array_slice($missing, 0, 5)));
    } else {
        ok(count($rels) . '/' . count($rels) . ' admin PHP files present');
    }
}

// 6. Forbidden artifacts. Skip known local runtime roots (ignored, never in the bundle).
echo "\nForbidden artifacts\n";
$skipRoots = [
    '.git/', 'vendor/', 'node_modules/', 'dist/', 'logs/',
    'storage/whatsapp/media/', 'storage/whatsapp/ipc/', 'assets/videos/',
    'automation/.env', 'assets/images/wa-promo-poster.jpg',
    '.whatsapp-session', 'automation/.whatsapp-session',
    '.playwright-mcp/', 'scripts/__pycache__/', 'tests/e2e_offline/results/',
];
$localIgnored = [];
foreach (['.whatsapp-session', 'automation/.whatsapp-session', 'automation/.whatsapp-session-test'] as $dir) {
    if (is_dir("$root/$dir")) {
        $localIgnored[] = $dir . '/';
    }
}
foreach (['automation/.env', 'assets/images/wa-promo-poster.jpg'] as $file) {
    if (is_file("$root/$file")) {
        $localIgnored[] = $file;
    }
}
if ($localIgnored) {
    warn('local ignored data present (excluded from bundle): ' . implode(', ', $localIgnored));
}
$sessionRoots = [];

$forbiddenPatterns = [
    '/\.mp4$/i', '/\.mov$/i', '/\.webm$/i',
    '/wa-promo-poster\.jpg$/i', '/\.env$/i', '/\.pem$/i', '/\.key$/i',
    '/\.sqlite$/i', '/\.db$/i', '/whatsapp-chat/i', '/chat.?export/i',
];
$found = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    $path = str_replace($root . DIRECTORY_SEPARATOR, '', $file->getPathname());
    $path = str_replace(DIRECTORY_SEPARATOR, '/', $path);
    $skip = false;
    foreach ($skipRoots as $sr) {
        if (strpos($path, $sr) === 0) { $skip = true; break; }
    }
    foreach ($sessionRoots as $sess) {
        if (strpos($path, $sess . '/') === 0) { $skip = true; break; }
    }
    if ($skip) { continue; }
    foreach ($forbiddenPatterns as $pattern) {
        if (preg_match($pattern, $path)) {
            $found[] = $path;
            break;
        }
    }
}
if ($found) {
    bad(count($found) . ' forbidden file(s) found: ' . implode(', ', array_slice($found, 0, 8)));
} else {
    ok('no forbidden media/secret/chat-export artifacts in installable files');
}

echo "\nSummary: $fail error(s), $warn warning(s)\n";
exit($fail > 0 ? 1 : 0);
