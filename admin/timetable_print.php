<?php
/**
 * Institutional Examination Timetable Print View
 * Hindustani Covenant Church High School & Jr. College, Solapur
 *
 * Exact institutional print template with crisp high-contrast laser printer styling,
 * multi-slot rowspan handling, centered dash placeholders, instruction card,
 * and three-column official institutional signature lines.
 */

// CLI vs Web safety
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/auth_check.php';
} else {
    require_once __DIR__ . '/../includes/functions.php';
}

$pdo = getDB();

// 1. Fetch requested timetable by ID, or latest published timetable
$timetableId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$timetable = null;

if ($timetableId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM timetables WHERE id = ?");
    $stmt->execute([$timetableId]);
    $timetable = $stmt->fetch();
}

if (!$timetable) {
    // Fallback: Latest published timetable
    $stmt = $pdo->query("SELECT * FROM timetables WHERE status = 'published' ORDER BY id DESC LIMIT 1");
    $timetable = $stmt->fetch();
}

if (!$timetable) {
    // Fallback: Any latest timetable
    $stmt = $pdo->query("SELECT * FROM timetables ORDER BY id DESC LIMIT 1");
    $timetable = $stmt->fetch();
}

// 2. Fetch school name from settings table with fallback
$settings = [];
if (function_exists('getAllSettings')) {
    try {
        $settings = getAllSettings();
    } catch (Throwable $e) {}
}

$schoolName = trim($settings['school_name'] ?? '');
if ($schoolName === '') {
    $schoolName = trim($timetable['school_name'] ?? '');
}
if ($schoolName === '') {
    $schoolName = 'HINDUSTANI COVENANT CHURCH HIGH SCHOOL & JR. COLLEGE, SOLAPUR.';
}

// Defaults for header elements
$title = $timetable['title'] ?? 'FIRST SEMESTER EXAMINATION TIME-TABLE (WRITTEN EXAM)';
$standardsLabel = $timetable['standards_label'] ?? 'STD V-X October 2026';
$academicYear = $timetable['academic_year'] ?? '2026-2027';
$examType = $timetable['exam_type'] ?? 'Semester Exam';
$notes = $timetable['notes'] ?? '';

// 3. Fetch columns for this timetable
$columns = [];
if ($timetable) {
    $stmt = $pdo->prepare("SELECT * FROM timetable_columns WHERE timetable_id = ? ORDER BY sort_order ASC, id ASC");
    $stmt->execute([$timetable['id']]);
    $columns = $stmt->fetchAll();
}

// Fallback default columns if none in DB
if (empty($columns)) {
    $columns = [
        ['id' => 1, 'column_key' => 'std_v', 'column_name' => 'STD V'],
        ['id' => 2, 'column_key' => 'std_vi_viii', 'column_name' => 'STD VI-VIII'],
        ['id' => 3, 'column_key' => 'std_ix_x', 'column_name' => 'STD IX & X'],
    ];
}

// 4. Fetch rows for this timetable
$rows = [];
if ($timetable) {
    $stmt = $pdo->prepare("SELECT * FROM timetable_rows WHERE timetable_id = ? ORDER BY sort_order ASC, exam_date ASC, id ASC");
    $stmt->execute([$timetable['id']]);
    $rows = $stmt->fetchAll();
}

// 5. Fetch cells mapped by row_id and column_id
$cellMap = [];
if ($timetable && !empty($rows)) {
    $stmt = $pdo->prepare("SELECT * FROM timetable_cells WHERE timetable_id = ?");
    $stmt->execute([$timetable['id']]);
    $allCells = $stmt->fetchAll();
    foreach ($allCells as $c) {
        $cellMap[$c['row_id']][$c['column_id']] = $c;
    }
}

// 6. Calculate Rowspan per Date
$dateCounts = [];
foreach ($rows as $r) {
    $d = $r['exam_date'];
    $dateCounts[$d] = ($dateCounts[$d] ?? 0) + 1;
}

$autoPrint = isset($_GET['autoprint']) && $_GET['autoprint'] === '1';

// 7. Dynamic Orientation & Density Detection
$columnCount = count($columns);
$forcedOrientation = isset($_GET['orientation']) ? strtolower(trim($_GET['orientation'])) : '';
if (in_array($forcedOrientation, ['portrait', 'landscape'], true)) {
    $isLandscape = ($forcedOrientation === 'landscape');
} else {
    // If 5 or more class columns (total 8+ columns), default to landscape
    $isLandscape = ($columnCount >= 5);
}

$layoutClass = $isLandscape ? 'layout-landscape' : 'layout-portrait';
$densityClass = ($columnCount >= 8) ? 'cols-many' : (($columnCount >= 5) ? 'cols-medium' : 'cols-normal');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($title) ?> — <?= htmlspecialchars($schoolName) ?></title>
    <link rel="icon" href="../assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="../assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/css/print-toolbar.css">
    <style>
        /* ==========================================================================
           INSTITUTIONAL PRINT VIEW — HCC HIGH SCHOOL & JR. COLLEGE
           Crisp High-Contrast Laser Printer Fidelity (1.5pt outer, 1pt inner)
           ========================================================================== */
        
        :root {
            --print-color: #000000;
            --print-bg: #ffffff;
            --border-outer: 1.5pt solid #000000;
            --border-inner: 1pt solid #000000;
            --font-stack: Arial, "Helvetica Neue", Helvetica, "Segoe UI", sans-serif;
        }

        *, *::before, *::after {
            box-sizing: border-box;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        body {
            margin: 0;
            padding: 0;
            background-color: #f1f5f9;
            color: var(--print-color);
            font-family: var(--font-stack);
            font-size: 13px;
            line-height: 1.35;
        }

        /* Screen Preview Wrapper */
        .print-viewport-container {
            width: 100%;
            overflow-x: auto;
            padding: 24px 16px 48px 16px;
            display: flex;
            justify-content: center;
            box-sizing: border-box;
        }

        .preview-wrapper {
            background: #ffffff;
            padding: 36px 40px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.12);
            border-radius: 4px;
            box-sizing: border-box;
            margin: 0 auto;
            width: 100%;
            max-width: 1020px;
            min-width: min-content;
            transition: all 0.2s ease-in-out;
        }

        /* Landscape Mode (Automatic when >= 5 standards or toggled) */
        .preview-wrapper.layout-landscape {
            max-width: 1440px;
            width: max-content;
            min-width: min(100%, 1180px);
            padding: 26px 30px;
        }

        /* Institutional Sheet Header */
        .sheet-header {
            text-align: center;
            margin-bottom: 18px;
            padding-bottom: 12px;
            border-bottom: 2pt solid #000000;
        }

        .school-name {
            font-size: 17pt;
            font-weight: 800;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 0 0 6px 0;
            color: #000000;
        }

        .exam-title {
            font-size: 13pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.8px;
            margin: 4px 0;
            color: #000000;
        }

        .standards-scope {
            font-size: 12pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin: 4px 0 0 0;
            color: #111111;
        }

        .meta-line {
            font-size: 9pt;
            font-weight: 600;
            margin-top: 6px;
            color: #333333;
            text-transform: uppercase;
        }

        /* Timetable Grid Table */
        .timetable-table {
            width: 100%;
            border-collapse: collapse;
            border: var(--border-outer);
            margin: 0 0 16px 0;
            page-break-inside: avoid;
            table-layout: auto;
        }

        .timetable-table th,
        .timetable-table td {
            border: var(--border-inner);
            padding: 7px 8px;
            color: #000000;
            vertical-align: middle;
        }

        .timetable-table th {
            background-color: #f2f2f2;
            font-size: 10pt;
            font-weight: 800;
            text-transform: uppercase;
            text-align: center;
            letter-spacing: 0.3px;
        }

        /* Dynamic, Content-Fitting Column Sizing (No rigid % waste) */
        .col-day {
            width: 1%;
            white-space: nowrap;
            text-align: center;
            font-weight: 700;
            font-size: 9.5pt;
            text-transform: uppercase;
            padding: 6px 8px;
        }

        .col-date {
            width: 1%;
            white-space: nowrap;
            text-align: center;
            font-weight: 700;
            font-size: 9.5pt;
            padding: 6px 8px;
        }

        .col-time {
            width: 1%;
            white-space: nowrap;
            text-align: center;
            font-weight: 600;
            font-size: 9pt;
            padding: 6px 10px;
        }

        .col-class {
            text-align: center;
            font-weight: 700;
            font-size: 9.5pt;
            text-transform: uppercase;
            padding: 6px 6px;
            min-width: 65px;
        }

        /* Landscape adjustments */
        .layout-landscape .timetable-table th,
        .layout-landscape .timetable-table td {
            padding: 6px 5px;
        }

        .layout-landscape .col-day,
        .layout-landscape .col-date {
            font-size: 8.5pt;
            padding: 6px 6px;
        }

        .layout-landscape .col-time {
            font-size: 8.5pt;
            padding: 6px 8px;
        }

        .layout-landscape .col-class {
            font-size: 8.5pt;
            padding: 6px 4px;
            min-width: 60px;
        }

        .layout-landscape .cell-subject {
            font-size: 8.5pt;
            word-break: break-word;
            line-height: 1.2;
        }

        /* High-Density Mode (8+ classes, e.g. STD I to STD X) */
        .cols-many .preview-wrapper,
        .preview-wrapper.cols-many {
            padding: 22px 24px;
        }

        .cols-many .timetable-table th,
        .cols-many .timetable-table td {
            padding: 5px 4px;
        }

        .cols-many .col-day {
            font-size: 8pt;
            padding: 5px 5px;
        }

        .cols-many .col-date {
            font-size: 8pt;
            padding: 5px 5px;
        }

        .cols-many .col-time {
            font-size: 7.5pt;
            padding: 5px 6px;
            letter-spacing: -0.2px;
        }

        .cols-many .col-class {
            font-size: 8pt;
            padding: 5px 3px;
            min-width: 52px;
        }

        .cols-many .cell-subject {
            font-size: 8pt;
            letter-spacing: -0.1px;
            word-break: break-word;
            line-height: 1.15;
        }

        .cols-many .cell-empty {
            font-size: 9pt;
            letter-spacing: 1px;
        }

        .cols-many .school-name {
            font-size: 15.5pt;
        }

        .cols-many .exam-title {
            font-size: 12pt;
        }

        .cols-many .standards-scope {
            font-size: 11pt;
        }

        /* Cell Content Styles */
        .cell-subject {
            text-align: center;
            font-size: 10pt;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.2px;
            line-height: 1.25;
        }

        .cell-empty {
            color: #000000;
            font-weight: 700;
            letter-spacing: 2px;
            font-family: monospace, Courier, sans-serif;
            font-size: 11pt;
        }

        .cell-note {
            font-size: 7.5pt;
            font-weight: normal;
            color: #444444;
            margin-top: 2px;
            text-transform: none;
        }

        /* Important Instructions Box */
        .instructions-box {
            border: 1pt solid #000000;
            padding: 10px 14px;
            margin-top: 14px;
            margin-bottom: 24px;
            background: #ffffff;
            page-break-inside: avoid;
        }

        .instructions-heading {
            font-weight: 800;
            font-size: 9.5pt;
            text-transform: uppercase;
            margin-bottom: 5px;
            letter-spacing: 0.3px;
            text-decoration: underline;
        }

        .instructions-list {
            margin: 0;
            padding-left: 20px;
            font-size: 8.5pt;
            line-height: 1.45;
        }

        .instructions-list li {
            margin-bottom: 3px;
        }

        /* Signature Lines Section */
        .signatures-container {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 48px;
            padding-top: 10px;
            page-break-inside: avoid;
        }

        .signature-cell {
            flex: 1;
            text-align: center;
            padding: 0 10px;
        }

        .signature-line {
            width: 80%;
            margin: 0 auto 8px auto;
            border-top: 1pt solid #000000;
        }

        .signature-title {
            font-weight: 800;
            font-size: 9.5pt;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        /* Screen Topbar Styling */
        .ptb-wrap {
            position: sticky;
            top: 0;
            z-index: 999;
            background: #ffffff;
            border-bottom: 1.5px solid #cbd5e1;
            padding: 10px 24px;
            box-shadow: 0 2px 10px rgba(0, 0, 0, 0.05);
        }

        .ptb-bar {
            max-width: 1440px;
            margin: 0 auto;
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }

        .ptb-left {
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .ptb-badge {
            background: #1a237e;
            color: #ffffff;
            font-size: 11px;
            font-weight: 700;
            padding: 4px 10px;
            border-radius: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }

        .ptb-right {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .ptb-btn {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 7px 14px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            border: 1px solid transparent;
            transition: all 0.15s ease-in-out;
        }

        .ptb-btn-primary {
            background: #1a237e;
            color: #ffffff;
        }
        .ptb-btn-primary:hover {
            background: #283593;
            color: #ffffff;
        }

        .ptb-btn-secondary {
            background: #ffffff;
            border-color: #cbd5e1;
            color: #334155;
        }
        .ptb-btn-secondary:hover {
            border-color: #1a237e;
            color: #1a237e;
        }

        .ptb-btn-success {
            background: #0f766e;
            color: #ffffff;
        }
        .ptb-btn-success:hover {
            background: #115e59;
            color: #ffffff;
        }

        /* ==========================================================================
           PRINT STYLING (Auto Portrait/Landscape, High Contrast Laser Optimization)
           ========================================================================== */
        @media print {
            @page {
                size: A4 <?= $isLandscape ? 'landscape' : 'portrait' ?>;
                margin: <?= $isLandscape ? '6mm 8mm 6mm 8mm' : '10mm 12mm 10mm 12mm' ?>;
            }

            html, body {
                background: #ffffff !important;
                margin: 0 !important;
                padding: 0 !important;
                font-size: 11px;
                color: #000000 !important;
                width: 100% !important;
            }

            .ptb-wrap, .no-print {
                display: none !important;
            }

            .print-viewport-container {
                display: block !important;
                padding: 0 !important;
                margin: 0 !important;
                width: 100% !important;
            }

            .preview-wrapper {
                max-width: 100% !important;
                width: 100% !important;
                min-width: 100% !important;
                margin: 0 !important;
                padding: 0 !important;
                box-shadow: none !important;
                border-radius: 0 !important;
                border: none !important;
            }

            .sheet-header {
                margin-bottom: 10px;
                padding-bottom: 6px;
                border-bottom: 2pt solid #000000;
            }

            .school-name {
                font-size: 14pt;
                margin-bottom: 3px;
            }

            .exam-title {
                font-size: 11pt;
                margin: 2px 0;
            }

            .standards-scope {
                font-size: 10pt;
                margin: 2px 0 0 0;
            }

            .timetable-table {
                width: 100% !important;
                border: 1.5pt solid #000000 !important;
                margin-bottom: 10px !important;
                page-break-inside: avoid;
                table-layout: auto !important;
            }

            .timetable-table th,
            .timetable-table td {
                border: 1pt solid #000000 !important;
                color: #000000 !important;
                padding: 4px 4px !important;
            }

            .timetable-table th {
                background-color: #f2f2f2 !important;
                font-size: 8.5pt !important;
            }

            .cell-subject {
                font-size: 8.5pt !important;
            }

            .instructions-box {
                border: 1pt solid #000000 !important;
                padding: 6px 10px !important;
                margin-top: 8px !important;
                margin-bottom: 16px !important;
                page-break-inside: avoid;
            }

            .signatures-container {
                margin-top: 32px !important;
                page-break-inside: avoid;
            }

            .signature-line {
                border-top: 1pt solid #000000 !important;
            }
        }
    </style>
    <style id="printOrientationStyle">
        @media print {
            @page {
                size: A4 <?= $isLandscape ? 'landscape' : 'portrait' ?>;
                margin: <?= $isLandscape ? '6mm 8mm 6mm 8mm' : '10mm 12mm 10mm 12mm' ?>;
            }
        }
    </style>
</head>
<body>

<!-- Floating Screen Topbar -->
<header class="ptb-wrap no-print">
    <div class="ptb-bar">
        <div class="ptb-left">
            <span class="ptb-badge"><i class="fa-solid fa-calendar-days"></i> Timetable Print Engine</span>
            <span style="font-weight: 600; font-size: 13px; color: #475569;">
                <?= htmlspecialchars($standardsLabel) ?>
            </span>
            <span style="font-size: 11px; color: #64748b; background: #f1f5f9; padding: 2px 8px; border-radius: 999px;">
                <?= count($columns) ?> Standards &bull; <?= $isLandscape ? 'Landscape' : 'Portrait' ?>
            </span>
        </div>
        <div class="ptb-right">
            <!-- Orientation Toggle Buttons -->
            <div class="no-print" style="display:inline-flex; align-items:center; background:#f1f5f9; padding:2px; border-radius:6px; border:1px solid #cbd5e1;">
                <button type="button" class="ptb-btn <?= !$isLandscape ? 'ptb-btn-primary' : 'ptb-btn-secondary' ?>" id="btnPortrait" onclick="toggleLayout(false)" style="padding:4px 8px; font-size:11px; margin:0; border:none; border-radius:4px;" title="Switch to Portrait Mode">
                    <i class="fa-solid fa-file"></i> Portrait
                </button>
                <button type="button" class="ptb-btn <?= $isLandscape ? 'ptb-btn-primary' : 'ptb-btn-secondary' ?>" id="btnLandscape" onclick="toggleLayout(true)" style="padding:4px 8px; font-size:11px; margin:0; border:none; border-radius:4px;" title="Switch to Landscape Mode">
                    <i class="fa-solid fa-file" style="transform: rotate(90deg);"></i> Landscape
                </button>
            </div>

            <a href="timetable.php" class="ptb-btn ptb-btn-secondary">
                <i class="fa-solid fa-arrow-left"></i> Back to Timetables
            </a>
            <a href="timetable_export_csv.php?id=<?= (int)($timetable['id'] ?? 0) ?>" class="ptb-btn ptb-btn-success">
                <i class="fa-solid fa-file-csv"></i> Export CSV
            </a>
            <button onclick="window.print()" class="ptb-btn ptb-btn-primary">
                <i class="fa-solid fa-print"></i> Print Document
            </button>
        </div>
    </div>
</header>

<!-- Main Printable Sheet Container with Horizontal Centering -->
<div class="print-viewport-container">
    <main class="preview-wrapper <?= $layoutClass ?> <?= $densityClass ?>" id="printableDoc">

    <!-- Document Header -->
    <header class="sheet-header">
        <h1 class="school-name"><?= htmlspecialchars(strtoupper($schoolName)) ?></h1>
        <h2 class="exam-title"><?= htmlspecialchars(strtoupper($title)) ?></h2>
        <h3 class="standards-scope"><?= htmlspecialchars(strtoupper($standardsLabel)) ?></h3>
        <div class="meta-line">Academic Year: <?= htmlspecialchars($academicYear) ?> &bull; <?= htmlspecialchars(strtoupper($examType)) ?></div>
    </header>

    <!-- Timetable Grid Table -->
    <table class="timetable-table" aria-label="Examination Schedule">
        <thead>
            <tr>
                <th class="col-day" scope="col">DAY</th>
                <th class="col-date" scope="col">DATE</th>
                <th class="col-time" scope="col">TIME</th>
                <?php foreach ($columns as $col): ?>
                    <th class="col-class" scope="col"><?= htmlspecialchars(strtoupper($col['column_name'])) ?></th>
                <?php endforeach; ?>
            </tr>
        </thead>
        <tbody>
            <?php
            $renderedDates = [];
            foreach ($rows as $rowIndex => $row):
                $examDate = $row['exam_date'];
                $isFirstSlotOfDate = !isset($renderedDates[$examDate]);
                $slotCount = $dateCounts[$examDate] ?? 1;

                // Format date cleanly as DD.MM.YYYY
                $formattedDate = date('d.m.Y', strtotime($examDate));
                $dayName = strtoupper($row['day_name']);
                $timeSlot = $row['time_slot'];
            ?>
            <tr>
                <?php if ($isFirstSlotOfDate): ?>
                    <?php $renderedDates[$examDate] = true; ?>
                    <td rowspan="<?= $slotCount ?>" class="col-day">
                        <?= htmlspecialchars($dayName) ?>
                    </td>
                    <td rowspan="<?= $slotCount ?>" class="col-date">
                        <?= htmlspecialchars($formattedDate) ?>
                    </td>
                <?php endif; ?>

                <td class="col-time">
                    <?= htmlspecialchars($timeSlot) ?>
                </td>

                <?php foreach ($columns as $col):
                    $colId = $col['id'];
                    $cell = $cellMap[$row['id']][$colId] ?? null;
                    $subject = trim($cell['subject_name'] ?? '');
                    $isDashed = ($subject === '' || $subject === '--------');
                ?>
                    <td class="cell-subject <?= $isDashed ? 'cell-empty' : '' ?>">
                        <?php if ($isDashed): ?>
                            --------
                        <?php else: ?>
                            <strong><?= htmlspecialchars(strtoupper($subject)) ?></strong>
                            <?php if (!empty($cell['room_or_note'])): ?>
                                <div class="cell-note"><?= htmlspecialchars($cell['room_or_note']) ?></div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>

            <?php if (empty($rows)): ?>
            <tr>
                <td colspan="<?= 3 + count($columns) ?>" style="text-align: center; padding: 24px; color: #64748b;">
                    No examination schedule records found for this timetable.
                </td>
            </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <!-- Important Instructions Box -->
    <section class="instructions-box" aria-label="Important Instructions">
        <div class="instructions-heading">Important Instructions / Notice:</div>
        <ol class="instructions-list">
            <?php
            if (!empty(trim($notes))) {
                // Split custom instructions by newline
                $noteLines = preg_split('/\r\n|\r|\n/', trim($notes));
                foreach ($noteLines as $line) {
                    $cleanLine = trim($line);
                    // Remove leading numbers e.g. "1. " or "- " if already formatted
                    $cleanLine = preg_replace('/^(\d+[\.\)]|\-|\*)\s*/', '', $cleanLine);
                    if ($cleanLine !== '') {
                        echo '<li>' . htmlspecialchars($cleanLine) . '</li>';
                    }
                }
            } else {
                // Institutional standard instructions fallback
                echo '<li>Students must be present in their respective classrooms 15 minutes before exam commencement.</li>';
                echo '<li>Borrowing of writing material / stationery is strictly prohibited in the examination hall.</li>';
                echo '<li>School uniform and valid Student Identity Card / Hall Ticket are compulsory.</li>';
                echo '<li>Students arriving more than 15 minutes late will not be permitted to take the examination.</li>';
            }
            ?>
        </ol>
    </section>

    <!-- Institutional Signature Lines -->
    <footer class="signatures-container">
        <div class="signature-cell">
            <div class="signature-line"></div>
            <div class="signature-title">EXAM IN-CHARGE</div>
        </div>
        <div class="signature-cell">
            <div class="signature-line"></div>
            <div class="signature-title">SUPERVISOR</div>
        </div>
        <div class="signature-cell">
            <div class="signature-line"></div>
            <div class="signature-title">HEADMISTRESS / PRINCIPAL</div>
        </div>
    </footer>

</main>
</div>

<script>
function toggleLayout(landscape) {
    const wrapper = document.getElementById('printableDoc');
    const btnP = document.getElementById('btnPortrait');
    const btnL = document.getElementById('btnLandscape');
    const dynamicStyle = document.getElementById('printOrientationStyle');
    
    if (landscape) {
        wrapper.classList.add('layout-landscape');
        wrapper.classList.remove('layout-portrait');
        if (btnL) { btnL.className = 'ptb-btn ptb-btn-primary'; }
        if (btnP) { btnP.className = 'ptb-btn ptb-btn-secondary'; }
        if (dynamicStyle) {
            dynamicStyle.innerHTML = '@media print { @page { size: A4 landscape; margin: 6mm 8mm 6mm 8mm; } }';
        }
    } else {
        wrapper.classList.remove('layout-landscape');
        wrapper.classList.add('layout-portrait');
        if (btnP) { btnP.className = 'ptb-btn ptb-btn-primary'; }
        if (btnL) { btnL.className = 'ptb-btn ptb-btn-secondary'; }
        if (dynamicStyle) {
            dynamicStyle.innerHTML = '@media print { @page { size: A4 portrait; margin: 10mm 12mm 10mm 12mm; } }';
        }
    }
}
</script>

<?php if ($autoPrint): ?>
<script>
    window.addEventListener('DOMContentLoaded', function() {
        setTimeout(function() {
            window.print();
        }, 500);
    });
</script>
<?php endif; ?>

</body>
</html>
