<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

$activeYear = rcGetActiveAcademicYear();
$year       = trim($_GET['year'] ?? $activeYear);
if ($year === '') $year = $activeYear;
$std        = trim($_GET['std'] ?? '1');
$division   = trim($_GET['division'] ?? 'A');
$testType   = trim($_GET['test_type'] ?? 'Unit Test 2');

$db = getDB();
$preferSem = in_array($testType, ['Unit Test 3', 'Unit Test 4'], true) ? 'Sem 2' : 'Sem 1';
$semStmt = $db->prepare("SELECT semester FROM report_card WHERE academic_year = ? AND std = ? AND division = ? ORDER BY (semester = ?) DESC, id ASC LIMIT 1");
$semStmt->execute([$year, $std, $division, $preferSem]);
$cohortSem = trim((string)$semStmt->fetchColumn()) ?: $preferSem;
$cohortClassKey = rcBuildClassKey($year, $std, $division, $cohortSem);

$matrixData = rcGetUnitTestClassData($year, $std, $division, $testType);
$students   = $matrixData['students'];
$subjects   = $matrixData['subjects'];
$totalMax   = $matrixData['total_max'];

$settings = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church English Medium School, Solapur';

$romanMap = ['1'=>'I','2'=>'II','3'=>'III','4'=>'IV','5'=>'V','6'=>'VI','7'=>'VII','8'=>'VIII','9'=>'IX','10'=>'X'];
$romanStd = $romanMap[(string)$std] ?? $std;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unit Test Parent Slips — STD <?= sanitize($romanStd) ?> <?= sanitize($division) ?> (<?= sanitize($testType) ?>)</title>
    <link rel="icon" href="../assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="../assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/whatsapp-share.css">
    <style>
        * { box-sizing: border-box; -webkit-print-color-adjust: exact; print-color-adjust: exact; }
        body {
            font-family: Arial, "Helvetica Neue", Helvetica, sans-serif;
            margin: 0;
            padding: 15px;
            color: #111;
            background: #fff;
            font-size: 9.5pt;
        }
        .no-print {
            padding: 10px 15px;
            background: #f1f5f9;
            border-bottom: 1px solid #cbd5e1;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .btn-print {
            background: #991b1b;
            color: #fff;
            border: none;
            padding: 8px 18px;
            border-radius: 4px;
            font-weight: 700;
            cursor: pointer;
            font-size: 10pt;
        }
        @media print {
            .no-print { display: none !important; }
            body { padding: 0; margin: 0; }
            @page {
                size: portrait;
                margin: 8mm;
            }
        }

        /* 4 Slips per page layout */
        .slips-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            grid-gap: 16px;
        }
        .slip-card {
            border: 2px dashed #475569;
            padding: 12px;
            border-radius: 6px;
            page-break-inside: avoid;
            background: #fff;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            min-height: 380px;
        }
        .slip-header {
            text-align: center;
            border-bottom: 1.5px solid #000;
            padding-bottom: 6px;
            margin-bottom: 8px;
        }
        .slip-school-name {
            font-size: 10.5pt;
            font-weight: 900;
            text-transform: uppercase;
        }
        .slip-badge {
            font-size: 8.5pt;
            font-weight: 800;
            margin-top: 3px;
            color: #991b1b;
        }
        .slip-meta-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            font-size: 8.5pt;
            margin-bottom: 8px;
            line-height: 1.35;
        }
        .slip-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 8.5pt;
            margin-bottom: 8px;
        }
        .slip-table th, .slip-table td {
            border: 1px solid #000;
            padding: 3px 5px;
            text-align: center;
        }
        .slip-table th { background: #f8fafc; font-weight: 800; }
        .slip-summary-bar {
            display: flex;
            justify-content: space-between;
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 8.5pt;
            font-weight: 800;
            margin-bottom: 8px;
        }
        .slip-sign-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 16px;
            font-size: 8pt;
            font-weight: 700;
            padding-top: 8px;
            border-top: 1px dotted #94a3b8;
        }
    </style>
</head>
<body>

    <div class="no-print" style="display:flex;align-items:center;justify-content:space-between;gap:12px;padding:12px 18px;background:#fff;border-bottom:1px solid #cbd5e1;position:sticky;top:0;z-index:100;box-shadow:0 2px 6px rgba(0,0,0,0.06);">
        <div>
            <strong>Unit Test Progress Slips:</strong> STD <?= sanitize($romanStd) ?> <?= sanitize($division) ?> &bull; <?= sanitize($testType) ?> (<?= count($students) ?> students)
        </div>
        <div style="display:flex;gap:8px;align-items:center;">
            <button type="button" class="btn btn-outline" id="downloadAllZipBtn" style="padding:6px 12px;font-size:0.82rem;font-weight:700;color:#0284c7;border-color:#0284c7;background:#fff;border-radius:6px;cursor:pointer;">
                <i class="fas fa-file-zipper"></i> Download All (ZIP)
            </button>
            <button type="button" class="btn btn-outline" id="downloadAllPdfBtn" style="padding:6px 12px;font-size:0.82rem;font-weight:700;color:#1e3a8a;border-color:#1e3a8a;background:#fff;border-radius:6px;cursor:pointer;">
                <i class="fas fa-file-pdf"></i> Download Class PDF
            </button>
            <a href="export_pdf_batch.php?class=<?= e_url($cohortClassKey) ?>&doc_type=unit_test&test_type=<?= e_url($testType) ?>" class="btn btn-outline" style="padding:6px 12px;font-size:0.82rem;font-weight:700;color:#475569;border-color:#cbd5e1;background:#fff;border-radius:6px;text-decoration:none;" title="Server-side fast offline ZIP archive" download>
                <i class="fas fa-file-archive"></i> Offline ZIP
            </a>
            <a href="whatsapp_share.php?doc_type=unit_test&test_type=<?= urlencode($testType) ?>&class=<?= urlencode($cohortClassKey) ?>" target="_blank" class="btn-whatsapp" style="font-size:0.82rem;padding:6px 14px;">
                <i class="fa-brands fa-whatsapp"></i> WhatsApp Hub
            </a>
            <button class="btn-print" onclick="window.print()">Print Slips</button>
            <button style="padding:6px 12px;border:1px solid #cbd5e1;background:#fff;border-radius:4px;cursor:pointer;" onclick="window.close()">Close</button>
        </div>
    </div>

    <div class="slips-grid">
        <?php foreach ($students as $stu): ?>
        <div class="slip-card">
            <div>
                <div class="slip-header" style="position:relative;">
                    <div class="slip-school-name"><?= sanitize($schoolName) ?></div>
                    <div class="slip-badge"><?= strtoupper(sanitize($testType)) ?> PROGRESS SLIP (<?= sanitize($year) ?>)</div>
                    <div style="position:absolute;top:0;right:0;display:flex;gap:4px;" class="no-print">
                        <button type="button" class="slip-pdf-btn js-slip-pdf-btn" 
                                data-roll="<?= e_attr($stu['roll_no']) ?>"
                                data-name="<?= e_attr($stu['student_name']) ?>"
                                title="Download A4 PDF"
                                style="padding:3px 7px;font-size:7.5pt;background:#f8fafc;color:#1e3a8a;border:1px solid #cbd5e1;border-radius:4px;cursor:pointer;font-weight:700;">
                            <i class="fas fa-file-pdf" style="color:#ef4444;"></i> A4 PDF
                        </button>
                        <button type="button" class="slip-wa-btn js-slip-wa-btn" 
                                data-roll="<?= e_attr($stu['roll_no']) ?>"
                                data-name="<?= e_attr($stu['student_name']) ?>"
                                title="Share slip via WhatsApp">
                            <i class="fa-brands fa-whatsapp"></i> Send
                        </button>
                    </div>
                </div>

                <div class="slip-meta-grid">
                    <div><strong>Roll No:</strong> <?= sanitize($stu['roll_no']) ?></div>
                    <div style="text-align:right;"><strong>Class:</strong> STD <?= sanitize($romanStd) ?>-<?= sanitize($division) ?></div>
                    <div style="grid-column:1/3;margin-top:2px;">
                        <strong>Student:</strong> <?= sanitize($stu['student_name']) ?>
                    </div>
                </div>

                <table class="slip-table">
                    <thead>
                        <tr>
                            <th style="text-align:left;padding-left:6px;">Subject</th>
                            <th style="width:50px;">Max</th>
                            <th style="width:50px;">Obt.</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($subjects as $s): 
                            $sName = $s['subject_name'];
                            $sm = $stu['subjects'][$sName] ?? null;
                            $val = '0';
                            if ($sm) {
                                if ($sm['is_absent']) $val = '-';
                                elseif ($sm['marks_obtained'] !== null) $val = (string)$sm['marks_obtained'];
                            }
                        ?>
                        <tr>
                            <td style="text-align:left;padding-left:6px;"><?= sanitize($sName) ?></td>
                            <td><?= (float)$s['max_marks'] ?></td>
                            <td style="font-weight:700;"><?= sanitize($val) ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr style="font-weight:900;background:#f8fafc;">
                            <td style="text-align:left;padding-left:6px;">Total</td>
                            <td><?= (float)$totalMax ?></td>
                            <td style="color:#0f766e;"><?= $stu['total_obtained'] ?></td>
                        </tr>
                    </tbody>
                </table>

                <div class="slip-summary-bar">
                    <span>%: <?= $stu['percentage'] !== null ? number_format($stu['percentage'], 2).'%' : '-' ?></span>
                    <span>Grade: <?= sanitize($stu['grade']) ?></span>
                    <span>Remark: <?= sanitize($stu['remark']) ?></span>
                </div>
            </div>

            <div class="slip-sign-row">
                <div>Class Teacher</div>
                <div>Parent's Signature</div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <script src="../assets/vendor/html2canvas@1.4.1/html2canvas.min.js"></script>
    <script src="../assets/vendor/jspdf@2.5.1/jspdf.umd.min.js"></script>
    <script src="../assets/vendor/jszip@3.10.1/jszip.min.js"></script>
    <script src="../assets/js/hcc-logo-base64.js?v=<?= filemtime(__DIR__ . '/../assets/js/hcc-logo-base64.js') ?>"></script>
    <script src="../assets/js/whatsapp-share.js?v=<?= filemtime(__DIR__ . '/../assets/js/whatsapp-share.js') ?>"></script>
    <script src="../assets/js/hcc-pdf-engine.js?v=<?= filemtime(__DIR__ . '/../assets/js/hcc-pdf-engine.js') ?>"></script>
    <script>
    (function () {
        'use strict';

        const studentsData = <?= json_encode($students, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const subjectsData = <?= json_encode($subjects, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const totalMax     = <?= json_encode((float)$totalMax, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const schoolName   = <?= json_encode($schoolName, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const className    = <?= json_encode("STD {$romanStd}-{$division}", JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const testType     = <?= json_encode($testType, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        const classKey     = <?= json_encode($cohortClassKey, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;

        const meta = {
            schoolName: schoolName,
            className: className,
            classKey: classKey,
            academicYear: <?= json_encode($year, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>,
            testType: testType
        };

        const formattedStudents = studentsData.map(stu => {
            const subList = subjectsData.map(s => {
                const sm = stu.subjects[s.subject_name];
                let val = '';
                if (sm) {
                    if (sm.is_absent) val = 'Absent';
                    else if (sm.marks_obtained !== null) val = String(sm.marks_obtained);
                }
                return { name: s.subject_name, max: Number(s.max_marks), obtained: val };
            });
            return {
                roll: stu.roll_no,
                name: stu.student_name,
                gender: stu.gender || '',
                parent_phone: stu.parent_phone || '',
                attendance: stu.attendance || '',
                parents_sign: stu.parents_sign ? 1 : 0,
                subjects: subList,
                obtained: stu.total_obtained,
                max_total: totalMax,
                pct: stu.percentage !== null ? Number(stu.percentage).toFixed(2) : '',
                grade: stu.grade || '',
                remark: stu.remark || ''
            };
        });

        // 1. Download All Class Slips as ZIP Archive
        const zipBtn = document.getElementById('downloadAllZipBtn');
        if (zipBtn) {
            zipBtn.addEventListener('click', () => {
                HCCPdfEngine.downloadClassBatchZip(formattedStudents, 'unit_test', meta);
            });
        }

        // 2. Download All Class Slips compiled in 1 A4 PDF
        const pdfBtn = document.getElementById('downloadAllPdfBtn');
        if (pdfBtn) {
            pdfBtn.addEventListener('click', () => {
                HCCPdfEngine.downloadClassBatchPdf(formattedStudents, 'unit_test', meta);
            });
        }

        // 3. Individual Student A4 PDF Download
        document.querySelectorAll('.js-slip-pdf-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const roll = btn.dataset.roll;
                const fStu = formattedStudents.find(s => String(s.roll) === String(roll));
                if (fStu) {
                    HCCPdfEngine.downloadSinglePdf(fStu, 'unit_test', meta);
                }
            });
        });

        // 4. Individual Student WhatsApp Share with Attached PDF
        document.querySelectorAll('.js-slip-wa-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const roll = btn.dataset.roll;
                const fStu = formattedStudents.find(s => String(s.roll) === String(roll));
                if (fStu) {
                    const phoneKey = 'hcc_wa_phone_' + classKey + '_' + roll;
                    const savedPhone = (fStu.parent_phone && String(fStu.parent_phone).trim()) || localStorage.getItem(phoneKey) || '';
                    HCCPdfEngine.shareWhatsAppWithPdf(fStu, 'unit_test', meta, savedPhone, '');
                }
            });
        });
    })();
    </script>
</body>
</html>
