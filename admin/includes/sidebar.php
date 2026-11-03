<?php
/* ── Common admin sidebar ─────────────────────────────────────────────
   Centralized navigation sidebar shared by all admin pages.
   Set `$adminPage` in the calling script BEFORE this include to
   control the active link highlight.

   $adminPage values:
     dashboard              → dashboard.php
     results                → results.php
     entry                  → entry.php
     import_excel           → import_excel.php
     bonafide_certificate   → bonafide_certificate.php
   Defaults to basename($_SERVER['PHP_SELF']) so every page gets
   correct highlighting even if $adminPage is not explicitly set.
   ────────────────────────────────────────────────────────────── */
if (isset($adminPage) && $adminPage !== '') {
    // already set by the calling page
} else {
    $adminPage = basename($_SERVER['PHP_SELF'], '.php');
}
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church High School';
?>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<aside class="admin-sidebar" id="sidebar" aria-label="Admin sidebar">
    <div class="sidebar-brand">
        <img src="<?= BASE_URL ?>assets/images/hcclogo.jpg" alt="<?= sanitize($schoolName) ?> logo">
        <div class="sidebar-brand-text">
            <strong><?= sanitize($schoolName) ?></strong>
            <span><i class="fa-solid fa-shield-halved"></i> Automation System</span>
        </div>
        <button class="sidebar-collapse js-sidebar-toggle" type="button"
                aria-label="Collapse sidebar" aria-controls="sidebar">
            <i class="fa-solid fa-bars"></i>
        </button>
    </div>

    <nav class="sidebar-nav" aria-label="Main navigation">
        <!-- 1. OVERVIEW -->
        <p class="sidebar-label">Overview</p>
        <a href="dashboard.php" <?= $adminPage === 'dashboard' ? 'class="active" aria-current="page"' : '' ?>>
            <i class="fa-solid fa-chart-pie"></i>
            <span>Dashboard</span>
        </a>

        <!-- 2. UNIT TESTS -->
        <p class="sidebar-label">Unit Tests (I &ndash; IV)</p>
        <a href="unit_tests.php" <?= $adminPage === 'unit_tests' ? 'class="active" aria-current="page"' : '' ?>>
            <i class="fa-solid fa-table-cells"></i>
            <span>Unit Test Results</span>
        </a>
        <a href="unit_test_entry.php" <?= $adminPage === 'unit_test_entry' ? 'class="active" aria-current="page"' : '' ?>>
            <i class="fa-solid fa-user-pen"></i>
            <span>Unit Test Entry</span>
        </a>
        <a href="import_unit_tests.php" <?= $adminPage === 'import_unit_tests' ? 'class="active" aria-current="page"' : '' ?>>
            <i class="fa-solid fa-file-arrow-up"></i>
            <span>Import Unit Tests</span>
        </a>

        <!-- ACADEMIC SCHEDULE / EXAM TIMETABLE -->
        <p class="sidebar-label">Academic Schedule</p>
        <a href="timetable.php" <?= $adminPage === 'timetable' ? 'class="active" aria-current="page"' : '' ?>>
            <i class="fa-solid fa-calendar-days"></i>
            <span>Exam Timetable</span>
            <span class="sidebar-badge" style="background:rgba(201,162,39,0.18);color:#c9a227;border:1px solid rgba(201,162,39,0.35);">Builder</span>
        </a>

        <!-- 3. SEMESTER RESULTS -->
        <p class="sidebar-label">Semester Results</p>
        <a href="results.php" <?= $adminPage === 'results' ? 'class="active" aria-current="page"' : '' ?>>
            <i class="fa-solid fa-table-list"></i>
            <span>Results Matrix</span>
        </a>
        <a href="entry.php" <?= $adminPage === 'entry' ? 'class="active" aria-current="page"' : '' ?>>
            <i class="fa-solid fa-pen-to-square"></i>
            <span>Student Marks Entry</span>
        </a>
        <a href="import_excel.php" <?= $adminPage === 'import_excel' ? 'class="active" aria-current="page"' : '' ?>>
            <i class="fa-solid fa-file-excel"></i>
            <span>Import Semester Excel</span>
        </a>

        <!-- 4. RECORDS & MANAGEMENT -->
        <p class="sidebar-label">Records & Management</p>
        <a href="promote_students.php" <?= $adminPage === 'promote_students' ? 'class="active" aria-current="page"' : '' ?>>
            <i class="fa-solid fa-graduation-cap"></i>
            <span>Promote Students</span>
        </a>
        <a href="bonafide_certificate.php" <?= $adminPage === 'bonafide_certificate' ? 'class="active" aria-current="page"' : '' ?>>
            <i class="fa-solid fa-certificate"></i>
            <span>Bonafide Certificate</span>
        </a>
        <a href="whatsapp_dispatch_log.php" <?= $adminPage === 'whatsapp_dispatch_log' ? 'class="active" aria-current="page"' : '' ?>>
            <i class="fas fa-history"></i>
            <span>WhatsApp Dispatch Log</span>
        </a>
        <a href="records_archive.php" <?= $adminPage === 'records_archive' ? 'class="active" aria-current="page"' : '' ?>>
            <i class="fa-solid fa-box-archive"></i>
            <span>Records Archive</span>
            <span class="sidebar-badge archive">Vault</span>
        </a>

        <!-- 5. COMMUNICATION & DISPATCH -->
        <p class="sidebar-label">Communication</p>
        <a href="whatsapp_share.php" <?= $adminPage === 'whatsapp_share' ? 'class="active" aria-current="page"' : '' ?>>
            <i class="fa-brands fa-whatsapp" style="color:#25D366;"></i>
            <span>WhatsApp Hub</span>
            <span class="sidebar-badge wa">Live</span>
        </a>

        <!-- 6. CLOUD WORKSPACE & SHARING -->
        <p class="sidebar-label">Cloud Workspace</p>
        <a href="shared_workspace.php" <?= $adminPage === 'shared_workspace' ? 'class="active" aria-current="page"' : '' ?>>
            <i class="fa-brands fa-google-drive" style="color:#4285F4;"></i>
            <span>Drive Workspace</span>
            <span class="sidebar-badge wa" style="background:#4285F4;">Drive</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="change_password.php" class="sidebar-logout" style="margin-bottom:6px; background:rgba(255,255,255,0.06); color:#cbd5e1;" title="Account Security & Password">
            <i class="fa-solid fa-key"></i>
            <span>Security</span>
        </a>
        <a href="logout.php" class="sidebar-logout js-logout-trigger" id="sidebarLogoutBtn" title="Log out of admin session">
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
                    <?= htmlspecialchars(mb_strtoupper(mb_substr(trim($_SESSION['admin_name'] ?? 'A'), 0, 1)), ENT_QUOTES, 'UTF-8') ?>
                </div>
                <div class="logout-modal-user-info">
                    <strong><?= htmlspecialchars(trim($_SESSION['admin_name'] ?? 'Administrator'), ENT_QUOTES, 'UTF-8') ?></strong>
                    <span><?= htmlspecialchars(ucfirst($_SESSION['admin_role'] ?? 'Admin'), ENT_QUOTES, 'UTF-8') ?></span>
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
