<?php
/**
 * HCC Automation System — Dedicated WhatsApp Share Hub
 * Centralized command center & batch dispatcher for sharing Report Cards,
 * Unit Test Slips, Bonafide Certificates, and School Notices to parents.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

$adminPage = 'whatsapp_share';
$settings  = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church High School';
$schoolTagline = $settings['school_tagline'] ?? '';
$schoolLogo = $settings['logo'] ?? 'assets/images/hcclogo.jpg';
$adminUser  = sanitize($_SESSION['admin_name'] ?? 'Admin');
$adminInitials = strtoupper(implode('', array_map(
    function ($part) { return mb_substr($part, 0, 1); },
    array_slice(array_filter(preg_split('/\s+/', trim($_SESSION['admin_name'] ?? 'A'))), 0, 2)
))) ?: 'AD';
$notifData  = rcGetRecentNotifications(8);

// Handle AJAX actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'mark_notifs_read') {
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

    if ($action === 'update_parent_phone') {
        if (!verifyCSRFToken()) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Security token invalid. Please refresh.']);
            exit;
        }
        $roll = trim($_POST['roll_no'] ?? '');
        $phone = trim($_POST['phone'] ?? '');
        $postDoc = trim($_POST['doc_type'] ?? 'report_card');
        $postClassKey = trim($_POST['class_key'] ?? '');
        $postTestType = trim($_POST['test_type'] ?? 'Unit Test 2');
        $cParsed = rcParseClassKey(urldecode($postClassKey));
        $pYear = $cParsed['academic_year'] ?: '2025-26';
        $pStd = $cParsed['std'] ?: '1';
        $pDiv = $cParsed['division'] ?: 'A';
        $pSem = $cParsed['semester'] ?: 'Sem 1';

        $db = getDB();
        $updated = false;
        if ($roll !== '') {
            if ($postDoc === 'unit_test') {
                $uStmt = $db->prepare("UPDATE unit_test_results SET parent_phone = ?, updated_at = NOW() WHERE academic_year = ? AND std = ? AND division = ? AND test_type = ? AND roll_no = ?");
                $uStmt->execute([$phone, $pYear, $pStd, $pDiv, $postTestType, $roll]);
                // Keep report_card in sync if row exists
                $rStmt = $db->prepare("UPDATE report_card SET parent_phone = ? WHERE academic_year = ? AND std = ? AND division = ? AND roll_no = ?");
                $rStmt->execute([$phone, $pYear, $pStd, $pDiv, $roll]);
                $updated = true;
            } else {
                $uStmt = $db->prepare("UPDATE report_card SET parent_phone = ? WHERE academic_year = ? AND std = ? AND division = ? AND semester = ? AND roll_no = ?");
                $uStmt->execute([$phone, $pYear, $pStd, $pDiv, $pSem, $roll]);
                $updated = true;
            }

            // Sync with whatsapp_contacts and whatsapp_chats tables if present
            $cleanDigits = preg_replace('/\D/', '', $phone);
            if (strlen($cleanDigits) === 10) $cleanDigits = '91' . $cleanDigits;
            if (strlen($cleanDigits) >= 10) {
                try {
                    $phoneE164 = '+' . $cleanDigits;
                    $waChatId = $cleanDigits . '@c.us';
                    $cUp = $db->prepare("UPDATE whatsapp_contacts SET phone_e164 = ? WHERE linked_student_roll = ?");
                    $cUp->execute([$phoneE164, $roll]);
                    $chUp = $db->prepare("UPDATE whatsapp_chats SET wa_chat_id = ? WHERE linked_student_roll = ?");
                    $chUp->execute([$waChatId, $roll]);
                } catch (Exception $ignored) {}
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => $updated, 'roll' => $roll, 'phone' => $phone]);
        exit;
    }

    if ($action === 'log_dispatch_status') {
        if (!verifyCSRFToken()) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Security token invalid. Please refresh.']);
            exit;
        }

        $roll = trim($_POST['student_roll'] ?? '');
        $studentName = trim($_POST['student_name'] ?? '');
        $phone = trim($_POST['parent_phone'] ?? '');
        $postDoc = trim($_POST['doc_type'] ?? 'report_card');
        $postClassKey = trim($_POST['class_key'] ?? '');
        $postTestType = trim($_POST['test_type'] ?? '');
        $status = trim($_POST['status'] ?? 'pending');
        $pdfFilename = trim($_POST['pdf_filename'] ?? '');
        $messageText = trim($_POST['message_text'] ?? '');
        $errorMessage = trim($_POST['error_message'] ?? '');

        $validStatuses = ['pending', 'ready', 'opened', 'sent', 'skipped', 'failed', 'no_phone'];
        if (!in_array($status, $validStatuses, true)) {
            $status = 'pending';
        }

        $cParsed = rcParseClassKey(urldecode($postClassKey));
        $pYear = $cParsed['academic_year'] ?: '2025-26';
        $pStd = $cParsed['std'] ?: '1';
        $pDiv = $cParsed['division'] ?: 'A';
        $pSem = $cParsed['semester'] ?: 'Sem 1';

        $db = getDB();
        try {
            $sql = "INSERT INTO whatsapp_dispatch_log
                (academic_year, std, division, semester, test_type, doc_type,
                 student_roll, student_name, parent_phone, message_text, pdf_filename,
                 dispatch_method, status, error_message, dispatched_by, dispatched_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'manual', ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE
                student_name = VALUES(student_name),
                parent_phone = VALUES(parent_phone),
                message_text = VALUES(message_text),
                pdf_filename = VALUES(pdf_filename),
                dispatch_method = 'manual',
                status = VALUES(status),
                error_message = VALUES(error_message),
                dispatched_by = VALUES(dispatched_by),
                dispatched_at = NOW()";

            $stmt = $db->prepare($sql);
            $stmt->execute([
                $pYear, $pStd, $pDiv, $pSem, $postTestType, $postDoc,
                $roll, $studentName, $phone, $messageText, $pdfFilename,
                $status, $errorMessage ?: null, $adminUser
            ]);

            header('Content-Type: application/json');
            echo json_encode([
                'ok' => true,
                'roll' => $roll,
                'status' => $status,
                'dispatched_by' => $adminUser,
                'dispatched_at' => date('Y-m-d H:i:s')
            ]);
            exit;
        } catch (Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }

    if ($action === 'reset_class_dispatch_status') {
        if (!verifyCSRFToken()) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Security token invalid. Please refresh.']);
            exit;
        }
        $postDoc = trim($_POST['doc_type'] ?? 'report_card');
        $postClassKey = trim($_POST['class_key'] ?? '');
        $postTestType = trim($_POST['test_type'] ?? '');
        $cParsed = rcParseClassKey(urldecode($postClassKey));
        $pYear = $cParsed['academic_year'] ?: '2025-26';
        $pStd = $cParsed['std'] ?: '1';
        $pDiv = $cParsed['division'] ?: 'A';
        $pSem = $cParsed['semester'] ?: 'Sem 1';

        $db = getDB();
        try {
            if ($postDoc === 'unit_test') {
                $delStmt = $db->prepare("DELETE FROM whatsapp_dispatch_log WHERE academic_year = ? AND std = ? AND division = ? AND doc_type = ? AND test_type = ?");
                $delStmt->execute([$pYear, $pStd, $pDiv, $postDoc, $postTestType]);
            } elseif ($postDoc === 'bonafide') {
                $delStmt = $db->prepare("DELETE FROM whatsapp_dispatch_log WHERE academic_year = ? AND doc_type = ?");
                $delStmt->execute([$pYear, $postDoc]);
            } else {
                $delStmt = $db->prepare("DELETE FROM whatsapp_dispatch_log WHERE academic_year = ? AND std = ? AND division = ? AND semester = ? AND doc_type = ?");
                $delStmt->execute([$pYear, $pStd, $pDiv, $pSem, $postDoc]);
            }
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        } catch (Throwable $e) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
            exit;
        }
    }
}

// Available classes
$availableClasses = rcClassOptions();
$defaultClassKey = '';
if (!empty($availableClasses)) {
    $first = $availableClasses[0];
    $defaultClassKey = rcBuildClassKey($first['academic_year'], $first['std'], $first['division'], $first['semester']);
}

// Request parameters
$classKey = trim($_GET['class'] ?? $defaultClassKey);
$docType  = trim($_GET['doc_type'] ?? 'report_card'); // report_card | unit_test | bonafide | announcement
$testType = trim($_GET['test_type'] ?? 'Unit Test 2');
$search   = trim($_GET['search'] ?? '');
$viewMode = trim($_GET['view'] ?? 'hub'); // hub | replica

$c = rcParseClassKey(urldecode($classKey));
$year = $c['academic_year'] ?: '2025-26';
$std = $c['std'] ?: '1';
$division = $c['division'] ?: 'A';
$semester = $c['semester'] ?: 'Sem 1';

$romanMap = ['1'=>'I','2'=>'II','3'=>'III','4'=>'IV','5'=>'V','6'=>'VI','7'=>'VII','8'=>'VIII','9'=>'IX','10'=>'X'];
$romanStd = $romanMap[(string)$std] ?? $std;
$classLabel = "STD {$romanStd} {$division} ({$year})";

// Preload Dispatch Log Map from MySQL
$dispatchMap = [];
try {
    $db = getDB();
    if ($docType === 'unit_test') {
        $dStmt = $db->prepare("SELECT student_roll, status, dispatched_by, dispatched_at FROM whatsapp_dispatch_log WHERE academic_year = ? AND std = ? AND division = ? AND doc_type = ? AND test_type = ?");
        $dStmt->execute([$year, $std, $division, $docType, $testType]);
    } elseif ($docType === 'bonafide') {
        $dStmt = $db->prepare("SELECT student_roll, status, dispatched_by, dispatched_at FROM whatsapp_dispatch_log WHERE academic_year = ? AND doc_type = ?");
        $dStmt->execute([$year, $docType]);
    } else {
        $dStmt = $db->prepare("SELECT student_roll, status, dispatched_by, dispatched_at FROM whatsapp_dispatch_log WHERE academic_year = ? AND std = ? AND division = ? AND semester = ? AND doc_type = ?");
        $dStmt->execute([$year, $std, $division, $semester, $docType]);
    }
    while ($row = $dStmt->fetch(PDO::FETCH_ASSOC)) {
        $dispatchMap[(string)$row['student_roll']] = [
            'status' => $row['status'],
            'dispatched_by' => $row['dispatched_by'],
            'dispatched_at' => $row['dispatched_at']
        ];
    }
} catch (Throwable $e) {
    error_log('Error loading dispatch log: ' . $e->getMessage());
}

// Fetch Student Roster based on Document Type
$rosterStudents = [];
if ($docType === 'unit_test') {
    rcEnsureUnitTestTables();
    $utData = rcGetUnitTestClassData($year, $std, $division, $testType);
    $subjectsList = $utData['subjects'];
    $totalMaxMarks = $utData['total_max'];

    foreach ($utData['students'] as $stu) {
        if ($search !== '' && stripos((string)$stu['student_name'], $search) === false && stripos((string)$stu['roll_no'], $search) === false) {
            continue;
        }

        $subList = [];
        foreach ($subjectsList as $sub) {
            $sName = $sub['subject_name'];
            $sm = $stu['subjects'][$sName] ?? null;
            $obt = '0';
            if ($sm) {
                if ($sm['is_absent']) $obt = 'Absent';
                elseif ($sm['marks_obtained'] !== null) $obt = (string)$sm['marks_obtained'];
            }
            $subList[] = [
                'name' => $sName,
                'max' => (float)$sub['max_marks'],
                'obtained' => $obt
            ];
        }

        $publicUrl = rcGetPublicSlipUrl($year, $std, $division, $testType, $stu['roll_no']);

        $stuPassed = ($stu['percentage'] !== null && (float)$stu['percentage'] >= 35);
        $rollStr = (string)$stu['roll_no'];
        $dInfo = $dispatchMap[$rollStr] ?? null;
        $phoneDigits = preg_replace('/\D/', '', (string)($stu['parent_phone'] ?? ''));
        $initStatus = $dInfo ? $dInfo['status'] : (strlen($phoneDigits) < 10 ? 'no_phone' : 'pending');

        $rosterStudents[] = [
            'roll'            => $stu['roll_no'],
            'name'            => $stu['student_name'],
            'gender'          => $stu['gender'] ?? '',
            'score_label'     => ($stu['total_obtained'] !== null ? $stu['total_obtained'] : '0') . ' / ' . $totalMaxMarks,
            'pct'             => $stu['percentage'] !== null ? number_format((float)$stu['percentage'], 2) : '—',
            'grade'           => $stu['grade'] ?: '—',
            'status'          => $stuPassed ? 'PASS' : 'FAIL',
            'passed'          => $stuPassed,
            'remark'          => $stu['remark'] ?: '',
            'attendance'      => $stu['attendance'] ?: '',
            'parents_sign'    => (int)($stu['parents_sign'] ?? 0),
            'parent_phone'    => $stu['parent_phone'] ?? '',
            'dispatch_status' => $initStatus,
            'dispatched_by'   => $dInfo['dispatched_by'] ?? null,
            'dispatched_at'   => $dInfo['dispatched_at'] ?? null,
            'public_url'      => $publicUrl,
            'subjects'        => $subList,
            'max_total'       => $totalMaxMarks,
            'obtained'        => $stu['total_obtained'] ?: 0,
            'std'             => $std,
            'division'        => $division,
            'academic_year'   => $year,
        ];
    }
} elseif ($docType === 'bonafide') {
    rcEnsureArchiveTables();
    $db = getDB();
    $bfStmt = $db->prepare("SELECT * FROM bonafide_issued_log WHERE academic_year = ? ORDER BY id DESC");
    $bfStmt->execute([$year]);
    $rawBonafides = $bfStmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rawBonafides)) {
        // Fallback: load students in the active class and provide bonafide preview
        $filters = ['class_key' => $classKey];
        if ($search !== '') $filters['search'] = $search;
        $classStudents = rcAllStudents($filters);
        foreach ($classStudents as $stu) {
            $grNo = $settings['gr_no'] ?? '3875';
            $certSr = str_pad((string)$stu['roll_no'], 2, '0', STR_PAD_LEFT);
            $publicUrl = rcGetPublicBonafideUrl($grNo, $certSr);
            $dobDigits = $stu['date_of_birth'] ?? '';
            $dobWords = function_exists('dobToWords') ? dobToWords($dobDigits) : '';
            $stdDiv = 'STD – ' . ($stu['std'] ?? $std) . ' – ' . ($stu['division'] ?? $division);

            $rollStr = (string)$stu['roll_no'];
            $dInfo = $dispatchMap[$rollStr] ?? null;
            $phoneDigits = preg_replace('/\D/', '', (string)($stu['parent_phone'] ?? ''));
            $initStatus = $dInfo ? $dInfo['status'] : (strlen($phoneDigits) < 10 ? 'no_phone' : 'pending');

            $rosterStudents[] = [
                'roll'            => $stu['roll_no'],
                'name'            => $stu['student_name'],
                'gender'          => '',
                'score_label'     => 'Sr. ' . $certSr . ' / GR ' . $grNo,
                'pct'             => '—',
                'grade'           => 'Bonafide',
                'status'          => 'READY',
                'remark'          => 'Educational Purpose',
                'attendance'      => '',
                'parent_phone'    => $stu['parent_phone'] ?? '',
                'dispatch_status' => $initStatus,
                'dispatched_by'   => $dInfo['dispatched_by'] ?? null,
                'dispatched_at'   => $dInfo['dispatched_at'] ?? null,
                'public_url'      => $publicUrl,
                'subjects'        => [],
                'max_total'       => 0,
                'obtained'        => 0,
                'gr_no'           => $grNo,
                'cert_serial'     => $certSr,
                'academic_year'   => $year,
                'std'             => $stu['std'] ?? $std,
                'division'        => $stu['division'] ?? $division,
                'dob_digits'      => $dobDigits,
                'dob_words'       => $dobWords,
                'std_div'         => $stdDiv,
                'caste'           => $stu['caste'] ?? '',
                'issue_date'      => date('d/m/Y'),
            ];
        }
    } else {
        foreach ($rawBonafides as $bf) {
            if ($search !== '' && stripos((string)$bf['student_name'], $search) === false && stripos((string)($bf['roll_no'] ?? ''), $search) === false && stripos((string)($bf['cert_serial'] ?? ''), $search) === false) {
                continue;
            }
            $publicUrl = rcGetPublicBonafideUrl($bf['gr_no'], $bf['cert_serial']);
            $dobDigits = $bf['date_of_birth'] ?? '';
            $dobWords = function_exists('dobToWords') ? dobToWords($dobDigits) : '';
            $stdDiv = !empty($bf['std_division']) ? $bf['std_division'] : ('STD – ' . ($bf['std'] ?? $std) . ' – ' . ($bf['division'] ?? $division));
            $issuedDateRaw = $bf['issued_date'] ?? ($bf['issue_date'] ?? '');
            $issueDateFormatted = !empty($issuedDateRaw) ? date('d/m/Y', strtotime($issuedDateRaw)) : date('d/m/Y');

            $rollStr = (string)($bf['roll_no'] ?: $bf['cert_serial']);
            $dInfo = $dispatchMap[$rollStr] ?? null;
            $phoneDigits = preg_replace('/\D/', '', (string)($bf['parent_phone'] ?? ''));
            $initStatus = $dInfo ? $dInfo['status'] : (strlen($phoneDigits) < 10 ? 'no_phone' : 'pending');

            $rosterStudents[] = [
                'roll'            => $bf['roll_no'] ?: $bf['cert_serial'],
                'name'            => $bf['student_name'],
                'gender'          => '',
                'score_label'     => 'Sr. ' . $bf['cert_serial'] . ' / GR ' . $bf['gr_no'],
                'pct'             => '—',
                'grade'           => 'Bonafide',
                'status'          => 'ISSUED',
                'remark'          => $bf['purpose'] ?: 'Educational Purpose',
                'attendance'      => '',
                'parent_phone'    => $bf['parent_phone'] ?? '',
                'dispatch_status' => $initStatus,
                'dispatched_by'   => $dInfo['dispatched_by'] ?? null,
                'dispatched_at'   => $dInfo['dispatched_at'] ?? null,
                'public_url'      => $publicUrl,
                'subjects'        => [],
                'max_total'       => 0,
                'obtained'        => 0,
                'gr_no'           => $bf['gr_no'],
                'cert_serial'     => $bf['cert_serial'],
                'academic_year'   => $bf['academic_year'],
                'std'             => $bf['std'] ?? '',
                'division'        => $bf['division'] ?? '',
                'dob_digits'      => $dobDigits,
                'dob_words'       => $dobWords,
                'std_div'         => $stdDiv,
                'caste'           => $bf['caste'] ?? '',
                'issue_date'      => $issueDateFormatted,
            ];
        }
    }
} else {
    // Default: Semester Report Card
    $filters = ['class_key' => $classKey];
    if ($search !== '') $filters['search'] = $search;
    $rawStudents = rcAllStudents($filters);

    // Preload subject breakdown for each student in this class
    $db = getDB();
    $subStmt = $db->prepare("SELECT roll_no, subject_name, fa_max, fa_obtained, sa_max, sa_obtained FROM report_card WHERE academic_year=? AND std=? AND division=? AND semester=? ORDER BY roll_no, id");
    $subStmt->execute([$year, $std, $division, $semester]);
    $subjectsByRoll = [];
    while ($sr = $subStmt->fetch(PDO::FETCH_ASSOC)) {
        $subjectsByRoll[$sr['roll_no']][] = [
            'name'           => $sr['subject_name'],
            'fa_max'         => (float)$sr['fa_max'],
            'fa_obtained'    => (float)$sr['fa_obtained'],
            'sa_max'         => (float)$sr['sa_max'],
            'sa_obtained'    => (float)$sr['sa_obtained'],
            'total_max'      => (float)$sr['fa_max'] + (float)$sr['sa_max'],
            'total_obtained' => (float)$sr['fa_obtained'] + (float)$sr['sa_obtained'],
        ];
    }

    foreach ($rawStudents as $stu) {
        $publicUrl = rcGetPublicCardUrl($classKey, $stu['roll_no']);
        $percentage = (float)$stu['percentage'];
        $passed = ((int)$stu['std'] >= 9 ? $percentage >= 35 : $percentage >= 33);

        $rollStr = (string)$stu['roll_no'];
        $dInfo = $dispatchMap[$rollStr] ?? null;
        $phoneDigits = preg_replace('/\D/', '', (string)($stu['parent_phone'] ?? ''));
        $initStatus = $dInfo ? $dInfo['status'] : (strlen($phoneDigits) < 10 ? 'no_phone' : 'pending');

        $rosterStudents[] = [
            'roll'            => $stu['roll_no'],
            'name'            => $stu['student_name'],
            'gender'          => '',
            'score_label'     => number_format((float)$stu['obtained'], 1) . ' / ' . number_format((float)$stu['maxtotal'], 1),
            'pct'             => number_format($percentage, 2),
            'grade'           => $stu['grade'] ?? $stu['grade_letter'] ?? '—',
            'division_label'  => getGradeDivision($percentage, $stu['std']),
            'status'          => $passed ? 'PASS' : 'FAIL',
            'passed'          => $passed,
            'remark'          => $stu['remark'] ?: '',
            'attendance'      => $stu['attendance'] ?: '',
            'parents_sign'    => (int)($stu['parents_sign'] ?? 0),
            'parent_phone'    => $stu['parent_phone'] ?? '',
            'dispatch_status' => $initStatus,
            'dispatched_by'   => $dInfo['dispatched_by'] ?? null,
            'dispatched_at'   => $dInfo['dispatched_at'] ?? null,
            'public_url'      => $publicUrl,
            'subjects'        => $subjectsByRoll[$stu['roll_no']] ?? [],
            'max_total'       => (float)$stu['maxtotal'],
            'obtained'        => (float)$stu['obtained'],
            'std'             => $stu['std'] ?? $std,
            'division'        => $stu['division'] ?? $division,
            'academic_year'   => $stu['academic_year'] ?? $year,
            'semester'        => $stu['semester'] ?? $semester,
        ];
    }
}

// Filter by pre-selected student rolls if supplied from results matrix
$selectedRollsParam = trim($_GET['selected_rolls'] ?? '');
$isFilteredBySelection = false;
if ($selectedRollsParam !== '') {
    $selRollsArr = array_map('trim', explode(',', $selectedRollsParam));
    $rosterStudents = array_values(array_filter($rosterStudents, function($st) use ($selRollsArr) {
        return in_array((string)$st['roll'], $selRollsArr, true);
    }));
    $isFilteredBySelection = true;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(generateCSRFToken(), ENT_QUOTES) ?>">
    <title>WhatsApp Share Hub — <?= sanitize($schoolName) ?></title>
    <link rel="icon" href="../assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="../assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="../assets/vendor/merriweather/merriweather.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
    <link rel="stylesheet" href="../assets/css/whatsapp-web.css?v=<?= filemtime(__DIR__ . '/../assets/css/whatsapp-web.css') ?>">
    <link rel="stylesheet" href="../assets/css/whatsapp-share.css?v=<?= filemtime(__DIR__ . '/../assets/css/whatsapp-share.css') ?>">
    <style>
        /* Ensure normal vertical page scrolling */
        html, body {
            height: auto !important;
            min-height: 100% !important;
            overflow-x: hidden !important;
            overflow-y: auto !important;
        }
        body:not(.wa-fullscreen-active) .admin-layout {
            min-height: 100vh;
            display: flex;
        }
        body:not(.wa-fullscreen-active) .admin-main {
            min-height: 100vh;
            display: flex;
            flex-direction: column;
            flex: 1;
            min-width: 0;
            overflow: visible !important;
        }
        body:not(.wa-fullscreen-active) .admin-content {
            flex: 1;
            overflow: visible !important;
            padding-bottom: 50px;
        }

        .wa-hub-stats {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 14px;
            margin-bottom: 20px;
        }
        .wa-stat-card {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: var(--radius-sm);
            padding: 14px 18px;
            display: flex;
            align-items: center;
            gap: 14px;
            box-shadow: var(--shadow-sm);
        }
        .wa-stat-icon {
            width: 40px;
            height: 40px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.15rem;
        }
        .wa-stat-num { font-size: 1.35rem; font-weight: 800; color: var(--ink); line-height: 1; }
        .wa-stat-label { font-size: 0.76rem; color: var(--text-2); font-weight: 600; margin-top: 4px; text-transform: uppercase; }
        @media (max-width: 768px) {
            .wa-hub-stats { grid-template-columns: repeat(2, 1fr); }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <main class="admin-main">
            <!-- Topbar Header -->
            <header class="admin-header">
                <button class="sidebar-toggle js-sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <div class="header-titles">
                    <h1 class="page-title"><i class="fa-brands fa-whatsapp" style="color:var(--wa-green);margin-right:8px;"></i> WhatsApp Share &amp; Broadcast Hub</h1>
                    <p class="page-sub"><?= sanitize($schoolName) ?> &bull; Parent Communication &amp; Academic Dispatch Portal</p>
                </div>

                <div class="header-actions" style="display:flex;align-items:center;gap:12px;">
                    <!-- Fullscreen WhatsApp Toggle -->
                    <button type="button" class="btn btn-whatsapp" id="headerFullscreenBtn" title="Launch Edge-to-Edge WhatsApp Web Desktop Replica" style="display:inline-flex;align-items:center;gap:7px;padding:7px 14px;border-radius:8px;font-weight:700;font-size:0.84rem;background:#00a884;color:#fff;border:none;cursor:pointer;box-shadow:0 2px 8px rgba(0,168,132,0.25);">
                        <i class="fa-brands fa-whatsapp" style="font-size:1.05rem;"></i>
                        <span>Fullscreen WhatsApp</span>
                    </button>
                    <!-- Sync Class Roster Button -->
                    <button type="button" class="btn btn-outline" id="headerSyncRosterBtn" title="Sync Current Class Roster into WhatsApp Chats" style="display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:8px;font-size:0.82rem;font-weight:600;border:1px solid var(--border);background:var(--paper);cursor:pointer;">
                        <i class="fas fa-rotate"></i>
                        <span>Sync Roster</span>
                    </button>

                    <!-- Activity Alerts / Notification Bell -->
                    <?php require __DIR__ . '/includes/notif_bell.php'; ?>

                    <div class="admin-user">
                        <span class="user-avatar"><?= sanitize($adminInitials) ?></span>
                        <span class="user-meta">
                            <strong><?= $adminUser ?></strong>
                            <small>Administrator</small>
                        </span>
                    </div>
                </div>
            </header>

            <div class="admin-content">
                <input type="hidden" name="csrf_token" id="csrfToken" value="<?= generateCSRFToken() ?>">

                <?php if ($isFilteredBySelection):
                    $clearParams = $_GET;
                    unset($clearParams['selected_rolls']);
                    $clearUrl = 'whatsapp_share.php?' . http_build_query($clearParams);
                ?>
                <div class="wa-filter-notice">
                    <span><i class="fas fa-filter"></i> <strong>Selection active:</strong> <?= count($rosterStudents) ?> student<?= count($rosterStudents) === 1 ? '' : 's' ?> forwarded from results.</span>
                    <a href="<?= htmlspecialchars($clearUrl) ?>"><i class="fas fa-times"></i> Show all</a>
                </div>
                <?php endif; ?>

                <!-- Academic Filter & Toolbar Strip -->
                <div class="wa-hub-control-bar" style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;background:#ffffff;padding:12px 18px;border-radius:10px;border:1px solid var(--border);box-shadow:var(--shadow-sm);flex-wrap:wrap;">
                    <form method="GET" action="whatsapp_share.php" id="classSelectorForm" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0;">
                        <input type="hidden" name="view" id="viewModeInput" value="<?= e_attr($viewMode) ?>">
                        <div style="display:flex;align-items:center;gap:6px;">
                            <label for="classSelect" style="font-size:0.8rem;font-weight:700;color:var(--ink);"><i class="fas fa-chalkboard-user" style="color:var(--wa-green);margin-right:4px;"></i>Class:</label>
                            <select name="class" id="classSelect" class="form-select" onchange="this.form.submit()" style="padding:6px 10px;border-radius:6px;border:1px solid #cbd5e1;font-size:0.83rem;font-weight:600;min-width:180px;">
                                <?php foreach ($availableClasses as $ac):
                                    $ck = rcBuildClassKey($ac['academic_year'], $ac['std'], $ac['division'], $ac['semester']);
                                    $rStd = $romanMap[(string)$ac['std']] ?? $ac['std'];
                                ?>
                                <option value="<?= e_attr($ck) ?>" <?= $ck === $classKey ? 'selected' : '' ?>>STD <?= $rStd ?> <?= $ac['division'] ?> (<?= $ac['academic_year'] ?>) &bull; <?= $ac['students'] ?> students</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="display:flex;align-items:center;gap:6px;">
                            <label for="docTypeSelectTop" style="font-size:0.8rem;font-weight:700;color:var(--ink);"><i class="fas fa-file-invoice" style="color:var(--wa-green);margin-right:4px;"></i>Document:</label>
                            <select name="doc_type" id="docTypeSelectTop" class="form-select" onchange="this.form.submit()" style="padding:6px 10px;border-radius:6px;border:1px solid #cbd5e1;font-size:0.83rem;font-weight:600;">
                                <option value="report_card" <?= $docType==='report_card' ? 'selected' : '' ?>>Semester Report Card</option>
                                <option value="unit_test" <?= $docType==='unit_test' ? 'selected' : '' ?>>Unit Test Progress Slip</option>
                                <option value="bonafide" <?= $docType==='bonafide' ? 'selected' : '' ?>>Bonafide Certificate</option>
                                <option value="announcement" <?= $docType==='announcement' ? 'selected' : '' ?>>Notice / Announcement</option>
                            </select>
                        </div>

                        <?php if ($docType === 'unit_test'): ?>
                        <select name="test_type" id="testTypeSelectTop" class="form-select" onchange="this.form.submit()" style="padding:6px 10px;border-radius:6px;border:1px solid #cbd5e1;font-size:0.83rem;">
                            <?php foreach (['Unit Test 1', 'Unit Test 2', 'Unit Test 3', 'Unit Test 4'] as $tt): ?>
                            <option value="<?= e_attr($tt) ?>" <?= $testType===$tt ? 'selected' : '' ?>><?= sanitize($tt) ?></option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </form>

                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <!-- View Mode Switcher: Hub vs Follow-ups vs Web Replica -->
                        <div class="wa-view-switcher" style="display:inline-flex;background:#f1f5f9;padding:3px;border-radius:8px;border:1px solid #cbd5e1;">
                            <button type="button" class="btn-view-toggle <?= $viewMode !== 'replica' ? 'active' : '' ?>" id="btnViewHub" data-view="hub" title="Switch to Academic Share & Dispatch Hub" style="padding:6px 13px;border-radius:6px;font-size:0.82rem;font-weight:700;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all 0.15s;<?= $viewMode !== 'replica' ? 'background:#00a884;color:#fff;box-shadow:0 1px 3px rgba(0,0,0,0.12);' : 'background:transparent;color:#475569;' ?>">
                                <i class="fas fa-paper-plane"></i> <span>Share Hub</span>
                            </button>
                            <a href="admission_followups.php" class="btn-view-toggle" id="btnViewFollowups" title="Switch to Admission Follow-up Management CRM" style="padding:6px 13px;border-radius:6px;font-size:0.82rem;font-weight:700;text-decoration:none;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all 0.15s;background:transparent;color:#475569;">
                                <i class="fa-solid fa-headset" style="color:#10b981;"></i> <span>Admission Follow-ups</span>
                            </a>
                            <button type="button" class="btn-view-toggle <?= $viewMode === 'replica' ? 'active' : '' ?>" id="btnViewReplica" data-view="replica" title="Switch to WhatsApp Web Replica Client" style="padding:6px 13px;border-radius:6px;font-size:0.82rem;font-weight:700;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all 0.15s;<?= $viewMode === 'replica' ? 'background:#00a884;color:#fff;box-shadow:0 1px 3px rgba(0,0,0,0.12);' : 'background:transparent;color:#475569;' ?>">
                                <i class="fa-brands fa-whatsapp"></i> <span>Web Replica</span>
                            </button>
                        </div>

                        <button type="button" class="btn btn-outline" id="btnSyncClassRoster" title="Sync current class roster into WhatsApp contacts & chats" style="display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:8px;font-size:0.82rem;font-weight:600;border:1px solid #cbd5e1;background:#fff;cursor:pointer;">
                            <i class="fas fa-rotate"></i> Sync Roster
                        </button>
                        <button type="button" class="btn btn-whatsapp" id="btnToggleFullscreen" title="Toggle Fullscreen WhatsApp Web Replica" style="display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border-radius:8px;font-size:0.82rem;font-weight:600;background:var(--wa-green, #00a884);color:#ffffff;border:none;cursor:pointer;">
                            <i class="fa-brands fa-whatsapp"></i> Fullscreen
                        </button>
                    </div>
                </div>

                <!-- ══════════════ 1. ACADEMIC WHATSAPP SHARE HUB (DEFAULT ACTIVE) ══════════════ -->
                <div class="wa-app" id="waApp" style="<?= $viewMode === 'replica' ? 'display:none;' : '' ?>">
                    <!-- ══════════════ LEFT: WHATSAPP CHAT LIST ══════════════ -->
                    <aside class="wa-chatlist">
                        <div class="wa-cl-header">
                            <div class="wa-cl-avatar wa-cl-avatar-logo"><img src="<?= BASE_URL ?>assets/images/hcclogo.jpg" alt="HCC logo"></div>
                            <div class="wa-cl-title">
                                <b>WhatsApp Hub</b>
                                <span><?= sanitize($classLabel) ?></span>
                            </div>
                            <div class="wa-batch-wrap">
                                <button type="button" class="wa-icon-btn" id="batchMenuBtn" title="Batch actions" aria-label="Batch actions" aria-expanded="false">
                                    <i class="fas fa-ellipsis-vertical"></i>
                                </button>
                                <div class="wa-menu" id="batchMenu" hidden>
                                    <div class="wa-menu-head">Dispatch · entire class</div>
                                    <button type="button" class="wa-menu-item" id="startSeqBtn"><i class="fas fa-play"></i> Start Sequential Dispatch</button>
                                    <div class="wa-menu-sep"></div>
                                    <button type="button" class="wa-menu-item" id="downloadBatchZipBtn"><i class="fas fa-file-zipper"></i> Download All (ZIP)</button>
                                    <button type="button" class="wa-menu-item" id="downloadBatchPdfBtn"><i class="fas fa-file-pdf"></i> Download Class PDF</button>
                                    <a class="wa-menu-item" href="export_pdf_batch.php?class=<?= e_url($classKey) ?>&doc_type=<?= e_url($docType) ?>&test_type=<?= e_url($testType) ?>" download><i class="fas fa-file-archive"></i> Offline ZIP</a>
                                    <div class="wa-menu-sep"></div>
                                    <button type="button" class="wa-menu-item" id="copyAllPhonesBtn"><i class="fas fa-copy"></i> Copy All Phones</button>
                                    <button type="button" class="wa-menu-item is-danger" id="resetSentStatusBtn"><i class="fas fa-rotate-left"></i> Reset Status for Class</button>
                                </div>
                            </div>
                        </div>

                        <form method="GET" action="whatsapp_share.php" id="hubFilterForm" class="wa-sidebar-form">
                            <div class="wa-search">
                                <i class="fas fa-search"></i>
                                <input type="text" name="search" id="searchInput" placeholder="Search student or roll…" value="<?= e_attr($search) ?>">
                                <button type="submit" class="wa-search-submit" title="Apply search" aria-label="Apply search"><i class="fas fa-arrow-right"></i></button>
                            </div>
                            <div class="wa-sidebar-selects">
                                <select name="doc_type" id="docTypeSelect" class="wa-mini-select" onchange="this.form.submit()">
                                    <option value="report_card" <?= $docType==='report_card' ? 'selected' : '' ?>>Semester Report Card</option>
                                    <option value="unit_test" <?= $docType==='unit_test' ? 'selected' : '' ?>>Unit Test Progress Slip</option>
                                    <option value="bonafide" <?= $docType==='bonafide' ? 'selected' : '' ?>>Bonafide Certificate</option>
                                    <option value="announcement" <?= $docType==='announcement' ? 'selected' : '' ?>>Notice / Announcement</option>
                                </select>
                                <?php if ($docType === 'unit_test'): ?>
                                <select name="test_type" id="testTypeSelect" class="wa-mini-select" onchange="this.form.submit()">
                                    <?php foreach (['Unit Test 1', 'Unit Test 2', 'Unit Test 3', 'Unit Test 4'] as $tt): ?>
                                    <option value="<?= e_attr($tt) ?>" <?= $testType===$tt ? 'selected' : '' ?>><?= sanitize($tt) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <?php endif; ?>
                            </div>
                            <input type="hidden" name="class" value="<?= e_attr($classKey) ?>">
                        </form>

                        <div class="wa-note-row">
                            <i class="fas fa-bullhorn"></i>
                            <input type="text" id="customNoteInput" placeholder="Teacher note (optional) — appended to every message">
                        </div>

                        <div class="wa-filter-tabs" id="rosterFilterPills">
                            <button type="button" class="wa-pill is-active" data-filter="all"><i class="fas fa-users"></i> All <span class="wa-pill-badge" id="pillCountAll"><?= count($rosterStudents) ?></span></button>
                            <button type="button" class="wa-pill" data-filter="pending"><i class="fas fa-clock"></i> Pending <span class="wa-pill-badge" id="pillCountPending"><?= count($rosterStudents) ?></span></button>
                            <button type="button" class="wa-pill" data-filter="sent"><i class="fas fa-check-double"></i> Sent <span class="wa-pill-badge" id="pillCountSent">0</span></button>
                            <button type="button" class="wa-pill" data-filter="missing"><i class="fas fa-phone-slash"></i> No phone <span class="wa-pill-badge" id="pillCountMissing">0</span></button>
                        </div>

                        <div class="wa-cl-scroll">
                            <!-- GROUPS · CLASSES -->
                            <div class="wa-cl-section">Groups · Classes</div>
                            <div class="wa-cl-groups">
                            <?php if (empty($availableClasses)): ?>
                                <div class="wa-cl-empty">No classes found.</div>
                            <?php else: ?>
                                <?php foreach ($availableClasses as $ac):
                                    $ck = rcBuildClassKey($ac['academic_year'], $ac['std'], $ac['division'], $ac['semester']);
                                    $rStd = $romanMap[(string)$ac['std']] ?? $ac['std'];
                                    $groupHref = 'whatsapp_share.php?' . http_build_query([
                                        'class'     => $ck,
                                        'doc_type'  => $docType,
                                        'test_type' => $testType,
                                        'search'    => $search,
                                    ]);
                                ?>
                                <a class="wa-group-item<?= $ck === $classKey ? ' is-active' : '' ?>" href="<?= htmlspecialchars($groupHref) ?>">
                                    <div class="wa-group-avatar"><i class="fas fa-users"></i></div>
                                    <div class="wa-gi-body">
                                        <div class="wa-gi-name">STD <?= sanitize($rStd) ?> <?= sanitize($ac['division']) ?> · <?= sanitize($ac['semester']) ?></div>
                                        <div class="wa-gi-sub"><?= (int)$ac['students'] ?> students · <?= sanitize($ac['academic_year']) ?></div>
                                    </div>
                                    <div class="wa-gi-chev"><i class="fas fa-chevron-right"></i></div>
                                </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                            </div>

                            <!-- CHATS · STUDENTS -->
                            <div class="wa-cl-section">Chats · Students</div>
                            <?php if (empty($rosterStudents)): ?>
                                <div class="wa-cl-empty">
                                    <i class="fas fa-folder-open"></i>
                                    <p>No students found for this class.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($rosterStudents as $idx => $st):
                                    $nameParts = preg_split('/\s+/', trim((string)$st['name']));
                                    $initials = '';
                                    foreach (array_slice(array_filter($nameParts), 0, 2) as $np) {
                                        $initials .= mb_substr($np, 0, 1);
                                    }
                                    $initials = strtoupper($initials) ?: 'ST';
                                    $preview = 'Roll ' . $st['roll'];
                                    if (isset($st['pct']) && $st['pct'] !== '—' && $st['pct'] !== '') {
                                        $preview .= ' · ' . $st['pct'] . '%';
                                    } elseif (!empty($st['score_label'])) {
                                        $preview .= ' · ' . $st['score_label'];
                                    }
                                    if (!empty($st['grade']) && $st['grade'] !== '—') {
                                        $preview .= ' · ' . $st['grade'];
                                    }
                                    $curStatus = $st['dispatch_status'] ?? 'pending';
                                    $pillClass = 'wa-status-' . str_replace('_', '-', $curStatus);
                                    $pillLabel = '⚪ Pending';
                                    if ($curStatus === 'sent') $pillLabel = '🟢 Sent';
                                    elseif ($curStatus === 'opened') $pillLabel = '🟡 Opened';
                                    elseif ($curStatus === 'ready') $pillLabel = '🔵 Ready';
                                    elseif ($curStatus === 'skipped') $pillLabel = '⚪ Skipped';
                                    elseif ($curStatus === 'failed') $pillLabel = '🔴 Failed';
                                    elseif ($curStatus === 'no_phone') $pillLabel = '⚠️ No Phone';
                                ?>
                                <div class="wa-chat-item" data-idx="<?= $idx ?>" data-roll="<?= e_attr($st['roll']) ?>" data-name="<?= e_attr($st['name']) ?>" data-status="<?= e_attr($curStatus) ?>">
                                    <div class="wa-ci-avatar"><?= sanitize($initials) ?></div>
                                    <div class="wa-ci-body">
                                        <div class="wa-ci-top">
                                            <span class="wa-ci-name"><?= sanitize($st['name']) ?></span>
                                            <span class="wa-status-pill <?= $pillClass ?>" id="statusPill_<?= e_attr($st['roll']) ?>"><?= $pillLabel ?></span>
                                        </div>
                                        <div class="wa-ci-bottom">
                                            <span class="wa-ci-preview"><?= sanitize($preview) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <div class="wa-cl-footer">
                            <div><strong id="sentCountEl">0</strong> / <strong id="totalCountEl"><?= count($rosterStudents) ?></strong> sent</div>
                            <div><span id="pendingCountEl"><?= count($rosterStudents) ?></span> pending · <span id="progressPctEl">0%</span></div>
                        </div>
                    </aside>

                    <!-- ══════════════ RIGHT: WHATSAPP CHAT PANE ══════════════ -->
                    <section class="wa-chatpane">
                        <div class="wa-cp-header">
                            <button type="button" class="wa-icon-btn wa-back" id="chatBackBtn" title="Back to list" aria-label="Back to list">
                                <i class="fas fa-arrow-left"></i>
                            </button>
                            <div class="wa-cp-avatar" id="mockAvatar">ST</div>
                            <div class="wa-cp-meta">
                                <div class="wa-cp-name" id="mockStudentName">Select a student</div>
                                <div class="wa-cp-sub">
                                    <span id="mockStudentRoll">Roll —</span>
                                    <span class="wa-cp-dot">·</span>
                                    <span id="mockPhoneStatus"><i class="fa-brands fa-whatsapp"></i> Parent WhatsApp</span>
                                </div>
                            </div>
                            <div class="wa-cp-actions">
                                <button type="button" class="wa-btn wa-btn-ghost wa-btn-sm" id="openCompanionBtn" title="Launch official WhatsApp click-to-chat window with pre-filled message">
                                    <i class="fa-brands fa-whatsapp"></i> Open WhatsApp
                                </button>
                                <button type="button" class="wa-btn wa-btn-ghost wa-btn-sm" id="btnTemplatesHub" title="Choose a message template for this student">
                                    <i class="fas fa-copy"></i> Templates
                                </button>
                                <button type="button" class="wa-btn wa-btn-send wa-btn-sm" id="paneSendBtn" title="Confirm that this message was sent in WhatsApp">
                                    <i class="fas fa-check-double"></i> Mark as Sent
                                </button>
                            </div>
                        </div>

                        <!-- Conversation -->
                        <div class="wa-messages" id="waMessages">
                            <div class="wa-date-pill">TODAY</div>
                            <div class="wa-empty-pane" id="waEmptyPane">
                                <i class="fa-brands fa-whatsapp"></i>
                                <p>Select a student from the list to preview and send their message.</p>
                            </div>
                            <div id="waHubLiveThread" class="wa-hub-live-thread" style="display:none;"></div>
                            <div class="wa-phone-bubble is-editable" id="mockBubbleText" contenteditable="true" spellcheck="false" role="textbox" aria-multiline="true" aria-label="Editable WhatsApp message">
                                Select a student from the list to preview their personalized WhatsApp message here.
                            </div>

                            <div class="wa-bubble-edit-bar">
                                <span class="wa-bubble-edit-hint" id="bubbleEditHint"><i class="fas fa-pen"></i> Click to edit — changes are used when you send.</span>
                                <button type="button" class="wa-bubble-reset-btn" id="bubbleResetBtn" style="display:none;"><i class="fas fa-rotate-left"></i> Reset to template</button>
                            </div>
                        </div>

                        <!-- Attachment preview · pinned above the composer so it is
                             always visible. Shows the auto-generated official PDF for
                             this student, or a file the teacher attaches from disk. -->
                        <div class="wa-attach" id="waDragTile" title="Save this PDF, then attach it in WhatsApp Web">
                            <div class="wa-attach-ico"><i class="fas fa-file-pdf"></i></div>
                            <div class="wa-attach-body">
                                <div class="wa-attach-name" id="mockPdfFilename">Report_Card_A4.pdf</div>
                                <div class="wa-attach-sub" id="attachMeta">Official A4 PDF &bull; save it, then send in WhatsApp Web</div>
                            </div>
                            <div class="wa-attach-actions">
                                <button type="button" class="wa-attach-act" id="attachCustomBtn" title="Choose a PDF from your computer"><i class="fas fa-folder-open"></i></button>
                                <button type="button" class="wa-attach-act" id="mockDownloadPdfBtn" title="Save this PDF"><i class="fas fa-download"></i></button>
                                <button type="button" class="wa-attach-act is-danger" id="attachRemoveBtn" title="Remove attachment" hidden><i class="fas fa-xmark"></i></button>
                            </div>
                        </div>

                        <!-- Composer -->
                        <div class="wa-composer">
                            <div class="wa-steps">
                                <span class="wa-step"><span class="wa-step-num">1</span> Open WhatsApp &amp; PDF</span>
                                <i class="fas fa-chevron-right" style="font-size:.6rem;color:#b1b8bd"></i>
                                <span class="wa-step"><span class="wa-step-num">2</span> Attach PDF &amp; Send</span>
                                <i class="fas fa-chevron-right" style="font-size:.6rem;color:#b1b8bd"></i>
                                <span class="wa-step"><span class="wa-step-num">3</span> Confirm &amp; Advance</span>
                            </div>
                            <div class="wa-composer-phone">
                                <i class="fas fa-mobile-screen"></i>
                                <input type="tel" id="panePhoneInput" class="wa-pane-phone" placeholder="Parent mobile number (auto-saves)" maxlength="14" autocomplete="tel">
                            </div>
                            <div class="wa-composer-row">
                                <button type="button" class="wa-btn wa-btn-attach wa-btn-sm" id="attachPickBtn" title="Open the file explorer and attach a PDF">
                                    <i class="fas fa-paperclip"></i> Attach PDF
                                </button>
                                <button type="button" class="wa-btn wa-btn-ghost wa-btn-sm" id="mockCopyDirectBtn"><i class="fas fa-copy"></i> Copy Message</button>
                                <div style="flex:1"></div>
                                <button type="button" class="wa-btn wa-btn-prev wa-btn-sm" id="prevStudentBtn" title="Previous student (Alt + Left)"><i class="fas fa-arrow-left"></i> Prev</button>
                                <button type="button" class="wa-btn wa-btn-ghost wa-btn-sm" id="skipStudentBtn" title="Skip student"><i class="fas fa-forward"></i> Skip</button>
                                <button type="button" class="wa-btn wa-btn-send wa-btn-sm" id="sendAndNextBtn" title="Mark as Sent & advance to next student (Alt + Right or Alt + Enter)"><span>Mark Sent &amp; Next</span> <i class="fas fa-arrow-right"></i></button>
                            </div>
                            <input type="file" id="pdfAttachInput" accept="application/pdf,.pdf" hidden>
                        </div>
                    </section>
                </div>

                <!-- ══════════════ 2. WHATSAPP WEB REPLICA COMPONENT ══════════════ -->
                <div class="wa-app-replica-container" id="waAppContainer" style="<?= $viewMode === 'replica' ? '' : 'display:none;' ?>;margin-bottom:16px;">
                    <wa-app id="waAppReplica"></wa-app>
                </div>

                <!-- ══════════════ WHATSAPP LINK (QR) OVERLAY ══════════════ -->
                <button type="button" id="waLinkFab" style="display:none;position:fixed;right:24px;bottom:24px;z-index:12001;background:#00a884;color:#fff;border:none;border-radius:999px;padding:12px 18px;font-weight:700;font-size:0.85rem;cursor:pointer;box-shadow:0 8px 24px rgba(0,168,132,0.35);">
                    <i class="fa-brands fa-whatsapp"></i> Link WhatsApp
                </button>
                <div id="waLinkChip" style="display:none;position:fixed;right:24px;bottom:24px;z-index:12001;align-items:center;gap:10px;background:#fff;color:#0f172a;border:1px solid #bbf7d0;border-radius:999px;padding:8px 8px 8px 14px;box-shadow:0 8px 24px rgba(15,23,42,0.16);font-size:0.82rem;font-weight:700;">
                    <i class="fa-brands fa-whatsapp" style="color:#00a884;"></i>
                    <span id="waLinkChipText">WhatsApp linked</span>
                    <button type="button" id="waLogoutBtn" style="background:#b91c1c;color:#fff;border:none;border-radius:999px;padding:8px 12px;font-weight:700;font-size:0.78rem;cursor:pointer;">Log out</button>
                </div>

                <div id="waLinkOverlay" style="display:none;position:fixed;inset:0;background:rgba(15,23,42,0.72);z-index:12002;align-items:center;justify-content:center;padding:16px;">
                    <div style="background:#fff;border-radius:16px;padding:24px;max-width:400px;width:100%;text-align:center;box-shadow:0 24px 60px rgba(0,0,0,0.35);position:relative;">
                        <button type="button" id="waLinkClose" aria-label="Close" style="position:absolute;top:10px;right:12px;background:none;border:none;font-size:1.5rem;line-height:1;color:#94a3b8;cursor:pointer;">&times;</button>
                        <h3 style="margin:0 0 6px;font-size:1.1rem;color:#0f172a;">Link your WhatsApp</h3>
                        <div style="margin:0 0 14px;font-size:0.8rem;color:#64748b;line-height:1.45;">
                            <div><strong>Option 1 (WhatsApp Scanner):</strong> WhatsApp &rarr; Linked Devices &rarr; Link a Device</div>
                            <div style="margin-top:3px;"><strong>Option 2 (Phone Camera):</strong> Scan with Camera app &rarr; Tap link to open WhatsApp</div>
                        </div>
                        <div style="width:264px;height:264px;margin:0 auto;display:flex;align-items:center;justify-content:center;background:#ffffff;border:1px solid #cbd5e1;border-radius:12px;box-shadow:0 2px 8px rgba(0,0,0,0.05);padding:8px;box-sizing:border-box;">
                            <img id="waLinkQr" alt="WhatsApp QR code" style="width:100%;height:100%;object-fit:contain;image-rendering:pixelated;image-rendering:crisp-edges;display:none;">
                            <span id="waLinkSpin" style="color:#94a3b8;font-size:0.8rem;">Waiting for bridge QR&hellip;</span>
                        </div>
                        <div style="margin-top:8px;display:flex;align-items:center;justify-content:center;gap:8px;">
                            <button type="button" id="waRefreshQrBtn" style="background:#f1f5f9;border:1px solid #cbd5e1;border-radius:6px;padding:5px 12px;font-size:0.75rem;font-weight:600;color:#334155;cursor:pointer;display:inline-flex;align-items:center;gap:5px;">
                                <i class="fa-solid fa-arrows-rotate"></i> Refresh QR Code
                            </button>
                        </div>
                        <p id="waLinkStatus" style="margin:8px 0 0;font-size:0.78rem;color:#94a3b8;">Waiting for scan&hellip;</p>

                        <div style="margin-top:14px;border-top:1px solid #e2e8f0;padding-top:12px;">
                            <button type="button" id="waPairToggle" style="background:none;border:none;color:#0ea5e9;font-size:0.8rem;cursor:pointer;text-decoration:underline;">
                                No camera? Use phone number instead
                            </button>
                            <div id="waPairBox" style="display:none;margin-top:10px;text-align:left;">
                                <input id="waPairPhone" type="tel" inputmode="numeric" placeholder="WhatsApp number, e.g. XXXXXXXXXX or XXXXXXXXXXXX" style="width:100%;box-sizing:border-box;padding:9px 12px;border:1px solid #cbd5e1;border-radius:8px;font-size:0.85rem;">
                                <p style="margin:6px 0 0;font-size:0.72rem;color:#94a3b8;">Use the number registered on WhatsApp. Country code (+91) is detected automatically.</p>
                                <button type="button" id="waPairBtn" style="margin-top:8px;width:100%;background:#0b141a;color:#fff;border:none;border-radius:8px;padding:10px;font-weight:700;cursor:pointer;font-size:0.85rem;">
                                    Get 8-digit pairing code
                                </button>
                                <div id="waPairCode" style="display:none;margin-top:10px;font-size:1.7rem;font-weight:800;letter-spacing:5px;color:#00a884;background:#f0fdf4;border:1px solid #86efac;border-radius:10px;padding:10px;text-align:center;"></div>
                                <div id="waPairMsg" style="margin-top:6px;font-size:0.75rem;color:#64748b;"></div>
                            </div>
                        </div>
                    </div>
                </div>

                <script>
                (function () {
                    var overlay  = document.getElementById('waLinkOverlay');
                    var fab      = document.getElementById('waLinkFab');
                    var chip     = document.getElementById('waLinkChip');
                    var chipText = document.getElementById('waLinkChipText');
                    var img      = document.getElementById('waLinkQr');
                    var spin     = document.getElementById('waLinkSpin');
                    var statusEl = document.getElementById('waLinkStatus');
                    var closeBtn = document.getElementById('waLinkClose');
                    var dismissed = false;
                    var logoutPending = false;
                    function waCsrfToken() {
                        var m = document.querySelector('meta[name="csrf-token"]');
                        return m && m.content ? m.content : '';
                    }

                    document.addEventListener('hcc:whatsapp-logout', function () {
                        logoutPending = true;
                        chip.style.display = 'none';
                        dismissed = false;
                        img.style.display = 'none';
                        spin.style.display = 'inline';
                        spin.textContent = 'Waiting for bridge QR…';
                        statusEl.innerHTML = '<span style="color:#f59e0b;font-weight:600;">Logged out.</span> Waiting for a new QR…';
                        if (replicaSurfaceOpen()) {
                            overlay.style.display = 'none';
                            window.dispatchEvent(new CustomEvent('open-qr-modal'));
                            return;
                        }
                        overlay.style.display = 'flex';
                    });

                    function showQR() { dismissed = false; overlay.style.display = 'flex'; fab.style.display = 'none'; }
                    function hideQR() { dismissed = true; overlay.style.display = 'none'; fab.style.display = 'inline-flex'; }

                    closeBtn.addEventListener('click', hideQR);
                    fab.addEventListener('click', showQR);

                    overlay.addEventListener('click', function (e) {
                        if (e.target === overlay) hideQR();
                    });

                    var lastQr = '';
                    var ensureAt = 0;

                    function replicaSurfaceOpen() {
                        var box = document.getElementById('waAppContainer');
                        if (!box) return false;
                        if (document.body.classList.contains('wa-fullscreen-active')) return true;
                        return window.getComputedStyle(box).display !== 'none';
                    }

                    // Always ask the Mac bridge to stay up. The LaunchAgent restarts
                    // it; this page must not skip the request when the replica is open.
                    function keepBridgeOn() {
                        if (Date.now() - ensureAt < 20000) return;
                        ensureAt = Date.now();
                        fetch('api_whatsapp_session.php?action=ensure', {
                            method: 'POST',
                            headers: { 'X-CSRF-Token': waCsrfToken() },
                            cache: 'no-store'
                        }).catch(function () {});
                    }

                    async function tick() {
                        try {
                            var s = await fetch('api_whatsapp_session.php?action=status', { cache: 'no-store' }).then(function (r) { return r.json(); });
                            var sess = s && s.session ? s.session : null;
                            if (sess && sess.is_connected && !logoutPending) {
                                overlay.style.display = 'none';
                                fab.style.display = 'none';
                                chip.style.display = 'inline-flex';
                                var phone = sess.phone_number ? String(sess.phone_number) : '';
                                var domSuffix = sess.dom_health_status && sess.dom_health_status !== 'ok'
                                    ? (' · DOM: ' + String(sess.dom_health_status))
                                    : '';
                                chipText.textContent = (phone ? ('WhatsApp linked · ' + phone) : 'WhatsApp linked') + domSuffix;
                                return;
                            }
                            if (!sess || !sess.is_connected) logoutPending = false;
                            chip.style.display = 'none';

                            var q = await fetch('api_whatsapp_session.php?action=qr', { cache: 'no-store' }).then(function (r) { return r.json(); }).catch(function () { return null; });
                            var bridgeAlive = !!((s && (s.bridge_running || (sess && sess.bridge_running))) || (q && q.bridge_running));
                            var isSyncing = !!((s && s.is_syncing) || (sess && sess.is_syncing) || (q && q.is_syncing));
                            if (!bridgeAlive) keepBridgeOn();

                            // Web Replica has its own QR dialog. Showing this overlay
                            // at the same time puts two codes on the screen.
                            if (replicaSurfaceOpen()) {
                                overlay.style.display = 'none';
                                fab.style.display = 'none';
                                return;
                            }
                            if (!dismissed) overlay.style.display = 'flex';

                            if (q && q.success && q.qr_code) {
                                if (q.qr_code !== lastQr) {
                                    lastQr = q.qr_code;
                                    img.src = q.qr_code;
                                }
                                img.style.display = 'block';
                                spin.style.display = 'none';
                                statusEl.innerHTML = '<span style="color:#059669;font-weight:600;">● QR ready</span> — point phone camera or WhatsApp scanner';
                            } else {
                                img.style.display = 'none';
                                spin.style.display = 'inline';
                                if (!bridgeAlive) {
                                    spin.innerHTML = '<span style="color:#0284c7;font-size:0.85rem;"><i class="fa-solid fa-circle-notch fa-spin"></i> Starting bridge…</span>';
                                    statusEl.innerHTML = '<span style="color:#0284c7;font-weight:600;">Starting the WhatsApp bridge</span><br><span style="color:#64748b;font-size:0.75rem;">It starts on its own on this Mac. The QR will show here. You do not need to run a command.</span>';
                                } else if (isSyncing) {
                                    spin.innerHTML = '<span style="color:#059669;font-size:0.85rem;"><i class="fa-solid fa-circle-notch fa-spin"></i> Linking…</span>';
                                    statusEl.innerHTML = '<span style="color:#059669;font-weight:600;">QR scanned</span> — linking your phone. Keep WhatsApp open.';
                                } else {
                                    spin.innerHTML = '<span style="color:#0284c7;font-size:0.85rem;"><i class="fa-solid fa-circle-notch fa-spin"></i> Generating QR code&hellip;</span>';
                                    statusEl.innerHTML = '<span style="color:#0284c7;font-weight:600;">Starting WhatsApp Web</span> — generating a fresh QR code…';
                                }
                            }
                        } catch (e) { /* silent */ }
                    }

                    tick();
                    setInterval(tick, 3000);

                    // ── Manual QR Refresh button ──
                    var refreshBtn = document.getElementById('waRefreshQrBtn');
                    if (refreshBtn) {
                        refreshBtn.addEventListener('click', async function () {
                            refreshBtn.disabled = true;
                            refreshBtn.innerHTML = '<i class="fa-solid fa-circle-notch fa-spin"></i> Refreshing…';
                            img.style.display = 'none';
                            spin.style.display = 'inline';
                            spin.innerHTML = '<span style="color:#0284c7;font-size:0.85rem;"><i class="fa-solid fa-circle-notch fa-spin"></i> Generating fresh QR code…</span>';
                            try {
                                await fetch('api_whatsapp_session.php?action=refresh_qr', {
                                    method: 'POST',
                                    headers: { 'X-CSRF-Token': waCsrfToken() }
                                });
                                lastQr = '';
                                setTimeout(tick, 2000);
                            } catch (e) {}
                            setTimeout(function () {
                                refreshBtn.disabled = false;
                                refreshBtn.innerHTML = '<i class="fa-solid fa-arrows-rotate"></i> Refresh QR Code';
                            }, 3500);
                        });
                    }

                    // ── Phone-number pairing code (no QR / no camera) ──
                    var pairToggle = document.getElementById('waPairToggle');
                    var pairBox    = document.getElementById('waPairBox');
                    var pairPhone  = document.getElementById('waPairPhone');
                    var pairBtn    = document.getElementById('waPairBtn');
                    var pairCode   = document.getElementById('waPairCode');
                    var pairMsg    = document.getElementById('waPairMsg');

                    pairToggle.addEventListener('click', function () {
                        var open = pairBox.style.display === 'block';
                        pairBox.style.display = open ? 'none' : 'block';
                    });

                    pairBtn.addEventListener('click', async function () {
                        var phone = (pairPhone.value || '').replace(/\D/g, '');
                        if (phone.length < 8) {
                            pairMsg.textContent = 'Enter your WhatsApp number (10 digits for +91, or with country code).';
                            return;
                        }
                        pairMsg.textContent = 'Requesting code…';
                        pairCode.style.display = 'none';
                        pairBtn.disabled = true;

                        try {
                            var r = await fetch('api_whatsapp_pair.php?action=request', {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-Token': waCsrfToken()
                                },
                                body: JSON.stringify({ phone: phone })
                            }).then(function (x) { return x.json(); });

                            if (!r.success) {
                                pairMsg.textContent = r.error || 'Could not request a code. Is the bridge running?';
                                pairBtn.disabled = false;
                                return;
                            }

                            pairMsg.textContent = 'Generating code in the bridge… (up to 30s)';

                            var tries = 0;
                            var timer = setInterval(async function () {
                                tries++;
                                try {
                                    var c = await fetch('api_whatsapp_pair.php?action=get', { cache: 'no-store' }).then(function (x) { return x.json(); });
                                    if (c.success && c.code) {
                                        clearInterval(timer);
                                        pairCode.textContent = c.code;
                                        pairCode.style.display = 'block';
                                        pairMsg.innerHTML = 'On your phone: <strong>WhatsApp → Linked Devices → Link with phone number</strong>, then type this code.';
                                        pairBtn.disabled = false;
                                    } else if (!c.pending) {
                                        clearInterval(timer);
                                        pairMsg.textContent = c.error || 'Bridge could not generate a code.';
                                        pairBtn.disabled = false;
                                    } else if (tries > 20) {
                                        clearInterval(timer);
                                        pairMsg.textContent = 'Timed out. Check that the bridge is running, then try again.';
                                        pairBtn.disabled = false;
                                    }
                                } catch (e) {
                                    if (tries > 20) { clearInterval(timer); pairBtn.disabled = false; }
                                }
                            }, 2000);
                        } catch (e) {
                            pairMsg.textContent = 'Network error while requesting the code.';
                            pairBtn.disabled = false;
                        }
                    });
                })();
                </script>

            </div>
        </main>
    </div>

    <!-- SEQUENTIAL DISPATCH FLOATING BANNER -->
    <div class="wa-seq-bar is-hidden" id="seqBar">
        <div class="wa-seq-info">
            <span class="wa-seq-count" id="seqCountPill">1 / <?= count($rosterStudents) ?></span>
            <span>Current: <strong id="seqStudentName">—</strong></span>
            <span id="seqStatusPill" class="wa-status-pill wa-status-pending" style="font-size:0.65rem;padding:1px 6px;">⚪ Pending</span>
        </div>
        <div class="wa-seq-actions" style="display:flex;gap:8px;align-items:center;">
            <button type="button" class="btn-whatsapp" id="seqOpenBtn" style="padding:6px 14px;font-size:0.85rem;background:#25D366;color:#fff;">
                <i class="fa-brands fa-whatsapp"></i> 1. Open Chat &amp; PDF
            </button>
            <button type="button" class="btn-whatsapp" id="seqSendNextBtn" style="padding:6px 16px;font-size:0.85rem;background:#059669;color:#fff;">
                <i class="fas fa-check"></i> 2. Mark Sent &amp; Next <i class="fas fa-arrow-right"></i>
            </button>
            <button type="button" class="btn-outline-whatsapp" id="seqSkipBtn" style="padding:6px 12px;font-size:0.82rem;background:#475569;color:#fff;border-color:#64748b;">
                <i class="fas fa-forward"></i> Skip
            </button>
            <button type="button" class="btn-outline-whatsapp" id="seqStopBtn" style="padding:6px 12px;font-size:0.82rem;background:#334155;color:#fff;border-color:#475569;">
                <i class="fas fa-times"></i> Exit
            </button>
        </div>
    </div>

    <wa-template-library id="wa-template-library"></wa-template-library>
    <wa-template-library id="wa-template-library-hub"></wa-template-library>

    <script src="../assets/vendor/html2canvas@1.4.1/html2canvas.min.js"></script>
    <script src="../assets/vendor/jspdf@2.5.1/jspdf.umd.min.js"></script>
    <script src="../assets/vendor/jszip@3.10.1/jszip.min.js"></script>
    <script src="../assets/js/hcc-logo-base64.js?v=<?= filemtime(__DIR__ . '/../assets/js/hcc-logo-base64.js') ?>"></script>
    <script src="../assets/js/whatsapp-audio-notifications.js?v=<?= filemtime(__DIR__ . '/../assets/js/whatsapp-audio-notifications.js') ?>"></script>
    <script>window.HCC_LOGO_URL = <?= e_js(BASE_URL . 'assets/images/hcclogo.jpg') ?>;</script>
    <script src="../assets/js/whatsapp-store.js?v=<?= filemtime(__DIR__ . '/../assets/js/whatsapp-store.js') ?>"></script>
    <script src="../assets/js/whatsapp-components.js?v=<?= filemtime(__DIR__ . '/../assets/js/whatsapp-components.js') ?>"></script>
    <script src="../assets/js/whatsapp-components2.js?v=<?= filemtime(__DIR__ . '/../assets/js/whatsapp-components2.js') ?>"></script>
    <script src="../assets/js/whatsapp-templates.js?v=<?= filemtime(__DIR__ . '/../assets/js/whatsapp-templates.js') ?>"></script>
    <script src="../assets/js/hcc-pdf-engine.js?v=<?= filemtime(__DIR__ . '/../assets/js/hcc-pdf-engine.js') ?>"></script>
    <script src="../assets/js/whatsapp-share.js?v=<?= filemtime(__DIR__ . '/../assets/js/whatsapp-share.js') ?>"></script>
    <script src="../assets/js/whatsapp-hub.js?v=<?= filemtime(__DIR__ . '/../assets/js/whatsapp-hub.js') ?>"></script>
    <script type="application/json" id="waHubData">
        <?= json_encode([
            "students"      => $rosterStudents,
            "schoolName"    => $schoolName,
            "classKey"      => $classKey,
            "docType"       => $docType,
            "className"     => $classLabel,
            "semester"      => $semester,
            "testType"      => $testType,
            "academicYear"  => $year,
            "schoolTagline" => $schoolTagline ?: "Excellence in Academic & Moral Education",
            "principalName" => $settings["principal_name"] ?? "PRINCIPAL"
        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
    </script>
    <script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= (is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 0) ?>"></script>

    <script>
    document.addEventListener('DOMContentLoaded', async function () {
        const classKey = <?= json_encode($classKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const waHubEl = document.getElementById('waApp');
        const waContainerEl = document.getElementById('waAppContainer');
        const waAppReplicaEl = document.getElementById('waAppReplica');
        const btnViewHub = document.getElementById('btnViewHub');
        const btnViewReplica = document.getElementById('btnViewReplica');
        const viewModeInput = document.getElementById('viewModeInput');
        const headerFsBtn = document.getElementById('headerFullscreenBtn');
        const topFsBtn = document.getElementById('btnToggleFullscreen');
        const headerSyncBtn = document.getElementById('headerSyncRosterBtn');
        const topSyncBtn = document.getElementById('btnSyncClassRoster');

        // View Mode Switcher (Hub vs Replica)
        function setView(mode) {
            const isReplica = (mode === 'replica');
            if (waHubEl) waHubEl.style.display = isReplica ? 'none' : 'grid';
            if (waContainerEl) waContainerEl.style.display = isReplica ? 'block' : 'none';

            if (btnViewHub) {
                btnViewHub.classList.toggle('active', !isReplica);
                btnViewHub.style.background = isReplica ? 'transparent' : '#00a884';
                btnViewHub.style.color = isReplica ? '#475569' : '#ffffff';
                btnViewHub.style.boxShadow = isReplica ? 'none' : '0 1px 3px rgba(0,0,0,0.12)';
            }
            if (btnViewReplica) {
                btnViewReplica.classList.toggle('active', isReplica);
                btnViewReplica.style.background = isReplica ? '#00a884' : 'transparent';
                btnViewReplica.style.color = isReplica ? '#ffffff' : '#475569';
                btnViewReplica.style.boxShadow = isReplica ? '0 1px 3px rgba(0,0,0,0.12)' : 'none';
            }
            if (viewModeInput) viewModeInput.value = isReplica ? 'replica' : 'hub';
            if (isReplica) {
                var linkChip = document.getElementById('waLinkChip');
                var alreadyLinked = linkChip && linkChip.style.display === 'inline-flex';
                if (!alreadyLinked) window.dispatchEvent(new CustomEvent('open-qr-modal'));
            }

            // Update URL without reload for persistent reload / bookmarking
            try {
                const u = new URL(window.location.href);
                u.searchParams.set('view', isReplica ? 'replica' : 'hub');
                window.history.replaceState({}, '', u.toString());
            } catch (e) {}
        }

        if (btnViewHub) btnViewHub.addEventListener('click', () => setView('hub'));
        if (btnViewReplica) btnViewReplica.addEventListener('click', () => setView('replica'));
        document.addEventListener('hcc:open-replica-chat', (e) => {
            setView('replica');
            const cid = e && e.detail && e.detail.chatId;
            if (cid && window.WhatsAppStore) {
                window.WhatsAppStore.setActiveChat(cid);
            }
        });

        // Toggle Fullscreen WhatsApp
        function toggleFullscreen() {
            setView('replica');
            document.body.classList.toggle('wa-fullscreen-active');
            if (waAppReplicaEl && typeof waAppReplicaEl.toggleFullscreenMode === 'function') {
                waAppReplicaEl.toggleFullscreenMode();
            }
        }

        if (headerFsBtn) headerFsBtn.addEventListener('click', toggleFullscreen);
        if (topFsBtn) topFsBtn.addEventListener('click', toggleFullscreen);

        // Escape key exits fullscreen
        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && document.body.classList.contains('wa-fullscreen-active')) {
                toggleFullscreen();
            }
        });

        // Sync Roster Function
        async function doSyncRoster(btn) {
            if (btn) {
                btn.disabled = true;
                btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Syncing...';
            }
            try {
                if (window.WhatsAppStore) {
                    const res = await window.WhatsAppStore.syncRoster(classKey);
                    console.log('Roster sync succeeded:', res);
                }
            } catch (err) {
                console.error('Roster sync failed:', err);
            } finally {
                if (btn) {
                    btn.disabled = false;
                    btn.innerHTML = '<i class="fas fa-check"></i> Synced!';
                    setTimeout(() => {
                        btn.innerHTML = '<i class="fas fa-rotate"></i> Sync Roster';
                    }, 2200);
                }
            }
        }

        if (headerSyncBtn) headerSyncBtn.addEventListener('click', () => doSyncRoster(headerSyncBtn));
        if (topSyncBtn) topSyncBtn.addEventListener('click', () => doSyncRoster(topSyncBtn));

        // Auto Roster Synchronization: if store is empty on page load, trigger sync
        try {
            if (window.WhatsAppStore) {
                await window.WhatsAppStore.init();
                if (window.WhatsAppStore.chats && window.WhatsAppStore.chats.size === 0) {
                    console.info('WhatsApp Store is empty; automatically syncing roster for:', classKey);
                    await window.WhatsAppStore.syncRoster(classKey);
                }
            }
        } catch (initErr) {
            console.warn('Initial store check/sync notice:', initErr);
        }

    });
    </script>
</body>
</html>
