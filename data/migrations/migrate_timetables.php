<?php
/**
 * Timetable Migration Runner
 * Database Migration Script for Hindustani Covenant Church High School (HCC) Timetable Feature
 */

declare(strict_types=1);

// Set CLI execution environment so config/database.php behaves properly
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/data/migrations/migrate_timetables.php';

require_once __DIR__ . '/../../config/database.php';

echo "====================================================================\n";
echo "HCC Timetable Database Migration\n";
echo "====================================================================\n";

try {
    $pdo = getDB();
    $dbName = $pdo->query('SELECT DATABASE()')->fetchColumn();
    echo "Connected to database: {$dbName}\n\n";

    $sqlFile = __DIR__ . '/create_timetable_tables.sql';
    if (!file_exists($sqlFile)) {
        throw new RuntimeException("SQL migration file not found at: {$sqlFile}");
    }

    echo "Reading SQL migration file: {$sqlFile}...\n";
    $sqlContent = file_get_contents($sqlFile);
    if ($sqlContent === false) {
        throw new RuntimeException("Failed to read SQL migration file.");
    }

    // Split SQL into statements while ignoring comments and handling delimiters
    // Note: PDO exec can execute multi-query or separate queries
    echo "Executing migration SQL statements...\n";
    $pdo->exec($sqlContent);
    echo "Migration execution completed successfully.\n\n";

    // =========================================================================
    // VERIFICATION
    // =========================================================================
    echo "====================================================================\n";
    echo "Starting Verification\n";
    echo "====================================================================\n";

    $expectedTables = [
        'timetables',
        'timetable_columns',
        'timetable_rows',
        'timetable_cells',
        'timetable_presets',
    ];

    $existingTables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

    echo "1. Checking table existence:\n";
    foreach ($expectedTables as $table) {
        if (in_array($table, $existingTables, true)) {
            echo "   [PASS] Table `{$table}` exists.\n";
        } else {
            throw new RuntimeException("Table `{$table}` is missing from database!");
        }
    }

    echo "\n2. Verifying `timetable_presets` records:\n";
    $presetCounts = $pdo->query("
        SELECT category, COUNT(*) as cnt
        FROM timetable_presets
        GROUP BY category
    ")->fetchAll(PDO::FETCH_KEY_PAIR);

    print_r($presetCounts);
    if (($presetCounts['time_slot'] ?? 0) < 5) {
        throw new RuntimeException("Expected at least 5 time_slot presets, found: " . ($presetCounts['time_slot'] ?? 0));
    }
    if (($presetCounts['standard'] ?? 0) < 5) {
        throw new RuntimeException("Expected at least 5 standard presets, found: " . ($presetCounts['standard'] ?? 0));
    }
    if (($presetCounts['subject'] ?? 0) < 17) {
        throw new RuntimeException("Expected at least 17 subject presets, found: " . ($presetCounts['subject'] ?? 0));
    }
    echo "   [PASS] `timetable_presets` contains all required categories and items.\n";

    echo "\n3. Verifying Timetable #1 Header:\n";
    $stmt = $pdo->prepare("SELECT * FROM timetables WHERE id = 1");
    $stmt->execute();
    $timetable = $stmt->fetch();
    if (!$timetable) {
        throw new RuntimeException("Timetable #1 not found!");
    }
    echo "   Title:           {$timetable['title']}\n";
    echo "   School:          {$timetable['school_name']}\n";
    echo "   Standards Label: {$timetable['standards_label']}\n";
    echo "   Academic Year:   {$timetable['academic_year']}\n";
    echo "   Exam Type:       {$timetable['exam_type']}\n";
    echo "   Status:          {$timetable['status']}\n";

    echo "\n4. Verifying Timetable #1 Columns:\n";
    $stmt = $pdo->prepare("SELECT column_key, column_name, sort_order FROM timetable_columns WHERE timetable_id = 1 ORDER BY sort_order");
    $stmt->execute();
    $columns = $stmt->fetchAll();
    foreach ($columns as $col) {
        echo "   Col {$col['sort_order']}: key={$col['column_key']} name={$col['column_name']}\n";
    }
    if (count($columns) !== 3) {
        throw new RuntimeException("Expected 3 columns for timetable #1, found: " . count($columns));
    }
    echo "   [PASS] 3 columns verified.\n";

    echo "\n5. Verifying Timetable #1 Rows:\n";
    $stmt = $pdo->prepare("SELECT id, exam_date, day_name, time_slot, sort_order FROM timetable_rows WHERE timetable_id = 1 ORDER BY sort_order");
    $stmt->execute();
    $rows = $stmt->fetchAll();
    echo "   Total rows found: " . count($rows) . "\n";
    if (count($rows) !== 12) {
        throw new RuntimeException("Expected 12 rows for timetable #1, found: " . count($rows));
    }
    echo "   [PASS] 12 rows verified.\n";

    echo "\n6. Verifying Timetable #1 Cells Matrix (12 rows x 3 columns = 36 cells):\n";
    $stmt = $pdo->prepare("
        SELECT r.sort_order AS row_num, r.exam_date, r.day_name, r.time_slot,
               c.column_key, tc.subject_name
        FROM timetable_cells tc
        JOIN timetable_rows r ON tc.row_id = r.id
        JOIN timetable_columns c ON tc.column_id = c.id
        WHERE tc.timetable_id = 1
        ORDER BY r.sort_order, c.sort_order
    ");
    $stmt->execute();
    $cells = $stmt->fetchAll();
    echo "   Total cells mapped: " . count($cells) . "\n";
    if (count($cells) !== 36) {
        throw new RuntimeException("Expected 36 cells for timetable #1, found: " . count($cells));
    }

    // Print Grid matrix preview
    $matrix = [];
    foreach ($cells as $cell) {
        $key = sprintf("%2d | %s | %-9s | %-20s", $cell['row_num'], $cell['exam_date'], $cell['day_name'], $cell['time_slot']);
        $matrix[$key][$cell['column_key']] = $cell['subject_name'];
    }

    printf("%-46s | %-15s | %-18s | %-15s\n", "Row | Date | Day | Time Slot", "STD V", "STD VI-VIII", "STD IX & X");
    echo str_repeat("-", 100) . "\n";
    foreach ($matrix as $header => $cols) {
        printf("%-46s | %-15s | %-18s | %-15s\n", $header, $cols['std_v'] ?? '', $cols['std_vi_viii'] ?? '', $cols['std_ix_x'] ?? '');
    }

    echo "\n[SUCCESS] Timetable migration and data seeding verified completely!\n";
    echo "====================================================================\n";

} catch (Throwable $e) {
    echo "\n[ERROR] Migration failed: " . $e->getMessage() . "\n";
    echo "File: " . $e->getFile() . " Line: " . $e->getLine() . "\n";
    echo $e->getTraceAsString() . "\n";
    exit(1);
}
