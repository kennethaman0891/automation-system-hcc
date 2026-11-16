<?php
/**
 * WhatsApp message templates — card library CRUD + fill + send.
 *
 * GET  ?action=list&scope=personal|group|both&q=&category=
 * GET  ?action=get&id=
 * GET  ?action=fill&id=&chat_id=   (preview filled body)
 * POST action=create|update|save_as_new|duplicate|delete|rename|send
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

waRequireAuth();
header('Content-Type: application/json; charset=utf-8');

$db = waGetDB();
// Ensure table exists (dev machines that skipped migrate)
try {
    $db->query('SELECT 1 FROM whatsapp_message_templates LIMIT 1');
} catch (Throwable $e) {
    $sqlFile = dirname(__DIR__) . '/data/migrations/create_whatsapp_message_templates.sql';
    if (is_file($sqlFile)) {
        $db->exec((string)file_get_contents($sqlFile));
    }
}

$action = $_GET['action'] ?? $_POST['action'] ?? 'list';
$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) $input = $_POST;
if (isset($input['action']) && $action === 'list') $action = (string)$input['action'];

$mutatingActions = ['create', 'update', 'save_as_new', 'duplicate', 'delete', 'rename', 'send'];
if (in_array($action, $mutatingActions, true)) {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        waJsonResponse(['success' => false, 'error' => 'Method not allowed'], 405);
    }
    waVerifyCSRF($input);
}

function waTplRow(array $r): array {
    $r['id'] = (int)$r['id'];
    $r['is_starter'] = (bool)$r['is_starter'];
    $r['is_archived'] = (bool)$r['is_archived'];
    $r['usage_count'] = (int)$r['usage_count'];
    if (!empty($r['placeholders']) && is_string($r['placeholders'])) {
        $r['placeholders'] = json_decode($r['placeholders'], true) ?: [];
    }
    return $r;
}

function waTplUniqueSlug(PDO $db, string $base, ?int $ignoreId = null): string {
    $slug = waSlugifyTemplate($base);
    $n = 0;
    while (true) {
        $try = $n === 0 ? $slug : ($slug . '_' . $n);
        $sql = 'SELECT id FROM whatsapp_message_templates WHERE slug = ?';
        $params = [$try];
        if ($ignoreId) { $sql .= ' AND id <> ?'; $params[] = $ignoreId; }
        $st = $db->prepare($sql);
        $st->execute($params);
        if (!$st->fetchColumn()) return $try;
        $n++;
        if ($n > 200) return $slug . '_' . bin2hex(random_bytes(3));
    }
}

switch ($action) {
    case 'list':
        $scope = trim((string)($_GET['scope'] ?? $input['scope'] ?? ''));
        $q = trim((string)($_GET['q'] ?? $input['q'] ?? ''));
        $category = trim((string)($_GET['category'] ?? $input['category'] ?? ''));
        $includeArchived = (($_GET['archived'] ?? '') === '1');
        $where = ['1=1'];
        $params = [];
        if (!$includeArchived) $where[] = 'is_archived = 0';
        if ($scope === 'personal' || $scope === 'group') {
            $where[] = '(scope = ? OR scope = \'both\')';
            $params[] = $scope;
        }
        if ($category !== '') {
            $where[] = 'category = ?';
            $params[] = $category;
        }
        if ($q !== '') {
            $where[] = '(title LIKE ? OR body LIKE ? OR slug LIKE ?)';
            $like = '%' . $q . '%';
            array_push($params, $like, $like, $like);
        }
        $sql = 'SELECT * FROM whatsapp_message_templates WHERE ' . implode(' AND ', $where)
             . ' ORDER BY category ASC, title ASC';
        $st = $db->prepare($sql);
        $st->execute($params);
        $rows = array_map('waTplRow', $st->fetchAll(PDO::FETCH_ASSOC));
        $cats = $db->query("SELECT category, COUNT(*) c FROM whatsapp_message_templates WHERE is_archived = 0 GROUP BY category")->fetchAll(PDO::FETCH_ASSOC);
        waJsonResponse(['success' => true, 'data' => $rows, 'categories' => $cats]);
        break;

    case 'get':
        $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
        $st = $db->prepare('SELECT * FROM whatsapp_message_templates WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) waJsonResponse(['success' => false, 'error' => 'Template not found'], 404);
        waJsonResponse(['success' => true, 'data' => waTplRow($row)]);
        break;

    case 'fill':
        $id = (int)($_GET['id'] ?? $input['id'] ?? 0);
        $chatId = (int)($_GET['chat_id'] ?? $input['chat_id'] ?? 0);
        $st = $db->prepare('SELECT * FROM whatsapp_message_templates WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) waJsonResponse(['success' => false, 'error' => 'Template not found'], 404);
        $vars = $chatId > 0 ? waTemplateVarsFromChat($db, $chatId) : [
            'parent_name' => 'Parent', 'student_name' => 'Student', 'class' => 'your class',
            'date' => date('d M Y'), 'announcement' => '',
        ];
        if (!empty($input['vars']) && is_array($input['vars'])) {
            foreach ($input['vars'] as $k => $v) $vars[(string)$k] = (string)$v;
        }
        $filled = waFillTemplatePlaceholders((string)$row['body'], $vars);
        waJsonResponse(['success' => true, 'data' => ['body' => $filled, 'vars' => $vars, 'template' => waTplRow($row)]]);
        break;

    case 'create':
        $title = trim((string)($input['title'] ?? ''));
        $body = trim((string)($input['body'] ?? ''));
        if ($title === '' || $body === '') waJsonResponse(['success' => false, 'error' => 'title and body required'], 400);
        $scope = in_array(($input['scope'] ?? 'both'), ['personal','group','both'], true) ? $input['scope'] : 'both';
        $category = trim((string)($input['category'] ?? 'custom')) ?: 'custom';
        $slug = waTplUniqueSlug($db, (string)($input['slug'] ?? $title));
        $ph = json_encode($input['placeholders'] ?? ['parent_name','student_name','class','date','announcement'], JSON_UNESCAPED_UNICODE);
        $db->prepare("INSERT INTO whatsapp_message_templates (slug,title,category,scope,body,placeholders,is_starter,created_by,updated_by) VALUES (?,?,?,?,?,?,0,?,?)")
           ->execute([$slug, $title, $category, $scope, $body, $ph, $_SESSION['admin_id'] ?? null, $_SESSION['admin_id'] ?? null]);
        $id = (int)$db->lastInsertId();
        $st = $db->prepare('SELECT * FROM whatsapp_message_templates WHERE id = ?');
        $st->execute([$id]);
        waJsonResponse(['success' => true, 'data' => waTplRow($st->fetch(PDO::FETCH_ASSOC))], 201);
        break;

    case 'update':
        // Save-on-edit: update in place
        $id = (int)($input['id'] ?? 0);
        $st = $db->prepare('SELECT * FROM whatsapp_message_templates WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) waJsonResponse(['success' => false, 'error' => 'Template not found'], 404);
        $title = trim((string)($input['title'] ?? $row['title']));
        $body = array_key_exists('body', $input) ? (string)$input['body'] : (string)$row['body'];
        $scope = in_array(($input['scope'] ?? $row['scope']), ['personal','group','both'], true) ? ($input['scope'] ?? $row['scope']) : $row['scope'];
        $category = trim((string)($input['category'] ?? $row['category'])) ?: $row['category'];
        $db->prepare("UPDATE whatsapp_message_templates SET title=?, body=?, scope=?, category=?, updated_by=?, is_starter=0 WHERE id=?")
           ->execute([$title, $body, $scope, $category, $_SESSION['admin_id'] ?? null, $id]);
        $st->execute([$id]);
        waJsonResponse(['success' => true, 'data' => waTplRow($st->fetch(PDO::FETCH_ASSOC)), 'mode' => 'update']);
        break;

    case 'save_as_new':
        $id = (int)($input['id'] ?? 0);
        $st = $db->prepare('SELECT * FROM whatsapp_message_templates WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row && empty($input['body'])) waJsonResponse(['success' => false, 'error' => 'source required'], 400);
        $title = trim((string)($input['title'] ?? (($row['title'] ?? 'Template') . ' (copy)')));
        $body = (string)($input['body'] ?? $row['body'] ?? '');
        $scope = in_array(($input['scope'] ?? ($row['scope'] ?? 'both')), ['personal','group','both'], true)
            ? ($input['scope'] ?? $row['scope'] ?? 'both') : 'both';
        $category = trim((string)($input['category'] ?? ($row['category'] ?? 'custom'))) ?: 'custom';
        $slug = waTplUniqueSlug($db, $title);
        $ph = $row['placeholders'] ?? json_encode(['parent_name','student_name','class','date']);
        $db->prepare("INSERT INTO whatsapp_message_templates (slug,title,category,scope,body,placeholders,is_starter,created_by,updated_by) VALUES (?,?,?,?,?,?,0,?,?)")
           ->execute([$slug, $title, $category, $scope, $body, $ph, $_SESSION['admin_id'] ?? null, $_SESSION['admin_id'] ?? null]);
        $newId = (int)$db->lastInsertId();
        $st = $db->prepare('SELECT * FROM whatsapp_message_templates WHERE id = ?');
        $st->execute([$newId]);
        waJsonResponse(['success' => true, 'data' => waTplRow($st->fetch(PDO::FETCH_ASSOC)), 'mode' => 'save_as_new'], 201);
        break;

    case 'duplicate':
        $id = (int)($input['id'] ?? 0);
        $st = $db->prepare('SELECT * FROM whatsapp_message_templates WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) waJsonResponse(['success' => false, 'error' => 'Template not found'], 404);
        $title = $row['title'] . ' (copy)';
        $slug = waTplUniqueSlug($db, $title);
        $db->prepare("INSERT INTO whatsapp_message_templates (slug,title,category,scope,body,placeholders,is_starter,created_by,updated_by) VALUES (?,?,?,?,?,?,0,?,?)")
           ->execute([$slug, $title, $row['category'], $row['scope'], $row['body'], $row['placeholders'], $_SESSION['admin_id'] ?? null, $_SESSION['admin_id'] ?? null]);
        $newId = (int)$db->lastInsertId();
        $st->execute([$newId]);
        // fetch new
        $st2 = $db->prepare('SELECT * FROM whatsapp_message_templates WHERE id = ?');
        $st2->execute([$newId]);
        waJsonResponse(['success' => true, 'data' => waTplRow($st2->fetch(PDO::FETCH_ASSOC))], 201);
        break;

    case 'rename':
        $id = (int)($input['id'] ?? 0);
        $title = trim((string)($input['title'] ?? ''));
        if ($title === '') waJsonResponse(['success' => false, 'error' => 'title required'], 400);
        $db->prepare('UPDATE whatsapp_message_templates SET title = ?, updated_by = ?, is_starter = 0 WHERE id = ?')
           ->execute([$title, $_SESSION['admin_id'] ?? null, $id]);
        $st = $db->prepare('SELECT * FROM whatsapp_message_templates WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!$row) waJsonResponse(['success' => false, 'error' => 'Template not found'], 404);
        waJsonResponse(['success' => true, 'data' => waTplRow($row)]);
        break;

    case 'delete':
        $id = (int)($input['id'] ?? 0);
        // Soft-delete starters/custom alike so seeds can be restored via migrate
        $db->prepare('UPDATE whatsapp_message_templates SET is_archived = 1, updated_by = ? WHERE id = ?')
           ->execute([$_SESSION['admin_id'] ?? null, $id]);
        waJsonResponse(['success' => true]);
        break;

    case 'send':
        $id = (int)($input['id'] ?? 0);
        $bodyOverride = array_key_exists('body', $input) ? (string)$input['body'] : null;
        $chatIds = [];
        if (!empty($input['chat_ids']) && is_array($input['chat_ids'])) {
            foreach ($input['chat_ids'] as $cid) {
                $cid = (int)$cid;
                if ($cid > 0) $chatIds[] = $cid;
            }
        }
        if (!empty($input['chat_id'])) $chatIds[] = (int)$input['chat_id'];
        $chatIds = array_values(array_unique($chatIds));
        if (!$chatIds) waJsonResponse(['success' => false, 'error' => 'chat_id or chat_ids required'], 400);

        $row = null;
        if ($id > 0) {
            $st = $db->prepare('SELECT * FROM whatsapp_message_templates WHERE id = ?');
            $st->execute([$id]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            if (!$row) waJsonResponse(['success' => false, 'error' => 'Template not found'], 404);
            // Save-on-edit before send when body provided
            if ($bodyOverride !== null && $bodyOverride !== (string)$row['body'] && !empty($input['save_edit'])) {
                $mode = ($input['save_mode'] ?? 'update') === 'save_as_new' ? 'save_as_new' : 'update';
                if ($mode === 'update') {
                    $db->prepare('UPDATE whatsapp_message_templates SET body = ?, updated_by = ?, is_starter = 0 WHERE id = ?')
                       ->execute([$bodyOverride, $_SESSION['admin_id'] ?? null, $id]);
                    $row['body'] = $bodyOverride;
                } else {
                    $title = trim((string)($input['title'] ?? ($row['title'] . ' (edited)')));
                    $slug = waTplUniqueSlug($db, $title);
                    $db->prepare("INSERT INTO whatsapp_message_templates (slug,title,category,scope,body,placeholders,is_starter,created_by,updated_by) VALUES (?,?,?,?,?,?,0,?,?)")
                       ->execute([$slug, $title, $row['category'], $row['scope'], $bodyOverride, $row['placeholders'], $_SESSION['admin_id'] ?? null, $_SESSION['admin_id'] ?? null]);
                    $id = (int)$db->lastInsertId();
                    $row['body'] = $bodyOverride;
                    $row['id'] = $id;
                }
            }
        }
        if ($bodyOverride === null && !$row) {
            waJsonResponse(['success' => false, 'error' => 'body or template id required'], 400);
        }

        $adminId = (int)($_SESSION['admin_id'] ?? 0);
        if ($adminId <= 0) {
            waJsonResponse(['success' => false, 'error' => 'Unauthorized'], 401);
        }
        // Rate limit: stagger broadcast by 2s each (scheduled_at)
        $queued = [];
        $baseDelay = 0;
        foreach ($chatIds as $idx => $chatId) {
            $vars = waTemplateVarsFromChat($db, $chatId);
            if (!empty($input['vars']) && is_array($input['vars'])) {
                foreach ($input['vars'] as $k => $v) $vars[(string)$k] = (string)$v;
            }
            $rawBody = $bodyOverride !== null ? $bodyOverride : (string)$row['body'];
            // If override already filled by UI, still run fill for leftover braces
            $filled = waFillTemplatePlaceholders($rawBody, $vars);
            $sched = null;
            if (count($chatIds) > 1) {
                $sched = date('Y-m-d H:i:s', time() + ($idx * 2));
            }
            $res = waEnqueueWhatsAppOutbound($db, [
                'chat_id' => $chatId,
                'msg_type' => 'text',
                'body' => $filled,
                'priority' => min(max(1, (int)($input['priority'] ?? 5)), 10),
                'related_doc_type' => 'template',
                'template_id' => $id ?: null,
                'scheduled_at' => $sched,
                'dispatch_method' => 'manual',
                'origin' => 'manual_ui',
                'approved_by' => $adminId,
                'dispatched_by' => $adminId > 0 ? $adminId : null,
            ]);
            $queued[] = ['chat_id' => $chatId, 'queue_id' => $res['queue_id'], 'temp_msg_id' => $res['temp_msg_id'], 'body' => $filled, 'status' => $res['status'] ?? null];
        }
        if ($id > 0) {
            $db->prepare('UPDATE whatsapp_message_templates SET usage_count = usage_count + 1 WHERE id = ?')->execute([$id]);
        }
        waJsonResponse(['success' => true, 'queued' => $queued, 'count' => count($queued)]);
        break;

    default:
        waJsonResponse(['success' => false, 'error' => 'Unknown action'], 400);
}
