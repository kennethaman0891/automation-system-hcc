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
                <span><i class="fa-solid fa-shield-halved" aria-hidden="true"></i> Automation System</span>
            </div>
        </a>
    </div>

    <nav class="sidebar-nav" aria-label="Main navigation">
        <!-- 1. OVERVIEW -->
        <p class="sidebar-label">Overview</p>
        <a href="dashboard.php" <?= $adminPage === 'dashboard' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Dashboard">
            <i class="fa-solid fa-fw fa-gauge-high" aria-hidden="true"></i>
            <span>Dashboard</span>
        </a>

        <!-- 2. UNIT TESTS -->
        <p class="sidebar-label">Unit Tests (I &ndash; IV)</p>
        <a href="unit_tests.php" <?= $adminPage === 'unit_tests' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Unit Test Results">
            <i class="fa-solid fa-fw fa-square-poll-vertical" aria-hidden="true"></i>
            <span>Unit Test Results</span>
        </a>
        <a href="unit_test_entry.php" <?= $adminPage === 'unit_test_entry' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Unit Test Entry">
            <i class="fa-solid fa-fw fa-file-pen" aria-hidden="true"></i>
            <span>Unit Test Entry</span>
        </a>
        <a href="import_unit_tests.php" <?= $adminPage === 'import_unit_tests' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Import Unit Tests">
            <i class="fa-solid fa-fw fa-file-import" aria-hidden="true"></i>
            <span>Import Unit Tests</span>
        </a>

        <!-- 3. ACADEMIC SCHEDULE -->
        <p class="sidebar-label">Academic Schedule</p>
        <a href="timetable.php" <?= $adminPage === 'timetable' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Exam Timetable" data-badge="Builder">
            <i class="fa-solid fa-fw fa-calendar-week" aria-hidden="true"></i>
            <span>Exam Timetable</span>
            <span class="sidebar-badge gold">Builder</span>
        </a>
        <a href="exam_seating.php" <?= $adminPage === 'exam_seating' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Exam Seating Plan">
            <i class="fa-solid fa-fw fa-chair" aria-hidden="true"></i>
            <span>Exam Seating Plan</span>
        </a>
        <a href="substitute_allocator.php" <?= $adminPage === 'substitute_allocator' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Substitute Allocator">
            <i class="fa-solid fa-fw fa-people-arrows" aria-hidden="true"></i>
            <span>Substitute Allocator</span>
        </a>

        <!-- 4. DAILY OPERATIONS -->
        <p class="sidebar-label">Daily Operations</p>
        <a href="at_risk_radar.php" <?= $adminPage === 'at_risk_radar' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="At-Risk Radar">
            <i class="fa-solid fa-fw fa-triangle-exclamation" aria-hidden="true"></i>
            <span>At-Risk Radar</span>
        </a>
        <a href="parent_sign_chase.php" <?= $adminPage === 'parent_sign_chase' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Parent-Sign Chase">
            <i class="fa-solid fa-fw fa-file-signature" aria-hidden="true"></i>
            <span>Parent-Sign Chase</span>
        </a>

        <!-- 5. SEMESTER RESULTS -->
        <p class="sidebar-label">Semester Results</p>
        <a href="results.php" <?= $adminPage === 'results' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Results Matrix">
            <i class="fa-solid fa-fw fa-table" aria-hidden="true"></i>
            <span>Results Matrix</span>
        </a>
        <a href="entry.php" <?= $adminPage === 'entry' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Student Marks Entry">
            <i class="fa-solid fa-fw fa-pen-to-square" aria-hidden="true"></i>
            <span>Student Marks Entry</span>
        </a>
        <a href="import_excel.php" <?= $adminPage === 'import_excel' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Import Semester Excel">
            <i class="fa-solid fa-fw fa-file-excel" aria-hidden="true"></i>
            <span>Import Semester Excel</span>
        </a>

        <!-- 6. RECORDS & MANAGEMENT -->
        <p class="sidebar-label">Records &amp; Management</p>
        <a href="promote_students.php" <?= $adminPage === 'promote_students' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Promote Students">
            <i class="fa-solid fa-fw fa-user-graduate" aria-hidden="true"></i>
            <span>Promote Students</span>
        </a>
        <a href="bonafide_certificate.php" <?= $adminPage === 'bonafide_certificate' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Bonafide Certificate">
            <i class="fa-solid fa-fw fa-award" aria-hidden="true"></i>
            <span>Bonafide Certificate</span>
        </a>
        <a href="whatsapp_dispatch_log.php" <?= $adminPage === 'whatsapp_dispatch_log' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="WhatsApp Dispatch Log">
            <i class="fa-solid fa-fw fa-clock-rotate-left" aria-hidden="true"></i>
            <span>WhatsApp Dispatch Log</span>
        </a>
        <a href="records_archive.php" <?= $adminPage === 'records_archive' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Records Archive" data-badge="Vault">
            <i class="fa-solid fa-fw fa-box-archive" aria-hidden="true"></i>
            <span>Records Archive</span>
            <span class="sidebar-badge archive">Vault</span>
        </a>

        <!-- 7. COMMUNICATION & ADMISSIONS -->
        <p class="sidebar-label">Communication &amp; Admissions</p>
        <a href="whatsapp_share.php" <?= $adminPage === 'whatsapp_share' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="WhatsApp Hub" data-badge="Live">
            <i class="fa-brands fa-fw fa-whatsapp" aria-hidden="true"></i>
            <span>WhatsApp Hub</span>
            <span class="sidebar-badge wa">Live</span>
        </a>
        <a href="admission_followups.php" <?= $adminPage === 'admission_followups' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Admission Follow-ups" data-badge="CRM">
            <i class="fa-solid fa-fw fa-phone-volume" aria-hidden="true"></i>
            <span>Admission Follow-ups</span>
            <span class="sidebar-badge crm">CRM</span>
        </a>
        <a href="admission_enquiries.php" <?= $adminPage === 'admission_enquiries' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Admission Enquiries" data-badge="Pipeline">
            <i class="fa-solid fa-fw fa-envelope-open-text" aria-hidden="true"></i>
            <span>Admission Enquiries</span>
            <span class="sidebar-badge pipeline">Pipeline</span>
        </a>

        <!-- 8. CLOUD WORKSPACE -->
        <p class="sidebar-label">Cloud Workspace</p>
        <a href="shared_workspace.php" <?= $adminPage === 'shared_workspace' ? 'class="active" aria-current="page"' : '' ?> data-tooltip="Drive Workspace" data-badge="Drive">
            <i class="fa-brands fa-fw fa-google-drive" aria-hidden="true"></i>
            <span>Drive Workspace</span>
            <span class="sidebar-badge drive">Drive</span>
        </a>
    </nav>

    <div class="sidebar-footer">
        <a href="change_password.php" class="sidebar-security<?= $adminPage === 'change_password' ? ' active' : '' ?>" title="Account Security &amp; Password" data-tooltip="Account Security &amp; Password"<?= $adminPage === 'change_password' ? ' aria-current="page"' : '' ?>>
            <i class="fa-solid fa-fw fa-user-shield" aria-hidden="true"></i>
            <span>Security</span>
        </a>
        <a href="logout.php" class="sidebar-logout js-logout-trigger" id="sidebarLogoutBtn" title="Log out of admin session" data-tooltip="Log Out">
            <i class="fa-solid fa-fw fa-arrow-right-from-bracket" aria-hidden="true"></i>
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
