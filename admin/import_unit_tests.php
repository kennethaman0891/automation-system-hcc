<?php
@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '300');

$adminPage = 'import_unit_tests';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$db = getDB();
rcEnsureUnitTestTables();
$settings = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church English Medium School, Solapur';
$adminUser = sanitize($_SESSION['admin_name'] ?? 'Admin');
$adminInitials = strtoupper(implode('', array_map(
    function ($part) { return mb_substr($part, 0, 1); },
    array_slice(array_filter(preg_split('/\s+/', trim($_SESSION['admin_name'] ?? 'A'))), 0, 2)
)));

/* ── Upload Limits ── */
const UT_MAX_ROWS = 3000;
const UT_MAX_COLS = 50;

/* ── 1. Text Normalization & Roman Numeral Helpers ── */
function utCleanText($v): string {
    if ($v === null) return '';
    $s = trim((string)$v);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s) ?? '';
    return trim($s);
}

function utRomanToDecimal(string $roman): string {
    $r = strtoupper(trim($roman));
    $map = [
        'X' => '10', 'IX' => '9', 'VIII' => '8', 'VII' => '7',
        'VI' => '6', 'V' => '5', 'IV' => '4', 'III' => '3',
        'II' => '2', 'I' => '1'
    ];
    return $map[$r] ?? $r;
}

function utDetectMetaFromText(string $text, string $sheetName = ''): array {
    $meta = [
        'academic_year' => '2025-26',
        'std'           => '',
        'division'      => 'A',
        'test_type'     => 'Unit Test 2',
    ];

    // 1. Detect Standard & Division: e.g. "STD:- I A", "STD : 1 A", "STD - II B"
    if (preg_match('/STD\s*[:-]?\s*(X|IX|VIII|VII|VI|V|IV|III|II|I|\d+)\s*([A-Za-z])?/i', $text, $m)) {
        $meta['std'] = utRomanToDecimal($m[1]);
        if (!empty($m[2])) $meta['division'] = strtoupper(trim($m[2]));
    } elseif (preg_match('/(X|IX|VIII|VII|VI|V|IV|III|II|I|\d+)\s*([A-Za-z])/i', $sheetName, $sm)) {
        $meta['std'] = utRomanToDecimal($sm[1]);
        if (!empty($sm[2])) $meta['division'] = strtoupper(trim($sm[2]));
    }

    // 2. Detect Unit Test: e.g. "UNIT TEST :- II", "UNIT EXAM II", "UNIT TEST 1"
    if (preg_match('/UNIT\s*(?:TEST|EXAM)?\s*[:-]?\s*(IV|III|II|I|\d+)/i', $text, $tm)) {
        $num = utRomanToDecimal($tm[1]);
        $meta['test_type'] = "Unit Test $num";
    }

    // 3. Detect Academic Year: e.g. "YEAR :- 2025-26", "2024-25"
    if (preg_match('/(?:YEAR\s*[:-]?\s*)?(\d{4}[-\/]\d{2,4})/i', $text, $ym)) {
        $meta['academic_year'] = str_replace('/', '-', $ym[1]);
    }

    return $meta;
}

/* ── 2. Specialized Unit Test Worksheet Parser ── */
function parseUnitTestWorksheet($sheet, string $sheetName): ?array {
    $highestRow = min((int)$sheet->getHighestRow(), UT_MAX_ROWS);
    $highestColLetter = $sheet->getHighestColumn();
    $highestCol = min(Coordinate::columnIndexFromString($highestColLetter), UT_MAX_COLS);

    // Read sheet cells into memory
    $rows = [];
    for ($r = 1; $r <= $highestRow; $r++) {
        $rowCells = [];
        for ($c = 1; $c <= $highestCol; $c++) {
            $val = $sheet->getCell([$c, $r])->getValue();
            if ($val !== null && is_string($val) && strpos($val, '=') === 0) {
                try { $val = $sheet->getCell([$c, $r])->getCalculatedValue(); } catch (\Throwable $e) {}
            }
            $rowCells[$c] = utCleanText($val);
        }
        $rows[$r] = $rowCells;
    }

    // A. Detect Meta from top rows (1..4)
    $topText = "";
    for ($r = 1; $r <= min(5, $highestRow); $r++) {
        if (isset($rows[$r])) {
            $topText .= " " . implode(" ", $rows[$r]);
        }
    }
    $meta = utDetectMetaFromText($topText, $sheetName);

    // B. Detect Header Row ("Roll No" / "Name of the Students")
    $headerRowIdx = null;
    foreach ($rows as $rIdx => $row) {
        foreach ($row as $val) {
            $cleanVal = preg_replace('/[^a-z]/', '', strtolower($val));
            if (in_array($cleanVal, ['rollno', 'roll', 'srno', 'sno', 'nameofstudents', 'nameofthestudents', 'studentname'])) {
                $headerRowIdx = $rIdx;
                break 2;
            }
        }
    }
    if (!$headerRowIdx) return null;

    $headerRow = $rows[$headerRowIdx] ?? [];
    $subHeaderRow = $rows[$headerRowIdx + 1] ?? [];

    // Identify Columns
    $rollCol = null;
    $nameCol = null;
    $totalCol = null;
    $pctCol = null;
    $gradeCol = null;
    $remarkCol = null;
    $attnCol = null;
    $signCol = null;
    $subjectCols = []; // colIndex => subjectName

    foreach ($headerRow as $cIdx => $val) {
        $clean = preg_replace('/[^a-z0-9]/', '', strtolower($val));
        if (in_array($clean, ['rollno', 'roll', 'srno', 'sno', 'rno'])) {
            $rollCol = $cIdx;
        } elseif (in_array($clean, ['nameofthestudents', 'nameofstudents', 'studentname', 'name'])) {
            $nameCol = $cIdx;
        } elseif (in_array($clean, ['total', 'tot'])) {
            $totalCol = $cIdx;
        } elseif (in_array($clean, ['pct', 'percentage', 'per']) || $val === '%') {
            $pctCol = $cIdx;
        } elseif (in_array($clean, ['grade', 'grd'])) {
            $gradeCol = $cIdx;
        } elseif (in_array($clean, ['remark', 'remarks'])) {
            $remarkCol = $cIdx;
        } elseif (in_array($clean, ['attn', 'attendance'])) {
            $attnCol = $cIdx;
        } elseif (in_array($clean, ['parentssign', 'parentsign', 'sign'])) {
            $signCol = $cIdx;
        }
    }

    if (!$rollCol || !$nameCol) return null;

    // Detect subjects: all non-empty columns between nameCol and totalCol
    $endCol = $totalCol ? $totalCol : $highestCol;
    for ($cIdx = $nameCol + 1; $cIdx < $endCol; $cIdx++) {
        $hName = trim($headerRow[$cIdx] ?? '');
        if ($hName !== '' && !in_array(strtolower($hName), ['total', '%', 'grade', 'remark', 'attn', 'parents sign'])) {
            $subjectCols[$cIdx] = $hName;
        }
    }
    if (empty($subjectCols)) return null;

    // Extract Max Marks for each subject from sub-header row (e.g. 20)
    $subjectMaxMap = [];
    foreach ($subjectCols as $cIdx => $sName) {
        $maxVal = (float)($subHeaderRow[$cIdx] ?? 20.0);
        if ($maxVal <= 0 || $maxVal > 100) $maxVal = 20.0;
        $subjectMaxMap[$sName] = $maxVal;
    }

    // Determine data starting row (after subHeader row if numeric, else after header row)
    $startRow = $headerRowIdx + 1;
    $subHeaderHasNumeric = false;
    foreach ($subjectCols as $cIdx => $sName) {
        if (is_numeric($subHeaderRow[$cIdx] ?? '')) {
            $subHeaderHasNumeric = true;
            break;
        }
    }
    if ($subHeaderHasNumeric) {
        $startRow = $headerRowIdx + 2;
    }

    // C. Parse Student Rows
    $students = [];
    $currentGender = '';

    for ($rIdx = $startRow; $rIdx <= $highestRow; $rIdx++) {
        $row = $rows[$rIdx] ?? [];
        $firstFew = strtoupper(implode(' ', array_slice($row, 0, 5)));

        // Gender section detection
        if (stripos($firstFew, 'GIRLS') !== false) {
            $currentGender = 'Girl';
            continue;
        }
        if (stripos($firstFew, 'BOYS') !== false) {
            $currentGender = 'Boy';
            continue;
        }

        $rollVal = trim($row[$rollCol] ?? '');
        $nameVal = trim($row[$nameCol] ?? '');

        // Valid roll number check
        if ($rollVal === '' || !preg_match('/^[0-9]+[a-z]?$/i', $rollVal)) {
            continue;
        }
        if ($nameVal === '') continue;

        // Clean student name
        $nameVal = preg_replace('/\s+/', ' ', $nameVal);

        // Subject marks
        $marks = [];
        $totObt = 0.0;
        $totMax = 0.0;
        $allAb = true;

        foreach ($subjectCols as $cIdx => $sName) {
            $rawMark = trim($row[$cIdx] ?? '');
            $maxM = $subjectMaxMap[$sName] ?? 20.0;
            $totMax += $maxM;

            $isAb = ($rawMark === '-' || strtoupper($rawMark) === 'AB' || $rawMark === '');
            if ($isAb && $rawMark === '') {
                // If completely blank, treat as absent or 0
                $obtVal = null;
                $isAb = true;
            } elseif ($isAb) {
                $obtVal = null;
                $isAb = true;
            } else {
                $isAb = false;
                $allAb = false;
                $obtVal = round((float)$rawMark, 1);
                $totObt += $obtVal;
            }

            $marks[$sName] = [
                'max_marks'      => $maxM,
                'marks_obtained' => $obtVal,
                'is_absent'      => $isAb ? 1 : 0,
            ];
        }

        // Attendance & Custom Remark
        $attnVal = $attnCol ? trim($row[$attnCol] ?? '') : '';
        $customRemark = $remarkCol ? trim($row[$remarkCol] ?? '') : '';

        // Calculate Percentage, Grade & Remark
        $pct = (!$allAb && $totMax > 0) ? round(($totObt / $totMax) * 100, 2) : null;
        $autoGr = rcCalculateUnitTestGradeAndRemark($pct);
        $grade = $autoGr['grade'];
        $remark = ($customRemark !== '') ? $customRemark : $autoGr['remark'];

        $students[] = [
            'roll_no'        => $rollVal,
            'student_name'   => $nameVal,
            'gender'         => $currentGender,
            'marks'          => $marks,
            'attendance'     => $attnVal,
            'total_obtained' => $totObt,
            'total_max'      => $totMax,
            'percentage'     => $pct,
            'grade'          => $grade,
            'remark'         => $remark,
        ];
    }

    if (empty($students)) return null;

    return [
        'sheet_name' => $sheetName,
        'meta'       => $meta,
        'subjects'   => $subjectMaxMap,
        'students'   => $students,
    ];
}

/* ── 3. Multi-Sheet Scanner for Master Workbooks ── */
function scanUnitTestWorkbook(string $filePath, string $ext): array {
    $reader = $ext === 'xlsx' ? new \PhpOffice\PhpSpreadsheet\Reader\Xlsx() : new \PhpOffice\PhpSpreadsheet\Reader\Xls();
    $reader->setReadDataOnly(true);
    $sheetNames = $reader->listWorksheetNames($filePath);

    $active = [];
    foreach ($sheetNames as $sn) {
        if (strpos($sn, 'Sheet') === 0 && preg_match('/^Sheet[0-9]{2,}$/', $sn)) continue;
        try {
            $reader->setLoadSheetsOnly([$sn]);
            $ss = $reader->load($filePath);
            $parsed = parseUnitTestWorksheet($ss->getActiveSheet(), $sn);
            $ss->disconnectWorksheets();
            unset($ss);

            if ($parsed && count($parsed['students']) > 0) {
                $active[$sn] = [
                    'sheet_name'     => $sn,
                    'class_label'    => 'STD ' . ($parsed['meta']['std'] ?: '?') . ' ' . ($parsed['meta']['division'] ?: ''),
                    'std'            => $parsed['meta']['std'],
                    'division'       => $parsed['meta']['division'],
                    'test_type'      => $parsed['meta']['test_type'],
                    'academic_year'  => $parsed['meta']['academic_year'],
                    'students_count' => count($parsed['students']),
                    'subjects_count' => count($parsed['subjects']),
                    'sub_list'       => array_keys($parsed['subjects']),
                ];
            }
        } catch (\Throwable $e) {}
    }
    return $active;
}

/* ─────────────────────────────────────────────────────────────────────────────
   REQUEST HANDLING (GET & POST)
   ───────────────────────────────────────────────────────────────────────────── */
$error = '';
$success = '';
$preview = null;
$multiSheets = [];
$isMulti = false;
$importResult = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        $error = 'Security check failed. Please refresh the page.';
    } else {
        $action = trim($_POST['action'] ?? '');

        /* ── Action: Parse File ── */
        if ($action === 'parse') {
            $file = $_FILES['excel_file'] ?? null;
            if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
                $error = 'No file uploaded. Please choose an Excel file.';
            } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                $error = 'File upload failed with error code: ' . (int)$file['error'];
            } else {
                $origName = $file['name'] ?? 'unit_test.xlsx';
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

                if (!in_array($ext, ['xlsx', 'xls'])) {
                    $error = 'Invalid file format. Please upload an Excel (.xlsx or .xls) file.';
                } else {
                    $uploadDir = sys_get_temp_dir() . '/hcc_ut_imports';
                    if (!is_dir($uploadDir)) @mkdir($uploadDir, 0755, true);
                    $destPath = $uploadDir . '/ut_' . bin2hex(random_bytes(8)) . '.' . $ext;

                    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                        $error = 'Failed to process uploaded file.';
                    } else {
                        // Scan workbook for active sheets
                        $multiSheets = scanUnitTestWorkbook($destPath, $ext);

                        if (empty($multiSheets)) {
                            $error = 'Could not detect any valid Unit Test marksheets in this Excel file. Please verify sheet headers.';
                            @unlink($destPath);
                        } else {
                            $_SESSION['ut_file_path'] = $destPath;
                            $_SESSION['ut_file_ext'] = $ext;
                            $_SESSION['ut_filename'] = $origName;
                            $_SESSION['ut_multi_sheets'] = $multiSheets;

                            if (count($multiSheets) > 1) {
                                // Multi-sheet master workbook detected!
                                $isMulti = true;
                                $_SESSION['ut_is_multi'] = true;
                            } else {
                                // Single sheet
                                $isMulti = false;
                                $_SESSION['ut_is_multi'] = false;
                                $firstSheet = array_key_first($multiSheets);
                                $reader = $ext === 'xlsx' ? new \PhpOffice\PhpSpreadsheet\Reader\Xlsx() : new \PhpOffice\PhpSpreadsheet\Reader\Xls();
                                $reader->setLoadSheetsOnly([$firstSheet]);
                                $ss = $reader->load($destPath);
                                $preview = parseUnitTestWorksheet($ss->getActiveSheet(), $firstSheet);
                                $ss->disconnectWorksheets();
                                unset($ss);
                                $_SESSION['ut_preview'] = $preview;
                            }
                        }
                    }
                }
            }
        }

        /* ── Action: Confirm Import (Single or Batch) ── */
        elseif ($action === 'confirm_import') {
            $tmpPath = $_SESSION['ut_file_path'] ?? '';
            $ext = $_SESSION['ut_file_ext'] ?? 'xlsx';
            $multiSheets = $_SESSION['ut_multi_sheets'] ?? [];
            $isBatch = !empty($_POST['is_batch']);
            $overwrite = !empty($_POST['overwrite']);

            if (!$tmpPath || !file_exists($tmpPath)) {
                $error = 'Uploaded file session expired. Please re-upload your Excel file.';
            } else {
                $reader = $ext === 'xlsx' ? new \PhpOffice\PhpSpreadsheet\Reader\Xlsx() : new \PhpOffice\PhpSpreadsheet\Reader\Xls();
                $totalImportedStudents = 0;
                $classesImported = [];
                $firstClassLink = '';

                // Determine which sheets to import
                $sheetsToImport = $isBatch ? array_keys($multiSheets) : [trim($_POST['selected_sheet'] ?? array_key_first($multiSheets))];

                foreach ($sheetsToImport as $sn) {
                    try {
                        $reader->setLoadSheetsOnly([$sn]);
                        $ss = $reader->load($tmpPath);
                        $parsed = parseUnitTestWorksheet($ss->getActiveSheet(), $sn);
                        $ss->disconnectWorksheets();
                        unset($ss);

                        if (!$parsed || empty($parsed['students'])) continue;

                        $cYear = !empty($parsed['meta']['academic_year']) ? $parsed['meta']['academic_year'] : '2025-26';
                        $cStd  = !empty($parsed['meta']['std']) ? $parsed['meta']['std'] : '1';
                        $cDiv  = !empty($parsed['meta']['division']) ? $parsed['meta']['division'] : 'A';
                        $cTest = !empty($parsed['meta']['test_type']) ? $parsed['meta']['test_type'] : 'Unit Test 2';

                        // Save subject configuration
                        $subjConf = [];
                        foreach ($parsed['subjects'] as $sName => $sMax) {
                            $subjConf[] = ['subject_name' => $sName, 'max_marks' => (float)$sMax];
                        }
                        rcSaveUnitTestConfig($cYear, $cStd, $cTest, $subjConf);

                        // If overwrite enabled, clear existing cohort for this class
                        if ($overwrite) {
                            $del = $db->prepare("DELETE FROM unit_test_results WHERE academic_year=? AND std=? AND division=? AND test_type=?");
                            $del->execute([$cYear, $cStd, $cDiv, $cTest]);
                        }

                        // Prepare bulk insert
                        $insStmt = $db->prepare("
                            INSERT INTO unit_test_results 
                            (academic_year, std, division, test_type, roll_no, student_name, gender, subject_name, max_marks, marks_obtained, is_absent, grade, remark, attendance)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                            ON DUPLICATE KEY UPDATE 
                                marks_obtained = VALUES(marks_obtained),
                                is_absent = VALUES(is_absent),
                                grade = VALUES(grade),
                                remark = VALUES(remark),
                                attendance = VALUES(attendance)
                        ");

                        $classStudentCount = 0;
                        foreach ($parsed['students'] as $stu) {
                            $roll = $stu['roll_no'];
                            $name = $stu['student_name'];
                            $gender = $stu['gender'];
                            $attn = $stu['attendance'];
                            $grd = $stu['grade'];
                            $rmk = $stu['remark'];

                            foreach ($stu['marks'] as $sName => $sData) {
                                $maxM = (float)$sData['max_marks'];
                                $obtM = $sData['marks_obtained'];
                                $isAb = (int)$sData['is_absent'];

                                $insStmt->execute([
                                    $cYear, $cStd, $cDiv, $cTest,
                                    $roll, $name, $gender, $sName,
                                    $maxM, $obtM, $isAb, $grd, $rmk, $attn
                                ]);
                            }
                            $classStudentCount++;
                        }

                        $totalImportedStudents += $classStudentCount;
                        $classesImported[] = "STD $cStd $cDiv ($classStudentCount students)";

                        if ($firstClassLink === '') {
                            $firstClassLink = "unit_tests.php?year=" . urlencode($cYear) . "&std=" . urlencode($cStd) . "&division=" . urlencode($cDiv) . "&test_type=" . urlencode($cTest);
                        }
                    } catch (\Throwable $e) {}
                }

                @unlink($tmpPath);
                unset($_SESSION['ut_file_path'], $_SESSION['ut_file_ext'], $_SESSION['ut_multi_sheets'], $_SESSION['ut_preview'], $_SESSION['ut_is_multi']);

                // Fire persistent notification with timestamp
                $notifTitle = "Unit Test Excel Imported";
                $notifMsg = "Imported {$totalImportedStudents} students across " . count($classesImported) . " classes into Unit Test results by {$adminUser}.";
                rcAddNotification($notifTitle, $notifMsg, 'unit_test', $firstClassLink);

                $success = "Successfully imported {$totalImportedStudents} student records across " . count($classesImported) . " classes!";
                $importResult = [
                    'imported'        => $totalImportedStudents,
                    'classes_count'   => count($classesImported),
                    'classes_list'    => $classesImported,
                    'first_link'      => $firstClassLink,
                ];
            }
        }
    }
}

// Session state restore on GET
if (!$preview && isset($_SESSION['ut_preview'])) {
    $preview = $_SESSION['ut_preview'];
}
if (empty($multiSheets) && isset($_SESSION['ut_multi_sheets'])) {
    $multiSheets = $_SESSION['ut_multi_sheets'];
    $isMulti = !empty($_SESSION['ut_is_multi']);
}

$notifData = rcGetRecentNotifications(8);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Unit Test Excel — <?= sanitize($schoolName) ?></title>
    <link rel="icon" href="../assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="../assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin-import.css?v=<?= (is_file(__DIR__ . '/../assets/css/admin-import.css') ? filemtime(__DIR__ . '/../assets/css/admin-import.css') : 0) ?>">
</head>
<body>
    <div class="admin-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <main class="admin-main">
            <header class="admin-header">
                <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <div class="header-titles">
                    <h1 class="page-title">Import Unit Test Excel</h1>
                    <p class="page-sub">Auto-detect classes, subjects, 20 max marks, half-marks, and attendance from Unit Test spreadsheets</p>
                </div>

                <div class="header-actions">
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
                                <span><i class="fas fa-bell"></i> Promotion &amp; System Alerts</span>
                                <span class="notif-count"><?= count($notifData['items']) ?> events</span>
                            </div>
                            <div class="notif-dropdown-list">
                                <?php if (empty($notifData['items'])): ?>
                                    <div class="notif-empty"><i class="fas fa-bell-slash"></i>No recent notifications.</div>
                                <?php else: ?>
                                    <?php foreach ($notifData['items'] as $nt): ?>
                                        <a href="<?= !empty($nt['link']) ? sanitize($nt['link']) : '#' ?>" class="notif-item">
                                            <div class="notif-item-icon"><i class="fas fa-file-excel"></i></div>
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

            <div class="admin-content">
                <div class="imp-wrap">

                <!-- Alert Messages -->
                <?php if ($success !== ''): ?>
                <div class="imp-alert imp-alert--ok">
                    <i class="fas fa-check-circle"></i>
                    <div class="imp-alert-body">
                        <strong>Import successful</strong>
                        <?= sanitize($success) ?>
                    </div>
                    <?php if (!empty($importResult['first_link'])): ?>
                    <a href="<?= sanitize($importResult['first_link']) ?>" class="btn btn-primary btn-sm">
                        <i class="fas fa-table"></i> View Results
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                <div class="imp-alert imp-alert--err">
                    <i class="fas fa-exclamation-circle"></i>
                    <div class="imp-alert-body">
                        <strong>Something went wrong</strong>
                        <?= sanitize($error) ?>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Hero -->
                <div class="imp-hero">
                    <h2><i class="fas fa-file-excel"></i> Unit Test Spreadsheet Importer</h2>
                    <p>
                        Upload your official school Unit Test Excel workbook (e.g. <code>Copy of 1 to 9 marksheet II UNIT EXAM 2025-26.xlsx</code>).
                        The engine automatically detects Roman numeral classes (e.g. <code>STD:- I A</code>), Unit Test exam types (<code>UNIT TEST :- II</code>),
                        subjects with 20 max marks, half-marks (<code>18.5</code>), absent (<code>-</code>), and attendance (<code>Attn</code>).
                    </p>
                </div>

                <!-- Step 1: Upload Form -->
                <?php if (!$preview && empty($multiSheets)): ?>
                <div class="panel">
                    <div class="panel-header">
                        <h2><i class="fas fa-cloud-arrow-up imp-panel-icon imp-panel-icon--brand"></i> Step 1 — Choose Your Workbook</h2>
                    </div>
                    <div class="panel-body">
                        <form method="POST" enctype="multipart/form-data" id="uploadForm">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="parse">

                            <label class="imp-drop" id="fileDrop" for="excelFileInput">
                                <input type="file" name="excel_file" id="excelFileInput" accept=".xlsx,.xls" required>
                                <span class="imp-drop-icon"><i class="fas fa-cloud-arrow-up"></i></span>
                                <p class="imp-drop-title">Drop your Unit Test workbook here, or <em>browse</em></p>
                                <p class="imp-drop-sub">Multi-sheet workbooks welcome — every class sheet is detected automatically.</p>
                                <span class="imp-drop-chips">
                                    <span class="imp-chip"><i class="fas fa-file-excel"></i> .xlsx / .xls</span>
                                    <span class="imp-chip"><i class="fas fa-layer-group"></i> Classes 1 – 10</span>
                                    <span class="imp-chip"><i class="fas fa-list-ol"></i> Max 3,000 rows</span>
                                </span>
                            </label>

                            <div id="fileStatus" hidden></div>

                            <div class="imp-legend">
                                <div class="imp-legend-item">
                                    <strong><i class="fas fa-wand-magic-sparkles"></i> Auto-detected</strong>
                                    <span>Roman numeral classes (STD:- I A), exam types (UNIT TEST :- II) and academic year.</span>
                                </div>
                                <div class="imp-legend-item">
                                    <strong><i class="fas fa-star-half-stroke"></i> Mark styles</strong>
                                    <span>20 max marks, half-marks like 18.5, absent marked with a dash.</span>
                                </div>
                                <div class="imp-legend-item">
                                    <strong><i class="fas fa-clipboard-user"></i> Attendance</strong>
                                    <span>Attendance columns (Attn) are picked up alongside the marks.</span>
                                </div>
                            </div>

                            <div class="imp-actions">
                                <button type="submit" class="btn btn-primary" id="parseBtn" disabled>
                                    <i class="fas fa-magnifying-glass"></i> Preview Workbook
                                </button>
                                <span class="imp-spacer"></span>
                                <a href="unit_tests.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Unit Tests</a>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Step 2: Multi-Sheet Workbook Detection Preview -->
                <?php if (!empty($multiSheets) && $isMulti): ?>
                <div class="panel">
                    <div class="panel-header">
                        <h2><i class="fas fa-layer-group imp-panel-icon imp-panel-icon--brand"></i> Multi-Class Workbook Detected</h2>
                        <span class="imp-chip"><i class="fas fa-file-excel"></i> <?= sanitize($_SESSION['ut_filename'] ?? 'file') ?></span>
                    </div>
                    <div class="panel-body">
                        <p class="imp-hint">
                            Found <strong><?= count($multiSheets) ?> active class sheets</strong>.
                            Review them below, then run the 1-click batch import.
                        </p>

                        <form method="POST" id="batchForm">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="confirm_import">
                            <input type="hidden" name="is_batch" value="1">

                            <div class="imp-sheets">
                                <?php foreach ($multiSheets as $sName => $sInfo): ?>
                                <div class="imp-sheet">
                                    <div class="imp-sheet-info">
                                        <span class="imp-chip"><i class="fas fa-graduation-cap"></i> <?= sanitize($sInfo['class_label']) ?></span>
                                        <div class="imp-sheet-name" title="<?= sanitize($sName) ?>"><?= sanitize($sName) ?></div>
                                        <div class="imp-sheet-sub">
                                            <?= sanitize($sInfo['test_type']) ?> &middot; <?= sanitize($sInfo['academic_year']) ?> &middot; Subjects: <?= sanitize(implode(', ', array_slice($sInfo['sub_list'], 0, 5))) ?>
                                        </div>
                                    </div>
                                    <div class="imp-sheet-count">
                                        <strong><?= (int)$sInfo['students_count'] ?></strong>
                                        <span>Students</span>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>

                            <label class="imp-switch" for="batchOverwrite">
                                <input type="checkbox" name="overwrite" id="batchOverwrite" value="1" checked>
                                <span class="imp-switch-text">
                                    <strong>Overwrite existing records</strong><br>
                                    Replaces Unit Test rows already stored for these classes.
                                </span>
                            </label>

                            <div class="imp-actions">
                                <button type="submit" class="btn btn-primary" id="batchBtn">
                                    <i class="fas fa-file-import"></i> Confirm Batch Import — <?= count($multiSheets) ?> classes
                                </button>
                                <span class="imp-spacer"></span>
                                <a href="import_unit_tests.php" class="btn btn-outline"><i class="fas fa-xmark"></i> Cancel &amp; Upload Another</a>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Step 3: Single Sheet Detailed Preview -->
                <?php if ($preview && !empty($preview['students'])): ?>
                <?php
                    $pvStudents = count($preview['students']);
                    $pvSubjects = count($preview['subjects']);
                    $pvShown = min($pvStudents, 25);
                    $pvClass = 'STD ' . ($preview['meta']['std'] ?? '') . ' ' . ($preview['meta']['division'] ?? '');
                    $pvTest = ($preview['meta']['test_type'] ?? '') . ' (' . ($preview['meta']['academic_year'] ?? '') . ')';
                ?>
                <div class="panel">
                    <div class="panel-header">
                        <h2><i class="fas fa-eye imp-panel-icon imp-panel-icon--brand"></i> Review Before Importing</h2>
                        <span class="imp-chip"><i class="fas fa-file-excel"></i> <?= sanitize($preview['sheet_name'] ?? 'sheet') ?></span>
                    </div>
                    <div class="panel-body">
                        <div class="imp-stats">
                            <div class="imp-stat imp-stat--ok">
                                <span class="imp-stat-icon"><i class="fas fa-user-graduate"></i></span>
                                <div>
                                    <div class="imp-stat-val"><?= $pvStudents ?></div>
                                    <div class="imp-stat-lbl">Students</div>
                                </div>
                            </div>
                            <div class="imp-stat">
                                <span class="imp-stat-icon"><i class="fas fa-book-open"></i></span>
                                <div>
                                    <div class="imp-stat-val"><?= $pvSubjects ?></div>
                                    <div class="imp-stat-lbl">Subjects</div>
                                </div>
                            </div>
                            <div class="imp-stat imp-stat--text">
                                <span class="imp-stat-icon"><i class="fas fa-graduation-cap"></i></span>
                                <div>
                                    <div class="imp-stat-val"><?= sanitize(trim($pvClass)) ?></div>
                                    <div class="imp-stat-lbl">Class</div>
                                </div>
                            </div>
                            <div class="imp-stat imp-stat--text">
                                <span class="imp-stat-icon"><i class="fas fa-clipboard-check"></i></span>
                                <div>
                                    <div class="imp-stat-val"><?= sanitize(trim($pvTest)) ?></div>
                                    <div class="imp-stat-lbl">Exam</div>
                                </div>
                            </div>
                        </div>

                        <div class="imp-maxrow">
                            <strong>Max marks</strong>
                            <?php foreach ($preview['subjects'] as $sName => $sMax): ?>
                                <span class="imp-chip"><?= sanitize($sName) ?>: <strong><?= (float)$sMax ?></strong></span>
                            <?php endforeach; ?>
                        </div>

                        <div class="imp-table-wrap">
                            <div class="imp-table-scroll">
                                <table class="imp-table">
                                    <thead>
                                        <tr>
                                            <th scope="col" class="col-roll">Roll</th>
                                            <th scope="col">Student Name</th>
                                            <?php foreach (array_keys($preview['subjects']) as $sName): ?>
                                                <th scope="col" class="c-c"><?= sanitize($sName) ?></th>
                                            <?php endforeach; ?>
                                            <th scope="col" class="c-c">Total</th>
                                            <th scope="col" class="c-c">%</th>
                                            <th scope="col" class="c-c">Grade</th>
                                            <th scope="col">Remark</th>
                                            <th scope="col" class="c-c">Attn</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($preview['students'], 0, 25) as $stu): ?>
                                        <tr>
                                            <td class="c-roll"><?= sanitize($stu['roll_no']) ?></td>
                                            <td class="c-name"><?= sanitize($stu['student_name']) ?></td>
                                            <?php foreach ($preview['subjects'] as $sName => $sMax): ?>
                                                <?php $m = $stu['marks'][$sName] ?? null; ?>
                                                <td class="c-c"><?= ($m && !empty($m['is_absent'])) ? '<span class="is-absent">–</span>' : sanitize((string)($m['marks_obtained'] ?? '0')) ?></td>
                                            <?php endforeach; ?>
                                            <td class="c-total"><?= sanitize((string)$stu['total_obtained']) ?></td>
                                            <td class="c-c"><?= $stu['percentage'] !== null ? sanitize((string)$stu['percentage']) . '%' : '–' ?></td>
                                            <td class="c-c"><strong><?= sanitize($stu['grade']) ?></strong></td>
                                            <td><?= sanitize($stu['remark']) !== '' ? sanitize($stu['remark']) : '<span class="is-missing">—</span>' ?></td>
                                            <td class="c-c"><?= sanitize($stu['attendance']) !== '' ? sanitize($stu['attendance']) : '–' ?></td>
                                        </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                            <div class="imp-table-foot">
                                <span>Showing <strong><?= $pvShown ?></strong> of <strong><?= $pvStudents ?></strong> student(s)</span>
                                <?php if ($pvStudents > 25): ?>
                                    <span><?= $pvStudents - 25 ?> more row(s) will still be imported.</span>
                                <?php endif; ?>
                            </div>
                        </div>

                        <form method="POST" id="confirmForm">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="confirm_import">
                            <input type="hidden" name="selected_sheet" value="<?= sanitize($preview['sheet_name']) ?>">

                            <label class="imp-switch" for="singleOverwrite">
                                <input type="checkbox" name="overwrite" id="singleOverwrite" value="1" checked>
                                <span class="imp-switch-text">
                                    <strong>Overwrite existing records</strong><br>
                                    Replaces Unit Test rows already stored for this class.
                                </span>
                            </label>

                            <div class="imp-actions">
                                <button type="submit" class="btn btn-primary" id="confirmBtn" data-count="<?= $pvStudents ?>">
                                    <i class="fas fa-check"></i> Confirm Import — <?= $pvStudents ?> student(s)
                                </button>
                                <span class="imp-spacer"></span>
                                <a href="import_unit_tests.php" class="btn btn-outline"><i class="fas fa-xmark"></i> Cancel</a>
                            </div>
                        </form>
                    </div>
                </div>
                <?php endif; ?>

            </div><!-- .imp-wrap -->
            </div>
        </main>
    </div>


    <script src="<?= BASE_URL ?>assets/js/import-unit-tests.js?v=<?= (is_file(__DIR__ . '/../assets/js/import-unit-tests.js') ? filemtime(__DIR__ . '/../assets/js/import-unit-tests.js') : 0) ?>"></script>
    <script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= (is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 0) ?>"></script>
</body>
</html>
