<?php
/**
 * HCC Automation System — At-Risk & Sudden Drop Radar (Automation #2)
 * Cross-analyzes Unit Test vs Semester Report Cards to flag:
 *  - Sudden Drop (>= threshold% drop from UT to Semester)
 *  - Exam-Anxiety Split (High FA >= 60% yet Fail SA < 33%/35%)
 *  - Repeated Fail across both UT and Semester
 * Includes 1-click Bilingual PTM WhatsApp Draft staging (Send-on-Press) & formula-safe CSV export.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

$adminPage  = 'at_risk_radar';
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

// Helpers
$normalizeName = static function (?string $name): string {
    $s = mb_strtolower(trim((string)$name), 'UTF-8');
    $s = preg_replace('/[^a-z0-9\s]/u', ' ', $s);
    return trim((string)preg_replace('/\s+/', ' ', (string)$s));
};

$normalizeSubjectKey = static function (?string $subj): string {
    $s = mb_strtolower(trim((string)$subj), 'UTF-8');
    $s = preg_replace('/[^a-z0-9]+/u', '', $s);
    $aliases = [
        'mathematics' => 'maths',
        'math' => 'maths',
        'algebra' => 'algebram1',
        'algebram1' => 'algebram1',
        'generalscience' => 'science',
        'genralscience' => 'science',
        'sci' => 'science',
        'computerscience' => 'compsci',
        'computer' => 'compsci',
        'it' => 'compsci',
        'english' => 'eng',
        'marathi' => 'marathi',
        'hindi' => 'hindi',
        'evs1' => 'evsi',
        'evsi' => 'evsi',
        'evs2' => 'evsii',
        'evsii' => 'evsii',
        'historycivics' => 'history',
        'socialstudies' => 'socialscience',
    ];
    return $aliases[$s] ?? $s;
};

$romanMap = ['1'=>'I','2'=>'II','3'=>'III','4'=>'IV','5'=>'V','6'=>'VI','7'=>'VII','8'=>'VIII','9'=>'IX','10'=>'X'];

// Dynamic filter options
$yearOptions = rcGetAcademicYearOptions();
$activeYear  = rcGetActiveAcademicYear();
$selectedYear = trim((string)($_GET['year'] ?? $activeYear));
if ($selectedYear === '' && !empty($yearOptions)) {
    $selectedYear = $yearOptions[0];
}

// Available standards, divisions, UTs, Semesters for selected year (with clean fallbacks)
$stdOptions = [];
$divOptions = [];
$utOptions  = [];
$semOptions = [];

try {
    $st = $db->prepare("SELECT DISTINCT std FROM report_card WHERE academic_year = ?
                        UNION SELECT DISTINCT std FROM unit_test_results WHERE academic_year = ?");
    $st->execute([$selectedYear, $selectedYear]);
    $stdOptions = array_values(array_filter(array_map('trim', $st->fetchAll(PDO::FETCH_COLUMN))));
    usort($stdOptions, static fn($a, $b) => ((int)$a <=> (int)$b) ?: strcmp($a, $b));
} catch (Throwable $e) {}

try {
    $st = $db->prepare("SELECT DISTINCT division FROM report_card WHERE academic_year = ?
                        UNION SELECT DISTINCT division FROM unit_test_results WHERE academic_year = ?");
    $st->execute([$selectedYear, $selectedYear]);
    $divOptions = array_values(array_filter(array_map('trim', $st->fetchAll(PDO::FETCH_COLUMN))));
    sort($divOptions);
} catch (Throwable $e) {}

try {
    $st = $db->prepare("SELECT DISTINCT test_type FROM unit_test_results WHERE academic_year = ? ORDER BY test_type");
    $st->execute([$selectedYear]);
    $utOptions = array_values(array_filter(array_map('trim', $st->fetchAll(PDO::FETCH_COLUMN))));
} catch (Throwable $e) {}
$defaultUtPool = ['Unit Test 1', 'Unit Test 2', 'Unit Test 3', 'Unit Test 4'];
foreach ($defaultUtPool as $utItem) {
    if (!in_array($utItem, $utOptions, true)) {
        $utOptions[] = $utItem;
    }
}

try {
    $st = $db->prepare("SELECT DISTINCT semester FROM report_card WHERE academic_year = ? ORDER BY semester");
    $st->execute([$selectedYear]);
    $semOptions = array_values(array_filter(array_map('trim', $st->fetchAll(PDO::FETCH_COLUMN))));
} catch (Throwable $e) {}
foreach (['Sem 1', 'Sem 2'] as $semItem) {
    if (!in_array($semItem, $semOptions, true)) {
        $semOptions[] = $semItem;
    }
}

$utAliasMap = [
    'UT1' => 'Unit Test 1',
    'UT2' => 'Unit Test 2',
    'UT3' => 'Unit Test 3',
    'UT4' => 'Unit Test 4',
];

$selectedStd  = trim((string)($_GET['std'] ?? ''));
$selectedDiv  = trim((string)($_GET['division'] ?? ''));
$rawUt        = trim((string)($_GET['ut'] ?? ''));
if ($rawUt === '') {
    $selectedUt = $utOptions[0] ?? 'Unit Test 1';
} else {
    $upperUt = strtoupper($rawUt);
    $selectedUt = (isset($utAliasMap[$upperUt]) && !in_array($rawUt, $utOptions, true))
        ? $utAliasMap[$upperUt]
        : $rawUt;
}
$selectedSem  = trim((string)($_GET['semester'] ?? ($semOptions[0] ?? 'Sem 1')));
$threshold    = max(1, min(60, (float)($_GET['threshold'] ?? 12)));
$flagFilter   = trim((string)($_GET['flag'] ?? 'all'));
$searchQuery  = trim((string)($_GET['search'] ?? ''));

// Fetch Unit Test rows
$utStudents = []; // key => student aggregate
$utByName   = []; // "std|div|normName" => key

try {
    $utWhere  = ['academic_year = ?', 'test_type = ?'];
    $utParams = [$selectedYear, $selectedUt];
    if ($selectedStd !== '') { $utWhere[] = 'std = ?'; $utParams[] = $selectedStd; }
    if ($selectedDiv !== '') { $utWhere[] = 'division = ?'; $utParams[] = $selectedDiv; }

    $utSql = "SELECT std, division, roll_no, student_name, subject_name, max_marks, marks_obtained, is_absent, parent_phone
              FROM unit_test_results
              WHERE " . implode(' AND ', $utWhere) . "
              ORDER BY CAST(std AS UNSIGNED), division, CAST(roll_no AS UNSIGNED), id";
    $utStmt = $db->prepare($utSql);
    $utStmt->execute($utParams);

    while ($r = $utStmt->fetch(PDO::FETCH_ASSOC)) {
        $rollNorm = ltrim(trim((string)$r['roll_no']), '0');
        if ($rollNorm === '') $rollNorm = trim((string)$r['roll_no']);
        $key = $r['std'] . '|' . $r['division'] . '|' . $rollNorm;
        if (!isset($utStudents[$key])) {
            $utStudents[$key] = [
                'std'          => trim((string)$r['std']),
                'division'     => trim((string)$r['division']),
                'roll_no'      => trim((string)$r['roll_no']),
                'student_name' => trim((string)$r['student_name']),
                'norm_name'    => $normalizeName($r['student_name']),
                'parent_phone' => trim((string)($r['parent_phone'] ?? '')),
                'obtained'     => 0.0,
                'max'          => 0.0,
                'subjects'     => [],
            ];
            $nameKey = $r['std'] . '|' . $r['division'] . '|' . $utStudents[$key]['norm_name'];
            if ($utStudents[$key]['norm_name'] !== '') {
                $utByName[$nameKey] = $key;
            }
        }
        $max = (float)($r['max_marks'] ?? 0);
        $obt = !empty($r['is_absent']) ? 0.0 : (float)($r['marks_obtained'] ?? 0);
        if ($max > 0) {
            $utStudents[$key]['obtained'] += $obt;
            $utStudents[$key]['max']      += $max;
            $subjKey = $normalizeSubjectKey($r['subject_name']);
            $utStudents[$key]['subjects'][$subjKey] = [
                'name'   => trim((string)$r['subject_name']),
                'obt'    => $obt,
                'max'    => $max,
                'pct'    => round(($obt / $max) * 100, 1),
                'absent' => !empty($r['is_absent']),
            ];
        }
        if ($utStudents[$key]['parent_phone'] === '' && !empty($r['parent_phone'])) {
            $utStudents[$key]['parent_phone'] = trim((string)$r['parent_phone']);
        }
    }
} catch (Throwable $e) {
    error_log('at_risk_radar UT query error: ' . $e->getMessage());
}

// Fetch Semester Report Card rows
$semStudents = [];
try {
    $rcWhere  = ['academic_year = ?', 'semester = ?'];
    $rcParams = [$selectedYear, $selectedSem];
    if ($selectedStd !== '') { $rcWhere[] = 'std = ?'; $rcParams[] = $selectedStd; }
    if ($selectedDiv !== '') { $rcWhere[] = 'division = ?'; $rcParams[] = $selectedDiv; }

    $rcSql = "SELECT std, division, roll_no, student_name, subject_name, fa_max, fa_obtained, sa_max, sa_obtained, parent_phone
              FROM report_card
              WHERE " . implode(' AND ', $rcWhere) . "
              ORDER BY CAST(std AS UNSIGNED), division, CAST(roll_no AS UNSIGNED), id";
    $rcStmt = $db->prepare($rcSql);
    $rcStmt->execute($rcParams);

    while ($r = $rcStmt->fetch(PDO::FETCH_ASSOC)) {
        $rollNorm = ltrim(trim((string)$r['roll_no']), '0');
        if ($rollNorm === '') $rollNorm = trim((string)$r['roll_no']);
        $key = $r['std'] . '|' . $r['division'] . '|' . $rollNorm;
        if (!isset($semStudents[$key])) {
            $semStudents[$key] = [
                'std'          => trim((string)$r['std']),
                'division'     => trim((string)$r['division']),
                'roll_no'      => trim((string)$r['roll_no']),
                'student_name' => trim((string)$r['student_name']),
                'norm_name'    => $normalizeName($r['student_name']),
                'parent_phone' => trim((string)($r['parent_phone'] ?? '')),
                'fa_obt'       => 0.0,
                'fa_max'       => 0.0,
                'sa_obt'       => 0.0,
                'sa_max'       => 0.0,
                'tot_obt'      => 0.0,
                'tot_max'      => 0.0,
                'subjects'     => [],
            ];
        }
        $faMax = (float)($r['fa_max'] ?? 0);
        $faObt = (float)($r['fa_obtained'] ?? 0);
        $saMax = (float)($r['sa_max'] ?? 0);
        $saObt = (float)($r['sa_obtained'] ?? 0);
        $tMax  = $faMax + $saMax;
        $tObt  = $faObt + $saObt;

        if ($tMax > 0) {
            $semStudents[$key]['fa_obt']  += $faObt;
            $semStudents[$key]['fa_max']  += $faMax;
            $semStudents[$key]['sa_obt']  += $saObt;
            $semStudents[$key]['sa_max']  += $saMax;
            $semStudents[$key]['tot_obt'] += $tObt;
            $semStudents[$key]['tot_max'] += $tMax;

            $subjKey = $normalizeSubjectKey($r['subject_name']);
            $semStudents[$key]['subjects'][$subjKey] = [
                'name'   => trim((string)$r['subject_name']),
                'fa_obt' => $faObt,
                'fa_max' => $faMax,
                'sa_obt' => $saObt,
                'sa_max' => $saMax,
                'fa_pct' => $faMax > 0 ? round(($faObt / $faMax) * 100, 1) : null,
                'sa_pct' => $saMax > 0 ? round(($saObt / $saMax) * 100, 1) : null,
                'pct'    => round(($tObt / $tMax) * 100, 1),
            ];
        }
        if ($semStudents[$key]['parent_phone'] === '' && !empty($r['parent_phone'])) {
            $semStudents[$key]['parent_phone'] = trim((string)$r['parent_phone']);
        }
    }
} catch (Throwable $e) {
    error_log('at_risk_radar report_card query error: ' . $e->getMessage());
}

// Fallback phone lookup from students_master when report_card/UT lacks phone
$masterPhones = [];
try {
    $mpStmt = $db->prepare("SELECT std, division, roll_no, parent_phone FROM students_master WHERE academic_year = ? AND parent_phone <> ''");
    $mpStmt->execute([$selectedYear]);
    while ($mp = $mpStmt->fetch(PDO::FETCH_ASSOC)) {
        $rn = ltrim(trim((string)$mp['roll_no']), '0') ?: trim((string)$mp['roll_no']);
        $masterPhones[$mp['std'] . '|' . $mp['division'] . '|' . $rn] = trim((string)$mp['parent_phone']);
    }
} catch (Throwable $e) {}

// Cross-analyze matched cohorts
$flaggedRows = [];
$counts = [
    'scanned'       => count($semStudents),
    'matched_ut'    => 0,
    'total_flagged' => 0,
    'sudden_drop'   => 0,
    'exam_anxiety'  => 0,
    'repeated_fail' => 0,
];

foreach ($semStudents as $key => $sem) {
    if ($sem['tot_max'] <= 0) continue;

    // Match UT record primarily by (std, division, roll_no) and verify/fallback by normalized student_name
    $ut = null;
    if (isset($utStudents[$key])) {
        $cand = $utStudents[$key];
        if ($cand['norm_name'] === $sem['norm_name'] || $cand['norm_name'] === '' || $sem['norm_name'] === '') {
            $ut = $cand;
        } else {
            // Check if name exists under another roll in same std+div
            $nameKey = $sem['std'] . '|' . $sem['division'] . '|' . $sem['norm_name'];
            $ut = isset($utByName[$nameKey]) ? $utStudents[$utByName[$nameKey]] : $cand;
        }
    } else {
        $nameKey = $sem['std'] . '|' . $sem['division'] . '|' . $sem['norm_name'];
        if (isset($utByName[$nameKey])) {
            $ut = $utStudents[$utByName[$nameKey]];
        }
    }

    if ($ut !== null && $ut['max'] > 0) {
        $counts['matched_ut']++;
    }

    $passCutoff = ((int)$sem['std'] >= 9) ? 35.0 : 33.0;
    $semPct     = round(($sem['tot_obt'] / $sem['tot_max']) * 100, 1);
    $utPct      = ($ut !== null && $ut['max'] > 0) ? round(($ut['obtained'] / $ut['max']) * 100, 1) : null;
    $overallDrop = ($utPct !== null) ? round($utPct - $semPct, 1) : null;

    $aggFaPct = $sem['fa_max'] > 0 ? round(($sem['fa_obt'] / $sem['fa_max']) * 100, 1) : null;
    $aggSaPct = $sem['sa_max'] > 0 ? round(($sem['sa_obt'] / $sem['sa_max']) * 100, 1) : null;

    // 1. Sudden Drop detection (overall OR individual subjects)
    $isSuddenDrop = ($overallDrop !== null && $overallDrop >= $threshold);
    $subjectDrops = [];
    if ($ut !== null) {
        foreach ($sem['subjects'] as $sKey => $sInfo) {
            if (isset($ut['subjects'][$sKey])) {
                $uSub = $ut['subjects'][$sKey];
                $sDrop = round($uSub['pct'] - $sInfo['pct'], 1);
                if ($sDrop >= $threshold) {
                    $subjectDrops[] = [
                        'subject' => $sInfo['name'],
                        'ut_pct'  => $uSub['pct'],
                        'sem_pct' => $sInfo['pct'],
                        'drop'    => $sDrop,
                    ];
                }
            }
        }
        usort($subjectDrops, static fn($a, $b) => $b['drop'] <=> $a['drop']);
    }

    // 2. Exam-Anxiety Split (FA >= 60% AND SA < passCutoff)
    $anxietySubjects = [];
    foreach ($sem['subjects'] as $sInfo) {
        if ($sInfo['fa_max'] > 0 && $sInfo['sa_max'] > 0) {
            if ($sInfo['fa_pct'] >= 60.0 && $sInfo['sa_pct'] < $passCutoff) {
                $anxietySubjects[] = [
                    'subject' => $sInfo['name'],
                    'fa_pct'  => $sInfo['fa_pct'],
                    'sa_pct'  => $sInfo['sa_pct'],
                ];
            }
        }
    }
    $isExamAnxiety = !empty($anxietySubjects) || ($aggFaPct !== null && $aggSaPct !== null && $aggFaPct >= 60.0 && $aggSaPct < $passCutoff);

    // 3. Repeated Fail (failing core/same subject in BOTH UT and Semester, or overall fail in both)
    $repeatedSubjects = [];
    if ($ut !== null) {
        foreach ($sem['subjects'] as $sKey => $sInfo) {
            if ($sInfo['pct'] < $passCutoff && isset($ut['subjects'][$sKey]) && $ut['subjects'][$sKey]['pct'] < $passCutoff) {
                $repeatedSubjects[] = [
                    'subject' => $sInfo['name'],
                    'ut_pct'  => $ut['subjects'][$sKey]['pct'],
                    'sem_pct' => $sInfo['pct'],
                ];
            }
        }
    }
    $isRepeatedFail = !empty($repeatedSubjects) || ($utPct !== null && $utPct < $passCutoff && $semPct < $passCutoff);

    if (!$isSuddenDrop && !$isExamAnxiety && !$isRepeatedFail) {
        continue;
    }

    $counts['total_flagged']++;
    if ($isSuddenDrop)  $counts['sudden_drop']++;
    if ($isExamAnxiety) $counts['exam_anxiety']++;
    if ($isRepeatedFail) $counts['repeated_fail']++;

    if ($flagFilter === 'sudden_drop' && !$isSuddenDrop) continue;
    if ($flagFilter === 'exam_anxiety' && !$isExamAnxiety) continue;
    if ($flagFilter === 'repeated_fail' && !$isRepeatedFail) continue;

    if ($searchQuery !== '') {
        $hay = mb_strtolower($sem['roll_no'] . ' ' . $sem['student_name']);
        if (mb_strpos($hay, mb_strtolower($searchQuery)) === false) continue;
    }

    $phone = $sem['parent_phone'] !== '' ? $sem['parent_phone'] : ($ut['parent_phone'] ?? '');
    if ($phone === '' && isset($masterPhones[$key])) {
        $phone = $masterPhones[$key];
    }

    $severity = ($isSuddenDrop ? 30 : 0) + ($isExamAnxiety ? 25 : 0) + ($isRepeatedFail ? 35 : 0) + max(0, (float)($overallDrop ?? 0));

    $flaggedRows[] = [
        'std'               => $sem['std'],
        'division'          => $sem['division'],
        'roll_no'           => $sem['roll_no'],
        'student_name'      => $sem['student_name'],
        'parent_phone'      => $phone,
        'pass_cutoff'       => $passCutoff,
        'ut_pct'            => $utPct,
        'sem_pct'           => $semPct,
        'overall_drop'      => $overallDrop,
        'agg_fa_pct'        => $aggFaPct,
        'agg_sa_pct'        => $aggSaPct,
        'is_sudden_drop'    => $isSuddenDrop,
        'is_exam_anxiety'   => $isExamAnxiety,
        'is_repeated_fail'  => $isRepeatedFail,
        'subject_drops'     => $subjectDrops,
        'anxiety_subjects'  => $anxietySubjects,
        'repeated_subjects' => $repeatedSubjects,
        'class_key'         => rcBuildClassKey($selectedYear, $sem['std'], $sem['division'], $selectedSem),
        'severity'          => $severity,
    ];
}

usort($flaggedRows, static fn($a, $b) => ($b['severity'] <=> $a['severity']) ?: ((int)$a['std'] <=> (int)$b['std']) ?: ((int)$a['roll_no'] <=> (int)$b['roll_no']));

// Formula-safe CSV export
if (isset($_GET['action']) && $_GET['action'] === 'export_csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="HCC_AtRisk_Radar_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $selectedYear . '_' . $selectedSem) . '.csv"');
    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF");
    $writeCsv = static function ($fp, array $cells): void {
        fputcsv($fp, array_map(static fn($v) => rcCsvSafeCell((string)$v), $cells));
    };
    $writeCsv($out, [$schoolName . ' — At-Risk Student Radar']);
    $writeCsv($out, ["Academic Year: {$selectedYear}", "UT: {$selectedUt}", "Semester: {$selectedSem}", "Threshold: {$threshold}%"]);
    $writeCsv($out, []);
    $writeCsv($out, ['Std', 'Div', 'Roll No', 'Student Name', 'Parent Phone', 'UT %', 'Semester %', 'Drop (%)', 'FA %', 'SA %', 'Flags', 'Key Subject Signals']);

    foreach ($flaggedRows as $row) {
        $flags = [];
        if ($row['is_sudden_drop'])  $flags[] = 'Sudden Drop';
        if ($row['is_exam_anxiety']) $flags[] = 'Exam-Anxiety Split (High FA / Fail SA)';
        if ($row['is_repeated_fail']) $flags[] = 'Repeated Fail';

        $sigs = [];
        foreach ($row['subject_drops'] as $sd) {
            $sigs[] = "{$sd['subject']} (-{$sd['drop']}%)";
        }
        foreach ($row['anxiety_subjects'] as $ax) {
            $sigs[] = "{$ax['subject']} (FA {$ax['fa_pct']}% / SA {$ax['sa_pct']}%)";
        }
        foreach ($row['repeated_subjects'] as $rf) {
            $sigs[] = "{$rf['subject']} (UT {$rf['ut_pct']}% / Sem {$rf['sem_pct']}%)";
        }

        $writeCsv($out, [
            $row['std'],
            $row['division'],
            $row['roll_no'],
            $row['student_name'],
            $row['parent_phone'],
            $row['ut_pct'] !== null ? $row['ut_pct'] . '%' : 'N/A',
            $row['sem_pct'] . '%',
            $row['overall_drop'] !== null ? $row['overall_drop'] . '%' : 'N/A',
            $row['agg_fa_pct'] !== null ? $row['agg_fa_pct'] . '%' : 'N/A',
            $row['agg_sa_pct'] !== null ? $row['agg_sa_pct'] . '%' : 'N/A',
            implode(' | ', $flags),
            implode('; ', array_unique($sigs)),
        ]);
    }
    fclose($out);
    exit;
}

$exportUrl = '?' . http_build_query([
    'year'      => $selectedYear,
    'std'       => $selectedStd,
    'division'  => $selectedDiv,
    'ut'        => $selectedUt,
    'semester'  => $selectedSem,
    'threshold' => $threshold,
    'flag'      => $flagFilter,
    'search'    => $searchQuery,
    'action'    => 'export_csv',
]);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>At-Risk Student Radar | <?= sanitize($schoolName) ?></title>
    <link rel="icon" href="<?= BASE_URL ?>assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap@5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin.css?v=<?= is_file(__DIR__ . '/../assets/css/admin.css') ? filemtime(__DIR__ . '/../assets/css/admin.css') : 1 ?>">
    <style>
        .ops-wrap { padding: 22px 26px 40px; }
        .ops-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(195px, 1fr)); gap: 14px; margin-bottom: 18px; }
        .ops-kpi-card { background: #fff; border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; box-shadow: var(--shadow-sm); display: flex; align-items: center; gap: 12px; }
        .ops-kpi-icon { width: 42px; height: 42px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
        .ops-kpi-val { font-size: 1.4rem; font-weight: 800; color: var(--ink); line-height: 1.1; }
        .ops-kpi-lbl { font-size: 0.74rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-2); margin-top: 2px; }
        .ops-panel { background: #fff; border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); padding: 18px 20px; margin-bottom: 18px; }
        .ops-filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(175px, 1fr)); gap: 12px; align-items: end; }
        .ops-field label { display: block; font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-2); margin-bottom: 5px; }
        .ops-pill { display: inline-flex; align-items: center; gap: 5px; padding: 3px 9px; border-radius: 999px; font-size: 0.72rem; font-weight: 700; white-space: nowrap; }
        .ops-pill-danger { background: #fef2f2; color: #b91c1c; border: 1px solid #fecaca; }
        .ops-pill-warn { background: #fffbeb; color: #b45309; border: 1px solid #fde68a; }
        .ops-pill-purple { background: #f5f3ff; color: #6d28d9; border: 1px solid #ddd6fe; }
        .ops-pill-muted { background: #f1f5f9; color: #475569; border: 1px solid #cbd5e1; }
        .ops-subj-chip { display: inline-flex; align-items: center; gap: 4px; padding: 2px 8px; border-radius: 6px; font-size: 0.74rem; font-weight: 600; margin: 2px 3px 2px 0; background: #fff5f5; color: #991b1b; border: 1px solid #fecaca; }
        .ops-subj-chip.ax { background: #fffbeb; color: #92400e; border-color: #fde68a; }
        .ops-subj-chip.rf { background: #f3e8ff; color: #6b21a8; border-color: #e9d5ff; }
        .btn-hcc { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; font-size: 0.84rem; font-weight: 600; border: 1px solid transparent; cursor: pointer; text-decoration: none; transition: all 0.16s ease; }
        .btn-hcc-primary { background: var(--brand); color: #fff; }
        .btn-hcc-primary:hover { background: var(--brand-dark); color: #fff; }
        .btn-hcc-outline { background: #fff; color: var(--ink); border-color: var(--border); }
        .btn-hcc-outline:hover { border-color: var(--brand); color: var(--brand); }
        .btn-hcc-wa { background: #16a34a; color: #fff; }
        .btn-hcc-wa:hover { background: #15803d; color: #fff; }
        .ops-table th { font-size: 0.74rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-2); background: var(--surface); border-bottom: 2px solid var(--border); padding: 10px 12px; white-space: nowrap; }
        .ops-table td { padding: 11px 12px; vertical-align: top; border-bottom: 1px solid var(--border); font-size: 0.85rem; }
        .ops-table td:nth-child(1) { min-width: 190px; }
        .ops-table td:nth-child(2), .ops-table td:nth-child(3), .ops-table td:nth-child(4), .ops-table td:nth-child(5) { white-space: nowrap; }
        .ops-table td:nth-child(6) { min-width: 170px; }
        .ops-table td:nth-child(7) { min-width: 240px; max-width: 360px; }
        .ops-table td:last-child { white-space: nowrap; }
        .ops-subj-chip { white-space: nowrap; }
        .ops-table-meta { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 6px 16px; }
        .ops-modal-backdrop { position: fixed; inset: 0; background: rgba(15, 23, 42, 0.6); backdrop-filter: blur(3px); z-index: 9999; display: none; align-items: center; justify-content: center; padding: 18px; }
        .ops-modal-backdrop.is-open { display: flex; }
        .ops-modal-card { background: #fff; border-radius: 14px; max-width: 620px; width: 100%; box-shadow: 0 24px 60px rgba(0,0,0,0.28); overflow: hidden; }
        .ops-modal-head { background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%); color: #fff; padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; }
        .ops-modal-body { padding: 18px 20px; }
        .ops-modal-foot { padding: 12px 20px; background: var(--surface); border-top: 1px solid var(--border); display: flex; gap: 10px; justify-content: flex-end; flex-wrap: wrap; }
        @media print {
            .admin-sidebar, .admin-header, .no-print { display: none !important; }
            .admin-main { margin-left: 0 !important; }
            .ops-wrap { padding: 0 !important; }
            .ops-panel { box-shadow: none !important; border: 1px solid #cbd5e1 !important; }
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
                <h1 class="page-title"><i class="fa-solid fa-wave-square text-danger me-1"></i> At-Risk Student Radar</h1>
                <p class="page-sub">Unit Test vs Semester drop detection, High FA / Fail SA split diagnostics &amp; 1-click PTM WhatsApp staging.</p>
            </div>
            <div class="header-actions no-print">
                <a href="<?= htmlspecialchars($exportUrl, ENT_QUOTES, 'UTF-8') ?>" class="btn-hcc btn-hcc-outline"><i class="fa-solid fa-file-csv text-success"></i> Export CSV</a>
                <button type="button" class="btn-hcc btn-hcc-outline" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
                <div class="admin-user" title="<?= sanitize($adminUser) ?> — <?= sanitize($adminRole) ?>">
                    <span class="user-avatar" aria-hidden="true"><?= sanitize($adminInitials) ?></span>
                    <span class="user-meta"><strong><?= sanitize($adminUser) ?></strong><small><?= sanitize($adminRole) ?></small></span>
                </div>
            </div>
        </header>

        <div class="ops-wrap">
            <!-- KPI Summary Row -->
            <div class="ops-kpi-grid">
                <div class="ops-kpi-card" style="border-left:4px solid #b91c1c;">
                    <span class="ops-kpi-icon" style="background:#fef2f2;color:#b91c1c;"><i class="fa-solid fa-triangle-exclamation"></i></span>
                    <div>
                        <div class="ops-kpi-val"><?= (int)$counts['total_flagged'] ?></div>
                        <div class="ops-kpi-lbl">Total At-Risk Flagged</div>
                    </div>
                </div>
                <div class="ops-kpi-card" style="border-left:4px solid #dc2626;">
                    <span class="ops-kpi-icon" style="background:#fee2e2;color:#dc2626;"><i class="fa-solid fa-arrow-trend-down"></i></span>
                    <div>
                        <div class="ops-kpi-val"><?= (int)$counts['sudden_drop'] ?></div>
                        <div class="ops-kpi-lbl">Sudden Drop (&ge;<?= htmlspecialchars((string)$threshold, ENT_QUOTES, 'UTF-8') ?>%)</div>
                    </div>
                </div>
                <div class="ops-kpi-card" style="border-left:4px solid #d97706;">
                    <span class="ops-kpi-icon" style="background:#fffbeb;color:#b45309;"><i class="fa-solid fa-brain"></i></span>
                    <div>
                        <div class="ops-kpi-val"><?= (int)$counts['exam_anxiety'] ?></div>
                        <div class="ops-kpi-lbl">High FA / Fail SA Split</div>
                    </div>
                </div>
                <div class="ops-kpi-card" style="border-left:4px solid #7c3aed;">
                    <span class="ops-kpi-icon" style="background:#f5f3ff;color:#6d28d9;"><i class="fa-solid fa-rotate-left"></i></span>
                    <div>
                        <div class="ops-kpi-val"><?= (int)$counts['repeated_fail'] ?></div>
                        <div class="ops-kpi-lbl">Repeated Fail (UT + Sem)</div>
                    </div>
                </div>
                <div class="ops-kpi-card" style="border-left:4px solid #0284c7;">
                    <span class="ops-kpi-icon" style="background:#f0f9ff;color:#0369a1;"><i class="fa-solid fa-users"></i></span>
                    <div>
                        <div class="ops-kpi-val"><?= (int)$counts['matched_ut'] ?> / <?= (int)$counts['scanned'] ?></div>
                        <div class="ops-kpi-lbl">UT-Matched / Sem Roster</div>
                    </div>
                </div>
            </div>

            <!-- Filter Bar -->
            <form method="GET" class="ops-panel no-print">
                <div class="ops-filter-grid">
                    <div class="ops-field">
                        <label for="fYear">Academic Year</label>
                        <select id="fYear" name="year" class="form-select form-select-sm">
                            <?php foreach ($yearOptions as $yr): ?>
                                <option value="<?= htmlspecialchars($yr, ENT_QUOTES, 'UTF-8') ?>" <?= $yr === $selectedYear ? 'selected' : '' ?>><?= htmlspecialchars($yr, ENT_QUOTES, 'UTF-8') ?></option>
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
                        <label for="fUt">Unit Test Baseline</label>
                        <select id="fUt" name="ut" class="form-select form-select-sm">
                            <?php foreach ($utOptions as $ut): ?>
                                <option value="<?= htmlspecialchars($ut, ENT_QUOTES, 'UTF-8') ?>" <?= $ut === $selectedUt ? 'selected' : '' ?>><?= htmlspecialchars($ut, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ops-field">
                        <label for="fSem">Semester Target</label>
                        <select id="fSem" name="semester" class="form-select form-select-sm">
                            <?php foreach ($semOptions as $sm): ?>
                                <option value="<?= htmlspecialchars($sm, ENT_QUOTES, 'UTF-8') ?>" <?= $sm === $selectedSem ? 'selected' : '' ?>><?= htmlspecialchars($sm, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="ops-field">
                        <label for="fThresh">Drop Threshold (%)</label>
                        <input id="fThresh" type="number" name="threshold" min="1" max="60" step="1" value="<?= htmlspecialchars((string)$threshold, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-sm">
                    </div>
                    <div class="ops-field">
                        <label for="fFlag">Diagnostic Bucket</label>
                        <select id="fFlag" name="flag" class="form-select form-select-sm">
                            <option value="all" <?= $flagFilter === 'all' ? 'selected' : '' ?>>All At-Risk Signals</option>
                            <option value="sudden_drop" <?= $flagFilter === 'sudden_drop' ? 'selected' : '' ?>>Sudden Drop (&ge;<?= htmlspecialchars((string)$threshold, ENT_QUOTES, 'UTF-8') ?>%)</option>
                            <option value="exam_anxiety" <?= $flagFilter === 'exam_anxiety' ? 'selected' : '' ?>>Exam-Anxiety Split</option>
                            <option value="repeated_fail" <?= $flagFilter === 'repeated_fail' ? 'selected' : '' ?>>Repeated Fail</option>
                        </select>
                    </div>
                    <div class="ops-field">
                        <label for="fSearch">Roll / Student</label>
                        <input id="fSearch" type="text" name="search" value="<?= htmlspecialchars($searchQuery, ENT_QUOTES, 'UTF-8') ?>" placeholder="Name or roll..." class="form-control form-control-sm">
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" class="btn-hcc btn-hcc-primary btn-sm w-100 justify-content-center"><i class="fa-solid fa-magnifying-glass"></i> Analyze</button>
                        <a href="at_risk_radar.php" class="btn-hcc btn-hcc-outline btn-sm" title="Reset"><i class="fa-solid fa-rotate-left"></i></a>
                    </div>
                </div>
            </form>

            <!-- Results Table -->
            <div class="ops-panel p-0 overflow-hidden">
                <div class="ops-table-meta px-3 py-2 border-bottom bg-light">
                    <div class="small fw-bold text-secondary">
                        Showing <span class="text-dark"><?= count($flaggedRows) ?></span> flagged student(s) &bull; Comparing <strong><?= htmlspecialchars($selectedUt, ENT_QUOTES, 'UTF-8') ?></strong> &rarr; <strong><?= htmlspecialchars($selectedSem, ENT_QUOTES, 'UTF-8') ?></strong> (<?= htmlspecialchars($selectedYear, ENT_QUOTES, 'UTF-8') ?>)
                    </div>
                    <div class="small text-muted">Pass cutoff: 33% (Std I&ndash;VIII) / 35% (Std IX&ndash;X)</div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover mb-0 ops-table">
                        <thead>
                            <tr>
                                <th>Student</th>
                                <th>Class</th>
                                <th><?= htmlspecialchars($selectedUt, ENT_QUOTES, 'UTF-8') ?> %</th>
                                <th><?= htmlspecialchars($selectedSem, ENT_QUOTES, 'UTF-8') ?> %</th>
                                <th>FA / SA Split</th>
                                <th>Risk Flags</th>
                                <th>Subject-by-Subject Signals</th>
                                <th class="no-print text-end">PTM Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($flaggedRows)): ?>
                            <tr>
                                <td colspan="8" class="text-center py-5 text-muted">
                                    <i class="fa-solid fa-circle-check fa-2x text-success mb-2 d-block"></i>
                                    <strong>No at-risk alerts found for the current filters.</strong>
                                    <div class="small mt-1">Try lowering the drop threshold or verifying Unit Test / Semester data is uploaded for <?= htmlspecialchars($selectedYear, ENT_QUOTES, 'UTF-8') ?>.</div>
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($flaggedRows as $idx => $row):
                                $stdLabel = 'STD ' . ($romanMap[$row['std']] ?? $row['std']) . ' ' . $row['division'];
                                $signalParts = [];
                                foreach (array_slice($row['subject_drops'], 0, 3) as $sd) {
                                    $signalParts[] = "{$sd['subject']} (UT {$sd['ut_pct']}% -> Sem {$sd['sem_pct']}%, drop {$sd['drop']}%)";
                                }
                                foreach (array_slice($row['anxiety_subjects'], 0, 3) as $ax) {
                                    $signalParts[] = "{$ax['subject']} (FA {$ax['fa_pct']}% / SA {$ax['sa_pct']}%)";
                                }
                                foreach (array_slice($row['repeated_subjects'], 0, 3) as $rf) {
                                    $signalParts[] = "{$rf['subject']} (Repeated < {$row['pass_cutoff']}%)";
                                }
                                $ptmPayload = [
                                    'student_name'  => $row['student_name'],
                                    'roll_no'       => $row['roll_no'],
                                    'class_label'   => $stdLabel,
                                    'year'          => $selectedYear,
                                    'ut_name'       => $selectedUt,
                                    'sem_name'      => $selectedSem,
                                    'ut_pct'        => $row['ut_pct'],
                                    'sem_pct'       => $row['sem_pct'],
                                    'overall_drop'  => $row['overall_drop'],
                                    'agg_fa_pct'    => $row['agg_fa_pct'],
                                    'agg_sa_pct'    => $row['agg_sa_pct'],
                                    'phone'         => $row['parent_phone'],
                                    'signals'       => implode('; ', array_unique($signalParts)),
                                    'wa_hub_url'    => 'whatsapp_share.php?doc_type=report_card&class=' . rawurlencode($row['class_key']) . '&selected_rolls=' . rawurlencode($row['roll_no']) . '&rolls=' . rawurlencode($row['roll_no']),
                                ];
                            ?>
                            <tr>
                                <td>
                                    <div class="fw-bold text-dark"><?= htmlspecialchars($row['student_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="small text-muted">
                                        Roll #<?= htmlspecialchars($row['roll_no'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php if ($row['parent_phone'] !== ''): ?>
                                            &bull; <i class="fa-solid fa-phone fa-xs"></i> <?= htmlspecialchars($row['parent_phone'], ENT_QUOTES, 'UTF-8') ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><span class="badge text-bg-light border"><?= htmlspecialchars($stdLabel, ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td>
                                    <?php if ($row['ut_pct'] !== null): ?>
                                        <span class="fw-bold"><?= number_format($row['ut_pct'], 1) ?>%</span>
                                    <?php else: ?>
                                        <span class="text-muted">No UT</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="fw-bold <?= $row['sem_pct'] < $row['pass_cutoff'] ? 'text-danger' : 'text-dark' ?>"><?= number_format($row['sem_pct'], 1) ?>%</span>
                                    <?php if ($row['overall_drop'] !== null && $row['overall_drop'] > 0): ?>
                                        <div class="small text-danger fw-bold">&darr; <?= number_format($row['overall_drop'], 1) ?>%</div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($row['agg_fa_pct'] !== null && $row['agg_sa_pct'] !== null): ?>
                                        <div class="small">FA: <strong><?= number_format($row['agg_fa_pct'], 1) ?>%</strong></div>
                                        <div class="small <?= $row['agg_sa_pct'] < $row['pass_cutoff'] ? 'text-danger fw-bold' : '' ?>">SA: <strong><?= number_format($row['agg_sa_pct'], 1) ?>%</strong></div>
                                    <?php else: ?>
                                        <span class="text-muted small">&mdash;</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-1">
                                        <?php if ($row['is_sudden_drop']): ?>
                                            <span class="ops-pill ops-pill-danger"><i class="fa-solid fa-arrow-trend-down"></i> Sudden Drop</span>
                                        <?php endif; ?>
                                        <?php if ($row['is_exam_anxiety']): ?>
                                            <span class="ops-pill ops-pill-warn"><i class="fa-solid fa-brain"></i> Exam-Anxiety Split</span>
                                        <?php endif; ?>
                                        <?php if ($row['is_repeated_fail']): ?>
                                            <span class="ops-pill ops-pill-purple"><i class="fa-solid fa-rotate-left"></i> Repeated Fail</span>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php foreach ($row['subject_drops'] as $sd): ?>
                                        <span class="ops-subj-chip" title="UT vs Semester drop">
                                            <?= htmlspecialchars($sd['subject'], ENT_QUOTES, 'UTF-8') ?>: <?= number_format($sd['ut_pct'], 0) ?>%&rarr;<?= number_format($sd['sem_pct'], 0) ?>% (-<?= number_format($sd['drop'], 0) ?>%)
                                        </span>
                                    <?php endforeach; ?>
                                    <?php foreach ($row['anxiety_subjects'] as $ax): ?>
                                        <span class="ops-subj-chip ax" title="High Formative, Low Summative">
                                            <?= htmlspecialchars($ax['subject'], ENT_QUOTES, 'UTF-8') ?>: FA <?= number_format($ax['fa_pct'], 0) ?>% / SA <?= number_format($ax['sa_pct'], 0) ?>%
                                        </span>
                                    <?php endforeach; ?>
                                    <?php foreach ($row['repeated_subjects'] as $rf): ?>
                                        <span class="ops-subj-chip rf" title="Failed in both UT and Semester">
                                            <?= htmlspecialchars($rf['subject'], ENT_QUOTES, 'UTF-8') ?>: Fail Both
                                        </span>
                                    <?php endforeach; ?>
                                </td>
                                <td class="no-print text-end">
                                    <button type="button"
                                            class="btn-hcc btn-hcc-wa btn-sm js-stage-ptm"
                                            data-ptm="<?= htmlspecialchars((string)json_encode($ptmPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>">
                                        <i class="fa-brands fa-whatsapp"></i> Stage PTM Invite
                                    </button>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </main>
</div>

<!-- Stage PTM WhatsApp Invite Modal (Send-on-Press only) -->
<div class="ops-modal-backdrop" id="ptmInviteModal" aria-hidden="true" role="dialog" aria-modal="true">
    <div class="ops-modal-card">
        <div class="ops-modal-head">
            <div>
                <h5 class="mb-0 fw-bold"><i class="fa-brands fa-whatsapp me-1"></i> Stage Bilingual PTM WhatsApp Invite</h5>
                <small id="ptmModalSubtitle" style="opacity:0.88;">Review and edit before sending manually</small>
            </div>
            <button type="button" class="btn btn-sm text-white fs-5 border-0" id="ptmModalClose" aria-label="Close">&times;</button>
        </div>
        <div class="ops-modal-body">
            <div class="row g-2 mb-3">
                <div class="col-md-6">
                    <label class="form-label small fw-bold mb-1">Parent Phone</label>
                    <input type="text" id="ptmPhoneInput" class="form-control form-control-sm" placeholder="10-digit WhatsApp number">
                </div>
                <div class="col-md-6">
                    <label class="form-label small fw-bold mb-1">PTM Slot (Optional)</label>
                    <input type="text" id="ptmDateInput" class="form-control form-control-sm" value="Tomorrow, 8:30 AM – 10:30 AM">
                </div>
            </div>
            <label for="ptmMessageBox" class="form-label small fw-bold mb-1">Polite Bilingual Invitation Draft (English + Marathi)</label>
            <textarea id="ptmMessageBox" rows="9" class="form-control form-control-sm" style="font-family:inherit;line-height:1.45;"></textarea>
            <div class="small text-muted mt-2"><i class="fa-solid fa-shield-halved me-1"></i> Send-on-Press safety: Messages are staged for your review and never auto-sent.</div>
        </div>
        <div class="ops-modal-foot">
            <button type="button" class="btn-hcc btn-hcc-outline btn-sm" id="ptmCopyBtn"><i class="fa-regular fa-copy"></i> Copy Draft</button>
            <a href="#" id="ptmHubLink" class="btn-hcc btn-hcc-outline btn-sm"><i class="fa-solid fa-up-right-from-square"></i> Open in WhatsApp Hub</a>
            <a href="#" id="ptmWaLink" target="_blank" rel="noopener noreferrer" class="btn-hcc btn-hcc-wa btn-sm"><i class="fa-brands fa-whatsapp"></i> Open WhatsApp Chat</a>
        </div>
    </div>
</div>

<script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 1 ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const modal     = document.getElementById('ptmInviteModal');
    const closeBtn  = document.getElementById('ptmModalClose');
    const phoneIn   = document.getElementById('ptmPhoneInput');
    const slotIn    = document.getElementById('ptmDateInput');
    const msgBox    = document.getElementById('ptmMessageBox');
    const subEl     = document.getElementById('ptmModalSubtitle');
    const copyBtn   = document.getElementById('ptmCopyBtn');
    const waLink    = document.getElementById('ptmWaLink');
    const hubLink   = document.getElementById('ptmHubLink');
    const school    = <?= e_js($schoolName) ?>;
    let activePtm   = null;

    function buildDraft(data) {
        const slot = (slotIn.value || 'Tomorrow, 8:30 AM – 10:30 AM').trim();
        const perfLine = data.ut_pct !== null
            ? `${data.ut_name}: ${data.ut_pct}% -> ${data.sem_name}: ${data.sem_pct}%`
            : `${data.sem_name}: ${data.sem_pct}% (FA: ${data.agg_fa_pct ?? '—'}%, SA: ${data.agg_sa_pct ?? '—'}%)`;
        const focusLine = data.signals ? `Focus areas: ${data.signals}.` : 'We want to review subject-wise progress together.';

        return `Dear Parent,\nGreetings from ${school}.\n\nWe noticed a recent dip in ${data.student_name}'s (${data.class_label}, Roll #${data.roll_no}) academic assessment (${perfLine}). ${focusLine}\n\nWe warmly invite you for a short Parent-Teacher Meeting (${slot}) to plan personalized study support.\n\n---\nआदरणीय पालक,\n${school} तर्फे नमस्कार.\n\nआपल्या पाल्याची (${data.student_name}, ${data.class_label}, हजेरी क्र. ${data.roll_no}) नुकत्याच झालेल्या परीक्षेतील कामगिरी (${perfLine}) सुधारण्यासाठी आणि अभ्यासाचे मार्गदर्शन करण्यासाठी आपल्याला पालक-शिक्षक भेटीसाठी (${slot}) निमंत्रित करण्यात येत आहे. कृपया वेळेत उपस्थित राहावे ही विनंती.`;
    }

    function updateWaHref() {
        const digits = (phoneIn.value || '').replace(/\D+/g, '');
        const normalized = digits.length === 10 ? ('91' + digits) : digits;
        const encoded = encodeURIComponent(msgBox.value || '');
        waLink.href = normalized.length >= 10
            ? `https://wa.me/${normalized}?text=${encoded}`
            : `https://wa.me/?text=${encoded}`;
    }

    document.querySelectorAll('.js-stage-ptm').forEach((btn) => {
        btn.addEventListener('click', () => {
            try {
                activePtm = JSON.parse(btn.getAttribute('data-ptm') || '{}');
            } catch (e) {
                activePtm = {};
            }
            subEl.textContent = `${activePtm.student_name || ''} • ${activePtm.class_label || ''} (Roll #${activePtm.roll_no || ''})`;
            phoneIn.value = activePtm.phone || '';
            msgBox.value = buildDraft(activePtm);
            hubLink.href = activePtm.wa_hub_url || 'whatsapp_share.php';
            updateWaHref();
            modal.classList.add('is-open');
            modal.setAttribute('aria-hidden', 'false');
        });
    });

    [phoneIn, msgBox].forEach((el) => el.addEventListener('input', updateWaHref));
    slotIn.addEventListener('input', () => {
        if (activePtm) {
            msgBox.value = buildDraft(activePtm);
            updateWaHref();
        }
    });

    copyBtn.addEventListener('click', async () => {
        try {
            await navigator.clipboard.writeText(msgBox.value || '');
            copyBtn.innerHTML = '<i class="fa-solid fa-check text-success"></i> Copied!';
            setTimeout(() => { copyBtn.innerHTML = '<i class="fa-regular fa-copy"></i> Copy Draft'; }, 1800);
        } catch (e) {
            msgBox.select();
            document.execCommand('copy');
        }
    });

    const closeModal = () => {
        modal.classList.remove('is-open');
        modal.setAttribute('aria-hidden', 'true');
    };
    closeBtn.addEventListener('click', closeModal);
    modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
    document.addEventListener('keydown', (e) => { if (e.key === 'Escape' && modal.classList.contains('is-open')) closeModal(); });
});
</script>
</body>
</html>
