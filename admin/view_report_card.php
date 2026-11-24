<?php
/* Individual Student Report Card — detailed subject-wise view */
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

$db = getDB();
$settings = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church High School';
$schoolTagline = trim((string)($settings['school_tagline'] ?? ''));
if ($schoolTagline === '') {
    $schoolTagline = 'Excellence in Academic & Moral Education';
}
$schoolLogo = $settings['logo'] ?? 'assets/images/hcclogo.jpg';

$classKey = trim($_GET['class'] ?? '');
$roll = trim($_GET['roll'] ?? '');

if ($classKey === '' || $roll === '') {
    http_response_code(400);
    die('Missing required parameters: class and roll.');
}

$report = rcStudentReport($classKey, $roll);
if (!$report) {
    http_response_code(404);
    die('Report card not found for the given student and class.');
}

$c = rcParseClassKey(urldecode($classKey));
$clsLabel = 'STD ' . $c['std'] . ' ' . $c['division'] . ' — ' . $c['semester'];
$fileBase = preg_replace('/[^A-Za-z0-9_-]+/', '_', 'Report_Card_' . $report['name'] . '_' . $report['roll'] . '_' . $clsLabel . '_' . $c['academic_year']);
$today = date('d-m-Y');
$publicCardUrl = rcGetPublicCardUrl($classKey, (string)$report['roll']);
$qrDataUri = rcRenderQrSvgDataUri($publicCardUrl, 2);
$parentPhone = preg_replace('/\D+/', '', (string)($report['rows'][0]['parent_phone'] ?? ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Report Card — <?= sanitize($report['name']) ?> (<?= sanitize($report['roll']) ?>)</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/merriweather/merriweather.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/whatsapp-share.css?v=<?= filemtime(__DIR__ . '/../assets/css/whatsapp-share.css') ?>">
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
        .qr-verify-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; padding: 10px 24px 12px; border-top: 1px dashed #cbd5e1; background: #f8fafc; page-break-inside: avoid; }
        .qr-verify-info { display: flex; flex-direction: column; gap: 2px; font-size: 0.74rem; color: #475569; }
        .qr-verify-info strong { color: #1a237e; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.04em; display: inline-flex; align-items: center; gap: 6px; }
        .qr-verify-info a { color: #1d4ed8; text-decoration: none; font-weight: 600; word-break: break-all; font-size: 0.7rem; }
        .qr-verify-info a:hover { text-decoration: underline; }
        .qr-verify-badge { display: inline-flex; align-items: center; gap: 8px; flex-shrink: 0; }
        .qr-verify-badge img { width: 58px; height: 58px; border: 1px solid #cbd5e1; border-radius: 6px; padding: 2px; background: #fff; display: block; }
        .empty-note { text-align: center; padding: 40px; color: #94a3b8; }
        .error-box { max-width: 600px; margin: 40px auto; padding: 32px; background: #fff; border: 2px solid #fee2e2; border-radius: 12px; text-align: center; }
        .error-box h1 { color: #dc2626; margin-bottom: 12px; }
        .error-box p { color: #64748b; margin-bottom: 20px; }
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
        <a class="btn btn-outline" href="results.php?class=<?= e_url($classKey) ?>" target="_self"><i class="fas fa-arrow-left"></i> Back to Results</a>
        <span class="spacer"></span>
        <button class="btn btn-whatsapp" type="button" id="whatsappShareBtn"><i class="fa-brands fa-whatsapp"></i> Share via WhatsApp</button>
        <button class="btn btn-secondary" type="button" id="downloadPrintBtn"><i class="fas fa-download"></i> Download PDF</button>
        <button class="btn btn-primary" type="button" id="printNowBtn"><i class="fas fa-print"></i> Print</button>
    </div>

    <div class="print-sheet">
        <div class="print-hdr">
            <img src="<?= e_attr(BASE_URL . safeLogoSrc($schoolLogo)) ?>" alt="Logo" id="schoolLogoImg">
            <h1><?= sanitize($schoolName) ?></h1>
            <?php if ($schoolTagline): ?><h2><?= sanitize($schoolTagline) ?></h2><?php endif; ?>
        </div>
        <div class="print-title"><h3>Report Card</h3></div>
        
        <div class="print-meta">
            <div><strong>Student Name</strong><span><?= sanitize($report['name']) ?></span></div>
            <div><strong>Roll No.</strong><span><?= sanitize($report['roll']) ?></span></div>
            <div><strong>Class</strong><span><?= sanitize($clsLabel) ?></span></div>
            <div><strong>Academic Year</strong><span><?= sanitize($c['academic_year']) ?></span></div>
        </div>

        <div class="print-body">
            <?php if (empty($report['rows'])): ?>
                <div class="empty-note">No subject records found.</div>
            <?php else: ?>
            <?php
                $hasSa = false;
                foreach ($report['rows'] as $r) {
                    if ((float)$r['sa_max'] > 0.0) {
                        $hasSa = true;
                        break;
                    }
                }
            ?>
            <?php if ($hasSa): ?>
            <table class="print-table">
                <thead>
                    <tr>
                        <th style="width:6%;">#</th>
                        <th style="width:28%;">Subject</th>
                        <th style="width:12%;">FA Max</th>
                        <th style="width:12%;">FA Obtained</th>
                        <th style="width:12%;">SA Max</th>
                        <th style="width:12%;">SA Obtained</th>
                        <th style="width:14%;">Total</th>
                        <th style="width:14%;">%</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $idx = 1; foreach ($report['rows'] as $row): 
                        $faMax = (float)$row['fa_max'];
                        $faObt = (float)$row['fa_obtained'];
                        $saMax = (float)$row['sa_max'];
                        $saObt = (float)$row['sa_obtained'];
                        $subjTotal = $faMax + $saMax;
                        $subjObt = $faObt + $saObt;
                        $subjPct = $subjTotal > 0 ? round(($subjObt / $subjTotal) * 100, 2) : 0;
                    ?>
                    <tr>
                        <td><?= $idx++ ?></td>
                        <td><strong><?= sanitize($row['subject_name']) ?></strong></td>
                        <td><?= number_format($faMax, 1) ?></td>
                        <td><?= number_format($faObt, 1) ?></td>
                        <td><?= number_format($saMax, 1) ?></td>
                        <td><?= number_format($saObt, 1) ?></td>
                        <td><strong><?= number_format($subjObt, 1) ?> / <?= number_format($subjTotal, 1) ?></strong></td>
                        <td><?= e_attr($subjPct) ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php else: ?>
            <table class="print-table">
                <thead>
                    <tr>
                        <th style="width:8%;">#</th>
                        <th style="width:44%;">Subject</th>
                        <th style="width:16%;">Max Marks</th>
                        <th style="width:18%;">Marks Obtained</th>
                        <th style="width:14%;">%</th>
                    </tr>
                </thead>
                <tbody>
                    <?php $idx = 1; foreach ($report['rows'] as $row): 
                        $maxM = (float)$row['fa_max'] + (float)$row['sa_max'];
                        $obtM = (float)$row['fa_obtained'] + (float)$row['sa_obtained'];
                        $subjPct = $maxM > 0 ? round(($obtM / $maxM) * 100, 2) : 0;
                    ?>
                    <tr>
                        <td><?= $idx++ ?></td>
                        <td><strong><?= sanitize($row['subject_name']) ?></strong></td>
                        <td><?= number_format($maxM, 1) ?></td>
                        <td><strong><?= number_format($obtM, 1) ?></strong></td>
                        <td><?= e_attr($subjPct) ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            <?php endif; ?>

            <?php $gBadgeCls = preg_replace('/[^a-zA-Z0-9_-]/', '', $report['grade']); ?>
            <div class="print-summary">
                <div><strong>Grand Total</strong><span><?= number_format($report['obtained'], 1) ?> / <?= number_format($report['grandTotal'], 1) ?></span></div>
                <div><strong>Percentage</strong><span><?= e_attr($report['percentage']) ?>%</span></div>
                <div><strong>Grade</strong><span><span class="grade-pill grade-<?= e_attr($gBadgeCls) ?>"><?= e_attr($report['grade']) ?></span></span></div>
                <div><strong>Division</strong><span><?= sanitize($report['division_label']) ?></span></div>
                <div><strong>Status</strong><span><?= $report['passed'] ? '<span style="color:#16a34a;font-weight:700;">PASS</span>' : '<span style="color:#dc2626;font-weight:700;">FAIL</span>' ?></span></div>
            </div>

            <div class="print-details">
                <h4>Additional Information</h4>
                <div class="details-grid">
                    <div><strong>Remark</strong><span><?= sanitize(rcDisplayRemark($report['remark'] ?? '', $report['percentage'] ?? 0) ?: '—') ?></span></div>
                    <div><strong>Attendance</strong><span><?= sanitize($report['attendance'] ?: '—') ?></span></div>
                    <div><strong>Parent Signature</strong><span><?php if (!empty($report['parents_sign']) && !empty($report['parent_signature_data'])): ?><img src="<?= e_attr($report['parent_signature_data']) ?>" alt="Parent signature" style="max-height:28px;max-width:110px;object-fit:contain;vertical-align:middle;"><?php else: ?><?= $report['parents_sign'] ? 'Confirmed' : 'Not Signed' ?><?php endif; ?></span></div>
                    <div><strong>Date Issued</strong><span><?= $today ?></span></div>
                    <div><strong>Class Teacher</strong><span></span></div>
                    <div><strong>Principal</strong><span></span></div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="print-foot">
            <div class="sig"><div class="sig-line"></div>Class Teacher</div>
            <div class="sig"><div class="sig-line"></div>Coordinator</div>
            <div class="sig"><div class="sig-line"></div>Principal</div>
        </div>

        <div class="qr-verify-bar">
            <div class="qr-verify-info">
                <strong><i class="fas fa-shield-check" style="color:#16a34a;"></i> Digital Record Verification</strong>
                <span>Scan QR code or open link to verify authenticity on the official school portal.</span>
                <a href="<?= e_attr($publicCardUrl) ?>" target="_blank" rel="noopener"><?= sanitize($publicCardUrl) ?></a>
            </div>
            <a class="qr-verify-badge" href="<?= e_attr($publicCardUrl) ?>" target="_blank" rel="noopener" title="Verify Report Card Online">
                <img src="<?= e_attr($qrDataUri) ?>" alt="QR Verification Code">
            </a>
        </div>
    </div>

    <script src="<?= BASE_URL ?>assets/vendor/html2canvas@1.4.1/html2canvas.min.js"></script>
    <script src="<?= BASE_URL ?>assets/vendor/jspdf@2.5.1/jspdf.umd.min.js"></script>
    <script src="<?= BASE_URL ?>assets/js/whatsapp-share.js?v=<?= filemtime(__DIR__ . '/../assets/js/whatsapp-share.js') ?>"></script>
    <script nonce="<?= $cspNonce ?>">
    (function () {
        'use strict';

        const logoImg = document.getElementById('schoolLogoImg');
        if (logoImg) {
            logoImg.addEventListener('error', function () { logoImg.style.display = 'none'; });
        }

        const printSheet = document.querySelector('.print-sheet');
        const rollStr = <?= json_encode(str_pad((string)$report['roll'], 2, '0', STR_PAD_LEFT), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const studentName = <?= json_encode($report['name'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const cleanName = studentName.replace(/[^a-zA-Z0-9_\-]/g, '_');
        const pdfFileName = 'Report_Card_Roll_' + rollStr + '_' + cleanName + '.pdf';

        function generateCardPdfBlob() {
            return html2canvas(printSheet, {
                scale: 2,
                useCORS: true,
                backgroundColor: '#ffffff',
                logging: false
            }).then(function (canvas) {
                const imgData = canvas.toDataURL('image/jpeg', 0.95);
                const jsPDF = window.jspdf.jsPDF;
                const pdf = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });
                const imgProps = pdf.getImageProperties(imgData);
                const pageW = 210, pageH = 297;
                let pdfW = pageW;
                let pdfH = (imgProps.height * pageW) / imgProps.width;
                if (pdfH > pageH) {
                    pdfH = pageH;
                    pdfW = (imgProps.width * pageH) / imgProps.height;
                }
                const offsetX = (pageW - pdfW) / 2;
                pdf.addImage(imgData, 'JPEG', offsetX, 0, pdfW, pdfH, undefined, 'FAST');
                return {
                    pdf: pdf,
                    blob: pdf.output('blob'),
                    fileName: pdfFileName
                };
            });
        }

        // 1. Direct A4 PDF Download
        const dlBtn = document.getElementById('downloadPrintBtn');
        if (dlBtn) {
            dlBtn.addEventListener('click', function () {
                const origHtml = dlBtn.innerHTML;
                dlBtn.disabled = true;
                dlBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Generating A4 PDF...';

                generateCardPdfBlob().then(function (res) {
                    res.pdf.save(res.fileName);
                    HCCWhatsApp.showToast('Downloaded ' + res.fileName, 'fa-file-pdf');
                }).catch(function (err) {
                    alert('PDF download failed: ' + err.message);
                }).finally(function () {
                    dlBtn.disabled = false;
                    dlBtn.innerHTML = origHtml;
                });
            });
        }

        // 2. Browser Print Now
        const printBtn = document.getElementById('printNowBtn');
        if (printBtn) {
            printBtn.addEventListener('click', function () {
                window.print();
            });
        }

        // 3. Share via WhatsApp with PDF Attached
        const waBtn = document.getElementById('whatsappShareBtn');
        if (waBtn) {
            waBtn.addEventListener('click', function () {
                const origHtml = waBtn.innerHTML;
                waBtn.disabled = true;
                waBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Preparing PDF...';

                const jsonFlags = true;
                const msg = HCCWhatsApp.templates.reportCard({
                    schoolName: <?= json_encode($schoolName, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                    studentName: studentName,
                    roll: <?= json_encode($report['roll'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                    className: <?= json_encode($clsLabel, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                    semester: <?= json_encode($c['semester'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                    academicYear: <?= json_encode($c['academic_year'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                    obtained: <?= json_encode(number_format($report['obtained'], 1), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                    maxTotal: <?= json_encode(number_format($report['grandTotal'], 1), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                    percentage: <?= json_encode($report['percentage'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                    grade: <?= json_encode($report['grade'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                    status: <?= json_encode($report['passed'] ? 'PASS' : 'FAIL', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                    attendance: <?= json_encode($report['attendance'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                    remark: <?= json_encode(rcDisplayRemark($report['remark'] ?? '', $report['percentage'] ?? 0), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>
                });

                const parentPhone = <?= json_encode($parentPhone, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

                generateCardPdfBlob().then(function (res) {
                    HCCWhatsApp.openModal({
                        studentName: studentName,
                        roll: <?= json_encode($report['roll'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                        className: <?= json_encode($clsLabel, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                        classKey: <?= json_encode($classKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                        phone: parentPhone,
                        messageText: msg,
                        pdfBlob: res.blob,
                        fileName: res.fileName,
                        onDownloadPdf: function () {
                            res.pdf.save(res.fileName);
                        }
                    });
                }).catch(function (err) {
                    console.error(err);
                    HCCWhatsApp.openModal({
                        studentName: studentName,
                        roll: <?= json_encode($report['roll'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                        className: <?= json_encode($clsLabel, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                        classKey: <?= json_encode($classKey, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
                        phone: parentPhone,
                        messageText: msg
                    });
                }).finally(function () {
                    waBtn.disabled = false;
                    waBtn.innerHTML = origHtml;
                });
            });
        }
    })();
    </script>
</body>
</html>