<?php
// Direct-access guard: helpers must only be loaded via require/include, never via HTTP.
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}
// HCC Admin — helpers used by dashboard / results / entry pages.
require_once __DIR__ . '/../config/database.php';

function getAllSettings() {
    $db = getDB();
    $stmt = $db->query("SELECT setting_key, setting_value FROM settings");
    $settings = [];
    while ($row = $stmt->fetch()) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

function sanitize($input) {
    if ($input === null) return '';
    return htmlspecialchars(trim((string)$input), ENT_QUOTES, 'UTF-8');
}

/* ── XSS output-encoding helpers (do not replace sanitize()) ── */
function e_attr($s) {
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}
function e_js($s) {
    return json_encode($s, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
}
function e_url($s) {
    return htmlspecialchars(rawurlencode((string)$s), ENT_QUOTES, 'UTF-8');
}
/* Logo src allowlist: relative paths and https only; blocks javascript:, data:, ../ traversal. */
function safeLogoSrc($path, $fallback = 'assets/images/hcclogo.jpg') {
    $p = trim((string)$path);
    if ($p === '') return $fallback;
    // Block dangerous schemes (case-insensitive, allow leading whitespace/control chars).
    $lower = strtolower(ltrim($p));
    if (preg_match('#^\s*(javascript|data|vbscript|file|ftp)\s*:#i', $lower)) return $fallback;
    // Allow https:// explicitly; all other absolute http:// or protocol-relative are rejected.
    if (preg_match('#^https?://#i', $p)) {
        if (!preg_match('#^https://#i', ltrim($p))) return $fallback;
        return $p;
    }
    if (strpos(ltrim($p), '//') === 0) return $fallback;
    // Reject any traversal attempt in the original value (before stripping).
    if (strpos($p, '..') !== false) return $fallback;
    // Strip leading ./ and / then reject traversal.
    $clean = ltrim($p, './');
    // Also strip leading slashes left after dot-trim.
    $clean = ltrim($clean, '/');
    if ($clean === '' || strpos($clean, '..') !== false) return $fallback;
    // Only allow safe chars in relative asset paths.
    if (!preg_match('#^[A-Za-z0-9_\-\/\.]+$#', $clean)) return $fallback;
    return $clean;
}

/**
 * Returns data:image/png;base64,... URI for school logo to allow offline PDF generation
 * without CORS or network round-trips.
 */
function getSchoolLogoBase64(): string {
    static $cached = null;
    if ($cached !== null) return $cached;
    $settings = getAllSettings();
    $logoRel = $settings['logo'] ?? 'assets/images/hcclogo.jpg';
    $fullPath = __DIR__ . '/../' . ltrim($logoRel, '/');
    if (!file_exists($fullPath)) {
        $fullPath = __DIR__ . '/../assets/images/hcclogo.jpg';
    }
    if (file_exists($fullPath)) {
        $data = file_get_contents($fullPath);
        $cached = 'data:image/jpeg;base64,' . base64_encode($data);
    } else {
        $cached = '';
    }
    return $cached;
}

function generateCSRFToken() {
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

function csrfField() {
    return '<input type="hidden" name="csrf_token" value="' . generateCSRFToken() . '">';
}

function verifyCSRFToken() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return true;
    $token = $_POST['csrf_token'] ?? '';
    return hash_equals($_SESSION['csrf_token'] ?? '', $token);
}

function calculateGrade($percentage, $std = null) {
    $p = (float)$percentage;
    $s = $std !== null ? (int)$std : 0;
    if ($s >= 9) {
        if ($p >= 75.0) return 'Distinction';
        if ($p >= 60.0) return 'Grade I';
        if ($p >= 45.0) return 'Grade II';
        if ($p >= 35.0) return 'Pass';
        return 'Fail';
    }
    if ($p >= 90.5) return 'A1';
    if ($p >= 80.5) return 'A2';
    if ($p >= 70.5) return 'B1';
    if ($p >= 60.5) return 'B2';
    if ($p >= 50.5) return 'C1';
    if ($p >= 40.5) return 'C2';
    if ($p >= 33.0) return 'D';
    if ($p >= 21.0) return 'E1';
    return 'E2';
}

function getGradeDivision($pct, $std = null) {
    $p = (float)$pct;
    $s = $std !== null ? (int)$std : 0;
    if ($s >= 9) {
        if ($p >= 75) return 'Distinction';
        if ($p >= 60) return 'First Class';
        if ($p >= 45) return 'Second Class';
        if ($p >= 35) return 'Pass Class';
        return 'Fail';
    }
    if ($p >= 90) return 'Distinction';
    if ($p >= 75) return 'First Class';
    if ($p >= 60) return 'Second Class';
    if ($p >= 45) return 'Third Class';
    if ($p >= 33) return 'Pass Class';
    return 'Needs Improvement';
}

/* Display-ready remark. Imported sheets sometimes store the raw Excel
   formula text (e.g. =IF(P6<21,...)) when the engine could not calculate
   it — printing that is useless. Evaluate the school's own band scale
   against the student's overall percentage instead. Plain remarks pass
   through untouched. */
function rcDisplayRemark($remark, $percentage): string {
    $t = ltrim(trim((string)$remark), "'");
    $isFormulaOrError = ($t !== '' && ($t[0] === '=' || preg_match('/^#(VALUE|REF|N\/A|NAME\?|DIV\/0|NUM|NULL)!/i', $t)));
    if (!$isFormulaOrError) return trim((string)$remark);
    $pct = (float)$percentage;
    if ($pct < 21) return 'Needs major improvement';
    if ($pct > 90) return 'Excellent';
    if ($pct > 80) return 'V.Good';
    if ($pct > 70) return 'Good';
    if ($pct > 60) return 'Satisfactory';
    if ($pct > 50) return 'Fair';
    if ($pct > 40) return 'Average';
    if ($pct > 30) return 'Can do better';
    return 'Needs to improve';
}

/* ── Dashboard stats ── */
function rcDashboardStats() {
    $db = getDB();
    $s = [];
    $r = $db->query("SELECT COUNT(DISTINCT roll_no) AS cnt FROM report_card")->fetch();
    $s['students'] = (int)$r['cnt'];
    $r = $db->query("SELECT COUNT(DISTINCT subject_name) AS cnt FROM report_card")->fetch();
    $s['subjects'] = (int)$r['cnt'];
    $r = $db->query("SELECT COUNT(DISTINCT roll_no) AS total, SUM(CASE WHEN obtained >= maxtotal THEN 1 ELSE 0 END) AS passed FROM (SELECT roll_no, SUM(fa_obtained+sa_obtained) AS obtained, SUM(fa_max+sa_max) AS maxtotal FROM report_card GROUP BY roll_no) t")->fetch();
    $s['passed'] = (int)$r['passed'];
    $s['total'] = (int)$r['total'];
    $s['passPct'] = $s['total'] > 0 ? round(($s['passed'] / $s['total']) * 100, 1) : 0;
    $r = $db->query("SELECT AVG(pct) AS avgPct FROM (SELECT roll_no, AVG(pct) AS pct FROM (SELECT roll_no, (fa_obtained+sa_obtained)/(fa_max+sa_max)*100 AS pct FROM report_card) t GROUP BY roll_no) tt")->fetch();
    $s['avgPct'] = round((float)($r['avgPct'] ?? 0), 1);
    return $s;
}

/* ── Results table ── */
function rcClassKey($year, $std, $div, $sem) {
    return urlencode($year . '|' . $std . '|' . $div . '|' . $sem);
}
function rcBuildClassKey($year, $std, $div, $sem) {
    return rcClassKey($year, $std, $div, $sem);
}
function rcParseClassKey($key) {
    $str = (string)$key;
    if (strpos($str, '%') !== false || strpos($str, '+') !== false) {
        $str = urldecode($str);
    }
    if (strpos($str, '|') !== false) {
        $p = explode('|', $str, 4);
    } elseif (strpos($str, '_') !== false) {
        $p = explode('_', $str, 4);
    } else {
        $p = [$str];
    }
    return [
        'academic_year' => $p[0] ?? '',
        'std'           => $p[1] ?? '',
        'division'      => $p[2] ?? '',
        'semester'      => $p[3] ?? ''
    ];
}

/* Number → Roman numeral (1→I … 20→XX). Out of range returns plain digits. */
function romanNumeral(int $n): string {
    if ($n < 1 || $n > 39) return (string)$n;
    $r = '';
    foreach ([10 => 'X', 9 => 'IX', 5 => 'V', 4 => 'IV', 1 => 'I'] as $v => $s) {
        while ($n >= $v) { $r .= $s; $n -= $v; }
    }
    return $r;
}

/* First integer found in a label ("Sem 1", "Semester 3") → Roman ("I", "III").
   No number found: returns the trimmed label unchanged. */
function romanSemLabel($label): string {
    if (preg_match('/(\d+)/', (string)$label, $m)) {
        return romanNumeral((int)$m[1]);
    }
    return trim((string)$label);
}

function rcClassOptions() {
    $db = getDB();
    $stmt = $db->query("SELECT academic_year, std, division, semester, COUNT(DISTINCT roll_no) AS students FROM report_card GROUP BY academic_year, std, division, semester");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    usort($rows, function ($a, $b) {
        if ($a['academic_year'] !== $b['academic_year']) {
            return strcmp($b['academic_year'], $a['academic_year']);
        }
        $sa = (int)$a['std'];
        $sb = (int)$b['std'];
        if ($sa !== $sb) {
            return $sa <=> $sb;
        }
        $c = strcmp($a['std'], $b['std']);
        if ($c !== 0) return $c;
        $d = strcmp($a['division'], $b['division']);
        if ($d !== 0) return $d;
        return strcmp($a['semester'], $b['semester']);
    });
    return $rows;
}

/* ── Per-class subject configuration ── */

/* Create the class_subjects table if it does not exist (idempotent). */
function rcEnsureClassSubjectsTable() {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS class_subjects (
      id INT AUTO_INCREMENT PRIMARY KEY,
      academic_year VARCHAR(20) NOT NULL,
      std VARCHAR(10) NOT NULL,
      division VARCHAR(5) NOT NULL,
      semester VARCHAR(10) NOT NULL,
      subject_name VARCHAR(50) NOT NULL,
      fa_max DECIMAL(5,1) NOT NULL DEFAULT 70.0,
      sa_max DECIMAL(5,1) NOT NULL DEFAULT 30.0,
      sort_order INT NOT NULL DEFAULT 0,
      created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
      UNIQUE KEY uniq_class_subject (academic_year, std, division, semester, subject_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

/* Subjects configured for a class. Auto-seeds from report_card when none exist. */
function rcClassSubjects(string $classKey): array {
    rcEnsureClassSubjectsTable();
    $c = rcParseClassKey(urldecode($classKey));
    $db = getDB();
    $stmt = $db->prepare("SELECT subject_name, fa_max, sa_max, sort_order FROM class_subjects WHERE academic_year=? AND std=? AND division=? AND semester=? ORDER BY sort_order, subject_name");
    $stmt->execute([$c['academic_year'], $c['std'], $c['division'], $c['semester']]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) {
        // Auto-seed from existing report_card subjects for this class.
        $seed = $db->prepare("SELECT subject_name, MAX(fa_max) AS fa_max, MAX(sa_max) AS sa_max FROM report_card WHERE academic_year=? AND std=? AND division=? AND semester=? GROUP BY subject_name ORDER BY subject_name");
        $seed->execute([$c['academic_year'], $c['std'], $c['division'], $c['semester']]);
        $seedRows = $seed->fetchAll(PDO::FETCH_ASSOC);
        if ($seedRows) {
            $ins = $db->prepare("INSERT INTO class_subjects (academic_year, std, division, semester, subject_name, fa_max, sa_max, sort_order) VALUES (?,?,?,?,?,?,?,?)");
            $i = 0;
            foreach ($seedRows as $s) {
                $ins->execute([$c['academic_year'], $c['std'], $c['division'], $c['semester'], $s['subject_name'], (float)$s['fa_max'], (float)$s['sa_max'], $i]);
                $rows[] = ['subject_name' => $s['subject_name'], 'fa_max' => (float)$s['fa_max'], 'sa_max' => (float)$s['sa_max'], 'sort_order' => $i];
                $i++;
            }
        }
    }
    $out = [];
    foreach ($rows as $r) {
        $out[] = [
            'subject_name' => $r['subject_name'],
            'fa_max' => (float)$r['fa_max'],
            'sa_max' => (float)$r['sa_max'],
            'sort_order' => (int)$r['sort_order'],
        ];
    }
    return $out;
}

/* Replace the subject list for a class (transactional). */
function rcSaveClassSubjects(string $classKey, array $subjects): void {
    rcEnsureClassSubjectsTable();
    $c = rcParseClassKey(urldecode($classKey));
    $db = getDB();
    $inTx = $db->inTransaction();
    if (!$inTx) $db->beginTransaction();
    try {
        $db->prepare("DELETE FROM class_subjects WHERE academic_year=? AND std=? AND division=? AND semester=?")
           ->execute([$c['academic_year'], $c['std'], $c['division'], $c['semester']]);
        $ins = $db->prepare("INSERT INTO class_subjects (academic_year, std, division, semester, subject_name, fa_max, sa_max, sort_order) VALUES (?,?,?,?,?,?,?,?)");
        $seen = [];
        $order = 0;
        foreach ($subjects as $s) {
            $name = trim((string)($s['subject_name'] ?? ''));
            if ($name === '') continue;                       // skip empty subject names
            $key = strtolower($name);
            if (isset($seen[$key])) continue;                 // de-dup case-insensitively (keep first)
            $seen[$key] = true;
            $fa = isset($s['fa_max']) ? (float)$s['fa_max'] : 0.0;
            $sa = isset($s['sa_max']) ? (float)$s['sa_max'] : 0.0;
            if ($fa <= 0 && $sa <= 0) {
                $fa = 70.0;
                $sa = 30.0;
            }
            $ins->execute([$c['academic_year'], $c['std'], $c['division'], $c['semester'], $name, $fa, $sa, $order]);
            $order++;
        }
        if (!$inTx) $db->commit();
    } catch (Exception $e) {
        if (!$inTx && $db->inTransaction()) $db->rollBack();
        throw $e;
    }
}

/* Per-student per-subject obtained marks for the Bulk Results matrix.
   Returns ['subjects'=>..., 'students'=>..., 'marks'=>...] where
   $marks[roll][strtolower(subject)] carries fa/sa obtained + max. */
function rcResultsMatrix(string $classKey, string $search = ''): array {
    $subjects = rcClassSubjects($classKey);
    $filters = ['class_key' => $classKey];
    if ($search !== '') $filters['search'] = $search;
    $students = rcAllStudents($filters);
    $c = rcParseClassKey(urldecode($classKey));
    $db = getDB();
    $stmt = $db->prepare("SELECT roll_no, subject_name, fa_obtained, sa_obtained, fa_max, sa_max FROM report_card WHERE academic_year=? AND std=? AND division=? AND semester=?");
    $stmt->execute([$c['academic_year'], $c['std'], $c['division'], $c['semester']]);
    $marks = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $marks[(string)$r['roll_no']][mb_strtolower($r['subject_name'])] = [
            'subject_name' => $r['subject_name'],
            'fa_obtained' => (float)$r['fa_obtained'],
            'sa_obtained' => (float)$r['sa_obtained'],
            'fa_max' => (float)$r['fa_max'],
            'sa_max' => (float)$r['sa_max'],
        ];
    }
    return ['subjects' => $subjects, 'students' => $students, 'marks' => $marks];
}

/* Bulk-save inline matrix marks. $marks[roll][subject] = ['fa'=>x,'sa'=>y].
   Only subjects in the class config are accepted; obtained values are clamped
   to max. Existing students only — unknown rolls are skipped. */
function rcSaveBulkMarks(string $classKey, array $marks): array {
    $c = rcParseClassKey(urldecode($classKey));
    if ($c['std'] === '') return ['ok' => false, 'error' => 'A class must be selected.', 'saved' => 0];
    $subjects = rcClassSubjects($classKey);
    if (empty($subjects)) return ['ok' => false, 'error' => 'No subjects configured for this class.', 'saved' => 0];
    $cfg = [];
    foreach ($subjects as $s) $cfg[mb_strtolower($s['subject_name'])] = $s;
    if (empty($marks)) return ['ok' => false, 'error' => 'Nothing to save.', 'saved' => 0];
    $db = getDB();
    $db->beginTransaction();
    $saved = 0;
    try {
        $meta = $db->prepare("SELECT student_name, remark, attendance, parents_sign FROM report_card WHERE academic_year=? AND std=? AND division=? AND semester=? AND roll_no=? LIMIT 1");
        $get = $db->prepare("SELECT fa_max, sa_max FROM report_card WHERE academic_year=? AND std=? AND division=? AND semester=? AND roll_no=? AND LOWER(subject_name)=? LIMIT 1");
        $upd = $db->prepare("UPDATE report_card SET fa_obtained=?, sa_obtained=? WHERE academic_year=? AND std=? AND division=? AND semester=? AND roll_no=? AND LOWER(subject_name)=?");
        $ins = $db->prepare("INSERT INTO report_card (academic_year, std, division, semester, roll_no, student_name, subject_name, fa_max, fa_obtained, sa_max, sa_obtained, remark, attendance, parents_sign) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($marks as $roll => $subs) {
            $roll = trim((string)$roll);
            if ($roll === '' || !is_array($subs)) continue;
            $meta->execute([$c['academic_year'], $c['std'], $c['division'], $c['semester'], $roll]);
            $m = $meta->fetch(PDO::FETCH_ASSOC);
            if (!$m) continue; // bulk edit covers existing students only
            $touched = false;
            foreach ($subs as $subj => $vals) {
                $key = mb_strtolower(trim((string)$subj));
                if (!isset($cfg[$key]) || !is_array($vals)) continue;
                $canon = $cfg[$key]['subject_name'];
                $get->execute([$c['academic_year'], $c['std'], $c['division'], $c['semester'], $roll, $key]);
                $row = $get->fetch(PDO::FETCH_ASSOC);
                $faMax = $row ? (float)$row['fa_max'] : (float)$cfg[$key]['fa_max'];
                $saMax = $row ? (float)$row['sa_max'] : (float)$cfg[$key]['sa_max'];
                $faObt = clampMark($vals['fa'] ?? 0, $faMax);
                $saObt = clampMark($vals['sa'] ?? 0, $saMax);
                if ($row) {
                    $upd->execute([$faObt, $saObt, $c['academic_year'], $c['std'], $c['division'], $c['semester'], $roll, $key]);
                } else {
                    $ins->execute([$c['academic_year'], $c['std'], $c['division'], $c['semester'], $roll, $m['student_name'], $canon, $faMax, $faObt, $saMax, $saObt, $m['remark'] ?? '', $m['attendance'] ?? '', (int)($m['parents_sign'] ?? 0)]);
                }
                $touched = true;
            }
            if ($touched) $saved++;
        }
        $db->commit();
        if ($saved === 0) return ['ok' => false, 'error' => 'Nothing to save.', 'saved' => 0];
        return ['ok' => true, 'error' => '', 'saved' => $saved];
    } catch (Exception $e) {
        $db->rollBack();
        return ['ok' => false, 'error' => $e->getMessage(), 'saved' => 0];
    }
}

function rcAllStudents($filters = []) {
    $db = getDB();
    $sql = "SELECT roll_no, student_name, academic_year, std, division, semester, SUM(fa_obtained+sa_obtained) AS obtained, SUM(fa_max+sa_max) AS maxtotal, COUNT(*) AS subject_count, GROUP_CONCAT(DISTINCT subject_name ORDER BY subject_name SEPARATOR ', ') AS subject_names, SUM(fa_obtained+sa_obtained) AS total FROM report_card";
    $params = [];
    $where = [];
    if (!empty($filters['class_key'])) {
        $c = rcParseClassKey(urldecode($filters['class_key']));
        if ($c['std'] !== '') {
            $where[] = "academic_year=? AND std=? AND division=? AND semester=?";
            $params[] = $c['academic_year'];
            $params[] = $c['std'];
            $params[] = $c['division'];
            $params[] = $c['semester'];
        }
    }
    if (!empty($filters['search'])) {
        $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $filters['search']);
        $q = '%' . $esc . '%';
        $where[] = "(roll_no LIKE ? ESCAPE '\\\\' OR student_name LIKE ? ESCAPE '\\\\')";
        $params[] = $q;
        $params[] = $q;
    }
    if (!empty($where)) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }
    $sql .= " GROUP BY roll_no, student_name, academic_year, std, division, semester HAVING maxtotal > 0 ORDER BY CAST(roll_no AS UNSIGNED), roll_no";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $rows = [];
    while ($r = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $r['percentage'] = $r['maxtotal'] > 0 ? round(($r['obtained'] / $r['maxtotal']) * 100, 2) : 0;
        $r['grade'] = calculateGrade($r['percentage'], $r['std']);
        $r['passed'] = ((int)$r['std'] >= 9 ? $r['percentage'] >= 35 : $r['percentage'] >= 33);
        // Build class_key for this student's class context
        $r['class_key'] = $r['academic_year'] . '|' . $r['std'] . '|' . $r['division'] . '|' . $r['semester'];
        // pull remark/attendance/sign/phone from first row for this student in this class
        $meta = $db->prepare("SELECT remark, attendance, parents_sign, parent_phone FROM report_card WHERE roll_no=? AND academic_year=? AND std=? AND division=? AND semester=? LIMIT 1");
        $meta->execute([$r['roll_no'], $r['academic_year'], $r['std'], $r['division'], $r['semester']]);
        $m = $meta->fetch(PDO::FETCH_ASSOC) ?: [];
        $r['remark'] = rcDisplayRemark($m['remark'] ?? '', $r['percentage'] ?? 0);
        $r['attendance'] = $m['attendance'] ?? '';
        $r['parents_sign'] = (int)($m['parents_sign'] ?? 0);
        $r['parent_phone'] = $m['parent_phone'] ?? '';
        $rows[] = $r;
    }
    return $rows;
}

/* ── Single student report for entry page ── */
function rcStudentReport($classKey, $roll) {
    $c = rcParseClassKey(urldecode($classKey));
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM report_card WHERE academic_year=? AND std=? AND division=? AND semester=? AND roll_no=? ORDER BY id");
    $stmt->execute([$c['academic_year'], $c['std'], $c['division'], $c['semester'], $roll]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return null;
    $grandTotal = 0; $runningTotal = 0;
    foreach ($rows as $r) {
        $grandTotal += (float)$r['fa_max'] + (float)$r['sa_max'];
        $runningTotal += (float)$r['fa_obtained'] + (float)$r['sa_obtained'];
    }
    $percentage = $grandTotal > 0 ? round(($runningTotal / $grandTotal) * 100, 2) : 0;
    return [
        'rows' => $rows,
        'name' => $rows[0]['student_name'],
        'academic_year' => $c['academic_year'],
        'std' => $c['std'],
        'division' => $c['division'],
        'semester' => $c['semester'],
        'roll' => $roll,
        'grandTotal' => $grandTotal,
        'obtained' => $runningTotal,
        'percentage' => $percentage,
        'grade' => calculateGrade($percentage, $c['std']),
        'division_label' => getGradeDivision($percentage, $c['std']),
        'passed' => ((int)$c['std'] >= 9 ? $percentage >= 35 : $percentage >= 33),
        'remark' => rcDisplayRemark($rows[0]['remark'] ?? '', $percentage),
        'attendance' => $rows[0]['attendance'],
        'parents_sign' => (int)$rows[0]['parents_sign'],
        'date_of_birth' => $rows[0]['date_of_birth'] ?? '',
    ];
}

/* ── Suggest next roll number for class ── */
function rcGetNextRollNumber(string $classKey): int {
    $c = rcParseClassKey(urldecode($classKey));
    if ($c['std'] === '') return 1;
    $db = getDB();
    $stmt = $db->prepare("SELECT MAX(CAST(roll_no AS UNSIGNED)) FROM report_card WHERE academic_year=? AND std=? AND division=? AND semester=?");
    $stmt->execute([$c['academic_year'], $c['std'], $c['division'], $c['semester']]);
    $max = (int)$stmt->fetchColumn();
    return $max > 0 ? ($max + 1) : 1;
}

/* ── Save entry (add or update) ── */
function rcSaveEntry($data) {
    $db = getDB();
    $db->beginTransaction();
    try {
        // Upsert student metadata (remark/attendance/sign/dob/phone)
        $existing = $db->prepare("SELECT parent_phone FROM report_card WHERE academic_year=? AND std=? AND division=? AND semester=? AND roll_no=? LIMIT 1");
        $existing->execute([trim($data['academic_year']), trim($data['std']), trim($data['division']), trim($data['semester']), trim($data['roll_no'])]);
        $existingPhone = $existing->fetchColumn();
        $sid = $existingPhone !== false ? true : false;

        // Delete old subject rows for this student in this class
        if ($sid) {
            $db->prepare("DELETE FROM report_card WHERE academic_year=? AND std=? AND division=? AND semester=? AND roll_no=?")->execute([trim($data['academic_year']), trim($data['std']), trim($data['division']), trim($data['semester']), trim($data['roll_no'])]);
        }

        $dobVal = !empty($data['date_of_birth']) ? trim($data['date_of_birth']) : null;
        // Preserve the saved parent phone across re-saves unless a new one is supplied
        $phoneVal = isset($data['parent_phone']) && trim($data['parent_phone']) !== ''
            ? preg_replace('/\D+/', '', $data['parent_phone'])
            : ($existingPhone ?: null);

        // Compute student percentage to clean/evaluate remark if it was imported as a formula
        $calcObt = 0.0; $calcMax = 0.0;
        foreach ($data['subjects'] as $subj) {
            $calcObt += (float)($subj['fa_obtained'] ?? 0) + (float)($subj['sa_obtained'] ?? 0);
            $calcMax += (float)($subj['fa_max'] ?? 0) + (float)($subj['sa_max'] ?? 0);
        }
        $calcPct = $calcMax > 0 ? round(($calcObt / $calcMax) * 100, 2) : 0;
        $saveRemark = rcDisplayRemark($data['remark'] ?? '', $calcPct);

        // Insert new subject rows
        $insert = $db->prepare("INSERT INTO report_card (academic_year, std, division, semester, roll_no, student_name, subject_name, fa_max, fa_obtained, sa_max, sa_obtained, remark, attendance, parents_sign, date_of_birth, parent_phone) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)");
        foreach ($data['subjects'] as $subj) {
            if (trim($subj['subject_name']) === '') continue;
            $faObt = clampMark($subj['fa_obtained'], $subj['fa_max']);
            $saObt = clampMark($subj['sa_obtained'], $subj['sa_max']);
            $insert->execute([
                trim($data['academic_year']),
                trim($data['std']),
                trim($data['division']),
                trim($data['semester']),
                trim($data['roll_no']),
                trim($data['student_name']),
                trim($subj['subject_name']),
                (float)$subj['fa_max'],
                $faObt,
                (float)$subj['sa_max'],
                $saObt,
                $saveRemark,
                trim($data['attendance'] ?? ''),
                isset($data['parents_sign']) ? 1 : 0,
                $dobVal,
                $phoneVal
            ]);
        }

        // Handle rename only
        if (!empty($data['renamed_name'])) {
            $db->prepare("UPDATE report_card SET student_name=? WHERE academic_year=? AND std=? AND division=? AND semester=? AND roll_no=?")->execute([trim($data['renamed_name']), trim($data['academic_year']), trim($data['std']), trim($data['division']), trim($data['semester']), trim($data['roll_no'])]);
        }

        $db->commit();
        return ['ok' => true, 'error' => ''];
    } catch (Exception $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

function clampMark($val, $max) {
    $v = (float)$val;
    if (is_nan($v) || is_infinite($v)) return 0;
    $m = (float)$max;
    if ($m <= 0) return 0;
    return max(0, min($v, $m));
}



/* ── Bonafide certificate helpers ── */

/* Ensure bonafide-related settings exist (idempotent). */
function bonafideEnsureSettings() {
    $db = getDB();
    $defaults = [
        'gr_no'      => '3875',
        'cert_serial'=> '01',
        'principal_name' => 'PRINCIPAL',
    ];
    $ins = $db->prepare("INSERT IGNORE INTO settings (setting_key, setting_value, setting_type) VALUES (?, ?, 'text')");
    $chk = $db->prepare("SELECT 1 FROM settings WHERE setting_key=? LIMIT 1");
    foreach ($defaults as $k => $v) {
        $chk->execute([$k]);
        $has = $chk->fetch();
        if (!$has) { $ins->execute([$k, $v]); }
    }
}

/* List individual students (roll + name) for a class, with DOB. */
function rcListStudents($classKey, $search = '') {
    $c = rcParseClassKeySafe($classKey);
    $db = getDB();
    $sql = "SELECT roll_no, student_name, std, division, semester, academic_year, date_of_birth FROM report_card";
    $params = [];
    $where = [];
    if ($c['std'] !== '') {
        $where[] = "academic_year=? AND std=? AND division=? AND semester=?";
        $params = [$c['academic_year'], $c['std'], $c['division'], $c['semester']];
    }
    if ($search !== '') {
        $esc = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search);
        $q = '%' . $esc . '%';
        $where[] = "(roll_no LIKE ? ESCAPE '\\\\' OR student_name LIKE ? ESCAPE '\\\\')";
        $params[] = $q; $params[] = $q;
    }
    if ($where) $sql .= " WHERE " . implode(" AND ", $where);
    $sql .= " GROUP BY roll_no, student_name, std, division, semester, academic_year, date_of_birth ORDER BY CAST(roll_no AS UNSIGNED)";
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* Safe wrapper: parse class key without urldecode (keys passed raw). */
function rcParseClassKeySafe($classKey) {
    return rcParseClassKey((string)$classKey);
}

/* Convert a date string (dd/mm/yyyy) to words, e.g. 02/04/2017 → "Second April Two Thousand Seventeen". */
function dobToWords($dob) {
    $dob = trim((string)$dob);
    if ($dob === '') return '';
    if (!preg_match('#^(\d{1,2})/(\d{1,2})/(\d{4})$#', $dob, $m)) {
        return $dob; // not a digits date — return as-is
    }
    $d = (int)$m[1]; $mo = (int)$m[2]; $y = (int)$m[3];
    if ($d < 1 || $d > 31 || $mo < 1 || $mo > 12 || $y < 1000) return $dob;

    $days = ['','First','Second','Third','Fourth','Fifth','Sixth','Seventh','Eighth','Ninth','Tenth',
             'Eleventh','Twelfth','Thirteenth','Fourteenth','Fifteenth','Sixteenth','Seventeenth','Eighteenth','Nineteenth','Twentieth',
             'Twenty First','Twenty Second','Twenty Third','Twenty Fourth','Twenty Fifth','Twenty Sixth','Twenty Seventh','Twenty Eighth','Twenty Ninth','Thirtieth','Thirty First'];
    $months = ['','January','February','March','April','May','June','July','August','September','October','November','December'];

    $numToWords = function (int $n): string {
        $ones = ['','One','Two','Three','Four','Five','Six','Seven','Eight','Nine','Ten','Eleven','Twelve','Thirteen','Fourteen','Fifteen','Sixteen','Seventeen','Eighteen','Nineteen','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
        $th = intdiv($n, 1000);
        $h  = intdiv($n % 1000, 100);
        $lr = $n % 100;
        $out = '';
        if ($th > 0) $out = $ones[$th] . ' Thousand';
        if ($h > 0)  $out .= ($out !== '' ? ' ' : '') . $ones[$h] . ' Hundred';
        if ($lr > 0) {
            if ($lr >= 20) {
                $tw = intdiv($lr, 10); $os = $lr % 10;
                $tens = ['','Twenty','Thirty','Forty','Fifty','Sixty','Seventy','Eighty','Ninety'];
                $out .= ($out !== '' ? ' ' : '') . $tens[$tw];
                if ($os > 0) $out .= ' ' . $ones[$os];
            } else {
                $out .= ($out !== '' ? ' ' : '') . $ones[$lr];
            }
        }
        return trim($out);
    };

    return trim($days[$d] . ' ' . $months[$mo] . ' ' . $numToWords($y));
}

/* ── Delete student (all rows) ── */
/* ── Bulk save remarks: $remarks = [roll => text, ...] for one class.
   Remark is per-student, so one UPDATE covers all their subject rows.
   Returns ['ok'=>bool, 'saved'=>int, 'error'=>string]. */
function rcSaveBulkRemarks(string $classKey, array $remarks): array {
    $c = rcParseClassKey(urldecode($classKey));
    if ($c['std'] === '') return ['ok' => false, 'error' => 'A class must be selected.', 'saved' => 0];
    $db = getDB();
    $saved = 0;
    try {
        $db->beginTransaction();
        $upd = $db->prepare("UPDATE report_card SET remark=? WHERE academic_year=? AND std=? AND division=? AND semester=? AND roll_no=?");
        foreach ($remarks as $roll => $text) {
            $roll = trim((string)$roll);
            if ($roll === '') continue;
            $text = trim((string)$text);
            $text = rcDisplayRemark($text, 0);
            if (mb_strlen($text) > 120) $text = mb_substr($text, 0, 120);
            $upd->execute([$text, $c['academic_year'], $c['std'], $c['division'], $c['semester'], $roll]);
            if ($upd->rowCount() > 0) $saved++;
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'error' => 'Remarks save failed.', 'saved' => 0];
    }
    return ['ok' => true, 'error' => '', 'saved' => $saved];
}

function rcDeleteStudent($classKey, $roll) {
    $c = rcParseClassKey(urldecode($classKey));
    $db = getDB();
    $stmt = $db->prepare("DELETE FROM report_card WHERE academic_year=? AND std=? AND division=? AND semester=? AND roll_no=?");
    return $stmt->execute([$c['academic_year'], $c['std'], $c['division'], $c['semester'], $roll]);
}

/* ── Bulk delete students: $targets = [['class_key'=>..,'roll'=>..], ...].
   Groups by class so one DELETE per class with an IN-list. Returns
   ['ok'=>bool, 'deleted'=>int, 'error'=>string]. Caps at 500 rows. */
function rcDeleteStudentsBulk(array $targets) {
    $targets = array_slice($targets, 0, 500);
    $byClass = [];
    foreach ($targets as $t) {
        if (!is_array($t)) continue;
        $ck = trim((string)($t['class_key'] ?? ''));
        $roll = trim((string)($t['roll'] ?? ''));
        if ($ck === '' || $roll === '') continue;
        $c = rcParseClassKey(urldecode($ck));
        // Refuse ambiguous class keys (std is the anchor of a class).
        if (($c['std'] ?? '') === '') continue;
        $ckNorm = implode('|', [$c['academic_year'], $c['std'], $c['division'], $c['semester']]);
        if (!isset($byClass[$ckNorm])) $byClass[$ckNorm] = ['c' => $c, 'rolls' => []];
        if (!in_array($roll, $byClass[$ckNorm]['rolls'], true)) {
            $byClass[$ckNorm]['rolls'][] = $roll;
        }
    }
    if (empty($byClass)) {
        return ['ok' => false, 'deleted' => 0, 'error' => 'No valid students selected.'];
    }
    $db = getDB();
    $deleted = 0;
    try {
        $db->beginTransaction();
        foreach ($byClass as $group) {
            $c = $group['c'];
            $rolls = $group['rolls'];
            $placeholders = implode(',', array_fill(0, count($rolls), '?'));
            $stmt = $db->prepare(
                "DELETE FROM report_card WHERE academic_year=? AND std=? AND division=? AND semester=? AND roll_no IN ($placeholders)"
            );
            $stmt->execute(array_merge(
                [$c['academic_year'], $c['std'], $c['division'], $c['semester']],
                $rolls
            ));
            $deleted += $stmt->rowCount();
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'deleted' => 0, 'error' => 'Delete failed.'];
    }
    return ['ok' => true, 'deleted' => $deleted, 'error' => ''];
}

/* ── Bulk delete unit-test students for ONE class context
   (academic_year + std + division + test_type). $rolls = [roll_no, ...].
   Single DELETE with an IN-list. Returns ['ok'=>bool,'deleted'=>int,'error'=>string]. */
function rcDeleteUnitTestStudents(string $year, string $std, string $division, string $testType, array $rolls): array {
    $year = trim($year); $std = trim($std);
    $division = trim($division); $testType = trim($testType);
    if ($year === '' || $std === '') {
        return ['ok' => false, 'deleted' => 0, 'error' => 'A class must be selected.'];
    }
    $clean = [];
    foreach (array_slice($rolls, 0, 500) as $r) {
        $r = trim((string)$r);
        if ($r !== '' && !in_array($r, $clean, true)) $clean[] = $r;
    }
    if (empty($clean)) {
        return ['ok' => false, 'deleted' => 0, 'error' => 'No valid students selected.'];
    }
    try {
        $db = getDB();
        $placeholders = implode(',', array_fill(0, count($clean), '?'));
        $stmt = $db->prepare(
            "DELETE FROM unit_test_results WHERE academic_year=? AND std=? AND division=? AND test_type=? AND roll_no IN ($placeholders)"
        );
        $stmt->execute(array_merge([$year, $std, $division, $testType], $clean));
        return ['ok' => true, 'deleted' => $stmt->rowCount(), 'error' => ''];
    } catch (Throwable $e) {
        return ['ok' => false, 'deleted' => 0, 'error' => 'Delete failed.'];
    }
}

/* ── Dashboard: top performer (by overall %) ── */
function rcTopPerformer() {
    $db = getDB();
    $stmt = $db->query(
        "SELECT student_name, std, division, semester, academic_year,
                ROUND(SUM(fa_obtained+sa_obtained)/SUM(fa_max+sa_max)*100, 1) AS pct,
                SUM(fa_obtained+sa_obtained) AS obtained,
                SUM(fa_max+sa_max) AS max_total
         FROM report_card
         GROUP BY student_name, std, division, semester, academic_year
         HAVING pct IS NOT NULL
         ORDER BY pct DESC
         LIMIT 1"
    );
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/* ── Dashboard: lowest performer (by overall %) ── */
function rcLowestPerformer() {
    $db = getDB();
    $stmt = $db->query(
        "SELECT student_name, std, division, semester, academic_year,
                ROUND(SUM(fa_obtained+sa_obtained)/SUM(fa_max+sa_max)*100, 1) AS pct,
                SUM(fa_obtained+sa_obtained) AS obtained,
                SUM(fa_max+sa_max) AS max_total
         FROM report_card
         GROUP BY student_name, std, division, semester, academic_year
         HAVING pct IS NOT NULL
         ORDER BY pct ASC
         LIMIT 1"
    );
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

/* ── Dashboard: recent activity count (rows added this month) ── */
function rcRecentAdditions() {
    $db = getDB();
    $stmt = $db->query(
        "SELECT COUNT(*) AS cnt FROM report_card
         WHERE created_at >= DATE_FORMAT(NOW(), '%Y-%m-01')"
    );
    return (int)$stmt->fetch()['cnt'];
}

/* ── Dashboard: subject-wise pass rate ── */
function rcSubjectStats() {
    $db = getDB();
    $stmt = $db->query(
        "SELECT subject_name,
                COUNT(*) AS total_entries,
                SUM(CASE WHEN (fa_obtained+sa_obtained) >= (fa_max+sa_max) THEN 1 ELSE 0 END) AS passed,
                ROUND(AVG((fa_obtained+sa_obtained)/(fa_max+sa_max)*100), 1) AS avg_pct
         FROM report_card
         WHERE fa_max + sa_max > 0
         GROUP BY subject_name
         ORDER BY avg_pct DESC"
    );
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* ─────────────────────────────────────────────────────────────────────────────
   STUDENT PROMOTION & SYSTEM NOTIFICATIONS ENGINE
   ───────────────────────────────────────────────────────────────────────────── */

/* Create the system_notifications table if not exists (idempotent). */
function rcEnsureNotificationsTable(): void {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS system_notifications (
        id INT AUTO_INCREMENT PRIMARY KEY,
        title VARCHAR(150) NOT NULL,
        message TEXT NOT NULL,
        type VARCHAR(30) NOT NULL DEFAULT 'promotion',
        link VARCHAR(255) NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        is_read TINYINT(1) NOT NULL DEFAULT 0
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/* Add a persistent system notification */
function rcAddNotification(string $title, string $message, string $type = 'promotion', ?string $link = null): bool {
    rcEnsureNotificationsTable();
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO system_notifications (title, message, type, link) VALUES (?, ?, ?, ?)");
    return $stmt->execute([$title, $message, $type, $link]);
}

/* Get recent system notifications with unread count */
function rcGetRecentNotifications(int $limit = 10): array {
    rcEnsureNotificationsTable();
    $db = getDB();
    $stmt = $db->prepare("SELECT * FROM system_notifications ORDER BY created_at DESC, id DESC LIMIT " . (int)$limit);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $unreadStmt = $db->query("SELECT COUNT(*) FROM system_notifications WHERE is_read = 0");
    $unreadCount = (int)$unreadStmt->fetchColumn();

    return [
        'unread' => $unreadCount,
        'items'  => $rows,
    ];
}

/* Mark all unread notifications as read */
function rcMarkNotificationsRead(): bool {
    rcEnsureNotificationsTable();
    $db = getDB();
    return (bool)$db->exec("UPDATE system_notifications SET is_read = 1 WHERE is_read = 0");
}

/* Get default subjects for a standard */
function rcGetStandardSubjects(string $std, string $year = '', string $div = '', string $sem = ''): array {
    $db = getDB();
    // 1. Try class_subjects if specific class key provided
    if ($year !== '' && $div !== '' && $sem !== '') {
        $stmt = $db->prepare("SELECT subject_name, fa_max, sa_max FROM class_subjects WHERE academic_year = ? AND std = ? AND division = ? AND semester = ? ORDER BY sort_order, id");
        $stmt->execute([$year, $std, $div, $sem]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) return $rows;
    }

    // 2. Try report_card for this standard across any year
    $stmt = $db->prepare("SELECT subject_name, MAX(fa_max) AS fa_max, MAX(sa_max) AS sa_max FROM report_card WHERE std = ? GROUP BY subject_name ORDER BY MIN(id)");
    $stmt->execute([$std]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($rows)) return $rows;

    // 3. Fallback standard subjects
    return [
        ['subject_name' => 'English', 'fa_max' => 70.0, 'sa_max' => 30.0],
        ['subject_name' => 'Hindi',   'fa_max' => 70.0, 'sa_max' => 30.0],
        ['subject_name' => 'Marathi', 'fa_max' => 70.0, 'sa_max' => 30.0],
        ['subject_name' => 'Maths',   'fa_max' => 70.0, 'sa_max' => 30.0],
        ['subject_name' => 'Science', 'fa_max' => 70.0, 'sa_max' => 30.0],
    ];
}

/* Fetch student list for a class with exact roll numbers */
function rcGetClassStudentsForPromotion(string $year, string $std, string $div, string $sem): array {
    $db = getDB();
    $stmt = $db->prepare("SELECT roll_no, student_name, date_of_birth, COUNT(DISTINCT subject_name) AS subject_count 
                          FROM report_card 
                          WHERE academic_year = ? AND std = ? AND division = ? AND semester = ?
                          GROUP BY roll_no, student_name, date_of_birth");
    $stmt->execute([$year, $std, $div, $sem]);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Natural sort by roll number
    usort($students, function($a, $b) {
        $na = (int)$a['roll_no'];
        $nb = (int)$b['roll_no'];
        if ($na > 0 && $nb > 0 && $na !== $nb) return $na <=> $nb;
        return strnatcasecmp((string)$a['roll_no'], (string)$b['roll_no']);
    });
    return $students;
}

/* Fetch promotion overview across all 10 standards */
function rcGetPromotionOverview(string $sourceYear, string $targetYear): array {
    $db = getDB();

    // Source classes
    $stmt = $db->prepare("SELECT std, division, semester, COUNT(DISTINCT roll_no) AS student_count 
                          FROM report_card 
                          WHERE academic_year = ? 
                          GROUP BY std, division, semester");
    $stmt->execute([$sourceYear]);
    $sourceClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Target classes
    $stmt = $db->prepare("SELECT std, division, semester, COUNT(DISTINCT roll_no) AS student_count 
                          FROM report_card 
                          WHERE academic_year = ? 
                          GROUP BY std, division, semester");
    $stmt->execute([$targetYear]);
    $targetClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $targetMap = [];
    foreach ($targetClasses as $tc) {
        $key = "{$tc['std']}|{$tc['division']}|{$tc['semester']}";
        $targetMap[$key] = (int)$tc['student_count'];
    }

    $overview = [];
    foreach ($sourceClasses as $sc) {
        $curStd = $sc['std'];
        $curDiv = $sc['division'];
        $curSem = $sc['semester'];
        $count  = (int)$sc['student_count'];

        $numStd = (int)$curStd;
        $isTerminal = ($numStd >= 10);
        $nextStd = $isTerminal ? 'Graduated' : (string)($numStd + 1);

        $targetKey = "{$nextStd}|{$curDiv}|{$curSem}";
        $promotedCount = $targetMap[$targetKey] ?? 0;
        $isPromoted = (!$isTerminal && $promotedCount > 0);

        $overview[] = [
            'source_year'    => $sourceYear,
            'source_std'     => $curStd,
            'source_div'     => $curDiv,
            'source_sem'     => $curSem,
            'student_count'  => $count,
            'target_year'    => $targetYear,
            'target_std'     => $nextStd,
            'target_div'     => $curDiv,
            'target_sem'     => $curSem,
            'is_terminal'    => $isTerminal,
            'is_promoted'    => $isPromoted,
            'promoted_count' => $promotedCount,
        ];
    }

    // Sort overview by std (1..10) then division
    usort($overview, function($a, $b) {
        $sa = (int)$a['source_std'];
        $sb = (int)$b['source_std'];
        if ($sa !== $sb) return $sa <=> $sb;
        return strcmp($a['source_div'], $b['source_div']);
    });

    return $overview;
}

/* Execute promotion of selected students with exact roll numbers preserved */
function rcPromoteClassWithChecklist(
    string $sourceYear, string $sourceStd, string $sourceDiv, string $sourceSem,
    string $targetYear, string $targetStd, string $targetDiv, string $targetSem,
    array $selectedRolls,
    string $adminUser = 'Admin'
): array {
    $db = getDB();

    if (empty($selectedRolls)) {
        return ['ok' => false, 'error' => 'No students were selected for promotion.'];
    }

    // Guard: Terminal class check
    if ((int)$sourceStd >= 10 || $targetStd === 'Graduated') {
        return ['ok' => false, 'error' => 'Standard 10 is the terminal graduating class and cannot be promoted to Standard 11.'];
    }

    // 1. Fetch Target Subjects
    $targetSubjects = rcGetStandardSubjects($targetStd, $targetYear, $targetDiv, $targetSem);
    if (empty($targetSubjects)) {
        $targetSubjects = rcGetStandardSubjects($sourceStd, $sourceYear, $sourceDiv, $sourceSem);
    }

    // 2. Save subject config for target class
    $classKey = "$targetYear|$targetStd|$targetDiv|$targetSem";
    rcSaveClassSubjects($classKey, $targetSubjects);

    // 3. Fetch Selected Students from Source Class
    $placeholders = implode(',', array_fill(0, count($selectedRolls), '?'));
    $sql = "SELECT DISTINCT roll_no, student_name, date_of_birth 
            FROM report_card 
            WHERE academic_year = ? AND std = ? AND division = ? AND semester = ? AND roll_no IN ($placeholders)";
    $params = array_merge([$sourceYear, $sourceStd, $sourceDiv, $sourceSem], $selectedRolls);
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    $students = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($students)) {
        return ['ok' => false, 'error' => 'No matching student records found in source class.'];
    }

    try {
        $db->beginTransaction();

        $checkExist = $db->prepare("SELECT 1 FROM report_card WHERE academic_year = ? AND std = ? AND division = ? AND semester = ? AND roll_no = ? AND subject_name = ? LIMIT 1");

        $insertStmt = $db->prepare("
            INSERT INTO report_card 
            (academic_year, std, division, semester, roll_no, student_name, subject_name,
             fa_max, fa_obtained, sa_max, sa_obtained, remark, attendance, parents_sign, date_of_birth)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0.0, ?, 0.0, '', '', 0, ?)
        ");

        $insertedStudents = 0;
        foreach ($students as $stu) {
            $exactRoll = trim((string)$stu['roll_no']);
            $name = trim((string)$stu['student_name']);
            $dob  = trim((string)($stu['date_of_birth'] ?? ''));

            foreach ($targetSubjects as $sub) {
                $subName = trim((string)$sub['subject_name']);
                $faMax   = (float)($sub['fa_max'] ?? 70.0);
                $saMax   = (float)($sub['sa_max'] ?? 30.0);

                // Prevent duplicate row
                $checkExist->execute([$targetYear, $targetStd, $targetDiv, $targetSem, $exactRoll, $subName]);
                if ($checkExist->fetchColumn()) {
                    continue;
                }

                $insertStmt->execute([
                    $targetYear,
                    $targetStd,
                    $targetDiv,
                    $targetSem,
                    $exactRoll, // EXACT same roll number preserved!
                    $name,
                    $subName,
                    $faMax,
                    $saMax,
                    $dob
                ]);
            }
            $insertedStudents++;

            // Record previous standard baseline snapshot in student_promotion_history
            try {
                rcEnsureArchiveTables();
                $prevScoreStmt = $db->prepare("
                    SELECT SUM(fa_obtained + sa_obtained) as tot_obt, SUM(fa_max + sa_max) as tot_max
                    FROM report_card
                    WHERE academic_year = ? AND std = ? AND division = ? AND semester = ? AND roll_no = ?
                ");
                $prevScoreStmt->execute([$sourceYear, $sourceStd, $sourceDiv, $sourceSem, $exactRoll]);
                $prevScore = $prevScoreStmt->fetch(PDO::FETCH_ASSOC);
                $pObt = (float)($prevScore['tot_obt'] ?? 0);
                $pMax = (float)($prevScore['tot_max'] ?? 0);
                $pPct = $pMax > 0 ? round(($pObt / $pMax) * 100, 2) : 0.0;
                $pGrade = calculateGrade($pPct, $sourceStd);

                $promoHistStmt = $db->prepare("
                    INSERT INTO student_promotion_history
                    (source_year, source_std, source_div, source_sem, target_year, target_std, target_div, target_sem, roll_no, student_name, prev_obtained, prev_max_total, prev_percentage, prev_grade, prev_status, promoted_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'PROMOTED', ?)
                ");
                $promoHistStmt->execute([
                    $sourceYear, $sourceStd, $sourceDiv, $sourceSem,
                    $targetYear, $targetStd, $targetDiv, $targetSem,
                    $exactRoll, $name, $pObt, $pMax, $pPct, $pGrade, $adminUser
                ]);
            } catch (Exception $e) {
                // Non-fatal logging
            }
        }

        $db->commit();

        // Add persistent system notification
        $notifTitle = "STD {$sourceStd}{$sourceDiv} Promoted → STD {$targetStd}{$targetDiv}";
        $notifMsg = "{$insertedStudents} students promoted from {$sourceYear} STD {$sourceStd}{$sourceDiv} to {$targetYear} STD {$targetStd}{$targetDiv} by {$adminUser}.";
        $notifLink = "results.php?class=" . urlencode($classKey);
        rcAddNotification($notifTitle, $notifMsg, 'promotion', $notifLink);

        return [
            'ok' => true,
            'promoted_count' => $insertedStudents,
            'target_class' => $classKey,
            'message' => "Successfully promoted {$insertedStudents} students to STD {$targetStd} {$targetDiv} ({$targetYear})."
        ];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'error' => 'Database error: ' . $e->getMessage()];
    }
}

/* Rollback / delete a promoted cohort from target year without touching source */
function rcRollbackClassPromotion(string $targetYear, string $targetStd, string $targetDiv, string $targetSem): array {
    $db = getDB();
    try {
        $db->beginTransaction();
        $del = $db->prepare("DELETE FROM report_card WHERE academic_year = ? AND std = ? AND division = ? AND semester = ?");
        $del->execute([$targetYear, $targetStd, $targetDiv, $targetSem]);
        $count = $del->rowCount();
        $db->commit();

        rcAddNotification(
            "Promotion Reverted: STD {$targetStd}{$targetDiv}",
            "Removed cohort entries for {$targetYear} STD {$targetStd}{$targetDiv}.",
            'system',
            'promote_students.php'
        );

        return ['ok' => true, 'deleted_rows' => $count];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/* ─────────────────────────────────────────────────────────────────────────────
   UNIT TEST RESULTS ENGINE
   ───────────────────────────────────────────────────────────────────────────── */

/* Create Unit Test tables if not exists (idempotent). */
function rcEnsureUnitTestTables(): void {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS unit_test_results (
        id INT AUTO_INCREMENT PRIMARY KEY,
        academic_year VARCHAR(20) NOT NULL,
        std VARCHAR(10) NOT NULL,
        division VARCHAR(5) NOT NULL,
        test_type VARCHAR(30) NOT NULL DEFAULT 'Unit Test 1',
        roll_no VARCHAR(10) NOT NULL,
        student_name VARCHAR(100) NOT NULL,
        gender VARCHAR(10) NULL,
        subject_name VARCHAR(50) NOT NULL,
        max_marks DECIMAL(5,1) NOT NULL DEFAULT 20.0,
        marks_obtained DECIMAL(5,1) NULL DEFAULT 0.0,
        is_absent TINYINT(1) NOT NULL DEFAULT 0,
        grade VARCHAR(5) NULL,
        remark VARCHAR(150) NULL,
        attendance VARCHAR(20) NULL,
        parents_sign TINYINT(1) NOT NULL DEFAULT 0,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_ut_student_subj (academic_year, std, division, test_type, roll_no, subject_name),
        INDEX idx_ut_lookup (academic_year, std, division, test_type),
        INDEX idx_ut_roll (academic_year, std, division, test_type, roll_no)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS unit_test_config (
        id INT AUTO_INCREMENT PRIMARY KEY,
        academic_year VARCHAR(20) NOT NULL,
        std VARCHAR(10) NOT NULL,
        test_type VARCHAR(30) NOT NULL DEFAULT 'Unit Test 1',
        subject_name VARCHAR(50) NOT NULL,
        max_marks DECIMAL(5,1) NOT NULL DEFAULT 20.0,
        sort_order INT NOT NULL DEFAULT 0,
        UNIQUE KEY uniq_ut_cfg (academic_year, std, test_type, subject_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/* Calculate official Unit Test grade and remark from percentage */
function rcCalculateUnitTestGradeAndRemark(?float $pct): array {
    if ($pct === null) {
        return ['grade' => '-', 'remark' => ''];
    }
    if ($pct >= 91.0) {
        return ['grade' => 'A1', 'remark' => 'Excellent'];
    } elseif ($pct >= 81.0) {
        return ['grade' => 'A2', 'remark' => 'V.Good'];
    } elseif ($pct >= 71.0) {
        return ['grade' => 'B1', 'remark' => 'Good'];
    } elseif ($pct >= 61.0) {
        return ['grade' => 'B2', 'remark' => 'Satisfactory'];
    } elseif ($pct >= 51.0) {
        return ['grade' => 'C1', 'remark' => 'Fair'];
    } elseif ($pct >= 41.0) {
        return ['grade' => 'C2', 'remark' => 'Average'];
    } elseif ($pct >= 35.0) {
        return ['grade' => 'D', 'remark' => 'Needs Improvement'];
    } else {
        return ['grade' => 'E', 'remark' => 'Needs Major Improvement'];
    }
}

/* Get distinct class options for Unit Tests */
function rcGetUnitTestClassOptions(): array {
    rcEnsureUnitTestTables();
    $db = getDB();

    // Classes existing in unit_test_results
    $stmt = $db->query("SELECT academic_year, std, division, test_type, COUNT(DISTINCT roll_no) AS student_count 
                        FROM unit_test_results 
                        GROUP BY academic_year, std, division, test_type");
    $utClasses = $stmt->fetchAll(PDO::FETCH_ASSOC);

    usort($utClasses, function ($a, $b) {
        if ($a['academic_year'] !== $b['academic_year']) {
            return strcmp($b['academic_year'], $a['academic_year']);
        }
        $sa = (int)$a['std'];
        $sb = (int)$b['std'];
        if ($sa !== $sb) return $sa <=> $sb;
        $c = strcmp($a['std'], $b['std']);
        if ($c !== 0) return $c;
        $d = strcmp($a['division'], $b['division']);
        if ($d !== 0) return $d;
        return strcmp($a['test_type'], $b['test_type']);
    });

    return $utClasses;
}

/* Get Unit Test subjects and max marks for a standard */
function rcGetUnitTestSubjects(string $year, string $std, string $testType = 'Unit Test 1'): array {
    rcEnsureUnitTestTables();
    $db = getDB();

    // 1. Try unit_test_config
    $stmt = $db->prepare("SELECT subject_name, max_marks FROM unit_test_config WHERE academic_year = ? AND std = ? AND test_type = ? ORDER BY sort_order, id");
    $stmt->execute([$year, $std, $testType]);
    $cfg = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($cfg)) return $cfg;

    // 2. Try unit_test_results for this class
    $stmt = $db->prepare("SELECT subject_name, MAX(max_marks) AS max_marks FROM unit_test_results WHERE academic_year = ? AND std = ? AND test_type = ? GROUP BY subject_name ORDER BY MIN(id)");
    $stmt->execute([$year, $std, $testType]);
    $fromResults = $stmt->fetchAll(PDO::FETCH_ASSOC);
    if (!empty($fromResults)) return $fromResults;

    // 3. Fallback: pull standard subjects from report_card or class_subjects, default 20 max marks
    $stmt = $db->prepare("SELECT DISTINCT subject_name FROM report_card WHERE std = ? ORDER BY id");
    $stmt->execute([$std]);
    $subjects = $stmt->fetchAll(PDO::FETCH_COLUMN);

    if (empty($subjects)) {
        $subjects = ['English', 'Hindi', 'Marathi', 'Maths'];
    }

    $out = [];
    foreach ($subjects as $s) {
        $out[] = [
            'subject_name' => $s,
            'max_marks'    => 20.0,
        ];
    }
    return $out;
}

/* Save / configure Unit Test subjects and max marks */
function rcSaveUnitTestConfig(string $year, string $std, string $testType, array $subjects): bool {
    rcEnsureUnitTestTables();
    $db = getDB();
    try {
        $db->beginTransaction();
        $del = $db->prepare("DELETE FROM unit_test_config WHERE academic_year = ? AND std = ? AND test_type = ?");
        $del->execute([$year, $std, $testType]);

        $ins = $db->prepare("INSERT INTO unit_test_config (academic_year, std, test_type, subject_name, max_marks, sort_order) VALUES (?, ?, ?, ?, ?, ?)");
        $order = 1;
        foreach ($subjects as $s) {
            $name = trim((string)($s['subject_name'] ?? ''));
            if ($name === '') continue;
            $max = (float)($s['max_marks'] ?? 20.0);
            if ($max <= 0) $max = 20.0;
            $ins->execute([$year, $std, $testType, $name, $max, $order++]);
        }
        $db->commit();
        return true;
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return false;
    }
}

/* Fetch complete matrix dataset for a Unit Test class */
function rcGetUnitTestClassData(string $year, string $std, string $division, string $testType): array {
    rcEnsureUnitTestTables();
    $db = getDB();

    // 1. Fetch subjects & max marks
    $subjects = rcGetUnitTestSubjects($year, $std, $testType);

    // 2. Fetch all student records for this class
    $stmt = $db->prepare("SELECT roll_no, student_name, gender, subject_name, max_marks, marks_obtained, is_absent, grade, remark, attendance, parents_sign, parent_phone 
                          FROM unit_test_results 
                          WHERE academic_year = ? AND std = ? AND division = ? AND test_type = ?
                          ORDER BY CAST(roll_no AS UNSIGNED), roll_no, id");
    $stmt->execute([$year, $std, $division, $testType]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Group by roll number
    $students = [];
    foreach ($rows as $r) {
        $roll = (string)$r['roll_no'];
        if (!isset($students[$roll])) {
            $students[$roll] = [
                'roll_no'      => $roll,
                'student_name' => $r['student_name'],
                'gender'       => $r['gender'] ?? '',
                'attendance'   => $r['attendance'] ?? '',
                'parents_sign' => (int)$r['parents_sign'],
                'parent_phone' => $r['parent_phone'] ?? '',
                'subjects'     => [],
                'total_obtained' => 0.0,
                'total_max'      => 0.0,
                'has_absent'     => false,
                'all_absent'     => true,
            ];
        }

        $sName = $r['subject_name'];
        $sMax  = (float)$r['max_marks'];
        $sObt  = ($r['marks_obtained'] !== null) ? (float)$r['marks_obtained'] : null;
        $isAb  = (int)$r['is_absent'];

        $students[$roll]['subjects'][$sName] = [
            'max_marks'      => $sMax,
            'marks_obtained' => $sObt,
            'is_absent'      => $isAb,
        ];

        $students[$roll]['total_max'] += $sMax;
        if ($isAb) {
            $students[$roll]['has_absent'] = true;
        } else {
            $students[$roll]['all_absent'] = false;
            $students[$roll]['total_obtained'] += ($sObt !== null ? $sObt : 0.0);
        }
    }

    // Compute student percentage, grade, and remark
    foreach ($students as $roll => &$st) {
        if ($st['all_absent'] || $st['total_max'] <= 0) {
            $st['percentage'] = null;
            $st['grade'] = '-';
            $st['remark'] = 'Absent';
        } else {
            $pct = round(($st['total_obtained'] / $st['total_max']) * 100, 2);
            $st['percentage'] = $pct;
            $gr = rcCalculateUnitTestGradeAndRemark($pct);
            $st['grade'] = $gr['grade'];
            $st['remark'] = $gr['remark'];
        }
    }
    unset($st);

    // Natural sort students by roll number
    uasort($students, function ($a, $b) {
        $na = (int)$a['roll_no'];
        $nb = (int)$b['roll_no'];
        if ($na > 0 && $nb > 0 && $na !== $nb) return $na <=> $nb;
        return strnatcasecmp($a['roll_no'], $b['roll_no']);
    });

    return [
        'academic_year' => $year,
        'std'           => $std,
        'division'      => $division,
        'test_type'     => $testType,
        'subjects'      => $subjects,
        'students'      => array_values($students),
        'total_max'     => array_sum(array_column($subjects, 'max_marks')),
    ];
}

/* Save / update bulk Unit Test marks */
function rcSaveUnitTestMarksBulk(
    string $year, string $std, string $division, string $testType,
    array $marksPayload, array $remarksPayload = [], array $attnPayload = [],
    string $adminUser = 'Admin'
): array {
    rcEnsureUnitTestTables();
    $db = getDB();

    $subjects = rcGetUnitTestSubjects($year, $std, $testType);
    $subjectMaxMap = [];
    foreach ($subjects as $s) {
        $subjectMaxMap[$s['subject_name']] = (float)$s['max_marks'];
    }

    try {
        $db->beginTransaction();

        $updStmt = $db->prepare("
            INSERT INTO unit_test_results 
            (academic_year, std, division, test_type, roll_no, student_name, gender, subject_name, max_marks, marks_obtained, is_absent, grade, remark, attendance)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                marks_obtained = VALUES(marks_obtained),
                is_absent = VALUES(is_absent),
                grade = VALUES(grade),
                remark = VALUES(remark),
                attendance = VALUES(attendance),
                updated_at = CURRENT_TIMESTAMP
        ");

        $updatedCount = 0;
        foreach ($marksPayload as $roll => $studentData) {
            $rollStr = trim((string)$roll);
            $sName = trim((string)($studentData['name'] ?? ''));
            $gender = trim((string)($studentData['gender'] ?? ''));
            $attn = trim((string)($attnPayload[$rollStr] ?? ($studentData['attendance'] ?? '')));
            $customRemark = trim((string)($remarksPayload[$rollStr] ?? ''));

            $subMarks = $studentData['marks'] ?? [];
            if (!is_array($subMarks)) continue;

            // Compute student total for grading
            $totObt = 0.0;
            $totMax = 0.0;
            $allAb = true;

            foreach ($subMarks as $subj => $val) {
                $maxM = $subjectMaxMap[$subj] ?? 20.0;
                $totMax += $maxM;
                $isAb = ($val === '-' || $val === 'AB' || $val === 'ab');
                if (!$isAb) {
                    $allAb = false;
                    $totObt += (float)$val;
                }
            }

            $pct = (!$allAb && $totMax > 0) ? round(($totObt / $totMax) * 100, 2) : null;
            $autoGr = rcCalculateUnitTestGradeAndRemark($pct);
            $grade = $autoGr['grade'];
            $remark = ($customRemark !== '') ? $customRemark : $autoGr['remark'];

            // Save each subject entry
            foreach ($subMarks as $subj => $val) {
                $maxM = $subjectMaxMap[$subj] ?? 20.0;
                $isAb = ($val === '-' || $val === 'AB' || $val === 'ab') ? 1 : 0;
                $obtVal = $isAb ? null : round((float)$val, 1);

                $updStmt->execute([
                    $year, $std, $division, $testType,
                    $rollStr, $sName, $gender, $subj,
                    $maxM, $obtVal, $isAb, $grade, $remark, $attn
                ]);
            }
            $updatedCount++;
        }

        $db->commit();

        // Fire notification
        $title = "Unit Test Marks Saved: STD {$std}{$division}";
        $msg = "{$testType} marks saved for {$updatedCount} students in STD {$std} {$division} ({$year}) by {$adminUser}.";
        $link = "unit_tests.php?year=" . urlencode($year) . "&std=" . urlencode($std) . "&division=" . urlencode($division) . "&test_type=" . urlencode($testType);
        rcAddNotification($title, $msg, 'unit_test', $link);

        return ['ok' => true, 'updated' => $updatedCount, 'message' => "Successfully saved marks for {$updatedCount} students."];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/* 1-Click Roster Sync: Pull active students from class cohort into Unit Test */
function rcSyncUnitTestRoster(
    string $sourceYear, string $std, string $division, string $testType,
    string $adminUser = 'Admin'
): array {
    rcEnsureUnitTestTables();
    $db = getDB();

    // 1. Fetch active students from report_card
    $stmt = $db->prepare("SELECT DISTINCT roll_no, student_name, date_of_birth 
                          FROM report_card 
                          WHERE academic_year = ? AND std = ? AND division = ?
                          ORDER BY CAST(roll_no AS UNSIGNED), roll_no");
    $stmt->execute([$sourceYear, $std, $division]);
    $cohort = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($cohort)) {
        return ['ok' => false, 'error' => "No student records found in active class cohort ({$sourceYear} STD {$std} {$division})."];
    }

    // 2. Fetch or establish standard subjects for unit test
    $subjects = rcGetUnitTestSubjects($sourceYear, $std, $testType);

    try {
        $db->beginTransaction();

        $checkExist = $db->prepare("SELECT 1 FROM unit_test_results WHERE academic_year = ? AND std = ? AND division = ? AND test_type = ? AND roll_no = ? AND subject_name = ? LIMIT 1");

        $ins = $db->prepare("
            INSERT INTO unit_test_results 
            (academic_year, std, division, test_type, roll_no, student_name, subject_name, max_marks, marks_obtained, is_absent, grade, remark, attendance)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, 0.0, 0, 'E', 'Needs Major Improvement', '')
        ");

        $addedCount = 0;
        foreach ($cohort as $stu) {
            $roll = trim((string)$stu['roll_no']);
            $name = trim((string)$stu['student_name']);

            foreach ($subjects as $s) {
                $subName = $s['subject_name'];
                $maxM    = (float)$s['max_marks'];

                $checkExist->execute([$sourceYear, $std, $division, $testType, $roll, $subName]);
                if ($checkExist->fetchColumn()) continue;

                $ins->execute([
                    $sourceYear, $std, $division, $testType,
                    $roll, $name, $subName, $maxM
                ]);
            }
            $addedCount++;
        }

        $db->commit();

        $title = "Roster Synced: STD {$std}{$division} → {$testType}";
        $msg = "{$addedCount} students synchronized from {$sourceYear} STD {$std} {$division} into {$testType} by {$adminUser}.";
        $link = "unit_tests.php?year=" . urlencode($sourceYear) . "&std=" . urlencode($std) . "&division=" . urlencode($division) . "&test_type=" . urlencode($testType);
        rcAddNotification($title, $msg, 'unit_test', $link);

        return ['ok' => true, 'count' => $addedCount, 'message' => "Successfully synced {$addedCount} students into {$testType}."];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/* Delete / clear a Unit Test class cohort */
function rcDeleteUnitTestClass(string $year, string $std, string $division, string $testType): array {
    rcEnsureUnitTestTables();
    $db = getDB();
    try {
        $db->beginTransaction();
        $del = $db->prepare("DELETE FROM unit_test_results WHERE academic_year = ? AND std = ? AND division = ? AND test_type = ?");
        $del->execute([$year, $std, $division, $testType]);
        $cnt = $del->rowCount();
        $db->commit();
        return ['ok' => true, 'deleted_rows' => $cnt];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/* Fetch list of distinct students enrolled in a Unit Test cohort */
function rcListUnitTestStudents(string $year, string $std, string $division, string $testType): array {
    rcEnsureUnitTestTables();
    $db = getDB();
    $stmt = $db->prepare("SELECT DISTINCT roll_no, student_name, gender, attendance 
                          FROM unit_test_results 
                          WHERE academic_year = ? AND std = ? AND division = ? AND test_type = ?
                          ORDER BY CAST(roll_no AS UNSIGNED), roll_no");
    $stmt->execute([$year, $std, $division, $testType]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/* Get next available roll number for a Unit Test class cohort */
function rcGetNextUnitTestRollNumber(string $year, string $std, string $division, string $testType): int {
    rcEnsureUnitTestTables();
    $db = getDB();
    $stmt = $db->prepare("SELECT MAX(CAST(roll_no AS UNSIGNED)) AS max_roll 
                          FROM unit_test_results 
                          WHERE academic_year = ? AND std = ? AND division = ? AND test_type = ?");
    $stmt->execute([$year, $std, $division, $testType]);
    $val = $stmt->fetchColumn();
    return ($val !== null && $val !== false && (int)$val > 0) ? ((int)$val + 1) : 1;
}

/* Fetch single student Unit Test report */
function rcGetStudentUnitTestReport(string $year, string $std, string $division, string $testType, string $roll): ?array {
    rcEnsureUnitTestTables();
    $db = getDB();
    $stmt = $db->prepare("SELECT roll_no, student_name, gender, subject_name, max_marks, marks_obtained, is_absent, grade, remark, attendance, parents_sign, parent_phone
                          FROM unit_test_results
                          WHERE academic_year = ? AND std = ? AND division = ? AND test_type = ? AND roll_no = ?
                          ORDER BY id");
    $stmt->execute([$year, $std, $division, $testType, $roll]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($rows)) {
        return null;
    }

    $first = $rows[0];
    $subjects = [];
    $totObtained = 0.0;
    $totMax = 0.0;
    $allAbsent = true;
    $hasAbsent = false;

    foreach ($rows as $r) {
        $sName = $r['subject_name'];
        $maxM = (float)$r['max_marks'];
        $isAb = (int)$r['is_absent'];
        $obtM = ($r['marks_obtained'] !== null) ? (float)$r['marks_obtained'] : null;

        $subjects[$sName] = [
            'subject_name'   => $sName,
            'max_marks'      => $maxM,
            'marks_obtained' => $obtM,
            'is_absent'      => $isAb,
        ];

        $totMax += $maxM;
        if ($isAb) {
            $hasAbsent = true;
        } else {
            $allAbsent = false;
            $totObtained += ($obtM !== null ? $obtM : 0.0);
        }
    }

    $percentage = (!$allAbsent && $totMax > 0) ? round(($totObtained / $totMax) * 100, 2) : null;
    $calc = rcCalculateUnitTestGradeAndRemark($percentage);

    return [
        'academic_year'   => $year,
        'std'             => $std,
        'division'        => $division,
        'test_type'       => $testType,
        'roll_no'         => (string)$first['roll_no'],
        'student_name'    => $first['student_name'],
        'gender'          => $first['gender'] ?? '',
        'attendance'      => $first['attendance'] ?? '',
        'parents_sign'    => (int)$first['parents_sign'],
        'parent_phone'    => $first['parent_phone'] ?? '',
        'remark'          => $first['remark'] ?? $calc['remark'],
        'grade'           => $first['grade'] ?? $calc['grade'],
        'percentage'      => $percentage,
        'total_obtained'  => $totObtained,
        'total_max'       => $totMax,
        'all_absent'      => $allAbsent,
        'has_absent'      => $hasAbsent,
        'subjects'        => $subjects,
    ];
}

/* Save / update single student Unit Test entry */
function rcSaveUnitTestEntry(array $data, string $adminUser = 'Admin'): array {
    rcEnsureUnitTestTables();
    $db = getDB();

    $year     = trim($data['academic_year'] ?? '2025-26');
    $std      = trim($data['std'] ?? '');
    $division = trim($data['division'] ?? 'A');
    $testType = trim($data['test_type'] ?? 'Unit Test 1');
    $rollNo   = trim((string)($data['roll_no'] ?? ''));
    $name     = trim($data['student_name'] ?? '');
    $gender   = trim($data['gender'] ?? '');
    $attn     = trim($data['attendance'] ?? '');
    $remarkIn = trim($data['remark'] ?? '');
    $sign     = !empty($data['parents_sign']) ? 1 : 0;
    $phoneRaw = trim((string)($data['parent_phone'] ?? ''));
    $phone    = $phoneRaw !== '' ? preg_replace('/\D+/', '', $phoneRaw) : null;
    $subjects = $data['subjects'] ?? [];

    if ($std === '' || $rollNo === '' || $name === '') {
        return ['ok' => false, 'error' => 'STD, Roll No, and Student Name are required.'];
    }
    if (empty($subjects) || !is_array($subjects)) {
        return ['ok' => false, 'error' => 'At least one subject mark is required.'];
    }

    try {
        $db->beginTransaction();

        // 1. Calculate overall grade and remark
        $totObt = 0.0;
        $totMax = 0.0;
        $allAb = true;

        foreach ($subjects as $s) {
            $sMax = (float)($s['max_marks'] ?? 20.0);
            if ($sMax <= 0) $sMax = 20.0;
            $totMax += $sMax;

            $isAb = !empty($s['is_absent']) ? 1 : 0;
            if (!$isAb) {
                $allAb = false;
                $totObt += (float)($s['marks_obtained'] ?? 0.0);
            }
        }

        $pct = (!$allAb && $totMax > 0) ? round(($totObt / $totMax) * 100, 2) : null;
        $autoGr = rcCalculateUnitTestGradeAndRemark($pct);
        $grade = $autoGr['grade'];
        $finalRemark = ($remarkIn !== '') ? $remarkIn : $autoGr['remark'];

        // 2. Upsert each subject row using INSERT ... ON DUPLICATE KEY UPDATE
        $updStmt = $db->prepare("
            INSERT INTO unit_test_results 
            (academic_year, std, division, test_type, roll_no, student_name, gender, subject_name, max_marks, marks_obtained, is_absent, grade, remark, attendance, parents_sign, parent_phone)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE 
                student_name = VALUES(student_name),
                gender = VALUES(gender),
                max_marks = VALUES(max_marks),
                marks_obtained = VALUES(marks_obtained),
                is_absent = VALUES(is_absent),
                grade = VALUES(grade),
                remark = VALUES(remark),
                attendance = VALUES(attendance),
                parents_sign = VALUES(parents_sign),
                parent_phone = COALESCE(VALUES(parent_phone), parent_phone),
                updated_at = CURRENT_TIMESTAMP
        ");

        $submittedSubjectNames = [];
        foreach ($subjects as $s) {
            $sName = trim((string)($s['subject_name'] ?? ''));
            if ($sName === '') continue;
            $submittedSubjectNames[] = $sName;

            $sMax = (float)($s['max_marks'] ?? 20.0);
            if ($sMax <= 0) $sMax = 20.0;
            $isAb = !empty($s['is_absent']) ? 1 : 0;
            $obtVal = $isAb ? null : round((float)($s['marks_obtained'] ?? 0.0), 1);

            $updStmt->execute([
                $year, $std, $division, $testType,
                $rollNo, $name, $gender, $sName,
                $sMax, $obtVal, $isAb, $grade, $finalRemark, $attn, $sign, $phone
            ]);
        }

        // Clean up any old subjects for this student not in the submitted list
        if (!empty($submittedSubjectNames)) {
            $inPlaceholders = implode(',', array_fill(0, count($submittedSubjectNames), '?'));
            $cleanStmt = $db->prepare("DELETE FROM unit_test_results 
                                       WHERE academic_year = ? AND std = ? AND division = ? AND test_type = ? AND roll_no = ? 
                                       AND subject_name NOT IN ($inPlaceholders)");
            $cleanStmt->execute(array_merge([$year, $std, $division, $testType, $rollNo], $submittedSubjectNames));
        }

        $db->commit();

        // 3. System Notification
        $title = "Unit Test Entry Saved: Roll {$rollNo} ({$name})";
        $msg = "{$testType} marks updated for {$name} (Roll {$rollNo}) in STD {$std} {$division} ({$year}) by {$adminUser}.";
        $link = "unit_test_entry.php?year=" . urlencode($year) . "&std=" . urlencode($std) . "&division=" . urlencode($division) . "&test_type=" . urlencode($testType) . "&roll=" . urlencode($rollNo);
        rcAddNotification($title, $msg, 'unit_test', $link);

        return [
            'ok'      => true,
            'roll'    => $rollNo,
            'message' => "Successfully saved {$testType} entry for {$name} (Roll {$rollNo})."
        ];
    } catch (\Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

/* ═══════════════════════════════════════════════════════════════════
   WHATSAPP & PUBLIC PARENT GATE HELPERS
   Signed, tamper-proof tokens for parent links without admin login
   ═══════════════════════════════════════════════════════════════════ */

function rcGetBaseHostUrl(): string {
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (($_SERVER['SERVER_PORT'] ?? 80) == 443) ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    return $scheme . '://' . $host;
}

function rcGenerateParentToken(string $type, array $params, int $ttlDays = 90): string {
    if (!defined('JWT_SECRET') || JWT_SECRET === '') {
        throw new RuntimeException('JWT_SECRET is not configured.');
    }
    $issuedAt = time();
    $expiresAt = $issuedAt + ($ttlDays * 86400);
    $payload = [
        'type' => $type,
        'params' => $params,
        'iat' => $issuedAt,
        'exp' => $expiresAt,
    ];
    $payloadB64 = base64_encode(json_encode($payload));
    $sig = substr(hash_hmac('sha256', $payloadB64, JWT_SECRET, true), 0, 16);
    $sigB64 = rtrim(base64_encode($sig), '=');
    return $payloadB64 . '.' . $sigB64;
}

function rcVerifyParentToken(string $type, array $params, string $token): bool {
    if (trim($token) === '') return false;
    
    // New format: payloadB64.sigB64 (with embedded expiry)
    if (strpos($token, '.') !== false) {
        [$payloadB64, $sigB64] = explode('.', $token, 2);
        $payloadJson = base64_decode($payloadB64, true);
        if ($payloadJson === false) return false;
        $payload = json_decode($payloadJson, true);
        if (!$payload || !isset($payload['type'], $payload['params'], $payload['iat'], $payload['exp'])) {
            return false;
        }
        if ($payload['type'] !== $type) return false;
        if ($payload['params'] !== $params) return false;
        if (time() > $payload['exp']) return false;
        $expectedSig = substr(hash_hmac('sha256', $payloadB64, JWT_SECRET, true), 0, 16);
        $expectedSigB64 = rtrim(base64_encode($expectedSig), '=');
        return hash_equals($expectedSigB64, $sigB64);
    }
    
    // Legacy format: simple HMAC (no expiry) - for backward compatibility
    $legacyPayload = $type . '|' . implode('|', array_map('strval', $params));
    $legacyExpected = substr(hash_hmac('sha256', $legacyPayload, JWT_SECRET), 0, 16);
    return hash_equals($legacyExpected, $token);
}

function rcGetPublicCardUrl(string $classKey, string $roll): string {
    $token = rcGenerateParentToken('card', [$classKey, $roll]);
    return rcGetBaseHostUrl() . BASE_URL . 'public_card.php?class=' . urlencode($classKey) . '&roll=' . urlencode($roll) . '&token=' . $token;
}

function rcGetPublicSlipUrl(string $year, string $std, string $division, string $testType, string $roll): string {
    $token = rcGenerateParentToken('slip', [$year, $std, $division, $testType, $roll]);
    return rcGetBaseHostUrl() . BASE_URL . 'public_slip.php?year=' . urlencode($year) . '&std=' . urlencode($std) . '&division=' . urlencode($division) . '&test_type=' . urlencode($testType) . '&roll=' . urlencode($roll) . '&token=' . $token;
}

function rcGetPublicBonafideUrl(string $grNo, string $certSerial): string {
    $token = rcGenerateParentToken('bonafide', [$grNo, $certSerial]);
    return rcGetBaseHostUrl() . BASE_URL . 'public_bonafide.php?gr=' . urlencode($grNo) . '&sr=' . urlencode($certSerial) . '&token=' . $token;
}

/* ═════════════════════════════════════════════════════════════════════════
   RECORDS ARCHIVE & ACADEMIC VAULT ENGINE
   ═════════════════════════════════════════════════════════════════════════ */

/**
 * Ensures archive tables exist (idempotent).
 */
function rcEnsureArchiveTables(): void {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS academic_year_vault (
        id INT AUTO_INCREMENT PRIMARY KEY,
        academic_year VARCHAR(20) NOT NULL UNIQUE,
        is_archived TINYINT(1) NOT NULL DEFAULT 1,
        is_locked TINYINT(1) NOT NULL DEFAULT 1,
        archived_at DATETIME NULL,
        archived_by VARCHAR(100) NULL,
        total_students INT DEFAULT 0,
        total_result_records INT DEFAULT 0,
        total_unit_test_records INT DEFAULT 0,
        total_bonafides_issued INT DEFAULT 0,
        notes TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS bonafide_issued_log (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cert_serial VARCHAR(50) NOT NULL,
        gr_no VARCHAR(50) NOT NULL,
        academic_year VARCHAR(20) NOT NULL,
        roll_no VARCHAR(20) NOT NULL,
        student_name VARCHAR(150) NOT NULL,
        std VARCHAR(10) NOT NULL,
        division VARCHAR(10) NOT NULL,
        date_of_birth DATE NULL,
        purpose VARCHAR(255) NULL,
        issued_date DATE NOT NULL,
        issued_by VARCHAR(100) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uk_serial_year (cert_serial, academic_year),
        KEY idx_year (academic_year)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $db->exec("CREATE TABLE IF NOT EXISTS student_promotion_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        source_year VARCHAR(20) NOT NULL,
        source_std VARCHAR(10) NOT NULL,
        source_div VARCHAR(10) NOT NULL,
        source_sem VARCHAR(20) NOT NULL DEFAULT 'Sem 2',
        target_year VARCHAR(20) NOT NULL,
        target_std VARCHAR(10) NOT NULL,
        target_div VARCHAR(10) NOT NULL,
        target_sem VARCHAR(20) NOT NULL DEFAULT 'Sem 1',
        roll_no VARCHAR(20) NOT NULL,
        student_name VARCHAR(150) NOT NULL,
        prev_obtained DECIMAL(7,2) DEFAULT 0,
        prev_max_total DECIMAL(7,2) DEFAULT 0,
        prev_percentage DECIMAL(5,2) DEFAULT 0,
        prev_grade VARCHAR(10) DEFAULT '',
        prev_status VARCHAR(20) DEFAULT 'PROMOTED',
        promoted_by VARCHAR(100) DEFAULT 'Admin',
        promoted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_source (source_year, source_std, source_div),
        KEY idx_target (target_year, target_std, target_div),
        KEY idx_student (roll_no, student_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Returns the currently active academic year.
 */
function rcGetActiveAcademicYear(): string {
    $db = getDB();
    $stmt = $db->query("SELECT setting_value FROM settings WHERE setting_key = 'active_academic_year' LIMIT 1");
    $val = $stmt ? $stmt->fetchColumn() : null;
    if ($val && trim((string)$val) !== '') {
        return trim((string)$val);
    }
    // Fallback to highest year present in database
    $yrStmt = $db->query("SELECT academic_year FROM unit_test_results ORDER BY academic_year DESC LIMIT 1");
    $latest = $yrStmt ? $yrStmt->fetchColumn() : null;
    if ($latest && trim((string)$latest) !== '') {
        return trim((string)$latest);
    }
    $rcYrStmt = $db->query("SELECT academic_year FROM report_card ORDER BY academic_year DESC LIMIT 1");
    $latestRc = $rcYrStmt ? $rcYrStmt->fetchColumn() : null;
    if ($latestRc && trim((string)$latestRc) !== '') {
        return trim((string)$latestRc);
    }
    return '2025-26';
}

/**
 * Returns summary cards data for all academic years in the archive vault.
 */
function rcGetVaultYearsSummary(): array {
    rcEnsureArchiveTables();
    $db = getDB();

    $years = [];
    $stmt1 = $db->query("SELECT DISTINCT academic_year FROM report_card");
    while ($r = $stmt1->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($r['academic_year'])) $years[$r['academic_year']] = true;
    }
    $stmt2 = $db->query("SELECT DISTINCT academic_year FROM unit_test_results");
    while ($r = $stmt2->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($r['academic_year'])) $years[$r['academic_year']] = true;
    }
    $stmt3 = $db->query("SELECT DISTINCT academic_year FROM academic_year_vault");
    while ($r = $stmt3->fetch(PDO::FETCH_ASSOC)) {
        if (!empty($r['academic_year'])) $years[$r['academic_year']] = true;
    }

    $currentYear = rcGetActiveAcademicYear();
    $years[$currentYear] = true;

    $yearList = array_keys($years);
    usort($yearList, function($a, $b) {
        return strcmp($b, $a);
    });

    $summaryList = [];
    $upsertStmt = $db->prepare("INSERT INTO academic_year_vault 
        (academic_year, is_archived, is_locked, total_students, total_result_records, total_unit_test_records, total_bonafides_issued)
        VALUES (?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE 
        total_students = VALUES(total_students),
        total_result_records = VALUES(total_result_records),
        total_unit_test_records = VALUES(total_unit_test_records),
        total_bonafides_issued = VALUES(total_bonafides_issued)
    ");

    $vaultRows = [];
    $vStmt = $db->query("SELECT * FROM academic_year_vault");
    while ($vr = $vStmt->fetch(PDO::FETCH_ASSOC)) {
        $vaultRows[$vr['academic_year']] = $vr;
    }

    foreach ($yearList as $yr) {
        $rcStat = $db->prepare("SELECT 
            COUNT(DISTINCT CONCAT(std,'-',division,'-',roll_no)) as unique_students,
            COUNT(*) as total_records,
            SUM(CASE WHEN (fa_obtained + sa_obtained) >= ((fa_max + sa_max) * 0.35) THEN 1 ELSE 0 END) as passed_marks,
            COUNT(DISTINCT CONCAT(std,'-',division,'-',semester)) as total_classes
            FROM report_card WHERE academic_year = ?");
        $rcStat->execute([$yr]);
        $rcRes = $rcStat->fetch(PDO::FETCH_ASSOC) ?: [];

        $utStat = $db->prepare("SELECT 
            COUNT(DISTINCT CONCAT(std,'-',division,'-',roll_no)) as unique_students,
            COUNT(*) as total_records,
            COUNT(DISTINCT test_type) as total_tests,
            COUNT(DISTINCT CONCAT(std,'-',division)) as total_classes
            FROM unit_test_results WHERE academic_year = ?");
        $utStat->execute([$yr]);
        $utRes = $utStat->fetch(PDO::FETCH_ASSOC) ?: [];

        $bfStat = $db->prepare("SELECT COUNT(*) as cnt FROM bonafide_issued_log WHERE academic_year = ?");
        $bfStat->execute([$yr]);
        $bfCount = (int)$bfStat->fetchColumn();

        $prStat = $db->prepare("SELECT COUNT(*) as cnt FROM student_promotion_history WHERE target_year = ?");
        $prStat->execute([$yr]);
        $promoCount = (int)$prStat->fetchColumn();

        $vData = $vaultRows[$yr] ?? null;
        $isActive = ($yr === $currentYear);
        $isArchived = $vData ? (bool)$vData['is_archived'] : (!$isActive);
        $isLocked   = $vData ? (bool)$vData['is_locked']   : (!$isActive);

        $totalUniqueStudents = max((int)($rcRes['unique_students'] ?? 0), (int)($utRes['unique_students'] ?? 0));
        $totalResultRecords  = (int)($rcRes['total_records'] ?? 0);
        $totalUtRecords      = (int)($utRes['total_records'] ?? 0);

        $totalMarksEvaluated = (int)($rcRes['total_records'] ?? 0);
        $passedMarks = (int)($rcRes['passed_marks'] ?? 0);
        $passRate = $totalMarksEvaluated > 0 ? round(($passedMarks / $totalMarksEvaluated) * 100, 1) : 0.0;

        $upsertStmt->execute([
            $yr,
            $isArchived ? 1 : 0,
            $isLocked ? 1 : 0,
            $totalUniqueStudents,
            $totalResultRecords,
            $totalUtRecords,
            $bfCount
        ]);

        $summaryList[] = [
            'academic_year'            => $yr,
            'is_active'                => $isActive,
            'is_archived'              => $isArchived,
            'is_locked'                => $isLocked,
            'archived_at'              => $vData['archived_at'] ?? null,
            'archived_by'              => $vData['archived_by'] ?? null,
            'notes'                    => $vData['notes'] ?? '',
            'total_students'           => $totalUniqueStudents,
            'total_result_records'     => $totalResultRecords,
            'total_unit_test_records'  => $totalUtRecords,
            'total_bonafides_issued'   => $bfCount,
            'total_promotions'         => $promoCount,
            'rc_classes_count'         => (int)($rcRes['total_classes'] ?? 0),
            'ut_classes_count'         => (int)($utRes['total_classes'] ?? 0),
            'ut_tests_count'           => (int)($utRes['total_tests'] ?? 0),
            'pass_rate'                => $passRate
        ];
    }

    return $summaryList;
}

/**
 * Returns full detailed categorized data for a single academic year vault.
 */
function rcGetYearArchiveDetail(string $year): array {
    rcEnsureArchiveTables();
    $db = getDB();

    $vStmt = $db->prepare("SELECT * FROM academic_year_vault WHERE academic_year = ? LIMIT 1");
    $vStmt->execute([$year]);
    $vault = $vStmt->fetch(PDO::FETCH_ASSOC) ?: [
        'academic_year' => $year,
        'is_archived' => 1,
        'is_locked' => 1,
        'notes' => ''
    ];

    // 1. Report Cards (Semester Results) by Class
    $rcClassesStmt = $db->prepare("
        SELECT std, division, semester,
               COUNT(DISTINCT roll_no) as student_count,
               COUNT(*) as mark_records,
               SUM(fa_obtained + sa_obtained) as total_obtained,
               SUM(fa_max + sa_max) as total_max
        FROM report_card
        WHERE academic_year = ?
        GROUP BY std, division, semester
        ORDER BY CAST(std AS UNSIGNED), division, semester
    ");
    $rcClassesStmt->execute([$year]);
    $resultsClasses = [];
    $romanMap = ['1'=>'I','2'=>'II','3'=>'III','4'=>'IV','5'=>'V','6'=>'VI','7'=>'VII','8'=>'VIII','9'=>'IX','10'=>'X'];
    while ($row = $rcClassesStmt->fetch(PDO::FETCH_ASSOC)) {
        $totMax = (float)$row['total_max'];
        $totObt = (float)$row['total_obtained'];
        $avgPct = $totMax > 0 ? round(($totObt / $totMax) * 100, 1) : 0.0;
        $classKey = rcClassKey($year, $row['std'], $row['division'], $row['semester']);
        $rStd = $romanMap[(string)$row['std']] ?? $row['std'];
        $resultsClasses[] = [
            'std'           => $row['std'],
            'division'      => $row['division'],
            'semester'      => $row['semester'],
            'class_label'   => "STD {$rStd}-{$row['division']} ({$row['semester']})",
            'class_key'     => $classKey,
            'student_count' => (int)$row['student_count'],
            'mark_records'  => (int)$row['mark_records'],
            'average_pct'   => $avgPct,
        ];
    }

    // 2. Unit Tests by Class & Test Type
    $utClassesStmt = $db->prepare("
        SELECT std, division, test_type,
               COUNT(DISTINCT roll_no) as student_count,
               COUNT(DISTINCT subject_name) as subject_count,
               COUNT(*) as mark_records,
               SUM(marks_obtained) as total_obtained,
               SUM(max_marks) as total_max
        FROM unit_test_results
        WHERE academic_year = ?
        GROUP BY std, division, test_type
        ORDER BY CAST(std AS UNSIGNED), division, test_type
    ");
    $utClassesStmt->execute([$year]);
    $unitTestClasses = [];
    while ($row = $utClassesStmt->fetch(PDO::FETCH_ASSOC)) {
        $totMax = (float)$row['total_max'];
        $totObt = (float)$row['total_obtained'];
        $avgPct = $totMax > 0 ? round(($totObt / $totMax) * 100, 1) : 0.0;
        $rStd = $romanMap[(string)$row['std']] ?? $row['std'];
        $unitTestClasses[] = [
            'std'           => $row['std'],
            'division'      => $row['division'],
            'test_type'     => $row['test_type'],
            'class_label'   => "STD {$rStd}-{$row['division']} • {$row['test_type']}",
            'student_count' => (int)$row['student_count'],
            'subject_count' => (int)$row['subject_count'],
            'mark_records'  => (int)$row['mark_records'],
            'average_pct'   => $avgPct,
        ];
    }

    // 3. Bonafide Certificates
    $bfStmt = $db->prepare("
        SELECT id, cert_serial, gr_no, roll_no, student_name, std, division, date_of_birth, purpose, issued_date, issued_by
        FROM bonafide_issued_log
        WHERE academic_year = ?
        ORDER BY id DESC
    ");
    $bfStmt->execute([$year]);
    $bonafides = $bfStmt->fetchAll(PDO::FETCH_ASSOC);

    $eligStmt = $db->prepare("SELECT COUNT(DISTINCT roll_no) FROM report_card WHERE academic_year = ?");
    $eligStmt->execute([$year]);
    $totalEligibleStudents = (int)$eligStmt->fetchColumn();

    // 4. Student Promotion & Previous Standard History
    $promoStmt = $db->prepare("
        SELECT id, source_year, source_std, source_div, source_sem,
               target_year, target_std, target_div, target_sem,
               roll_no, student_name, prev_obtained, prev_max_total,
               prev_percentage, prev_grade, prev_status, promoted_by, promoted_at
        FROM student_promotion_history
        WHERE target_year = ? OR source_year = ?
        ORDER BY id DESC
    ");
    $promoStmt->execute([$year, $year]);
    $promotions = $promoStmt->fetchAll(PDO::FETCH_ASSOC);

    return [
        'vault'             => $vault,
        'results_classes'   => $resultsClasses,
        'unit_test_classes' => $unitTestClasses,
        'bonafides'         => $bonafides,
        'eligible_students' => $totalEligibleStudents,
        'promotions'        => $promotions,
    ];
}

/**
 * Returns previous standard promotion baseline for a promoted class.
 */
function rcGetPromotionHistoryForClass(string $targetYear, string $targetStd, string $targetDiv): array {
    $db = getDB();
    $stmt = $db->prepare("
        SELECT * FROM student_promotion_history
        WHERE target_year = ? AND target_std = ? AND target_div = ?
        ORDER BY CAST(roll_no AS UNSIGNED)
    ");
    $stmt->execute([$targetYear, $targetStd, $targetDiv]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Returns an individual student's multi-year historical dossier across all standards.
 */
function rcGetStudentMultiYearHistory(string $name, string $rollNo = ''): array {
    $db = getDB();
    $history = [];

    // 1. Report card records across all years
    $sql = "SELECT academic_year, std, division, semester, roll_no, student_name,
                   SUM(fa_obtained + sa_obtained) as obtained,
                   SUM(fa_max + sa_max) as max_total,
                   remark, attendance
            FROM report_card
            WHERE student_name = ?
            GROUP BY academic_year, std, division, semester, roll_no, student_name, remark, attendance
            ORDER BY academic_year, CAST(std AS UNSIGNED), semester";
    $stmt = $db->prepare($sql);
    $stmt->execute([$name]);
    $history['report_cards'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // 2. Unit tests across all years
    $utSql = "SELECT academic_year, std, division, test_type, roll_no,
                     SUM(marks_obtained) as obtained,
                     SUM(max_marks) as max_total,
                     grade, remark, attendance
              FROM unit_test_results
              WHERE student_name = ?
              GROUP BY academic_year, std, division, test_type, roll_no, grade, remark, attendance
              ORDER BY academic_year, CAST(std AS UNSIGNED), test_type";
    $utStmt = $db->prepare($utSql);
    $utStmt->execute([$name]);
    $history['unit_tests'] = $utStmt->fetchAll(PDO::FETCH_ASSOC);

    // 3. Promotion history
    $prSql = "SELECT * FROM student_promotion_history WHERE student_name = ? ORDER BY id";
    $prStmt = $db->prepare($prSql);
    $prStmt->execute([$name]);
    $history['promotions'] = $prStmt->fetchAll(PDO::FETCH_ASSOC);

    // 4. Issued bonafides
    $bfSql = "SELECT * FROM bonafide_issued_log WHERE student_name = ? ORDER BY id DESC";
    $bfStmt = $db->prepare($bfSql);
    $bfStmt->execute([$name]);
    $history['bonafides'] = $bfStmt->fetchAll(PDO::FETCH_ASSOC);

    return $history;
}

/**
 * Locks or unlocks an academic year in the vault.
 */
function rcToggleArchiveLock(string $year, bool $isLocked, string $adminUser = 'Admin'): bool {
    rcEnsureArchiveTables();
    $db = getDB();
    $stmt = $db->prepare("UPDATE academic_year_vault SET is_locked = ?, archived_by = ? WHERE academic_year = ?");
    return $stmt->execute([$isLocked ? 1 : 0, $adminUser, $year]);
}

/**
 * Archives and seals an academic year with notes.
 */
function rcArchiveAcademicYear(string $sourceYear, ?string $targetYear, string $notes = '', string $adminUser = 'Admin'): array {
    rcEnsureArchiveTables();
    $db = getDB();

    $stmt = $db->prepare("
        INSERT INTO academic_year_vault (academic_year, is_archived, is_locked, archived_at, archived_by, notes)
        VALUES (?, 1, 1, NOW(), ?, ?)
        ON DUPLICATE KEY UPDATE 
        is_archived = 1,
        is_locked = 1,
        archived_at = NOW(),
        archived_by = VALUES(archived_by),
        notes = VALUES(notes)
    ");
    $stmt->execute([$sourceYear, $adminUser, $notes]);

    if ($targetYear && trim($targetYear) !== '') {
        $tStmt = $db->prepare("
            INSERT IGNORE INTO academic_year_vault (academic_year, is_archived, is_locked, notes)
            VALUES (?, 0, 0, 'Active academic year')
        ");
        $tStmt->execute([$targetYear]);
    }

    rcAddNotification(
        "Academic Year {$sourceYear} Archived",
        "Academic Year {$sourceYear} was permanently archived and sealed by {$adminUser}.",
        'vault',
        "records_archive.php?year=" . urlencode($sourceYear)
    );

    return ['ok' => true, 'message' => "Academic Year {$sourceYear} successfully sealed and archived into the Records Archive Vault."];
}

/**
 * Records an issued bonafide certificate into the permanent log.
 */
function rcLogIssuedBonafide(array $certData, string $adminUser = 'Admin'): bool {
    rcEnsureArchiveTables();
    $db = getDB();
    $stmt = $db->prepare("
        INSERT INTO bonafide_issued_log 
        (cert_serial, gr_no, academic_year, roll_no, student_name, std, division, date_of_birth, purpose, issued_date, issued_by)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
        purpose = VALUES(purpose),
        issued_date = VALUES(issued_date),
        issued_by = VALUES(issued_by)
    ");
    return $stmt->execute([
        $certData['cert_serial'] ?? '01',
        $certData['gr_no'] ?? '',
        $certData['academic_year'] ?? '2024-25',
        $certData['roll_no'] ?? '',
        $certData['student_name'] ?? '',
        $certData['std'] ?? '',
        $certData['division'] ?? '',
        $certData['date_of_birth'] ?? null,
        $certData['purpose'] ?? 'Educational Purpose',
        $certData['issued_date'] ?? date('Y-m-d'),
        $adminUser
    ]);
}

/**
 * Ensures audit_logs table exists (idempotent).
 */
function rcEnsureAuditTable(): void {
    $db = getDB();
    $db->exec("CREATE TABLE IF NOT EXISTS audit_logs (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NULL,
        username VARCHAR(100) NOT NULL DEFAULT 'System',
        action VARCHAR(100) NOT NULL,
        target VARCHAR(100) NULL,
        details TEXT NULL,
        ip_address VARCHAR(45) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_action (action),
        INDEX idx_created (created_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/**
 * Records an immutable security / admin audit event.
 */
function rcEnsureLoginAttemptsTable(): void {
    static $done = false;
    if ($done) return;
    $done = true;
    try {
        $db = getDB();
        $db->exec("CREATE TABLE IF NOT EXISTS login_attempts (
            scope VARCHAR(10) NOT NULL,
            key_hash CHAR(64) NOT NULL,
            attempts INT UNSIGNED NOT NULL DEFAULT 0,
            window_start DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (scope, key_hash),
            INDEX idx_window (window_start)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    } catch (\Throwable $e) {
        error_log('login_attempts table ensure failed: ' . $e->getMessage());
    }
}

function rcLogAudit(string $action, ?string $target = null, ?string $details = null): bool {
    try {
        rcEnsureAuditTable();
        $db = getDB();
        $userId = $_SESSION['admin_id'] ?? null;
        $username = $_SESSION['admin_name'] ?? 'System';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
        $stmt = $db->prepare("INSERT INTO audit_logs (user_id, username, action, target, details, ip_address) VALUES (?, ?, ?, ?, ?, ?)");
        return $stmt->execute([$userId, $username, $action, $target, $details, $ip]);
    } catch (\Throwable $e) {
        error_log('Audit log failure: ' . $e->getMessage());
        return false;
    }
}

/**
 * Streams a full MySQL database backup (.sql) to the browser.
 */
function rcExportDatabaseSqlBackup(): void {
    if (headers_sent()) {
        die('Headers already sent.');
    }
    $db = getDB();
    $dbName = defined('DB_NAME') ? DB_NAME : 'hcc_admin';
    $timestamp = date('Y-m-d_H-i-s');
    $filename = "HCC_Backup_{$dbName}_{$timestamp}.sql";

    header('Content-Type: application/sql; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');

    echo "-- ============================================================\n";
    echo "-- Hindustani Covenant Church High School & Junior College\n";
    echo "-- Database SQL Backup Export\n";
    echo "-- Database: {$dbName}\n";
    echo "-- Exported on: " . date('Y-m-d H:i:s') . "\n";
    echo "-- ============================================================\n\n";
    echo "SET FOREIGN_KEY_CHECKS=0;\n";
    echo "SET SQL_MODE = \"NO_AUTO_VALUE_ON_ZERO\";\n";
    echo "SET NAMES utf8mb4;\n\n";

    $tablesStmt = $db->query("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
    $tables = $tablesStmt->fetchAll(PDO::FETCH_NUM);

    foreach ($tables as $tRow) {
        $table = $tRow[0];
        echo "-- ------------------------------------------------------------\n";
        echo "-- Table structure for `{$table}`\n";
        echo "-- ------------------------------------------------------------\n";
        echo "DROP TABLE IF EXISTS `{$table}`;\n";

        $createStmt = $db->query("SHOW CREATE TABLE `{$table}`");
        $createRow = $createStmt->fetch(PDO::FETCH_NUM);
        echo $createRow[1] . ";\n\n";

        // Dump data
        $dataStmt = $db->query("SELECT * FROM `{$table}`");
        $rowCount = 0;
        $batch = [];
        while ($row = $dataStmt->fetch(PDO::FETCH_ASSOC)) {
            $vals = [];
            foreach ($row as $val) {
                if ($val === null) {
                    $vals[] = 'NULL';
                } else {
                    $vals[] = $db->quote($val);
                }
            }
            $batch[] = "(" . implode(", ", $vals) . ")";
            $rowCount++;

            if (count($batch) >= 100) {
                echo "INSERT INTO `{$table}` VALUES \n" . implode(",\n", $batch) . ";\n";
                $batch = [];
            }
        }
        if (!empty($batch)) {
            echo "INSERT INTO `{$table}` VALUES \n" . implode(",\n", $batch) . ";\n";
        }
        if ($rowCount > 0) {
            echo "\n";
        }
    }

    echo "SET FOREIGN_KEY_CHECKS=1;\n";
    echo "-- End of backup\n";
    exit;
}

function saveSetting(string $key, string $value): bool {
    $db = getDB();
    $stmt = $db->prepare("INSERT INTO settings (setting_key, setting_value, updated_at) VALUES (?, ?, NOW()) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = NOW()");
    return $stmt->execute([$key, $value]);
}

/**
 * Track the latest mail delivery error for transparent diagnostics.
 */
function rcSetLastMailError(string $msg): void {
    $GLOBALS['HCC_LAST_MAIL_ERROR'] = $msg;
}

function rcGetLastMailError(): string {
    return (string)($GLOBALS['HCC_LAST_MAIL_ERROR'] ?? '');
}

/**
 * Escape a line that SMTP would treat as the end of DATA.
 */
function rcSmtpDotStuff(string $data): string {
    $data = str_replace("\r\n", "\n", $data);
    $data = str_replace("\r", "\n", $data);
    $lines = explode("\n", $data);
    foreach ($lines as $i => $line) {
        if ($line !== '' && $line[0] === '.') {
            $lines[$i] = '.' . $line;
        }
    }
    return implode("\r\n", $lines);
}

/**
 * Build the SMTP DATA payload (headers + body), without the terminating dot.
 */
function rcBuildSmtpData(string $fromName, string $fromEmail, string $to, string $subject, string $bodyText, string $bodyHtml = '', array $inlineFiles = []): string {
    $fromName = trim(str_replace(["\r", "\n"], '', $fromName));
    $fromEmail = trim(str_replace(["\r", "\n", ' ', '<', '>'], '', $fromEmail));
    $to = trim(str_replace(["\r", "\n", ' ', '<', '>'], '', $to));
    $msgId = '<' . time() . '.' . bin2hex(random_bytes(8)) . '@' . (gethostname() ?: 'hcc.local') . '>';
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

    $headers  = "Message-ID: $msgId\r\n";
    $headers .= 'Date: ' . date('r') . "\r\n";
    $headers .= "From: {$fromName} <{$fromEmail}>\r\n";
    $headers .= "To: <{$to}>\r\n";
    $headers .= "Subject: {$encodedSubject}\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "X-Mailer: HCC-Automation-SMTP\r\n";

    $usable = [];
    foreach ($inlineFiles as $file) {
        $path = (string)($file['path'] ?? '');
        $mime = (string)($file['mime'] ?? '');
        if ($path === '' || !is_readable($path) || !in_array($mime, ['image/jpeg', 'image/png', 'image/gif'], true)) {
            continue;
        }
        $raw = @file_get_contents($path);
        if ($raw === false || $raw === '') {
            continue;
        }
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '', (string)($file['filename'] ?? 'logo.jpg'));
        $usable[] = [
            'cid' => (string)($file['cid'] ?? 'hcc-logo@hindustani'),
            'mime' => $mime,
            'filename' => ($filename !== '' && $filename !== null) ? $filename : 'logo.jpg',
            'raw' => $raw,
        ];
    }

    if ($bodyHtml === '') {
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        $headers .= "Content-Transfer-Encoding: base64\r\n";
        return rcSmtpDotStuff($headers . "\r\n" . chunk_split(base64_encode($bodyText)));
    }

    $alt = 'alt_' . bin2hex(random_bytes(8));
    $related = $usable ? ('rel_' . bin2hex(random_bytes(8))) : '';
    if ($related !== '') {
        $headers .= "Content-Type: multipart/related; boundary=\"{$related}\"\r\n";
    } else {
        $headers .= "Content-Type: multipart/alternative; boundary=\"{$alt}\"\r\n";
    }

    $body = '';
    if ($related !== '') {
        $body .= "--{$related}\r\n";
        $body .= "Content-Type: multipart/alternative; boundary=\"{$alt}\"\r\n\r\n";
    }
    $body .= "--{$alt}\r\n";
    $body .= "Content-Type: text/plain; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($bodyText));
    $body .= "--{$alt}\r\n";
    $body .= "Content-Type: text/html; charset=UTF-8\r\n";
    $body .= "Content-Transfer-Encoding: base64\r\n\r\n";
    $body .= chunk_split(base64_encode($bodyHtml));
    $body .= "--{$alt}--\r\n";
    if ($related !== '') {
        foreach ($usable as $file) {
            $body .= "--{$related}\r\n";
            $body .= "Content-Type: {$file['mime']}; name=\"{$file['filename']}\"\r\n";
            $body .= "Content-Transfer-Encoding: base64\r\n";
            $body .= "Content-ID: <{$file['cid']}>\r\n";
            $body .= "Content-Disposition: inline; filename=\"{$file['filename']}\"\r\n\r\n";
            $body .= chunk_split(base64_encode($file['raw']));
        }
        $body .= "--{$related}--\r\n";
    }

    return rcSmtpDotStuff($headers . "\r\n" . $body);
}

/**
 * Lightweight, zero-dependency SMTP client for pure PHP.
 * Works with Gmail (smtp.gmail.com:587 TLS or 465 SSL), Outlook, and standard mail servers.
 * $bodyHtml and $inlineFiles are optional. When HTML is present the message is multipart,
 * and each inline file is embedded so the logo renders in Gmail without a public URL.
 */
function rcSendSMTPEmail(string $to, string $subject, string $bodyText, array $smtpConfig, string $bodyHtml = '', array $inlineFiles = []): bool {
    rcSetLastMailError('');
    $host = $smtpConfig['host'] ?? 'smtp.gmail.com';
    $port = (int)($smtpConfig['port'] ?? 587);
    $user = trim($smtpConfig['user'] ?? '');
    $pass = trim($smtpConfig['pass'] ?? '');
    // Google app passwords are displayed with spaces ("abcd efgh ijkl mnop") but the
    // real secret is the 16 characters only. Normalise so a spaced value still works.
    $passCompact = preg_replace('/\s+/', '', $pass);
    if (str_contains(strtolower($host), 'gmail') && strlen($passCompact) === 16) {
        $pass = $passCompact;
    }
    $fromName = $smtpConfig['from_name'] ?? 'Hindustani Covenant Church High School';
    $fromEmail = $smtpConfig['from_email'] ?? $user;
    $secure = strtolower($smtpConfig['secure'] ?? 'tls');

    if ($user === '' || $pass === '') {
        rcSetLastMailError('SMTP username or Google App Password is not configured.');
        return false;
    }

    $timeout = 15;
    $remote = ($secure === 'ssl' || $port === 465) ? 'ssl://' . $host . ':' . $port : 'tcp://' . $host . ':' . $port;
    $socket = @stream_socket_client($remote, $errno, $errstr, $timeout, STREAM_CLIENT_CONNECT);
    if (!$socket) {
        $msg = "Could not connect to SMTP server {$remote}: {$errstr} ({$errno}). Check firewall or internet connectivity.";
        error_log("HCC [SMTP] " . $msg);
        rcSetLastMailError($msg);
        return false;
    }

    stream_set_timeout($socket, $timeout);

    $lastResp = '';
    $readResp = function() use ($socket, &$lastResp) {
        $data = '';
        while (!feof($socket)) {
            $line = fgets($socket, 515);
            if ($line === false) break;
            $data .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        $lastResp = $data;
        return $data;
    };

    $sendCmd = function($cmd, $expectedCode) use ($socket, $readResp, &$lastResp) {
        fwrite($socket, $cmd . "\r\n");
        $resp = $readResp();
        $code = (int)substr($resp, 0, 3);
        if ($code !== (int)$expectedCode) {
            error_log("HCC [SMTP] Command expected $expectedCode, got: " . trim($resp));
            return false;
        }
        return true;
    };

    $init = $readResp();
    if ((int)substr($init, 0, 3) !== 220) {
        fclose($socket);
        rcSetLastMailError('SMTP server rejected initial connection: ' . trim($init));
        return false;
    }

    if (!$sendCmd('EHLO ' . (gethostname() ?: 'localhost'), 250)) {
        fclose($socket);
        rcSetLastMailError('SMTP server rejected EHLO: ' . trim($lastResp));
        return false;
    }

    if ($secure === 'tls' || $port === 587) {
        if (!$sendCmd('STARTTLS', 220)) {
            fclose($socket);
            rcSetLastMailError('SMTP server rejected STARTTLS: ' . trim($lastResp));
            return false;
        }
        $cryptoMethod = STREAM_CRYPTO_METHOD_TLS_CLIENT;
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_2_CLIENT;
        }
        if (defined('STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT')) {
            $cryptoMethod |= STREAM_CRYPTO_METHOD_TLSv1_3_CLIENT;
        }
        if (!@stream_socket_enable_crypto($socket, true, $cryptoMethod)) {
            error_log("HCC [SMTP] TLS crypto negotiation failed");
            fclose($socket);
            rcSetLastMailError("TLS encryption handshake failed with {$host}:{$port}.");
            return false;
        }
        if (!$sendCmd('EHLO ' . (gethostname() ?: 'localhost'), 250)) {
            fclose($socket);
            rcSetLastMailError('SMTP server rejected EHLO after TLS: ' . trim($lastResp));
            return false;
        }
    }

    if (!$sendCmd('AUTH LOGIN', 334)) {
        fclose($socket);
        rcSetLastMailError('SMTP server rejected AUTH LOGIN: ' . trim($lastResp));
        return false;
    }
    if (!$sendCmd(base64_encode($user), 334)) {
        fclose($socket);
        rcSetLastMailError('SMTP server rejected username "' . $user . '": ' . trim($lastResp));
        return false;
    }
    if (!$sendCmd(base64_encode($pass), 235)) {
        fclose($socket);
        rcSetLastMailError('Google SMTP authentication failed. Please verify that your Gmail address and 16-character Google App Password are correct (Response: ' . trim($lastResp) . ')');
        return false;
    }

    if (!$sendCmd('MAIL FROM:<' . $fromEmail . '>', 250)) {
        fclose($socket);
        rcSetLastMailError('SMTP server rejected sender <' . $fromEmail . '>: ' . trim($lastResp));
        return false;
    }
    if (!$sendCmd('RCPT TO:<' . $to . '>', 250)) {
        fclose($socket);
        rcSetLastMailError('SMTP server rejected recipient <' . $to . '>: ' . trim($lastResp));
        return false;
    }
    if (!$sendCmd('DATA', 354)) {
        fclose($socket);
        rcSetLastMailError('SMTP DATA command rejected: ' . trim($lastResp));
        return false;
    }

    $payload = rcBuildSmtpData($fromName, $fromEmail, $to, $subject, $bodyText, $bodyHtml, $inlineFiles);
    fwrite($socket, $payload . ".\r\n");
    $dataResp = $readResp();
    $sendCmd('QUIT', 221);
    fclose($socket);

    $ok = ((int)substr($dataResp, 0, 3) === 250);
    if (!$ok) {
        rcSetLastMailError('SMTP rejected message body: ' . trim($dataResp));
    }
    return $ok;
}

/**
 * Solapur local time, printed in outbound mail so it matches the Gmail clock.
 */
function rcMailStamp(): string {
    return date('d M Y, h:i A') . ' IST';
}

/**
 * Show the first two letters of a mailbox and hide the rest of the local part.
 */
function rcMaskEmail(string $email): string {
    $email = trim($email);
    $at = strrpos($email, '@');
    if ($at === false || $at < 1) {
        return '';
    }
    $local = substr($email, 0, $at);
    $domain = substr($email, $at + 1);
    if ($domain === '') {
        return '';
    }
    return substr($local, 0, min(2, strlen($local))) . '••••@' . $domain;
}

function rcSchoolName(): string {
    $settings = getAllSettings();
    $name = trim((string)($settings['school_name'] ?? ''));
    return $name !== '' ? $name : 'Hindustani Covenant Church High School';
}

/**
 * Local school logo, embedded in the message. A localhost URL does not render in Gmail.
 */
function rcSchoolLogoInline(): ?array {
    $settings = getAllSettings();
    $logoRel = trim((string)($settings['logo'] ?? 'assets/images/hcclogo.jpg'));
    $full = __DIR__ . '/../' . ltrim($logoRel, '/');
    if (!is_file($full)) {
        $full = __DIR__ . '/../assets/images/hcclogo.jpg';
    }
    if (!is_file($full)) {
        return null;
    }
    $ext = strtolower(pathinfo($full, PATHINFO_EXTENSION));
    $mime = $ext === 'png' ? 'image/png' : ($ext === 'gif' ? 'image/gif' : 'image/jpeg');
    $safeExt = in_array($ext, ['png', 'gif', 'jpg', 'jpeg'], true) ? $ext : 'jpg';
    return [
        'cid' => 'hcc-logo@hindustani',
        'path' => $full,
        'mime' => $mime,
        'filename' => 'hcclogo.' . $safeExt,
    ];
}

function rcPortalEmailHtml(string $schoolName, string $innerHtml, ?array $logo): string {
    $safeSchool = htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8');
    if ($logo) {
        $cid = htmlspecialchars((string)$logo['cid'], ENT_QUOTES, 'UTF-8');
        $logoHtml = '<img src="cid:' . $cid . '" width="76" alt="' . $safeSchool . '" style="display:block;width:76px;height:auto;border-radius:14px;border:1px solid #ead5dc;">';
    } else {
        $logoHtml = '<div style="width:76px;height:76px;border-radius:14px;background:#7a2238;color:#ffffff;font-family:Arial,Helvetica,sans-serif;font-size:22px;font-weight:700;line-height:76px;text-align:center;">HCC</div>';
    }

    return '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"></head>'
        . '<body style="margin:0;padding:0;background:#f6f3f4;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f3f4;padding:28px 12px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:16px;overflow:hidden;border:1px solid #eadfe3;">'
        . '<tr><td style="height:6px;background-color:#7a2238;font-size:0;line-height:0;">&nbsp;</td></tr>'
        . '<tr><td align="center" style="padding:28px 32px 0;">' . $logoHtml . '</td></tr>'
        . '<tr><td align="center" style="padding:14px 32px 0;font-family:Arial,Helvetica,sans-serif;">'
        . '<div style="font-size:17px;font-weight:700;color:#3b1020;line-height:1.35;">' . $safeSchool . '</div>'
        . '<div style="margin-top:4px;font-size:11px;letter-spacing:0.12em;text-transform:uppercase;color:#7a2238;">Solapur &middot; Administration Portal</div>'
        . '</td></tr>'
        . '<tr><td style="padding:22px 32px 8px;font-family:Arial,Helvetica,sans-serif;font-size:15px;line-height:1.6;color:#1e293b;">' . $innerHtml . '</td></tr>'
        . '<tr><td style="padding:8px 32px 26px;font-family:Arial,Helvetica,sans-serif;font-size:12px;line-height:1.5;color:#94a3b8;border-top:1px solid #f3e8ec;">'
        . '&mdash; ' . $safeSchool . ' Automation System<br>Solapur'
        . '</td></tr></table></td></tr></table></body></html>';
}

function rcOtpDigitsHtml(string $otp): string {
    $cells = '';
    $digits = str_split($otp);
    $last = count($digits) - 1;
    foreach ($digits as $i => $digit) {
        $gap = $i === $last ? '0' : '8px';
        $cells .= '<td style="padding:0 ' . $gap . ' 0 0;">'
            . '<div style="width:44px;height:52px;line-height:52px;text-align:center;font-family:Consolas,Menlo,Courier,monospace;font-size:26px;font-weight:700;color:#7a2238;background:#faf6f7;border:1px solid #ead5dc;border-radius:10px;">'
            . htmlspecialchars($digit, ENT_QUOTES, 'UTF-8')
            . '</div></td>';
    }
    return '<table role="presentation" cellpadding="0" cellspacing="0" align="center" style="margin:8px auto 4px;"><tr>' . $cells . '</tr></table>';
}

function rcMailDisplayName(string $fullName): string {
    $name = trim(str_replace(["\r", "\n"], ' ', $fullName));
    return $name !== '' ? $name : 'Administrator';
}

/**
 * Send a branded portal message. SMTP is preferred. The debug log never receives the message body.
 */
function rcDeliverPortalEmail(string $to, string $subject, string $bodyText, string $bodyHtml, array $inlineFiles = []): bool {
    rcSetLastMailError('');
    $settings = getAllSettings();
    $fromName = rcSchoolName();
    $smtpUser = trim($settings['smtp_user'] ?? (getenv('SMTP_USER') ?: ''));
    $smtpPass = trim($settings['smtp_pass'] ?? (getenv('SMTP_PASS') ?: ''));

    if ($smtpUser !== '' && $smtpPass !== '') {
        $smtpConfig = [
            'host'       => $settings['smtp_host'] ?? 'smtp.gmail.com',
            'port'       => (int)($settings['smtp_port'] ?? 587),
            'user'       => $smtpUser,
            'pass'       => $smtpPass,
            'from_name'  => $fromName,
            'from_email' => $smtpUser,
            'secure'     => $settings['smtp_secure'] ?? 'tls',
        ];
        if (rcSendSMTPEmail($to, $subject, $bodyText, $smtpConfig, $bodyHtml, $inlineFiles)) {
            rcSetLastMailError('');
            return true;
        }
    }

    $smtpErr = rcGetLastMailError();
    $fromAddr = $settings['contact_email'] ?? 'noreply@hcc.local';
    $alt = 'alt_' . bin2hex(random_bytes(8));
    $headers = [
        'From: ' . str_replace(["\r", "\n"], '', $fromName) . ' <' . $fromAddr . '>',
        'Reply-To: ' . $fromAddr,
        'MIME-Version: 1.0',
        'Content-Type: multipart/alternative; boundary="' . $alt . '"',
        'X-Mailer: HCC-Automation',
    ];
    $fallback  = "--{$alt}\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$bodyText}\r\n";
    $fallback .= "--{$alt}\r\nContent-Type: text/html; charset=UTF-8\r\nContent-Transfer-Encoding: 8bit\r\n\r\n{$bodyHtml}\r\n";
    $fallback .= "--{$alt}--\r\n";
    if (@mail($to, $subject, $fallback, implode("\r\n", $headers))) {
        rcSetLastMailError('');
        return true;
    }

    if ($smtpErr === '') {
        rcSetLastMailError('No SMTP relay configured and local mail() server is offline. Please enter your Gmail and App Password in Outgoing Mail Relay.');
    } else {
        rcSetLastMailError($smtpErr);
    }
    error_log('HCC [mail] Delivery failed to ' . $to . ': ' . $subject);
    $logDir = __DIR__ . '/../logs';
    if (!is_dir($logDir)) {
        @mkdir($logDir, 0755, true);
    }
    @file_put_contents(
        $logDir . '/forgot_password_debug.txt',
        date('Y-m-d H:i:s') . ' | TO: ' . $to . ' | FAILED: ' . $subject . PHP_EOL,
        FILE_APPEND | LOCK_EX
    );
    return false;
}

/**
 * Password-reset code. The code is the only secret in the message. There is no link.
 */
function rcSendPasswordOtpEmail(string $to, string $fullName, string $otp): bool {
    $school = rcSchoolName();
    $name = rcMailDisplayName($fullName);
    $stamp = rcMailStamp();
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $subject = 'Password reset code — ' . $school;
    $text = "Hello {$name},\n\n"
        . "Someone asked to reset the password for the {$school} administration portal.\n\n"
        . "Your one-time code is: {$otp}\n\n"
        . "This code expires in 10 minutes. Enter it on the Forgot password page, then choose a new password.\n\n"
        . "Sent on: {$stamp}\n\n"
        . "If you did not ask for this, ignore this email. Your password will not change.\n\n"
        . "— {$school} Automation System\nSolapur\n";
    $inner = '<p style="margin:0 0 14px;">Hello ' . $safeName . ',</p>'
        . '<p style="margin:0 0 14px;">Someone asked to reset the password for the school administration portal.</p>'
        . '<p style="margin:0 0 6px;">Your one-time code is:</p>'
        . rcOtpDigitsHtml($otp)
        . '<p style="margin:14px 0 14px;text-align:center;font-size:13px;color:#64748b;">This code expires in 10 minutes.</p>'
        . '<p style="margin:0 0 14px;">Enter this code on the Forgot password page, then choose a new password.</p>'
        . '<p style="margin:0 0 14px;font-size:13px;color:#64748b;">Sent on: ' . htmlspecialchars($stamp, ENT_QUOTES, 'UTF-8') . '</p>'
        . '<p style="margin:0;">If you did not ask for this, ignore this email. Your password will not change.</p>';
    $logo = rcSchoolLogoInline();
    return rcDeliverPortalEmail($to, $subject, $text, rcPortalEmailHtml($school, $inner, $logo), $logo ? [$logo] : []);
}

/**
 * Connection test from Account Security. Confirms the mailbox. Does not contain a reset code.
 */
function rcSendRecoveryConnectedEmail(string $to, string $fullName): bool {
    $school = rcSchoolName();
    $name = rcMailDisplayName($fullName);
    $stamp = rcMailStamp();
    $safeName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
    $subject = 'Recovery email connected — ' . $school;
    $text = "Hello {$name},\n\n"
        . "This mailbox is connected to the {$school} administration portal.\n\n"
        . "When you use Forgot password on the sign-in page, the one-time code will be sent only to this address.\n\n"
        . "This message is a connection test. It does not contain a reset code, and it does not change your password.\n\n"
        . "Checked on: {$stamp}\n\n"
        . "— {$school} Automation System\nSolapur\n";
    $inner = '<p style="margin:0 0 14px;">Hello ' . $safeName . ',</p>'
        . '<div style="margin:0 0 16px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:12px;padding:14px 16px;color:#166534;">'
        . '<strong>Recovery mailbox connected</strong><br>Reset codes from the sign-in page will be sent only to this address.'
        . '</div>'
        . '<p style="margin:0 0 14px;">When you use Forgot password on the sign-in page, a 6-digit code arrives here. It expires in 10 minutes.</p>'
        . '<p style="margin:0 0 14px;">This message is a connection test. It does not contain a reset code, and it does not change your password.</p>'
        . '<p style="margin:0;font-size:13px;color:#64748b;">Checked on: ' . htmlspecialchars($stamp, ENT_QUOTES, 'UTF-8') . '</p>';
    $logo = rcSchoolLogoInline();
    return rcDeliverPortalEmail($to, $subject, $text, rcPortalEmailHtml($school, $inner, $logo), $logo ? [$logo] : []);
}



