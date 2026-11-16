<?php
/* Unit Test Report Card — single-student view for Unit Test results.
   Dedicated to unit tests only; the semester "Report Card"
   (view_report_card.php) is untouched. */
$cspNonce = bin2hex(random_bytes(16));
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-{$cspNonce}'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

$year      = trim($_GET['year'] ?? '');
$std       = trim($_GET['std'] ?? '');
$division  = trim($_GET['division'] ?? '');
$testType  = trim($_GET['test_type'] ?? '');
$roll      = trim($_GET['roll'] ?? '');

if ($year === '' || $std === '' || $roll === '') {
    http_response_code(400);
    die('Missing required parameters: year, std and roll.');
}

$settings = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church High School';
$schoolTagline = trim((string)($settings['school_tagline'] ?? ''));
if ($schoolTagline === '') {
    $schoolTagline = 'Excellence in Academic & Moral Education';
}
$schoolLogo = $settings['logo'] ?? 'assets/images/hcclogo.jpg';

$matrixData = rcGetUnitTestClassData($year, $std, $division, $testType);
$student = null;
foreach ($matrixData['students'] as $stu) {
    if ((string)($stu['roll_no'] ?? '') === $roll) { $student = $stu; break; }
}
if ($student === null) {
    http_response_code(404);
    die('Unit Test report card not found for the given student and class.');
}

$subjects = $matrixData['subjects'];
$clsLabel = 'STD ' . $std . ' ' . $division;
$fileBase = preg_replace('/[^A-Za-z0-9_-]+/', '_', 'Unit_Test_Report_' . $student['student_name'] . '_' . $roll . '_' . $clsLabel);
$today = date('d-m-Y');
$backLink = 'unit_tests.php?year=' . urlencode($year) . '&std=' . urlencode($std) . '&division=' . urlencode($division) . '&test_type=' . urlencode($testType);
$stuPct = $student['percentage'];
$stuPassed = ($stuPct !== null && $stuPct >= 35);
$publicSlipUrl = rcGetPublicSlipUrl($year, $std, $division, $testType, $roll);
$qrDataUri = rcRenderQrSvgDataUri($publicSlipUrl, 2);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Unit Test Report Card — <?= sanitize($student['student_name']) ?> (<?= sanitize($roll) ?>)</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/merriweather/merriweather.css">
    <style>
        * { box-sizing: border-box; }
        body { font-family: 'Inter', system-ui, sans-serif; background: #f1f5f9; color: #0f172a; margin: 0; padding: 24px; }
        .print-toolbar { max-width: 900px; margin: 0 auto 16px; display: flex; gap: 10px; flex-wrap: wrap; align-items: center; }
        .btn { display: inline-flex; align-items: center; gap: 6px; padding: 10px 18px; border-radius: 8px; font-weight: 600; font-size: 0.88rem; cursor: pointer; border: none; text-decoration: none; font-family: inherit; }
        .btn-outline { background: #fff; border: 1.5px solid #cbd5e1; color: #334155; }
        .btn-outline:hover { border-color: #1a237e; color: #1a237e; }
        .btn-secondary { background: #64748b; color: #fff; }
        .btn-secondary:hover { background: #475569; }
        .btn-primary { background: #1a237e; color: #fff; }
        .btn-primary:hover { background: #283593; }
        .spacer { flex: 1; }
        .print-sheet { max-width: 900px; margin: 0 auto; background: #fff; border: 2px solid #1a237e; }
        .print-hdr { text-align: center; padding: 24px 24px 14px; position: relative; }
        .print-hdr::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 4px; background: linear-gradient(90deg, #1a237e, #c9943e, #1a237e); }
        .print-hdr img { height: 64px; width: auto; object-fit: contain; margin-bottom: 6px; }
        .print-hdr h1 { font-family: 'Merriweather', Georgia, serif; font-size: 1.35rem; font-weight: 900; color: #1a237e; margin: 0; }
        .print-hdr h2 { font-family: 'Merriweather', Georgia, serif; font-size: 0.95rem; font-weight: 700; color: #1a237e; margin: 2px 0 0; letter-spacing: 1px; }
        .print-title { margin: 0; padding: 10px 24px; border-top: 2px solid #1a237e; border-bottom: 2px solid #1a237e; }
        .print-title h3 { margin: 0; font-size: 1rem; font-weight: 700; color: #dc2626; text-transform: uppercase; letter-spacing: 3px; text-align: center; }
        .print-meta { display: grid; grid-template-columns: repeat(4, 1fr); gap: 12px; padding: 16px 24px; font-size: 0.85rem; background: #f8fafc; border-bottom: 1px solid #e2e8f0; }
        .print-meta > div { display: flex; flex-direction: column; gap: 2px; }
        .print-meta strong { color: #64748b; font-weight: 600; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.04em; }
        .print-meta span { font-weight: 700; color: #1a237e; word-break: break-word; }
        .print-body { padding: 16px 24px 20px; }
        table.print-table { width: 100%; border-collapse: collapse; border: 2px solid #1a237e; font-size: 0.82rem; }
        table.print-table thead th { background: #1a237e; color: #fff; padding: 8px 10px; text-align: center; font-size: 0.72rem; text-transform: uppercase; letter-spacing: 0.05em; }
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
        .qr-verify-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 24px 12px; border-top: 1px dashed #cbd5e1; background: #f8fafc; page-break-inside: avoid; }
        .qr-verify-info { display: flex; flex-direction: column; gap: 2px; font-size: 0.74rem; color: #475569; }
        .qr-verify-info strong { color: #1a237e; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; display: inline-flex; align-items: center; gap: 6px; }
        .qr-verify-info a { color: #1d4ed8; text-decoration: none; font-weight: 600; word-break: break-all; font-size: 0.7rem; }
        .qr-verify-info a:hover { text-decoration: underline; }
        .qr-verify-badge { display: inline-flex; align-items: center; gap: 8px; flex-shrink: 0; }
        .qr-verify-badge img { width: 58px; height: 58px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 2px; background: #fff; display: block; }
        .empty-note { text-align: center; padding: 40px; color: #94a3b8; }
        .error-box { max-width: 600px; margin: 40px auto; padding: 32px; background: #fff; border: 2px solid #fee2e2; border-radius: 12px; text-align: center; }
        @media print {
            @page { margin: 10mm; size: A4 portrait; }
            * { print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            body { background: #fff; padding: 0; font-size: 9pt; }
            .print-toolbar { display: none !important; }
            .print-sheet { max-width: 100%; border-width: 2px; }
            table.print-table thead { display: table-header-group; }
            table.print-table tr { page-break-inside: avoid; }
            table.print-table thead th { background: #1a237e !important; color: #fff !important; }
            .print-summary { page-break-inside: avoid; }
        }
        @media (max-width: 768px) {
            .print-meta { grid-template-columns: repeat(2, 1fr); }
            .print-summary { grid-template-columns: repeat(2, 1fr); }
            .details-grid { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body data-print-name="<?= sanitize($fileBase . '-' . date('Ymd')) ?>">
    <div class="print-toolbar">
        <a class="btn btn-outline" href="<?= sanitize($backLink) ?>"><i class="fas fa-arrow-left"></i> Back to Unit Tests</a>
        <span class="spacer"></span>
        <button class="btn btn-secondary" type="button" id="downloadPrintBtn"><i class="fas fa-download"></i> Download PDF</button>
        <button class="btn btn-primary" type="button" id="printNowBtn"><i class="fas fa-print"></i> Print</button>
    </div>

    <div class="print-sheet">
        <div class="print-hdr">
            <img src="<?= e_attr(BASE_URL . safeLogoSrc($schoolLogo)) ?>" alt="Logo" id="schoolLogoImg">
            <h1><?= sanitize($schoolName) ?></h1>
            <?php if ($schoolTagline): ?><h2><?= sanitize($schoolTagline) ?></h2><?php endif; ?>
        </div>
        <div class="print-title"><h3>Unit Test Report Card</h3></div>

        <div class="print-meta">
            <div><strong>Student Name</strong><span><?= sanitize($student['student_name']) ?></span></div>
            <div><strong>Roll No.</strong><span><?= sanitize($student['roll_no']) ?></span></div>
            <div><strong>Class</strong><span><?= sanitize($clsLabel) ?></span></div>
            <div><strong>Exam</strong><span><?= sanitize($testType) ?> (<?= sanitize($year) ?>)</span></div>
        </div>

        <div class="print-body">
            <?php if (empty($subjects)): ?>
                <div class="empty-note">No subject records found.</div>
            <?php else: ?>
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
                <tbody>
                    <?php $idx = 1; foreach ($subjects as $subj):
                        $sName = $subj['subject_name'];
                        $maxM = (float)$subj['max_marks'];
                        $mk = $student['subjects'][$sName] ?? null;
                        $isAb = !empty($mk['is_absent']);
                        $obtM = (!$isAb && isset($mk['marks_obtained']) && $mk['marks_obtained'] !== null) ? (float)$mk['marks_obtained'] : null;
                        $subjPct = (!$isAb && $obtM !== null && $maxM > 0) ? round(($obtM / $maxM) * 100, 2) : null;
                    ?>
                    <tr>
                        <td><?= $idx++ ?></td>
                        <td><strong><?= sanitize($sName) ?></strong></td>
                        <td><?= number_format($maxM, 1) ?></td>
                        <td><?= $isAb ? '<strong>–</strong>' : ($obtM !== null ? '<strong>' . number_format($obtM, 1) . '</strong>' : '–') ?></td>
                        <td><?= $subjPct !== null ? e_attr($subjPct) . '%' : '–' ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <div class="print-summary">
                <div><strong>Grand Total</strong><span><?= number_format((float)$student['total_obtained'], 1) ?> / <?= number_format((float)$student['total_max'], 1) ?></span></div>
                <div><strong>Percentage</strong><span><?= $stuPct !== null ? e_attr($stuPct) . '%' : '–' ?></span></div>
                <div><strong>Grade</strong><span><?= sanitize($student['grade'] ?? '–') ?></span></div>
                <div><strong>Status</strong><span><?= $stuPassed ? '<span style="color:#16a34a;font-weight:700;">PASS</span>' : '<span style="color:#dc2626;font-weight:700;">FAIL</span>' ?></span></div>
                <div><strong>Attendance</strong><span><?= sanitize($student['attendance'] ?: '—') ?></span></div>
            </div>

            <div class="print-details">
                <h4>Additional Information</h4>
                <div class="details-grid">
                    <div><strong>Remark</strong><span><?= sanitize($student['remark'] ?: '—') ?></span></div>
                    <div><strong>Date Issued</strong><span><?= $today ?></span></div>
                    <div><strong>Parents Sign</strong><span><?= !empty($student['parents_sign']) ? 'Confirmed' : 'Not Signed' ?></span></div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="print-foot">
            <div class="sig"><div class="sig-line"></div>Teacher Signature</div>
            <div class="sig"><div class="sig-line"></div>Coordinator Signature</div>
            <div class="sig"><div class="sig-line"></div>Principal Signature</div>
        </div>

        <div class="qr-verify-bar">
            <div class="qr-verify-info">
                <strong><i class="fas fa-shield-check" style="color:#16a34a;"></i> Digital Record Verification</strong>
                <span>Scan QR code or open link to verify authenticity on the official school portal.</span>
                <a href="<?= e_attr($publicSlipUrl) ?>" target="_blank" rel="noopener"><?= sanitize($publicSlipUrl) ?></a>
            </div>
            <a class="qr-verify-badge" href="<?= e_attr($publicSlipUrl) ?>" target="_blank" rel="noopener" title="Verify Unit Test Report Card Online">
                <img src="<?= e_attr($qrDataUri) ?>" alt="QR Verification Code">
            </a>
        </div>
    </div>

    <script src="<?= BASE_URL ?>assets/vendor/html2canvas@1.4.1/html2canvas.min.js"></script>
    <script src="<?= BASE_URL ?>assets/vendor/jspdf@2.5.1/jspdf.umd.min.js"></script>
    <script src="<?= BASE_URL ?>assets/js/print-utils.js?v=<?= is_file(__DIR__ . '/../assets/js/print-utils.js') ? filemtime(__DIR__ . '/../assets/js/print-utils.js') : 0 ?>"></script>
    <script nonce="<?= $cspNonce ?>">
    (function () {
        'use strict';

        var logoImg = document.getElementById('schoolLogoImg');
        if (logoImg) {
            logoImg.addEventListener('error', function () { logoImg.style.display = 'none'; });
        }

        var printSheet = document.querySelector('.print-sheet');
        var rollStr = <?= json_encode(str_pad((string)$roll, 2, '0', STR_PAD_LEFT), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        var studentName = <?= json_encode($student['student_name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        var cleanName = studentName.replace(/[^a-zA-Z0-9_\-]/g, '_');
        var pdfFileName = 'Unit_Test_Report_Roll_' + rollStr + '_' + cleanName + '.pdf';

        function generateCardPdfBlob() {
            return html2canvas(printSheet, {
                scale: 2,
                useCORS: true,
                backgroundColor: '#ffffff',
                logging: false
            }).then(function (canvas) {
                var imgData = canvas.toDataURL('image/jpeg', 0.95);
                var jsPDF = window.jspdf.jsPDF;
                var pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
                var imgProps = pdf.getImageProperties(imgData);
                var pageW = 210, pageH = 297;
                var pdfW = pageW;
                var pdfH = (imgProps.height * pageW) / imgProps.width;
                if (pdfH > pageH) {
                    pdfH = pageH;
                    pdfW = (imgProps.width * pageH) / imgProps.height;
                }
                var offsetX = (pageW - pdfW) / 2;
                pdf.addImage(imgData, 'JPEG', offsetX, 0, pdfW, pdfH, undefined, 'FAST');
                return {
                    pdf: pdf,
                    blob: pdf.output('blob'),
                    fileName: pdfFileName
                };
            });
        }

        var dlBtn = document.getElementById('downloadPrintBtn');
        if (dlBtn) {
            dlBtn.addEventListener('click', function () {
                var origHtml = dlBtn.innerHTML;
                dlBtn.disabled = true;
                dlBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating A4 PDF...';
                generateCardPdfBlob().then(function (res) {
                    res.pdf.save(res.fileName);
                }).catch(function (err) {
                    alert('PDF generation failed: ' + (err && err.message ? err.message : err));
                }).finally(function () {
                    dlBtn.disabled = false;
                    dlBtn.innerHTML = origHtml;
                });
            });
        }

        var printBtn = document.getElementById('printNowBtn');
        if (printBtn) {
            printBtn.addEventListener('click', function () { window.print(); });
        }
    })();
    </script>
</body>
</html>
