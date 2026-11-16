<?php
/**
 * HCC Automation System — Save Parent WhatsApp Number API
 *
 * Persists the parent mobile number typed in the WhatsApp modal against the
 * student, so it is prefilled on the next share. Accepts JSON:
 *   { "class_key": "2024-25|5|A|Sem 1", "roll": "01", "phone": "9876543210", "table": "report_card" }
 *
 * Response: { "success": true } | { "success": false, "error": "..." }
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

header('Content-Type: application/json');
header('Cache-Control: no-cache, no-store, must-revalidate');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid or missing JSON body']);
    exit;
}

if (empty($_SERVER['HTTP_X_CSRF_TOKEN']) && !empty($input['csrf_token'])) {
    $_POST['csrf_token'] = (string)$input['csrf_token'];
}
if (!verifyCSRFToken()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Security check failed']);
    exit;
}

$classKey = trim($input['class_key'] ?? '');
$roll     = trim($input['roll'] ?? '');
$phone    = trim($input['phone'] ?? '');
$table    = trim($input['table'] ?? 'report_card');

$allowedTables = ['report_card', 'unit_test_results'];
if (!in_array($table, $allowedTables, true)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid table']);
    exit;
}

if ($classKey === '' || $roll === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing class_key or roll']);
    exit;
}

// Normalize: digits only; accept 10-digit Indian mobile, 11-digit 0-prefixed, or 12-digit 91-prefixed
$digits = preg_replace('/\D+/', '', $phone);
if ($digits === '' || strlen($digits) < 10 || strlen($digits) > 13) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid phone number (need 10-13 digits)']);
    exit;
}

$parts = explode('|', urldecode($classKey));
if (count($parts) !== 4) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid class_key format']);
    exit;
}
list($year, $std, $div, $sem) = array_map('trim', $parts);

if (rcIsVaultLocked($year, $sem)) {
    http_response_code(423);
    echo json_encode(['success' => false, 'error' => "Academic year {$year} is locked in the Records Archive."]);
    exit;
}

try {
    $db = getDB();
    $primaryUpdated = 0;

    if ($table === 'report_card') {
        $stmt = $db->prepare('UPDATE report_card SET parent_phone=? WHERE academic_year=? AND std=? AND division=? AND semester=? AND roll_no=?');
        $stmt->execute([$digits, $year, $std, $div, $sem, $roll]);
        $primaryUpdated = $stmt->rowCount();
        // Propagate to unit_test_results for same student cohort
        $db->prepare('UPDATE unit_test_results SET parent_phone=? WHERE academic_year=? AND std=? AND division=? AND roll_no=?')
           ->execute([$digits, $year, $std, $div, $roll]);
    } else {
        $stmt = $db->prepare('UPDATE unit_test_results SET parent_phone=? WHERE academic_year=? AND std=? AND division=? AND roll_no=?');
        $stmt->execute([$digits, $year, $std, $div, $roll]);
        $primaryUpdated = $stmt->rowCount();
        // Propagate to report_card for same student cohort
        $db->prepare('UPDATE report_card SET parent_phone=? WHERE academic_year=? AND std=? AND division=? AND roll_no=?')
           ->execute([$digits, $year, $std, $div, $roll]);
    }

    // Propagate to students_master
    try {
        $db->prepare('UPDATE students_master SET parent_phone=? WHERE academic_year=? AND std=? AND division=? AND roll_no=?')
           ->execute([$digits, $year, $std, $div, (int)$roll]);
    } catch (Throwable $ignore) {
    }

    if ($primaryUpdated === 0) {
        // Check if student exists with identical number already (PDO rowCount=0 when unchanged)
        $chkSql = $table === 'report_card'
            ? 'SELECT 1 FROM report_card WHERE academic_year=? AND std=? AND division=? AND semester=? AND roll_no=? LIMIT 1'
            : 'SELECT 1 FROM unit_test_results WHERE academic_year=? AND std=? AND division=? AND roll_no=? LIMIT 1';
        $chk = $db->prepare($chkSql);
        $chk->execute($table === 'report_card' ? [$year, $std, $div, $sem, $roll] : [$year, $std, $div, $roll]);
        if (!$chk->fetchColumn()) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Student not found for this class/roll']);
            exit;
        }
    }
    echo json_encode(['success' => true, 'updated' => $primaryUpdated]);
} catch (Throwable $e) {
    error_log('api_save_phone: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Database error']);
}
