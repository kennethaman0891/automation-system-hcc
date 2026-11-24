<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

$adminPage = 'dashboard';
$settings = getAllSettings();
$stats = rcDashboardStats();
$classes = rcClassOptions();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church High School';
$topPerformer = rcTopPerformer();
$lowestPerformer = rcLowestPerformer();
$schoolTagline = $settings['school_tagline'] ?? '';
$schoolLogoRel = trim((string)($settings['logo'] ?? ''));
if ($schoolLogoRel === '' || !is_file(__DIR__ . '/../' . ltrim($schoolLogoRel, '/'))) {
    $schoolLogoRel = 'assets/images/hcclogo.jpg';
}
$schoolLogoUrl = BASE_URL . ltrim($schoolLogoRel, '/');
$notifData = rcGetRecentNotifications(8);

/* ── WhatsApp hub quick stats (fail-soft: WhatsApp tables may not exist yet) ── */
$waContacts = 0;
$waSent = 0;
$waPending = 0;
$waEnquiries = 0;
try {
    $waDb = getDB();
    $waContacts = (int)$waDb->query("SELECT COUNT(DISTINCT linked_student_roll) FROM whatsapp_contacts WHERE linked_student_roll IS NOT NULL AND linked_student_roll <> ''")->fetchColumn();
    if ($waContacts === 0) {
        $waContacts = (int)$waDb->query("SELECT COUNT(DISTINCT roll_no) FROM report_card WHERE parent_phone IS NOT NULL AND parent_phone <> ''")->fetchColumn();
    }
} catch (Throwable $e) {}
try {
    $waDb = $waDb ?? getDB();
    $waSent = (int)$waDb->query("SELECT COUNT(*) FROM whatsapp_dispatch_log WHERE status = 'sent'")->fetchColumn();
} catch (Throwable $e) {}
try {
    $waDb = $waDb ?? getDB();
    $waPending = (int)$waDb->query("SELECT COUNT(*) FROM whatsapp_dispatch_log WHERE status IN ('pending','ready')")->fetchColumn();
} catch (Throwable $e) {}
try {
    $waDb = $waDb ?? getDB();
    $waEnquiries = (int)$waDb->query("SELECT COUNT(*) FROM admission_enquiries WHERE status NOT IN ('CLOSED', 'ENROLLED')")->fetchColumn();
} catch (Throwable $e) {}

/* ── Daily Operations Hub Live Badge Counts (all fail-soft) ── */
$activeAcYear = rcGetActiveAcademicYear();
$atRiskCount = 0;
$unsignedParentCount = 0;
$inboundAiCount = 0;
$subsTodayCount = 0;
$seatingPlansCount = 0;
try {
    $opsDb = $waDb ?? getDB();
    $rcUnsigned = (int)$opsDb->query("SELECT COUNT(*) FROM (SELECT 1 FROM report_card WHERE parents_sign = 0 GROUP BY academic_year, std, division, semester, roll_no) t")->fetchColumn();
    $utUnsigned = (int)$opsDb->query("SELECT COUNT(*) FROM (SELECT 1 FROM unit_test_results WHERE parents_sign = 0 GROUP BY academic_year, std, division, test_type, roll_no) u")->fetchColumn();
    $unsignedParentCount = $rcUnsigned + $utUnsigned;
} catch (Throwable $e) {}
try {
    $opsDb = $opsDb ?? getDB();
    $diagStmt = $opsDb->prepare("
        SELECT COUNT(DISTINCT CONCAT(std,'|',division,'|',roll_no))
        FROM report_card
        WHERE academic_year = ?
          AND fa_max > 0 AND sa_max > 0
          AND (
              ((fa_obtained / fa_max) >= 0.65 AND (sa_obtained / sa_max) < 0.35)
              OR (((fa_obtained + sa_obtained) / (fa_max + sa_max) * 100) < (CASE WHEN CAST(std AS UNSIGNED) >= 9 THEN 35 ELSE 33 END))
          )
    ");
    $diagStmt->execute([$activeAcYear]);
    $atRiskCount = (int)$diagStmt->fetchColumn();
} catch (Throwable $e) {}
try {
    $opsDb = $opsDb ?? getDB();
    $hasInboundTbl = $opsDb->query("SHOW TABLES LIKE 'inbound_signal_cards'")->fetchColumn();
    if ($hasInboundTbl) {
        try {
            $inboundAiCount = (int)$opsDb->query("SELECT COUNT(*) FROM inbound_signal_cards WHERE status IN ('pending','open','new')")->fetchColumn();
        } catch (Throwable $inner) {
            $inboundAiCount = (int)$opsDb->query("SELECT COUNT(*) FROM inbound_signal_cards")->fetchColumn();
        }
    }
} catch (Throwable $e) {}
try {
    $opsDb = $opsDb ?? getDB();
    $subsTodayCount = (int)$opsDb->query("SELECT COUNT(*) FROM daily_substitutions WHERE sub_date = CURDATE()")->fetchColumn();
} catch (Throwable $e) {}
try {
    $opsDb = $opsDb ?? getDB();
    $seatingPlansCount = (int)$opsDb->query("SELECT COUNT(*) FROM exam_seating_plans WHERE exam_date >= CURDATE()")->fetchColumn();
} catch (Throwable $e) {}

/* Mark-as-read endpoint for the dashboard feed (same contract as results.php). */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mark_notifs_read') {
    if (!verifyCSRFToken()) {
        header('Content-Type: application/json');
        echo json_encode(['ok' => false]);
        exit;
    }
    rcMarkNotificationsRead();
    header('Content-Type: application/json');
    echo json_encode(['ok' => true]);
    exit;
}

/* ── View state ── */
$adminName = sanitize($_SESSION['admin_name'] ?? 'Admin');
$adminRole = sanitize(ucfirst(trim((string)($_SESSION['admin_role'] ?? 'Admin')) ?: 'Admin'));
$adminInitials = strtoupper(implode('', array_map(
    function ($part) { return mb_substr($part, 0, 1); },
    array_slice(array_filter(preg_split('/\s+/', trim((string)($_SESSION['admin_name'] ?? 'A')))), 0, 2)
)));
if ($adminInitials === '') {
    $adminInitials = 'A';
}

// Greeting uses Indian Standard Time (Asia/Kolkata) so it matches the school's local time.
$istNow  = new DateTime('now', new DateTimeZone('Asia/Kolkata'));
$hourNow = (int)$istNow->format('G');
$greeting = $hourNow < 12 ? 'Good morning' : ($hourNow < 17 ? 'Good afternoon' : 'Good evening');
$hasData = ((int)($stats['students'] ?? 0)) > 0;
$hasPerf = !empty($topPerformer) || !empty($lowestPerformer);

$heroVideoFile = __DIR__ . '/../assets/videos/hcc-project-promo.mp4';
$heroFallbackVideoFile = __DIR__ . '/../assets/videos/whatsapp-hub-promo.mp4';
if (is_file($heroVideoFile)) {
    $heroVideoSrc = BASE_URL . 'assets/videos/hcc-project-promo.mp4?v=' . filemtime($heroVideoFile);
} else {
    $heroVideoSrc = BASE_URL . 'assets/videos/whatsapp-hub-promo.mp4' . (is_file($heroFallbackVideoFile) ? '?v=' . filemtime($heroFallbackVideoFile) : '');
}

$heroPosterFile = __DIR__ . '/../assets/images/hcc-project-promo-poster.jpg';
$heroFallbackPosterFile = __DIR__ . '/../assets/images/wa-promo-poster.jpg';
if (is_file($heroPosterFile)) {
    $heroPosterSrc = BASE_URL . 'assets/images/hcc-project-promo-poster.jpg?v=' . filemtime($heroPosterFile);
} else {
    $heroPosterSrc = BASE_URL . 'assets/images/wa-promo-poster.jpg' . (is_file($heroFallbackPosterFile) ? '?v=' . filemtime($heroFallbackPosterFile) : '');
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php require __DIR__ . '/includes/account_head.php'; ?>
    <title>Dashboard | <?= sanitize($schoolName) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <meta name="csrf-token" content="<?= htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin.css?v=<?= (is_file(__DIR__ . '/../assets/css/admin.css') ? filemtime(__DIR__ . '/../assets/css/admin.css') : 0) ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin-dash.css?v=<?= (is_file(__DIR__ . '/../assets/css/admin-dash.css') ? filemtime(__DIR__ . '/../assets/css/admin-dash.css') : 0) ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/hcc-assistant.css?v=<?= (is_file(__DIR__ . '/../assets/css/hcc-assistant.css') ? filemtime(__DIR__ . '/../assets/css/hcc-assistant.css') : 0) ?>">
</head>
<body>
    <div class="admin-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
        <main class="admin-main">
            <header class="admin-header">
                <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle navigation" aria-controls="sidebar" aria-expanded="false">
                    <i class="fas fa-bars" aria-hidden="true"></i>
                </button>
                <div class="header-titles">
                    <h1 class="page-title">Dashboard</h1>
                    <p class="page-sub">At-a-glance results, classes and performance for <?= sanitize($schoolName) ?>.</p>
                </div>
                <div class="header-actions">
                    <a href="whatsapp_share.php" class="wa-quick-btn" id="waQuickBtn" title="Open WhatsApp Share Hub" aria-label="Open WhatsApp Share Hub">
                        <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
                    </a>
                    <?php require __DIR__ . '/includes/notif_bell.php'; ?>
                    <div class="admin-user">
                        <span class="user-avatar"><?= sanitize($adminInitials) ?></span>
                        <span class="user-meta">
                            <strong><?= $adminName ?></strong>
                            <small><?= $adminRole ?></small>
                        </span>
                    </div>
                </div>
            </header>
            <div class="admin-content">
                <div class="dash-wrap">
                <?= csrfField() ?>

                <?php if (($_GET['err'] ?? '') === 'admin_only'): ?>
                <div class="alert alert-warning" role="alert" style="background:#fffbeb; color:#92400e; border:1px solid #fde68a; padding:12px 16px; border-radius:8px; margin-bottom:16px; display:flex; align-items:center; gap:10px; font-weight:600; font-size:0.88rem;">
                    <i class="fa-solid fa-lock" aria-hidden="true"></i>
                    <span>Access Restricted: That feature requires Administrator privileges.</span>
                </div>
                <?php endif; ?>

                <!-- ═══ Executive Ambient Background-Video Hero Section ═══ -->
                <section class="dash-hero" id="dashHero" aria-labelledby="dashHeroHeading">
                    <!-- Layer 0 & 1: Running Background Video + Masked Frost + Maroon-Slate Scrim -->
                    <div class="dash-hero-media">
                        <video class="dash-hero-bg-video" id="dashHeroVideo"
                               autoplay muted loop playsinline preload="metadata"
                               aria-hidden="true" tabindex="-1"
                               disablepictureinpicture disableremoteplayback
                               poster="<?= htmlspecialchars($heroPosterSrc, ENT_QUOTES, 'UTF-8') ?>">
                            <source src="<?= htmlspecialchars($heroVideoSrc, ENT_QUOTES, 'UTF-8') ?>" type="video/mp4">
                        </video>
                        <div class="dash-hero-frost"></div>
                        <div class="dash-hero-scrim"></div>
                    </div>

                    <!-- Layer 2: Executive Foreground -->
                    <div class="dash-hero-inner">
                        <!-- 1. Top Ambient Bar -->
                        <div class="dash-hero-topbar">
                            <div class="dash-hero-pill">
                                <i class="fa-solid fa-graduation-cap" aria-hidden="true"></i>
                                <span class="dash-hero-pill-text"><?= sanitize($schoolName) ?> &bull; AY <?= sanitize($activeAcYear) ?></span>
                            </div>

                            <div class="dash-hero-media-ctrls" role="group" aria-label="Showcase video controls">
                                <span class="dash-hero-video-tag" id="heroVideoStatusBadge">
                                    <span class="live-dot" aria-hidden="true"></span>
                                    <span id="heroShowcaseStatus">HCC Campus Showcase</span>
                                </span>
                                <button type="button" class="dash-hero-ctrl-btn" id="heroPlayToggle"
                                        aria-label="Pause background video" title="Pause background video">
                                    <i class="fa-solid fa-pause" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="dash-hero-ctrl-btn" id="heroMuteToggle"
                                        aria-label="Unmute background video" title="Unmute background video">
                                    <i class="fa-solid fa-volume-xmark" aria-hidden="true"></i>
                                </button>
                                <button type="button" class="dash-hero-ctrl-btn" id="heroExpandBtn"
                                        aria-label="Watch full showcase video in fullscreen" title="Watch full showcase video">
                                    <i class="fa-solid fa-expand" aria-hidden="true"></i>
                                </button>
                            </div>
                        </div>

                        <!-- 2. Asymmetric Main Stage (Left Executive Copy + Right Open Video Viewport) -->
                        <div class="dash-hero-stage">
                            <div class="dash-hero-copy">
                                <div class="dash-hero-brand">
                                    <img class="dash-hero-logo" src="<?= htmlspecialchars($schoolLogoUrl, ENT_QUOTES, 'UTF-8') ?>" width="56" height="56" decoding="async" alt="<?= sanitize($schoolName) ?> crest">
                                    <div class="dash-hero-eyebrow">
                                        <p id="dashGreetingLine">
                                            <span id="dashGreetingText"><?= sanitize($greeting) ?></span>, <strong><?= $adminName ?></strong>
                                        </p>
                                        <span class="dash-hero-motto"><?= sanitize($schoolTagline) !== '' ? sanitize($schoolTagline) : 'Learn &middot; Lead &middot; Serve' ?></span>
                                    </div>
                                </div>

                                <h2 class="dash-hero-title" id="dashHeroHeading">Welcome to <span class="dash-hero-accent">Automation System</span></h2>
                                <p class="dash-hero-lead">Unified exam evaluation, instant WhatsApp parent dispatch, and daily campus operations in one command center.</p>

                                <div class="dash-hero-actions">
                                    <a href="results.php" class="dash-hero-btn dash-hero-btn--primary">
                                        <i class="fa-solid fa-chart-line" aria-hidden="true"></i>
                                        <span>Academic Results</span>
                                    </a>
                                    <a href="whatsapp_share.php" class="dash-hero-btn dash-hero-btn--wa">
                                        <i class="fa-brands fa-whatsapp" aria-hidden="true"></i>
                                        <span>WhatsApp Hub</span>
                                    </a>
                                    <a href="unit_test_entry.php" class="dash-hero-btn dash-hero-btn--ghost">
                                        <i class="fa-solid fa-pen-nib" aria-hidden="true"></i>
                                        <span>Unit Test Entry</span>
                                    </a>
                                    <a href="timetable.php" class="dash-hero-btn dash-hero-btn--ghost">
                                        <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
                                        <span>Time-Table</span>
                                    </a>
                                </div>
                            </div>

                            <!-- Open right-hand stage lets ambient motion graphics run visibly in background -->
                            <div class="dash-hero-showcase-space" aria-hidden="true"></div>
                        </div>

                        <!-- 3. Bottom Docked Glass KPI Ribbon -->
                        <div class="dash-hero-metrics" role="group" aria-label="Key campus performance metrics">
                            <div class="dash-hero-metric-item">
                                <span class="metric-val"><?= number_format((int)($stats['students'] ?? 0)) ?></span>
                                <span class="metric-lbl">Students &bull; AY <?= sanitize($activeAcYear) ?></span>
                            </div>
                            <div class="dash-hero-metric-item">
                                <span class="metric-val"><?= number_format((float)($stats['pass_rate'] ?? 0), 1) ?>%</span>
                                <span class="metric-lbl">Overall Pass Rate</span>
                            </div>
                            <div class="dash-hero-metric-item">
                                <span class="metric-val"><?= number_format((int)$waSent) ?></span>
                                <span class="metric-lbl">WhatsApp Sent<?= $waPending > 0 ? ' (' . (int)$waPending . ' queued)' : ($waContacts > 0 ? ' • ' . (int)$waContacts . ' parents' : '') ?></span>
                            </div>
                            <div class="dash-hero-metric-item is-live">
                                <span class="metric-val">
                                    <span class="dash-hero-live-dot" aria-hidden="true"></span>
                                    <span><?= $waEnquiries > 0 ? (int)$waEnquiries . ' Active' : 'Live' ?></span>
                                </span>
                                <span class="metric-lbl"><?= $waEnquiries > 0 ? 'Admission Enquiries' : 'Portal Operational' ?></span>
                            </div>
                        </div>
                    </div>
                </section>

                <!-- ═══ Daily Office & Academic Operations Hub ═══ -->
                <section class="ops-hub" aria-labelledby="opsHubHeading">
                    <div class="ops-hub-head">
                        <div class="ops-hub-heading">
                            <span class="ops-hub-icon" aria-hidden="true"><i class="fa-solid fa-bolt"></i></span>
                            <div>
                                <h2 id="opsHubHeading">Daily Office &amp; Academic Operations</h2>
                                <p>Real-time intervention queues, parent signature tracking, and exam logistics.</p>
                            </div>
                        </div>
                        <div class="ops-hub-head-actions">
                            <span class="ops-hub-year"><i class="fa-solid fa-calendar-check" aria-hidden="true"></i> AY <?= sanitize($activeAcYear) ?></span>
                            <a href="admission_enquiries.php" class="ops-hub-ai-chip">
                                <i class="fa-solid fa-envelope-open-text" aria-hidden="true"></i>
                                <span>Admissions</span>
                                <strong><?= (int)$waEnquiries ?></strong>
                            </a>
                            <?php if ($inboundAiCount > 0): ?>
                            <a href="whatsapp_share.php" class="ops-hub-ai-chip">
                                <i class="fa-solid fa-robot" aria-hidden="true"></i>
                                <span>AI Signals</span>
                                <strong><?= (int)$inboundAiCount ?></strong>
                            </a>
                            <?php endif; ?>
                        </div>
                    </div>

                    <div class="ops-hub-grid">
                        <a href="at_risk_radar.php" class="ops-card ops-card--danger">
                            <div class="ops-card-top">
                                <span class="ops-card-icon" aria-hidden="true"><i class="fa-solid fa-triangle-exclamation"></i></span>
                                <span class="ops-card-count"><?= (int)$atRiskCount ?> Alerts</span>
                            </div>
                            <div class="ops-card-body">
                                <h3>At-Risk Student Radar</h3>
                                <p>Flags High FA / Low SA exam drops and failing trends for 1-click PTM outreach.</p>
                            </div>
                            <div class="ops-card-foot">
                                <span>Open Radar</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </div>
                        </a>

                        <a href="parent_sign_chase.php" class="ops-card ops-card--warning">
                            <div class="ops-card-top">
                                <span class="ops-card-icon" aria-hidden="true"><i class="fa-solid fa-file-signature"></i></span>
                                <span class="ops-card-count"><?= (int)$unsignedParentCount ?> Unsigned</span>
                            </div>
                            <div class="ops-card-body">
                                <h3>Parent-Sign Morning Queue</h3>
                                <p>Track unsigned report cards and unit test slips with class-wise follow-up lists.</p>
                            </div>
                            <div class="ops-card-foot">
                                <span>Open Queue</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </div>
                        </a>

                        <a href="substitute_allocator.php" class="ops-card ops-card--info">
                            <div class="ops-card-top">
                                <span class="ops-card-icon" aria-hidden="true"><i class="fa-solid fa-people-arrows"></i></span>
                                <span class="ops-card-count"><?= (int)$subsTodayCount ?> Today</span>
                            </div>
                            <div class="ops-card-body">
                                <h3>Substitute Teacher Desk</h3>
                                <p>Resolve period collisions, assign free faculty, and dispatch duty slips.</p>
                            </div>
                            <div class="ops-card-foot">
                                <span>Manage Substitutions</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </div>
                        </a>

                        <a href="exam_seating.php" class="ops-card ops-card--success">
                            <div class="ops-card-top">
                                <span class="ops-card-icon" aria-hidden="true"><i class="fa-solid fa-chair"></i></span>
                                <span class="ops-card-count"><?= (int)$seatingPlansCount ?> Upcoming</span>
                            </div>
                            <div class="ops-card-body">
                                <h3>Exam Hall Seating Planner</h3>
                                <p>Alternate-bench class mixing, room matrix charts, and printable desk slips.</p>
                            </div>
                            <div class="ops-card-foot">
                                <span>Open Seating Planner</span>
                                <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>
                            </div>
                        </a>
                    </div>
                </section>

                <?php if (!$hasData): ?>
                <div class="dash-onboard">
                    <div class="dash-onboard-icon" aria-hidden="true"><i class="fas fa-rocket"></i></div>
                    <div class="dash-onboard-body">
                        <h2>Welcome to HCC Admin</h2>
                        <p>No results yet. Import your first Excel sheet to start tracking marks, grades, pass rate and class averages.</p>
                    </div>
                    <a href="import_excel.php" class="btn btn-primary"><i class="fas fa-plus" aria-hidden="true"></i> Import Excel</a>
                </div>
                <?php endif; ?>

                <!-- Promotion & System Alerts -->
                <section class="dash-section" aria-labelledby="alertsSectionHeading">
                    <div class="dash-section-head">
                        <div class="dash-section-head-title">
                            <h2 id="alertsSectionHeading"><i class="fas fa-bell" aria-hidden="true"></i> Promotion &amp; System Alerts</h2>
                            <?php if (!empty($notifData['unread'])): ?>
                                <span class="dash-count dash-count--unread" id="notifUnreadCountBadge"><span class="unread-pulse" aria-hidden="true"></span> <?= (int)$notifData['unread'] ?> Unread</span>
                            <?php endif; ?>
                            <?php if (!empty($notifData['items'])): ?>
                                <span class="dash-count"><?= count($notifData['items']) ?> Total</span>
                            <?php endif; ?>
                        </div>

                        <!-- Quick Filter Tabs -->
                        <?php if (!empty($notifData['items'])): ?>
                        <div class="dash-section-head-filters" id="notifFilterBar" role="group" aria-label="Filter notifications">
                            <button type="button" class="notif-filter-btn active" data-filter="all" aria-pressed="true">All (<?= count($notifData['items']) ?>)</button>
                            <?php if (!empty($notifData['unread'])): ?>
                            <button type="button" class="notif-filter-btn" data-filter="unread" id="notifFilterUnreadBtn" aria-pressed="false">Unread (<?= (int)$notifData['unread'] ?>)</button>
                            <?php endif; ?>
                            <button type="button" class="notif-filter-btn" data-filter="unit_test" aria-pressed="false">Unit Tests</button>
                            <button type="button" class="notif-filter-btn" data-filter="promotion" aria-pressed="false">Promotions</button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="panel">
                        <?php if (empty($notifData['items'])): ?>
                            <div class="panel-body">
                                <div class="notif-empty">
                                    <i class="fas fa-bell-slash" aria-hidden="true"></i>
                                    No notifications yet. Promotion and system alerts will appear here.
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="dash-notif-list" id="dashNotifList">
                                <?php foreach ($notifData['items'] as $nt):
                                    $rawType = strtolower($nt['type'] ?? 'system');
                                    $titleLower = strtolower($nt['title'] ?? '');

                                    if (strpos($rawType, 'unit_test') !== false || strpos($titleLower, 'unit test') !== false) {
                                        $typeKey = 'unit_test';
                                        $typeLabel = 'Unit Test';
                                        $typeIcon = 'fa-solid fa-pen-to-square';
                                    } elseif (strpos($rawType, 'promotion') !== false || strpos($titleLower, 'promot') !== false) {
                                        $typeKey = 'promotion';
                                        $typeLabel = 'Promotion';
                                        $typeIcon = 'fa-solid fa-graduation-cap';
                                    } elseif (strpos($rawType, 'results') !== false || strpos($titleLower, 'report card') !== false || strpos($titleLower, 'marks') !== false) {
                                        $typeKey = 'results';
                                        $typeLabel = 'Results';
                                        $typeIcon = 'fa-solid fa-table-list';
                                    } elseif (strpos($rawType, 'excel') !== false || strpos($titleLower, 'excel') !== false || strpos($titleLower, 'import') !== false) {
                                        $typeKey = 'excel';
                                        $typeLabel = 'Excel Import';
                                        $typeIcon = 'fa-solid fa-file-excel';
                                    } elseif (strpos($rawType, 'whatsapp') !== false || strpos($titleLower, 'whatsapp') !== false) {
                                        $typeKey = 'whatsapp';
                                        $typeLabel = 'WhatsApp';
                                        $typeIcon = 'fa-brands fa-whatsapp';
                                    } else {
                                        $typeKey = 'system';
                                        $typeLabel = 'System Alert';
                                        $typeIcon = 'fa-solid fa-bell';
                                    }

                                    $ntCreatedAt = (int) strtotime($nt['created_at'] ?? 'now');
                                    $ntDiff = time() - $ntCreatedAt;
                                    if ($ntDiff < 60) {
                                        $notifTimeAgo = 'just now';
                                    } elseif ($ntDiff < 3600) {
                                        $notifTimeAgo = (int) floor($ntDiff / 60) . 'm ago';
                                    } elseif ($ntDiff < 86400) {
                                        $notifTimeAgo = (int) floor($ntDiff / 3600) . 'h ago';
                                    } elseif ($ntDiff < 172800) {
                                        $notifTimeAgo = 'yesterday';
                                    } else {
                                        $notifTimeAgo = date('M j, Y', $ntCreatedAt);
                                    }

                                    $isUnread = empty($nt['is_read']);
                                    $itemClasses = "notif-item type-{$typeKey}" . ($isUnread ? ' notif-item--unread' : '');
                                    $hasLink = !empty($nt['link']);
                                    $linkUrl = $hasLink ? sanitize($nt['link']) : '';
                                ?>
                                    <?php if ($hasLink): ?>
                                    <a href="<?= $linkUrl ?>" class="<?= $itemClasses ?>" data-type="<?= $typeKey ?>" data-unread="<?= $isUnread ? '1' : '0' ?>">
                                    <?php else: ?>
                                    <div class="<?= $itemClasses ?>" data-type="<?= $typeKey ?>" data-unread="<?= $isUnread ? '1' : '0' ?>">
                                    <?php endif; ?>
                                        <div class="notif-item-icon icon-box-<?= $typeKey ?>" aria-hidden="true">
                                            <i class="<?= $typeIcon ?>"></i>
                                        </div>
                                        <div class="notif-item-body">
                                            <div class="notif-item-top">
                                                <h3 class="notif-item-title"><?= sanitize($nt['title']) ?></h3>
                                                <?php if ($isUnread): ?>
                                                    <span class="notif-unread-badge"><span class="unread-pulse" aria-hidden="true"></span> NEW</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="notif-item-desc"><?= sanitize($nt['message']) ?></div>
                                            <div class="notif-item-meta">
                                                <span class="notif-pill pill-<?= $typeKey ?>"><i class="<?= $typeIcon ?>" aria-hidden="true"></i> <?= $typeLabel ?></span>
                                                <span title="<?= date('l, d M Y • h:i A', $ntCreatedAt) ?>"><?= $notifTimeAgo ?></span>
                                                <?php if ($hasLink): ?>
                                                    <span class="notif-item-open"><i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> Open</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <?php if ($hasLink): ?>
                                        <div class="notif-item-arrow" aria-hidden="true"><i class="fas fa-chevron-right"></i></div>
                                        <?php endif; ?>
                                    <?php if ($hasLink): ?>
                                    </a>
                                    <?php else: ?>
                                    </div>
                                    <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                            <div class="notif-empty" id="notifFilterEmpty" hidden>
                                <i class="fas fa-filter" aria-hidden="true"></i>
                                No notifications match this filter.
                            </div>
                            <div class="dash-notif-foot">
                                <span class="dash-notif-foot-note" id="dashNotifFootNote" data-total="<?= count($notifData['items']) ?>">
                                    <i class="fas fa-circle-info" aria-hidden="true"></i>
                                    <span id="dashNotifFootText"><?= count($notifData['items']) ?> notification<?= count($notifData['items']) === 1 ? '' : 's' ?><?= !empty($notifData['unread']) ? ' • ' . (int)$notifData['unread'] . ' unread' : '' ?></span>
                                </span>
                                <?php $hasUnreadNotifs = !empty($notifData['unread']); ?>
                                <button type="button" id="notifMarkAll" class="btn btn-outline btn-sm"<?= !$hasUnreadNotifs ? ' disabled' : '' ?>>
                                    <i class="fas <?= $hasUnreadNotifs ? 'fa-check-double' : 'fa-check' ?>" aria-hidden="true"></i>
                                    <?= $hasUnreadNotifs ? 'Mark all read' : 'All caught up' ?>
                                </button>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- Classes -->
                <section class="dash-section" aria-labelledby="classesSectionHeading">
                    <div class="dash-section-head">
                        <div class="dash-section-head-title">
                            <h2 id="classesSectionHeading"><i class="fas fa-layer-group" aria-hidden="true"></i> Classes</h2>
                            <?php if (!empty($classes)): ?><span class="dash-count"><?= count($classes) ?></span><?php endif; ?>
                        </div>
                    </div>
                    <div class="panel">
                        <div class="panel-body dash-classes">
                            <?php if (empty($classes)): ?>
                            <p class="dash-empty"><i class="fas fa-inbox" aria-hidden="true"></i> <span>No classes yet — they appear here once you <a href="import_excel.php">import your first Excel sheet</a>.</span></p>
                            <?php else: ?>
                                <?php foreach ($classes as $c): ?>
                                <a class="class-chip" href="results.php?class=<?= e_url($c['academic_year'] . '|' . $c['std'] . '|' . $c['division'] . '|' . $c['semester']) ?>">
                                    <i class="fas fa-graduation-cap" aria-hidden="true"></i>
                                    <span>STD <?= sanitize($c['std']) ?> <?= sanitize($c['division']) ?> — <?= sanitize($c['semester']) ?></span>
                                    <small>(<?= (int)$c['students'] ?>)</small>
                                </a>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                </section>

                <!-- Performance -->
                <?php if ($hasPerf): ?>
                <section class="dash-section" aria-labelledby="perfSectionHeading">
                    <div class="dash-section-head">
                        <div class="dash-section-head-title">
                            <h2 id="perfSectionHeading"><i class="fas fa-trophy" aria-hidden="true"></i> Performance Overview</h2>
                        </div>
                    </div>
                    <div class="perf-grid">
                        <?php if (!empty($topPerformer)): ?>
                        <a href="results.php" class="perf-card">
                            <span class="perf-icon" aria-hidden="true"><i class="fas fa-trophy"></i></span>
                            <div class="perf-label"><i class="fas fa-star" aria-hidden="true"></i> Top Performer</div>
                            <div class="perf-name"><?= sanitize($topPerformer['student_name'] ?? '') ?></div>
                            <div class="perf-score"><?= e_attr((float)($topPerformer['pct'] ?? 0)) ?>%</div>
                            <div class="perf-detail">STD <?= sanitize($topPerformer['std'] ?? '') ?> <?= sanitize($topPerformer['division'] ?? '') ?> — <?= sanitize($topPerformer['academic_year'] ?? '') ?></div>
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($lowestPerformer)): ?>
                        <a href="results.php" class="perf-card perf-card--low">
                            <span class="perf-icon" aria-hidden="true"><i class="fas fa-chart-column"></i></span>
                            <div class="perf-label"><i class="fas fa-hand-holding-heart" aria-hidden="true"></i> Needs Support</div>
                            <div class="perf-name"><?= sanitize($lowestPerformer['student_name'] ?? '') ?></div>
                            <div class="perf-score"><?= e_attr((float)($lowestPerformer['pct'] ?? 0)) ?>%</div>
                            <div class="perf-detail">STD <?= sanitize($lowestPerformer['std'] ?? '') ?> <?= sanitize($lowestPerformer['division'] ?? '') ?> — <?= sanitize($lowestPerformer['academic_year'] ?? '') ?></div>
                        </a>
                        <?php endif; ?>
                    </div>
                </section>
                <?php endif; ?>

                </div><!-- .dash-wrap -->
            </div><!-- .admin-content -->
        </main>
    </div>

    <script>
    /* ═══ Ambient Background Video Controller (Play/Pause, Mute, Fullscreen, Reduced Motion & Viewport Saver) ═══ */
    (function () {
        'use strict';
        var video = document.getElementById('dashHeroVideo');
        if (!video) return;

        var playBtn = document.getElementById('heroPlayToggle');
        var muteBtn = document.getElementById('heroMuteToggle');
        var expandBtn = document.getElementById('heroExpandBtn');
        var statusBadge = document.getElementById('heroVideoStatusBadge');
        var statusText = document.getElementById('heroShowcaseStatus');

        var reduceMotionQuery = window.matchMedia ? window.matchMedia('(prefers-reduced-motion: reduce)') : null;
        var userPaused = !!(reduceMotionQuery && reduceMotionQuery.matches);
        var inViewport = true;

        video.defaultMuted = true;
        video.muted = true;

        function updatePlayUI(isPaused) {
            if (playBtn) {
                playBtn.innerHTML = isPaused
                    ? '<i class="fa-solid fa-play" aria-hidden="true"></i>'
                    : '<i class="fa-solid fa-pause" aria-hidden="true"></i>';
                var label = isPaused ? 'Play background video' : 'Pause background video';
                playBtn.setAttribute('aria-label', label);
                playBtn.setAttribute('title', label);
            }
            if (statusBadge) {
                statusBadge.classList.toggle('is-paused', isPaused);
            }
            if (statusText) {
                statusText.textContent = isPaused ? 'Showcase Paused' : 'HCC Campus Showcase';
            }
        }

        function updateMuteUI(isMuted) {
            if (muteBtn) {
                muteBtn.innerHTML = isMuted
                    ? '<i class="fa-solid fa-volume-xmark" aria-hidden="true"></i>'
                    : '<i class="fa-solid fa-volume-high" aria-hidden="true"></i>';
                var label = isMuted ? 'Unmute background video' : 'Mute background video';
                muteBtn.setAttribute('aria-label', label);
                muteBtn.setAttribute('title', label);
            }
        }

        function isVideoFullscreen() {
            return document.fullscreenElement === video || document.webkitFullscreenElement === video;
        }

        function syncPlayback() {
            var shouldPlay = !userPaused && (inViewport || isVideoFullscreen()) && !document.hidden;
            if (shouldPlay) {
                if (video.paused) {
                    video.play().then(function () {
                        updatePlayUI(false);
                    }).catch(function () {
                        updatePlayUI(true);
                    });
                } else {
                    updatePlayUI(false);
                }
            } else {
                if (!video.paused) {
                    video.pause();
                }
                updatePlayUI(userPaused || video.paused);
            }
        }

        function onFullscreenChange() {
            var fs = isVideoFullscreen();
            video.controls = fs;
            if (fs) {
                video.removeAttribute('aria-hidden');
                video.removeAttribute('tabindex');
            } else {
                video.setAttribute('aria-hidden', 'true');
                video.setAttribute('tabindex', '-1');
                userPaused = video.paused;
                updatePlayUI(video.paused);
                updateMuteUI(video.muted);
                syncPlayback();
            }
        }

        if (userPaused) {
            video.pause();
            updatePlayUI(true);
        } else {
            syncPlayback();
        }
        updateMuteUI(video.muted);

        if (playBtn) {
            playBtn.addEventListener('click', function () {
                if (video.paused) {
                    userPaused = false;
                    video.play().then(function () {
                        updatePlayUI(false);
                    }).catch(function () {});
                } else {
                    userPaused = true;
                    video.pause();
                    updatePlayUI(true);
                }
            });
        }

        if (muteBtn) {
            muteBtn.addEventListener('click', function () {
                video.muted = !video.muted;
                updateMuteUI(video.muted);
                if (!video.muted && video.paused) {
                    userPaused = false;
                    video.play().then(function () { updatePlayUI(false); }).catch(function () {});
                }
            });
        }

        if (expandBtn) {
            expandBtn.addEventListener('click', function () {
                if (!document.fullscreenElement && !document.webkitFullscreenElement) {
                    if (video.requestFullscreen) {
                        video.requestFullscreen().catch(function () {});
                    } else if (video.webkitRequestFullscreen) {
                        video.webkitRequestFullscreen();
                    } else if (video.webkitEnterFullscreen) {
                        video.webkitEnterFullscreen();
                    }
                    if (video.paused) {
                        userPaused = false;
                        video.play().then(function () { updatePlayUI(false); }).catch(function () {});
                    }
                } else {
                    if (document.exitFullscreen) {
                        document.exitFullscreen().catch(function () {});
                    } else if (document.webkitExitFullscreen) {
                        document.webkitExitFullscreen();
                    }
                }
            });
        }

        document.addEventListener('fullscreenchange', onFullscreenChange);
        document.addEventListener('webkitfullscreenchange', onFullscreenChange);
        video.addEventListener('webkitendfullscreen', onFullscreenChange);

        if ('IntersectionObserver' in window) {
            var heroSection = document.getElementById('dashHero') || video;
            var observer = new IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    inViewport = entry.isIntersecting;
                    syncPlayback();
                });
            }, { threshold: 0.15 });
            observer.observe(heroSection);
        }

        document.addEventListener('visibilitychange', syncPlayback);

        if (reduceMotionQuery) {
            var onMotionPrefChange = function (e) {
                userPaused = !!e.matches;
                syncPlayback();
            };
            if (typeof reduceMotionQuery.addEventListener === 'function') {
                reduceMotionQuery.addEventListener('change', onMotionPrefChange);
            } else if (typeof reduceMotionQuery.addListener === 'function') {
                reduceMotionQuery.addListener(onMotionPrefChange);
            }
        }
    })();

    /* ═══ Dashboard Notification Feed & Mark-Read Sync ═══ */
    (function () {
        'use strict';
        var trigger = document.getElementById('notifTrigger');
        var badge = document.getElementById('notifBadge');
        var filterBar = document.getElementById('notifFilterBar');
        var filterEmpty = document.getElementById('notifFilterEmpty');
        var footNote = document.getElementById('dashNotifFootNote');
        var footText = document.getElementById('dashNotifFootText');
        var activeFilter = 'all';

        function applyFilter(filter) {
            activeFilter = filter;
            var notifRows = document.querySelectorAll('.dash-notif-list .notif-item');
            var visibleCount = 0;
            notifRows.forEach(function (row) {
                var show = false;
                if (filter === 'all') {
                    show = true;
                } else if (filter === 'unread') {
                    show = (row.getAttribute('data-unread') === '1');
                } else {
                    show = (row.getAttribute('data-type') === filter);
                }
                row.style.display = show ? '' : 'none';
                if (show) visibleCount++;
            });
            if (filterEmpty) {
                filterEmpty.hidden = (visibleCount > 0);
            }
        }

        function markAllRead(onSuccess, onFail) {
            var meta = document.querySelector('meta[name="csrf-token"]');
            var csrf = document.querySelector('input[name="csrf_token"]');
            var token = (csrf && csrf.value) ? csrf.value : (meta ? meta.content : '');
            var fd = new FormData();
            fd.append('action', 'mark_notifs_read');
            if (token) fd.append('csrf_token', token);
            fetch('dashboard.php', { method: 'POST', body: fd, credentials: 'same-origin' })
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (d && d.ok) {
                        if (onSuccess) onSuccess();
                    } else if (onFail) {
                        onFail();
                    }
                })
                .catch(function () {
                    if (onFail) onFail();
                });
        }

        function clearUnreadUI() {
            if (badge) badge.style.display = 'none';
            if (trigger) {
                trigger.classList.remove('has-unread');
                trigger.setAttribute('aria-label', 'Notifications');
            }
            var dropdownUnread = document.querySelectorAll('#notifDropdown .notif-item.is-unread');
            dropdownUnread.forEach(function (el) { el.classList.remove('is-unread'); });

            var unreadCountBadge = document.getElementById('notifUnreadCountBadge');
            if (unreadCountBadge) unreadCountBadge.style.display = 'none';

            var unreadFilterBtn = document.getElementById('notifFilterUnreadBtn');
            var wasUnreadFilter = unreadFilterBtn && unreadFilterBtn.classList.contains('active');
            if (unreadFilterBtn) unreadFilterBtn.style.display = 'none';

            var unreadRows = document.querySelectorAll('.dash-notif-list .notif-item--unread');
            unreadRows.forEach(function (row) {
                row.classList.remove('notif-item--unread');
                row.setAttribute('data-unread', '0');
                var uBadge = row.querySelector('.notif-unread-badge');
                if (uBadge) uBadge.remove();
            });

            if (footNote && footText) {
                var total = parseInt(footNote.getAttribute('data-total') || '0', 10);
                footText.textContent = total + ' notification' + (total === 1 ? '' : 's');
            }

            var markAllBtn = document.getElementById('notifMarkAll');
            if (markAllBtn) {
                markAllBtn.disabled = true;
                markAllBtn.innerHTML = '<i class="fas fa-check" aria-hidden="true"></i> All caught up';
            }

            if (wasUnreadFilter && filterBar) {
                var allBtn = filterBar.querySelector('[data-filter="all"]');
                if (allBtn) allBtn.click();
            } else {
                applyFilter(activeFilter);
            }
        }

        var markAllBtn = document.getElementById('notifMarkAll');
        if (markAllBtn) {
            markAllBtn.addEventListener('click', function () {
                markAllBtn.disabled = true;
                markAllRead(clearUnreadUI, function () {
                    markAllBtn.disabled = false;
                });
            });
        }

        /* Sync dashboard feed when topbar bell is opened (admin.js marks notifications read on open) */
        if (trigger) {
            trigger.addEventListener('click', function () {
                setTimeout(function () {
                    if (trigger.getAttribute('aria-expanded') === 'true') {
                        clearUnreadUI();
                    }
                }, 250);
            });
        }

        if (filterBar) {
            var filterBtns = filterBar.querySelectorAll('.notif-filter-btn');
            filterBtns.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    filterBtns.forEach(function (b) {
                        var isSelected = (b === btn);
                        b.classList.toggle('active', isSelected);
                        b.setAttribute('aria-pressed', isSelected ? 'true' : 'false');
                    });
                    applyFilter(btn.getAttribute('data-filter') || 'all');
                });
            });
        }
    })();
    </script>
    <script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= (is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 0) ?>"></script>

    <!-- HCC Assistant: dashboard help chatbot -->
    <button type="button" class="hcc-bot-launcher" id="hccBotLauncher" aria-label="Open HCC Assistant" aria-expanded="false" aria-controls="hccBotPanel" title="HCC Assistant">
        <i class="fa-solid fa-comments hcc-bot-ico-open" aria-hidden="true"></i>
        <i class="fa-solid fa-xmark hcc-bot-ico-close" aria-hidden="true"></i>
        <span class="hcc-bot-dot" aria-hidden="true"></span>
    </button>
    <section class="hcc-bot-panel" id="hccBotPanel" role="dialog" aria-modal="false" aria-labelledby="hccBotTitle" aria-hidden="true"
             data-endpoint="api_assistant_chat.php" data-logo="<?= htmlspecialchars($schoolLogoUrl, ENT_QUOTES, 'UTF-8') ?>" data-admin="<?= htmlspecialchars(explode(' ', trim((string)($_SESSION['admin_name'] ?? '')))[0], ENT_QUOTES, 'UTF-8') ?>">
        <header class="hcc-bot-head">
            <span class="hcc-bot-avatar" aria-hidden="true"><img src="<?= htmlspecialchars($schoolLogoUrl, ENT_QUOTES, 'UTF-8') ?>" alt=""></span>
            <div class="hcc-bot-head-text">
                <strong id="hccBotTitle">HCC Assistant</strong>
                <span>Online · System help</span>
            </div>
            <button type="button" id="hccBotReset" title="New conversation" aria-label="Start a new conversation"><i class="fa-solid fa-rotate-right" aria-hidden="true"></i></button>
            <button type="button" id="hccBotClose" title="Close" aria-label="Close assistant"><i class="fa-solid fa-minus" aria-hidden="true"></i></button>
        </header>
        <div class="hcc-bot-body" id="hccBotBody" role="log" aria-live="polite" aria-relevant="additions"></div>
        <form class="hcc-bot-form" id="hccBotForm" autocomplete="off">
            <label for="hccBotInput" class="hcc-bot-sr">Ask the assistant a question</label>
            <input type="text" id="hccBotInput" maxlength="500" placeholder="Ask how the system works…">
            <button type="submit" id="hccBotSend" aria-label="Send message" disabled><i class="fa-solid fa-paper-plane" aria-hidden="true"></i></button>
        </form>
        <div class="hcc-bot-foot">Steps stay in this chat. Open is the button that goes to a page.</div>
    </section>
    <script src="<?= BASE_URL ?>assets/js/hcc-assistant.js?v=<?= (is_file(__DIR__ . '/../assets/js/hcc-assistant.js') ? filemtime(__DIR__ . '/../assets/js/hcc-assistant.js') : 0) ?>"></script>

    <!-- Live IST greeting: updates as Indian time changes without page reload -->
    <script>
    (function () {
        'use strict';
        var textEl = document.getElementById('dashGreetingText');
        if (!textEl) return;

        function istHour(date) {
            var d = date || new Date();
            try {
                var parts = new Intl.DateTimeFormat('en-US', {
                    timeZone: 'Asia/Kolkata', hour: '2-digit', hour12: false
                }).formatToParts(d);
                var hourPart = null;
                for (var i = 0; i < parts.length; i++) {
                    if (parts[i].type === 'hour') { hourPart = parts[i]; break; }
                }
                if (hourPart) return parseInt(hourPart.value, 10) % 24;
            } catch (e) {
                return new Date(d.getTime() + (5.5 * 60 * 60 * 1000)).getUTCHours();
            }
            return d.getHours();
        }

        function greetingFor(hour) {
            if (hour < 12) return 'Good morning';
            if (hour < 17) return 'Good afternoon';
            return 'Good evening';
        }

        function updateGreeting() {
            var text = greetingFor(istHour());
            if (textEl.textContent !== text) textEl.textContent = text;
        }

        updateGreeting();
        setInterval(updateGreeting, 30000);
        document.addEventListener('visibilitychange', function () {
            if (!document.hidden) updateGreeting();
        });
        window.addEventListener('focus', updateGreeting);
        window.addEventListener('pageshow', updateGreeting);
    })();
    </script>
</body>
</html>
