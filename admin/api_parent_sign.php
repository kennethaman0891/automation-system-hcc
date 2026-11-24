<?php
/* Toggle a single student's parent-signature confirmation.
 * POST: csrf_token, roll, sign (0|1), optional class_key (year|std|div|sem).
 * When class_key is supplied the update is scoped to that class only.
 * Returns JSON {ok: bool, error?: string, updated?: int}
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

header('Content-Type: application/json');

function psign_json($payload) {
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    psign_json(['ok' => false, 'error' => 'Method not allowed']);
}

if (!function_exists('verifyCSRFToken') || !verifyCSRFToken()) {
    http_response_code(403);
    psign_json(['ok' => false, 'error' => 'Security check failed']);
}

$roll     = trim((string)($_POST['roll'] ?? ''));
$sign     = ((int)($_POST['sign'] ?? 0)) === 1 ? 1 : 0;
$classKey = trim((string)($_POST['class_key'] ?? ''));
$table    = trim((string)($_POST['table'] ?? 'report_card'));
$sigRaw   = trim((string)($_POST['signature_data'] ?? ''));
$hasSigData = array_key_exists('signature_data', $_POST);
$sigData  = null;

if ($sigRaw !== '') {
    if (!preg_match('/^data:image\/png;base64,[A-Za-z0-9+\/=]+$/', $sigRaw) || strlen($sigRaw) > 524288) {
        http_response_code(422);
        psign_json(['ok' => false, 'error' => 'Invalid digital signature image format']);
    }
    $sigData = $sigRaw;
    $sign = 1;
}

if ($roll === '') {
    http_response_code(422);
    psign_json(['ok' => false, 'error' => 'Roll number required']);
}

if (!in_array($table, ['report_card', 'unit_test_results'], true)) {
    http_response_code(422);
    psign_json(['ok' => false, 'error' => 'Invalid target table']);
}

try {
    rcEnsureParentSignatureStorage();
    $db = getDB();

    if ($table === 'unit_test_results') {
        $year     = trim((string)($_POST['year'] ?? ''));
        $std      = trim((string)($_POST['std'] ?? ''));
        $division = trim((string)($_POST['division'] ?? ''));
        $testType = trim((string)($_POST['test_type'] ?? ''));
        if ($year !== '' && rcIsVaultLocked($year)) {
            http_response_code(423);
            psign_json(['ok' => false, 'error' => "Academic year {$year} is locked in the Records Archive."]);
        }
        if ($sign === 0) {
            $sql    = 'UPDATE unit_test_results SET parents_sign = ?, parent_signature_data = NULL WHERE roll_no = ?';
            $params = [0, $roll];
        } elseif ($hasSigData && $sigData !== null) {
            $sql    = 'UPDATE unit_test_results SET parents_sign = ?, parent_signature_data = ? WHERE roll_no = ?';
            $params = [1, $sigData, $roll];
        } else {
            $sql    = 'UPDATE unit_test_results SET parents_sign = ? WHERE roll_no = ?';
            $params = [$sign, $roll];
        }
        if ($year !== '')     { $sql .= ' AND academic_year = ?'; $params[] = $year; }
        if ($std !== '')      { $sql .= ' AND std = ?';           $params[] = $std; }
        if ($division !== '') { $sql .= ' AND division = ?';      $params[] = $division; }
        if ($testType !== '') { $sql .= ' AND test_type = ?';     $params[] = $testType; }
        $stmt = $db->prepare($sql);
        $stmt->execute($params);
        psign_json(['ok' => true, 'updated' => $stmt->rowCount(), 'sign' => $sign, 'signature_data' => $sign === 0 ? '' : ($sigData ?? '')]);
    }

    if ($sign === 0) {
        $sql    = 'UPDATE report_card SET parents_sign = ?, parent_signature_data = NULL WHERE roll_no = ?';
        $params = [0, $roll];
    } elseif ($hasSigData && $sigData !== null) {
        $sql    = 'UPDATE report_card SET parents_sign = ?, parent_signature_data = ? WHERE roll_no = ?';
        $params = [1, $sigData, $roll];
    } else {
        $sql    = 'UPDATE report_card SET parents_sign = ? WHERE roll_no = ?';
        $params = [$sign, $roll];
    }

    if ($classKey !== '') {
        $c = rcParseClassKey(urldecode($classKey));
        if (!empty($c['academic_year']) && rcIsVaultLocked($c['academic_year'], $c['semester'] ?? null)) {
            http_response_code(423);
            psign_json(['ok' => false, 'error' => "Academic year {$c['academic_year']} is locked in the Records Archive."]);
        }
        if (!empty($c['academic_year'])) { $sql .= ' AND academic_year = ?'; $params[] = $c['academic_year']; }
        if (!empty($c['std']))           { $sql .= ' AND std = ?';           $params[] = $c['std']; }
        if (!empty($c['division']))      { $sql .= ' AND division = ?';      $params[] = $c['division']; }
        if (!empty($c['semester']))      { $sql .= ' AND semester = ?';      $params[] = $c['semester']; }
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    psign_json(['ok' => true, 'updated' => $stmt->rowCount(), 'sign' => $sign, 'signature_data' => $sign === 0 ? '' : ($sigData ?? '')]);
} catch (\Throwable $e) {
    error_log('api_parent_sign failed: ' . $e->getMessage());
    http_response_code(500);
    psign_json(['ok' => false, 'error' => 'Could not update signature']);
}
