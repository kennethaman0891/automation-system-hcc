<?php
@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '300');

$adminPage = 'import_unit_tests';
if (!defined('HCC_IMPORT_LIB_ONLY')) {
    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/auth_check.php';
}
require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../includes/excel_import_helpers.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

if (!defined('HCC_IMPORT_LIB_ONLY')) {
    $db = getDB();
    rcEnsureUnitTestTables();
    $settings = getAllSettings();
    $schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church English Medium School, Solapur';
    $adminUser = sanitize($_SESSION['admin_name'] ?? 'Admin');
    $adminInitials = strtoupper(implode('', array_map(
        function ($part) { return mb_substr($part, 0, 1); },
        array_slice(array_filter(preg_split('/\s+/', trim($_SESSION['admin_name'] ?? 'A'))), 0, 2)
    )));
}

/* ── Upload Limits ── */
const UT_MAX_ROWS = 3000;
const UT_MAX_COLS = 80;
const UT_MAX_CONSECUTIVE_EMPTY = 40;
const UT_MAX_FILE_BYTES = 40 * 1024 * 1024;

/* ── 1. Text Normalization & Roman Numeral Helpers ── */
function utCleanText($v): string {
    if ($v === null) return '';
    $s = trim((string)$v);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s) ?? '';
    return trim($s);
}

function utNormNameKey(string $name): string {
    $s = mb_strtoupper(utCleanText($name), 'UTF-8');
    $s = preg_replace('/[^A-Z0-9\s]/', ' ', $s) ?? '';
    $s = preg_replace('/\s+/', ' ', $s) ?? '';
    return trim($s);
}

function utParseSign($val): ?int {
    if ($val === null) return null;
    $s = strtolower(trim((string)$val));
    if ($s === '') return null;
    if (in_array($s, ['1', 'yes', 'y', 'true', 'signed'], true)) return 1;
    if (in_array($s, ['0', 'no', 'n', 'false', 'unsigned'], true)) return 0;
    return null;
}

/**
 * Auto-Healer & Metadata Shield for Unit Tests:
 * Matches against existing unit_test_results + report_card + students_master to detect
 * swapped/shifted roll numbers and preserve parent_phone + parents_sign across overwrites.
 */
function utAnalyzeCohortHealing(PDO $db, array $students, string $year, string $std, string $div, string $testType): array {
    $byRoll = [];

    if ($year !== '' && $std !== '' && $div !== '') {
        // 1. Existing unit test records (prioritize same test_type, then other tests in same class/year)
        $utStmt = $db->prepare(
            "SELECT roll_no, student_name, test_type,
                    MAX(COALESCE(parents_sign, 0)) AS parents_sign,
                    MAX(NULLIF(TRIM(COALESCE(parent_phone, '')), '')) AS parent_phone
             FROM unit_test_results
             WHERE academic_year = ? AND std = ? AND division = ?
             GROUP BY roll_no, student_name, test_type
             ORDER BY (test_type = ?) DESC"
        );
        $utStmt->execute([$year, $std, $div, $testType]);
        foreach ($utStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $roll = (string)$r['roll_no'];
            if ($roll === '') continue;
            if (!isset($byRoll[$roll])) {
                $byRoll[$roll] = [
                    'roll_no'      => $roll,
                    'student_name' => (string)$r['student_name'],
                    'parent_phone' => trim((string)($r['parent_phone'] ?? '')),
                    'parents_sign' => ($r['test_type'] === $testType) ? (int)($r['parents_sign'] ?? 0) : 0,
                ];
            } elseif ($byRoll[$roll]['parent_phone'] === '' && !empty($r['parent_phone'])) {
                $byRoll[$roll]['parent_phone'] = trim((string)$r['parent_phone']);
            }
        }

        // 2. Fallback / complement from report_card cohort roster
        $rcStmt = $db->prepare(
            "SELECT roll_no, student_name, parent_phone
             FROM report_card
             WHERE academic_year = ? AND std = ? AND division = ?
             ORDER BY id ASC"
        );
        $rcStmt->execute([$year, $std, $div]);
        foreach ($rcStmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $roll = (string)$r['roll_no'];
            if ($roll === '') continue;
            if (!isset($byRoll[$roll])) {
                $byRoll[$roll] = [
                    'roll_no'      => $roll,
                    'student_name' => (string)$r['student_name'],
                    'parent_phone' => trim((string)($r['parent_phone'] ?? '')),
                    'parents_sign' => 0,
                ];
            } elseif ($byRoll[$roll]['parent_phone'] === '' && !empty($r['parent_phone'])) {
                $byRoll[$roll]['parent_phone'] = trim((string)$r['parent_phone']);
            }
        }
    }

    // 3. Complement missing phones from students_master
    if (!empty($byRoll)) {
        try {
            $smStmt = $db->prepare("SELECT roll_no, parent_phone FROM students_master WHERE std = ? AND division = ?");
            $smStmt->execute([$std, $div]);
            foreach ($smStmt->fetchAll(PDO::FETCH_ASSOC) as $sm) {
                $rKey = trim((string)($sm['roll_no'] ?? ''));
                if ($rKey !== '' && isset($byRoll[$rKey]) && $byRoll[$rKey]['parent_phone'] === '' && !empty($sm['parent_phone'])) {
                    $byRoll[$rKey]['parent_phone'] = trim((string)$sm['parent_phone']);
                }
            }
        } catch (\Throwable $e) {
            // Optional table lookup
        }
    }

    $normNames = [];
    foreach ($byRoll as $rKey => $info) {
        $normNames[$rKey] = utNormNameKey($info['student_name']);
    }

    $usedRolls = [];
    $plans = [];
    $healCount = 0;
    $shieldCount = 0;

    foreach ($students as $idx => $stu) {
        $xlRoll = (string)($stu['roll_no'] ?? '');
        $xlNorm = utNormNameKey((string)($stu['student_name'] ?? ''));
        $direct = $byRoll[$xlRoll] ?? null;
        $directSim = 0.0;
        if ($direct && $xlNorm !== '' && ($normNames[$xlRoll] ?? '') !== '') {
            similar_text($xlNorm, $normNames[$xlRoll], $directSim);
        }

        $suggestedRoll = null;
        $healReason = '';
        $healPct = 0.0;
        $metaSource = $direct;

        if ($xlNorm !== '' && (!$direct || $directSim < 75.0)) {
            $bestRoll = null;
            $bestPct = 0.0;
            foreach ($normNames as $candRoll => $candNorm) {
                if ($candNorm === '') continue;
                similar_text($xlNorm, $candNorm, $candPct);
                if ($candPct > $bestPct) {
                    $bestPct = $candPct;
                    $bestRoll = $candRoll;
                }
            }
            if ($bestRoll !== null && $bestRoll !== $xlRoll && $bestPct >= 75.0 && !isset($usedRolls[$bestRoll])) {
                $suggestedRoll = $bestRoll;
                $healPct = round($bestPct, 1);
                $healReason = $direct
                    ? "Roll #{$xlRoll} belongs to \"{$direct['student_name']}\"; \"{$stu['student_name']}\" matches Roll #{$bestRoll} ({$healPct}%)"
                    : "Roll #{$xlRoll} matches existing Roll #{$bestRoll} ({$healPct}%)";
                $metaSource = $byRoll[$bestRoll];
                $usedRolls[$bestRoll] = true;
                $healCount++;
            }
        }

        $xlPhone = trim((string)($stu['parent_phone'] ?? ''));
        $xlSign  = array_key_exists('parents_sign', $stu) && $stu['parents_sign'] !== null ? (int)$stu['parents_sign'] : null;

        $dbPhone = ($metaSource && $metaSource['parent_phone'] !== '') ? $metaSource['parent_phone'] : '';
        $dbSign  = $metaSource ? (int)$metaSource['parents_sign'] : 0;

        $finalPhone = ($xlPhone !== '') ? $xlPhone : $dbPhone;
        $finalSign  = ($xlSign !== null) ? $xlSign : $dbSign;

        $shielded = [];
        if ($xlPhone === '' && $dbPhone !== '') $shielded[] = 'Phone';
        if ($xlSign === null && $dbSign === 1) $shielded[] = 'Sign';
        if (!empty($shielded)) $shieldCount++;

        $plans[$idx] = [
            'original_roll'   => $xlRoll,
            'suggested_roll'  => $suggestedRoll,
            'heal_reason'     => $healReason,
            'heal_pct'        => $healPct,
            'final_phone'     => $finalPhone,
            'final_sign'      => $finalSign,
            'shielded_fields' => $shielded,
        ];
    }

    return [
        'rows'         => $plans,
        'heal_count'   => $healCount,
        'shield_count' => $shieldCount,
    ];
}

function utRomanToDecimal(string $roman): string {
    $r = strtoupper(trim($roman));
    $map = [
        'XII' => '12', 'XI' => '11', 'X' => '10', 'IX' => '9', 'VIII' => '8', 'VII' => '7',
        'VI' => '6', 'V' => '5', 'IV' => '4', 'III' => '3',
        'II' => '2', 'I' => '1'
    ];
    return $map[$r] ?? $r;
}

function utDetectMetaFromText(string $text, string $sheetName = ''): array {
    $meta = [
        'academic_year' => rcGetActiveAcademicYear(),
        'std'           => '',
        'division'      => '',
        'test_type'     => 'Unit Test 2',
    ];

    // 1. Detect Academic Year: e.g. "YEAR :- 2025-26", "2024-25", "2025/2026"
    if (preg_match('/(?:YEAR\s*[:.\-\s]*)?\b(20\d{2}\s*[-\/_]\s*(?:20)?\d{2})\b/i', $text, $ym)) {
        $meta['academic_year'] = hccNormalizeAcademicYear($ym[1]);
    } elseif (preg_match('/\b(20\d{2}\s*[-\/_]\s*(?:20)?\d{2})\b/i', $sheetName, $ym)) {
        $meta['academic_year'] = hccNormalizeAcademicYear($ym[1]);
    }

    // 2. Detect Unit Test: e.g. "UNIT TEST :- II", "UNIT EXAM II", "UNIT TEST 1", "I UNIT EXAM", "UT-1"
    if (preg_match('/\bUNIT\s*(?:TEST|EXAM)?\s*[:.\-\s]*\b(IV|III|II|I|[1-4])\b/i', $text, $tm)) {
        $num = utRomanToDecimal($tm[1]);
        $meta['test_type'] = "Unit Test $num";
    } elseif (preg_match('/\b(IV|III|II|I|[1-4])(?:st|nd|rd|th)?\s+UNIT\s*(?:TEST|EXAM)\b/i', $text, $tm)) {
        $num = utRomanToDecimal($tm[1]);
        $meta['test_type'] = "Unit Test $num";
    } elseif (preg_match('/\bUT\s*[:.\-\s]*\b(IV|III|II|I|[1-4])\b/i', $text . ' ' . $sheetName, $tm)) {
        $num = utRomanToDecimal($tm[1]);
        $meta['test_type'] = "Unit Test $num";
    }

    // Strip year and unit-test tokens before matching Std / Division
    $cleanText = preg_replace('/\b20\d{2}\s*[-\/_]\s*(?:20)?\d{2}\b/', ' ', $text) ?? $text;
    $cleanText = preg_replace('/\bUNIT\s*(?:TEST|EXAM)?\s*[:.\-\s]*\b(?:IV|III|II|I|[1-4])\b/i', ' ', $cleanText) ?? $cleanText;
    $cleanSheet = preg_replace('/\bsheet\s*\d+\b/i', ' ', str_replace('_', ' ', $sheetName)) ?? $sheetName;

    // 3. Detect Standard & Division: e.g. "STD:- I A", "STD : 1 A", "STD - II B", "Class 8th B", "10A"
    if (preg_match('/\b(?:STD|STANDARD|CLASS)\s*[:.\-\s]*\b(XII|XI|X|IX|VIII|VII|VI|V|IV|III|II|I)\b(?:\s*[:.\-\s]*\b([A-F])\b)?/i', $cleanText, $m)) {
        $meta['std'] = utRomanToDecimal($m[1]);
        if (!empty($m[2])) $meta['division'] = strtoupper($m[2]);
    } elseif (preg_match('/\b(?:STD|STANDARD|CLASS)\s*[:.\-\s]*(XII|XI|X|IX|VIII|VII|VI|V|IV|III|II|I)([A-F])\b/i', $cleanText, $m)) {
        $meta['std'] = utRomanToDecimal($m[1]);
        $meta['division'] = strtoupper($m[2]);
    } elseif (preg_match('/\b(?:STD|STANDARD|CLASS)\s*[:.\-\s]*\b(1[0-2]|[1-9])(?:st|nd|rd|th)?\b(?:\s*[:.\-\s]*\b([A-F])\b)?/i', $cleanText, $m)) {
        $meta['std'] = $m[1];
        if (!empty($m[2])) $meta['division'] = strtoupper($m[2]);
    } elseif (preg_match('/\b(?:STD|STANDARD|CLASS)\s*[:.\-\s]*(1[0-2]|[1-9])([A-F])\b/i', $cleanText, $m)) {
        $meta['std'] = $m[1];
        $meta['division'] = strtoupper($m[2]);
    } elseif (preg_match('/\b(XII|XI|X|IX|VIII|VII|VI|V|IV|III|II|I)\b\s*[:.\-\s]*([A-F])\b/i', $cleanSheet, $sm)) {
        $meta['std'] = utRomanToDecimal($sm[1]);
        $meta['division'] = strtoupper($sm[2]);
    } elseif (preg_match('/\b(XII|XI|X|IX|VIII|VII|VI|V|IV|III|II|I)([A-F])\b/i', $cleanSheet, $sm)) {
        $meta['std'] = utRomanToDecimal($sm[1]);
        $meta['division'] = strtoupper($sm[2]);
    } elseif (preg_match('/\b(1[0-2]|[1-9])(?:st|nd|rd|th)?\s*[:.\-\s]*([A-F])\b/i', $cleanSheet, $sm)) {
        $meta['std'] = $sm[1];
        $meta['division'] = strtoupper($sm[2]);
    } elseif (preg_match('/\b(1[0-2]|[1-9])([A-F])\b/i', $cleanSheet, $sm)) {
        $meta['std'] = $sm[1];
        $meta['division'] = strtoupper($sm[2]);
    }

    if ($meta['division'] === '') {
        if (preg_match('/\b(?:DIV|DIVISION|SEC|SECTION)\s*[:.\-\s]*([A-F])\b/i', $cleanText . ' ' . $cleanSheet, $dm)) {
            $meta['division'] = strtoupper($dm[1]);
        } else {
            $meta['division'] = 'A';
        }
    }

    return $meta;
}

/* ── 2. Specialized Unit Test Worksheet Parser ── */
function parseUnitTestWorksheet($sheet, string $sheetName): ?array {
    $highestRow = min((int)$sheet->getHighestDataRow(), UT_MAX_ROWS);
    $highestColLetter = $sheet->getHighestDataColumn();
    $highestCol = min(Coordinate::columnIndexFromString($highestColLetter), UT_MAX_COLS);
    if ($highestRow <= 0 || $highestCol <= 0) return null;

    // Read top rows first for metadata + header detection
    $headerScanLimit = min($highestRow, 25);
    $topRows = [];
    for ($r = 1; $r <= $headerScanLimit; $r++) {
        $rowCells = [];
        $hasData = false;
        for ($c = 1; $c <= $highestCol; $c++) {
            $val = hccExcelCellValue($sheet, $c, $r);
            $rowCells[$c] = $val;
            if ($val !== '') $hasData = true;
        }
        if ($hasData) $topRows[$r] = $rowCells;
    }
    if (empty($topRows)) return null;

    // B. Detect Header Row ("Roll No" / "Name of the Students") via scoring
    $headerRowIdx = null;
    $bestHeaderScore = 0;
    foreach ($topRows as $rIdx => $row) {
        $score = 0;
        $hasNameOrRoll = false;
        $nonEmpty = 0;
        foreach ($row as $val) {
            if ($val === '') continue;
            $nonEmpty++;
            $cleanVal = preg_replace('/[^a-z]/', '', strtolower($val));
            if (in_array($cleanVal, ['nameofstudents', 'nameofthestudents', 'nameofstudent', 'studentname', 'studentsname', 'fullname'], true)) {
                $score += 5;
                $hasNameOrRoll = true;
            } elseif (in_array($cleanVal, ['rollno', 'rollnumber', 'rno'], true)) {
                $score += 4;
                $hasNameOrRoll = true;
            } elseif (in_array($cleanVal, ['roll', 'srno', 'sno', 'name', 'student'], true)) {
                $score += 2;
                $hasNameOrRoll = true;
            } elseif (in_array($cleanVal, ['total', 'tot', 'percentage', 'pct', 'grade', 'remark', 'remarks', 'attn', 'attendance'], true)) {
                $score += 2;
            }
        }
        if ($hasNameOrRoll && $nonEmpty >= 2 && $score > $bestHeaderScore) {
            $bestHeaderScore = $score;
            $headerRowIdx = $rIdx;
        }
    }
    if (!$headerRowIdx) return null;

    // A. Detect Meta from all title rows above headerRowIdx
    $topText = '';
    for ($r = 1; $r < $headerRowIdx; $r++) {
        if (isset($topRows[$r])) {
            $topText .= ' ' . implode(' ', array_filter($topRows[$r], fn($v) => $v !== ''));
        }
    }
    $meta = utDetectMetaFromText($topText, $sheetName);

    $headerRow = $topRows[$headerRowIdx] ?? [];
    $subHeaderRow = $topRows[$headerRowIdx + 1] ?? [];
    if (empty($subHeaderRow) && ($headerRowIdx + 1) <= $highestRow) {
        for ($c = 1; $c <= $highestCol; $c++) {
            $subHeaderRow[$c] = hccExcelCellValue($sheet, $c, $headerRowIdx + 1);
        }
    }

    // Identify Columns (prefer explicit Roll No over Sr No)
    $rollColExplicit = null;
    $srNoCol = null;
    $nameCol = null;
    $totalCol = null;
    $pctCol = null;
    $gradeCol = null;
    $remarkCol = null;
    $attnCol = null;
    $signCol = null;
    $phoneCol = null;
    $subjectCols = []; // colIndex => subjectName

    $nonSubjectClean = [
        'rollno', 'roll', 'rollnumber', 'rno', 'srno', 'sno', 'serialno',
        'nameofthestudents', 'nameofstudents', 'nameofstudent', 'studentname', 'studentsname', 'name', 'student', 'fullname',
        'total', 'grandtotal', 'totalmarks', 'tot',
        'pct', 'percentage', 'percent', 'per',
        'grade', 'grd', 'remark', 'remarks', 'comment', 'comments',
        'attn', 'attendance', 'dayspresent', 'presentdays',
        'parentssign', 'parentsign', 'parents', 'sign', 'signature', 'signed',
        'parentphone', 'phone', 'mobile', 'mobilenumber', 'whatsapp', 'contact', 'contactno',
        'dob', 'dateofbirth', 'birthdate', 'grno', 'gr', 'grnumber', 'gender', 'sex',
        'std', 'class', 'standard', 'division', 'div', 'section', 'sec', 'academicyear', 'year',
        'result', 'status', 'passfail', 'rank', 'position',
    ];

    foreach ($headerRow as $cIdx => $val) {
        $clean = preg_replace('/[^a-z0-9]/', '', strtolower($val));
        if (in_array($clean, ['rollno', 'roll', 'rollnumber', 'rno'], true)) {
            if ($rollColExplicit === null) $rollColExplicit = $cIdx;
        } elseif (in_array($clean, ['srno', 'sno', 'serialno'], true)) {
            if ($srNoCol === null) $srNoCol = $cIdx;
        } elseif (in_array($clean, ['nameofthestudents', 'nameofstudents', 'nameofstudent', 'studentname', 'studentsname', 'name', 'student', 'fullname'], true)) {
            if ($nameCol === null) $nameCol = $cIdx;
        } elseif (in_array($clean, ['total', 'grandtotal', 'totalmarks', 'tot'], true)) {
            if ($totalCol === null) $totalCol = $cIdx;
        } elseif (in_array($clean, ['pct', 'percentage', 'percent', 'per'], true) || trim($val) === '%') {
            $pctCol = $cIdx;
        } elseif (in_array($clean, ['grade', 'grd'], true)) {
            $gradeCol = $cIdx;
        } elseif (in_array($clean, ['remark', 'remarks', 'comment', 'comments'], true)) {
            $remarkCol = $cIdx;
        } elseif (in_array($clean, ['attn', 'attendance', 'dayspresent', 'presentdays'], true)) {
            $attnCol = $cIdx;
        } elseif (in_array($clean, ['parentssign', 'parentsign', 'sign', 'signature'], true)) {
            $signCol = $cIdx;
        } elseif (in_array($clean, ['parentphone', 'phone', 'mobile', 'mobilenumber', 'whatsapp', 'contact', 'contactno'], true)) {
            $phoneCol = $cIdx;
        }
    }

    $rollCol = $rollColExplicit ?? $srNoCol;
    if (!$nameCol) return null;

    // Detect subjects: all non-empty non-meta columns between nameCol and totalCol (or through highestCol inclusive!)
    $endCol = $totalCol ? ($totalCol - 1) : $highestCol;
    for ($cIdx = $nameCol + 1; $cIdx <= $endCol; $cIdx++) {
        $hName = hccCleanSubjectName($headerRow[$cIdx] ?? '');
        $hClean = preg_replace('/[^a-z0-9]/', '', strtolower($hName));
        if ($hName !== '' && !in_array($hClean, $nonSubjectClean, true) && $hName !== '%') {
            $subjectCols[$cIdx] = $hName;
        }
    }
    if (empty($subjectCols)) return null;

    // Determine whether ($headerRowIdx + 1) is a sub-header row (max marks like 20) or the first student row!
    $subNameCell = trim((string)($subHeaderRow[$nameCol] ?? ''));
    $subNameNorm = preg_replace('/[^a-z]/', '', strtolower($subNameCell));
    $subNameLooksLikeStudent = ($subNameCell !== '' && !in_array($subNameNorm, ['max', 'maxmarks', 'outof', 'marks', 'total', 'boys', 'girls'], true));

    $subHeaderHasMaxMarks = false;
    if (!$subNameLooksLikeStudent) {
        foreach ($subjectCols as $cIdx => $sName) {
            $sh = trim((string)($subHeaderRow[$cIdx] ?? ''));
            if (is_numeric($sh) || preg_match('/^(?:max|out\s*of)?\s*[:\-]?\s*\(?([0-9]+(?:\.[0-9]+)?)\)?$/i', $sh)) {
                $subHeaderHasMaxMarks = true;
                break;
            }
        }
    }

    // Extract Max Marks for each subject from sub-header row (or embedded in header like "English (20)")
    $subjectMaxMap = [];
    $cleanSubjectCols = [];
    foreach ($subjectCols as $cIdx => $sName) {
        $maxVal = 20.0;
        $displayName = $sName;
        if (preg_match('/^(.+?)\s*\(\s*([0-9]+(?:\.[0-9]+)?)\s*\)$/', $sName, $sm)) {
            $displayName = trim($sm[1]);
            $mEmbedded = (float)$sm[2];
            if ($mEmbedded > 0 && $mEmbedded <= 100) $maxVal = $mEmbedded;
        }
        if ($subHeaderHasMaxMarks) {
            $sh = trim((string)($subHeaderRow[$cIdx] ?? ''));
            if (preg_match('/([0-9]+(?:\.[0-9]+)?)/', $sh, $mm)) {
                $mSub = (float)$mm[1];
                if ($mSub > 0 && $mSub <= 100) $maxVal = $mSub;
            }
        }
        $cleanSubjectCols[$cIdx] = $displayName;
        $subjectMaxMap[$displayName] = $maxVal;
    }
    $subjectCols = $cleanSubjectCols;

    $startRow = $subHeaderHasMaxMarks ? ($headerRowIdx + 2) : ($headerRowIdx + 1);

    // C. Parse Student Rows
    $students = [];
    $currentGender = '';
    $autoRoll = 1;
    $consecutiveEmpty = 0;

    for ($rIdx = $startRow; $rIdx <= $highestRow; $rIdx++) {
        $row = [];
        $hasData = false;
        for ($c = 1; $c <= $highestCol; $c++) {
            $v = hccExcelCellValue($sheet, $c, $rIdx);
            $row[$c] = $v;
            if ($v !== '') $hasData = true;
        }
        if (!$hasData) {
            $consecutiveEmpty++;
            if ($consecutiveEmpty >= UT_MAX_CONSECUTIVE_EMPTY) break;
            continue;
        }
        $consecutiveEmpty = 0;

        $rollVal = $rollCol ? trim((string)($row[$rollCol] ?? '')) : '';
        if (preg_match('/^([0-9]+)\.0+$/', $rollVal, $rm)) {
            $rollVal = $rm[1];
        }
        $hasNumericRoll = ($rollVal !== '' && preg_match('/^[0-9]+[a-z]?$/i', $rollVal));

        // Standalone gender section divider check (only on standalone BOYS/GIRLS label rows, never matching student surnames)
        if (!$hasNumericRoll) {
            $isDivider = false;
            foreach (array_slice($row, 0, 5) as $cellText) {
                $ct = trim((string)$cellText);
                if ($ct !== '' && preg_match('/^(GIRLS|BOYS)\b(?:\s*[:\-\.\(\)\/].*|\s+SECTION)?$/i', $ct, $gm)) {
                    $currentGender = (strtoupper($gm[1]) === 'GIRLS') ? 'Girl' : 'Boy';
                    $isDivider = true;
                    break;
                }
            }
            if ($isDivider) continue;
        }

        $nameVal = trim((string)($row[$nameCol] ?? ''));
        if ($nameVal === '' || preg_match('/^(BOYS|GIRLS|TOTAL|GRAND TOTAL|AVERAGE|CLASS AVERAGE|RESULT|PASS|FAIL|HIGHEST|LOWEST)(?:\s*[:\-].*)?$/i', strtoupper($nameVal))) {
            continue;
        }

        // If Roll No column exists and has a non-numeric non-empty label (like "Total"), skip;
        // if Roll No is empty or missing column, auto-number sequentially.
        if ($rollCol && $rollVal !== '' && !$hasNumericRoll) {
            continue;
        }
        if ($rollVal === '') {
            $rollVal = (string)$autoRoll;
        }
        if (is_numeric($rollVal)) {
            $autoRoll = max($autoRoll, (int)$rollVal + 1);
        } else {
            $autoRoll++;
        }

        // Clean student name
        $nameVal = preg_replace('/\s+/', ' ', $nameVal);

        // Subject marks: separate explicit AB/absent from blank unentered cells, and clamp numeric marks <= maxM
        $marks = [];
        $totObt = 0.0;
        $totMax = 0.0;
        $allAb = true;
        $anyEntered = false;
        $blankSubjects = [];
        $clampedSubjects = [];

        foreach ($subjectCols as $cIdx => $sName) {
            $rawMark = trim((string)($row[$cIdx] ?? ''));
            $maxM = $subjectMaxMap[$sName] ?? 20.0;
            $totMax += $maxM;
            $upperMark = strtoupper($rawMark);

            $isExplicitAb = in_array($upperMark, ['-', '--', '–', '—', 'AB', 'A', 'ABSENT'], true);
            $isBlank = ($rawMark === '' || in_array($upperMark, ['NA', 'N/A', 'NIL', 'NULL', 'NONE', 'EXEMPT'], true));

            $parsedNumeric = null;
            if (!$isExplicitAb && !$isBlank) {
                if (is_numeric($rawMark)) {
                    $parsedNumeric = (float)$rawMark;
                } elseif (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*\/\s*[0-9]+(?:\.[0-9]+)?$/', $rawMark, $fm)) {
                    $parsedNumeric = (float)$fm[1];
                }
            }

            if ($isExplicitAb) {
                $obtVal = null;
                $isAb = true;
                $anyEntered = true;
            } elseif ($parsedNumeric === null) {
                $obtVal = null;
                $isAb = false;
                $allAb = false;
                $blankSubjects[] = $sName;
            } else {
                $isAb = false;
                $allAb = false;
                $anyEntered = true;
                $obtVal = round(clampMark($parsedNumeric, $maxM), 1);
                if ($parsedNumeric > $maxM || $parsedNumeric < 0) {
                    $clampedSubjects[] = $sName;
                }
                $totObt += $obtVal;
            }

            $marks[$sName] = [
                'max_marks'      => $maxM,
                'marks_obtained' => $obtVal,
                'is_absent'      => $isAb ? 1 : 0,
                'is_blank'       => ($obtVal === null && !$isAb) ? 1 : 0,
            ];
        }

        // Attendance, Custom Remark, Optional Parent Phone & Parents Sign
        $attnVal = $attnCol ? trim((string)($row[$attnCol] ?? '')) : '';
        $customRemark = $remarkCol ? trim((string)($row[$remarkCol] ?? '')) : '';
        $phoneVal = $phoneCol ? utCleanText($row[$phoneCol] ?? '') : '';
        $signVal = $signCol ? utParseSign($row[$signCol] ?? null) : null;

        // Calculate Percentage, Grade & Remark
        if (!$anyEntered) {
            $pct = null;
            $grade = '-';
            $remark = $customRemark;
        } elseif ($allAb) {
            $pct = null;
            $grade = '-';
            $remark = ($customRemark !== '') ? $customRemark : 'Absent';
        } else {
            $pct = ($totMax > 0) ? round(($totObt / $totMax) * 100, 2) : null;
            $autoGr = rcCalculateUnitTestGradeAndRemark($pct);
            $grade = $autoGr['grade'];
            $remark = ($customRemark !== '') ? $customRemark : $autoGr['remark'];
        }

        $students[] = [
            'roll_no'          => $rollVal,
            'student_name'     => $nameVal,
            'gender'           => $currentGender,
            'marks'            => $marks,
            'attendance'       => $attnVal,
            'parent_phone'     => $phoneVal,
            'parents_sign'     => $signVal,
            'blank_subjects'   => $blankSubjects,
            'clamped_subjects' => $clampedSubjects,
            'total_obtained'   => $totObt,
            'total_max'        => $totMax,
            'percentage'       => $pct,
            'grade'            => $grade,
            'remark'           => $remark,
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
function scanUnitTestWorkbook(string $filePath, string $ext, array &$skippedSheets = []): array {
    $reader = hccCreateExcelReader($filePath, $ext);
    $sheetNames = hccListExcelSheetNames($reader, $filePath);

    $active = [];
    foreach ($sheetNames as $sn) {
        try {
            $ss = hccLoadSingleWorksheet($filePath, $sn, $ext);
            $sheet = $ss->getSheetByName($sn) ?: $ss->getActiveSheet();
            $parsed = parseUnitTestWorksheet($sheet, $sn);
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
            } else {
                $skippedSheets[] = $sn . ' (no valid Unit Test header/rows found)';
            }
        } catch (\Throwable $e) {
            error_log("import_unit_tests scan error on sheet '{$sn}': " . $e->getMessage());
            $skippedSheets[] = $sn . ' (read error: ' . $e->getMessage() . ')';
        }
    }
    return $active;
}

/* ─────────────────────────────────────────────────────────────────────────────
   REQUEST HANDLING (GET & POST)
   ───────────────────────────────────────────────────────────────────────────── */
if (defined('HCC_IMPORT_LIB_ONLY')) {
    return;
}
/* ── Fresh Reset ── */
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && !empty($_GET['fresh'])) {
    if (!empty($_SESSION['ut_file_path']) && file_exists($_SESSION['ut_file_path'])) {
        @unlink($_SESSION['ut_file_path']);
    }
    unset($_SESSION['ut_file_path'], $_SESSION['ut_file_ext'], $_SESSION['ut_filename'],
          $_SESSION['ut_multi_sheets'], $_SESSION['ut_skipped_sheets'], $_SESSION['ut_preview'],
          $_SESSION['ut_is_multi'], $_SESSION['ut_confirm_token'], $_SESSION['ut_active_sheet']);
    header('Location: import_unit_tests.php');
    exit;
}

$error = '';
$success = '';
$preview = null;
$multiSheets = [];
$skippedSheets = $_SESSION['ut_skipped_sheets'] ?? [];
$isMulti = false;
$importResult = null;
$maxUploadBytes = hccImportMaxUploadBytes(UT_MAX_FILE_BYTES);

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    $postLimitErr = hccImportPostExceededLimit();
    if ($postLimitErr !== null) {
        $error = $postLimitErr;
    } elseif (!verifyCSRFToken()) {
        $error = 'Security check failed. Please refresh the page.';
    } else {
        $action = trim($_POST['action'] ?? '');

        /* ── Action: Parse File ── */
        if ($action === 'parse') {
            $file = $_FILES['excel_file'] ?? null;
            if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
                $error = 'No file uploaded. Please choose an Excel file.';
            } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                $error = hccUploadErrorMessage((int)$file['error']);
            } else {
                $origName = $file['name'] ?? 'unit_test.xlsx';
                $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

                if (!in_array($ext, ['xlsx', 'xls', 'csv'], true)) {
                    $error = 'Invalid file format. Please upload an Excel (.xlsx, .xls, or .csv) file.';
                } elseif ((int)($file['size'] ?? 0) <= 0) {
                    $error = 'The uploaded file is empty. Please choose a valid workbook.';
                } elseif ((int)($file['size'] ?? 0) > $maxUploadBytes) {
                    $error = 'File exceeds the server upload limit (' . round($maxUploadBytes / (1024 * 1024), 1) . ' MB).';
                } else {
                    if (!empty($_SESSION['ut_file_path']) && file_exists($_SESSION['ut_file_path'])) {
                        @unlink($_SESSION['ut_file_path']);
                    }
                    $uploadDir = hccImportTempDir('ut_imports');
                    $destPath = $uploadDir . DIRECTORY_SEPARATOR . 'ut_' . bin2hex(random_bytes(8)) . '.' . $ext;

                    if (!move_uploaded_file($file['tmp_name'], $destPath)) {
                        $error = 'Failed to save uploaded file on server. Check storage folder permissions.';
                    } else {
                        try {
                            $scanSkipped = [];
                            $multiSheets = scanUnitTestWorkbook($destPath, $ext, $scanSkipped);

                            if (empty($multiSheets)) {
                                $detail = !empty($scanSkipped) ? (' Checked tabs: ' . implode('; ', array_slice($scanSkipped, 0, 5))) : '';
                                $error = 'Could not detect any valid Unit Test marksheets in this Excel file. Please verify sheet headers.' . $detail;
                                @unlink($destPath);
                            } else {
                                $_SESSION['ut_file_path'] = $destPath;
                                $_SESSION['ut_file_ext'] = $ext;
                                $_SESSION['ut_filename'] = basename($origName);
                                $_SESSION['ut_multi_sheets'] = $multiSheets;
                                $_SESSION['ut_skipped_sheets'] = $scanSkipped;
                                $_SESSION['ut_confirm_token'] = bin2hex(random_bytes(32));
                                $skippedSheets = $scanSkipped;

                                if (count($multiSheets) > 1) {
                                    $isMulti = true;
                                    $_SESSION['ut_is_multi'] = true;
                                    unset($_SESSION['ut_preview'], $_SESSION['ut_active_sheet']);
                                } else {
                                    $isMulti = false;
                                    $_SESSION['ut_is_multi'] = false;
                                    $firstSheet = array_key_first($multiSheets);
                                    $ss = hccLoadSingleWorksheet($destPath, $firstSheet, $ext);
                                    $sheet = $ss->getSheetByName($firstSheet) ?: $ss->getActiveSheet();
                                    $preview = parseUnitTestWorksheet($sheet, $firstSheet);
                                    $ss->disconnectWorksheets();
                                    unset($ss);
                                    $_SESSION['ut_active_sheet'] = $firstSheet;
                                    $_SESSION['ut_preview'] = $preview;
                                }
                            }
                        } catch (\Throwable $e) {
                            error_log('import_unit_tests parse error: ' . $e->getMessage());
                            $error = 'Error reading workbook: ' . $e->getMessage();
                            @unlink($destPath);
                        }
                    }
                }
            }
        }

        /* ── Action: Inspect Individual Sheet from Multi-Sheet Workbook ── */
        elseif ($action === 'switch_sheet') {
            $sheetToView = utCleanText($_POST['sheet_name'] ?? '');
            $tmpPath = $_SESSION['ut_file_path'] ?? '';
            $ext = $_SESSION['ut_file_ext'] ?? 'xlsx';
            $multiSheets = $_SESSION['ut_multi_sheets'] ?? [];
            $isMulti = !empty($_SESSION['ut_is_multi']);
            if (!$tmpPath || !file_exists($tmpPath)) {
                $error = 'Uploaded file session expired. Please re-upload your Excel file.';
                unset($_SESSION['ut_multi_sheets'], $_SESSION['ut_preview'], $_SESSION['ut_is_multi']);
                $multiSheets = [];
                $isMulti = false;
            } elseif (!isset($multiSheets[$sheetToView])) {
                $error = 'Selected sheet was not found in the uploaded workbook.';
            } else {
                try {
                    $ss = hccLoadSingleWorksheet($tmpPath, $sheetToView, $ext);
                    $sheet = $ss->getSheetByName($sheetToView) ?: $ss->getActiveSheet();
                    $preview = parseUnitTestWorksheet($sheet, $sheetToView);
                    $ss->disconnectWorksheets();
                    unset($ss);
                    $_SESSION['ut_active_sheet'] = $sheetToView;
                    $_SESSION['ut_preview'] = $preview;
                } catch (\Throwable $e) {
                    error_log("import_unit_tests switch_sheet error on '{$sheetToView}': " . $e->getMessage());
                    $error = 'Could not read sheet "' . $sheetToView . '": ' . $e->getMessage();
                }
            }
        }

        /* ── Action: Confirm Import (Single or Batch) ── */
        elseif ($action === 'confirm_import') {
            $sessionToken  = $_SESSION['ut_confirm_token'] ?? '';
            $postedToken   = $_POST['confirm_token'] ?? '';
            $tmpPath       = $_SESSION['ut_file_path'] ?? '';
            $ext           = $_SESSION['ut_file_ext'] ?? 'xlsx';
            $multiSheets   = $_SESSION['ut_multi_sheets'] ?? [];
            $isBatch       = !empty($_POST['is_batch']);
            $overwrite     = !empty($_POST['overwrite']);
            $applyRollHeal = !empty($_POST['apply_roll_heal']);

            if ($sessionToken === '' || $postedToken === '' || !hash_equals((string)$sessionToken, (string)$postedToken)) {
                $error = 'Invalid or expired import confirmation token. Please re-upload your Excel file.';
            } elseif (!$tmpPath || !file_exists($tmpPath)) {
                $error = 'Uploaded file session expired. Please re-upload your Excel file.';
            } else {
                $activeYear = rcGetActiveAcademicYear();
                $totalImportedStudents = 0;
                $totalHealedRolls = 0;
                $totalShieldedRows = 0;
                $classesImported = [];
                $lockedWarnings = [];
                $importErrors = [];
                $firstClassLink = '';

                // Single-sheet user overrides (when not batch)
                $overrideYear = !$isBatch ? utCleanText($_POST['confirm_academic_year'] ?? '') : '';
                $overrideStd  = !$isBatch ? utCleanText($_POST['confirm_std'] ?? '') : '';
                $overrideDiv  = !$isBatch ? strtoupper(utCleanText($_POST['confirm_division'] ?? '')) : '';
                $overrideTest = !$isBatch ? utCleanText($_POST['confirm_test_type'] ?? '') : '';

                // Determine which sheets to import
                $sheetsToImport = $isBatch ? array_keys($multiSheets) : [trim($_POST['selected_sheet'] ?? (string)array_key_first($multiSheets))];

                foreach ($sheetsToImport as $sn) {
                    try {
                        $ss = hccLoadSingleWorksheet($tmpPath, $sn, $ext);
                        $sheet = $ss->getSheetByName($sn) ?: $ss->getActiveSheet();
                        $parsed = parseUnitTestWorksheet($sheet, $sn);
                        $ss->disconnectWorksheets();
                        unset($ss);

                        if (!$parsed || empty($parsed['students'])) {
                            $importErrors[] = "Sheet '{$sn}': no student rows detected.";
                            continue;
                        }

                        $cYear = $overrideYear !== '' ? hccNormalizeAcademicYear($overrideYear) : (!empty($parsed['meta']['academic_year']) ? $parsed['meta']['academic_year'] : $activeYear);
                        $cStd  = $overrideStd !== '' ? $overrideStd : (!empty($parsed['meta']['std']) ? $parsed['meta']['std'] : '1');
                        $cDiv  = $overrideDiv !== '' ? $overrideDiv : (!empty($parsed['meta']['division']) ? $parsed['meta']['division'] : 'A');
                        $cTest = $overrideTest !== '' ? $overrideTest : (!empty($parsed['meta']['test_type']) ? $parsed['meta']['test_type'] : 'Unit Test 2');

                        if (rcIsVaultLocked($cYear)) {
                            $lockedWarnings[] = "STD $cStd $cDiv ({$cYear}) skipped — Academic Vault is locked.";
                            continue;
                        }

                        // Snapshot Auto-Healer & Metadata Shield BEFORE any DELETE/overwrite
                        $healing = utAnalyzeCohortHealing($db, $parsed['students'], $cYear, $cStd, $cDiv, $cTest);
                        $claimedRolls = [];

                        // Save subject configuration
                        $subjConf = [];
                        foreach ($parsed['subjects'] as $sName => $sMax) {
                            $subjConf[] = ['subject_name' => $sName, 'max_marks' => (float)$sMax];
                        }
                        rcSaveUnitTestConfig($cYear, $cStd, $cTest, $subjConf);

                        $db->beginTransaction();
                        try {
                            // If overwrite enabled, clear existing cohort for this class (metadata already shielded in $healing)
                            if ($overwrite) {
                                $del = $db->prepare("DELETE FROM unit_test_results WHERE academic_year=? AND std=? AND division=? AND test_type=?");
                                $del->execute([$cYear, $cStd, $cDiv, $cTest]);
                            }

                            // Prepare bulk insert (preserving parents_sign & parent_phone)
                            $insStmt = $db->prepare("
                                INSERT INTO unit_test_results
                                (academic_year, std, division, test_type, roll_no, student_name, gender, subject_name, max_marks, marks_obtained, is_absent, grade, remark, attendance, parents_sign, parent_phone)
                                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                                ON DUPLICATE KEY UPDATE
                                    student_name = VALUES(student_name),
                                    gender = VALUES(gender),
                                    max_marks = VALUES(max_marks),
                                    marks_obtained = VALUES(marks_obtained),
                                    is_absent = VALUES(is_absent),
                                    grade = VALUES(grade),
                                    remark = VALUES(remark),
                                    attendance = VALUES(attendance),
                                    parents_sign = VALUES(parents_sign),
                                    parent_phone = VALUES(parent_phone)
                            ");

                            $classStudentCount = 0;
                            foreach ($parsed['students'] as $idx => $stu) {
                                $plan = $healing['rows'][$idx] ?? null;
                                $roll = $stu['roll_no'];
                                if ($applyRollHeal && $plan && !empty($plan['suggested_roll']) && !isset($claimedRolls[$plan['suggested_roll']])) {
                                    $roll = $plan['suggested_roll'];
                                    $totalHealedRolls++;
                                }
                                $claimedRolls[$roll] = true;

                                $name = mb_substr($stu['student_name'], 0, 100);
                                $gender = mb_substr($stu['gender'], 0, 10);
                                $attn = mb_substr($stu['attendance'], 0, 20);
                                $grd = mb_substr($stu['grade'], 0, 5);
                                $rmk = mb_substr($stu['remark'], 0, 150);
                                $finalSign  = $plan ? (int)$plan['final_sign'] : (int)($stu['parents_sign'] ?? 0);
                                $finalPhone = $plan ? (string)$plan['final_phone'] : (string)($stu['parent_phone'] ?? '');
                                $finalPhone = mb_substr(preg_replace('/\D+/', '', $finalPhone), 0, 32);
                                if ($plan && !empty($plan['shielded_fields'])) {
                                    $totalShieldedRows++;
                                }

                                foreach ($stu['marks'] as $sName => $sData) {
                                    $maxM = (float)$sData['max_marks'];
                                    $obtM = $sData['marks_obtained'];
                                    $isAb = (int)$sData['is_absent'];

                                    $insStmt->execute([
                                        $cYear, $cStd, $cDiv, $cTest,
                                        mb_substr((string)$roll, 0, 10), $name, $gender, mb_substr((string)$sName, 0, 50),
                                        $maxM, $obtM, $isAb, $grd, $rmk, $attn,
                                        $finalSign, ($finalPhone !== '' ? $finalPhone : null)
                                    ]);
                                }
                                $classStudentCount++;
                            }

                            $db->commit();
                            $totalImportedStudents += $classStudentCount;
                            $classesImported[] = "STD $cStd $cDiv ($classStudentCount students)";

                            if ($firstClassLink === '') {
                                $firstClassLink = "unit_tests.php?year=" . urlencode($cYear) . "&std=" . urlencode($cStd) . "&division=" . urlencode($cDiv) . "&test_type=" . urlencode($cTest);
                            }
                        } catch (\Throwable $txErr) {
                            if ($db->inTransaction()) $db->rollBack();
                            throw $txErr;
                        }
                    } catch (\Throwable $e) {
                        error_log("import_unit_tests confirm error on sheet '{$sn}': " . $e->getMessage());
                        $importErrors[] = "Sheet '{$sn}': " . $e->getMessage();
                    }
                }

                if ($totalImportedStudents === 0 && (!empty($lockedWarnings) || !empty($importErrors))) {
                    // Keep session intact so user can adjust overrides or fix issue without losing preview
                    $error = implode(' ', array_merge($lockedWarnings, $importErrors));
                } else {
                    @unlink($tmpPath);
                    unset($_SESSION['ut_file_path'], $_SESSION['ut_file_ext'], $_SESSION['ut_multi_sheets'],
                          $_SESSION['ut_skipped_sheets'], $_SESSION['ut_preview'], $_SESSION['ut_is_multi'],
                          $_SESSION['ut_confirm_token'], $_SESSION['ut_active_sheet']);
                    $multiSheets = [];
                    $isMulti = false;
                    $preview = null;

                    // Fire persistent notification with timestamp
                    $notifTitle = "Unit Test Excel Imported";
                    $notifMsg = "Imported {$totalImportedStudents} students across " . count($classesImported) . " classes into Unit Test results by {$adminUser} (Healed: {$totalHealedRolls}, Shielded: {$totalShieldedRows}).";
                    rcAddNotification($notifTitle, $notifMsg, 'unit_test', $firstClassLink);

                    $success = "Successfully imported {$totalImportedStudents} student records across " . count($classesImported) . " classes!";
                    if ($totalHealedRolls > 0 || $totalShieldedRows > 0) {
                        $success .= " (Auto-Healer fixed {$totalHealedRolls} roll(s); Metadata Shield preserved {$totalShieldedRows} phone/signature record(s).)";
                    }
                    if (!empty($lockedWarnings) || !empty($importErrors)) {
                        $success .= ' Warnings: ' . implode(' ', array_merge($lockedWarnings, $importErrors));
                    }
                    $importResult = [
                        'imported'        => $totalImportedStudents,
                        'healed'          => $totalHealedRolls,
                        'shielded'        => $totalShieldedRows,
                        'classes_count'   => count($classesImported),
                        'classes_list'    => $classesImported,
                        'first_link'      => $firstClassLink,
                    ];
                }
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
<?php require __DIR__ . '/includes/account_head.php'; ?>
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
                    <?php require __DIR__ . '/includes/notif_bell.php'; ?>

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
                        <form method="POST" enctype="multipart/form-data" id="uploadForm" data-max-bytes="<?= (int)$maxUploadBytes ?>">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="parse">

                            <label class="imp-drop" id="fileDrop" for="excelFileInput">
                                <input type="file" name="excel_file" id="excelFileInput" accept=".xlsx,.xls,.csv" required>
                                <span class="imp-drop-icon"><i class="fas fa-cloud-arrow-up"></i></span>
                                <p class="imp-drop-title">Drop your Unit Test workbook here, or <em>browse</em></p>
                                <p class="imp-drop-sub">Multi-sheet workbooks welcome — every class sheet is detected automatically.</p>
                                <span class="imp-drop-chips">
                                    <span class="imp-chip"><i class="fas fa-file-excel"></i> .xlsx / .xls / .csv</span>
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
                            <input type="hidden" name="confirm_token" value="<?= sanitize($_SESSION['ut_confirm_token'] ?? '') ?>">

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
                                    Replaces Unit Test rows already stored for these classes (Metadata Shield still preserves parent phone &amp; signatures).
                                </span>
                            </label>
                            <label class="imp-switch" for="batchAutoHeal">
                                <input type="checkbox" name="apply_roll_heal" id="batchAutoHeal" value="1" checked>
                                <span class="imp-switch-text">
                                    <strong>Auto-Heal swapped or shifted roll numbers</strong><br>
                                    Matches Excel names against active class cohorts to fix shifted roll numbers automatically.
                                </span>
                            </label>

                            <div class="imp-actions">
                                <button type="submit" class="btn btn-primary" id="batchBtn">
                                    <i class="fas fa-file-import"></i> Confirm Batch Import — <?= count($multiSheets) ?> classes
                                </button>
                                <span class="imp-spacer"></span>
                                <a href="import_unit_tests.php?fresh=1" class="btn btn-outline"><i class="fas fa-xmark"></i> Cancel &amp; Upload Another</a>
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
                    $utHealing = utAnalyzeCohortHealing(
                        $db,
                        $preview['students'],
                        (string)($preview['meta']['academic_year'] ?? rcGetActiveAcademicYear()),
                        (string)($preview['meta']['std'] ?? ''),
                        (string)($preview['meta']['division'] ?? 'A'),
                        (string)($preview['meta']['test_type'] ?? 'Unit Test 2')
                    );
                    $utBlankRows = 0;
                    $utClampedRows = 0;
                    foreach ($preview['students'] as $pu) {
                        if (!empty($pu['blank_subjects'])) $utBlankRows++;
                        if (!empty($pu['clamped_subjects'])) $utClampedRows++;
                    }
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

                        <?php if ($utHealing['heal_count'] > 0 || $utHealing['shield_count'] > 0 || $utBlankRows > 0 || $utClampedRows > 0): ?>
                        <div style="margin: 12px 0 16px; padding: 12px 16px; border-radius: 10px; background: #fffbeb; border: 1px solid #fde68a; color: #78350f; font-size: 0.85rem; display: flex; flex-wrap: wrap; gap: 12px; align-items: center;">
                            <span style="font-weight: 800;"><i class="fas fa-shield-halved" style="margin-right:6px;"></i> Auto-Healer &amp; Shield:</span>
                            <?php if ($utHealing['heal_count'] > 0): ?>
                                <span><strong><?= (int)$utHealing['heal_count'] ?></strong> roll number swap/shift suggestion(s).</span>
                            <?php endif; ?>
                            <?php if ($utHealing['shield_count'] > 0): ?>
                                <span><strong><?= (int)$utHealing['shield_count'] ?></strong> record(s) have Phone / Parent Sign preserved.</span>
                            <?php endif; ?>
                            <?php if ($utBlankRows > 0): ?>
                                <span><strong><?= (int)$utBlankRows ?></strong> student(s) have unentered blank cells (separate from AB).</span>
                            <?php endif; ?>
                            <?php if ($utClampedRows > 0): ?>
                                <span><strong><?= (int)$utClampedRows ?></strong> student(s) had marks clamped &le; max.</span>
                            <?php endif; ?>
                        </div>
                        <?php endif; ?>

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
                                            <th scope="col">Healer / Shield</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        <?php foreach (array_slice($preview['students'], 0, 25) as $idx => $stu):
                                            $hPlan = $utHealing['rows'][$idx] ?? null;
                                        ?>
                                        <tr>
                                            <td class="c-roll">
                                                <?= sanitize($stu['roll_no']) ?>
                                                <?php if ($hPlan && !empty($hPlan['suggested_roll'])): ?>
                                                    <div style="font-size:0.7rem;color:#b45309;font-weight:700;">&rarr; #<?= sanitize($hPlan['suggested_roll']) ?></div>
                                                <?php endif; ?>
                                            </td>
                                            <td class="c-name"><?= sanitize($stu['student_name']) ?></td>
                                            <?php foreach ($preview['subjects'] as $sName => $sMax): ?>
                                                <?php $m = $stu['marks'][$sName] ?? null; ?>
                                                <td class="c-c">
                                                    <?php if ($m && !empty($m['is_absent'])): ?>
                                                        <span class="is-absent" title="Absent">AB</span>
                                                    <?php elseif ($m && !empty($m['is_blank'])): ?>
                                                        <span class="is-missing" title="Blank / unentered">—</span>
                                                    <?php else: ?>
                                                        <?= sanitize((string)($m['marks_obtained'] ?? '')) ?>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endforeach; ?>
                                            <td class="c-total"><?= sanitize((string)$stu['total_obtained']) ?></td>
                                            <td class="c-c"><?= $stu['percentage'] !== null ? sanitize((string)$stu['percentage']) . '%' : '–' ?></td>
                                            <td class="c-c"><strong><?= sanitize($stu['grade']) ?></strong></td>
                                            <td><?= sanitize($stu['remark']) !== '' ? sanitize($stu['remark']) : '<span class="is-missing">—</span>' ?></td>
                                            <td class="c-c"><?= sanitize($stu['attendance']) !== '' ? sanitize($stu['attendance']) : '–' ?></td>
                                            <td style="font-size:0.75rem;">
                                                <?php if ($hPlan && !empty($hPlan['suggested_roll'])): ?>
                                                    <span class="imp-chip" style="background:#fef3c7;border-color:#f59e0b;color:#92400e;" title="<?= e_attr($hPlan['heal_reason']) ?>">Swap &rarr; #<?= sanitize($hPlan['suggested_roll']) ?></span>
                                                <?php endif; ?>
                                                <?php if ($hPlan && !empty($hPlan['shielded_fields'])): ?>
                                                    <span class="imp-chip" style="background:#ecfdf5;border-color:#6ee7b7;color:#065f46;">Keep <?= sanitize(implode('/', $hPlan['shielded_fields'])) ?></span>
                                                <?php endif; ?>
                                                <?php if (!empty($stu['clamped_subjects'])): ?>
                                                    <span class="imp-chip" style="background:#fee2e2;border-color:#fca5a5;color:#991b1b;">Clamped</span>
                                                <?php endif; ?>
                                                <?php if ((!$hPlan || (empty($hPlan['suggested_roll']) && empty($hPlan['shielded_fields']))) && empty($stu['clamped_subjects'])): ?>
                                                    <span style="color:#94a3b8;">OK</span>
                                                <?php endif; ?>
                                            </td>
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
                            <input type="hidden" name="confirm_token" value="<?= sanitize($_SESSION['ut_confirm_token'] ?? '') ?>">
                            <input type="hidden" name="selected_sheet" value="<?= sanitize($preview['sheet_name']) ?>">

                            <div style="padding: 16px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-sm); margin-bottom:16px;">
                                <div style="font-weight:600; font-size:0.9rem; margin-bottom:8px; color:var(--text-2);">
                                    <i class="fas fa-sliders-h"></i> Destination Details for this Unit Test Sheet
                                </div>
                                <div class="imp-grid">
                                    <div class="imp-field">
                                        <label>Academic Year</label>
                                        <input type="text" name="confirm_academic_year" class="form-control" value="<?= sanitize($preview['meta']['academic_year'] ?? rcGetActiveAcademicYear()) ?>" required>
                                    </div>
                                    <div class="imp-field">
                                        <label>Class / Std</label>
                                        <input type="text" name="confirm_std" class="form-control" value="<?= sanitize($preview['meta']['std'] ?: '1') ?>" required>
                                    </div>
                                    <div class="imp-field">
                                        <label>Division</label>
                                        <input type="text" name="confirm_division" class="form-control" value="<?= sanitize($preview['meta']['division'] ?: 'A') ?>" required>
                                    </div>
                                    <div class="imp-field">
                                        <label>Unit Test</label>
                                        <input type="text" name="confirm_test_type" class="form-control" value="<?= sanitize($preview['meta']['test_type'] ?: 'Unit Test 1') ?>" required>
                                    </div>
                                </div>
                            </div>

                            <label class="imp-switch" for="singleOverwrite">
                                <input type="checkbox" name="overwrite" id="singleOverwrite" value="1" checked>
                                <span class="imp-switch-text">
                                    <strong>Overwrite existing records</strong><br>
                                    Replaces Unit Test rows already stored for this class (Metadata Shield still preserves parent phone &amp; signatures).
                                </span>
                            </label>
                            <label class="imp-switch" for="singleAutoHeal">
                                <input type="checkbox" name="apply_roll_heal" id="singleAutoHeal" value="1" checked>
                                <span class="imp-switch-text">
                                    <strong>Apply Auto-Healer roll number alignments</strong><br>
                                    Aligns swapped/shifted roll numbers with existing cohort records before saving.
                                </span>
                            </label>

                            <div class="imp-actions">
                                <button type="submit" class="btn btn-primary" id="confirmBtn" data-count="<?= $pvStudents ?>">
                                    <i class="fas fa-check"></i> Confirm Import — <?= $pvStudents ?> student(s)
                                </button>
                                <span class="imp-spacer"></span>
                                <a href="import_unit_tests.php?fresh=1" class="btn btn-outline"><i class="fas fa-xmark"></i> Cancel</a>
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
