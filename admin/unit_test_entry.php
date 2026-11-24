<?php
/**
 * HCC Automation System — Dedicated Unit Test Student Entry & Editor
 * Mirrors the comprehensive entry standard of admin/entry.php for Unit Tests 1-4.
 * Features:
 *   - Class & Test Type selection with live student enrollment count
 *   - Sequential student roster navigation (< Prev Student, Next Student >, Student Switcher)
 *   - Auto-suggest next available roll number for new students
 *   - Student demographics: Roll, Name, Gender, Attendance, Teacher Remark with 1-click pills
 *   - Official Unit Test Grading Scale (A1, A2, B1, B2, C1, C2, D, E) & Pass/Fail status
 *   - Real-time over-max mark validation and Absent toggles (- / AB)
 *   - Direct student actions: View Parent Slip, WhatsApp Share, Delete Student
 *   - Dual save workflows: "Save Changes" and "Save & Add Next Student"
 *   - Manage Subjects configuration panel
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

$adminPage  = 'unit_test_entry';
$db         = getDB();
rcEnsureUnitTestTables();
$settings   = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church English Medium School, Solapur';
$adminUser  = sanitize($_SESSION['admin_name'] ?? 'Admin');
$adminInitials = strtoupper(implode('', array_map(
    function ($part) { return mb_substr($part, 0, 1); },
    array_slice(array_filter(preg_split('/\s+/', trim($_SESSION['admin_name'] ?? 'A'))), 0, 2)
)));
$notifData  = rcGetRecentNotifications(8);

// Available class options for unit tests
$classOptions = rcGetUnitTestClassOptions();

// Filter parameters with fallbacks
$activeYear  = rcGetActiveAcademicYear();
$year        = trim($_GET['year'] ?? $activeYear);
if ($year === '') $year = $activeYear;
$std         = trim($_GET['std'] ?? '1');
$division    = trim($_GET['division'] ?? 'A');
$testType    = trim($_GET['test_type'] ?? 'Unit Test 2');
$yearOptions = rcGetAcademicYearOptions();
if (!in_array($year, $yearOptions, true)) array_unshift($yearOptions, $year);

$preferSem = in_array($testType, ['Unit Test 3', 'Unit Test 4'], true) ? 'Sem 2' : 'Sem 1';
$semStmt = $db->prepare("SELECT semester FROM report_card WHERE academic_year = ? AND std = ? AND division = ? ORDER BY (semester = ?) DESC, id ASC LIMIT 1");
$semStmt->execute([$year, $std, $division, $preferSem]);
$cohortSem = trim((string)$semStmt->fetchColumn()) ?: $preferSem;
$cohortClassKey = rcBuildClassKey($year, $std, $division, $cohortSem);

$romanMap = ['1'=>'I','2'=>'II','3'=>'III','4'=>'IV','5'=>'V','6'=>'VI','7'=>'VII','8'=>'VIII','9'=>'IX','10'=>'X'];
$romanStd = $romanMap[(string)$std] ?? $std;
$currentClassLabel = "STD {$romanStd}-{$division} • {$testType} ({$year})";

$error   = '';
$success = '';

// Compact number formatter (20.0 -> 20, 18.5 -> 18.5)
$fmtNum = function ($v) {
    if ($v === null || $v === '') return '';
    $f = (float)$v;
    if (abs($f - round($f)) < 0.0001) return (string)(int)round($f);
    return rtrim(rtrim(number_format($f, 1, '.', ''), '0'), '.');
};

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (($_POST['action'] ?? '') === 'mark_notifs_read') {
        if (!verifyCSRFToken()) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false]);
            exit;
        }
        rcMarkNotificationsRead();
        header('Content-Type: application/json');
        echo json_encode(['ok' => true]);
        exit;
    }
    if (!verifyCSRFToken()) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $act = trim($_POST['action'] ?? '');

        // 1. Save Student Entry
        if ($act === 'save') {
            $data = [
                'academic_year' => trim($_POST['academic_year'] ?? $year),
                'std'           => trim($_POST['std'] ?? $std),
                'division'      => trim($_POST['division'] ?? $division),
                'test_type'     => trim($_POST['test_type'] ?? $testType),
                'roll_no'       => trim($_POST['roll_no'] ?? ''),
                'student_name'  => trim($_POST['student_name'] ?? ''),
                'gender'        => trim($_POST['gender'] ?? ''),
                'attendance'    => trim($_POST['attendance'] ?? ''),
                'remark'        => trim($_POST['remark'] ?? ''),
                'parents_sign'  => isset($_POST['parents_sign']) ? 1 : 0,
                'parent_phone'  => trim($_POST['parent_phone'] ?? ''),
                'subjects'      => []
            ];

            $subjNames = $_POST['subject_name'] ?? [];
            $maxMarks  = $_POST['max_marks'] ?? [];
            $obtMarks  = $_POST['marks_obtained'] ?? [];
            $absents   = $_POST['is_absent'] ?? [];

            foreach ($subjNames as $i => $name) {
                $name = trim($name);
                if ($name === '') continue;
                $isAb = !empty($absents[$i]) ? 1 : 0;
                $mObt = $isAb ? null : (float)($obtMarks[$i] ?? 0);
                $mMax = (float)($maxMarks[$i] ?? 20);

                $data['subjects'][] = [
                    'subject_name'   => $name,
                    'max_marks'      => $mMax,
                    'marks_obtained' => $mObt,
                    'is_absent'      => $isAb,
                ];
            }

            if ($data['std'] === '' || $data['roll_no'] === '' || $data['student_name'] === '') {
                $error = 'STD, Roll No and Student Name are required.';
            } elseif (empty($data['subjects'])) {
                $error = 'Please provide at least one subject row.';
            } else {
                $res = rcSaveUnitTestEntry($data, $adminUser);
                if ($res['ok']) {
                    $savedRoll = $data['roll_no'];
                    $baseRedirect = 'unit_test_entry.php?' . http_build_query([
                        'year'      => $data['academic_year'],
                        'std'       => $data['std'],
                        'division'  => $data['division'],
                        'test_type' => $data['test_type']
                    ]);

                    if (isset($_POST['submit_action']) && $_POST['submit_action'] === 'save_and_add_next') {
                        header('Location: ' . $baseRedirect . '&mode=new&msg=saved');
                        exit;
                    } else {
                        header('Location: ' . $baseRedirect . '&roll=' . urlencode($savedRoll) . '&msg=saved');
                        exit;
                    }
                } else {
                    $error = 'Save failed: ' . $res['error'];
                }
            }
        }

        // 2. Delete Student Record from Unit Test
        elseif ($act === 'delete_student') {
            $delRoll = trim($_POST['roll_no'] ?? '');
            if ($delRoll !== '') {
                $res = rcDeleteUnitTestStudents($year, $std, $division, $testType, [$delRoll]);
                if ($res['ok']) {
                    header('Location: unit_test_entry.php?' . http_build_query([
                        'year'      => $year,
                        'std'       => $std,
                        'division'  => $division,
                        'test_type' => $testType,
                        'msg'       => 'deleted'
                    ]));
                    exit;
                } else {
                    $error = 'Could not delete student: ' . ($res['error'] ?: 'Unknown error');
                }
            }
        }

        // 3. Save / Update Test Subjects Config
        elseif ($act === 'save_subjects') {
            $cfgNames = $_POST['cfg_subject_name'] ?? [];
            $cfgMax   = $_POST['cfg_max_marks'] ?? [];
            $newCfg = [];

            if (is_array($cfgNames)) {
                foreach ($cfgNames as $i => $n) {
                    $n = trim((string)$n);
                    if ($n === '') continue;
                    $newCfg[] = [
                        'subject_name' => $n,
                        'max_marks'    => (float)($cfgMax[$i] ?? 20.0),
                    ];
                }
            }

            if (empty($newCfg)) {
                $error = 'At least one subject is required in subject configuration.';
            } else {
                $ok = rcSaveUnitTestConfig($year, $std, $testType, $newCfg);
                if ($ok) {
                    header('Location: unit_test_entry.php?' . http_build_query([
                        'year'      => $year,
                        'std'       => $std,
                        'division'  => $division,
                        'test_type' => $testType,
                        'roll'      => $_POST['current_roll'] ?? '',
                        'msg'       => 'subjects_saved'
                    ]));
                    exit;
                } else {
                    $error = 'Failed to save subject configuration.';
                }
            }
        }
    }
}

// Flash messages from redirects
if (isset($_GET['msg'])) {
    if ($_GET['msg'] === 'saved')          $success = 'Unit test student entry saved successfully.';
    if ($_GET['msg'] === 'deleted')        $success = 'Student removed from this unit test.';
    if ($_GET['msg'] === 'subjects_saved') $success = 'Unit test subjects configuration updated.';
}

// Fetch enrolled student roster for this class and test type
$roster = rcListUnitTestStudents($year, $std, $division, $testType);
$nextAvailableRoll = rcGetNextUnitTestRollNumber($year, $std, $division, $testType);

// Determine mode: Add New vs Edit Existing
$mode = trim($_GET['mode'] ?? '');
$roll = trim($_GET['roll'] ?? '');
$edit = false;
$report = null;

if ($mode === 'new') {
    $edit = false;
    $targetRoll = (string)$nextAvailableRoll;
} elseif ($roll !== '') {
    $report = rcGetStudentUnitTestReport($year, $std, $division, $testType, $roll);
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
        $report = rcGetStudentUnitTestReport($year, $std, $division, $testType, $targetRoll);
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

// Fetch configured subjects for this standard and test type
$classSubjects = rcGetUnitTestSubjects($year, $std, $testType);

// Merge with any student-specific rows if present
$matrixSubjects = $classSubjects;
$haveSubject = [];
foreach ($matrixSubjects as $s) {
    $haveSubject[mb_strtolower($s['subject_name'])] = true;
}
if ($report && !empty($report['subjects'])) {
    foreach ($report['subjects'] as $sName => $sData) {
        $lk = mb_strtolower($sName);
        if (!isset($haveSubject[$lk])) {
            $haveSubject[$lk] = true;
            $matrixSubjects[] = [
                'subject_name' => $sData['subject_name'],
                'max_marks'    => (float)$sData['max_marks'],
            ];
        }
    }
}

$pageTitle = ($edit ? 'Edit Student Entry' : 'Add New Student Entry') . ' — ' . $schoolName;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle) ?></title>
    <link rel="icon" href="../assets/images/hcclogo.jpg">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="../assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
    <style>

        /* ══ Professional design layer (unit-test entry) ══ */
        .ute-wrap { --ute-radius: 16px; --ute-shadow: 0 1px 2px rgba(15,23,42,.05), 0 8px 24px -12px rgba(15,23,42,.18); }

        /* ── Hero band ── */
        .ute-hero {
            position: relative; overflow: hidden; border-radius: 18px; margin-bottom: 18px;
            background: linear-gradient(120deg, #3d0f1f 0%, #7a2238 45%, #a33a4f 100%);
            color: #fff; padding: 22px 24px; box-shadow: var(--ute-shadow);
        }
        .ute-hero::before {
            content: ''; position: absolute; inset: 0; pointer-events: none;
            background:
                radial-gradient(420px 200px at 95% -30%, rgba(201,162,39,.35), transparent 60%),
                radial-gradient(300px 180px at 60% 130%, rgba(255,255,255,.08), transparent 60%);
        }
        .ute-hero::after {
            content: ''; position: absolute; inset: 0; pointer-events: none; opacity: .5;
            background-image: radial-gradient(rgba(255,255,255,.14) 1px, transparent 1px);
            background-size: 18px 18px;
            mask-image: linear-gradient(100deg, transparent 55%, #000 100%);
        }
        .ute-hero-inner { position: relative; z-index: 1; display: flex; align-items: center; justify-content: space-between; gap: 18px; flex-wrap: wrap; }
        .ute-crumb { display: flex; align-items: center; gap: 8px; font-size: .72rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: rgba(255,255,255,.72); margin-bottom: 8px; }
        .ute-crumb i { font-size: .62rem; opacity: .6; }
        .ute-hero h2 { margin: 0; font-size: 1.45rem; font-weight: 800; letter-spacing: -.01em; display: flex; align-items: center; gap: 12px; }
        .ute-hero h2 .ute-hero-ico { width: 44px; height: 44px; border-radius: 13px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.05rem; background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.25); backdrop-filter: blur(4px); }
        .ute-hero p { margin: 8px 0 0; font-size: .86rem; color: rgba(255,255,255,.82); max-width: 560px; }
        .ute-hero-meta { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 14px; }
        .ute-chip { display: inline-flex; align-items: center; gap: 7px; font-size: .76rem; font-weight: 700; padding: 7px 13px; border-radius: 999px; background: rgba(255,255,255,.13); border: 1px solid rgba(255,255,255,.22); backdrop-filter: blur(4px); white-space: nowrap; }
        .ute-chip i { color: #f3d27a; font-size: .72rem; }
        .ute-chip--gold { background: linear-gradient(120deg, #c9a227, #e3c15d); color: #3d2c05; border-color: transparent; }
        .ute-chip--gold i { color: #3d2c05; }
        .ute-hero-side { display: flex; flex-direction: column; align-items: flex-end; gap: 10px; }
        .ute-enrolled { text-align: right; }
        .ute-enrolled strong { display: block; font-size: 1.9rem; font-weight: 800; line-height: 1; font-variant-numeric: tabular-nums; }
        .ute-enrolled span { font-size: .7rem; font-weight: 700; letter-spacing: .1em; text-transform: uppercase; color: rgba(255,255,255,.7); }
        .ute-hero-actions { display: flex; gap: 8px; flex-wrap: wrap; justify-content: flex-end; }
        .ute-btn-light { display: inline-flex; align-items: center; gap: 7px; font-size: .8rem; font-weight: 700; padding: 9px 15px; border-radius: 10px; background: rgba(255,255,255,.14); color: #fff; border: 1px solid rgba(255,255,255,.28); text-decoration: none; cursor: pointer; transition: background .15s, transform .15s; white-space: nowrap; }
        .ute-btn-light:hover { background: rgba(255,255,255,.24); transform: translateY(-1px); }
        .ute-btn-gold { display: inline-flex; align-items: center; gap: 7px; font-size: .8rem; font-weight: 800; padding: 9px 16px; border-radius: 10px; background: linear-gradient(120deg, #c9a227, #e3c15d); color: #3d2c05; border: none; text-decoration: none; cursor: pointer; box-shadow: 0 6px 18px -6px rgba(201,162,39,.7); transition: transform .15s, box-shadow .15s; white-space: nowrap; }
        .ute-btn-gold:hover { transform: translateY(-1px); box-shadow: 0 10px 24px -6px rgba(201,162,39,.8); }

        /* ── Control card (class / test picker) ── */
        .entry-top-bar {
            background: var(--paper, #ffffff);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: var(--radius, 10px);
            padding: 14px 18px;
            display: flex;
            align-items: flex-end;
            justify-content: space-between;
            gap: 14px;
            margin-bottom: 18px;
            box-shadow: var(--shadow-sm, 0 1px 2px rgba(0,0,0,0.05));
            flex-wrap: wrap;
        }
        .class-picker-wrap {
            display: flex;
            align-items: flex-end;
            gap: 12px;
            flex-wrap: wrap;
        }
        .tp-field { display: flex; flex-direction: column; gap: 5px; min-width: 0; }
        .tp-field > span {
            font-size: 0.8rem;
            font-weight: 600;
            text-transform: none;
            letter-spacing: normal;
            color: var(--text-2, #4b5563);
            line-height: 1.25;
            display: flex; align-items: center; gap: 5px;
        }
        .tp-field > span i { color: var(--text-3, #6b7280); font-size: .75rem; }
        .tp-field .form-control {
            appearance: none; -webkit-appearance: none;
            background-color: #ffffff;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%2364748b' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat; background-position: right 11px center;
            padding: 0 30px 0 11px !important;
            height: 38px;
            box-sizing: border-box;
            font-size: 0.88rem; font-weight: 500;
            border-radius: var(--radius-sm, 8px);
            border: 1px solid var(--border, #cbd5e1);
            color: var(--text, #1f2937);
            width: auto;
            box-shadow: none;
            transition: border-color 0.15s ease, box-shadow 0.15s ease;
        }
        .tp-field .form-control:hover { border-color: #94a3b8; }
        .tp-field .form-control:focus {
            outline: none;
            border-color: var(--brand, #7a2238);
            box-shadow: 0 0 0 3px rgba(122, 34, 56, 0.12);
        }
        .tp-sep { width: 1px; height: 26px; align-self: flex-end; margin-bottom: 6px; background: var(--border, #e5e7eb); margin-left: 2px; margin-right: 2px; }
        .class-badge-pill {
            background: #f1f5f9;
            color: #334155;
            font-size: 0.8rem;
            font-weight: 600;
            letter-spacing: normal;
            height: 38px;
            box-sizing: border-box;
            padding: 0 14px;
            border-radius: 999px;
            border: 1px solid #e2e8f0;
            display: inline-flex;
            align-items: center;
            gap: 7px;
            white-space: nowrap;
            align-self: flex-end;
            margin-bottom: 0;
        }
        .class-badge-pill i { color: #64748b; font-size: 0.75rem; }
        .tp-count {
            font-size: 0.82rem;
            color: var(--text-2, #64748b);
            font-weight: 500;
            height: 38px;
            box-sizing: border-box;
            display: inline-flex;
            align-items: center;
            align-self: flex-end;
            padding: 0 4px;
            margin-bottom: 0;
            white-space: nowrap;
        }
        .tp-count strong { color: var(--text, #1f2937); font-weight: 700; margin-right: 4px; font-variant-numeric: tabular-nums; }
        .tp-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-left: auto;
            align-self: flex-end;
        }
        .tp-actions .btn {
            height: 38px;
            box-sizing: border-box;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            line-height: 1;
            padding: 0 14px;
            font-size: 0.82rem;
            font-weight: 600;
            border-radius: var(--radius-sm, 8px);
            white-space: nowrap;
            vertical-align: middle;
            text-decoration: none;
            gap: 6px;
            margin: 0;
            border: 1px solid transparent;
        }
        .tp-actions .btn i {
            font-size: 0.8rem;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
        }
        .tp-actions .btn-outline {
            background: #ffffff;
            color: var(--text-2, #374151);
            border: 1px solid var(--border, #cbd5e1);
            box-shadow: none;
        }
        .tp-actions .btn-outline i { color: #64748b; }
        .tp-actions .btn-outline:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            color: var(--text, #1f2937);
            transform: none;
            box-shadow: none;
        }
        .tp-actions .btn-primary {
            background: var(--brand, #7a2238);
            color: #ffffff;
            box-shadow: none;
        }
        .tp-actions .btn-primary:hover {
            background: var(--brand-dark, #5a1a2a);
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(122,34,56,.25);
        }
        @media (max-width: 900px) {
            .entry-top-bar { align-items: stretch; }
            .tp-actions { width: 100%; margin-left: 0; justify-content: flex-start; padding-top: 4px; }
        }

        /* ── Student Navigation Strip (simple) ── */
        .student-nav-strip {
            background: var(--paper, #ffffff);
            border: 1px solid var(--border, #e5e7eb);
            border-radius: var(--radius, 10px);
            padding: 12px 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px 16px;
            margin-bottom: 20px;
            box-shadow: var(--shadow-sm, 0 1px 2px rgba(0,0,0,.05));
        }

        /* Left: current student info */
        .sns-current {
            display: flex;
            align-items: center;
            gap: 10px;
            min-width: 0;
        }
        .sns-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            background: var(--brand, #7a2238);
            flex-shrink: 0;
        }
        .student-nav-strip.is-new .sns-dot {
            background: #10b981;
        }
        .sns-title {
            font-size: 0.92rem;
            font-weight: 700;
            color: var(--text, #1f2937);
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
            max-width: 320px;
        }
        .sns-sub {
            font-size: 0.8rem;
            color: var(--text-2, #6b7280);
            white-space: nowrap;
            font-variant-numeric: tabular-nums;
        }

        /* Right: controls */
        .sns-controls {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            min-width: 0;
        }
        .sns-select {
            appearance: none;
            -webkit-appearance: none;
            background-color: #ffffff;
            background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='10' height='6' viewBox='0 0 10 6'%3E%3Cpath d='M1 1l4 4 4-4' stroke='%2364748b' stroke-width='1.8' fill='none' stroke-linecap='round'/%3E%3C/svg%3E");
            background-repeat: no-repeat;
            background-position: right 11px center;
            padding: 0 30px 0 11px;
            height: 36px;
            box-sizing: border-box;
            font-size: 0.85rem;
            font-weight: 600;
            font-family: inherit;
            color: var(--text, #1f2937);
            border: 1px solid var(--border, #cbd5e1);
            border-radius: var(--radius-sm, 8px);
            min-width: 220px;
            max-width: 300px;
            outline: none;
            cursor: pointer;
            transition: border-color .15s ease;
        }
        .sns-select:hover { border-color: #94a3b8; }
        .sns-select:focus {
            border-color: var(--brand, #7a2238);
            box-shadow: 0 0 0 3px rgba(122,34,56,0.12);
        }

        /* Prev/Next stepper */
        .sns-stepper {
            display: inline-flex;
            align-items: center;
            gap: 2px;
        }
        .sns-step-btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            padding: 0 10px;
            height: 36px;
            border: 1px solid var(--border, #cbd5e1);
            background: #ffffff;
            color: var(--text-2, #374151);
            font-size: 0.8rem;
            font-weight: 600;
            border-radius: var(--radius-sm, 8px);
            text-decoration: none;
            cursor: pointer;
            transition: background .15s ease, border-color .15s ease;
            white-space: nowrap;
        }
        .sns-step-btn:first-child { border-radius: var(--radius-sm, 8px) 0 0 var(--radius-sm, 8px); border-right: none; }
        .sns-step-btn:last-child { border-radius: 0 var(--radius-sm, 8px) var(--radius-sm, 8px) 0; }
        .sns-step-btn:not(.is-disabled):not(:disabled):hover {
            background: #f8fafc;
            border-color: #94a3b8;
            color: var(--text, #1f2937);
        }
        .sns-step-btn.is-disabled, .sns-step-btn:disabled {
            color: #94a3b8;
            cursor: not-allowed;
            opacity: 0.45;
            pointer-events: none;
        }

        /* Action links */
        .sns-actions {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            margin-left: auto;
        }
        .sns-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 0.8rem;
            font-weight: 600;
            padding: 0 12px;
            height: 36px;
            box-sizing: border-box;
            border-radius: var(--radius-sm, 8px);
            text-decoration: none;
            cursor: pointer;
            border: 1px solid var(--border, #cbd5e1);
            background: #ffffff;
            color: var(--text-2, #374151);
            transition: background .15s ease, border-color .15s ease;
            white-space: nowrap;
        }
        .sns-btn i { font-size: 0.78rem; color: #64748b; }
        .sns-btn:hover {
            background: #f8fafc;
            border-color: #94a3b8;
            color: var(--text, #1f2937);
        }
        .sns-btn--delete {
            background: #fff5f5;
            border-color: #fecaca;
            color: #dc2626;
        }
        .sns-btn--delete i { color: #dc2626; }
        .sns-btn--delete:hover {
            background: #fef2f2;
            border-color: #f87171;
        }

        @media (max-width: 640px) {
            .student-nav-strip { flex-direction: column; align-items: stretch; }
            .sns-controls { width: 100%; }
            .sns-select { flex: 1 1 100%; max-width: 100%; }
            .sns-stepper { width: 100%; }
            .sns-step-btn { flex: 1; justify-content: center; }
            .sns-actions { width: 100%; }
        }

        /* ── Form Cards & Grid ── */
        .entry-grid {
            display: grid;
            grid-template-columns: 360px minmax(0, 1fr);
            gap: 20px;
            align-items: start;
        }
        @media (max-width: 992px) {
            .entry-grid { grid-template-columns: 1fr; }
        }
        .entry-card {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: var(--ute-radius);
            padding: 0;
            box-shadow: var(--ute-shadow);
            overflow: hidden;
            min-width: 0;
        }
        .entry-card-pad { padding: 20px 22px 22px; }
        .card-header-flex {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 0;
            padding: 16px 22px;
            border-bottom: 1px solid var(--border);
            background: linear-gradient(180deg, #fafbfc, #f4f6f9);
        }
        .card-title-wrap { display: flex; align-items: center; gap: 12px; min-width: 0; }
        .card-ico-tile {
            width: 38px; height: 38px; border-radius: 11px; flex-shrink: 0;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: .9rem; color: #fff;
            background: linear-gradient(135deg, var(--brand), #a33a4f);
            box-shadow: 0 5px 12px -5px rgba(122,34,56,.55);
        }
        .card-ico-tile--gold { background: linear-gradient(135deg, #a8841c, #d9b545); box-shadow: 0 5px 12px -5px rgba(201,162,39,.6); }
        .card-title-text {
            font-size: 15px;
            font-weight: 800;
            color: var(--text);
            margin: 0;
            letter-spacing: -.01em;
        }
        .card-title-sub { display: block; font-size: 11.5px; font-weight: 600; color: var(--text-3); margin-top: 2px; }
        .ute-kbd-hint { font-size: 11.5px; color: var(--text-3); font-weight: 600; white-space: nowrap; }
        .ute-kbd-hint kbd { background: #f1f5f9; border: 1px solid #e2e8f0; border-bottom-width: 2px; border-radius: 5px; padding: 1px 6px; font-size: 10.5px; font-family: inherit; font-weight: 700; color: #475569; }
        .ute-mode-badge {
            font-weight: 800; font-size: 11px; padding: 6px 12px; border-radius: 999px;
            display: inline-flex; align-items: center; gap: 6px; white-space: nowrap;
        }
        .ute-mode-badge--edit { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .ute-mode-badge--new { background: #dcfce7; color: #15803d; border: 1px solid #bbf7d0; }
        .ute-mode-badge .dot { width: 7px; height: 7px; border-radius: 50%; background: currentColor; }

        /* Demographic Inputs */
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: flex; align-items: center; gap: 6px;
            font-size: 11.5px;
            font-weight: 800;
            color: var(--text-2);
            margin-bottom: 7px;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .form-group label i { color: var(--brand); font-size: .7rem; }
        .form-control {
            width: 100%;
            height: 42px;
            padding: 8px 13px;
            font-size: 13.5px;
            font-weight: 600;
            border: 1.5px solid #dbe2ec;
            border-radius: 10px;
            background: #fbfcfe;
            color: var(--text);
            font-family: inherit;
            box-sizing: border-box;
            transition: border-color 0.15s ease, box-shadow 0.15s ease, background 0.15s ease;
        }
        .form-control::placeholder { color: #9aa6b8; font-weight: 500; }
        .form-control:hover { border-color: #b9c4d4; }
        .form-control:focus {
            outline: none;
            border-color: var(--brand);
            background: #fff;
            box-shadow: 0 0 0 3px rgba(122, 34, 56, 0.12);
        }
        .ute-2col { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
        .ute-check-card {
            display: flex; align-items: center; gap: 11px; cursor: pointer;
            background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 11px; padding: 11px 13px;
            transition: border-color .15s, background .15s;
        }
        .ute-check-card:hover { border-color: var(--brand); background: #fff8f9; }
        .ute-check-card input { width: 17px; height: 17px; accent-color: var(--brand); flex-shrink: 0; }
        .ute-check-card span { font-size: 12.5px; color: var(--text); font-weight: 700; line-height: 1.4; }

        /* Preset Pills */
        .preset-pills {
            display: flex;
            gap: 6px;
            flex-wrap: wrap;
            margin-top: 8px;
        }
        .ut-preset-pill {
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            color: #475569;
            font-size: 11px;
            font-weight: 700;
            padding: 5px 11px;
            border-radius: 999px;
            cursor: pointer;
            transition: all 0.15s;
        }
        .ut-preset-pill:hover {
            background: var(--brand);
            color: #fff;
            border-color: var(--brand);
            transform: translateY(-1px);
            box-shadow: 0 4px 10px -3px rgba(122,34,56,.5);
        }

        /* ── Subject Matrix Table ── */
        .table-responsive {
            overflow-x: auto;
            border: 1px solid #e2e8f0;
            border-radius: 12px;
            background: #fff;
        }
        table.ut-matrix-table {
            width: 100%;
            border-collapse: separate;
            border-spacing: 0;
            font-size: 13.5px;
            min-width: 640px;
        }
        table.ut-matrix-table thead th {
            background: linear-gradient(180deg, #1e2a5a, #16204a);
            color: #fff;
            padding: 11px 12px;
            font-weight: 800;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.06em;
            text-align: left;
            border-bottom: none;
            position: sticky;
            top: 0;
            white-space: nowrap;
        }
        table.ut-matrix-table thead th:first-child { border-top-left-radius: 11px; }
        table.ut-matrix-table thead th:last-child { border-top-right-radius: 11px; }
        table.ut-matrix-table td {
            padding: 10px 12px;
            border-bottom: 1px solid #eef2f7;
            vertical-align: middle;
            background: #fff;
        }
        table.ut-matrix-table tbody tr { transition: background .12s; }
        table.ut-matrix-table tbody tr:hover td { background: #f8faff; }
        table.ut-matrix-table tr:last-child td { border-bottom: none; }
        table.ut-matrix-table tr:last-child td:first-child { border-bottom-left-radius: 11px; }
        table.ut-matrix-table tr:last-child td:last-child { border-bottom-right-radius: 11px; }
        .ute-rownum {
            display: inline-flex; align-items: center; justify-content: center;
            width: 28px; height: 28px; border-radius: 9px;
            background: #eef2f7; color: #475569; font-weight: 800; font-size: 12px;
            font-variant-numeric: tabular-nums;
        }
        tr:hover .ute-rownum { background: var(--brand); color: #fff; }
        .ute-subj-name { font-size: 14px; font-weight: 700; color: var(--text); display: block; }
        .ut-obt-inp, .ut-max-inp {
            width: 84px;
            height: 40px;
            padding: 6px 10px;
            font-size: 14.5px;
            font-weight: 800;
            text-align: center;
            border: 1.5px solid #dbe2ec;
            border-radius: 10px;
            background: #fbfcfe;
            box-sizing: border-box;
            transition: all 0.15s ease;
            font-variant-numeric: tabular-nums;
        }
        .ut-obt-inp:hover, .ut-max-inp:hover { border-color: #b9c4d4; }
        .ut-max-inp { background: #f1f5f9; color: #334155; }
        .ut-obt-inp:focus {
            outline: none;
            border-color: var(--brand);
            box-shadow: 0 0 0 3px rgba(122, 34, 56, 0.15);
            background: #fffdf5;
        }
        .ut-max-inp:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(122,34,56,.12); background: #fff; }
        .ut-obt-inp.rm-over {
            border-color: #dc2626 !important;
            background: #fef2f2 !important;
            color: #dc2626 !important;
            box-shadow: 0 0 0 3px rgba(220, 38, 38, 0.18) !important;
        }
        .ut-obt-inp:disabled { background: #f1f5f9; color: #94a3b8; cursor: not-allowed; }
        .badge-over-text {
            color: #fff;
            background: #dc2626;
            font-weight: 800;
            font-size: 11px;
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 4px 10px;
            border-radius: 999px;
            white-space: nowrap;
        }
        .badge-absent-text {
            color: #64748b;
            font-weight: 800;
            font-size: 11px;
            background: #f1f5f9;
            border: 1px solid #e2e8f0;
            padding: 4px 10px;
            border-radius: 999px;
            white-space: nowrap;
        }
        .ute-ok-ico { color: #10b981; font-size: .95rem; }
        .ute-ab-toggle {
            cursor: pointer; display: inline-flex; align-items: center; gap: 6px;
            background: #f8fafc; border: 1.5px solid #e2e8f0; border-radius: 9px; padding: 7px 12px;
            transition: all .15s;
        }
        .ute-ab-toggle:hover { border-color: #94a3b8; }
        .ute-ab-toggle input { accent-color: #64748b; width: 15px; height: 15px; }
        .ute-ab-toggle span { font-size: 11px; font-weight: 800; color: #64748b; letter-spacing: .04em; }
        .ute-ab-toggle:has(input:checked) { background: #f1f5f9; border-color: #64748b; }
        .ute-ab-toggle:has(input:checked) span { color: #334155; }

        /* ── Live Performance Summary ── */
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
        .summary-chip::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: linear-gradient(90deg, var(--brand), #d9a441); }
        .summary-chip .sc-ico { display: inline-flex; align-items: center; justify-content: center; width: 30px; height: 30px; border-radius: 9px; background: #f1f5f9; color: var(--brand); font-size: .78rem; margin-bottom: 7px; }
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
            color: var(--text);
            font-variant-numeric: tabular-nums;
        }
        .ut-summary-grade {
            font-weight: 800;
            font-size: 16px;
            padding: 3px 14px;
            border-radius: 999px;
            display: inline-block;
            border: 1px solid transparent;
        }
        .grade-a1 { background: #dcfce7; color: #15803d; border-color: #bbf7d0; }
        .grade-a2 { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
        .grade-b1 { background: #e0f2fe; color: #0369a1; border-color: #bae6fd; }
        .grade-b2 { background: #f0fdf4; color: #15803d; border-color: #bbf7d0; }
        .grade-c1 { background: #fef3c7; color: #b45309; border-color: #fde68a; }
        .grade-c2 { background: #ffedd5; color: #c2410c; border-color: #fed7aa; }
        .grade-d  { background: #fee2e2; color: #b91c1c; border-color: #fecaca; }
        .grade-e  { background: #fef2f2; color: #dc2626; border-color: #fecaca; }
        .grade-dash { color: #94a3b8; background: #f1f5f9; border-color: #e2e8f0; }

        .result-badge {
            display: inline-block;
            padding: 4px 14px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 800;
            letter-spacing: 0.5px;
            border: 1px solid transparent;
        }
        .badge-pass   { background: #dcfce7; color: #166534; border-color: #bbf7d0; }
        .badge-fail   { background: #fee2e2; color: #991b1b; border-color: #fecaca; }
        .badge-absent { background: #f1f5f9; color: #64748b; border-color: #e2e8f0; }

        /* ── Sticky Action Bar ── */
        .form-action-footer {
            position: sticky;
            bottom: 0;
            z-index: 20;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            margin: 22px -22px -22px;
            padding: 14px 22px;
            background: rgba(255,255,255,.92);
            backdrop-filter: blur(8px);
            border-top: 1px solid var(--border);
            flex-wrap: wrap;
        }
        .ute-save-hint { font-size: .75rem; color: var(--text-3); font-weight: 600; display: flex; align-items: center; gap: 7px; }
        .ute-save-hint i { color: #16a34a; }
        .ute-save-group { display: flex; align-items: center; gap: 10px; flex-wrap: wrap; }
        .btn { transition: transform .14s ease, box-shadow .14s ease; }
        .btn-primary { box-shadow: 0 8px 18px -8px rgba(122,34,56,.6); }
        .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 12px 24px -8px rgba(122,34,56,.65); }

        /* ── Professional alerts ── */
        .ute-alert {
            display: flex; align-items: flex-start; gap: 12px;
            border-radius: 13px; padding: 13px 16px; margin-bottom: 18px;
            font-size: .86rem; font-weight: 600; border: 1px solid;
            box-shadow: var(--ute-shadow);
        }
        .ute-alert .ute-alert-ico { width: 34px; height: 34px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; font-size: .85rem; flex-shrink: 0; }
        .ute-alert--danger { background: #fef2f2; border-color: #fecaca; color: #991b1b; }
        .ute-alert--danger .ute-alert-ico { background: #dc2626; color: #fff; }
        .ute-alert--success { background: #f0fdf4; border-color: #bbf7d0; color: #166534; }
        .ute-alert--success .ute-alert-ico { background: #16a34a; color: #fff; }

        /* ── Modals ── */
        .confirm-modal[hidden], .modal[hidden] { display: none !important; }
        .confirm-modal, .modal { position: fixed; inset: 0; z-index: 2000; display: flex; align-items: center; justify-content: center; padding: 20px; }
        .confirm-backdrop, .modal-backdrop { position: absolute; inset: 0; background: rgba(15, 23, 42, 0.55); backdrop-filter: blur(3px); }
        .confirm-card { position: relative; background: #fff; border-radius: 18px; max-width: 440px; width: 100%; padding: 28px 26px; text-align: center; box-shadow: 0 24px 60px rgba(15,23,42,0.35); border-top: 4px solid #dc2626; }
        .modal-panel { position: relative; width: 100%; max-width: 640px; max-height: 90vh; background: #ffffff; border-radius: 18px; box-shadow: var(--shadow-lg); display: flex; flex-direction: column; overflow: hidden; }
        .modal-header { display: flex; align-items: center; justify-content: space-between; padding: 16px 20px; border-bottom: 1px solid var(--border); background: linear-gradient(180deg, #fafbfc, #f4f6f9); }
        .modal-body { padding: 20px; overflow-y: auto; flex: 1; }
        .modal-close { border: none; background: #f1f5f9; width: 32px; height: 32px; border-radius: 9px; font-size: 16px; cursor: pointer; color: var(--text-3); transition: all .15s; }
        .modal-close:hover { background: #fee2e2; color: #dc2626; }

        /* Manage Subjects Grid */
        .ms-head, .ms-row { display: grid; grid-template-columns: 2fr 1fr 40px; gap: 8px; align-items: center; margin-bottom: 8px; }
        .ms-head span { font-size: 0.72rem; font-weight: 800; color: #64748b; text-transform: uppercase; letter-spacing: .05em; }
        .ms-row input { width: 100%; height: 40px; padding: 8px 11px; border: 1.5px solid #e2e8f0; border-radius: 9px; font-size: 0.9rem; font-weight: 600; background: #fbfcfe; }
        .ms-row input:focus { outline: none; border-color: var(--brand); box-shadow: 0 0 0 3px rgba(122,34,56,.1); background: #fff; }
        .btn-rem-subj { width: 36px; height: 40px; border: none; background: #fee2e2; color: #dc2626; border-radius: 9px; cursor: pointer; display: flex; align-items: center; justify-content: center; transition: all .15s; }
        .btn-rem-subj:hover { background: #dc2626; color: #fff; }
        .btn-add-subj { padding: 9px 18px; font-size: 0.85rem; background: #fff5f5; color: #991b1b; border: 1.5px dashed #d97777; border-radius: 10px; cursor: pointer; font-weight: 700; display: inline-flex; align-items: center; gap: 6px; transition: all .15s; }
        .btn-add-subj:hover { background: #991b1b; color: #fff; border-style: solid; }

        @media (max-width: 768px) {
            .ute-hero { padding: 18px; }
            .ute-hero h2 { font-size: 1.15rem; }
            .ute-hero-side { align-items: flex-start; }
            .ute-hero-actions { justify-content: flex-start; }
            .form-action-footer { margin: 18px -16px -16px; padding: 12px 16px; }
            .entry-card-pad { padding: 16px; }
            .card-header-flex { padding: 13px 16px; }
        }

        /* Notification Dropdown (shared header component) */
        .notif-container { position: relative; display: inline-block; }
        .notif-trigger { background: var(--paper); border: 1px solid var(--border); border-radius: var(--radius-sm); width: 40px; height: 40px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text-2); font-size: 0.95rem; position: relative; transition: background 0.18s ease, border-color 0.18s ease, color 0.18s ease; }
        .notif-trigger:hover { background: var(--brand-soft); border-color: var(--brand); color: var(--brand); }
        .notif-badge { position: absolute; top: -6px; right: -6px; background: var(--danger); color: #fff; font-size: 0.65rem; font-weight: 700; border-radius: 999px; padding: 2px 6px; border: 2px solid var(--paper); min-width: 18px; text-align: center; font-variant-numeric: tabular-nums; }
        .notif-dropdown { display: none; position: absolute; right: 0; top: 48px; width: 360px; max-width: calc(100vw - 32px); background: var(--paper); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-lg); z-index: 1000; overflow: hidden; }
        .notif-dropdown.show { display: block; }
        .notif-dropdown-header { padding: 12px 16px; border-bottom: 1px solid var(--border); background: var(--surface); display: flex; align-items: center; justify-content: space-between; font-size: 0.85rem; font-weight: 700; color: var(--ink); }
        .notif-dropdown-header > span:first-child i { color: var(--gold-ink); margin-right: 6px; }
        .notif-count { font-size: 0.75rem; font-weight: 600; color: var(--text-3); }
        .notif-dropdown-list { max-height: 380px; overflow-y: auto; }
        .notif-empty { padding: 26px 20px; text-align: center; color: var(--text-3); font-size: 0.84rem; }
        .notif-empty i { font-size: 1.5rem; display: block; margin-bottom: 8px; opacity: 0.5; }
        .notif-item { padding: 12px 16px; border-bottom: 1px solid var(--border); display: flex; gap: 12px; font-size: 0.82rem; line-height: 1.45; text-decoration: none; color: inherit; transition: background 0.15s ease; }
        .notif-item:hover { background: var(--surface); }
        .notif-item:last-child { border-bottom: none; }
        .notif-item-icon { width: 32px; height: 32px; flex: 0 0 32px; border-radius: 8px; background: var(--gold-glow); color: var(--gold-ink); display: flex; align-items: center; justify-content: center; font-size: 0.85rem; }
        .notif-item-body { flex: 1; min-width: 0; }
        .notif-item-title { font-weight: 700; color: var(--ink); margin-bottom: 2px; }
        .notif-item-desc { color: var(--text-2); font-size: 0.78rem; margin-bottom: 4px; }
        .notif-item-time { color: var(--text-3); font-size: 0.72rem; }
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
                <h1 class="page-title"><i class="fa-solid fa-pen-to-square" style="color:var(--brand);margin-right:8px;"></i> <?= $edit ? 'Edit Student Entry' : 'Add New Student Entry' ?></h1>
                <p class="page-sub"><?= sanitize($schoolName) ?> &bull; Unit Test Progress Entry & Management</p>
            </div>
            <div style="display:flex;align-items:center;gap:12px;">
                <!-- Activity Alerts / Notification Bell -->
                <div class="notif-container" id="notifContainer">
                    <button type="button" class="notif-trigger" id="notifTrigger" title="Recent Activity Notifications" aria-label="Notifications">
                        <i class="fas fa-bell"></i>
                        <?php if ($notifData['unread'] > 0): ?>
                            <span class="notif-badge" id="notifBadge"><?= (int)$notifData['unread'] ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notif-dropdown" id="notifDropdown">
                        <div class="notif-dropdown-header">
                            <span><i class="fas fa-bell"></i> Promotion &amp; System Alerts</span>
                            <span class="notif-count"><?= count($notifData['items']) ?> events</span>
                        </div>
                        <div class="notif-dropdown-list">
                            <?php if (empty($notifData['items'])): ?>
                                <div class="notif-empty"><i class="fas fa-bell-slash"></i>No recent notifications.</div>
                            <?php else: ?>
                                <?php foreach ($notifData['items'] as $nt): ?>
                                    <a href="<?= !empty($nt['link']) ? sanitize($nt['link']) : '#' ?>" class="notif-item">
                                        <div class="notif-item-icon"><i class="fas fa-graduation-cap"></i></div>
                                        <div class="notif-item-body">
                                            <div class="notif-item-title"><?= sanitize($nt['title']) ?></div>
                                            <div class="notif-item-desc"><?= sanitize($nt['message']) ?></div>
                                            <div class="notif-item-time"><i class="far fa-clock"></i> <?= date('M d, Y • h:i A', strtotime($nt['created_at'])) ?></div>
                                        </div>
                                    </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <div class="admin-user">
                    <span class="user-avatar"><?= sanitize($adminInitials) ?></span>
                    <span class="user-meta">
                        <strong><?= $adminUser ?></strong>
                        <small>Admin</small>
                    </span>
                </div>
            </div>
        </header>

        <div class="admin-content ute-wrap">

            <!-- Hero band -->
            <section class="ute-hero">
                <div class="ute-hero-inner">
                    <div>
                        <div class="ute-crumb">Unit Tests <i class="fa-solid fa-chevron-right"></i> <?= sanitize($testType) ?> <i class="fa-solid fa-chevron-right"></i> <?= $edit ? 'Edit Entry' : 'New Entry' ?></div>
                        <h2><span class="ute-hero-ico"><i class="fa-solid fa-pen-to-square"></i></span> <?= $edit ? 'Edit Student Entry' : 'Add New Student Entry' ?></h2>
                        <p><?= sanitize($schoolName) ?> &bull; Unit Test progress entry &amp; management</p>
                        <div class="ute-hero-meta">
                            <span class="ute-chip"><i class="fa-solid fa-school"></i> STD <?= sanitize($romanStd) ?>-<?= sanitize($division) ?></span>
                            <span class="ute-chip"><i class="fa-solid fa-file-lines"></i> <?= sanitize($testType) ?></span>
                            <span class="ute-chip"><i class="fa-solid fa-calendar-days"></i> <?= sanitize($year) ?></span>
                            <?php if ($edit && $report): ?>
                            <span class="ute-chip ute-chip--gold"><i class="fa-solid fa-user-pen"></i> Roll <?= sanitize($targetRoll) ?> &bull; <?= sanitize($report['student_name']) ?></span>
                            <?php else: ?>
                            <span class="ute-chip ute-chip--gold"><i class="fa-solid fa-user-plus"></i> New &bull; Suggested Roll <?= sanitize($nextAvailableRoll) ?></span>
                            <?php endif; ?>
                        </div>
                    </div>
                    <div class="ute-hero-side">
                        <div class="ute-enrolled"><strong><?= count($roster) ?></strong><span>Students Enrolled</span></div>
                        <div class="ute-hero-actions">
                            <a href="unit_tests.php?year=<?= urlencode($year) ?>&std=<?= urlencode($std) ?>&division=<?= urlencode($division) ?>&test_type=<?= urlencode($testType) ?>" class="ute-btn-light"><i class="fa-solid fa-table"></i> Matrix</a>
                            <a href="unit_test_entry.php?year=<?= urlencode($year) ?>&std=<?= urlencode($std) ?>&division=<?= urlencode($division) ?>&test_type=<?= urlencode($testType) ?>&mode=new" class="ute-btn-gold"><i class="fa-solid fa-user-plus"></i> Add Student</a>
                        </div>
                    </div>
                </div>
            </section>

            <!-- Flash Alerts -->
            <?php if (!empty($error)): ?>
            <div class="ute-alert ute-alert--danger"><span class="ute-alert-ico"><i class="fas fa-exclamation-circle"></i></span><div><?= sanitize($error) ?></div></div>
            <?php endif; ?>
            <?php if (!empty($success)): ?>
            <div class="ute-alert ute-alert--success"><span class="ute-alert-ico"><i class="fas fa-check-circle"></i></span><div><?= sanitize($success) ?></div></div>
            <?php endif; ?>

            <!-- 1. TOP CLASS & TEST TYPE SELECTOR BAR -->
            <div class="entry-top-bar">
                <form method="GET" class="class-picker-wrap" id="classFilterForm">
                    <label class="tp-field">
                        <span><i class="fa-solid fa-calendar-days"></i> Year</span>
                        <select name="year" class="form-control" onchange="this.form.submit()">
                            <?php foreach ($yearOptions as $yOpt): ?>
                                <option value="<?= sanitize($yOpt) ?>" <?= $year === $yOpt ? 'selected' : '' ?>><?= sanitize($yOpt) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>

                    <label class="tp-field">
                        <span><i class="fa-solid fa-graduation-cap"></i> Standard</span>
                        <select name="std" class="form-control" onchange="this.form.submit()">
                            <?php for ($i=1; $i<=10; $i++):
                                $r = $romanMap[(string)$i] ?? $i;
                            ?>
                            <option value="<?= $i ?>" <?= (string)$std === (string)$i ? 'selected' : '' ?>>STD <?= $r ?></option>
                            <?php endfor; ?>
                        </select>
                    </label>

                    <label class="tp-field">
                        <span><i class="fa-solid fa-users"></i> Division</span>
                        <select name="division" class="form-control" onchange="this.form.submit()">
                            <option value="A" <?= $division === 'A' ? 'selected' : '' ?>>Div A</option>
                            <option value="B" <?= $division === 'B' ? 'selected' : '' ?>>Div B</option>
                            <option value="C" <?= $division === 'C' ? 'selected' : '' ?>>Div C</option>
                        </select>
                    </label>

                    <label class="tp-field">
                        <span><i class="fa-solid fa-file-lines"></i> Exam</span>
                        <select name="test_type" class="form-control" onchange="this.form.submit()">
                            <option value="Unit Test 1" <?= $testType === 'Unit Test 1' ? 'selected' : '' ?>>Unit Test 1</option>
                            <option value="Unit Test 2" <?= $testType === 'Unit Test 2' ? 'selected' : '' ?>>Unit Test 2</option>
                            <option value="Unit Test 3" <?= $testType === 'Unit Test 3' ? 'selected' : '' ?>>Unit Test 3</option>
                            <option value="Unit Test 4" <?= $testType === 'Unit Test 4' ? 'selected' : '' ?>>Unit Test 4</option>
                        </select>
                    </label>

                    <span class="tp-sep" aria-hidden="true"></span>

                    <span class="class-badge-pill">
                        <i class="fa-solid fa-school"></i> <?= sanitize($currentClassLabel) ?>
                    </span>

                    <span class="tp-count">
                        <strong><?= count($roster) ?></strong> students enrolled
                    </span>
                </form>

                <div class="tp-actions">
                    <button type="button" class="btn btn-outline" onclick="document.getElementById('manageSubjectsModal').hidden = false">
                        <i class="fa-solid fa-sliders"></i> Test Subjects
                    </button>
                    <a href="unit_tests.php?year=<?= urlencode($year) ?>&std=<?= urlencode($std) ?>&division=<?= urlencode($division) ?>&test_type=<?= urlencode($testType) ?>" class="btn btn-outline">
                        <i class="fa-solid fa-table"></i> Open Unit Test Matrix
                    </a>
                    <a href="unit_test_entry.php?year=<?= urlencode($year) ?>&std=<?= urlencode($std) ?>&division=<?= urlencode($division) ?>&test_type=<?= urlencode($testType) ?>&mode=new" class="btn btn-primary">
                        <i class="fa-solid fa-user-plus"></i> Add New Student
                    </a>
                </div>
            </div>

            <!-- 2. STUDENT NAVIGATION & SWITCHER STRIP -->
            <?php
            $uteCurrentName = ($edit && $report) ? $report['student_name'] : 'New Student';
            $utePosText = ($currentRollIndex >= 0) ? ($currentRollIndex + 1) . ' of ' . count($roster) : '—';
            ?>
            <div class="student-nav-strip <?= $edit ? 'is-editing' : 'is-new' ?>">
                <div class="sns-current">
                    <span class="sns-dot" aria-hidden="true"></span>
                    <?php if ($edit && $report): ?>
                        <span class="sns-title" title="<?= e_attr($report['student_name']) ?>">Roll <?= sanitize($report['roll_no']) ?> &mdash; <?= sanitize($report['student_name']) ?></span>
                        <span class="sns-sub"><?= sanitize($utePosText) ?></span>
                    <?php else: ?>
                        <span class="sns-title">New Student</span>
                        <span class="sns-sub">Roll #<?= sanitize($nextAvailableRoll) ?> &middot; <?= count($roster) ?> enrolled</span>
                    <?php endif; ?>
                </div>

                <?php if (!empty($roster)): ?>
                <div class="sns-controls">
                    <select id="studentPicker" class="sns-select" aria-label="Switch student"
                        onchange="location.href='unit_test_entry.php?year=<?= urlencode($year) ?>&std=<?= urlencode($std) ?>&division=<?= urlencode($division) ?>&test_type=<?= urlencode($testType) ?>&roll=' + encodeURIComponent(this.value)">
                        <?php if ($mode === 'new' || !$edit): ?>
                            <option value="" selected>&plus; Add New Student</option>
                        <?php endif; ?>
                        <?php foreach ($roster as $st):
                            $isSel = ($edit && (string)$st['roll_no'] === (string)$targetRoll) ? 'selected' : '';
                        ?>
                        <option value="<?= e_attr($st['roll_no']) ?>" <?= $isSel ?>>
                            Roll <?= sanitize($st['roll_no']) ?> &mdash; <?= sanitize($st['student_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>

                    <div class="sns-stepper" role="group" aria-label="Student Navigation">
                        <?php if ($prevStudentRoll !== null): ?>
                        <a href="unit_test_entry.php?year=<?= urlencode($year) ?>&std=<?= urlencode($std) ?>&division=<?= urlencode($division) ?>&test_type=<?= urlencode($testType) ?>&roll=<?= urlencode($prevStudentRoll) ?>" class="sns-step-btn" title="Previous Student [Alt+←]" id="btnPrevStudent">
                            <i class="fa-solid fa-chevron-left"></i> Prev
                        </a>
                        <?php else: ?>
                        <button type="button" class="sns-step-btn is-disabled" disabled>
                            <i class="fa-solid fa-chevron-left"></i> Prev
                        </button>
                        <?php endif; ?>

                        <?php if ($nextStudentRoll !== null): ?>
                        <a href="unit_test_entry.php?year=<?= urlencode($year) ?>&std=<?= urlencode($std) ?>&division=<?= urlencode($division) ?>&test_type=<?= urlencode($testType) ?>&roll=<?= urlencode($nextStudentRoll) ?>" class="sns-step-btn" title="Next Student [Alt+→]" id="btnNextStudent">
                            Next <i class="fa-solid fa-chevron-right"></i>
                        </a>
                        <?php else: ?>
                        <button type="button" class="sns-step-btn is-disabled" disabled>
                            Next <i class="fa-solid fa-chevron-right"></i>
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>

                <div class="sns-actions">
                    <?php if ($edit && $report): ?>
                        <a href="unit_test_slips.php?year=<?= urlencode($year) ?>&std=<?= urlencode($std) ?>&division=<?= urlencode($division) ?>&test_type=<?= urlencode($testType) ?>" target="_blank" class="sns-btn" title="View & Print Parent Slips">
                            <i class="fa-solid fa-receipt"></i> Slips
                        </a>
                        <a href="whatsapp_share.php?doc_type=unit_test&test_type=<?= urlencode($testType) ?>&class=<?= urlencode($cohortClassKey) ?>&selected_rolls=<?= urlencode($targetRoll) ?>" class="sns-btn" title="Share via WhatsApp">
                            <i class="fa-brands fa-whatsapp"></i> WhatsApp
                        </a>
                        <a href="unit_test_entry.php?year=<?= urlencode($year) ?>&std=<?= urlencode($std) ?>&division=<?= urlencode($division) ?>&test_type=<?= urlencode($testType) ?>&mode=new" class="sns-btn" title="Add new student">
                            <i class="fa-solid fa-user-plus"></i> New
                        </a>
                        <button type="button" class="sns-btn sns-btn--delete" onclick="document.getElementById('deleteConfirmModal').hidden = false" title="Delete this student">
                            <i class="fa-solid fa-trash-can"></i> Delete
                        </button>
                    <?php else: ?>
                        <?php if (!empty($roster)): ?>
                        <a href="unit_test_entry.php?year=<?= urlencode($year) ?>&std=<?= urlencode($std) ?>&division=<?= urlencode($division) ?>&test_type=<?= urlencode($testType) ?>&roll=<?= urlencode($roster[0]['roll_no']) ?>" class="sns-btn" title="First student">
                            <i class="fa-solid fa-arrow-left"></i> First Student
                        </a>
                        <?php endif; ?>
                        <a href="unit_tests.php?year=<?= urlencode($year) ?>&std=<?= urlencode($std) ?>&division=<?= urlencode($division) ?>&test_type=<?= urlencode($testType) ?>" class="sns-btn" title="View Matrix">
                            <i class="fa-solid fa-table"></i> Matrix
                        </a>
                    <?php endif; ?>
                </div>
            </div>

            <!-- 3. MAIN STUDENT ENTRY FORM -->
            <form method="POST" id="unitTestEntryForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="academic_year" value="<?= e_attr($year) ?>">
                <input type="hidden" name="std" value="<?= e_attr($std) ?>">
                <input type="hidden" name="division" value="<?= e_attr($division) ?>">
                <input type="hidden" name="test_type" value="<?= e_attr($testType) ?>">

                <div class="entry-grid">

                    <!-- Left: Student Profile Card -->
                    <div class="entry-card">
                        <div class="card-header-flex">
                            <div class="card-title-wrap">
                                <span class="card-ico-tile"><i class="fa-solid fa-id-card"></i></span>
                                <div>
                                    <h3 class="card-title-text">Student Profile</h3>
                                    <span class="card-title-sub">Demographics &amp; contact</span>
                                </div>
                            </div>
                            <?php if ($edit): ?>
                            <span class="ute-mode-badge ute-mode-badge--edit"><span class="dot"></span> Editing Roll <?= sanitize($targetRoll) ?></span>
                            <?php else: ?>
                            <span class="ute-mode-badge ute-mode-badge--new"><span class="dot"></span> New Student</span>
                            <?php endif; ?>
                        </div>
                        <div class="entry-card-pad">

                        <div class="ute-2col">
                        <div class="form-group">
                            <label for="studentRollInput"><i class="fa-solid fa-hashtag"></i> Roll Number *</label>
                            <input type="number" id="studentRollInput" name="roll_no" class="form-control"
                                   value="<?= e_attr($edit ? $report['roll_no'] : $targetRoll) ?>" required min="1">
                        </div>

                        <div class="form-group">
                            <label for="studentGenderSelect"><i class="fa-solid fa-venus-mars"></i> Gender</label>
                            <select id="studentGenderSelect" name="gender" class="form-control">
                                <option value="" <?= ($edit && empty($report['gender'])) ? 'selected' : '' ?>>-- Select --</option>
                                <option value="Boy" <?= ($edit && $report['gender'] === 'Boy') ? 'selected' : '' ?>>Boy</option>
                                <option value="Girl" <?= ($edit && $report['gender'] === 'Girl') ? 'selected' : '' ?>>Girl</option>
                            </select>
                        </div>
                        </div>

                        <div class="form-group">
                            <label for="studentNameInput"><i class="fa-solid fa-user"></i> Student Name *</label>
                            <input type="text" id="studentNameInput" name="student_name" class="form-control"
                                   value="<?= e_attr($edit ? $report['student_name'] : '') ?>"
                                   placeholder="e.g. MOHAMMAD ZAID SHAIKH" required>
                        </div>

                        <div class="form-group">
                            <label for="studentPhoneInput"><i class="fa-brands fa-whatsapp"></i> Parent Mobile (WhatsApp)</label>
                            <input type="tel" id="studentPhoneInput" name="parent_phone" class="form-control"
                                   value="<?= e_attr($edit ? ($report['parent_phone'] ?? '') : '') ?>"
                                   placeholder="10-digit mobile number" maxlength="14" autocomplete="tel">
                        </div>

                        <div class="form-group">
                            <label for="studentAttendanceInput"><i class="fa-solid fa-calendar-check"></i> Attendance</label>
                            <input type="text" id="studentAttendanceInput" name="attendance" class="form-control"
                                   value="<?= e_attr($edit ? $report['attendance'] : '') ?>"
                                   placeholder="e.g. 42 or 18/20">
                        </div>

                        <div class="form-group">
                            <label for="teacherRemarkInput"><i class="fa-solid fa-comment-dots"></i> Teacher's Remark</label>
                            <input type="text" id="teacherRemarkInput" name="remark" class="form-control"
                                   value="<?= e_attr($edit ? $report['remark'] : 'Good') ?>"
                                   placeholder="e.g. Excellent / Good / Needs Improvement">

                            <div class="preset-pills">
                                <span class="ut-preset-pill" data-remark="Excellent">Excellent</span>
                                <span class="ut-preset-pill" data-remark="V.Good">V.Good</span>
                                <span class="ut-preset-pill" data-remark="Good">Good</span>
                                <span class="ut-preset-pill" data-remark="Satisfactory">Satisfactory</span>
                                <span class="ut-preset-pill" data-remark="Fair">Fair</span>
                                <span class="ut-preset-pill" data-remark="Average">Average</span>
                                <span class="ut-preset-pill" data-remark="Needs Improvement">Needs Improvement</span>
                                <span class="ut-preset-pill" data-remark="Absent">Absent</span>
                            </div>
                        </div>

                        <div class="form-group" style="margin-top:16px;margin-bottom:0;">
                            <label class="ute-check-card">
                                <input type="checkbox" name="parents_sign" value="1" <?= ($edit && !empty($report['parents_sign'])) ? 'checked' : '' ?>>
                                <span>Parents Signature Acknowledged</span>
                            </label>
                        </div>
                        </div>
                    </div>

                    <!-- Right: Subject Marks Entry Matrix & Live Summary -->
                    <div class="entry-card">
                        <div class="card-header-flex">
                            <div class="card-title-wrap">
                                <span class="card-ico-tile card-ico-tile--gold"><i class="fa-solid fa-list-check"></i></span>
                                <div>
                                    <h3 class="card-title-text">Subject Marks</h3>
                                    <span class="card-title-sub">Max / Obtained &bull; <?= sanitize($testType) ?></span>
                                </div>
                            </div>
                            <span class="ute-kbd-hint">
                                <kbd>Enter</kbd>/<kbd>↓</kbd> next field
                            </span>
                        </div>
                        <div class="entry-card-pad">

                        <div class="table-responsive">
                            <table class="ut-matrix-table">
                                <thead>
                                    <tr>
                                        <th style="width:52px;">#</th>
                                        <th>Subject Name</th>
                                        <th style="width:120px;text-align:center;">Max Marks</th>
                                        <th style="width:130px;text-align:center;">Obtained</th>
                                        <th style="width:100px;text-align:center;">Absent?</th>
                                        <th style="width:90px;text-align:center;">Status</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $rowIndex = 1;
                                    foreach ($matrixSubjects as $idx => $subj): 
                                        $sName = $subj['subject_name'];
                                        $sMax  = (float)$subj['max_marks'];
                                        $stuSub = ($report && isset($report['subjects'][$sName])) ? $report['subjects'][$sName] : null;

                                        $isAb = $stuSub ? (bool)$stuSub['is_absent'] : false;
                                        $valObt = '';
                                        if ($stuSub) {
                                            $valObt = $isAb ? '-' : $fmtNum($stuSub['marks_obtained']);
                                        }
                                    ?>
                                    <tr class="ut-subj-row" data-subject="<?= e_attr($sName) ?>">
                                        <td><span class="ute-rownum"><?= $rowIndex++ ?></span></td>
                                        <td>
                                            <input type="hidden" name="subject_name[<?= $idx ?>]" value="<?= e_attr($sName) ?>">
                                            <span class="ute-subj-name"><?= sanitize($sName) ?></span>
                                        </td>
                                        <td style="text-align:center;">
                                            <input type="number" name="max_marks[<?= $idx ?>]"
                                                   class="ut-max-inp"
                                                   value="<?= e_attr($fmtNum($sMax)) ?>"
                                                   min="1" max="100" step="0.5">
                                        </td>
                                        <td style="text-align:center;">
                                            <input type="text" name="marks_obtained[<?= $idx ?>]"
                                                   class="ut-obt-inp"
                                                   value="<?= e_attr($valObt) ?>"
                                                   autocomplete="off"
                                                   placeholder="0"
                                                   <?= $isAb ? 'disabled' : '' ?>>
                                        </td>
                                        <td style="text-align:center;">
                                            <label class="ute-ab-toggle">
                                                <input type="checkbox" name="is_absent[<?= $idx ?>]" value="1"
                                                       class="ut-abs-chk" <?= $isAb ? 'checked' : '' ?>>
                                                <span>AB</span>
                                            </label>
                                        </td>
                                        <td style="text-align:center;" class="ut-row-status">
                                            <i class="fas fa-check-circle ute-ok-ico"></i>
                                        </td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>

                        <!-- Live Calculated Performance Metrics -->
                        <div class="live-summary">
                            <div class="summary-chip">
                                <span class="sc-ico"><i class="fa-solid fa-bullseye"></i></span>
                                <span class="sc-label">Total Obtained</span>
                                <strong id="sumTotObt">-</strong>
                            </div>
                            <div class="summary-chip">
                                <span class="sc-ico"><i class="fa-solid fa-flag-checkered"></i></span>
                                <span class="sc-label">Total Max</span>
                                <strong id="sumTotMax">-</strong>
                            </div>
                            <div class="summary-chip">
                                <span class="sc-ico"><i class="fa-solid fa-percent"></i></span>
                                <span class="sc-label">Percentage</span>
                                <strong id="sumPct">-</strong>
                            </div>
                            <div class="summary-chip">
                                <span class="sc-ico"><i class="fa-solid fa-award"></i></span>
                                <span class="sc-label">Official Grade</span>
                                <div><span id="sumGrade" class="ut-summary-grade grade-dash">-</span></div>
                            </div>
                            <div class="summary-chip">
                                <span class="sc-ico"><i class="fa-solid fa-circle-check"></i></span>
                                <span class="sc-label">Result Status</span>
                                <div><span id="sumResult" class="result-badge badge-pass">PASS</span></div>
                            </div>
                        </div>

                        <!-- Dual Action Buttons -->
                        <div class="form-action-footer">
                            <span class="ute-save-hint"><i class="fa-solid fa-shield-halved"></i> Validated &amp; autosaved per student</span>

                            <div class="ute-save-group">
                                <a href="unit_tests.php?year=<?= urlencode($year) ?>&std=<?= urlencode($std) ?>&division=<?= urlencode($division) ?>&test_type=<?= urlencode($testType) ?>" class="btn btn-outline">
                                    <i class="fa-solid fa-arrow-left"></i> Cancel
                                </a>
                                <button type="submit" name="submit_action" value="save" class="btn btn-primary js-btn-save" style="font-weight:700;">
                                    <i class="fa-solid fa-floppy-disk"></i> Save Changes
                                </button>
                                <button type="submit" name="submit_action" value="save_and_add_next" class="btn btn-secondary js-btn-save" style="font-weight:700;">
                                    <i class="fa-solid fa-user-plus"></i> Save &amp; Add Next
                                </button>
                            </div>
                        </div>

                        </div>
                    </div>
                </div>
            </form>

        </div>
    </main>
</div>

<!-- Modal: Manage Unit Test Subjects & Max Marks -->
<div class="modal" id="manageSubjectsModal" role="dialog" aria-modal="true" hidden>
    <div class="modal-backdrop" onclick="document.getElementById('manageSubjectsModal').hidden = true"></div>
    <div class="modal-panel" role="document">
        <div class="modal-header">
            <h3 style="margin:0;font-size:16px;font-weight:800;color:var(--text);"><i class="fa-solid fa-sliders" style="color:var(--brand);margin-right:6px;"></i> Configure Test Subjects (STD <?= sanitize($romanStd) ?> &bull; <?= sanitize($testType) ?>)</h3>
            <button type="button" class="modal-close" onclick="document.getElementById('manageSubjectsModal').hidden = true">&times;</button>
        </div>
        <form method="POST" style="display:flex;flex-direction:column;flex:1;overflow:hidden;">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="save_subjects">
            <input type="hidden" name="academic_year" value="<?= e_attr($year) ?>">
            <input type="hidden" name="std" value="<?= e_attr($std) ?>">
            <input type="hidden" name="division" value="<?= e_attr($division) ?>">
            <input type="hidden" name="test_type" value="<?= e_attr($testType) ?>">
            <input type="hidden" name="current_roll" value="<?= e_attr($targetRoll) ?>">

            <div class="modal-body">
                <p style="font-size:12.5px;color:var(--text-3);margin:0 0 16px 0;">
                    Changes made here establish the default subjects and maximum marks for <strong>STD <?= sanitize($romanStd) ?> (<?= sanitize($testType) ?>)</strong>.
                </p>

                <div class="ms-head">
                    <span>Subject Name</span>
                    <span>Max Marks</span>
                    <span></span>
                </div>

                <div id="unitTestSubjectRows">
                    <?php foreach ($classSubjects as $cs): ?>
                    <div class="ms-row">
                        <input type="text" name="cfg_subject_name[]" value="<?= e_attr($cs['subject_name']) ?>" required>
                        <input type="number" name="cfg_max_marks[]" value="<?= e_attr($fmtNum($cs['max_marks'])) ?>" min="1" max="100" step="1" required>
                        <button type="button" class="btn-rem-subj" title="Remove Subject" onclick="this.closest('.ms-row').remove()"><i class="fas fa-trash"></i></button>
                    </div>
                    <?php endforeach; ?>
                </div>

                <div style="margin-top:14px;">
                    <button type="button" class="btn-add-subj" id="btnAddUnitTestSubject">
                        <i class="fas fa-plus"></i> + Add Subject
                    </button>
                </div>
            </div>

            <div style="padding:16px 20px;border-top:1px solid var(--border);background:var(--surface);display:flex;justify-content:flex-end;gap:10px;">
                <button type="button" class="btn btn-outline" onclick="document.getElementById('manageSubjectsModal').hidden = true">Cancel</button>
                <button type="submit" class="btn btn-primary" style="font-weight:700;"><i class="fas fa-save"></i> Save Configuration</button>
            </div>
        </form>
    </div>
</div>

<!-- Modal: Confirm Delete Student Record -->
<?php if ($edit && $report): ?>
<div class="confirm-modal" id="deleteConfirmModal" role="alertdialog" aria-modal="true" hidden>
    <div class="confirm-backdrop" onclick="document.getElementById('deleteConfirmModal').hidden = true"></div>
    <div class="confirm-card">
        <div style="width:52px;height:52px;margin:0 auto 14px;display:flex;align-items:center;justify-content:center;border-radius:50%;background:#fee2e2;color:#dc2626;font-size:1.3rem;">
            <i class="fas fa-trash"></i>
        </div>
        <h3 style="margin:0 0 8px;font-size:1.15rem;font-weight:800;color:var(--text);">Remove Roll <?= sanitize($report['roll_no']) ?>?</h3>
        <p style="margin:0 0 20px;font-size:0.88rem;color:var(--text-3);line-height:1.55;">
            Are you sure you want to remove <strong><?= sanitize($report['student_name']) ?></strong> from <?= sanitize($testType) ?>? This action cannot be undone.
        </p>
        <div style="display:flex;gap:10px;justify-content:center;">
            <button type="button" class="btn btn-outline" onclick="document.getElementById('deleteConfirmModal').hidden = true">Cancel</button>
            <form method="POST" style="margin:0;">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="delete_student">
                <input type="hidden" name="academic_year" value="<?= e_attr($year) ?>">
                <input type="hidden" name="std" value="<?= e_attr($std) ?>">
                <input type="hidden" name="division" value="<?= e_attr($division) ?>">
                <input type="hidden" name="test_type" value="<?= e_attr($testType) ?>">
                <input type="hidden" name="roll_no" value="<?= e_attr($report['roll_no']) ?>">
                <button type="submit" class="btn btn-danger" style="font-weight:700;"><i class="fas fa-trash"></i> Delete Student</button>
            </form>
        </div>
    </div>
</div>
<?php endif; ?>


<script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= (is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 0) ?>"></script>
<script src="../assets/js/unit-test-entry.js?v=<?= filemtime(__DIR__ . '/../assets/js/unit-test-entry.js') ?>"></script>
</body>
</html>
