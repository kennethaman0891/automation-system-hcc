<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

$adminPage = 'results';
$db = getDB();
$settings = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church English Medium School, Solapur';
$adminUser = sanitize($_SESSION['admin_name'] ?? 'Admin');

// Current Filter Parameters
$classParam = trim($_GET['class'] ?? '');
if ($classParam !== '' && $classParam !== 'all') {
    $parsed = rcParseClassKey(urldecode($classParam));
    $year     = trim($_GET['year'] ?? ($parsed['academic_year'] ?: '2024-25'));
    $std      = trim($_GET['std'] ?? ($parsed['std'] ?: '1'));
    $division = trim($_GET['division'] ?? ($parsed['division'] ?: 'A'));
    $semester = trim($_GET['semester'] ?? ($parsed['semester'] ?: 'Sem 1'));
} else {
    $year     = trim($_GET['year'] ?? '2024-25');
    $std      = trim($_GET['std'] ?? '1');
    $division = trim($_GET['division'] ?? 'A');
    $semester = trim($_GET['semester'] ?? 'Sem 1');
}
$classKey = rcBuildClassKey($year, $std, $division, $semester);
$search   = trim($_GET['search'] ?? '');

// Parents Sign Mode: digital (interactive checkbox), blank (empty block for physical signing), or hide
$signMode = trim($_GET['sign_mode'] ?? '');
if ($signMode === '') {
    if (isset($_GET['show_sign']) && ($_GET['show_sign'] === '0' || $_GET['show_sign'] === 'false')) {
        $signMode = 'hide';
    } elseif (isset($_GET['digital_sign']) && ($_GET['digital_sign'] === '1' || $_GET['digital_sign'] === 'true')) {
        $signMode = 'digital';
    } else {
        $signMode = 'digital';
    }
}
$showSign = ($signMode !== 'hide');
$digitalSign = ($signMode === 'digital');

$romanMap = ['1'=>'I','2'=>'II','3'=>'III','4'=>'IV','5'=>'V','6'=>'VI','7'=>'VII','8'=>'VIII','9'=>'IX','10'=>'X'];
$romanStd = $romanMap[(string)$std] ?? $std;
$romanSem = romanSemLabel($semester);

$error = '';
$success = '';

// Flash messages from import_excel.php redirect
$_flash = $_SESSION['rc_flash'] ?? [];
unset($_SESSION['rc_flash']);

// Notifications for header bell
$notifData = rcGetRecentNotifications(8);

// Compact number formatter for matrix inputs (70.0 -> 70, 87.5 -> 87.5).
$fmtNum = function ($v) {
    $f = (float)$v;
    if (abs($f - round($f)) < 0.0001) return (string)(int)round($f);
    return rtrim(rtrim(number_format($f, 1, '.', ''), '0'), '.');
};

// Excel Export Action
if (isset($_GET['action']) && $_GET['action'] === 'export_excel') {
    require_once __DIR__ . '/../vendor/autoload.php';
    
    $mx = rcResultsMatrix($classKey, $search);
    $expSubjects = $mx['subjects'] ?? [];
    $expStudents = $mx['students'] ?? [];
    $expMarks    = $mx['marks'] ?? [];
    $expMaxTotal = 0.0;
    foreach ($expSubjects as $s) {
        $expMaxTotal += (float)$s['fa_max'] + (float)$s['sa_max'];
    }

    $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
    $sheet = $spreadsheet->getActiveSheet();
    $sheet->setTitle("STD $std $division");

    // Title
    $sheet->setCellValue('A1', $schoolName);
    $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);
    $sheet->getStyle('A1')->getAlignment()->setHorizontal(\PhpOffice\PhpSpreadsheet\Style\Alignment::HORIZONTAL_CENTER);

    // Meta row
    $sheet->setCellValue('A2', "STD:- " . $romanStd . " " . $division);
    $sheet->setCellValue('D2', "SEMESTER :- " . strtoupper($romanSem));
    $sheet->setCellValue('H2', "YEAR :- " . $year);
    $sheet->getStyle('A2:H2')->getFont()->setBold(true);

    // Header Row 3
    $sheet->setCellValue('A3', 'Roll No');
    $sheet->setCellValue('B3', 'Name of the Students');
    $colLetter = 'C';
    foreach ($expSubjects as $s) {
        $isSingle = ((float)$s['sa_max'] <= 0.0);
        if ($isSingle) {
            $sheet->setCellValue($colLetter . '3', $s['subject_name']);
            $colLetter++;
        } else {
            $startCol = $colLetter;
            $sheet->setCellValue($colLetter . '3', $s['subject_name']);
            $colLetter++; $colLetter++;
            $endCol = $colLetter;
            $sheet->mergeCells("{$startCol}3:{$endCol}3");
            $colLetter++;
        }
    }
    $sheet->setCellValue($colLetter . '3', 'Total'); $totCol = $colLetter++;
    $sheet->setCellValue($colLetter . '3', '%'); $pctCol = $colLetter++;
    $sheet->setCellValue($colLetter . '3', 'Grade'); $grdCol = $colLetter++;
    $sheet->setCellValue($colLetter . '3', 'Status'); $statCol = $colLetter++;
    $sheet->setCellValue($colLetter . '3', 'Remark'); $rmkCol = $colLetter++;
    $sheet->setCellValue($colLetter . '3', 'Attn'); $attnCol = $colLetter++;
    $sheet->setCellValue($colLetter . '3', 'Parents Sign'); $signCol = $colLetter;

    // Sub-header Row 4 (Max Marks)
    $colLetter = 'C';
    foreach ($expSubjects as $s) {
        $isSingle = ((float)$s['sa_max'] <= 0.0);
        if ($isSingle) {
            $sheet->setCellValue($colLetter . '4', $s['fa_max']);
            $colLetter++;
        } else {
            $sheet->setCellValue($colLetter . '4', 'F(' . $s['fa_max'] . ')'); $colLetter++;
            $sheet->setCellValue($colLetter . '4', 'S(' . $s['sa_max'] . ')'); $colLetter++;
            $sheet->setCellValue($colLetter . '4', ((float)$s['fa_max'] + (float)$s['sa_max'])); $colLetter++;
        }
    }
    $sheet->setCellValue($totCol . '4', $expMaxTotal);

    // Data rows
    $rowIdx = 5;
    foreach ($expStudents as $stu) {
        $rRoll = (string)$stu['roll_no'];
        $sheet->setCellValue('A' . $rowIdx, $stu['roll_no']);
        $sheet->setCellValue('B' . $rowIdx, $stu['student_name']);
        $cLet = 'C';
        foreach ($expSubjects as $s) {
            $isSingle = ((float)$s['sa_max'] <= 0.0);
            $sKey = mb_strtolower($s['subject_name']);
            $mk = $expMarks[$rRoll][$sKey] ?? null;
            $faV = ($mk !== null && $mk['fa_obtained'] !== null) ? (float)$mk['fa_obtained'] : 0;
            $saV = ($mk !== null && $mk['sa_obtained'] !== null) ? (float)$mk['sa_obtained'] : 0;
            if ($isSingle) {
                $sheet->setCellValue($cLet . $rowIdx, $faV);
                $cLet++;
            } else {
                $sheet->setCellValue($cLet . $rowIdx, $faV); $cLet++;
                $sheet->setCellValue($cLet . $rowIdx, $saV); $cLet++;
                $sheet->setCellValue($cLet . $rowIdx, $faV + $saV); $cLet++;
            }
        }
        $sheet->setCellValue($totCol . $rowIdx, $stu['obtained']);
        $sheet->setCellValue($pctCol . $rowIdx, $stu['percentage'] !== null ? $stu['percentage'] : '-');
        $sheet->setCellValue($grdCol . $rowIdx, $stu['grade']);
        $sheet->setCellValue($statCol . $rowIdx, !empty($stu['passed']) ? 'PASS' : 'FAIL');
        $sheet->setCellValue($rmkCol . $rowIdx, rcDisplayRemark($stu['remark'] ?? '', $stu['percentage'] ?? 0));
        $sheet->setCellValue($attnCol . $rowIdx, $stu['attendance']);
        $sheet->setCellValue($signCol . $rowIdx, ($digitalSign && !empty($stu['parents_sign'])) ? 'Signed' : '');
        $rowIdx++;
    }

    $sheet->mergeCells("A1:{$signCol}1");

    $filename = "STD_{$std}_{$division}_{$semester}_{$year}.xlsx";
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet);
    $writer->save('php://output');
    exit;
}

// POST Actions
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = $_POST['action'] ?? '';
    
    // 1. Mark Notifications Read
    if ($act === 'mark_notifs_read') {
        if (!verifyCSRFToken()) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Invalid security token']);
            exit;
        }
        rcMarkNotificationsRead();
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }

    // 2. Delete Single Student
    if ($act === 'delete') {
        if (!verifyCSRFToken()) {
            $error = 'Security check failed.';
        } else {
            $ck = trim($_POST['class_key'] ?? $classKey);
            $roll = trim($_POST['roll'] ?? '');
            if ($ck !== '' && $roll !== '') {
                if (rcDeleteStudent($ck, $roll)) {
                    $success = 'Student ' . sanitize($roll) . ' deleted.';
                    $classKey = $ck;
                } else {
                    $error = 'Delete failed.';
                }
            }
        }
    }

    // 3. Bulk Delete Students
    elseif ($act === 'delete_bulk') {
        if (!verifyCSRFToken()) {
            $error = 'Security check failed.';
        } else {
            $ck = trim($_POST['class_key'] ?? $classKey);
            $rawRolls = $_POST['rolls'] ?? [];
            if (!is_array($rawRolls)) $rawRolls = [];
            $rawTargets = $_POST['targets'] ?? [];
            if (!is_array($rawTargets)) $rawTargets = [];

            $targets = [];
            foreach ($rawRolls as $r) {
                $r = trim((string)$r);
                if ($r !== '') {
                    $targets[] = ['class_key' => $ck, 'roll' => $r];
                }
            }
            foreach (array_slice($rawTargets, 0, 500) as $t) {
                if (!is_string($t) || $t === '') continue;
                $d = json_decode($t, true);
                if (!is_array($d)) continue;
                $targets[] = [
                    'class_key' => trim((string)($d['c'] ?? '')),
                    'roll'      => trim((string)($d['r'] ?? '')),
                ];
            }

            if (empty($targets)) {
                $error = 'No students selected.';
            } else {
                $res = rcDeleteStudentsBulk($targets);
                if ($res['ok']) {
                    $n = (int)$res['deleted'];
                    $success = 'Deleted ' . $n . ' student' . ($n !== 1 ? 's' : '') . '.';
                } else {
                    $error = $res['error'] !== '' ? $res['error'] : 'Bulk delete failed.';
                }
            }
        }
    }

    // 4. Bulk Save Marks, Remarks & Attendance
    elseif ($act === 'save_marks') {
        if (!verifyCSRFToken()) {
            $error = 'Security check failed.';
        } else {
            $ck = trim($_POST['class_key'] ?? $classKey);
            $in = $_POST['marks'] ?? [];
            if (!is_array($in)) $in = [];
            
            $bulk = [];
            foreach ($in as $roll => $subs) {
                if (!is_array($subs)) continue;
                foreach ($subs as $subj => $vals) {
                    if (!is_array($vals)) continue;
                    $bulk[$roll][$subj] = ['fa' => $vals['fa'] ?? 0, 'sa' => $vals['sa'] ?? 0];
                }
            }
            $res = rcSaveBulkMarks($ck, $bulk);

            // Remarks
            $rmIn = $_POST['remarks'] ?? [];
            if (!is_array($rmIn)) $rmIn = [];
            $rm = [];
            foreach ($rmIn as $roll => $text) {
                if (!is_string($text) && !is_numeric($text)) continue;
                $roll = trim((string)$roll);
                if ($roll === '') continue;
                $rm[$roll] = rcDisplayRemark((string)$text, 0);
            }
            $rmSaved = 0;
            if (!empty($rm) && $ck !== '') {
                $rmRes = rcSaveBulkRemarks($ck, $rm);
                if ($rmRes['ok']) $rmSaved = (int)$rmRes['saved'];
            }

            // Attendance
            $attIn = $_POST['attendance'] ?? [];
            if (is_array($attIn) && !empty($attIn) && $ck !== '') {
                $c = rcParseClassKey(urldecode($ck));
                try {
                    $updAtt = $db->prepare("UPDATE report_card SET attendance=? WHERE academic_year=? AND std=? AND division=? AND semester=? AND roll_no=?");
                    foreach ($attIn as $roll => $attVal) {
                        $updAtt->execute([trim((string)$attVal), $c['academic_year'], $c['std'], $c['division'], $c['semester'], trim((string)$roll)]);
                    }
                } catch (\Throwable $e) {}
            }

            if ($res['ok']) {
                $n = (int)$res['saved'];
                $success = 'Marks saved for ' . $n . ' student' . ($n !== 1 ? 's' : '') . '.';
                if ($rmSaved > 0) $success .= ' Remarks updated for ' . $rmSaved . ' student' . ($rmSaved !== 1 ? 's' : '') . '.';
                $classKey = $ck;
            } else {
                $error = $res['error'] !== '' ? $res['error'] : 'Save failed.';
                if ($ck !== '') $classKey = $ck;
            }
        }
    }
}

// Fetch Matrix Dataset
$matrixMode = false;
$matrixSubjects = [];
$matrixMarks = [];
$classMaxTotal = 0.0;
$students = [];

try {
    $mx = rcResultsMatrix($classKey, $search);
    $matrixSubjects = $mx['subjects'] ?? [];
    $matrixMarks = $mx['marks'] ?? [];
    $students = $mx['students'] ?? [];
    $matrixMode = !empty($matrixSubjects);
    if ($matrixMode) {
        foreach ($matrixSubjects as $ms) {
            $classMaxTotal += (float)$ms['fa_max'] + (float)$ms['sa_max'];
        }
    }
} catch (Throwable $e) {
    if ($error === '') $error = 'Could not load subject marks: ' . $e->getMessage();
}

// KPI Calculations
$totalStudents = count($students);
$sumPct = 0.0;
$highestObtained = 0.0;
$passCount = 0;
foreach ($students as $s) {
    $pct = (float)($s['percentage'] ?? 0);
    $sumPct += $pct;
    $obt = (float)($s['obtained'] ?? 0);
    if ($obt > $highestObtained) $highestObtained = $obt;
    if (!empty($s['passed'])) $passCount++;
}
$classAvg = $totalStudents > 0 ? round($sumPct / $totalStudents, 1) : 0.0;
$passPct = $totalStudents > 0 ? round(($passCount / $totalStudents) * 100, 1) : 0.0;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Semester Results Matrix — <?= sanitize($schoolName) ?></title>
    <link rel="icon" href="../assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="../assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
    <link rel="stylesheet" href="../assets/css/whatsapp-share.css?v=<?= filemtime(__DIR__ . '/../assets/css/whatsapp-share.css') ?>">
    <script src="<?= BASE_URL ?>assets/vendor/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        /* Matrix specific styling - Modern Executive Edition */
        .ut-filter-bar {
            background: var(--paper);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px 20px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
        }
        .ut-filter-inputs {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .ut-filter-field {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .ut-filter-label {
            font-size: 0.72rem;
            font-weight: 700;
            color: #64748b;
            text-transform: uppercase;
            letter-spacing: 0.04em;
        }
        .ut-filter-inputs select, .ut-filter-inputs input {
            height: 38px;
            padding: 6px 12px;
            border: 1.5px solid #e2e8f0;
            border-radius: 8px;
            font-size: 0.86rem;
            font-weight: 600;
            background: #ffffff;
            color: #1e293b;
            transition: all 0.15s ease;
        }
        .ut-filter-inputs select:hover, .ut-filter-inputs input:hover {
            border-color: #cbd5e1;
        }
        .ut-filter-inputs select:focus, .ut-filter-inputs input:focus {
            outline: none;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(122, 34, 56, 0.12);
            background: #fff;
        }

        /* ── Academic Year picker (results.php) ───────────────────────────────
           A native <select> is the source of truth (any number of years, native
           mobile picker, keyboard + screen-reader support for free). It is laid
           transparent over a styled "face", so the control looks bespoke while
           the OS renders the option list. Never a radio group: options will grow.
           Markup: .ay-select > select.ay-select-input + span.ay-select-face      */
        .ay-select {
            position: relative;
            display: inline-flex;
            align-items: center;
            min-width: 172px;
            height: 38px;
            background: #ffffff;
            border: 1.5px solid var(--border);
            border-radius: 10px;
            box-shadow: 0 1px 2px rgba(42, 16, 24, 0.04);
            transition: border-color 0.16s ease, box-shadow 0.16s ease, background 0.16s ease;
        }
        .ay-select:hover {
            border-color: #d9cdd2;
            background: #fffdfd;
        }
        .ay-select:focus-within {
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(122, 34, 56, 0.12);
        }
        /* The real control: invisible but fully interactive, filling the face */
        .ay-select > .ay-select-input {
            position: absolute;
            inset: 0;
            width: 100%;
            height: 100%;
            margin: 0;
            padding: 0;
            border: 0;
            opacity: 0;
            cursor: pointer;
            -webkit-appearance: none;
            appearance: none;
            z-index: 2;
        }
        .ay-select-face {
            display: flex;
            align-items: center;
            gap: 9px;
            width: 100%;
            min-width: 0;
            padding: 0 11px 0 9px;
            pointer-events: none;
        }
        .ay-select-icon {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            width: 22px;
            height: 22px;
            flex-shrink: 0;
            border-radius: 6px;
            background: var(--gold-glow);
            color: var(--gold-ink);
            font-size: 0.72rem;
        }
        .ay-select-value {
            min-width: 0;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            font-size: 0.86rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            font-variant-numeric: tabular-nums;
            color: #1e293b;
        }
        .ay-select-caret {
            margin-left: auto;
            padding-left: 2px;
            color: #94a3b8;
            font-size: 0.68rem;
            transition: color 0.16s ease, transform 0.16s ease;
        }
        .ay-select:hover .ay-select-caret {
            color: var(--brand);
        }
        .ay-select:focus-within .ay-select-caret {
            color: var(--brand);
            transform: translateY(1px);
        }
        .ut-action-toolbar {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
        }
        .ut-btn-group {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }

        /* KPI Summary Grid */
        .ut-kpi-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 16px;
            margin-bottom: 20px;
        }
        .ut-kpi-card {
            background: var(--paper);
            border: 1px solid var(--border);
            border-radius: 12px;
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
            transition: transform 0.15s ease, box-shadow 0.15s ease;
        }
        .ut-kpi-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 6px 16px rgba(0,0,0,0.06);
        }
        .ut-kpi-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
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
        .ut-kpi-val { font-size: 1.45rem; font-weight: 800; color: #0f172a; line-height: 1.1; font-variant-numeric: tabular-nums; }
        .ut-kpi-lbl { font-size: 0.74rem; color: #64748b; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; margin-top: 4px; }

        /* Two-Tier Table Card */
        .ut-table-card {
            background: var(--paper);
            border: 1px solid var(--border);
            border-radius: 12px;
            box-shadow: 0 4px 16px rgba(0,0,0,0.04);
            overflow: hidden;
            margin-bottom: 24px;
        }
        .ut-table-header {
            padding: 14px 20px;
            background: #ffffff;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .ut-table-title-area {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .ut-table-title {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 800;
            color: #0f172a;
            display: flex;
            align-items: center;
        }
        .ut-badge {
            display: inline-flex;
            align-items: center;
            padding: 3px 10px;
            border-radius: 999px;
            font-size: 0.74rem;
            font-weight: 700;
            border: 1px solid #e2e8f0;
            background: #f8fafc;
            color: #475569;
        }
        .ut-badge-gold {
            background: rgba(201,162,39,0.12);
            color: var(--brand-deep);
            border-color: rgba(201,162,39,0.3);
        }
        .ut-badge-info {
            background: #eff6ff;
            color: #1d4ed8;
            border-color: #bfdbfe;
        }
        .ut-table-header-tools {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .ut-autosave-wrap {
            font-size: 0.82rem;
            font-weight: 600;
            color: #64748b;
        }

        .ut-table-wrapper {
            overflow: auto;
            max-height: calc(85vh - 200px);
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 transparent;
        }
        .ut-table-wrapper::-webkit-scrollbar {
            width: 8px;
            height: 8px;
        }
        .ut-table-wrapper::-webkit-scrollbar-track {
            background: #f8fafc;
        }
        .ut-table-wrapper::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 4px;
        }
        .ut-table-wrapper::-webkit-scrollbar-thumb:hover {
            background: #94a3b8;
        }

        .ut-matrix-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 0.85rem;
        }
        .ut-matrix-table thead tr:first-child th {
            height: 38px;
            box-sizing: border-box;
        }
        .ut-matrix-table th {
            background: linear-gradient(180deg, #6b1d30, #541524);
            color: #ffffff;
            padding: 8px 6px;
            text-align: center;
            font-weight: 700;
            font-size: 0.78rem;
            letter-spacing: 0.02em;
            border-bottom: 1px solid rgba(255,255,255,0.15);
            border-right: 1px solid rgba(255,255,255,0.12);
            position: sticky;
            top: 0;
            z-index: 10;
            white-space: nowrap;
            vertical-align: middle;
        }
        .ut-matrix-table th[rowspan="2"] {
            z-index: 16;
            height: 68px;
            border-bottom: 2px solid var(--gold);
        }
        .ut-matrix-table th.rm-subj-grp {
            background: linear-gradient(180deg, #6b1d30, #541524);
            border-left: 1px solid rgba(201,162,39,0.35);
            border-right: 1px solid rgba(201,162,39,0.35);
            font-weight: 800;
            letter-spacing: 0.03em;
        }
        .ut-matrix-table th.th-sub-max {
            background: #f8fafc;
            color: #475569;
            font-size: 0.72rem;
            font-weight: 700;
            padding: 5px 4px;
            top: 38px;
            height: 30px;
            box-sizing: border-box;
            z-index: 9;
            border-bottom: 2px solid var(--gold);
            border-right: 1px solid #e2e8f0;
        }
        .ut-matrix-table th.th-sub-tot {
            background: #f1f5f9;
            color: var(--brand-deep);
            font-weight: 800;
            border-right: 1.5px solid #cbd5e1 !important;
        }
        .ut-matrix-table td {
            padding: 6px 8px;
            border-bottom: 1px solid #eef2f6;
            border-right: 1px solid #f1f5f9;
            text-align: center;
            vertical-align: middle;
            background: #ffffff;
        }
        .ut-matrix-table tbody tr:nth-child(even) td { background: #fafbfc; }
        .ut-matrix-table tbody tr:hover td { background: #f1f5f9; }
        tr.rm-dirty td { background: #fffbeb !important; }

        /* Sticky Columns */
        .ut-check {
            position: sticky;
            left: 0;
            z-index: 8;
            background: #ffffff !important;
            text-align: center;
            width: 42px;
            min-width: 42px;
            max-width: 42px;
            border-right: 1px solid #e2e8f0 !important;
        }
        th.ut-check {
            z-index: 25 !important;
            background: linear-gradient(180deg, #6b1d30, #541524) !important;
        }
        .ut-check-box, .ut-row-check {
            width: 17px;
            height: 17px;
            accent-color: #dc2626;
            cursor: pointer;
            vertical-align: middle;
        }
        tr.is-checked td { background: #fef2f2 !important; }
        tr.is-checked td.ut-check,
        tr.is-checked td.ut-sticky-roll,
        tr.is-checked td.ut-sticky-name { background: #fee2e2 !important; }

        .ut-sticky-roll {
            position: sticky;
            left: 42px;
            z-index: 7;
            background: #ffffff !important;
            font-weight: 800;
            color: var(--brand);
            width: 56px;
            min-width: 56px;
            max-width: 56px;
            border-right: 1px solid #e2e8f0 !important;
        }
        th.ut-sticky-roll {
            z-index: 24 !important;
            background: linear-gradient(180deg, #6b1d30, #541524) !important;
        }

        .ut-sticky-name {
            position: sticky;
            left: 98px;
            z-index: 6;
            background: #ffffff !important;
            text-align: left !important;
            font-weight: 700;
            min-width: 220px;
            max-width: 280px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            border-right: 2px solid #cbd5e1 !important;
            box-shadow: 4px 0 8px -2px rgba(0,0,0,0.06);
        }
        th.ut-sticky-name {
            z-index: 23 !important;
            background: linear-gradient(180deg, #6b1d30, #541524) !important;
            border-right: 2px solid #cbd5e1 !important;
            box-shadow: 4px 0 8px -2px rgba(0,0,0,0.06);
        }

        /* Mark Inputs */
        .ut-mark-input {
            width: 52px;
            height: 32px;
            padding: 4px 6px;
            border: 1.5px solid #e2e8f0;
            border-radius: 6px;
            text-align: center;
            font-weight: 700;
            font-size: 0.88rem;
            color: #0f172a;
            background: #ffffff;
            box-shadow: inset 0 1px 2px rgba(0,0,0,0.03);
            transition: all 0.15s ease;
        }
        .ut-mark-input:hover {
            border-color: #cbd5e1;
        }
        .ut-mark-input:focus {
            outline: none;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(122, 34, 56, 0.15);
            background: #ffffff;
        }
        .ut-mark-input.rm-over {
            border-color: #ef4444 !important;
            background: #fef2f2 !important;
            color: #b91c1c !important;
        }
        .rm-cell--subtot {
            font-weight: 800;
            font-size: 0.88rem;
            color: #1e293b;
            background: #f8fafc !important;
            border-right: 1.5px solid #e2e8f0 !important;
            font-variant-numeric: tabular-nums;
        }

        .ut-attn-input {
            width: 44px;
            height: 32px;
            padding: 4px 6px;
            border: 1.5px solid #e2e8f0;
            border-radius: 6px;
            text-align: center;
            font-size: 0.82rem;
            font-weight: 700;
            color: #334155;
            background: #ffffff;
        }
        .ut-attn-input:focus {
            outline: none;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(122, 34, 56, 0.12);
        }

        .ut-cell-remark {
            width: 140px;
            height: 32px;
            padding: 4px 8px;
            border: 1.5px solid #e2e8f0;
            border-radius: 6px;
            font-size: 0.82rem;
            color: #334155;
            background: #f8fafc;
            transition: all 0.15s ease;
        }
        .ut-cell-remark:focus {
            border-color: var(--brand);
            background: #ffffff;
            box-shadow: 0 0 0 3px rgba(122, 34, 56, 0.12);
            outline: none;
        }

        /* Calculated Result Cells */
        .ut-cell-total {
            font-weight: 900;
            font-size: 0.92rem;
            color: var(--brand-deep);
            background: rgba(201, 162, 39, 0.12) !important;
            font-variant-numeric: tabular-nums;
        }
        .ut-cell-pct {
            font-weight: 800;
            font-size: 0.88rem;
            color: #0f172a;
            background: #fafaf9;
            font-variant-numeric: tabular-nums;
        }

        /* Grade Badges */
        .ut-cell-grade {
            font-weight: 800;
            font-size: 0.78rem;
            padding: 3px 10px;
            border-radius: 999px;
            display: inline-block;
            min-width: 38px;
            text-align: center;
            border: 1px solid transparent;
        }
        .grade-A, .grade-A1, .grade-A2, .grade-Distinction { background: #dcfce7; color: #166534; border-color: #86efac; }
        .grade-B, .grade-B1, .grade-B2, .grade-Grade-I { background: #dbeafe; color: #1e40af; border-color: #93c5fd; }
        .grade-C, .grade-C1, .grade-C2, .grade-Grade-II { background: #fef3c7; color: #92400e; border-color: #fde047; }
        .grade-D { background: #ffedd5; color: #9a3412; border-color: #fed7aa; }
        .grade-Pass { background: #dcfce7; color: #166534; border-color: #86efac; }
        .grade-F, .grade-E1, .grade-E2, .grade-Fail { background: #fee2e2; color: #991b1b; border-color: #fca5a5; }

        .pass-tag { background: #dcfce7; color: #166534; padding: 3px 9px; border-radius: 999px; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.5px; border: 1px solid #86efac; display: inline-block; }
        .fail-tag { background: #fee2e2; color: #991b1b; padding: 3px 9px; border-radius: 999px; font-size: 0.72rem; font-weight: 800; letter-spacing: 0.5px; border: 1px solid #fca5a5; display: inline-block; }

        /* Actions Column Group */
        .ut-actions-cell {
            padding: 4px 6px !important;
            text-align: center;
            white-space: nowrap;
        }
        .ut-action-group {
            display: inline-flex;
            align-items: center;
            gap: 3px;
            padding: 2px 4px;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
        }
        .ut-action-btn {
            width: 28px;
            height: 28px;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border-radius: 6px;
            border: none;
            background: transparent;
            color: #64748b;
            font-size: 0.82rem;
            cursor: pointer;
            transition: all 0.15s ease;
            text-decoration: none;
            padding: 0;
        }
        .ut-action-btn:hover {
            background: #ffffff;
            box-shadow: 0 1px 3px rgba(0,0,0,0.12);
            transform: translateY(-1px);
        }
        .ut-action-btn.analytics:hover { color: #0284c7; background: #f0f9ff; }
        .ut-action-btn.report:hover { color: var(--brand); background: #fdf2f4; }
        .ut-action-btn.edit:hover { color: #d97706; background: #fffbeb; }
        .ut-action-btn.whatsapp:hover { color: #16a34a; background: #f0fdf4; }
        .ut-action-btn.delete:hover { color: #dc2626; background: #fef2f2; }

        .ut-bulk-count {
            display: inline-block; min-width: 18px; text-align: center;
            background: rgba(255,255,255,0.3); border-radius: 999px;
            font-size: 0.7rem; font-weight: 800; padding: 1px 5px; margin-left: 4px;
        }
        #bulkDeleteBtn:disabled, #bulkWaBtn:disabled { opacity: 0.55; cursor: not-allowed; }

        /* Parent signature interactive tick / untick toggle */
        .sign-check {
            appearance: none;
            -webkit-appearance: none;
            width: 22px;
            height: 22px;
            border: 2px solid #cbd5e1;
            border-radius: 6px;
            background: #fff;
            cursor: pointer;
            position: relative;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            vertical-align: middle;
            transition: background .15s ease, border-color .15s ease, box-shadow .15s ease;
        }
        .sign-check::after {
            content: '\2713';
            font-size: 14px;
            font-weight: 900;
            line-height: 1;
            color: #fff;
            opacity: 0;
            transform: scale(.55);
            transition: opacity .15s ease, transform .15s ease;
        }
        .sign-check:hover { border-color: #16a34a; }
        .sign-check:checked { background: #16a34a; border-color: #16a34a; }
        .sign-check:checked::after { opacity: 1; transform: scale(1); }
        .sign-check:focus-visible { outline: 2px solid var(--brand); outline-offset: 2px; }
        .sign-check.is-busy { opacity: .55; cursor: progress; }
        .sign-check.is-error { border-color: #dc2626 !important; background: #fee2e2 !important; }
        .sign-check.is-error::after { color: #dc2626; opacity: 1; transform: scale(1); }

        /* Bulk delete modal */
        .ut-confirm-modal { position: fixed; inset: 0; z-index: 1050; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .ut-confirm-modal[hidden] { display: none; }
        .ut-confirm-backdrop { position: absolute; inset: 0; background: rgba(42,16,24,0.55); }
        .ut-confirm-card { position: relative; background: #fff; border-radius: 12px; box-shadow: 0 24px 60px rgba(0,0,0,0.25); max-width: 420px; width: 100%; padding: 28px 26px; text-align: center; }
        .ut-confirm-icon { width: 52px; height: 52px; margin: 0 auto 14px; display: flex; align-items: center; justify-content: center; border-radius: 50%; background: #fef2f2; color: #dc2626; font-size: 1.3rem; }
        .ut-confirm-card h3 { margin: 0 0 8px; font-size: 1.1rem; font-weight: 800; color: #0f172a; }
        .ut-confirm-card p { margin: 0 0 20px; font-size: 0.87rem; color: #64748b; line-height: 1.55; }
        .ut-confirm-actions { display: flex; gap: 10px; justify-content: center; }

        /* Notification Bell */
        .notif-container { position:relative; display:inline-block; margin-right:16px; }
        .notif-trigger { background:transparent; border:1px solid var(--border); border-radius:8px; width:38px; height:38px; display:flex; align-items:center; justify-content:center; cursor:pointer; color:var(--text); position:relative; transition:all 0.15s ease; }
        .notif-trigger:hover { background:var(--bg); border-color:var(--accent); color:var(--accent); }
        .notif-badge { position:absolute; top:-5px; right:-5px; background:#dc2626; color:#fff; font-size:0.65rem; font-weight:700; border-radius:10px; padding:2px 6px; border:2px solid #fff; min-width:18px; text-align:center; }
        .notif-dropdown { display:none; position:absolute; right:0; top:44px; width:360px; background:var(--paper); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow-lg); z-index:1000; overflow:hidden; }
        .notif-dropdown.show { display:block; }
        .notif-dropdown-header { padding:12px 16px; border-bottom:1px solid var(--border); background:var(--bg); display:flex; align-items:center; justify-content:space-between; font-size:0.85rem; font-weight:700; }
        .notif-dropdown-list { max-height:380px; overflow-y:auto; }
        .notif-item { padding:12px 16px; border-bottom:1px solid var(--border); display:flex; gap:12px; font-size:0.82rem; line-height:1.4; text-decoration:none; color:inherit; }
        .notif-item:hover { background:#fafaf9; }
        .notif-item-icon { width:32px; height:32px; border-radius:8px; background:#fee2e2; color:#991b1b; display:flex; align-items:center; justify-content:center; flex-shrink:0; font-size:0.85rem; }

        /* Dashboard Modal */
        .modal { position:fixed; inset:0; z-index:1000; display:flex; align-items:center; justify-content:center; padding:20px; }
        .modal-backdrop { position:absolute; inset:0; background:rgba(42,16,24,0.55); backdrop-filter:blur(2px); }
        .modal-panel { position:relative; width:100%; max-width:1100px; max-height:90vh; background:var(--paper); border:1px solid var(--border); border-radius:var(--radius); box-shadow:var(--shadow-lg); display:flex; flex-direction:column; overflow:hidden; animation:modalIn .18s ease; }
        @keyframes modalIn { from {opacity:0; transform:scale(.96);} to {opacity:1; transform:scale(1);} }
        .modal-header { display:flex; align-items:center; justify-content:space-between; padding:16px 20px; border-bottom:1px solid var(--border); background:var(--surface); flex-shrink:0; gap:12px; }
        .modal-header h3 { margin:0; font-size:1rem; font-weight:700; color:var(--ink); display:flex; align-items:center; gap:10px; }
        .modal-header h3 i { color:var(--brand); }
        .modal-close { width:36px; height:36px; border-radius:50%; border:none; background:transparent; color:var(--text-3); display:grid; place-items:center; cursor:pointer; transition:background .15s, color .15s; flex-shrink:0; }
        .modal-close:hover { background:var(--brand-soft); color:var(--brand); }
        .dashboard-tabs { display:flex; gap:4px; padding:12px 20px 0; border-bottom:1px solid var(--border); background:var(--surface); flex-shrink:0; overflow-x:auto; flex-wrap:wrap; }
        .dashboard-tabs button { display:inline-flex; align-items:center; gap:6px; padding:10px 16px; border:1px solid transparent; background:transparent; color:var(--text-2); border-radius:var(--radius-sm) var(--radius-sm) 0 0; font-weight:600; font-size:0.82rem; cursor:pointer; transition:all .15s; white-space:nowrap; margin-bottom:-1px; }
        .dashboard-tabs button:hover { color:var(--brand); background:var(--brand-soft); }
        .dashboard-tabs button.active { color:var(--brand); background:var(--paper); border-color:var(--border); border-bottom-color:var(--paper); }
        .modal-body { padding:20px; overflow:auto; flex:1; min-height:0; }
        .tab-panel { display:none; animation:fadeIn .15s ease; }
        .tab-panel.active { display:block; }
        .chart-card { background:var(--paper); border:1px solid var(--border); border-radius:var(--radius); padding:16px; margin-bottom:16px; }
        .chart-card h4 { margin:0 0 12px; font-size:0.88rem; font-weight:700; color:var(--ink); display:flex; align-items:center; gap:8px; }
        .chart-wrap { position:relative; height:360px; min-height:280px; }
        .chart-wrap-small { position:relative; height:220px; min-height:180px; }
        .kpi-grid { display:grid; grid-template-columns: repeat(4, 1fr); gap:12px; margin-bottom:16px; }
        .kpi-card { background:var(--paper); border:1px solid var(--border); border-radius:var(--radius); padding:16px; display:flex; flex-direction:column; align-items:center; text-align:center; }
        .kpi-value { font-size:1.5rem; font-weight:800; color:var(--ink); line-height:1.2; font-variant-numeric:tabular-nums; }
        .kpi-label { font-size:0.7rem; text-transform:uppercase; letter-spacing:.04em; color:var(--text-3); margin-top:4px; font-weight:600; }
        .overview-charts { display:grid; grid-template-columns: 1fr 1fr; gap:16px; margin-bottom:16px; }
        .meta-cards { display:grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap:12px; }
        .meta-card { background:var(--surface); border:1px solid var(--border); border-radius:var(--radius-sm); padding:14px 16px; }
        .meta-card strong { display:block; font-size:0.7rem; text-transform:uppercase; letter-spacing:.04em; color:var(--text-3); margin-bottom:6px; }
        .meta-card span { font-size:0.95rem; font-weight:600; color:var(--ink); }
        .dashboard-loading { display:flex; align-items:center; justify-content:center; gap:10px; padding:60px 20px; color:var(--text-3); font-weight:600; font-size:1rem; }
        .dashboard-error { padding:18px 24px; border-radius:8px; background:#fef2f2; border:1px solid #fecaca; color:#991b1b; font-weight:600; margin:20px; text-align:center; }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <main class="admin-main">
            <header class="admin-header">
                <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <div class="header-titles">
                    <h1 class="page-title">Semester Results Matrix</h1>
                    <p class="page-sub"><?= sanitize($schoolName) ?> &bull; Term Examinations & Comprehensive Evaluations</p>
                </div>

                <div class="header-actions" style="display:flex;align-items:center;">
                    <!-- Notification Bell -->
                    <div class="notif-container" id="notifContainer">
                        <button type="button" class="notif-trigger" id="notifTrigger" title="Recent Activity Notifications" aria-label="Notifications">
                            <i class="fas fa-bell"></i>
                            <?php if ($notifData['unread'] > 0): ?>
                                <span class="notif-badge" id="notifBadge"><?= (int)$notifData['unread'] ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="notif-dropdown" id="notifDropdown">
                            <div class="notif-dropdown-header">
                                <span><i class="fas fa-bell"></i> Recent Alerts & Activity</span>
                                <span style="font-size:0.75rem;color:var(--muted);"><?= count($notifData['items']) ?> events</span>
                            </div>
                            <div class="notif-dropdown-list">
                                <?php if (empty($notifData['items'])): ?>
                                    <div style="padding:24px;text-align:center;color:var(--muted);font-size:0.82rem;">No recent notifications.</div>
                                <?php else: ?>
                                    <?php foreach ($notifData['items'] as $nt): ?>
                                        <a href="<?= !empty($nt['link']) ? sanitize($nt['link']) : '#' ?>" class="notif-item">
                                            <div class="notif-item-icon"><i class="fas fa-pen-to-square"></i></div>
                                            <div style="flex:1;">
                                                <div style="font-weight:700;color:var(--text);margin-bottom:2px;"><?= sanitize($nt['title']) ?></div>
                                                <div style="color:var(--muted);font-size:0.78rem;"><?= sanitize($nt['message']) ?></div>
                                                <div style="color:#94a3b8;font-size:0.72rem;margin-top:2px;"><i class="far fa-clock"></i> <?= date('M d, Y • h:i A', strtotime($nt['created_at'])) ?></div>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="admin-user">
                        <i class="fas fa-user-circle"></i>
                        <span><?= sanitize($adminUser) ?></span>
                    </div>
                </div>
            </header>

            <div class="admin-content">

                <!-- Flash Messages -->
                <?php if ($success !== ''): ?>
                <div style="margin-bottom:16px;padding:14px 20px;border-radius:10px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;font-weight:600;">
                    <i class="fas fa-check-circle" style="margin-right:8px;"></i> <?= sanitize($success) ?>
                </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                <div style="margin-bottom:16px;padding:14px 20px;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-weight:600;">
                    <i class="fas fa-exclamation-circle" style="margin-right:8px;"></i> <?= sanitize($error) ?>
                </div>
                <?php endif; ?>

                <!-- Filter & Class Selector Bar -->
                <?php
                // Academic-year options. The picker is a real <select>, so adding more
                // sessions later only means extending this list. The current selection is
                // always included so a stale/bookmarked year can never be dropped.
                $yearOptions = ['2025-26', '2024-25'];
                if ($year !== '' && !in_array($year, $yearOptions, true)) {
                    array_unshift($yearOptions, $year);
                }
                ?>
                <div class="ut-filter-bar">
                    <form method="GET" class="ut-filter-inputs">
                        <div class="ut-filter-field">
                            <label class="ut-filter-label" for="aySelect"><i class="fas fa-calendar-alt"></i> Academic Year</label>
                            <div class="ay-select">
                                <select class="ay-select-input" id="aySelect" name="year" onchange="this.form.submit()">
                                    <?php foreach ($yearOptions as $yearOpt): ?>
                                        <option value="<?= sanitize($yearOpt) ?>" <?= $year === $yearOpt ? 'selected' : '' ?>><?= sanitize($yearOpt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <span class="ay-select-face" aria-hidden="true">
                                    <span class="ay-select-icon"><i class="fas fa-calendar-day"></i></span>
                                    <span class="ay-select-value"><?= sanitize($year) ?></span>
                                    <i class="fas fa-chevron-down ay-select-caret"></i>
                                </span>
                            </div>
                        </div>

                        <div class="ut-filter-field">
                            <label class="ut-filter-label"><i class="fas fa-graduation-cap"></i> Standard</label>
                            <select name="std" onchange="this.form.submit()">
                                <?php for ($s = 1; $s <= 10; $s++): ?>
                                    <option value="<?= $s ?>" <?= (string)$std === (string)$s ? 'selected' : '' ?>>STD <?= $s ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>

                        <div class="ut-filter-field">
                            <label class="ut-filter-label"><i class="fas fa-layer-group"></i> Division</label>
                            <select name="division" onchange="this.form.submit()">
                                <option value="A" <?= $division === 'A' ? 'selected' : '' ?>>Div A</option>
                                <option value="B" <?= $division === 'B' ? 'selected' : '' ?>>Div B</option>
                                <option value="C" <?= $division === 'C' ? 'selected' : '' ?>>Div C</option>
                            </select>
                        </div>

                        <div class="ut-filter-field">
                            <label class="ut-filter-label"><i class="fas fa-book-open"></i> Semester</label>
                            <select name="semester" onchange="this.form.submit()">
                                <option value="Sem 1" <?= ($semester === 'Sem 1' || $semester === '1') ? 'selected' : '' ?>>Semester 1 (I)</option>
                                <option value="Sem 2" <?= ($semester === 'Sem 2' || $semester === '2') ? 'selected' : '' ?>>Semester 2 (II)</option>
                            </select>
                        </div>

                        <div class="ut-filter-field">
                            <label class="ut-filter-label"><i class="fas fa-signature"></i> Parents Sign</label>
                            <select name="sign_mode" onchange="this.form.submit()">
                                <option value="digital" <?= $signMode === 'digital' ? 'selected' : '' ?>>Digital Sign (Interactive Toggle)</option>
                                <option value="blank" <?= $signMode === 'blank' ? 'selected' : '' ?>>Blank Block (Physical Sign)</option>
                                <option value="hide" <?= $signMode === 'hide' ? 'selected' : '' ?>>Hide Parents Column</option>
                            </select>
                        </div>

                        <div class="ut-filter-field" style="min-width:180px;">
                            <label class="ut-filter-label"><i class="fas fa-search"></i> Search Student</label>
                            <div style="position:relative;">
                                <input type="search" name="search" id="studentSearchInput" value="<?= sanitize($search) ?>" placeholder="Filter roll or name…" autocomplete="off" style="padding-left:32px;width:100%;">
                                <i class="fas fa-search" style="position:absolute;left:10px;top:50%;transform:translateY(-50%);color:#94a3b8;font-size:0.8rem;pointer-events:none;"></i>
                            </div>
                        </div>
                    </form>

                    <!-- Quick Actions Toolbar -->
                    <div class="ut-action-toolbar">
                        <div class="ut-btn-group">
                            <a href="import_excel.php?class=<?= e_url($classKey) ?>" class="btn btn-sm btn-secondary" title="Upload spreadsheet">
                                <i class="fas fa-file-excel"></i> Import
                            </a>
                            <a href="results.php?action=export_excel&year=<?= urlencode($year) ?>&std=<?= urlencode($std) ?>&division=<?= urlencode($division) ?>&semester=<?= urlencode($semester) ?>&sign_mode=<?= urlencode($signMode) ?>" class="btn btn-sm btn-secondary" title="Download Excel template">
                                <i class="fas fa-download"></i> Export
                            </a>
                            <a href="results_print.php?year=<?= urlencode($year) ?>&std=<?= urlencode($std) ?>&division=<?= urlencode($division) ?>&semester=<?= urlencode($semester) ?>&sign_mode=<?= urlencode($signMode) ?>" target="_blank" class="btn btn-sm btn-secondary" title="Print-ready marksheet">
                                <i class="fas fa-print"></i> Print
                            </a>
                        </div>

                        <div class="ut-btn-group">
                            <a href="whatsapp_share.php?class=<?= e_url($classKey) ?>" class="btn btn-sm btn-whatsapp" title="Open WhatsApp Dispatcher">
                                <i class="fa-brands fa-whatsapp"></i> WhatsApp Hub
                            </a>
                            <a href="entry.php?class=<?= e_url($classKey) ?>" class="btn btn-sm btn-secondary" title="Manage Subjects / Settings">
                                <i class="fas fa-sliders-h"></i> Subjects
                            </a>
                        </div>

                        <div class="ut-btn-group">
                            <button type="submit" form="bulkMarksForm" class="btn btn-sm btn-primary" id="bulkSaveBtn" title="Save All Marks (Ctrl+S)">
                                <i class="fas fa-save"></i> Save Changes
                            </button>
                        </div>

                        <?php if (!empty($students)): ?>
                        <div class="ut-btn-group">
                            <button type="button" class="btn btn-sm btn-whatsapp" id="bulkWaBtn" title="Share selected students via WhatsApp" disabled>
                                <i class="fa-brands fa-whatsapp"></i> WhatsApp <span class="ut-bulk-count" id="bulkWaCount">0</span>
                            </button>
                            <button type="button" class="btn btn-sm btn-danger" id="bulkDeleteBtn" title="Delete selected students" disabled>
                                <i class="fas fa-trash"></i> Delete <span class="ut-bulk-count" id="bulkDeleteCount">0</span>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- KPI Summary Cards -->
                <div class="ut-kpi-grid">
                    <div class="ut-kpi-card">
                        <div class="ut-kpi-icon primary"><i class="fas fa-users"></i></div>
                        <div>
                            <div class="ut-kpi-val"><?= $totalStudents ?></div>
                            <div class="ut-kpi-lbl">Students Enrolled</div>
                        </div>
                    </div>
                    <div class="ut-kpi-card">
                        <div class="ut-kpi-icon blue"><i class="fas fa-chart-line"></i></div>
                        <div>
                            <div class="ut-kpi-val" id="kpiClassAvg"><?= $classAvg ?>%</div>
                            <div class="ut-kpi-lbl">Class Average</div>
                        </div>
                    </div>
                    <div class="ut-kpi-card">
                        <div class="ut-kpi-icon amber"><i class="fas fa-trophy"></i></div>
                        <div>
                            <div class="ut-kpi-val" id="kpiHighest"><?= $fmtNum($highestObtained) ?></div>
                            <div class="ut-kpi-lbl">Highest Marks / <?= $fmtNum($classMaxTotal) ?></div>
                        </div>
                    </div>
                    <div class="ut-kpi-card">
                        <div class="ut-kpi-icon success"><i class="fas fa-check-double"></i></div>
                        <div>
                            <div class="ut-kpi-val" id="kpiPassPct"><?= $passPct ?>%</div>
                            <div class="ut-kpi-lbl">Pass Percentage (&ge;<?= (int)$std >= 9 ? '35' : '33' ?>%)</div>
                        </div>
                    </div>
                </div>

                <!-- If No Students: Clean Banner -->
                <?php if (empty($students)): ?>
                <div style="background:var(--paper);border:2px dashed #cbd5e1;border-radius:var(--radius);padding:48px 24px;text-align:center;margin-bottom:24px;">
                    <i class="fas fa-user-graduate" style="font-size:3rem;color:#94a3b8;margin-bottom:16px;display:block;"></i>
                    <h3 style="margin:0 0 8px 0;font-size:1.25rem;font-weight:700;">No Semester Records Found for STD <?= sanitize($romanStd) ?> <?= sanitize($division) ?> (<?= sanitize($semester) ?>)</h3>
                    <p style="margin:0 0 20px 0;color:var(--muted);font-size:0.9rem;max-width:600px;display:inline-block;">
                        You can immediately import results from an Excel sheet or select a different academic year / class above.
                    </p>
                    <div style="display:flex;align-items:center;justify-content:center;gap:12px;">
                        <a href="import_excel.php?class=<?= e_url($classKey) ?>" class="btn btn-primary" style="font-weight:700;">
                            <i class="fas fa-file-excel"></i> Upload Semester Excel
                        </a>
                        <a href="entry.php?class=<?= e_url($classKey) ?>" class="btn btn-secondary">
                            <i class="fas fa-plus"></i> Manual Student Entry
                        </a>
                    </div>
                </div>
                <?php else: ?>

                <!-- Professional Two-Tier Marksheet Table Matrix -->
                <div class="ut-table-card">
                    <form method="POST" id="bulkMarksForm" data-std="<?= e_attr($std) ?>">
                        <?= csrfField() ?>
                        <input type="hidden" name="action" value="save_marks">
                        <input type="hidden" name="class_key" value="<?= e_attr($classKey) ?>">
                        <input type="hidden" name="year" value="<?= sanitize($year) ?>">
                        <input type="hidden" name="std" value="<?= sanitize($std) ?>">
                        <input type="hidden" name="division" value="<?= sanitize($division) ?>">
                        <input type="hidden" name="semester" value="<?= sanitize($semester) ?>">

                        <!-- Table Card Executive Header -->
                        <div class="ut-table-header">
                            <div class="ut-table-title-area">
                                <h2 class="ut-table-title">
                                    <i class="fas fa-table-cells" style="color:var(--brand);margin-right:8px;"></i>
                                    STD <?= sanitize($romanStd) ?> &ndash; <?= sanitize($division) ?> Marksheet Matrix
                                </h2>
                                <span class="ut-badge ut-badge-gold"><i class="fas fa-award" style="margin-right:4px;"></i> <?= sanitize($semester) ?></span>
                                <span class="ut-badge ut-badge-info" id="tableStudentCount"><i class="fas fa-users" style="margin-right:4px;"></i> <?= count($students) ?> Students</span>
                                <span class="ut-badge"><i class="fas fa-calendar" style="margin-right:4px;"></i> <?= sanitize($year) ?></span>
                            </div>
                            <div class="ut-table-header-tools">
                                <div class="ut-autosave-wrap" id="autoSaveStatusWrap">
                                    <span id="autoSaveStatus"></span>
                                </div>
                                <button type="submit" form="bulkMarksForm" class="btn btn-sm btn-primary" title="Save All Marks (Ctrl+S)">
                                    <i class="fas fa-save"></i> Save Changes
                                </button>
                            </div>
                        </div>

                        <div class="ut-table-wrapper">
                            <table class="ut-matrix-table results-table" id="resultsTable">
                                <thead>
                                    <!-- Tier 1: Column Headers -->
                                    <tr>
                                        <th rowspan="2" class="ut-check"><input type="checkbox" id="checkAll" class="ut-check-box" title="Select all students" aria-label="Select all students"></th>
                                        <th rowspan="2" class="ut-sticky-roll">Roll No</th>
                                        <th rowspan="2" class="ut-sticky-name">Name of the Students</th>
                                        <?php if ($matrixMode): ?>
                                            <?php foreach ($matrixSubjects as $ms): 
                                                $isSingleMark = ((float)$ms['sa_max'] <= 0.0);
                                                $subCols = $isSingleMark ? 1 : 3;
                                            ?>
                                            <th colspan="<?= $subCols ?>" class="rm-subj-grp"><?= sanitize($ms['subject_name']) ?></th>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <th>Subjects</th>
                                        <?php endif; ?>
                                        <th style="min-width:68px;">Total</th>
                                        <th style="min-width:56px;">%</th>
                                        <th style="min-width:62px;">Grade</th>
                                        <th style="min-width:62px;">Status</th>
                                        <th rowspan="2" style="min-width:130px;">Remark</th>
                                        <th rowspan="2" style="min-width:54px;">Attn</th>
                                        <?php if ($showSign): ?>
                                        <th rowspan="2" class="col-parent-sign" style="min-width:85px;text-align:center;">Parents Sign</th>
                                        <?php endif; ?>
                                        <th rowspan="2" class="ut-actions-th" style="min-width:145px;text-align:center;">Actions</th>
                                    </tr>
                                    <!-- Tier 2: Max Marks Sub-Headers -->
                                    <tr class="th-sub-max-row">
                                        <?php if ($matrixMode): ?>
                                            <?php foreach ($matrixSubjects as $ms):
                                                $isSingleMark = ((float)$ms['sa_max'] <= 0.0);
                                            ?>
                                                <?php if ($isSingleMark): ?>
                                                <th class="th-sub-max th-sub-tot" title="Subject Max Marks">(<?= $fmtNum($ms['fa_max']) ?>)</th>
                                                <?php else: ?>
                                                <th class="th-sub-max" title="Formative Assessment (FA) Max">F(<?= $fmtNum($ms['fa_max']) ?>)</th>
                                                <th class="th-sub-max" title="Summative Assessment (SA) Max">S(<?= $fmtNum($ms['sa_max']) ?>)</th>
                                                <th class="th-sub-max th-sub-tot" title="Subject Total Max"><?= $fmtNum($ms['fa_max'] + $ms['sa_max']) ?></th>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <th class="th-sub-max"></th>
                                        <?php endif; ?>
                                        <th class="th-sub-max th-sub-tot" title="Class Maximum Total"><?= $fmtNum($classMaxTotal) ?></th>
                                        <th class="th-sub-max">100%</th>
                                        <th class="th-sub-max">Scale</th>
                                        <th class="th-sub-max">&ge;<?= (int)$std >= 9 ? '35' : '33' ?>%</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($students as $s): 
                                        $rowRoll = (string)($s['roll_no'] ?? '');
                                        $rowCk = (string)($s['class_key'] ?? $classKey);
                                        $gVal = (string)($s['grade'] ?? 'F');
                                        $gCls = 'grade-' . preg_replace('/[^a-zA-Z0-9_-]/', '', $gVal);
                                        if (in_array(substr($gVal, 0, 1), ['A', 'B', 'C', 'D'])) $gCls .= ' grade-' . substr($gVal, 0, 1);
                                        elseif (in_array($gVal, ['Distinction', 'Grade I', 'Pass'])) $gCls .= ' grade-A';
                                        else $gCls .= ' grade-F';
                                    ?>
                                    <tr class="ut-student-row" data-roll="<?= e_attr($rowRoll) ?>">
                                        <td class="ut-check">
                                            <input type="checkbox" class="ut-row-check row-check" form="bulkDeleteForm" name="rolls[]" value="<?= e_attr($rowRoll) ?>" aria-label="Select roll <?= e_attr($rowRoll) ?>">
                                        </td>
                                        <td class="ut-sticky-roll"><?= sanitize($s['roll_no']) ?></td>
                                        <td class="ut-sticky-name" title="<?= sanitize($s['student_name']) ?>">
                                            <?= sanitize($s['student_name']) ?>
                                        </td>

                                        <!-- Subject Mark Inputs -->
                                        <?php if ($matrixMode): ?>
                                            <?php foreach ($matrixSubjects as $ms):
                                                $isSingleMark = ((float)$ms['sa_max'] <= 0.0);
                                                $subjKey = mb_strtolower($ms['subject_name']);
                                                $mk = $matrixMarks[$rowRoll][$subjKey] ?? null;
                                                $hasFa = ($mk !== null && $mk['fa_obtained'] !== null);
                                                $hasSa = ($mk !== null && $mk['sa_obtained'] !== null);
                                                $faV = $hasFa ? $fmtNum($mk['fa_obtained']) : '';
                                                $saV = $hasSa ? $fmtNum($mk['sa_obtained']) : '';
                                                $subjSubtotal = ((float)($mk['fa_obtained'] ?? 0)) + ((float)($mk['sa_obtained'] ?? 0));
                                                $hasAny = ($faV !== '' || $saV !== '');
                                            ?>
                                                <?php if ($isSingleMark): ?>
                                                <td class="rm-cell rm-cell--single">
                                                    <input type="number" step="0.5" min="0" max="<?= e_attr($fmtNum($ms['fa_max'])) ?>"
                                                           class="ut-mark-input rm-input rm-input--single"
                                                           data-kind="fa"
                                                           data-subj="<?= e_attr($subjKey) ?>"
                                                           data-famax="<?= e_attr($fmtNum($ms['fa_max'])) ?>"
                                                           data-samax="0"
                                                           name="marks[<?= e_attr($rowRoll) ?>][<?= e_attr($ms['subject_name']) ?>][fa]"
                                                           value="<?= e_attr($faV) ?>"
                                                           placeholder="-"
                                                           autocomplete="off">
                                                    <input type="hidden" name="marks[<?= e_attr($rowRoll) ?>][<?= e_attr($ms['subject_name']) ?>][sa]" value="0">
                                                </td>
                                                <?php else: ?>
                                                <td class="rm-cell rm-cell--fa">
                                                    <input type="number" step="0.5" min="0" max="<?= e_attr($fmtNum($ms['fa_max'])) ?>"
                                                           class="ut-mark-input rm-input rm-input--fa"
                                                           data-kind="fa"
                                                           data-subj="<?= e_attr($subjKey) ?>"
                                                           data-famax="<?= e_attr($fmtNum($ms['fa_max'])) ?>"
                                                           data-samax="<?= e_attr($fmtNum($ms['sa_max'])) ?>"
                                                           name="marks[<?= e_attr($rowRoll) ?>][<?= e_attr($ms['subject_name']) ?>][fa]"
                                                           value="<?= e_attr($faV) ?>"
                                                           placeholder="-"
                                                           autocomplete="off">
                                                </td>
                                                <td class="rm-cell rm-cell--sa">
                                                    <input type="number" step="0.5" min="0" max="<?= e_attr($fmtNum($ms['sa_max'])) ?>"
                                                           class="ut-mark-input rm-input rm-input--sa"
                                                           data-kind="sa"
                                                           data-subj="<?= e_attr($subjKey) ?>"
                                                           data-famax="<?= e_attr($fmtNum($ms['fa_max'])) ?>"
                                                           data-samax="<?= e_attr($fmtNum($ms['sa_max'])) ?>"
                                                           name="marks[<?= e_attr($rowRoll) ?>][<?= e_attr($ms['subject_name']) ?>][sa]"
                                                           value="<?= e_attr($saV) ?>"
                                                           placeholder="-"
                                                           autocomplete="off">
                                                </td>
                                                <td class="rm-cell rm-cell--subtot" data-rm-subtot="<?= e_attr($subjKey) ?>" style="font-weight:700;font-variant-numeric:tabular-nums;"><?= $hasAny ? $fmtNum($subjSubtotal) : '-' ?></td>
                                                <?php endif; ?>
                                            <?php endforeach; ?>
                                        <?php else: ?>
                                            <td><?= sanitize($s['subject_names'] ?? '') ?></td>
                                        <?php endif; ?>

                                        <!-- Live Computed Cells -->
                                        <td class="ut-cell-total" data-rm="total"><?= number_format($s['obtained'], 1) ?></td>
                                        <td class="ut-cell-pct" data-rm="pct"><?= e_attr((float)$s['percentage']) ?>%</td>
                                        <td>
                                            <span data-rm="grade" class="ut-cell-grade <?= e_attr($gCls) ?>"><?= sanitize($gVal) ?></span>
                                        </td>
                                        <td>
                                            <span data-rm="status" class="<?= $s['passed'] ? 'pass-tag' : 'fail-tag' ?>"><?= $s['passed'] ? 'PASS' : 'FAIL' ?></span>
                                        </td>
                                        <td>
                                            <input type="text" 
                                                   name="remarks[<?= e_attr($rowRoll) ?>]" 
                                                   value="<?= e_attr(rcDisplayRemark($s['remark'] ?? '', $s['percentage'] ?? 0)) ?>" 
                                                   placeholder="Add remark…" 
                                                   class="ut-cell-remark rm-remark" 
                                                   maxlength="120" 
                                                   autocomplete="off">
                                        </td>
                                        <td>
                                            <input type="text" 
                                                   name="attendance[<?= e_attr($rowRoll) ?>]" 
                                                   value="<?= sanitize($s['attendance']) ?>" 
                                                   class="ut-attn-input" 
                                                   maxlength="5" 
                                                   autocomplete="off">
                                        </td>

                                        <!-- Parents Sign Column -->
                                        <?php if ($showSign): ?>
                                        <td class="col-parent-sign" style="text-align:center;min-width:85px;vertical-align:middle;">
                                            <?php if ($signMode === 'blank'): ?>
                                                <div style="height:26px;width:100%;min-width:60px;" title="Blank empty block for physical parent signature"></div>
                                            <?php else: ?>
                                                <input type="checkbox" class="sign-check parent-sign-toggle"
                                                    data-roll="<?= e_attr($s['roll_no']) ?>"
                                                    data-class="<?= e_attr($rowCk) ?>"
                                                    <?= !empty($s['parents_sign']) ? 'checked' : '' ?>
                                                    title="Parents digital signature — click to toggle digital confirmation">
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>

                                        <!-- Executive Action Column -->
                                        <td class="ut-actions-cell">
                                            <div class="ut-action-group">
                                                <button type="button" class="ut-action-btn analytics dashboard-btn" 
                                                        data-class="<?= e_attr($rowCk) ?>" 
                                                        data-roll="<?= e_attr($s['roll_no']) ?>" 
                                                        data-name="<?= e_attr($s['student_name']) ?>" 
                                                        title="Performance Analytics & Charts">
                                                    <i class="fas fa-chart-line"></i>
                                                </button>
                                                <a href="view_report_card.php?class=<?= e_url($rowCk) ?>&roll=<?= e_url($s['roll_no']) ?>" 
                                                   class="ut-action-btn report" title="View Full Report Card" target="_blank" rel="noopener">
                                                    <i class="fas fa-file-invoice"></i>
                                                </a>
                                                <a href="entry.php?class=<?= e_url($rowCk) ?>&roll=<?= e_url($s['roll_no']) ?>" 
                                                   class="ut-action-btn edit" title="Edit Student Details">
                                                    <i class="fas fa-pen"></i>
                                                </a>
                                                <button type="button" class="ut-action-btn whatsapp btn-wa-row js-wa-row-btn" 
                                                        data-class="<?= e_attr($rowCk) ?>"
                                                        data-roll="<?= e_attr($s['roll_no']) ?>"
                                                        data-name="<?= e_attr($s['student_name']) ?>"
                                                        data-obtained="<?= number_format($s['obtained'], 1) ?>"
                                                        data-total="<?= number_format($s['maxtotal'], 1) ?>"
                                                        data-pct="<?= e_attr((float)$s['percentage']) ?>"
                                                        data-grade="<?= sanitize($gVal) ?>"
                                                        data-status="<?= $s['passed'] ? 'PASS' : 'FAIL' ?>"
                                                        data-attendance="<?= sanitize($s['attendance']) ?>"
                                                        data-remark="<?= sanitize(rcDisplayRemark($s['remark'] ?? '', $s['percentage'] ?? 0)) ?>"
                                                        data-phone="<?= e_attr(preg_replace('/\D+/', '', $s['parent_phone'] ?? '')) ?>"
                                                        data-public-url="<?= e_attr(rcGetPublicCardUrl($rowCk, $s['roll_no'])) ?>"
                                                        title="Share Marksheet via WhatsApp">
                                                    <i class="fa-brands fa-whatsapp"></i>
                                                </button>
                                                <button type="button" class="ut-action-btn delete" 
                                                        onclick='confirmDelete(<?= e_js($s['roll_no']) ?>, <?= e_js($rowCk) ?>)' 
                                                        title="Delete Student Record">
                                                    <i class="fas fa-trash-alt"></i>
                                                </button>
                                            </div>
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

    <!-- Hidden Bulk Delete Form -->
    <form id="bulkDeleteForm" method="POST" style="display:none;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete_bulk">
        <input type="hidden" name="class_key" value="<?= e_attr($classKey) ?>">
    </form>

    <!-- Hidden Single Delete Form -->
    <form id="deleteForm" method="POST" style="display:none;">
        <?= csrfField() ?>
        <input type="hidden" name="action" value="delete">
        <input type="hidden" name="class_key" id="delClassKey">
        <input type="hidden" name="roll" id="delRoll">
    </form>

    <!-- Single Delete Modal -->
    <div class="ut-confirm-modal" id="deleteModal" role="alertdialog" aria-modal="true" aria-labelledby="deleteModalTitle" hidden>
        <div class="ut-confirm-backdrop" data-close></div>
        <div class="ut-confirm-card" role="document">
            <div class="ut-confirm-icon"><i class="fas fa-trash"></i></div>
            <h3 id="deleteModalTitle">Delete student?</h3>
            <p>Roll <strong id="deleteModalRoll"></strong> and all associated marks will be permanently removed.</p>
            <div class="ut-confirm-actions">
                <button type="button" class="btn btn-outline" id="deleteCancelBtn">Cancel</button>
                <button type="button" class="btn btn-danger" id="deleteConfirmBtn"><i class="fas fa-trash"></i> Delete</button>
            </div>
        </div>
    </div>

    <!-- Bulk Delete Modal -->
    <div class="ut-confirm-modal" id="bulkDeleteModal" role="alertdialog" aria-modal="true" aria-labelledby="bulkDeleteModalTitle" hidden>
        <div class="ut-confirm-backdrop" data-close></div>
        <div class="ut-confirm-card" role="document">
            <div class="ut-confirm-icon"><i class="fas fa-trash"></i></div>
            <h3 id="bulkDeleteModalTitle">Delete <span id="bulkDeleteModalCount">0</span> students?</h3>
            <p>The selected students and all their marks records will be permanently removed. This cannot be undone.</p>
            <div class="ut-confirm-actions">
                <button type="button" class="btn btn-outline" id="bulkDeleteCancelBtn">Cancel</button>
                <button type="button" class="btn btn-danger" id="bulkDeleteConfirmBtn"><i class="fas fa-trash"></i> Delete All</button>
            </div>
        </div>
    </div>

    <!-- Student Dashboard Modal -->
    <div class="modal" id="dashboardModal" role="dialog" aria-modal="true" aria-labelledby="dashTitle" style="display:none;">
        <div class="modal-backdrop" tabindex="-1"></div>
        <div class="modal-panel" role="document">
            <header class="modal-header">
                <h3 id="dashTitle"><i class="fas fa-chart-line"></i> <span class="student-name"></span> — Dashboard</h3>
                <div class="header-actions">
                    <button class="btn btn-sm btn-primary" id="exportPdfBtn" title="Download Complete Analytics PDF Report (Overview, Subjects, Trends, Comparative)"><i class="fas fa-file-pdf"></i> Download Analytics PDF</button>
                    <button class="modal-close" aria-label="Close dashboard"><i class="fas fa-times"></i></button>
                </div>
            </header>
            <nav class="dashboard-tabs" role="tablist" aria-label="Dashboard sections">
                <button role="tab" data-tab="overview" class="active" aria-selected="true"><i class="fas fa-home"></i> Overview</button>
                <button role="tab" data-tab="subjects" aria-selected="false"><i class="fas fa-book"></i> Subjects</button>
                <button role="tab" data-tab="trends" aria-selected="false"><i class="fas fa-chart-line"></i> Trends</button>
                <button role="tab" data-tab="comparative" aria-selected="false"><i class="fas fa-users"></i> Comparative</button>
            </nav>
            <div class="modal-body">
                <div role="tabpanel" id="tab-overview" class="tab-panel active">
                    <div class="kpi-grid" id="kpiGrid"></div>
                    <div class="overview-charts">
                        <div class="chart-card">
                            <h4><i class="fas fa-tachometer-alt"></i> Grade Gauge</h4>
                            <div class="chart-wrap-small"><canvas id="gaugeChart"></canvas></div>
                        </div>
                        <div class="chart-card">
                            <h4><i class="fas fa-chart-pie"></i> FA vs SA Split</h4>
                            <div class="chart-wrap-small"><canvas id="faSaSplitChart"></canvas></div>
                        </div>
                    </div>
                    <div class="meta-cards" id="metaCards"></div>
                </div>
                <div role="tabpanel" id="tab-subjects" class="tab-panel" hidden>
                    <div class="chart-card full-width">
                        <div class="chart-wrap"><canvas id="subjectChart"></canvas></div>
                    </div>
                </div>
                <div role="tabpanel" id="tab-trends" class="tab-panel" hidden>
                    <div class="chart-card full-width">
                        <div class="chart-wrap"><canvas id="trendChart"></canvas></div>
                    </div>
                </div>
                <div role="tabpanel" id="tab-comparative" class="tab-panel" hidden>
                    <div class="chart-card full-width">
                        <div class="chart-wrap"><canvas id="deviationChart"></canvas></div>
                    </div>
                </div>
                <div class="dashboard-loading" id="dashboardLoading" style="display:none;">
                    <i class="fas fa-spinner fa-spin"></i> Loading dashboard…
                </div>
                <div class="dashboard-error" id="dashboardError" style="display:none;"></div>
            </div>
        </div>
    </div>

    <!-- Scripts -->
    <script>
    // Live Student Search Filter
    (function () {
        const searchInput = document.getElementById('studentSearchInput');
        if (!searchInput) return;
        searchInput.addEventListener('input', function () {
            const query = this.value.trim().toLowerCase();
            const rows = document.querySelectorAll('#resultsTable tbody tr.ut-student-row');
            let visibleCount = 0;
            rows.forEach(row => {
                const roll = (row.dataset.roll || '').toLowerCase();
                const nameCell = row.querySelector('.ut-sticky-name');
                const name = (nameCell ? nameCell.textContent : '').toLowerCase();
                const match = !query || roll.includes(query) || name.includes(query);
                row.style.display = match ? '' : 'none';
                if (match) visibleCount++;
            });
            const counter = document.getElementById('tableStudentCount');
            if (counter) {
                counter.innerHTML = query ? ('<i class="fas fa-filter" style="margin-right:4px;"></i> ' + visibleCount + ' of ' + rows.length) : ('<i class="fas fa-users" style="margin-right:4px;"></i> ' + rows.length + ' Students');
            }
        });
    })();

    // Delete student confirm handling
    (function () {
        const delModal = document.getElementById('deleteModal');
        let pendingRoll = '', pendingCk = '', lastFocus = null;
        window.confirmDelete = function (roll, ck) {
            pendingRoll = roll; pendingCk = ck;
            document.getElementById('deleteModalRoll').textContent = roll;
            lastFocus = document.activeElement;
            delModal.hidden = false;
            document.body.style.overflow = 'hidden';
            document.getElementById('deleteCancelBtn').focus();
        };
        function closeDeleteModal() {
            delModal.hidden = true;
            document.body.style.overflow = '';
            pendingRoll = ''; pendingCk = '';
            if (lastFocus && lastFocus.focus) lastFocus.focus();
        }
        document.getElementById('deleteCancelBtn')?.addEventListener('click', closeDeleteModal);
        delModal?.querySelector('[data-close]')?.addEventListener('click', closeDeleteModal);
        document.addEventListener('keydown', (e) => {
            if (delModal && !delModal.hidden && e.key === 'Escape') closeDeleteModal();
        });
        document.getElementById('deleteConfirmBtn')?.addEventListener('click', () => {
            document.getElementById('delClassKey').value = pendingCk;
            document.getElementById('delRoll').value = pendingRoll;
            document.getElementById('deleteForm').submit();
        });
    })();

    // Bulk selection and delete handling
    (function () {
        const checkAll = document.getElementById('checkAll');
        const bulkBtn = document.getElementById('bulkDeleteBtn');
        const bulkCount = document.getElementById('bulkDeleteCount');
        const bulkModal = document.getElementById('bulkDeleteModal');
        const bulkWaBtn = document.getElementById('bulkWaBtn');
        const bulkWaCount = document.getElementById('bulkWaCount');

        const boxes = () => Array.from(document.querySelectorAll('.ut-row-check:not(:disabled)'));

        function refresh() {
            const checked = boxes().filter(b => b.checked);
            const n = checked.length;
            if (bulkBtn) bulkBtn.disabled = n === 0;
            if (bulkCount) bulkCount.textContent = n;
            if (bulkWaBtn) bulkWaBtn.disabled = n === 0;
            if (bulkWaCount) bulkWaCount.textContent = n;
            if (checkAll) {
                const all = boxes();
                checkAll.checked = all.length > 0 && all.every(b => b.checked);
                checkAll.indeterminate = n > 0 && n < all.length;
            }
            document.querySelectorAll('#resultsTable tbody tr').forEach(tr => {
                const box = tr.querySelector('.ut-row-check');
                tr.classList.toggle('is-checked', !!(box && box.checked));
            });
        }

        if (checkAll) {
            checkAll.addEventListener('change', () => {
                boxes().forEach(b => { b.checked = checkAll.checked; });
                refresh();
            });
        }

        document.getElementById('resultsTable')?.addEventListener('change', (e) => {
            if (e.target && e.target.classList.contains('ut-row-check')) {
                refresh();
            }
        });

        if (bulkBtn && bulkModal) {
            bulkBtn.addEventListener('click', () => {
                const n = boxes().filter(b => b.checked).length;
                if (n === 0) return;
                document.getElementById('bulkDeleteModalCount').textContent = n;
                bulkModal.hidden = false;
                document.body.style.overflow = 'hidden';
            });
            function closeBulk() {
                bulkModal.hidden = true;
                document.body.style.overflow = '';
            }
            document.getElementById('bulkDeleteCancelBtn')?.addEventListener('click', closeBulk);
            bulkModal.querySelector('[data-close]')?.addEventListener('click', closeBulk);
            document.getElementById('bulkDeleteConfirmBtn')?.addEventListener('click', () => {
                document.getElementById('bulkDeleteForm').submit();
            });
        }
    })();

    // Dashboard Modal Handling
    (function () {
        const modal = document.getElementById('dashboardModal');
        if (!modal) return;
        const closeBtn = modal.querySelector('.modal-close');
        const backdrop = modal.querySelector('.modal-backdrop');
        const tabs = modal.querySelectorAll('.dashboard-tabs button');
        let currentCharts = {};
        let activeStudent = { ck: '', roll: '' };
        let activeDashboardData = null;

        function escapeHtml(str) {
            if (str === null || str === undefined) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;');
        }

        function closeModal() {
            modal.style.display = 'none';
            document.body.style.overflow = '';
            Object.values(currentCharts).forEach(c => { if (c && c.destroy) c.destroy(); });
            currentCharts = {};
            activeDashboardData = null;
        }

        closeBtn?.addEventListener('click', closeModal);
        backdrop?.addEventListener('click', closeModal);
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && modal.style.display === 'flex') closeModal();
        });

        // Generate and download full comprehensive A4 Analytics PDF Report
        async function generateAnalyticsPdf(data, actStudent) {
            const exportBtn = document.getElementById('exportPdfBtn');
            const origHtml = exportBtn ? exportBtn.innerHTML : '<i class="fas fa-file-pdf"></i> Download Analytics PDF';

            if (exportBtn) {
                exportBtn.disabled = true;
                exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing Analytics PDF...';
            }

            let container = null;
            try {
                // If data not yet cached, fetch it now
                if (!data || !data.student || String(data.student.roll) !== String(actStudent.roll)) {
                    if (exportBtn) exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Fetching Analytics...';
                    const resp = await fetch('api_student_dashboard.php?class=' + encodeURIComponent(actStudent.ck) + '&roll=' + encodeURIComponent(actStudent.roll));
                    if (!resp.ok) throw new Error('HTTP ' + resp.status + ' while fetching student analytics');
                    data = await resp.json();
                    if (!data || data.error) throw new Error((data && data.error) || 'Failed to load student analytics data');
                    activeDashboardData = data;
                }

                if (!window.jspdf || !window.jspdf.jsPDF) {
                    throw new Error('jsPDF library is not loaded. Please refresh the page.');
                }
                if (!window.html2canvas) {
                    throw new Error('html2canvas library is not loaded. Please refresh the page.');
                }

                if (exportBtn) exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Rendering Analytics Charts...';

                const student = data.student || {};
                const summary = data.summary || {};
                const subjects = Array.isArray(data.subjects) ? data.subjects : [];
                const classComp = data.class_comparatives || {};
                const historical = Array.isArray(data.historical) ? data.historical : [];
                const pctNum = Number(summary.percentage) || 0;
                const logoSrc = (window.HCC_LOGO_DATA_URL && window.HCC_LOGO_DATA_URL.startsWith('data:image')) 
                    ? window.HCC_LOGO_DATA_URL 
                    : '../assets/images/hcclogo.jpg';

                // Helper to render high-resolution Chart.js onto an offscreen canvas
                function renderOffscreenChart(type, cfgData, cfgOpts, w, h) {
                    const cvs = document.createElement('canvas');
                    cvs.width = w || 600;
                    cvs.height = h || 200;
                    const ctx = cvs.getContext('2d');
                    const ch = new Chart(ctx, {
                        type: type,
                        data: cfgData,
                        options: Object.assign({
                            animation: false,
                            responsive: false,
                            maintainAspectRatio: false
                        }, cfgOpts || {})
                    });
                    const png = cvs.toDataURL('image/png', 1.0);
                    ch.destroy();
                    return png;
                }

                // Chart 1: Grade Gauge
                const gaugeColor = pctNum >= 75 ? '#16a34a' : (pctNum >= 50 ? '#0284c7' : (pctNum >= 35 ? '#d97706' : '#dc2626'));
                const gaugePng = renderOffscreenChart('doughnut', {
                    labels: ['Score', 'Remaining'],
                    datasets: [{
                        data: [pctNum, Math.max(0, 100 - pctNum)],
                        backgroundColor: [gaugeColor, '#e2e8f0'],
                        borderWidth: 0
                    }]
                }, {
                    circumference: 180,
                    rotation: -90,
                    plugins: { legend: { display: false } }
                }, 340, 170);

                // Chart 2: FA vs SA Split
                const faTotal = subjects.reduce((a, b) => a + (Number(b.fa_obtained) || 0), 0);
                const saTotal = subjects.reduce((a, b) => a + (Number(b.sa_obtained) || 0), 0);
                const splitPng = renderOffscreenChart('doughnut', {
                    labels: ['FA: ' + faTotal.toFixed(1), 'SA: ' + saTotal.toFixed(1)],
                    datasets: [{
                        data: [faTotal, saTotal],
                        backgroundColor: ['#1e40af', '#c9a227'],
                        borderWidth: 2,
                        borderColor: '#ffffff'
                    }]
                }, {
                    plugins: { legend: { position: 'bottom', labels: { boxWidth: 12, font: { size: 10 } } } }
                }, 340, 170);

                // Chart 3: Subject Performance Bar Chart
                const subBarPng = renderOffscreenChart('bar', {
                    labels: subjects.map(s => s.subject),
                    datasets: [{
                        label: 'Subject %',
                        data: subjects.map(s => Number(s.total_pct) || 0),
                        backgroundColor: '#7a2238',
                        borderRadius: 4
                    }]
                }, {
                    scales: {
                        y: { min: 0, max: 100, ticks: { font: { size: 9 } } },
                        x: { ticks: { font: { size: 9 } } }
                    },
                    plugins: { legend: { display: false } }
                }, 736, 170);

                // Chart 4: Academic Trends Line Chart
                let trendPng = null;
                if (historical.length > 0) {
                    trendPng = renderOffscreenChart('line', {
                        labels: historical.map(h => (h.semester || 'Sem') + ' (' + (h.academic_year || '') + ')'),
                        datasets: [{
                            label: 'Overall %',
                            data: historical.map(h => Number(h.percentage) || 0),
                            borderColor: '#0284c7',
                            backgroundColor: 'rgba(2, 132, 199, 0.12)',
                            fill: true,
                            tension: 0.3,
                            pointRadius: 4,
                            pointBackgroundColor: '#0284c7'
                        }]
                    }, {
                        scales: {
                            y: { min: 0, max: 100, ticks: { font: { size: 9 } } },
                            x: { ticks: { font: { size: 8 } } }
                        },
                        plugins: { legend: { display: false } }
                    }, 736, 170);
                }

                // Chart 5: Class Comparative Bar Chart
                let compPng = null;
                if (classComp.subject_avgs && subjects.length > 0) {
                    compPng = renderOffscreenChart('bar', {
                        labels: subjects.map(s => s.subject),
                        datasets: [
                            {
                                label: 'Student %',
                                data: subjects.map(s => Number(s.total_pct) || 0),
                                backgroundColor: '#7a2238',
                                borderRadius: 3
                            },
                            {
                                label: 'Class Avg %',
                                data: subjects.map(s => Number(classComp.subject_avgs[s.subject]) || 0),
                                backgroundColor: '#94a3b8',
                                borderRadius: 3
                            }
                        ]
                    }, {
                        scales: {
                            y: { min: 0, max: 100, ticks: { font: { size: 9 } } },
                            x: { ticks: { font: { size: 9 } } }
                        },
                        plugins: { legend: { position: 'top', labels: { boxWidth: 12, font: { size: 10 } } } }
                    }, 736, 170);
                }

                if (exportBtn) exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating A4 Pages...';

                // Create off-screen container for rendering
                container = document.createElement('div');
                container.style.position = 'fixed';
                container.style.left = '0';
                container.style.top = '0';
                container.style.width = '800px';
                container.style.zIndex = '-99999';
                container.style.opacity = '0';
                container.style.pointerEvents = 'none';

                // Page 1 HTML: Overview & Subjects
                const page1Div = document.createElement('div');
                page1Div.style.cssText = 'width:800px;min-height:1130px;box-sizing:border-box;padding:26px 32px;background:#ffffff;color:#0f172a;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;display:flex;flex-direction:column;justify-content:space-between;';
                page1Div.innerHTML = `
                    <div>
                        <!-- Header -->
                        <div style="display:flex;align-items:center;gap:14px;border-bottom:2.5px solid #7a2238;padding-bottom:12px;margin-bottom:14px;">
                            <img src="${logoSrc}" style="width:58px;height:58px;object-fit:contain;flex-shrink:0;" alt="School Crest">
                            <div style="flex:1;">
                                <div style="font-size:1.15rem;font-weight:900;color:#7a2238;text-transform:uppercase;line-height:1.2;letter-spacing:0.02em;">
                                    Hindustani Covenant Church English Medium School
                                </div>
                                <div style="font-size:0.75rem;color:#64748b;font-weight:600;margin-top:2px;">
                                    Solapur &bull; Comprehensive Student Performance &amp; Academic Analytics Report
                                </div>
                            </div>
                            <div style="text-align:right;">
                                <span style="display:inline-block;padding:3px 10px;background:#fdf2f4;color:#7a2238;border:1px solid #fecdd3;border-radius:999px;font-size:0.75rem;font-weight:800;">
                                    ${escapeHtml(student.semester || 'Semester')} (${escapeHtml(student.academic_year || '')})
                                </span>
                                <div style="font-size:0.7rem;color:#94a3b8;margin-top:3px;font-weight:600;">Page 1 of 2</div>
                            </div>
                        </div>

                        <!-- Student Profile Strip -->
                        <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 14px;margin-bottom:14px;display:grid;grid-template-columns:2.2fr 1fr 1fr 1fr;gap:10px;font-size:0.8rem;">
                            <div><span style="color:#64748b;font-size:0.68rem;text-transform:uppercase;font-weight:700;display:block;">Student Name</span><strong style="font-size:0.92rem;color:#0f172a;">${escapeHtml(student.name || '—')}</strong></div>
                            <div><span style="color:#64748b;font-size:0.68rem;text-transform:uppercase;font-weight:700;display:block;">Roll No</span><strong style="font-size:0.92rem;color:#7a2238;">${escapeHtml(student.roll || '—')}</strong></div>
                            <div><span style="color:#64748b;font-size:0.68rem;text-transform:uppercase;font-weight:700;display:block;">Class &amp; Div</span><strong style="font-size:0.92rem;color:#0f172a;">STD ${escapeHtml(student.std || '—')} - ${escapeHtml(student.division || '—')}</strong></div>
                            <div><span style="color:#64748b;font-size:0.68rem;text-transform:uppercase;font-weight:700;display:block;">Academic Term</span><strong style="font-size:0.92rem;color:#0f172a;">${escapeHtml(student.academic_year || '—')}</strong></div>
                        </div>

                        <!-- 1. Executive Overview -->
                        <div style="margin-bottom:14px;">
                            <div style="font-size:0.82rem;font-weight:800;color:#7a2238;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                                <span style="width:4px;height:14px;background:#7a2238;border-radius:2px;display:inline-block;"></span>
                                1. Executive Overview &amp; Key Metrics
                            </div>

                            <!-- KPI Badges -->
                            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:10px;">
                                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:9px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
                                    <div style="font-size:1.35rem;font-weight:900;color:#0f172a;line-height:1.1;">${pctNum.toFixed(1)}%</div>
                                    <div style="font-size:0.68rem;font-weight:700;color:#64748b;text-transform:uppercase;margin-top:2px;">Percentage</div>
                                </div>
                                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:9px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
                                    <div style="font-size:1.35rem;font-weight:900;color:#7a2238;line-height:1.1;">${escapeHtml(summary.grade || '—')}</div>
                                    <div style="font-size:0.68rem;font-weight:700;color:#64748b;text-transform:uppercase;margin-top:2px;">Grade</div>
                                </div>
                                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:9px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
                                    <div style="font-size:1.35rem;font-weight:900;color:#0f172a;line-height:1.1;">${summary.grand_total_obtained || 0}/${summary.grand_total_max || 0}</div>
                                    <div style="font-size:0.68rem;font-weight:700;color:#64748b;text-transform:uppercase;margin-top:2px;">Total Marks</div>
                                </div>
                                <div style="background:#ffffff;border:1px solid #e2e8f0;border-radius:8px;padding:9px;text-align:center;box-shadow:0 1px 3px rgba(0,0,0,0.03);">
                                    <div style="font-size:1.35rem;font-weight:900;color:${summary.passed ? '#16a34a' : '#dc2626'};line-height:1.1;">${summary.passed ? 'PASS' : 'FAIL'}</div>
                                    <div style="font-size:0.68rem;font-weight:700;color:#64748b;text-transform:uppercase;margin-top:2px;">Result Status</div>
                                </div>
                            </div>

                            <!-- Meta Strip -->
                            <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:12px;font-size:0.75rem;">
                                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:6px 10px;">
                                    <span style="color:#64748b;display:block;font-size:0.66rem;font-weight:700;text-transform:uppercase;">Attendance</span>
                                    <strong style="color:#0f172a;">${escapeHtml(summary.attendance || '—')}</strong>
                                </div>
                                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:6px 10px;">
                                    <span style="color:#64748b;display:block;font-size:0.66rem;font-weight:700;text-transform:uppercase;">Class Standing</span>
                                    <strong style="color:#0284c7;">${classComp.percentile !== undefined ? classComp.percentile + 'th Percentile' : '—'}</strong>
                                </div>
                                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:6px 10px;">
                                    <span style="color:#64748b;display:block;font-size:0.66rem;font-weight:700;text-transform:uppercase;">Teacher Remark</span>
                                    <strong style="color:#0f172a;">${escapeHtml(summary.remark || '—')}</strong>
                                </div>
                                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:6px;padding:6px 10px;">
                                    <span style="color:#64748b;display:block;font-size:0.66rem;font-weight:700;text-transform:uppercase;">Parent Sign</span>
                                    <strong style="color:${summary.parents_sign ? '#16a34a' : '#dc2626'};">${summary.parents_sign ? '✓ Verified' : 'Pending'}</strong>
                                </div>
                            </div>

                            <!-- Overview Charts: Gauge & Split -->
                            <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:6px;">
                                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;text-align:center;">
                                    <div style="font-size:0.75rem;font-weight:700;color:#334155;margin-bottom:4px;text-align:left;">Grade Gauge (${pctNum.toFixed(1)}%)</div>
                                    <img src="${gaugePng}" style="width:100%;height:120px;object-fit:contain;" alt="Grade Gauge">
                                </div>
                                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;text-align:center;">
                                    <div style="font-size:0.75rem;font-weight:700;color:#334155;margin-bottom:4px;text-align:left;">FA vs SA Mark Distribution</div>
                                    <img src="${splitPng}" style="width:100%;height:120px;object-fit:contain;" alt="FA vs SA Split">
                                </div>
                            </div>
                        </div>

                        <!-- 2. Subject Performance Breakdown -->
                        <div>
                            <div style="font-size:0.82rem;font-weight:800;color:#7a2238;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                                <span style="width:4px;height:14px;background:#7a2238;border-radius:2px;display:inline-block;"></span>
                                2. Subject-wise Marks &amp; Performance Breakdown
                            </div>

                            <!-- Subject Bar Chart -->
                            <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:6px 10px;margin-bottom:10px;">
                                <img src="${subBarPng}" style="width:100%;height:150px;object-fit:contain;" alt="Subject Bar Chart">
                            </div>

                            <!-- Subject Marks Table -->
                            <table style="width:100%;border-collapse:collapse;font-size:0.74rem;border:1px solid #cbd5e1;">
                                <thead>
                                    <tr style="background:#7a2238;color:#fff;">
                                        <th style="padding:5px 8px;text-align:left;border:1px solid #cbd5e1;">Subject</th>
                                        <th style="padding:5px 6px;text-align:center;border:1px solid #cbd5e1;">FA Marks</th>
                                        <th style="padding:5px 6px;text-align:center;border:1px solid #cbd5e1;">SA Marks</th>
                                        <th style="padding:5px 6px;text-align:center;border:1px solid #cbd5e1;">Total Obt / Max</th>
                                        <th style="padding:5px 6px;text-align:center;border:1px solid #cbd5e1;">% Score</th>
                                        <th style="padding:5px 6px;text-align:center;border:1px solid #cbd5e1;">Grade</th>
                                        <th style="padding:5px 6px;text-align:center;border:1px solid #cbd5e1;">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    ${subjects.map((s, idx) => `
                                        <tr style="background:${idx % 2 === 0 ? '#fff' : '#f8fafc'};">
                                            <td style="padding:4px 8px;font-weight:700;border:1px solid #cbd5e1;">${escapeHtml(s.subject)}</td>
                                            <td style="padding:4px 6px;text-align:center;border:1px solid #cbd5e1;">${s.fa_obtained} / ${s.fa_max}</td>
                                            <td style="padding:4px 6px;text-align:center;border:1px solid #cbd5e1;">${s.sa_max > 0 ? (s.sa_obtained + ' / ' + s.sa_max) : '—'}</td>
                                            <td style="padding:4px 6px;text-align:center;font-weight:700;border:1px solid #cbd5e1;">${s.total_obtained} / ${s.total_max}</td>
                                            <td style="padding:4px 6px;text-align:center;font-weight:800;color:#7a2238;border:1px solid #cbd5e1;">${Number(s.total_pct).toFixed(1)}%</td>
                                            <td style="padding:4px 6px;text-align:center;font-weight:800;border:1px solid #cbd5e1;">${escapeHtml(s.grade)}</td>
                                            <td style="padding:4px 6px;text-align:center;font-weight:700;color:${Number(s.total_pct) >= (Number(student.std) >= 9 ? 35 : 33) ? '#16a34a' : '#dc2626'};border:1px solid #cbd5e1;">
                                                ${Number(s.total_pct) >= (Number(student.std) >= 9 ? 35 : 33) ? 'PASS' : 'FAIL'}
                                            </td>
                                        </tr>
                                    `).join('')}
                                </tbody>
                                <tfoot>
                                    <tr style="background:#f1f5f9;font-weight:800;">
                                        <td style="padding:5px 8px;border:1px solid #cbd5e1;">Grand Total</td>
                                        <td colspan="2" style="padding:5px 6px;text-align:center;border:1px solid #cbd5e1;color:#64748b;">${subjects.length} Subjects Evaluated</td>
                                        <td style="padding:5px 6px;text-align:center;border:1px solid #cbd5e1;color:#7a2238;">${summary.grand_total_obtained} / ${summary.grand_total_max}</td>
                                        <td style="padding:5px 6px;text-align:center;border:1px solid #cbd5e1;color:#7a2238;">${pctNum.toFixed(1)}%</td>
                                        <td style="padding:5px 6px;text-align:center;border:1px solid #cbd5e1;">${escapeHtml(summary.grade)}</td>
                                        <td style="padding:5px 6px;text-align:center;color:${summary.passed ? '#16a34a' : '#dc2626'};border:1px solid #cbd5e1;">${summary.passed ? 'PASS' : 'FAIL'}</td>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>

                    <!-- Page 1 Footer Note -->
                    <div style="border-top:1px solid #e2e8f0;padding-top:6px;font-size:0.68rem;color:#94a3b8;display:flex;justify-content:space-between;align-items:center;">
                        <span>HCC English Medium School Solapur &bull; Confidential Academic Record</span>
                        <span>Continued on Page 2 (Trends &amp; Comparative Analytics) &rarr;</span>
                    </div>
                `;

                // Page 2 HTML: Trends & Comparative
                const page2Div = document.createElement('div');
                page2Div.style.cssText = 'width:800px;min-height:1130px;box-sizing:border-box;padding:26px 32px;background:#ffffff;color:#0f172a;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;display:flex;flex-direction:column;justify-content:space-between;';
                page2Div.innerHTML = `
                    <div>
                        <!-- Running Header -->
                        <div style="display:flex;align-items:center;justify-content:space-between;border-bottom:2px solid #7a2238;padding-bottom:10px;margin-bottom:14px;">
                            <div style="display:flex;align-items:center;gap:10px;">
                                <img src="${logoSrc}" style="width:36px;height:36px;object-fit:contain;" alt="Logo">
                                <div>
                                    <div style="font-size:0.95rem;font-weight:900;color:#7a2238;text-transform:uppercase;">Hindustani Covenant Church English Medium School</div>
                                    <div style="font-size:0.7rem;color:#64748b;">Academic Trends &amp; Comparative Analytics &bull; ${escapeHtml(student.name)} (Roll ${escapeHtml(student.roll)})</div>
                                </div>
                            </div>
                            <div style="text-align:right;">
                                <span style="font-size:0.75rem;font-weight:800;color:#0f172a;">STD ${escapeHtml(student.std)} - ${escapeHtml(student.division)}</span>
                                <div style="font-size:0.68rem;color:#94a3b8;font-weight:600;">Page 2 of 2</div>
                            </div>
                        </div>

                        <!-- 3. Academic Trends & Historical Progress -->
                        <div style="margin-bottom:16px;">
                            <div style="font-size:0.82rem;font-weight:800;color:#7a2238;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                                <span style="width:4px;height:14px;background:#7a2238;border-radius:2px;display:inline-block;"></span>
                                3. Longitudinal Academic Growth &amp; Trend Analysis
                            </div>

                            ${trendPng ? `
                                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:6px 10px;margin-bottom:8px;">
                                    <img src="${trendPng}" style="width:100%;height:165px;object-fit:contain;" alt="Trend Chart">
                                </div>
                            ` : `
                                <div style="background:#f8fafc;border:1px dashed #cbd5e1;border-radius:8px;padding:20px;text-align:center;color:#64748b;font-size:0.78rem;margin-bottom:8px;">
                                    First academic evaluation on record for this student. Multi-term longitudinal trend analysis will accumulate as future semester examinations are recorded.
                                </div>
                            `}

                            ${historical.length > 0 ? `
                                <table style="width:100%;border-collapse:collapse;font-size:0.72rem;border:1px solid #cbd5e1;">
                                    <thead>
                                        <tr style="background:#f1f5f9;color:#334155;font-weight:700;">
                                            <th style="padding:4px 8px;text-align:left;border:1px solid #cbd5e1;">Academic Year</th>
                                            <th style="padding:4px 6px;text-align:center;border:1px solid #cbd5e1;">Standard / Div</th>
                                            <th style="padding:4px 6px;text-align:center;border:1px solid #cbd5e1;">Term / Exam</th>
                                            <th style="padding:4px 6px;text-align:center;border:1px solid #cbd5e1;">Percentage</th>
                                            <th style="padding:4px 6px;text-align:center;border:1px solid #cbd5e1;">Grade</th>
                                            <th style="padding:4px 6px;text-align:center;border:1px solid #cbd5e1;">Trend Trajectory</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        ${historical.slice(-5).map((h, i, arr) => {
                                            const prev = i > 0 ? Number(arr[i - 1].percentage) : null;
                                            const curr = Number(h.percentage);
                                            let traj = '—';
                                            if (prev !== null) {
                                                const diff = curr - prev;
                                                if (diff > 0) traj = `<span style="color:#16a34a;font-weight:700;">▲ +${diff.toFixed(1)}%</span>`;
                                                else if (diff < 0) traj = `<span style="color:#dc2626;font-weight:700;">▼ ${diff.toFixed(1)}%</span>`;
                                                else traj = '<span style="color:#64748b;">Steady</span>';
                                            }
                                            return `
                                                <tr style="background:${i % 2 === 0 ? '#fff' : '#f8fafc'};">
                                                    <td style="padding:4px 8px;font-weight:600;border:1px solid #cbd5e1;">${escapeHtml(h.academic_year)}</td>
                                                    <td style="padding:4px 6px;text-align:center;border:1px solid #cbd5e1;">STD ${escapeHtml(h.std || '—')} ${escapeHtml(h.division || '')}</td>
                                                    <td style="padding:4px 6px;text-align:center;border:1px solid #cbd5e1;">${escapeHtml(h.semester)}</td>
                                                    <td style="padding:4px 6px;text-align:center;font-weight:800;color:#0284c7;border:1px solid #cbd5e1;">${curr.toFixed(1)}%</td>
                                                    <td style="padding:4px 6px;text-align:center;font-weight:700;border:1px solid #cbd5e1;">${escapeHtml(h.grade || '—')}</td>
                                                    <td style="padding:4px 6px;text-align:center;border:1px solid #cbd5e1;">${traj}</td>
                                                </tr>
                                            `;
                                        }).join('')}
                                    </tbody>
                                </table>
                            ` : ''}
                        </div>

                        <!-- 4. Class Comparative Analytics -->
                        <div style="margin-bottom:16px;">
                            <div style="font-size:0.82rem;font-weight:800;color:#7a2238;text-transform:uppercase;letter-spacing:0.04em;margin-bottom:8px;display:flex;align-items:center;gap:6px;">
                                <span style="width:4px;height:14px;background:#7a2238;border-radius:2px;display:inline-block;"></span>
                                4. Class Benchmark &amp; Comparative Evaluation
                            </div>

                            ${compPng ? `
                                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:8px;padding:6px 10px;margin-bottom:10px;">
                                    <img src="${compPng}" style="width:100%;height:165px;object-fit:contain;" alt="Comparative Chart">
                                </div>
                            ` : ''}

                            <!-- Benchmark Metrics Cards -->
                            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:10px;">
                                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;text-align:center;">
                                    <span style="font-size:0.68rem;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Student vs Class Average</span>
                                    <div style="font-size:1.15rem;font-weight:900;color:${pctNum >= (classComp.class_avg_pct || 0) ? '#16a34a' : '#dc2626'};margin-top:2px;">
                                        ${pctNum.toFixed(1)}% vs ${(classComp.class_avg_pct || 0).toFixed(1)}%
                                    </div>
                                    <span style="font-size:0.7rem;font-weight:700;color:${pctNum >= (classComp.class_avg_pct || 0) ? '#16a34a' : '#dc2626'};">
                                        ${pctNum >= (classComp.class_avg_pct || 0) ? '+' : ''}${(pctNum - (classComp.class_avg_pct || 0)).toFixed(1)}% Variance
                                    </span>
                                </div>
                                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;text-align:center;">
                                    <span style="font-size:0.68rem;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Cohort Percentile Rank</span>
                                    <div style="font-size:1.15rem;font-weight:900;color:#0284c7;margin-top:2px;">
                                        ${classComp.percentile || 0}th Percentile
                                    </div>
                                    <span style="font-size:0.7rem;color:#64748b;">Out of ${classComp.class_size || 0} students enrolled</span>
                                </div>
                                <div style="background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:8px 12px;text-align:center;">
                                    <span style="font-size:0.68rem;font-weight:700;color:#64748b;text-transform:uppercase;display:block;">Class Performance Tier</span>
                                    <div style="font-size:1.15rem;font-weight:900;color:#7a2238;margin-top:2px;">
                                        ${pctNum >= 75 ? 'Top Quartile (Q1)' : (pctNum >= 60 ? 'Above Average (Q2)' : (pctNum >= 45 ? 'Average Tier (Q3)' : 'Focus Tier (Q4)'))}
                                    </div>
                                    <span style="font-size:0.7rem;color:#64748b;">Academic Standing Category</span>
                                </div>
                            </div>
                        </div>

                        <!-- Institutional Authentication & Signatures -->
                        <div style="border:1px solid #cbd5e1;border-radius:8px;padding:12px 16px;background:#fafaf9;">
                            <div style="font-size:0.72rem;font-weight:800;color:#475569;text-transform:uppercase;margin-bottom:28px;text-align:center;letter-spacing:0.04em;">
                                Institutional Authentication &amp; Signatures
                            </div>
                            <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:20px;text-align:center;">
                                <div>
                                    <div style="border-top:1.5px solid #64748b;margin:0 10px 4px 10px;"></div>
                                    <strong style="font-size:0.75rem;color:#0f172a;display:block;">Class Teacher</strong>
                                    <span style="font-size:0.68rem;color:#64748b;">Signature &amp; Date</span>
                                </div>
                                <div>
                                    <div style="border-top:1.5px solid #64748b;margin:0 10px 4px 10px;"></div>
                                    <strong style="font-size:0.75rem;color:#0f172a;display:block;">Exam In-charge</strong>
                                    <span style="font-size:0.68rem;color:#64748b;">Verification Seal</span>
                                </div>
                                <div>
                                    <div style="border-top:1.5px solid #64748b;margin:0 10px 4px 10px;"></div>
                                    <strong style="font-size:0.75rem;color:#0f172a;display:block;">Principal</strong>
                                    <span style="font-size:0.68rem;color:#64748b;">H.C.C. High School, Solapur</span>
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Page 2 Footer -->
                    <div style="border-top:1px solid #e2e8f0;padding-top:6px;font-size:0.68rem;color:#94a3b8;display:flex;justify-content:space-between;align-items:center;">
                        <span>Generated by HCC Academic Management System &bull; Official Analytics Document</span>
                        <span>${new Date().toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })}</span>
                    </div>
                `;

                container.appendChild(page1Div);
                container.appendChild(page2Div);
                document.body.appendChild(container);

                const jsPDF = window.jspdf.jsPDF;
                const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });

                // Render Page 1
                if (exportBtn) exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Converting Page 1...';
                const canvas1 = await window.html2canvas(page1Div, {
                    scale: 2,
                    useCORS: true,
                    backgroundColor: '#ffffff',
                    logging: false,
                    width: 800,
                    windowWidth: 800
                });
                const imgData1 = canvas1.toDataURL('image/jpeg', 0.95);
                pdf.addImage(imgData1, 'JPEG', 0, 0, 210, 297, undefined, 'FAST');

                // Render Page 2
                if (exportBtn) exportBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Converting Page 2...';
                pdf.addPage();
                const canvas2 = await window.html2canvas(page2Div, {
                    scale: 2,
                    useCORS: true,
                    backgroundColor: '#ffffff',
                    logging: false,
                    width: 800,
                    windowWidth: 800
                });
                const imgData2 = canvas2.toDataURL('image/jpeg', 0.95);
                pdf.addImage(imgData2, 'JPEG', 0, 0, 210, 297, undefined, 'FAST');

                const rollStr = String(student.roll || '0').padStart(2, '0');
                const safeName = (student.name || 'Student').replace(/[^a-zA-Z0-9_\-]/g, '_');
                const fileName = `Analytics_STD_${student.std || ''}${student.division || ''}_Roll_${rollStr}_${safeName}.pdf`;

                pdf.save(fileName);

                // Auto-file to Google Drive Workspace if connected
                try {
                    const pdfBase64 = pdf.output('datauristring').split(',')[1];
                    if (pdfBase64) {
                        const csrfToken = document.querySelector('input[name="csrf_token"]')?.value || '';
                        const driveForm = new FormData();
                        driveForm.append('csrf_token', csrfToken);
                        driveForm.append('filename', fileName);
                        driveForm.append('category', 'analytics');
                        driveForm.append('mime_type', 'application/pdf');
                        driveForm.append('content_base64', pdfBase64);

                        fetch('api_drive_upload.php', { method: 'POST', body: driveForm })
                            .then(r => r.json())
                            .then(res => {
                                if (res && res.success && res.drive_url) {
                                    showDriveToast(fileName, res.drive_url);
                                }
                            })
                            .catch(() => {});
                    }
                } catch (e) {
                    // Non-blocking background sync
                }
                console.error('Analytics PDF Error:', err);
                alert('Could not generate Analytics PDF: ' + err.message);
            } finally {
                if (container && container.parentNode) {
                    document.body.removeChild(container);
                }
                if (exportBtn) {
                    exportBtn.disabled = false;
                    exportBtn.innerHTML = origHtml;
                }
            }
        }

        // PDF export button in header
        document.getElementById('exportPdfBtn')?.addEventListener('click', async () => {
            if (!activeStudent.ck || !activeStudent.roll) {
                alert('No active student selected.');
                return;
            }
            await generateAnalyticsPdf(activeDashboardData, activeStudent);
        });

        tabs.forEach(tab => {
            tab.addEventListener('click', () => {
                const targetKey = tab.dataset.tab;
                tabs.forEach(t => {
                    const isTarget = t.dataset.tab === targetKey;
                    t.classList.toggle('active', isTarget);
                    t.setAttribute('aria-selected', isTarget ? 'true' : 'false');
                });
                panels.forEach(p => {
                    const isTarget = p.id === 'tab-' + targetKey;
                    p.classList.toggle('active', isTarget);
                    p.hidden = !isTarget;
                });
                // Allow Chart.js to recalculate canvas dimensions after unhiding
                setTimeout(() => {
                    Object.values(currentCharts).forEach(c => { if (c && c.resize) c.resize(); });
                }, 50);
            });
        });

        document.addEventListener('click', (e) => {
            const btn = e.target.closest('.dashboard-btn');
            if (!btn) return;
            const ck = btn.dataset.class;
            const roll = btn.dataset.roll;
            const name = btn.dataset.name;
            if (!ck || !roll) return;

            activeStudent = { ck, roll };
            modal.style.display = 'flex';
            document.body.style.overflow = 'hidden';
            modal.querySelector('.student-name').textContent = name || ('Roll ' + roll);

            // Reset to Overview tab
            tabs.forEach(t => {
                const isOverview = t.dataset.tab === 'overview';
                t.classList.toggle('active', isOverview);
                t.setAttribute('aria-selected', isOverview ? 'true' : 'false');
            });
            panels.forEach(p => {
                const isOverview = p.id === 'tab-overview';
                p.classList.toggle('active', isOverview);
                p.hidden = !isOverview;
                p.style.display = 'none'; // hide until loaded
            });

            document.getElementById('dashboardLoading').style.display = 'flex';
            document.getElementById('dashboardError').style.display = 'none';

            // Clean up previous charts
            Object.values(currentCharts).forEach(c => { if (c && c.destroy) c.destroy(); });
            currentCharts = {};

            fetch('api_student_dashboard.php?class=' + encodeURIComponent(ck) + '&roll=' + encodeURIComponent(roll))
                .then(r => {
                    if (!r.ok) throw new Error('Server returned HTTP ' + r.status);
                    return r.json();
                })
                .then(d => {
                    document.getElementById('dashboardLoading').style.display = 'none';
                    if (!d || d.error) {
                        document.getElementById('dashboardError').textContent = (d && d.error) || 'Failed to load student analytics.';
                        document.getElementById('dashboardError').style.display = 'block';
                        return;
                    }

                    // Cache active dashboard data for instant analytics PDF generation
                    activeDashboardData = d;

                    // Restore tab-panel display
                    panels.forEach(p => { p.style.display = ''; });

                    const student = d.student || {};
                    const summary = d.summary || {};
                    const subjects = Array.isArray(d.subjects) ? d.subjects : [];
                    const classComp = d.class_comparatives || {};
                    const historical = Array.isArray(d.historical) ? d.historical : [];

                    modal.querySelector('.student-name').textContent = student.name || ('Roll ' + roll);

                    // 1. KPI Cards in Overview Tab
                    const pctNum = Number(summary.percentage) || 0;
                    document.getElementById('kpiGrid').innerHTML = `
                        <div class="kpi-card"><div class="kpi-value">${pctNum.toFixed(1)}%</div><div class="kpi-label">Percentage</div></div>
                        <div class="kpi-card"><div class="kpi-value" style="color:var(--brand);">${summary.grade || '—'}</div><div class="kpi-label">Grade</div></div>
                        <div class="kpi-card"><div class="kpi-value">${summary.grand_total_obtained || 0}/${summary.grand_total_max || 0}</div><div class="kpi-label">Total Marks</div></div>
                        <div class="kpi-card"><div class="kpi-value" style="color:${summary.passed ? '#16a34a' : '#dc2626'};">${summary.passed ? 'PASS' : 'FAIL'}</div><div class="kpi-label">Status</div></div>
                    `;

                    // 2. Meta Cards in Overview Tab
                    document.getElementById('metaCards').innerHTML = `
                        <div class="meta-card"><strong>Attendance</strong><span>${summary.attendance || '—'}</span></div>
                        <div class="meta-card"><strong>Remark</strong><span>${summary.remark || '—'}</span></div>
                        <div class="meta-card"><strong>Parents Signature</strong><span>${summary.parents_sign ? '<span style="color:#16a34a;font-weight:700;">✓ Confirmed</span>' : '<span style="color:#dc2626;">Not Signed</span>'}</span></div>
                        <div class="meta-card"><strong>Class Rank / Percentile</strong><span>${classComp.percentile !== undefined ? classComp.percentile + 'th Percentile' : '—'}</span></div>
                    `;

                    if (typeof Chart === 'undefined') {
                        return;
                    }

                    // 3. Render Gauge Chart (Grade Gauge)
                    const ctxGauge = document.getElementById('gaugeChart');
                    if (ctxGauge) {
                        const gaugeColor = pctNum >= 75 ? '#16a34a' : (pctNum >= 50 ? '#0284c7' : (pctNum >= 35 ? '#eab308' : '#dc2626'));
                        currentCharts.gauge = new Chart(ctxGauge.getContext('2d'), {
                            type: 'doughnut',
                            data: {
                                labels: ['Score', 'Remainder'],
                                datasets: [{
                                    data: [pctNum, Math.max(0, 100 - pctNum)],
                                    backgroundColor: [gaugeColor, '#e2e8f0'],
                                    borderWidth: 0
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                circumference: 180,
                                rotation: -90,
                                plugins: {
                                    legend: { display: false },
                                    tooltip: {
                                        callbacks: {
                                            label: function (ctx) {
                                                return ctx.dataIndex === 0 ? pctNum.toFixed(1) + '% (' + (summary.grade || '') + ')' : '';
                                            }
                                        }
                                    }
                                }
                            }
                        });
                    }

                    // 4. Render FA vs SA Split Chart
                    const ctxSplit = document.getElementById('faSaSplitChart');
                    if (ctxSplit) {
                        const faTotal = subjects.reduce((a, b) => a + (Number(b.fa_obtained) || 0), 0);
                        const saTotal = subjects.reduce((a, b) => a + (Number(b.sa_obtained) || 0), 0);
                        currentCharts.split = new Chart(ctxSplit.getContext('2d'), {
                            type: 'doughnut',
                            data: {
                                labels: ['Formative (FA)', 'Summative (SA)'],
                                datasets: [{
                                    data: [faTotal, saTotal],
                                    backgroundColor: ['#1e40af', '#c9a227'],
                                    borderWidth: 2,
                                    borderColor: '#ffffff'
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                plugins: {
                                    legend: { position: 'bottom' }
                                }
                            }
                        });
                    }

                    // 5. Render Subjects Bar Chart (Subjects Tab)
                    const ctxSub = document.getElementById('subjectChart');
                    if (ctxSub && subjects.length > 0) {
                        currentCharts.sub = new Chart(ctxSub.getContext('2d'), {
                            type: 'bar',
                            data: {
                                labels: subjects.map(x => x.subject),
                                datasets: [{
                                    label: 'Subject %',
                                    data: subjects.map(x => Number(x.total_pct) || 0),
                                    backgroundColor: '#7a2238',
                                    borderRadius: 6
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: {
                                    y: { min: 0, max: 100, title: { display: true, text: 'Percentage (%)' } }
                                }
                            }
                        });
                    }

                    // 6. Render Trends Chart (Trends Tab)
                    const ctxTrend = document.getElementById('trendChart');
                    if (ctxTrend && historical.length > 0) {
                        currentCharts.trend = new Chart(ctxTrend.getContext('2d'), {
                            type: 'line',
                            data: {
                                labels: historical.map(h => (h.semester || 'Sem') + ' (' + (h.academic_year || '') + ')'),
                                datasets: [{
                                    label: 'Performance Trend %',
                                    data: historical.map(h => Number(h.percentage) || 0),
                                    borderColor: '#0284c7',
                                    backgroundColor: 'rgba(2, 132, 199, 0.12)',
                                    fill: true,
                                    tension: 0.3,
                                    pointRadius: 5,
                                    pointHoverRadius: 7
                                }]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: {
                                    y: { min: 0, max: 100, title: { display: true, text: 'Overall %' } }
                                }
                            }
                        });
                    }

                    // 7. Render Comparative Chart (Comparative Tab)
                    const ctxDev = document.getElementById('deviationChart');
                    if (ctxDev && classComp.subject_avgs && subjects.length > 0) {
                        currentCharts.deviation = new Chart(ctxDev.getContext('2d'), {
                            type: 'bar',
                            data: {
                                labels: subjects.map(x => x.subject),
                                datasets: [
                                    {
                                        label: 'Student %',
                                        data: subjects.map(x => Number(x.total_pct) || 0),
                                        backgroundColor: '#7a2238',
                                        borderRadius: 4
                                    },
                                    {
                                        label: 'Class Average %',
                                        data: subjects.map(x => Number(classComp.subject_avgs[x.subject]) || 0),
                                        backgroundColor: '#94a3b8',
                                        borderRadius: 4
                                    }
                                ]
                            },
                            options: {
                                responsive: true,
                                maintainAspectRatio: false,
                                scales: {
                                    y: { min: 0, max: 100, title: { display: true, text: 'Percentage (%)' } }
                                }
                            }
                        });
                    }
                })
                .catch(err => {
                    document.getElementById('dashboardLoading').style.display = 'none';
                    document.getElementById('dashboardError').textContent = 'Network error: ' + err.message;
                    document.getElementById('dashboardError').style.display = 'block';
                });
        });
    })();

    // Notification dropdown toggle
    (function () {
        const trig = document.getElementById('notifTrigger');
        const drop = document.getElementById('notifDropdown');
        if (trig && drop) {
            trig.addEventListener('click', (e) => {
                e.stopPropagation();
                drop.classList.toggle('show');
            });
            document.addEventListener('click', (e) => {
                if (!drop.contains(e.target) && !trig.contains(e.target)) {
                    drop.classList.remove('show');
                }
            });
        }
    })();

    function showDriveToast(filename, driveUrl) {
        let toast = document.getElementById('driveAutoFileToast');
        if (!toast) {
            toast = document.createElement('div');
            toast.id = 'driveAutoFileToast';
            toast.style.cssText = 'position:fixed;bottom:24px;right:24px;background:#0f172a;color:#ffffff;padding:12px 18px;border-radius:10px;box-shadow:0 10px 25px rgba(0,0,0,0.3);display:flex;align-items:center;gap:12px;z-index:99999;font-size:0.84rem;font-weight:600;border:1px solid rgba(255,255,255,0.15);';
            document.body.appendChild(toast);
        }
        toast.innerHTML = `<i class="fa-brands fa-google-drive" style="color:#4285F4;font-size:1.25rem;"></i> <span>Auto-filed to your Google Drive!</span> <a href="${driveUrl}" target="_blank" rel="noopener" style="color:#60a5fa;text-decoration:underline;font-weight:700;">Open in Drive &rarr;</a>`;
        toast.style.display = 'flex';
        setTimeout(() => { if (toast) toast.style.display = 'none'; }, 6000);
    }
    </script>
    <script src="<?= BASE_URL ?>assets/js/results-matrix.js?v=<?= (is_file(__DIR__ . '/../assets/js/results-matrix.js') ? filemtime(__DIR__ . '/../assets/js/results-matrix.js') : 0) ?>"></script>
    <script src="<?= BASE_URL ?>assets/js/parent-sign.js?v=<?= (is_file(__DIR__ . '/../assets/js/parent-sign.js') ? filemtime(__DIR__ . '/../assets/js/parent-sign.js') : 0) ?>"></script>
    <script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= (is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 0) ?>"></script>
    <script src="<?= BASE_URL ?>assets/vendor/html2canvas@1.4.1/html2canvas.min.js"></script>
    <script src="<?= BASE_URL ?>assets/vendor/jspdf@2.5.1/jspdf.umd.min.js"></script>
    <script src="<?= BASE_URL ?>assets/js/hcc-logo-base64.js?v=<?= filemtime(__DIR__ . '/../assets/js/hcc-logo-base64.js') ?>"></script>
    <script src="<?= BASE_URL ?>assets/js/whatsapp-share.js?v=<?= filemtime(__DIR__ . '/../assets/js/whatsapp-share.js') ?>"></script>
    <script src="<?= BASE_URL ?>assets/js/hcc-pdf-engine.js?v=<?= filemtime(__DIR__ . '/../assets/js/hcc-pdf-engine.js') ?>"></script>
    <script type="application/json" id="resultsBridgeData">
        <?= json_encode([
            'marksMap'      => $matrixMarks ?? [],
            'schoolName'    => $schoolName,
            'hdrStd'        => $std,
            'hdrDiv'        => $division,
            'hdrSem'        => $romanSem,
            'hdrYear'       => $year,
            'schoolTagline' => 'Excellence in Academic & Moral Education'
        ], JSON_UNESCAPED_UNICODE) ?>
    </script>
    <script src="<?= BASE_URL ?>assets/js/hcc-bridge.js?v=<?= (is_file(__DIR__ . '/../assets/js/hcc-bridge.js') ? filemtime(__DIR__ . '/../assets/js/hcc-bridge.js') : 0) ?>"></script>
</body>
</html>
