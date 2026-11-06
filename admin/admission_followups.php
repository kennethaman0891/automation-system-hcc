<?php
/**
 * HCC Automation System — Admission Follow-up Management Hub
 * 
 * Manages:
 *  - Parent enquiries & follow-up pipeline
 *  - 7 Dashboard sections (Today, Overdue, Upcoming, High-Priority, Awaiting, Interested, Completed)
 *  - 8 Follow-up categories
 *  - Parent WhatsApp conversation review
 *  - Suggested WhatsApp message generation & editing
 *  - Manual WhatsApp launch & manual send confirmation (strict unverified delivery)
 *  - Rust automation engine durable MySQL job queue integration
 *  - Complete audit logs (created, edited, completed, cancelled)
 */

declare(strict_types=1);

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/includes/whatsapp_functions.php';
require_once __DIR__ . '/auth_check.php';

waRequireAuth();

$adminPage = 'admission_followups';
$pageTitle = 'Admission Follow-up Management — HCC WhatsApp Hub';

$settings = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church High School';
$adminName = trim($_SESSION['admin_name'] ?? 'Administrator');
$adminRole = ucfirst($_SESSION['admin_role'] ?? 'Admin');
$adminInitials = 'A';
foreach (preg_split('/\s+/', $adminName) as $part) {
    if ($part === '') continue;
    $adminInitials = mb_strtoupper(mb_substr($part, 0, 1));
    break;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="csrf-token" content="<?= htmlspecialchars(generateCSRFToken(), ENT_QUOTES) ?>">
    <title>Admission Follow-up Hub — <?= sanitize($schoolName) ?></title>
    <link rel="icon" href="../assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="../assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admission-followups.css?v=<?= filemtime(__DIR__ . '/../assets/css/admission-followups.css') ?>">
</head>
<body>
    <div class="sidebar-overlay" id="sidebarOverlay"></div>
    <div class="admin-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <main class="admin-main">
            <header class="admin-header">
                <button class="sidebar-toggle js-sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar menu">
                    <i class="fa-solid fa-bars"></i>
                </button>
                <div class="header-titles">
                    <h1 class="page-title">
                        <i class="fa-solid fa-headset" style="color:#0ea5e9;margin-right:8px;"></i> Admission Follow-up Hub
                    </h1>
                    <p class="page-sub">Ensure no interested parent enquiry is forgotten · Follow-up CRM &amp; Manual WhatsApp Dispatch</p>
                </div>
                <div class="header-actions">
                    <button type="button" class="btn btn-primary" id="btnNewEnquiryModal">
                        <i class="fa-solid fa-plus"></i> <span class="ae-btn-label">New Enquiry</span>
                    </button>
                    <a href="whatsapp_share.php?view=replica" class="btn btn-secondary btn-whatsapp-hub" title="Open WhatsApp Web Replica" target="_blank" rel="noopener noreferrer">
                        <i class="fa-brands fa-whatsapp"></i> <span class="ae-btn-label">WhatsApp Hub</span>
                        <span class="wa-live-dot" title="Live WhatsApp Hub"></span>
                    </a>
                    <a href="admission_enquiries.php" class="btn btn-secondary" title="Admission Enquiries Pipeline">
                        <i class="fa-solid fa-user-plus"></i> <span class="ae-btn-label">Enquiries</span>
                    </a>
                    <a href="dashboard.php" class="btn btn-secondary" title="Main Dashboard">
                        <i class="fa-solid fa-chart-pie"></i> <span class="ae-btn-label">Dashboard</span>
                    </a>
                    <div class="admin-user" title="<?= sanitize($adminName) ?> — <?= sanitize($adminRole) ?>">
                        <span class="user-avatar" aria-hidden="true"><?= sanitize($adminInitials) ?></span>
                        <span class="user-meta">
                            <strong><?= sanitize($adminName) ?></strong>
                            <small><?= sanitize($adminRole) ?></small>
                        </span>
                    </div>
                </div>
            </header>

        <div class="admin-content" style="padding: 24px;">
            <!-- ═══ Hero Header & Rust Engine Status Card ═══ -->
            <div class="followup-header-card">
                <div class="followup-header-title">
                    <div class="followup-header-icon">
                        <i class="fa-solid fa-headset"></i>
                    </div>
                    <div>
                        <h1>Admission Follow-up Command Center</h1>
                        <p>Track next follow-up dates, review parent chats &amp; dispatch manually via WhatsApp.</p>
                        <p id="waInboundStatus" style="margin:6px 0 0;font-size:0.82rem;color:#64748b;">Checking WhatsApp link…</p>
                        <button type="button" id="waLogoutBtn" style="display:none;margin-top:8px;background:#fff;color:#b91c1c;border:1px solid #fecaca;border-radius:999px;padding:4px 10px;font-size:0.75rem;font-weight:700;cursor:pointer;">Log out of WhatsApp</button>
                    </div>
                </div>

                <div class="followup-header-actions">
                    <div class="engine-status-pill" title="Durable MySQL Job Queue &amp; Rust Automation Engine">
                        <span class="engine-pulse-dot"></span>
                        <span>Rust Engine: <strong style="color:#10b981;">Active</strong></span>
                        <span class="badge" id="engineQueueBadge" style="background:rgba(255,255,255,0.15);padding:2px 8px;border-radius:10px;font-size:0.75rem;">Queue</span>
                        <button type="button" class="btn-engine-trigger" id="btnRunEngineNow" title="Run scheduled reminder jobs now">
                            <i class="fas fa-play"></i> Run Engine Now
                        </button>
                    </div>
                </div>
            </div>

            <!-- ═══ 7 Dashboard KPI Sections ═══ -->
            <div class="followup-kpi-grid">
                <!-- 1. Today's Follow-ups -->
                <div class="followup-kpi-card kpi-card--today active" data-section="today" title="View enquiries due for follow-up today">
                    <div class="kpi-head">
                        <span class="kpi-label">Today's Follow-ups</span>
                        <span class="kpi-icon-badge"><i class="fas fa-calendar-day"></i></span>
                    </div>
                    <div class="kpi-count" id="kpiCountToday">-</div>
                    <div class="kpi-desc">Due for contact today</div>
                </div>

                <!-- 2. Overdue Follow-ups -->
                <div class="followup-kpi-card kpi-card--overdue" data-section="overdue" title="View follow-ups past their scheduled date">
                    <div class="kpi-head">
                        <span class="kpi-label">Overdue</span>
                        <span class="kpi-icon-badge"><i class="fas fa-triangle-exclamation"></i></span>
                    </div>
                    <div class="kpi-count" id="kpiCountOverdue" style="color:#b91c1c;">-</div>
                    <div class="kpi-desc">Requires immediate action</div>
                </div>

                <!-- 3. Upcoming Follow-ups -->
                <div class="followup-kpi-card kpi-card--upcoming" data-section="upcoming" title="View future scheduled follow-ups">
                    <div class="kpi-head">
                        <span class="kpi-label">Upcoming</span>
                        <span class="kpi-icon-badge"><i class="fas fa-calendar-days"></i></span>
                    </div>
                    <div class="kpi-count" id="kpiCountUpcoming">-</div>
                    <div class="kpi-desc">Scheduled ahead</div>
                </div>

                <!-- 4. High-Priority Enquiries -->
                <div class="followup-kpi-card kpi-card--high" data-section="high_priority" title="High and Urgent priority enquiries">
                    <div class="kpi-head">
                        <span class="kpi-label">High Priority</span>
                        <span class="kpi-icon-badge"><i class="fas fa-fire"></i></span>
                    </div>
                    <div class="kpi-count" id="kpiCountHigh" style="color:#c2410c;">-</div>
                    <div class="kpi-desc">Urgent &amp; High urgency</div>
                </div>

                <!-- 5. Parents Awaiting Responses -->
                <div class="followup-kpi-card kpi-card--awaiting" data-section="awaiting" title="First response pending from school desk">
                    <div class="kpi-head">
                        <span class="kpi-label">Awaiting Response</span>
                        <span class="kpi-icon-badge"><i class="fas fa-hourglass-half"></i></span>
                    </div>
                    <div class="kpi-count" id="kpiCountAwaiting" style="color:#7e22ce;">-</div>
                    <div class="kpi-desc">First response pending</div>
                </div>

                <!-- 6. Interested Parents -->
                <div class="followup-kpi-card kpi-card--interested" data-section="interested" title="Keen parents in admission pipeline">
                    <div class="kpi-head">
                        <span class="kpi-label">Interested Parents</span>
                        <span class="kpi-icon-badge"><i class="fas fa-star"></i></span>
                    </div>
                    <div class="kpi-count" id="kpiCountInterested" style="color:#0f766e;">-</div>
                    <div class="kpi-desc">High enrollment intent</div>
                </div>

                <!-- 7. Completed Admissions -->
                <div class="followup-kpi-card kpi-card--completed" data-section="completed" title="Finalized admissions">
                    <div class="kpi-head">
                        <span class="kpi-label">Completed</span>
                        <span class="kpi-icon-badge"><i class="fas fa-graduation-cap"></i></span>
                    </div>
                    <div class="kpi-count" id="kpiCountCompleted">-</div>
                    <div class="kpi-desc">Finalized / Enrolled</div>
                </div>
            </div>

            <!-- ═══ Section Filter Bar & Search ═══ -->
            <div class="followup-tabs-bar">
                <div class="followup-nav-tabs">
                    <button type="button" class="tab-btn active js-followup-tab" data-section="today">
                        <i class="fas fa-calendar-day"></i> Today's Follow-ups
                        <span class="tab-badge" id="tabBadgeToday">0</span>
                    </button>
                    <button type="button" class="tab-btn js-followup-tab" data-section="overdue">
                        <i class="fas fa-triangle-exclamation" style="color:#ef4444;"></i> Overdue
                        <span class="tab-badge" id="tabBadgeOverdue">0</span>
                    </button>
                    <button type="button" class="tab-btn js-followup-tab" data-section="upcoming">
                        <i class="fas fa-calendar-days"></i> Upcoming
                        <span class="tab-badge" id="tabBadgeUpcoming">0</span>
                    </button>
                    <button type="button" class="tab-btn js-followup-tab" data-section="high_priority">
                        <i class="fas fa-fire" style="color:#f97316;"></i> High Priority
                        <span class="tab-badge" id="tabBadgeHigh">0</span>
                    </button>
                    <button type="button" class="tab-btn js-followup-tab" data-section="awaiting">
                        <i class="fas fa-hourglass-half" style="color:#a855f7;"></i> Awaiting Response
                        <span class="tab-badge" id="tabBadgeAwaiting">0</span>
                    </button>
                    <button type="button" class="tab-btn js-followup-tab" data-section="interested">
                        <i class="fas fa-star" style="color:#10b981;"></i> Interested
                        <span class="tab-badge" id="tabBadgeInterested">0</span>
                    </button>
                    <button type="button" class="tab-btn js-followup-tab" data-section="completed">
                        <i class="fas fa-check-circle"></i> Completed
                        <span class="tab-badge" id="tabBadgeCompleted">0</span>
                    </button>
                    <button type="button" class="tab-btn js-followup-tab" data-section="all">
                        All Active
                    </button>
                </div>

                <div class="followup-search-filter">
                    <div class="search-input-wrap">
                        <i class="fas fa-search"></i>
                        <input type="text" id="followupSearchInput" placeholder="Search parent, child, phone..." aria-label="Search follow-ups">
                    </div>
                </div>
            </div>

            <!-- ═══ Follow-up Records Table ═══ -->
            <div class="followup-table-container">
                <table class="followup-table">
                    <thead>
                        <tr>
                            <th style="width:24%;">Parent &amp; Student</th>
                            <th style="width:14%;">Phone / WhatsApp</th>
                            <th style="width:16%;">Category</th>
                            <th style="width:20%;">Next Follow-up &amp; Reason</th>
                            <th style="width:9%;">Priority</th>
                            <th style="width:11%;">Assigned Staff</th>
                            <th style="width:6%;text-align:right;">Action</th>
                        </tr>
                    </thead>
                    <tbody id="followupListContainer">
                        <!-- Populated dynamically via JS -->
                    </tbody>
                </table>
            </div>

        </div><!-- .admin-content -->
    </main>
</div>

<!-- ═══ 6-Step Manual Dispatch & Follow-up Detail Modal ═══ -->
<div class="followup-modal-backdrop" id="followupDetailModal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="modalParentName">
    <div class="followup-modal-card">
        <div class="followup-modal-header">
            <div>
                <h3 id="modalParentName">Review Admission Follow-up</h3>
                <div id="modalChildMeta" style="font-size:0.8rem;color:#64748b;margin-top:2px;"></div>
            </div>
            <button type="button" class="btn-modal-close" id="btnFollowupModalClose" aria-label="Close dialog">&times;</button>
        </div>

        <div class="followup-modal-body">
            <!-- Strict Sending Policy Notice -->
            <div class="restriction-banner">
                <strong><i class="fas fa-shield-halved"></i> Strict School WhatsApp Sending Policy:</strong>
                Do not automatically send WhatsApp messages. Review the parent conversation thread below, inspect or edit the suggested message, click <em>Open WhatsApp</em>, manually press Send in WhatsApp, and mark the follow-up as completed.
            </div>

            <!-- Status Banner (if completed) -->
            <div id="modalStatusBanner" class="modal-status-banner" style="display:none;">
                <span id="modalStatusBannerText"></span>
            </div>

            <!-- Step Notice Banner (Appears after clicking Open WhatsApp) -->
            <div id="modalStepNotice" class="modal-step-notice" style="display:none;">
                <div style="font-weight:700;margin-bottom:4px;"><i class="fas fa-arrow-right"></i> WhatsApp opened in new window!</div>
                <div>1. In WhatsApp, review your text and press <strong>Send</strong> manually.</div>
                <div>2. Return here and click <strong>Mark Follow-up as Completed</strong> to log confirmation.</div>
            </div>

            <!-- Follow-up Meta Row -->
            <div class="modal-meta-grid">
                <div>
                    <span class="modal-meta-label">Follow-up Reason:</span>
                    <strong id="modalFollowupReason">-</strong>
                </div>
                <div>
                    <span class="modal-meta-label">Assigned Staff:</span>
                    <strong id="modalStaffAssigned">-</strong>
                </div>
                <div>
                    <span class="modal-meta-label">Priority:</span>
                    <span id="modalPriorityBadge" class="badge-priority medium">MEDIUM</span>
                </div>
                <div>
                    <span class="modal-meta-label">Recommended Action:</span>
                    <strong id="modalNextAction">-</strong>
                </div>
            </div>

            <!-- Step 2: Parent Conversation View -->
            <div>
                <label class="modal-step-label">
                    <i class="fa-brands fa-whatsapp" style="color:#25d366;"></i> Step 2: Parent Conversation History (Database Synced)
                </label>
                <div class="parent-conversation-box" id="modalConversationContainer">
                    <!-- Chat bubbles rendered here -->
                </div>
            </div>

            <!-- Step 3: Suggested WhatsApp Message -->
            <div class="suggested-msg-wrap">
                <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:6px;">
                    <label for="modalSuggestedMessage" style="font-weight:700;font-size:0.8rem;color:#1e293b;margin:0;">
                        <i class="fas fa-pen-to-square" style="color:#00a884;"></i> Step 3: Review or Edit Suggested WhatsApp Message
                    </label>
                    <span style="font-size:0.73rem;color:#94a3b8;">Prepared by Rust Automation Engine</span>
                </div>
                <textarea id="modalSuggestedMessage" class="suggested-msg-textarea" rows="4" placeholder="Enter personalized message to parent..."></textarea>
            </div>

            <!-- Previous Follow-up History (Audit Trail) -->
            <div style="margin-top:18px;">
                <h4 style="font-size:0.84rem;font-weight:700;color:#1e293b;margin:0 0 10px 0;display:flex;align-items:center;gap:8px;">
                    <i class="fas fa-history" style="color:#94a3b8;"></i> Previous Follow-up History &amp; Audit Trail
                </h4>
                <div id="modalHistoryContainer">
                    <!-- History timeline rendered here -->
                </div>
            </div>

            <!-- Unverified Delivery Representation Warning -->
            <div class="unverified-delivery-notice">
                <i class="fas fa-circle-info"></i>
                <div>
                    <strong>Audit Compliance Notice:</strong>
                    A manual sent confirmation must not be represented as verified WhatsApp delivery. Manual confirmation records that staff initiated the send via WhatsApp Web/App; delivery receipt is unverified.
                </div>
            </div>
        </div>

        <div class="workflow-actions-bar">
            <button type="button" class="btn btn-secondary" onclick="document.getElementById('followupDetailModal').style.display='none'">
                Close
            </button>
            <div style="display:flex;align-items:center;gap:10px;">
                <!-- Step 4: Open WhatsApp -->
                <button type="button" class="btn-open-wa" id="btnModalOpenWhatsApp" title="Step 4: Launch WhatsApp Web or App with pre-filled text">
                    <i class="fa-brands fa-whatsapp"></i> Step 4: Open WhatsApp
                </button>
                <!-- Step 6: Mark Follow-up Completed -->
                <button type="button" class="btn-mark-completed" id="btnModalMarkCompleted" title="Step 6: Mark follow-up completed with manual confirmation">
                    <i class="fas fa-check"></i> Step 6: Mark Completed
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══ New Admission Enquiry Modal ═══ -->
<div class="followup-modal-backdrop" id="newEnquiryModal" style="display:none;" role="dialog" aria-modal="true" aria-labelledby="modalNewTitle">
    <div class="followup-modal-card" style="max-width:700px;">
        <div class="followup-modal-header">
            <h3 id="modalNewTitle"><i class="fas fa-user-plus" style="color:#00a884;"></i> New Parent Admission Enquiry</h3>
            <button type="button" class="btn-modal-close" id="btnNewEnquiryModalClose" aria-label="Close dialog">&times;</button>
        </div>
        <form id="newEnquiryForm" style="display:flex;flex-direction:column;flex:1;overflow:hidden;">
            <div class="followup-modal-body">
                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                    <div>
                        <label class="enq-label">Parent Full Name *</label>
                        <input type="text" name="parent_name" class="form-control" placeholder="e.g. Ramesh Kulkarni" required>
                    </div>
                    <div>
                        <label class="enq-label">Student / Child Name *</label>
                        <input type="text" name="student_name" class="form-control" placeholder="e.g. Omkar Kulkarni" required>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                    <div>
                        <label class="enq-label">WhatsApp Phone Number *</label>
                        <input type="text" name="phone_number" class="form-control" placeholder="e.g. +91 98016 60010" required>
                    </div>
                    <div>
                        <label class="enq-label">Parent Email</label>
                        <input type="email" name="parent_email" class="form-control" placeholder="e.g. ramesh@gmail.com">
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                    <div>
                        <label class="enq-label">Class / Grade Applying For *</label>
                        <select name="interested_class" class="form-control" required>
                            <option value="Nursery">Nursery</option>
                            <option value="LKG">LKG</option>
                            <option value="UKG">UKG</option>
                            <option value="STD I" selected>STD I</option>
                            <option value="STD II">STD II</option>
                            <option value="STD III">STD III</option>
                            <option value="STD IV">STD IV</option>
                            <option value="STD V">STD V</option>
                            <option value="STD VI">STD VI</option>
                            <option value="STD VII">STD VII</option>
                            <option value="STD VIII">STD VIII</option>
                            <option value="STD IX">STD IX</option>
                            <option value="STD X">STD X</option>
                        </select>
                    </div>
                    <div>
                        <label class="enq-label">Follow-up Category *</label>
                        <select name="followup_category" class="form-control" required>
                            <option value="First response pending" selected>First response pending</option>
                            <option value="Admission details shared">Admission details shared</option>
                            <option value="Parent requested more information">Parent requested more information</option>
                            <option value="Parent interested">Parent interested</option>
                            <option value="Campus visit pending">Campus visit pending</option>
                            <option value="Documents pending">Documents pending</option>
                            <option value="Application pending">Application pending</option>
                            <option value="Admission decision pending">Admission decision pending</option>
                        </select>
                    </div>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px;">
                    <div>
                        <label class="enq-label">Next Follow-up Date *</label>
                        <input type="date" name="next_followup_date" class="form-control" value="<?= date('Y-m-d') ?>" required>
                    </div>
                    <div>
                        <label class="enq-label">Next Follow-up Time *</label>
                        <input type="time" name="next_followup_time" class="form-control" value="10:00" required>
                    </div>
                    <div>
                        <label class="enq-label">Priority *</label>
                        <select name="priority" class="form-control" required>
                            <option value="low">Low</option>
                            <option value="medium" selected>Medium</option>
                            <option value="high">High</option>
                            <option value="urgent">Urgent</option>
                        </select>
                    </div>
                </div>

                <div style="margin-bottom:14px;">
                    <label class="enq-label">Follow-up Reason *</label>
                    <input type="text" name="followup_reason" class="form-control" placeholder="e.g. Inbound enquiry regarding admission syllabus, fees & school bus" required>
                </div>

                <div style="display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px;">
                    <div>
                        <label class="enq-label">Assigned Staff Member</label>
                        <input type="text" name="assigned_staff_name" class="form-control" value="Admissions Desk">
                    </div>
                    <div>
                        <label class="enq-label">Next Recommended Action</label>
                        <input type="text" name="next_recommended_action" class="form-control" placeholder="e.g. Share prospectus PDF and invite for campus visit">
                    </div>
                </div>

                <div style="margin-bottom:14px;">
                    <label class="enq-label">Internal Notes</label>
                    <textarea name="internal_notes" class="form-control" rows="2" placeholder="Any internal notes or parent preferences..."></textarea>
                </div>
            </div>

            <div class="workflow-actions-bar">
                <button type="button" class="btn btn-secondary" onclick="document.getElementById('newEnquiryModal').style.display='none'">Cancel</button>
                <button type="submit" id="btnSaveNewEnquiry" style="background:#00a884;border:none;color:#fff;font-weight:700;padding:9px 18px;border-radius:10px;cursor:pointer;font-size:0.85rem;display:inline-flex;align-items:center;gap:8px;transition:background 0.15s ease;">
                    <i class="fas fa-check"></i> Save &amp; Schedule Follow-up
                </button>
            </div>
        </form>
    </div>
</div>

    <script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= (is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 0) ?>"></script>
    <script src="<?= BASE_URL ?>assets/js/admission-followups.js?v=<?= filemtime(__DIR__ . '/../assets/js/admission-followups.js') ?>"></script>
</body>
</html>
