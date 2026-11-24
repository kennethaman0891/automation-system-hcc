<?php
/**
 * HCC Automation System — Annual Records Archive & Data Storage Vault
 * Centralized historical storage for Academic Years, Semester Results,
 * Unit Test Evaluations, Bonafide Certificates, and Multi-Year Student Promotion History.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

$adminPage  = 'records_archive';
$settings   = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church High School';
$adminUser  = sanitize($_SESSION['admin_name'] ?? 'Admin');

// Ensure database tables exist
rcEnsureArchiveTables();

$activeYear = rcGetActiveAcademicYear();
$error   = '';
$success = '';

// Handle CSV Export for Promotions
if (isset($_GET['action']) && $_GET['action'] === 'export_promotions_csv') {
    $expYear = trim($_GET['year'] ?? $activeYear);
    $db = getDB();
    $stmt = $db->prepare("
        SELECT source_year, source_std, source_div, source_sem,
               target_year, target_std, target_div, target_sem,
               roll_no, student_name, prev_obtained, prev_max_total,
               prev_percentage, prev_grade, prev_status, promoted_by, promoted_at
        FROM student_promotion_history
        WHERE target_year = ? OR source_year = ?
        ORDER BY CAST(target_std AS UNSIGNED), target_div, CAST(roll_no AS UNSIGNED)
    ");
    $stmt->execute([$expYear, $expYear]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="HCC_Promotion_History_' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $expYear) . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, [
        'Target Year', 'Target Standard', 'Target Division', 'Target Semester',
        'Roll No', 'Student Name',
        'Previous Year', 'Previous Standard', 'Previous Division', 'Previous Semester',
        'Previous Marks Obtained', 'Previous Max Total', 'Previous Percentage (%)', 'Previous Grade',
        'Promotion Status', 'Promoted By', 'Promotion Timestamp'
    ]);
    foreach ($rows as $r) {
        fputcsv($out, array_map('rcCsvSafeCell', [
            $r['target_year'], $r['target_std'], $r['target_div'], $r['target_sem'],
            $r['roll_no'], $r['student_name'],
            $r['source_year'], $r['source_std'], $r['source_div'], $r['source_sem'],
            $r['prev_obtained'], $r['prev_max_total'], $r['prev_percentage'], $r['prev_grade'],
            $r['prev_status'], $r['promoted_by'], $r['promoted_at']
        ]));
    }
    fclose($out);
    exit;
}

// Handle AJAX Baseline Requests
if (isset($_GET['action']) && $_GET['action'] === 'ajax_baseline') {
    header('Content-Type: application/json');
    $tYear = trim($_GET['target_year'] ?? '');
    $tStd  = trim($_GET['target_std'] ?? '');
    $tDiv  = trim($_GET['target_div'] ?? '');
    if ($tYear === '' || $tStd === '' || $tDiv === '') {
        echo json_encode(['ok' => false, 'error' => 'Missing parameters']);
        exit;
    }
    $history = rcGetPromotionHistoryForClass($tYear, $tStd, $tDiv);
    echo json_encode(['ok' => true, 'data' => $history]);
    exit;
}

// Handle AJAX Student Journey Requests
if (isset($_GET['action']) && $_GET['action'] === 'ajax_student_journey') {
    header('Content-Type: application/json');
    $sName = trim($_GET['student_name'] ?? '');
    $sRoll = trim($_GET['roll_no'] ?? '');
    if ($sName === '') {
        echo json_encode(['ok' => false, 'error' => 'Student name required']);
        exit;
    }
    $journey = rcGetStudentMultiYearHistory($sName, $sRoll);
    echo json_encode(['ok' => true, 'data' => $journey]);
    exit;
}

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        $error = 'Security check failed. Please refresh the page.';
    } else {
        $action = trim($_POST['action'] ?? '');

        if ($action === 'toggle_lock') {
            $lockYear = trim($_POST['year'] ?? '');
            $setLock  = (int)($_POST['lock_state'] ?? 1);
            if ($lockYear !== '') {
                $db = getDB();
                $chk = $db->prepare("SELECT 1 FROM academic_year_vault WHERE academic_year = ? LIMIT 1");
                $chk->execute([$lockYear]);
                if (!$chk->fetchColumn()) {
                    $ins = $db->prepare("INSERT INTO academic_year_vault (academic_year, is_active, is_locked, archived_by, archived_at) VALUES (?, ?, 0, ?, NOW())");
                    $ins->execute([$lockYear, ($lockYear === $activeYear ? 1 : 0), $adminUser]);
                }
                $ok = rcToggleArchiveLock($lockYear, $setLock === 1, $adminUser);
                if ($ok) {
                    $success = "Academic Year {$lockYear} " . ($setLock === 1 ? 'successfully locked into read-only archive mode.' : 'unlocked for modifications.');
                    rcLogAudit('archive_toggle_lock', 'vault', $success);
                } else {
                    $error = "Failed to update lock status for Academic Year {$lockYear}.";
                }
            }
        } elseif ($action === 'archive_year') {
            $srcYear = trim($_POST['source_year'] ?? '');
            $tgtYear = trim($_POST['target_year'] ?? '');
            $notes   = trim($_POST['notes'] ?? '');
            if ($srcYear === '') {
                $error = 'Source Academic Year is required.';
            } elseif (rcIsVaultLocked($srcYear)) {
                $error = "Academic Year {$srcYear} is already locked in the vault. Unlock it first before modifying archive metadata.";
            } elseif ($tgtYear !== '' && rcIsVaultLocked($tgtYear)) {
                $error = "Target Academic Year {$tgtYear} is locked in the archive vault and cannot be activated as an editable year.";
            } else {
                $res = rcArchiveAcademicYear($srcYear, $tgtYear !== '' ? $tgtYear : null, $notes, $adminUser);
                if ($res['ok']) {
                    $success = $res['message'];
                    rcLogAudit('archive_year', 'vault', $res['message']);
                } else {
                    $error = $res['error'] ?? 'Failed to archive academic year.';
                }
            }
        } elseif ($action === 'backup_database') {
            if (($_SESSION['admin_role'] ?? '') !== 'admin') {
                $error = 'Database backup requires full Administrator privileges.';
            } else {
                rcLogAudit('database_backup', 'database', 'Admin downloaded full SQL database backup');
                rcExportDatabaseSqlBackup();
                exit;
            }
        }
    }
}

// Retrieve Vault Multi-Year Summaries
$vaultYears = rcGetVaultYearsSummary();
$yearKeys   = array_column($vaultYears, 'academic_year');

// Resolve Selected Year
$selectedYear = trim($_GET['year'] ?? $activeYear);
if (!in_array($selectedYear, $yearKeys, true)) {
    $selectedYear = $yearKeys[0] ?? $activeYear;
}

// Fetch Detailed Data for Selected Year
$detail           = rcGetYearArchiveDetail($selectedYear);
$vaultMeta        = $detail['vault'];
$isYearLocked     = !empty($vaultMeta['is_locked']) || rcIsVaultLocked($selectedYear);
$resultsClasses   = $detail['results_classes'];
$unitTestClasses  = $detail['unit_test_classes'];
$bonafides        = $detail['bonafides'];
$eligibleStudents = $detail['eligible_students'];
$promotions       = $detail['promotions'];

// Group promotions by target cohort for easy summary display
$promotionCohorts = [];
foreach ($promotions as $pr) {
    $cohortKey = $pr['target_std'] . '-' . $pr['target_div'];
    if (!isset($promotionCohorts[$cohortKey])) {
        $promotionCohorts[$cohortKey] = [
            'source_year' => $pr['source_year'],
            'source_std'  => $pr['source_std'],
            'source_div'  => $pr['source_div'],
            'target_year' => $pr['target_year'],
            'target_std'  => $pr['target_std'],
            'target_div'  => $pr['target_div'],
            'students'    => 0,
            'total_pct'   => 0.0,
        ];
    }
    $promotionCohorts[$cohortKey]['students']++;
    $promotionCohorts[$cohortKey]['total_pct'] += (float)$pr['prev_percentage'];
}

$romanMap = ['1'=>'I','2'=>'II','3'=>'III','4'=>'IV','5'=>'V','6'=>'VI','7'=>'VII','8'=>'VIII','9'=>'IX','10'=>'X'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php require __DIR__ . '/includes/account_head.php'; ?>
    <title>Records Archive & Data Vault — <?= sanitize($schoolName) ?></title>
    <link rel="icon" href="../assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="../assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
    <link rel="stylesheet" href="../assets/css/whatsapp-share.css?v=<?= filemtime(__DIR__ . '/../assets/css/whatsapp-share.css') ?>">
    <style>
        :root {
            --vault-maroon: #7a2238;
            --vault-maroon-dark: #5a1a2a;
            --vault-gold: #c9a227;
            --vault-gold-light: #fef8e7;
            --vault-border: #e9e3e5;
            --vault-card-bg: #ffffff;
            --vault-muted: #64748b;
        }

        /* ── Top Header Strip ── */
        .vault-header-strip {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            margin-bottom: 24px;
        }
        .vault-title h1 {
            font-size: 24px;
            font-weight: 800;
            color: var(--brand);
            display: flex;
            align-items: center;
            gap: 12px;
            margin: 0 0 4px 0;
        }
        .vault-title p {
            color: var(--text-2);
            font-size: 13.5px;
            margin: 0;
        }
        .vault-actions {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }

        /* ── Year Master Cards Deck ── */
        .vault-years-deck {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(290px, 1fr));
            gap: 18px;
            margin-bottom: 30px;
        }
        .year-card {
            background: var(--vault-card-bg);
            border: 2px solid var(--border);
            border-radius: var(--radius);
            padding: 20px;
            transition: all 0.22s ease-in-out;
            cursor: pointer;
            position: relative;
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            text-decoration: none;
            color: inherit;
        }
        .year-card:hover {
            transform: translateY(-3px);
            border-color: var(--brand);
            box-shadow: var(--shadow);
        }
        .year-card.is-selected {
            border-color: var(--brand);
            background: #fffdfd;
            box-shadow: 0 0 0 3px rgba(122, 34, 56, 0.15), var(--shadow);
        }
        .year-card-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            margin-bottom: 14px;
        }
        .year-badge-title {
            font-size: 20px;
            font-weight: 800;
            color: var(--ink);
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .year-status-pill {
            font-size: 11px;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            padding: 4px 10px;
            border-radius: 20px;
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
        .status-active {
            background: #dcfce7;
            color: #15803d;
            border: 1px solid #bbf7d0;
        }
        .status-locked {
            background: #fee2e2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }
        .year-card-metrics {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin: 12px 0 16px 0;
            font-size: 12.5px;
        }
        .year-metric-box {
            background: var(--surface);
            padding: 8px 12px;
            border-radius: 8px;
            border: 1px solid #efe8ea;
        }
        .year-metric-box strong {
            display: block;
            font-size: 16px;
            font-weight: 800;
            color: var(--brand);
        }
        .year-metric-box span {
            color: var(--text-2);
            font-size: 11px;
        }
        .year-card-foot {
            border-top: 1px dashed var(--border);
            padding-top: 12px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 12px;
            font-weight: 600;
            color: var(--brand);
        }

        /* Add Year Action Card */
        .year-card-add {
            background: #faf6f7;
            border: 2px dashed #d1c0c5;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 24px;
            border-radius: var(--radius);
            cursor: pointer;
            transition: all 0.2s;
        }
        .year-card-add:hover {
            border-color: var(--brand);
            background: #fdf2f4;
            transform: translateY(-3px);
        }
        .year-card-add i {
            font-size: 32px;
            color: var(--brand);
            margin-bottom: 12px;
        }
        .year-card-add h3 {
            font-size: 16px;
            font-weight: 700;
            color: var(--brand);
            margin: 0 0 4px 0;
        }
        .year-card-add p {
            font-size: 12px;
            color: var(--text-2);
            margin: 0;
        }

        /* ── Selected Year Workspace ── */
        .vault-workspace {
            background: var(--vault-card-bg);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 24px;
            box-shadow: var(--shadow-sm);
            margin-bottom: 30px;
        }
        .vault-workspace-header {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 16px;
            padding-bottom: 18px;
            border-bottom: 2px solid var(--border);
            margin-bottom: 24px;
        }
        .vault-workspace-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .vault-workspace-title h2 {
            font-size: 22px;
            font-weight: 800;
            color: var(--ink);
            margin: 0;
        }

        /* ── 4 Dedicated Category Cards Grid ── */
        .vault-categories-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 22px;
        }
        @media (max-width: 992px) {
            .vault-categories-grid {
                grid-template-columns: 1fr;
            }
        }
        .category-card {
            background: #ffffff;
            border: 1.5px solid var(--border);
            border-radius: var(--radius);
            padding: 22px;
            box-shadow: var(--shadow-sm);
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            position: relative;
            transition: border-color 0.2s;
        }
        .category-card:hover {
            border-color: #cbd5e1;
        }
        .category-card-head {
            display: flex;
            align-items: flex-start;
            justify-content: space-between;
            gap: 12px;
            margin-bottom: 16px;
        }
        .category-icon-title {
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .category-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 20px;
        }
        .icon-results { background: #fee2e2; color: #991b1b; }
        .icon-unit-test { background: #e0e7ff; color: #3730a3; }
        .icon-bonafide { background: #fef3c7; color: #92400e; }
        .icon-promotion { background: #dcfce7; color: #166534; }

        .category-title-text h3 {
            font-size: 17px;
            font-weight: 800;
            color: var(--ink);
            margin: 0;
        }
        .category-title-text span {
            font-size: 12px;
            color: var(--text-2);
        }

        .category-metrics-strip {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .cat-metric-pill {
            background: var(--surface);
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            color: var(--text-2);
            border: 1px solid var(--border);
        }
        .cat-metric-pill strong {
            color: var(--brand);
        }

        .category-content-area {
            flex-grow: 1;
            margin-bottom: 18px;
            max-height: 240px;
            overflow-y: auto;
            border: 1px solid #f1ecee;
            border-radius: 8px;
            padding: 12px;
            background: #faf8f9;
        }
        .cat-item-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 7px 10px;
            border-bottom: 1px solid #efe8ea;
            font-size: 12.5px;
        }
        .cat-item-row:last-child { border-bottom: none; }
        .cat-item-left {
            display: flex;
            align-items: center;
            gap: 8px;
            font-weight: 600;
            color: var(--ink);
        }
        .cat-item-right {
            color: var(--text-2);
            font-size: 12px;
        }

        .category-actions-bar {
            display: flex;
            align-items: center;
            gap: 8px;
            flex-wrap: wrap;
            border-top: 1px solid var(--border);
            padding-top: 14px;
        }
        .btn-cat-action {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            font-weight: 700;
            padding: 7px 12px;
            border-radius: 6px;
            text-decoration: none;
            transition: all 0.15s;
        }
        .btn-cat-primary {
            background: var(--brand);
            color: white;
            border: 1px solid var(--brand);
        }
        .btn-cat-primary:hover {
            background: var(--brand-dark);
            color: white;
        }
        .btn-cat-outline {
            background: white;
            color: var(--text);
            border: 1px solid var(--border);
        }
        .btn-cat-outline:hover {
            background: var(--surface);
            border-color: #cbd5e1;
        }
        .btn-cat-whatsapp {
            background: #25D366;
            color: white;
            border: 1px solid #20b858;
        }
        .btn-cat-whatsapp:hover {
            background: #1eb855;
            color: white;
        }

        /* ── Modals & Drawers ── */
        .vault-modal-overlay {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(15, 23, 42, 0.65);
            display: none;
            align-items: center;
            justify-content: center;
            z-index: 1050;
            backdrop-filter: blur(4px);
            padding: 16px;
        }
        .vault-modal-overlay.is-active {
            display: flex;
        }
        .vault-modal {
            background: #ffffff;
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 950px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: modalFadeIn 0.2s ease-out;
        }
        @keyframes modalFadeIn {
            from { transform: scale(0.96); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        .vault-modal-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: #fdfbfc;
        }
        .vault-modal-header h3 {
            font-size: 18px;
            font-weight: 800;
            color: var(--brand);
            margin: 0;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .vault-modal-body {
            padding: 24px;
            overflow-y: auto;
            flex-grow: 1;
        }
        .vault-modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--border);
            display: flex;
            justify-content: flex-end;
            gap: 10px;
            background: #fdfbfc;
        }

        /* Progression Timeline */
        .progression-timeline {
            position: relative;
            padding-left: 28px;
            margin: 16px 0;
        }
        .progression-timeline::before {
            content: '';
            position: absolute;
            left: 10px;
            top: 0;
            bottom: 0;
            width: 2px;
            background: #e2e8f0;
        }
        .timeline-step {
            position: relative;
            margin-bottom: 24px;
        }
        .timeline-bullet {
            position: absolute;
            left: -28px;
            top: 4px;
            width: 20px;
            height: 20px;
            border-radius: 50%;
            background: var(--brand);
            color: white;
            font-size: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 3px solid white;
            box-shadow: 0 0 0 1px #cbd5e1;
        }
        .timeline-card {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 8px;
            padding: 14px 18px;
        }
    </style>
</head>
<body class="admin-page">
<div class="admin-layout">
    <?php include __DIR__ . '/includes/sidebar.php'; ?>

    <main class="admin-main">
        <header class="admin-header">
            <button class="sidebar-toggle js-sidebar-toggle" type="button" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
            <div class="header-titles">
                <h1 class="page-title"><i class="fa-solid fa-box-archive" style="color:var(--brand);margin-right:8px;"></i> Records Archive & Data Vault</h1>
                <p class="page-sub"><?= sanitize($schoolName) ?> &bull; Permanent Academic Storage Hub</p>
            </div>
            <div class="header-actions">
                <div class="admin-user">
                    <i class="fas fa-user-circle"></i>
                    <span><?= sanitize($adminUser) ?></span>
                </div>
            </div>
        </header>

        <div class="admin-content">

            <!-- Alerts -->
            <?php if (!empty($success)): ?>
            <div class="alert alert-success" style="margin-bottom:20px;display:flex;align-items:center;gap:10px;">
                <i class="fa-solid fa-circle-check" style="font-size:18px;"></i>
                <div><?= sanitize($success) ?></div>
            </div>
            <?php endif; ?>

            <?php if (!empty($error)): ?>
            <div class="alert alert-danger" style="margin-bottom:20px;display:flex;align-items:center;gap:10px;">
                <i class="fa-solid fa-triangle-exclamation" style="font-size:18px;"></i>
                <div><?= sanitize($error) ?></div>
            </div>
            <?php endif; ?>

            <!-- TOP BANNER & ACTION STRIP -->
            <div class="vault-header-strip">
                <div class="vault-title">
                    <h1>
                        <span>Academic Records Vault</span>
                        <span style="font-size:12px;font-weight:600;background:var(--brand-soft);color:var(--brand);padding:4px 12px;border-radius:20px;border:1px solid #ebd2d9;">
                            Active Session: <?= sanitize($activeYear) ?>
                        </span>
                    </h1>
                    <p>Explore, download, and preserve semester report cards, unit test evaluations, bonafide certificates, and multi-year promotion baselines.</p>
                </div>
                <div class="vault-actions">
                    <button type="button" class="btn btn-primary" onclick="openArchiveWizard()">
                        <i class="fa-solid fa-plus-circle"></i> Archive / Store Year
                    </button>
                    <a href="promote_students.php" class="btn btn-outline-secondary">
                        <i class="fa-solid fa-graduation-cap"></i> Promote Students
                    </a>
                    <form method="POST" action="records_archive.php" style="display:inline;" data-confirm="Download complete MySQL database backup SQL export?" data-confirm-title="Database Backup" data-confirm-btn="Download Backup">
                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                        <input type="hidden" name="action" value="backup_database">
                        <button type="submit" class="btn btn-outline-secondary" title="Download Complete MySQL Database Backup" style="background:#fff; border-color:#cbd5e1; color:#1e293b;">
                            <i class="fa-solid fa-database" style="color:#0284c7;"></i> Backup DB (.sql)
                        </button>
                    </form>
                </div>
            </div>

            <!-- SECTION 1: MASTER ACADEMIC YEARS DECK -->
            <h2 style="font-size:16px;font-weight:700;color:var(--text);margin-bottom:14px;display:flex;align-items:center;gap:8px;">
                <i class="fa-solid fa-layer-group" style="color:var(--brand);"></i> Academic Year Storage Cards
            </h2>

            <div class="vault-years-deck">
                <?php foreach ($vaultYears as $vy): 
                    $isSelected = ($vy['academic_year'] === $selectedYear);
                    $isLocked   = (bool)$vy['is_locked'];
                    $isActive   = (bool)$vy['is_active'];
                ?>
                <a href="records_archive.php?year=<?= urlencode($vy['academic_year']) ?>" class="year-card <?= $isSelected ? 'is-selected' : '' ?>">
                    <div>
                        <div class="year-card-head">
                            <div class="year-badge-title">
                                <i class="fa-solid fa-calendar-check" style="color:var(--brand);font-size:18px;"></i>
                                <?= sanitize($vy['academic_year']) ?>
                            </div>
                            <?php if ($isLocked && $isActive): ?>
                                <span class="year-status-pill status-locked"><i class="fa-solid fa-lock"></i> Active (Locked)</span>
                            <?php elseif ($isLocked): ?>
                                <span class="year-status-pill status-locked"><i class="fa-solid fa-lock"></i> Sealed &amp; Locked</span>
                            <?php elseif ($isActive): ?>
                                <span class="year-status-pill status-active"><i class="fa-solid fa-bolt"></i> Live Active</span>
                            <?php else: ?>
                                <span class="year-status-pill status-active"><i class="fa-solid fa-unlock"></i> Stored Unlocked</span>
                            <?php endif; ?>
                        </div>

                        <div class="year-card-metrics">
                            <div class="year-metric-box">
                                <strong><?= number_format($vy['total_students']) ?></strong>
                                <span>Total Students</span>
                            </div>
                            <div class="year-metric-box">
                                <strong><?= number_format($vy['total_result_records']) ?></strong>
                                <span>Report Card Marks</span>
                            </div>
                            <div class="year-metric-box">
                                <strong><?= number_format($vy['total_unit_test_records']) ?></strong>
                                <span>Unit Test Records</span>
                            </div>
                            <div class="year-metric-box">
                                <strong><?= number_format($vy['total_promotions']) ?></strong>
                                <span>Promoted Snapshots</span>
                            </div>
                        </div>
                    </div>

                    <div class="year-card-foot">
                        <span>
                            <?php if ($vy['total_result_records'] > 0): ?>
                                <i class="fa-solid fa-chart-pie"></i> Pass Rate: <?= $vy['pass_rate'] ?>%
                            <?php else: ?>
                                <i class="fa-solid fa-pen-nib"></i> Unit Tests Active
                            <?php endif; ?>
                        </span>
                        <span><?= $isSelected ? 'Viewing Vault <i class="fa-solid fa-arrow-right"></i>' : 'Open Vault &rarr;' ?></span>
                    </div>
                </a>
                <?php endforeach; ?>

                <div class="year-card-add" onclick="openArchiveWizard()">
                    <i class="fa-solid fa-circle-plus"></i>
                    <h3>+ Archive New Year</h3>
                    <p>Transition active marks into historical storage & seal records.</p>
                </div>
            </div>

            <!-- SECTION 2: SELECTED YEAR WORKSPACE & 4 DEDICATED CARDS -->
            <div class="vault-workspace">
                <div class="vault-workspace-header">
                    <div class="vault-workspace-title">
                        <div style="width:48px;height:48px;border-radius:12px;background:var(--brand-soft);color:var(--brand);display:flex;align-items:center;justify-content:center;font-size:22px;">
                            <i class="fa-solid fa-box-archive"></i>
                        </div>
                        <div>
                            <h2>Academic Year <?= sanitize($selectedYear) ?> Storage Vault</h2>
                            <p style="margin:0;font-size:13px;color:var(--text-2);">
                                Vault Status: 
                                <?php if ($isYearLocked): ?>
                                    <strong style="color:#991b1b;"><i class="fa-solid fa-lock"></i> Locked &amp; Sealed (Read-Only Archive)</strong>
                                <?php else: ?>
                                    <strong style="color:#15803d;"><i class="fa-solid fa-unlock"></i> Open / Active</strong>
                                <?php endif; ?>
                                <?php if (!empty($vaultMeta['archived_by'])): ?>
                                    &bull; Archived by <?= sanitize($vaultMeta['archived_by']) ?>
                                <?php endif; ?>
                            </p>
                        </div>
                    </div>

                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <button type="button" class="btn btn-outline-secondary" onclick="openToggleLockModal('<?= e_attr($selectedYear) ?>', <?= $isYearLocked ? 0 : 1 ?>)">
                            <?php if ($isYearLocked): ?>
                                <i class="fa-solid fa-lock-open"></i> Unlock Year for Editing
                            <?php else: ?>
                                <i class="fa-solid fa-lock"></i> Lock Year (Read-Only)
                            <?php endif; ?>
                        </button>
                        <a href="records_archive.php?action=export_promotions_csv&year=<?= urlencode($selectedYear) ?>" class="btn btn-outline-secondary">
                            <i class="fa-solid fa-file-csv"></i> Export Promotion Baseline CSV
                        </a>
                    </div>
                </div>

                <?php if ($isYearLocked): ?>
                <div style="margin-bottom:18px;padding:12px 16px;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;font-size:13px;display:flex;align-items:center;gap:10px;">
                    <i class="fa-solid fa-shield-halved" style="font-size:16px;"></i>
                    <div><strong>Read-Only Archive Lock Enforced for <?= sanitize($selectedYear) ?>:</strong> Viewing, printing, WhatsApp sharing, and CSV exports remain enabled, while Excel imports and batch promotions into this sealed cohort are locked.</div>
                </div>
                <?php endif; ?>

                <!-- THE 4 DEDICATED CATEGORY CARDS -->
                <div class="vault-categories-grid">

                    <!-- CARD 1: SEMESTER RESULTS & FINAL MARKSHEETS -->
                    <div class="category-card">
                        <div>
                            <div class="category-card-head">
                                <div class="category-icon-title">
                                    <div class="category-icon icon-results">
                                        <i class="fa-solid fa-table"></i>
                                    </div>
                                    <div class="category-title-text">
                                        <h3>Semester Results & Marksheets</h3>
                                        <span>Summative & Formative Evaluations (Sem 1 & Sem 2)</span>
                                    </div>
                                </div>
                            </div>

                            <div class="category-metrics-strip">
                                <span class="cat-metric-pill">Records: <strong><?= number_format(array_sum(array_column($resultsClasses, 'mark_records'))) ?></strong></span>
                                <span class="cat-metric-pill">Classes: <strong><?= count($resultsClasses) ?></strong></span>
                                <span class="cat-metric-pill">Students: <strong><?= number_format(array_sum(array_column($resultsClasses, 'student_count'))) ?></strong></span>
                            </div>

                            <div class="category-content-area">
                                <?php if (empty($resultsClasses)): ?>
                                    <div style="text-align:center;padding:30px 10px;color:var(--text-3);font-size:13px;">
                                        <i class="fa-regular fa-folder-open" style="font-size:28px;margin-bottom:8px;display:block;"></i>
                                        No semester result records found for <?= sanitize($selectedYear) ?>.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($resultsClasses as $rc): ?>
                                    <div class="cat-item-row">
                                        <div class="cat-item-left">
                                            <i class="fa-solid fa-graduation-cap" style="color:var(--brand);font-size:12px;"></i>
                                            <?= sanitize($rc['class_label']) ?>
                                        </div>
                                        <div class="cat-item-right">
                                            <strong><?= $rc['student_count'] ?></strong> students &bull; Avg: <?= $rc['average_pct'] ?>%
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="category-actions-bar">
                            <a href="results.php?year=<?= urlencode($selectedYear) ?>" class="btn-cat-action btn-cat-primary">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i> Open Marksheets
                            </a>
                            <a href="whatsapp_share.php?doc_type=report_card&year=<?= urlencode($selectedYear) ?>" class="btn-cat-action btn-cat-whatsapp">
                                <i class="fa-brands fa-whatsapp"></i> Share on WhatsApp
                            </a>
                            <?php if (!$isYearLocked): ?>
                            <a href="import_excel.php" class="btn-cat-action btn-cat-outline">
                                <i class="fa-solid fa-file-excel"></i> Import Excel
                            </a>
                            <?php else: ?>
                            <span class="btn-cat-action btn-cat-outline" aria-disabled="true" style="opacity:0.55;cursor:not-allowed;" title="Year locked in archive vault">
                                <i class="fa-solid fa-lock"></i> Import Locked
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- CARD 2: UNIT TEST EVALUATIONS & SLIPS -->
                    <div class="category-card">
                        <div>
                            <div class="category-card-head">
                                <div class="category-icon-title">
                                    <div class="category-icon icon-unit-test">
                                        <i class="fa-solid fa-pen-to-square"></i>
                                    </div>
                                    <div class="category-title-text">
                                        <h3>Unit Test Evaluations & Slips</h3>
                                        <span>Unit Test 1, Unit Test 2, and Continuous Assessments</span>
                                    </div>
                                </div>
                            </div>

                            <div class="category-metrics-strip">
                                <span class="cat-metric-pill">Test Marks: <strong><?= number_format(array_sum(array_column($unitTestClasses, 'mark_records'))) ?></strong></span>
                                <span class="cat-metric-pill">Cohorts: <strong><?= count($unitTestClasses) ?></strong></span>
                                <span class="cat-metric-pill">Students: <strong><?= number_format(array_sum(array_column($unitTestClasses, 'student_count'))) ?></strong></span>
                            </div>

                            <div class="category-content-area">
                                <?php if (empty($unitTestClasses)): ?>
                                    <div style="text-align:center;padding:30px 10px;color:var(--text-3);font-size:13px;">
                                        <i class="fa-regular fa-folder-open" style="font-size:28px;margin-bottom:8px;display:block;"></i>
                                        No unit test evaluations recorded for <?= sanitize($selectedYear) ?>.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($unitTestClasses as $utc): ?>
                                    <div class="cat-item-row">
                                        <div class="cat-item-left">
                                            <i class="fa-solid fa-file-signature" style="color:#4f46e5;font-size:12px;"></i>
                                            <?= sanitize($utc['class_label']) ?>
                                        </div>
                                        <div class="cat-item-right">
                                            <strong><?= $utc['student_count'] ?></strong> students (<?= $utc['subject_count'] ?> subjs)
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="category-actions-bar">
                            <a href="unit_tests.php?year=<?= urlencode($selectedYear) ?>" class="btn-cat-action btn-cat-primary">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i> Open Unit Slips
                            </a>
                            <a href="whatsapp_share.php?doc_type=unit_test&year=<?= urlencode($selectedYear) ?>" class="btn-cat-action btn-cat-whatsapp">
                                <i class="fa-brands fa-whatsapp"></i> Share Slips via WhatsApp
                            </a>
                            <?php if (!$isYearLocked): ?>
                            <a href="import_unit_tests.php" class="btn-cat-action btn-cat-outline">
                                <i class="fa-solid fa-file-excel"></i> Import Unit Tests
                            </a>
                            <?php else: ?>
                            <span class="btn-cat-action btn-cat-outline" aria-disabled="true" style="opacity:0.55;cursor:not-allowed;" title="Year locked in archive vault">
                                <i class="fa-solid fa-lock"></i> Import Locked
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- CARD 3: BONAFIDE CERTIFICATES VAULT -->
                    <div class="category-card">
                        <div>
                            <div class="category-card-head">
                                <div class="category-icon-title">
                                    <div class="category-icon icon-bonafide">
                                        <i class="fa-solid fa-certificate"></i>
                                    </div>
                                    <div class="category-title-text">
                                        <h3>Bonafide Certificates Vault</h3>
                                        <span>Issued Certificate Ledger & Student Roster Archive</span>
                                    </div>
                                </div>
                            </div>

                            <div class="category-metrics-strip">
                                <span class="cat-metric-pill">Issued Certificates: <strong><?= count($bonafides) ?></strong></span>
                                <span class="cat-metric-pill">Eligible Students: <strong><?= number_format($eligibleStudents) ?></strong></span>
                            </div>

                            <div class="category-content-area">
                                <?php if (empty($bonafides)): ?>
                                    <div style="text-align:center;padding:26px 10px;color:var(--text-3);font-size:13px;">
                                        <i class="fa-solid fa-stamp" style="font-size:26px;margin-bottom:8px;display:block;color:#d97706;"></i>
                                        No bonafide certificates formally recorded in ledger for <?= sanitize($selectedYear) ?> yet.
                                        <div style="margin-top:8px;">
                                            <a href="bonafide_certificate.php" class="btn btn-sm btn-outline-secondary" style="font-size:12px;">
                                                <i class="fa-solid fa-plus"></i> Issue New Bonafide
                                            </a>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($bonafides as $bf): ?>
                                    <div class="cat-item-row">
                                        <div class="cat-item-left">
                                            <i class="fa-solid fa-award" style="color:#d97706;font-size:12px;"></i>
                                            <strong>#<?= sanitize($bf['cert_serial']) ?></strong> &bull; <?= sanitize($bf['student_name']) ?>
                                        </div>
                                        <div class="cat-item-right">
                                            GR: <?= sanitize($bf['gr_no']) ?> &bull; <?= date('d M Y', strtotime($bf['issued_date'])) ?>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="category-actions-bar">
                            <a href="bonafide_certificate.php" class="btn-cat-action btn-cat-primary">
                                <i class="fa-solid fa-plus-circle"></i> Issue New Certificate
                            </a>
                            <a href="whatsapp_share.php?doc_type=bonafide" class="btn-cat-action btn-cat-whatsapp">
                                <i class="fa-brands fa-whatsapp"></i> Share on WhatsApp
                            </a>
                        </div>
                    </div>

                    <!-- CARD 4: PREVIOUS STANDARD & STUDENT PROMOTION HISTORY -->
                    <div class="category-card" style="border: 2px solid #bbf7d0; background: #fcfdfc;">
                        <div>
                            <div class="category-card-head">
                                <div class="category-icon-title">
                                    <div class="category-icon icon-promotion">
                                        <i class="fa-solid fa-graduation-cap"></i>
                                    </div>
                                    <div class="category-title-text">
                                        <h3>Previous Standard & Promotion History</h3>
                                        <span>Preserved Past Standard Marks, Percentages & Trajectory</span>
                                    </div>
                                </div>
                            </div>

                            <div class="category-metrics-strip">
                                <span class="cat-metric-pill">Promoted Snapshots: <strong><?= number_format(count($promotions)) ?></strong></span>
                                <span class="cat-metric-pill">Cohorts: <strong><?= count($promotionCohorts) ?></strong></span>
                            </div>

                            <div class="category-content-area" style="background:#ffffff;">
                                <?php if (empty($promotionCohorts)): ?>
                                    <div style="text-align:center;padding:26px 10px;color:var(--text-3);font-size:13px;">
                                        <i class="fa-solid fa-user-graduate" style="font-size:26px;margin-bottom:8px;display:block;color:#16a34a;"></i>
                                        No promotion baseline snapshots recorded for this academic year yet.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($promotionCohorts as $ck => $coh): 
                                        $avgPrevPct = $coh['students'] > 0 ? round($coh['total_pct'] / $coh['students'], 1) : 0.0;
                                        $rSrcStd = $romanMap[(string)$coh['source_std']] ?? $coh['source_std'];
                                        $rTgtStd = $romanMap[(string)$coh['target_std']] ?? $coh['target_std'];
                                    ?>
                                    <div class="cat-item-row" style="background:#f8fafc;margin-bottom:6px;border-radius:6px;padding:8px 12px;border:1px solid #e2e8f0;">
                                        <div>
                                            <div style="font-weight:700;color:var(--ink);display:flex;align-items:center;gap:6px;">
                                                <span>STD <?= sanitize($rSrcStd) ?>-<?= sanitize($coh['source_div']) ?></span>
                                                <i class="fa-solid fa-arrow-right" style="font-size:10px;color:#16a34a;"></i>
                                                <span style="color:#166534;">STD <?= sanitize($rTgtStd) ?>-<?= sanitize($coh['target_div']) ?></span>
                                            </div>
                                            <div style="font-size:11.5px;color:var(--text-2);margin-top:2px;">
                                                <strong><?= $coh['students'] ?></strong> students &bull; Prev Standard Avg: <strong><?= $avgPrevPct ?>%</strong>
                                            </div>
                                        </div>
                                        <div>
                                            <button type="button" class="btn btn-sm btn-outline-primary" style="font-size:11px;padding:4px 8px;"
                                                    onclick="openCohortBaselineModal('<?= e_attr($coh['target_year']) ?>', '<?= e_attr($coh['target_std']) ?>', '<?= e_attr($coh['target_div']) ?>', 'STD <?= sanitize($rSrcStd) ?>-<?= sanitize($coh['source_div']) ?> → STD <?= sanitize($rTgtStd) ?>-<?= sanitize($coh['target_div']) ?>')">
                                                <i class="fa-solid fa-eye"></i> View Baseline
                                            </button>
                                        </div>
                                    </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>

                        <div class="category-actions-bar">
                            <div style="display:flex;align-items:center;gap:6px;width:100%;margin-bottom:6px;">
                                <div style="position:relative;flex-grow:1;">
                                    <i class="fa-solid fa-search" style="position:absolute;left:10px;top:10px;color:var(--text-3);font-size:12px;"></i>
                                    <input type="text" id="journeySearchInput" placeholder="Lookup student multi-year journey..." 
                                           style="width:100%;padding:7px 10px 7px 30px;border-radius:6px;border:1px solid var(--border);font-size:12px;"
                                           onkeydown="if(event.key==='Enter') searchStudentJourney()">
                                </div>
                                <button type="button" class="btn-cat-action btn-cat-primary" onclick="searchStudentJourney()">
                                    <i class="fa-solid fa-magnifying-glass"></i> Trace
                                </button>
                            </div>
                            <a href="records_archive.php?action=export_promotions_csv&year=<?= urlencode($selectedYear) ?>" class="btn-cat-action btn-cat-outline">
                                <i class="fa-solid fa-download"></i> Export Roster CSV
                            </a>
                            <?php if (!$isYearLocked): ?>
                            <a href="promote_students.php?source_year=<?= urlencode($selectedYear) ?>" class="btn-cat-action btn-cat-outline">
                                <i class="fa-solid fa-plus-circle"></i> Promote Next Batch
                            </a>
                            <?php else: ?>
                            <span class="btn-cat-action btn-cat-outline" aria-disabled="true" style="opacity:0.55;cursor:not-allowed;" title="Year locked in archive vault">
                                <i class="fa-solid fa-lock"></i> Promotion Locked
                            </span>
                            <?php endif; ?>
                        </div>
                    </div>

                </div><!-- /.vault-categories-grid -->

            </div><!-- /.vault-workspace -->

        </div><!-- /.admin-content -->
    </main>
</div><!-- /.admin-layout -->

<!-- ═══════════════════════════════════════════════════════════════════
     MODAL 1: PREVIOUS STANDARD BASELINE VIEWER
     ═══════════════════════════════════════════════════════════════════ -->
<div class="vault-modal-overlay" id="baselineModal">
    <div class="vault-modal">
        <div class="vault-modal-header">
            <h3><i class="fa-solid fa-clock-rotate-left"></i> Previous Standard Baseline — <span id="baselineModalTitle">Class</span></h3>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="closeModal('baselineModal')"><i class="fa-solid fa-times"></i></button>
        </div>
        <div class="vault-modal-body">
            <div style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:16px;">
                <div style="position:relative;flex-grow:1;max-width:320px;">
                    <i class="fa-solid fa-search" style="position:absolute;left:10px;top:10px;color:var(--text-3);font-size:12px;"></i>
                    <input type="text" id="baselineTableFilter" placeholder="Filter student by roll or name..." 
                           style="width:100%;padding:7px 10px 7px 30px;border-radius:6px;border:1px solid var(--border);font-size:12.5px;"
                           onkeyup="filterBaselineTable()">
                </div>
                <div style="font-size:13px;color:var(--text-2);">
                    Retained Historical Snapshot &bull; <span id="baselineStudentCount">0</span> students
                </div>
            </div>

            <div style="overflow-x:auto;border:1px solid var(--border);border-radius:8px;">
                <table class="table" style="width:100%;margin:0;font-size:12.5px;" id="baselineTable">
                    <thead style="background:#f8fafc;font-size:12px;text-transform:uppercase;">
                        <tr>
                            <th style="padding:10px;">Roll</th>
                            <th style="padding:10px;">Student Name</th>
                            <th style="padding:10px;">Prior Standard</th>
                            <th style="padding:10px;text-align:right;">Prior Marks</th>
                            <th style="padding:10px;text-align:right;">Prior %</th>
                            <th style="padding:10px;text-align:center;">Prior Grade</th>
                            <th style="padding:10px;text-align:center;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="baselineTableBody">
                        <tr><td colspan="7" style="text-align:center;padding:20px;">Loading historical baseline...</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="vault-modal-footer">
            <button type="button" class="btn btn-outline-secondary" onclick="closeModal('baselineModal')">Close</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     MODAL 2: STUDENT MULTI-YEAR ACADEMIC JOURNEY
     ═══════════════════════════════════════════════════════════════════ -->
<div class="vault-modal-overlay" id="journeyModal">
    <div class="vault-modal">
        <div class="vault-modal-header">
            <h3><i class="fa-solid fa-graduation-cap"></i> Student Academic Progression Journey</h3>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="closeModal('journeyModal')"><i class="fa-solid fa-times"></i></button>
        </div>
        <div class="vault-modal-body" id="journeyModalBody">
            <div style="text-align:center;padding:30px;"><i class="fa-solid fa-spinner fa-spin"></i> Retrieving academic timeline...</div>
        </div>
        <div class="vault-modal-footer">
            <button type="button" class="btn btn-outline-secondary" onclick="closeModal('journeyModal')">Close</button>
        </div>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     MODAL 3: ARCHIVE & SEAL ACADEMIC YEAR WIZARD
     ═══════════════════════════════════════════════════════════════════ -->
<div class="vault-modal-overlay" id="archiveWizardModal">
    <div class="vault-modal" style="max-width:540px;">
        <form method="POST" action="records_archive.php">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="action" value="archive_year">

            <div class="vault-modal-header">
                <h3><i class="fa-solid fa-box-archive"></i> Archive & Seal Academic Year</h3>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="closeModal('archiveWizardModal')"><i class="fa-solid fa-times"></i></button>
            </div>
            <div class="vault-modal-body">
                <div style="background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;padding:12px;margin-bottom:18px;font-size:13px;color:#1e40af;">
                    <i class="fa-solid fa-shield-halved" style="margin-right:6px;"></i>
                    Archiving packages this academic year into a permanent, read-only historical card. All student marksheets, unit test evaluations, and bonafides remain 100% accessible anytime.
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label style="font-weight:700;font-size:13px;display:block;margin-bottom:6px;">Source Academic Year to Archive & Seal:</label>
                    <select name="source_year" class="form-control" style="width:100%;padding:8px 12px;border-radius:6px;border:1px solid var(--border);" required>
                        <?php foreach ($vaultYears as $vy): ?>
                        <option value="<?= e_attr($vy['academic_year']) ?>" <?= $vy['academic_year']===$selectedYear ? 'selected' : '' ?>>
                            Academic Year <?= sanitize($vy['academic_year']) ?> (<?= number_format($vy['total_students']) ?> Students)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label style="font-weight:700;font-size:13px;display:block;margin-bottom:6px;">Incoming New Academic Year (Optional):</label>
                    <input type="text" name="target_year" placeholder="e.g. 2026-27" class="form-control" style="width:100%;padding:8px 12px;border-radius:6px;border:1px solid var(--border);">
                    <span style="font-size:11.5px;color:var(--text-3);">Creates a fresh incoming card ready for enrollment & promotions.</span>
                </div>

                <div class="form-group" style="margin-bottom:16px;">
                    <label style="font-weight:700;font-size:13px;display:block;margin-bottom:6px;">Archive Audit Notes:</label>
                    <textarea name="notes" rows="2" placeholder="e.g. Annual examination completed, promotions executed." class="form-control" style="width:100%;padding:8px 12px;border-radius:6px;border:1px solid var(--border);"></textarea>
                </div>
            </div>
            <div class="vault-modal-footer">
                <button type="button" class="btn btn-outline-secondary" onclick="closeModal('archiveWizardModal')">Cancel</button>
                <button type="submit" class="btn btn-primary"><i class="fa-solid fa-lock"></i> Seal & Store into Vault</button>
            </div>
        </form>
    </div>
</div>

<!-- ═══════════════════════════════════════════════════════════════════
     MODAL 4: TOGGLE LOCK CONFIRMATION
     ═══════════════════════════════════════════════════════════════════ -->
<div class="vault-modal-overlay" id="toggleLockModal">
    <div class="vault-modal" style="max-width:480px;">
        <form method="POST" action="records_archive.php">
            <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
            <input type="hidden" name="action" value="toggle_lock">
            <input type="hidden" name="year" id="toggleLockYearInput" value="">
            <input type="hidden" name="lock_state" id="toggleLockStateInput" value="1">

            <div class="vault-modal-header">
                <h3 id="toggleLockTitle"><i class="fa-solid fa-shield-halved"></i> Lock Academic Year</h3>
                <button type="button" class="btn btn-sm btn-outline-secondary" onclick="closeModal('toggleLockModal')"><i class="fa-solid fa-times"></i></button>
            </div>
            <div class="vault-modal-body">
                <p id="toggleLockMessage" style="font-size:13.5px;color:var(--text);margin:0 0 12px 0;">
                    Are you sure you want to change the security state for this academic year?
                </p>
                <div style="font-size:12px;color:var(--text-2);background:var(--surface);padding:10px 14px;border-radius:6px;border:1px solid var(--border);">
                    <i class="fa-solid fa-circle-info" style="color:var(--brand);margin-right:6px;"></i>
                    When locked, marks cannot be accidentally edited or overwritten, while all reports, slips, and exports remain 100% active.
                </div>
            </div>
            <div class="vault-modal-footer">
                <button type="button" class="btn btn-outline-secondary" onclick="closeModal('toggleLockModal')">Cancel</button>
                <button type="submit" class="btn btn-primary" id="toggleLockSubmitBtn"><i class="fa-solid fa-check"></i> Confirm</button>
            </div>
        </form>
    </div>
</div>

<script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= filemtime(__DIR__ . '/../assets/js/admin.js') ?>"></script>
<script src="<?= BASE_URL ?>assets/js/records-archive.js?v=<?= (is_file(__DIR__ . '/../assets/js/records-archive.js') ? filemtime(__DIR__ . '/../assets/js/records-archive.js') : 0) ?>"></script>
</body>
</html>
