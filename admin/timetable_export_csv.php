<?php
/**
 * Timetable CSV Exporter
 * Hindustani Covenant Church High School & Jr. College, Solapur
 *
 * Exports official institutional examination timetable to CSV
 * with UTF-8 BOM, school hierarchy headers, and formatted slots.
 */

// CLI vs Web safety
if (php_sapi_name() !== 'cli') {
    require_once __DIR__ . '/../includes/functions.php';
    require_once __DIR__ . '/auth_check.php';
} else {
    require_once __DIR__ . '/../includes/functions.php';
}

$pdo = getDB();

// 1. Fetch requested timetable by ID, or latest published
$timetableId = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$timetable = null;

if ($timetableId > 0) {
    $stmt = $pdo->prepare("SELECT * FROM timetables WHERE id = ?");
    $stmt->execute([$timetableId]);
    $timetable = $stmt->fetch();
}

if (!$timetable) {
    $stmt = $pdo->query("SELECT * FROM timetables WHERE status = 'published' ORDER BY id DESC LIMIT 1");
    $timetable = $stmt->fetch();
}

if (!$timetable) {
    $stmt = $pdo->query("SELECT * FROM timetables ORDER BY id DESC LIMIT 1");
    $timetable = $stmt->fetch();
}

if (!$timetable) {
    http_response_code(404);
    die('Timetable not found for export.');
}

// 2. Fetch school name
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

$defaultAcademicYear = '2025-26';
try {
    if (function_exists('rcGetActiveAcademicYear')) {
        $defaultAcademicYear = rcGetActiveAcademicYear();
    }
} catch (Throwable $e) {}

$title = trim((string)($timetable['title'] ?? '')) ?: 'EXAMINATION TIME-TABLE (WRITTEN EXAM)';
$academicYear = trim((string)($timetable['academic_year'] ?? '')) ?: $defaultAcademicYear;
$standardsLabel = trim((string)($timetable['standards_label'] ?? '')) ?: ('Academic Year ' . $academicYear);
$notes = $timetable['notes'] ?? '';

// 3. Fetch columns
$stmt = $pdo->prepare("SELECT * FROM timetable_columns WHERE timetable_id = ? ORDER BY sort_order ASC, id ASC");
$stmt->execute([$timetable['id']]);
$columns = $stmt->fetchAll();

if (empty($columns)) {
    $columns = [
        ['id' => 1, 'column_key' => 'std_v', 'column_name' => 'STD V'],
        ['id' => 2, 'column_key' => 'std_vi_viii', 'column_name' => 'STD VI-VIII'],
        ['id' => 3, 'column_key' => 'std_ix_x', 'column_name' => 'STD IX & X'],
    ];
}

// 4. Fetch rows
$stmt = $pdo->prepare("SELECT * FROM timetable_rows WHERE timetable_id = ? ORDER BY sort_order ASC, exam_date ASC, id ASC");
$stmt->execute([$timetable['id']]);
$rows = $stmt->fetchAll();

// 5. Fetch cells
$stmt = $pdo->prepare("SELECT * FROM timetable_cells WHERE timetable_id = ?");
$stmt->execute([$timetable['id']]);
$allCells = $stmt->fetchAll();
$cellMap = [];
foreach ($allCells as $c) {
    $cellMap[$c['row_id']][$c['column_id']] = $c;
}

// Prepare CSV Filename
$cleanTitle = preg_replace('/[^A-Za-z0-9_-]+/', '_', $standardsLabel . '_' . $academicYear);
$filename = 'Timetable_' . $cleanTitle . '_' . date('Ymd_His') . '.csv';

// Output headers (only if not running purely in CLI with stdout redirect)
if (!headers_sent()) {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
}

$output = fopen('php://output', 'w');

// Formula-injection-safe CSV writer helper (neutralizes leading =, +, -, @, \t, \r)
$safeCell = static function ($val): string {
    if (function_exists('rcCsvSafeCell')) {
        return rcCsvSafeCell($val);
    }
    $str = (string)$val;
    if ($str !== '' && (preg_match('/^[\t\r\n ]*[=+\-@]/u', $str) || $str[0] === "\t" || $str[0] === "\r")) {
        return "'" . $str;
    }
    return $str;
};
$writeSafeCsvRow = static function ($handle, array $cells) use ($safeCell): void {
    fputcsv($handle, array_map($safeCell, $cells));
};

// Write UTF-8 BOM for Microsoft Excel compatibility
fwrite($output, "\xEF\xBB\xBF");

// Write Institutional Header
$writeSafeCsvRow($output, [strtoupper($schoolName)]);
$writeSafeCsvRow($output, [strtoupper($title)]);
$writeSafeCsvRow($output, [strtoupper($standardsLabel) . ' - ACADEMIC YEAR: ' . $academicYear]);
$writeSafeCsvRow($output, []); // Blank separator line

// Write Table Grid Column Headers
$headers = ['DAY', 'DATE', 'TIME'];
foreach ($columns as $col) {
    $headers[] = strtoupper($col['column_name']);
}
$writeSafeCsvRow($output, $headers);

// Write Table Data Rows
foreach ($rows as $row) {
    $dayName = strtoupper($row['day_name']);
    $formattedDate = date('d.m.Y', strtotime($row['exam_date']));
    $timeSlot = $row['time_slot'];

    $rowOutput = [$dayName, $formattedDate, $timeSlot];

    foreach ($columns as $col) {
        $cell = $cellMap[$row['id']][$col['id']] ?? null;
        $subject = trim($cell['subject_name'] ?? '');
        if ($subject === '' || $subject === '--------') {
            $rowOutput[] = '--------';
        } else {
            $val = strtoupper($subject);
            if (!empty($cell['room_or_note'])) {
                $val .= ' (' . $cell['room_or_note'] . ')';
            }
            $rowOutput[] = $val;
        }
    }

    $writeSafeCsvRow($output, $rowOutput);
}

// Write Instructions / Notes
$writeSafeCsvRow($output, []);
$writeSafeCsvRow($output, ['IMPORTANT INSTRUCTIONS / NOTICE:']);
if (!empty(trim($notes))) {
    $noteLines = preg_split('/\r\n|\r|\n/', trim($notes));
    $idx = 1;
    foreach ($noteLines as $line) {
        $cleanLine = trim($line);
        $cleanLine = preg_replace('/^(\d+[\.\)]|\-|\*)\s*/', '', $cleanLine);
        if ($cleanLine !== '') {
            $writeSafeCsvRow($output, [$idx . '. ' . $cleanLine]);
            $idx++;
        }
    }
} else {
    $writeSafeCsvRow($output, ['1. Students must be present in their respective classrooms 15 minutes before exam commencement.']);
    $writeSafeCsvRow($output, ['2. Borrowing of writing material / stationery is strictly prohibited in the examination hall.']);
    $writeSafeCsvRow($output, ['3. School uniform and valid Student Identity Card / Hall Ticket are compulsory.']);
    $writeSafeCsvRow($output, ['4. Students arriving more than 15 minutes late will not be permitted to take the examination.']);
}

// Write Institutional Signatures
$writeSafeCsvRow($output, []);
$writeSafeCsvRow($output, ['SIGNATURES:']);
$writeSafeCsvRow($output, ['EXAM IN-CHARGE', '', 'SUPERVISOR', '', 'HEADMISTRESS / PRINCIPAL']);

fclose($output);
exit;
