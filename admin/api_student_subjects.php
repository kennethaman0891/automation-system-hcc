<?php
/* Per-student subject data for dashboard charts */
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
    $subjPct = $subjTotal > 0 ? round(($subjObt / $subjTotal) * 100, 1) : 0;
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
        'total_pct' => $subjPct,
    ];
}

echo json_encode([
    'student' => [
        'name' => $report['name'],
        'roll' => $report['roll'],
        'class_label' => 'STD ' . $report['std'] . ' ' . $report['division'] . ' — ' . $report['semester'] . ' (' . $report['academic_year'] . ')',
    ],
    'subjects' => $subjects,
    'summary' => [
        'grand_total_max' => (float)$report['grandTotal'],
        'grand_total_obtained' => (float)$report['obtained'],
        'percentage' => (float)$report['percentage'],
        'grade' => $report['grade'],
        'division' => $report['division_label'],
        'passed' => $report['passed'],
    ]
], JSON_UNESCAPED_UNICODE | JSON_NUMERIC_CHECK);
