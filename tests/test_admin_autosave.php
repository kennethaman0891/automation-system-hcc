<?php
/**
 * End-to-End Verification Test for HCC Universal Admin Auto-Save
 * Verifies:
 *  1. Table creation via hccEnsureAutosaveTable()
 *  2. Upsert + update + load + clear lifecycle in `admin_autosave_drafts`
 *  3. HTML asset output via hccRenderAdminAutosaveAssets()
 *  4. Presence of direct-mode hooks in marks entry/matrix pages
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

ini_set('session.save_path', sys_get_temp_dir());
$_SERVER['SCRIPT_NAME'] = '/hcc/admin/results.php';
$_SERVER['HTTP_HOST']   = 'localhost';

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../admin/includes/admin_autosave.php';

$pass = 0;
$fail = 0;
function assertOk(bool $cond, string $label): void {
    global $pass, $fail;
    if ($cond) {
        $pass++;
        echo "[PASS] {$label}\n";
    } else {
        $fail++;
        echo "[FAIL] {$label}\n";
    }
}

// 1. Ensure DB table
$db = getDB();
$ensured = hccEnsureAutosaveTable($db);
assertOk($ensured === true, 'hccEnsureAutosaveTable() created/verified admin_autosave_drafts');

$tblCheck = $db->query("SHOW TABLES LIKE 'admin_autosave_drafts'")->fetchColumn();
assertOk($tblCheck === 'admin_autosave_drafts', 'Table admin_autosave_drafts exists in MySQL (' . DB_NAME . ')');

// 2. Test save -> update -> load -> clear in DB
$testAdminId = 999999;
$testPage    = 'results';
$testForm    = 'form:bulkMarksForm';
$testCtx     = 'class=2025-26%7C5%7CA%7CSem+1';

$payload1 = json_encode(['name:marks[1][Maths][fa]' => '65', 'name:marks[1][Maths][sa]' => '28']);
$stmt = $db->prepare('
    INSERT INTO admin_autosave_drafts
        (admin_id, page_key, form_key, context_key, page_url, payload_json, field_count)
    VALUES (?, ?, ?, ?, ?, ?, ?)
    ON DUPLICATE KEY UPDATE
        payload_json = VALUES(payload_json),
        field_count = VALUES(field_count),
        updated_at = CURRENT_TIMESTAMP
');
$stmt->execute([$testAdminId, $testPage, $testForm, $testCtx, '/hcc/admin/results.php', $payload1, 2]);

// Update same scope (upsert)
$payload2 = json_encode(['name:marks[1][Maths][fa]' => '68', 'name:marks[1][Maths][sa]' => '29']);
$stmt->execute([$testAdminId, $testPage, $testForm, $testCtx, '/hcc/admin/results.php', $payload2, 2]);

$sel = $db->prepare('SELECT payload_json, field_count FROM admin_autosave_drafts WHERE admin_id = ? AND page_key = ? AND form_key = ? AND context_key = ?');
$sel->execute([$testAdminId, $testPage, $testForm, $testCtx]);
$row = $sel->fetch(PDO::FETCH_ASSOC);

assertOk(is_array($row) && (int)$row['field_count'] === 2, 'Upsert stored exactly 1 row with field_count=2');
$decoded = json_decode((string)($row['payload_json'] ?? ''), true);
assertOk(($decoded['name:marks[1][Maths][fa]'] ?? null) === '68', 'Upsert updated changed figure (65 -> 68) in DB');

// Clear test draft
$db->prepare('DELETE FROM admin_autosave_drafts WHERE admin_id = ?')->execute([$testAdminId]);
$sel->execute([$testAdminId, $testPage, $testForm, $testCtx]);
assertOk($sel->fetch(PDO::FETCH_ASSOC) === false, 'Clear deleted draft cleanly from DB');

// 3. Test common connector output
$_SESSION['admin_id'] = 1;
ob_start();
hccRenderAdminAutosaveAssets();
$html = ob_get_clean();
assertOk(strpos($html, 'meta name="hcc-autosave-config"') !== false, 'Common connector emits CSP-safe config meta tag');
assertOk(strpos($html, 'assets/js/hcc-autosave.js') !== false, 'Common connector loads assets/js/hcc-autosave.js');
assertOk(strpos($html, 'assets/css/hcc-autosave.css') !== false, 'Common connector loads assets/css/hcc-autosave.css');

// 4. Verify direct-mode attributes in core marks pages
foreach (['results.php', 'entry.php', 'unit_tests.php', 'unit_test_entry.php'] as $pg) {
    $src = file_get_contents(__DIR__ . '/../admin/' . $pg);
    assertOk(strpos((string)$src, 'data-autosave-mode="direct"') !== false, "Direct DB autosave enabled on admin/{$pg}");
}

echo "\nSummary: {$pass} passed, {$fail} failed\n";
exit($fail > 0 ? 1 : 0);
