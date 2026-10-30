<?php
/* Bonafide Certificate — single-student, live editable preview + print + PDF download. */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';
$adminPage = 'bonafide_certificate';

/* ── JSON list endpoint (used by the student picker via fetch) ── */
if (isset($_GET['action']) && $_GET['action'] === 'list') {
    $db = getDB();
    $classKey = trim($_GET['class'] ?? '');
    $search   = trim($_GET['search']  ?? '');
    $sql = "SELECT roll_no, student_name, std, division, semester, academic_year, date_of_birth
               FROM report_card";
    $params = [];
    $where = [];
    if ($classKey !== '') {
        $c = rcParseClassKey(urldecode($classKey));
        if ($c['std'] !== '') {
            $where[] = "academic_year=? AND std=? AND division=? AND semester=?";
            $params = [$c['academic_year'], $c['std'], $c['division'], $c['semester']];
        }
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
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    header('Content-Type: application/json');
    echo json_encode(array_map(function ($r) {
        $r['class_key'] = rcClassKey($r['academic_year'], $r['std'], $r['division'], $r['semester']);
        $r['dob_words'] = dobToWords($r['date_of_birth'] ?? '');
        return $r;
    }, $rows));
    exit;
}

/* ── JSON endpoint to log issued bonafide into Archive Vault ── */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'log_bonafide') {
    if (!verifyCSRFToken()) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'error' => 'Invalid security token']);
        exit;
    }
    $adminUser = sanitize($_SESSION['admin_name'] ?? 'Admin');
    $payload = [
        'cert_serial'  => trim($_POST['cert_serial'] ?? '01'),
        'gr_no'        => trim($_POST['gr_no'] ?? '3875'),
        'academic_year'=> trim($_POST['academic_year'] ?? '2024-25'),
        'roll_no'      => trim($_POST['roll_no'] ?? ''),
        'student_name' => trim($_POST['student_name'] ?? ''),
        'std'          => trim($_POST['std'] ?? ''),
        'division'     => trim($_POST['division'] ?? ''),
        'date_of_birth'=> !empty($_POST['dob']) ? trim($_POST['dob']) : null,
        'purpose'      => trim($_POST['purpose'] ?? 'Educational Purpose'),
        'issued_date'  => date('Y-m-d')
    ];
    $ok = rcLogIssuedBonafide($payload, $adminUser);
    header('Content-Type: application/json');
    echo json_encode(['ok' => $ok]);
    exit;
}

/* ── Resolve target student from query string ── */
$db = getDB();
bonafideEnsureSettings();
$settings = getAllSettings();
$schoolName  = $settings['school_name']  ?? 'HINDUSTANI COVENANT CHURCH HIGH SCHOOL & JUNIOR COLLEGE';
if (trim($schoolName) === 'Hindustani Covenant Church High School') {
    $schoolName = 'HINDUSTANI COVENANT CHURCH HIGH SCHOOL & JUNIOR COLLEGE';
}
$schoolTagline = $settings['school_tagline'] ?? '';
$schoolLogo  = $settings['logo']          ?? 'assets/images/hcclogo.jpg';
$schoolAddr  = $settings['contact_address'] ?? '';
$grNo        = $settings['gr_no']         ?? '3875';
$certSerial  = $settings['cert_serial']   ?? '01';
$principalNm = $settings['principal_name'] ?? 'PRINCIPAL';

$classKey = trim($_GET['class'] ?? '');
$rollNo   = trim($_GET['roll'] ?? '');
$student  = null;
if ($classKey !== '' && $rollNo !== '') {
    foreach (rcListStudents(urldecode($classKey)) as $s) {
        if ((string)$s['roll_no'] === $rollNo) { $student = $s; break; }
    }
}
$allClasses = rcClassOptions();
$classOptions = [];
foreach ($allClasses as $ck) {
    $key = rcClassKey($ck['academic_year'], $ck['std'], $ck['division'], $ck['semester']);
    $label = 'STD ' . $ck['std'] . ' ' . $ck['division'] . ' — ' . $ck['semester'] . ' (' . $ck['academic_year'] . ') — ' . ($ck['students'] ?? 0) . ' students';
    $classOptions[] = ['key' => $key, 'label' => $label];
}
$defaults = [
    'student_name' => $student['student_name'] ?? '',
    'std_div'      => $student ? ('STD – ' . $student['std'] . ' – ' . $student['division']) : '',
    'dob_digits'   => $student['date_of_birth'] ?? '',
    'dob_words'    => dobToWords($student['date_of_birth'] ?? ''),
    'academic_year'=> $student['academic_year'] ?? '',
    'issue_date'   => date('d/m/Y'),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Bonafide Certificate | HCC Admin</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/merriweather/merriweather.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin.css?v=<?= (is_file(__DIR__ . '/../assets/css/admin.css') ? filemtime(__DIR__ . '/../assets/css/admin.css') : 0) ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/whatsapp-share.css?v=<?= filemtime(__DIR__ . '/../assets/css/whatsapp-share.css') ?>">
    <style>
        :root {
            --bf-bg: #f6f8fb;
            --bf-card: #ffffff;
            --bf-border: #e5eaf1;
            --bf-border-soft: #eef2f7;
            --bf-ink: #0f1e3d;
            --bf-muted: #64748b;
            --bf-muted-2: #94a3b8;
            --bf-brand: #1a237e;
            --bf-brand-2: #283593;
            --bf-gold: #c9a227;
            --bf-success: #15803d;
            --bf-radius: 14px;
            --bf-shadow: 0 1px 2px rgba(15,30,61,.05), 0 8px 24px rgba(15,30,61,.07);
            --bf-shadow-sm: 0 1px 2px rgba(15,30,61,.06);
        }
        * { box-sizing: border-box; }
        body { margin: 0; background: var(--bf-bg); font-family: 'Inter', system-ui, -apple-system, sans-serif; color: var(--bf-ink); -webkit-font-smoothing: antialiased; }

        .admin-content { min-width: 0; overflow-x: clip; }
        .bonafide-layout { display: grid; grid-template-columns: minmax(280px, 340px) minmax(0,1fr); gap: 16px; align-items: start; max-width: 1380px; margin: 0 auto; }
        .bonafide-layout > .form-panel {
            background: var(--bf-card); border: 1px solid var(--bf-border); border-radius: var(--bf-radius);
            box-shadow: var(--bf-shadow-sm); padding: 18px; position: sticky; top: 18px;
            max-height: calc(100vh - 140px); overflow-y: auto; min-width: 0;
        }
        .bonafide-layout > .preview-panel {
            padding: 2px 2px 8px; display: flex; flex-direction: column; align-items: center; gap: 14px;
            min-width: 0; width: 100%; overflow-x: clip;
        }

        /* Mid widths (sidebar open): stay side-by-side, just tighten. */
        @media (max-width: 1280px) and (min-width: 1025px) {
            .bonafide-layout { gap: 14px; }
            .bonafide-layout > .form-panel { padding: 16px; }
        }

        @media (max-width: 1024px) {
            .bonafide-layout { grid-template-columns: 1fr; }
            .bonafide-layout > .form-panel { position: static; max-height: none; }
            .bonafide-layout > .preview-panel { overflow-x: visible; }
        }

        /* ── Form panel ── */
        .panel-eyebrow { display: inline-flex; align-items: center; gap: 7px; font-size: .7rem; font-weight: 800; letter-spacing: .09em; text-transform: uppercase; color: var(--bf-brand); background: rgba(26,35,126,.07); border: 1px solid rgba(26,35,126,.14); padding: 5px 11px; border-radius: 999px; margin-bottom: 10px; }
        .panel-title { font-size: 1.08rem; font-weight: 800; color: var(--bf-ink); margin: 0 0 4px; letter-spacing: -.01em; }
        .panel-sub { font-size: .82rem; color: var(--bf-muted); margin: 0 0 16px; line-height: 1.55; }
        .form-group { margin-bottom: 12px; }
        .form-group label { display: block; font-size: .7rem; font-weight: 800; color: #334155; text-transform: uppercase; letter-spacing: .07em; margin-bottom: 6px; }
        .form-group input, .form-group select {
            width: 100%; height: 42px; padding: 0 13px; border: 1.5px solid var(--bf-border); border-radius: 10px;
            font-size: .87rem; font-weight: 500; font-family: inherit; background: #fff; color: var(--bf-ink);
            box-shadow: 0 1px 2px rgba(15,30,61,.04);
            transition: border-color .15s, box-shadow .15s, background .15s;
        }
        .form-group input::placeholder { color: var(--bf-muted-2); font-weight: 400; }
        .form-group input:hover, .form-group select:hover { border-color: #b6c2d2; }
        .form-group input:focus, .form-group select:focus { outline: none; border-color: var(--bf-brand); background: #fff; box-shadow: 0 0 0 3.5px rgba(26,35,126,.12); }
        .select-wrap { position: relative; }
        .select-wrap::after {
            content: ''; position: absolute; right: 14px; top: 50%; width: 8px; height: 8px; pointer-events: none;
            border-right: 2px solid #64748b; border-bottom: 2px solid #64748b; transform: translateY(-70%) rotate(45deg);
            transition: border-color .15s;
        }
        .select-wrap:focus-within::after { border-color: var(--bf-brand); }
        .form-group select {
            appearance: none; -webkit-appearance: none; -moz-appearance: none;
            padding-right: 38px; cursor: pointer; text-overflow: ellipsis; white-space: nowrap; overflow: hidden;
            line-height: 1.2;
        }
        .form-group select:required:invalid { color: var(--bf-muted-2); }
        .input-icon { position: relative; }
        .input-icon > i { position: absolute; left: 13px; top: 50%; transform: translateY(-50%); color: #94a3b8; font-size: .82rem; pointer-events: none; }
        .input-icon input { padding-left: 37px; }
        .form-section { margin-top: 16px; padding: 15px 14px; border: 1px solid var(--bf-border-soft); background: #fbfcfe; border-radius: 12px; }
        .form-section:first-of-type { margin-top: 0; }
        .form-section h3 { font-size: .76rem; font-weight: 800; color: var(--bf-ink); margin: 0 0 11px; text-transform: uppercase; letter-spacing: .06em; display: flex; align-items: center; gap: 8px; }
        .form-section h3 i { width: 26px; height: 26px; display: inline-grid; place-items: center; background: var(--bf-brand); color: #fff; border-radius: 8px; font-size: .72rem; }
        .form-section h3 .step-pill { margin-left: auto; font-size: .66rem; background: #eef2ff; color: var(--bf-brand); border: 1px solid rgba(26,35,126,.15); padding: 2px 9px; border-radius: 999px; letter-spacing: .06em; }

        .btn { display: inline-flex; align-items: center; justify-content: center; gap: 7px; padding: 10px 15px; border-radius: 9px; font-weight: 700; font-size: .83rem; cursor: pointer; border: none; font-family: inherit; text-decoration: none; transition: transform .12s, box-shadow .15s, background .15s, border-color .15s; line-height: 1; }
        .btn:active { transform: translateY(1px); }
        .btn-primary { background: var(--bf-brand); color: #fff; box-shadow: 0 6px 14px rgba(26,35,126,.22); }
        .btn-primary:hover { background: var(--bf-brand-2); }
        .btn-success { background: var(--bf-success); color: #fff; box-shadow: 0 6px 14px rgba(21,128,61,.22); }
        .btn-success:hover { background: #166534; }
        .btn-outline { background: #fff; border: 1.5px solid var(--bf-border); color: #334155; }
        .btn-outline:hover { border-color: var(--bf-brand); color: var(--bf-brand); box-shadow: var(--bf-shadow-sm); }
        #clearPickBtn { background: #f1f5f9; border: 1.5px solid var(--bf-border); color: #334155; box-shadow: none; }
        #clearPickBtn:hover, #clearPickBtn:focus-visible { background: #e2e8f0; border-color: #cbd5e1; color: #0f172a; box-shadow: none; }
        #clearPickBtn i { color: inherit; }
        .form-actions { display: flex; gap: 8px; flex-wrap: wrap; margin-top: 14px; }
        .pick-row { display: flex; gap: 8px; } .pick-row .btn { flex: 1; min-height: 42px; }
        .form-actions .btn { min-height: 40px; }

        .student-list { max-height: 264px; overflow-y: auto; border: 1px solid var(--bf-border); border-radius: 10px; background: #fff; margin-top: 10px; }
        .student-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 10px 12px; border-bottom: 1px solid var(--bf-border-soft); cursor: pointer; font-size: .85rem; transition: background .12s; }
        .student-row:last-child { border-bottom: none; }
        .student-row:hover { background: #eef2ff; }
        .student-row.selected { background: var(--bf-brand); color: #fff; }
        .student-row .s-name { font-weight: 700; display: block; line-height: 1.35; }
        .student-row .s-roll { font-size: .74rem; color: var(--bf-muted); font-weight: 600; }
        .student-row.selected .s-name { color: #fff; }
        .student-row.selected .s-roll { color: #c7d2fe; }
        .student-row .s-go { font-size: .72rem; opacity: .55; }
        .student-row.selected .s-go { opacity: .9; }
        .no-students { padding: 18px 14px; text-align: center; color: var(--bf-muted-2); font-size: .82rem; }
        .no-students i { display: block; font-size: 1.3rem; margin-bottom: 7px; opacity: .6; }

        .dirty-badge { display: inline-block; width: 8px; height: 8px; border-radius: 50%; background: #f59e0b; margin-left: 5px; vertical-align: middle; box-shadow: 0 0 0 3px rgba(245,158,11,.18); }

        /* ── Certificate preview ── */
        .cert-toolbar {
            display: flex; gap: 9px; align-items: center; flex-wrap: wrap; width: 100%; max-width: 720px;
            background: var(--bf-card); border: 1px solid var(--bf-border); border-radius: 12px;
            padding: 11px 12px; box-shadow: var(--bf-shadow-sm); position: sticky; top: 18px; z-index: 5;
            min-width: 0;
        }
        .cert-toolbar .spacer { flex: 1; }
        .mode-pill { display: inline-flex; align-items: center; gap: 7px; font-size: .78rem; font-weight: 700; color: #334155; background: #f1f5f9; border: 1px solid var(--bf-border); padding: 7px 11px; border-radius: 999px; }
        .mode-pill .dot { width: 8px; height: 8px; border-radius: 50%; background: #22c55e; box-shadow: 0 0 0 3px rgba(34,197,94,.18); }
        .mode-pill.off .dot { background: var(--bf-muted-2); box-shadow: none; }
        .cert-sheet {
            width: 100%; max-width: 720px; min-width: 0; min-height: 460px; background: #fff;
            border: 2px solid var(--bf-brand); border-radius: 6px; position: relative;
            padding: clamp(20px, 4vw, 32px) clamp(18px, 4vw, 36px) 28px; display: flex; flex-direction: column;
            font-family: 'Merriweather', Georgia, serif; color: #1e293b;
            box-shadow: var(--bf-shadow); flex-shrink: 1;
        }
        .cert-sheet::before { display: none; }
        .cert-sheet::after {
            content: ''; position: absolute; inset: 0; pointer-events: none; border-radius: 4px;
            background: radial-gradient(600px 200px at 50% -60px, rgba(201,162,39,.10), transparent 70%);
        }
        .cert-top { display: flex; justify-content: flex-start; align-items: center; gap: 14px; margin-bottom: 10px; position: relative; z-index: 1; min-width: 0; }
        .cert-school { display: flex; gap: 12px; align-items: center; text-align: left; min-width: 0; flex: 1 1 auto; }
        .cert-school img { height: 52px; width: 52px; object-fit: contain; border-radius: 10px; border: 1px solid var(--bf-border); background: #fff; padding: 4px; flex-shrink: 0; }
        .cert-school-text { min-width: 0; flex: 1 1 auto; }
        .cert-school h1 { font-size: .88rem; font-weight: 900; color: var(--bf-brand); margin: 1px 0 0; line-height: 1.35; letter-spacing: .01em; max-width: 520px; text-wrap: balance; overflow-wrap: anywhere; }
        .cert-addr { font-size: .7rem; color: #475569; margin: 4px 0 0; font-family: 'Inter',sans-serif; letter-spacing: .02em; font-weight: 600; }
        .cert-meta-table { width: 100%; border-collapse: collapse; margin: 0; font-family: 'Inter',sans-serif; font-size: .78rem; }
        .cert-meta-table td { text-align: left; color: #334155; font-weight: 600; padding: 0; }
        .cert-ref { display: block; text-align: left; font-family: 'Inter',sans-serif; font-size: .78rem; color: #334155; font-weight: 600; margin: 0 0 4px; padding: 0 2px; }
        .cert-ref span { color: inherit; font-weight: inherit; margin-left: 5px; text-decoration: underline; text-underline-offset: 3px; text-decoration-thickness: 1px; }
        .cert-title { text-align: center; margin: 8px 0 18px; position: relative; z-index: 1; }
        .cert-title h2 { font-size: 1.12rem; font-weight: 900; color: #b91c1c; letter-spacing: .2em; text-transform: uppercase; margin: 0; }
        .cert-title::after { content: ''; display: block; width: 72px; height: 3px; background: var(--bf-gold); border-radius: 999px; margin: 9px auto 0; }
        .cert-body { flex: 1; font-size: .9rem; line-height: 1.9; padding: 0 12px; position: relative; z-index: 1; }
        .cert-body p { margin: 0 0 11px; text-align: justify; }
        .cert-fill { display: inline; min-width: 2ch; padding: 1px 6px; border-bottom: 1.5px solid #94a3b8; font-weight: 700; color: #0f172a; border-radius: 3px 3px 0 0; }
        .cert-body .cert-fill { padding: 0 2px; border-bottom: none; border-radius: 0; font-weight: 700; color: #0f172a; text-decoration: underline; text-underline-offset: 3px; text-decoration-thickness: 1.5px; }
        .cert-body .cert-fill.is-blank { font-weight: inherit; color: inherit; text-decoration: none; }
        .cert-foot { display: flex; justify-content: space-between; align-items: flex-end; flex-wrap: wrap; gap: 16px; margin-top: 22px; padding: 18px 12px 0; border-top: none; position: relative; z-index: 1; }
        .cert-date { display: inline-flex; align-items: baseline; gap: 8px; font-family: 'Inter',sans-serif; background: transparent; border: none; border-radius: 0; padding: 0; line-height: 1.6; }
        .cert-date .d-label { font-size: .83rem; font-weight: 600; letter-spacing: 0; text-transform: none; color: #334155; }
        .cert-date .cert-fill { display: inline-block; min-width: 132px; text-align: center; font-family: 'Merriweather', Georgia, serif; font-size: .92rem; font-weight: 700; color: var(--bf-brand); border-bottom: 2px solid var(--bf-brand); padding: 0 10px 3px; border-radius: 0; }
        .cert-principal { text-align: center; font-size: .83rem; font-weight: 800; color: var(--bf-brand); font-family: 'Inter',sans-serif; }
        .cert-principal .sig-line { width: 190px; height: 32px; margin: 0 auto 6px; border-bottom: 1.5px solid var(--bf-brand); }
        .cert-principal .cert-fill { border-bottom: none; display: inline-block; padding: 0 4px; }
        .cert-photo { align-self: flex-end; transform: translateY(-14px); }
        .photo-box { width: 112px; height: 136px; border: 1.5px dashed #94a3b8; border-radius: 6px; background: #f8fafc; display: grid; place-items: center; cursor: pointer; overflow: hidden; position: relative; }
        .photo-box:hover { border-color: var(--bf-brand); background: #eef2ff; }
        .photo-box img { width: 100%; height: 100%; object-fit: cover; display: none; }
        .photo-box.has-img img { display: block; }
        .photo-box.has-img .photo-ph { display: none; }
        .photo-ph { display: flex; flex-direction: column; align-items: center; gap: 6px; font-family: 'Inter',sans-serif; font-size: .66rem; font-weight: 700; letter-spacing: .08em; text-transform: uppercase; color: #94a3b8; }
        .photo-ph i { font-size: 1.1rem; }

        /* Editing affordances */
        .cert-sheet[data-mode="edit"] [data-editable] { border-radius: 4px; transition: background .12s, outline-color .12s; }
        .cert-sheet[data-mode="edit"] [data-editable]:hover { outline: 2px dashed #f59e0b; outline-offset: 3px; cursor: text; background: rgba(245,158,11,.06); }
        .cert-sheet[data-mode="edit"] [data-editable]:focus { outline: 2px solid #f59e0b; outline-offset: 3px; background: #fffbeb; }
        .cert-legend { display: inline-flex; align-items: center; gap: 8px; font-size: .75rem; color: var(--bf-muted); text-align: center; margin: 0; width: 100%; max-width: 720px; background: #fff; border: 1px solid var(--bf-border); padding: 8px 14px; border-radius: 999px; box-shadow: var(--bf-shadow-sm); }
        .cert-legend i { color: #f59e0b; }

        @media (max-width: 640px) {
            .cert-sheet { padding: 24px 20px; }
            .cert-top { flex-direction: column; align-items: flex-start; }
            .cert-ref { flex-wrap: wrap; gap: 12px; }
            .cert-body { padding: 0 2px; }
            .cert-foot { flex-direction: column; align-items: stretch; }
            .cert-photo { transform: none; align-self: flex-start; }
            .cert-principal { text-align: left; } .cert-principal .sig-line { margin: 0 0 6px; }
            .cert-toolbar { top: 10px; }
        }

        /* Print: hide everything except the certificate */
        @media print {
            @page { size: A4; margin: 10mm 9mm; }
            * { -webkit-print-color-adjust: exact; print-color-adjust: exact; }
            html, body { height: auto !important; overflow: visible !important; }
            body { background: #fff; padding: 0; }
            .admin-sidebar, .admin-header, .sidebar-overlay,
            .form-panel, .cert-toolbar, .cert-legend { display: none !important; }
            .admin-layout { display: block !important; height: auto !important; overflow: visible !important; }
            .admin-main { display: block !important; margin-left: 0 !important; height: auto !important; overflow: visible !important; }
            .admin-content { display: block !important; padding: 0 !important; overflow: visible !important; }
            .bonafide-layout { display: block; min-height: auto; max-width: none; overflow: visible !important; }
            .preview-panel { padding: 0; overflow: visible !important; }
            .cert-sheet {
                width: 100%; max-width: 100%; min-height: auto; height: auto; box-shadow: none;
                border: 2px solid var(--bf-brand); padding: 8mm 10mm; border-radius: 0;
                page-break-inside: avoid; overflow: visible;
            }
            .cert-sheet::after { display: none; }
            .cert-date { background: transparent; border-color: transparent; padding: 0; }
            .photo-box { border-style: solid; background: #fff; cursor: default; }
            .cert-sheet [data-editable] { outline: none !important; background: transparent !important; }
        }
    </style>
</head>
<body>
<div class="admin-layout">
    <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
    <main class="admin-main">
        <header class="admin-header">
            <button class="sidebar-toggle" id="sidebarToggle" type="button"
                    aria-label="Toggle sidebar menu" aria-expanded="false" aria-controls="sidebar">
                <i class="fas fa-bars"></i>
            </button>
            <h1 class="page-title">Bonafide Certificate</h1>
            <div class="admin-user">
                <i class="fas fa-user-circle"></i>
                <span><?= sanitize($_SESSION['admin_name'] ?? 'Admin') ?></span>
            </div>
        </header>
        <div class="admin-content">

<div class="bonafide-layout">

    <!-- ═══════════ LEFT: FORM PANEL ═══════════ -->
    <div class="form-panel">
        <span class="panel-eyebrow"><i class="fas fa-certificate"></i> Certificate Studio</span>
        <h2 class="panel-title">Bonafide Certificate</h2>
        <p class="panel-sub">Select a student, then edit any field. Untouched fields keep the auto-filled value.</p>

        <!-- Step 1: pick student -->
        <div class="form-section" style="margin-top:0;">
            <h3><i class="fas fa-users"></i> Pick a student <span class="step-pill">Step 1</span></h3>
            <div class="form-group">
                <label for="pickClass">Class / semester</label>
                <div class="select-wrap">
                    <select id="pickClass">
                        <option value="">— All classes —</option>
                        <?php foreach ($classOptions as $co): ?>
                        <option value="<?= htmlspecialchars($co['key'], ENT_QUOTES) ?>" <?= ($classKey !== '' && $classKey === $co['key']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($co['label'], ENT_QUOTES) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="form-group">
                <label for="pickSearch">Search by roll no. or name</label>
                <div class="input-icon">
                    <i class="fas fa-magnifying-glass"></i>
                    <input type="text" id="pickSearch" placeholder="e.g. 01 or Vivek" value="<?= sanitize($_GET['search'] ?? '') ?>">
                </div>
            </div>
            <div class="form-group pick-row">
                <button class="btn btn-primary" id="loadBtn" type="button"><i class="fas fa-magnifying-glass"></i> Load students</button>
                <button class="btn btn-outline" id="clearPickBtn" type="button"><i class="fas fa-xmark"></i> Clear</button>
            </div>
            <div class="student-list" id="studentList" style="display:none;">
                <div class="no-students"><i class="fas fa-user-slash"></i>No students found.</div>
            </div>
        </div>

        <!-- Step 2: edit fields -->
        <div class="form-section">
            <h3><i class="fas fa-pen-to-square"></i> Edit fields <span class="step-pill">Step 2</span></h3>
            <p style="font-size:.75rem;color:#64748b;margin:0 0 10px;">Any field you leave blank in the form stays as shown in the preview. Fields you type into override the preview live.</p>

            <div class="form-group">
                <label for="f_student">Student name</label>
                <input type="text" id="f_student" value="<?= htmlspecialchars($defaults['student_name'], ENT_QUOTES) ?>">
            </div>
            <div class="form-group">
                <label for="f_std">Std – Division</label>
                <input type="text" id="f_std" value="<?= htmlspecialchars($defaults['std_div'], ENT_QUOTES) ?>" placeholder="STD – III – A">
            </div>
            <div class="form-group">
                <label for="f_dob">Date of birth (dd/mm/yyyy)</label>
                <input type="text" id="f_dob" value="<?= htmlspecialchars($defaults['dob_digits'], ENT_QUOTES) ?>" placeholder="02/04/2017">
            </div>
            <div class="form-group">
                <label for="f_dobwords">DOB in words</label>
                <input type="text" id="f_dobwords" value="<?= htmlspecialchars($defaults['dob_words'], ENT_QUOTES) ?>" placeholder="Second April Two Thousand Seventeen">
            </div>
            <div class="form-group">
                <label for="f_ay">Academic year</label>
                <input type="text" id="f_ay" value="<?= htmlspecialchars($defaults['academic_year'], ENT_QUOTES) ?>" placeholder="2025-2026">
            </div>
            <div class="form-group">
                <label for="f_caste">Caste</label>
                <input type="text" id="f_caste" value="" placeholder="e.g. Maratha">
            </div>
            <div class="form-group">
                <label for="f_date">Issue date (dd/mm/yyyy)</label>
                <input type="text" id="f_date" value="<?= htmlspecialchars($defaults['issue_date'], ENT_QUOTES) ?>" placeholder="08/04/2026">
            </div>
            <div class="form-group">
                <label for="f_grno">G.R No</label>
                <input type="text" id="f_grno" value="<?= htmlspecialchars($grNo, ENT_QUOTES) ?>" placeholder="3875">
            </div>
            <div class="form-group">
                <label for="f_srno">Sr. No</label>
                <input type="text" id="f_srno" value="<?= htmlspecialchars($certSerial, ENT_QUOTES) ?>" placeholder="01">
            </div>
            <div class="form-group">
                <label for="f_principal">Principal / signature label</label>
                <input type="text" id="f_principal" value="<?= htmlspecialchars($principalNm, ENT_QUOTES) ?>" placeholder="PRINCIPAL">
            </div>
            <div class="form-group">
                <label for="f_school">School name (header)</label>
                <input type="text" id="f_school" value="<?= htmlspecialchars($schoolName, ENT_QUOTES) ?>">
            </div>
        </div>

        <div class="form-actions">
            <button class="btn btn-outline" id="resetBtn" type="button"><i class="fas fa-rotate-left"></i> Reset to auto-filled</button>
        </div>
    </div>

    <!-- ═══════════ RIGHT: PREVIEW PANEL ═══════════ -->
    <div class="preview-panel">
        <div class="cert-toolbar">
            <button class="btn btn-whatsapp" id="whatsappCertBtn" type="button"><i class="fa-brands fa-whatsapp"></i> Share on WhatsApp</button>
            <button class="btn btn-success" id="pdfBtn" type="button"><i class="fas fa-file-pdf"></i> Download PDF</button>
            <button class="btn btn-primary" id="printBtn" type="button"><i class="fas fa-print"></i> Print</button>
            <span class="spacer"></span>
            <button class="mode-pill" id="editModeBtn" type="button" title="Click individual text directly in the preview"><span class="dot"></span><i class="fas fa-pen"></i> Edit-in-place: <span id="editModeLabel">ON</span></button>
        </div>

        <div class="cert-sheet" id="certSheet" data-mode="edit"
             data-student="<?= htmlspecialchars($defaults['student_name'], ENT_QUOTES) ?>"
             data-ay="<?= htmlspecialchars($defaults['academic_year'], ENT_QUOTES) ?>">

            <div class="cert-top">
                <div class="cert-school">
                    <img src="<?= e_attr(BASE_URL . safeLogoSrc($schoolLogo)) ?>" alt="" onerror="this.style.display='none'">
                    <div class="cert-school-text">
                        <h1 data-editable="school"><?= sanitize($schoolName) ?></h1>
                        <p class="cert-addr">166, Railway Lines, Near Rang Bhawan, Church Street, Solapur-413 001</p>
                    </div>
                </div>
            </div>
            <div class="cert-ref">
                <table class="cert-meta-table"><tr>
                    <td>G.R No:<span data-editable="grno"><?= sanitize($grNo) ?></span></td>
                    <td style="text-align:right;">Sr. No:<span data-editable="srno"><?= sanitize($certSerial) ?></span></td>
                </tr></table>
            </div>

            <div class="cert-title">
                <h2>Bonafide Certificate</h2>
            </div>

            <div class="cert-body">
                <p>
                    This is to certify that <span data-editable="student" class="cert-fill<?= empty($defaults['student_name']) ? ' is-blank' : '' ?>"><?= sanitize($defaults['student_name']) ?: '____' ?></span>
                    is a <strong>Bonafide Student</strong> of High School / Jr. College.
                    He/She is studying in <span data-editable="std" class="cert-fill<?= empty($defaults['std_div']) ? ' is-blank' : '' ?>"><?= sanitize($defaults['std_div']) ?: 'STD – ____' ?></span>.
                    His/Her date of birth according to the record is
                    <span data-editable="dob" class="cert-fill<?= empty($defaults['dob_digits']) ? ' is-blank' : '' ?>"><?= sanitize($defaults['dob_digits']) ?: '____' ?></span>.
                    (In words: <span data-editable="dobwords" class="cert-fill<?= empty($defaults['dob_words']) ? ' is-blank' : '' ?>"><?= sanitize($defaults['dob_words']) ?: '____' ?></span>.)
                    For <span data-editable="ay" class="cert-fill<?= empty($defaults['academic_year']) ? ' is-blank' : '' ?>"><?= sanitize($defaults['academic_year']) ?: '____' ?></span>.
                    <br>Caste :- <span data-editable="caste" class="cert-fill is-blank">_______</span>
                </p>
            </div>

            <div class="cert-foot">
                <div class="cert-date">
                    <span class="d-label">Date :</span><span data-editable="date" class="cert-fill"><?= sanitize($defaults['issue_date']) ?></span>
                </div>
                <div class="cert-photo">
                    <div class="photo-box" id="photoBox" title="Click to add passport photo">
                        <img id="photoImg" alt="Student photo">
                        <span class="photo-ph"><i class="fas fa-camera"></i>Photo</span>
                        <input type="file" id="photoInput" accept="image/*" hidden>
                    </div>
                </div>
                <div class="cert-principal">
                    <div class="sig-line"></div>
                    <span data-editable="principal"><?= sanitize($principalNm) ?></span>
                </div>
            </div>

        </div>
        <p class="cert-legend"><i class="fas fa-circle-info"></i> Hover over any highlighted element to edit it in place (when Edit-in-place is ON). Or use the form on the left.</p>
    </div>

</div><!-- /.bonafide-layout -->
        </div><!-- /.admin-content -->
    </main>
</div><!-- /.admin-layout -->

<!-- html2pdf for real PDF download (client-side, no server deps) -->
<script src="<?= BASE_URL ?>assets/vendor/html2canvas@1.4.1/html2canvas.min.js"></script>
<script src="<?= BASE_URL ?>assets/vendor/jspdf@2.5.1/jspdf.umd.min.js"></script>

<script src="<?= BASE_URL ?>assets/js/whatsapp-share.js?v=<?= filemtime(__DIR__ . '/../assets/js/whatsapp-share.js') ?>"></script>
<script type="application/json" id="bonafideData">
    <?= json_encode([
        'schoolName' => $schoolName,
        'csrfToken'  => generateCSRFToken()
    ], JSON_UNESCAPED_UNICODE) ?>
</script>
<script src="<?= BASE_URL ?>assets/js/bonafide.js?v=<?= (is_file(__DIR__ . '/../assets/js/bonafide.js') ? filemtime(__DIR__ . '/../assets/js/bonafide.js') : 0) ?>"></script>
<script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= (is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 0) ?>"></script>
</body>
</html>
