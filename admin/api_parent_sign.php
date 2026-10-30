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

if ($roll === '') {
    http_response_code(422);
    psign_json(['ok' => false, 'error' => 'Roll number required']);
}

try {
    $db = getDB();

    $sql    = 'UPDATE report_card SET parents_sign = ? WHERE roll_no = ?';
    $params = [$sign, $roll];

    if ($classKey !== '') {
        $c = rcParseClassKey(urldecode($classKey));
        if (!empty($c['academic_year'])) { $sql .= ' AND academic_year = ?'; $params[] = $c['academic_year']; }
        if (!empty($c['std']))           { $sql .= ' AND std = ?';           $params[] = $c['std']; }
        if (!empty($c['division']))      { $sql .= ' AND division = ?';      $params[] = $c['division']; }
        if (!empty($c['semester']))      { $sql .= ' AND semester = ?';      $params[] = $c['semester']; }
    }

    $stmt = $db->prepare($sql);
    $stmt->execute($params);

    psign_json(['ok' => true, 'updated' => $stmt->rowCount(), 'sign' => $sign]);
} catch (\Throwable $e) {
    error_log('api_parent_sign failed: ' . $e->getMessage());
    http_response_code(500);
    psign_json(['ok' => false, 'error' => 'Could not update signature']);
}
