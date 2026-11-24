<?php
// Emits the signed-in user's text size and profile photo rules.
// Include this from <head> on admin screens. Print sheets do not include it.
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}
require_once __DIR__ . '/../../includes/user_account.php';
hccAccountHead();
