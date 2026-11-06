<?php
require_once __DIR__ . '/../includes/functions.php';
if (PHP_SAPI !== 'cli') {
    require_once __DIR__ . '/auth_check.php';
    if (empty($_SESSION['admin_id'])) { http_response_code(401); exit('Unauthorized'); }
}
$db = getDB();
$cols = $db->query('SHOW COLUMNS FROM admission_ai_review_drafts')->fetchAll(PDO::FETCH_COLUMN);
$add = [
    'analysis_engine' => "ALTER TABLE admission_ai_review_drafts ADD COLUMN analysis_engine VARCHAR(20) NULL DEFAULT 'local' AFTER model_used",
    'analysis_provider' => "ALTER TABLE admission_ai_review_drafts ADD COLUMN analysis_provider VARCHAR(40) NULL DEFAULT NULL AFTER analysis_engine",
    'analysis_model' => "ALTER TABLE admission_ai_review_drafts ADD COLUMN analysis_model VARCHAR(120) NULL DEFAULT NULL AFTER analysis_provider",
    'analysis_endpoint_host' => "ALTER TABLE admission_ai_review_drafts ADD COLUMN analysis_endpoint_host VARCHAR(255) NULL DEFAULT NULL AFTER analysis_model",
];
foreach ($add as $name => $sql) {
    if (in_array($name, $cols, true)) { echo "skip $name\n"; continue; }
    $db->exec($sql);
    echo "added $name\n";
}
echo "OK\n";
