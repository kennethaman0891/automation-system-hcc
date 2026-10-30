<?php
/* Bulk Results — dedicated print/download view (handles huge data). */
// Security headers — must run before ANY output.
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

// Accept both individual parameters and composite classKey
$classKey = trim($_GET['class'] ?? '');
if ($classKey !== '' && $classKey !== 'all') {
    $c = rcParseClassKey(urldecode($classKey));
    $year     = trim($_GET['year'] ?? ($c['academic_year'] ?: '2024-25'));
    $std      = trim($_GET['std'] ?? ($c['std'] ?: '1'));
    $division = trim($_GET['division'] ?? ($c['division'] ?: 'A'));
    $semester = trim($_GET['semester'] ?? ($c['semester'] ?: 'Sem 1'));
} else {
    $year     = trim($_GET['year'] ?? '2024-25');
    $std      = trim($_GET['std'] ?? '1');
    $division = trim($_GET['division'] ?? 'A');
    $semester = trim($_GET['semester'] ?? 'Sem 1');
    $classKey = rcBuildClassKey($year, $std, $division, $semester);
}

$search   = trim($_GET['search'] ?? '');

// Parents Sign Mode: blank (default for physical signing), digital, or hide
$signMode = trim($_GET['sign_mode'] ?? '');
if ($signMode === '') {
    if (isset($_GET['show_sign']) && ($_GET['show_sign'] === '0' || $_GET['show_sign'] === 'false')) {
        $signMode = 'hide';
    } elseif (isset($_GET['digital_sign']) && ($_GET['digital_sign'] === '1' || $_GET['digital_sign'] === 'true')) {
        $signMode = 'digital';
    } else {
        $signMode = 'blank'; // Default: blank empty block where parents can actually sign
    }
}
$showSign = ($signMode !== 'hide');
$digitalSign = ($signMode === 'digital');

$settings = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church English Medium School, Solapur';

// Roman numerals
$romanMap = ['1'=>'I','2'=>'II','3'=>'III','4'=>'IV','5'=>'V','6'=>'VI','7'=>'VII','8'=>'VIII','9'=>'IX','10'=>'X'];
$romanStd = $romanMap[(string)$std] ?? $std;
$romanSem = romanSemLabel($semester);

$matrixSubjects = [];
$matrixMarks = [];
$classMaxTotal = 0.0;
$students = [];

try {
    $mx = rcResultsMatrix($classKey, $search);
    $matrixSubjects = $mx['subjects'] ?? [];
    $matrixMarks = $mx['marks'] ?? [];
    $students = $mx['students'] ?? [];
    if (!empty($matrixSubjects)) {
        foreach ($matrixSubjects as $ms) {
            $classMaxTotal += (float)$ms['fa_max'] + (float)$ms['sa_max'];
        }
    }
} catch (\Throwable $e) {}


// Compute total colspan
$totalCols = 2; // Roll + Name
foreach ($matrixSubjects as $ms) {
    $isSingleMark = ((float)$ms['sa_max'] <= 0.0);
    $totalCols += $isSingleMark ? 1 : 3;
}
$totalCols += 5; // Total + % + Grade + Remark + Attn
if ($showSign) {
    $totalCols += 1; // Parents Sign
}

$fileBase = preg_replace('/[^A-Za-z0-9_-]+/', '_', 'Semester_' . $romanSem . '_STD_' . $romanStd . '_' . $division . '_' . $year);
$today = date('d-m-Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Semester Marksheet — STD <?= sanitize($romanStd) ?> <?= sanitize($division) ?> (<?= sanitize($semester) ?>)</title>
    <link rel="icon" href="../assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="../assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/whatsapp-share.css">
    <link rel="stylesheet" href="../assets/css/print-toolbar.css">
    <style>
        * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body {
            font-family: Arial, "Helvetica Neue", Helvetica, sans-serif;
            margin: 0;
            padding: 15px;
            --ptb-pad: 15px;
            color: #000;
            background: #fff;
            font-size: 11pt;
        }
        @media print {
            body { padding: 0; margin: 0; }
            @page {
                size: landscape;
                margin: 8mm 6mm;
            }
        }
        /* Replica of School Spreadsheet */
        .marksheet-table {
            width: 100%;
            border-collapse: collapse;
            border: 2px solid #000;
            font-size: 9.5pt;
        }
        .marksheet-table th, .marksheet-table td {
            border: 1px solid #000;
            padding: 4px 6px;
            text-align: center;
            vertical-align: middle;
        }
        .th-school-title {
            font-size: 14pt;
            font-weight: 900;
            letter-spacing: 0.5px;
            padding: 8px 4px !important;
            text-transform: uppercase;
            border-bottom: 2px solid #000 !important;
        }
        .th-meta-bar {
            padding: 6px 12px !important;
            font-size: 10.5pt;
            font-weight: 800;
            border-bottom: 2px solid #000 !important;
        }
        .th-meta-container {
            display: flex;
            justify-content: space-around;
            align-items: center;
            width: 100%;
        }
        .th-col-header {
            font-weight: 800;
            font-size: 9pt;
            background: #f8fafc;
        }
        .th-sub-max {
            font-weight: 800;
            font-size: 9pt;
            background: #f1f5f9;
            border-bottom: 2px solid #000 !important;
        }
        .td-name {
            text-align: left !important;
            padding-left: 8px !important;
            font-weight: 700;
            white-space: nowrap;
        }
        .td-roll {
            font-weight: 800;
            width: 45px;
        }
        .td-category {
            background: #fff !important;
            font-weight: 900;
            text-align: left !important;
            padding-left: 10px !important;
            letter-spacing: 1px;
            font-size: 9pt;
            border-top: 1.5px solid #000 !important;
            border-bottom: 1.5px solid #000 !important;
        }
        .td-grade { font-weight: 800; }
        .td-tot { font-weight: 800; }
        .td-pct { font-weight: 800; }

        .sign-row {
            margin-top: 40px;
            display: flex;
            justify-content: space-between;
            padding: 0 40px;
            font-weight: 800;
            font-size: 10.5pt;
        }
        .sheet-date { text-align: right; font-weight: 800; font-size: 10.5pt; margin: 0 2px 8px 0; }
        .sheet-date #sheetDate { display: inline-block; min-width: 110px; text-align: center; border-bottom: 1.5px solid #000; padding: 0 10px; outline: none; }
        .sheet-date #sheetDate:focus { background: #fffbeb; }
        @media print {
            .sheet-date #sheetDate:focus { background: transparent; }
        }
        .col-parent-sign {
            min-width: 90px;
            height: 34px;
        }
        .sign-block {
            min-height: 28px;
            width: 100%;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
        }
    </style>
</head>
<body data-print-name="<?= sanitize($fileBase . '-' . date('Ymd')) ?>">

    <div class="ptb-wrap">
        <div class="ptb-bar">
            <a class="ptb-btn ptb-ghost" href="results.php?year=<?= urlencode($year) ?>&std=<?= urlencode($std) ?>&division=<?= urlencode($division) ?>&semester=<?= urlencode($semester) ?>&sign_mode=<?= urlencode($signMode) ?>"><i class="fas fa-arrow-left"></i> Back</a>
            <span class="ptb-title"><i class="fas fa-clipboard-list"></i> Semester Results Marksheet</span>
            <span class="ptb-spacer"></span>
            <button class="ptb-btn ptb-soft" type="button" id="toggleDigitalSignBtn" title="Toggle Digital Sign vs Blank Empty Blocks for Pen Signature">
                <i class="fas fa-signature"></i> Digital Sign: <span id="digitalSignStatus"><?= $digitalSign ? 'ON' : 'OFF' ?></span>
            </button>
            <button class="ptb-btn ptb-ghost" type="button" id="toggleSignBtn" title="Toggle Parents Sign column">
                <i class="fas fa-columns"></i> Column: <span id="signStatusText"><?= $showSign ? 'Shown' : 'Hidden' ?></span>
            </button>
            <button class="ptb-btn ptb-ghost" type="button" id="whatsappClassSummaryBtn" title="Share class summary on WhatsApp"><i class="fa-brands fa-whatsapp" style="color:#25D366;"></i> Share</button>
            <button class="ptb-btn ptb-soft" type="button" data-ptb="viewall" aria-pressed="false" title="Fit the full table to the screen"><i class="fas fa-expand"></i> View All</button>
            <button class="ptb-btn ptb-dark" type="button" data-ptb="download" title="Save as PDF"><i class="fas fa-download"></i> Download</button>
            <button class="ptb-btn ptb-solid" type="button" data-ptb="print"><i class="fas fa-print"></i> Print</button>
        </div>
    </div>

    <div class="sheet-date"><span>Date :</span> <span id="sheetDate" contenteditable="true" spellcheck="false" title="Click to edit the date"><?= $today ?></span></div>

    <div class="ptb-scroll">
    <table class="marksheet-table" id="marksheetTable">
        <thead>
            <!-- Top Row: School Name -->
            <tr>
                <th colspan="<?= $totalCols ?>" class="th-school-title" id="thSchoolTitle">
                    <?= sanitize($schoolName) ?>
                </th>
            </tr>

            <!-- Row 2: Metadata Bar -->
            <tr>
                <th colspan="<?= $totalCols ?>" class="th-meta-bar" id="thMetaBar">
                    <div class="th-meta-container">
                        <span>STD:- <?= sanitize($romanStd) ?> <?= sanitize($division) ?></span>
                        <span>SEMESTER :- <?= sanitize($romanSem) ?></span>
                        <span>YEAR :- <?= sanitize($year) ?></span>
                    </div>
                </th>
            </tr>

            <!-- Row 3: Column Headers -->
            <tr>
                <th class="th-col-header" style="width:45px;">Roll<br>No</th>
                <th class="th-col-header" style="text-align:left;padding-left:8px;min-width:180px;">Name of the Students</th>
                <?php foreach ($matrixSubjects as $ms): 
                    $isSingleMark = ((float)$ms['sa_max'] <= 0.0);
                    $subCols = $isSingleMark ? 1 : 3;
                ?>
                    <th colspan="<?= $subCols ?>" class="th-col-header"><?= sanitize($ms['subject_name']) ?></th>
                <?php endforeach; ?>
                <th class="th-col-header" style="min-width:65px;">Total</th>
                <th class="th-col-header" style="min-width:55px;">%</th>
                <th class="th-col-header" style="min-width:50px;">Grade</th>
                <th class="th-col-header" style="min-width:90px;">Remark</th>
                <th class="th-col-header" style="width:50px;">Attn</th>
                <th class="th-col-header col-parent-sign" style="min-width:90px;<?= $showSign ? '' : 'display:none;' ?>">Parents Sign</th>
            </tr>

            <!-- Row 4: Max Marks Sub-header -->
            <tr>
                <th class="th-sub-max"></th>
                <th class="th-sub-max"></th>
                <?php foreach ($matrixSubjects as $ms): 
                    $isSingleMark = ((float)$ms['sa_max'] <= 0.0);
                ?>
                    <?php if ($isSingleMark): ?>
                        <th class="th-sub-max"><?= (float)$ms['fa_max'] ?></th>
                    <?php else: ?>
                        <th class="th-sub-max">F(<?= (float)$ms['fa_max'] ?>)</th>
                        <th class="th-sub-max">S(<?= (float)$ms['sa_max'] ?>)</th>
                        <th class="th-sub-max"><?= (float)($ms['fa_max'] + $ms['sa_max']) ?></th>
                    <?php endif; ?>
                <?php endforeach; ?>
                <th class="th-sub-max"><?= (float)$classMaxTotal ?></th>
                <th class="th-sub-max"></th>
                <th class="th-sub-max"></th>
                <th class="th-sub-max"></th>
                <th class="th-sub-max"></th>
                <th class="th-sub-max col-parent-sign" style="<?= $showSign ? '' : 'display:none;' ?>"></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($students)): ?>
            <tr>
                <td colspan="<?= $totalCols ?>" style="padding:40px;color:#94a3b8;font-weight:700;">No student records found for STD <?= sanitize($romanStd) ?> <?= sanitize($division) ?> (<?= sanitize($semester) ?>).</td>
            </tr>
            <?php else: ?>
            <?php foreach ($students as $stu): ?>

            <tr>
                <td class="td-roll"><?= sanitize($stu['roll_no']) ?></td>
                <td class="td-name"><?= sanitize($stu['student_name']) ?></td>

                <!-- Subject Marks -->
                <?php 
                $rowRoll = (string)$stu['roll_no'];
                foreach ($matrixSubjects as $ms): 
                    $isSingleMark = ((float)$ms['sa_max'] <= 0.0);
                    $subjKey = mb_strtolower($ms['subject_name']);
                    $mk = $matrixMarks[$rowRoll][$subjKey] ?? null;
                    $hasFa = ($mk !== null && $mk['fa_obtained'] !== null);
                    $hasSa = ($mk !== null && $mk['sa_obtained'] !== null);
                    $faV = $hasFa ? (float)$mk['fa_obtained'] : '';
                    $saV = $hasSa ? (float)$mk['sa_obtained'] : '';
                    $subTotal = ((float)($mk['fa_obtained'] ?? 0)) + ((float)($mk['sa_obtained'] ?? 0));
                    $hasAny = ($faV !== '' || $saV !== '');
                ?>
                    <?php if ($isSingleMark): ?>
                        <td><?= $faV !== '' ? $faV : '-' ?></td>
                    <?php else: ?>
                        <td><?= $faV !== '' ? $faV : '-' ?></td>
                        <td><?= $saV !== '' ? $saV : '-' ?></td>
                        <td class="td-tot"><?= $hasAny ? $subTotal : '-' ?></td>
                    <?php endif; ?>
                <?php endforeach; ?>

                <td class="td-tot"><?= number_format($stu['obtained'], 1) ?></td>
                <td class="td-pct"><?= $stu['percentage'] !== null ? number_format($stu['percentage'], 2) : '-' ?></td>
                <td class="td-grade"><?= sanitize($stu['grade']) ?></td>
                <td style="text-align:left;padding-left:6px;"><?= sanitize(rcDisplayRemark($stu['remark'] ?? '', $stu['percentage'] ?? 0) ?: '—') ?></td>
                <td><?= sanitize($stu['attendance'] ?: '—') ?></td>
                <td class="col-parent-sign" style="<?= $showSign ? '' : 'display:none;' ?>">
                    <div class="sign-block" data-signed="<?= !empty($stu['parents_sign']) ? '1' : '0' ?>">
                        <span class="digital-sign-content" style="<?= ($digitalSign && !empty($stu['parents_sign'])) ? '' : 'display:none;' ?>">
                            <span style="font-family:'Brush Script MT', 'Lucida Calligraphy', cursive, sans-serif; font-size:13pt; color:#0f172a; line-height:1.1;">Signed</span>
                            <small style="display:block; font-family:Arial, sans-serif; font-size:6.5pt; color:#64748b; font-weight:700; letter-spacing:0.5px; text-transform:uppercase;">Digital Sign</small>
                        </span>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
    </div>

    <div class="sign-row">
        <div>Teacher Signature</div>
        <div>Coordinator Signature</div>
        <div>Principal Signature</div>
    </div>

    <script src="../assets/js/print-toolbar.js"></script>
    <script src="<?= BASE_URL ?>assets/js/whatsapp-share.js?v=<?= filemtime(__DIR__ . '/../assets/js/whatsapp-share.js') ?>"></script>
    <script>
    // Toggle Digital Signature vs Blank Blocks for Physical Signing
    let isDigitalOn = <?= $digitalSign ? 'true' : 'false' ?>;
    document.getElementById('toggleDigitalSignBtn')?.addEventListener('click', function () {
        isDigitalOn = !isDigitalOn;
        document.getElementById('digitalSignStatus').textContent = isDigitalOn ? 'ON' : 'OFF';
        document.querySelectorAll('.sign-block').forEach(el => {
            const isSigned = el.getAttribute('data-signed') === '1';
            const content = el.querySelector('.digital-sign-content');
            if (content) {
                content.style.display = (isDigitalOn && isSigned) ? '' : 'none';
            }
        });
    });

    // Toggle Column Visibility
    document.getElementById('toggleSignBtn')?.addEventListener('click', function () {
        const cols = document.querySelectorAll('.col-parent-sign');
        const isHidden = cols.length > 0 && cols[0].style.display === 'none';
        cols.forEach(el => el.style.display = isHidden ? '' : 'none');
        document.getElementById('signStatusText').textContent = isHidden ? 'Shown' : 'Hidden';

        const delta = isHidden ? 1 : -1;
        const th1 = document.getElementById('thSchoolTitle');
        const th2 = document.getElementById('thMetaBar');
        if (th1) th1.colSpan = parseInt(th1.colSpan, 10) + delta;
        if (th2) th2.colSpan = parseInt(th2.colSpan, 10) + delta;
    });

    document.getElementById('whatsappClassSummaryBtn')?.addEventListener('click', function () {
        const students = <?= json_encode($students, JSON_UNESCAPED_UNICODE) ?>;
        const total = students.length;
        let pass = 0;
        let sumPct = 0;
        const sorted = [...students].sort((a,b) => (Number(b.percentage)||0) - (Number(a.percentage)||0));

        students.forEach(s => {
            if (s.passed) pass++;
            sumPct += (Number(s.percentage) || 0);
        });

        const fail = Math.max(0, total - pass);
        const passPct = total > 0 ? ((pass / total) * 100).toFixed(1) : 0;
        const avg = total > 0 ? (sumPct / total).toFixed(1) : 0;
        const topScorers = sorted.slice(0, 3).map(s => ({
            name: s.student_name,
            percentage: s.percentage,
            grade: s.grade || s.grade_letter || '—'
        }));

        const msg = HCCWhatsApp.templates.classSummary({
            schoolName: <?= json_encode($schoolName) ?>,
            principalName: <?= json_encode($settings['principal_name'] ?? 'The Principal') ?>,
            className: <?= json_encode("STD {$romanStd} {$division}") ?>,
            semester: <?= json_encode($semester) ?>,
            totalStudents: total,
            passed: pass,
            failed: fail,
            passPct: passPct,
            classAverage: avg,
            topScorers: topScorers
        });

        HCCWhatsApp.openModal({
            studentName: <?= json_encode("STD {$romanStd} {$division} Marksheet Summary") ?>,
            roll: 'ALL',
            className: <?= json_encode("STD {$romanStd} {$division}") ?>,
            classKey: <?= json_encode($classKey) ?>,
            messageText: msg
        });
    });
    </script>
</body>
</html>
