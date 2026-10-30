<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

$year     = trim($_GET['year'] ?? '2025-26');
$std      = trim($_GET['std'] ?? '1');
$division = trim($_GET['division'] ?? 'A');
$testType = trim($_GET['test_type'] ?? 'Unit Test 2');

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

$matrixData = rcGetUnitTestClassData($year, $std, $division, $testType);
$students   = $matrixData['students'];
$subjects   = $matrixData['subjects'];
$totalMax   = $matrixData['total_max'];

$settings = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church English Medium School, Solapur';

// Roman Numeral standard
$romanMap = ['1'=>'I','2'=>'II','3'=>'III','4'=>'IV','5'=>'V','6'=>'VI','7'=>'VII','8'=>'VIII','9'=>'IX','10'=>'X'];
$romanStd = $romanMap[(string)$std] ?? $std;

// Roman Numeral Unit test
$romanTest = strtoupper(str_replace(['Unit Test ', 'Unit Test', 'UT '], '', $testType));
if (in_array($romanTest, ['1','2','3','4'])) {
    $romanTest = $romanMap[$romanTest];
}

$totalCols = 2 + count($subjects) + 5 + ($showSign ? 1 : 0);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Unit Test Marksheet — STD <?= sanitize($romanStd) ?> <?= sanitize($division) ?> (<?= sanitize($testType) ?>)</title>
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
<body data-print-name="Unit_Test_<?= sanitize($romanTest) ?>_STD_<?= sanitize($romanStd) ?>_<?= sanitize($division) ?>_<?= sanitize($year) ?>_<?= date('Ymd') ?>">

    <div class="ptb-wrap">
        <div class="ptb-bar">
            <a class="ptb-btn ptb-ghost" href="unit_tests.php?year=<?= urlencode($year) ?>&std=<?= urlencode($std) ?>&division=<?= urlencode($division) ?>&test_type=<?= urlencode($testType) ?>"><i class="fas fa-arrow-left"></i> Back</a>
            <span class="ptb-title"><i class="fas fa-clipboard-list"></i> Unit Test Marksheet</span>
            <span class="ptb-spacer"></span>
            <button class="ptb-btn ptb-soft" type="button" id="toggleDigitalSignBtn" title="Toggle Digital Sign vs Blank Empty Blocks for Pen Signature">
                <i class="fas fa-signature"></i> Digital Sign: <span id="digitalSignStatus"><?= $digitalSign ? 'ON' : 'OFF' ?></span>
            </button>
            <a class="ptb-btn ptb-ghost" href="whatsapp_share.php?doc_type=unit_test&test_type=<?= urlencode($testType) ?>&class=<?= urlencode(rcBuildClassKey($year, $std, $division, 'Sem 1')) ?>" target="_blank" title="Share on WhatsApp"><i class="fa-brands fa-whatsapp" style="color:#25D366;"></i> Share</a>
            <button class="ptb-btn ptb-soft" type="button" data-ptb="viewall" aria-pressed="false" title="Fit the full table to the screen"><i class="fas fa-expand"></i> View All</button>
            <button class="ptb-btn ptb-dark" type="button" data-ptb="download" title="Save as PDF"><i class="fas fa-download"></i> Download</button>
            <button class="ptb-btn ptb-solid" type="button" data-ptb="print"><i class="fas fa-print"></i> Print</button>
        </div>
    </div>

    <div class="sheet-date"><span>Date :</span> <span id="sheetDate" contenteditable="true" spellcheck="false" title="Click to edit the date"><?= date('d-m-Y') ?></span></div>

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
                        <span>UNIT TEST :- <?= sanitize($romanTest) ?></span>
                        <span>YEAR :- <?= sanitize($year) ?></span>
                    </div>
                </th>
            </tr>

            <!-- Row 3: Column Headers -->
            <tr>
                <th class="th-col-header" style="width:45px;">Roll<br>No</th>
                <th class="th-col-header" style="text-align:left;padding-left:8px;min-width:200px;">Name of the Students</th>
                <?php foreach ($subjects as $s): ?>
                    <th class="th-col-header" style="min-width:60px;"><?= sanitize($s['subject_name']) ?></th>
                <?php endforeach; ?>
                <th class="th-col-header" style="min-width:55px;">Total</th>
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
                <?php foreach ($subjects as $s): ?>
                    <th class="th-sub-max"><?= (float)$s['max_marks'] ?></th>
                <?php endforeach; ?>
                <th class="th-sub-max"><?= (float)$totalMax ?></th>
                <th class="th-sub-max"></th>
                <th class="th-sub-max"></th>
                <th class="th-sub-max"></th>
                <th class="th-sub-max"></th>
                <th class="th-sub-max col-parent-sign" style="<?= $showSign ? '' : 'display:none;' ?>"></th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $prevGender = null;
            foreach ($students as $stu): 
                $curGender = $stu['gender'] ?? '';
                if ($curGender !== '' && $curGender !== $prevGender):
                    $prevGender = $curGender;
            ?>
                <tr>
                    <td colspan="<?= $totalCols ?>" class="td-category td-category-gender">
                        <?= strtoupper($curGender) ?>S :
                    </td>
                </tr>
            <?php endif; ?>

            <tr>
                <td class="td-roll"><?= sanitize($stu['roll_no']) ?></td>
                <td class="td-name"><?= sanitize($stu['student_name']) ?></td>

                <!-- Subject Marks -->
                <?php foreach ($subjects as $s): 
                    $sName = $s['subject_name'];
                    $sm = $stu['subjects'][$sName] ?? null;
                    $val = '0';
                    if ($sm) {
                        if ($sm['is_absent']) $val = '-';
                        elseif ($sm['marks_obtained'] !== null) $val = (string)$sm['marks_obtained'];
                    }
                ?>
                    <td><?= sanitize($val) ?></td>
                <?php endforeach; ?>

                <td class="td-tot"><?= $stu['total_obtained'] ?></td>
                <td class="td-pct"><?= $stu['percentage'] !== null ? number_format($stu['percentage'], 2) : '-' ?></td>
                <td class="td-grade"><?= sanitize($stu['grade']) ?></td>
                <td style="text-align:left;padding-left:6px;"><?= sanitize($stu['remark']) ?></td>
                <td><?= sanitize($stu['attendance']) ?></td>
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
        </tbody>
    </table>
    </div>

    <div class="sign-row">
        <div>Teacher Signature</div>
        <div>Coordinator Signature</div>
        <div>Principal Signature</div>
    </div>

    <script src="../assets/js/print-toolbar.js"></script>
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


    </script>
</body>
</html>
