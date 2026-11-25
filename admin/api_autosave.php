<?php
/**
 * HCC Universal Admin Auto-Save API
 * ---------------------------------
 * Persists and restores auto-saved form & figure drafts in MySQL (`admin_autosave_drafts`)
 * for every authenticated admin page so sudden power cuts never lose in-progress work.
 *
 * Endpoints:
 *   GET  ?action=load&page=<page_key>&context=<context_key>
 *   POST ?action=save   (JSON or form-encoded; CSRF-protected)
 *   POST ?action=clear  (JSON or form-encoded; CSRF-protected)
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/includes/admin_autosave.php';

header('Content-Type: application/json; charset=utf-8');

function hccAutosaveJson(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

$adminId = (int)($_SESSION['admin_id'] ?? 0);
if ($adminId < 1) {
    hccAutosaveJson(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$db = getDB();
if (!hccEnsureAutosaveTable($db)) {
    hccAutosaveJson(['ok' => false, 'error' => 'Autosave storage unavailable'], 500);
}

$method = strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? 'GET'));
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? ''));

$body = null;
if ($method === 'POST') {
    $raw = file_get_contents('php://input');
    if (is_string($raw) && trim($raw) !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $body = $decoded;
            if ($action === '' && isset($body['action'])) {
                $action = trim((string)$body['action']);
            }
            // Allow JSON body csrf_token for keepalive/sendBeacon requests where custom headers may not be set
            if (empty($_POST['csrf_token']) && empty($_SERVER['HTTP_X_CSRF_TOKEN']) && !empty($body['csrf_token'])) {
                $_POST['csrf_token'] = (string)$body['csrf_token'];
            }
        }
    }
    if (!verifyCSRFToken()) {
        hccAutosaveJson(['ok' => false, 'error' => 'Invalid security token'], 403);
    }
}

function hccCleanAutosaveKey(string $val, int $maxLen): string
{
    $val = trim($val);
    $val = preg_replace('/[\x00-\x1F\x7F]/', '', $val);
    if (strlen($val) > $maxLen) {
        $val = substr($val, 0, $maxLen);
    }
    return $val;
}

if ($method === 'GET' && $action === 'load') {
    $pageKey    = strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string)($_GET['page'] ?? '')));
    $contextKey = hccCleanAutosaveKey((string)($_GET['context'] ?? ''), 255);

    if ($pageKey === '') {
        hccAutosaveJson(['ok' => false, 'error' => 'Missing page key'], 400);
    }

    try {
        // Opportunistically prune drafts older than 14 days for this admin
        $db->prepare('DELETE FROM admin_autosave_drafts WHERE admin_id = ? AND updated_at < DATE_SUB(NOW(), INTERVAL 14 DAY)')
           ->execute([$adminId]);

        $stmt = $db->prepare('
            SELECT form_key, context_key, payload_json, field_count, updated_at, UNIX_TIMESTAMP(updated_at) AS updated_ts
            FROM admin_autosave_drafts
            WHERE admin_id = ? AND page_key = ? AND context_key = ?
            ORDER BY updated_at DESC
            LIMIT 20
        ');
        $stmt->execute([$adminId, $pageKey, $contextKey]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $drafts = [];
        foreach ($rows as $row) {
            $fields = json_decode((string)$row['payload_json'], true);
            if (!is_array($fields) || empty($fields)) {
                continue;
            }
            $drafts[] = [
                'form_key'    => (string)$row['form_key'],
                'context_key' => (string)$row['context_key'],
                'fields'      => $fields,
                'field_count' => (int)$row['field_count'],
                'updated_at'  => (string)$row['updated_at'],
                'updated_ts'  => (int)$row['updated_ts'],
            ];
        }

        hccAutosaveJson(['ok' => true, 'drafts' => $drafts]);
    } catch (Throwable $e) {
        error_log('HCC autosave load error: ' . $e->getMessage());
        hccAutosaveJson(['ok' => false, 'error' => 'Failed to load drafts'], 500);
    }
}

if ($method === 'POST' && $action === 'save') {
    $src = is_array($body) ? $body : $_POST;

    $pageKey    = strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string)($src['page_key'] ?? '')));
    $formKey    = hccCleanAutosaveKey((string)($src['form_key'] ?? ''), 120);
    $contextKey = hccCleanAutosaveKey((string)($src['context_key'] ?? ''), 255);
    $pageUrl    = hccCleanAutosaveKey((string)($src['page_url'] ?? ''), 500);
    $fields     = $src['fields'] ?? null;

    if (is_string($fields)) {
        $fields = json_decode($fields, true);
    }

    if ($pageKey === '' || $formKey === '' || !is_array($fields)) {
        hccAutosaveJson(['ok' => false, 'error' => 'Invalid autosave payload'], 400);
    }

    // Security hygiene: strip any accidental password or csrf_token keys server-side too
    $sanitizedFields = [];
    foreach ($fields as $k => $v) {
        $keyStr = (string)$k;
        $lower  = strtolower($keyStr);
        if (
            $keyStr === '' ||
            strpos($lower, 'password') !== false ||
            strpos($lower, 'passwd') !== false ||
            strpos($lower, 'csrf') !== false ||
            strpos($lower, 'secret') !== false ||
            strpos($lower, 'smtp_pass') !== false ||
            strpos($lower, 'client_secret') !== false
        ) {
            continue;
        }
        $sanitizedFields[$keyStr] = $v;
    }

    if (empty($sanitizedFields)) {
        // Nothing meaningful to save — delete any stale draft for this scope
        try {
            $db->prepare('DELETE FROM admin_autosave_drafts WHERE admin_id = ? AND page_key = ? AND form_key = ? AND context_key = ?')
               ->execute([$adminId, $pageKey, $formKey, $contextKey]);
        } catch (Throwable $e) {}
        hccAutosaveJson(['ok' => true, 'cleared' => true, 'saved_at' => date('H:i:s')]);
    }

    $json = json_encode($sanitizedFields, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($json === false || strlen($json) > 2 * 1024 * 1024) {
        hccAutosaveJson(['ok' => false, 'error' => 'Draft payload too large'], 413);
    }

    try {
        $stmt = $db->prepare('
            INSERT INTO admin_autosave_drafts
                (admin_id, page_key, form_key, context_key, page_url, payload_json, field_count)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                page_url = VALUES(page_url),
                payload_json = VALUES(payload_json),
                field_count = VALUES(field_count),
                updated_at = CURRENT_TIMESTAMP
        ');
        $stmt->execute([
            $adminId,
            $pageKey,
            $formKey,
            $contextKey,
            $pageUrl,
            $json,
            count($sanitizedFields),
        ]);

        hccAutosaveJson([
            'ok'          => true,
            'saved_at'    => date('H:i:s'),
            'field_count' => count($sanitizedFields),
        ]);
    } catch (Throwable $e) {
        error_log('HCC autosave save error: ' . $e->getMessage());
        hccAutosaveJson(['ok' => false, 'error' => 'Database save failed'], 500);
    }
}

if ($method === 'POST' && $action === 'clear') {
    $src = is_array($body) ? $body : $_POST;

    $pageKey    = strtolower(preg_replace('/[^a-z0-9_-]/i', '', (string)($src['page_key'] ?? '')));
    $formKey    = hccCleanAutosaveKey((string)($src['form_key'] ?? ''), 120);
    $contextKey = hccCleanAutosaveKey((string)($src['context_key'] ?? ''), 255);

    if ($pageKey === '') {
        hccAutosaveJson(['ok' => false, 'error' => 'Missing page key'], 400);
    }

    try {
        if ($formKey !== '') {
            $stmt = $db->prepare('DELETE FROM admin_autosave_drafts WHERE admin_id = ? AND page_key = ? AND form_key = ? AND context_key = ?');
            $stmt->execute([$adminId, $pageKey, $formKey, $contextKey]);
        } else {
            $stmt = $db->prepare('DELETE FROM admin_autosave_drafts WHERE admin_id = ? AND page_key = ? AND context_key = ?');
            $stmt->execute([$adminId, $pageKey, $contextKey]);
        }
        hccAutosaveJson(['ok' => true]);
    } catch (Throwable $e) {
        error_log('HCC autosave clear error: ' . $e->getMessage());
        hccAutosaveJson(['ok' => false, 'error' => 'Failed to clear draft'], 500);
    }
}

hccAutosaveJson(['ok' => false, 'error' => 'Unsupported action'], 400);
