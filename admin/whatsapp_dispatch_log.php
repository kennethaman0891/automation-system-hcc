<?php
/**
 * HCC Automation System — WhatsApp Dispatch Log
 * View audit trail of all WhatsApp sends (auto + manual if logged)
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

$adminPage = 'whatsapp_dispatch_log';
$settings  = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church High School';
$adminUser  = sanitize($_SESSION['admin_name'] ?? 'Admin');

$db = getDB();

// Filters
$filterYear     = trim($_GET['year'] ?? '');
$filterStd      = trim($_GET['std'] ?? '');
$filterDiv      = trim($_GET['division'] ?? '');
$filterSem      = trim($_GET['semester'] ?? '');
$filterDocType  = trim($_GET['doc_type'] ?? '');
$filterStatus   = trim($_GET['status'] ?? '');
$filterMethod   = trim($_GET['method'] ?? '');
$search         = trim($_GET['search'] ?? '');
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 50;
$offset         = ($page - 1) * $perPage;

$where = ['1=1'];
$params = [];

if ($filterYear) { $where[] = 'academic_year = ?'; $params[] = $filterYear; }
if ($filterStd)  { $where[] = 'std = ?'; $params[] = $filterStd; }
if ($filterDiv)  { $where[] = 'division = ?'; $params[] = $filterDiv; }
if ($filterSem)  { $where[] = 'semester = ?'; $params[] = $filterSem; }
if ($filterDocType) { $where[] = 'doc_type = ?'; $params[] = $filterDocType; }
if ($filterStatus)  { $where[] = 'status = ?'; $params[] = $filterStatus; }
if ($filterMethod)  { $where[] = 'dispatch_method = ?'; $params[] = $filterMethod; }
if ($search) {
    $where[] = '(student_name LIKE ? OR student_roll LIKE ? OR parent_phone LIKE ?)';
    $params[] = "%$search%"; $params[] = "%$search%"; $params[] = "%$search%";
}

$whereSql = implode(' AND ', $where);

// Total count
$countStmt = $db->prepare("SELECT COUNT(*) FROM whatsapp_dispatch_log WHERE $whereSql");
$countStmt->execute($params);
$total = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));
if ($page > $totalPages) $page = $totalPages;

// Fetch logs
$paramsPage = array_merge($params, [$perPage, $offset]);
$stmt = $db->prepare("
    SELECT * FROM whatsapp_dispatch_log 
    WHERE $whereSql 
    ORDER BY dispatched_at DESC 
    LIMIT ? OFFSET ?
");
$stmt->execute($paramsPage);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get filter options
$years = $db->query("SELECT DISTINCT academic_year FROM whatsapp_dispatch_log ORDER BY academic_year DESC")->fetchAll(PDO::FETCH_COLUMN);
$stds = $db->query("SELECT DISTINCT std FROM whatsapp_dispatch_log ORDER BY std")->fetchAll(PDO::FETCH_COLUMN);
$divs = $db->query("SELECT DISTINCT division FROM whatsapp_dispatch_log ORDER BY division")->fetchAll(PDO::FETCH_COLUMN);
$semesters = $db->query("SELECT DISTINCT semester FROM whatsapp_dispatch_log WHERE semester IS NOT NULL ORDER BY semester")->fetchAll(PDO::FETCH_COLUMN);

$romanMap = ['1'=>'I','2'=>'II','3'=>'III','4'=>'IV','5'=>'V','6'=>'VI','7'=>'VII','8'=>'VIII','9'=>'IX','10'=>'X'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>WhatsApp Dispatch Log — <?= sanitize($schoolName) ?></title>
    <link rel="icon" href="../assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="../assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
    <style>
        .log-filters { background:#fff; border:1px solid var(--border); border-radius:var(--radius); padding:16px; margin-bottom:20px; }
        .filter-row { display:flex; gap:12px; flex-wrap:wrap; align-items:flex-end; margin-bottom:12px; }
        .filter-group { display:flex; flex-direction:column; gap:4px; }
        .filter-group label { font-size:0.75rem; font-weight:600; color:var(--text-2); text-transform:uppercase; }
        .filter-group select, .filter-group input { padding:8px 12px; border:1px solid var(--border); border-radius:6px; font-size:0.85rem; background:#fff; min-width:140px; }
        .filter-actions { display:flex; gap:8px; margin-top:8px; }
        .log-table { width:100%; border-collapse:collapse; background:#fff; border-radius:var(--radius); overflow:hidden; box-shadow:var(--shadow-sm); }
        .log-table th, .log-table td { padding:10px 12px; border-bottom:1px solid var(--border); font-size:0.82rem; text-align:left; vertical-align:top; }
        .log-table th { background:var(--surface); font-weight:700; color:var(--ink); white-space:nowrap; }
        .log-table tr:hover td { background:var(--surface); }
        .status-badge { display:inline-flex; align-items:center; gap:4px; padding:3px 8px; border-radius:999px; font-size:0.7rem; font-weight:700; text-transform:uppercase; }
        .status-sent { background:#dcfce7; color:#166534; }
        .status-opened { background:#fef3c7; color:#b45309; }
        .status-ready { background:#e0f2fe; color:#0369a1; }
        .status-skipped { background:#f1f5f9; color:#475569; }
        .status-failed { background:#fee2e2; color:#991b1b; }
        .status-no_phone { background:#fff7ed; color:#c2410c; }
        .status-pending { background:#f1f5f9; color:#64748b; }
        .method-badge { font-size:0.7rem; padding:2px 6px; border-radius:4px; background:var(--brand-soft); color:var(--brand); font-weight:600; }
        .error-text { color:#991b1b; font-size:0.75rem; max-width:280px; word-break:break-word; }
        .pagination { display:flex; justify-content:center; gap:6px; margin-top:20px; }
        .pagination a, .pagination span { padding:6px 12px; border:1px solid var(--border); border-radius:6px; text-decoration:none; color:var(--ink); font-size:0.85rem; }
        .pagination a:hover { background:var(--brand-soft); border-color:var(--brand); }
        .pagination .current { background:var(--brand); color:#fff; border-color:var(--brand); }
        .empty-state { text-align:center; padding:40px; color:var(--text-2); }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
        <main class="admin-main">
            <header class="admin-header">
                <button class="sidebar-toggle js-sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <div class="header-titles">
                    <h1 class="page-title"><i class="fas fa-history" style="margin-right:8px;"></i> WhatsApp Dispatch Log</h1>
                    <p class="page-sub">Audit trail of all WhatsApp sends</p>
                </div>
            </header>
            <div class="admin-content">
                <!-- Filters -->
                <form method="GET" class="log-filters">
                    <div class="filter-row">
                        <div class="filter-group">
                            <label>Academic Year</label>
                            <select name="year">
                                <option value="">All Years</option>
                                <?php foreach ($years as $y): ?>
                                <option value="<?= sanitize($y) ?>" <?= $filterYear===$y?'selected':'' ?>><?= sanitize($y) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Std</label>
                            <select name="std">
                                <option value="">All</option>
                                <?php foreach ($stds as $s): ?>
                                <option value="<?= sanitize($s) ?>" <?= $filterStd===$s?'selected':'' ?>>STD <?= $romanMap[$s] ?? $s ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Division</label>
                            <select name="division">
                                <option value="">All</option>
                                <?php foreach ($divs as $d): ?>
                                <option value="<?= sanitize($d) ?>" <?= $filterDiv===$d?'selected':'' ?>><?= sanitize($d) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Semester</label>
                            <select name="semester">
                                <option value="">All</option>
                                <?php foreach ($semesters as $sem): ?>
                                <option value="<?= sanitize($sem) ?>" <?= $filterSem===$sem?'selected':'' ?>><?= sanitize($sem) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                    <div class="filter-row">
                        <div class="filter-group">
                            <label>Doc Type</label>
                            <select name="doc_type">
                                <option value="">All Types</option>
                                <option value="report_card" <?= $filterDocType==='report_card'?'selected':'' ?>>Report Card</option>
                                <option value="unit_test" <?= $filterDocType==='unit_test'?'selected':'' ?>>Unit Test</option>
                                <option value="bonafide" <?= $filterDocType==='bonafide'?'selected':'' ?>>Bonafide</option>
                                <option value="announcement" <?= $filterDocType==='announcement'?'selected':'' ?>>Announcement</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Status</label>
                            <select name="status">
                                <option value="">All</option>
                                <option value="sent" <?= $filterStatus==='sent'?'selected':'' ?>>Sent (Confirmed)</option>
                                <option value="opened" <?= $filterStatus==='opened'?'selected':'' ?>>Opened (In Chat)</option>
                                <option value="ready" <?= $filterStatus==='ready'?'selected':'' ?>>Ready</option>
                                <option value="pending" <?= $filterStatus==='pending'?'selected':'' ?>>Pending</option>
                                <option value="skipped" <?= $filterStatus==='skipped'?'selected':'' ?>>Skipped</option>
                                <option value="failed" <?= $filterStatus==='failed'?'selected':'' ?>>Failed</option>
                                <option value="no_phone" <?= $filterStatus==='no_phone'?'selected':'' ?>>No Phone</option>
                            </select>
                        </div>
                        <div class="filter-group">
                            <label>Method</label>
                            <select name="method">
                                <option value="">All</option>
                                <option value="auto" <?= $filterMethod==='auto'?'selected':'' ?>>Auto (Puppeteer)</option>
                                <option value="manual" <?= $filterMethod==='manual'?'selected':'' ?>>Manual</option>
                                <option value="companion" <?= $filterMethod==='companion'?'selected':'' ?>>Companion Window</option>
                            </select>
                        </div>
                        <div class="filter-group" style="flex:1;">
                            <label>Search</label>
                            <input type="text" name="search" placeholder="Student name, roll, phone…" value="<?= e_attr($search) ?>">
                        </div>
                    </div>
                    <div class="filter-actions">
                        <button type="submit" class="btn-primary"><i class="fas fa-filter"></i> Apply</button>
                        <a href="whatsapp_dispatch_log.php" class="btn-secondary"><i class="fas fa-rotate-left"></i> Clear</a>
                    </div>
                </form>

                <!-- Log Table -->
                <?php if (empty($logs)): ?>
                    <div class="empty-state">
                        <i class="fas fa-inbox" style="font-size:2.5rem;display:block;margin-bottom:12px;opacity:0.5;"></i>
                        <p>No dispatch logs found.</p>
                    </div>
                <?php else: ?>
                    <div style="overflow-x:auto;">
                        <table class="log-table">
                            <thead>
                                <tr>
                                    <th>Date & Time</th>
                                    <th>Student</th>
                                    <th>Class</th>
                                    <th>Doc Type</th>
                                    <th>Method</th>
                                    <th>Phone</th>
                                    <th>Status</th>
                                    <th>PDF File</th>
                                    <th>Dispatched By</th>
                                    <th>Error</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td style="white-space:nowrap;">
                                        <?= date('d M Y, H:i:s', strtotime($log['dispatched_at'])) ?>
                                    </td>
                                    <td>
                                        <strong><?= sanitize($log['student_name']) ?></strong><br>
                                        <span style="color:var(--text-2);font-size:0.75rem;">Roll <?= sanitize($log['student_roll']) ?></span>
                                    </td>
                                    <td style="white-space:nowrap;">
                                        STD <?= $romanMap[$log['std']] ?? $log['std'] ?> <?= sanitize($log['division']) ?>
                                        <?php if ($log['semester']): ?><br><small><?= sanitize($log['semester']) ?></small><?php endif; ?>
                                        <?php if ($log['test_type']): ?><br><small><?= sanitize($log['test_type']) ?></small><?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="method-badge"><?= ucfirst(str_replace('_', ' ', $log['doc_type'])) ?></span>
                                    </td>
                                    <td>
                                        <span class="method-badge"><?= ucfirst($log['dispatch_method']) ?></span>
                                    </td>
                                    <td style="font-family:monospace;font-size:0.8rem;">
                                        <?= sanitize($log['parent_phone']) ?>
                                    </td>
                                    <td>
                                        <?php
                                        $st = $log['status'];
                                        $ico = 'clock';
                                        if ($st === 'sent') $ico = 'check-circle';
                                        elseif ($st === 'opened') $ico = 'envelope-open-text';
                                        elseif ($st === 'ready') $ico = 'check';
                                        elseif ($st === 'skipped') $ico = 'forward';
                                        elseif ($st === 'failed') $ico = 'times-circle';
                                        elseif ($st === 'no_phone') $ico = 'phone-slash';
                                        ?>
                                        <span class="status-badge status-<?= sanitize($st) ?>">
                                            <i class="fas fa-<?= $ico ?>"></i>
                                            <?= sanitize(ucwords(str_replace('_', ' ', $st))) ?>
                                        </span>
                                    </td>
                                    <td style="font-size:0.75rem;max-width:180px;word-break:break-all;">
                                        <?= sanitize($log['pdf_filename'] ?? '—') ?>
                                    </td>
                                    <td><?= sanitize($log['dispatched_by']) ?></td>
                                    <td>
                                        <?php if ($log['error_message']): ?>
                                            <div class="error-text" title="<?= e_attr($log['error_message']) ?>">
                                                <i class="fas fa-exclamation-triangle"></i> <?= sanitize($log['error_message']) ?>
                                            </div>
                                        <?php else: ?>
                                            <span style="color:var(--text-3);">—</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($totalPages > 1): ?>
                    <div class="pagination">
                        <?php if ($page > 1): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page-1])) ?>">&laquo; Prev</a>
                        <?php endif; ?>
                        <?php
                            $start = max(1, $page - 2);
                            $end = min($totalPages, $page + 2);
                            for ($p = $start; $p <= $end; $p++):
                        ?>
                            <?php if ($p === $page): ?>
                                <span class="current"><?= $p ?></span>
                            <?php else: ?>
                                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $p])) ?>"><?= $p ?></a>
                            <?php endif; ?>
                        <?php endfor; ?>
                        <?php if ($page < $totalPages): ?>
                            <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page+1])) ?>">Next &raquo;</a>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </main>
    </div>
    <script src="../assets/js/admin.js?v=<?= (is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 0) ?>"></script>
</body>
</html>
