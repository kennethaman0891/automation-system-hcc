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
$schoolLogo = $settings['logo'] ?? 'assets/images/hcclogo.jpg';
$principalName = $settings['principal_name'] ?? '';
$grNo = $settings['gr_no'] ?? '';
$contactAddress = $settings['contact_address'] ?? '';
$notifData = rcGetRecentNotifications(8);

/* ── WhatsApp hub quick stats (fail-soft: WhatsApp tables may not exist yet) ── */
$waContacts = 0;
$waSent = 0;
$waPending = 0;
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

/* Mark-as-read endpoint for the header bell (same contract as results.php). */
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
$adminInitials = strtoupper(implode('', array_map(
    function ($part) { return mb_substr($part, 0, 1); },
    array_slice(array_filter(preg_split('/\s+/', trim($_SESSION['admin_name'] ?? 'A'))), 0, 2)
)));
$hourNow = (int)date('G');
$greeting = $hourNow < 12 ? 'Good morning' : ($hourNow < 17 ? 'Good afternoon' : 'Good evening');
$hasData = ((int)($stats['students'] ?? 0)) > 0;
$hasPerf = !empty($topPerformer) || !empty($lowestPerformer);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard | <?= sanitize($schoolName) ?></title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin.css?v=<?= (is_file(__DIR__ . '/../assets/css/admin.css') ? filemtime(__DIR__ . '/../assets/css/admin.css') : 0) ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin-dash.css?v=<?= (is_file(__DIR__ . '/../assets/css/admin-dash.css') ? filemtime(__DIR__ . '/../assets/css/admin-dash.css') : 0) ?>">
</head>
<body>
    <div class="admin-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
        <main class="admin-main">
            <header class="admin-header">
                <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle navigation" aria-controls="sidebar"><i class="fas fa-bars"></i></button>
                <div class="header-titles">
                    <h1 class="page-title">Dashboard</h1>
                    <p class="page-sub">At-a-glance results, classes and performance for <?= sanitize($schoolName) ?>.</p>
                </div>
                <div class="notif-container" id="notifContainer">
                    <?= csrfField() ?>
                    <button type="button" class="notif-trigger<?= ($notifData['unread'] ?? 0) > 0 ? ' has-unread' : '' ?>" id="notifTrigger" title="Recent Activity Notifications" aria-label="Notifications">
                        <i class="fas fa-bell"></i>
                        <?php if ($notifData['unread'] > 0): ?>
                            <span class="notif-badge" id="notifBadge"><?= (int)$notifData['unread'] ?></span>
                        <?php endif; ?>
                    </button>
                    <div class="notif-dropdown" id="notifDropdown">
                        <div class="notif-dropdown-header">
                            <span><i class="fas fa-bell"></i> Promotion &amp; System Alerts</span>
                            <span class="notif-count"><?= count($notifData['items']) ?> events</span>
                        </div>
                        <div class="notif-dropdown-list">
                            <?php if (empty($notifData['items'])): ?>
                                <div class="notif-empty">
                                    <i class="fas fa-bell-slash"></i>
                                    No recent promotion notifications.
                                </div>
                            <?php else: ?>
                                <?php foreach ($notifData['items'] as $nt): ?>
                                    <a href="<?= !empty($nt['link']) ? sanitize($nt['link']) : '#' ?>" class="notif-item">
                                        <div class="notif-item-icon">
                                            <i class="fas <?= ($nt['type'] ?? '') === 'promotion' ? 'fa-graduation-cap' : 'fa-info-circle' ?>"></i>
                                        </div>
                                        <div class="notif-item-body">
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
                <a href="whatsapp_share.php" class="wa-quick-btn" id="waQuickBtn" title="Open WhatsApp Share Hub" aria-label="Open WhatsApp Share Hub">
                    <i class="fa-brands fa-whatsapp"></i>
                </a>
                <div class="admin-user">
                    <span class="user-avatar"><?= sanitize($adminInitials) ?></span>
                    <span class="user-meta">
                        <strong><?= $adminName ?></strong>
                        <small>Admin</small>
                    </span>
                </div>
            </header>
            <div class="admin-content">
                <div class="dash-wrap">

                <?php if (($_GET['err'] ?? '') === 'admin_only'): ?>
                <div class="alert alert-warning" style="background:#fffbeb; color:#92400e; border:1px solid #fde68a; padding:12px 16px; border-radius:8px; margin-bottom:16px; display:flex; align-items:center; gap:10px; font-weight:600; font-size:0.88rem;">
                    <i class="fa-solid fa-lock"></i>
                    <span>Access Restricted: That feature requires Administrator privileges.</span>
                </div>
                <?php endif; ?>

                <!-- ═══ Hero ═══ -->
                <section class="dash-hero">
                    <img class="dash-hero-logo" src="<?= BASE_URL ?>assets/images/hcclogo.jpg" alt="HCC logo">
                    <div class="dash-hero-body">
                        <h2>Welcome to <span class="dash-hero-accent">Automation System</span></h2>
                        <p><?= sanitize($greeting) ?>, <?= $adminName ?> — <?= sanitize($schoolTagline) !== '' ? sanitize($schoolTagline) : 'Learn &middot; Lead &middot; Serve' ?></p>
                    </div>
                </section>

                <!-- ═══ WhatsApp Share Hub launcher ═══ -->
                <section class="dash-wa" id="dashWaLauncher" role="link" tabindex="0"
                         data-href="whatsapp_share.php"
                         aria-label="Open the WhatsApp Share Hub">
                    <span class="dash-wa-orb" aria-hidden="true"><i class="fa-brands fa-whatsapp"></i></span>
                    <div class="dash-wa-main">
                        <div class="dash-wa-head">
                            <h2>WhatsApp Share Hub</h2>
                            <span class="dash-wa-live"><span class="dash-wa-dot" aria-hidden="true"></span> Live</span>
                        </div>
                        <p class="dash-wa-sub">Broadcast report cards, unit-test slips and bonafide certificates straight to parent WhatsApp.</p>
                        <div class="dash-wa-stats">
                            <span><i class="fas fa-address-book" aria-hidden="true"></i> <?= (int)$waContacts ?> parents</span>
                            <span class="is-ok"><i class="fas fa-check-double" aria-hidden="true"></i> <?= (int)$waSent ?> sent</span>
                            <span class="is-wait"><i class="fas fa-clock" aria-hidden="true"></i> <?= (int)$waPending ?> pending</span>
                        </div>
                    </div>
                    <div class="dash-wa-actions">
                        <div class="dash-wa-chips">
                            <a href="whatsapp_share.php?doc_type=report_card" class="dash-wa-chip"><i class="fas fa-file-lines" aria-hidden="true"></i> Report Card</a>
                            <a href="whatsapp_share.php?doc_type=unit_test" class="dash-wa-chip"><i class="fas fa-pen-to-square" aria-hidden="true"></i> Unit Test</a>
                            <a href="whatsapp_share.php?doc_type=bonafide" class="dash-wa-chip"><i class="fas fa-certificate" aria-hidden="true"></i> Bonafide</a>
                            <a href="whatsapp_share.php?doc_type=announcement" class="dash-wa-chip"><i class="fas fa-bullhorn" aria-hidden="true"></i> Notice</a>
                        </div>
                        <a href="whatsapp_share.php" class="dash-wa-open">Open Hub <i class="fas fa-arrow-right" aria-hidden="true"></i></a>
                    </div>
                </section>

                <?php if (!$hasData): ?>
                <div class="dash-onboard">
                    <div class="dash-onboard-icon"><i class="fas fa-rocket"></i></div>
                    <div class="dash-onboard-body">
                        <h2>Welcome to HCC Admin</h2>
                        <p>No results yet. Import your first Excel sheet to start tracking marks, grades, pass rate and class averages.</p>
                    </div>
                    <a href="import_excel.php" class="btn btn-primary"><i class="fas fa-plus"></i> Import Excel</a>
                </div>
                <?php endif; ?>

                <!-- Promotion & System Alerts -->
                <section class="dash-section">
                    <div class="dash-section-head" style="flex-wrap:wrap;gap:12px;">
                        <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                            <h2><i class="fas fa-bell"></i> Promotion &amp; System Alerts</h2>
                            <?php if (!empty($notifData['unread'])): ?>
                                <span class="dash-count dash-count--unread" id="notifUnreadCountBadge"><span class="unread-pulse"></span> <?= (int)$notifData['unread'] ?> Unread</span>
                            <?php endif; ?>
                            <?php if (!empty($notifData['items'])): ?>
                                <span class="dash-count"><?= count($notifData['items']) ?> Total</span>
                            <?php endif; ?>
                        </div>

                        <!-- Quick Filter Tabs -->
                        <?php if (!empty($notifData['items'])): ?>
                        <div class="dash-section-head-filters" id="notifFilterBar">
                            <button type="button" class="notif-filter-btn active" data-filter="all">All (<?= count($notifData['items']) ?>)</button>
                            <?php if (!empty($notifData['unread'])): ?>
                            <button type="button" class="notif-filter-btn" data-filter="unread" id="notifFilterUnreadBtn">Unread (<?= (int)$notifData['unread'] ?>)</button>
                            <?php endif; ?>
                            <button type="button" class="notif-filter-btn" data-filter="unit_test">Unit Tests</button>
                            <button type="button" class="notif-filter-btn" data-filter="promotion">Promotions</button>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="panel">
                        <?php if (empty($notifData['items'])): ?>
                            <div class="panel-body">
                                <div class="notif-empty">
                                    <i class="fas fa-bell-slash"></i>
                                    No notifications yet. Promotion and system alerts will appear here.
                                </div>
                            </div>
                        <?php else: ?>
                            <div class="dash-notif-list">
                                <?php foreach ($notifData['items'] as $nt): 
                                    $rawType = strtolower($nt['type'] ?? 'system');
                                    $titleLower = strtolower($nt['title'] ?? '');

                                    if (strpos($rawType, 'unit_test') !== false || strpos($titleLower, 'unit test') !== false) {
                                        $typeKey = 'unit_test';
                                        $typeLabel = 'Unit Test';
                                        $typeIcon = 'fa-pen-to-square';
                                    } elseif (strpos($rawType, 'promotion') !== false || strpos($titleLower, 'promot') !== false) {
                                        $typeKey = 'promotion';
                                        $typeLabel = 'Promotion';
                                        $typeIcon = 'fa-graduation-cap';
                                    } elseif (strpos($rawType, 'results') !== false || strpos($titleLower, 'report card') !== false || strpos($titleLower, 'marks') !== false) {
                                        $typeKey = 'results';
                                        $typeLabel = 'Results';
                                        $typeIcon = 'fa-table-list';
                                    } elseif (strpos($rawType, 'excel') !== false || strpos($titleLower, 'excel') !== false || strpos($titleLower, 'import') !== false) {
                                        $typeKey = 'excel';
                                        $typeLabel = 'Excel Import';
                                        $typeIcon = 'fa-file-excel';
                                    } elseif (strpos($rawType, 'whatsapp') !== false || strpos($titleLower, 'whatsapp') !== false) {
                                        $typeKey = 'whatsapp';
                                        $typeLabel = 'WhatsApp';
                                        $typeIcon = 'fa-whatsapp';
                                    } else {
                                        $typeKey = 'system';
                                        $typeLabel = 'System Alert';
                                        $typeIcon = 'fa-bell';
                                    }

                                    // One-line "time ago" for the meta row; exact date on hover (title attr).
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
                                    $linkUrl = !empty($nt['link']) ? sanitize($nt['link']) : '#';
                                ?>
                                    <a href="<?= $linkUrl ?>" class="<?= $itemClasses ?>" data-type="<?= $typeKey ?>" data-unread="<?= $isUnread ? '1' : '0' ?>">
                                        <div class="notif-item-icon icon-box-<?= $typeKey ?>">
                                            <i class="fa-solid <?= $typeIcon ?>"></i>
                                        </div>
                                        <div class="notif-item-body">
                                            <div class="notif-item-top">
                                                <h3 class="notif-item-title"><?= sanitize($nt['title']) ?></h3>
                                                <?php if ($isUnread): ?>
                                                    <span class="notif-unread-badge"><span class="unread-pulse"></span> NEW</span>
                                                <?php endif; ?>
                                            </div>
                                            <div class="notif-item-desc"><?= sanitize($nt['message']) ?></div>
                                            <div class="notif-item-meta">
                                                <span class="notif-pill pill-<?= $typeKey ?>"><i class="fa-solid <?= $typeIcon ?>"></i> <?= $typeLabel ?></span>
                                                <span title="<?= date('l, d M Y • h:i A', $ntCreatedAt) ?>"><?= $notifTimeAgo ?></span>
                                                <?php if (!empty($nt['link'])): ?>
                                                    <span class="notif-item-open"><i class="fas fa-arrow-up-right-from-square" aria-hidden="true"></i> Open</span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                        <div class="notif-item-arrow"><i class="fas fa-chevron-right"></i></div>
                                    </a>
                                <?php endforeach; ?>
                            </div>
                            <div class="dash-notif-foot">
                                <span class="dash-notif-foot-note"><i class="fas fa-circle-info" aria-hidden="true"></i>
                                    <?= count($notifData['items']) ?> notification<?= count($notifData['items']) === 1 ? '' : 's' ?><?= !empty($notifData['unread']) ? ' • ' . (int)$notifData['unread'] . ' unread' : '' ?>
                                </span>
                                <button type="button" id="notifMarkAll" class="btn btn-outline btn-sm"><i class="fas fa-check-double"></i> Mark all read</button>
                            </div>
                        <?php endif; ?>
                    </div>
                </section>

                <!-- Classes -->
                <section class="dash-section">
                    <div class="dash-section-head">
                        <h2><i class="fas fa-layer-group"></i> Classes</h2>
                        <?php if (!empty($classes)): ?><span class="dash-count"><?= count($classes) ?></span><?php endif; ?>
                    </div>
                    <div class="panel">
                        <div class="panel-body dash-classes">
                            <?php if (empty($classes)): ?>
                            <p class="dash-empty"><i class="fas fa-inbox"></i> <span>No classes yet — they appear here once you <a href="import_excel.php">import your first Excel sheet</a>.</span></p>
                            <?php else: ?>
                                <?php foreach ($classes as $c): ?>
                                <a class="class-chip" href="results.php?class=<?= e_url($c['academic_year'] . '|' . $c['std'] . '|' . $c['division'] . '|' . $c['semester']) ?>">
                                    <i class="fas fa-graduation-cap"></i>
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
                <section class="dash-section">
                    <div class="dash-section-head"><h2><i class="fas fa-trophy"></i> Performance Overview</h2></div>
                    <div class="perf-grid">
                        <?php if (!empty($topPerformer)): ?>
                        <a href="results.php" class="perf-card">
                            <span class="perf-icon"><i class="fas fa-trophy"></i></span>
                            <div class="perf-label"><i class="fas fa-star"></i> Top Performer</div>
                            <div class="perf-name"><?= sanitize($topPerformer['student_name'] ?? '') ?></div>
                            <div class="perf-score"><?= e_attr((float)($topPerformer['pct'] ?? 0)) ?>%</div>
                            <div class="perf-detail">STD <?= sanitize($topPerformer['std'] ?? '') ?> <?= sanitize($topPerformer['division'] ?? '') ?> — <?= sanitize($topPerformer['academic_year'] ?? '') ?></div>
                        </a>
                        <?php endif; ?>
                        <?php if (!empty($lowestPerformer)): ?>
                        <a href="results.php" class="perf-card perf-card--low">
                            <span class="perf-icon"><i class="fas fa-chart-column"></i></span>
                            <div class="perf-label"><i class="fas fa-hand-holding-heart"></i> Needs Support</div>
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
        /* WhatsApp launcher: whole card is clickable / keyboard-activatable. */
    (function () {
        var launcher = document.getElementById('dashWaLauncher');
        if (!launcher) return;
        var target = launcher.getAttribute('data-href') || 'whatsapp_share.php';
        launcher.addEventListener('click', function (e) {
            // Let the inner chips & "Open Hub" link behave normally.
            if (e.target.closest('a, button')) return;
            window.location.href = target;
        });
        launcher.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' || e.key === ' ' || e.key === 'Spacebar') {
                e.preventDefault();
                window.location.href = target;
            }
        });
    })();

        /* Header notification bell: toggle dropdown + mark-as-read on open. */
    (function () {
        var trigger = document.getElementById('notifTrigger');
        var dropdown = document.getElementById('notifDropdown');
        var badge = document.getElementById('notifBadge');
        function markAllRead(done) {
            var csrf = document.querySelector('input[name="csrf_token"]');
            var fd = new FormData();
            fd.append('action', 'mark_notifs_read');
            if (csrf) fd.append('csrf_token', csrf.value);
            fetch('dashboard.php', { method: 'POST', body: fd }).then(done).catch(function () {});
        }
        function clearUnreadUI() {
            if (badge) badge.style.display = 'none';
            if (trigger) trigger.classList.remove('has-unread');
            var unreadCountBadge = document.getElementById('notifUnreadCountBadge');
            if (unreadCountBadge) unreadCountBadge.style.display = 'none';
            var unreadFilterBtn = document.getElementById('notifFilterUnreadBtn');
            if (unreadFilterBtn) unreadFilterBtn.style.display = 'none';

            // Clear unread highlights and badges from rows
            var unreadRows = document.querySelectorAll('.notif-item--unread');
            unreadRows.forEach(function (row) {
                row.classList.remove('notif-item--unread');
                row.setAttribute('data-unread', '0');
                var uBadge = row.querySelector('.notif-unread-badge');
                if (uBadge) uBadge.remove();
            });
        }
        if (trigger && dropdown) {
            trigger.addEventListener('click', function (e) {
                e.stopPropagation();
                var isOpen = dropdown.classList.contains('show');
                dropdown.classList.toggle('show');
                if (!isOpen && badge) {
                    markAllRead(clearUnreadUI);
                }
            });
            document.addEventListener('click', function (e) {
                if (!dropdown.contains(e.target) && e.target !== trigger) {
                    dropdown.classList.remove('show');
                }
            });
        }
        var markAllBtn = document.getElementById('notifMarkAll');
        if (markAllBtn) {
            markAllBtn.addEventListener('click', function () {
                markAllBtn.disabled = true;
                markAllRead(function () {
                    clearUnreadUI();
                    markAllBtn.innerHTML = '<i class="fas fa-check"></i> All caught up';
                });
            });
        }

        // Interactive Notification Filter Tabs
        var filterBar = document.getElementById('notifFilterBar');
        if (filterBar) {
            var filterBtns = filterBar.querySelectorAll('.notif-filter-btn');
            var notifRows = document.querySelectorAll('.dash-notif-list .notif-item');

            filterBtns.forEach(function (btn) {
                btn.addEventListener('click', function () {
                    filterBtns.forEach(function (b) { b.classList.remove('active'); });
                    btn.classList.add('active');

                    var filter = btn.getAttribute('data-filter');
                    notifRows.forEach(function (row) {
                        if (filter === 'all') {
                            row.style.display = '';
                        } else if (filter === 'unread') {
                            row.style.display = (row.getAttribute('data-unread') === '1') ? '' : 'none';
                        } else {
                            row.style.display = (row.getAttribute('data-type') === filter) ? '' : 'none';
                        }
                    });
                });
            });
        }
    })();
    </script>
    <script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= (is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 0) ?>"></script>
</body>
</html>
