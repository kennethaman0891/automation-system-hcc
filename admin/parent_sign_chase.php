<?php
/**
 * HCC Automation System — Unsigned & Undelivered Parent-Sign Chase Queue (Automation #3)
 * Tracks parents_sign = 0 across Semester Report Cards & Unit Test Slips,
 * cross-checked with whatsapp_dispatch_log & whatsapp_outbound_queue:
 *   - Never Sent
 *   - Sent but Unsigned
 *   - All Unsigned
 * Features 1-click AJAX "Mark Signed" (api_parent_sign.php), A4 Print Assembly Bench-Check Sheet,
 * and 1-click "Open in WhatsApp Hub" batch deep-linking.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

$adminPage  = 'parent_sign_chase';
$settings   = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church High School';
$adminUser  = sanitize($_SESSION['admin_name'] ?? 'Admin');
$adminRole  = ucfirst($_SESSION['admin_role'] ?? 'Admin');
$adminInitials = strtoupper(implode('', array_map(
    static fn($p) => mb_substr($p, 0, 1),
    array_slice(array_filter(preg_split('/\s+/', trim($_SESSION['admin_name'] ?? 'A'))), 0, 2)
))) ?: 'AD';
$csrfToken = generateCSRFToken();

$db = getDB();
rcEnsureUnitTestTables();
$romanMap = ['1'=>'I','2'=>'II','3'=>'III','4'=>'IV','5'=>'V','6'=>'VI','7'=>'VII','8'=>'VIII','9'=>'IX','10'=>'X'];

$source       = ($_GET['source'] ?? 'report_card') === 'unit_test' ? 'unit_test' : 'report_card';
$yearOptions  = rcGetAcademicYearOptions();
$activeYear   = rcGetActiveAcademicYear();
$selectedYear = trim((string)($_GET['year'] ?? $activeYear));
if ($selectedYear === '' && !empty($yearOptions)) {
    $selectedYear = $yearOptions[0];
}

// Available standards, divisions, and terms for selected year & source
$stdOptions  = [];
$divOptions  = [];
$termOptions = [];
try {
    $srcTable = $source === 'unit_test' ? 'unit_test_results' : 'report_card';
    $termCol  = $source === 'unit_test' ? 'test_type' : 'semester';

    $st = $db->prepare("SELECT DISTINCT std FROM {$srcTable} WHERE academic_year = ?");
    $st->execute([$selectedYear]);
    $stdOptions = array_values(array_filter(array_map('trim', $st->fetchAll(PDO::FETCH_COLUMN))));
    usort($stdOptions, static fn($a, $b) => ((int)$a <=> (int)$b) ?: strcmp($a, $b));

    $dv = $db->prepare("SELECT DISTINCT division FROM {$srcTable} WHERE academic_year = ? ORDER BY division");
    $dv->execute([$selectedYear]);
    $divOptions = array_values(array_filter(array_map('trim', $dv->fetchAll(PDO::FETCH_COLUMN))));

    $tm = $db->prepare("SELECT DISTINCT {$termCol} FROM {$srcTable} WHERE academic_year = ? ORDER BY {$termCol}");
    $tm->execute([$selectedYear]);
    $termOptions = array_values(array_filter(array_map('trim', $tm->fetchAll(PDO::FETCH_COLUMN))));
} catch (Throwable $e) {}

if (empty($termOptions)) {
    $termOptions = $source === 'unit_test'
        ? ['Unit Test 1', 'Unit Test 2', 'Unit Test 3', 'Unit Test 4']
        : ['Sem 1', 'Sem 2'];
}

$selectedStd  = trim((string)($_GET['std'] ?? ''));
$selectedDiv  = trim((string)($_GET['division'] ?? ''));
$selectedTerm = trim((string)($_GET['term'] ?? ($termOptions[0] ?? ($source === 'unit_test' ? 'Unit Test 1' : 'Sem 1'))));
$bucket       = trim((string)($_GET['bucket'] ?? 'all_unsigned'));
if (!in_array($bucket, ['all_unsigned', 'never_sent', 'sent_unsigned', 'signed'], true)) {
    $bucket = 'all_unsigned';
}
$searchQuery  = trim((string)($_GET['search'] ?? ''));

// Preload dispatch status from whatsapp_dispatch_log + whatsapp_outbound_queue (fail-soft)
$dispatchLogMap = []; // "std|div|term|roll" => ['status'=>..., 'at'=>..., 'by'=>...]
try {
    if ($source === 'unit_test') {
        $dStmt = $db->prepare("SELECT std, division, test_type AS term_val, student_roll, status, dispatched_at, dispatched_by
                               FROM whatsapp_dispatch_log
                               WHERE academic_year = ? AND doc_type = 'unit_test' AND test_type = ?");
        $dStmt->execute([$selectedYear, $selectedTerm]);
    } else {
        $dStmt = $db->prepare("SELECT std, division, semester AS term_val, student_roll, status, dispatched_at, dispatched_by
                               FROM whatsapp_dispatch_log
                               WHERE academic_year = ? AND doc_type = 'report_card' AND semester = ?");
        $dStmt->execute([$selectedYear, $selectedTerm]);
    }
    while ($dr = $dStmt->fetch(PDO::FETCH_ASSOC)) {
        $k = trim((string)$dr['std']) . '|' . trim((string)$dr['division']) . '|' . trim((string)$dr['term_val']) . '|' . ltrim(trim((string)$dr['student_roll']), '0');
        $dispatchLogMap[$k] = [
            'status' => strtolower(trim((string)$dr['status'])),
            'at'     => $dr['dispatched_at'] ?? '',
            'by'     => $dr['dispatched_by'] ?? '',
        ];
    }
} catch (Throwable $e) {}

$outboundQueueMap = []; // "class_key|roll" => status
try {
    $oqStmt = $db->prepare("SELECT related_class_key, related_student_roll, status, sent_at, created_at
                            FROM whatsapp_outbound_queue
                            WHERE related_doc_type = ?
                              AND related_class_key LIKE ?
                            ORDER BY id DESC");
    $oqStmt->execute([$source, $selectedYear . '|%']);
    while ($oq = $oqStmt->fetch(PDO::FETCH_ASSOC)) {
        $ck = trim((string)$oq['related_class_key']);
        $rl = ltrim(trim((string)$oq['related_student_roll']), '0') ?: trim((string)$oq['related_student_roll']);
        $ok = $ck . '|' . $rl;
        if (!isset($outboundQueueMap[$ok])) {
            $outboundQueueMap[$ok] = [
                'status' => strtolower(trim((string)$oq['status'])),
                'at'     => $oq['sent_at'] ?: ($oq['created_at'] ?? ''),
            ];
        }
    }
} catch (Throwable $e) {}

// Fallback phones from students_master
$masterPhones = [];
try {
    $mpStmt = $db->prepare("SELECT std, division, roll_no, parent_phone FROM students_master WHERE academic_year = ? AND parent_phone <> ''");
    $mpStmt->execute([$selectedYear]);
    while ($mp = $mpStmt->fetch(PDO::FETCH_ASSOC)) {
        $rn = ltrim(trim((string)$mp['roll_no']), '0') ?: trim((string)$mp['roll_no']);
        $masterPhones[$mp['std'] . '|' . $mp['division'] . '|' . $rn] = trim((string)$mp['parent_phone']);
    }
} catch (Throwable $e) {}

// Fetch student cohort rows
$allCohort = [];
try {
    $where  = ['academic_year = ?'];
    $params = [$selectedYear];
    if ($source === 'unit_test') {
        $where[]  = 'test_type = ?';
        $params[] = $selectedTerm;
        if ($selectedStd !== '') { $where[] = 'std = ?'; $params[] = $selectedStd; }
        if ($selectedDiv !== '') { $where[] = 'division = ?'; $params[] = $selectedDiv; }
        $sql = "SELECT std, division, test_type AS term_val, roll_no,
                       MAX(student_name) AS student_name,
                       MAX(parents_sign) AS parents_sign,
                       MAX(NULLIF(parent_phone, '')) AS parent_phone,
                       SUM(CASE WHEN is_absent = 1 THEN 0 ELSE COALESCE(marks_obtained,0) END) AS obtained,
                       SUM(max_marks) AS maxtotal
                FROM unit_test_results
                WHERE " . implode(' AND ', $where) . "
                GROUP BY std, division, test_type, roll_no
                ORDER BY CAST(std AS UNSIGNED), division, CAST(roll_no AS UNSIGNED), roll_no";
    } else {
        $where[]  = 'semester = ?';
        $params[] = $selectedTerm;
        if ($selectedStd !== '') { $where[] = 'std = ?'; $params[] = $selectedStd; }
        if ($selectedDiv !== '') { $where[] = 'division = ?'; $params[] = $selectedDiv; }
        $sql = "SELECT std, division, semester AS term_val, roll_no,
                       MAX(student_name) AS student_name,
                       MAX(parents_sign) AS parents_sign,
                       MAX(NULLIF(parent_phone, '')) AS parent_phone,
                       SUM(fa_obtained + sa_obtained) AS obtained,
                       SUM(fa_max + sa_max) AS maxtotal
                FROM report_card
                WHERE " . implode(' AND ', $where) . "
                GROUP BY std, division, semester, roll_no
                HAVING maxtotal > 0
                ORDER BY CAST(std AS UNSIGNED), division, CAST(roll_no AS UNSIGNED), roll_no";
    }
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $allCohort = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('parent_sign_chase query error: ' . $e->getMessage());
}

// Classify cohort into chase buckets
$kpis = [
    'total'         => 0,
    'signed'        => 0,
    'all_unsigned'  => 0,
    'never_sent'    => 0,
    'sent_unsigned' => 0,
];
$rows = [];
$classRollBuckets = []; // classKey => ['label'=>..., 'rolls'=>[]]

foreach ($allCohort as $stu) {
    $std      = trim((string)$stu['std']);
    $div      = trim((string)$stu['division']);
    $termVal  = trim((string)$stu['term_val']);
    $roll     = trim((string)$stu['roll_no']);
    $rollNorm = ltrim($roll, '0') ?: $roll;
    $signed   = ((int)($stu['parents_sign'] ?? 0)) === 1;
    $maxTot   = (float)($stu['maxtotal'] ?? 0);
    $obt      = (float)($stu['obtained'] ?? 0);
    $pct      = $maxTot > 0 ? round(($obt / $maxTot) * 100, 1) : 0.0;

    $phone = trim((string)($stu['parent_phone'] ?? ''));
    if ($phone === '' && isset($masterPhones[$std . '|' . $div . '|' . $rollNorm])) {
        $phone = $masterPhones[$std . '|' . $div . '|' . $rollNorm];
    }

    $classKeyRaw = $selectedYear . '|' . $std . '|' . $div . '|' . ($source === 'report_card' ? $termVal : 'Sem 1');
    $logKey      = $std . '|' . $div . '|' . $termVal . '|' . $rollNorm;
    $dEntry      = $dispatchLogMap[$logKey] ?? null;
    $oqEntry     = $outboundQueueMap[$classKeyRaw . '|' . $rollNorm] ?? null;

    $waStatus = 'never_sent';
    $waAt     = '';
    if ($dEntry) {
        $waStatus = $dEntry['status'] ?: 'pending';
        $waAt     = (string)$dEntry['at'];
    }
    if ($oqEntry && in_array($oqEntry['status'], ['sent', 'delivered', 'read', 'queued', 'processing'], true)) {
        if ($waStatus === 'never_sent' || in_array($oqEntry['status'], ['sent', 'delivered', 'read'], true)) {
            $waStatus = $oqEntry['status'];
            $waAt     = (string)$oqEntry['at'];
        }
    }

    $isSentOrOpened = in_array($waStatus, ['sent', 'opened', 'delivered', 'read'], true);

    $kpis['total']++;
    if ($signed) {
        $kpis['signed']++;
    } else {
        $kpis['all_unsigned']++;
        if ($isSentOrOpened) {
            $kpis['sent_unsigned']++;
        } else {
            $kpis['never_sent']++;
        }
    }

    // Apply chase bucket filter
    if ($bucket === 'signed' && !$signed) continue;
    if ($bucket === 'all_unsigned' && $signed) continue;
    if ($bucket === 'never_sent' && ($signed || $isSentOrOpened)) continue;
    if ($bucket === 'sent_unsigned' && ($signed || !$isSentOrOpened)) continue;

    if ($searchQuery !== '') {
        $hay = mb_strtolower($roll . ' ' . (string)$stu['student_name']);
        if (mb_strpos($hay, mb_strtolower($searchQuery)) === false) continue;
    }

    $classLabel = 'STD ' . ($romanMap[$std] ?? $std) . ' ' . $div;
    $rowItem = [
        'std'          => $std,
        'division'     => $div,
        'class_label'  => $classLabel,
        'term_val'     => $termVal,
        'roll_no'      => $roll,
        'student_name' => trim((string)$stu['student_name']),
        'parent_phone' => $phone,
        'pct'          => $pct,
        'signed'       => $signed,
        'wa_status'    => $waStatus,
        'wa_at'        => $waAt,
        'is_sent'      => $isSentOrOpened,
        'class_key'    => $classKeyRaw,
    ];
    $rows[] = $rowItem;

    if (!isset($classRollBuckets[$classKeyRaw])) {
        $classRollBuckets[$classKeyRaw] = ['label' => $classLabel, 'rolls' => []];
    }
    $classRollBuckets[$classKeyRaw]['rolls'][] = $roll;
}

// Primary WhatsApp Hub batch deep-link
$waHubBatchUrl = 'whatsapp_share.php?doc_type=' . rawurlencode($source);
if (!empty($classRollBuckets)) {
    $firstKey = array_key_first($classRollBuckets);
    $rollsCsv = implode(',', $classRollBuckets[$firstKey]['rolls']);
    $waHubBatchUrl .= '&class=' . rawurlencode($firstKey)
        . ($source === 'unit_test' ? '&test_type=' . rawurlencode($selectedTerm) : '')
        . '&selected_rolls=' . rawurlencode($rollsCsv)
        . '&rolls=' . rawurlencode($rollsCsv);
}

// Formula-safe CSV export
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="HCC_ParentSign_Chase_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $selectedYear . '_' . $selectedTerm) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $writeCsv = static function ($fp, array $cells): void {
        fputcsv($fp, array_map(static fn($v) => rcCsvSafeCell((string)$v), $cells));
    };
    $writeCsv($out, [$schoolName . ' — Parent-Sign Chase Queue']);
    $writeCsv($out, ["Year: {$selectedYear}", "Source: {$source}", "Term: {$selectedTerm}", "Bucket: {$bucket}"]);
    $writeCsv($out, []);
    $writeCsv($out, ['Std', 'Div', 'Roll No', 'Student Name', 'Score %', 'Parent Phone', 'WhatsApp Status', 'Dispatch Timestamp', 'Parent Sign']);
    foreach ($rows as $r) {
        $writeCsv($out, [
            $r['std'],
            $r['division'],
            $r['roll_no'],
            $r['student_name'],
            $r['pct'] . '%',
            $r['parent_phone'],
            strtoupper($r['wa_status']),
            $r['wa_at'],
            $r['signed'] ? 'Signed' : 'Unsigned',
        ]);
    }
    fclose($out);
    exit;
}

$exportUrl = '?' . http_build_query([
    'source'   => $source,
    'year'     => $selectedYear,
    'std'      => $selectedStd,
    'division' => $selectedDiv,
    'term'     => $selectedTerm,
    'bucket'   => $bucket,
    'search'   => $searchQuery,
    'action'   => 'export_csv',
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php require __DIR__ . '/includes/account_head.php'; ?>
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>Parent-Sign Chase Queue | <?= sanitize($schoolName) ?></title>
    <link rel="icon" href="<?= BASE_URL ?>assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap@5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin.css?v=<?= is_file(__DIR__ . '/../assets/css/admin.css') ? filemtime(__DIR__ . '/../assets/css/admin.css') : 1 ?>">
    <style>
        .ops-wrap { padding: 22px 26px 40px; }
        .ops-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(185px, 1fr)); gap: 14px; margin-bottom: 18px; }
        .ops-kpi-card { background: #fff; border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; box-shadow: var(--shadow-sm); display: flex; align-items: center; gap: 12px; }
        .ops-kpi-icon { width: 42px; height: 42px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
        .ops-kpi-val { font-size: 1.38rem; font-weight: 800; color: var(--ink); line-height: 1.1; }
        .ops-kpi-lbl { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-2); margin-top: 2px; }
        .ops-panel { background: #fff; border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); padding: 18px 20px; margin-bottom: 18px; }
        .ops-filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(145px, 1fr)); gap: 12px; align-items: end; }
        .ops-field label { display: block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-2); margin-bottom: 5px; }
        .ops-pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; white-space: nowrap; }
        .pill-never { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .pill-sent { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
        .pill-signed { background: #f0fdf4; color: #15803d; border: 1px solid #bbf7d0; }
        .pill-other { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .btn-hcc { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; font-size: 0.84rem; font-weight: 600; border: 1px solid transparent; cursor: pointer; text-decoration: none; transition: all 0.16s ease; }
        .btn-hcc-primary { background: var(--brand); color: #fff; }
        .btn-hcc-primary:hover { background: var(--brand-dark); color: #fff; }
        .btn-hcc-outline { background: #fff; color: var(--ink); border-color: var(--border); }
        .btn-hcc-outline:hover { border-color: var(--brand); color: var(--brand); }
        .btn-hcc-wa { background: #16a34a; color: #fff; }
        .btn-hcc-wa:hover { background: #15803d; color: #fff; }
        .ops-table th { font-size: 0.74rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-2); background: var(--surface); border-bottom: 2px solid var(--border); padding: 10px 12px; white-space: nowrap; }
        .ops-table td { padding: 10px 12px; vertical-align: middle; border-bottom: 1px solid var(--border); font-size: 0.85rem; }
        .print-only-header { display: none; }
        .print-check-col { display: none; }
        @media print {
            @page { size: A4 portrait; margin: 10mm 12mm; }
            .admin-sidebar, .admin-header, .no-print, .ops-kpi-grid { display: none !important; }
            .admin-main { margin-left: 0 !important; }
            .ops-wrap { padding: 0 !important; }
            .ops-panel { box-shadow: none !important; border: none !important; }
            .print-only-header { display: block !important; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 12px; }
            .print-check-col { display: table-cell !important; }
            .ops-table th, .ops-table td { border: 1px solid #334155 !important; padding: 6px 8px !important; font-size: 11px !important; }
        }
    </style>
</head>
<body>
<div class="admin-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <main class="admin-main">
        <header class="admin-header">
            <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle navigation" aria-controls="sidebar"><i class="fa-solid fa-bars"></i></button>
            <div class="header-titles">
                <h1 class="page-title"><i class="fa-solid fa-signature text-warning me-1"></i> Parent-Sign Chase Queue</h1>
                <p class="page-sub">Zero-click queue of unsigned report cards &amp; unit test slips with WhatsApp dispatch cross-check and bench check sheet.</p>
            </div>
            <div class="header-actions no-print">
                <a href="<?= htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-hcc btn-hcc-outline"><i class="fa-solid fa-file-csv text-success"></i> CSV</a>
                <button type="button" class="btn-hcc btn-hcc-outline" onclick="window.print()"><i class="fa-solid fa-print"></i> Print Assembly Bench-Check Sheet</button>
                <a href="<?= htmlspecialchars($waHubBatchUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-hcc btn-hcc-wa"><i class="fa-brands fa-whatsapp"></i> Open in WhatsApp Hub</a>
                <div class="admin-user" title="<?= sanitize($adminUser) ?> — <?= sanitize($adminRole) ?>">
                    <span class="user-avatar" aria-hidden="true"><?= sanitize($adminInitials) ?></span>
                    <span class="user-meta"><strong><?= sanitize($adminUser) ?></strong><small><?= sanitize($adminRole) ?></small></span>
                </div>
            </div>
        </header>

        <div class="ops-wrap">
            <!-- Print-Only Header for Assembly Bench-Check Sheet -->
            <div class="print-only-header">
                <div style="text-align:center;">
                    <div style="font-size:15pt;font-weight:800;text-transform:uppercase;"><?= htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8') ?></div>
                    <div style="font-size:11.5pt;font-weight:700;margin-top:2px;">MORNING ASSEMBLY PARENT-SIGN BENCH-CHECK SHEET</div>
                    <div style="font-size:9.5pt;margin-top:3px;">
                        Academic Year: <strong><?= htmlspecialchars($selectedYear, ENT_QUOTES, 'UTF-8') ?></strong> &bull;
                        Assessment: <strong><?= htmlspecialchars($selectedTerm, ENT_QUOTES, 'UTF-8') ?></strong> &bull;
                        Scope: <strong><?= $selectedStd !== '' ? ('STD ' . htmlspecialchars($romanMap[$selectedStd] ?? $selectedStd, ENT_QUOTES, 'UTF-8') . ($selectedDiv !== '' ? ' ' . htmlspecialchars($selectedDiv, ENT_QUOTES, 'UTF-8') : '')) : 'All Standards' ?></strong> &bull;
                        Printed: <?= date('d/m/Y h:i A') ?>
                    </div>
                </div>
            </div>

            <!-- KPI Cards -->
            <div class="ops-kpi-grid">
                <div class="ops-kpi-card" style="border-left:4px solid #b45309;">
                    <span class="ops-kpi-icon" style="background:#fffbeb;color:#b45309;"><i class="fa-solid fa-signature"></i></span>
                    <div>
                        <div class="ops-kpi-val" id="kpiAllUnsigned"><?= (int)$kpis['all_unsigned'] ?></div>
                        <div class="ops-kpi-lbl">All Unsigned</div>
                    </div>
                </div>
                <div class="ops-kpi-card" style="border-left:4px solid #dc2626;">
                    <span class="ops-kpi-icon" style="background:#fef2f2;color:#dc2626;"><i class="fa-solid fa-paper-plane"></i></span>
                    <div>
                        <div class="ops-kpi-val" id="kpiNeverSent"><?= (int)$kpis['never_sent'] ?></div>
                        <div class="ops-kpi-lbl">Never Sent on WA</div>
                    </div>
                </div>
                <div class="ops-kpi-card" style="border-left:4px solid #0284c7;">
                    <span class="ops-kpi-icon" style="background:#f0f9ff;color:#0369a1;"><i class="fa-solid fa-hourglass-half"></i></span>
                    <div>
                        <div class="ops-kpi-val" id="kpiSentUnsigned"><?= (int)$kpis['sent_unsigned'] ?></div>
                        <div class="ops-kpi-lbl">Sent, Awaiting Sign</div>
                    </div>
                </div>
                <div class="ops-kpi-card" style="border-left:4px solid #15803d;">
                    <span class="ops-kpi-icon" style="background:#f0fdf4;color:#15803d;"><i class="fa-solid fa-circle-check"></i></span>
                    <div>
                        <div class="ops-kpi-val" id="kpiSigned"><?= (int)$kpis['signed'] ?> / <?= (int)$kpis['total'] ?></div>
                        <div class="ops-kpi-lbl">Verified Signed</div>
                    </div>
                </div>
            </div>

            <!-- Filters -->
            <form method="GET" class="ops-panel no-print">
                <div class="ops-filter-grid">
                    <div class="ops-field">
                        <label for="fSource">Document Source</label>
                        <select id="fSource" name="source" class="form-select form-select-sm" onchange="this.form.submit()">
                            <option value="report_card" <?= $source === 'report_card' ? 'selected' : '' ?>>Semester Report Card</option>
                            <option value="unit_test" <?= $source === 'unit_test' ? 'selected' : '' ?>>Unit Test Slip</option>
                        </select>
                    </div>
                    <div class="ops-field">
                        <label for="fYear">Academic Year</label>
                        <select id="fYear" name="year" class="form-select form-select-sm">
                            <?php foreach ($yearOptions as $yr): ?>
                                <option value="<?= htmlspecialchars($yr, ENT_QUOTES, 'UTF-8') ?>" <?= $yr === $selectedYear ? 'selected' : '' ?>><?= htmlspecialchars($yr, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ops-field">
                        <label for="fTerm"><?= $source === 'unit_test' ? 'Unit Test' : 'Semester' ?></label>
                        <select id="fTerm" name="term" class="form-select form-select-sm">
                            <?php foreach ($termOptions as $tm): ?>
                                <option value="<?= htmlspecialchars($tm, ENT_QUOTES, 'UTF-8') ?>" <?= $tm === $selectedTerm ? 'selected' : '' ?>><?= htmlspecialchars($tm, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ops-field">
                        <label for="fStd">Standard</label>
                        <select id="fStd" name="std" class="form-select form-select-sm">
                            <option value="">All Standards</option>
                            <?php foreach ($stdOptions as $st): ?>
                                <option value="<?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?>" <?= $st === $selectedStd ? 'selected' : '' ?>>STD <?= htmlspecialchars($romanMap[$st] ?? $st, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ops-field">
                        <label for="fDiv">Division</label>
                        <select id="fDiv" name="division" class="form-select form-select-sm">
                            <option value="">All Divisions</option>
                            <?php foreach ($divOptions as $dv): ?>
                                <option value="<?= htmlspecialchars($dv, ENT_QUOTES, 'UTF-8') ?>" <?= $dv === $selectedDiv ? 'selected' : '' ?>><?= htmlspecialchars($dv, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ops-field">
                        <label for="fBucket">Chase Bucket</label>
                        <select id="fBucket" name="bucket" class="form-select form-select-sm">
                            <option value="all_unsigned" <?= $bucket === 'all_unsigned' ? 'selected' : '' ?>>All Unsigned (parents_sign = 0)</option>
                            <option value="never_sent" <?= $bucket === 'never_sent' ? 'selected' : '' ?>>Never Sent</option>
                            <option value="sent_unsigned" <?= $bucket === 'sent_unsigned' ? 'selected' : '' ?>>Sent but Unsigned</option>
                            <option value="signed" <?= $bucket === 'signed' ? 'selected' : '' ?>>Already Signed (Verified)</option>
                        </select>
                    </div>
                    <div class="ops-field">
                        <label for="fSearch">Roll / Name</label>
                        <input id="fSearch" type="text" name="search" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>" placeholder="Search roll or name..." class="form-control form-control-sm">
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn-hcc btn-hcc-primary btn-sm w-100 justify-content-center"><i class="fa-solid fa-filter"></i> Filter</button>
                        <a href="parent_sign_chase.php" class="btn-hcc btn-hcc-outline btn-sm" title="Reset"><i class="fa-solid fa-rotate-left"></i></a>
                    </div>
                </div>

                <?php if (count($classRollBuckets) > 1): ?>
                <div class="mt-3 pt-2 border-top d-flex align-items-center gap-2 flex-wrap">
                    <span class="small fw-bold text-secondary"><i class="fa-brands fa-whatsapp text-success"></i> Quick Class Batch Launch:</span>
                    <?php foreach ($classRollBuckets as $ck => $info):
                        $rollsCsv = implode(',', $info['rolls']);
                        $link = 'whatsapp_share.php?doc_type=' . rawurlencode($source)
                              . '&class=' . rawurlencode($ck)
                              . ($source === 'unit_test' ? '&test_type=' . rawurlencode($selectedTerm) : '')
                              . '&selected_rolls=' . rawurlencode($rollsCsv)
                              . '&rolls=' . rawurlencode($rollsCsv);
                    ?>
                        <a href="<?= htmlspecialchars($link, ENT_QUOTES, 'UTF-8') ?>" class="btn btn-sm btn-outline-success py-0 px-2" style="font-size:0.75rem;">
                            <?= htmlspecialchars($info['label'], ENT_QUOTES, 'UTF-8') ?> (<?= count($info['rolls']) ?>)
                        </a>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </form>

            <!-- Chase Table -->
            <div class="ops-panel p-0 overflow-hidden">
                <div class="d-flex justify-content-between align-items-center px-3 py-2 border-bottom bg-light no-print">
                    <div class="small fw-bold text-secondary">
                        Showing <span class="text-dark" id="chaseRowCount"><?= count($rows) ?></span> student(s) in queue
                    </div>
                    <div class="small text-muted">Click <strong>Mark Signed</strong> to record parent confirmation live.</div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover mb-0 ops-table">
                        <thead>
                            <tr>
                                <th>Class</th>
                                <th>Roll No</th>
                                <th>Student Name</th>
                                <th>Result %</th>
                                <th>Parent Phone</th>
                                <th>WhatsApp Dispatch</th>
                                <th>Sign Status</th>
                                <th class="print-check-col">Bench Check [ &#10003; ]</th>
                                <th class="no-print text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="9" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-clipboard-check fa-2x text-success mb-2 d-block"></i>
                                    <strong>No students match this chase bucket!</strong>
                                    <div class="small mt-1">All parents in the selected filter have signed, or try switching the bucket / academic year.</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $r):
                                $rowUrl = 'whatsapp_share.php?doc_type=' . rawurlencode($source)
                                        . '&class=' . rawurlencode($r['class_key'])
                                        . ($source === 'unit_test' ? '&test_type=' . rawurlencode($selectedTerm) : '')
                                        . '&selected_rolls=' . rawurlencode($r['roll_no'])
                                        . '&rolls=' . rawurlencode($r['roll_no']);
                            ?>
                            <tr data-roll="<?= htmlspecialchars($r['roll_no'], ENT_QUOTES, 'UTF-8') ?>" data-sent="<?= $r['is_sent'] ? '1' : '0' ?>">
                                <td><span class="badge text-bg-light border"><?= htmlspecialchars($r['class_label'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td class="fw-bold">#<?= htmlspecialchars($r['roll_no'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="fw-semibold text-dark"><?= htmlspecialchars($r['student_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= number_format($r['pct'], 1) ?>%</td>
                                <td>
                                    <?php if ($r['parent_phone'] !== ''): ?>
                                        <span class="font-monospace small"><?= htmlspecialchars($r['parent_phone'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <?php else: ?>
                                        <span class="text-danger small fw-semibold">Missing Phone</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($r['is_sent']): ?>
                                        <span class="ops-pill pill-sent"><i class="fa-solid fa-check-double"></i> <?= htmlspecialchars(strtoupper($r['wa_status']), ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if ($r['wa_at'] !== ''): ?>
                                            <div class="small text-muted mt-1"><?= htmlspecialchars(date('d M, h:i A', strtotime($r['wa_at'])), ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                    <?php else: ?>
                                        <span class="ops-pill pill-never"><i class="fa-solid fa-circle-xmark"></i> Never Sent</span>
                                    <?php endif; ?>
                                </td>
                                <td class="js-sign-cell">
                                    <?php if ($r['signed']): ?>
                                        <span class="ops-pill pill-signed"><i class="fa-solid fa-check"></i> Signed</span>
                                    <?php else: ?>
                                        <span class="ops-pill pill-never"><i class="fa-regular fa-clock"></i> Unsigned</span>
                                    <?php endif; ?>
                                </td>
                                <td class="print-check-col">
                                    <span style="display:inline-block;width:14px;height:14px;border:1.5px solid #000;vertical-align:middle;margin-right:6px;"></span>
                                    <span style="color:#475569;">Sign / Note: ____________</span>
                                </td>
                                <td class="no-print text-end">
                                    <div class="d-inline-flex gap-1">
                                        <button type="button"
                                                class="btn-hcc btn-sm <?= $r['signed'] ? 'btn-hcc-outline' : 'btn-hcc-primary' ?> js-toggle-sign"
                                                data-roll="<?= htmlspecialchars($r['roll_no'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-next-sign="<?= $r['signed'] ? '0' : '1' ?>"
                                                data-table="<?= $source === 'unit_test' ? 'unit_test_results' : 'report_card' ?>"
                                                data-class-key="<?= htmlspecialchars($r['class_key'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-year="<?= htmlspecialchars($selectedYear, ENT_QUOTES, 'UTF-8') ?>"
                                                data-std="<?= htmlspecialchars($r['std'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-division="<?= htmlspecialchars($r['division'], ENT_QUOTES, 'UTF-8') ?>"
                                                data-test-type="<?= htmlspecialchars($selectedTerm, ENT_QUOTES, 'UTF-8') ?>">
                                            <i class="fa-solid <?= $r['signed'] ? 'fa-rotate-left' : 'fa-check' ?>"></i>
                                            <span><?= $r['signed'] ? 'Undo Sign' : 'Mark Signed' ?></span>
                                        </button>
                                        <a href="<?= htmlspecialchars($rowUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-hcc btn-hcc-outline btn-sm" title="Open student in WhatsApp Hub">
                                            <i class="fa-brands fa-whatsapp text-success"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <!-- Print-only Footer -->
                <div class="print-only-header" style="border-bottom:none;border-top:1px solid #000;margin-top:20px;padding-top:12px;">
                    <div style="display:flex;justify-content:space-between;font-size:10pt;font-weight:700;">
                        <span>Class Teacher Signature: ______________________</span>
                        <span>Supervisor / Principal: ______________________</span>
                    </div>
                </div>
            </div>
        </div>
    </main>
</div>

<script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 1 ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';

    document.querySelectorAll('.js-toggle-sign').forEach((btn) => {
        btn.addEventListener('click', async () => {
            if (btn.disabled) return;
            const nextSign = btn.getAttribute('data-next-sign') === '1' ? '1' : '0';
            const origHtml = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Saving...';

            const fd = new FormData();
            fd.append('csrf_token', csrfToken);
            fd.append('roll', btn.getAttribute('data-roll') || '');
            fd.append('sign', nextSign);
            fd.append('table', btn.getAttribute('data-table') || 'report_card');
            if (btn.getAttribute('data-table') === 'unit_test_results') {
                fd.append('year', btn.getAttribute('data-year') || '');
                fd.append('std', btn.getAttribute('data-std') || '');
                fd.append('division', btn.getAttribute('data-division') || '');
                fd.append('test_type', btn.getAttribute('data-test-type') || '');
            } else {
                fd.append('class_key', btn.getAttribute('data-class-key') || '');
            }

            try {
                const resp = await fetch('api_parent_sign.php', {
                    method: 'POST',
                    headers: { 'X-CSRF-Token': csrfToken },
                    body: fd
                });
                const res = await resp.json();
                if (!resp.ok || !res.ok) {
                    alert(res.error || 'Could not update parent signature.');
                    btn.innerHTML = origHtml;
                    btn.disabled = false;
                    return;
                }

                const tr = btn.closest('tr');
                const signCell = tr ? tr.querySelector('.js-sign-cell') : null;
                const isNowSigned = nextSign === '1';

                if (signCell) {
                    signCell.innerHTML = isNowSigned
                        ? '<span class="ops-pill pill-signed"><i class="fa-solid fa-check"></i> Signed</span>'
                        : '<span class="ops-pill pill-never"><i class="fa-regular fa-clock"></i> Unsigned</span>';
                }

                btn.setAttribute('data-next-sign', isNowSigned ? '0' : '1');
                btn.className = 'btn-hcc btn-sm js-toggle-sign ' + (isNowSigned ? 'btn-hcc-outline' : 'btn-hcc-primary');
                btn.innerHTML = isNowSigned
                    ? '<i class="fa-solid fa-rotate-left"></i> <span>Undo Sign</span>'
                    : '<i class="fa-solid fa-check"></i> <span>Mark Signed</span>';

                // Update live KPI counters cleanly
                const kpiAll = document.getElementById('kpiAllUnsigned');
                const kpiNever = document.getElementById('kpiNeverSent');
                const kpiSent = document.getElementById('kpiSentUnsigned');
                const kpiSigned = document.getElementById('kpiSigned');
                const delta = isNowSigned ? -1 : 1;
                if (kpiAll) kpiAll.textContent = Math.max(0, parseInt(kpiAll.textContent || '0', 10) + delta);
                const wasSent = tr && tr.getAttribute('data-sent') === '1';
                if (wasSent && kpiSent) kpiSent.textContent = Math.max(0, parseInt(kpiSent.textContent || '0', 10) + delta);
                if (!wasSent && kpiNever) kpiNever.textContent = Math.max(0, parseInt(kpiNever.textContent || '0', 10) + delta);
                if (kpiSigned) {
                    const parts = (kpiSigned.textContent || '0 / 0').split('/');
                    const curS = Math.max(0, parseInt(parts[0] || '0', 10) + (isNowSigned ? 1 : -1));
                    kpiSigned.textContent = `${curS} / ${(parts[1] || '0').trim()}`;
                }
            } catch (err) {
                alert('Network error updating signature.');
                btn.innerHTML = origHtml;
            } finally {
                btn.disabled = false;
            }
        });
    });
});
</script>
</body>
</html>
