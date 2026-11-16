<?php
/**
 * HCC Automation System — Server-Side Class Batch Archive Exporter
 * Generates an offline ZIP archive containing standalone, self-contained,
 * print-ready A4 documents for every student in a class.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

$settings     = getAllSettings();
$schoolName   = $settings['school_name'] ?? 'Hindustani Covenant Church High School';
$schoolTagline= trim((string)($settings['school_tagline'] ?? ''));
if ($schoolTagline === '') {
    $schoolTagline = 'Excellence in Academic & Moral Education';
}
$logoBase64   = getSchoolLogoBase64();
if ($logoBase64 === '') {
    $logoJsPath = __DIR__ . '/../assets/js/hcc-logo-base64.js';
    if (is_file($logoJsPath)) {
        $jsContent = @file_get_contents($logoJsPath);
        if ($jsContent && preg_match('/"((?:data:image\/[a-zA-Z0-9+.-]+;base64,)[A-Za-z0-9+\/=]+)"/', $jsContent, $lm)) {
            $logoBase64 = $lm[1];
        }
    }
}

$activeYear = rcGetActiveAcademicYear();
$classKey = trim($_GET['class'] ?? '');
$docType  = trim($_GET['doc_type'] ?? 'report_card');
$testType = trim($_GET['test_type'] ?? 'Unit Test 2');

if ($classKey === '') {
    die('Error: Class key parameter is required.');
}

$c = rcParseClassKey(urldecode($classKey));
$year = $c['academic_year'] ?: $activeYear;
$std = $c['std'] ?: '1';
$division = $c['division'] ?: 'A';
$semester = $c['semester'] ?: 'Sem 1';

$romanMap = ['1'=>'I','2'=>'II','3'=>'III','4'=>'IV','5'=>'V','6'=>'VI','7'=>'VII','8'=>'VIII','9'=>'IX','10'=>'X'];
$romanStd = $romanMap[(string)$std] ?? $std;
$classLabel = "STD_{$romanStd}_{$division}";

if (!class_exists('ZipArchive')) {
    die('Error: PHP ZipArchive extension is not enabled on this server.');
}

$zip = new ZipArchive();
$tempZipPath = tempnam(sys_get_temp_dir(), 'hcc_zip_');
if ($zip->open($tempZipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    die('Error: Could not create temporary ZIP archive.');
}

if ($docType === 'unit_test') {
    rcEnsureUnitTestTables();
    $utData = rcGetUnitTestClassData($year, $std, $division, $testType);
    $students = $utData['students'];
    $subjects = $utData['subjects'];
    $totalMax = $utData['total_max'];

    foreach ($students as $stu) {
        $rollStr = str_pad((string)$stu['roll_no'], 2, '0', STR_PAD_LEFT);
        $cleanName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $stu['student_name']);
        $html = renderServerUnitTestA4Html($stu, $subjects, $totalMax, $schoolName, $schoolTagline, $logoBase64, "STD {$romanStd} {$division}", $testType, $year);
        $zip->addFromString("Roll_{$rollStr}_{$cleanName}_UnitTest.html", $html);
    }
    $downloadName = "{$classLabel}_" . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $testType) . "_Archive.zip";

} elseif ($docType === 'bonafide') {
    rcEnsureArchiveTables();
    $db = getDB();
    $bfStmt = $db->prepare("SELECT * FROM bonafide_issued_log WHERE academic_year = ? ORDER BY id DESC");
    $bfStmt->execute([$year]);
    $rawBonafides = $bfStmt->fetchAll(PDO::FETCH_ASSOC);

    $usedZipNames = [];
    if (empty($rawBonafides)) {
        $rawStudents = rcAllStudents(['class_key' => $classKey]);
        foreach ($rawStudents as $stu) {
            $grNo = $settings['gr_no'] ?? '3875';
            $certSr = str_pad((string)$stu['roll_no'], 2, '0', STR_PAD_LEFT);
            $cleanName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $stu['student_name']);
            $rowStd = trim((string)($stu['std'] ?? '')) ?: $std;
            $rowDiv = trim((string)($stu['division'] ?? '')) ?: $division;
            $rowRomanStd = $romanMap[$rowStd] ?? $rowStd;
            $rowStdDiv = $rowDiv !== '' ? "STD – {$rowRomanStd} – {$rowDiv}" : "STD – {$rowRomanStd}";
            $rowAy = trim((string)($stu['academic_year'] ?? '')) ?: $year;
            $html = renderServerBonafideA4Html($stu, $schoolName, $settings['principal_name'] ?? 'PRINCIPAL', $logoBase64, $rowStdDiv, $grNo, $certSr, $rowAy);
            $entryName = "Roll_{$certSr}_{$cleanName}_Bonafide.html";
            if (isset($usedZipNames[$entryName])) {
                $usedZipNames[$entryName]++;
                $entryName = "Roll_{$certSr}_{$cleanName}_" . $usedZipNames[$entryName] . "_Bonafide.html";
            } else {
                $usedZipNames[$entryName] = 1;
            }
            $zip->addFromString($entryName, $html);
        }
    } else {
        foreach ($rawBonafides as $bf) {
            $cleanName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $bf['student_name']);
            $certSr = str_pad((string)($bf['cert_serial'] ?: $bf['roll_no']), 2, '0', STR_PAD_LEFT);
            $rowStd = trim((string)($bf['std'] ?? ''));
            $rowDiv = trim((string)($bf['division'] ?? ''));
            $rowAy  = trim((string)($bf['academic_year'] ?? '')) ?: $year;
            if ($rowStd !== '' && stripos($rowStd, 'STD') === 0) {
                $rowStdDiv = ($rowDiv !== '' && stripos($rowStd, $rowDiv) === false) ? "{$rowStd} – {$rowDiv}" : $rowStd;
            } elseif ($rowStd !== '') {
                $rowRomanStd = $romanMap[$rowStd] ?? $rowStd;
                $rowStdDiv = $rowDiv !== '' ? "STD – {$rowRomanStd} – {$rowDiv}" : "STD – {$rowRomanStd}";
            } else {
                $rowStdDiv = "STD – {$romanStd} – {$division}";
            }
            $html = renderServerBonafideA4Html($bf, $schoolName, $settings['principal_name'] ?? 'PRINCIPAL', $logoBase64, $rowStdDiv, $bf['gr_no'], $bf['cert_serial'], $rowAy);
            $entryName = "Roll_{$certSr}_{$cleanName}_Bonafide.html";
            if (isset($usedZipNames[$entryName])) {
                $usedZipNames[$entryName]++;
                $entryName = "Roll_{$certSr}_{$cleanName}_" . $usedZipNames[$entryName] . "_Bonafide.html";
            } else {
                $usedZipNames[$entryName] = 1;
            }
            $zip->addFromString($entryName, $html);
        }
    }
    $downloadName = "{$classLabel}_Bonafide_Certificates_Archive.zip";

} else {
    // Semester Report Cards
    $db = getDB();
    $subStmt = $db->prepare("SELECT roll_no, subject_name, fa_max, fa_obtained, sa_max, sa_obtained FROM report_card WHERE academic_year=? AND std=? AND division=? AND semester=? ORDER BY roll_no, id");
    $subStmt->execute([$year, $std, $division, $semester]);
    $subjectsByRoll = [];
    while ($sr = $subStmt->fetch(PDO::FETCH_ASSOC)) {
        $subjectsByRoll[$sr['roll_no']][] = $sr;
    }

    $rawStudents = rcAllStudents(['class_key' => $classKey]);
    foreach ($rawStudents as $stu) {
        $rollStr = str_pad((string)$stu['roll_no'], 2, '0', STR_PAD_LEFT);
        $cleanName = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $stu['student_name']);
        $stuRows = $subjectsByRoll[$stu['roll_no']] ?? [];
        $html = renderServerReportCardA4Html($stu, $stuRows, $schoolName, $schoolTagline, $logoBase64, "STD {$romanStd} {$division} — {$semester}", $semester, $year);
        $zip->addFromString("Roll_{$rollStr}_{$cleanName}_ReportCard.html", $html);
    }
    $downloadName = "{$classLabel}_{$semester}_ReportCards_Archive.zip";
}

$zip->close();

$downloadName = preg_replace('/[^A-Za-z0-9_.-]/', '_', $downloadName);

if (!file_exists($tempZipPath) || filesize($tempZipPath) === 0) {
    die('Error: Generated ZIP archive is empty or invalid.');
}

// Auto-file batch archive into Google Drive workspace if teacher is connected
if (!empty($_SESSION['admin_id'])) {
    try {
        $db = getDB();
        $uStmt = $db->prepare("SELECT google_drive_folder_id, google_drive_subfolders, google_refresh_token, username FROM users WHERE id = ? LIMIT 1");
        $uStmt->execute([(int)$_SESSION['admin_id']]);
        $uData = $uStmt->fetch(PDO::FETCH_ASSOC);
        if (!empty($uData['google_drive_folder_id'])) {
            require_once __DIR__ . '/../includes/google_drive.php';
            $client = new HCCGoogleDrive();
            $accessToken = $_SESSION['google_access_token'] ?? null;
            if (!$accessToken && !empty($uData['google_refresh_token'])) {
                $accessToken = $client->refreshAccessToken($uData['google_refresh_token']);
                if ($accessToken) $_SESSION['google_access_token'] = $accessToken;
            }
            if ($accessToken) {
                $subfolders = json_decode($uData['google_drive_subfolders'] ?? '[]', true) ?: [];
                $targetFolderId = ($docType === 'unit_test') 
                    ? ($subfolders['unit_tests']['id'] ?? $uData['google_drive_folder_id'])
                    : ($subfolders['reports']['id'] ?? $uData['google_drive_folder_id']);
                
                $zipContent = @file_get_contents($tempZipPath);
                if ($zipContent !== false && strlen($zipContent) > 0) {
                    $client->uploadFile($accessToken, $targetFolderId, $downloadName, $zipContent, 'application/zip');
                    rcLogAudit('drive_batch_auto_filed', 'workspace', "Auto-filed batch archive {$downloadName} into Drive folder {$targetFolderId} for user {$uData['username']}");
                }
            }
        }
    } catch (Throwable $e) {
        error_log("HCC [Batch Export Drive] Auto-file error: " . $e->getMessage());
    }
}

// Stream ZIP file
header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $downloadName . '"');
header('Content-Length: ' . filesize($tempZipPath));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($tempZipPath);
@unlink($tempZipPath);
exit;

/**
 * Helper to render standalone A4 Report Card HTML
 * Exactly matches admin/view_report_card.php (.print-sheet)
 */
function renderServerReportCardA4Html($stu, $rows, $schoolName, $schoolTagline, $logoBase64, $className, $semester, $ay): string {
    $rowsHtml = '';
    $hasSa = false;
    foreach ($rows as $r) {
        if ((float)$r['sa_max'] > 0.0) {
            $hasSa = true;
            break;
        }
    }

    $i = 1;
    foreach ($rows as $r) {
        $faMax = (float)$r['fa_max'];
        $faObt = (float)$r['fa_obtained'];
        $saMax = (float)$r['sa_max'];
        $saObt = (float)$r['sa_obtained'];
        $subName = htmlspecialchars($r['subject_name'], ENT_QUOTES);

        if ($hasSa) {
            $tMax = $faMax + $saMax;
            $tObt = $faObt + $saObt;
            $pct = $tMax > 0 ? number_format(($tObt / $tMax) * 100, 2) : '0.00';
            $rowsHtml .= "<tr><td>{$i}</td><td><strong>{$subName}</strong></td><td>" . number_format($faMax, 1) . "</td><td>" . number_format($faObt, 1) . "</td><td>" . number_format($saMax, 1) . "</td><td>" . number_format($saObt, 1) . "</td><td><strong>" . number_format($tObt, 1) . " / " . number_format($tMax, 1) . "</strong></td><td>{$pct}%</td></tr>";
        } else {
            $tMax = $faMax + $saMax;
            $tObt = $faObt + $saObt;
            $pct = $tMax > 0 ? number_format(($tObt / $tMax) * 100, 2) : '0.00';
            $rowsHtml .= "<tr><td>{$i}</td><td><strong>{$subName}</strong></td><td>" . number_format($tMax, 1) . "</td><td><strong>" . number_format($tObt, 1) . "</strong></td><td>{$pct}%</td></tr>";
        }
        $i++;
    }

    $stuName = htmlspecialchars((string)($stu['student_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $roll = htmlspecialchars((string)($stu['roll_no'] ?? ''), ENT_QUOTES, 'UTF-8');
    $safeSchoolName = htmlspecialchars((string)$schoolName, ENT_QUOTES, 'UTF-8');
    $safeSchoolTagline = htmlspecialchars((string)(trim((string)$schoolTagline) !== '' ? $schoolTagline : 'Excellence in Academic & Moral Education'), ENT_QUOTES, 'UTF-8');
    $safeClassName = htmlspecialchars((string)$className, ENT_QUOTES, 'UTF-8');
    $safeAy = htmlspecialchars((string)$ay, ENT_QUOTES, 'UTF-8');
    $logoHtml = $logoBase64 !== '' ? '<img src="' . htmlspecialchars($logoBase64, ENT_QUOTES, 'UTF-8') . '" alt="Logo">' : '';
    $obtained = number_format((float)$stu['obtained'], 1);
    $maxTotal = number_format((float)$stu['maxtotal'], 1);
    $percentage = (float)$stu['percentage'];
    $pctTotal = number_format($percentage, 2);
    $grade = htmlspecialchars((string)($stu['grade'] ?? '—'), ENT_QUOTES, 'UTF-8');
    $gradeCls = preg_replace('/[^a-zA-Z0-9_-]/', '', $grade);
    $divisionLabel = htmlspecialchars(getGradeDivision($percentage, $stu['std'] ?? '1'), ENT_QUOTES, 'UTF-8');
    $isPassed = ((int)($stu['std'] ?? 1) >= 9 ? $percentage >= 35 : $percentage >= 33);
    $statusHtml = $isPassed ? '<span style="color:#16a34a;font-weight:700;">PASS</span>' : '<span style="color:#dc2626;font-weight:700;">FAIL</span>';
    $remark = htmlspecialchars(rcDisplayRemark($stu['remark'] ?? '', $percentage), ENT_QUOTES, 'UTF-8');
    $attendance = htmlspecialchars((string)($stu['attendance'] ?: '—'), ENT_QUOTES, 'UTF-8');
    $parentsSign = !empty($stu['parents_sign']) ? 'Confirmed' : 'Not Signed';
    $today = date('d-m-Y');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Report Card - {$stuName}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; background: #fff; color: #0f172a; margin: 0; padding: 20px; font-size: 12px; }
        .print-sheet { max-width: 820px; margin: 0 auto; background: #fff; border: 2px solid #1a237e; }
        .print-hdr { text-align: center; padding: 24px 24px 14px; position: relative; }
        .print-hdr::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #1a237e, #c9943e, #1a237e); }
        .print-hdr img { height: 64px; width: auto; object-fit: contain; margin-bottom: 6px; }
        .print-hdr h1 { font-family: 'Merriweather', Georgia, 'Times New Roman', serif; font-size: 1.35rem; font-weight: 900; color: #1a237e; margin: 0; }
        .print-hdr h2 { font-family: 'Merriweather', Georgia, 'Times New Roman', serif; font-size: 0.95rem; font-weight: 700; color: #1a237e; margin: 2px 0 0; letter-spacing: 1px; }
        .print-title { margin: 0; padding: 10px 24px; border-top: 2px solid #1a237e; border-bottom: 2px solid #1a237e; text-align: center; }
        .print-title h3 { margin: 0; font-size: 1rem; font-weight: 700; color: #dc2626; text-transform: uppercase; letter-spacing: 3px; }
        .print-meta { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; padding: 16px 24px; font-size: 0.85rem; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        .print-meta > div { display: flex; flex-direction: column; gap: 2px; }
        .print-meta strong { color: #64748b; font-weight: 600; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .print-meta span { font-weight: 700; color: #1a237e; word-break: break-word; }
        .print-body { padding: 16px 24px 20px; }
        table.print-table { width: 100%; border-collapse: collapse; border: 2px solid #1a237e; font-size: 0.82rem; }
        table.print-table thead th { background: #1a237e; color: #fff; padding: 8px 10px; text-align: center; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; border: 1px solid #1a237e; }
        table.print-table tbody td { padding: 7px 10px; border-bottom: 1px solid #e2e8f0; color: #334155; text-align: center; }
        table.print-table tbody td:first-child { text-align: left; }
        table.print-table tbody td:nth-child(2) { text-align: left; }
        table.print-table tbody tr:nth-child(even) { background: #f8fafc; }
        table.print-table tbody tr:last-child td { border-bottom: none; }
        .print-summary { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-top: 16px; padding: 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; }
        .print-summary > div { display: flex; flex-direction: column; gap: 4px; text-align: center; }
        .print-summary strong { color: #64748b; font-weight: 600; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .print-summary span { font-weight: 800; color: #1a237e; font-size: 1.05rem; }
        .grade-pill { display: inline-block; padding: 2px 12px; border-radius: 12px; font-weight: 800; font-size: 0.8rem; border: 1px solid #c7d2fe; background: #eef2ff; color: #1a237e; }
        .grade-A, .grade-A1, .grade-A2, .grade-Distinction { background: #dcfce7; border-color: #86efac; color: #166534; }
        .grade-B, .grade-B1, .grade-B2, .grade-Grade-I, .grade-GradeI { background: #dbeafe; border-color: #93c5fd; color: #1d4ed8; }
        .grade-C, .grade-C1, .grade-C2, .grade-Grade-II, .grade-GradeII { background: #fef3c7; border-color: #fde68a; color: #92400e; }
        .grade-D { background: #fed7aa; border-color: #fdba74; color: #9a3412; }
        .grade-Pass { background: #dcfce7; border-color: #86efac; color: #166534; }
        .grade-F, .grade-E1, .grade-E2, .grade-Fail { background: #fee2e2; border-color: #fecaca; color: #991b1b; }
        .print-details { margin-top: 20px; padding: 16px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; }
        .print-details h4 { margin: 0 0 12px; font-size: 0.88rem; font-weight: 700; color: #1a237e; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; }
        .details-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; font-size: 0.85rem; }
        .details-grid > div { display: flex; flex-direction: column; gap: 2px; }
        .details-grid strong { color: #64748b; font-weight: 600; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .details-grid span { font-weight: 600; color: #334155; }
        .print-foot { display: flex; justify-content: space-between; gap: 16px; padding: 26px 24px 20px; font-size: 0.82rem; color: #475569; }
        .print-foot .sig { text-align: center; flex: 1; }
        .print-foot .sig-line { width: 180px; border-bottom: 1.5px solid #94a3b8; height: 34px; margin: 0 auto 5px; }
        @media print {
            @page { size: A4 portrait; margin: 10mm; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            body { padding: 0; }
        }
    </style>
</head>
<body>
    <div class="print-sheet">
        <div class="print-hdr">
            {$logoHtml}
            <h1>{$safeSchoolName}</h1>
            <h2>{$safeSchoolTagline}</h2>
        </div>
        <div class="print-title"><h3>Report Card</h3></div>
        
        <div class="print-meta">
            <div><strong>Student Name</strong><span>{$stuName}</span></div>
            <div><strong>Roll No.</strong><span>{$roll}</span></div>
            <div><strong>Class</strong><span>{$safeClassName}</span></div>
            <div><strong>Academic Year</strong><span>{$safeAy}</span></div>
        </div>

        <div class="print-body">
            <table class="print-table">
                <thead>
                    <tr>
HTML;

    if ($hasSa) {
        $outHtml .= <<<HTML
                        <th style="width:6%;">#</th>
                        <th style="width:28%;">Subject</th>
                        <th style="width:12%;">FA Max</th>
                        <th style="width:12%;">FA Obtained</th>
                        <th style="width:12%;">SA Max</th>
                        <th style="width:12%;">SA Obtained</th>
                        <th style="width:14%;">Total</th>
                        <th style="width:14%;">%</th>
HTML;
    } else {
        $outHtml .= <<<HTML
                        <th style="width:8%;">#</th>
                        <th style="width:44%;">Subject</th>
                        <th style="width:16%;">Max Marks</th>
                        <th style="width:18%;">Marks Obtained</th>
                        <th style="width:14%;">%</th>
HTML;
    }

    $outHtml .= <<<HTML
                    </tr>
                </thead>
                <tbody>{$rowsHtml}</tbody>
            </table>

            <div class="print-summary">
                <div><strong>Grand Total</strong><span>{$obtained} / {$maxTotal}</span></div>
                <div><strong>Percentage</strong><span>{$pctTotal}%</span></div>
                <div><strong>Grade</strong><span><span class="grade-pill grade-{$gradeCls}">{$grade}</span></span></div>
                <div><strong>Division</strong><span>{$divisionLabel}</span></div>
                <div><strong>Status</strong><span>{$statusHtml}</span></div>
            </div>

            <div class="print-details">
                <h4>Additional Information</h4>
                <div class="details-grid">
                    <div><strong>Remark</strong><span>{$remark}</span></div>
                    <div><strong>Attendance</strong><span>{$attendance}</span></div>
                    <div><strong>Parent Signature</strong><span>{$parentsSign}</span></div>
                    <div><strong>Date Issued</strong><span>{$today}</span></div>
                    <div><strong>Class Teacher</strong><span></span></div>
                    <div><strong>Principal</strong><span></span></div>
                </div>
            </div>
        </div>

        <div class="print-foot">
            <div class="sig"><div class="sig-line"></div>Class Teacher</div>
            <div class="sig"><div class="sig-line"></div>Coordinator</div>
            <div class="sig"><div class="sig-line"></div>Principal</div>
        </div>
    </div>
</body>
</html>
HTML;

    return $outHtml;
}

/**
 * Helper to render standalone A4 Unit Test HTML
 * Exactly matches admin/unit_test_report_card.php (.print-sheet)
 */
function renderServerUnitTestA4Html($stu, $subjects, $totalMax, $schoolName, $schoolTagline, $logoBase64, $className, $testType, $ay): string {
    $rowsHtml = '';
    $i = 1;
    foreach ($subjects as $s) {
        $sName = htmlspecialchars($s['subject_name'], ENT_QUOTES);
        $max = (float)$s['max_marks'];
        $sm = $stu['subjects'][$s['subject_name']] ?? null;
        $isAb = !empty($sm['is_absent']);
        $obtM = (!$isAb && isset($sm['marks_obtained']) && $sm['marks_obtained'] !== null) ? (float)$sm['marks_obtained'] : null;
        $subjPct = (!$isAb && $obtM !== null && $max > 0) ? round(($obtM / $max) * 100, 2) : null;

        $obtDisplay = $isAb ? '<strong>–</strong>' : ($obtM !== null ? '<strong>' . number_format($obtM, 1) . '</strong>' : '–');
        $pctDisplay = $subjPct !== null ? $subjPct . '%' : '–';

        $rowsHtml .= "<tr><td>{$i}</td><td><strong>{$sName}</strong></td><td>" . number_format($max, 1) . "</td><td>{$obtDisplay}</td><td>{$pctDisplay}</td></tr>";
        $i++;
    }

    $stuName = htmlspecialchars((string)($stu['student_name'] ?? ''), ENT_QUOTES, 'UTF-8');
    $roll = htmlspecialchars((string)($stu['roll_no'] ?? ''), ENT_QUOTES, 'UTF-8');
    $safeSchoolName = htmlspecialchars((string)$schoolName, ENT_QUOTES, 'UTF-8');
    $safeSchoolTagline = htmlspecialchars((string)(trim((string)$schoolTagline) !== '' ? $schoolTagline : 'Excellence in Academic & Moral Education'), ENT_QUOTES, 'UTF-8');
    $safeClassName = htmlspecialchars((string)$className, ENT_QUOTES, 'UTF-8');
    $safeTestType = htmlspecialchars((string)$testType, ENT_QUOTES, 'UTF-8');
    $safeAy = htmlspecialchars((string)$ay, ENT_QUOTES, 'UTF-8');
    $logoHtml = $logoBase64 !== '' ? '<img src="' . htmlspecialchars($logoBase64, ENT_QUOTES, 'UTF-8') . '" alt="Logo">' : '';
    $totalObt = $stu['total_obtained'] !== null ? number_format((float)$stu['total_obtained'], 1) : '0.0';
    $totalMaxDisp = number_format((float)$totalMax, 1);
    $pct = $stu['percentage'] !== null ? number_format((float)$stu['percentage'], 2) : null;
    $grade = htmlspecialchars((string)($stu['grade'] ?: '–'), ENT_QUOTES, 'UTF-8');
    $isPass = ($stu['percentage'] !== null && (float)$stu['percentage'] >= 35);
    $statusHtml = $isPass ? '<span style="color:#16a34a;font-weight:700;">PASS</span>' : '<span style="color:#dc2626;font-weight:700;">FAIL</span>';
    $remark = htmlspecialchars((string)($stu['remark'] ?: '—'), ENT_QUOTES, 'UTF-8');
    $attendance = htmlspecialchars((string)($stu['attendance'] ?: '—'), ENT_QUOTES, 'UTF-8');
    $parentsSign = !empty($stu['parents_sign']) ? 'Confirmed' : 'Not Signed';
    $today = date('d-m-Y');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Unit Test Report Card - {$stuName}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; background: #fff; color: #0f172a; margin: 0; padding: 20px; font-size: 12px; }
        .print-sheet { max-width: 820px; margin: 0 auto; background: #fff; border: 2px solid #1a237e; }
        .print-hdr { text-align: center; padding: 24px 24px 14px; position: relative; }
        .print-hdr::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #1a237e, #c9943e, #1a237e); }
        .print-hdr img { height: 64px; width: auto; object-fit: contain; margin-bottom: 6px; }
        .print-hdr h1 { font-family: 'Merriweather', Georgia, 'Times New Roman', serif; font-size: 1.35rem; font-weight: 900; color: #1a237e; margin: 0; }
        .print-hdr h2 { font-family: 'Merriweather', Georgia, 'Times New Roman', serif; font-size: 0.95rem; font-weight: 700; color: #1a237e; margin: 2px 0 0; letter-spacing: 1px; }
        .print-title { margin: 0; padding: 10px 24px; border-top: 2px solid #1a237e; border-bottom: 2px solid #1a237e; text-align: center; }
        .print-title h3 { margin: 0; font-size: 1rem; font-weight: 700; color: #dc2626; text-transform: uppercase; letter-spacing: 3px; }
        .print-meta { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; padding: 16px 24px; font-size: 0.85rem; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        .print-meta > div { display: flex; flex-direction: column; gap: 2px; }
        .print-meta strong { color: #64748b; font-weight: 600; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .print-meta span { font-weight: 700; color: #1a237e; word-break: break-word; }
        .print-body { padding: 16px 24px 20px; }
        table.print-table { width: 100%; border-collapse: collapse; border: 2px solid #1a237e; font-size: 0.82rem; }
        table.print-table thead th { background: #1a237e; color: #fff; padding: 8px 10px; text-align: center; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; border: 1px solid #1a237e; }
        table.print-table tbody td { padding: 7px 10px; border-bottom: 1px solid #e2e8f0; color: #334155; text-align: center; }
        table.print-table tbody td:nth-child(2) { text-align: left; }
        table.print-table tbody tr:nth-child(even) { background: #f8fafc; }
        table.print-table tbody tr:last-child td { border-bottom: none; }
        .print-summary { display: grid; grid-template-columns: repeat(5, 1fr); gap: 12px; margin-top: 16px; padding: 16px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; }
        .print-summary > div { display: flex; flex-direction: column; gap: 4px; text-align: center; }
        .print-summary strong { color: #64748b; font-weight: 600; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .print-summary span { font-weight: 800; color: #1a237e; font-size: 1.05rem; }
        .print-details { margin-top: 20px; padding: 16px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; }
        .print-details h4 { margin: 0 0 12px; font-size: 0.88rem; font-weight: 700; color: #1a237e; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e2e8f0; padding-bottom: 8px; }
        .details-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; font-size: 0.85rem; }
        .details-grid > div { display: flex; flex-direction: column; gap: 2px; }
        .details-grid strong { color: #64748b; font-weight: 600; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .details-grid span { font-weight: 600; color: #334155; }
        .print-foot { display: flex; justify-content: space-between; gap: 16px; padding: 26px 24px 20px; font-size: 0.82rem; color: #475569; }
        .print-foot .sig { text-align: center; flex: 1; }
        .print-foot .sig-line { width: 180px; border-bottom: 1.5px solid #94a3b8; height: 34px; margin: 0 auto 5px; }
        @media print {
            @page { size: A4 portrait; margin: 10mm; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            body { padding: 0; }
        }
    </style>
</head>
<body>
    <div class="print-sheet">
        <div class="print-hdr">
            {$logoHtml}
            <h1>{$safeSchoolName}</h1>
            <h2>{$safeSchoolTagline}</h2>
        </div>
        <div class="print-title"><h3>Unit Test Report Card</h3></div>

        <div class="print-meta">
            <div><strong>Student Name</strong><span>{$stuName}</span></div>
            <div><strong>Roll No.</strong><span>{$roll}</span></div>
            <div><strong>Class</strong><span>{$safeClassName}</span></div>
            <div><strong>Exam</strong><span>{$safeTestType} ({$safeAy})</span></div>
        </div>

        <div class="print-body">
            <table class="print-table">
                <thead>
                    <tr>
                        <th style="width:8%;">#</th>
                        <th style="width:40%;">Subject</th>
                        <th style="width:16%;">Max Marks</th>
                        <th style="width:18%;">Marks Obtained</th>
                        <th style="width:18%;">%</th>
                    </tr>
                </thead>
                <tbody>{$rowsHtml}</tbody>
            </table>

            <div class="print-summary">
                <div><strong>Grand Total</strong><span>{$totalObt} / {$totalMaxDisp}</span></div>
                <div><strong>Percentage</strong><span>" . ($pct !== null ? $pct . '%' : '–') . "</span></div>
                <div><strong>Grade</strong><span>{$grade}</span></div>
                <div><strong>Status</strong><span>{$statusHtml}</span></div>
                <div><strong>Attendance</strong><span>{$attendance}</span></div>
            </div>

            <div class="print-details">
                <h4>Additional Information</h4>
                <div class="details-grid">
                    <div><strong>Remark</strong><span>{$remark}</span></div>
                    <div><strong>Date Issued</strong><span>{$today}</span></div>
                    <div><strong>Parents Sign</strong><span>{$parentsSign}</span></div>
                </div>
            </div>
        </div>

        <div class="print-foot">
            <div class="sig"><div class="sig-line"></div>Teacher Signature</div>
            <div class="sig"><div class="sig-line"></div>Coordinator Signature</div>
            <div class="sig"><div class="sig-line"></div>Principal Signature</div>
        </div>
    </div>
</body>
</html>
HTML;
}

/**
 * Helper to render standalone A4 Bonafide Certificate HTML
 * Exactly matches admin/bonafide_certificate.php (.cert-sheet)
 */
function renderServerBonafideA4Html($stu, $schoolName, $principalName, $logoBase64, $stdDiv, $grNo, $certSerial, $ay): string {
    $rawDob = trim((string)($stu['date_of_birth'] ?? ''));
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $rawDob, $dm)) {
        $rawDob = "{$dm[3]}/{$dm[2]}/{$dm[1]}";
    }
    $stuName = htmlspecialchars((string)($stu['student_name'] ?? '____'), ENT_QUOTES, 'UTF-8');
    $safeSchoolName = htmlspecialchars((string)$schoolName, ENT_QUOTES, 'UTF-8');
    $stdDivStr = htmlspecialchars((string)$stdDiv, ENT_QUOTES, 'UTF-8');
    $dobDigits = htmlspecialchars($rawDob !== '' ? $rawDob : '____', ENT_QUOTES, 'UTF-8');
    $dobWords = htmlspecialchars($rawDob !== '' && function_exists('dobToWords') ? dobToWords($rawDob) : '____', ENT_QUOTES, 'UTF-8');
    $ayStr = htmlspecialchars((string)$ay, ENT_QUOTES, 'UTF-8');
    $caste = htmlspecialchars((string)($stu['caste'] ?? ($stu['caste_category'] ?? '_______')), ENT_QUOTES, 'UTF-8');
    $grNoStr = htmlspecialchars((string)$grNo, ENT_QUOTES, 'UTF-8');
    $certSerialStr = htmlspecialchars((string)$certSerial, ENT_QUOTES, 'UTF-8');
    $principalStr = htmlspecialchars((string)$principalName, ENT_QUOTES, 'UTF-8');
    $logoHtml = $logoBase64 !== '' ? '<img src="' . htmlspecialchars($logoBase64, ENT_QUOTES, 'UTF-8') . '" alt="Logo">' : '';
    $today = date('d/m/Y');

    return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Bonafide Certificate - {$stuName}</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Inter', -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Arial, sans-serif; background: #fff; color: #0f1e3d; margin: 0; padding: 20px; font-size: 13px; }
        .cert-sheet { max-width: 820px; min-height: 1000px; margin: 0 auto; background: #fff; border: 2px solid #1a237e; padding: 40px 48px; position: relative; display: flex; flex-direction: column; }
        .cert-top { display: flex; align-items: center; gap: 14px; margin-bottom: 12px; }
        .cert-school { display: flex; gap: 12px; align-items: center; text-align: left; flex: 1; }
        .cert-school img { height: 54px; width: 54px; object-fit: contain; border-radius: 10px; border: 1px solid #e5eaf1; background: #fff; padding: 4px; }
        .cert-school-text h1 { font-family: 'Merriweather', Georgia, 'Times New Roman', serif; font-size: 1.15rem; font-weight: 900; color: #1a237e; margin: 0; line-height: 1.35; }
        .cert-addr { font-size: 0.72rem; color: #475569; margin: 4px 0 0; font-weight: 600; }
        .cert-meta-table { width: 100%; border-collapse: collapse; font-size: 0.8rem; font-weight: 600; color: #334155; margin: 6px 0; }
        .cert-title { text-align: center; margin: 18px 0 24px; }
        .cert-title h2 { font-family: 'Merriweather', Georgia, 'Times New Roman', serif; font-size: 1.25rem; font-weight: 900; color: #b91c1c; letter-spacing: 0.2em; text-transform: uppercase; margin: 0; }
        .cert-title-bar { width: 72px; height: 3px; background: #c9a227; border-radius: 999px; margin: 9px auto 0; }
        .cert-body { flex: 1; font-size: 0.98rem; line-height: 2.1; text-align: justify; padding: 0 4px; }
        .cert-fill { text-decoration: underline; text-underline-offset: 3px; font-weight: 700; color: #0f172a; }
        .cert-foot { display: flex; justify-content: space-between; align-items: flex-end; margin-top: auto; padding-top: 30px; font-size: 0.85rem; }
        .cert-date { font-weight: 600; color: #334155; }
        .cert-photo { width: 112px; height: 136px; border: 1.5px dashed #94a3b8; border-radius: 6px; background: #f8fafc; display: flex; flex-direction: column; align-items: center; justify-content: center; color: #94a3b8; font-size: 0.68rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; gap: 6px; }
        .cert-principal { text-align: center; font-size: 0.85rem; font-weight: 800; color: #1a237e; }
        .cert-principal .sig-line { width: 190px; height: 32px; margin: 0 auto 6px; border-bottom: 1.5px solid #1a237e; }
        @media print {
            @page { size: A4 portrait; margin: 10mm; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            body { padding: 0; }
        }
    </style>
</head>
<body>
    <div class="cert-sheet">
        <div class="cert-top">
            <div class="cert-school">
                {$logoHtml}
                <div class="cert-school-text">
                    <h1>{$safeSchoolName}</h1>
                    <p class="cert-addr">166, Railway Lines, Near Rang Bhawan, Church Street, Solapur-413 001</p>
                </div>
            </div>
        </div>

        <table class="cert-meta-table">
            <tr>
                <td style="text-align:left;">G.R No:<span class="cert-fill" style="margin-left:6px;">{$grNoStr}</span></td>
                <td style="text-align:right;">Sr. No:<span class="cert-fill" style="margin-left:6px;">{$certSerialStr}</span></td>
            </tr>
        </table>

        <div class="cert-title">
            <h2>Bonafide Certificate</h2>
            <div class="cert-title-bar"></div>
        </div>

        <div class="cert-body">
            <p>
                This is to certify that <span class="cert-fill">{$stuName}</span>
                is a <strong>Bonafide Student</strong> of High School / Jr. College.
                He/She is studying in <span class="cert-fill">{$stdDivStr}</span>.
                His/Her date of birth according to the record is
                <span class="cert-fill">{$dobDigits}</span>.
                (In words: <span class="cert-fill">{$dobWords}</span>.)
                For <span class="cert-fill">{$ayStr}</span>.
                <br>Caste :- <span class="cert-fill">{$caste}</span>
            </p>
        </div>

        <div class="cert-foot">
            <div class="cert-date">
                Date : <span style="font-family:'Merriweather',Georgia,serif;font-size:0.95rem;font-weight:700;color:#1a237e;text-decoration:underline;text-underline-offset:3px;padding:0 6px;">{$today}</span>
            </div>
            <div class="cert-photo">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M23 19a2 2 0 0 1-2 2H3a2 2 0 0 1-2-2V8a2 2 0 0 1 2-2h4l2-3h6l2 3h4a2 2 0 0 1 2 2z"/><circle cx="12" cy="13" r="4"/></svg>Photo
            </div>
            <div class="cert-principal">
                <div class="sig-line"></div>
                <span>{$principalStr}</span>
            </div>
        </div>
    </div>
</body>
</html>
HTML;
}

