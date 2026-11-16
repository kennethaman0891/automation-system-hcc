<?php
/* Public Parent View — Official Student Report Card */
$cspNonce = bin2hex(random_bytes(16));
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$cspNonce}'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}
require_once __DIR__ . '/includes/functions.php';

$classKey = trim($_GET['class'] ?? '');
$roll     = trim($_GET['roll'] ?? '');
$token    = trim($_GET['token'] ?? '');

if ($classKey === '' || $roll === '' || $token === '') {
    http_response_code(400);
    die('Invalid link. Required parameters are missing.');
}

// Verify tamper-proof URL-safe HMAC signature (handling decoded or pre-encoded classKey)
$tokenValid = rcVerifyParentToken('card', [$classKey, $roll], $token);
if (!$tokenValid && strpos($classKey, '%') !== false) {
    $decodedKey = urldecode($classKey);
    if (rcVerifyParentToken('card', [$decodedKey, $roll], $token)) {
        $classKey = $decodedKey;
        $tokenValid = true;
    }
}
if (!$tokenValid) {
    http_response_code(403);
    die('Access denied: Invalid or expired security token. Please request a fresh link from the school.');
}

$report = rcStudentReport($classKey, $roll);
if (!$report) {
    http_response_code(404);
    die('Student report card record not found.');
}

$settings = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church High School';
$schoolTagline = $settings['school_tagline'] ?? 'Excellence in Academic & Moral Education';
$schoolLogo = $settings['logo'] ?? 'assets/images/hcclogo.jpg';

$c = rcParseClassKey(urldecode($classKey));
$clsLabel = 'STD ' . $c['std'] . ' ' . $c['division'] . ' — ' . $c['semester'];
$fileBase = preg_replace('/[^A-Za-z0-9_-]+/', '_', 'Report_Card_' . $report['name'] . '_' . $report['roll'] . '_' . $clsLabel);
$today = date('d-m-Y');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Report Card — <?= sanitize($report['name']) ?> (Roll <?= sanitize($report['roll']) ?>)</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/merriweather/merriweather.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/whatsapp-share.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, sans-serif; background: #f8fafc; color: #0f172a; margin: 0; padding: 16px; }
        .parent-toolbar { max-width: 860px; margin: 0 auto 16px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; justify-content: space-between; }
        .school-seal-badge { display: inline-flex; align-items: center; gap: 8px; font-size: 0.82rem; font-weight: 700; color: #1e3a8a; background: #eff6ff; padding: 6px 12px; border-radius: 20px; border: 1px solid #bfdbfe; }
        .parent-sheet { max-width: 860px; margin: 0 auto; background: #fff; border: 2px solid #1a237e; border-radius: 4px; box-shadow: 0 4px 20px rgba(0,0,0,0.06); }
        .print-hdr { text-align: center; padding: 22px 20px 14px; position: relative; }
        .print-hdr::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #1a237e, #c9943e, #1a237e); }
        .print-hdr img { height: 60px; width: auto; object-fit: contain; margin-bottom: 6px; }
        .print-hdr h1 { font-family: 'Merriweather', Georgia, serif; font-size: 1.3rem; font-weight: 900; color: #1a237e; margin: 0; }
        .print-hdr h2 { font-family: 'Merriweather', Georgia, serif; font-size: 0.9rem; font-weight: 700; color: #1a237e; margin: 2px 0 0; letter-spacing: 0.5px; }
        .print-title { margin: 0; padding: 8px 20px; border-top: 2px solid #1a237e; border-bottom: 2px solid #1a237e; background: #fafafa; }
        .print-title h3 { margin: 0; font-size: 0.95rem; font-weight: 800; color: #dc2626; text-transform: uppercase; letter-spacing: 2px; text-align: center; }
        .print-meta { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; padding: 14px 20px; font-size: 0.85rem; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        .print-meta > div { display: flex; flex-direction: column; gap: 2px; }
        .print-meta strong { color: #64748b; font-weight: 600; font-size: 0.72rem; text-transform: uppercase; }
        .print-meta span { font-weight: 700; color: #1a237e; }
        .print-body { padding: 16px 20px 20px; }
        table.print-table { width: 100%; border-collapse: collapse; border: 2px solid #1a237e; font-size: 0.82rem; }
        table.print-table thead th { background: #1a237e; color: #fff; padding: 8px 10px; text-align: center; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; }
        table.print-table tbody td { padding: 7px 10px; border-bottom: 1px solid #e2e8f0; color: #334155; text-align: center; }
        table.print-table tbody td:first-child { text-align: left; }
        table.print-table tbody td:nth-child(2) { text-align: left; }
        table.print-table tbody tr:nth-child(even) { background: #f8fafc; }
        .print-summary { display: grid; grid-template-columns: repeat(5, 1fr); gap: 10px; margin-top: 16px; padding: 14px; background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px; }
        .print-summary > div { display: flex; flex-direction: column; gap: 3px; text-align: center; }
        .print-summary strong { color: #64748b; font-weight: 600; font-size: 0.7rem; text-transform: uppercase; }
        .print-summary span { font-weight: 800; color: #1a237e; font-size: 1rem; }
        .grade-pill { display: inline-block; padding: 2px 10px; border-radius: 12px; font-weight: 800; font-size: 0.8rem; background: #e0e7ff; color: #3730a3; }
        .print-details { margin-top: 16px; padding: 14px; background: #fff; border: 1px solid #e2e8f0; border-radius: 8px; }
        .print-details h4 { margin: 0 0 10px; font-size: 0.82rem; font-weight: 700; color: #1a237e; text-transform: uppercase; letter-spacing: 0.05em; border-bottom: 1px solid #e2e8f0; padding-bottom: 6px; }
        .details-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; font-size: 0.82rem; }
        .details-grid > div { display: flex; flex-direction: column; gap: 2px; }
        .details-grid strong { color: #64748b; font-size: 0.7rem; text-transform: uppercase; }
        .print-foot { display: flex; justify-content: space-between; gap: 16px; padding: 24px 20px; font-size: 0.82rem; color: #475569; }
        .print-foot .sig { text-align: center; flex: 1; }
        .print-foot .sig-line { width: 140px; border-bottom: 1.5px solid #94a3b8; height: 30px; margin: 0 auto 5px; }
        @media (max-width: 640px) {
            body { padding: 8px; }
            .print-meta { grid-template-columns: 1fr 1fr; }
            .print-summary { grid-template-columns: 1fr 1fr; }
            .details-grid { grid-template-columns: 1fr; }
        }
        @media print {
            .parent-toolbar, .no-print { display: none !important; }
            body { background: #fff; padding: 0; }
            .parent-sheet { max-width: 100%; border-width: 2px; box-shadow: none; }
            @page { margin: 10mm; size: A4 portrait; }
        }
    </style>
</head>
<body data-print-name="<?= sanitize($fileBase . '-' . date('Ymd')) ?>">

    <div class="parent-toolbar no-print">
        <div class="school-seal-badge">
            <i class="fas fa-shield-halved" style="color:#16a34a;"></i> Cryptographically Verified Official Record
        </div>
        <div style="display:flex;gap:8px;">
            <button class="btn-whatsapp" id="printCardBtn" type="button">
                <i class="fas fa-print"></i> Print / Save PDF
            </button>
        </div>
    </div>

    <div class="parent-sheet">
        <div class="print-hdr">
            <img src="<?= e_attr(BASE_URL . safeLogoSrc($schoolLogo)) ?>" alt="School Logo">
            <h1><?= sanitize($schoolName) ?></h1>
            <?php if ($schoolTagline): ?><h2><?= sanitize($schoolTagline) ?></h2><?php endif; ?>
        </div>
        <div class="print-title"><h3>Official Student Report Card</h3></div>

        <div class="print-meta">
            <div><strong>Student Name</strong><span><?= sanitize($report['name']) ?></span></div>
            <div><strong>Roll No.</strong><span><?= sanitize($report['roll']) ?></span></div>
            <div><strong>Class</strong><span><?= sanitize($clsLabel) ?></span></div>
            <div><strong>Academic Year</strong><span><?= sanitize($c['academic_year']) ?></span></div>
        </div>

        <div class="print-body">
            <?php
                $hasSa = false;
                foreach ($report['rows'] as $r) {
                    if ((float)$r['sa_max'] > 0.0) { $hasSa = true; break; }
                }
            ?>
            <table class="print-table">
                <thead>
                    <?php if ($hasSa): ?>
                    <tr>
                        <th style="width:6%;">#</th>
                        <th style="width:34%;">Subject</th>
                        <th style="width:12%;">FA Max</th>
                        <th style="width:12%;">FA Obt.</th>
                        <th style="width:12%;">SA Max</th>
                        <th style="width:12%;">SA Obt.</th>
                        <th style="width:12%;">Total</th>
                    </tr>
                    <?php else: ?>
                    <tr>
                        <th style="width:8%;">#</th>
                        <th style="width:48%;">Subject</th>
                        <th style="width:22%;">Max Marks</th>
                        <th style="width:22%;">Marks Obtained</th>
                    </tr>
                    <?php endif; ?>
                </thead>
                <tbody>
                    <?php $idx = 1; foreach ($report['rows'] as $row): 
                        $faMax = (float)$row['fa_max'];
                        $faObt = (float)$row['fa_obtained'];
                        $saMax = (float)$row['sa_max'];
                        $saObt = (float)$row['sa_obtained'];
                        $subjTotal = $faMax + $saMax;
                        $subjObt = $faObt + $saObt;
                    ?>
                    <tr>
                        <td><?= $idx++ ?></td>
                        <td><strong><?= sanitize($row['subject_name']) ?></strong></td>
                        <?php if ($hasSa): ?>
                            <td><?= number_format($faMax, 1) ?></td>
                            <td><?= number_format($faObt, 1) ?></td>
                            <td><?= number_format($saMax, 1) ?></td>
                            <td><?= number_format($saObt, 1) ?></td>
                            <td><strong><?= number_format($subjObt, 1) ?> / <?= number_format($subjTotal, 1) ?></strong></td>
                        <?php else: ?>
                            <td><?= number_format($subjTotal, 1) ?></td>
                            <td><strong><?= number_format($subjObt, 1) ?></strong></td>
                        <?php endif; ?>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="print-summary">
                <div><strong>Grand Total</strong><span><?= number_format($report['obtained'], 1) ?> / <?= number_format($report['grandTotal'], 1) ?></span></div>
                <div><strong>Percentage</strong><span><?= e_attr($report['percentage']) ?>%</span></div>
                <div><strong>Grade</strong><span><span class="grade-pill"><?= e_attr($report['grade']) ?></span></span></div>
                <div><strong>Status</strong><span><?= $report['passed'] ? '<span style="color:#16a34a;font-weight:700;">PASS</span>' : '<span style="color:#dc2626;font-weight:700;">FAIL</span>' ?></span></div>
                <div><strong>Attendance</strong><span><?= sanitize($report['attendance'] ?: '—') ?></span></div>
            </div>

            <div class="print-details">
                <h4>School Remarks</h4>
                <div class="details-grid">
                    <div><strong>Teacher Remark</strong><span><?= sanitize($report['remark'] ?: 'Good conduct and satisfactory progress.') ?></span></div>
                    <div><strong>Issue Date</strong><span><?= $today ?></span></div>
                    <div><strong>Verification</strong><span style="color:#16a34a;font-weight:700;">Cryptographically Verified Official Record (<code><?= sanitize(substr($token, 0, 10)) ?>&hellip;</code>)</span></div>
                </div>
            </div>
        </div>

        <div class="print-foot">
            <div class="sig"><div class="sig-line"></div>Class Teacher</div>
            <div class="sig"><div class="sig-line"></div>Principal</div>
        </div>
    </div>

    <script nonce="<?= $cspNonce ?>">
    (function () {
        var btn = document.getElementById('printCardBtn');
        if (btn) btn.addEventListener('click', function () { window.print(); });
    })();
    </script>
</body>
</html>
