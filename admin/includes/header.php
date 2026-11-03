<?php
// Security headers — must run before ANY output (auth_check.php also sends no-cache).
// headers_sent() guard prevents "headers already sent" warnings; header() replaces
// by default so no duplicates on double-include.
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../auth_check.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !verifyCSRFToken()) {
    die('Invalid CSRF token. Please go back and try again.');
}

$adminPage = $adminPage ?? basename($_SERVER['PHP_SELF'], '.php');

/* Page title: pages may set $pageTitle before including this file. */
$adminPageTitle = trim($pageTitle ?? ucwords(str_replace('_', ' ', $adminPage)));
if ($adminPageTitle === '') $adminPageTitle = 'Dashboard';

/* Short subtitle shown under the title in the topbar. */
$adminPageSubtitles = [
    'results' => 'Search classes, enter marks and print report cards',
    'timetable' => 'Design, manage and publish examination schedules and class time slots',
];
$adminPageSubtitle = $adminPageSubtitles[$adminPage] ?? 'Hindustani Covenant Church High School administration';

/* Signed-in user details for the topbar identity chip. */
$adminName = trim($_SESSION['admin_name'] ?? 'Admin');
$adminRole = ucfirst($_SESSION['admin_role'] ?? 'admin');
$adminInitials = '';
foreach (preg_split('/\s+/', $adminName) as $part) {
    if ($part === '') continue;
    $adminInitials .= mb_strtoupper(mb_substr($part, 0, 1));
    if (mb_strlen($adminInitials) >= 2) break;
}
if ($adminInitials === '') $adminInitials = 'A';

/* Sidebar navigation. 'page' decides the active highlight. */
$adminNav = [
    ['label' => 'Academic', 'items' => [
        ['href' => 'results.php', 'icon' => 'fa-file-invoice',    'label' => 'Report Card', 'page' => 'results'],
        ['href' => 'unit_tests.php', 'icon' => 'fa-pen-to-square', 'label' => 'Unit Test Results', 'page' => 'unit_tests'],
        ['href' => 'import_unit_tests.php', 'icon' => 'fa-file-excel', 'label' => 'Import Unit Test Excel', 'page' => 'import_unit_tests'],
        ['href' => 'timetable.php', 'icon' => 'fa-calendar-days', 'label' => 'Exam Timetable', 'page' => 'timetable'],
        ['href' => 'promote_students.php', 'icon' => 'fa-graduation-cap', 'label' => 'Promote Students', 'page' => 'promote_students'],
        ['href' => 'bonafide_certificate.php', 'icon' => 'fa-certificate',   'label' => 'Bonafide Certificate', 'page' => 'bonafide_certificate'],
    ]],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Hindustani Covenant Church High School — Admin Panel">
    <meta name="robots" content="noindex, nofollow">
    <title><?= htmlspecialchars($adminPageTitle, ENT_QUOTES, 'UTF-8') ?> | Hindustani Covenant Church High School Admin</title>
    <link rel="icon" href="<?= BASE_URL ?>assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <meta http-equiv="Cache-Control" content="no-cache, no-store, must-revalidate">
    <meta http-equiv="Pragma" content="no-cache">
    <meta http-equiv="Expires" content="0">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin.css?v=<?= (is_file(__DIR__ . '/../../assets/css/admin.css') ? filemtime(__DIR__ . '/../../assets/css/admin.css') : 0) ?>">
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="admin-layout">
        <aside class="admin-sidebar" id="sidebar" aria-label="Admin sidebar">
            <div class="sidebar-brand">
                <img src="<?= BASE_URL ?>assets/images/hcclogo.jpg" alt="Hindustani Covenant Church High School logo">
                <div class="sidebar-brand-text">
                    <strong>Hindustani Covenant Church High School</strong>
                    <span><i class="fa-solid fa-shield-halved"></i> Admin Panel</span>
                </div>
                <button class="sidebar-collapse js-sidebar-toggle" type="button"
                        aria-label="Collapse sidebar" aria-controls="sidebar">
                    <i class="fa-solid fa-bars"></i>
                </button>
            </div>

            <nav class="sidebar-nav" aria-label="Main navigation">
                <?php foreach ($adminNav as $group): ?>
                    <p class="sidebar-label"><?= htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8') ?></p>
                    <?php foreach ($group['items'] as $item):
                        $isActive = $item['page'] !== '' && $adminPage === $item['page']; ?>
                        <a href="<?= $item['href'] ?>" class="<?= $isActive ? 'active' : '' ?>"<?= $isActive ? ' aria-current="page"' : '' ?>>
                            <i class="fa-solid <?= $item['icon'] ?>"></i>
                            <span><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></span>
                        </a>
                    <?php endforeach; ?>
                <?php endforeach; ?>
            </nav>

            <div class="sidebar-footer">
                <a href="<?= BASE_URL ?>admin/logout.php" class="sidebar-logout js-logout-trigger" title="Log out of admin session">
                    <i class="fa-solid fa-arrow-right-from-bracket"></i>
                    <span>Log out</span>
                </a>
            </div>
        </aside>

        <!-- Logout Confirmation Modal (Custom CSS Popup) -->
        <div class="logout-modal-backdrop" id="logoutConfirmModal" aria-hidden="true" role="dialog" aria-modal="true" aria-labelledby="logoutModalTitle">
            <div class="logout-modal-card">
                <button type="button" class="logout-modal-close" id="logoutModalClose" aria-label="Close dialog">&times;</button>
                <div class="logout-modal-body">
                    <div class="logout-modal-icon-wrap">
                        <div class="logout-modal-icon">
                            <i class="fa-solid fa-arrow-right-from-bracket"></i>
                        </div>
                    </div>
                    <h3 class="logout-modal-title" id="logoutModalTitle">Confirm Logout</h3>
                    <p class="logout-modal-desc">Are you sure you want to log out of the admin panel? Any unsaved changes on this page will be lost.</p>
                    <div class="logout-modal-user">
                        <div class="logout-modal-avatar">
                            <?= htmlspecialchars(mb_strtoupper(mb_substr(trim($adminName), 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                        <div class="logout-modal-user-info">
                            <strong><?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?></strong>
                            <span><?= htmlspecialchars($adminRole, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    </div>
                    <form method="POST" action="<?= BASE_URL ?>admin/logout.php" class="logout-modal-form">
                        <?= csrfField() ?>
                        <input type="hidden" name="confirm" value="1">
                        <div class="logout-modal-actions">
                            <button type="button" class="logout-btn-cancel" id="logoutModalCancel">
                                <i class="fa-solid fa-xmark"></i>
                                <span>Cancel</span>
                            </button>
                            <button type="submit" class="logout-btn-confirm">
                                <i class="fa-solid fa-arrow-right-from-bracket"></i>
                                <span>Yes, Log Out</span>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <main class="admin-main">
            <header class="admin-header">
                <button class="sidebar-toggle" id="sidebarToggle" type="button"
                        aria-label="Toggle sidebar menu" aria-expanded="false" aria-controls="sidebar">
                    <i class="fa-solid fa-bars"></i>
                </button>

                <div class="header-titles">
                    <h1 class="page-title"><?= htmlspecialchars($adminPageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                    <p class="page-sub"><?= htmlspecialchars($adminPageSubtitle, ENT_QUOTES, 'UTF-8') ?></p>
                </div>

                <div class="header-actions">
                    <div class="admin-user" title="<?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?> — <?= htmlspecialchars($adminRole, ENT_QUOTES, 'UTF-8') ?>">
                        <span class="user-avatar" aria-hidden="true"><?= htmlspecialchars($adminInitials, ENT_QUOTES, 'UTF-8') ?></span>
                        <span class="user-meta">
                            <strong><?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?></strong>
                            <small><?= htmlspecialchars($adminRole, ENT_QUOTES, 'UTF-8') ?></small>
                        </span>
                    </div>
                </div>
            </header>
            <div class="admin-content">
