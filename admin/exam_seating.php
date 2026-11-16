<?php
/**
 * HCC Automation System — Interleaved Exam Hall Seating & Invigilator Matrix Generator (Automation #7)
 * Features:
 *   - Load cohorts from report_card (with unit_test_results / students_master fallback)
 *   - Optional link to saved timetables/timetable_rows
 *   - Multi-room builder with 2 or 3 seats/bench + custom bench count
 *   - Greedy balanced interleaving algorithm preventing adjacent same-class peers
 *   - Persists room plans into exam_seating_plans
 *   - 3 crisp A4 printable views: Room Door Chits, Bench Seating Matrix Grid, Desk Roll-Number Stickers
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

$adminPage  = 'exam_seating';
$settings   = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church High School';
$adminUser  = sanitize($_SESSION['admin_name'] ?? 'Admin');
$adminRole  = ucfirst($_SESSION['admin_role'] ?? 'Admin');
$adminId    = !empty($_SESSION['admin_id']) ? (int)$_SESSION['admin_id'] : null;
$adminInitials = strtoupper(implode('', array_map(
    static fn($p) => mb_substr($p, 0, 1),
    array_slice(array_filter(preg_split('/\s+/', trim($_SESSION['admin_name'] ?? 'A'))), 0, 2)
))) ?: 'AD';
$csrfToken = generateCSRFToken();

$db = getDB();
$romanMap = ['1'=>'I','2'=>'II','3'=>'III','4'=>'IV','5'=>'V','6'=>'VI','7'=>'VII','8'=>'VIII','9'=>'IX','10'=>'X'];

// Idempotent table guard matching 2026_11_academic_ops_tables.sql
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `exam_seating_plans` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `timetable_id` INT UNSIGNED NULL DEFAULT NULL,
        `exam_date` DATE NOT NULL,
        `slot_label` VARCHAR(64) NOT NULL DEFAULT '',
        `room_name` VARCHAR(120) NOT NULL,
        `bench_capacity` TINYINT UNSIGNED NOT NULL DEFAULT 2,
        `plan_json` LONGTEXT NOT NULL,
        `created_by` INT UNSIGNED NULL DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_seating_date_room` (`exam_date`, `room_name`),
        KEY `idx_seating_timetable` (`timetable_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log('exam_seating DDL guard note: ' . $e->getMessage());
}

$yearOptions  = rcGetAcademicYearOptions();
$activeYear   = rcGetActiveAcademicYear();
$selectedYear = trim((string)($_REQUEST['year'] ?? $activeYear));
if ($selectedYear === '' && !empty($yearOptions)) {
    $selectedYear = $yearOptions[0];
}

// Fetch saved timetables + rows for quick schedule link
$savedTimetables = [];
$ttScheduleRows  = [];
try {
    $savedTimetables = $db->query("SELECT id, title, academic_year, exam_type FROM timetables ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC);
    $rStmt = $db->query("SELECT r.timetable_id, r.exam_date, r.day_name, r.time_slot,
                                GROUP_CONCAT(DISTINCT CONCAT(c.column_name, ': ', cl.subject_name) ORDER BY c.sort_order SEPARATOR ' | ') AS subjects_summary
                         FROM timetable_rows r
                         LEFT JOIN timetable_cells cl ON cl.row_id = r.id AND cl.subject_name <> '--------' AND cl.subject_name <> ''
                         LEFT JOIN timetable_columns c ON cl.column_id = c.id
                         GROUP BY r.id
                         ORDER BY r.exam_date ASC, r.id ASC");
    foreach ($rStmt->fetchAll(PDO::FETCH_ASSOC) as $tr) {
        $ttScheduleRows[(int)$tr['timetable_id']][] = $tr;
    }
} catch (Throwable $e) {}

// Fetch distinct participating cohorts in selectedYear from report_card (with UT/students_master fallback)
$cohorts = []; // "std|div" => ['std'=>..., 'division'=>..., 'label'=>..., 'count'=>..., 'students'=>[]]
try {
    $rcStmt = $db->prepare("SELECT std, division, roll_no, MAX(student_name) AS student_name
                            FROM report_card
                            WHERE academic_year = ?
                            GROUP BY std, division, roll_no
                            ORDER BY CAST(std AS UNSIGNED), division, CAST(roll_no AS UNSIGNED), roll_no");
    $rcStmt->execute([$selectedYear]);
    while ($r = $rcStmt->fetch(PDO::FETCH_ASSOC)) {
        $ck = trim((string)$r['std']) . '|' . trim((string)$r['division']);
        if (!isset($cohorts[$ck])) {
            $cohorts[$ck] = [
                'key'      => $ck,
                'std'      => trim((string)$r['std']),
                'division' => trim((string)$r['division']),
                'label'    => 'STD ' . ($romanMap[$r['std']] ?? $r['std']) . ' ' . trim((string)$r['division']),
                'students' => [],
            ];
        }
        $cohorts[$ck]['students'][(string)$r['roll_no']] = [
            'roll_no'      => trim((string)$r['roll_no']),
            'student_name' => trim((string)$r['student_name']),
            'class_key'    => $ck,
            'class_label'  => $cohorts[$ck]['label'],
        ];
    }

    // Merge unit_test_results / students_master cohorts not already present
    $utStmt = $db->prepare("SELECT std, division, roll_no, MAX(student_name) AS student_name
                            FROM unit_test_results
                            WHERE academic_year = ?
                            GROUP BY std, division, roll_no
                            ORDER BY CAST(std AS UNSIGNED), division, CAST(roll_no AS UNSIGNED), roll_no");
    $utStmt->execute([$selectedYear]);
    while ($r = $utStmt->fetch(PDO::FETCH_ASSOC)) {
        $ck = trim((string)$r['std']) . '|' . trim((string)$r['division']);
        if (!isset($cohorts[$ck])) {
            $cohorts[$ck] = [
                'key'      => $ck,
                'std'      => trim((string)$r['std']),
                'division' => trim((string)$r['division']),
                'label'    => 'STD ' . ($romanMap[$r['std']] ?? $r['std']) . ' ' . trim((string)$r['division']),
                'students' => [],
            ];
        }
        $roll = trim((string)$r['roll_no']);
        if (!isset($cohorts[$ck]['students'][$roll])) {
            $cohorts[$ck]['students'][$roll] = [
                'roll_no'      => $roll,
                'student_name' => trim((string)$r['student_name']),
                'class_key'    => $ck,
                'class_label'  => $cohorts[$ck]['label'],
            ];
        }
    }
} catch (Throwable $e) {}

// Sort cohorts naturally and reindex student lists numerically
uksort($cohorts, static function ($a, $b) use ($cohorts) {
    $ca = $cohorts[$a]; $cb = $cohorts[$b];
    return ((int)$ca['std'] <=> (int)$cb['std']) ?: strcmp($ca['std'], $cb['std']) ?: strcmp($ca['division'], $cb['division']);
});
foreach ($cohorts as &$ch) {
    usort($ch['students'], static fn($x, $y) => ((int)$x['roll_no'] <=> (int)$y['roll_no']) ?: strcmp($x['roll_no'], $y['roll_no']));
    $ch['count'] = count($ch['students']);
}
unset($ch);

// Form defaults
$examDate       = trim((string)($_REQUEST['exam_date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $examDate)) $examDate = date('Y-m-d');
$slotLabel      = trim((string)($_REQUEST['slot_label'] ?? '8.00 AM - 10.15 AM'));
$timetableId    = !empty($_REQUEST['timetable_id']) ? (int)$_REQUEST['timetable_id'] : null;
$benchCapacity  = ((int)($_REQUEST['bench_capacity'] ?? 2)) === 3 ? 3 : 2;
$selectedClasses = $_REQUEST['classes'] ?? [];
if (!is_array($selectedClasses)) $selectedClasses = [];
$selectedClasses = array_values(array_filter(array_map('trim', $selectedClasses), static fn($k) => isset($cohorts[$k])));

// Default room configs
$roomsInput = [];
$rawRoomNames   = $_POST['room_name'] ?? ['Room 101', 'Room 102'];
$rawBenchCounts = $_POST['bench_count'] ?? [15, 15];
if (is_array($rawRoomNames)) {
    foreach ($rawRoomNames as $idx => $rName) {
        $rn = trim((string)$rName);
        if ($rn === '') continue;
        $bc = max(1, min(60, (int)($rawBenchCounts[$idx] ?? 15)));
        $roomsInput[] = ['room_name' => $rn, 'bench_count' => $bc];
    }
}
if (empty($roomsInput)) {
    $roomsInput = [['room_name' => 'Room 101', 'bench_count' => 15]];
}

$activePlans    = []; // Array of room plan structs for rendering
$unseatedPool   = [];
$flashSuccess   = '';
$flashError     = '';
$classColorPalette = ['#1d4ed8', '#b91c1c', '#047857', '#7c3aed', '#b45309', '#0f766e'];

/**
 * Interleaved Seating Allocation Engine:
 * Ensures adjacent seats on every bench hold different classes whenever possible,
 * balancing remaining class counts greedily.
 */
function esGenerateInterleavedPlans(
    array $selectedClassKeys,
    array $cohorts,
    array $roomsConfig,
    int $benchCapacity,
    string $academicYear,
    string $examDate,
    string $slotLabel
): array {
    $queues = [];
    foreach ($selectedClassKeys as $ck) {
        if (!empty($cohorts[$ck]['students'])) {
            $queues[$ck] = array_values($cohorts[$ck]['students']);
        }
    }

    $remainingTotal = static function () use (&$queues): int {
        $sum = 0;
        foreach ($queues as $q) $sum += count($q);
        return $sum;
    };

    $plans = [];
    foreach ($roomsConfig as $rCfg) {
        if ($remainingTotal() === 0) break;

        $benches       = [];
        $roomStudents  = 0;
        $classStats    = []; // ck => ['label'=>..., 'rolls'=>[], 'count'=>0]
        $sameClassWarn = 0;

        for ($b = 1; $b <= $rCfg['bench_count']; $b++) {
            if ($remainingTotal() === 0) break;

            $seats = [];
            $prevClassOnBench = null;
            $usedClassesOnBench = [];

            for ($s = 0; $s < $benchCapacity; $s++) {
                // Available non-empty class queues
                $availKeys = [];
                foreach ($queues as $ck => $q) {
                    if (!empty($q)) $availKeys[] = $ck;
                }
                if (empty($availKeys)) break;

                // 1. Prefer classes NOT yet used on this bench (guarantees no same-class peers on same bench)
                $candidates = array_values(array_filter($availKeys, static fn($ck) => !isset($usedClassesOnBench[$ck])));
                // 2. Fallback: at least avoid immediately adjacent seat's class
                if (empty($candidates) && $prevClassOnBench !== null) {
                    $candidates = array_values(array_filter($availKeys, static fn($ck) => $ck !== $prevClassOnBench));
                }
                // 3. Final fallback: only 1 class remains in pool
                if (empty($candidates)) {
                    $candidates = $availKeys;
                    if ($prevClassOnBench !== null && count($seats) > 0) {
                        $sameClassWarn++;
                    }
                }

                // Pick candidate class with most remaining students (balanced interleaving)
                usort($candidates, static fn($a, $b) => count($queues[$b]) <=> count($queues[$a]));
                $chosenCk = $candidates[0];
                $stu = array_shift($queues[$chosenCk]);

                $seats[] = $stu;
                $prevClassOnBench = $chosenCk;
                $usedClassesOnBench[$chosenCk] = true;
                $roomStudents++;

                if (!isset($classStats[$chosenCk])) {
                    $classStats[$chosenCk] = [
                        'class_key'   => $chosenCk,
                        'class_label' => $stu['class_label'],
                        'rolls'       => [],
                        'count'       => 0,
                    ];
                }
                $classStats[$chosenCk]['rolls'][] = $stu['roll_no'];
                $classStats[$chosenCk]['count']++;
            }

            if (!empty($seats)) {
                $benches[] = [
                    'bench_no' => $b,
                    'seats'    => $seats,
                ];
            }
        }

        // Compute clean roll ranges per class for Room Door Chits
        $rollRanges = [];
        foreach ($classStats as $ck => $cs) {
            $rolls = $cs['rolls'];
            $firstRoll = $rolls[0] ?? '';
            $lastRoll  = $rolls[count($rolls) - 1] ?? '';
            $rollRanges[] = [
                'class_key'   => $ck,
                'class_label' => $cs['class_label'],
                'start_roll'  => $firstRoll,
                'end_roll'    => $lastRoll,
                'count'       => $cs['count'],
            ];
        }

        $invigilatorsNeeded = ($roomStudents > 30 || count($benches) > 15) ? 2 : 1;

        $plans[] = [
            'room_name'           => $rCfg['room_name'],
            'bench_capacity'      => $benchCapacity,
            'bench_count'         => $rCfg['bench_count'],
            'benches_used'        => count($benches),
            'total_seated'        => $roomStudents,
            'invigilators_needed' => $invigilatorsNeeded,
            'same_class_warnings' => $sameClassWarn,
            'academic_year'       => $academicYear,
            'exam_date'           => $examDate,
            'slot_label'          => $slotLabel,
            'classes'             => array_values($selectedClassKeys),
            'roll_ranges'         => $rollRanges,
            'benches'             => $benches,
        ];
    }

    $unseated = [];
    foreach ($queues as $q) {
        foreach ($q as $stu) $unseated[] = $stu;
    }
    return ['plans' => $plans, 'unseated' => $unseated];
}

// Handle POST Actions (CSRF verified)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCSRFToken()) {
        $flashError = 'Security check failed. Please refresh and try again.';
    } else {
        $action = trim((string)($_POST['action'] ?? 'generate'));

        if ($action === 'delete_plan') {
            $delDate = trim((string)($_POST['del_exam_date'] ?? ''));
            $delSlot = trim((string)($_POST['del_slot_label'] ?? ''));
            $delId   = (int)($_POST['del_id'] ?? 0);
            if ($delId > 0) {
                $db->prepare("DELETE FROM exam_seating_plans WHERE id = ?")->execute([$delId]);
                $flashSuccess = 'Deleted saved room seating plan.';
            } elseif ($delDate !== '') {
                $db->prepare("DELETE FROM exam_seating_plans WHERE exam_date = ? AND slot_label = ?")->execute([$delDate, $delSlot]);
                $flashSuccess = "Deleted seating plans for {$delDate} ({$delSlot}).";
            }
        } elseif ($action === 'generate_and_save' || $action === 'preview_only') {
            if (count($selectedClasses) < 2) {
                $flashError = 'Please select at least 2 participating classes/divisions to interleave seats.';
            } else {
                $gen = esGenerateInterleavedPlans($selectedClasses, $cohorts, $roomsInput, $benchCapacity, $selectedYear, $examDate, $slotLabel);
                $activePlans  = $gen['plans'];
                $unseatedPool = $gen['unseated'];

                if (empty($activePlans)) {
                    $flashError = 'Selected classes have 0 students to seat.';
                } elseif ($action === 'generate_and_save') {
                    $db->beginTransaction();
                    try {
                        $del = $db->prepare("DELETE FROM exam_seating_plans WHERE exam_date = ? AND slot_label = ? AND room_name = ?");
                        $ins = $db->prepare("INSERT INTO exam_seating_plans (timetable_id, exam_date, slot_label, room_name, bench_capacity, plan_json, created_by) VALUES (?, ?, ?, ?, ?, ?, ?)");
                        foreach ($activePlans as $pl) {
                            $del->execute([$examDate, $slotLabel, $pl['room_name']]);
                            $ins->execute([
                                $timetableId,
                                $examDate,
                                $slotLabel,
                                $pl['room_name'],
                                $pl['bench_capacity'],
                                json_encode($pl, JSON_UNESCAPED_UNICODE),
                                $adminId,
                            ]);
                        }
                        $db->commit();
                        $flashSuccess = 'Generated & saved interleaved seating plans for ' . count($activePlans) . ' room(s)!';
                    } catch (Throwable $e) {
                        if ($db->inTransaction()) $db->rollBack();
                        $flashError = 'Failed to save seating plans: ' . $e->getMessage();
                    }
                }
            }
        }
    }
}

// Load saved plan batch via GET (?load_date=...&load_slot=...) or single (?load_id=...)
if (empty($activePlans) && isset($_GET['load_date'])) {
    $ldDate = trim((string)$_GET['load_date']);
    $ldSlot = trim((string)($_GET['load_slot'] ?? ''));
    try {
        $st = $db->prepare("SELECT * FROM exam_seating_plans WHERE exam_date = ? AND slot_label = ? ORDER BY room_name ASC, id ASC");
        $st->execute([$ldDate, $ldSlot]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $dec = json_decode((string)$row['plan_json'], true);
            if (is_array($dec)) {
                $activePlans[] = $dec;
                $examDate      = $row['exam_date'];
                $slotLabel     = $row['slot_label'];
                $benchCapacity = (int)$row['bench_capacity'];
            }
        }
    } catch (Throwable $e) {}
} elseif (empty($activePlans) && !empty($_GET['load_id'])) {
    try {
        $st = $db->prepare("SELECT * FROM exam_seating_plans WHERE id = ?");
        $st->execute([(int)$_GET['load_id']]);
        if ($row = $st->fetch(PDO::FETCH_ASSOC)) {
            $dec = json_decode((string)$row['plan_json'], true);
            if (is_array($dec)) {
                $activePlans[] = $dec;
                $examDate      = $row['exam_date'];
                $slotLabel     = $row['slot_label'];
                $benchCapacity = (int)$row['bench_capacity'];
            }
        }
    } catch (Throwable $e) {}
}

// Fetch Saved Seating Plans history grouped by exam_date + slot_label
$savedPlansList = [];
try {
    $savedPlansList = $db->query("SELECT id, timetable_id, exam_date, slot_label, room_name, bench_capacity, plan_json, created_at
                                  FROM exam_seating_plans
                                  ORDER BY exam_date DESC, id DESC
                                  LIMIT 40")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Totals across active plans
$totalSeatedAll = 0;
$totalInvigAll  = 0;
$classBadgeColor = [];
foreach ($activePlans as $pl) {
    $totalSeatedAll += (int)($pl['total_seated'] ?? 0);
    $totalInvigAll  += (int)($pl['invigilators_needed'] ?? 1);
    foreach (($pl['classes'] ?? []) as $cIdx => $ck) {
        if (!isset($classBadgeColor[$ck])) {
            $classBadgeColor[$ck] = $classColorPalette[$cIdx % count($classColorPalette)];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>Exam Hall Seating Planner | <?= sanitize($schoolName) ?></title>
    <link rel="icon" href="<?= BASE_URL ?>assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap@5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin.css?v=<?= is_file(__DIR__ . '/../assets/css/admin.css') ? filemtime(__DIR__ . '/../assets/css/admin.css') : 1 ?>">
    <style>
        .ops-wrap { padding: 22px 26px 40px; }
        .ops-panel { background: #fff; border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); padding: 18px 20px; margin-bottom: 18px; }
        .cohort-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(175px, 1fr)); gap: 8px; }
        .cohort-chip { border: 1px solid var(--border); border-radius: 8px; padding: 8px 10px; display: flex; align-items: center; gap: 8px; cursor: pointer; background: #fff; transition: all 0.15s; }
        .cohort-chip:hover { border-color: var(--brand); background: var(--brand-soft); }
        .cohort-chip.is-picked { border-color: var(--brand); background: var(--brand-soft); }
        .btn-hcc { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; font-size: 0.84rem; font-weight: 600; border: 1px solid transparent; cursor: pointer; text-decoration: none; transition: all 0.16s ease; }
        .btn-hcc-primary { background: var(--brand); color: #fff; }
        .btn-hcc-primary:hover { background: var(--brand-dark); color: #fff; }
        .btn-hcc-outline { background: #fff; color: var(--ink); border-color: var(--border); }
        .btn-hcc-outline:hover { border-color: var(--brand); color: var(--brand); }
        .view-tab-btn.active { background: var(--brand); color: #fff; border-color: var(--brand); }

        /* Seating Matrix Grid */
        .room-block { border: 1px solid var(--border); border-radius: 12px; padding: 16px; margin-bottom: 18px; background: #fff; page-break-after: always; }
        .room-block:last-child { page-break-after: auto; }
        .invig-desk { text-align: center; background: #1e293b; color: #fff; font-size: 0.78rem; font-weight: 800; letter-spacing: 0.08em; text-transform: uppercase; padding: 6px 12px; border-radius: 6px; max-width: 280px; margin: 0 auto 14px; }
        .bench-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: 10px; }
        @media (max-width: 900px) { .bench-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        .bench-card { border: 1.5px solid #cbd5e1; border-radius: 8px; padding: 8px; background: #f8fafc; }
        .bench-head { font-size: 0.7rem; font-weight: 800; text-transform: uppercase; color: #475569; margin-bottom: 6px; display: flex; justify-content: space-between; }
        .seat-slots { display: grid; gap: 6px; }
        .seat-slots.cap-2 { grid-template-columns: 1fr 1fr; }
        .seat-slots.cap-3 { grid-template-columns: 1fr 1fr 1fr; }
        .seat-box { background: #fff; border: 1px solid #cbd5e1; border-top: 3px solid #1d4ed8; border-radius: 6px; padding: 5px 6px; text-align: center; min-width: 0; }
        .seat-cls { font-size: 0.66rem; font-weight: 800; text-transform: uppercase; }
        .seat-roll { font-size: 0.92rem; font-weight: 800; color: #0f172a; line-height: 1.2; }
        .seat-name { font-size: 0.66rem; color: #475569; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }

        /* Room Door Chits */
        .door-chit { border: 2pt solid #000; padding: 20px 24px; background: #fff; margin-bottom: 20px; page-break-after: always; }
        .door-chit:last-child { page-break-after: auto; }
        .door-chit-title { font-size: 22pt; font-weight: 900; text-transform: uppercase; letter-spacing: 0.04em; border: 2pt solid #000; display: inline-block; padding: 6px 22px; margin: 10px 0; }

        /* Desk Stickers */
        .sticker-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
        .desk-sticker { border: 1.5px dashed #334155; border-radius: 6px; padding: 8px 10px; background: #fff; text-align: center; page-break-inside: avoid; }

        .seating-view-pane { display: none; }
        .seating-view-pane.is-active { display: block; }

        @media print {
            @page { size: A4 portrait; margin: 8mm 10mm; }
            .admin-sidebar, .admin-header, .no-print { display: none !important; }
            .admin-main { margin-left: 0 !important; }
            .ops-wrap { padding: 0 !important; }
            .ops-panel { box-shadow: none !important; border: none !important; padding: 0 !important; }
            .room-block { border: 1.5pt solid #000 !important; padding: 10px !important; }
            .bench-grid { grid-template-columns: repeat(3, 1fr) !important; gap: 6px !important; }
            .bench-card { border: 1pt solid #000 !important; padding: 5px !important; background: #fff !important; }
            .seat-box { border: 1pt solid #334155 !important; }
            .invig-desk { background: #000 !important; color: #fff !important; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
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
                <h1 class="page-title"><i class="fa-solid fa-chair text-success me-1"></i> Exam Hall Seating Planner</h1>
                <p class="page-sub">Interleaved multi-standard bench allocation, invigilator count, Room Door Chits &amp; Desk Stickers.</p>
            </div>
            <div class="header-actions no-print">
                <?php if (!empty($activePlans)): ?>
                    <button type="button" class="btn-hcc btn-hcc-primary" onclick="window.print()"><i class="fa-solid fa-print"></i> Print Active View</button>
                <?php endif; ?>
                <div class="admin-user" title="<?= sanitize($adminUser) ?> — <?= sanitize($adminRole) ?>">
                    <span class="user-avatar" aria-hidden="true"><?= sanitize($adminInitials) ?></span>
                    <span class="user-meta"><strong><?= sanitize($adminUser) ?></strong><small><?= sanitize($adminRole) ?></small></span>
                </div>
            </div>
        </header>

        <div class="ops-wrap">
            <?php if ($flashSuccess !== ''): ?>
                <div class="alert alert-success alert-dismissible fade show no-print" role="alert">
                    <i class="fa-solid fa-circle-check me-1"></i> <?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?>
                    <button type="button" class="btn-close" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if ($flashError !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show no-print" role="alert">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?>
                    <button type="button" class="btn-close" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- Configuration Builder Form -->
            <form method="POST" class="ops-panel no-print" id="seatingConfigForm">
                <?= csrfField() ?>
                <div class="row g-3 mb-3 align-items-end">
                    <div class="col-md-2">
                        <label for="esYear" class="form-label small fw-bold mb-1">Academic Year</label>
                        <select id="esYear" name="year" class="form-select form-select-sm" onchange="window.location='exam_seating.php?year='+encodeURIComponent(this.value)">
                            <?php foreach ($yearOptions as $yr): ?>
                                <option value="<?= htmlspecialchars($yr, ENT_QUOTES, 'UTF-8') ?>" <?= $yr === $selectedYear ? 'selected' : '' ?>><?= htmlspecialchars($yr, ENT_QUOTES, 'UTF-8') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label for="esTimetable" class="form-label small fw-bold mb-1">Link Exam Timetable (Optional)</label>
                        <select id="esTimetable" name="timetable_id" class="form-select form-select-sm">
                            <option value="">-- Manual Date / Slot --</option>
                            <?php foreach ($savedTimetables as $tt): ?>
                                <option value="<?= (int)$tt['id'] ?>" <?= (int)$timetableId === (int)$tt['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($tt['title'] . ' (' . $tt['academic_year'] . ')', ENT_QUOTES, 'UTF-8') ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="esTimetableSlotPick" class="form-label small fw-bold mb-1">Pick Saved Exam Slot</label>
                        <select id="esTimetableSlotPick" class="form-select form-select-sm">
                            <option value="">-- Pick Slot --</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label for="esDate" class="form-label small fw-bold mb-1">Exam Date</label>
                        <input id="esDate" type="date" name="exam_date" value="<?= htmlspecialchars($examDate, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-2">
                        <label for="esSlot" class="form-label small fw-bold mb-1">Slot Label</label>
                        <input id="esSlot" type="text" name="slot_label" value="<?= htmlspecialchars($slotLabel, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-sm" required>
                    </div>
                    <div class="col-md-1">
                        <label for="esCap" class="form-label small fw-bold mb-1">Seats/Bench</label>
                        <select id="esCap" name="bench_capacity" class="form-select form-select-sm">
                            <option value="2" <?= $benchCapacity === 2 ? 'selected' : '' ?>>2</option>
                            <option value="3" <?= $benchCapacity === 3 ? 'selected' : '' ?>>3</option>
                        </select>
                    </div>
                </div>

                <!-- Step 1: Select 2+ Participating Classes -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small fw-bold text-uppercase text-secondary"><i class="fa-solid fa-users me-1"></i> Participating Classes / Divisions (Select 2+ to Interleave)</span>
                        <span class="small text-muted" id="selectedStudentSummary">0 students selected</span>
                    </div>
                    <?php if (empty($cohorts)): ?>
                        <div class="alert alert-warning mb-0">No student cohorts found for <?= htmlspecialchars($selectedYear, ENT_QUOTES, 'UTF-8') ?> in report_card or unit_test_results.</div>
                    <?php else: ?>
                        <div class="cohort-grid">
                            <?php foreach ($cohorts as $ck => $ch):
                                $picked = in_array($ck, $selectedClasses, true);
                            ?>
                            <label class="cohort-chip <?= $picked ? 'is-picked' : '' ?>">
                                <input type="checkbox" name="classes[]" value="<?= htmlspecialchars($ck, ENT_QUOTES, 'UTF-8') ?>" <?= $picked ? 'checked' : '' ?> data-count="<?= (int)$ch['count'] ?>" class="form-check-input js-cohort-chk">
                                <div>
                                    <div class="fw-bold small text-dark"><?= htmlspecialchars($ch['label'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="text-muted" style="font-size:0.74rem;"><?= (int)$ch['count'] ?> students</div>
                                </div>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>

                <!-- Step 2: Room Configuration -->
                <div class="mb-3">
                    <div class="d-flex justify-content-between align-items-center mb-2">
                        <span class="small fw-bold text-uppercase text-secondary"><i class="fa-solid fa-door-open me-1"></i> Exam Rooms &amp; Bench Capacities</span>
                        <button type="button" class="btn btn-sm btn-outline-primary" id="btnAddRoomRow"><i class="fa-solid fa-plus"></i> Add Room</button>
                    </div>
                    <div id="roomsContainer" class="row g-2">
                        <?php foreach ($roomsInput as $rCfg): ?>
                        <div class="col-md-4 js-room-row">
                            <div class="input-group input-group-sm">
                                <span class="input-group-text fw-bold">Room</span>
                                <input type="text" name="room_name[]" class="form-control" value="<?= htmlspecialchars($rCfg['room_name'], ENT_QUOTES, 'UTF-8') ?>" placeholder="Room 101" required>
                                <span class="input-group-text">Benches</span>
                                <input type="number" name="bench_count[]" class="form-control js-bench-cnt" value="<?= (int)$rCfg['bench_count'] ?>" min="1" max="60" style="max-width:70px;" required>
                                <button type="button" class="btn btn-outline-danger js-del-room" title="Remove Room">&times;</button>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                    <div class="small text-muted mt-1" id="roomCapacitySummary"></div>
                </div>

                <div class="d-flex gap-2 justify-content-end">
                    <button type="submit" name="action" value="preview_only" class="btn-hcc btn-hcc-outline"><i class="fa-solid fa-eye"></i> Preview Matrix</button>
                    <button type="submit" name="action" value="generate_and_save" class="btn-hcc btn-hcc-primary"><i class="fa-solid fa-wand-magic-sparkles"></i> Generate &amp; Save Seating Plan</button>
                </div>
            </form>

            <!-- Generated / Loaded Output -->
            <?php if (!empty($activePlans)): ?>
            <div class="ops-panel">
                <!-- Summary Strip + Printable View Tabs -->
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 no-print pb-3 border-bottom">
                    <div class="d-flex gap-3 flex-wrap align-items-center">
                        <span class="badge text-bg-success px-3 py-2"><i class="fa-solid fa-users me-1"></i> <?= (int)$totalSeatedAll ?> Students Seated</span>
                        <span class="badge text-bg-primary px-3 py-2"><i class="fa-solid fa-door-open me-1"></i> <?= count($activePlans) ?> Room(s)</span>
                        <span class="badge text-bg-warning px-3 py-2"><i class="fa-solid fa-user-shield me-1"></i> <?= (int)$totalInvigAll ?> Invigilator(s) Needed</span>
                        <?php if (!empty($unseatedPool)): ?>
                            <span class="badge text-bg-danger px-3 py-2"><i class="fa-solid fa-triangle-exclamation me-1"></i> <?= count($unseatedPool) ?> Unseated (Add more benches/rooms)</span>
                        <?php endif; ?>
                    </div>
                    <div class="btn-group btn-group-sm" role="tablist">
                        <button type="button" class="btn btn-outline-secondary view-tab-btn active" data-view="matrix"><i class="fa-solid fa-table-cells me-1"></i> Bench Matrix Grid</button>
                        <button type="button" class="btn btn-outline-secondary view-tab-btn" data-view="door"><i class="fa-solid fa-door-open me-1"></i> Room Door Chits</button>
                        <button type="button" class="btn btn-outline-secondary view-tab-btn" data-view="stickers"><i class="fa-solid fa-tags me-1"></i> Desk Stickers</button>
                    </div>
                </div>

                <!-- View 1: Bench Seating Matrix Grid -->
                <div class="seating-view-pane is-active" id="pane-matrix">
                    <?php foreach ($activePlans as $pl): ?>
                    <div class="room-block">
                        <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-2 pb-2 border-bottom">
                            <div>
                                <div class="fw-bold fs-5 text-dark"><?= htmlspecialchars($pl['room_name'], ENT_QUOTES, 'UTF-8') ?> &mdash; Seating Matrix</div>
                                <div class="small text-muted">
                                    <?= htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8') ?> &bull;
                                    Exam Date: <strong><?= htmlspecialchars(date('d M Y', strtotime($pl['exam_date'])), ENT_QUOTES, 'UTF-8') ?></strong> &bull;
                                    Slot: <strong><?= htmlspecialchars($pl['slot_label'], ENT_QUOTES, 'UTF-8') ?></strong>
                                </div>
                            </div>
                            <div class="text-end small">
                                <div><strong><?= (int)$pl['total_seated'] ?></strong> Students &bull; <strong><?= (int)$pl['benches_used'] ?></strong> Benches (<?= (int)$pl['bench_capacity'] ?>/bench)</div>
                                <div>Invigilators Required: <strong><?= (int)$pl['invigilators_needed'] ?></strong></div>
                            </div>
                        </div>

                        <?php if (!empty($pl['same_class_warnings'])): ?>
                            <div class="alert alert-warning py-1 px-2 small mb-2 no-print">
                                <i class="fa-solid fa-triangle-exclamation"></i> <?= (int)$pl['same_class_warnings'] ?> tail bench seat(s) had only 1 remaining class left in the pool.
                            </div>
                        <?php endif; ?>

                        <div class="invig-desk"><i class="fa-solid fa-chalkboard-user me-1"></i> Blackboard &amp; Invigilator Desk (<?= (int)$pl['invigilators_needed'] ?> Invigilator<?= $pl['invigilators_needed'] > 1 ? 's' : '' ?>)</div>

                        <div class="bench-grid">
                            <?php foreach ($pl['benches'] as $bench): ?>
                            <div class="bench-card">
                                <div class="bench-head">
                                    <span>Bench #<?= (int)$bench['bench_no'] ?></span>
                                    <span><?= count($bench['seats']) ?>/<?= (int)$pl['bench_capacity'] ?></span>
                                </div>
                                <div class="seat-slots cap-<?= (int)$pl['bench_capacity'] ?>">
                                    <?php foreach ($bench['seats'] as $sIdx => $stu):
                                        $clr = $classBadgeColor[$stu['class_key']] ?? '#1d4ed8';
                                    ?>
                                    <div class="seat-box" style="border-top-color:<?= htmlspecialchars($clr, ENT_QUOTES, 'UTF-8') ?>;">
                                        <div class="seat-cls" style="color:<?= htmlspecialchars($clr, ENT_QUOTES, 'UTF-8') ?>;"><?= htmlspecialchars($stu['class_label'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="seat-roll">#<?= htmlspecialchars($stu['roll_no'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <div class="seat-name" title="<?= htmlspecialchars($stu['student_name'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($stu['student_name'], ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- View 2: A4 Printable Room Door Chits -->
                <div class="seating-view-pane" id="pane-door">
                    <?php foreach ($activePlans as $pl): ?>
                    <div class="door-chit">
                        <div style="text-align:center;border-bottom:1.5pt solid #000;padding-bottom:10px;margin-bottom:14px;">
                            <div style="font-size:15pt;font-weight:800;text-transform:uppercase;"><?= htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8') ?></div>
                            <div style="font-size:11pt;font-weight:700;margin-top:3px;">EXAMINATION HALL DOOR NOTICE &mdash; AY <?= htmlspecialchars($pl['academic_year'] ?? $selectedYear, ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="door-chit-title"><?= htmlspecialchars($pl['room_name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div style="font-size:10.5pt;font-weight:600;">
                                Date: <strong><?= htmlspecialchars(date('l, d M Y', strtotime($pl['exam_date'])), ENT_QUOTES, 'UTF-8') ?></strong> &bull;
                                Time: <strong><?= htmlspecialchars($pl['slot_label'], ENT_QUOTES, 'UTF-8') ?></strong>
                            </div>
                        </div>

                        <table class="table table-bordered" style="border:1.5pt solid #000;">
                            <thead>
                                <tr style="background:#f1f5f9;">
                                    <th style="font-size:11pt;">Class / Standard</th>
                                    <th style="font-size:11pt;">Roll Number Range</th>
                                    <th style="font-size:11pt;text-align:center;">Total Students</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($pl['roll_ranges'] as $rr): ?>
                                <tr>
                                    <td style="font-size:13pt;font-weight:800;"><?= htmlspecialchars($rr['class_label'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td style="font-size:14pt;font-weight:800;">
                                        Roll No. <?= htmlspecialchars($rr['start_roll'], ENT_QUOTES, 'UTF-8') ?> &ndash; <?= htmlspecialchars($rr['end_roll'], ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td style="font-size:13pt;font-weight:800;text-align:center;"><?= (int)$rr['count'] ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr>
                                    <th colspan="2" style="text-align:right;font-size:11pt;">Total Seated in <?= htmlspecialchars($pl['room_name'], ENT_QUOTES, 'UTF-8') ?> (Invigilators: <?= (int)$pl['invigilators_needed'] ?>)</th>
                                    <th style="text-align:center;font-size:12pt;"><?= (int)$pl['total_seated'] ?></th>
                                </tr>
                            </tfoot>
                        </table>

                        <div style="display:flex;justify-content:space-between;margin-top:34px;font-size:10pt;font-weight:700;">
                            <span>Exam In-Charge: ___________________</span>
                            <span>Principal / Supervisor: ___________________</span>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>

                <!-- View 3: A4 Printable Desk Roll-Number Stickers -->
                <div class="seating-view-pane" id="pane-stickers">
                    <?php foreach ($activePlans as $pl): ?>
                    <div class="room-block">
                        <div class="fw-bold mb-2 pb-1 border-bottom">
                            Desk Stickers &mdash; <?= htmlspecialchars($pl['room_name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars(date('d M Y', strtotime($pl['exam_date'])), ENT_QUOTES, 'UTF-8') ?> &bull; <?= htmlspecialchars($pl['slot_label'], ENT_QUOTES, 'UTF-8') ?>)
                        </div>
                        <div class="sticker-grid">
                            <?php foreach ($pl['benches'] as $bench): ?>
                                <?php foreach ($bench['seats'] as $sIdx => $stu):
                                    $seatLetter = chr(65 + $sIdx);
                                ?>
                                <div class="desk-sticker">
                                    <div style="font-size:0.68rem;font-weight:700;color:#475569;text-transform:uppercase;">
                                        <?= htmlspecialchars($pl['room_name'], ENT_QUOTES, 'UTF-8') ?> &bull; Bench #<?= (int)$bench['bench_no'] ?> (Seat <?= htmlspecialchars($seatLetter, ENT_QUOTES, 'UTF-8') ?>)
                                    </div>
                                    <div style="font-size:0.86rem;font-weight:800;color:#7a2238;margin-top:2px;">
                                        <?= htmlspecialchars($stu['class_label'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <div style="font-size:1.35rem;font-weight:900;color:#0f172a;line-height:1.15;">
                                        ROLL #<?= htmlspecialchars($stu['roll_no'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <div style="font-size:0.74rem;font-weight:600;color:#334155;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">
                                        <?= htmlspecialchars($stu['student_name'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Saved Seating Plans History -->
            <div class="ops-panel no-print">
                <h5 class="fw-bold mb-3"><i class="fa-solid fa-clock-rotate-left text-primary me-1"></i> Saved Exam Seating Plans</h5>
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0">
                        <thead>
                            <tr>
                                <th>Exam Date</th>
                                <th>Slot</th>
                                <th>Room</th>
                                <th>Bench Cap</th>
                                <th>Seated / Invigilators</th>
                                <th class="text-end">Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($savedPlansList)): ?>
                            <tr><td colspan="6" class="text-center py-3 text-muted">No saved seating plans yet. Configure participating classes above and click <strong>Generate &amp; Save Seating Plan</strong>.</td></tr>
                        <?php else: ?>
                            <?php foreach ($savedPlansList as $sp):
                                $dec = json_decode((string)$sp['plan_json'], true);
                                $seated = (int)($dec['total_seated'] ?? 0);
                                $invig  = (int)($dec['invigilators_needed'] ?? 1);
                            ?>
                            <tr>
                                <td class="fw-bold"><?= htmlspecialchars(date('d M Y', strtotime($sp['exam_date'])), ENT_QUOTES, 'UTF-8') ?></td>
                                <td><?= htmlspecialchars($sp['slot_label'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><span class="badge text-bg-light border"><?= htmlspecialchars($sp['room_name'], ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= (int)$sp['bench_capacity'] ?> / bench</td>
                                <td><strong><?= $seated ?></strong> students &bull; <?= $invig ?> invigilator(s)</td>
                                <td class="text-end">
                                    <div class="d-inline-flex gap-1">
                                        <a href="?year=<?= rawurlencode($selectedYear) ?>&load_date=<?= rawurlencode($sp['exam_date']) ?>&load_slot=<?= rawurlencode($sp['slot_label']) ?>" class="btn btn-sm btn-outline-primary">Load Hall Batch</a>
                                        <a href="?year=<?= rawurlencode($selectedYear) ?>&load_id=<?= (int)$sp['id'] ?>" class="btn btn-sm btn-outline-secondary">Room Only</a>
                                        <form method="POST" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_plan">
                                            <input type="hidden" name="year" value="<?= htmlspecialchars($selectedYear, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="del_id" value="<?= (int)$sp['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-regular fa-trash-can"></i></button>
                                        </form>
                                    </div>
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

<script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 1 ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    const ttRowsById = <?= e_js($ttScheduleRows) ?>;
    const ttSelect   = document.getElementById('esTimetable');
    const slotPick   = document.getElementById('esTimetableSlotPick');
    const dateIn     = document.getElementById('esDate');
    const slotIn     = document.getElementById('esSlot');
    const capIn      = document.getElementById('esCap');
    const roomsWrap  = document.getElementById('roomsContainer');
    const addRoomBtn = document.getElementById('btnAddRoomRow');

    document.querySelectorAll('.alert .btn-close').forEach((b) => {
        b.addEventListener('click', () => b.closest('.alert')?.remove());
    });

    function populateSlotsForTimetable() {
        const tid = ttSelect ? ttSelect.value : '';
        slotPick.innerHTML = '<option value="">-- Pick Slot --</option>';
        const rows = ttRowsById[tid] || [];
        rows.forEach((r) => {
            const opt = document.createElement('option');
            opt.value = JSON.stringify({ date: r.exam_date, slot: r.time_slot });
            opt.textContent = `${r.exam_date} (${r.time_slot})${r.subjects_summary ? ' - ' + r.subjects_summary.slice(0, 42) : ''}`;
            slotPick.appendChild(opt);
        });
    }
    if (ttSelect) {
        ttSelect.addEventListener('change', populateSlotsForTimetable);
        populateSlotsForTimetable();
    }
    if (slotPick) {
        slotPick.addEventListener('change', () => {
            if (!slotPick.value) return;
            try {
                const picked = JSON.parse(slotPick.value);
                if (picked.date && dateIn) dateIn.value = picked.date;
                if (picked.slot && slotIn) slotIn.value = picked.slot;
            } catch (e) {}
        });
    }

    function refreshCapacitySummary() {
        let totalStudents = 0;
        let classesPicked = 0;
        document.querySelectorAll('.js-cohort-chk').forEach((chk) => {
            chk.closest('.cohort-chip')?.classList.toggle('is-picked', chk.checked);
            if (chk.checked) {
                classesPicked++;
                totalStudents += parseInt(chk.getAttribute('data-count') || '0', 10);
            }
        });
        const stuEl = document.getElementById('selectedStudentSummary');
        if (stuEl) stuEl.textContent = `${classesPicked} class(es) • ${totalStudents} student(s) selected`;

        const capPerBench = parseInt(capIn?.value || '2', 10);
        let benchesTotal = 0;
        document.querySelectorAll('.js-bench-cnt').forEach((inp) => {
            benchesTotal += Math.max(0, parseInt(inp.value || '0', 10));
        });
        const totalSeats = benchesTotal * capPerBench;
        const capEl = document.getElementById('roomCapacitySummary');
        if (capEl) {
            capEl.innerHTML = `Total Room Capacity: <strong>${totalSeats}</strong> seats (${benchesTotal} benches &times; ${capPerBench} seats/bench)`
                + (totalStudents > 0 && totalSeats < totalStudents ? ` <span class="text-danger fw-bold">(Need ${totalStudents - totalSeats} more seat(s)!)</span>` : '');
        }
    }

    document.querySelectorAll('.js-cohort-chk').forEach((chk) => chk.addEventListener('change', refreshCapacitySummary));
    if (capIn) capIn.addEventListener('change', refreshCapacitySummary);
    if (roomsWrap) {
        roomsWrap.addEventListener('input', refreshCapacitySummary);
        roomsWrap.addEventListener('click', (e) => {
            const del = e.target.closest('.js-del-room');
            if (!del) return;
            if (roomsWrap.querySelectorAll('.js-room-row').length <= 1) return;
            del.closest('.js-room-row')?.remove();
            refreshCapacitySummary();
        });
    }
    if (addRoomBtn && roomsWrap) {
        addRoomBtn.addEventListener('click', () => {
            const nextIdx = roomsWrap.querySelectorAll('.js-room-row').length + 1;
            const col = document.createElement('div');
            col.className = 'col-md-4 js-room-row';
            col.innerHTML = `
                <div class="input-group input-group-sm">
                    <span class="input-group-text fw-bold">Room</span>
                    <input type="text" name="room_name[]" class="form-control" value="Room 10${nextIdx}" required>
                    <span class="input-group-text">Benches</span>
                    <input type="number" name="bench_count[]" class="form-control js-bench-cnt" value="15" min="1" max="60" style="max-width:70px;" required>
                    <button type="button" class="btn btn-outline-danger js-del-room" title="Remove Room">&times;</button>
                </div>`;
            roomsWrap.appendChild(col);
            refreshCapacitySummary();
        });
    }
    refreshCapacitySummary();

    // Printable view switcher tabs
    document.querySelectorAll('.view-tab-btn').forEach((btn) => {
        btn.addEventListener('click', () => {
            const target = btn.getAttribute('data-view');
            document.querySelectorAll('.view-tab-btn').forEach((b) => b.classList.remove('active'));
            btn.classList.add('active');
            document.querySelectorAll('.seating-view-pane').forEach((pane) => {
                pane.classList.toggle('is-active', pane.id === `pane-${target}`);
            });
        });
    });
});
</script>
</body>
</html>
