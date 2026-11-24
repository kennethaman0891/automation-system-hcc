<?php
/**
 * HCC Automation System — 7:15 AM Teacher-Leave Substitute & Supervision Allocator (Automation #6)
 * Uses teacher_timetable_map, timetables/timetable_rows, and daily_substitutions:
 *   - Inline / bulk teacher-class-subject schedule manager (auto-seeded once out of the box)
 *   - Pick Date + Absent Teacher(s) -> lists uncovered slots
 *   - Ranks free teachers by zero period conflict, fewest substitutions in past 7 days, and subject affinity
 *   - 1-click "Auto-Allocate Best Fit" & manual overrides
 *   - Staffroom Proxy Noticeboard A4 Print view & per-teacher morning WhatsApp messages (Send-on-Press)
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

$adminPage  = 'substitute_allocator';
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

// Idempotent guard matching 2026_11_academic_ops_tables.sql
try {
    $db->exec("CREATE TABLE IF NOT EXISTS `teacher_timetable_map` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `timetable_id` INT UNSIGNED NULL DEFAULT NULL,
        `std` VARCHAR(16) NOT NULL,
        `division` VARCHAR(16) NOT NULL DEFAULT 'A',
        `subject_name` VARCHAR(120) NOT NULL,
        `teacher_name` VARCHAR(160) NOT NULL,
        `teacher_phone` VARCHAR(32) NOT NULL DEFAULT '',
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_ttm_timetable` (`timetable_id`),
        KEY `idx_ttm_class_subj` (`std`, `division`, `subject_name`),
        KEY `idx_ttm_teacher` (`teacher_name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS `daily_substitutions` (
        `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
        `sub_date` DATE NOT NULL,
        `period_label` VARCHAR(64) NOT NULL,
        `std` VARCHAR(16) NOT NULL,
        `division` VARCHAR(16) NOT NULL DEFAULT 'A',
        `absent_teacher` VARCHAR(160) NOT NULL,
        `substitute_teacher` VARCHAR(160) NOT NULL,
        `subject_name` VARCHAR(120) NOT NULL DEFAULT '',
        `whatsapp_notified` TINYINT(1) NOT NULL DEFAULT 0,
        `created_by` INT UNSIGNED NULL DEFAULT NULL,
        `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_daily_sub_slot` (`sub_date`, `period_label`, `std`, `division`),
        KEY `idx_daily_sub_date` (`sub_date`),
        KEY `idx_daily_sub_absent` (`sub_date`, `absent_teacher`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
} catch (Throwable $e) {
    error_log('substitute_allocator DDL guard note: ' . $e->getMessage());
}

/**
 * Seed starter teacher_timetable_map out-of-the-box (once) from report_card subjects or default core roster.
 */
function saSeedStarterMap(PDO $db, bool $force = false): int {
    $existingCount = (int)$db->query("SELECT COUNT(*) FROM teacher_timetable_map")->fetchColumn();
    if ($existingCount > 0 && !$force) {
        return 0;
    }

    $starterStaff = [
        'English'   => ['Mrs. Rebecca D\'Souza', '9822001101'],
        'Marathi'   => ['Mr. Sandeep Kulkarni',  '9822001102'],
        'Hindi'     => ['Mrs. Sunita Sharma',    '9822001103'],
        'Maths'     => ['Mr. Rajesh Patil',      '9822001104'],
        'Science'   => ['Mrs. Priya Joshi',      '9822001105'],
        'History'   => ['Mr. Prakash Deshmukh',  '9822001106'],
        'Geography' => ['Mrs. Kavita Shinde',    '9822001107'],
        'EVS'       => ['Mrs. Meena Kamble',     '9822001108'],
        'Computer'  => ['Mr. Vivek Gaikwad',     '9822001109'],
        'Drawing'   => ['Ms. Sneha Pawar',       '9822001110'],
    ];
    $matchStaff = static function (string $subj) use ($starterStaff): array {
        $l = mb_strtolower($subj);
        if (str_contains($l, 'math') || str_contains($l, 'alg') || str_contains($l, 'geom')) return $starterStaff['Maths'];
        if (str_contains($l, 'eng')) return $starterStaff['English'];
        if (str_contains($l, 'mar')) return $starterStaff['Marathi'];
        if (str_contains($l, 'hin') || str_contains($l, 'sans')) return $starterStaff['Hindi'];
        if (str_contains($l, 'sci')) return $starterStaff['Science'];
        if (str_contains($l, 'hist') || str_contains($l, 'civ')) return $starterStaff['History'];
        if (str_contains($l, 'geog')) return $starterStaff['Geography'];
        if (str_contains($l, 'evs')) return $starterStaff['EVS'];
        if (str_contains($l, 'comp') || str_contains($l, 'it')) return $starterStaff['Computer'];
        if (str_contains($l, 'draw') || str_contains($l, 'art')) return $starterStaff['Drawing'];
        return ['Mrs. Anjali Kale', '9822001111'];
    };

    $rowsToInsert = [];
    try {
        $activeYr = rcGetActiveAcademicYear();
        $rcStmt = $db->prepare("SELECT DISTINCT std, division, subject_name
                                FROM report_card
                                WHERE academic_year = ?
                                ORDER BY CAST(std AS UNSIGNED), division, subject_name
                                LIMIT 45");
        $rcStmt->execute([$activeYr]);
        foreach ($rcStmt->fetchAll(PDO::FETCH_ASSOC) as $rcRow) {
            [$tName, $tPhone] = $matchStaff((string)$rcRow['subject_name']);
            $rowsToInsert[] = [
                'std'          => trim((string)$rcRow['std']),
                'division'     => trim((string)$rcRow['division']) ?: 'A',
                'subject_name' => trim((string)$rcRow['subject_name']),
                'teacher_name' => $tName,
                'teacher_phone'=> $tPhone,
            ];
        }
    } catch (Throwable $e) {}

    if (empty($rowsToInsert)) {
        $rowsToInsert = [
            ['std'=>'5','division'=>'A','subject_name'=>'English',   'teacher_name'=>'Mrs. Rebecca D\'Souza','teacher_phone'=>'9822001101'],
            ['std'=>'5','division'=>'A','subject_name'=>'Maths',     'teacher_name'=>'Mr. Rajesh Patil',     'teacher_phone'=>'9822001104'],
            ['std'=>'6','division'=>'A','subject_name'=>'Marathi',   'teacher_name'=>'Mr. Sandeep Kulkarni', 'teacher_phone'=>'9822001102'],
            ['std'=>'6','division'=>'A','subject_name'=>'Science',   'teacher_name'=>'Mrs. Priya Joshi',     'teacher_phone'=>'9822001105'],
            ['std'=>'7','division'=>'A','subject_name'=>'English',   'teacher_name'=>'Mrs. Rebecca D\'Souza','teacher_phone'=>'9822001101'],
            ['std'=>'7','division'=>'A','subject_name'=>'Hindi',     'teacher_name'=>'Mrs. Sunita Sharma',   'teacher_phone'=>'9822001103'],
            ['std'=>'8','division'=>'A','subject_name'=>'Maths',     'teacher_name'=>'Mr. Rajesh Patil',     'teacher_phone'=>'9822001104'],
            ['std'=>'8','division'=>'A','subject_name'=>'History',   'teacher_name'=>'Mr. Prakash Deshmukh', 'teacher_phone'=>'9822001106'],
            ['std'=>'9','division'=>'A','subject_name'=>'Science',   'teacher_name'=>'Mrs. Priya Joshi',     'teacher_phone'=>'9822001105'],
            ['std'=>'9','division'=>'A','subject_name'=>'Geography', 'teacher_name'=>'Mrs. Kavita Shinde',   'teacher_phone'=>'9822001107'],
            ['std'=>'10','division'=>'A','subject_name'=>'English',  'teacher_name'=>'Mrs. Rebecca D\'Souza','teacher_phone'=>'9822001101'],
            ['std'=>'10','division'=>'A','subject_name'=>'Maths',    'teacher_name'=>'Mr. Rajesh Patil',     'teacher_phone'=>'9822001104'],
        ];
    }

    $ins = $db->prepare("INSERT INTO teacher_timetable_map (timetable_id, std, division, subject_name, teacher_name, teacher_phone) VALUES (NULL, ?, ?, ?, ?, ?)");
    $added = 0;
    foreach ($rowsToInsert as $item) {
        $ins->execute([$item['std'], $item['division'], $item['subject_name'], $item['teacher_name'], $item['teacher_phone']]);
        $added++;
    }
    return $added;
}

if (empty($settings['ttm_auto_seeded'])) {
    try {
        if (saSeedStarterMap($db, false) > 0) {
            saveSetting('ttm_auto_seeded', '1');
        }
    } catch (Throwable $e) {}
}

$subDate = trim((string)($_REQUEST['sub_date'] ?? date('Y-m-d')));
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $subDate)) {
    $subDate = date('Y-m-d');
}

$selectedAbsent = $_REQUEST['absent'] ?? [];
if (!is_array($selectedAbsent)) {
    $selectedAbsent = $selectedAbsent !== '' ? [$selectedAbsent] : [];
}
$selectedAbsent = array_values(array_unique(array_filter(array_map('trim', $selectedAbsent))));

$flashSuccess = '';
$flashError   = '';

// Handle POST Actions (CSRF-protected)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    if (!verifyCSRFToken()) {
        $flashError = 'Security token verification failed. Please refresh and try again.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));

        if ($action === 'add_teacher_map') {
            $mStd   = trim((string)($_POST['std'] ?? ''));
            $mDiv   = trim((string)($_POST['division'] ?? 'A')) ?: 'A';
            $mSubj  = trim((string)($_POST['subject_name'] ?? ''));
            $mTch   = trim((string)($_POST['teacher_name'] ?? ''));
            $mPhone = preg_replace('/\D+/', '', (string)($_POST['teacher_phone'] ?? ''));

            if ($mStd === '' || $mSubj === '' || $mTch === '') {
                $flashError = 'Standard, Subject, and Teacher Name are required.';
            } else {
                $st = $db->prepare("INSERT INTO teacher_timetable_map (timetable_id, std, division, subject_name, teacher_name, teacher_phone) VALUES (NULL, ?, ?, ?, ?, ?)");
                $st->execute([$mStd, $mDiv, $mSubj, $mTch, $mPhone]);
                $flashSuccess = "Added mapping: {$mTch} -> STD {$mStd} {$mDiv} ({$mSubj}).";
            }
        } elseif ($action === 'delete_teacher_map') {
            $delId = (int)($_POST['map_id'] ?? 0);
            if ($delId > 0) {
                $db->prepare("DELETE FROM teacher_timetable_map WHERE id = ?")->execute([$delId]);
                $flashSuccess = 'Removed teacher timetable mapping.';
            }
        } elseif ($action === 'seed_sample_map') {
            $countAdded = saSeedStarterMap($db, true);
            saveSetting('ttm_auto_seeded', '1');
            $flashSuccess = "Seeded {$countAdded} teacher schedule mappings.";
        } elseif ($action === 'bulk_import_map') {
            $rawCsv = trim((string)($_POST['bulk_csv'] ?? ''));
            if ($rawCsv === '') {
                $flashError = 'Paste lines in format: Std, Division, Subject, Teacher Name, Phone';
            } else {
                $lines = preg_split('/\r\n|\r|\n/', $rawCsv);
                $ins = $db->prepare("INSERT INTO teacher_timetable_map (timetable_id, std, division, subject_name, teacher_name, teacher_phone) VALUES (NULL, ?, ?, ?, ?, ?)");
                $imported = 0;
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with(strtolower($line), 'std,')) continue;
                    $cols = array_map('trim', str_getcsv($line));
                    if (count($cols) < 4) continue;
                    if ($cols[0] === '' || $cols[2] === '' || $cols[3] === '') continue;
                    $ins->execute([
                        $cols[0],
                        $cols[1] !== '' ? $cols[1] : 'A',
                        $cols[2],
                        $cols[3],
                        preg_replace('/\D+/', '', (string)($cols[4] ?? '')),
                    ]);
                    $imported++;
                }
                $flashSuccess = "Imported {$imported} teacher schedule rows.";
            }
        } elseif ($action === 'save_substitutions' || $action === 'auto_allocate') {
            $slots = $_POST['slots'] ?? [];
            if (!is_array($slots) || empty($slots)) {
                $flashError = 'No uncovered periods selected to allocate.';
            } else {
                // Build teacher directory & 7-day load + existing period occupancy for auto-allocation
                $allMaps = $db->query("SELECT std, division, subject_name, teacher_name, teacher_phone FROM teacher_timetable_map ORDER BY teacher_name")->fetchAll(PDO::FETCH_ASSOC);
                $teacherProfiles = [];
                foreach ($allMaps as $mp) {
                    $tn = trim((string)$mp['teacher_name']);
                    if ($tn === '') continue;
                    if (!isset($teacherProfiles[$tn])) {
                        $teacherProfiles[$tn] = ['name' => $tn, 'phone' => $mp['teacher_phone'], 'subjects' => [], 'stds' => []];
                    }
                    $teacherProfiles[$tn]['subjects'][mb_strtolower(trim((string)$mp['subject_name']))] = true;
                    $teacherProfiles[$tn]['stds'][trim((string)$mp['std'])] = true;
                }

                $load7d = [];
                $ldStmt = $db->prepare("SELECT substitute_teacher, COUNT(*) AS cnt FROM daily_substitutions WHERE sub_date BETWEEN DATE_SUB(?, INTERVAL 6 DAY) AND ? GROUP BY substitute_teacher");
                $ldStmt->execute([$subDate, $subDate]);
                foreach ($ldStmt->fetchAll(PDO::FETCH_ASSOC) as $lr) {
                    $load7d[$lr['substitute_teacher']] = (int)$lr['cnt'];
                }

                $busyInPeriod = [];
                $bpStmt = $db->prepare("SELECT period_label, substitute_teacher FROM daily_substitutions WHERE sub_date = ?");
                $bpStmt->execute([$subDate]);
                foreach ($bpStmt->fetchAll(PDO::FETCH_ASSOC) as $bp) {
                    $busyInPeriod[$bp['period_label']][$bp['substitute_teacher']] = true;
                }

                $upsert = $db->prepare("
                    INSERT INTO daily_substitutions
                        (sub_date, period_label, std, division, absent_teacher, substitute_teacher, subject_name, whatsapp_notified, created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?)
                    ON DUPLICATE KEY UPDATE
                        absent_teacher = VALUES(absent_teacher),
                        substitute_teacher = VALUES(substitute_teacher),
                        subject_name = VALUES(subject_name),
                        created_by = VALUES(created_by)
                ");

                $savedCount = 0;
                $skippedCount = 0;
                $absentLookup = array_flip($selectedAbsent);

                foreach ($slots as $slotRow) {
                    $pLabel = trim((string)($slotRow['period_label'] ?? ''));
                    $sStd   = trim((string)($slotRow['std'] ?? ''));
                    $sDiv   = trim((string)($slotRow['division'] ?? 'A'));
                    $sSubj  = trim((string)($slotRow['subject_name'] ?? ''));
                    $sAbs   = trim((string)($slotRow['absent_teacher'] ?? ''));
                    $sSub   = trim((string)($slotRow['substitute_teacher'] ?? ''));

                    if ($pLabel === '' || $sStd === '' || $sAbs === '') continue;

                    if ($action === 'auto_allocate') {
                        $candidates = [];
                        $subjKey = mb_strtolower($sSubj);
                        foreach ($teacherProfiles as $tName => $prof) {
                            if (isset($absentLookup[$tName]) || $tName === $sAbs) continue;
                            if (!empty($busyInPeriod[$pLabel][$tName])) continue;
                            $affinity = isset($prof['subjects'][$subjKey]) ? 1 : 0;
                            if ($affinity === 0) {
                                foreach (array_keys($prof['subjects']) as $ps) {
                                    if ($subjKey !== '' && (str_contains($ps, $subjKey) || str_contains($subjKey, $ps))) {
                                        $affinity = 1;
                                        break;
                                    }
                                }
                            }
                            $sameStd = isset($prof['stds'][$sStd]) ? 1 : 0;
                            $candidates[] = [
                                'name'     => $tName,
                                'load_7d'  => $load7d[$tName] ?? 0,
                                'affinity' => $affinity,
                                'same_std' => $sameStd,
                            ];
                        }
                        usort($candidates, static function ($a, $b) {
                            return ($a['load_7d'] <=> $b['load_7d'])
                                ?: ($b['affinity'] <=> $a['affinity'])
                                ?: ($b['same_std'] <=> $a['same_std'])
                                ?: strcmp($a['name'], $b['name']);
                        });
                        if (!empty($candidates)) {
                            $sSub = $candidates[0]['name'];
                        }
                    }

                    if ($sSub === '') {
                        $skippedCount++;
                        continue;
                    }
                    $upsert->execute([$subDate, $pLabel, $sStd, $sDiv, $sAbs, $sSub, $sSubj, $adminId]);
                    $busyInPeriod[$pLabel][$sSub] = true;
                    $load7d[$sSub] = ($load7d[$sSub] ?? 0) + 1;
                    $savedCount++;
                }

                if ($savedCount > 0) {
                    $flashSuccess = ($action === 'auto_allocate' ? 'Auto-allocated ' : 'Saved ') . "{$savedCount} substitute assignment(s) for {$subDate}."
                        . ($skippedCount > 0 ? " ({$skippedCount} slot(s) skipped — no free teacher available.)" : '');
                } else {
                    $flashError = 'No substitute teachers could be assigned (check that non-absent teachers exist in Teacher Timetable Map).';
                }
            }
        } elseif ($action === 'mark_notified') {
            $subId = (int)($_POST['sub_id'] ?? 0);
            if ($subId > 0) {
                $db->prepare("UPDATE daily_substitutions SET whatsapp_notified = 1 WHERE id = ?")->execute([$subId]);
                $flashSuccess = 'Marked substitute WhatsApp notice as dispatched.';
            }
        } elseif ($action === 'delete_substitution') {
            $subId = (int)($_POST['sub_id'] ?? 0);
            if ($subId > 0) {
                $db->prepare("DELETE FROM daily_substitutions WHERE id = ?")->execute([$subId]);
                $flashSuccess = 'Removed substitution entry.';
            }
        }
    }
}

// Fetch Teacher Timetable Map + Exam Time Slot labels from timetables/timetable_rows
$teacherMaps = [];
try {
    $teacherMaps = $db->query("SELECT * FROM teacher_timetable_map ORDER BY teacher_name ASC, CAST(std AS UNSIGNED) ASC, division ASC, subject_name ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$periodPresets = [
    'Period 1 (07:30-08:15)',
    'Period 2 (08:15-09:00)',
    'Period 3 (09:00-09:45)',
    'Period 4 (10:05-10:50)',
    'Period 5 (10:50-11:35)',
    'Period 6 (11:35-12:20)',
    'Period 7 (12:20-01:00)',
];
try {
    $ttSlots = $db->query("SELECT DISTINCT time_slot FROM timetable_rows ORDER BY id DESC LIMIT 12")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($ttSlots as $ts) {
        $ts = trim((string)$ts);
        if ($ts !== '' && !in_array($ts, $periodPresets, true)) {
            $periodPresets[] = $ts;
        }
    }
} catch (Throwable $e) {}

// Build Teacher Directory
$teachers = []; // teacher_name => ['phone'=>..., 'classes'=>[...], 'subjects'=>[...], 'stds'=>[...]]
foreach ($teacherMaps as $tm) {
    $tName = trim((string)$tm['teacher_name']);
    if ($tName === '') continue;
    if (!isset($teachers[$tName])) {
        $teachers[$tName] = [
            'name'     => $tName,
            'phone'    => trim((string)$tm['teacher_phone']),
            'classes'  => [],
            'subjects' => [],
            'stds'     => [],
        ];
    }
    if ($teachers[$tName]['phone'] === '' && !empty($tm['teacher_phone'])) {
        $teachers[$tName]['phone'] = trim((string)$tm['teacher_phone']);
    }
    $teachers[$tName]['classes'][] = $tm;
    $teachers[$tName]['subjects'][mb_strtolower(trim((string)$tm['subject_name']))] = true;
    $teachers[$tName]['stds'][trim((string)$tm['std'])] = true;
}
ksort($teachers);

// 7-day fairness substitution counts & today's occupancy
$subCount7d = [];
try {
    $st7 = $db->prepare("SELECT substitute_teacher, COUNT(*) AS cnt FROM daily_substitutions WHERE sub_date BETWEEN DATE_SUB(?, INTERVAL 6 DAY) AND ? GROUP BY substitute_teacher");
    $st7->execute([$subDate, $subDate]);
    foreach ($st7->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $subCount7d[$r['substitute_teacher']] = (int)$r['cnt'];
    }
} catch (Throwable $e) {}

$todaySubstitutions = [];
$slotBusyTeachers = []; // period_label => [teacher => true]
try {
    $stToday = $db->prepare("SELECT * FROM daily_substitutions WHERE sub_date = ? ORDER BY period_label ASC, CAST(std AS UNSIGNED) ASC, division ASC");
    $stToday->execute([$subDate]);
    $todaySubstitutions = $stToday->fetchAll(PDO::FETCH_ASSOC);
    foreach ($todaySubstitutions as $ts) {
        $slotBusyTeachers[$ts['period_label']][$ts['substitute_teacher']] = true;
        if (!in_array($ts['absent_teacher'], $selectedAbsent, true) && ($_SERVER['REQUEST_METHOD'] === 'GET' && empty($_GET['absent']))) {
            // Preserve previously logged absent teachers on today's view if user didn't submit a filter
        }
    }
} catch (Throwable $e) {}

// Build Uncovered Periods from checked absent teachers
$uncoveredSlots = [];
foreach ($selectedAbsent as $absTeacher) {
    if (!isset($teachers[$absTeacher])) continue;
    foreach ($teachers[$absTeacher]['classes'] as $pIdx => $clsRow) {
        $pLabel = $periodPresets[$pIdx % count($periodPresets)];
        $uncoveredSlots[] = [
            'period_label'   => $pLabel,
            'std'            => trim((string)$clsRow['std']),
            'division'       => trim((string)$clsRow['division']),
            'subject_name'   => trim((string)$clsRow['subject_name']),
            'absent_teacher' => $absTeacher,
        ];
    }
}

// Helper: Rank candidate teachers for an uncovered slot
$rankCandidates = static function (array $slot) use ($teachers, $selectedAbsent, $slotBusyTeachers, $subCount7d): array {
    $pLabel  = $slot['period_label'];
    $subjKey = mb_strtolower($slot['subject_name']);
    $absSet  = array_flip($selectedAbsent);
    $ranked  = [];

    foreach ($teachers as $tName => $prof) {
        if (isset($absSet[$tName]) || $tName === $slot['absent_teacher']) continue;
        $isBusy = !empty($slotBusyTeachers[$pLabel][$tName]);
        $affinity = isset($prof['subjects'][$subjKey]) ? 1 : 0;
        if ($affinity === 0) {
            foreach (array_keys($prof['subjects']) as $ps) {
                if ($subjKey !== '' && (str_contains($ps, $subjKey) || str_contains($subjKey, $ps))) {
                    $affinity = 1;
                    break;
                }
            }
        }
        $sameStd = isset($prof['stds'][$slot['std']]) ? 1 : 0;
        $ranked[] = [
            'name'     => $tName,
            'phone'    => $prof['phone'],
            'busy'     => $isBusy,
            'load_7d'  => $subCount7d[$tName] ?? 0,
            'affinity' => $affinity,
            'same_std' => $sameStd,
        ];
    }

    usort($ranked, static function ($a, $b) {
        return ($a['busy'] <=> $b['busy'])
            ?: ($a['load_7d'] <=> $b['load_7d'])
            ?: ($b['affinity'] <=> $a['affinity'])
            ?: ($b['same_std'] <=> $a['same_std'])
            ?: strcmp($a['name'], $b['name']);
    });
    return $ranked;
};
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php require __DIR__ . '/includes/account_head.php'; ?>
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title>7:15 AM Substitute Allocator | <?= sanitize($schoolName) ?></title>
    <link rel="icon" href="<?= BASE_URL ?>assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap@5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin.css?v=<?= is_file(__DIR__ . '/../assets/css/admin.css') ? filemtime(__DIR__ . '/../assets/css/admin.css') : 1 ?>">
    <style>
        .ops-wrap { padding: 22px 26px 40px; }
        .ops-panel { background: #fff; border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-sm); padding: 18px 20px; margin-bottom: 18px; }
        .ops-kpi-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(195px, 1fr)); gap: 14px; margin-bottom: 18px; }
        .ops-kpi-card { background: #fff; border: 1px solid var(--border); border-radius: var(--radius); padding: 14px 16px; box-shadow: var(--shadow-sm); display: flex; align-items: center; gap: 12px; }
        .ops-kpi-icon { width: 42px; height: 42px; border-radius: 10px; display: inline-flex; align-items: center; justify-content: center; font-size: 1.1rem; flex-shrink: 0; }
        .ops-kpi-val { font-size: 1.38rem; font-weight: 800; color: var(--ink); line-height: 1.1; }
        .ops-kpi-lbl { font-size: 0.72rem; font-weight: 700; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-2); margin-top: 2px; }
        .teacher-chk-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(210px, 1fr)); gap: 8px; }
        .teacher-chk-card { border: 1px solid var(--border); border-radius: 8px; padding: 9px 11px; display: flex; align-items: flex-start; gap: 8px; cursor: pointer; background: #fff; transition: border-color 0.15s, background 0.15s; }
        .teacher-chk-card:hover { border-color: var(--brand); background: var(--brand-soft); }
        .teacher-chk-card.is-absent { border-color: #ef4444; background: #fef2f2; }
        .btn-hcc { display: inline-flex; align-items: center; gap: 6px; padding: 8px 14px; border-radius: 8px; font-size: 0.84rem; font-weight: 600; border: 1px solid transparent; cursor: pointer; text-decoration: none; transition: all 0.16s ease; }
        .btn-hcc-primary { background: var(--brand); color: #fff; }
        .btn-hcc-primary:hover { background: var(--brand-dark); color: #fff; }
        .btn-hcc-gold { background: #b45309; color: #fff; }
        .btn-hcc-gold:hover { background: #92400e; color: #fff; }
        .btn-hcc-outline { background: #fff; color: var(--ink); border-color: var(--border); }
        .btn-hcc-outline:hover { border-color: var(--brand); color: var(--brand); }
        .btn-hcc-wa { background: #16a34a; color: #fff; }
        .btn-hcc-wa:hover { background: #15803d; color: #fff; }
        .ops-table th { font-size: 0.74rem; font-weight: 800; text-transform: uppercase; letter-spacing: 0.04em; color: var(--text-2); background: var(--surface); border-bottom: 2px solid var(--border); padding: 10px 12px; white-space: nowrap; }
        .ops-table td { padding: 10px 12px; vertical-align: middle; border-bottom: 1px solid var(--border); font-size: 0.85rem; }
        .print-notice-head { display: none; }
        @media print {
            @page { size: A4 portrait; margin: 10mm 12mm; }
            .admin-sidebar, .admin-header, .no-print, .ops-kpi-grid { display: none !important; }
            .admin-main { margin-left: 0 !important; }
            .ops-wrap { padding: 0 !important; }
            .ops-panel { box-shadow: none !important; border: none !important; padding: 0 !important; }
            .print-notice-head { display: block !important; border-bottom: 2px solid #000; padding-bottom: 8px; margin-bottom: 12px; text-align: center; }
            .ops-table th, .ops-table td { border: 1px solid #000 !important; padding: 7px 8px !important; font-size: 11pt !important; }
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
                <h1 class="page-title"><i class="fa-solid fa-user-clock text-info me-1"></i> 7:15 AM Substitute &amp; Proxy Allocator</h1>
                <p class="page-sub">Fairness-ranked substitute assignment with zero period collisions, Staffroom Noticeboard &amp; WhatsApp slip drafts.</p>
            </div>
            <div class="header-actions no-print">
                <button type="button" class="btn-hcc btn-hcc-outline" onclick="window.print()"><i class="fa-solid fa-print"></i> Print Staffroom Proxy Noticeboard</button>
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
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>
            <?php if ($flashError !== ''): ?>
                <div class="alert alert-danger alert-dismissible fade show no-print" role="alert">
                    <i class="fa-solid fa-triangle-exclamation me-1"></i> <?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
                </div>
            <?php endif; ?>

            <!-- KPIs -->
            <div class="ops-kpi-grid no-print">
                <div class="ops-kpi-card" style="border-left:4px solid #0284c7;">
                    <span class="ops-kpi-icon" style="background:#f0f9ff;color:#0369a1;"><i class="fa-solid fa-chalkboard-user"></i></span>
                    <div>
                        <div class="ops-kpi-val"><?= count($teachers) ?></div>
                        <div class="ops-kpi-lbl">Mapped Staff Members</div>
                    </div>
                </div>
                <div class="ops-kpi-card" style="border-left:4px solid #dc2626;">
                    <span class="ops-kpi-icon" style="background:#fef2f2;color:#dc2626;"><i class="fa-solid fa-user-xmark"></i></span>
                    <div>
                        <div class="ops-kpi-val"><?= count($selectedAbsent) ?></div>
                        <div class="ops-kpi-lbl">Absent Checked</div>
                    </div>
                </div>
                <div class="ops-kpi-card" style="border-left:4px solid #d97706;">
                    <span class="ops-kpi-icon" style="background:#fffbeb;color:#b45309;"><i class="fa-solid fa-list-check"></i></span>
                    <div>
                        <div class="ops-kpi-val"><?= count($uncoveredSlots) ?></div>
                        <div class="ops-kpi-lbl">Uncovered Periods</div>
                    </div>
                </div>
                <div class="ops-kpi-card" style="border-left:4px solid #15803d;">
                    <span class="ops-kpi-icon" style="background:#f0fdf4;color:#15803d;"><i class="fa-solid fa-check-double"></i></span>
                    <div>
                        <div class="ops-kpi-val"><?= count($todaySubstitutions) ?></div>
                        <div class="ops-kpi-lbl">Substitutions on <?= htmlspecialchars(date('d M', strtotime($subDate)), ENT_QUOTES, 'UTF-8') ?></div>
                    </div>
                </div>
            </div>

            <!-- Step 1: Pick Date & Mark Absent Teacher(s) -->
            <form method="GET" class="ops-panel no-print">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="fw-bold mb-0"><i class="fa-solid fa-calendar-day text-danger me-1"></i> Step 1: Select Date &amp; Absent Teacher(s)</h5>
                        <div class="small text-muted">Check teachers on leave today to inspect uncovered classes &amp; compute conflict-free substitutes.</div>
                    </div>
                    <div class="d-flex align-items-center gap-2">
                        <label for="subDateIn" class="small fw-bold text-secondary mb-0">Date:</label>
                        <input id="subDateIn" type="date" name="sub_date" value="<?= htmlspecialchars($subDate, ENT_QUOTES, 'UTF-8') ?>" class="form-control form-control-sm" style="width:auto;">
                        <button type="submit" class="btn-hcc btn-hcc-primary btn-sm"><i class="fa-solid fa-bolt"></i> Load Uncovered Slots</button>
                    </div>
                </div>

                <?php if (empty($teachers)): ?>
                    <div class="alert alert-warning mb-0">
                        No teachers mapped yet. Expand <strong>Quick Teacher Schedule Setup</strong> below to add or seed staff mappings.
                    </div>
                <?php else: ?>
                    <div class="teacher-chk-grid">
                        <?php foreach ($teachers as $tName => $tInfo):
                            $isAbs = in_array($tName, $selectedAbsent, true);
                            $loadCnt = $subCount7d[$tName] ?? 0;
                        ?>
                        <label class="teacher-chk-card <?= $isAbs ? 'is-absent' : '' ?>">
                            <input type="checkbox" name="absent[]" value="<?= htmlspecialchars($tName, ENT_QUOTES, 'UTF-8') ?>" <?= $isAbs ? 'checked' : '' ?> class="form-check-input mt-1">
                            <div class="min-w-0">
                                <div class="fw-bold small text-dark text-truncate"><?= htmlspecialchars($tName, ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="text-muted" style="font-size:0.73rem;">
                                    <?= count($tInfo['classes']) ?> class(es) &bull; <?= $loadCnt ?> sub(s)/7d
                                </div>
                            </div>
                        </label>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </form>

            <!-- Step 2: Uncovered Slots & Ranked Substitute Allocator -->
            <?php if (!empty($uncoveredSlots)): ?>
            <form method="POST" class="ops-panel no-print">
                <?= csrfField() ?>
                <input type="hidden" name="sub_date" value="<?= htmlspecialchars($subDate, ENT_QUOTES, 'UTF-8') ?>">
                <?php foreach ($selectedAbsent as $ab): ?>
                    <input type="hidden" name="absent[]" value="<?= htmlspecialchars($ab, ENT_QUOTES, 'UTF-8') ?>">
                <?php endforeach; ?>

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
                    <div>
                        <h5 class="fw-bold mb-0"><i class="fa-solid fa-wand-magic-sparkles text-warning me-1"></i> Step 2: Assign Substitutes (Fairness + Subject Affinity Ranked)</h5>
                        <div class="small text-muted">Ranked by zero period conflict, fewest substitutions in past 7 days, and subject match.</div>
                    </div>
                    <div class="d-flex gap-2">
                        <button type="submit" name="action" value="auto_allocate" class="btn-hcc btn-hcc-gold btn-sm">
                            <i class="fa-solid fa-wand-magic-sparkles"></i> Auto-Allocate Best Fit
                        </button>
                        <button type="submit" name="action" value="save_substitutions" class="btn-hcc btn-hcc-primary btn-sm">
                            <i class="fa-solid fa-floppy-disk"></i> Save Manual Selection
                        </button>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover mb-0 ops-table">
                        <thead>
                            <tr>
                                <th>Period / Time Slot</th>
                                <th>Class</th>
                                <th>Subject</th>
                                <th>Absent Teacher</th>
                                <th>Ranked Free Substitute Teacher</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($uncoveredSlots as $i => $slot):
                            $candidates = $rankCandidates($slot);
                            $stdLbl = 'STD ' . ($romanMap[$slot['std']] ?? $slot['std']) . ' ' . $slot['division'];
                        ?>
                            <tr>
                                <td>
                                    <select name="slots[<?= $i ?>][period_label]" class="form-select form-select-sm" style="min-width:190px;">
                                        <?php foreach ($periodPresets as $pr): ?>
                                            <option value="<?= htmlspecialchars($pr, ENT_QUOTES, 'UTF-8') ?>" <?= $pr === $slot['period_label'] ? 'selected' : '' ?>><?= htmlspecialchars($pr, ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                                <td>
                                    <span class="badge text-bg-light border"><?= htmlspecialchars($stdLbl, ENT_QUOTES, 'UTF-8') ?></span>
                                    <input type="hidden" name="slots[<?= $i ?>][std]" value="<?= htmlspecialchars($slot['std'], ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="slots[<?= $i ?>][division]" value="<?= htmlspecialchars($slot['division'], ENT_QUOTES, 'UTF-8') ?>">
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($slot['subject_name'], ENT_QUOTES, 'UTF-8') ?></strong>
                                    <input type="hidden" name="slots[<?= $i ?>][subject_name]" value="<?= htmlspecialchars($slot['subject_name'], ENT_QUOTES, 'UTF-8') ?>">
                                </td>
                                <td>
                                    <span class="text-danger fw-semibold"><?= htmlspecialchars($slot['absent_teacher'], ENT_QUOTES, 'UTF-8') ?></span>
                                    <input type="hidden" name="slots[<?= $i ?>][absent_teacher]" value="<?= htmlspecialchars($slot['absent_teacher'], ENT_QUOTES, 'UTF-8') ?>">
                                </td>
                                <td>
                                    <select name="slots[<?= $i ?>][substitute_teacher]" class="form-select form-select-sm" style="min-width:260px;">
                                        <option value="">-- Select Substitute --</option>
                                        <?php foreach ($candidates as $cIdx => $cand):
                                            $hint = $cand['busy'] ? 'BUSY in slot' : ($cand['load_7d'] . ' subs/7d' . ($cand['affinity'] ? ' • Subject Match' : '') . ($cand['same_std'] ? ' • Same Std' : ''));
                                        ?>
                                            <option value="<?= htmlspecialchars($cand['name'], ENT_QUOTES, 'UTF-8') ?>" <?= ($cIdx === 0 && !$cand['busy']) ? 'selected' : '' ?> <?= $cand['busy'] ? 'disabled' : '' ?>>
                                                <?= htmlspecialchars($cand['name'], ENT_QUOTES, 'UTF-8') ?> (<?= htmlspecialchars($hint, ENT_QUOTES, 'UTF-8') ?>)
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </form>
            <?php endif; ?>

            <!-- Step 3: Today's Staffroom Proxy Noticeboard (Screen + A4 Print) -->
            <div class="ops-panel">
                <div class="print-notice-head">
                    <div style="font-size:15pt;font-weight:800;text-transform:uppercase;"><?= htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8') ?></div>
                    <div style="font-size:12pt;font-weight:700;margin-top:2px;">DAILY STAFFROOM PROXY &amp; SUBSTITUTE NOTICEBOARD</div>
                    <div style="font-size:9.5pt;margin-top:3px;">Date: <strong><?= htmlspecialchars(date('l, d M Y', strtotime($subDate)), ENT_QUOTES, 'UTF-8') ?></strong> &bull; Generated at <?= date('h:i A') ?></div>
                </div>

                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3 no-print">
                    <div>
                        <h5 class="fw-bold mb-0"><i class="fa-solid fa-clipboard-list text-success me-1"></i> Active Proxy Ledger &mdash; <?= htmlspecialchars(date('d M Y', strtotime($subDate)), ENT_QUOTES, 'UTF-8') ?></h5>
                        <div class="small text-muted">Saved in <code>daily_substitutions</code>. Stage morning WhatsApp proxy slips below (Send-on-Press).</div>
                    </div>
                </div>

                <div class="table-responsive">
                    <table class="table table-hover mb-0 ops-table">
                        <thead>
                            <tr>
                                <th>Period / Slot</th>
                                <th>Class</th>
                                <th>Subject</th>
                                <th>Absent Teacher</th>
                                <th>Substitute Teacher</th>
                                <th class="no-print text-end">WhatsApp / Manage</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($todaySubstitutions)): ?>
                            <tr>
                                <td colspan="6" class="text-center py-4 text-muted">
                                    No substitutions saved yet for <?= htmlspecialchars($subDate, ENT_QUOTES, 'UTF-8') ?>. Select absent teacher(s) above and click <strong>Auto-Allocate Best Fit</strong>.
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($todaySubstitutions as $subRow):
                                $stdLbl = 'STD ' . ($romanMap[$subRow['std']] ?? $subRow['std']) . ' ' . $subRow['division'];
                                $subPhone = $teachers[$subRow['substitute_teacher']]['phone'] ?? '';
                                $waDigits = preg_replace('/\D+/', '', (string)$subPhone);
                                if (strlen($waDigits) === 10) $waDigits = '91' . $waDigits;
                                $waMsg = "Good morning {$subRow['substitute_teacher']}, kindly cover {$stdLbl} ({$subRow['subject_name']}) during {$subRow['period_label']} on " . date('d M Y', strtotime($subDate)) . " in place of {$subRow['absent_teacher']}. — {$schoolName}";
                                $waUrl = strlen($waDigits) >= 10
                                    ? ('https://wa.me/' . $waDigits . '?text=' . rawurlencode($waMsg))
                                    : ('https://wa.me/?text=' . rawurlencode($waMsg));
                            ?>
                            <tr>
                                <td class="fw-bold"><?= htmlspecialchars($subRow['period_label'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td><span class="badge text-bg-light border"><?= htmlspecialchars($stdLbl, ENT_QUOTES, 'UTF-8') ?></span></td>
                                <td><?= htmlspecialchars($subRow['subject_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="text-danger"><?= htmlspecialchars($subRow['absent_teacher'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td>
                                    <div class="fw-bold text-success"><?= htmlspecialchars($subRow['substitute_teacher'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if ($subPhone !== ''): ?>
                                        <div class="small text-muted no-print"><?= htmlspecialchars($subPhone, ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="no-print text-end">
                                    <div class="d-inline-flex gap-1 align-items-center">
                                        <button type="button" class="btn btn-sm btn-outline-secondary js-copy-sub-msg" data-msg="<?= htmlspecialchars($waMsg, ENT_QUOTES, 'UTF-8') ?>" title="Copy WhatsApp notice">
                                            <i class="fa-regular fa-copy"></i>
                                        </button>
                                        <a href="<?= htmlspecialchars($waUrl, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener noreferrer" class="btn btn-sm btn-success" title="Open WhatsApp message (Send-on-Press)">
                                            <i class="fa-brands fa-whatsapp"></i>
                                        </a>
                                        <?php if ((int)$subRow['whatsapp_notified'] === 1): ?>
                                            <span class="badge text-bg-success"><i class="fa-solid fa-check"></i> Notified</span>
                                        <?php else: ?>
                                            <form method="POST" class="d-inline">
                                                <?= csrfField() ?>
                                                <input type="hidden" name="action" value="mark_notified">
                                                <input type="hidden" name="sub_date" value="<?= htmlspecialchars($subDate, ENT_QUOTES, 'UTF-8') ?>">
                                                <?php foreach ($selectedAbsent as $ab): ?><input type="hidden" name="absent[]" value="<?= htmlspecialchars($ab, ENT_QUOTES, 'UTF-8') ?>"><?php endforeach; ?>
                                                <input type="hidden" name="sub_id" value="<?= (int)$subRow['id'] ?>">
                                                <button type="submit" class="btn btn-sm btn-outline-primary" title="Mark WhatsApp notice sent">Mark Sent</button>
                                            </form>
                                        <?php endif; ?>
                                        <form method="POST" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_substitution">
                                            <input type="hidden" name="sub_date" value="<?= htmlspecialchars($subDate, ENT_QUOTES, 'UTF-8') ?>">
                                            <?php foreach ($selectedAbsent as $ab): ?><input type="hidden" name="absent[]" value="<?= htmlspecialchars($ab, ENT_QUOTES, 'UTF-8') ?>"><?php endforeach; ?>
                                            <input type="hidden" name="sub_id" value="<?= (int)$subRow['id'] ?>">
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

                <div class="print-notice-head" style="border-bottom:none;border-top:1px solid #000;margin-top:24px;padding-top:14px;">
                    <div style="display:flex;justify-content:space-between;font-size:10pt;font-weight:700;">
                        <span>Supervisor Signature: ______________________</span>
                        <span>Principal / Headmistress: ______________________</span>
                    </div>
                </div>
            </div>

            <!-- Step 4: Quick Teacher Schedule Setup (Collapsible Inline CRUD + Seed + CSV Paste) -->
            <details class="ops-panel no-print" <?= empty($teacherMaps) ? 'open' : '' ?>>
                <summary class="fw-bold d-flex justify-content-between align-items-center" style="cursor:pointer;">
                    <span><i class="fa-solid fa-table-cells text-primary me-1"></i> Quick Teacher Schedule Setup (<code>teacher_timetable_map</code> &mdash; <?= count($teacherMaps) ?> mappings)</span>
                    <span class="small text-muted">Click to add, seed, bulk-paste, or remove staff-subject mappings</span>
                </summary>

                <div class="mt-3 pt-3 border-top">
                    <div class="row g-3 mb-3">
                        <div class="col-lg-8">
                            <form method="POST" class="row g-2 align-items-end bg-light p-3 rounded border">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="add_teacher_map">
                                <input type="hidden" name="sub_date" value="<?= htmlspecialchars($subDate, ENT_QUOTES, 'UTF-8') ?>">
                                <div class="col-md-2">
                                    <label class="form-label small fw-bold mb-1">Std</label>
                                    <input type="text" name="std" class="form-control form-control-sm" placeholder="e.g. 8" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small fw-bold mb-1">Div</label>
                                    <input type="text" name="division" class="form-control form-control-sm" value="A" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small fw-bold mb-1">Subject</label>
                                    <input type="text" name="subject_name" class="form-control form-control-sm" placeholder="e.g. Maths" required>
                                </div>
                                <div class="col-md-3">
                                    <label class="form-label small fw-bold mb-1">Teacher Name</label>
                                    <input type="text" name="teacher_name" class="form-control form-control-sm" placeholder="Mr. Patil" required>
                                </div>
                                <div class="col-md-2">
                                    <label class="form-label small fw-bold mb-1">Phone</label>
                                    <input type="text" name="teacher_phone" class="form-control form-control-sm" placeholder="98...">
                                </div>
                                <div class="col-12 d-flex gap-2 justify-content-end mt-2">
                                    <button type="submit" class="btn-hcc btn-hcc-primary btn-sm"><i class="fa-solid fa-plus"></i> Add Mapping</button>
                                </div>
                            </form>
                        </div>

                        <div class="col-lg-4 d-flex flex-column gap-2 justify-content-between bg-light p-3 rounded border">
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="seed_sample_map">
                                <input type="hidden" name="sub_date" value="<?= htmlspecialchars($subDate, ENT_QUOTES, 'UTF-8') ?>">
                                <button type="submit" class="btn btn-sm btn-outline-primary w-100"><i class="fa-solid fa-seedling me-1"></i> Auto-Populate from Report Card Classes</button>
                            </form>
                            <form method="POST">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="bulk_import_map">
                                <input type="hidden" name="sub_date" value="<?= htmlspecialchars($subDate, ENT_QUOTES, 'UTF-8') ?>">
                                <textarea name="bulk_csv" rows="2" class="form-control form-control-sm mb-1" placeholder="Bulk paste CSV: 8, A, Maths, Mr. Rajesh Patil, 9822001104"></textarea>
                                <button type="submit" class="btn btn-sm btn-outline-secondary w-100"><i class="fa-solid fa-file-import me-1"></i> Bulk Import Lines</button>
                            </form>
                        </div>
                    </div>

                    <?php if (!empty($teacherMaps)): ?>
                    <div class="table-responsive" style="max-height:300px;overflow-y:auto;">
                        <table class="table table-sm table-striped mb-0 ops-table">
                            <thead>
                                <tr>
                                    <th>Teacher</th>
                                    <th>Phone</th>
                                    <th>Std / Div</th>
                                    <th>Subject</th>
                                    <th class="text-end">Remove</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($teacherMaps as $tm): ?>
                                <tr>
                                    <td class="fw-semibold"><?= htmlspecialchars($tm['teacher_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="small font-monospace"><?= htmlspecialchars($tm['teacher_phone'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td>STD <?= htmlspecialchars($romanMap[$tm['std']] ?? $tm['std'], ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($tm['division'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td><?= htmlspecialchars($tm['subject_name'], ENT_QUOTES, 'UTF-8') ?></td>
                                    <td class="text-end">
                                        <form method="POST" class="d-inline">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="action" value="delete_teacher_map">
                                            <input type="hidden" name="sub_date" value="<?= htmlspecialchars($subDate, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="map_id" value="<?= (int)$tm['id'] ?>">
                                            <button type="submit" class="btn btn-sm btn-link text-danger p-0" title="Remove"><i class="fa-regular fa-trash-can"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </details>
        </div>
    </main>
</div>

<script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 1 ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
    document.querySelectorAll('.alert .btn-close').forEach((btn) => {
        btn.addEventListener('click', () => btn.closest('.alert')?.remove());
    });

    document.querySelectorAll('.teacher-chk-card input[type="checkbox"]').forEach((cb) => {
        cb.addEventListener('change', () => {
            cb.closest('.teacher-chk-card')?.classList.toggle('is-absent', cb.checked);
        });
    });

    document.querySelectorAll('.js-copy-sub-msg').forEach((btn) => {
        btn.addEventListener('click', async () => {
            const msg = btn.getAttribute('data-msg') || '';
            try {
                await navigator.clipboard.writeText(msg);
                btn.innerHTML = '<i class="fa-solid fa-check text-success"></i>';
                setTimeout(() => { btn.innerHTML = '<i class="fa-regular fa-copy"></i>'; }, 1600);
            } catch (e) {}
        });
    });
});
</script>
</body>
</html>
