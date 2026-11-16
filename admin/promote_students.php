<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

$adminPage = 'promote_students';
$db = getDB();
$settings = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church High School';
$adminUser = sanitize($_SESSION['admin_name'] ?? 'Admin');

// Ensure tables
rcEnsureNotificationsTable();

// Academic Year Selection (dynamic active academic year + computed target year)
$activeYear = rcGetActiveAcademicYear();
$sourceYear = trim($_GET['source_year'] ?? $activeYear);
if ($sourceYear === '') $sourceYear = $activeYear;

$computedNextYear = $sourceYear;
if (preg_match('/^(\d{4})-(\d{2,4})$/', $sourceYear, $ym)) {
    $y1 = (int)$ym[1] + 1;
    $y2 = strlen($ym[2]) === 2 ? sprintf('%02d', ((int)$ym[2] + 1) % 100) : (string)((int)$ym[2] + 1);
    $computedNextYear = "{$y1}-{$y2}";
}
$targetYear = trim($_GET['target_year'] ?? $computedNextYear);
if ($targetYear === '') $targetYear = $computedNextYear;
$activeClass = trim($_GET['class'] ?? ''); // e.g. "1|A|Sem 1"

$error = '';
$success = '';
$promotedTargetClass = '';

// Handle POST actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        $error = 'Security check failed. Please refresh the page.';
    } else {
        $action = trim($_POST['action'] ?? '');

        if ($action === 'promote_class') {
            $pSourceYear = trim($_POST['source_year'] ?? $sourceYear);
            $pSourceStd  = trim($_POST['source_std'] ?? '');
            $pSourceDiv  = trim($_POST['source_div'] ?? '');
            $pSourceSem  = trim($_POST['source_sem'] ?? 'Sem 1');
            $pTargetYear = trim($_POST['target_year'] ?? $targetYear);
            $pTargetStd  = trim($_POST['target_std'] ?? '');
            $pTargetDiv  = trim($_POST['target_div'] ?? $pSourceDiv);
            $pTargetSem  = trim($_POST['target_sem'] ?? $pSourceSem);
            $selectedRolls = $_POST['selected_rolls'] ?? [];

            if (empty($selectedRolls) || !is_array($selectedRolls)) {
                $error = 'Please select at least one student to promote.';
            } else {
                $res = rcPromoteClassWithChecklist(
                    $pSourceYear, $pSourceStd, $pSourceDiv, $pSourceSem,
                    $pTargetYear, $pTargetStd, $pTargetDiv, $pTargetSem,
                    $selectedRolls,
                    $adminUser
                );

                if ($res['ok']) {
                    $success = $res['message'];
                    $promotedTargetClass = $res['target_class'];
                    $activeClass = ''; // close modal/drawer
                    rcLogAudit('promote_students', 'academic', $res['message']);
                } else {
                    $error = $res['error'];
                }
            }
        } elseif ($action === 'rollback_promotion') {
            $rTargetYear = trim($_POST['target_year'] ?? '');
            $rTargetStd  = trim($_POST['target_std'] ?? '');
            $rTargetDiv  = trim($_POST['target_div'] ?? '');
            $rTargetSem  = trim($_POST['target_sem'] ?? 'Sem 1');

            if ($rTargetYear && $rTargetStd && $rTargetDiv) {
                $res = rcRollbackClassPromotion($rTargetYear, $rTargetStd, $rTargetDiv, $rTargetSem);
                if ($res['ok']) {
                    $success = "Reverted promotion for STD {$rTargetStd} {$rTargetDiv} ({$rTargetYear}). Deleted {$res['deleted_rows']} entries.";
                    rcLogAudit('rollback_promotion', 'academic', $success);
                } else {
                    $error = $res['error'];
                }
            }
        } elseif ($action === 'mark_notifs_read') {
            rcMarkNotificationsRead();
            header('Content-Type: application/json');
            echo json_encode(['ok' => true]);
            exit;
        }
    }
}

// Fetch Promotion Overview for all 10 Standards
$classesOverview = rcGetPromotionOverview($sourceYear, $targetYear);

// Calculate Summary Statistics
$totalClasses = count($classesOverview);
$promotedClassesCount = 0;
$totalEligibleStudents = 0;
$totalPromotedStudents = 0;

foreach ($classesOverview as $c) {
    $totalEligibleStudents += $c['student_count'];
    if ($c['is_promoted']) {
        $promotedClassesCount++;
        $totalPromotedStudents += $c['promoted_count'];
    }
}

// If a specific class is selected for review, load its student checklist
$checklistStudents = [];
$activeClassMeta = null;
$targetSubjectsPreview = [];

if ($activeClass !== '') {
    $parts = explode('|', $activeClass);
    if (count($parts) >= 3) {
        $acStd = $parts[0];
        $acDiv = $parts[1];
        $acSem = $parts[2] ?? 'Sem 1';
        $activeClassMeta = [
            'std' => $acStd,
            'div' => $acDiv,
            'sem' => $acSem,
            'target_std' => ((int)$acStd >= 10) ? 'Graduated' : (string)((int)$acStd + 1),
            'target_div' => $acDiv,
            'target_sem' => ($acSem === 'Sem 2' ? 'Sem 1' : $acSem),
        ];
        $checklistStudents = rcGetClassStudentsForPromotion($sourceYear, $acStd, $acDiv, $acSem);
        if ($activeClassMeta['target_std'] !== 'Graduated') {
            $targetSubjectsPreview = rcGetStandardSubjects($activeClassMeta['target_std'], $targetYear, $acDiv, $activeClassMeta['target_sem']);
        }
    }
}

// Notifications for header bell
$notifData = rcGetRecentNotifications(8);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Promote Students — <?= sanitize($schoolName) ?></title>
    <link rel="icon" href="../assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="../assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
    <style>
        /* Specific enhancements for Promote Students hub */
        .promo-header-card {
            background: linear-gradient(135deg, #2a1018 0%, #4a1525 100%);
            color: #fff;
            border-radius: var(--radius);
            padding: 24px 28px;
            margin-bottom: 24px;
            box-shadow: var(--shadow-md);
            position: relative;
            overflow: hidden;
        }
        .promo-header-card::after {
            content: "\f19d";
            font-family: "Font Awesome 6 Free";
            font-weight: 900;
            position: absolute;
            right: 20px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 8rem;
            color: rgba(255,255,255,0.04);
            pointer-events: none;
        }
        .promo-header-title {
            font-size: 1.45rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 12px;
            margin-bottom: 8px;
        }
        .promo-header-sub {
            font-size: 0.9rem;
            color: rgba(255,255,255,0.8);
            max-width: 780px;
            line-height: 1.5;
        }
        .promo-year-bar {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 16px;
            margin-top: 20px;
            background: rgba(255,255,255,0.08);
            padding: 12px 18px;
            border-radius: 8px;
            backdrop-filter: blur(4px);
        }
        .promo-year-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .promo-year-input {
            background: #fff;
            color: var(--text);
            border: 1px solid rgba(255,255,255,0.2);
            padding: 6px 12px;
            border-radius: 6px;
            font-weight: 700;
            font-size: 0.9rem;
        }
        .promo-year-arrow {
            font-size: 1.1rem;
            color: #fbbf24;
        }

        /* Stats row */
        .promo-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 16px;
            margin-bottom: 24px;
        }
        .promo-stat-card {
            background: var(--paper);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: var(--shadow-sm);
        }
        .promo-stat-icon {
            width: 46px;
            height: 46px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        .promo-stat-icon.primary { background: #fee2e2; color: #991b1b; }
        .promo-stat-icon.success { background: #dcfce7; color: #166534; }
        .promo-stat-icon.amber   { background: #fef3c7; color: #92400e; }
        .promo-stat-val { font-size: 1.35rem; font-weight: 800; color: var(--text); line-height: 1.1; }
        .promo-stat-lbl { font-size: 0.78rem; color: var(--muted); font-weight: 500; margin-top: 4px; }

        /* Notification bell styling */
        .notif-container {
            position: relative;
            display: inline-block;
            margin-right: 16px;
        }
        .notif-trigger {
            background: transparent;
            border: 1px solid var(--border);
            border-radius: 8px;
            width: 38px;
            height: 38px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
            color: var(--text);
            position: relative;
            transition: all 0.15s ease;
        }
        .notif-trigger:hover {
            background: var(--bg);
            border-color: var(--accent);
            color: var(--accent);
        }
        .notif-badge {
            position: absolute;
            top: -5px;
            right: -5px;
            background: #dc2626;
            color: #fff;
            font-size: 0.65rem;
            font-weight: 700;
            border-radius: 10px;
            padding: 2px 6px;
            border: 2px solid #fff;
            min-width: 18px;
            text-align: center;
        }
        .notif-dropdown {
            display: none;
            position: absolute;
            right: 0;
            top: 44px;
            width: 360px;
            background: var(--paper);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            z-index: 1000;
            overflow: hidden;
            animation: fadeIn 0.15s ease;
        }
        .notif-dropdown.show { display: block; }
        .notif-dropdown-header {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            background: var(--bg);
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.85rem;
            font-weight: 700;
        }
        .notif-dropdown-list {
            max-height: 380px;
            overflow-y: auto;
        }
        .notif-item {
            padding: 12px 16px;
            border-bottom: 1px solid var(--border);
            display: flex;
            gap: 12px;
            font-size: 0.82rem;
            line-height: 1.4;
            transition: background 0.15s ease;
            text-decoration: none;
            color: inherit;
        }
        .notif-item:hover { background: #fafaf9; }
        .notif-item:last-child { border-bottom: none; }
        .notif-item-icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            background: #fee2e2;
            color: #991b1b;
            display: flex;
            align-items: center;
            justify-content: center;
            flex-shrink: 0;
            font-size: 0.85rem;
        }
        .notif-item-title { font-weight: 700; color: var(--text); margin-bottom: 2px; }
        .notif-item-desc { color: var(--muted); font-size: 0.78rem; margin-bottom: 4px; }
        .notif-item-time { color: #94a3b8; font-size: 0.72rem; }

        /* Overview Table */
        .classes-card {
            background: var(--paper);
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
            margin-bottom: 28px;
        }
        .classes-card-header {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 12px;
        }
        .classes-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.88rem;
        }
        .classes-table th {
            background: var(--bg);
            padding: 12px 16px;
            text-align: left;
            font-weight: 700;
            font-size: 0.76rem;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            color: var(--muted);
            border-bottom: 1px solid var(--border);
        }
        .classes-table td {
            padding: 14px 16px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        .classes-table tr:hover { background: #fafaf9; }
        .std-badge {
            display: inline-block;
            font-weight: 800;
            font-size: 0.9rem;
            padding: 4px 10px;
            border-radius: 6px;
            background: #f1f5f9;
            color: #334155;
        }
        .status-pill {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 0.76rem;
            font-weight: 700;
        }
        .status-pill.pending { background: #fef3c7; color: #92400e; }
        .status-pill.promoted { background: #dcfce7; color: #166534; }
        .status-pill.terminal { background: #f3e8ff; color: #6b21a8; }

        /* Modal Overlay for Student Checklist */
        .promo-modal {
            position: fixed;
            inset: 0;
            background: rgba(42, 16, 24, 0.6);
            backdrop-filter: blur(3px);
            z-index: 1050;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            animation: fadeIn 0.18s ease;
        }
        .promo-modal-panel {
            background: var(--paper);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            width: 100%;
            max-width: 900px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            border: 1px solid var(--border);
        }
        .promo-modal-header {
            padding: 18px 24px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            background: var(--bg);
        }
        /* The form sits between the flex panel and the body/footer, so it
           must itself be a shrinking flex column — otherwise .promo-modal-body
           grows to full content height and the list cannot scroll. */
        #promoteForm {
            display: flex;
            flex-direction: column;
            flex: 1 1 auto;
            min-height: 0;
        }
        .promo-modal-body {
            padding: 20px 24px;
            overflow-y: auto;
            flex: 1 1 auto;
            min-height: 0;
            scrollbar-width: thin;
            scrollbar-color: #cbd5e1 transparent;
        }
        .promo-modal-body::-webkit-scrollbar { width: 10px; }
        .promo-modal-body::-webkit-scrollbar-track { background: transparent; }
        .promo-modal-body::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 8px;
            border: 2px solid transparent;
            background-clip: content-box;
        }
        .promo-modal-body::-webkit-scrollbar-thumb:hover { background: #94a3b8; border: 2px solid transparent; background-clip: content-box; }
        .promo-modal-footer {
            padding: 16px 24px;
            border-top: 1px solid var(--border);
            background: var(--bg);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
        }
        .checklist-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.85rem;
            margin-top: 12px;
        }
.checklist-table th {
            background: #f8fafc;
            padding: 10px 12px;
            text-align: left;
            border-bottom: 2px solid var(--border);
            font-weight: 700;
            color: var(--muted);
            position: sticky;
            top: 0;
            z-index: 1;
        }
        .checklist-table td {
            padding: 10px 12px;
            border-bottom: 1px solid var(--border);
        }
        .checklist-table tr:hover { background: #fdf2f8; }
        .exact-roll {
            font-weight: 800;
            color: #991b1b;
            font-size: 0.95rem;
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <main class="admin-main">
            <header class="admin-header">
                <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <div class="header-titles">
                    <h1 class="page-title">Promote Students & Academic Year Transition</h1>
                    <p class="page-sub">Safely advance all classes from 1st to 10th standard without losing historical records</p>
                </div>

                <div class="header-actions" style="display:flex;align-items:center;">
                    <!-- Notification Bell -->
                    <div class="notif-container" id="notifContainer">
                        <button type="button" class="notif-trigger" id="notifTrigger" title="Recent Activity Notifications" aria-label="Notifications">
                            <i class="fas fa-bell"></i>
                            <?php if ($notifData['unread'] > 0): ?>
                                <span class="notif-badge" id="notifBadge"><?= (int)$notifData['unread'] ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="notif-dropdown" id="notifDropdown">
                            <div class="notif-dropdown-header">
                                <span><i class="fas fa-bell"></i> Promotion & System Alerts</span>
                                <span style="font-size:0.75rem;color:var(--muted);"><?= count($notifData['items']) ?> events</span>
                            </div>
                            <div class="notif-dropdown-list">
                                <?php if (empty($notifData['items'])): ?>
                                    <div style="padding:24px;text-align:center;color:var(--muted);font-size:0.82rem;">
                                        <i class="fas fa-bell-slash" style="font-size:1.5rem;display:block;margin-bottom:8px;opacity:0.5;"></i>
                                        No recent promotion notifications.
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($notifData['items'] as $nt): ?>
                                        <a href="<?= !empty($nt['link']) ? sanitize($nt['link']) : '#' ?>" class="notif-item">
                                            <div class="notif-item-icon">
                                                <i class="fas <?= $nt['type']==='promotion' ? 'fa-graduation-cap' : 'fa-info-circle' ?>"></i>
                                            </div>
                                            <div style="flex:1;">
                                                <div class="notif-item-title"><?= sanitize($nt['title']) ?></div>
                                                <div class="notif-item-desc"><?= sanitize($nt['message']) ?></div>
                                                <div class="notif-item-time">
                                                    <i class="far fa-clock"></i> <?= date('M d, Y • h:i A', strtotime($nt['created_at'])) ?>
                                                </div>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <!-- User Chip -->
                    <div class="admin-user">
                        <i class="fas fa-user-circle"></i>
                        <span><?= sanitize($adminUser) ?></span>
                    </div>
                </div>
            </header>

            <div class="admin-content">

                <!-- Flash Messages -->
                <?php if ($success !== ''): ?>
                <div style="margin-bottom:20px;padding:16px 20px;border-radius:10px;background:#f0fdf4;border:1px solid #bbf7d0;color:#166534;display:flex;align-items:center;justify-content:space-between;gap:12px;">
                    <div>
                        <i class="fas fa-check-circle" style="margin-right:8px;font-size:1.1rem;"></i>
                        <strong>Success:</strong> <?= sanitize($success) ?>
                    </div>
                    <?php if ($promotedTargetClass !== ''): ?>
                    <a href="results.php?class=<?= urlencode($promotedTargetClass) ?>" class="btn btn-primary btn-sm" style="white-space:nowrap;">
                        <i class="fas fa-table"></i> Open Promoted Class in Results Matrix &rarr;
                    </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <?php if ($error !== ''): ?>
                <div style="margin-bottom:20px;padding:16px 20px;border-radius:10px;background:#fef2f2;border:1px solid #fecaca;color:#991b1b;">
                    <i class="fas fa-exclamation-circle" style="margin-right:8px;"></i>
                    <strong>Error:</strong> <?= sanitize($error) ?>
                </div>
                <?php endif; ?>

                <!-- Top Hero Banner with Year Filter -->
                <div class="promo-header-card">
                    <div class="promo-header-title">
                        <i class="fas fa-graduation-cap"></i> Student Promotion Hub — All 10 Standards
                    </div>
                    <div class="promo-header-sub">
                        Promote students from their current class to the next standard (e.g., STD 1A &rarr; STD 2A). 
                        Historical records for <strong><?= sanitize($sourceYear) ?></strong> are strictly preserved forever, and exact roll numbers are retained without modification.
                    </div>

                    <form method="GET" class="promo-year-bar">
                        <div class="promo-year-item">
                            <span>Current Academic Year:</span>
                            <input type="text" name="source_year" value="<?= sanitize($sourceYear) ?>" class="promo-year-input" style="width:110px;" required>
                        </div>
                        <div class="promo-year-arrow">
                            <i class="fas fa-arrow-right"></i>
                        </div>
                        <div class="promo-year-item">
                            <span>Target Academic Year:</span>
                            <input type="text" name="target_year" value="<?= sanitize($targetYear) ?>" class="promo-year-input" style="width:110px;" required>
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary" style="background:#fbbf24;color:#1e293b;border:none;font-weight:700;">
                            <i class="fas fa-sync-alt"></i> Update View
                        </button>
                    </form>
                </div>

                <!-- Statistics Row -->
                <div class="promo-stats-grid">
                    <div class="promo-stat-card">
                        <div class="promo-stat-icon primary"><i class="fas fa-school"></i></div>
                        <div>
                            <div class="promo-stat-val"><?= $totalClasses ?></div>
                            <div class="promo-stat-lbl">Active Cohorts (STD 1–10)</div>
                        </div>
                    </div>
                    <div class="promo-stat-card">
                        <div class="promo-stat-icon amber"><i class="fas fa-users"></i></div>
                        <div>
                            <div class="promo-stat-val"><?= number_format($totalEligibleStudents) ?></div>
                            <div class="promo-stat-lbl">Students in <?= sanitize($sourceYear) ?></div>
                        </div>
                    </div>
                    <div class="promo-stat-card">
                        <div class="promo-stat-icon success"><i class="fas fa-user-check"></i></div>
                        <div>
                            <div class="promo-stat-val"><?= $promotedClassesCount ?> / <?= $totalClasses ?></div>
                            <div class="promo-stat-lbl">Classes Promoted to <?= sanitize($targetYear) ?></div>
                        </div>
                    </div>
                    <div class="promo-stat-card">
                        <div class="promo-stat-icon success"><i class="fas fa-clipboard-check"></i></div>
                        <div>
                            <div class="promo-stat-val"><?= number_format($totalPromotedStudents) ?></div>
                            <div class="promo-stat-lbl">Students in <?= sanitize($targetYear) ?></div>
                        </div>
                    </div>
                </div>

                <!-- Classes Overview Table -->
                <div class="classes-card">
                    <div class="classes-card-header">
                        <div>
                            <h2 style="font-size:1.1rem;font-weight:700;margin:0 0 4px 0;"><i class="fas fa-list-ol"></i> Class-by-Class Promotion Board</h2>
                            <p style="font-size:0.8rem;color:var(--muted);margin:0;">Select any class below to inspect the student checklist and confirm promotion into <?= sanitize($targetYear) ?>.</p>
                        </div>
                    </div>

                    <div style="overflow-x:auto;">
                        <table class="classes-table">
                            <thead>
                                <tr>
                                    <th>Standard</th>
                                    <th>Division</th>
                                    <th>Current Students</th>
                                    <th>Semester</th>
                                    <th>Target Promotion</th>
                                    <th>Status in <?= sanitize($targetYear) ?></th>
                                    <th style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($classesOverview)): ?>
                                <tr>
                                    <td colspan="7" style="text-align:center;padding:40px;color:var(--muted);">
                                        No classes found for academic year <?= sanitize($sourceYear) ?>.
                                    </td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($classesOverview as $co): ?>
                                <tr>
                                    <td>
                                        <span class="std-badge">STD <?= sanitize($co['source_std']) ?></span>
                                    </td>
                                    <td>
                                        <strong style="font-size:1rem;color:var(--accent);">Div <?= sanitize($co['source_div']) ?></strong>
                                    </td>
                                    <td>
                                        <strong><?= $co['student_count'] ?></strong> students
                                    </td>
                                    <td>
                                        <span style="font-size:0.8rem;color:var(--muted);"><?= sanitize($co['source_sem']) ?></span>
                                    </td>
                                    <td>
                                        <?php if ($co['is_terminal']): ?>
                                            <span style="color:#6b21a8;font-weight:700;"><i class="fas fa-award"></i> Graduated Alumni (Class 10)</span>
                                        <?php else: ?>
                                            <strong style="color:#0f766e;"><i class="fas fa-arrow-right"></i> STD <?= sanitize($co['target_std']) ?> <?= sanitize($co['target_div']) ?></strong> (<?= sanitize($targetYear) ?>)
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if ($co['is_terminal']): ?>
                                            <span class="status-pill terminal"><i class="fas fa-user-graduate"></i> Secondary Completed</span>
                                        <?php elseif ($co['is_promoted']): ?>
                                            <span class="status-pill promoted"><i class="fas fa-check-circle"></i> Promoted (<?= $co['promoted_count'] ?> students)</span>
                                        <?php else: ?>
                                            <span class="status-pill pending"><i class="fas fa-clock"></i> Not Promoted Yet</span>
                                        <?php endif; ?>
                                    </td>
                                    <td style="text-align:right;">
                                        <?php if ($co['is_terminal']): ?>
                                            <span style="font-size:0.8rem;color:var(--muted);font-style:italic;">Archived permanently</span>
                                        <?php else: ?>
                                            <?php if ($co['is_promoted']): ?>
                                                <a href="results.php?class=<?= urlencode("{$targetYear}|{$co['target_std']}|{$co['target_div']}|{$co['target_sem']}") ?>" class="btn btn-sm btn-secondary" style="margin-right:6px;" title="View marksheet in results">
                                                    <i class="fas fa-table"></i> Results
                                                </a>
                                                <form method="POST" style="display:inline-block;" data-confirm="Are you sure you want to revert promotion for STD <?= sanitize($co['target_std']) ?> <?= sanitize($co['target_div']) ?> in <?= sanitize($targetYear) ?>?" data-confirm-title="Revert Promotion Cohort" data-confirm-btn="Revert Promotion">
                                                    <?= csrfField() ?>
                                                    <input type="hidden" name="action" value="rollback_promotion">
                                                    <input type="hidden" name="target_year" value="<?= sanitize($targetYear) ?>">
                                                    <input type="hidden" name="target_std" value="<?= sanitize($co['target_std']) ?>">
                                                    <input type="hidden" name="target_div" value="<?= sanitize($co['target_div']) ?>">
                                                    <input type="hidden" name="target_sem" value="<?= sanitize($co['target_sem']) ?>">
                                                    <button type="submit" class="btn btn-sm" style="color:#dc2626;background:#fef2f2;border:1px solid #fecaca;" title="Delete target cohort">
                                                        <i class="fas fa-undo"></i> Revert
                                                    </button>
                                                </form>
                                            <?php else: ?>
                                                <a href="promote_students.php?source_year=<?= urlencode($sourceYear) ?>&target_year=<?= urlencode($targetYear) ?>&class=<?= urlencode("{$co['source_std']}|{$co['source_div']}|{$co['source_sem']}") ?>" class="btn btn-sm btn-primary">
                                                    <i class="fas fa-user-check"></i> Review & Promote &rarr;
                                                </a>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>

            </div>
        </main>
    </div>

    <!-- Interactive Student Checklist Modal -->
    <?php if ($activeClassMeta && !empty($checklistStudents)): ?>
    <div class="promo-modal" id="checklistModal">
        <div class="promo-modal-panel">
            <div class="promo-modal-header">
                <div>
                    <h3 style="margin:0;font-size:1.15rem;font-weight:700;">
                        <i class="fas fa-user-graduate" style="color:var(--accent);"></i>
                        Promote STD <?= sanitize($activeClassMeta['std']) ?> <?= sanitize($activeClassMeta['div']) ?> &rarr; STD <?= sanitize($activeClassMeta['target_std']) ?> <?= sanitize($activeClassMeta['target_div']) ?>
                    </h3>
                    <p style="margin:4px 0 0 0;font-size:0.8rem;color:var(--muted);">
                        Source: <strong><?= sanitize($sourceYear) ?></strong> &bull; Target: <strong><?= sanitize($targetYear) ?></strong> &bull; Exact roll numbers are preserved
                    </p>
                </div>
                <a href="promote_students.php?source_year=<?= urlencode($sourceYear) ?>&target_year=<?= urlencode($targetYear) ?>" class="btn btn-sm btn-secondary" aria-label="Close modal">
                    <i class="fas fa-times"></i>
                </a>
            </div>

            <form method="POST" id="promoteForm">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="promote_class">
                <input type="hidden" name="source_year" value="<?= sanitize($sourceYear) ?>">
                <input type="hidden" name="source_std" value="<?= sanitize($activeClassMeta['std']) ?>">
                <input type="hidden" name="source_div" value="<?= sanitize($activeClassMeta['div']) ?>">
                <input type="hidden" name="source_sem" value="<?= sanitize($activeClassMeta['sem']) ?>">
                <input type="hidden" name="target_year" value="<?= sanitize($targetYear) ?>">
                <input type="hidden" name="target_std" value="<?= sanitize($activeClassMeta['target_std']) ?>">
                <input type="hidden" name="target_div" value="<?= sanitize($activeClassMeta['target_div']) ?>">
                <input type="hidden" name="target_sem" value="<?= sanitize($activeClassMeta['target_sem']) ?>">

                <div class="promo-modal-body">
                    <!-- Controls and Target Subjects Info -->
                    <div style="background:#f8fafc;padding:12px 16px;border-radius:8px;border:1px solid var(--border);margin-bottom:16px;">
                        <div style="font-size:0.82rem;font-weight:700;color:var(--text);margin-bottom:6px;">
                            <i class="fas fa-book-open"></i> Target Class Subjects for STD <?= sanitize($activeClassMeta['target_std']) ?>:
                        </div>
                        <div style="display:flex;flex-wrap:wrap;gap:6px;">
                            <?php foreach ($targetSubjectsPreview as $tsp): ?>
                                <span style="background:#fff;border:1px solid var(--border);padding:3px 8px;border-radius:4px;font-size:0.75rem;font-weight:600;color:#334155;">
                                    <?= sanitize($tsp['subject_name']) ?> (FA <?= $tsp['fa_max'] ?>, SA <?= $tsp['sa_max'] ?>)
                                </span>
                            <?php endforeach; ?>
                        </div>
                    </div>

                    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:10px;">
                        <div>
                            <span style="font-size:0.85rem;font-weight:700;" id="selectionCount">
                                <span id="checkedCount"><?= count($checklistStudents) ?></span> of <?= count($checklistStudents) ?> Students Selected
                            </span>
                        </div>
                        <div style="display:flex;gap:8px;">
                            <button type="button" class="btn btn-sm btn-secondary" id="btnSelectAll" style="font-size:0.75rem;">Select All</button>
                            <button type="button" class="btn btn-sm btn-secondary" id="btnDeselectAll" style="font-size:0.75rem;">Deselect All</button>
                        </div>
                    </div>

                    <!-- Checklist Table -->
                    <table class="checklist-table">
                        <thead>
                            <tr>
                                <th style="width:40px;text-align:center;">
                                    <input type="checkbox" id="masterCheckbox" checked>
                                </th>
                                <th style="width:100px;">Roll No (Exact)</th>
                                <th>Student Name</th>
                                <th>Action in <?= sanitize($targetYear) ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($checklistStudents as $cs): ?>
                            <tr>
                                <td style="text-align:center;">
                                    <input type="checkbox" name="selected_rolls[]" value="<?= sanitize($cs['roll_no']) ?>" class="stu-checkbox" checked>
                                </td>
                                <td>
                                    <span class="exact-roll"><?= sanitize($cs['roll_no']) ?></span>
                                </td>
                                <td>
                                    <strong><?= sanitize($cs['student_name']) ?></strong>
                                    <?php if (!empty($cs['date_of_birth'])): ?>
                                        <div style="font-size:0.72rem;color:var(--muted);"><?= sanitize($cs['date_of_birth']) ?></div>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span style="font-size:0.8rem;color:#166534;font-weight:600;">
                                        <i class="fas fa-check"></i> Advance to STD <?= sanitize($activeClassMeta['target_std']) ?> <?= sanitize($activeClassMeta['target_div']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <div class="promo-modal-footer">
                    <a href="promote_students.php?source_year=<?= urlencode($sourceYear) ?>&target_year=<?= urlencode($targetYear) ?>" class="btn btn-secondary">
                        Cancel
                    </a>
                    <button type="submit" class="btn btn-primary" id="btnConfirmPromote">
                        <i class="fas fa-graduation-cap"></i> Confirm & Promote <span id="submitCount"><?= count($checklistStudents) ?></span> Students to STD <?= sanitize($activeClassMeta['target_std']) ?> <?= sanitize($activeClassMeta['target_div']) ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <script>
        // Notification Dropdown Toggle & Mark as Read
        // The bell toggle is owned by admin.js (loaded on this page); no inline duplicate here,
        // otherwise the two handlers fight and the dropdown never opens.

        // Student Checklist Selection Manager
        const masterCheckbox = document.getElementById('masterCheckbox');
        const stuCheckboxes = document.querySelectorAll('.stu-checkbox');
        const checkedCountSpan = document.getElementById('checkedCount');
        const submitCountSpan = document.getElementById('submitCount');
        const btnSelectAll = document.getElementById('btnSelectAll');
        const btnDeselectAll = document.getElementById('btnDeselectAll');
        const btnConfirm = document.getElementById('btnConfirmPromote');

        function updateSelectionCount() {
            let count = 0;
            stuCheckboxes.forEach(cb => { if (cb.checked) count++; });
            if (checkedCountSpan) checkedCountSpan.textContent = count;
            if (submitCountSpan) submitCountSpan.textContent = count;
            if (btnConfirm) btnConfirm.disabled = (count === 0);
            if (masterCheckbox) {
                masterCheckbox.checked = (count === stuCheckboxes.length);
                masterCheckbox.indeterminate = (count > 0 && count < stuCheckboxes.length);
            }
        }

        if (masterCheckbox) {
            masterCheckbox.addEventListener('change', () => {
                stuCheckboxes.forEach(cb => { cb.checked = masterCheckbox.checked; });
                updateSelectionCount();
            });
        }

        if (btnSelectAll) {
            btnSelectAll.addEventListener('click', () => {
                stuCheckboxes.forEach(cb => { cb.checked = true; });
                updateSelectionCount();
            });
        }

        if (btnDeselectAll) {
            btnDeselectAll.addEventListener('click', () => {
                stuCheckboxes.forEach(cb => { cb.checked = false; });
                updateSelectionCount();
            });
        }

        stuCheckboxes.forEach(cb => {
            cb.addEventListener('change', updateSelectionCount);
        });

        // Sidebar toggle
        const toggle = document.getElementById('sidebarToggle');
        const sidebar = document.getElementById('sidebar');
        if (toggle && sidebar) {
            toggle.addEventListener('click', () => {
                sidebar.classList.toggle('open');
            });
        }
    </script>
    <script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= (is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 0) ?>"></script>
</body>
</html>
