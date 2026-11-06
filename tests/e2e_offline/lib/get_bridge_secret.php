<?php
// Print bridge secret to stdout for test harness only (local offline).
$envFile = dirname(__DIR__, 3) . '/config/env.php';
$env = require $envFile;
$secret = $env['WHATSAPP_BRIDGE_SECRET'] ?? '';
if (!is_string($secret) || strlen($secret) < 16) {
    fwrite(STDERR, "NO_SECRET\n");
    exit(2);
}
echo $secret;
