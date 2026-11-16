<?php
@ini_set('memory_limit', '512M');
@ini_set('max_execution_time', '300');

$adminPage = 'import_excel';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;

$db = getDB();
$settings = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church High School';
$classes = rcClassOptions();

/* ── Upload limits (DoS caps) ── */
const IMPORT_MAX_ROWS = 5000;
const IMPORT_MAX_COLS = 60;

/* ── 1. Text Normalization & Auto-Healer / Metadata Shield Helpers ── */
function cleanText($v): string {
    if ($v === null) return '';
    $s = trim((string)$v);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s) ?? '';
    return trim($s);
}

function normalizeStudentKey(string $name): string {
    $s = mb_strtoupper(cleanText($name), 'UTF-8');
    $s = preg_replace('/[^A-Z0-9\s]/', ' ', $s) ?? '';
    $s = preg_replace('/\s+/', ' ', $s) ?? '';
    return trim($s);
}

/* ── 2. Smart Heuristic Mark & Sign Parsers ── */
function parseMarkToken($val): array {
    if ($val === null) {
        return ['val' => 0.0, 'is_blank' => true, 'is_absent' => false];
    }
    if (is_numeric($val)) {
        $f = (float)$val;
        $num = (is_nan($f) || is_infinite($f)) ? 0.0 : $f;
        return ['val' => $num, 'is_blank' => false, 'is_absent' => false];
    }
    $s = trim((string)$val);
    if ($s === '') {
        return ['val' => 0.0, 'is_blank' => true, 'is_absent' => false];
    }
    $lower = strtolower($s);
    if (in_array($lower, ['ab', 'a', 'absent', '-', '--', '–'], true)) {
        return ['val' => 0.0, 'is_blank' => false, 'is_absent' => true];
    }
    if (in_array($lower, ['na', 'n/a', 'nil', 'null', 'none', 'exempt', 'fail'], true)) {
        return ['val' => 0.0, 'is_blank' => true, 'is_absent' => false];
    }
    if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*\/\s*[0-9]+(?:\.[0-9]+)?/', $s, $m)) {
        return ['val' => (float)$m[1], 'is_blank' => false, 'is_absent' => false];
    }
    if (preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*%/', $s, $m)) {
        return ['val' => (float)$m[1], 'is_blank' => false, 'is_absent' => false];
    }
    if (preg_match('/^([0-9]+(?:\.[0-9]+)?)/', $s, $m)) {
        return ['val' => (float)$m[1], 'is_blank' => false, 'is_absent' => false];
    }
    return ['val' => 0.0, 'is_blank' => true, 'is_absent' => false];
}

function parseSignValue($val): ?int {
    if ($val === null) return null;
    $s = strtolower(trim((string)$val));
    if ($s === '') return null;
    if (in_array($s, ['1', 'yes', 'y', 'true', 'signed'], true)) {
        return 1;
    }
    if (in_array($s, ['0', 'no', 'n', 'false', 'unsigned'], true)) {
        return 0;
    }
    return null;
}

/**
 * Auto-Healer & Metadata Shield:
 * Detects swapped/shifted roll numbers against the existing cohort in report_card / students_master,
 * and protects existing DOB, Parent Phone, and Parent Signature from accidental blank overwrites.
 */
function rcAnalyzeImportCohortHealing(PDO $db, array $students, string $year, string $std, string $div, string $sem = ''): array {
    $byRoll = [];
    $normNamesByRoll = [];

    if ($year !== '' && $std !== '' && $div !== '') {
        $stmt = $db->prepare(
            "SELECT roll_no, student_name, semester, date_of_birth, parent_phone, parents_sign
             FROM report_card
             WHERE academic_year = ? AND std = ? AND division = ?
             ORDER BY (semester = ?) DESC, id ASC"
        );
        $stmt->execute([$year, $std, $div, $sem]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $roll = (string)$r['roll_no'];
            if ($roll === '') continue;
            if (!isset($byRoll[$roll])) {
                $byRoll[$roll] = [
                    'roll_no'       => $roll,
                    'student_name'  => (string)$r['student_name'],
                    'date_of_birth' => !empty($r['date_of_birth']) ? (string)$r['date_of_birth'] : null,
                    'parent_phone'  => trim((string)($r['parent_phone'] ?? '')),
                    'parents_sign'  => (int)($r['parents_sign'] ?? 0),
                ];
            } else {
                // Fill missing metadata from sibling semester rows in the same academic year
                if (empty($byRoll[$roll]['date_of_birth']) && !empty($r['date_of_birth'])) {
                    $byRoll[$roll]['date_of_birth'] = (string)$r['date_of_birth'];
                }
                if ($byRoll[$roll]['parent_phone'] === '' && !empty($r['parent_phone'])) {
                    $byRoll[$roll]['parent_phone'] = trim((string)$r['parent_phone']);
                }
                if ((int)$byRoll[$roll]['parents_sign'] === 0 && !empty($r['parents_sign'])) {
                    $byRoll[$roll]['parents_sign'] = 1;
                }
            }
        }
    }

    // Enrich missing DOB / Phone from students_master when possible
    if (!empty($byRoll)) {
        try {
            $smStmt = $db->prepare(
                "SELECT roll_no, student_name, date_of_birth, parent_phone
                 FROM students_master
                 WHERE std = ? AND division = ?"
            );
            $smStmt->execute([$std, $div]);
            foreach ($smStmt->fetchAll(PDO::FETCH_ASSOC) as $sm) {
                $rKey = trim((string)($sm['roll_no'] ?? ''));
                if ($rKey !== '' && isset($byRoll[$rKey])) {
                    if (empty($byRoll[$rKey]['date_of_birth']) && !empty($sm['date_of_birth'])) {
                        $byRoll[$rKey]['date_of_birth'] = (string)$sm['date_of_birth'];
                    }
                    if ($byRoll[$rKey]['parent_phone'] === '' && !empty($sm['parent_phone'])) {
                        $byRoll[$rKey]['parent_phone'] = trim((string)$sm['parent_phone']);
                    }
                }
            }
        } catch (\Throwable $e) {
            // Ignore if students_master schema/rows unavailable
        }
    }

    foreach ($byRoll as $rKey => $info) {
        $normNamesByRoll[$rKey] = normalizeStudentKey($info['student_name']);
    }

    $usedHealedRolls = [];
    $plans = [];
    $healCount = 0;
    $shieldCount = 0;

    foreach ($students as $idx => $stu) {
        $xlRoll = (string)($stu['roll_no'] ?? '');
        $xlNorm = normalizeStudentKey((string)($stu['student_name'] ?? ''));
        $direct = $byRoll[$xlRoll] ?? null;
        $directSim = 0.0;
        if ($direct && $xlNorm !== '' && $normNamesByRoll[$xlRoll] !== '') {
            similar_text($xlNorm, $normNamesByRoll[$xlRoll], $directSim);
        }

        $suggestedRoll = null;
        $healReason = '';
        $healPct = 0.0;
        $metaSource = $direct;

        // If roll not in DB or DB student name at that roll differs strongly (<75%), detect shifted/swapped roll
        if ($xlNorm !== '' && (!$direct || $directSim < 75.0)) {
            $bestRoll = null;
            $bestPct = 0.0;
            foreach ($normNamesByRoll as $candRoll => $candNorm) {
                if ($candNorm === '') continue;
                similar_text($xlNorm, $candNorm, $candPct);
                if ($candPct > $bestPct) {
                    $bestPct = $candPct;
                    $bestRoll = $candRoll;
                }
            }
            if ($bestRoll !== null && $bestRoll !== $xlRoll && $bestPct >= 75.0 && !isset($usedHealedRolls[$bestRoll])) {
                $suggestedRoll = $bestRoll;
                $healPct = round($bestPct, 1);
                $healReason = $direct
                    ? "Roll #{$xlRoll} belongs to \"{$direct['student_name']}\"; \"{$stu['student_name']}\" matches Roll #{$bestRoll} ({$healPct}%)"
                    : "New/shifted roll #{$xlRoll} matches existing Roll #{$bestRoll} ({$healPct}%)";
                $metaSource = $byRoll[$bestRoll];
                $usedHealedRolls[$bestRoll] = true;
                $healCount++;
            }
        }

        // Metadata Shield: keep existing DOB / Phone / Parent Sign unless Excel supplies valid non-empty updates
        $xlDob = !empty($stu['date_of_birth']) ? rcNormalizeDateToMysql((string)$stu['date_of_birth']) : null;
        $xlPhone = trim((string)($stu['parent_phone'] ?? ''));
        $xlSign = array_key_exists('parents_sign', $stu) && $stu['parents_sign'] !== null ? (int)$stu['parents_sign'] : null;

        $dbDob = ($metaSource && !empty($metaSource['date_of_birth'])) ? (string)$metaSource['date_of_birth'] : null;
        $dbPhone = ($metaSource && $metaSource['parent_phone'] !== '') ? $metaSource['parent_phone'] : '';
        $dbSign = $metaSource ? (int)$metaSource['parents_sign'] : 0;

        $finalDob = $xlDob ?: $dbDob;
        $finalPhone = ($xlPhone !== '') ? $xlPhone : $dbPhone;
        $finalSign = ($xlSign !== null) ? $xlSign : $dbSign;

        $shieldedFields = [];
        if ($xlDob === null && $dbDob !== null) $shieldedFields[] = 'DOB';
        if ($xlPhone === '' && $dbPhone !== '') $shieldedFields[] = 'Phone';
        if ($xlSign === null && $metaSource && $dbSign === 1) $shieldedFields[] = 'Sign';
        if (!empty($shieldedFields)) $shieldCount++;

        $plans[$idx] = [
            'original_roll'   => $xlRoll,
            'suggested_roll'  => $suggestedRoll,
            'heal_reason'     => $healReason,
            'heal_pct'        => $healPct,
            'final_dob'       => $finalDob,
            'final_phone'     => $finalPhone,
            'final_sign'      => $finalSign,
            'shielded_fields' => $shieldedFields,
            'matched_existing'=> $metaSource !== null,
        ];
    }

    return [
        'rows'         => $plans,
        'heal_count'   => $healCount,
        'shield_count' => $shieldCount,
        'db_count'     => count($byRoll),
    ];
}

/* ── 3. Heuristic Metadata Extractor (Handles Roman numerals like I A, IIB, X A) ── */
function extractMetaFromText(string $text): array {
    $meta = ['academic_year' => rcGetActiveAcademicYear(), 'std' => '', 'division' => 'A', 'semester' => 'Sem 1'];
    $s = str_replace('_', ' ', trim($text));
    if ($s === '') return $meta;

    $romans = ['I'=>1,'II'=>2,'III'=>3,'IV'=>4,'V'=>5,'VI'=>6,'VII'=>7,'VIII'=>8,'IX'=>9,'X'=>10,'XI'=>11,'XII'=>12];

    // Academic year: "2024-25", "2025-26", "2024_25"
    if (preg_match('/\b(20\d{2}\s*[-_\/]\s*(?:20)?\d{2})\b/', $s, $m)) {
        $meta['academic_year'] = preg_replace('/[_\/\s]+/', '-', $m[1]);
    }
    // Semester: "Sem 1", "Semester 2", "Sem-I", "Term 1"
    if (preg_match('/(?:sem|semester|term)\s*[:.\-]?\s*([12]|I{1,2})\b/i', $s, $m)) {
        $u = strtoupper($m[1]);
        $meta['semester'] = ($u === 'I' || $u === '1') ? 'Sem 1' : 'Sem 2';
    }

    // Standard + Division with Roman numerals (e.g. "STD:- I A", "IIB", "IV A", "V   A", "X A")
    if (preg_match('/(?:std|class\s*)?[:.\-]?\s*\b(XII|XI|X|IX|VIII|VII|VI|V|IV|III|II|I)\b\s*[:.\-]?\s*([A-D])\b/i', $s, $m)) {
        $meta['std'] = (string)($romans[strtoupper($m[1])] ?? $m[1]);
        $meta['division'] = strtoupper($m[2]);
    } elseif (preg_match('/(?:std|class\s*)?[:.\-]?\s*(XII|XI|X|IX|VIII|VII|VI|V|IV|III|II|I)\s*([A-D])\b/i', $s, $m)) {
        $meta['std'] = (string)($romans[strtoupper($m[1])] ?? $m[1]);
        $meta['division'] = strtoupper($m[2]);
    } elseif (preg_match('/(?:std|class\s*)?[:.\-]?\s*\b([1-9]|1[0-2])\s*[-_ ]?\s*([A-D])\b/i', $s, $m)) {
        $meta['std'] = $m[1];
        $meta['division'] = strtoupper($m[2]);
    } elseif (preg_match('/(?:std|class\s*)?[:.\-]?\s*\b(XII|XI|X|IX|VIII|VII|VI|V|IV|III|II|I)\b/i', $s, $m)) {
        $meta['std'] = (string)($romans[strtoupper($m[1])] ?? $m[1]);
    } elseif (preg_match('/(?:std|class\s*)?[:.\-]?\s*\b([1-9]|1[0-2])\b/i', $s, $m)) {
        $meta['std'] = $m[1];
    }

    if (empty($meta['division'])) {
        if (preg_match('/\b(?:div|division|sec|section)\s*[:.\-]?\s*([A-D])\b/i', $s, $m)) {
            $meta['division'] = strtoupper($m[1]);
        }
    }

    return $meta;
}

/* ── 4. Robust Two-Tier School Worksheet Parser ── */
function parseSchoolWorksheet($sheet, string $sheetName, array $defaultMeta = []): ?array {
    $rows = [];
    $highestRow = $sheet->getHighestRow();
    $highestCol = Coordinate::columnIndexFromString($sheet->getHighestColumn());
    $scanLimit = min($highestRow, 250);

    for ($r = 1; $r <= $scanLimit; $r++) {
        $cells = [];
        $hasData = false;
        for ($c = 1; $c <= min($highestCol, IMPORT_MAX_COLS); $c++) {
            $coord = Coordinate::stringFromColumnIndex($c) . $r;
            try { $val = $sheet->getCell($coord)->getCalculatedValue(); }
            catch (\Throwable $e) { $val = $sheet->getCell($coord)->getValue(); }
            if ($val !== null && trim((string)$val) !== "") {
                $hasData = true;
                $cells[$c] = trim((string)$val);
            }
        }
        if ($hasData) $rows[$r] = $cells;
    }

    if (empty($rows)) return null;

    // 1. Meta extraction
    $metaText = $sheetName . " ";
    foreach ([1, 2, 3] as $rIdx) {
        if (isset($rows[$rIdx])) {
            foreach ($rows[$rIdx] as $val) {
                if (stripos($val, 'STD') !== false || stripos($val, 'SEMESTER') !== false || stripos($val, 'YEAR') !== false) {
                    $metaText .= " " . $val;
                }
            }
        }
    }
    $extracted = extractMetaFromText($metaText);
    $activeAcademicYear = rcGetActiveAcademicYear();
    $meta = [
        'academic_year' => (!empty($defaultMeta['academic_year'])) ? $defaultMeta['academic_year'] : (!empty($extracted['academic_year']) ? $extracted['academic_year'] : $activeAcademicYear),
        'std'           => (!empty($defaultMeta['std'])) ? $defaultMeta['std'] : (!empty($extracted['std']) ? $extracted['std'] : ''),
        'division'      => (!empty($defaultMeta['division'])) ? $defaultMeta['division'] : (!empty($extracted['division']) ? $extracted['division'] : 'A'),
        'semester'      => (!empty($defaultMeta['semester'])) ? $defaultMeta['semester'] : (!empty($extracted['semester']) ? $extracted['semester'] : 'Sem 1'),
    ];

    // 2. Header row detection (Roll No / Name of the Students)
    $headerRowIdx = null;
    foreach ($rows as $rIdx => $row) {
        foreach ($row as $val) {
            $n = preg_replace('/[^a-z]/', '', strtolower($val));
            if (in_array($n, ['rollno', 'roll', 'srno', 'sno', 'rno', 'nameofstudents', 'nameofthestudents', 'studentname'])) {
                $headerRowIdx = $rIdx;
                break 2;
            }
        }
    }
    if (!$headerRowIdx) return null;

    $headerRow = $rows[$headerRowIdx] ?? [];
    $subHeaderRow = $rows[$headerRowIdx + 1] ?? [];
    $highestColIdx = max(array_keys($headerRow + $subHeaderRow));

    $colMap = [];
    $subjects = [];
    $currSubject = "";

    for ($c = 1; $c <= $highestColIdx; $c++) {
        $h1 = $headerRow[$c] ?? "";
        $h2 = $subHeaderRow[$c] ?? "";
        $norm1 = preg_replace('/[^a-z0-9]/', '', strtolower($h1));
        $norm2 = preg_replace('/[^a-z0-9]/', '', strtolower($h2));

        if (in_array($norm1, ['rollno', 'roll', 'srno', 'sno', 'rno', 'rollnumber'], true)) {
            $colMap[$c] = 'roll_no'; $currSubject = ""; continue;
        }
        if (in_array($norm1, ['nameofthestudents', 'nameofstudents', 'studentname', 'name', 'student', 'fullname'], true)) {
            $colMap[$c] = 'student_name'; $currSubject = ""; continue;
        }
        if (in_array($norm1, ['academicyear', 'year', 'session', 'ay', 'schoolyear'], true)) {
            $colMap[$c] = 'academic_year'; $currSubject = ""; continue;
        }
        if (in_array($norm1, ['class', 'std', 'standard'], true)) {
            $colMap[$c] = 'std'; $currSubject = ""; continue;
        }
        if (in_array($norm1, ['division', 'div', 'section', 'sec'], true)) {
            $colMap[$c] = 'division'; $currSubject = ""; continue;
        }
        if (in_array($norm1, ['semester', 'sem', 'term', 'exam'], true)) {
            $colMap[$c] = 'semester'; $currSubject = ""; continue;
        }
        if (in_array($norm1, ['dob', 'dateofbirth', 'birthdate'], true)) {
            $colMap[$c] = 'date_of_birth'; $currSubject = ""; continue;
        }
        if (in_array($norm1, ['parentphone', 'phone', 'mobile', 'mobilenumber', 'whatsapp', 'contact', 'contactno'], true)) {
            $colMap[$c] = 'parent_phone'; $currSubject = ""; continue;
        }
        if (in_array($norm1, ['grno', 'gr', 'grnumber'], true)) {
            $colMap[$c] = 'gr_no'; $currSubject = ""; continue;
        }
        if (in_array($norm1, ['total', 'grandtotal', 'totalmarks', 'tot', 'status', 'result', 'passfail', 'rank', 'position'], true)) {
            $colMap[$c] = 'total'; $currSubject = ""; continue;
        }
        if (in_array($norm1, ['percentage', 'percent', 'pct'], true)) { $colMap[$c] = 'pct'; $currSubject = ""; continue; }
        if ($norm1 === 'grade') { $colMap[$c] = 'grade'; $currSubject = ""; continue; }
        if (in_array($norm1, ['remark', 'remarks', 'comment', 'comments'], true)) { $colMap[$c] = 'remark'; $currSubject = ""; continue; }
        if (in_array($norm1, ['attn', 'attendance', 'dayspresent', 'presentdays'], true)) { $colMap[$c] = 'attendance'; $currSubject = ""; continue; }
        if (in_array($norm1, ['parentssign', 'parentsign', 'parents', 'sign', 'signature', 'signed'], true)) { $colMap[$c] = 'parents_sign'; $currSubject = ""; continue; }

        if ($h1 !== "") {
            $currSubject = $h1;
        }

        if ($currSubject !== "") {
            if (preg_match('/^f(?:a)?\s*\(?([0-9]+)\)?/i', $h2, $m)) {
                $subjects[$currSubject]['fa_col'] = $c;
                $subjects[$currSubject]['fa_max'] = (float)$m[1];
            } elseif (preg_match('/^s(?:a)?\s*\(?([0-9]+)\)?/i', $h2, $m)) {
                $subjects[$currSubject]['sa_col'] = $c;
                $subjects[$currSubject]['sa_max'] = (float)$m[1];
            } elseif (is_numeric($h2) && (float)$h2 > 0 && !isset($subjects[$currSubject]['fa_col'])) {
                $subjects[$currSubject]['fa_col'] = $c;
                $subjects[$currSubject]['fa_max'] = (float)$h2;
                $subjects[$currSubject]['sa_max'] = 0.0;
            } elseif ($norm2 === '100' || $norm2 === 'total') {
                $colMap[$c] = 'subj_total';
            } elseif ($h1 !== "" && $h2 === "" && !isset($subjects[$currSubject]['fa_col'])) {
                // Single-column subject header without Row 2 sub-header
                $subjects[$currSubject]['fa_col'] = $c;
                $subjects[$currSubject]['fa_max'] = 100.0;
                $subjects[$currSubject]['sa_max'] = 0.0;
            }
        }
    }

    // Default missing max marks
    foreach ($subjects as $sName => &$sData) {
        if (!isset($sData['fa_max'])) $sData['fa_max'] = 70.0;
        if (!isset($sData['sa_max'])) $sData['sa_max'] = 30.0;
    }
    unset($sData);

    // 3. Extract student records
    $students = [];
    $dataStartRow = $headerRowIdx + 2;
    $rollCol  = array_search('roll_no', $colMap, true);
    $nameCol  = array_search('student_name', $colMap, true);
    $remCol   = array_search('remark', $colMap, true);
    $attCol   = array_search('attendance', $colMap, true);
    $sgnCol   = array_search('parents_sign', $colMap, true);
    $dobCol   = array_search('date_of_birth', $colMap, true);
    $phoneCol = array_search('parent_phone', $colMap, true);
    $yrCol    = array_search('academic_year', $colMap, true);
    $stdCol   = array_search('std', $colMap, true);
    $divCol   = array_search('division', $colMap, true);
    $semCol   = array_search('semester', $colMap, true);

    $autoRoll = 1;

    for ($r = $dataStartRow; $r <= $highestRow; $r++) {
        $rowCells = [];
        for ($c = 1; $c <= $highestColIdx; $c++) {
            $coord = Coordinate::stringFromColumnIndex($c) . $r;
            try { $val = $sheet->getCell($coord)->getCalculatedValue(); }
            catch (\Throwable $e) { $val = $sheet->getCell($coord)->getValue(); }
            if ($val !== null && trim((string)$val) !== "") {
                $rowCells[$c] = trim((string)$val);
            }
        }
        if (empty($rowCells)) continue;

        $roll = $rollCol && isset($rowCells[$rollCol]) ? cleanText($rowCells[$rollCol]) : '';
        $name = $nameCol && isset($rowCells[$nameCol]) ? cleanText($rowCells[$nameCol]) : '';

        // Ignore standalone category divider rows (BOYS, GIRLS, TOTAL, etc.) without filtering real student surnames
        if ($name === '' || preg_match('/^(BOYS|GIRLS|TOTAL|GRAND TOTAL|AVERAGE|CLASS AVERAGE|RESULT|PASS|FAIL)(?:\s*[:\-].*)?$/i', strtoupper($name))) {
            continue;
        }

        // Adopt row-level meta overrides if present
        $rowYear = ($yrCol && !empty($rowCells[$yrCol])) ? cleanText($rowCells[$yrCol]) : $meta['academic_year'];
        $rowStd  = ($stdCol && !empty($rowCells[$stdCol])) ? cleanText($rowCells[$stdCol]) : $meta['std'];
        $rowDiv  = ($divCol && !empty($rowCells[$divCol])) ? strtoupper(cleanText($rowCells[$divCol])) : $meta['division'];
        $rowSem  = ($semCol && !empty($rowCells[$semCol])) ? cleanText($rowCells[$semCol]) : $meta['semester'];
        if ($meta['std'] === '' && $rowStd !== '') $meta['std'] = $rowStd;
        if ($rowYear !== '') $meta['academic_year'] = $rowYear;
        if ($rowDiv !== '')  $meta['division'] = $rowDiv;
        if ($rowSem !== '')  $meta['semester'] = $rowSem;

        // Clean roll number
        if (preg_match('/^([0-9]+)\.0+$/', $roll, $m)) $roll = $m[1];
        if ($roll === '') {
            $roll = (string)$autoRoll;
        }
        if (is_numeric($roll)) {
            $autoRoll = max($autoRoll, (int)$roll + 1);
        } else {
            $autoRoll++;
        }

        $subjMarks = [];
        $blankSubjects = [];
        $absentSubjects = [];
        $clampedSubjects = [];

        foreach ($subjects as $sName => $sConf) {
            $faToken = parseMarkToken(isset($sConf['fa_col']) ? ($rowCells[$sConf['fa_col']] ?? null) : null);
            $saToken = isset($sConf['sa_col'])
                ? parseMarkToken($rowCells[$sConf['sa_col']] ?? null)
                : ['val' => 0.0, 'is_blank' => true, 'is_absent' => false];

            if ($faToken['is_absent'] || $saToken['is_absent']) {
                $absentSubjects[] = $sName;
            } elseif ($faToken['is_blank'] && (!isset($sConf['sa_col']) || $saToken['is_blank'])) {
                $blankSubjects[] = $sName;
            }

            $clampedFa = clampMark($faToken['val'], $sConf['fa_max']);
            $clampedSa = clampMark($saToken['val'], $sConf['sa_max']);
            if ((!$faToken['is_blank'] && !$faToken['is_absent'] && $faToken['val'] > $sConf['fa_max'])
                || (!$saToken['is_blank'] && !$saToken['is_absent'] && $saToken['val'] > $sConf['sa_max'])) {
                $clampedSubjects[] = $sName;
            }

            $subjMarks[] = [
                'subject_name' => $sName,
                'fa_max'       => $sConf['fa_max'],
                'fa_obtained'  => $clampedFa,
                'sa_max'       => $sConf['sa_max'],
                'sa_obtained'  => $clampedSa,
            ];
        }

        $rawDob = ($dobCol && isset($rowCells[$dobCol])) ? cleanText($rowCells[$dobCol]) : '';
        $normDob = $rawDob !== '' ? rcNormalizeDateToMysql($rawDob) : null;
        $rawPhone = ($phoneCol && isset($rowCells[$phoneCol])) ? cleanText($rowCells[$phoneCol]) : '';
        $parsedSign = ($sgnCol && isset($rowCells[$sgnCol])) ? parseSignValue($rowCells[$sgnCol]) : null;

        $students[] = [
            'roll_no'          => $roll,
            'student_name'     => $name,
            'academic_year'    => $rowYear,
            'std'              => $rowStd,
            'division'         => $rowDiv,
            'semester'         => $rowSem,
            'remark'           => $remCol && isset($rowCells[$remCol]) ? cleanText($rowCells[$remCol]) : '',
            'attendance'       => $attCol && isset($rowCells[$attCol]) ? cleanText($rowCells[$attCol]) : '',
            'parents_sign'     => $parsedSign,
            'date_of_birth'    => $normDob,
            'parent_phone'     => $rawPhone,
            'blank_subjects'   => $blankSubjects,
            'absent_subjects'  => $absentSubjects,
            'clamped_subjects' => $clampedSubjects,
            'subjects'         => $subjMarks,
        ];
    }

    return [
        'sheet_name' => $sheetName,
        'meta'       => $meta,
        'subjects'   => $subjects,
        'students'   => $students,
    ];
}

/* ── 5. Workbook Scanner for All Active Classes ── */
function scanWorkbookActiveSheets(string $filePath, string $ext): array {
    $reader = $ext === 'xlsx' ? new \PhpOffice\PhpSpreadsheet\Reader\Xlsx() : new \PhpOffice\PhpSpreadsheet\Reader\Xls();
    $reader->setReadDataOnly(true);
    $sheetNames = $reader->listWorksheetNames($filePath);

    $active = [];
    foreach ($sheetNames as $sn) {
        if (strpos($sn, 'Sheet') === 0 && preg_match('/^Sheet[0-9]{2,}$/', $sn)) continue;
        try {
            $reader->setLoadSheetsOnly([$sn]);
            $ss = $reader->load($filePath);
            $parsed = parseSchoolWorksheet($ss->getActiveSheet(), $sn);
            $ss->disconnectWorksheets();
            unset($ss);

            if ($parsed && count($parsed['students']) > 0) {
                $active[$sn] = [
                    'sheet_name' => $sn,
                    'class_label' => 'STD ' . ($parsed['meta']['std'] ?: '?') . ' ' . ($parsed['meta']['division'] ?: ''),
                    'std' => $parsed['meta']['std'],
                    'division' => $parsed['meta']['division'],
                    'semester' => $parsed['meta']['semester'],
                    'academic_year' => $parsed['meta']['academic_year'],
                    'students_count' => count($parsed['students']),
                    'subjects_count' => count($parsed['subjects']),
                    'sub_list' => array_keys($parsed['subjects']),
                ];
            }
        } catch (\Throwable $e) {
            // Skip unparseable sheet
        }
    }
    return $active;
}

/* ─────────────────────────────────────────────
   REQUEST HANDLERS (GET & POST)
   ────────────────────────────────────────── */

/* ── Template Download ── */
$reqAction = $_POST['action'] ?? ($_GET['action'] ?? '');
if ($reqAction === 'template') {
    $activeYear = rcGetActiveAcademicYear();
    $tpl = new Spreadsheet();
    $sheet = $tpl->getActiveSheet();
    $sheet->setTitle(mb_substr("STD I A Sem 1 {$activeYear}", 0, 31));

    // Row 1: Column Headers, Row 2: Max Marks F(70) / S(30) / 100, Row 3+: Sample Students
    $sheet->setCellValue('A1', 'Roll No');
    $sheet->setCellValue('B1', 'Name of the Students');
    $tplSubjects = ['English', 'Hindi', 'Math', 'Science', 'Social Studies', 'Marathi'];
    $colIdx = 3;
    $subjColMeta = [];
    foreach ($tplSubjects as $subName) {
        $faCol = Coordinate::stringFromColumnIndex($colIdx);
        $saCol = Coordinate::stringFromColumnIndex($colIdx + 1);
        $totCol = Coordinate::stringFromColumnIndex($colIdx + 2);
        $sheet->setCellValue($faCol . '1', $subName);
        $sheet->mergeCells("{$faCol}1:{$totCol}1");
        $sheet->setCellValue($faCol . '2', 'F(70)');
        $sheet->setCellValue($saCol . '2', 'S(30)');
        $sheet->setCellValue($totCol . '2', 100);
        $subjColMeta[] = [$faCol, $saCol, $totCol];
        $colIdx += 3;
    }
    $remCol  = Coordinate::stringFromColumnIndex($colIdx++);
    $attnCol = Coordinate::stringFromColumnIndex($colIdx++);
    $signCol = Coordinate::stringFromColumnIndex($colIdx);
    $sheet->setCellValue($remCol . '1', 'Remark');
    $sheet->setCellValue($attnCol . '1', 'Attendance');
    $sheet->setCellValue($signCol . '1', 'Parents Sign');
    $sheet->getStyle("A1:{$signCol}2")->getFont()->setBold(true);

    $samples = [
        [1, 'BAGBAN MUHAMMAD USMAN', [[50, 22], [52, 24], [58, 25], [49, 21], [54, 23], [46, 20]], 'Good progress', '48', 'Yes'],
        [2, 'ASABE MADHURA SHANKAR', [[44, 18], [48, 20], [42, 17], [40, 16], [46, 19], [41, 18]], 'Regular student', '44', 'No'],
    ];
    $rowIdx = 3;
    foreach ($samples as $s) {
        $sheet->setCellValue('A' . $rowIdx, $s[0]);
        $sheet->setCellValue('B' . $rowIdx, $s[1]);
        foreach ($subjColMeta as $sIdx => [$faCol, $saCol, $totCol]) {
            $faVal = $s[2][$sIdx][0];
            $saVal = $s[2][$sIdx][1];
            $sheet->setCellValue($faCol . $rowIdx, $faVal);
            $sheet->setCellValue($saCol . $rowIdx, $saVal);
            $sheet->setCellValue($totCol . $rowIdx, "={$faCol}{$rowIdx}+{$saCol}{$rowIdx}");
        }
        $sheet->setCellValue($remCol . $rowIdx, $s[3]);
        $sheet->setCellValue($attnCol . $rowIdx, $s[4]);
        $sheet->setCellValue($signCol . $rowIdx, $s[5]);
        $rowIdx++;
    }
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="HCC_Import_Template.xlsx"');
    header('Cache-Control: max-age=0');
    $writer = new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($tpl);
    $writer->save('php://output');
    exit;
}

/* ── Fresh Reset ── */
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !empty($_GET['fresh'])) {
    if (!empty($_SESSION['import_file_path']) && file_exists($_SESSION['import_file_path'])) {
        @unlink($_SESSION['import_file_path']);
    }
    unset($_SESSION['import_preview'], $_SESSION['import_preview_done'], $_SESSION['import_confirm_token'],
          $_SESSION['import_file_path'], $_SESSION['import_filename'], $_SESSION['import_multi_sheets'],
          $_SESSION['import_is_multi'], $_SESSION['import_active_sheet']);
    header('Location: import_excel.php');
    exit;
}

$preview       = null;
$previewDone   = !empty($_SESSION['import_preview_done']);
$storedPreview = $_SESSION['import_preview'] ?? null;
$multiSheets   = $_SESSION['import_multi_sheets'] ?? [];
$isMulti       = !empty($_SESSION['import_is_multi']);
$success       = '';
$error         = '';
$importResult  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        $error = 'Security check failed. Please reload the page and try again.';
    } else {
        $action = $_POST['action'] ?? '';

        /* ── Action: Parse File ── */
        if ($action === 'parse') {
            $file = $_FILES['excel_file'] ?? null;
            if (!$file || $file['error'] === UPLOAD_ERR_NO_FILE) {
                $error = 'No file uploaded. Please choose an Excel file.';
            } elseif ($file['error'] !== UPLOAD_ERR_OK) {
                $error = 'Upload failed (error code ' . $file['error'] . '). Try again.';
            } else {
                $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
                if (!in_array($ext, ['xlsx', 'xls'], true)) {
                    $error = 'Unsupported file format. Please upload an .xlsx or .xls file.';
                } elseif ($file['size'] > 50 * 1024 * 1024) {
                    $error = 'File exceeds the size limit.';
                } else {
                    $uploadDir = rtrim(sys_get_temp_dir(), '/\\');
                    $tmpPath = $uploadDir . DIRECTORY_SEPARATOR . 'hcc_imp_' . bin2hex(random_bytes(8)) . '.' . $ext;
                    if (move_uploaded_file($file['tmp_name'], $tmpPath)) {
                        try {
                            $activeSheets = scanWorkbookActiveSheets($tmpPath, $ext);
                            if (empty($activeSheets)) {
                                $error = 'No valid student marksheet found in this workbook. Please ensure the sheet has student names and subject columns.';
                                @unlink($tmpPath);
                            } else {
                                $confirmToken = bin2hex(random_bytes(32));
                                $_SESSION['import_file_path'] = $tmpPath;
                                $_SESSION['import_filename']  = basename($file['name']);
                                $_SESSION['import_multi_sheets'] = $activeSheets;
                                $_SESSION['import_confirm_token'] = $confirmToken;
                                $_SESSION['import_preview_done'] = true;
                                $_SESSION['import_is_multi'] = (count($activeSheets) > 1);

                                // Pick first active sheet for preview
                                $firstSheetKey = array_key_first($activeSheets);
                                $_SESSION['import_active_sheet'] = $firstSheetKey;

                                // Parse first sheet for preview
                                $reader = $ext === 'xlsx' ? new \PhpOffice\PhpSpreadsheet\Reader\Xlsx() : new \PhpOffice\PhpSpreadsheet\Reader\Xls();
                                $reader->setReadDataOnly(true);
                                $reader->setLoadSheetsOnly([$firstSheetKey]);
                                $ss = $reader->load($tmpPath);
                                $parsed = parseSchoolWorksheet($ss->getActiveSheet(), $firstSheetKey);
                                $ss->disconnectWorksheets();
                                unset($ss);

                                $_SESSION['import_preview'] = $parsed;
                                $preview = $parsed;
                                $multiSheets = $activeSheets;
                                $isMulti = (count($activeSheets) > 1);
                                $previewDone = true;
                            }
                        } catch (\Throwable $e) {
                            $error = 'Error reading workbook: ' . sanitize($e->getMessage());
                            @unlink($tmpPath);
                        }
                    } else {
                        $error = 'Failed to process uploaded file.';
                    }
                }
            }
        }

        /* ── Action: Switch Preview Sheet ── */
        elseif ($action === 'switch_sheet') {
            $sheetToView = cleanText($_POST['sheet_name'] ?? '');
            $tmpPath = $_SESSION['import_file_path'] ?? '';
            if ($tmpPath && file_exists($tmpPath) && isset($multiSheets[$sheetToView])) {
                $ext = strtolower(pathinfo($tmpPath, PATHINFO_EXTENSION));
                $reader = $ext === 'xlsx' ? new \PhpOffice\PhpSpreadsheet\Reader\Xlsx() : new \PhpOffice\PhpSpreadsheet\Reader\Xls();
                $reader->setReadDataOnly(true);
                $reader->setLoadSheetsOnly([$sheetToView]);
                $ss = $reader->load($tmpPath);
                $parsed = parseSchoolWorksheet($ss->getActiveSheet(), $sheetToView);
                $ss->disconnectWorksheets();
                unset($ss);

                $_SESSION['import_active_sheet'] = $sheetToView;
                $_SESSION['import_preview'] = $parsed;
                $preview = $parsed;
                $previewDone = true;
            }
        }

        /* ── Action: Confirm Import (Single Class or Batch All Classes) ── */
        elseif ($action === 'confirm') {
            $sessionToken = $_SESSION['import_confirm_token'] ?? '';
            $postedToken  = $_POST['confirm_token'] ?? '';
            $tmpPath      = $_SESSION['import_file_path'] ?? '';
            $confirmMode  = $_POST['confirm_mode'] ?? 'single';
            $overwrite    = !empty($_POST['overwrite']);

            if ($sessionToken === '' || $postedToken === '' || !hash_equals((string)$sessionToken, (string)$postedToken)) {
                $error = 'Invalid or expired import session. Please re-upload the file.';
            } elseif ($confirmMode === 'all_classes' && (!empty($multiSheets) && $tmpPath && file_exists($tmpPath))) {
                // BATCH IMPORT ALL DETECTED CLASSES
                $applyRollHeal = !empty($_POST['apply_roll_heal']);
                $ext = strtolower(pathinfo($tmpPath, PATHINFO_EXTENSION));
                $reader = $ext === 'xlsx' ? new \PhpOffice\PhpSpreadsheet\Reader\Xlsx() : new \PhpOffice\PhpSpreadsheet\Reader\Xls();
                $reader->setReadDataOnly(true);

                $db = getDB();
                $activeYear = rcGetActiveAcademicYear();
                $totalImported = 0;
                $totalFailed   = 0;
                $totalHealed   = 0;
                $totalShielded = 0;
                $classesImported = [];
                $lockedWarnings = [];
                $firstClassKey = '';

                foreach ($multiSheets as $sn => $sInfo) {
                    try {
                        $reader->setLoadSheetsOnly([$sn]);
                        $ss = $reader->load($tmpPath);
                        $parsed = parseSchoolWorksheet($ss->getActiveSheet(), $sn);
                        $ss->disconnectWorksheets();
                        unset($ss);

                        if (!$parsed || empty($parsed['students'])) continue;

                        $cYear = (!empty($parsed['meta']['academic_year'])) ? $parsed['meta']['academic_year'] : $activeYear;
                        $cStd  = (!empty($parsed['meta']['std'])) ? $parsed['meta']['std'] : '1';
                        $cDiv  = (!empty($parsed['meta']['division'])) ? $parsed['meta']['division'] : 'A';
                        $cSem  = (!empty($parsed['meta']['semester'])) ? $parsed['meta']['semester'] : 'Sem 1';
                        $classKey = "$cYear|$cStd|$cDiv|$cSem";

                        if (rcIsVaultLocked($cYear, $cSem)) {
                            $lockedWarnings[] = "STD $cStd $cDiv ($cYear $cSem) skipped — Academic Vault locked.";
                            $totalFailed += count($parsed['students']);
                            continue;
                        }

                        if ($firstClassKey === '') $firstClassKey = $classKey;

                        // 1. Configure subjects for this class
                        $subjConf = [];
                        foreach ($parsed['subjects'] as $subName => $subVal) {
                            $subjConf[] = [
                                'subject_name' => $subName,
                                'fa_max'       => $subVal['fa_max'] ?? 70.0,
                                'sa_max'       => $subVal['sa_max'] ?? 30.0,
                            ];
                        }
                        if (!empty($subjConf)) {
                            rcSaveClassSubjects($classKey, $subjConf);
                        }

                        // 2. Analyze Auto-Healer & Metadata Shield against existing cohort
                        $healing = rcAnalyzeImportCohortHealing($db, $parsed['students'], $cYear, $cStd, $cDiv, $cSem);
                        $claimedRolls = [];

                        // 3. Insert / update students
                        $classImp = 0;
                        foreach ($parsed['students'] as $idx => $stu) {
                            $plan = $healing['rows'][$idx] ?? null;
                            $effectiveRoll = $stu['roll_no'];
                            if ($applyRollHeal && $plan && !empty($plan['suggested_roll']) && !isset($claimedRolls[$plan['suggested_roll']])) {
                                $effectiveRoll = $plan['suggested_roll'];
                                $totalHealed++;
                            }
                            $claimedRolls[$effectiveRoll] = true;

                            if (!$overwrite) {
                                $chk = $db->prepare("SELECT 1 FROM report_card WHERE academic_year=? AND std=? AND division=? AND semester=? AND roll_no=? LIMIT 1");
                                $chk->execute([$cYear, $cStd, $cDiv, $cSem, $effectiveRoll]);
                                if ($chk->fetchColumn()) continue;
                            }

                            $finalDob   = $plan ? $plan['final_dob'] : ($stu['date_of_birth'] ?? null);
                            $finalPhone = $plan ? $plan['final_phone'] : ($stu['parent_phone'] ?? '');
                            $finalSign  = $plan ? (int)$plan['final_sign'] : (int)($stu['parents_sign'] ?? 0);
                            if ($plan && !empty($plan['shielded_fields'])) {
                                $totalShielded++;
                            }

                            $data = [
                                'academic_year' => $cYear,
                                'std'           => $cStd,
                                'division'      => $cDiv,
                                'semester'      => $cSem,
                                'roll_no'       => $effectiveRoll,
                                'student_name'  => $stu['student_name'],
                                'renamed_name'  => '',
                                'date_of_birth' => $finalDob,
                                'parent_phone'  => $finalPhone,
                                'remark'        => $stu['remark'],
                                'attendance'    => $stu['attendance'],
                                'subjects'      => $stu['subjects'],
                            ];
                            // Guard against rcSaveEntry() isset($data['parents_sign']) quirk when value is 0
                            if ($finalSign === 1) {
                                $data['parents_sign'] = 1;
                            }

                            $res = rcSaveEntry($data);
                            if ($res['ok']) {
                                $totalImported++;
                                $classImp++;
                            } else {
                                $totalFailed++;
                            }
                        }
                        $classesImported[] = "STD $cStd $cDiv ($classImp students)";
                    } catch (\Throwable $e) {
                        $totalFailed++;
                    }
                }

                @unlink($tmpPath);
                unset($_SESSION['import_preview'], $_SESSION['import_preview_done'], $_SESSION['import_confirm_token'],
                      $_SESSION['import_file_path'], $_SESSION['import_multi_sheets']);

                $importResult = [
                    'imported'        => $totalImported,
                    'failed'          => $totalFailed,
                    'healed'          => $totalHealed,
                    'shielded'        => $totalShielded,
                    'classes_count'   => count($classesImported),
                    'classes_list'    => $classesImported,
                    'locked_warnings' => $lockedWarnings,
                    'classKey'        => $firstClassKey,
                    'filename'        => $_SESSION['import_filename'] ?? 'Master Workbook',
                ];
                $success = "Batch import complete! Imported $totalImported students across " . count($classesImported) . " classes.";
                if ($totalHealed > 0 || $totalShielded > 0) {
                    $success .= " (Auto-Healer adjusted {$totalHealed} roll(s); Metadata Shield preserved {$totalShielded} record(s).)";
                }
                if (!empty($lockedWarnings)) {
                    $success .= ' ' . implode(' ', $lockedWarnings);
                }
                rcAddNotification(
                    'Excel Master Workbook Imported',
                    sprintf(
                        'Imported %d student(s) across %d class(es) from %s (Healed: %d, Shielded: %d).',
                        $totalImported,
                        count($classesImported),
                        $importResult['filename'],
                        $totalHealed,
                        $totalShielded
                    ),
                    'import',
                    $firstClassKey !== '' ? ('results.php?class=' . urlencode($firstClassKey)) : 'results.php'
                );
                $_SESSION['rc_flash'] = [
                    'type' => 'success',
                    'msg'  => sprintf('Imported %d students across %d classes successfully.', $totalImported, count($classesImported)),
                ];
            } else {
                // SINGLE CLASS IMPORT
                if (!$storedPreview || empty($storedPreview['students'])) {
                    $error = 'No pending student records found. Please upload a file.';
                } else {
                    $activeYear   = rcGetActiveAcademicYear();
                    $overrideYear = cleanText($_POST['confirm_academic_year'] ?? '');
                    $overrideStd  = cleanText($_POST['confirm_std'] ?? '');
                    $overrideDiv  = cleanText($_POST['confirm_division'] ?? '');
                    $overrideSem  = cleanText($_POST['confirm_semester'] ?? '');
                    $applyRollHeal = !empty($_POST['apply_roll_heal']);

                    $finalYear = $overrideYear ?: ($storedPreview['meta']['academic_year'] ?? $activeYear);
                    $finalStd  = $overrideStd  ?: ($storedPreview['meta']['std'] ?? '1');
                    $finalDiv  = $overrideDiv  ?: ($storedPreview['meta']['division'] ?? 'A');
                    $finalSem  = $overrideSem  ?: ($storedPreview['meta']['semester'] ?? 'Sem 1');
                    $classKey  = "$finalYear|$finalStd|$finalDiv|$finalSem";

                    if (rcIsVaultLocked($finalYear, $finalSem)) {
                        $error = "Academic year {$finalYear} ({$finalSem}) is locked in the Records Archive. Unlock it before importing.";
                    } else {
                        // Save subjects configuration
                        $subjConf = [];
                        foreach ($storedPreview['subjects'] as $subName => $subVal) {
                            $subjConf[] = [
                                'subject_name' => $subName,
                                'fa_max'       => $subVal['fa_max'] ?? 70.0,
                                'sa_max'       => $subVal['sa_max'] ?? 30.0,
                            ];
                        }
                        if (!empty($subjConf)) {
                            rcSaveClassSubjects($classKey, $subjConf);
                        }

                        $db = getDB();
                        $healing = rcAnalyzeImportCohortHealing($db, $storedPreview['students'], $finalYear, $finalStd, $finalDiv, $finalSem);
                        $claimedRolls = [];
                        $imported = 0;
                        $failed = 0;
                        $healedCount = 0;
                        $shieldedCount = 0;

                        foreach ($storedPreview['students'] as $idx => $stu) {
                            $plan = $healing['rows'][$idx] ?? null;
                            $effectiveRoll = $stu['roll_no'];
                            if ($applyRollHeal && $plan && !empty($plan['suggested_roll']) && !isset($claimedRolls[$plan['suggested_roll']])) {
                                $effectiveRoll = $plan['suggested_roll'];
                                $healedCount++;
                            }
                            $claimedRolls[$effectiveRoll] = true;

                            if (!$overwrite) {
                                $chk = $db->prepare("SELECT 1 FROM report_card WHERE academic_year=? AND std=? AND division=? AND semester=? AND roll_no=? LIMIT 1");
                                $chk->execute([$finalYear, $finalStd, $finalDiv, $finalSem, $effectiveRoll]);
                                if ($chk->fetchColumn()) continue;
                            }

                            $finalDob   = $plan ? $plan['final_dob'] : ($stu['date_of_birth'] ?? null);
                            $finalPhone = $plan ? $plan['final_phone'] : ($stu['parent_phone'] ?? '');
                            $finalSign  = $plan ? (int)$plan['final_sign'] : (int)($stu['parents_sign'] ?? 0);
                            if ($plan && !empty($plan['shielded_fields'])) {
                                $shieldedCount++;
                            }

                            $data = [
                                'academic_year' => $finalYear,
                                'std'           => $finalStd,
                                'division'      => $finalDiv,
                                'semester'      => $finalSem,
                                'roll_no'       => $effectiveRoll,
                                'student_name'  => $stu['student_name'],
                                'renamed_name'  => '',
                                'date_of_birth' => $finalDob,
                                'parent_phone'  => $finalPhone,
                                'remark'        => $stu['remark'],
                                'attendance'    => $stu['attendance'],
                                'subjects'      => $stu['subjects'],
                            ];
                            if ($finalSign === 1) {
                                $data['parents_sign'] = 1;
                            }

                            $res = rcSaveEntry($data);
                            if ($res['ok']) $imported++; else $failed++;
                        }

                        if ($tmpPath && file_exists($tmpPath)) @unlink($tmpPath);
                        unset($_SESSION['import_preview'], $_SESSION['import_preview_done'], $_SESSION['import_confirm_token'],
                              $_SESSION['import_file_path'], $_SESSION['import_multi_sheets']);

                        $importResult = [
                            'imported'  => $imported,
                            'failed'    => $failed,
                            'healed'    => $healedCount,
                            'shielded'  => $shieldedCount,
                            'classKey'  => $classKey,
                            'filename'  => $_SESSION['import_filename'] ?? 'File',
                        ];
                        $success = "Import complete! $imported student(s) imported into STD $finalStd $finalDiv.";
                        if ($healedCount > 0 || $shieldedCount > 0) {
                            $success .= " (Auto-Healer fixed {$healedCount} roll(s); Metadata Shield preserved {$shieldedCount} record(s).)";
                        }
                        rcAddNotification(
                            "Excel Imported — STD {$finalStd} {$finalDiv}",
                            sprintf(
                                'Imported %d student(s) into %s %s (%s) (Healed: %d, Shielded: %d).',
                                $imported,
                                "STD {$finalStd}-{$finalDiv}",
                                $finalSem,
                                $finalYear,
                                $healedCount,
                                $shieldedCount
                            ),
                            'import',
                            'results.php?class=' . urlencode($classKey)
                        );
                        $_SESSION['rc_flash'] = [
                            'type' => 'success',
                            'msg'  => sprintf('%d student(s) imported into STD %s %s successfully.', $imported, $finalStd, $finalDiv),
                        ];
                    }
                }
            }
        }
    }
}

// Fallback preview retrieval on GET
if ($preview === null && $previewDone && $storedPreview !== null && empty($error) && empty($success)) {
    $preview = $storedPreview;
}

$showResult  = ($importResult !== null && $success !== '');
$showPreview = (!$showResult && $previewDone && !empty($preview['students']) && empty($error));
$step = $showResult ? 3 : ($showPreview ? 2 : 1);

$adminName = sanitize($_SESSION['admin_name'] ?? 'Admin');
$adminInitials = strtoupper(implode('', array_map(fn($p) => mb_substr($p, 0, 1), array_slice(array_filter(preg_split('/\s+/', trim($_SESSION['admin_name'] ?? 'A'))), 0, 2))));

$studentCount = $preview ? count($preview['students'] ?? []) : 0;
$activeSheetName = $_SESSION['import_active_sheet'] ?? ($preview['sheet_name'] ?? '');
$previewHealing = ['rows' => [], 'heal_count' => 0, 'shield_count' => 0, 'db_count' => 0];
$previewBlankCount = 0;
$previewClampedCount = 0;
if ($showPreview && !empty($preview['students'])) {
    $previewHealing = rcAnalyzeImportCohortHealing(
        getDB(),
        $preview['students'],
        (string)($preview['meta']['academic_year'] ?? rcGetActiveAcademicYear()),
        (string)($preview['meta']['std'] ?? ''),
        (string)($preview['meta']['division'] ?? 'A'),
        (string)($preview['meta']['semester'] ?? 'Sem 1')
    );
    foreach ($preview['students'] as $ps) {
        if (!empty($ps['blank_subjects'])) $previewBlankCount++;
        if (!empty($ps['clamped_subjects'])) $previewClampedCount++;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Import Excel Results | <?= sanitize($schoolName) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin.css?v=<?= (is_file(__DIR__ . '/../assets/css/admin.css') ? filemtime(__DIR__ . '/../assets/css/admin.css') : 0) ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin-import.css?v=<?= (is_file(__DIR__ . '/../assets/css/admin-import.css') ? filemtime(__DIR__ . '/../assets/css/admin-import.css') : 0) ?>">
</head>
<body>
<div class="admin-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <main class="admin-main">
        <header class="admin-header">
            <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle navigation" aria-controls="sidebar"><i class="fas fa-bars"></i></button>
            <div class="header-titles">
                <h1 class="page-title">Import Excel Results</h1>
                <p class="page-sub">Upload marksheets, auto-detect classes across tabs, and automate results &amp; report cards.</p>
            </div>
            <div class="admin-user">
                <span class="user-avatar"><?= sanitize($adminInitials) ?></span>
                <span class="user-meta"><strong><?= sanitize($adminName) ?></strong><small>Admin</small></span>
            </div>
        </header>

        <div class="admin-content">
            <div class="imp-wrap">
                <!-- Stepper -->
                <ol class="imp-steps">
                    <?php
                    $stepCfg = [
                        1 => ['Upload File', 'Master spreadsheet or class sheet'],
                        2 => ['Review Classes', 'Multi-tab detection &amp; roster'],
                        3 => ['Import Complete', 'Database status &amp; report cards ready'],
                    ];
                    foreach ($stepCfg as $n => [$title, $sub]):
                        $cls = $n === $step ? 'imp-step active' : ($n < $step ? 'imp-step done' : 'imp-step');
                        $num = $n < $step ? '<i class="fas fa-check"></i>' : (string)$n;
                    ?>
                    <li class="<?= $cls ?>">
                        <span class="imp-step-num"><?= $num ?></span>
                        <span class="imp-step-text"><strong><?= $title ?></strong><small><?= $sub ?></small></span>
                    </li>
                    <?php endforeach; ?>
                </ol>

                <!-- STEP 3: RESULT BOX -->
                <?php if ($showResult): ?>
                    <div class="panel">
                        <div class="panel-header">
                            <h2><i class="fas fa-check-double imp-panel-icon imp-panel-icon--ok"></i> Import Complete!</h2>
                        </div>
                        <div class="panel-body">
                            <div class="imp-stats">
                                <div class="imp-stat imp-stat--ok">
                                    <span class="imp-stat-icon"><i class="fas fa-user-check"></i></span>
                                    <div>
                                        <div class="imp-stat-val"><?= (int)$importResult['imported'] ?></div>
                                        <div class="imp-stat-lbl">Students Imported</div>
                                    </div>
                                </div>
                                <?php if (!empty($importResult['classes_count'])): ?>
                                <div class="imp-stat">
                                    <span class="imp-stat-icon"><i class="fas fa-layer-group"></i></span>
                                    <div>
                                        <div class="imp-stat-val"><?= (int)$importResult['classes_count'] ?></div>
                                        <div class="imp-stat-lbl">Classes Configured</div>
                                    </div>
                                </div>
                                <?php endif; ?>
                                <div class="imp-stat <?= (int)($importResult['failed'] ?? 0) > 0 ? 'imp-stat--err' : '' ?>">
                                    <span class="imp-stat-icon"><i class="fas fa-times"></i></span>
                                    <div>
                                        <div class="imp-stat-val"><?= (int)($importResult['failed'] ?? 0) ?></div>
                                        <div class="imp-stat-lbl">Failed / Skipped</div>
                                    </div>
                                </div>
                                <?php if (!empty($importResult['healed']) || !empty($importResult['shielded'])): ?>
                                <div class="imp-stat imp-stat--ok">
                                    <span class="imp-stat-icon"><i class="fas fa-shield-halved"></i></span>
                                    <div>
                                        <div class="imp-stat-val"><?= (int)($importResult['healed'] ?? 0) ?> / <?= (int)($importResult['shielded'] ?? 0) ?></div>
                                        <div class="imp-stat-lbl">Rolls Healed / Shielded</div>
                                    </div>
                                </div>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($importResult['locked_warnings'])): ?>
                            <div class="imp-alert imp-alert--err" style="margin:12px 0;">
                                <i class="fas fa-lock"></i>
                                <div class="imp-alert-body">
                                    <?php foreach ($importResult['locked_warnings'] as $lw): ?>
                                        <div><?= sanitize($lw) ?></div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <?php if (!empty($importResult['classes_list'])): ?>
                            <div style="margin: 18px 0; padding: 14px 18px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-sm);">
                                <strong style="font-size: 0.88rem; color: var(--ink); display:block; margin-bottom: 8px;"><i class="fas fa-check-circle" style="color:var(--success); margin-right:6px;"></i> Imported Classes:</strong>
                                <div style="display:flex; flex-wrap:wrap; gap:8px;">
                                    <?php foreach ($importResult['classes_list'] as $cLabel): ?>
                                        <span class="imp-chip" style="background:#fff; border-color:var(--border); font-weight:600;"><i class="fas fa-graduation-cap"></i> <?= sanitize($cLabel) ?></span>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endif; ?>

                            <div class="imp-actions">
                                <a href="results.php<?= !empty($importResult['classKey']) ? '?class=' . e_url($importResult['classKey']) : '' ?>" class="btn btn-primary">
                                    <i class="fas fa-table"></i> Go to Results Matrix
                                </a>
                                <a href="import_excel.php?fresh=1" class="btn btn-outline">
                                    <i class="fas fa-file-excel"></i> Import Another File
                                </a>
                            </div>
                        </div>
                    </div>

                <!-- STEP 2: MULTI-SHEET OR SINGLE-SHEET PREVIEW -->
                <?php elseif ($showPreview): ?>
                    <div class="panel">
                        <div class="panel-header">
                            <h2><i class="fas fa-eye imp-panel-icon imp-panel-icon--brand"></i> Review &amp; Confirm Import</h2>
                            <span class="imp-chip"><i class="fas fa-file-excel"></i> <?= sanitize($_SESSION['import_filename'] ?? 'Workbook') ?></span>
                        </div>
                        <div class="panel-body">

                            <!-- Master Workbook Multi-Class Banner -->
                            <?php if ($isMulti && !empty($multiSheets)): 
                                $totalMultiStudents = array_sum(array_column($multiSheets, 'students_count'));
                            ?>
                                <div class="imp-alert imp-alert--ok" style="margin-bottom: 20px;">
                                    <i class="fas fa-wand-magic-sparkles"></i>
                                    <div class="imp-alert-body">
                                        <strong style="font-size:1.05rem;">Master Workbook Detected: <?= count($multiSheets) ?> Active Classes Found!</strong>
                                        <p style="margin:4px 0 0; color:var(--text-2);">
                                            Total <strong><?= $totalMultiStudents ?> students</strong> ready to import across Classes 1 through 10.
                                            You can import all classes at once in 1 click, or preview and import individual classes below.
                                        </p>
                                    </div>
                                </div>

                                <!-- Batch Import Action -->
                                <form method="POST" style="margin-bottom: 24px; padding: 20px; background: linear-gradient(135deg, #f8fafc, #eff6ff); border: 2px solid #c7d2fe; border-radius: var(--radius);">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="confirm">
                                    <input type="hidden" name="confirm_mode" value="all_classes">
                                    <input type="hidden" name="confirm_token" value="<?= sanitize($_SESSION['import_confirm_token'] ?? '') ?>">

                                    <div style="display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:16px;">
                                        <div>
                                            <h3 style="margin:0 0 4px; font-size:1.15rem; color:#1a237e;"><i class="fas fa-layer-group" style="margin-right:8px; color:#c9943e;"></i> Option 1: Complete School Automation</h3>
                                            <p style="margin:0; font-size:0.86rem; color:#475569;">Import all <?= count($multiSheets) ?> classes (<?= $totalMultiStudents ?> students) into the database simultaneously.</p>
                                        </div>
                                        <div style="display:flex; align-items:center; gap:12px; flex-wrap:wrap;">
                                            <label style="display:inline-flex; align-items:center; gap:6px; font-size:0.85rem; font-weight:600; cursor:pointer;">
                                                <input type="checkbox" name="overwrite" value="1" checked> Overwrite existing
                                            </label>
                                            <label style="display:inline-flex; align-items:center; gap:6px; font-size:0.85rem; font-weight:600; cursor:pointer;" title="Align swapped/shifted roll numbers with existing cohort and preserve DOB/Phone/Signature">
                                                <input type="checkbox" name="apply_roll_heal" value="1" checked> Auto-Heal Rolls
                                            </label>
                                            <button type="submit" class="btn btn-primary imp-btn-lg">
                                                <i class="fas fa-cloud-arrow-up"></i> Import All <?= count($multiSheets) ?> Classes Now
                                            </button>
                                        </div>
                                    </div>
                                </form>

                                <h3 style="margin: 24px 0 10px; font-size:0.95rem; font-weight:700; color:var(--text-2); text-transform:uppercase; letter-spacing:0.04em;">
                                    <i class="fas fa-chalkboard-user" style="color:var(--brand); margin-right:6px;"></i> Option 2: Detected Class Tabs (Click any to inspect roster)
                                </h3>
                                <div class="imp-classes-grid">
                                    <?php foreach ($multiSheets as $sn => $sInfo): 
                                        $isSelected = ($sn === $activeSheetName);
                                    ?>
                                        <form method="POST" style="margin:0;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="switch_sheet">
                                            <input type="hidden" name="sheet_name" value="<?= e_attr($sn) ?>">
                                            <button type="submit" class="imp-class-card <?= $isSelected ? 'is-selected' : '' ?>" style="width:100%; text-align:left; cursor:pointer; background:<?= $isSelected ? '#fff' : 'var(--surface)' ?>;">
                                                <div class="imp-class-card-hdr">
                                                    <span class="imp-class-title"><?= sanitize($sInfo['class_label']) ?></span>
                                                    <span class="imp-class-badge"><?= $sInfo['students_count'] ?> stu</span>
                                                </div>
                                                <div class="imp-class-sub">
                                                    <span><i class="fas fa-book"></i> <?= $sInfo['subjects_count'] ?> subjects</span>
                                                    <span><i class="fas fa-calendar-alt"></i> <?= sanitize($sInfo['semester']) ?> (<?= sanitize($sInfo['academic_year']) ?>)</span>
                                                </div>
                                            </button>
                                        </form>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>

                            <!-- Auto-Healer & Metadata Shield Summary Banner -->
                            <?php if ($previewHealing['heal_count'] > 0 || $previewHealing['shield_count'] > 0 || $previewBlankCount > 0 || $previewClampedCount > 0): ?>
                            <div style="margin: 12px 0 16px; padding: 14px 18px; border-radius: 10px; background: #fffbeb; border: 1px solid #fde68a; color: #78350f; font-size: 0.86rem; display: flex; flex-wrap: wrap; gap: 14px; align-items: center;">
                                <span style="font-weight: 800;"><i class="fas fa-shield-halved" style="margin-right:6px;"></i> Auto-Healer &amp; Metadata Shield Active:</span>
                                <?php if ($previewHealing['heal_count'] > 0): ?>
                                    <span><strong><?= (int)$previewHealing['heal_count'] ?></strong> swapped/shifted roll match(es) detected via fuzzy name alignment.</span>
                                <?php endif; ?>
                                <?php if ($previewHealing['shield_count'] > 0): ?>
                                    <span><strong><?= (int)$previewHealing['shield_count'] ?></strong> student(s) have existing DOB / Phone / Parent Sign protected from blank overwrite.</span>
                                <?php endif; ?>
                                <?php if ($previewBlankCount > 0): ?>
                                    <span><strong><?= (int)$previewBlankCount ?></strong> student(s) have unentered blank mark cells.</span>
                                <?php endif; ?>
                                <?php if ($previewClampedCount > 0): ?>
                                    <span><strong><?= (int)$previewClampedCount ?></strong> student(s) had out-of-range marks clamped to subject max.</span>
                                <?php endif; ?>
                            </div>
                            <?php endif; ?>

                            <!-- Current Inspected Class Header -->
                            <div style="margin: 20px 0 12px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px;">
                                <h3 style="margin:0; font-size:1.05rem; font-weight:700; color:var(--ink);">
                                    <i class="fas fa-user-graduate" style="color:var(--brand); margin-right:8px;"></i> Class Preview: STD <?= sanitize($preview['meta']['std'] ?: '?') ?> <?= sanitize($preview['meta']['division'] ?: '') ?> (<?= sanitize($preview['sheet_name']) ?>)
                                </h3>
                                <span class="imp-chip"><i class="fas fa-users"></i> <?= $studentCount ?> Student<?= $studentCount !== 1 ? 's' : '' ?></span>
                            </div>

                            <!-- Students Table Preview -->
                            <div class="imp-table-wrap">
                                <div class="imp-table-scroll">
                                    <table class="imp-table">
                                        <thead>
                                            <tr>
                                                <th scope="col" class="col-roll">Roll</th>
                                                <th scope="col">Student Name</th>
                                                <th scope="col">Class &amp; Division</th>
                                                <th scope="col">Semester</th>
                                                <th scope="col" class="is-num">Subjects</th>
                                                <th scope="col">Auto-Healer &amp; Shield</th>
                                                <th scope="col">Remark / Status</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            <?php foreach (array_slice($preview['students'], 0, 30) as $idx => $s):
                                                $hPlan = $previewHealing['rows'][$idx] ?? null;
                                            ?>
                                            <tr>
                                                <td class="c-roll">
                                                    <?= sanitize($s['roll_no']) ?>
                                                    <?php if ($hPlan && !empty($hPlan['suggested_roll'])): ?>
                                                        <div style="font-size:0.7rem;color:#b45309;font-weight:700;">&rarr; #<?= sanitize($hPlan['suggested_roll']) ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="c-name" style="min-width:220px; font-weight:600;"><?= sanitize($s['student_name']) ?></td>
                                                <td>STD <?= sanitize($s['std']) ?> <?= sanitize($s['division']) ?></td>
                                                <td><?= sanitize($s['semester']) ?> (<?= sanitize($s['academic_year']) ?>)</td>
                                                <td class="c-num"><?= count($s['subjects']) ?></td>
                                                <td style="font-size:0.76rem;">
                                                    <?php if ($hPlan && !empty($hPlan['suggested_roll'])): ?>
                                                        <span class="imp-chip" style="background:#fef3c7;border-color:#f59e0b;color:#92400e;" title="<?= e_attr($hPlan['heal_reason']) ?>">
                                                            <i class="fas fa-wand-magic-sparkles"></i> Swap &rarr; #<?= sanitize($hPlan['suggested_roll']) ?> (<?= (float)$hPlan['heal_pct'] ?>%)
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ($hPlan && !empty($hPlan['shielded_fields'])): ?>
                                                        <span class="imp-chip" style="background:#ecfdf5;border-color:#6ee7b7;color:#065f46;">
                                                            <i class="fas fa-shield-halved"></i> Keep <?= sanitize(implode(', ', $hPlan['shielded_fields'])) ?>
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($s['blank_subjects'])): ?>
                                                        <span class="imp-chip" style="background:#f1f5f9;border-color:#cbd5e1;color:#475569;" title="<?= e_attr('Blank: ' . implode(', ', $s['blank_subjects'])) ?>">
                                                            <?= count($s['blank_subjects']) ?> blank
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($s['clamped_subjects'])): ?>
                                                        <span class="imp-chip" style="background:#fee2e2;border-color:#fca5a5;color:#991b1b;" title="<?= e_attr('Clamped: ' . implode(', ', $s['clamped_subjects'])) ?>">
                                                            <?= count($s['clamped_subjects']) ?> clamped
                                                        </span>
                                                    <?php endif; ?>
                                                    <?php if ((!$hPlan || (empty($hPlan['suggested_roll']) && empty($hPlan['shielded_fields']))) && empty($s['blank_subjects']) && empty($s['clamped_subjects'])): ?>
                                                        <span style="color:#94a3b8;">OK</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td><?= sanitize($s['remark'] ?: '—') ?></td>
                                            </tr>
                                            <?php endforeach; ?>
                                        </tbody>
                                    </table>
                                </div>
                                <div class="imp-table-foot">
                                    <span>Showing <?= min($studentCount, 30) ?> of <?= $studentCount ?> student(s) in this class.</span>
                                </div>
                            </div>

                            <!-- Single Class Import Confirmation Form -->
                            <form method="POST" id="confirmSingleForm" style="margin-top:20px;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="confirm">
                                <input type="hidden" name="confirm_mode" value="single">
                                <input type="hidden" name="confirm_token" value="<?= sanitize($_SESSION['import_confirm_token'] ?? '') ?>">

                                <div style="padding: 16px; background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius-sm); margin-bottom:16px;">
                                    <div style="font-weight:600; font-size:0.9rem; margin-bottom:8px; color:var(--text-2);">
                                        <i class="fas fa-sliders-h"></i> Destination Details for this Class
                                    </div>
                                    <div class="imp-grid">
                                        <div class="imp-field">
                                            <label>Academic Year</label>
                                            <input type="text" name="confirm_academic_year" class="form-control" value="<?= sanitize($preview['meta']['academic_year']) ?>" required>
                                        </div>
                                        <div class="imp-field">
                                            <label>Class / Std</label>
                                            <input type="text" name="confirm_std" class="form-control" value="<?= sanitize($preview['meta']['std']) ?>" required>
                                        </div>
                                        <div class="imp-field">
                                            <label>Division</label>
                                            <input type="text" name="confirm_division" class="form-control" value="<?= sanitize($preview['meta']['division']) ?>" required>
                                        </div>
                                        <div class="imp-field">
                                            <label>Semester</label>
                                            <input type="text" name="confirm_semester" class="form-control" value="<?= sanitize($preview['meta']['semester']) ?>" required>
                                        </div>
                                    </div>
                                    <div style="display:flex; flex-wrap:wrap; gap:18px; margin-top:14px; font-size:0.86rem; font-weight:600;">
                                        <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                                            <input type="checkbox" name="overwrite" value="1" checked> Overwrite existing marks (Metadata Shield keeps existing DOB/Phone/Signature)
                                        </label>
                                        <label style="display:inline-flex; align-items:center; gap:6px; cursor:pointer;">
                                            <input type="checkbox" name="apply_roll_heal" value="1" checked> Apply Auto-Healer roll number alignments
                                        </label>
                                    </div>
                                </div>

                                <div class="imp-actions">
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-file-import"></i> Confirm &amp; Import STD <?= sanitize($preview['meta']['std']) ?> <?= sanitize($preview['meta']['division']) ?> (<?= $studentCount ?> students)
                                    </button>
                                    <a href="import_excel.php?fresh=1" class="btn btn-outline"><i class="fas fa-rotate-left"></i> Start Over</a>
                                    <span class="imp-spacer"></span>
                                    <a href="results.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Cancel</a>
                                </div>
                            </form>

                        </div>
                    </div>

                <!-- STEP 1: UPLOAD FILE -->
                <?php else: ?>

                <?php if ($error !== ''): ?>
                    <div class="imp-alert imp-alert--err">
                        <i class="fas fa-exclamation-triangle"></i>
                        <div class="imp-alert-body">
                            <strong>Could not read the file:</strong> <?= sanitize($error) ?>
                        </div>
                    </div>
                <?php endif; ?>

                <form id="uploadForm" method="POST" enctype="multipart/form-data">
                    <?= csrfField() ?>
                    <input type="hidden" name="action" value="parse">

                    <div class="panel">
                        <div class="panel-header">
                            <h2><i class="fas fa-file-excel imp-panel-icon imp-panel-icon--ok"></i> Step 1 — Choose Your Master Spreadsheet</h2>
                        </div>
                        <div class="panel-body">
                            <label class="imp-drop" id="fileDrop" for="excelFile">
                                <input type="file" name="excel_file" id="excelFile" accept=".xlsx,.xls" onchange="document.getElementById('parseBtn').disabled = !this.files.length; if(this.files.length) document.getElementById('fileNameText').textContent = this.files[0].name;">
                                <span class="imp-drop-icon"><i class="fas fa-cloud-arrow-up"></i></span>
                                <p class="imp-drop-title">Drop your Master Excel / Marksheet here, or <em>browse</em></p>
                                <p class="imp-drop-sub" id="fileNameText">Supports multi-class workbooks (Std 1 to 10 with separate tabs) as well as single-class templates.</p>
                                <span class="imp-drop-chips">
                                    <span class="imp-chip"><i class="fas fa-file-excel"></i> .xlsx / .xls</span>
                                    <span class="imp-chip"><i class="fas fa-layer-group"></i> Multi-Sheet Auto-Scan</span>
                                    <span class="imp-chip"><i class="fas fa-users"></i> Long names &amp; absent marks supported</span>
                                </span>
                            </label>

                            <div class="imp-legend">
                                <div class="imp-legend-item">
                                    <strong><i class="fas fa-layer-group"></i> All 1st–10th Tabs Supported</strong>
                                    <span>Automatically identifies all classes (`I A` to `X C`), subjects, and max marks.</span>
                                </div>
                                <div class="imp-legend-item">
                                    <strong><i class="fas fa-user-check"></i> Clean Name Extraction</strong>
                                    <span>Long names up to 100 characters preserved; section headers (BOYS, GIRLS) safely filtered out.</span>
                                </div>
                                <div class="imp-legend-item">
                                    <strong><i class="fas fa-bolt"></i> Live Marks Matrix Integration</strong>
                                    <span>Sets up your classes instantly so you can type marks and print report cards right away.</span>
                                </div>
                            </div>

                            <div class="imp-actions">
                                <button type="submit" class="btn btn-primary" id="parseBtn" disabled>
                                    <i class="fas fa-magnifying-glass"></i> Preview Workbook &amp; Classes
                                </button>
                                <a href="import_excel.php?action=template" class="btn btn-outline" download="HCC_Import_Template.xlsx">
                                    <i class="fas fa-download"></i> Download Blank Template
                                </a>
                                <span class="imp-spacer"></span>
                                <a href="results.php" class="btn btn-outline"><i class="fas fa-arrow-left"></i> Back to Results</a>
                            </div>
                        </div>
                    </div>
                </form>
                <?php endif; ?>

            </div>
        </div>
    </main>
</div>
<script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= (is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 0) ?>"></script>
</body>
</html>
