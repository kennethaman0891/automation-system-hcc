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
<script>
/* ── Immediate desktop collapsed sidebar state restore (Zero FOUC) ── */
(function() {
    try {
        if (window.innerWidth > 1024 && localStorage.getItem('hcc_sidebar_collapsed') === '1') {
            var layout = document.querySelector('.admin-layout');
            if (layout) layout.classList.add('sidebar-collapsed');
        }
    } catch (e) {}
})();
</script>
<div class="sidebar-overlay" id="sidebarOverlay"></div>
<aside class="admin-sidebar" id="sidebar" aria-label="Admin sidebar">
    <div class="sidebar-brand">
        <a href="dashboard.php" class="sidebar-brand-main" title="<?= sanitize($schoolName) ?> Dashboard" data-tooltip="Dashboard">
            <img src="<?= BASE_URL ?>assets/images/hcclogo.jpg" alt="<?= sanitize($schoolName) ?> logo">
            <div class="sidebar-brand-text">
                <strong><?= sanitize($schoolName) ?></strong>
                <span><i class="fa-solid fa-shield-halved"></i> Automation System</span>
            </div>
        </a>
    </div>

    <nav class="sidebar-nav" aria-label="Main navigation">
        <!-- 1. OVERVIEW -->
        <p class="sidebar-label">Overview</p>
        <a href="dashboard.php" <?= $adminPage === 'dashboard' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Dashboard">
            <i class="fa-solid fa-chart-pie"></i>
            <span>Dashboard</span>
        </a>

        <!-- 2. UNIT TESTS -->
        <p class="sidebar-label">Unit Tests (I &ndash; IV)</p>
        <a href="unit_tests.php" <?= $adminPage === 'unit_tests' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Unit Test Results">
            <i class="fa-solid fa-table-cells"></i>
            <span>Unit Test Results</span>
        </a>
        <a href="unit_test_entry.php" <?= $adminPage === 'unit_test_entry' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Unit Test Entry">
            <i class="fa-solid fa-user-pen"></i>
            <span>Unit Test Entry</span>
        </a>
        <a href="import_unit_tests.php" <?= $adminPage === 'import_unit_tests' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Import Unit Tests">
            <i class="fa-solid fa-file-arrow-up"></i>
            <span>Import Unit Tests</span>
        </a>

        <!-- ACADEMIC SCHEDULE / EXAM TIMETABLE -->
        <p class="sidebar-label">Academic Schedule</p>
        <a href="timetable.php" <?= $adminPage === 'timetable' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Exam Timetable" data-badge="Builder">
            <i class="fa-solid fa-calendar-days"></i>
            <span>Exam Timetable</span>
            <span class="sidebar-badge" style="background:rgba(201,162,39,0.18);color:#c9a227;border:1px solid rgba(201,162,39,0.35);">Builder</span>
        </a>

        <!-- 3. SEMESTER RESULTS -->
        <p class="sidebar-label">Semester Results</p>
        <a href="results.php" <?= $adminPage === 'results' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Results Matrix">
            <i class="fa-solid fa-table-list"></i>
            <span>Results Matrix</span>
        </a>
        <a href="entry.php" <?= $adminPage === 'entry' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Student Marks Entry">
            <i class="fa-solid fa-pen-to-square"></i>
            <span>Student Marks Entry</span>
        </a>
        <a href="import_excel.php" <?= $adminPage === 'import_excel' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Import Semester Excel">
            <i class="fa-solid fa-file-excel"></i>
            <span>Import Semester Excel</span>
        </a>

        <!-- 4. RECORDS & MANAGEMENT -->
        <p class="sidebar-label">Records & Management</p>
        <a href="promote_students.php" <?= $adminPage === 'promote_students' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Promote Students">
            <i class="fa-solid fa-graduation-cap"></i>
            <span>Promote Students</span>
        </a>
        <a href="bonafide_certificate.php" <?= $adminPage === 'bonafide_certificate' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Bonafide Certificate">
            <i class="fa-solid fa-certificate"></i>
            <span>Bonafide Certificate</span>
        </a>
        <a href="whatsapp_dispatch_log.php" <?= $adminPage === 'whatsapp_dispatch_log' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="WhatsApp Dispatch Log">
            <i class="fa-solid fa-clock-rotate-left"></i>
            <span>WhatsApp Dispatch Log</span>
        </a>
        <a href="records_archive.php" <?= $adminPage === 'records_archive' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Records Archive" data-badge="Vault">
            <i class="fa-solid fa-box-archive"></i>
            <span>Records Archive</span>
            <span class="sidebar-badge archive">Vault</span>
        </a>

        <!-- 5. COMMUNICATION & ADMISSIONS -->
        <p class="sidebar-label">Communication & Admissions</p>
        <a href="whatsapp_share.php" <?= $adminPage === 'whatsapp_share' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="WhatsApp Hub" data-badge="Live">
            <i class="fa-brands fa-whatsapp" style="color:#25D366;"></i>
            <span>WhatsApp Hub</span>
            <span class="sidebar-badge wa">Live</span>
        </a>
        <a href="custom_dispatcher.php" <?= $adminPage === 'custom_dispatcher' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Custom Dispatcher" data-badge="Fast">
            <i class="fa-solid fa-paper-plane" style="color:#f59e0b;"></i>
            <span>Custom Dispatcher</span>
            <span class="sidebar-badge" style="background:rgba(245,158,11,0.18);color:#fbbf24;border:1px solid rgba(245,158,11,0.35);">Fast</span>
        </a>
        <a href="admission_followups.php" <?= $adminPage === 'admission_followups' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Admission Follow-ups" data-badge="CRM">
            <i class="fa-solid fa-headset" style="color:#10b981;"></i>
            <span>Admission Follow-ups</span>
            <span class="sidebar-badge" style="background:#10b981;color:#fff;">CRM</span>
        </a>
        <a href="admission_enquiries.php" <?= $adminPage === 'admission_enquiries' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Admission Enquiries" data-badge="Pipeline">
            <i class="fa-solid fa-user-plus" style="color:#0ea5e9;"></i>
            <span>Admission Enquiries</span>
            <span class="sidebar-badge" style="background:#e0f2fe;color:#0284c7;font-weight:700;">Pipeline</span>
        </a>

        <!-- 6. CLOUD WORKSPACE & SHARING -->
        <p class="sidebar-label">Cloud Workspace</p>
        <a href="shared_workspace.php" <?= $adminPage === 'shared_workspace' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Drive Workspace" data-badge="Drive">
            <i class="fa-brands fa-google-drive" style="color:#4285F4;"></i>
            <span>Drive Workspace</span>
            <span class="sidebar-badge wa" style="background:#4285F4;">Drive</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="change_password.php" class="sidebar-security<?= $adminPage === 'change_password' ? ' active' : '' ?>" title="Account Security & Password" data-tooltip="Account Security & Password"<?= $adminPage === 'change_password' ? ' aria-current="page"' : '' ?>>
            <i class="fa-solid fa-key"></i>
            <span>Security</span>
        </a>
        <a href="logout.php" class="sidebar-logout js-logout-trigger" id="sidebarLogoutBtn" title="Log out of admin session" data-tooltip="Log Out">
            <i class="fa-solid fa-arrow-right-from-bracket"></i>
            <span>Log out</span>
        </a>
    </div>

    <script>
    /* ── Immediate active sidebar item auto-scroll (Zero FOUC / Zero layout shift) ── */
    (function () {
        window.hccScrollActiveSidebar = function (smooth) {
            try {
                var sb = document.getElementById('sidebar');
                if (!sb) return;
                var nav = sb.querySelector('.sidebar-nav');
                if (!nav || nav.clientHeight <= 0 || nav.scrollHeight <= nav.clientHeight) return;

                var active = nav.querySelector('a.active, .active, [aria-current="page"]');
                if (active) {
                    var p = active.parentElement;
                    while (p && p !== nav) {
                        if (p.tagName === 'DETAILS' && !p.open) p.open = true;
                        p = p.parentElement;
                    }
                    var navRect = nav.getBoundingClientRect();
                    var activeRect = active.getBoundingClientRect();
                    if (activeRect.height <= 0) return;
                    var relTop = activeRect.top - navRect.top;
                    var targetRelTop = Math.floor((nav.clientHeight - activeRect.height) / 2);
                    var maxScroll = nav.scrollHeight - nav.clientHeight;
                    var targetScroll = Math.max(0, Math.min(maxScroll, Math.round(nav.scrollTop + (relTop - targetRelTop))));
                    if (Math.abs(nav.scrollTop - targetScroll) > 2) {
                        if (smooth && typeof nav.scrollTo === 'function') {
                            nav.scrollTo({ top: targetScroll, behavior: 'smooth' });
                        } else {
                            nav.scrollTop = targetScroll;
                        }
                    }
                } else {
                    var footerActive = sb.querySelector('.sidebar-footer a.active, .sidebar-footer .active');
                    if (footerActive && nav.scrollHeight > nav.clientHeight) {
                        var maxScroll = nav.scrollHeight - nav.clientHeight;
                        if (Math.abs(nav.scrollTop - maxScroll) > 2) {
                            nav.scrollTop = maxScroll;
                        }
                    }
                }
            } catch (e) {}
        };
        // 1. Synchronous execution on HTML parse (before first paint)
        window.hccScrollActiveSidebar(false);
        // 2. Next animation frame (guarantees flexbox rendering stabilization)
        if (typeof requestAnimationFrame === 'function') {
            requestAnimationFrame(function () {
                window.hccScrollActiveSidebar(false);
            });
        }
    })();
    </script>
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
