<?php
/**
 * Shared Excel Import Helpers (used by admin/import_excel.php & admin/import_unit_tests.php)
 *
 * Solves common reliability failures across real-world school workbooks:
 *  - Misnamed .xls/.xlsx/.csv files (content-based IOFactory detection, never extension-only)
 *  - Memory/timeout hangs from formatted empty rows/columns (setReadDataOnly + getHighestDataRow/Col)
 *  - Cross-sheet / Excel 365 formula crashes (getOldCalculatedValue -> getCalculatedValue -> error strip)
 *  - Fragile OS temp directories (workspace-local protected storage/imports/ with auto-cleanup)
 *  - Silent post_max_size truncation (clear error before CSRF check fails)
 *  - Excel serial date values in DOB columns
 */

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Reader\IReader;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Convert PHP ini shorthand (e.g. "40M", "512K", "1G") to bytes.
 */
function hccIniSizeToBytes(string $val): int {
    $val = trim($val);
    if ($val === '') return 0;
    $last = strtolower($val[strlen($val) - 1]);
    $num = (int)$val;
    switch ($last) {
        case 'g': $num *= 1024;
        case 'm': $num *= 1024;
        case 'k': $num *= 1024;
    }
    return max(0, $num);
}

/**
 * Effective max upload size in bytes (respecting both app cap and PHP ini limits).
 */
function hccImportMaxUploadBytes(int $appCapBytes = 40 * 1024 * 1024): int {
    $limits = [$appCapBytes];
    $u = hccIniSizeToBytes((string)ini_get('upload_max_filesize'));
    $p = hccIniSizeToBytes((string)ini_get('post_max_size'));
    if ($u > 0) $limits[] = $u;
    if ($p > 0) $limits[] = $p;
    return min($limits);
}

/**
 * Detect when PHP silently discarded $_POST and $_FILES because Content-Length exceeded post_max_size.
 */
function hccImportPostExceededLimit(): ?string {
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return null;
    }
    $contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($contentLength <= 0 || !empty($_POST) || !empty($_FILES)) {
        return null;
    }
    $postMax = (string)ini_get('post_max_size');
    $postMaxBytes = hccIniSizeToBytes($postMax);
    if ($postMaxBytes > 0 && $contentLength > $postMaxBytes) {
        return "The uploaded file is too large for the server limit ({$postMax}). Please split the workbook or reduce its size and try again.";
    }
    return null;
}

/**
 * Human-readable PHP upload error message.
 */
function hccUploadErrorMessage(int $code): string {
    switch ($code) {
        case UPLOAD_ERR_INI_SIZE:
            return 'The file exceeds the server upload limit (' . (ini_get('upload_max_filesize') ?: 'limit') . ').';
        case UPLOAD_ERR_FORM_SIZE:
            return 'The file exceeds the maximum allowed upload size.';
        case UPLOAD_ERR_PARTIAL:
            return 'The file was only partially uploaded. Please try again.';
        case UPLOAD_ERR_NO_FILE:
            return 'No file uploaded. Please choose an Excel file.';
        case UPLOAD_ERR_NO_TMP_DIR:
            return 'Server temporary upload folder is missing. Please contact the administrator.';
        case UPLOAD_ERR_CANT_WRITE:
            return 'Server failed to write the uploaded file to disk. Check folder permissions.';
        case UPLOAD_ERR_EXTENSION:
            return 'File upload was blocked by a server extension.';
        default:
            return 'File upload failed (error code ' . $code . '). Please try again.';
    }
}

/**
 * Protected workspace-local temp directory for staged Excel uploads.
 * Auto-cleans stale files older than $maxAgeSeconds (default 2 hours).
 */
function hccImportTempDir(string $subdir = 'imports', int $maxAgeSeconds = 7200): string {
    $base = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . $subdir;
    if (!is_dir($base)) {
        @mkdir($base, 0755, true);
    }
    $htaccess = $base . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_dir($base) && !file_exists($htaccess)) {
        @file_put_contents($htaccess, "Order allow,deny\nDeny from all\n<IfModule mod_authz_core.c>\nRequire all denied\n</IfModule>\n");
    }
    if (!is_dir($base) || !is_writable($base)) {
        $fallback = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'hcc_' . $subdir;
        if (!is_dir($fallback)) {
            @mkdir($fallback, 0755, true);
        }
        $base = $fallback;
    }

    // Best-effort cleanup of stale temp workbooks
    if (is_dir($base)) {
        $now = time();
        $entries = @scandir($base);
        if (is_array($entries)) {
            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..' || $entry === '.htaccess') continue;
                $full = $base . DIRECTORY_SEPARATOR . $entry;
                if (is_file($full) && ($now - (int)@filemtime($full)) > $maxAgeSeconds) {
                    @unlink($full);
                }
            }
        }
    }

    return $base;
}

/**
 * Create the right PhpSpreadsheet Reader based on actual file content (with safe fallback),
 * always configured with setReadDataOnly(true) to avoid OOM/timeouts on heavily styled sheets.
 */
function hccCreateExcelReader(string $filePath, string $extHint = ''): IReader {
    $reader = null;
    try {
        $inputType = IOFactory::identify($filePath);
        $allowedTypes = ['Xlsx', 'Xls', 'Ods', 'Csv', 'Xml', 'Html'];
        if (in_array($inputType, $allowedTypes, true)) {
            $reader = IOFactory::createReader($inputType);
        }
    } catch (\Throwable $e) {
        // Fall back to extension hint below
    }

    if ($reader === null) {
        $ext = strtolower($extHint !== '' ? $extHint : pathinfo($filePath, PATHINFO_EXTENSION));
        if ($ext === 'xls') {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xls();
        } elseif ($ext === 'csv') {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Csv();
        } elseif ($ext === 'ods') {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Ods();
        } else {
            $reader = new \PhpOffice\PhpSpreadsheet\Reader\Xlsx();
        }
    }

    if (method_exists($reader, 'setReadDataOnly')) {
        $reader->setReadDataOnly(true);
    }
    if (method_exists($reader, 'setReadEmptyCells')) {
        $reader->setReadEmptyCells(false);
    }

    return $reader;
}

/**
 * List worksheet names from a workbook safely (falls back to loading if reader doesn't support listWorksheetNames).
 */
function hccListExcelSheetNames(IReader $reader, string $filePath): array {
    if (method_exists($reader, 'listWorksheetNames')) {
        $names = $reader->listWorksheetNames($filePath);
        if (is_array($names) && !empty($names)) {
            return $names;
        }
    }
    $ss = $reader->load($filePath);
    $names = $ss->getSheetNames();
    $ss->disconnectWorksheets();
    unset($ss);
    return $names;
}

/**
 * Load a single sheet from a workbook using a fresh reader instance to avoid state leakage
 * between sheets in PhpSpreadsheet.
 */
function hccLoadSingleWorksheet(string $filePath, string $sheetName, string $extHint = ''): \PhpOffice\PhpSpreadsheet\Spreadsheet {
    $reader = hccCreateExcelReader($filePath, $extHint);
    if (method_exists($reader, 'setLoadSheetsOnly')) {
        $reader->setLoadSheetsOnly([$sheetName]);
    }
    return $reader->load($filePath);
}

/**
 * Safely extract a cell's display/computed value without crashing on cross-sheet formulas
 * or Excel 365 functions. Prefers Excel's cached formula result (getOldCalculatedValue) first.
 */
function hccExcelCellValue(Worksheet $sheet, int $colIndex, int $rowIndex): string {
    $cell = $sheet->getCell([$colIndex, $rowIndex]);
    $raw = $cell->getValue();
    if ($raw === null) {
        return '';
    }

    // Handle RichText objects cleanly
    if ($raw instanceof \PhpOffice\PhpSpreadsheet\RichText\RichText) {
        $raw = $raw->getPlainText();
    }

    if (is_string($raw) && $raw !== '' && $raw[0] === '=') {
        // 1. Try Excel's cached calculated value first (fast & safe for cross-sheet formulas)
        $cached = $cell->getOldCalculatedValue();
        if ($cached !== null && $cached !== '' && !hccIsExcelErrorToken($cached)) {
            $raw = $cached;
        } else {
            // 2. Fall back to PhpSpreadsheet formula evaluation
            try {
                $calc = $cell->getCalculatedValue();
                if ($calc !== null && !hccIsExcelErrorToken($calc)) {
                    $raw = $calc;
                } else {
                    $raw = '';
                }
            } catch (\Throwable $e) {
                // Never return raw "=SUM(...)" formula text as a mark or name
                $raw = ($cached !== null && !hccIsExcelErrorToken($cached)) ? $cached : '';
            }
        }
    }

    if (hccIsExcelErrorToken($raw)) {
        return '';
    }

    if (is_bool($raw)) {
        return $raw ? '1' : '0';
    }

    $s = trim((string)$raw);
    $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/', '', $s) ?? '';
    return trim($s);
}

/**
 * Detect Excel error tokens like #REF!, #DIV/0!, #VALUE!, #NAME?, #N/A, #NUM!, #NULL!.
 */
function hccIsExcelErrorToken($val): bool {
    if (!is_string($val)) return false;
    $u = strtoupper(trim($val));
    return in_array($u, ['#REF!', '#DIV/0!', '#VALUE!', '#NAME?', '#N/A', '#NUM!', '#NULL!', '#SPILL!', '#CALC!'], true);
}

/**
 * Normalize multiline/noisy subject headers into clean single-line subject names.
 */
function hccCleanSubjectName(string $name): string {
    $s = preg_replace('/[\r\n\t]+/', ' ', $name) ?? $name;
    $s = preg_replace('/\s+/', ' ', $s) ?? $s;
    return trim($s);
}

/**
 * Normalize an academic year string (e.g. "2025/2026" or "2025 - 26" -> "2025-26").
 */
function hccNormalizeAcademicYear(string $raw): string {
    $s = trim($raw);
    if (preg_match('/(20\d{2})\s*[-\/_]\s*(?:20)?(\d{2})/', $s, $m)) {
        return $m[1] . '-' . $m[2];
    }
    return $s;
}

/**
 * Parse DOB from either a formatted string (dd/mm/yyyy, YYYY-MM-DD) or an Excel serial date number.
 */
function hccExcelParseDob($rawVal): ?string {
    $s = trim((string)$rawVal);
    if ($s === '') return null;

    // Numeric Excel date serial (typical student DOBs: 1995..2030 => ~34700..47800, allow wider 18000..65000)
    if (is_numeric($s)) {
        $num = (float)$s;
        if ($num >= 18000 && $num <= 65000) {
            try {
                $dt = ExcelDate::excelToDateTimeObject($num);
                return $dt->format('Y-m-d');
            } catch (\Throwable $e) {
                return null;
            }
        }
        return null;
    }

    if (function_exists('rcNormalizeDateToMysql')) {
        return rcNormalizeDateToMysql($s);
    }
    return null;
}
