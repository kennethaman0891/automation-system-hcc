<?php
/**
 * HCC Admission Enquiry Management — Migration Runner
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $db = getDB();
    $sql = file_get_contents(__DIR__ . '/../data/migrations/create_admission_enquiry_tables.sql');
    $queries = array_filter(array_map('trim', explode(';', $sql)));
    foreach ($queries as $q) {
        if ($q !== '') {
            $db->exec($q);
        }
    }
    echo "✅ Admission Enquiry Management tables migrated successfully.\n";
} catch (Throwable $e) {
    http_response_code(500);
    echo "❌ Migration error: " . $e->getMessage() . "\n";
}
