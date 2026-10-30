<?php
/**
 * Comprehensive Student Dashboard API
 * Returns all data needed for Power BI/Tableau-style per-student dashboard
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

header('Content-Type: application/json; charset=utf-8');

$classKey = trim($_GET['class'] ?? '');
$roll = trim($_GET['roll'] ?? '');

if ($classKey === '' || $roll === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing class or roll']);
    exit;
}

$report = rcStudentReport($classKey, $roll);
if (!$report) {
    http_response_code(404);
    echo json_encode(['error' => 'Student not found']);
    exit;
}

$db = getDB();
$c = rcParseClassKey(urldecode($classKey));

// ──────────────────────────────────────────────
// 1. SUBJECTS — detailed per-subject breakdown
// ──────────────────────────────────────────────
$subjects = [];
foreach ($report['rows'] as $row) {
    $faMax = (float)$row['fa_max'];
    $faObt = (float)$row['fa_obtained'];
    $saMax = (float)$row['sa_max'];
    $saObt = (float)$row['sa_obtained'];
    $subjTotal = $faMax + $saMax;
    $subjObt = $faObt + $saObt;
    $faPct = $faMax > 0 ? round(($faObt / $faMax) * 100, 1) : 0;
    $saPct = $saMax > 0 ? round(($saObt / $saMax) * 100, 1) : 0;
    $totalPct = $subjTotal > 0 ? round(($subjObt / $subjTotal) * 100, 1) : 0;
    $grade = calculateGrade($totalPct);
    $subjects[] = [
        'subject' => $row['subject_name'],
        'fa_max' => $faMax,
        'fa_obtained' => $faObt,
        'fa_pct' => $faPct,
        'sa_max' => $saMax,
        'sa_obtained' => $saObt,
        'sa_pct' => $saPct,
        'total_max' => $subjTotal,
        'total_obtained' => $subjObt,
        'total_pct' => $totalPct,
        'grade' => $grade,
    ];
}

// ──────────────────────────────────────────────
// 2. SUMMARY — student aggregates
// ──────────────────────────────────────────────
$summary = [
    'grand_total_max' => (float)$report['grandTotal'],
    'grand_total_obtained' => (float)$report['obtained'],
    'percentage' => (float)$report['percentage'],
    'grade' => $report['grade'],
    'division' => $report['division_label'],
    'passed' => $report['passed'],
    'remark' => $report['remark'] ?: '—',
    'attendance' => $report['attendance'] ?: '—',
    'parents_sign' => (int)$report['parents_sign'],
];

// ──────────────────────────────────────────────
// 3. CLASS COMPARATIVES — percentile, averages, grade dist
// ──────────────────────────────────────────────
// Class average percentage
$stmt = $db->prepare("
    SELECT AVG(pct) AS avg_pct, COUNT(*) AS class_size
    FROM (
        SELECT roll_no, SUM(fa_obtained+sa_obtained)/SUM(fa_max+sa_max)*100 AS pct
        FROM report_card
        WHERE academic_year=? AND std=? AND division=? AND semester=?
        GROUP BY roll_no
    ) t
");
$stmt->execute([$c['academic_year'], $c['std'], $c['division'], $c['semester']]);
$classStats = $stmt->fetch(PDO::FETCH_ASSOC);
$classAvgPct = round((float)($classStats['avg_pct'] ?? 0), 1);
$classSize = (int)($classStats['class_size'] ?? 1);

// Percentile rank of this student
$stmt = $db->prepare("
    SELECT COUNT(*) AS below
    FROM (
        SELECT roll_no, SUM(fa_obtained+sa_obtained)/SUM(fa_max+sa_max)*100 AS pct
        FROM report_card
        WHERE academic_year=? AND std=? AND division=? AND semester=?
        GROUP BY roll_no
    ) t
    WHERE pct < ?
");
$stmt->execute([$c['academic_year'], $c['std'], $c['division'], $c['semester'], $report['percentage']]);
$below = (int)$stmt->fetchColumn();
$percentile = $classSize > 1 ? round(($below / ($classSize - 1)) * 100) : 50;

// Subject-wise class averages
$stmt = $db->prepare("
    SELECT subject_name, AVG((fa_obtained+sa_obtained)/(fa_max+sa_max)*100) AS avg_pct
    FROM report_card
    WHERE academic_year=? AND std=? AND division=? AND semester=?
    GROUP BY subject_name
");
$stmt->execute([$c['academic_year'], $c['std'], $c['division'], $c['semester']]);
$subjectAvgs = [];
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $subjectAvgs[$r['subject_name']] = round((float)$r['avg_pct'], 1);
}

// Grade distribution
$stmt = $db->prepare("
    SELECT grade, COUNT(*) AS cnt
    FROM (
        SELECT roll_no,
            CASE 
                WHEN SUM(fa_obtained+sa_obtained)/SUM(fa_max+sa_max)*100 >= 90 THEN 'A'
                WHEN SUM(fa_obtained+sa_obtained)/SUM(fa_max+sa_max)*100 >= 75 THEN 'B'
                WHEN SUM(fa_obtained+sa_obtained)/SUM(fa_max+sa_max)*100 >= 60 THEN 'C'
                WHEN SUM(fa_obtained+sa_obtained)/SUM(fa_max+sa_max)*100 >= 45 THEN 'D'
                ELSE 'F'
            END AS grade
        FROM report_card
        WHERE academic_year=? AND std=? AND division=? AND semester=?
        GROUP BY roll_no
    ) t
    GROUP BY grade
");
$stmt->execute([$c['academic_year'], $c['std'], $c['division'], $c['semester']]);
$gradeDist = ['A' => 0, 'B' => 0, 'C' => 0, 'D' => 0, 'F' => 0];
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $gradeDist[$r['grade']] = (int)$r['cnt'];
}

$classComparatives = [
    'class_avg_pct' => $classAvgPct,
    'class_size' => $classSize,
    'percentile' => $percentile,
    'subject_avgs' => $subjectAvgs,
    'grade_distribution' => $gradeDist,
];

// ──────────────────────────────────────────────
// 4. HISTORICAL — all terms for this student
// ──────────────────────────────────────────────
$stmt = $db->prepare("
    SELECT academic_year, std, division, semester,
           SUM(fa_obtained+sa_obtained) AS obtained,
           SUM(fa_max+sa_max) AS maxtotal,
           COUNT(*) AS subject_count
    FROM report_card
    WHERE roll_no=?
    GROUP BY academic_year, std, division, semester
    ORDER BY academic_year, semester
");
$stmt->execute([$roll]);
$historical = [];
while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $pct = $r['maxtotal'] > 0 ? round(($r['obtained'] / $r['maxtotal']) * 100, 1) : 0;
    // Get per-subject for this term
    $subjStmt = $db->prepare("
        SELECT subject_name, fa_max, fa_obtained, sa_max, sa_obtained
        FROM report_card
        WHERE roll_no=? AND academic_year=? AND std=? AND division=? AND semester=?
    ");
    $subjStmt->execute([$roll, $r['academic_year'], $r['std'], $r['division'], $r['semester']]);
    $termSubjects = [];
    $faPctSum = 0; $saPctSum = 0; $subjCount = 0;
    while ($sr = $subjStmt->fetch(PDO::FETCH_ASSOC)) {
        $faM = (float)$sr['fa_max']; $faO = (float)$sr['fa_obtained'];
        $saM = (float)$sr['sa_max']; $saO = (float)$sr['sa_obtained'];
        $faPct = $faM > 0 ? round(($faO / $faM) * 100, 1) : 0;
        $saPct = $saM > 0 ? round(($saO / $saM) * 100, 1) : 0;
        $faPctSum += $faPct; $saPctSum += $saPct; $subjCount++;
        $termSubjects[] = [
            'subject' => $sr['subject_name'],
            'fa_pct' => $faPct,
            'sa_pct' => $saPct,
            'total_pct' => ($faM + $saM) > 0 ? round((($faO + $saO) / ($faM + $saM)) * 100, 1) : 0,
        ];
    }
    $historical[] = [
        'academic_year' => $r['academic_year'],
        'std' => $r['std'],
        'division' => $r['division'],
        'semester' => $r['semester'],
        'percentage' => $pct,
        'grade' => calculateGrade($pct),
        'fa_pct_avg' => $subjCount > 0 ? round($faPctSum / $subjCount, 1) : 0,
        'sa_pct_avg' => $subjCount > 0 ? round($saPctSum / $subjCount, 1) : 0,
        'subjects' => $termSubjects,
    ];
}

// ──────────────────────────────────────────────
// RESPONSE
// ──────────────────────────────────────────────
echo json_encode([
    'student' => [
        'name' => $report['name'],
        'roll' => $report['roll'],
        'class_label' => 'STD ' . $report['std'] . ' ' . $report['division'] . ' — ' . $report['semester'] . ' (' . $report['academic_year'] . ')',
        'academic_year' => $report['academic_year'],
        'std' => $report['std'],
        'division' => $report['division'],
        'semester' => $report['semester'],
    ],
    'subjects' => $subjects,
    'summary' => $summary,
    'class_comparatives' => $classComparatives,
    'historical' => $historical,
], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
