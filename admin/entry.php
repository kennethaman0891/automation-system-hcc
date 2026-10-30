<?php
/**
 * HCC Automation System — Manual Marks Entry & Student Profile Management
 * Comprehensive per-student entry and editor aligned with results.php reference standard.
 * Features:
 *   - Class selection & auto-defaults (never a blank state)
 *   - Sequential student roster navigation (< Prev Student, Next Student >, Student Switcher)
 *   - Auto-suggest next available roll number for new students
 *   - Full demographics including Date of Birth (with live words translation)
 *   - CBSE (STD 1-8) & Maharashtra State Board (STD 9-10) live grading
 *   - Two-tier (FA+SA) and single-mark subject support with over-max validation
 *   - Direct student actions: View Report Card, WhatsApp Share, Performance Analytics Dashboard
 *   - Notification bell and modern admin header
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

$adminPage  = 'entry';
$db         = getDB();
$settings   = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church High School';
$adminUser  = sanitize($_SESSION['admin_name'] ?? 'Admin');
$notifData  = rcGetRecentNotifications(8);

$classes = rcClassOptions();

// Resolve active class key
$classKey = trim($_GET['class'] ?? '');
if ($classKey === '' && !empty($classes)) {
    $first = $classes[0];
    $classKey = rcClassKey($first['academic_year'], $first['std'], $first['division'], $first['semester']);
}

$error   = '';
$success = '';

// Compact number formatter for matrix inputs (70.0 -> 70, 87.5 -> 87.5).
$fmtNum = function ($v) {
    $f = (float)$v;
    if (abs($f - round($f)) < 0.0001) return (string)(int)round($f);
    return rtrim(rtrim(number_format($f, 1, '.', ''), '0'), '.');
};

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $act = trim($_POST['action'] ?? '');

        if ($act === 'save') {
            $data = [
                'academic_year' => trim($_POST['academic_year'] ?? '2025-26'),
                'std'           => trim($_POST['std'] ?? ''),
                'division'      => trim($_POST['division'] ?? ''),
                'semester'      => trim($_POST['semester'] ?? 'Sem 1'),
                'roll_no'       => trim($_POST['roll_no'] ?? ''),
                'student_name'  => trim($_POST['student_name'] ?? ''),
                'renamed_name'  => trim($_POST['renamed_name'] ?? ''),
                'date_of_birth' => trim($_POST['date_of_birth'] ?? ''),
                'remark'        => trim($_POST['remark'] ?? ''),
                'attendance'    => trim($_POST['attendance'] ?? ''),
                'parents_sign'  => isset($_POST['parents_sign']) ? 1 : 0,
                'subjects'      => []
            ];

            // Collect subject marks
            $subjNames = $_POST['subject_name'] ?? [];
            $faMax     = $_POST['fa_max'] ?? [];
            $faObt     = $_POST['fa_obtained'] ?? [];
            $saMax     = $_POST['sa_max'] ?? [];
            $saObt     = $_POST['sa_obtained'] ?? [];

            foreach ($subjNames as $i => $name) {
                if (trim($name) === '') continue;
                $data['subjects'][] = [
                    'subject_name' => trim($name),
                    'fa_max'       => (float)($faMax[$i] ?? 70),
                    'fa_obtained'  => (float)($faObt[$i] ?? 0),
                    'sa_max'       => (float)($saMax[$i] ?? 30),
                    'sa_obtained'  => (float)($saObt[$i] ?? 0),
                ];
            }

            if ($data['std'] === '' || $data['roll_no'] === '' || $data['student_name'] === '') {
                $error = 'STD, Roll No and Student Name are required.';
            } elseif (empty($data['subjects'])) {
                $error = 'Please add at least one subject row.';
            } else {
                $res = rcSaveEntry($data);
                if ($res['ok']) {
                    $newClassKey = rcClassKey($data['academic_year'], $data['std'], $data['division'], $data['semester']);
                    $savedRoll   = $data['roll_no'];

                    if (isset($_POST['submit_action']) && $_POST['submit_action'] === 'save_and_add_next') {
                        header('Location: entry.php?class=' . urlencode($newClassKey) . '&mode=new&msg=saved');
                        exit;
                    } else {
                        header('Location: entry.php?class=' . urlencode($newClassKey) . '&roll=' . urlencode($savedRoll) . '&msg=saved');
                        exit;
                    }
                } else {
                    $error = 'Save failed: ' . $res['error'];
                }
            }
        } elseif ($act === 'save_subjects') {
            $sk = trim($_POST['class_key'] ?? '');
            if ($sk === '') {
                $sk = trim($_POST['academic_year'] ?? '') . '|' . trim($_POST['std'] ?? '') . '|' . trim($_POST['division'] ?? '') . '|' . trim($_POST['semester'] ?? '');
            }
            $sNames = $_POST['subject_name'] ?? [];
            $sFam   = $_POST['fa_max'] ?? [];
            $sSam   = $_POST['sa_max'] ?? [];
            $subs = [];
            if (is_array($sNames)) {
                foreach ($sNames as $i => $n) {
                    $n = trim((string)$n);
                    if ($n === '') continue;
                    $subs[] = [
                        'subject_name' => $n,
                        'fa_max'       => (float)($sFam[$i] ?? 70),
                        'sa_max'       => (float)($sSam[$i] ?? 30),
                    ];
                }
            }
            $skParsed = rcParseClassKey(urldecode($sk));
            if ($skParsed['std'] === '') {
                $error = 'A class must be selected before saving subjects.';
            } elseif (empty($subs)) {
                $error = 'Add at least one subject before saving.';
            } else {
                try {
                    rcSaveClassSubjects($sk, $subs);
                    $classKey = $sk;
                    $success = 'Class subjects saved successfully.';
                } catch (Throwable $e) {
                    $error = 'Could not save subjects: ' . $e->getMessage();
                }
            }
        } elseif ($act === 'delete') {
            $ck = trim($_POST['class_key'] ?? '');
            $r  = trim($_POST['roll'] ?? '');
            if ($ck !== '' && $r !== '') {
                rcDeleteStudent($ck, $r);
                header('Location: entry.php?class=' . urlencode($ck) . '&msg=deleted');
                exit;
            }
        }
    }
}

// Flash messages from redirect
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'saved')   $success = 'Student entry saved successfully.';
    if ($_GET['msg'] === 'deleted') $success = 'Student record deleted successfully.';
}

// Parse class context
$parsedKey = $classKey !== ''
    ? rcParseClassKey(urldecode($classKey))
    : ['academic_year' => '2025-26', 'std' => '1', 'division' => 'A', 'semester' => 'Sem 1'];

$ctx = [
    'academic_year' => $parsedKey['academic_year'] ?: '2025-26',
    'std'           => $parsedKey['std'] ?: '1',
    'division'      => $parsedKey['division'] ?: 'A',
    'semester'      => $parsedKey['semester'] ?: 'Sem 1',
];
$effClassKey = rcClassKey($ctx['academic_year'], $ctx['std'], $ctx['division'], $ctx['semester']);

// Fetch roster of students for this class
$roster = rcListStudents($effClassKey);
$nextAvailableRoll = rcGetNextRollNumber($effClassKey);

// Mode resolution: Add new vs Edit existing
$mode = trim($_GET['mode'] ?? '');
$roll = trim($_GET['roll'] ?? '');
$edit = false;
$report = null;

if ($mode === 'new') {
    $edit = false;
    $targetRoll = (string)$nextAvailableRoll;
} elseif ($roll !== '') {
    $report = rcStudentReport($effClassKey, $roll);
    if ($report) {
        $edit = true;
        $targetRoll = $roll;
    } else {
        $targetRoll = (string)$nextAvailableRoll;
    }
} else {
    // If students exist, default to the first student; otherwise new student mode
    if (!empty($roster)) {
        $firstStu = $roster[0];
        $targetRoll = (string)$firstStu['roll_no'];
        $report = rcStudentReport($effClassKey, $targetRoll);
        $edit = ($report !== null);
    } else {
        $edit = false;
        $targetRoll = (string)$nextAvailableRoll;
    }
}

// Calculate Prev / Next roll in roster
$currentRollIndex = -1;
foreach ($roster as $idx => $st) {
    if ((string)$st['roll_no'] === (string)$targetRoll) {
        $currentRollIndex = $idx;
        break;
    }
}
$prevStudentRoll = ($currentRollIndex > 0) ? $roster[$currentRollIndex - 1]['roll_no'] : null;
$nextStudentRoll = ($currentRollIndex >= 0 && $currentRollIndex < count($roster) - 1) ? $roster[$currentRollIndex + 1]['roll_no'] : null;

// Load subjects for this class
$classSubjects = [];
try {
    $classSubjects = rcClassSubjects($effClassKey);
} catch (Throwable $e) {
    if ($error === '') $error = 'Could not load class subjects: ' . $e->getMessage();
}

// Build matrix subjects (incorporating any student-specific rows)
$rowBySubject = [];
if ($report && !empty($report['rows'])) {
    foreach ($report['rows'] as $r) {
        $rowBySubject[mb_strtolower($r['subject_name'])] = $r;
    }
}
$matrixSubjects = $classSubjects;
$haveSubject = [];
foreach ($matrixSubjects as $s) {
    $haveSubject[mb_strtolower($s['subject_name'])] = true;
}
if ($report && !empty($report['rows'])) {
    foreach ($report['rows'] as $r) {
        $lk = mb_strtolower($r['subject_name']);
        if (!isset($haveSubject[$lk])) {
            $haveSubject[$lk] = true;
            $matrixSubjects[] = [
                'subject_name' => $r['subject_name'],
                'fa_max'       => (float)$r['fa_max'],
                'sa_max'       => (float)$r['sa_max'],
                'sort_order'   => PHP_INT_MAX,
            ];
        }
    }
}

$romanMap = ['1'=>'I','2'=>'II','3'=>'III','4'=>'IV','5'=>'V','6'=>'VI','7'=>'VII','8'=>'VIII','9'=>'IX','10'=>'X'];
$rStd = $romanMap[(string)$ctx['std']] ?? $ctx['std'];
$currentClassLabel = "STD {$rStd} {$ctx['division']} — {$ctx['semester']} ({$ctx['academic_year']})";
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $edit ? 'Edit Student: ' . sanitize($report['name']) : 'Add Student Entry' ?> | <?= sanitize($schoolName) ?></title>
    <link rel="icon" href="../assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/whatsapp-share.css?v=<?= filemtime(__DIR__ . '/../assets/css/whatsapp-share.css') ?>">
    <script src="<?= BASE_URL ?>assets/vendor/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <style>
        :root {
            --brand-main: #7a2238;
            --brand-dark: #5a1a2a;
            --brand-soft: #f9eef1;
            --gold-accent: #c9a227;
        }

        /* ── Topbar Controls ── */
        .entry-top-bar {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px 20px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            box-shadow: var(--shadow-sm);
        }
        .class-picker-wrap {
            display: flex;
            align-items: center;
            gap: 12px;
            flex-wrap: wrap;
        }
        .class-badge-pill {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 7px 16px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--brand-main);
            background: var(--brand-soft);
            border: 1.5px solid #ecc9d2;
        }

        /* ── Student Switcher Strip ── */
        .student-nav-strip {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 12px 18px;
            margin-bottom: 22px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .nav-buttons-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        /* ── Subject Marks Matrix Table (professional) ── */
        .matrix-wrap {
            overflow-x: auto;
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            background: #ffffff;
            box-shadow: inset 0 2px 8px rgba(15,23,42,.04);
        }
        table.subj-matrix {
            border-collapse: separate;
            border-spacing: 0;
            width: 100%;
            min-width: 760px;
            font-size: 13px;
        }
        table.subj-matrix th, table.subj-matrix td {
            padding: 10px;
            text-align: center;
            vertical-align: middle;
            border-bottom: 1px solid #eef2f7;
            background: #fff;
        }
        table.subj-matrix thead th {
            background: linear-gradient(180deg, #232f6b, #1a237e);
            color: #ffffff;
            font-weight: 800;
            font-size: 11.5px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
            white-space: nowrap;
            border-bottom: none;
            padding: 12px 10px;
            position: sticky;
            top: 0;
            z-index: 2;
        }
        table.subj-matrix thead th:first-child {
            border-top-left-radius: 13px;
            text-align: left;
            padding-left: 16px;
        }
        table.subj-matrix thead th:last-child { border-top-right-radius: 13px; }
        table.subj-matrix tbody tr { transition: background .12s; }
        table.subj-matrix tbody tr:hover td, table.subj-matrix tbody tr:hover th { background: #f8faff; }
        table.subj-matrix tbody tr:last-child th, table.subj-matrix tbody tr:last-child td { border-bottom: none; }
        table.subj-matrix tbody th {
            background: #f8fafc;
            text-align: left;
            font-weight: 800;
            color: #334155;
            white-space: nowrap;
            padding-left: 16px;
            font-size: 12.5px;
            position: sticky;
            left: 0;
            z-index: 1;
            border-right: 1px solid #e2e8f0;
        }
        .mtx-row-tag {
            display: inline-block;
            font-size: 10px;
            font-weight: 800;
            letter-spacing: .06em;
            padding: 3px 9px;
            border-radius: 999px;
            margin-right: 8px;
            vertical-align: 1px;
        }
        .mtx-tag-max { background: #e0e7ff; color: #3730a3; }
        .mtx-tag-obt { background: #dcfce7; color: #166534; }
        .mtx-tag-sub { background: #fef3c7; color: #92400e; }
        /* GRAND TOTAL header cell: gold band + dark text (white text would vanish on the pink .mtx-total bg) */
        table.subj-matrix thead th.mtx-total {
            background: linear-gradient(180deg, #d9b545, #b98a1c) !important;
            color: #3d2c05;
        }
        table.subj-matrix input[type=number] {
            width: 84px;
            height: 40px;
            padding: 7px 8px;
            border: 1.5px solid #dbe2ec;
            border-radius: 10px;
            font-size: 14px;
            font-weight: 800;
            text-align: center;
            background: #fbfcfe;
            color: #1e293b;
            font-variant-numeric: tabular-nums;
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        table.subj-matrix input[type=number]:hover { border-color: #b9c4d4; }
        table.subj-matrix input[type=number]:focus {
            border-color: var(--brand-main);
            outline: none;
            background: #fff;
            box-shadow: 0 0 0 3px rgba(122, 34, 56, 0.12);
        }
        table.subj-matrix input.rm-over {
            border-color: #dc2626 !important;
            background: #fef2f2 !important;
            color: #dc2626 !important;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.15) !important;
        }
        /* max-limit inputs read as configuration, obtained as entry */
        table.subj-matrix input[data-mark$="_max"] { background: #f1f5f9; color: #334155; }
        table.subj-matrix input[data-mark$="_max"]:focus { background: #fff; }
        .mtx-single {
            display: inline-flex; align-items: center; gap: 5px;
            color: #64748b; font-size: 11px; font-weight: 700;
            background: #f1f5f9; border: 1px dashed #cbd5e1;
            padding: 8px 12px; border-radius: 999px; white-space: nowrap;
        }
        .mtx-total {
            font-weight: 800;
            color: var(--brand-main);
            background: #fdf5f7 !important;
            font-variant-numeric: tabular-nums;
            font-size: 13.5px;
            border-left: 1px solid #f3dfe4 !important;
        }
        tbody tr.mtx-subtotal-row th, tbody tr.mtx-subtotal-row td { background: #fffbeb; }
        tbody tr.mtx-subtotal-row:hover th, tbody tr.mtx-subtotal-row:hover td { background: #fef6e0; }
        .subj-total-cell {
            font-size: 13px;
            color: #92400e;
            font-weight: 800;
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        /* ── Sticky save bar ── */
        .mtx-savebar {
            position: sticky;
            bottom: 0;
            z-index: 20;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
            margin: 0 -20px 36px;
            padding: 13px 20px;
            background: rgba(255,255,255,.94);
            backdrop-filter: blur(8px);
            border: 1px solid #e2e8f0;
            border-radius: 14px;
            box-shadow: 0 -8px 24px -12px rgba(15,23,42,.18);
        }
        .mtx-save-hint { font-size: .75rem; color: var(--text-3); font-weight: 600; display: flex; align-items: center; gap: 7px; }
        .mtx-save-hint i { color: #16a34a; }
        .mtx-save-group { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .mtx-savebar .btn-primary { box-shadow: 0 8px 18px -8px rgba(122,34,56,.6); }
        .mtx-savebar .btn { transition: transform .14s ease, box-shadow .14s ease; }
        .mtx-savebar .btn-primary:hover { transform: translateY(-1px); }

        /* ── Live Summary ── */
        .live-summary {
            display: grid;
            grid-template-columns: repeat(5, minmax(0, 1fr));
            gap: 12px;
            margin-top: 18px;
        }
        @media (max-width: 1100px) { .live-summary { grid-template-columns: repeat(3, minmax(0, 1fr)); } }
        @media (max-width: 640px) { .live-summary { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .summary-chip {
            position: relative;
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 13px;
            padding: 13px 12px 12px;
            text-align: center;
            overflow: hidden;
            transition: transform .15s, box-shadow .15s;
        }
        .summary-chip:hover { transform: translateY(-2px); box-shadow: 0 10px 22px -12px rgba(15,23,42,.25); }
        .summary-chip::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, var(--brand-main), #d9a441); }
        .summary-chip .sc-ico { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 9px; background: #f1f5f9; color: var(--brand-main); font-size: .78rem; margin-bottom: 7px; }
        .summary-chip .sc-label {
            display: block;
            font-size: 10px;
            text-transform: uppercase;
            letter-spacing: 0.07em;
            color: var(--text-3);
            font-weight: 800;
            margin-bottom: 5px;
        }
        .summary-chip strong {
            font-size: 19px;
            font-weight: 800;
            color: var(--ink);
            font-variant-numeric: tabular-nums;
        }
        .result-badge {
            display: inline-block;
            padding: 3px 14px;
            border-radius: 999px;
            font-size: 13px;
            font-weight: 800;
            letter-spacing: 0.5px;
        }
        .badge-pass { background: #dcfce7; color: #166534; }
        .badge-fail { background: #fee2e2; color: #991b1b; }

        /* Grade Badges */
        .grade-badge {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 32px;
            height: 28px;
            padding: 0 10px;
            border-radius: 14px;
            font-weight: 800;
            font-size: 0.82rem;
        }
        .grade-A, .grade-A1, .grade-A2, .grade-Distinction { background: #dcfce7; color: #166534; }
        .grade-B, .grade-B1, .grade-B2, .grade-Grade-I { background: #dbeafe; color: #1d4ed8; }
        .grade-C, .grade-C1, .grade-C2, .grade-Grade-II { background: #fef3c7; color: #92400e; }
        .grade-D { background: #fed7aa; color: #9a3412; }
        .grade-Pass { background: #dcfce7; color: #166534; }
        .grade-F, .grade-E1, .grade-E2, .grade-Fail { background: #fee2e2; color: #991b1b; }

        /* Presets */
        .preset-pills {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 6px;
        }
        .preset-pill {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            color: #475569;
            font-size: 11px;
            font-weight: 600;
            padding: 3px 8px;
            border-radius: 4px;
            cursor: pointer;
            transition: all 0.15s;
        }
        .preset-pill:hover {
            background: var(--brand-soft);
            color: var(--brand-main);
            border-color: #ebd2d9;
        }

        /* ── Modals ── */
        .confirm-modal[hidden], .modal[hidden] { display: none !important; }
        .confirm-modal, .modal { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .confirm-backdrop, .modal-backdrop { position: absolute; inset: 0; background: rgba(15, 23, 42, 0.55); backdrop-filter: blur(2px); }
        .confirm-card { position: relative; background: #fff; border-radius: 14px; max-width: 440px; width: 100%; padding: 28px 26px; text-align: center; box-shadow: 0 24px 60px rgba(15,23,42,0.35); }
        .modal-panel { position: relative; width: 100%; max-width: 1080px; max-height: 90vh; background: #ffffff; border-radius: var(--radius); box-shadow: var(--shadow-lg); display: flex; flex-direction: column; overflow: hidden; }
        .modal-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border); background: var(--surface); }
        .modal-body { padding: 20px; overflow-y: auto; flex: 1; }
        .modal-close { border: none; background: transparent; font-size: 18px; cursor: pointer; color: var(--text-3); }

        /* Manage Subjects Grid */
        .ms-head, .ms-row { display: grid; grid-template-columns: 2fr 1fr 1fr 40px; gap: 8px; align-items: center; margin-bottom: 8px; }
        .ms-head span { font-size: 0.7rem; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: .05em; }
        .ms-row input { width: 100%; height: 40px; padding: 8px 11px; border: 1.5px solid #e2e8f0; border-radius: 9px; font-size: 0.9rem; font-weight: 600; background: #fbfcfe; }
        .ms-row input:focus { outline: none; border-color: var(--brand-main); box-shadow: 0 0 0 3px rgba(122,34,56,.1); background: #fff; }
        .btn-rem-subj { width: 36px; height: 40px; border: none; background: #fee2e2; color: #dc2626; border-radius: 9px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all .15s; }
        .btn-rem-subj:hover { background: #dc2626; color: #fff; }
        .btn-add-subj { padding: 9px 18px; font-size: 0.85rem; background: #fff5f5; color: #991b1b; border: 1.5px dashed #d97777; border-radius: 10px; cursor: pointer; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; transition: all .15s; }
        .btn-add-subj:hover { background: #991b1b; color: #fff; border-style: solid; }

        /* Dashboard tabs */
        .dashboard-tabs { display: flex; gap: 4px; padding: 12px 20px 0; border-bottom: 1px solid var(--border); background: var(--surface); flex-shrink: 0; overflow-x: auto; }
        .dashboard-tabs button { display: inline-flex; align-items: center; gap: 6px; padding: 10px 16px; border: 1px solid transparent; background: transparent; color: var(--text-2); border-radius: 8px 8px 0 0; font-weight: 600; font-size: 0.82rem; cursor: pointer; }
        .dashboard-tabs button.active { color: var(--brand-main); background: #ffffff; border-color: var(--border); border-bottom-color: #ffffff; }
        .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; margin-bottom: 16px; }
        .kpi-card { background: #ffffff; border: 1px solid var(--border); border-radius: 8px; padding: 14px; text-align: center; }
        .kpi-value { font-size: 1.4rem; font-weight: 800; }
        .kpi-label { font-size: 0.7rem; text-transform: uppercase; color: var(--text-3); font-weight: 700; margin-top: 4px; }
    </style>
</head>
<body class="admin-page">
<div class="admin-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

    <main class="admin-main">
        <!-- Topbar Header -->
        <header class="admin-header">
            <button class="sidebar-toggle js-sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
            <div class="header-titles">
                <h1 class="page-title"><i class="fa-solid fa-pen-to-square" style="color:var(--brand-main);margin-right:8px;"></i> <?= $edit ? 'Edit Student Entry' : 'Add New Student Entry' ?></h1>
                <p class="page-sub"><?= sanitize($schoolName) ?> &bull; Manual Report Card Entry & Management</p>
            </div>
            <div style="display:flex;align-items:center;gap:12px;">
                <!-- Activity Alerts / Notification Bell -->
                <div class="notif-container" style="position:relative;">
                    <button type="button" class="btn btn-outline" style="padding:7px 12px;font-size:13px;" onclick="document.getElementById('notifMenu').classList.toggle('show')">
                        <i class="fas fa-bell"></i>
                        <?php if ($notifData['unread'] > 0): ?>
                            <span style="background:#dc2626;color:white;font-size:10px;font-weight:700;padding:1px 5px;border-radius:10px;margin-left:4px;"><?= (int)$notifData['unread'] ?></span>
                        <?php endif; ?>
                    </button>
                    <div id="notifMenu" class="notif-dropdown" style="display:none;position:absolute;right:0;top:40px;width:340px;background:white;border:1px solid var(--border);border-radius:8px;box-shadow:var(--shadow-lg);z-index:100;padding:12px;">
                        <h4 style="margin:0 0 8px 0;font-size:13px;font-weight:700;">Recent System Activity</h4>
                        <div style="max-height:260px;overflow-y:auto;font-size:12px;">
                            <?php foreach ($notifData['items'] as $nt): ?>
                            <div style="padding:6px 0;border-bottom:1px solid #f1f5f9;">
                                <strong><?= sanitize($nt['title']) ?></strong>
                                <div style="color:var(--text-2);"><?= sanitize($nt['message']) ?></div>
                            </div>
                            <?php endforeach; ?>
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

            <!-- Flash Alerts -->
            <?php if (!empty($error)): ?>
            <div class="alert alert-danger" style="margin-bottom:18px;"><i class="fas fa-exclamation-circle"></i> <?= sanitize($error) ?></div>
            <?php endif; ?>
            <?php if (!empty($success)): ?>
            <div class="alert alert-success" style="margin-bottom:18px;"><i class="fas fa-check-circle"></i> <?= sanitize($success) ?></div>
            <?php endif; ?>

            <!-- 1. TOP CLASS & SESSION BAR -->
            <div class="entry-top-bar">
                <div class="class-picker-wrap">
                    <label for="classSelector" style="font-weight:700;font-size:13px;"><i class="fa-solid fa-chalkboard"></i> Target Class:</label>
                    <select id="classSelector" class="form-control" style="font-weight:600;min-width:260px;" onchange="location.href='entry.php?class=' + encodeURIComponent(this.value)">
                        <?php foreach ($classes as $c): 
                            $ck = rcClassKey($c['academic_year'], $c['std'], $c['division'], $c['semester']);
                            $rS = $romanMap[(string)$c['std']] ?? $c['std'];
                            $sel = ($ck === $effClassKey) ? 'selected' : '';
                        ?>
                        <option value="<?= e_attr($ck) ?>" <?= $sel ?>>
                            STD <?= sanitize($rS) ?>-<?= sanitize($c['division']) ?> &bull; <?= sanitize($c['semester']) ?> (<?= sanitize($c['academic_year']) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <span class="class-badge-pill">
                        <i class="fa-solid fa-school"></i> <?= sanitize($currentClassLabel) ?>
                    </span>

                    <span style="font-size:12.5px;color:var(--text-2);font-weight:600;">
                        <strong><?= count($roster) ?></strong> students enrolled
                    </span>
                </div>

                <div style="display:flex;align-items:center;gap:10px;">
                    <a href="results.php?class=<?= e_url($effClassKey) ?>" class="btn btn-outline" style="font-size:12.5px;">
                        <i class="fa-solid fa-table"></i> Open Results Matrix
                    </a>
                    <a href="entry.php?class=<?= e_url($effClassKey) ?>&mode=new" class="btn btn-primary" style="font-size:12.5px;">
                        <i class="fa-solid fa-user-plus"></i> + Add New Student
                    </a>
                </div>
            </div>

            <!-- 2. STUDENT NAVIGATION & SWITCHER STRIP -->
            <div class="student-nav-strip">
                <div style="display:flex;align-items:center;gap:12px;flex-wrap:wrap;">
                    <label for="studentPicker" style="font-weight:700;font-size:13px;"><i class="fa-solid fa-address-book"></i> Select Student:</label>
                    <select id="studentPicker" class="form-control" style="font-weight:600;min-width:240px;" onchange="location.href='entry.php?class=<?= e_url($effClassKey) ?>&roll=' + encodeURIComponent(this.value)">
                        <?php if ($mode === 'new' || !$edit): ?>
                            <option value="" selected>&plus; Add New Student (Roll <?= $nextAvailableRoll ?>)</option>
                        <?php endif; ?>
                        <?php foreach ($roster as $st): 
                            $isSel = ($edit && (string)$st['roll_no'] === (string)$targetRoll) ? 'selected' : '';
                        ?>
                        <option value="<?= e_attr($st['roll_no']) ?>" <?= $isSel ?>>
                            Roll <?= sanitize($st['roll_no']) ?> &mdash; <?= sanitize($st['student_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="nav-buttons-group">
                        <?php if ($prevStudentRoll !== null): ?>
                        <a href="entry.php?class=<?= e_url($effClassKey) ?>&roll=<?= e_url($prevStudentRoll) ?>" class="btn btn-sm btn-outline" title="Previous Student">
                            <i class="fa-solid fa-chevron-left"></i> Prev
                        </a>
                        <?php else: ?>
                        <button class="btn btn-sm btn-outline is-idle" disabled><i class="fa-solid fa-chevron-left"></i> Prev</button>
                        <?php endif; ?>

                        <?php if ($nextStudentRoll !== null): ?>
                        <a href="entry.php?class=<?= e_url($effClassKey) ?>&roll=<?= e_url($nextStudentRoll) ?>" class="btn btn-sm btn-outline" title="Next Student">
                            Next <i class="fa-solid fa-chevron-right"></i>
                        </a>
                        <?php else: ?>
                        <button class="btn btn-sm btn-outline is-idle" disabled>Next <i class="fa-solid fa-chevron-right"></i></button>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- Integrated Student Actions (Available when editing) -->
                <?php if ($edit && $report): ?>
                <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <a href="view_report_card.php?class=<?= e_url($effClassKey) ?>&roll=<?= e_url($targetRoll) ?>" target="_blank" class="btn btn-sm btn-outline" title="View Printable Report Card">
                        <i class="fa-solid fa-file-invoice"></i> View Report Card
                    </a>
                    <a href="whatsapp_share.php?class=<?= e_url($effClassKey) ?>&selected_rolls=<?= e_url($targetRoll) ?>" class="btn btn-sm btn-whatsapp" title="Share via WhatsApp Hub">
                        <i class="fa-brands fa-whatsapp"></i> WhatsApp Share
                    </a>
                    <button type="button" class="btn btn-sm btn-outline dashboard-btn" 
                            data-class="<?= e_attr($effClassKey) ?>" 
                            data-roll="<?= e_attr($targetRoll) ?>"
                            data-name="<?= e_attr($report['name']) ?>"
                            title="Open Performance Dashboard">
                        <i class="fa-solid fa-chart-line"></i> Analytics
                    </button>
                    <button type="button" class="btn btn-sm btn-danger" onclick="confirmDel()" title="Delete Student">
                        <i class="fa-solid fa-trash"></i> Delete
                    </button>
                </div>
                <?php endif; ?>
            </div>

            <!-- 3. MAIN STUDENT ENTRY FORM -->
            <form method="POST" id="entryForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="renamed_name" value="">

                <!-- PANEL 1: STUDENT DEMOGRAPHICS -->
                <div class="panel" style="margin-bottom:22px;">
                    <div class="panel-header">
                        <h2 style="margin:0;font-size:15px;font-weight:700;"><i class="fa-solid fa-user" style="color:var(--brand-main);margin-right:8px;"></i> Student Demographics & Profile</h2>
                        <?php if ($edit): ?>
                        <span style="font-size:12px;background:#e0e7ff;color:#3730a3;font-weight:700;padding:3px 10px;border-radius:12px;">Editing Mode</span>
                        <?php else: ?>
                        <span style="font-size:12px;background:#dcfce7;color:#166534;font-weight:700;padding:3px 10px;border-radius:12px;">New Entry Mode</span>
                        <?php endif; ?>
                    </div>
                    <div class="panel-body" style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:18px;">
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Academic Year *</label>
                            <input type="text" name="academic_year" class="form-control" value="<?= sanitize($report['academic_year'] ?? $ctx['academic_year']) ?>" readonly style="background:#f8fafc;" required>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Standard (STD) *</label>
                            <input type="text" name="std" id="stdInput" class="form-control" value="<?= sanitize($report['std'] ?? $ctx['std']) ?>" readonly style="background:#f8fafc;" required>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Division *</label>
                            <input type="text" name="division" class="form-control" value="<?= sanitize($report['division'] ?? $ctx['division']) ?>" readonly style="background:#f8fafc;" required>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Semester *</label>
                            <input type="text" name="semester" class="form-control" value="<?= sanitize($report['semester'] ?? $ctx['semester']) ?>" readonly style="background:#f8fafc;" required>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Roll Number *</label>
                            <input type="text" name="roll_no" class="form-control" value="<?= sanitize($edit ? $report['roll'] : $targetRoll) ?>" required <?= $edit ? 'readonly style="background:#f8fafc;"' : '' ?>>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Student Full Name *</label>
                            <input type="text" name="student_name" class="form-control" value="<?= sanitize($report['name'] ?? '') ?>" placeholder="e.g. SURYAWANSHI AARAV AMIT" required>
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Date of Birth (DOB) <small id="dobWordsDisplay" style="color:var(--brand-main);font-weight:600;"></small></label>
                            <input type="text" name="date_of_birth" id="dobInput" class="form-control" value="<?= sanitize($report['date_of_birth'] ?? '') ?>" placeholder="YYYY-MM-DD or DD/MM/YYYY">
                        </div>
                        <div class="form-group" style="margin:0;">
                            <label class="form-label">Attendance Record</label>
                            <input type="text" name="attendance" class="form-control" value="<?= sanitize($report['attendance'] ?? '') ?>" placeholder="e.g. 92% or 40/42">
                        </div>
                        <div class="form-group" style="margin:0;grid-column: span 2;">
                            <label class="form-label">Teacher Remark</label>
                            <input type="text" name="remark" id="remarkInput" class="form-control" value="<?= sanitize($report['remark'] ?? '') ?>" placeholder="e.g. Excellent progress in mathematics">
                            <div class="preset-pills">
                                <span class="preset-pill remark-preset-btn" data-text="Excellent">Excellent</span>
                                <span class="preset-pill remark-preset-btn" data-text="Very Good">Very Good</span>
                                <span class="preset-pill remark-preset-btn" data-text="Good Conduct">Good Conduct</span>
                                <span class="preset-pill remark-preset-btn" data-text="Needs Improvement">Needs Improvement</span>
                                <span class="preset-pill remark-preset-btn" data-text="Promoted with Distinction">Promoted with Distinction</span>
                            </div>
                        </div>
                        <div class="form-group" style="margin:0;display:flex;align-items:center;padding-top:20px;">
                            <label style="display:flex;align-items:center;gap:8px;cursor:pointer;margin:0;font-weight:600;font-size:13px;">
                                <input type="checkbox" name="parents_sign" value="1" <?= ($report && $report['parents_sign']) ? 'checked' : '' ?> style="width:16px;height:16px;accent-color:var(--brand-main);">
                                <span>Parent Signature Confirmed</span>
                            </label>
                        </div>
                    </div>
                </div>

                <!-- PANEL 2: SUBJECT MARKS MATRIX -->
                <div class="panel" style="margin-bottom:22px;overflow:hidden;">
                    <div class="panel-header" style="display:flex;align-items:center;justify-content:space-between;gap:12px;background:linear-gradient(180deg,#fafbfc,#f3f5f8);padding:15px 20px;">
                        <div style="display:flex;align-items:center;gap:12px;min-width:0;">
                            <span style="width:38px;height:38px;border-radius:11px;flex-shrink:0;display:inline-flex;align-items:center;justify-content:center;color:#fff;background:linear-gradient(135deg,#a8841c,#d9b545);box-shadow:0 5px 12px -5px rgba(201,162,39,.6);"><i class="fa-solid fa-table-cells"></i></span>
                            <div>
                                <h2 style="margin:0;font-size:15px;font-weight:800;letter-spacing:-.01em;">Subject Marks Matrix</h2>
                                <span style="font-size:11.5px;color:var(--text-2);font-weight:600;">Type marks below — live totals, grading, and pass/fail calculate instantly. <?= count($matrixSubjects) ?> subjects</span>
                            </div>
                        </div>
                        <button type="button" class="btn btn-sm btn-outline" onclick="document.getElementById('manageSubjectsDetails').toggleAttribute('open')">
                            <i class="fa-solid fa-sliders"></i> Configure Subjects
                        </button>
                    </div>
                    <div class="panel-body">
                        <?php if (!empty($matrixSubjects)): ?>
                        <div class="matrix-wrap">
                            <table class="subj-matrix">
                                <thead>
                                    <tr>
                                        <th style="text-align:left;padding-left:14px;">Evaluation Type</th>
                                        <?php foreach ($matrixSubjects as $ci => $ms): 
                                            $isSingle = ((float)$ms['sa_max'] <= 0.0);
                                        ?>
                                        <th>
                                            <?= sanitize($ms['subject_name']) ?>
                                            <input type="hidden" name="subject_name[]" value="<?= e_attr($ms['subject_name']) ?>">
                                        </th>
                                        <?php endforeach; ?>
                                        <th class="mtx-total">GRAND TOTAL</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <!-- FA Max -->
                                    <tr>
                                        <th><span class="mtx-row-tag mtx-tag-max">MAX</span>FA Max Limit</th>
                                        <?php foreach ($matrixSubjects as $ci => $ms): 
                                            $r = $rowBySubject[mb_strtolower($ms['subject_name'])] ?? null;
                                            $val = $r ? $r['fa_max'] : $ms['fa_max'];
                                        ?>
                                        <td>
                                            <input type="number" step="0.5" min="0" class="mk-input" data-mark="fa_max" data-col="<?= $ci ?>" name="fa_max[]" value="<?= e_attr($fmtNum($val)) ?>">
                                        </td>
                                        <?php endforeach; ?>
                                        <td class="mtx-total" id="tot-fa_max"></td>
                                    </tr>

                                    <!-- FA Obtained -->
                                    <tr>
                                        <th><span class="mtx-row-tag mtx-tag-obt">MARKS</span>FA Marks Obtained</th>
                                        <?php foreach ($matrixSubjects as $ci => $ms): 
                                            $r = $rowBySubject[mb_strtolower($ms['subject_name'])] ?? null;
                                            $val = $r ? $r['fa_obtained'] : '';
                                        ?>
                                        <td>
                                            <input type="number" step="0.5" min="0" class="mk-input" data-mark="fa_obtained" data-col="<?= $ci ?>" data-cap="<?= e_attr($fmtNum($ms['fa_max'])) ?>" name="fa_obtained[]" value="<?= $val !== '' ? e_attr($fmtNum($val)) : '' ?>" placeholder="—">
                                        </td>
                                        <?php endforeach; ?>
                                        <td class="mtx-total" id="tot-fa_obtained"></td>
                                    </tr>

                                    <!-- SA Max -->
                                    <tr>
                                        <th><span class="mtx-row-tag mtx-tag-max">MAX</span>SA Max Limit</th>
                                        <?php foreach ($matrixSubjects as $ci => $ms): 
                                            $r = $rowBySubject[mb_strtolower($ms['subject_name'])] ?? null;
                                            $val = $r ? $r['sa_max'] : $ms['sa_max'];
                                            $isSingle = ((float)$ms['sa_max'] <= 0.0);
                                        ?>
                                        <td>
                                            <?php if ($isSingle): ?>
                                                <input type="hidden" class="mk-input" data-mark="sa_max" data-col="<?= $ci ?>" name="sa_max[]" value="0">
                                                <span class="mtx-single"><i class="fa-solid fa-minus" style="font-size:9px;"></i>Single Mark</span>
                                            <?php else: ?>
                                                <input type="number" step="0.5" min="0" class="mk-input" data-mark="sa_max" data-col="<?= $ci ?>" name="sa_max[]" value="<?= e_attr($fmtNum($val)) ?>">
                                            <?php endif; ?>
                                        </td>
                                        <?php endforeach; ?>
                                        <td class="mtx-total" id="tot-sa_max"></td>
                                    </tr>

                                    <!-- SA Obtained -->
                                    <tr>
                                        <th><span class="mtx-row-tag mtx-tag-obt">MARKS</span>SA Marks Obtained</th>
                                        <?php foreach ($matrixSubjects as $ci => $ms): 
                                            $r = $rowBySubject[mb_strtolower($ms['subject_name'])] ?? null;
                                            $val = $r ? $r['sa_obtained'] : '';
                                            $isSingle = ((float)$ms['sa_max'] <= 0.0);
                                        ?>
                                        <td>
                                            <?php if ($isSingle): ?>
                                                <input type="hidden" class="mk-input" data-mark="sa_obtained" data-col="<?= $ci ?>" name="sa_obtained[]" value="0">
                                                <span class="mtx-single"><i class="fa-solid fa-minus" style="font-size:9px;"></i>N/A</span>
                                            <?php else: ?>
                                                <input type="number" step="0.5" min="0" class="mk-input" data-mark="sa_obtained" data-col="<?= $ci ?>" data-cap="<?= e_attr($fmtNum($ms['sa_max'])) ?>" name="sa_obtained[]" value="<?= $val !== '' ? e_attr($fmtNum($val)) : '' ?>" placeholder="—">
                                            <?php endif; ?>
                                        </td>
                                        <?php endforeach; ?>
                                        <td class="mtx-total" id="tot-sa_obtained"></td>
                                    </tr>

                                    <!-- Subject Subtotals -->
                                    <tr class="mtx-subtotal-row">
                                        <th><span class="mtx-row-tag mtx-tag-sub">TOTAL</span>Subject Subtotal</th>
                                        <?php foreach ($matrixSubjects as $ci => $ms): ?>
                                        <td class="subj-total-cell" data-col="<?= $ci ?>"></td>
                                        <?php endforeach; ?>
                                        <td class="mtx-total" id="tot-grand"></td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>

                        <!-- LIVE STATS STRIP -->
                        <div class="live-summary" id="liveSummary">
                            <div class="summary-chip"><span class="sc-ico"><i class="fa-solid fa-bullseye"></i></span><span class="sc-label">Grand Total</span><strong id="sumGrand">0 / 0</strong></div>
                            <div class="summary-chip"><span class="sc-ico"><i class="fa-solid fa-percent"></i></span><span class="sc-label">Percentage</span><strong id="sumPct" style="color:var(--brand-main);">0.00%</strong></div>
                            <div class="summary-chip"><span class="sc-ico"><i class="fa-solid fa-award"></i></span><span class="sc-label">Official Grade</span><strong id="sumGrade">F</strong></div>
                            <div class="summary-chip"><span class="sc-ico"><i class="fa-solid fa-layer-group"></i></span><span class="sc-label">Division Label</span><strong id="sumDivision">Fail</strong></div>
                            <div class="summary-chip"><span class="sc-ico"><i class="fa-solid fa-circle-check"></i></span><span class="sc-label">Passing Status</span><strong id="sumPass" class="result-badge badge-fail">FAIL</strong></div>
                        </div>
                        <div id="liveTotal" style="font-size:12px;color:var(--text-3);margin-top:10px;text-align:right;"></div>
                        <?php else: ?>
                        <div style="text-align:center;padding:36px;color:var(--text-3);">
                            <i class="fa-solid fa-book-open" style="font-size:32px;margin-bottom:10px;display:block;color:var(--brand-main);"></i>
                            No subjects configured for this class cohort yet.
                            <div style="margin-top:10px;">
                                <button type="button" class="btn btn-primary" onclick="document.getElementById('manageSubjectsDetails').setAttribute('open','true')">
                                    <i class="fa-solid fa-plus"></i> Configure Class Subjects
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>

                <!-- SUBMIT ACTIONS -->
                <div class="mtx-savebar">
                    <span class="mtx-save-hint"><i class="fa-solid fa-shield-halved"></i> Validated before save — over-max marks are blocked</span>
                    <div class="mtx-save-group">
                    <button type="submit" name="submit_action" value="save" class="btn btn-primary" style="padding:12px 32px;font-size:14px;font-weight:700;">
                        <i class="fa-solid fa-save"></i> Save Student Entry
                    </button>
                    <button type="submit" name="submit_action" value="save_and_add_next" class="btn btn-outline-secondary" style="padding:12px 24px;font-size:14px;font-weight:700;">
                        <i class="fa-solid fa-user-plus"></i> Save &amp; Add Next Student
                    </button>
                    <a href="results.php?class=<?= e_url($effClassKey) ?>" class="btn btn-outline-secondary" style="padding:12px 20px;font-size:14px;">
                        <i class="fa-solid fa-arrow-left"></i> Back to Results
                    </a>
                    </div>
                </div>
            </form>

            <!-- 4. MANAGE SUBJECTS PANEL -->
            <details class="panel" id="manageSubjectsDetails" style="margin-bottom:24px;overflow:hidden;">
                <summary style="cursor:pointer;padding:15px 20px;font-weight:800;color:var(--brand-main);display:flex;align-items:center;justify-content:space-between;gap:12px;background:linear-gradient(180deg,#fafbfc,#f3f5f8);list-style:none;">
                    <span><span style="width:32px;height:32px;border-radius:9px;display:inline-flex;align-items:center;justify-content:center;color:#fff;background:linear-gradient(135deg,#7a2238,#a33a4f);margin-right:10px;font-size:.8rem;"><i class="fa-solid fa-sliders"></i></span>Manage Class Subjects <span style="font-weight:600;color:var(--text-3);font-size:12px;">— columns configuration</span></span>
                    <span style="font-size:12px;color:var(--text-2);font-weight:600;"><?= sanitize($currentClassLabel) ?></span>
                </summary>
                <form method="POST" id="manageSubjectsForm" class="panel-body" style="padding:20px;">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="save_subjects">
                    <input type="hidden" name="class_key" value="<?= e_attr($effClassKey) ?>">
                    <input type="hidden" name="academic_year" value="<?= e_attr($ctx['academic_year']) ?>">
                    <input type="hidden" name="std" value="<?= e_attr($ctx['std']) ?>">
                    <input type="hidden" name="division" value="<?= e_attr($ctx['division']) ?>">
                    <input type="hidden" name="semester" value="<?= e_attr($ctx['semester']) ?>">

                    <div class="ms-head">
                        <span>Subject Name</span>
                        <span>FA Max Limit</span>
                        <span>SA Max Limit</span>
                        <span></span>
                    </div>
                    <div id="manageSubjectRows">
                        <?php foreach ($classSubjects as $s): ?>
                        <div class="ms-row">
                            <input type="text" name="subject_name[]" value="<?= e_attr($s['subject_name']) ?>" placeholder="Subject name" required>
                            <input type="number" name="fa_max[]" step="0.5" min="0" value="<?= e_attr($fmtNum($s['fa_max'])) ?>">
                            <input type="number" name="sa_max[]" step="0.5" min="0" value="<?= e_attr($fmtNum($s['sa_max'])) ?>">
                            <button type="button" class="btn-rem-subj ms-remove" title="Remove subject"><i class="fas fa-times"></i></button>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:14px;">
                        <button type="button" class="btn-add-subj" id="msAddRow"><i class="fas fa-plus"></i> Add Subject Column</button>
                        <button type="submit" class="btn btn-primary"><i class="fas fa-save"></i> Save Subject Columns</button>
                    </div>
                </form>
            </details>

        </div><!-- /.admin-content -->
    </main>
</div><!-- /.admin-layout -->

<!-- DELETE CONFIRMATION MODAL -->
<form id="deleteEntryForm" method="POST" style="display:none;">
    <?= csrfField() ?>
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="class_key" id="delCk" value="<?= e_attr($effClassKey) ?>">
    <input type="hidden" name="roll" id="delRoll" value="<?= e_attr($targetRoll) ?>">
</form>
<div class="confirm-modal" id="deleteModal" role="alertdialog" aria-modal="true" hidden>
    <div class="confirm-backdrop" data-close></div>
    <div class="confirm-card" role="document">
        <div style="width:52px;height:52px;border-radius:50%;background:#fee2e2;color:#dc2626;display:flex;align-items:center;justify-content:center;margin:0 auto 14px auto;font-size:22px;">
            <i class="fas fa-triangle-exclamation"></i>
        </div>
        <h3 style="margin:0 0 8px 0;font-size:18px;">Delete student?</h3>
        <p style="color:var(--text-2);font-size:13.5px;margin:0 0 18px 0;">
            Roll <strong id="deleteModalRoll"><?= sanitize($targetRoll) ?></strong> and all their marks will be permanently removed. This cannot be undone.
        </p>
        <div style="display:flex;gap:10px;justify-content:center;">
            <button type="button" class="btn btn-outline" id="deleteCancelBtn">Cancel</button>
            <button type="button" class="btn btn-danger" id="deleteConfirmBtn"><i class="fas fa-trash"></i> Delete Student</button>
        </div>
    </div>
</div>

<!-- PERFORMANCE DASHBOARD MODAL -->
<div class="modal" id="dashboardModal" role="dialog" aria-modal="true" style="display:none;">
    <div class="modal-backdrop" tabindex="-1"></div>
    <div class="modal-panel" role="document">
        <header class="modal-header">
            <h3 id="dashTitle" style="margin:0;font-size:16px;font-weight:700;"><i class="fas fa-chart-line" style="color:var(--brand-main);margin-right:8px;"></i> <span class="student-name"></span> &mdash; Academic Analytics</h3>
            <div style="display:flex;align-items:center;gap:8px;">
                <button type="button" class="modal-close" onclick="closeDashboardModal()"><i class="fas fa-times"></i></button>
            </div>
        </header>
        <nav class="dashboard-tabs" role="tablist">
            <button role="tab" data-tab="overview" class="active"><i class="fas fa-home"></i> Overview</button>
            <button role="tab" data-tab="subjects"><i class="fas fa-book"></i> Subjects</button>
        </nav>
        <div class="modal-body" id="dashboardModalBody">
            <div id="dashLoading" style="text-align:center;padding:40px;color:var(--text-3);"><i class="fas fa-spinner fa-spin"></i> Loading student analytics...</div>
            <div id="dashContent" style="display:none;">
                <div id="dashTabOverview">
                    <div class="kpi-grid" id="kpiGrid"></div>
                    <div style="height:280px;position:relative;">
                        <canvas id="subjectChartCanvas"></canvas>
                    </div>
                    <div id="dashChartEmpty" style="display:none;text-align:center;padding:24px;color:var(--text-3);font-size:0.86rem;">No subject marks recorded yet for this student.</div>
                </div>
                <div id="dashTabSubjects" style="display:none;">
                    <div class="table-responsive" style="overflow-x:auto;border:1px solid var(--border);border-radius:8px;">
                        <table style="width:100%;border-collapse:collapse;font-size:0.84rem;">
                            <thead>
                                <tr style="background:#f8fafc;text-align:left;">
                                    <th style="padding:10px 12px;border-bottom:2px solid var(--border);">Subject</th>
                                    <th style="padding:10px 12px;border-bottom:2px solid var(--border);text-align:center;">FA</th>
                                    <th style="padding:10px 12px;border-bottom:2px solid var(--border);text-align:center;">SA</th>
                                    <th style="padding:10px 12px;border-bottom:2px solid var(--border);text-align:center;">Total</th>
                                    <th style="padding:10px 12px;border-bottom:2px solid var(--border);text-align:center;">%</th>
                                    <th style="padding:10px 12px;border-bottom:2px solid var(--border);text-align:center;">Grade</th>
                                </tr>
                            </thead>
                            <tbody id="dashSubjectsBody"></tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script type="application/json" id="entryData"><?= json_encode(['rows' => $report['rows'] ?? [], 'subjects' => $matrixSubjects, 'classKey' => $effClassKey, 'roll' => $targetRoll], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?></script>
<script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= filemtime(__DIR__ . '/../assets/js/admin.js') ?>"></script>
<script src="<?= BASE_URL ?>assets/js/entry.js?v=<?= filemtime(__DIR__ . '/../assets/js/entry.js') ?>"></script>
</body>
</html>
