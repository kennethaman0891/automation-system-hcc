<?php
/**
 * Exam Timetable Builder — Admin Main Interface
 * Hindustani Covenant Church High School
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

$adminPage = 'timetable';
$pageTitle = 'Exam Timetable Builder';
$settings = [];
try {
    if (function_exists('getAllSettings')) {
        $settings = getAllSettings();
    }
} catch (\Throwable $e) {
    $settings = [];
}
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church High School';
$adminUser = sanitize($_SESSION['admin_name'] ?? 'Admin');
$adminRole = ucfirst($_SESSION['admin_role'] ?? 'Administrator');
$csrfToken = generateCSRFToken();
$activeAcademicYear = '2025-26';
try {
    if (function_exists('rcGetActiveAcademicYear')) {
        $activeAcademicYear = rcGetActiveAcademicYear();
    }
} catch (\Throwable $e) {}

// Generate user initials
$adminInitials = 'A';
$nameParts = preg_split('/\s+/', trim($adminUser));
if (!empty($nameParts[0])) {
    $adminInitials = mb_strtoupper(mb_substr($nameParts[0], 0, 1));
    if (isset($nameParts[1])) {
        $adminInitials .= mb_strtoupper(mb_substr($nameParts[1], 0, 1));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php require __DIR__ . '/includes/account_head.php'; ?>
    <meta name="description" content="Design, manage and publish examination schedules for HCC High School">
    <meta name="csrf-token" content="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
    <title><?= sanitize($pageTitle) ?> | <?= sanitize($schoolName) ?></title>
    
    <link rel="icon" href="<?= BASE_URL ?>assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    
    <!-- Core Admin Styles -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin.css?v=<?= is_file(__DIR__ . '/../assets/css/admin.css') ? filemtime(__DIR__ . '/../assets/css/admin.css') : 1 ?>">
    
    <!-- Timetable Builder Stylesheet -->
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/timetable.css?v=<?= is_file(__DIR__ . '/../assets/css/timetable.css') ? filemtime(__DIR__ . '/../assets/css/timetable.css') : 1 ?>">
</head>
<body>
    <div class="admin-layout">
        <!-- Sidebar Navigation -->
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <main class="admin-main">
            <!-- Top Admin Header -->
            <header class="admin-header">
                <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar" aria-expanded="false" aria-controls="sidebar">
                    <i class="fa-solid fa-bars"></i>
                </button>

                <div class="header-titles">
                    <div class="header-breadcrumb-row">
                        <nav class="tt-breadcrumb" aria-label="Breadcrumb">
                            <a href="dashboard.php"><i class="fa-solid fa-house"></i> Dashboard</a>
                            <span class="sep"><i class="fa-solid fa-chevron-right"></i></span>
                            <span>Academic Schedule</span>
                            <span class="sep"><i class="fa-solid fa-chevron-right"></i></span>
                            <span class="current">Timetable Builder</span>
                        </nav>
                    </div>
                    <h1 class="page-title">
                        Exam Timetable Builder
                    </h1>
                    <p class="page-sub">Design, configure and publish examination schedules</p>
                </div>

                <div class="header-actions">
                    <a href="whatsapp_share.php" class="wa-quick-btn" id="waQuickBtn" title="Open WhatsApp Share Hub" aria-label="Open WhatsApp Share Hub">
                        <i class="fa-brands fa-whatsapp"></i>
                    </a>
                    <button type="button" class="header-action-btn" id="headerPrintBtn" title="Print Official Timetable (opens preview)" aria-label="Print Preview" onclick="window.hccTimetableApp?.openPrintPreview()">
                        <i class="fa-solid fa-print"></i>
                        <span>Print</span>
                    </button>
                    <button type="button" class="header-action-btn" id="headerFullscreenBtn" title="Toggle Fullscreen Matrix Mode" aria-label="Fullscreen" onclick="window.hccTimetableApp?.toggleFullscreen()">
                        <i class="fa-solid fa-expand"></i>
                    </button>
                    <div class="admin-user" title="<?= sanitize($adminUser) ?> — <?= sanitize($adminRole) ?>">
                        <span class="user-avatar" aria-hidden="true"><?= sanitize($adminInitials) ?></span>
                        <span class="user-meta">
                            <strong><?= sanitize($adminUser) ?></strong>
                            <small><?= sanitize($adminRole) ?></small>
                        </span>
                    </div>
                </div>
            </header>

            <!-- Main Content Container -->
            <div class="admin-content" style="padding: 20px 24px;">
                <!-- Reactive Timetable Matrix Engine App Mount -->
                <div id="timetableMatrixApp">
                    <!-- Loading Skeleton Placeholder -->
                    <div class="timetable-app-card" style="padding: 48px 24px; text-align: center; color: var(--text-2);">
                        <i class="fa-solid fa-spinner fa-spin" style="font-size: 2.2rem; color: var(--brand); margin-bottom: 14px;"></i>
                        <p style="font-weight: 700; font-size: 1rem; color: var(--tt-brand-deep);">Loading Exam Timetable Matrix Engine...</p>
                        <p style="font-size: 0.85rem; color: var(--text-3); margin-top: 4px;">Preparing institutional layouts and subject presets</p>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- Scripts -->
    <script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 1 ?>"></script>
    <script src="<?= BASE_URL ?>assets/js/timetable-engine.js?v=<?= is_file(__DIR__ . '/../assets/js/timetable-engine.js') ? filemtime(__DIR__ . '/../assets/js/timetable-engine.js') : 1 ?>"></script>
    <script>
        document.addEventListener('DOMContentLoaded', () => {
            if (typeof TimetableMatrixController === 'function') {
                window.hccTimetableApp = new TimetableMatrixController('timetableMatrixApp', {
                    apiBase: 'api_timetable.php',
                    csrfToken: <?= e_js($csrfToken) ?>,
                    defaultAcademicYear: <?= e_js($activeAcademicYear) ?>
                });
            } else {
                console.error('TimetableMatrixController failed to load.');
            }
        });
    </script>
</body>
</html>
