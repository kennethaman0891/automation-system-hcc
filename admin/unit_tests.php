<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

$adminPage = 'unit_tests';
$db = getDB();
rcEnsureUnitTestTables();
$settings = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church English Medium School, Solapur';
$adminUser = sanitize($_SESSION['admin_name'] ?? 'Admin');

// Current Filter Parameters
$activeYear = rcGetActiveAcademicYear();
$year       = trim($_GET['year'] ?? $activeYear);
if ($year === '') $year = $activeYear;
$std        = trim($_GET['std'] ?? '1');
$division   = trim($_GET['division'] ?? 'A');
$testType   = trim($_GET['test_type'] ?? 'Unit Test 2');
$yearOptions = rcGetAcademicYearOptions();
if (!in_array($year, $yearOptions, true)) array_unshift($yearOptions, $year);

$romanMap = ['1'=>'I','2'=>'II','3'=>'III','4'=>'IV','5'=>'V','6'=>'VI','7'=>'VII','8'=>'VIII','9'=>'IX','10'=>'X'];
$romanStd = $romanMap[(string)$std] ?? $std;

$error = '';
$success = '';

// Excel Export Action
if (isset($_GET['action']) && $_GET['action'] === 'export_excel') {
    require_once __DIR__ . '/../vendor/autoload.php';
    $data = rcGetUnitTestClassData($year, $std, $division, $testType);
    
    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle("STD $std $division");

    // Title
    $sheet->setCellValue('A1', rcCsvSafeCell($schoolName));
    $sheet->mergeCells('A1:L1');
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    // Meta row
    $sheet->setCellValue('A2', rcCsvSafeCell("STD:- " . $std . " " . $division));
    $sheet->setCellValue('D2', rcCsvSafeCell("UNIT TEST :- " . strtoupper($testType)));
    $sheet->setCellValue('H2', rcCsvSafeCell("YEAR :- " . $year));
    $sheet->getStyle('A2:H2')->getFont()->setBold(true);

    // Header Row 3
    $sheet->setCellValue('A3', 'Roll No');
    $sheet->setCellValue('B3', 'Name of the Students');
    $colLetter = 'C';
    foreach ($data['subjects'] as $s) {
        $sheet->setCellValue($colLetter . '3', rcCsvSafeCell($s['subject_name']));
        $colLetter++;
    }
    $sheet->setCellValue($colLetter . '3', 'Total'); $totCol = $colLetter++;
    $sheet->setCellValue($colLetter . '3', '%'); $pctCol = $colLetter++;
    $sheet->setCellValue($colLetter . '3', 'Grade'); $grdCol = $colLetter++;
    $sheet->setCellValue($colLetter . '3', 'Remark'); $rmkCol = $colLetter++;
    $sheet->setCellValue($colLetter . '3', 'Attn'); $attnCol = $colLetter++;
    $sheet->setCellValue($colLetter . '3', 'Parents Sign'); $signCol = $colLetter;

    // Sub-header Row 4 (Max Marks)
    $colLetter = 'C';
    foreach ($data['subjects'] as $s) {
        $sheet->setCellValue($colLetter . '4', $s['max_marks']);
        $colLetter++;
    }
    $sheet->setCellValue($totCol . '4', $data['total_max']);

    // Data rows
    $rowIdx = 5;
    foreach ($data['students'] as $stu) {
        $sheet->setCellValue('A' . $rowIdx, rcCsvSafeCell($stu['roll_no']));
        $sheet->setCellValue('B' . $rowIdx, rcCsvSafeCell($stu['student_name']));
        $cLet = 'C';
        foreach ($data['subjects'] as $s) {
            $sName = $s['subject_name'];
            $sm = $stu['subjects'][$sName] ?? null;
            if ($sm && $sm['is_absent']) {
                $sheet->setCellValue($cLet . $rowIdx, '-');
            } elseif ($sm && $sm['marks_obtained'] !== null) {
                $sheet->setCellValue($cLet . $rowIdx, (float)$sm['marks_obtained']);
            } else {
                $sheet->setCellValue($cLet . $rowIdx, '');
            }
            $cLet++;
        }
        $sheet->setCellValue($totCol . $rowIdx, !empty($stu['has_entered']) ? $stu['total_obtained'] : '');
        $sheet->setCellValue($pctCol . $rowIdx, $stu['percentage'] !== null ? $stu['percentage'] : '-');
        $sheet->setCellValue($grdCol . $rowIdx, rcCsvSafeCell($stu['grade']));
        $sheet->setCellValue($rmkCol . $rowIdx, rcCsvSafeCell($stu['remark']));
        $sheet->setCellValue($attnCol . $rowIdx, rcCsvSafeCell($stu['attendance']));
        $sheet->setCellValue($signCol . $rowIdx, !empty($stu['parents_sign']) ? 'Yes' : 'No');
        $rowIdx++;
    }

    $filename = preg_replace('/[^A-Za-z0-9_.-]/', '_', "STD_{$std}_{$division}_{$testType}_{$year}.xlsx");
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// POST Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        if (!empty($_POST['is_ajax'])) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Security token expired. Please refresh the page.']);
            exit;
        }
        $error = 'Security check failed. Please reload the page.';
    } else {
        $action = trim($_POST['action'] ?? '');

        // 1. Bulk Save Marks
        if ($action === 'save_marks') {
            $pYear = trim($_POST['year'] ?? $year);
            $pStd  = trim($_POST['std'] ?? $std);
            $pDiv  = trim($_POST['division'] ?? $division);
            $pTest = trim($_POST['test_type'] ?? $testType);

            $rawMarks = $_POST['marks'] ?? [];
            $rawRemarks = $_POST['remarks'] ?? [];
            $rawAttn = $_POST['attn'] ?? [];

            $payload = [];
            foreach ($rawMarks as $roll => $subs) {
                $payload[$roll] = [
                    'name'       => trim($_POST['names'][$roll] ?? ''),
                    'gender'     => trim($_POST['genders'][$roll] ?? ''),
                    'attendance' => trim($rawAttn[$roll] ?? ''),
                    'marks'      => is_array($subs) ? $subs : [],
                ];
            }

            $res = rcSaveUnitTestMarksBulk($pYear, $pStd, $pDiv, $pTest, $payload, $rawRemarks, $rawAttn, $adminUser);

            if (!empty($_POST['is_ajax'])) {
                header('Content-Type: application/json');
                echo json_encode($res);
                exit;
            }

            if ($res['ok']) {
                $success = $res['message'];
            } else {
                $error = $res['error'];
            }
        }

        // 2. Sync Roster from Active Cohort
        elseif ($action === 'sync_roster') {
            $sourceYear = trim($_POST['source_year'] ?? '');
            if ($sourceYear === '') $sourceYear = $year;
            $res = rcSyncUnitTestRoster($sourceYear, $std, $division, $testType, $adminUser);
            if ($res['ok']) {
                $success = $res['message'];
            } else {
                $error = $res['error'];
            }
        }

        // 3. Bulk Delete Students (same class context as the page filter)
        elseif ($action === 'delete_bulk') {
            $dYear = trim($_POST['year'] ?? $year);
            $dStd  = trim($_POST['std'] ?? $std);
            $dDiv  = trim($_POST['division'] ?? $division);
            $dTest = trim($_POST['test_type'] ?? $testType);
            $rawRolls = $_POST['rolls'] ?? [];
            if (!is_array($rawRolls)) $rawRolls = [];
            if (empty($rawRolls)) {
                $error = 'No students selected.';
            } else {
                $res = rcDeleteUnitTestStudents($dYear, $dStd, $dDiv, $dTest, $rawRolls);
                if ($res['ok']) {
                    $n = (int)$res['deleted'];
                    $success = 'Deleted ' . $n . ' student' . ($n !== 1 ? 's' : '') . ' from Unit Test results.';
                } else {
                    $error = $res['error'] !== '' ? $res['error'] : 'Bulk delete failed.';
                }
            }
        }

        // 4. Mark Notifications Read
        elseif ($action === 'mark_notifs_read') {
            rcMarkNotificationsRead();
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
    }
}

// Fetch Matrix Dataset
$matrixData = rcGetUnitTestClassData($year, $std, $division, $testType);
$students   = $matrixData['students'];
$subjects   = $matrixData['subjects'];
$totalMax   = $matrixData['total_max'];

// Resolve cohort semester dynamically from report_card instead of hardcoding 'Sem 1'
$preferSem = in_array($testType, ['Unit Test 3', 'Unit Test 4'], true) ? 'Sem 2' : 'Sem 1';
$semStmt = $db->prepare("SELECT semester FROM report_card WHERE academic_year = ? AND std = ? AND division = ? ORDER BY (semester = ?) DESC, id ASC LIMIT 1");
$semStmt->execute([$year, $std, $division, $preferSem]);
$cohortSem = trim((string)$semStmt->fetchColumn()) ?: $preferSem;
$cohortClassKey = rcBuildClassKey($year, $std, $division, $cohortSem);

// Available standards and classes
$availableClasses = rcClassOptions();
$notifData = rcGetRecentNotifications(8);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php require __DIR__ . '/includes/account_head.php'; ?>
    <title>Unit Test Results — <?= sanitize($schoolName) ?></title>
    <link rel="icon" href="../assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="../assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
    <link rel="stylesheet" href="../assets/css/whatsapp-share.css?v=<?= filemtime(__DIR__ . '/../assets/css/whatsapp-share.css') ?>">
    <style>
        /* Matrix specific styling */
        .ut-filter-bar {
            background: var(--paper);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: var(--shadow-sm);
        }
        .ut-filter-inputs {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .ut-filter-inputs input {
            padding: 8px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.88rem;
            font-weight: 600;
            background: var(--bg);
            color: var(--text);
        }
        .ut-select {
            padding: 8px 34px 8px 12px;
            border: 1px solid var(--border);
            border-radius: 8px;
            font-size: 0.88rem;
            font-weight: 600;
            font-family: inherit;
            background-color: var(--bg);
            color: var(--text);
            cursor: pointer;
            outline: none;
            appearance: none;
            -webkit-appearance: none;
            background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='12' height='12' viewBox='0 0 24 24' fill='none' stroke='%23718096' stroke-width='2.5' stroke-linecap='round' stroke-linejoin='round'%3E%3Cpolyline points='6 9 12 15 18 9'%3E%3C/polyline%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 12px center;
            transition: border-color 0.15s ease, box-shadow 0.15s ease, background-color 0.15s ease;
        }
        .ut-select:hover {
            border-color: var(--brand);
            background-color: var(--paper);
        }
        .ut-select:focus {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(201, 162, 39, 0.18);
        }

        /* KPI Cards */
        .ut-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        .ut-kpi-card {
            background: var(--paper);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: var(--shadow-sm);
        }
        .ut-kpi-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        .ut-kpi-icon.primary { background: #fee2e2; color: #991b1b; }
        .ut-kpi-icon.success { background: #dcfce7; color: #166534; }
        .ut-kpi-icon.amber   { background: #fef3c7; color: #92400e; }
        .ut-kpi-icon.blue    { background: #e0f2fe; color: #0284c7; }
        .ut-kpi-val { font-size: 1.35rem; font-weight: 800; color: var(--text); line-height: 1.1; }
        .ut-kpi-lbl { font-size: 0.78rem; color: var(--muted); font-weight: 500; margin-top: 4px; }

        /* Two-Tier Table Matrix */
        .ut-table-card {
            background: var(--paper);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .ut-table-wrapper {
            overflow-x: auto;
            max-height: 75vh;
        }
        .ut-matrix-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 0.85rem;
        }
        .ut-matrix-table th {
            background: linear-gradient(180deg, var(--brand-deep), var(--brand));
            color: #fff;
            padding: 10px;
            text-align: center;
            font-weight: 700;
            font-size: 0.78rem;
            letter-spacing: 0.02em;
            border-bottom: 2px solid var(--gold);
            border-right: 1px solid rgba(255,255,255,0.14);
            position: sticky;
            top: 0;
            z-index: 10;
            white-space: nowrap;
        }
        .ut-matrix-table th.th-sub-max {
            background: #faf7f4;
            color: var(--brand);
            font-size: 0.76rem;
            font-weight: 800;
            top: 39px;
            z-index: 9;
            border-bottom: 2px solid var(--gold);
        }
        .ut-matrix-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #ece5e7;
            border-right: 1px solid #f1ecee;
            text-align: center;
            vertical-align: middle;
            background: #fff;
        }
        .ut-matrix-table tbody tr:nth-child(even) td { background: #fdfbfb; }
        .ut-matrix-table tbody tr:hover td { background: rgba(201,162,39,0.07); }

        /* Sticky Columns */
        .ut-check {
            position: sticky;
            left: 0;
            z-index: 5;
            background: #fff !important;
            text-align: center;
            width: 44px;
            min-width: 44px;
            border-right: 2px solid #cbd5e1 !important;
        }
        th.ut-check { z-index: 15 !important; background: linear-gradient(180deg, var(--brand-deep), var(--brand)) !important; }
        th.th-sub-max.ut-check { z-index: 14 !important; background: #faf7f4 !important; }
        .ut-check-box, .ut-row-check { width: 16px; height: 16px; accent-color: #dc2626; cursor: pointer; vertical-align: middle; }
        tr.is-checked td { background: #fef2f2; }
        tr.is-checked td.ut-check,
        tr.is-checked td.ut-sticky-roll,
        tr.is-checked td.ut-sticky-name { background: #fee2e2 !important; }
        .ut-bulk-count {
            display: inline-block; min-width: 20px; text-align: center;
            background: rgba(255,255,255,0.25); border-radius: 999px;
            font-size: 0.72rem; font-weight: 800; padding: 1px 6px; margin-left: 4px;
        }
        #utBulkDeleteBtn:disabled { opacity: 0.55; cursor: not-allowed; }
        /* Quick actions: row 1 filters + primary actions, row 2 secondary + bulk actions, all neutral */
        .ut-filter-bar { flex-direction: column; align-items: stretch; justify-content: flex-start; gap: 14px; }
        .ut-filter-top {
            display: flex; align-items: flex-end; justify-content: space-between;
            flex-wrap: wrap; gap: 12px 16px;
        }
        .ut-filter-inputs { align-items: flex-end; }
        .ut-actions-primary {
            display: flex; align-items: center; flex-wrap: wrap; gap: 8px;
            margin-left: auto;
        }
        .ut-actions {
            display: flex; align-items: center; justify-content: flex-start;
            flex-wrap: wrap; gap: 8px;
            padding-top: 14px; border-top: 1px solid var(--border, #e5e7eb);
        }
        .ut-actions-sep { width: 1px; align-self: stretch; min-height: 24px; background: var(--border, #e5e7eb); margin: 0 4px; }
        .ut-autosave { font-size: 0.8rem; font-weight: 600; white-space: nowrap; }
        .ut-autosave:empty { display: none; }
        .ut-filter-bar .btn,
        .ut-filter-bar .btn.btn-whatsapp {
            display: inline-flex; align-items: center; justify-content: center;
            height: 36px; box-sizing: border-box; white-space: nowrap; vertical-align: middle;
            background: var(--paper, #fff);
            color: var(--text, #1f2937) !important;
            border: 1px solid var(--border, #e5e7eb);
            box-shadow: none;
            font-weight: 600;
            gap: 6px;
            padding: 6px 14px;
            font-size: 0.8rem;
            border-radius: var(--radius-sm, 8px);
            line-height: 1.5;
            transform: none;
            filter: none;
        }
        .ut-filter-bar .btn i { color: var(--text-2, #4b5563); width: 1em; text-align: center; flex-shrink: 0; }
        .ut-filter-bar .btn:hover:not(:disabled),
        .ut-filter-bar .btn.btn-whatsapp:hover:not(:disabled) {
            background: var(--bg, #f3f4f6);
            border-color: #cfc5c9;
            color: var(--text, #1f2937) !important;
            box-shadow: none;
            transform: none;
            filter: none;
        }
        .ut-filter-bar .btn:focus-visible { outline: 2px solid var(--text-2, #4b5563); outline-offset: 2px; }
        .ut-filter-bar .btn:disabled { opacity: 0.55; cursor: not-allowed; }
        .ut-filter-bar .ut-bulk-count { background: var(--bg, #f3f4f6); color: var(--text, #1f2937); }
        @media (max-width: 640px) {
            .ut-actions-primary { width: 100%; margin-left: 0; justify-content: flex-start; }
            .ut-actions-sep { display: none; }
        }
        /* Bulk-delete confirm modal */
        .ut-confirm-modal { position: fixed; inset: 0; z-index: 1050; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .ut-confirm-modal[hidden] { display: none; }
        .ut-confirm-backdrop { position: absolute; inset: 0; background: rgba(42,16,24,0.55); }
        .ut-confirm-card { position: relative; background: #fff; border-radius: 12px; box-shadow: 0 24px 60px rgba(0,0,0,0.25); max-width: 420px; width: 100%; padding: 28px 26px; text-align: center; }
        .ut-confirm-icon { width: 52px; height: 52px; margin: 0 auto 14px; display: flex; align-items: center; justify-content: center; border-radius: 50%; background: #fef2f2; color: #dc2626; font-size: 1.3rem; }
        .ut-confirm-card h3 { margin: 0 0 8px; font-size: 1.1rem; font-weight: 800; color: #0f172a; }
        .ut-confirm-card p { margin: 0 0 20px; font-size: 0.87rem; color: #64748b; line-height: 1.55; }
        .ut-confirm-actions { display: flex; gap: 10px; justify-content: center; }
        .ut-sticky-roll {
            position: sticky;
            left: 44px;
            z-index: 5;
            background: #fff !important;
            font-weight: 800;
            color: var(--brand);
            width: 60px;
            border-right: 2px solid #cbd5e1 !important;
        }
        .ut-sticky-name {
            position: sticky;
            left: 104px;
            z-index: 5;
            background: #fff !important;
            text-align: left !important;
            font-weight: 700;
            min-width: 220px;
            max-width: 280px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            border-right: 2px solid #cbd5e1 !important;
        }
        th.ut-sticky-roll, th.ut-sticky-name { z-index: 15 !important; background: linear-gradient(180deg, var(--brand-deep), var(--brand)) !important; }
        th.th-sub-max.ut-sticky-roll, th.th-sub-max.ut-sticky-name { z-index: 14 !important; background: #faf7f4 !important; }

        /* Mark Inputs */
        .ut-mark-input {
            width: 58px;
            padding: 5px 6px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            text-align: center;
            font-weight: 700;
            font-size: 0.88rem;
            color: var(--text);
            background: #fff;
            transition: all 0.15s ease;
        }
        .ut-mark-input:focus {
            outline: none;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(122, 34, 56, 0.12);
            background: #fffdf8;
        }
        .ut-attn-input {
            width: 48px;
            padding: 4px 6px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            text-align: center;
            font-size: 0.82rem;
            font-weight: 600;
        }
        .ut-cell-remark {
            width: 120px;
            padding: 4px 6px;
            border: 1px solid transparent;
            border-radius: 4px;
            font-size: 0.78rem;
            background: transparent;
        }
        .ut-cell-remark:focus {
            border-color: #cbd5e1;
            background: #fff;
        }

        /* Grade Badges */
        .ut-cell-grade {
            font-weight: 800;
            font-size: 0.82rem;
            padding: 2px 10px;
            border-radius: 999px;
            display: inline-block;
            color: var(--brand-deep);
            background: var(--gold-glow);
            border: 1px solid rgba(201, 162, 39, 0.45);
            min-width: 34px;
        }
        .ut-cell-total { font-weight: 800; color: var(--brand-deep); background: rgba(201,162,39,0.10) !important; font-variant-numeric: tabular-nums; }
        .ut-cell-pct { font-weight: 800; color: var(--text); font-variant-numeric: tabular-nums; }

        /* Interactive Parent Signature Toggle */
        .ut-sign-toggle {
            width: 30px;
            height: 30px;
            border-radius: 50%;
            border: 1.5px solid #cbd5e1;
            background: #fff;
            color: #94a3b8;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            transition: all 0.15s ease;
            font-size: 0.85rem;
        }
        .ut-sign-toggle:hover {
            border-color: var(--brand);
            color: var(--brand);
            transform: scale(1.06);
        }
        .ut-sign-toggle.is-signed {
            background: #dcfce7;
            border-color: #16a34a;
            color: #15803d;
        }
        .ut-sign-toggle.is-busy {
            opacity: 0.55;
            pointer-events: none;
        }
        .ut-sign-toggle.is-error {
            border-color: #dc2626;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.2);
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <main class="admin-main">
            <header class="admin-header">
                <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <div class="header-titles">
                    <h1 class="page-title">Unit Test Results Matrix</h1>
                    <p class="page-sub"><?= sanitize($schoolName) ?> &bull; Periodic Assessments</p>
                </div>

                <div class="header-actions" style="display:flex;align-items:center;">
                    <!-- Notification Bell -->
                    <?php require __DIR__ . '/includes/notif_bell.php'; ?>

                    <div class="admin-user">
                        <i class="fas fa-user-circle"></i>
                        <span><?= sanitize($adminUser) ?></span>
                    </div>
                </div>
            </header>

            <div class="admin-content">

                <!-- Flash Messages -->
                <?php if ($success !== ''): ?>
                <div style="margin-bottom:16px;padding:14px 20px;border-radius:10px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;">
                    <i class="fas fa-check-circle" style="margin-right:8px;"></i> <?= sanitize($success) ?>
                </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                <div style="margin-bottom:16px;padding:14px 20px;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;">
                    <i class="fas fa-exclamation-circle" style="margin-right:8px;"></i> <?= sanitize($error) ?>
                </div>
                <?php endif; ?>

                <!-- Filter & Class Selector Bar -->
                <div class="ut-filter-bar">
                    <!-- Row 1: class filters (left) + primary record actions (right) -->
                    <div class="ut-filter-top">
                        <form method="GET" class="ut-filter-inputs">
                            <div>
                                <label style="font-size:0.75rem;font-weight:700;color:var(--muted);display:block;margin-bottom:4px;">Academic Year</label>
                                <select class="ut-select" name="year" onchange="this.form.submit()">
                                    <?php foreach ($yearOptions as $yOpt): ?>
                                        <option value="<?= sanitize($yOpt) ?>" <?= $year === $yOpt ? 'selected' : '' ?>><?= sanitize($yOpt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div>
                                <label style="font-size:0.75rem;font-weight:700;color:var(--muted);display:block;margin-bottom:4px;">Standard</label>
                                <select class="ut-select" name="std" onchange="this.form.submit()">
                                    <?php for ($s = 1; $s <= 10; $s++): ?>
                                        <option value="<?= $s ?>" <?= (string)$std === (string)$s ? 'selected' : '' ?>>STD <?= $s ?></option>
                                    <?php endfor; ?>
                                </select>
                            </div>

                            <div>
                                <label style="font-size:0.75rem;font-weight:700;color:var(--muted);display:block;margin-bottom:4px;">Division</label>
                                <select class="ut-select" name="division" onchange="this.form.submit()">
                                    <option value="A" <?= $division === 'A' ? 'selected' : '' ?>>Div A</option>
                                    <option value="B" <?= $division === 'B' ? 'selected' : '' ?>>Div B</option>
                                    <option value="C" <?= $division === 'C' ? 'selected' : '' ?>>Div C</option>
                                </select>
                            </div>

                            <div>
                                <label style="font-size:0.75rem;font-weight:700;color:var(--muted);display:block;margin-bottom:4px;">Exam / Test Type</label>
                                <select class="ut-select" name="test_type" onchange="this.form.submit()">
                                    <option value="Unit Test 1" <?= $testType === 'Unit Test 1' ? 'selected' : '' ?>>Unit Test 1 (I)</option>
                                    <option value="Unit Test 2" <?= $testType === 'Unit Test 2' ? 'selected' : '' ?>>Unit Test 2 (II)</option>
                                    <option value="Unit Test 3" <?= $testType === 'Unit Test 3' ? 'selected' : '' ?>>Unit Test 3 (III)</option>
                                    <option value="Unit Test 4" <?= $testType === 'Unit Test 4' ? 'selected' : '' ?>>Unit Test 4 (IV)</option>
                                </select>
                            </div>
                        </form>

                        <div class="ut-actions-primary">
                            <span id="autoSaveStatus" class="ut-autosave"></span>
                            <a href="unit_test_entry.php?year=<?= urlencode($year) ?>&std=<?= urlencode($std) ?>&division=<?= urlencode($division) ?>&test_type=<?= urlencode($testType) ?>&mode=new" class="btn btn-sm btn-primary" title="Add new student in dedicated entry page">
                                <i class="fas fa-user-plus"></i> Add Student Entry
                            </a>
                            <button type="button" class="btn btn-sm btn-primary" onclick="document.getElementById('unitTestMatrixForm').submit();">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </div>
                    </div>

                    <!-- Row 2: documents & sharing, then selection (bulk) actions — all left-aligned -->
                    <div class="ut-actions">
                        <a href="import_unit_tests.php" class="btn btn-sm btn-secondary" title="Upload spreadsheet">
                            <i class="fas fa-file-excel"></i> Import Excel
                        </a>
                        <a href="unit_tests.php?action=export_excel&year=<?= urlencode($year) ?>&std=<?= urlencode($std) ?>&division=<?= urlencode($division) ?>&test_type=<?= urlencode($testType) ?>" class="btn btn-sm btn-secondary" title="Download Excel template">
                            <i class="fas fa-download"></i> Export Excel
                        </a>
                        <a href="unit_tests_print.php?year=<?= urlencode($year) ?>&std=<?= urlencode($std) ?>&division=<?= urlencode($division) ?>&test_type=<?= urlencode($testType) ?>" target="_blank" class="btn btn-sm btn-secondary">
                            <i class="fas fa-print"></i> Print Marksheet
                        </a>
                        <a href="unit_test_slips.php?year=<?= urlencode($year) ?>&std=<?= urlencode($std) ?>&division=<?= urlencode($division) ?>&test_type=<?= urlencode($testType) ?>" target="_blank" class="btn btn-sm btn-secondary" title="Print 4-per-A4 parent progress slips">
                            <i class="fas fa-receipt"></i> Parent Slips
                        </a>
                        <a href="whatsapp_share.php?doc_type=unit_test&test_type=<?= urlencode($testType) ?>&class=<?= urlencode($cohortClassKey) ?>" class="btn btn-sm btn-whatsapp" title="Open WhatsApp Dispatcher for this Unit Test">
                            <i class="fa-brands fa-whatsapp"></i> WhatsApp Hub
                        </a>
                        <?php if (!empty($students)): ?>
                        <span class="ut-actions-sep" aria-hidden="true"></span>
                        <button type="button" class="btn btn-sm btn-whatsapp" id="utBulkWaBtn" title="Share selected students via WhatsApp" disabled>
                            <i class="fa-brands fa-whatsapp"></i> WhatsApp <span class="ut-bulk-count" id="utBulkWaCount">0</span>
                        </button>
                        <button type="button" class="btn btn-sm btn-danger" id="utBulkDeleteBtn" title="Delete selected students">
                            <i class="fas fa-trash"></i> Delete <span class="ut-bulk-count" id="utBulkDeleteCount">0</span>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- KPI Summary Cards -->
                <div class="ut-kpi-grid">
                    <div class="ut-kpi-card">
                        <div class="ut-kpi-icon primary"><i class="fas fa-users"></i></div>
                        <div>
                            <div class="ut-kpi-val"><?= count($students) ?></div>
                            <div class="ut-kpi-lbl">Students Enrolled</div>
                        </div>
                    </div>
                    <div class="ut-kpi-card">
                        <div class="ut-kpi-icon blue"><i class="fas fa-chart-line"></i></div>
                        <div>
                            <div class="ut-kpi-val" id="kpiClassAvg">0.0%</div>
                            <div class="ut-kpi-lbl">Class Average</div>
                        </div>
                    </div>
                    <div class="ut-kpi-card">
                        <div class="ut-kpi-icon amber"><i class="fas fa-trophy"></i></div>
                        <div>
                            <div class="ut-kpi-val" id="kpiHighest">-</div>
                            <div class="ut-kpi-lbl">Highest Marks / <?= $totalMax ?></div>
                        </div>
                    </div>
                    <div class="ut-kpi-card">
                        <div class="ut-kpi-icon success"><i class="fas fa-check-double"></i></div>
                        <div>
                            <div class="ut-kpi-val" id="kpiPassPct">0.0%</div>
                            <div class="ut-kpi-lbl">Pass Percentage (&ge;35%)</div>
                        </div>
                    </div>
                </div>

                <!-- If No Students in Unit Test: Roster Sync Banner -->
                <?php if (empty($students)): ?>
                <div style="background:var(--paper);border:2px dashed #cbd5e1;border-radius:var(--radius);padding:48px 24px;text-align:center;margin-bottom:24px;">
                    <i class="fas fa-user-graduate" style="font-size:3rem;color:#94a3b8;margin-bottom:16px;display:block;"></i>
                    <h3 style="margin:0 0 8px 0;font-size:1.25rem;font-weight:700;">No Unit Test Records Found for STD <?= sanitize($std) ?> <?= sanitize($division) ?> (<?= sanitize($testType) ?>)</h3>
                    <p style="margin:0 0 20px 0;color:var(--muted);font-size:0.9rem;max-width:600px;display:inline-block;">
                        You can immediately import from an Excel sheet or click below to synchronize student names and exact roll numbers from the active class cohort.
                    </p>
                    <div style="display:flex;align-items:center;justify-content:center;gap:12px;">
                        <form method="POST">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="sync_roster">
                            <input type="hidden" name="source_year" value="<?= sanitize($year) ?>">
                            <button type="submit" class="btn btn-primary" style="font-weight:700;">
                                <i class="fas fa-sync-alt"></i> 1-Click Sync Students from Active Cohort
                            </button>
                        </form>
                        <a href="import_unit_tests.php" class="btn btn-secondary">
                            <i class="fas fa-file-excel"></i> Upload Unit Test Excel
                        </a>
                    </div>
                </div>
                <?php else: ?>

                <!-- Two-Tier Marksheet Table Matrix -->
                <div class="ut-table-card">
                    <form method="POST" id="unitTestMatrixForm" data-autosave-mode="direct">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="save_marks">
                        <input type="hidden" name="year" value="<?= sanitize($year) ?>">
                        <input type="hidden" name="std" value="<?= sanitize($std) ?>">
                        <input type="hidden" name="division" value="<?= sanitize($division) ?>">
                        <input type="hidden" name="test_type" value="<?= sanitize($testType) ?>">

                        <div class="ut-table-wrapper">
                            <table class="ut-matrix-table" id="unitTestTable">
                                <thead>
                                    <!-- Tier 1: Column Headers -->
                                    <tr>
                                        <th class="ut-check"><input type="checkbox" id="utCheckAll" class="ut-check-box" title="Select all students" aria-label="Select all students"></th>
                                        <th class="ut-sticky-roll">Roll No</th>
                                        <th class="ut-sticky-name">Name of the Students</th>
                                        <?php foreach ($subjects as $s): ?>
                                            <th><?= sanitize($s['subject_name']) ?></th>
                                        <?php endforeach; ?>
                                        <th style="min-width:64px;">Total</th>
                                        <th style="min-width:64px;">%</th>
                                        <th style="min-width:64px;">Grade</th>
                                        <th style="min-width:130px;">Remark</th>
<th style="min-width:54px;">Attn</th>
                                        <th style="min-width:60px;">Sign</th>
                                        <th style="min-width:110px;">Actions</th>
                                    </tr>
                                    <!-- Tier 2: Max Marks Sub-Headers -->
                                    <tr>
                                        <th class="th-sub-max ut-check"></th>
                                        <th class="th-sub-max ut-sticky-roll"></th>
                                        <th class="th-sub-max ut-sticky-name">Max Marks:</th>
                                        <?php foreach ($subjects as $s): ?>
                                            <th class="th-sub-max"><?= (float)$s['max_marks'] ?></th>
                                        <?php endforeach; ?>
                                        <th class="th-sub-max"><?= (float)$totalMax ?></th>
                                        <th class="th-sub-max">100%</th>
                                        <th class="th-sub-max">A1-E</th>
                                        <th class="th-sub-max"></th>
                                        <th class="th-sub-max"></th>
                                        <th class="th-sub-max"></th>
                                        <th class="th-sub-max"></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    foreach ($students as $stu): 
                                    ?>
                                    <tr class="ut-student-row" data-roll="<?= sanitize($stu['roll_no']) ?>">
                                        <td class="ut-check"><input type="checkbox" class="ut-row-check" form="utBulkDeleteForm" name="rolls[]" value="<?= sanitize($stu['roll_no']) ?>" aria-label="Select roll <?= sanitize($stu['roll_no']) ?>"></td>
                                        <td class="ut-sticky-roll"><?= sanitize($stu['roll_no']) ?></td>
                                        <td class="ut-sticky-name" title="<?= sanitize($stu['student_name']) ?>">
                                            <input type="hidden" name="names[<?= sanitize($stu['roll_no']) ?>]" value="<?= sanitize($stu['student_name']) ?>">
                                            <input type="hidden" name="genders[<?= sanitize($stu['roll_no']) ?>]" value="<?= sanitize($stu['gender']) ?>">
                                            <?= sanitize($stu['student_name']) ?>
                                        </td>

                                        <!-- Subject Mark Inputs -->
                                        <?php foreach ($subjects as $s): 
                                            $sName = $s['subject_name'];
                                            $sm = $stu['subjects'][$sName] ?? null;
                                            $val = '';
                                            if ($sm) {
                                                if ($sm['is_absent']) $val = '-';
                                                elseif ($sm['marks_obtained'] !== null) $val = (string)$sm['marks_obtained'];
                                            }
                                        ?>
                                        <td>
                                            <input type="text" 
                                                   name="marks[<?= sanitize($stu['roll_no']) ?>][<?= sanitize($sName) ?>]" 
                                                   value="<?= sanitize($val) ?>" 
                                                   data-max="<?= (float)$s['max_marks'] ?>"
                                                   class="ut-mark-input"
                                                   autocomplete="off">
                                        </td>
                                        <?php endforeach; ?>

                                        <!-- Live Computed Cells -->
                                        <td class="ut-cell-total"><?= $stu['total_obtained'] ?></td>
                                        <td class="ut-cell-pct"><?= $stu['percentage'] !== null ? $stu['percentage'].'%' : '-' ?></td>
                                        <td>
                                            <span class="ut-cell-grade"><?= sanitize($stu['grade']) ?></span>
                                        </td>
                                        <td>
                                            <input type="text" 
                                                   name="remarks[<?= sanitize($stu['roll_no']) ?>]" 
                                                   value="<?= sanitize($stu['remark']) ?>" 
                                                   class="ut-cell-remark">
                                        </td>
                                        <td>
                                            <input type="text" 
                                                   name="attn[<?= sanitize($stu['roll_no']) ?>]" 
                                                   value="<?= sanitize($stu['attendance']) ?>" 
                                                   class="ut-attn-input"
                                                   maxlength="5">
                                        </td>
                                        <td style="text-align:center;">
                                            <?php
                                            $isSigned = !empty($stu['parents_sign']);
                                            $sigImg   = trim((string)($stu['parent_signature_data'] ?? ''));
                                            ?>
                                            <button type="button"
                                                    class="psb-trigger js-open-sign-board <?= $isSigned ? 'is-signed' : '' ?>"
                                                    data-table="unit_test_results"
                                                    data-year="<?= e_attr($year) ?>"
                                                    data-std="<?= e_attr($std) ?>"
                                                    data-division="<?= e_attr($division) ?>"
                                                    data-test-type="<?= e_attr($testType) ?>"
                                                    data-roll="<?= e_attr($stu['roll_no']) ?>"
                                                    data-student-name="<?= e_attr($stu['student_name']) ?>"
                                                    data-signed="<?= $isSigned ? '1' : '0' ?>"
                                                    data-signature="<?= e_attr($sigImg) ?>"
                                                    aria-pressed="<?= $isSigned ? 'true' : 'false' ?>"
                                                    title="<?= $isSigned ? 'Parent digital signature saved (click to view or re-sign)' : 'Open digital signature board' ?>">
                                                <?php if ($isSigned && $sigImg !== ''): ?>
                                                    <img src="<?= e_attr($sigImg) ?>" alt="Parent signature" class="psb-thumb">
                                                <?php elseif ($isSigned): ?>
                                                    <i class="fas fa-check" aria-hidden="true"></i><span>Signed</span>
                                                <?php else: ?>
                                                    <i class="fas fa-signature" aria-hidden="true"></i><span>Sign</span>
                                                <?php endif; ?>
                                            </button>
                                        </td>
                                        <td style="text-align:center;white-space:nowrap;">
                                            <a href="unit_test_report_card.php?year=<?= urlencode($year) ?>&std=<?= urlencode($std) ?>&division=<?= urlencode($division) ?>&test_type=<?= urlencode($testType) ?>&roll=<?= urlencode($stu['roll_no']) ?>"
                                               class="btn btn-sm btn-outline" style="padding:4px 7px;margin-right:4px;" title="View unit test report card" target="_blank" rel="noopener">
                                                <i class="fas fa-file-invoice"></i>
                                            </a>
                                            <a href="unit_test_entry.php?year=<?= urlencode($year) ?>&std=<?= urlencode($std) ?>&division=<?= urlencode($division) ?>&test_type=<?= urlencode($testType) ?>&roll=<?= urlencode($stu['roll_no']) ?>"
                                               class="btn btn-sm btn-outline" style="padding:4px 7px;margin-right:4px;" title="Edit Student Entry">
                                                <i class="fas fa-pen" style="color:var(--accent);"></i>
                                            </a>
                                            <button type="button" class="btn btn-sm btn-outline btn-wa-row js-ut-wa-btn"
                                                    data-roll="<?= e_attr($stu['roll_no']) ?>"
                                                    data-name="<?= e_attr($stu['student_name']) ?>"
                                                    data-public-url="<?= e_attr(rcGetPublicSlipUrl($year, $std, $division, $testType, $stu['roll_no'])) ?>"
                                                    title="Share Unit Test Slip via WhatsApp">
                                                <i class="fa-brands fa-whatsapp" style="color:#25D366;"></i>
                                            </button>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </form>
                </div>
                <?php endif; ?>

            </div>
        </main>
    </div>

    <!-- Bulk delete posts only the checked rows (checkboxes opt in via form=). -->
    <form id="utBulkDeleteForm" method="POST" style="display:none;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete_bulk">
        <input type="hidden" name="year" value="<?= sanitize($year) ?>">
        <input type="hidden" name="std" value="<?= sanitize($std) ?>">
        <input type="hidden" name="division" value="<?= sanitize($division) ?>">
        <input type="hidden" name="test_type" value="<?= sanitize($testType) ?>">
    </form>

    <div class="ut-confirm-modal" id="utBulkDeleteModal" role="alertdialog" aria-modal="true" aria-labelledby="utBulkDeleteTitle" hidden>
        <div class="ut-confirm-backdrop" data-close></div>
        <div class="ut-confirm-card" role="document">
            <div class="ut-confirm-icon"><i class="fas fa-trash"></i></div>
            <h3 id="utBulkDeleteTitle">Delete <span id="utBulkDeleteModalCount">0</span> students?</h3>
            <p>The selected students and all their Unit Test rows will be permanently removed. This cannot be undone.</p>
            <div class="ut-confirm-actions">
                <button type="button" class="btn btn-outline" id="utBulkDeleteCancelBtn">Cancel</button>
                <button type="button" class="btn btn-danger" id="utBulkDeleteConfirmBtn"><i class="fas fa-trash"></i> Delete All</button>
            </div>
        </div>
    </div>

    <script src="../assets/js/unit-test-matrix.js?v=<?= filemtime(__DIR__ . '/../assets/js/unit-test-matrix.js') ?>"></script>
    <script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= (is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 0) ?>"></script>
    <script src="<?= BASE_URL ?>assets/vendor/html2canvas@1.4.1/html2canvas.min.js"></script>
    <script src="<?= BASE_URL ?>assets/vendor/jspdf@2.5.1/jspdf.umd.min.js"></script>
    <script src="<?= BASE_URL ?>assets/js/hcc-logo-base64.js?v=<?= filemtime(__DIR__ . '/../assets/js/hcc-logo-base64.js') ?>"></script>
    <script src="<?= BASE_URL ?>assets/js/whatsapp-share.js?v=<?= filemtime(__DIR__ . '/../assets/js/whatsapp-share.js') ?>"></script>
    <script src="<?= BASE_URL ?>assets/js/hcc-pdf-engine.js?v=<?= filemtime(__DIR__ . '/../assets/js/hcc-pdf-engine.js') ?>"></script>
    <script type="application/json" id="unitTestBridgeData">
        <?= json_encode([
            'students'     => $students,
            'subjects'     => $subjects,
            'totalMax'     => (float)$totalMax,
            'schoolName'   => $schoolName,
            'className'    => "STD {$romanStd}-{$division}",
            'testType'     => $testType,
            'std'          => $std,
            'division'     => $division,
            'classKey'     => $cohortClassKey,
            'academicYear' => $year
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
    </script>
    <script src="<?= BASE_URL ?>assets/js/hcc-bridge.js?v=<?= (is_file(__DIR__ . '/../assets/js/hcc-bridge.js') ? filemtime(__DIR__ . '/../assets/js/hcc-bridge.js') : 0) ?>"></script>
    <script>
    (function () {
        'use strict';
        const bridgeEl = document.getElementById('unitTestBridgeData');
        let bridgeMeta = {};
        try {
            bridgeMeta = bridgeEl ? JSON.parse(bridgeEl.textContent || '{}') : {};
        } catch (e) { bridgeMeta = {}; }

        function getCsrfToken() {
            const el = document.querySelector('#unitTestMatrixForm input[name="csrf_token"], input[name="csrf_token"]');
            return el ? el.value : '';
        }

        function setBtnVisual(btn, signed) {
            btn.dataset.signed = signed ? '1' : '0';
            btn.setAttribute('aria-pressed', signed ? 'true' : 'false');
            btn.classList.toggle('is-signed', !!signed);
            btn.title = signed ? 'Parent signature verified (click to untick)' : 'Parent signature pending (click to verify)';
            const icon = btn.querySelector('i');
            if (icon) {
                icon.className = signed ? 'fas fa-check' : 'far fa-circle';
            }
        }

        document.addEventListener('click', function (ev) {
            const btn = ev.target.closest('.ut-sign-toggle');
            if (!btn || btn.classList.contains('is-busy')) return;
            const roll = btn.dataset.roll || '';
            if (!roll) return;

            const prevSigned = btn.dataset.signed === '1' ? 1 : 0;
            const nextSigned = prevSigned ? 0 : 1;

            btn.classList.add('is-busy');
            setBtnVisual(btn, nextSigned);

            const body = new URLSearchParams({
                csrf_token: getCsrfToken(),
                table: 'unit_test_results',
                year: bridgeMeta.academicYear || '',
                std: bridgeMeta.std || '',
                division: bridgeMeta.division || '',
                test_type: bridgeMeta.testType || '',
                roll: roll,
                sign: String(nextSigned)
            });

            fetch('api_parent_sign.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                credentials: 'same-origin',
                body: body
            })
            .then(function (r) {
                return r.json().catch(function () { return { ok: false, error: 'Server returned HTTP ' + r.status }; });
            })
            .then(function (data) {
                btn.classList.remove('is-busy');
                if (!data || !data.ok) {
                    setBtnVisual(btn, prevSigned);
                    btn.classList.add('is-error');
                    setTimeout(function () { btn.classList.remove('is-error'); }, 1200);
                    alert((data && data.error) || 'Could not update parent signature.');
                    return;
                }
                if (Array.isArray(bridgeMeta.students)) {
                    const match = bridgeMeta.students.find(function (s) { return String(s.roll_no) === String(roll); });
                    if (match) match.parents_sign = nextSigned;
                    if (bridgeEl) bridgeEl.textContent = JSON.stringify(bridgeMeta);
                }
            })
            .catch(function () {
                btn.classList.remove('is-busy');
                setBtnVisual(btn, prevSigned);
                btn.classList.add('is-error');
                setTimeout(function () { btn.classList.remove('is-error'); }, 1200);
            });
        });
        document.addEventListener('hcc:parent-sign-updated', function (ev) {
            const detail = ev && ev.detail ? ev.detail : null;
            if (!detail || !detail.roll) return;
            if (Array.isArray(bridgeMeta.students)) {
                const match = bridgeMeta.students.find(function (s) { return String(s.roll_no) === String(detail.roll); });
                if (match) {
                    match.parents_sign = detail.signed ? 1 : 0;
                    match.parent_signature_data = detail.signature_data || '';
                }
                if (bridgeEl) bridgeEl.textContent = JSON.stringify(bridgeMeta);
            }
        });
    })();
    </script>
    <script src="<?= BASE_URL ?>assets/js/parent-sign.js?v=<?= (is_file(__DIR__ . '/../assets/js/parent-sign.js') ? filemtime(__DIR__ . '/../assets/js/parent-sign.js') : 0) ?>"></script>
</body>
</html>
