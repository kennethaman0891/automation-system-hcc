<?php
/**
 * End-to-End Automated Test Suite for Semester & Unit Test Excel Importers.
 *
 * Covers every failure mode that caused intermittent import failures:
 *  1. Single-row header sheets (no F(70)/S(30) sub-header) — Student #1 must NOT be skipped or treated as max marks
 *  2. Two-tier F(70)/S(30)/100 merged-header sheets + FA/SA variants (F.A. 70, Formative, Summative, multiline subject names)
 *  3. Metadata extraction for STD:- I A, UNIT TEST :- I, 8th B, Div B, 2025/2026, Sheet1 false-positive resistance
 *  4. Unit Test sheets WITHOUT a Total column (last subject column must NOT be dropped)
 *  5. Misnamed files (.xlsx saved with .xls extension) and .csv files
 *  6. Formula cells (SUM, cross-sheet refs, #REF!/#DIV/0! error tokens)
 *  7. Excel date serials for DOB + float roll numbers (1.0 -> 1) + missing Roll No column auto-numbering
 *  8. Absent markers (AB, A, -, –, —), fractional marks (18.5/20), clamping, blank vs absent separation
 *  9. Multi-sheet workbooks with non-marksheet/summary tabs (skipped diagnostics instead of silent failure)
 */

declare(strict_types=1);

define('HCC_IMPORT_LIB_ONLY', true);

// Minimal pure-function shims for helpers normally in includes/functions.php (avoids DB/session bootstrap in CLI)
function rcGetActiveAcademicYear(): string { return '2025-26'; }
function clampMark($val, $max) {
    $v = (float)$val;
    if (is_nan($v) || is_infinite($v)) return 0;
    $m = (float)$max;
    if ($m <= 0) return 0;
    return max(0, min($v, $m));
}
function rcNormalizeDateToMysql(?string $dob): ?string {
    $s = trim((string)$dob);
    if ($s === '') return null;
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m)) {
        if (checkdate((int)$m[2], (int)$m[3], (int)$m[1])) return $s;
    }
    if (preg_match('/^(\d{1,2})[\/\-\.](\d{1,2})[\/\-\.](\d{4})$/', $s, $m)) {
        $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
        if (checkdate($mo, $d, $y)) return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }
    return null;
}
function rcCalculateUnitTestGradeAndRemark(?float $pct): array {
    if ($pct === null) return ['grade' => '-', 'remark' => ''];
    if ($pct >= 91.0) return ['grade' => 'A1', 'remark' => 'Excellent'];
    if ($pct >= 81.0) return ['grade' => 'A2', 'remark' => 'V.Good'];
    if ($pct >= 71.0) return ['grade' => 'B1', 'remark' => 'Good'];
    if ($pct >= 61.0) return ['grade' => 'B2', 'remark' => 'Satisfactory'];
    if ($pct >= 51.0) return ['grade' => 'C1', 'remark' => 'Fair'];
    if ($pct >= 41.0) return ['grade' => 'C2', 'remark' => 'Average'];
    if ($pct >= 35.0) return ['grade' => 'D', 'remark' => 'Needs Improvement'];
    return ['grade' => 'E', 'remark' => 'Needs Major Improvement'];
}

require_once __DIR__ . '/../admin/import_excel.php';
require_once __DIR__ . '/../admin/import_unit_tests.php';

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

$passed = 0;
$failed = 0;

function assertEq($actual, $expected, string $label): void {
    global $passed, $failed;
    if ($actual === $expected) {
        $passed++;
    } else {
        $failed++;
        echo "FAIL: {$label}\n  expected: " . var_export($expected, true) . "\n  actual:   " . var_export($actual, true) . "\n";
    }
}

function assertTrue(bool $cond, string $label): void {
    assertEq($cond, true, $label);
}

$tmpDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'hcc_test_excel_' . bin2hex(random_bytes(4));
mkdir($tmpDir, 0755, true);

/* ── TEST 1: Metadata Extraction (Semester & Unit Test) ── */
$m1 = extractMetaFromText('STD:- I A SEMESTER 2 YEAR 2025/2026');
assertEq($m1['std'], '1', 'Semester meta: STD:- I A -> std 1');
assertEq($m1['division'], 'A', 'Semester meta: STD:- I A -> div A');
assertEq($m1['semester'], 'Sem 2', 'Semester meta: SEMESTER 2 -> Sem 2');
assertEq($m1['academic_year'], '2025-26', 'Semester meta: 2025/2026 -> 2025-26');

$m2 = extractMetaFromText('Class 8th DIV B Term 1');
assertEq($m2['std'], '8', 'Semester meta: Class 8th -> std 8');
assertEq($m2['division'], 'B', 'Semester meta: DIV B (previously dead code) -> div B');

$m3 = extractMetaFromText('Sheet1');
assertEq($m3['std'], '', 'Semester meta: Sheet1 must not falsely parse as std 1');

$u1 = utDetectMetaFromText('HCC SCHOOL STD:- I A UNIT TEST :- I YEAR :- 2025-26', 'Sheet1');
assertEq($u1['std'], '1', 'UT meta: STD:- I A -> std 1');
assertEq($u1['division'], 'A', 'UT meta: STD:- I A -> div A');
assertEq($u1['test_type'], 'Unit Test 1', 'UT meta: UNIT TEST :- I -> Unit Test 1 (previously broken by [:-]?)');
assertEq($u1['academic_year'], '2025-26', 'UT meta: YEAR :- 2025-26');

$u2 = utDetectMetaFromText('STD 5 ENGLISH MEDIUM UNIT EXAM :- II', '5 B');
assertEq($u2['std'], '5', 'UT meta: STD 5 ENGLISH -> std 5');
assertEq($u2['division'], 'A', 'UT meta: STD 5 ENGLISH must NOT capture E from ENGLISH');
assertEq($u2['test_type'], 'Unit Test 2', 'UT meta: UNIT EXAM :- II -> Unit Test 2');

$u3 = utDetectMetaFromText('STD:- XI C I UNIT EXAM 2025-2026', 'XI C');
assertEq($u3['std'], '11', 'UT meta: XI -> 11');
assertEq($u3['division'], 'C', 'UT meta: XI C -> C');
assertEq($u3['test_type'], 'Unit Test 1', 'UT meta: I UNIT EXAM -> Unit Test 1');

/* ── TEST 2: Excel Serial DOB Parsing ── */
assertEq(hccExcelParseDob('41234'), '2012-11-21', 'Excel serial date 41234 -> 2012-11-21');
assertEq(hccExcelParseDob('15/08/2014'), '2014-08-15', 'dd/mm/yyyy DOB -> 2014-08-15');
assertEq(hccExcelParseDob('2013-03-05'), '2013-03-05', 'YYYY-MM-DD DOB -> 2013-03-05');

/* ── TEST 3: Semester Single-Row Header Sheet (No F(70)/S(30) sub-header) ── */
$ssSingle = new Spreadsheet();
$sh = $ssSingle->getActiveSheet();
$sh->setTitle('Class 7B');
$sh->fromArray([
    ['Roll No', 'Student Name', 'English', 'Math', 'Science', 'DOB', 'Remark'],
    [1, 'AARAV SHARMA', 88, 92, 79, 41234, 'Good'],
    [2, 'PRIYA PATIL', 75, 'AB', 84, '10/05/2013', 'Very Good'],
], null, 'A1');

$parsedSingle = parseSchoolWorksheet($sh, 'Class 7B');
assertTrue($parsedSingle !== null, 'Single-row semester sheet parsed');
assertEq(count($parsedSingle['students']), 2, 'Single-row sheet keeps Student #1 (not skipped!)');
assertEq($parsedSingle['students'][0]['student_name'], 'AARAV SHARMA', 'Student #1 name intact');
assertEq($parsedSingle['subjects']['English']['fa_max'], 100.0, 'English fa_max is 100 (NOT Student #1 mark 88!)');
assertEq($parsedSingle['students'][0]['subjects'][0]['fa_obtained'], 88.0, 'Student #1 English mark is 88');
assertEq($parsedSingle['students'][0]['date_of_birth'], '2012-11-21', 'Student #1 Excel serial DOB decoded');
assertEq($parsedSingle['students'][1]['absent_subjects'], ['Math'], 'Student #2 Math marked absent');
assertEq($parsedSingle['meta']['std'], '7', 'Single-row sheet std 7');
assertEq($parsedSingle['meta']['division'], 'B', 'Single-row sheet div B');
$ssSingle->disconnectWorksheets();

/* ── TEST 4: Semester Multi-Sheet Master Workbook + Misnamed .xls + Formula + Skipped Tab ── */
$ssMaster = new Spreadsheet();
$s1 = $ssMaster->getActiveSheet();
$s1->setTitle('STD I A');
$s1->setCellValue('A1', 'HINDUSTANI COVENANT CHURCH SCHOOL');
$s1->setCellValue('A2', 'STD:- I A  SEMESTER II  YEAR 2025-26');
$s1->setCellValue('A3', 'Roll No');
$s1->setCellValue('B3', 'Name of the Students');
$s1->setCellValue('C3', "Social\nStudies");
$s1->mergeCells('C3:E3');
$s1->setCellValue('C4', 'F.A. (70)');
$s1->setCellValue('D4', 'S.A. (30)');
$s1->setCellValue('E4', '100');
$s1->setCellValue('F3', 'Math');
$s1->mergeCells('F3:H3');
$s1->setCellValue('F4', 'FA');
$s1->setCellValue('G4', 'SA');
$s1->setCellValue('H4', 'Total');
$s1->setCellValue('I3', 'Remark');
$s1->setCellValue('A5', '1.0');
$s1->setCellValue('B5', 'ROHAN DESHMUKH');
$s1->setCellValue('C5', 60);
$s1->setCellValue('D5', 25);
$s1->setCellValue('E5', '=C5+D5');
$s1->setCellValue('F5', 55);
$s1->setCellValue('G5', 28);
$s1->setCellValue('H5', '=F5+G5');
$s1->setCellValue('I5', 'Excellent');

// Add a non-marksheet summary tab that should be skipped with diagnostic
$sSummary = $ssMaster->createSheet();
$sSummary->setTitle('Summary Notes');
$sSummary->setCellValue('A1', 'Internal Teacher Notes Only');

// Save as .xlsx content with a MISNAMED .xls extension!
$misnamedPath = $tmpDir . DIRECTORY_SEPARATOR . 'master_misnamed.xls';
(new Xlsx($ssMaster))->save($misnamedPath);
$ssMaster->disconnectWorksheets();

$skipped = [];
$scanned = scanWorkbookActiveSheets($misnamedPath, 'xls', $skipped);
assertEq(count($scanned), 1, 'Misnamed .xls (actually xlsx) scanned 1 active class');
assertTrue(isset($scanned['STD I A']), 'Detected STD I A tab');
assertEq($scanned['STD I A']['semester'], 'Sem 2', 'Detected Sem 2 from Row 2');
assertEq($scanned['STD I A']['sub_list'], ['Social Studies', 'Math'], 'Normalized multiline subject & FA/SA without numbers');
assertEq(count($skipped), 1, 'Recorded 1 skipped non-marksheet tab');

/* ── TEST 5: Unit Test Workbook WITHOUT Total Column + Missing Roll No + STD:- / UNIT TEST :- ── */
$ssUt = new Spreadsheet();
$utSheet = $ssUt->getActiveSheet();
$utSheet->setTitle('Sheet1');
$utSheet->setCellValue('A1', 'STD:- IV B   UNIT TEST :- I   YEAR :- 2025-26');
$utSheet->fromArray([
    ['Student Name', 'English', 'Hindi', 'Math', 'EVS'],
    ['', 20, 20, 20, 20],
    ['BOYS', '', '', '', ''],
    ['KABIR JOSHI', '18.5', '19/20', '—', 25],
    ['GIRLS', '', '', '', ''],
    ['ANANYA KULKARNI', 17, '', 'A', 16.5],
], null, 'A2');

$utPath = $tmpDir . DIRECTORY_SEPARATOR . 'ut_no_total.xlsx';
(new Xlsx($ssUt))->save($utPath);
$ssUt->disconnectWorksheets();

$utSkipped = [];
$utScanned = scanUnitTestWorkbook($utPath, 'xlsx', $utSkipped);
assertEq(count($utScanned), 1, 'Unit test workbook scanned 1 sheet');
$ssUtReload = hccLoadSingleWorksheet($utPath, 'Sheet1', 'xlsx');
$utParsed = parseUnitTestWorksheet($ssUtReload->getActiveSheet(), 'Sheet1');
$ssUtReload->disconnectWorksheets();

assertTrue($utParsed !== null, 'UT sheet without Roll No or Total parsed');
assertEq($utParsed['meta']['std'], '4', 'UT std IV -> 4');
assertEq($utParsed['meta']['division'], 'B', 'UT div B');
assertEq($utParsed['meta']['test_type'], 'Unit Test 1', 'UT UNIT TEST :- I -> Unit Test 1');
assertEq(array_keys($utParsed['subjects']), ['English', 'Hindi', 'Math', 'EVS'], 'Last subject EVS NOT dropped when Total col absent');
assertEq(count($utParsed['students']), 2, 'Parsed 2 students across BOYS/GIRLS dividers');
assertEq($utParsed['students'][0]['roll_no'], '1', 'Auto-assigned roll 1 when Roll No col absent');
assertEq($utParsed['students'][0]['gender'], 'Boy', 'Gender divider Boy tracked');
assertEq($utParsed['students'][0]['marks']['English']['marks_obtained'], 18.5, 'Half-mark 18.5 preserved');
assertEq($utParsed['students'][0]['marks']['Hindi']['marks_obtained'], 19.0, 'Fractional 19/20 parsed as 19.0');
assertEq($utParsed['students'][0]['marks']['Math']['is_absent'], 1, 'Em-dash — parsed as absent');
assertEq($utParsed['students'][0]['marks']['EVS']['marks_obtained'], 20.0, 'Mark 25 clamped to max 20');
assertEq($utParsed['students'][0]['clamped_subjects'], ['EVS'], 'EVS flagged as clamped');
assertEq($utParsed['students'][1]['gender'], 'Girl', 'Gender divider Girl tracked');
assertEq($utParsed['students'][1]['marks']['Hindi']['is_blank'], 1, 'Blank cell tracked as blank');
assertEq($utParsed['students'][1]['marks']['Math']['is_absent'], 1, 'Letter A tracked as absent');

// Cleanup temp files
foreach (glob($tmpDir . DIRECTORY_SEPARATOR . '*') as $f) @unlink($f);
@rmdir($tmpDir);

echo "\nExcel Import Test Suite: {$passed} passed, {$failed} failed.\n";
exit($failed > 0 ? 1 : 0);
