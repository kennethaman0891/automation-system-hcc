<?php
/* Public Parent View — Official Unit Test Progress Slip */
$cspNonce = bin2hex(random_bytes(16));
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$cspNonce}'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}
require_once __DIR__ . '/includes/functions.php';

$year     = trim($_GET['year'] ?? '');
$std      = trim($_GET['std'] ?? '');
$division = trim($_GET['division'] ?? '');
$testType = trim($_GET['test_type'] ?? '');
$roll     = trim($_GET['roll'] ?? '');
$token    = trim($_GET['token'] ?? '');

if ($year === '' || $std === '' || $division === '' || $testType === '' || $roll === '' || $token === '') {
    http_response_code(400);
    die('Invalid link. Required parameters are missing.');
}

if (!rcVerifyParentToken('slip', [$year, $std, $division, $testType, $roll], $token)) {
    http_response_code(403);
    die('Access denied: Invalid or expired security token. Please request a fresh link from the school.');
}

$matrixData = rcGetUnitTestClassData($year, $std, $division, $testType);
$students   = $matrixData['students'];
$subjects   = $matrixData['subjects'];
$totalMax   = $matrixData['total_max'];

$student = null;
foreach ($students as $stu) {
    if ((string)$stu['roll_no'] === (string)$roll) {
        $student = $stu;
        break;
    }
}

if (!$student) {
    http_response_code(404);
    die('Student progress slip not found for the given roll number.');
}

$settings = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church English Medium School, Solapur';
$schoolTagline = $settings['school_tagline'] ?? 'Excellence in Academic & Moral Education';
$schoolLogo = $settings['logo'] ?? 'assets/images/hcclogo.jpg';

$romanMap = ['1'=>'I','2'=>'II','3'=>'III','4'=>'IV','5'=>'V','6'=>'VI','7'=>'VII','8'=>'VIII','9'=>'IX','10'=>'X'];
$romanStd = $romanMap[(string)$std] ?? $std;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?= sanitize($testType) ?> Slip — <?= sanitize($student['student_name']) ?> (Roll <?= sanitize($student['roll_no']) ?>)</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/whatsapp-share.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, sans-serif; background: #f8fafc; color: #0f172a; margin: 0; padding: 16px; }
        .parent-toolbar { max-width: 540px; margin: 0 auto 16px; display: flex; gap: 10px; align-items: center; justify-content: space-between; }
        .school-seal-badge { display: inline-flex; align-items: center; gap: 8px; font-size: 0.82rem; font-weight: 700; color: #1e3a8a; background: #eff6ff; padding: 6px 12px; border-radius: 20px; border: 1px solid #bfdbfe; }
        .slip-box { max-width: 540px; margin: 0 auto; background: #fff; border: 2px solid #475569; border-radius: 12px; padding: 20px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); }
        .slip-header { text-align: center; border-bottom: 2px solid #0f172a; padding-bottom: 10px; margin-bottom: 14px; }
        .slip-school-name { font-size: 1.05rem; font-weight: 900; text-transform: uppercase; color: #0f172a; }
        .slip-badge { font-size: 0.88rem; font-weight: 800; color: #991b1b; margin-top: 4px; }
        .slip-meta-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 8px; font-size: 0.86rem; margin-bottom: 14px; background: #f8fafc; padding: 10px 12px; border-radius: 6px; }
        .slip-meta-grid strong { color: #475569; font-size: 0.74rem; text-transform: uppercase; display: block; }
        .slip-meta-grid span { font-weight: 700; color: #0f172a; }
        .slip-table { width: 100%; border-collapse: collapse; font-size: 0.88rem; margin-bottom: 14px; }
        .slip-table th, .slip-table td { border: 1px solid #cbd5e1; padding: 8px 10px; text-align: center; }
        .slip-table th { background: #f1f5f9; font-weight: 800; color: #334155; font-size: 0.78rem; text-transform: uppercase; }
        .slip-table td:first-child { text-align: left; }
        .slip-table tr:nth-child(even) { background: #fcfcfc; }
        .slip-summary-bar { display: flex; justify-content: space-between; background: #f1f5f9; border: 1.5px solid #cbd5e1; padding: 10px 14px; border-radius: 8px; font-size: 0.88rem; font-weight: 800; margin-bottom: 16px; flex-wrap: wrap; gap: 8px; }
        .slip-sign-row { display: flex; justify-content: space-between; align-items: flex-end; margin-top: 24px; font-size: 0.82rem; font-weight: 700; color: #475569; padding-top: 12px; border-top: 1px dashed #cbd5e1; }
        @media print {
            .parent-toolbar, .no-print { display: none !important; }
            body { background: #fff; padding: 0; }
            .slip-box { border-width: 1.5px; box-shadow: none; max-width: 100%; }
        }
    </style>
</head>
<body>

    <div class="parent-toolbar no-print">
        <div class="school-seal-badge">
            <i class="fas fa-shield-halved" style="color:#16a34a;"></i> Cryptographically Verified Official Record
        </div>
        <button class="btn-whatsapp" id="printSlipBtn" type="button">
            <i class="fas fa-print"></i> Print / Save
        </button>
    </div>

    <div class="slip-box">
        <div class="slip-header">
            <img src="<?= e_attr(BASE_URL . safeLogoSrc($schoolLogo)) ?>" alt="School Logo" style="height:44px;width:auto;object-fit:contain;margin-bottom:6px;">
            <div class="slip-school-name"><?= sanitize($schoolName) ?></div>
            <?php if ($schoolTagline !== ''): ?>
                <div style="font-size:0.75rem;color:#475569;margin-top:2px;"><?= sanitize($schoolTagline) ?></div>
            <?php endif; ?>
            <div class="slip-badge"><?= strtoupper(sanitize($testType)) ?> PROGRESS SLIP (<?= sanitize($year) ?>)</div>
        </div>

        <div class="slip-meta-grid">
            <div><strong>Student Name</strong><span><?= sanitize($student['student_name']) ?></span></div>
            <div><strong>Roll No.</strong><span><?= sanitize($student['roll_no']) ?></span></div>
            <div><strong>Class</strong><span>STD <?= sanitize($romanStd) ?>-<?= sanitize($division) ?></span></div>
            <div><strong>Academic Year</strong><span><?= sanitize($year) ?></span></div>
        </div>

        <table class="slip-table">
            <thead>
                <tr>
                    <th style="text-align:left;">Subject</th>
                    <th style="width:70px;">Max</th>
                    <th style="width:70px;">Marks Obt.</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($subjects as $s): 
                    $sName = $s['subject_name'];
                    $sm = $student['subjects'][$sName] ?? null;
                    $val = '—';
                    if ($sm) {
                        if ($sm['is_absent']) $val = 'Absent';
                        elseif ($sm['marks_obtained'] !== null) $val = (string)$sm['marks_obtained'];
                    }
                ?>
                <tr>
                    <td style="text-align:left;font-weight:600;"><?= sanitize($sName) ?></td>
                    <td><?= (float)$s['max_marks'] ?></td>
                    <td style="font-weight:700;color:<?= $val==='Absent' ? '#dc2626' : '#0f172a' ?>;"><?= sanitize($val) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr style="font-weight:900;background:#f8fafc;">
                    <td style="text-align:left;">Total</td>
                    <td><?= (float)$totalMax ?></td>
                    <td style="color:#0f766e;"><?= $student['total_obtained'] ?></td>
                </tr>
            </tbody>
        </table>

        <div class="slip-summary-bar">
            <span>Percentage: <?= $student['percentage'] !== null ? number_format($student['percentage'], 2).'%' : '—' ?></span>
            <span>Grade: <?= sanitize($student['grade']) ?></span>
            <span>Remark: <?= sanitize($student['remark']) ?></span>
        </div>

        <div class="slip-sign-row">
            <div>Class Teacher</div>
            <div>Parent's Signature</div>
        </div>
        <div style="margin-top:12px;padding-top:8px;border-top:1px solid #e2e8f0;font-size:0.72rem;color:#64748b;display:flex;justify-content:space-between;flex-wrap:wrap;gap:6px;">
            <span><i class="fas fa-shield-halved" style="color:#16a34a;"></i> Cryptographically Verified Official Record</span>
            <span>Token: <code><?= sanitize(substr($token, 0, 10)) ?>&hellip;</code></span>
        </div>
    </div>

    <script nonce="<?= $cspNonce ?>">
    (function () {
        var btn = document.getElementById('printSlipBtn');
        if (btn) btn.addEventListener('click', function () { window.print(); });
    })();
    </script>
</body>
</html>
