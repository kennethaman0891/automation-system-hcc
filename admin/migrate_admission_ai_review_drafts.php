<?php
/**
 * Apply admission_ai_review_drafts table (additive).
 * CLI: php admin/migrate_admission_ai_review_drafts.php
 * Web: admin session required.
 */
require_once __DIR__ . '/../includes/functions.php';
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/auth_check.php';
    if (empty($_SESSION['admin_id'])) {
        http_response_code(401);
        exit('Unauthorized');
    }
}
$db = getDB();
$sql = file_get_contents(__DIR__ . '/../data/migrations/create_admission_ai_review_drafts.sql');
// Strip comments-only lines that aren't SQL
$db->exec($sql);
echo "OK admission_ai_review_drafts ready on " . (defined('DB_NAME') ? DB_NAME : '?') . "\n";
