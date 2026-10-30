<?php
/**
 * Automated Verification Suite for HCC Timetable Feature
 *
 * Verifies 100% of Timetable Schema, Seed Data, Multi-Slot Rowspans,
 * Dynamic Column Expansion, Transaction Rollbacks, and Foreign Key Cascades.
 *
 * File: automation/test_timetable_feature.php
 * Run via: php automation/test_timetable_feature.php
 */

declare(strict_types=1);

// Configure CLI environment for config/database.php
$_SERVER['HTTP_HOST'] = 'localhost';
$_SERVER['REQUEST_METHOD'] = 'GET';
$_SERVER['SCRIPT_NAME'] = '/automation/test_timetable_feature.php';

require_once __DIR__ . '/../config/database.php';

// ANSI terminal color codes
const COLOR_RESET   = "\033[0m";
const COLOR_GREEN   = "\033[1;32m";
const COLOR_RED     = "\033[1;31m";
const COLOR_YELLOW  = "\033[1;33m";
const COLOR_CYAN    = "\033[1;36m";
const COLOR_BOLD    = "\033[1m";
const COLOR_GRAY    = "\033[0;90m";

class TimetableFeatureTester
{
    private PDO $pdo;
    private int $totalTests = 0;
    private int $passedTests = 0;
    private int $failedTests = 0;
    private array $failures = [];

    public function __construct()
    {
        $this->pdo = getDB();
        $this->bootstrapDatabaseIfEmpty();
    }

    private function bootstrapDatabaseIfEmpty(): void
    {
        $check = $this->pdo->query("SHOW TABLES LIKE 'timetables'")->fetchAll();
        if (empty($check)) {
            echo COLOR_YELLOW . "Notice: Timetable tables not found. Initializing schema from migration...\n" . COLOR_RESET;
            $migrationSqlFile = __DIR__ . '/../data/migrations/create_timetable_tables.sql';
            if (file_exists($migrationSqlFile)) {
                $sql = file_get_contents($migrationSqlFile);
                $this->pdo->exec($sql);
                echo COLOR_GREEN . "Schema and seed data applied successfully.\n" . COLOR_RESET;
            } else {
                throw new RuntimeException("Migration file not found: {$migrationSqlFile}");
            }
        }
    }

    public function assert(bool $condition, string $testName, string $details = ''): void
    {
        $this->totalTests++;
        if ($condition) {
            $this->passedTests++;
            echo "  " . COLOR_GREEN . "[PASS]" . COLOR_RESET . " " . $testName;
            if ($details !== '') {
                echo " " . COLOR_GRAY . "({$details})" . COLOR_RESET;
            }
            echo "\n";
        } else {
            $this->failedTests++;
            $this->failures[] = $testName . ($details ? ": {$details}" : '');
            echo "  " . COLOR_RED . "[FAIL]" . COLOR_RESET . " " . COLOR_BOLD . $testName . COLOR_RESET;
            if ($details !== '') {
                echo "\n         " . COLOR_RED . "Error: {$details}" . COLOR_RESET;
            }
            echo "\n";
        }
    }

    public function runAll(): bool
    {
        $startTime = microtime(true);

        echo "\n" . COLOR_BOLD . COLOR_CYAN . str_repeat('=', 78) . COLOR_RESET . "\n";
        echo COLOR_BOLD . COLOR_CYAN . "  HINDUSTANI COVENANT CHURCH HIGH SCHOOL & JR. COLLEGE, SOLAPUR\n";
        echo "  TIMETABLE FEATURE COMPREHENSIVE AUTOMATION TEST SUITE\n" . COLOR_RESET;
        echo COLOR_BOLD . COLOR_CYAN . str_repeat('=', 78) . COLOR_RESET . "\n\n";

        $this->testCategory1Schema();
        $this->testCategory2SeedData();
        $this->testCategory3MultiSlotRowspan();
        $this->testCategory4DynamicColumnExpansion();
        $this->testCategory5TransactionRollback();
        $this->testCategory6CascadeCleanup();
        $this->testCategory7PrintAndExportFidelity();
        $this->testCategory8PromptAndStatementGenerator();

        $elapsed = round(microtime(true) - $startTime, 3);

        echo "\n" . COLOR_BOLD . COLOR_CYAN . str_repeat('-', 78) . COLOR_RESET . "\n";
        echo COLOR_BOLD . "TEST SUMMARY REPORT:\n" . COLOR_RESET;
        echo "  Total Tests:    " . COLOR_BOLD . $this->totalTests . COLOR_RESET . "\n";
        echo "  Passed Tests:   " . COLOR_GREEN . COLOR_BOLD . $this->passedTests . COLOR_RESET . "\n";
        echo "  Failed Tests:   " . ($this->failedTests > 0 ? COLOR_RED : COLOR_GREEN) . COLOR_BOLD . $this->failedTests . COLOR_RESET . "\n";
        echo "  Execution Time: " . $elapsed . "s\n";
        echo "  Success Rate:   " . ($this->totalTests > 0 ? round(($this->passedTests / $this->totalTests) * 100, 1) : 0) . "%\n";
        echo COLOR_BOLD . COLOR_CYAN . str_repeat('=', 78) . COLOR_RESET . "\n\n";

        if ($this->failedTests > 0) {
            echo COLOR_RED . COLOR_BOLD . "Failures Detected:\n" . COLOR_RESET;
            foreach ($this->failures as $i => $fail) {
                echo "  " . ($i + 1) . ". {$fail}\n";
            }
            echo "\n";
            return false;
        }

        echo COLOR_GREEN . COLOR_BOLD . ">>> ALL TIMETABLE TESTS PASSED WITH 100% SUCCESS RATE! <<<\n\n" . COLOR_RESET;
        return true;
    }

    // =========================================================================
    // Category 1: Schema verification
    // =========================================================================
    private function testCategory1Schema(): void
    {
        echo COLOR_BOLD . "Category 1: Schema Verification\n" . COLOR_RESET;

        $tables = [
            'timetables' => ['id', 'title', 'school_name', 'standards_label', 'academic_year', 'exam_type', 'status', 'grid_data_json'],
            'timetable_columns' => ['id', 'timetable_id', 'column_key', 'column_name', 'sort_order'],
            'timetable_rows' => ['id', 'timetable_id', 'exam_date', 'day_name', 'time_slot', 'sort_order'],
            'timetable_cells' => ['id', 'timetable_id', 'row_id', 'column_id', 'subject_name', 'room_or_note'],
            'timetable_presets' => ['id', 'category', 'preset_value', 'sort_order'],
        ];

        $existingTables = $this->pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);

        foreach ($tables as $table => $expectedCols) {
            $tableExists = in_array($table, $existingTables, true);
            $this->assert($tableExists, "Table `{$table}` exists in database");

            if ($tableExists) {
                $colsInDb = $this->pdo->query("DESCRIBE `{$table}`")->fetchAll(PDO::FETCH_COLUMN);
                foreach ($expectedCols as $col) {
                    $this->assert(
                        in_array($col, $colsInDb, true),
                        "Table `{$table}` contains expected column `{$col}`"
                    );
                }
            }
        }
        echo "\n";
    }

    // =========================================================================
    // Category 2: Seed data check
    // =========================================================================
    private function testCategory2SeedData(): void
    {
        echo COLOR_BOLD . "Category 2: Seed Data Check (October 2026 Timetable)\n" . COLOR_RESET;

        // 1. Timetable #1 existence
        $stmt = $this->pdo->prepare("SELECT * FROM timetables WHERE id = 1");
        $stmt->execute();
        $tt = $stmt->fetch();
        $this->assert(!empty($tt), "Timetable #1 exists in DB");
        if ($tt) {
            $this->assert(
                strpos($tt['title'], 'FIRST SEMESTER EXAMINATION') !== false,
                "Timetable #1 title is correct",
                $tt['title']
            );
            $this->assert(
                $tt['standards_label'] === 'STD V-X October 2026',
                "Timetable #1 scope matches 'STD V-X October 2026'",
                $tt['standards_label']
            );
            $this->assert(
                $tt['status'] === 'published',
                "Timetable #1 status is 'published'"
            );
        }

        // 2. 3 Columns: STD V, STD VI-VIII, STD IX & X
        $stmt = $this->pdo->prepare("SELECT * FROM timetable_columns WHERE timetable_id = 1 ORDER BY sort_order");
        $stmt->execute();
        $cols = $stmt->fetchAll();
        $this->assert(count($cols) === 3, "Timetable #1 has exactly 3 columns", "Found " . count($cols));

        $colNames = array_column($cols, 'column_name');
        $this->assert(in_array('STD V', $colNames, true), "Column 'STD V' exists");
        $this->assert(in_array('STD VI-VIII', $colNames, true), "Column 'STD VI-VIII' exists");
        $this->assert(in_array('STD IX & X', $colNames, true), "Column 'STD IX & X' exists");

        // 3. 12 Slot Rows
        $stmt = $this->pdo->prepare("SELECT * FROM timetable_rows WHERE timetable_id = 1 ORDER BY sort_order");
        $stmt->execute();
        $rows = $stmt->fetchAll();
        $this->assert(count($rows) === 12, "Timetable #1 has exactly 12 slot rows", "Found " . count($rows));

        // 4. Validate subjects (at least 15 distinct subjects mapped)
        $stmt = $this->pdo->prepare("
            SELECT DISTINCT subject_name
            FROM timetable_cells
            WHERE timetable_id = 1 AND subject_name != '--------'
        ");
        $stmt->execute();
        $distinctSubjects = $stmt->fetchAll(PDO::FETCH_COLUMN);
        $this->assert(
            count($distinctSubjects) >= 15,
            "Timetable #1 contains 15 distinct subjects (found " . count($distinctSubjects) . ")",
            implode(', ', $distinctSubjects)
        );

        // Verify key subjects from reference
        $expectedKeySubjects = ['ALGEBRA(M-1)', 'ENGLISH', 'MARATHI', 'HINDI / SANSKRIT', 'MATHS', 'GEOMETRY', 'SCIENCE-1', 'DRAWING'];
        foreach ($expectedKeySubjects as $s) {
            $this->assert(
                in_array($s, $distinctSubjects, true),
                "Key subject '{$s}' is present in Timetable #1 cells"
            );
        }

        // 5. Total Cells (12 rows * 3 columns = 36 cells)
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM timetable_cells WHERE timetable_id = 1");
        $stmt->execute();
        $cellCount = (int)$stmt->fetchColumn();
        $this->assert($cellCount === 36, "Timetable #1 grid matrix has 36 cells (12 rows x 3 columns)", "Found {$cellCount}");

        echo "\n";
    }

    // =========================================================================
    // Category 3: Multi-slot rowspan verification
    // =========================================================================
    private function testCategory3MultiSlotRowspan(): void
    {
        echo COLOR_BOLD . "Category 3: Multi-Slot Rowspan Verification\n" . COLOR_RESET;

        // Group rows by exam_date for timetable #1
        $stmt = $this->pdo->prepare("
            SELECT exam_date, COUNT(*) as slot_count
            FROM timetable_rows
            WHERE timetable_id = 1
            GROUP BY exam_date
            ORDER BY exam_date
        ");
        $stmt->execute();
        $dateSlots = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);

        // 2026-10-26 must have count = 2 slots
        $this->assert(
            ($dateSlots['2026-10-26'] ?? 0) === 2,
            "Date 2026-10-26 has count = 2 slots (rowspan=2)",
            "Actual: " . ($dateSlots['2026-10-26'] ?? 0)
        );

        // 2026-10-27 must have count = 2 slots
        $this->assert(
            ($dateSlots['2026-10-27'] ?? 0) === 2,
            "Date 2026-10-27 has count = 2 slots (rowspan=2)",
            "Actual: " . ($dateSlots['2026-10-27'] ?? 0)
        );

        // 2026-10-28 must have count = 2 slots
        $this->assert(
            ($dateSlots['2026-10-28'] ?? 0) === 2,
            "Date 2026-10-28 has count = 2 slots (rowspan=2)",
            "Actual: " . ($dateSlots['2026-10-28'] ?? 0)
        );

        // 2026-10-23 must have count = 1 slot
        $this->assert(
            ($dateSlots['2026-10-23'] ?? 0) === 1,
            "Date 2026-10-23 has count = 1 slot (single slot)",
            "Actual: " . ($dateSlots['2026-10-23'] ?? 0)
        );

        // Verify the HTML Rowspan rendering algorithm matches
        $stmt = $this->pdo->prepare("SELECT * FROM timetable_rows WHERE timetable_id = 1 ORDER BY sort_order");
        $stmt->execute();
        $rows = $stmt->fetchAll();

        $renderedDates = [];
        $dayDateCellsRendered = 0;
        $dayDateCellsSuppressed = 0;

        foreach ($rows as $r) {
            $d = $r['exam_date'];
            if (!isset($renderedDates[$d])) {
                $renderedDates[$d] = true;
                $dayDateCellsRendered++;
            } else {
                $dayDateCellsSuppressed++;
            }
        }

        $this->assert(
            $dayDateCellsRendered === 9,
            "Distinct examination dates rendered = 9 (9 unique exam dates)",
            "Rendered: {$dayDateCellsRendered}"
        );
        $this->assert(
            $dayDateCellsSuppressed === 3,
            "Multi-slot duplicate Day/Date cells suppressed = 3 (12 total rows - 9 dates)",
            "Suppressed: {$dayDateCellsSuppressed}"
        );

        echo "\n";
    }

    // =========================================================================
    // Category 4: Dynamic column expansion
    // =========================================================================
    private function testCategory4DynamicColumnExpansion(): void
    {
        echo COLOR_BOLD . "Category 4: Dynamic Column Expansion Test\n" . COLOR_RESET;

        // Create isolated test timetable
        $this->pdo->exec("
            INSERT INTO timetables (title, school_name, standards_label, academic_year, status)
            VALUES ('TEST DYNAMIC COLUMN EXPANSION', 'HCC HIGH SCHOOL', 'STD V-XI', '2026-2027', 'draft')
        ");
        $testTtId = (int)$this->pdo->lastInsertId();

        try {
            // Add initial 3 columns
            $colStmt = $this->pdo->prepare("
                INSERT INTO timetable_columns (timetable_id, column_key, column_name, sort_order)
                VALUES (?, ?, ?, ?)
            ");
            $colStmt->execute([$testTtId, 'col_1', 'STD V', 1]);
            $col1Id = (int)$this->pdo->lastInsertId();
            $colStmt->execute([$testTtId, 'col_2', 'STD VI-VIII', 2]);
            $col2Id = (int)$this->pdo->lastInsertId();
            $colStmt->execute([$testTtId, 'col_3', 'STD IX & X', 3]);
            $col3Id = (int)$this->pdo->lastInsertId();

            // Add 2 rows
            $rowStmt = $this->pdo->prepare("
                INSERT INTO timetable_rows (timetable_id, exam_date, day_name, time_slot, sort_order)
                VALUES (?, ?, ?, ?, ?)
            ");
            $rowStmt->execute([$testTtId, '2026-11-10', 'Tuesday', '8.00 AM - 10.15 AM', 1]);
            $row1Id = (int)$this->pdo->lastInsertId();
            $rowStmt->execute([$testTtId, '2026-11-11', 'Wednesday', '8.00 AM - 10.15 AM', 2]);
            $row2Id = (int)$this->pdo->lastInsertId();

            // Add initial cells (2 rows x 3 columns = 6 cells)
            $cellStmt = $this->pdo->prepare("
                INSERT INTO timetable_cells (timetable_id, row_id, column_id, subject_name)
                VALUES (?, ?, ?, ?)
            ");
            foreach ([$row1Id, $row2Id] as $rId) {
                foreach ([$col1Id, $col2Id, $col3Id] as $cId) {
                    $cellStmt->execute([$testTtId, $rId, $cId, 'INITIAL_SUB']);
                }
            }

            // Assert initial count
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM timetable_columns WHERE timetable_id = ?");
            $stmt->execute([$testTtId]);
            $this->assert((int)$stmt->fetchColumn() === 3, "Initial column count is 3");

            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM timetable_cells WHERE timetable_id = ?");
            $stmt->execute([$testTtId]);
            $this->assert((int)$stmt->fetchColumn() === 6, "Initial cell count is 6 (2 rows x 3 cols)");

            // --- NOW DYNAMICALLY EXPAND BY ADDING A 4TH COLUMN ---
            $colStmt->execute([$testTtId, 'col_4', 'STD XI', 4]);
            $col4Id = (int)$this->pdo->lastInsertId();

            // Populate cells for the new column across all existing rows
            $cellStmt->execute([$testTtId, $row1Id, $col4Id, 'PHYSICS']);
            $cellStmt->execute([$testTtId, $row2Id, $col4Id, 'CHEMISTRY']);

            // Assert expanded column count
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM timetable_columns WHERE timetable_id = ?");
            $stmt->execute([$testTtId]);
            $newColCount = (int)$stmt->fetchColumn();
            $this->assert($newColCount === 4, "Expanded column count equals 4 (STD XI added)", "Count: {$newColCount}");

            // Assert expanded cell count (2 rows x 4 cols = 8 cells)
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM timetable_cells WHERE timetable_id = ?");
            $stmt->execute([$testTtId]);
            $newCellCount = (int)$stmt->fetchColumn();
            $this->assert($newCellCount === 8, "Expanded grid matrix has 8 cells (2 rows x 4 cols)", "Count: {$newCellCount}");

            // Verify subjects in expanded column
            $stmt = $this->pdo->prepare("
                SELECT subject_name FROM timetable_cells
                WHERE timetable_id = ? AND column_id = ?
                ORDER BY row_id
            ");
            $stmt->execute([$testTtId, $col4Id]);
            $expandedSubjects = $stmt->fetchAll(PDO::FETCH_COLUMN);
            $this->assert(
                $expandedSubjects === ['PHYSICS', 'CHEMISTRY'],
                "Expanded column correctly contains new row subjects ['PHYSICS', 'CHEMISTRY']",
                implode(', ', $expandedSubjects)
            );

        } finally {
            // Clean up test timetable
            $this->pdo->prepare("DELETE FROM timetables WHERE id = ?")->execute([$testTtId]);
        }

        echo "\n";
    }

    // =========================================================================
    // Category 5: Transaction rollback test
    // =========================================================================
    private function testCategory5TransactionRollback(): void
    {
        echo COLOR_BOLD . "Category 5: Transaction Rollback Integrity Test\n" . COLOR_RESET;

        // Create isolated test timetable
        $this->pdo->exec("
            INSERT INTO timetables (title, school_name, standards_label, academic_year, status)
            VALUES ('TEST TRANSACTION TIMETABLE', 'HCC HIGH SCHOOL', 'STD V', '2026-2027', 'draft')
        ");
        $ttId = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare("
            INSERT INTO timetable_columns (timetable_id, column_key, column_name, sort_order)
            VALUES (?, 'col_a', 'STD V', 1)
        ")->execute([$ttId]);
        $colId = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare("
            INSERT INTO timetable_rows (timetable_id, exam_date, day_name, time_slot, sort_order)
            VALUES (?, '2026-11-20', 'Friday', '8.00 AM - 10.00 AM', 1)
        ")->execute([$ttId]);
        $rowId = (int)$this->pdo->lastInsertId();

        $this->pdo->prepare("
            INSERT INTO timetable_cells (timetable_id, row_id, column_id, subject_name)
            VALUES (?, ?, ?, 'INITIAL_VALUE')
        ")->execute([$ttId, $rowId, $colId]);

        // Capture initial cell state
        $stmt = $this->pdo->prepare("SELECT subject_name FROM timetable_cells WHERE timetable_id = ? AND row_id = ? AND column_id = ?");
        $stmt->execute([$ttId, $rowId, $colId]);
        $initialSubject = $stmt->fetchColumn();

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM timetable_cells WHERE timetable_id = ?");
        $stmt->execute([$ttId]);
        $initialCount = (int)$stmt->fetchColumn();

        // Begin transaction and inject corruption
        $rollbackCaught = false;
        $this->pdo->beginTransaction();
        try {
            // 1. Valid operation inside transaction
            $this->pdo->prepare("
                UPDATE timetable_cells SET subject_name = 'TRANSACTION_MODIFIED'
                WHERE timetable_id = ? AND row_id = ? AND column_id = ?
            ")->execute([$ttId, $rowId, $colId]);

            // 2. Corrupt operation: violates NOT NULL or foreign key constraint
            // row_id 999999 does not exist in timetable_rows -> foreign key violation!
            $this->pdo->prepare("
                INSERT INTO timetable_cells (timetable_id, row_id, column_id, subject_name)
                VALUES (?, 999999, ?, 'CORRUPT_DATA')
            ")->execute([$ttId, $colId]);

            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            $rollbackCaught = true;
        }

        $this->assert($rollbackCaught, "Transaction caught foreign key error and triggered rollback");

        // Verify state is completely preserved
        $stmt = $this->pdo->prepare("SELECT subject_name FROM timetable_cells WHERE timetable_id = ? AND row_id = ? AND column_id = ?");
        $stmt->execute([$ttId, $rowId, $colId]);
        $currentSubject = $stmt->fetchColumn();

        $this->assert(
            $currentSubject === $initialSubject,
            "Cell subject reverted back to original ('{$initialSubject}') without corrupted state",
            "Current: {$currentSubject}"
        );

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM timetable_cells WHERE timetable_id = ?");
        $stmt->execute([$ttId]);
        $currentCount = (int)$stmt->fetchColumn();

        $this->assert(
            $currentCount === $initialCount,
            "Total cell count unchanged after rollback ({$initialCount})",
            "Current: {$currentCount}"
        );

        // Clean up
        $this->pdo->prepare("DELETE FROM timetables WHERE id = ?")->execute([$ttId]);

        echo "\n";
    }

    // =========================================================================
    // Category 6: Cascade cleanup test
    // =========================================================================
    private function testCategory6CascadeCleanup(): void
    {
        echo COLOR_BOLD . "Category 6: Cascade Cleanup Test (ON DELETE CASCADE)\n" . COLOR_RESET;

        // Create timetable with columns, rows, and cells
        $this->pdo->exec("
            INSERT INTO timetables (title, school_name, standards_label, academic_year, status)
            VALUES ('CASCADE TEST TIMETABLE', 'HCC HIGH SCHOOL', 'STD IX', '2026-2027', 'draft')
        ");
        $ttId = (int)$this->pdo->lastInsertId();

        // Insert 2 columns
        $colStmt = $this->pdo->prepare("
            INSERT INTO timetable_columns (timetable_id, column_key, column_name, sort_order)
            VALUES (?, ?, ?, ?)
        ");
        $colStmt->execute([$ttId, 'c1', 'COL 1', 1]);
        $c1 = (int)$this->pdo->lastInsertId();
        $colStmt->execute([$ttId, 'c2', 'COL 2', 2]);
        $c2 = (int)$this->pdo->lastInsertId();

        // Insert 2 rows
        $rowStmt = $this->pdo->prepare("
            INSERT INTO timetable_rows (timetable_id, exam_date, day_name, time_slot, sort_order)
            VALUES (?, ?, ?, ?, ?)
        ");
        $rowStmt->execute([$ttId, '2026-12-01', 'Tuesday', '9.00 AM - 11.00 AM', 1]);
        $r1 = (int)$this->pdo->lastInsertId();
        $rowStmt->execute([$ttId, '2026-12-02', 'Wednesday', '9.00 AM - 11.00 AM', 2]);
        $r2 = (int)$this->pdo->lastInsertId();

        // Insert 4 cells
        $cellStmt = $this->pdo->prepare("
            INSERT INTO timetable_cells (timetable_id, row_id, column_id, subject_name)
            VALUES (?, ?, ?, 'TEST')
        ");
        $cellStmt->execute([$ttId, $r1, $c1]);
        $cellStmt->execute([$ttId, $r1, $c2]);
        $cellStmt->execute([$ttId, $r2, $c1]);
        $cellStmt->execute([$ttId, $r2, $c2]);

        // Pre-deletion assertion
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM timetable_columns WHERE timetable_id = ?");
        $stmt->execute([$ttId]);
        $this->assert((int)$stmt->fetchColumn() === 2, "Pre-delete columns count is 2");

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM timetable_rows WHERE timetable_id = ?");
        $stmt->execute([$ttId]);
        $this->assert((int)$stmt->fetchColumn() === 2, "Pre-delete rows count is 2");

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM timetable_cells WHERE timetable_id = ?");
        $stmt->execute([$ttId]);
        $this->assert((int)$stmt->fetchColumn() === 4, "Pre-delete cells count is 4");

        // Execute parent deletion
        $this->pdo->prepare("DELETE FROM timetables WHERE id = ?")->execute([$ttId]);

        // Post-deletion cascade assertions
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM timetables WHERE id = ?");
        $stmt->execute([$ttId]);
        $this->assert((int)$stmt->fetchColumn() === 0, "Timetable record deleted from `timetables`");

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM timetable_columns WHERE timetable_id = ?");
        $stmt->execute([$ttId]);
        $this->assert((int)$stmt->fetchColumn() === 0, "All associated columns automatically deleted (cascade)");

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM timetable_rows WHERE timetable_id = ?");
        $stmt->execute([$ttId]);
        $this->assert((int)$stmt->fetchColumn() === 0, "All associated rows automatically deleted (cascade)");

        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM timetable_cells WHERE timetable_id = ?");
        $stmt->execute([$ttId]);
        $this->assert((int)$stmt->fetchColumn() === 0, "All associated cells automatically deleted (cascade)");

        echo "\n";
    }

    // =========================================================================
    // Category 7: Institutional Print & CSV Export Fidelity
    // =========================================================================
    private function testCategory7PrintAndExportFidelity(): void
    {
        echo COLOR_BOLD . "Category 7: Institutional Print & CSV Export Fidelity\n" . COLOR_RESET;

        // 1. Verify timetable_print.php output
        $_GET['id'] = 1;
        $_GET['autoprint'] = '0';
        ob_start();
        include __DIR__ . '/../admin/timetable_print.php';
        $printHtml = ob_get_clean();

        $this->assert(
            strpos($printHtml, 'HINDUSTANI COVENANT CHURCH HIGH SCHOOL') !== false,
            "Print view contains institution school name"
        );
        $this->assert(
            strpos($printHtml, 'FIRST SEMESTER EXAMINATION TIME-TABLE') !== false,
            "Print view contains examination title"
        );
        $this->assert(
            strpos($printHtml, 'STD V-X October 2026') !== false,
            "Print view contains standards scope 'STD V-X October 2026'"
        );
        $this->assert(
            strpos($printHtml, 'rowspan="2"') !== false,
            "Print view contains multi-slot table cell `rowspan=\"2\"`"
        );
        $this->assert(
            strpos($printHtml, '--------') !== false,
            "Print view displays centered dashes `--------` for empty examination slots"
        );
        $this->assert(
            strpos($printHtml, '1.5pt solid') !== false,
            "Print view has high-contrast 1.5pt laser-printer outer border"
        );
        $this->assert(
            strpos($printHtml, '1pt solid') !== false,
            "Print view has crisp 1pt laser-printer inner cell border"
        );
        $this->assert(
            strpos($printHtml, 'EXAM IN-CHARGE') !== false,
            "Print view contains 'EXAM IN-CHARGE' signature block"
        );
        $this->assert(
            strpos($printHtml, 'SUPERVISOR') !== false,
            "Print view contains 'SUPERVISOR' signature block"
        );
        $this->assert(
            strpos($printHtml, 'HEADMISTRESS / PRINCIPAL') !== false,
            "Print view contains 'HEADMISTRESS / PRINCIPAL' signature block"
        );

        // 2. Verify timetable_export_csv.php output
        $_GET['id'] = 1;
        ob_start();
        // Catch exit in timetable_export_csv.php via a sub-shell or simulated stream
        $csvCode = '
            $_GET["id"] = 1;
            include "' . __DIR__ . '/../admin/timetable_export_csv.php";
        ';
        $csvOutput = shell_exec("php -r " . escapeshellarg($csvCode));

        $this->assert(
            substr($csvOutput, 0, 3) === "\xEF\xBB\xBF",
            "CSV export begins with UTF-8 BOM (\\xEF\\xBB\\xBF) for Excel"
        );
        $this->assert(
            strpos($csvOutput, 'FIRST SEMESTER EXAMINATION TIME-TABLE') !== false,
            "CSV export contains Timetable Title"
        );
        $this->assert(
            strpos($csvOutput, 'DAY,DATE,TIME') !== false || strpos($csvOutput, '"DAY","DATE","TIME"') !== false,
            "CSV export contains column headers DAY, DATE, TIME"
        );
        $this->assert(
            strpos($csvOutput, 'ALGEBRA(M-1)') !== false,
            "CSV export contains examination subject 'ALGEBRA(M-1)'"
        );
        $this->assert(
            strpos($csvOutput, '--------') !== false,
            "CSV export preserves empty slot markers `--------`"
        );
        $this->assert(
            strpos($csvOutput, 'HEADMISTRESS / PRINCIPAL') !== false,
            "CSV export contains institutional signature block"
        );

        echo "\n";
    }

    public function testCategory8PromptAndStatementGenerator(): void
    {
        echo COLOR_BOLD . "Category 8: Prompt & Fixed Statement Generator (Priority #1 & #2)\n" . COLOR_RESET;

        // 1. Verify CSS styling contains Priority #1 & Priority #2 classes
        $cssContent = file_get_contents(__DIR__ . '/../assets/css/timetable.css');
        $this->assert(
            strpos($cssContent, '.tt-generator-studio') !== false,
            "CSS contains `.tt-generator-studio` styling for Priority #1"
        );
        $this->assert(
            strpos($cssContent, '.statement-select') !== false,
            "CSS contains `.statement-select` for Mad-Libs inline dropdowns"
        );
        $this->assert(
            strpos($cssContent, '.btn-generate-main') !== false,
            "CSS contains `.btn-generate-main` for prominent generate button"
        );
        $this->assert(
            strpos($cssContent, '.tt-secondary-tools-accordion') !== false,
            "CSS contains `.tt-secondary-tools-accordion` for Priority #2 manual tools"
        );

        // 2. Verify JS Engine contains Priority #1 methods
        $jsContent = file_get_contents(__DIR__ . '/../assets/js/timetable-engine.js');
        $this->assert(
            strpos($jsContent, 'renderGeneratorStudioHtml') !== false,
            "JS Engine defines `renderGeneratorStudioHtml()`"
        );
        $this->assert(
            strpos($jsContent, 'renderFixedStatementHtml') !== false,
            "JS Engine defines `renderFixedStatementHtml()`"
        );
        $this->assert(
            strpos($jsContent, 'executeGenerateTimetable') !== false,
            "JS Engine defines `executeGenerateTimetable()`"
        );
        $this->assert(
            strpos($jsContent, 'renderSecondaryToolsHtml') !== false,
            "JS Engine defines `renderSecondaryToolsHtml()` for Priority #2 collapsible drawer"
        );

        // 3. Test API generate_template action via sub-process
        $apiPayload = [
            'standards_group' => 'std_v_x',
            'academic_year' => '2026-27',
            'exam_type' => 'FIRST SEMESTER EXAMINATION TIME-TABLE (WRITTEN EXAM)',
            'start_date' => '2026-10-23',
            'primary_slot' => '8.00 AM - 10.15 AM',
            'extended_slot' => '8.00 AM - 11.15 AM',
            'has_extended_slot' => true,
            'skip_sundays' => true,
            'days' => [
                ['dayNum' => 1, 'subject' => 'ENGLISH', 'hsSubject' => 'ALGEBRA(M-1)'],
                ['dayNum' => 2, 'subject' => 'MARATHI', 'hsSubject' => 'ENGLISH'],
                ['dayNum' => 3, 'subject' => 'HINDI / SANSKRIT', 'hsSubject' => 'MARATHI'],
                ['dayNum' => 4, 'subject' => 'MATHS', 'hsSubject' => 'HINDI / SANSKRIT'],
                ['dayNum' => 5, 'subject' => 'EVS - I', 'hsSubject' => 'SCIENCE-1'],
                ['dayNum' => 6, 'subject' => 'SCIENCE', 'hsSubject' => 'GEOMETRY'],
                ['dayNum' => 7, 'subject' => 'COMP. SCI.', 'hsSubject' => 'SCIENCE-2'],
                ['dayNum' => 8, 'subject' => 'EVS-II', 'hsSubject' => 'HISTORY'],
                ['dayNum' => 9, 'subject' => 'DRAWING', 'hsSubject' => 'GEOGRAPHY']
            ]
        ];

        $jsonPayload = json_encode($apiPayload);
        $apiScript = '
            if (session_status() === PHP_SESSION_NONE) session_start();
            $_SERVER["SCRIPT_NAME"] = "api_timetable.php";
            $_SESSION["admin_id"] = 1;
            $_SESSION["admin_role"] = "admin";
            $_SESSION["admin_name"] = "Admin";
            $_SERVER["REQUEST_METHOD"] = "POST";
            $_GET["action"] = "generate_template";
            $_POST = json_decode(' . var_export($jsonPayload, true) . ', true);
            include "' . __DIR__ . '/../admin/api_timetable.php";
        ';
        $apiResponseJson = shell_exec("php -r " . escapeshellarg($apiScript));
        $apiRes = json_decode($apiResponseJson ?: '', true);

        $this->assert(
            is_array($apiRes) && !empty($apiRes['ok']),
            "API `generate_template` returns successful JSON response"
        );
        $this->assert(
            isset($apiRes['timetable']['columns']) && count($apiRes['timetable']['columns']) === 3,
            "Generated STD V-X timetable has exactly 3 combined/separate columns"
        );
        $this->assert(
            isset($apiRes['timetable']['rows']) && count($apiRes['timetable']['rows']) === 9,
            "Generated timetable has 9 sequential examination dates"
        );

        // Check Sunday skip: Day 1 is 2026-10-23 (Fri), Day 2 is 2026-10-24 (Sat), Day 3 is 2026-10-26 (Mon, skipping Sun)
        $dates = array_column($apiRes['timetable']['rows'] ?? [], 'date');
        $this->assert(
            !in_array('2026-10-25', $dates, true),
            "Generated dates automatically exclude Sunday (2026-10-25 skipped)"
        );

        // Check multi-slot for languages on Day 2
        $day2Slots = $apiRes['timetable']['rows'][1]['slots'] ?? [];
        $this->assert(
            count($day2Slots) === 2,
            "Day 2 (Language Paper) automatically generates 2 time slots for middle vs high school"
        );

        // 4. Test Comprehensive 10-Class Template Generation
        $api10Payload = [
            'standards_group' => 'std_i_x',
            'academic_year' => '2025-26',
            'exam_type' => 'ANNUAL EXAMINATION TIME-TABLE',
            'start_date' => '2026-04-10',
            'primary_slot' => '08:30 AM - 10:30 AM',
            'has_extended_slot' => false,
            'skip_sundays' => true,
            'days' => [
                ['dayNum' => 1, 'subject' => 'ENGLISH', 'hsSubject' => 'ENGLISH'],
                ['dayNum' => 2, 'subject' => 'MARATHI', 'hsSubject' => 'MARATHI'],
                ['dayNum' => 3, 'subject' => 'MATHEMATICS', 'hsSubject' => 'MATHEMATICS']
            ]
        ];
        $api10Script = '
            if (session_status() === PHP_SESSION_NONE) session_start();
            $_SERVER["SCRIPT_NAME"] = "api_timetable.php";
            $_SESSION["admin_id"] = 1;
            $_SESSION["admin_role"] = "admin";
            $_SESSION["admin_name"] = "Admin";
            $_SERVER["REQUEST_METHOD"] = "POST";
            $_GET["action"] = "generate_template";
            $_POST = json_decode(' . var_export(json_encode($api10Payload), true) . ', true);
            include "' . __DIR__ . '/../admin/api_timetable.php";
        ';
        $api10ResponseJson = shell_exec("php -r " . escapeshellarg($api10Script));
        $api10Res = json_decode($api10ResponseJson ?: '', true);

        $this->assert(
            isset($api10Res['timetable']['columns']) && count($api10Res['timetable']['columns']) === 10,
            "Generated Full School (STD I-X) template contains all 10 standard columns"
        );

        echo "\n";
    }
}

// Execute Runner
$tester = new TimetableFeatureTester();
$allPassed = $tester->runAll();

exit($allPassed ? 0 : 1);
