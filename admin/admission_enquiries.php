<?php
/**
 * HCC Automation System — Centralized Admission Enquiry Management Dashboard
 * 
 * Allows school staff to:
 *  - Manually capture new parent enquiries from WhatsApp
 *  - Prevent duplicate enquiries with configurable matching rules
 *  - Reuse existing parent/student records
 *  - Track admission pipeline (NEW, CONTACTED, INFORMATION_SHARED, INTERESTED,
 *    VISIT_PLANNED, DOCUMENTS_PENDING, APPLICATION_SUBMITTED, ENROLLED, CLOSED)
 *  - Manage persistent conversations (incoming msgs, AI drafts, staff edits, approved responses, follow-up history)
 *  - Maintain audit trail and role permissions
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

$adminPage  = 'admission_enquiries';
$settings   = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church High School';
$adminUser  = sanitize($_SESSION['admin_name'] ?? 'Admin');
$adminRole  = $_SESSION['admin_role'] ?? 'editor';
$adminId    = (int)($_SESSION['admin_id'] ?? 1);

$db = getDB();

// Available Academic Years and Classes for filters
$years = ['2025-26', '2026-27', '2027-28'];
try {
    $dbYears = $db->query("SELECT DISTINCT academic_year FROM admission_enquiries ORDER BY academic_year DESC")->fetchAll(PDO::FETCH_COLUMN);
    if (!empty($dbYears)) {
        $years = array_unique(array_merge($years, $dbYears));
    }
} catch (Throwable $e) {}

$standardClasses = [
    'Nursery', 'LKG', 'UKG',
    'STD I', 'STD II', 'STD III', 'STD IV', 'STD V',
    'STD VI', 'STD VII', 'STD VIII', 'STD IX', 'STD X'
];

// Fetch staff users
$staffUsers = [];
try {
    $staffStmt = $db->query("SELECT id, username, full_name, role FROM users ORDER BY full_name ASC");
    $staffUsers = $staffStmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

// Initial filters from GET (strict allowlist for status)
$rawStatus = strtoupper(trim($_GET['status'] ?? 'ALL'));
$allowedFilterStatuses = [
    'ALL', 'NEW', 'CONTACTED', 'INFORMATION_SHARED', 'INTERESTED',
    'VISIT_PLANNED', 'DOCUMENTS_PENDING', 'APPLICATION_SUBMITTED', 'ENROLLED', 'CLOSED'
];
$fStatus = in_array($rawStatus, $allowedFilterStatuses, true) ? $rawStatus : 'ALL';
$fYear   = trim($_GET['year'] ?? '');
$fClass  = trim($_GET['class'] ?? '');
$fStaff  = !empty($_GET['staff_id']) ? (int)$_GET['staff_id'] : 0;
$search  = trim($_GET['search'] ?? '');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php require __DIR__ . '/includes/account_head.php'; ?>
    <meta name="csrf-token" content="<?= htmlspecialchars(generateCSRFToken(), ENT_QUOTES) ?>">
    <title>Admission Enquiry Management — <?= sanitize($schoolName) ?></title>
    <link rel="icon" href="../assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="../assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
    <link rel="stylesheet" href="../assets/css/admission-ai-review.css?v=<?= filemtime(__DIR__ . '/../assets/css/admission-ai-review.css') ?>">
    <style>
        /* ── Header Responsiveness & Text Truncation Fix ── */
        .admin-header {
            flex-wrap: wrap !important;
            gap: 12px !important;
            padding: 14px 20px !important;
        }
        .header-titles {
            min-width: 280px !important;
            flex: 1 1 300px !important;
        }
        .header-titles .page-title {
            white-space: normal !important;
            line-height: 1.3 !important;
        }
        .header-titles .page-sub {
            white-space: normal !important;
            line-height: 1.4 !important;
        }
        .header-actions {
            flex-wrap: wrap !important;
            gap: 8px !important;
            align-items: center !important;
        }

        /* ── Pipeline & Dashboard Styling ───────────────────────────────────── */
        .enquiry-stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 16px;
            margin-bottom: 20px;
        }
        .stat-card {
            background: #fff;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            padding: 16px 20px;
            display: flex;
            align-items: center;
            gap: 16px;
            box-shadow: var(--shadow-sm);
        }
        .stat-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        .stat-val { font-size: 1.55rem; font-weight: 800; color: var(--ink); line-height: 1; }
        .stat-lbl { font-size: 0.76rem; color: var(--text-2); font-weight: 600; text-transform: uppercase; margin-top: 5px; }

        /* Status Funnel Bar */
        .pipeline-funnel {
            display: flex;
            gap: 8px;
            overflow-x: auto;
            padding-bottom: 8px;
            margin-bottom: 20px;
            scrollbar-width: thin;
        }
        .funnel-pill {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: 999px;
            padding: 8px 16px;
            font-size: 0.82rem;
            font-weight: 600;
            color: var(--text-2);
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            white-space: nowrap;
            transition: all 0.15s ease;
        }
        .funnel-pill:hover {
            border-color: var(--brand);
            color: var(--brand);
            background: var(--brand-soft);
        }
        .funnel-pill.active {
            background: var(--brand);
            border-color: var(--brand);
            color: #ffffff;
            box-shadow: 0 2px 6px rgba(0, 168, 132, 0.3);
        }
        .funnel-badge {
            background: rgba(0, 0, 0, 0.08);
            padding: 2px 8px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
        }
        .funnel-pill.active .funnel-badge {
            background: rgba(255, 255, 255, 0.25);
            color: #ffffff;
        }

        /* Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 4px 10px;
            border-radius: 999px;
            font-size: 0.72rem;
            font-weight: 700;
            text-transform: uppercase;
            letter-spacing: 0.4px;
            border: 1px solid transparent;
            white-space: nowrap;
        }
        .status-NEW                  { background: #dbeafe; color: #1e40af; border-color: #93c5fd; }
        .status-CONTACTED            { background: #fef3c7; color: #92400e; border-color: #fcd34d; }
        .status-INFORMATION_SHARED   { background: #f3e8ff; color: #6b21a8; border-color: #d8b4fe; }
        .status-INTERESTED           { background: #ccfbf1; color: #115e59; border-color: #5eead4; }
        .status-VISIT_PLANNED        { background: #e0e7ff; color: #3730a3; border-color: #a5b4fc; }
        .status-DOCUMENTS_PENDING    { background: #ffedd5; color: #9a3412; border-color: #fdba74; }
        .status-APPLICATION_SUBMITTED{ background: #cffafe; color: #155e75; border-color: #67e8f9; }
        .status-ENROLLED             { background: #dcfce7; color: #166534; border-color: #86efac; }
        .status-CLOSED               { background: #f1f5f9; color: #475569; border-color: #cbd5e1; }

        /* Filter Row & Table */
        .enquiry-panel {
            background: #ffffff;
            border: 1px solid var(--border);
            border-radius: var(--radius);
            box-shadow: var(--shadow-sm);
            overflow: hidden;
        }
        .enquiry-toolbar {
            padding: 16px 20px;
            border-bottom: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            background: #f8fafc;
        }
        .toolbar-filters {
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
            flex: 1;
        }
        .toolbar-filters select, .toolbar-filters input {
            padding: 7px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.85rem;
            background: #ffffff;
        }
        .enquiry-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.84rem;
        }
        .enquiry-table th {
            background: var(--surface);
            padding: 12px 14px;
            text-align: left;
            font-weight: 700;
            color: var(--ink);
            border-bottom: 1px solid var(--border);
            white-space: nowrap;
        }
        .enquiry-table td {
            padding: 12px 14px;
            border-bottom: 1px solid var(--border);
            vertical-align: middle;
        }
        .enquiry-table tr:hover td {
            background: #f8fafc;
        }
        .student-sub {
            font-size: 0.74rem;
            color: var(--text-2);
            margin-top: 2px;
        }
        .reused-badge {
            background: #e0f2fe;
            color: #0369a1;
            font-size: 0.68rem;
            font-weight: 700;
            padding: 2px 6px;
            border-radius: 4px;
            display: inline-flex;
            align-items: center;
            gap: 3px;
            margin-top: 3px;
        }

        /* Action Buttons */
        .btn-table {
            padding: 5px 10px;
            border-radius: 5px;
            font-size: 0.78rem;
            font-weight: 600;
            border: 1px solid var(--border);
            background: #ffffff;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            color: var(--text-2);
            transition: all 0.15s;
        }
        .btn-table:hover {
            background: var(--surface);
            color: var(--ink);
            border-color: #94a3b8;
        }
        .btn-wa-chat {
            background: #dcfce7;
            color: #15803d;
            border-color: #bbf7d0;
        }
        .btn-wa-chat:hover {
            background: #25D366;
            color: #ffffff;
            border-color: #25D366;
        }

        /* ── Modal Common Styles ────────────────────────────────────────────── */
        .modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
            z-index: 1050;
            align-items: center;
            justify-content: center;
            padding: 16px;
            box-sizing: border-box;
        }
        .modal-backdrop.open { display: flex; }
        .modal-card {
            background: #ffffff;
            border-radius: 12px;
            box-shadow: 0 20px 45px rgba(0,0,0,0.25);
            width: 100%;
            max-width: 720px;
            max-height: 90vh;
            display: flex;
            flex-direction: column;
            overflow: hidden;
            animation: modalScale 0.18s cubic-bezier(0.16, 1, 0.3, 1);
        }
        .modal-card.modal-lg { max-width: 900px; }
        @keyframes modalScale {
            from { opacity: 0; transform: scale(0.96) translateY(-8px); }
            to { opacity: 1; transform: scale(1) translateY(0); }
        }
        .modal-head {
            padding: 16px 22px;
            background: #005c4b;
            color: #ffffff;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .modal-title {
            font-size: 1.15rem;
            font-weight: 700;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .modal-close-btn {
            background: none;
            border: none;
            color: #ffffff;
            font-size: 1.4rem;
            cursor: pointer;
            opacity: 0.8;
            line-height: 1;
        }
        .modal-close-btn:hover { opacity: 1; }
        .modal-body {
            padding: 20px 24px;
            overflow-y: auto;
            flex: 1;
        }
        .modal-foot {
            padding: 14px 22px;
            background: #f8fafc;
            border-top: 1px solid var(--border);
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 10px;
        }

        /* Form grid */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 14px;
            margin-bottom: 14px;
        }
        .form-col-full { grid-column: span 2; }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 4px;
        }
        .form-group label {
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--ink);
            text-transform: uppercase;
            letter-spacing: 0.3px;
        }
        .form-group input, .form-group select, .form-group textarea {
            padding: 9px 12px;
            border: 1px solid var(--border);
            border-radius: 6px;
            font-size: 0.86rem;
            background: #ffffff;
            font-family: inherit;
        }
        .form-group input:focus, .form-group select:focus, .form-group textarea:focus {
            border-color: var(--brand);
            outline: none;
            box-shadow: 0 0 0 2px var(--brand-soft);
        }
        .msg-paste-box {
            font-family: inherit;
            min-height: 110px;
            resize: vertical;
            line-height: 1.45;
        }
        .alert-box {
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 0.82rem;
            line-height: 1.4;
            margin-bottom: 14px;
            display: none;
        }
        .alert-warning { background: #fef3c7; color: #92400e; border: 1px solid #fcd34d; }
        .alert-info { background: #e0f2fe; color: #0369a1; border: 1px solid #bae6fd; }
        .alert-danger { background: #fee2e2; color: #991b1b; border: 1px solid #fecaca; }

        /* Timeline & Conversation */
        .timeline-stream {
            display: flex;
            flex-direction: column;
            gap: 14px;
            padding: 10px 0;
        }
        .tl-item {
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }
        .tl-icon {
            width: 34px;
            height: 34px;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 0.9rem;
            flex-shrink: 0;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        .tl-incoming   { background: #25d366; color: #ffffff; }
        .tl-aidraft    { background: #8b5cf6; color: #ffffff; }
        .tl-staffedit  { background: #3b82f6; color: #ffffff; }
        .tl-approved   { background: #059669; color: #ffffff; }
        .tl-followup   { background: #f59e0b; color: #ffffff; }
        .tl-status     { background: #64748b; color: #ffffff; }

        .tl-content {
            flex: 1;
            background: #f8fafc;
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 12px 14px;
        }
        .tl-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 6px;
        }
        .tl-sender { font-size: 0.8rem; font-weight: 700; color: var(--ink); }
        .tl-time { font-size: 0.72rem; color: var(--text-2); }
        .tl-body { font-size: 0.85rem; color: #334155; line-height: 1.45; white-space: pre-wrap; }

        @media (max-width: 900px) {
            .enquiry-stats-grid { grid-template-columns: repeat(2, 1fr); }
            .form-row { grid-template-columns: 1fr; }
            .form-col-full { grid-column: span 1; }
        }

        /* ── Header layout & action buttons ──────────────────────────────────
           Clear hierarchy: title and subtitle never truncate with ellipsis;
           compact action buttons with pixel-perfect flex alignment. */
        .admin-header {
            gap: 14px 20px;
            padding: 12px 24px;
        }
        .header-titles {
            flex: 1;
            min-width: 240px;
        }
        .header-titles .page-title {
            white-space: normal;
            line-height: 1.25;
            font-size: 1.18rem;
        }
        .header-titles .page-sub {
            white-space: normal;
            line-height: 1.35;
        }
        .header-actions {
            display: flex;
            align-items: center;
            gap: 8px !important;
            flex-shrink: 0;
            flex-wrap: wrap;
        }

        .header-actions .btn-primary,
        .header-actions .btn-secondary,
        .header-actions .btn-whatsapp-hub {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 7px;
            height: 38px;
            padding: 0 13px;
            border-radius: 8px;
            font-size: 0.83rem;
            font-weight: 700;
            letter-spacing: 0.01em;
            white-space: nowrap;
            cursor: pointer;
            text-decoration: none;
            user-select: none;
            transition: all 0.18s cubic-bezier(0.16, 1, 0.3, 1);
        }

        .header-actions .ae-btn-label {
            line-height: 1;
            display: inline-block;
        }

        /* Primary: the create action gets the page's green identity */
        .header-actions .btn-primary {
            background: linear-gradient(135deg, #0dbd7d 0%, #06a06d 55%, #008f62 100%);
            border: 0;
            color: #fff;
            box-shadow: 0 2px 6px rgba(6, 160, 109, 0.28);
        }
        .header-actions .btn-primary:hover {
            background: linear-gradient(135deg, #0fcb87 0%, #07ab75 55%, #019468 100%);
            box-shadow: 0 6px 16px rgba(6, 160, 109, 0.35);
            transform: translateY(-1px);
        }
        .header-actions .btn-primary:active { transform: translateY(0); box-shadow: 0 2px 6px rgba(6, 160, 109, 0.28); }
        .header-actions .btn-primary i { font-size: 0.82rem; line-height: 1; }

        /* Dedicated WhatsApp Hub Capsule Button */
        .header-actions .btn-whatsapp-hub {
            background: #f0fdf4;
            border: 1.5px solid #86efac;
            color: #166534;
            box-shadow: 0 1px 3px rgba(34, 197, 94, 0.1);
            position: relative;
        }
        .header-actions .btn-whatsapp-hub i {
            font-size: 1.05rem;
            color: #25d366;
            line-height: 1;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            transition: transform 0.18s ease, color 0.18s ease;
        }
        .header-actions .btn-whatsapp-hub .wa-live-dot {
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #22c55e;
            box-shadow: 0 0 0 2px rgba(34, 197, 94, 0.25);
            display: inline-block;
            flex-shrink: 0;
            transition: all 0.18s ease;
        }
        .header-actions .btn-whatsapp-hub:hover {
            background: #dcfce7;
            border-color: #22c55e;
            color: #14532d;
            transform: translateY(-1px);
            box-shadow: 0 4px 12px rgba(22, 163, 74, 0.18);
        }
        .header-actions .btn-whatsapp-hub:hover i {
            color: #15803d;
            transform: scale(1.1);
        }
        .header-actions .btn-whatsapp-hub:hover .wa-live-dot {
            background: #16a34a;
            box-shadow: 0 0 0 3px rgba(34, 197, 94, 0.35);
        }
        .header-actions .btn-whatsapp-hub:active {
            background: #bbf7d0;
            border-color: #16a34a;
            color: #14532d;
            transform: translateY(0);
            box-shadow: 0 1px 3px rgba(22, 163, 74, 0.2);
        }

        /* Secondaries: quiet outlined buttons with tinted icons */
        .header-actions .btn-secondary {
            background: #ffffff;
            border: 1.5px solid var(--border);
            color: #55434a;
            text-decoration: none;
            box-shadow: 0 1px 2px rgba(0, 0, 0, 0.03);
        }
        .header-actions .btn-secondary i { font-size: 0.9rem; line-height: 1; }
        .header-actions .btn-secondary:hover {
            background: var(--brand-soft);
            border-color: rgba(122, 34, 56, 0.35);
            color: var(--brand-main);
            transform: translateY(-1px);
            box-shadow: 0 3px 8px rgba(122, 34, 56, 0.12);
        }
        .header-actions .btn-secondary:active { transform: translateY(0); }

        /* Keyboard parity for all variants */
        .header-actions .btn-primary:focus-visible { outline: none; box-shadow: 0 0 0 3px rgba(6, 160, 109, 0.3); }
        .header-actions .btn-whatsapp-hub:focus-visible { outline: none; box-shadow: 0 0 0 3px rgba(37, 211, 102, 0.35); }
        .header-actions .btn-secondary:focus-visible {
            outline: none;
            box-shadow: 0 0 0 3px rgba(122, 34, 56, 0.18);
        }

        /* Responsive header adjustments */
        @media (max-width: 1200px) {
            .header-actions .btn-primary,
            .header-actions .btn-secondary,
            .header-actions .btn-whatsapp-hub { padding: 0 10px; font-size: 0.8rem; }
        }
        @media (max-width: 900px) {
            .header-actions { flex-wrap: wrap; }
            .header-actions .btn-primary,
            .header-actions .btn-secondary,
            .header-actions .btn-whatsapp-hub { padding: 0 10px; font-size: 0.78rem; }
        }
        @media (max-width: 560px) {
            .header-actions .btn-secondary,
            .header-actions .btn-whatsapp-hub { padding: 0 10px; min-width: 40px; }
            .header-actions .btn-secondary .ae-btn-label,
            .header-actions .btn-whatsapp-hub .ae-btn-label { display: none; }
            .header-actions .btn-whatsapp-hub .wa-live-dot { display: none; }
            .header-actions .btn-primary { flex: 1; justify-content: center; }
        }
        /* ── Modern Floating Toast Notifications ── */
        .ae-toast-container {
            position: fixed;
            bottom: 24px;
            right: 24px;
            z-index: 110000;
            display: flex;
            flex-direction: column;
            gap: 10px;
            max-width: 420px;
            pointer-events: none;
        }
        .ae-toast {
            pointer-events: auto;
            background: #ffffff;
            border-radius: 12px;
            padding: 12px 16px;
            box-shadow: 0 10px 30px rgba(15, 23, 42, 0.16), 0 2px 6px rgba(0, 0, 0, 0.06);
            border: 1px solid var(--border);
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.85rem;
            color: var(--ink);
            animation: aeToastSlideIn 0.22s cubic-bezier(0.16, 1, 0.3, 1);
            transition: opacity 0.2s ease, transform 0.2s ease;
        }
        .ae-toast.ae-toast-hiding {
            opacity: 0;
            transform: translateY(8px) scale(0.96);
        }
        @keyframes aeToastSlideIn {
            from { opacity: 0; transform: translateY(12px) scale(0.95); }
            to { opacity: 1; transform: translateY(0) scale(1); }
        }
        .ae-toast i {
            font-size: 1.15rem;
            flex-shrink: 0;
        }
        .ae-toast-success { border-left: 4px solid #10b981; }
        .ae-toast-success i { color: #10b981; }
        .ae-toast-error, .ae-toast-danger { border-left: 4px solid #ef4444; }
        .ae-toast-error i, .ae-toast-danger i { color: #ef4444; }
        .ae-toast-warning { border-left: 4px solid #f59e0b; }
        .ae-toast-warning i { color: #f59e0b; }
        .ae-toast-info { border-left: 4px solid #0ea5e9; }
        .ae-toast-info i { color: #0ea5e9; }
        .ae-toast-msg {
            flex: 1;
            line-height: 1.4;
            font-weight: 600;
        }
        .ae-toast-close {
            background: transparent;
            border: none;
            color: #94a3b8;
            font-size: 1.15rem;
            cursor: pointer;
            padding: 0 4px;
            line-height: 1;
            border-radius: 4px;
            transition: color 0.15s ease;
        }
        .ae-toast-close:hover { color: #334155; }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>
        
        <main class="admin-main">
            <header class="admin-header">
                <button class="sidebar-toggle js-sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar">
                    <i class="fas fa-bars"></i>
                </button>
                <div class="header-titles">
                    <h1 class="page-title">
                        <i class="fa-solid fa-user-plus" style="color:#0ea5e9;margin-right:8px;"></i> Admission Enquiry Management
                    </h1>
                    <p class="page-sub">Centralized hub to capture WhatsApp enquiries, qualify prospects, and manage admissions pipeline</p>
                    <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;margin-top:4px;">
                        <p id="waInboundStatus" class="page-sub" style="margin:0;">Checking WhatsApp link…</p>
                        <button type="button" id="waLogoutBtn" style="display:none;background:#fff;color:#b91c1c;border:1px solid #fecaca;border-radius:999px;padding:4px 10px;font-size:0.75rem;font-weight:700;cursor:pointer;">Log out of WhatsApp</button>
                    </div>
                </div>
                <div class="header-actions" style="display:flex;">
                    <button type="button" class="btn-primary" id="btnOpenNewEnquiry">
                        <i class="fa-solid fa-plus"></i> <span class="ae-btn-label">New Enquiry</span>
                    </button>
                    <a href="whatsapp_share.php?view=replica" class="btn-secondary btn-whatsapp-hub" title="Open WhatsApp Web Replica" target="_blank" rel="noopener noreferrer">
                        <i class="fa-brands fa-whatsapp"></i> <span class="ae-btn-label">WhatsApp Hub</span>
                        <span class="wa-live-dot" title="Live WhatsApp Hub"></span>
                    </a>
                    <?php if ($adminRole === 'admin'): ?>
                    <button type="button" class="btn-secondary" id="btnOpenRulesModal" title="Configure Duplicate Matching Rules">
                        <i class="fa-solid fa-sliders"></i> <span class="ae-btn-label">Matching Rules</span>
                    </button>
                    <?php endif; ?>
                    <button type="button" class="btn-secondary" id="btnExportCsv" title="Export Enquiries to CSV">
                        <i class="fa-solid fa-file-csv"></i> <span class="ae-btn-label">Export</span>
                    </button>
                </div>
            </header>

            <div class="admin-content">
                <!-- 1. KPI Stats Summary Cards -->
                <div class="enquiry-stats-grid">
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#e0f2fe;color:#0284c7;">
                            <i class="fa-solid fa-clipboard-list"></i>
                        </div>
                        <div>
                            <div class="stat-val" id="kpiTotal">0</div>
                            <div class="stat-lbl">Total Enquiries</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#fef3c7;color:#b45309;">
                            <i class="fa-solid fa-comments"></i>
                        </div>
                        <div>
                            <div class="stat-val" id="kpiActive">0</div>
                            <div class="stat-lbl">Active Pipeline</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#e0e7ff;color:#4338ca;">
                            <i class="fa-solid fa-school"></i>
                        </div>
                        <div>
                            <div class="stat-val" id="kpiVisits">0</div>
                            <div class="stat-lbl">Campus Visits &amp; Apps</div>
                        </div>
                    </div>
                    <div class="stat-card">
                        <div class="stat-icon" style="background:#dcfce7;color:#15803d;">
                            <i class="fa-solid fa-user-graduate"></i>
                        </div>
                        <div>
                            <div class="stat-val" id="kpiEnrolled">0</div>
                            <div class="stat-lbl">Enrolled Students</div>
                        </div>
                    </div>
                </div>

                <!-- 2. Admission Pipeline Funnel Tabs -->
                
            <!-- ══════ AI Admission Review (local Ollama) ══════ -->
            <section class="air-section" id="airSection" aria-label="AI Admission Review">
                <div class="air-section-hdr">
                    <div>
                        <h2><i class="fa-solid fa-brain"></i> AI Admission Review</h2>
                        <p>Select stored inbound WhatsApp messages (or paste text) → analyze with <strong>local Ollama only</strong> → edit the draft → mark Reviewed → Send. Nothing is sent automatically.</p>
                    </div>
                    <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end;">
                        <span class="air-status-pill" id="airAiStatus">Checking local AI…</span>
                        <span class="air-status-pill warn" id="airBridgeStatus">WhatsApp link…</span>
                        <button type="button" class="btn-air" id="airBtnRefresh" style="border:1px solid #c7d2fe;background:#fff;color:#3730a3;border-radius:8px;padding:6px 10px;font-size:0.78rem;font-weight:600;cursor:pointer;">Refresh</button>
                    </div>
                </div>
                
                <div class="air-engine-bar">
                    <label for="airEngineSelect">Analysis engine</label>
                    <select id="airEngineSelect">
                        <option value="local" selected>Local (llama / Ollama) — default</option>
                        <option value="api">API (NVIDIA / OpenAI-compatible)</option>
                    </select>
                    <button type="button" class="btn-air" id="airBtnToggleSettings" style="border:1px solid #c7d2fe;background:#fff;color:#3730a3;border-radius:8px;padding:6px 10px;font-size:0.78rem;font-weight:600;cursor:pointer;">API settings</button>
                    <span class="air-key-status missing" id="airKeyStatus">API key: Not configured</span>
                </div>
                <div class="air-settings" id="airSettingsPanel" style="display:none;">
                    <h4>API analysis settings (admin)</h4>
                    <p style="margin:0 0 8px;font-size:0.78rem;color:#475569;">Keys are stored encrypted on the server and never shown again after Save. Connection Test uses a synthetic sample only.</p>
                    <div class="grid">
                        <div class="air-field"><label>Provider</label>
                            <select id="airProvider">
                                <option value="nvidia">NVIDIA NIM (OpenAI-compatible)</option>
                                <option value="openai_compatible">Generic OpenAI-compatible</option>
                            </select>
                        </div>
                        <div class="air-field"><label>Model ID (required for API — no default guessed)</label>
                            <input type="text" id="airApiModel" placeholder="e.g. provider/model-name" autocomplete="off">
                        </div>
                        <div class="air-field" style="grid-column:1/-1;"><label>Base URL (HTTPS only)</label>
                            <input type="url" id="airBaseUrl" placeholder="https://integrate.api.nvidia.com/v1" autocomplete="off">
                        </div>
                        <div class="air-field" style="grid-column:1/-1;"><label>API key (leave blank to keep existing)</label>
                            <input type="password" id="airApiKey" value="" placeholder="Paste key to set/replace" autocomplete="new-password">
                        </div>
                    </div>
                    <div class="air-disclosure" id="airDisclosure"></div>
                    <div class="air-actions">
                        <button type="button" class="btn-air primary" id="airBtnSaveSettings">Save settings</button>
                        <button type="button" class="btn-air" id="airBtnTestConn">Test connection</button>
                        <button type="button" class="btn-air danger" id="airBtnRemoveKey">Remove saved key</button>
                    </div>
                </div>

                <div class="air-body">
                    <div class="air-panel">
                        <h3>1. Incoming messages</h3>
                        <div class="air-context-box" id="airInboundNote">Checking stored inbound messages…</div>
                        <div class="air-chat-list" id="airChatList"><div class="air-empty">Loading…</div></div>
                        <h3 style="margin-top:12px;">Inbound messages in selected chat</h3>
                        <div class="air-context-box" id="airContextHint">Tick the messages that should be analyzed. Analyze sends only that text to the local model.</div>
                        <div class="air-msg-list" id="airMsgList"><div class="air-empty">Select a chat first</div></div>
                        <div class="air-manual" id="airManualWrap" style="display:none;">
                            <div class="air-field">
                                <label>Or paste parent text (manual analysis — phone/identity stays unknown)</label>
                                <textarea id="airManualText" placeholder="Paste the parent's message here if no inbound messages are stored yet…"></textarea>
                            </div>
                        </div>
                        <div class="air-actions">
                            <button type="button" class="btn-air primary" id="airBtnAnalyze">Analyze selected</button>
                        </div>
                    </div>
                    <div class="air-panel">
                        <h3>2. Drafts &amp; review</h3>
                        <div class="air-draft-list" id="airDraftList"><div class="air-empty">No drafts yet</div></div>
                        <div id="airDraftEmpty" class="air-empty" style="margin-top:10px;">Select or create a draft to edit.</div>
                        <div id="airDraftEditor" style="display:none;margin-top:10px;">
                            <div style="display:flex;gap:8px;align-items:center;margin-bottom:8px;">
                                <span class="air-badge" id="airClassBadge">—</span>
                                <span class="air-status-pill" id="airDraftStatus">draft</span>
                            </div>
                            <div class="air-explain" id="airExplain"></div>
                            <div class="air-context-box" id="airSourceMeta"></div>
                            <div class="air-field"><label>Parent / guardian</label><input id="airFParent" type="text"></div>
                            <div class="air-field"><label>Child name</label><input id="airFStudent" type="text"></div>
                            <div class="air-field"><label>Requested class</label><input id="airFClass" type="text"></div>
                            <div class="air-field"><label>Academic year</label><input id="airFYear" type="text"></div>
                            <div class="air-field"><label>Age / DOB</label><input id="airFAge" type="text"></div>
                            <div class="air-field"><label>Contact (from verified chat identity only)</label><input id="airFPhone" type="text" readonly></div>
                            <div class="air-field"><label>Question summary</label><textarea id="airFSummary"></textarea></div>
                            <div class="air-field"><label>Missing details (one per line)</label><textarea id="airFMissing"></textarea></div>
                            <div class="air-field"><label>Suggested / edited reply</label><textarea id="airFReply" style="min-height:120px;"></textarea></div>
                            <div class="air-actions">
                                <button type="button" class="btn-air" id="airBtnSave">Save draft</button>
                                <button type="button" class="btn-air" id="airBtnEnquiry">Create / update enquiry</button>
                                <button type="button" class="btn-air primary" id="airBtnReview">Mark reviewed</button>
                                <button type="button" class="btn-air primary" id="airBtnSend" disabled title="Mark Reviewed first">Send</button>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

                <div class="pipeline-funnel" id="pipelineFunnel">
                    <button type="button" class="funnel-pill <?= $fStatus==='ALL'?'active':'' ?>" data-status="ALL">
                        <span>All Enquiries</span>
                        <span class="funnel-badge" id="cnt-ALL">0</span>
                    </button>
                    <button type="button" class="funnel-pill <?= $fStatus==='NEW'?'active':'' ?>" data-status="NEW">
                        <span>⚪ NEW</span>
                        <span class="funnel-badge" id="cnt-NEW">0</span>
                    </button>
                    <button type="button" class="funnel-pill <?= $fStatus==='CONTACTED'?'active':'' ?>" data-status="CONTACTED">
                        <span>🟡 CONTACTED</span>
                        <span class="funnel-badge" id="cnt-CONTACTED">0</span>
                    </button>
                    <button type="button" class="funnel-pill <?= $fStatus==='INFORMATION_SHARED'?'active':'' ?>" data-status="INFORMATION_SHARED">
                        <span>🟣 INFO SHARED</span>
                        <span class="funnel-badge" id="cnt-INFORMATION_SHARED">0</span>
                    </button>
                    <button type="button" class="funnel-pill <?= $fStatus==='INTERESTED'?'active':'' ?>" data-status="INTERESTED">
                        <span>🟢 INTERESTED</span>
                        <span class="funnel-badge" id="cnt-INTERESTED">0</span>
                    </button>
                    <button type="button" class="funnel-pill <?= $fStatus==='VISIT_PLANNED'?'active':'' ?>" data-status="VISIT_PLANNED">
                        <span>🔵 VISIT PLANNED</span>
                        <span class="funnel-badge" id="cnt-VISIT_PLANNED">0</span>
                    </button>
                    <button type="button" class="funnel-pill <?= $fStatus==='DOCUMENTS_PENDING'?'active':'' ?>" data-status="DOCUMENTS_PENDING">
                        <span>🟠 DOCS PENDING</span>
                        <span class="funnel-badge" id="cnt-DOCUMENTS_PENDING">0</span>
                    </button>
                    <button type="button" class="funnel-pill <?= $fStatus==='APPLICATION_SUBMITTED'?'active':'' ?>" data-status="APPLICATION_SUBMITTED">
                        <span>🔷 APP SUBMITTED</span>
                        <span class="funnel-badge" id="cnt-APPLICATION_SUBMITTED">0</span>
                    </button>
                    <button type="button" class="funnel-pill <?= $fStatus==='ENROLLED'?'active':'' ?>" data-status="ENROLLED">
                        <span>🎉 ENROLLED</span>
                        <span class="funnel-badge" id="cnt-ENROLLED">0</span>
                    </button>
                    <button type="button" class="funnel-pill <?= $fStatus==='CLOSED'?'active':'' ?>" data-status="CLOSED">
                        <span>📁 CLOSED</span>
                        <span class="funnel-badge" id="cnt-CLOSED">0</span>
                    </button>
                </div>

                <!-- 3. Enquiries Table Panel -->
                <div class="enquiry-panel">
                    <div class="enquiry-toolbar">
                        <div class="toolbar-filters">
                            <select id="filterYear">
                                <option value="">All Academic Years</option>
                                <?php foreach ($years as $y): ?>
                                <option value="<?= sanitize($y) ?>" <?= $fYear===$y?'selected':'' ?>><?= sanitize($y) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <select id="filterClass">
                                <option value="">All Classes / Grades</option>
                                <?php foreach ($standardClasses as $c): ?>
                                <option value="<?= sanitize($c) ?>" <?= $fClass===$c?'selected':'' ?>><?= sanitize($c) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <select id="filterStaff">
                                <option value="0">All Assigned Staff</option>
                                <?php foreach ($staffUsers as $su): ?>
                                <option value="<?= $su['id'] ?>" <?= $fStaff===(int)$su['id']?'selected':'' ?>><?= sanitize($su['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>

                            <input type="text" id="filterSearch" placeholder="Search parent, student, phone…" value="<?= e_attr($search) ?>" style="min-width:220px;">
                            
                            <button type="button" class="btn-primary" id="btnApplyFilter" style="padding:7px 14px;font-size:0.82rem;">
                                <i class="fa-solid fa-filter"></i> Filter
                            </button>
                            <button type="button" class="btn-secondary" id="btnResetFilter" style="padding:7px 12px;font-size:0.82rem;">
                                Reset
                            </button>
                        </div>
                    </div>

                    <div style="overflow-x:auto;">
                        <table class="enquiry-table" id="enquiriesTable">
                            <thead>
                                <tr>
                                    <th style="width:70px;">Ref #</th>
                                    <th>Parent / WhatsApp</th>
                                    <th>Student &amp; Class</th>
                                    <th>Pipeline Status</th>
                                    <th>Query / Latest Message</th>
                                    <th>Assigned Staff</th>
                                    <th>Latest Activity</th>
                                    <th style="text-align:right;">Actions</th>
                                </tr>
                            </thead>
                            <tbody id="enquiriesTableBody">
                                <tr>
                                    <td colspan="8" style="text-align:center;padding:40px;color:var(--text-2);">
                                        <i class="fa-solid fa-spinner fa-spin" style="font-size:1.8rem;margin-bottom:10px;display:block;"></i>
                                        Loading admission enquiries...
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:#f8fafc;" id="paginationBar">
                        <span id="pageInfoText" style="font-size:0.82rem;color:var(--text-2);">Showing 0 to 0 of 0 enquiries</span>
                        <div style="display:flex;gap:6px;" id="paginationBtns"></div>
                    </div>
                </div>
            </div>
        </main>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <!-- MODAL 1: NEW ENQUIRY CAPTURE (From WhatsApp)                          -->
    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <div class="modal-backdrop" id="modalNewEnquiry">
        <div class="modal-card">
            <div class="modal-head">
                <div class="modal-title">
                    <i class="fa-brands fa-whatsapp" style="font-size:1.3rem;"></i>
                    <span>Capture New WhatsApp Admission Enquiry</span>
                </div>
                <button type="button" class="modal-close-btn js-close-modal">&times;</button>
            </div>
            <form id="formNewEnquiry" onsubmit="return false;">
                <?= csrfField() ?>
                <div class="modal-body">
                    <!-- Real-time Duplicate / Reused Record Alerts -->
                    <div class="alert-box alert-warning" id="alertDupWarning"></div>
                    <div class="alert-box alert-info" id="alertRecordMatched"></div>
                    <div class="alert-box alert-danger" id="alertDupBlocked"></div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Parent Name <span style="color:#ef4444;">*</span></label>
                            <input type="text" name="parent_name" id="inpParentName" placeholder="e.g. Ramesh Sharma" required>
                        </div>
                        <div class="form-group">
                            <label>WhatsApp Phone Number <span style="color:#ef4444;">*</span></label>
                            <input type="tel" name="phone_number" id="inpPhoneNumber" placeholder="e.g. +91 98765 43210" required>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Student Name (Optional)</label>
                            <input type="text" name="student_name" id="inpStudentName" placeholder="e.g. Akash Sharma">
                        </div>
                        <div class="form-group">
                            <label>Interested Class / Grade <span style="color:#ef4444;">*</span></label>
                            <select name="interested_class" id="inpInterestedClass" required>
                                <option value="">Select Grade</option>
                                <?php foreach ($standardClasses as $sc): ?>
                                <option value="<?= sanitize($sc) ?>"><?= sanitize($sc) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Academic Year <span style="color:#ef4444;">*</span></label>
                            <select name="academic_year" id="inpAcademicYear" required>
                                <?php foreach ($years as $y): ?>
                                <option value="<?= sanitize($y) ?>" <?= $y==='2025-26'?'selected':'' ?>><?= sanitize($y) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Enquiry Source</label>
                            <select name="enquiry_source" id="inpEnquirySource">
                                <option value="whatsapp" selected>WhatsApp Message</option>
                                <option value="walk_in">School Walk-in</option>
                                <option value="phone_call">Phone Call</option>
                                <option value="website">School Website</option>
                                <option value="referral">Parent Referral</option>
                                <option value="social_media">Social Media</option>
                                <option value="other">Other</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group form-col-full">
                            <label>Parent's Original Message (Preserved &amp; Immutable) <span style="color:#ef4444;">*</span></label>
                            <textarea name="original_message" id="inpOriginalMessage" class="msg-paste-box" placeholder="Paste the original incoming WhatsApp message from the parent here..." required></textarea>
                            <span style="font-size:0.73rem;color:var(--text-2);margin-top:2px;">
                                <i class="fa-solid fa-lock" style="font-size:0.68rem;"></i> This message will be preserved in the persistent conversation record and synced to WhatsApp Hub.
                            </span>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label>Assigned Staff Member</label>
                            <select name="assigned_staff_id" id="inpAssignedStaff">
                                <?php foreach ($staffUsers as $su): ?>
                                <option value="<?= $su['id'] ?>" <?= (int)$su['id']===$adminId?'selected':'' ?>><?= sanitize($su['full_name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="form-group">
                            <label>Staff Internal Notes</label>
                            <input type="text" name="notes" id="inpNotes" placeholder="e.g. Parent prefers morning bus route">
                        </div>
                    </div>
                </div>

                <div class="modal-foot">
                    <button type="button" class="btn-secondary js-close-modal">Cancel</button>
                    <button type="submit" class="btn-primary" id="btnSubmitEnquiry" style="background:#00a884;border-color:#00a884;">
                        <i class="fa-solid fa-check"></i> Save Enquiry &amp; Open Chat
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <!-- MODAL 2: PERSISTENT CONVERSATION & TIMELINE VIEW                       -->
    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <div class="modal-backdrop" id="modalConversationView">
        <div class="modal-card modal-lg">
            <div class="modal-head">
                <div class="modal-title">
                    <i class="fa-solid fa-comments"></i>
                    <span id="cvParentTitle">Admission Enquiry Conversation</span>
                </div>
                <button type="button" class="modal-close-btn js-close-modal">&times;</button>
            </div>
            <div class="modal-body" style="background:#f8fafc;">
                <!-- Parent & Enquiry Meta Overview Box -->
                <div style="background:#fff;border:1px solid var(--border);border-radius:10px;padding:16px 20px;margin-bottom:18px;">
                    <div style="display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:12px;">
                        <div>
                            <h3 id="cvStudentName" style="margin:0 0 4px 0;font-size:1.15rem;color:var(--ink);">Student Name</h3>
                            <div style="font-size:0.83rem;color:var(--text-2);display:flex;gap:14px;flex-wrap:wrap;">
                                <span><i class="fa-solid fa-user"></i> <strong id="cvParentName">—</strong></span>
                                <span><i class="fa-brands fa-whatsapp" style="color:#25d366;"></i> <span id="cvParentPhone">—</span></span>
                                <span><i class="fa-solid fa-graduation-cap"></i> <span id="cvClassYear">—</span></span>
                                <span><i class="fa-solid fa-user-tie"></i> Assigned: <span id="cvAssignedStaff">—</span></span>
                            </div>
                            <div id="cvReusedBadge" style="margin-top:6px;"></div>
                        </div>
                        <div style="display:flex;align-items:center;gap:10px;">
                            <span class="status-badge" id="cvStatusBadge">NEW</span>
                            <a href="#" class="btn-table btn-wa-chat" id="cvBtnOpenWa" target="_blank">
                                <i class="fa-brands fa-whatsapp"></i> Chat in Hub
                            </a>
                        </div>
                    </div>

                    <!-- Pipeline Status Fast-Update -->
                    <div style="margin-top:14px;padding-top:12px;border-top:1px solid #f1f5f9;display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <span style="font-size:0.75rem;font-weight:700;color:var(--text-2);text-transform:uppercase;">Update Pipeline:</span>
                        <select id="cvStatusSelect" style="padding:5px 10px;border-radius:6px;border:1px solid var(--border);font-size:0.82rem;font-weight:600;">
                            <option value="NEW">NEW</option>
                            <option value="CONTACTED">CONTACTED</option>
                            <option value="INFORMATION_SHARED">INFORMATION_SHARED</option>
                            <option value="INTERESTED">INTERESTED</option>
                            <option value="VISIT_PLANNED">VISIT_PLANNED</option>
                            <option value="DOCUMENTS_PENDING">DOCUMENTS_PENDING</option>
                            <option value="APPLICATION_SUBMITTED">APPLICATION_SUBMITTED</option>
                            <option value="ENROLLED">ENROLLED</option>
                            <option value="CLOSED">CLOSED</option>
                        </select>
                        <input type="text" id="cvStatusNote" placeholder="Reason / transition note…" style="padding:5px 10px;border-radius:6px;border:1px solid var(--border);font-size:0.82rem;flex:1;min-width:180px;">
                        <button type="button" class="btn-primary" id="btnUpdateStatusSubmit" style="padding:6px 14px;font-size:0.8rem;">
                            Update Status
                        </button>
                    </div>
                </div>

                <!-- AI Draft Generation & Response Workspace -->
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:16px 20px;margin-bottom:18px;">
                    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px;">
                        <h4 style="margin:0;font-size:0.92rem;font-weight:700;color:var(--ink);display:flex;align-items:center;gap:8px;">
                            <span style="color:#8b5cf6;"><i class="fa-solid fa-wand-magic-sparkles"></i></span> AI Response Assistant
                        </h4>
                        <button type="button" class="btn-table" id="btnGenerateAiDraft" style="color:#7c3aed;border-color:#ddd6fe;background:#f5f3ff;">
                            <i class="fa-solid fa-sparkles"></i> Generate AI Draft
                        </button>
                    </div>
                    <textarea id="aiDraftEditor" style="width:100%;min-height:95px;padding:10px 12px;border:1px solid var(--border);border-radius:6px;font-size:0.85rem;line-height:1.45;box-sizing:border-box;font-family:inherit;" placeholder="Click 'Generate AI Draft' to produce a personalized admission response, or draft manually..."></textarea>
                    <div id="aiDraftMeta" style="display:none;margin-top:8px;padding:8px 10px;border-radius:6px;background:#f8fafc;border:1px solid #e2e8f0;font-size:0.78rem;color:#334155;line-height:1.45;"></div>
                    <div style="display:flex;justify-content:flex-end;gap:8px;margin-top:8px;flex-wrap:wrap;">
                        <button type="button" class="btn-secondary" id="btnSaveStaffEdit" style="font-size:0.8rem;padding:6px 12px;">
                            <i class="fa-solid fa-pen"></i> Save Staff Edit
                        </button>
                        <button type="button" class="btn-primary" id="btnApproveResponse" style="font-size:0.8rem;padding:6px 14px;background:#059669;border-color:#059669;">
                            <i class="fa-solid fa-check-double"></i> Approve Response
                        </button>
                        <button type="button" class="btn-primary" id="btnOpenWhatsApp" style="font-size:0.8rem;padding:6px 14px;background:#128C7E;border-color:#128C7E;">
                            <i class="fa-brands fa-whatsapp"></i> Open WhatsApp
                        </button>
                        <button type="button" class="btn-secondary" id="btnConfirmManualSend" style="font-size:0.8rem;padding:6px 12px;">
                            <i class="fa-solid fa-circle-check"></i> Mark manually confirmed sent
                        </button>
                    </div>
                    <p id="sendDisclaimer" style="margin:8px 0 0;font-size:0.75rem;color:#64748b;">Opening WhatsApp prefills the chat. You must press Send yourself. A manual confirmation is not a delivery or read receipt.</p>
                </div>

                <!-- Follow-up Logger -->
                <div style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:14px 20px;margin-bottom:18px;">
                    <h4 style="margin:0 0 10px 0;font-size:0.88rem;font-weight:700;color:var(--ink);display:flex;align-items:center;gap:6px;">
                        <i class="fa-solid fa-phone-volume" style="color:#f59e0b;"></i> Log Follow-Up / Campus Visit / Note
                    </h4>
                    <div style="display:flex;gap:10px;flex-wrap:wrap;">
                        <input type="text" id="inpFollowupNote" placeholder="e.g. Called parent, campus tour scheduled for Thursday 11 AM" style="flex:1;padding:7px 12px;border:1px solid var(--border);border-radius:6px;font-size:0.84rem;">
                        <input type="datetime-local" id="inpFollowupDate" style="padding:7px 10px;border:1px solid var(--border);border-radius:6px;font-size:0.82rem;">
                        <button type="button" class="btn-primary" id="btnAddFollowup" style="padding:7px 14px;font-size:0.82rem;background:#d97706;border-color:#d97706;">
                            <i class="fa-solid fa-plus"></i> Log Follow-Up
                        </button>
                    </div>
                </div>

                <!-- Persistent Conversation Timeline Stream -->
                <h4 style="margin:0 0 10px 4px;font-size:0.88rem;font-weight:700;color:var(--text-2);text-transform:uppercase;letter-spacing:0.5px;">
                    Persistent Conversation &amp; Follow-up History
                </h4>
                <div class="timeline-stream" id="cvTimelineStream">
                    <!-- Dynamic timeline items render here -->
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn-secondary js-close-modal">Close</button>
            </div>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <!-- MODAL 3: CONFIGURABLE DUPLICATE MATCHING RULES (Admin Only)            -->
    <!-- ══════════════════════════════════════════════════════════════════════ -->
    <?php if ($adminRole === 'admin'): ?>
    <div class="modal-backdrop" id="modalRules">
        <div class="modal-card">
            <div class="modal-head" style="background:#1e293b;">
                <div class="modal-title">
                    <i class="fa-solid fa-sliders"></i>
                    <span>Configurable Duplicate Matching Rules</span>
                </div>
                <button type="button" class="modal-close-btn js-close-modal">&times;</button>
            </div>
            <div class="modal-body">
                <p style="font-size:0.85rem;color:var(--text-2);margin-top:0;margin-bottom:16px;">
                    Define how the admission system detects duplicate enquiries across WhatsApp and phone records.
                </p>
                <div id="rulesContainer" style="display:flex;flex-direction:column;gap:14px;">
                    <!-- Rendered dynamically -->
                </div>
            </div>
            <div class="modal-foot">
                <button type="button" class="btn-secondary js-close-modal">Cancel</button>
                <button type="button" class="btn-primary" id="btnSaveRules">
                    <i class="fa-solid fa-check"></i> Save Rules Configuration
                </button>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script src="../assets/js/admin.js?v=<?= is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 1 ?>"></script>
    <script>
    (function() {
        const CSRF_TOKEN = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        let currentEnquiryId = null;
        let activeStatusFilter = <?= json_encode($fStatus, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
        let currentPage = 1;
        let debounceTimer = null;

        // ── Helper: Format Date (Robust Cross-Browser & Mobile ISO Parsing) ──
        function formatDate(dtStr) {
            if (!dtStr) return '—';
            const s = String(dtStr).trim();
            const iso = s.includes('T') ? s : s.replace(' ', 'T');
            const d = new Date(iso);
            if (isNaN(d.getTime())) return dtStr;
            return d.toLocaleDateString('en-IN', { day:'numeric', month:'short', year:'numeric', hour:'2-digit', minute:'2-digit', hour12:true });
        }

        function escapeHtml(str) {
            if (!str) return '';
            return String(str)
                .replace(/&/g, '&amp;')
                .replace(/</g, '&lt;')
                .replace(/>/g, '&gt;')
                .replace(/"/g, '&quot;')
                .replace(/'/g, '&#039;');
        }

        // ── Helper: Modern Floating Toast Notifications ───────────────────────
        function showToast(message, type = 'info') {
            if (!message) return;
            let container = document.getElementById('aeToastContainer');
            if (!container) {
                container = document.createElement('div');
                container.id = 'aeToastContainer';
                container.className = 'ae-toast-container';
                document.body.appendChild(container);
            }
            const toast = document.createElement('div');
            toast.className = `ae-toast ae-toast-${type}`;
            const iconMap = {
                success: 'fa-circle-check',
                error: 'fa-circle-exclamation',
                danger: 'fa-circle-exclamation',
                warning: 'fa-triangle-exclamation',
                info: 'fa-circle-info'
            };
            const icon = iconMap[type] || 'fa-circle-info';
            toast.innerHTML = `
                <i class="fa-solid ${icon}"></i>
                <div class="ae-toast-msg">${escapeHtml(message)}</div>
                <button type="button" class="ae-toast-close" aria-label="Close">&times;</button>
            `;
            container.appendChild(toast);
            const removeToast = () => {
                toast.classList.add('ae-toast-hiding');
                setTimeout(() => toast.remove(), 220);
            };
            toast.querySelector('.ae-toast-close').addEventListener('click', removeToast);
            setTimeout(removeToast, 4500);
        }

        // ── Helper: Custom Confirmation Modal (window.hccConfirm) ─────────────
        async function requestConfirm(options) {
            if (typeof window.hccConfirm === 'function') {
                return await window.hccConfirm(options);
            }
            const msg = typeof options === 'string' ? options : (options.message || 'Are you sure you want to proceed?');
            return Promise.resolve(window.confirm(msg));
        }

        // ── Fetch & Render Enquiries Table ────────────────────────────────────
        async function loadEnquiries(page = 1) {
            currentPage = page;
            const year = document.getElementById('filterYear').value;
            const classVal = document.getElementById('filterClass').value;
            const staffId = document.getElementById('filterStaff').value;
            const search = document.getElementById('filterSearch').value;

            const url = `api_admission_enquiry.php?action=list&page=${page}&status=${encodeURIComponent(activeStatusFilter)}&academic_year=${encodeURIComponent(year)}&interested_class=${encodeURIComponent(classVal)}&assigned_staff_id=${staffId}&search=${encodeURIComponent(search)}`;

            try {
                const res = await fetch(url);
                const data = await res.json();
                if (!data.success) {
                    showToast('Error: ' + (data.error || 'Failed to load enquiries'), 'error');
                    return;
                }

                // Render KPI Stats
                const sc = data.status_counts || {};
                document.getElementById('kpiTotal').textContent = sc.ALL || 0;
                const activeCount = (sc.NEW||0) + (sc.CONTACTED||0) + (sc.INFORMATION_SHARED||0) + (sc.INTERESTED||0);
                document.getElementById('kpiActive').textContent = activeCount;
                document.getElementById('kpiVisits').textContent = (sc.VISIT_PLANNED||0) + (sc.APPLICATION_SUBMITTED||0);
                document.getElementById('kpiEnrolled').textContent = sc.ENROLLED || 0;

                // Update Funnel Badges
                for (const [k, v] of Object.entries(sc)) {
                    const el = document.getElementById(`cnt-${k}`);
                    if (el) el.textContent = v;
                }

                // Render Table Rows
                const tbody = document.getElementById('enquiriesTableBody');
                if (!data.data || data.data.length === 0) {
                    tbody.innerHTML = `
                        <tr>
                            <td colspan="8" style="text-align:center;padding:50px 20px;color:var(--text-2);">
                                <i class="fa-solid fa-folder-open" style="font-size:2.4rem;opacity:0.4;display:block;margin-bottom:12px;"></i>
                                No admission enquiries found matching the criteria.
                            </td>
                        </tr>
                    `;
                } else {
                    tbody.innerHTML = data.data.map(item => {
                        const statusClass = `status-${item.status}`;
                        const origMsg = item.original_message || '';
                        const lastMsg = item.last_message_body || origMsg;
                        const origMsgPreview = escapeHtml(origMsg.substring(0, 60)) + (origMsg.length > 60 ? '…' : '');
                        const lastMsgPreview = escapeHtml(lastMsg.substring(0, 60)) + (lastMsg.length > 60 ? '…' : '');
                        const isLastIncoming = item.last_message_type === 'incoming_message';
                        const reusedBadge = item.matched_student_roll ? `
                            <div class="reused-badge" title="Reused existing record: Roll #${escapeHtml(item.matched_student_roll)} (${escapeHtml(item.matched_student_class || '')})">
                                <i class="fa-solid fa-link"></i> Roll #${escapeHtml(item.matched_student_roll)}
                            </div>
                        ` : '';

                        const msgCountBadge = (item.message_count > 1) ? `
                            <span style="display:inline-block;background:#e0f2fe;color:#0369a1;font-size:0.68rem;font-weight:700;padding:1px 6px;border-radius:999px;margin-left:4px;" title="${item.message_count} messages in conversation">
                                <i class="fa-solid fa-comments" style="font-size:0.65rem;"></i> ${item.message_count}
                            </span>
                        ` : '';

                        return `
                            <tr>
                                <td><strong>#${item.id}</strong></td>
                                <td>
                                    <div style="font-weight:700;color:var(--ink);">${escapeHtml(item.parent_name)}${msgCountBadge}</div>
                                    <div style="font-size:0.75rem;color:var(--text-2);margin-top:2px;">
                                        <i class="fa-brands fa-whatsapp" style="color:#25d366;"></i> ${escapeHtml(item.phone_number)}
                                    </div>
                                </td>
                                <td>
                                    <div style="font-weight:600;color:#0f172a;">${escapeHtml(item.student_name || '—')}</div>
                                    <div class="student-sub">
                                        <strong>${escapeHtml(item.interested_class)}</strong> · ${escapeHtml(item.academic_year)}
                                    </div>
                                    ${reusedBadge}
                                </td>
                                <td>
                                    <span class="status-badge ${statusClass}">${escapeHtml(item.status)}</span>
                                </td>
                                <td>
                                    <div style="color:#334155;font-size:0.8rem;line-height:1.4;" title="${escapeHtml(lastMsg)}">
                                        ${lastMsg !== origMsg ? `<span style="font-size:0.68rem;font-weight:700;color:${isLastIncoming ? '#059669' : '#0284c7'};background:${isLastIncoming ? '#d1fae5' : '#e0f2fe'};padding:1px 5px;border-radius:4px;margin-right:4px;">${isLastIncoming ? 'INCOMING' : 'LATEST'}</span>` : ''}
                                        <span>${lastMsgPreview || '<em style="color:#94a3b8;">No message text</em>'}</span>
                                    </div>
                                    ${origMsg && origMsg !== lastMsg ? `
                                        <div style="font-size:0.72rem;color:var(--text-2);margin-top:2px;" title="Original Query: ${escapeHtml(origMsg)}">
                                            <span style="opacity:0.75;">Orig:</span> ${origMsgPreview}
                                        </div>
                                    ` : ''}
                                </td>
                                <td>
                                    <span style="font-size:0.8rem;color:#334155;">${escapeHtml(item.assigned_staff_name || 'Unassigned')}</span>
                                </td>
                                <td>
                                    <div style="font-size:0.75rem;font-weight:600;color:#0f172a;">${formatDate(item.last_interaction_at || item.updated_at || item.created_at)}</div>
                                    ${item.last_interaction_at && item.created_at && item.last_interaction_at !== item.created_at ? `
                                        <div style="font-size:0.68rem;color:var(--text-2);margin-top:2px;" title="Registered: ${formatDate(item.created_at)}">
                                            Created: ${formatDate(item.created_at)}
                                        </div>
                                    ` : ''}
                                </td>
                                <td style="text-align:right;white-space:nowrap;">
                                    <button type="button" class="btn-table btn-view-enquiry" data-id="${item.id}" title="View persistent conversation timeline">
                                        <i class="fa-solid fa-comments"></i> Details
                                    </button>
                                    <a href="whatsapp_share.php?view=replica&chat_id=${item.chat_id || ''}" class="btn-table btn-wa-chat" title="Open chat in WhatsApp Hub" target="_blank">
                                        <i class="fa-brands fa-whatsapp"></i> Chat
                                    </a>
                                </td>
                            </tr>
                        `;
                    }).join('');
                }

                // Render Pagination
                const pag = data.pagination || {};
                const from = pag.total > 0 ? (pag.page - 1) * pag.per_page + 1 : 0;
                const to = Math.min(pag.page * pag.per_page, pag.total);
                document.getElementById('pageInfoText').textContent = `Showing ${from} to ${to} of ${pag.total} enquiries`;

                const btnsWrap = document.getElementById('paginationBtns');
                btnsWrap.innerHTML = '';
                if (pag.total_pages > 1) {
                    if (pag.page > 1) {
                        btnsWrap.innerHTML += `<button type="button" class="btn-table js-page-btn" data-page="${pag.page - 1}">« Prev</button>`;
                    }
                    for (let p = Math.max(1, pag.page - 2); p <= Math.min(pag.total_pages, pag.page + 2); p++) {
                        btnsWrap.innerHTML += `<button type="button" class="btn-table js-page-btn ${p === pag.page ? 'btn-primary' : ''}" data-page="${p}">${p}</button>`;
                    }
                    if (pag.page < pag.total_pages) {
                        btnsWrap.innerHTML += `<button type="button" class="btn-table js-page-btn" data-page="${pag.page + 1}">Next »</button>`;
                    }
                }
            } catch (err) {
                console.error('loadEnquiries error:', err);
            }
        }

        // ── Funnel Filter Clicks ──────────────────────────────────────────────
        document.getElementById('pipelineFunnel').addEventListener('click', (e) => {
            const btn = e.target.closest('.funnel-pill');
            if (!btn) return;
            document.querySelectorAll('.funnel-pill').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            activeStatusFilter = btn.getAttribute('data-status') || 'ALL';
            loadEnquiries(1);
        });

        document.getElementById('btnApplyFilter').addEventListener('click', () => loadEnquiries(1));
        document.getElementById('btnResetFilter').addEventListener('click', () => {
            document.getElementById('filterYear').value = '';
            document.getElementById('filterClass').value = '';
            document.getElementById('filterStaff').value = '0';
            document.getElementById('filterSearch').value = '';
            loadEnquiries(1);
        });

        document.getElementById('paginationBtns').addEventListener('click', (e) => {
            const btn = e.target.closest('.js-page-btn');
            if (!btn) return;
            const p = parseInt(btn.getAttribute('data-page'), 10);
            if (p) loadEnquiries(p);
        });

        // ── Open / Close Modals ───────────────────────────────────────────────
        function openModal(id) {
            document.getElementById(id)?.classList.add('open');
        }
        function closeModal(id) {
            document.getElementById(id)?.classList.remove('open');
        }
        document.querySelectorAll('.js-close-modal').forEach(btn => {
            btn.addEventListener('click', (e) => {
                const modal = e.target.closest('.modal-backdrop');
                if (modal) modal.classList.remove('open');
            });
        });

        // ── New Enquiry Capture & Live Duplicate Check ────────────────────────
        document.getElementById('btnOpenNewEnquiry').addEventListener('click', () => {
            document.getElementById('formNewEnquiry').reset();
            document.getElementById('alertDupWarning').style.display = 'none';
            document.getElementById('alertRecordMatched').style.display = 'none';
            document.getElementById('alertDupBlocked').style.display = 'none';
            openModal('modalNewEnquiry');
        });

        async function triggerLiveDuplicateCheck() {
            const phone = document.getElementById('inpPhoneNumber').value.trim();
            const student = document.getElementById('inpStudentName').value.trim();
            const year = document.getElementById('inpAcademicYear').value;
            const cls = document.getElementById('inpInterestedClass').value;

            if (phone.replace(/\D/g, '').length < 10) return;

            try {
                const url = `api_admission_enquiry.php?action=check_duplicates&phone=${encodeURIComponent(phone)}&student_name=${encodeURIComponent(student)}&academic_year=${encodeURIComponent(year)}&interested_class=${encodeURIComponent(cls)}`;
                const res = await fetch(url);
                const data = await res.json();
                if (!data.success) return;

                const dup = data.data;
                const warnBox = document.getElementById('alertDupWarning');
                const blockBox = document.getElementById('alertDupBlocked');
                const matchBox = document.getElementById('alertRecordMatched');

                // Block alert
                if (dup.is_blocked) {
                    blockBox.innerHTML = `<strong>⚠️ Duplicate Blocked:</strong> ${dup.block_reasons.map(escapeHtml).join('<br>')}`;
                    blockBox.style.display = 'block';
                    document.getElementById('btnSubmitEnquiry').disabled = true;
                } else {
                    blockBox.style.display = 'none';
                    document.getElementById('btnSubmitEnquiry').disabled = false;
                }

                // Warn alert
                if (dup.is_warned && !dup.is_blocked) {
                    warnBox.innerHTML = `<strong>ℹ️ Potential Duplicate Notice:</strong> ${dup.warn_reasons.map(escapeHtml).join('<br>')}<br><small>You may still proceed to record this enquiry if it is a fresh request.</small>`;
                    warnBox.style.display = 'block';
                } else {
                    warnBox.style.display = 'none';
                }

                // Reused student/parent record match
                if (dup.matched_records && dup.matched_records.length > 0) {
                    const first = dup.matched_records[0];
                    matchBox.innerHTML = `<strong>✨ Existing School Record Matched:</strong> ${escapeHtml(first.label)}<br><small>This enquiry will be automatically linked to the existing student/parent profile.</small>`;
                    matchBox.style.display = 'block';
                } else {
                    matchBox.style.display = 'none';
                }
            } catch (err) {}
        }

        ['inpPhoneNumber', 'inpStudentName', 'inpAcademicYear', 'inpInterestedClass'].forEach(id => {
            document.getElementById(id)?.addEventListener('input', () => {
                clearTimeout(debounceTimer);
                debounceTimer = setTimeout(triggerLiveDuplicateCheck, 400);
            });
        });

        // ── Submit New Enquiry ────────────────────────────────────────────────
        document.getElementById('formNewEnquiry').addEventListener('submit', async (e) => {
            e.preventDefault();
            const btn = document.getElementById('btnSubmitEnquiry');

            // If a duplicate notice is active, prompt with custom confirmation dialog
            const warnBox = document.getElementById('alertDupWarning');
            if (warnBox && warnBox.style.display === 'block') {
                const proceed = await requestConfirm({
                    title: 'Potential Duplicate Warning',
                    message: 'This enquiry matches an existing school record or contact. Do you want to proceed and save this enquiry anyway?',
                    confirmText: 'Proceed & Save',
                    cancelText: 'Review Form',
                    type: 'warning',
                    icon: 'fa-triangle-exclamation'
                });
                if (!proceed) return;
            }

            btn.disabled = true;
            btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Saving...`;

            const payload = {
                action: 'create',
                parent_name: document.getElementById('inpParentName').value.trim(),
                phone_number: document.getElementById('inpPhoneNumber').value.trim(),
                student_name: document.getElementById('inpStudentName').value.trim(),
                interested_class: document.getElementById('inpInterestedClass').value,
                academic_year: document.getElementById('inpAcademicYear').value,
                original_message: document.getElementById('inpOriginalMessage').value.trim(),
                enquiry_source: document.getElementById('inpEnquirySource').value,
                assigned_staff_id: document.getElementById('inpAssignedStaff').value,
                notes: document.getElementById('inpNotes').value.trim(),
                confirm_duplicate: document.getElementById('alertDupWarning')?.style.display === 'block'
            };

            try {
                const res = await fetch('api_admission_enquiry.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: JSON.stringify(payload)
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Admission enquiry captured successfully!', 'success');
                    closeModal('modalNewEnquiry');
                    loadEnquiries(1);
                    // Open detailed conversation modal for newly created enquiry
                    if (data.enquiry_id) {
                        openEnquiryTimeline(data.enquiry_id);
                    }
                } else {
                    showToast(data.error || 'Failed to save enquiry.', 'error');
                }
            } catch (err) {
                showToast('Network or server error while saving enquiry.', 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = `<i class="fa-solid fa-check"></i> Save Enquiry &amp; Open Chat`;
            }
        });

        // ── Open Persistent Conversation Timeline ─────────────────────────────
        let currentTimelineSig = '';

        async function openEnquiryTimeline(id) {
            currentEnquiryId = id;
            currentTimelineSig = '';
            try {
                const res = await fetch(`api_admission_enquiry.php?action=get&id=${id}`);
                const data = await res.json();
                if (!data.success) {
                    showToast('Error loading enquiry details.', 'error');
                    return;
                }

                const enq = data.data;
                document.getElementById('cvParentTitle').textContent = `Enquiry #${enq.id} — ${enq.parent_name}`;
                document.getElementById('cvStudentName').textContent = enq.student_name ? `${enq.student_name} (${enq.interested_class})` : `Enquiry for ${enq.interested_class}`;
                document.getElementById('cvParentName').textContent = enq.parent_name;
                document.getElementById('cvParentPhone').textContent = enq.phone_number;
                document.getElementById('cvClassYear').textContent = `${enq.interested_class} · ${enq.academic_year}`;
                document.getElementById('cvAssignedStaff').textContent = enq.assigned_staff_name || 'Unassigned';

                const badge = document.getElementById('cvStatusBadge');
                badge.className = `status-badge status-${enq.status}`;
                badge.textContent = enq.status;

                document.getElementById('cvStatusSelect').value = enq.status;
                document.getElementById('cvStatusNote').value = '';
                document.getElementById('cvBtnOpenWa').href = `whatsapp_share.php?view=replica&chat_id=${enq.chat_id || ''}`;

                // Reused badge
                const reusedWrap = document.getElementById('cvReusedBadge');
                if (enq.matched_student_roll) {
                    reusedWrap.innerHTML = `
                        <span class="reused-badge">
                            <i class="fa-solid fa-link"></i> Reused Student Record: Roll #${escapeHtml(enq.matched_student_roll)} (${escapeHtml(enq.matched_student_class || '')})
                        </span>
                    `;
                } else {
                    reusedWrap.innerHTML = '';
                }

                // Render Timeline
                currentTimelineSig = `${(data.timeline || []).length}:${(data.timeline || []).map(t => t.id + ':' + (t.created_at || '')).join(',')}`;
                renderTimelineStream(data.timeline || [], enq);
                openModal('modalConversationView');
            } catch (err) {
                console.error('openEnquiryTimeline error:', err);
            }
        }

        function renderTimelineStream(timeline, enq) {
            const wrap = document.getElementById('cvTimelineStream');
            if (timeline.length === 0) {
                wrap.innerHTML = `<div style="text-align:center;padding:20px;color:var(--text-2);">No conversation history yet.</div>`;
                return;
            }

            const iconMap = {
                'incoming_message': { class: 'tl-incoming', icon: 'fa-whatsapp fa-brands', title: 'Parent Incoming WhatsApp Message' },
                'outgoing_message': { class: 'tl-approved', icon: 'fa-paper-plane fa-solid', title: 'School Outgoing WhatsApp Message' },
                'ai_draft':         { class: 'tl-aidraft',  icon: 'fa-wand-magic-sparkles fa-solid', title: 'AI-Generated Response Draft' },
                'staff_edit':       { class: 'tl-staffedit',icon: 'fa-user-pen fa-solid', title: 'Staff Edited Draft' },
                'approved_response':{ class: 'tl-approved', icon: 'fa-check-double fa-solid', title: 'Approved Response Sent / Ready' },
                'follow_up':        { class: 'tl-followup', icon: 'fa-phone-volume fa-solid', title: 'Follow-Up Scheduled' },
                'status_change':    { class: 'tl-status',   icon: 'fa-arrows-rotate fa-solid', title: 'Admission Pipeline Status Change' },
                'note':             { class: 'tl-status',   icon: 'fa-note-sticky fa-solid', title: 'Internal Note' },
                'history_event':    { class: 'tl-status',   icon: 'fa-clock-rotate-left fa-solid', title: 'Follow-up History' }
            };

            wrap.innerHTML = timeline.map(item => {
                let conf = iconMap[item.message_type] || { class: 'tl-status', icon: 'fa-circle-info fa-solid', title: item.message_type };
                if (item.message_type === 'history_event') {
                    const act = item.history_action || '';
                    if (act === 'whatsapp_opened') {
                        conf = { class: 'tl-followup', icon: 'fa-arrow-up-right-from-square fa-solid', title: 'WhatsApp Opened' };
                    } else if (act === 'completed' || act === 'manual_sent_confirmed') {
                        conf = { class: 'tl-approved', icon: 'fa-circle-check fa-solid', title: 'Confirmed / Completed' };
                    } else if (act === 'rescheduled') {
                        conf = { class: 'tl-followup', icon: 'fa-calendar-days fa-solid', title: 'Follow-up Rescheduled' };
                    } else if (act === 'cancelled') {
                        conf = { class: 'tl-status', icon: 'fa-xmark fa-solid', title: 'Follow-up Cancelled' };
                    } else if (act === 'created') {
                        conf = { class: 'tl-status', icon: 'fa-user-plus fa-solid', title: 'Enquiry Registered' };
                    }
                }

                return `
                    <div class="tl-item">
                        <div class="tl-icon ${conf.class}">
                            <i class="${conf.icon}"></i>
                        </div>
                        <div class="tl-content">
                            <div class="tl-header">
                                <span class="tl-sender">${escapeHtml(item.admin_name || conf.title)}</span>
                                <span class="tl-time">${formatDate(item.created_at)}</span>
                            </div>
                            <div class="tl-body">${escapeHtml(item.body)}</div>
                            ${item.ai_draft_body && item.message_type === 'staff_edit' ? `
                                <details style="margin-top:6px;font-size:0.75rem;color:var(--text-2);">
                                    <summary style="cursor:pointer;font-weight:600;">View original AI draft before edit</summary>
                                    <div style="padding:6px 8px;background:#f1f5f9;border-radius:4px;margin-top:4px;white-space:pre-wrap;">${escapeHtml(item.ai_draft_body)}</div>
                                </details>
                            ` : ''}
                        </div>
                    </div>
                `;
            }).join('');

            // Scroll timeline to latest messages
            setTimeout(() => { wrap.scrollTop = wrap.scrollHeight; }, 50);
        }

        // Table details click
        document.getElementById('enquiriesTableBody').addEventListener('click', (e) => {
            const btn = e.target.closest('.btn-view-enquiry');
            if (!btn) return;
            const id = parseInt(btn.getAttribute('data-id'), 10);
            if (id) openEnquiryTimeline(id);
        });

        // ── Fast Status Update ────────────────────────────────────────────────
        document.getElementById('btnUpdateStatusSubmit').addEventListener('click', async () => {
            if (!currentEnquiryId) return;
            const status = document.getElementById('cvStatusSelect').value;
            const notes = document.getElementById('cvStatusNote').value.trim();

            if (status === 'ENROLLED' || status === 'CLOSED') {
                const ok = await requestConfirm({
                    title: status === 'ENROLLED' ? 'Confirm Student Enrollment' : 'Confirm Closing Enquiry',
                    message: status === 'ENROLLED'
                        ? 'Are you sure you want to mark this enquiry as ENROLLED? This will finalize the admission pipeline for this student.'
                        : 'Are you sure you want to CLOSE this admission enquiry? Active follow-up tasks will be archived.',
                    confirmText: status === 'ENROLLED' ? 'Yes, Mark Enrolled' : 'Yes, Close Enquiry',
                    cancelText: 'Cancel',
                    type: status === 'ENROLLED' ? 'info' : 'warning',
                    icon: status === 'ENROLLED' ? 'fa-graduation-cap' : 'fa-folder-closed'
                });
                if (!ok) return;
            }

            try {
                const res = await fetch('api_admission_enquiry.php?action=update_status', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify({ id: currentEnquiryId, status, notes })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(`Pipeline status updated to ${status}`, 'success');
                    openEnquiryTimeline(currentEnquiryId);
                    loadEnquiries(currentPage);
                } else {
                    showToast(data.error || 'Failed to update status.', 'error');
                }
            } catch (err) {
                showToast('Network error while updating status.', 'error');
            }
        });

        // ── AI Draft Generation & Approval ────────────────────────────────────
        document.getElementById('btnGenerateAiDraft').addEventListener('click', async () => {
            if (!currentEnquiryId) return;
            const btn = document.getElementById('btnGenerateAiDraft');
            btn.disabled = true;
            btn.innerHTML = `<i class="fa-solid fa-spinner fa-spin"></i> Generating...`;

            try {
                const res = await fetch('api_admission_enquiry.php?action=generate_ai_draft', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-CSRF-Token': CSRF_TOKEN
                    },
                    body: JSON.stringify({ id: currentEnquiryId })
                });
                const data = await res.json();
                if (data.success && data.draft_text) {
                    document.getElementById('aiDraftEditor').value = data.draft_text;
                    const meta = document.getElementById('aiDraftMeta');
                    const missing = Array.isArray(data.missing_information) ? data.missing_information.join('; ') : '';
                    meta.style.display = 'block';
                    meta.innerHTML = `<strong>Intent:</strong> ${escapeHtml(data.intent || 'General enquiry')} (${Math.round((Number(data.confidence) || 0) * 100)}%)`
                        + (data.escalation_required ? `<br><strong>Escalation:</strong> ${escapeHtml(data.escalation_reason || 'Staff review required')}` : '')
                        + (missing ? `<br><strong>Missing:</strong> ${escapeHtml(missing)}` : '')
                        + (data.service_warning ? `<br><strong>AI service:</strong> ${escapeHtml(data.service_warning)}` : '')
                        + `<br><span>Draft only. Not sent.</span>`;
                    if (data.service_warning) {
                        showToast(data.service_warning, 'warning');
                    } else {
                        showToast('AI draft generated successfully!', 'success');
                    }
                    openEnquiryTimeline(currentEnquiryId);
                } else {
                    showToast(data.error || 'Failed to generate draft.', 'error');
                }
            } catch (err) {
                showToast('Error generating draft.', 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = `<i class="fa-solid fa-sparkles"></i> Generate AI Draft`;
            }
        });

        document.getElementById('btnSaveStaffEdit').addEventListener('click', async () => {
            if (!currentEnquiryId) return;
            const editedText = document.getElementById('aiDraftEditor').value.trim();
            if (!editedText) {
                showToast('Draft text is empty.', 'warning');
                return;
            }

            try {
                const res = await fetch('api_admission_enquiry.php?action=save_draft_edit', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify({ enquiry_id: currentEnquiryId, edited_body: editedText })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Staff edit saved to conversation history.', 'success');
                    openEnquiryTimeline(currentEnquiryId);
                } else {
                    showToast(data.error || 'Failed to save staff edit.', 'error');
                }
            } catch (err) {
                showToast('Error saving staff edit.', 'error');
            }
        });

        document.getElementById('btnApproveResponse').addEventListener('click', async () => {
            if (!currentEnquiryId) return;
            const approvedText = document.getElementById('aiDraftEditor').value.trim();
            if (!approvedText) {
                showToast('Please enter or generate a response text before approving.', 'warning');
                return;
            }

            const ok = await requestConfirm({
                title: 'Approve Response Draft',
                message: 'Approve this response text as the official staff draft? It will be logged in the conversation history.',
                confirmText: 'Approve Draft',
                cancelText: 'Cancel',
                type: 'info',
                icon: 'fa-check-double'
            });
            if (!ok) return;

            try {
                const res = await fetch('api_admission_enquiry.php?action=approve_response', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify({ enquiry_id: currentEnquiryId, approved_text: approvedText })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message || 'Response approved as a draft. It has not been sent.', 'success');
                    openEnquiryTimeline(currentEnquiryId);
                    loadEnquiries(currentPage);
                } else {
                    showToast(data.error || 'Failed to approve response.', 'error');
                }
            } catch (err) {
                showToast('Error approving response.', 'error');
            }
        });

        document.getElementById('btnOpenWhatsApp').addEventListener('click', async () => {
            if (!currentEnquiryId) return;
            const message = document.getElementById('aiDraftEditor').value.trim();
            if (!message) {
                showToast('Write or approve a message before opening WhatsApp.', 'warning');
                return;
            }
            try {
                const res = await fetch('api_admission_enquiry.php?action=open_whatsapp', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify({ id: currentEnquiryId, message })
                });
                const data = await res.json();
                if (!data.success || !data.whatsapp_url) {
                    showToast(data.error || 'Could not open WhatsApp.', 'error');
                    return;
                }
                window.open(data.whatsapp_url, '_blank', 'noopener,noreferrer');
                showToast('WhatsApp opened with prefilled message. Press Send yourself in WhatsApp.', 'info');
            } catch (err) {
                showToast('Could not open WhatsApp.', 'error');
            }
        });

        document.getElementById('btnConfirmManualSend').addEventListener('click', async () => {
            if (!currentEnquiryId) return;
            const ok = await requestConfirm({
                title: 'Confirm Manual Dispatch',
                message: 'Did you press Send in WhatsApp yourself? This will record an unverified staff confirmation in the audit log.',
                confirmText: 'Yes, Confirm Sent',
                cancelText: 'Cancel',
                type: 'warning',
                icon: 'fa-circle-check'
            });
            if (!ok) return;

            try {
                const res = await fetch('api_admission_enquiry.php?action=confirm_manual_send', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify({ id: currentEnquiryId, manual_sent_confirmed: true })
                });
                const data = await res.json();
                if (data.success) {
                    showToast(data.message || 'Manual send recorded. Delivery was not verified.', 'success');
                    openEnquiryTimeline(currentEnquiryId);
                } else {
                    showToast(data.error || 'Could not record the confirmation.', 'error');
                }
            } catch (err) {
                showToast('Could not record the confirmation.', 'error');
            }
        });

        // ── Follow-Up Logger ──────────────────────────────────────────────────
        document.getElementById('btnAddFollowup').addEventListener('click', async () => {
            if (!currentEnquiryId) return;
            const note = document.getElementById('inpFollowupNote').value.trim();
            const date = document.getElementById('inpFollowupDate').value;
            if (!note) {
                showToast('Please enter a follow-up note.', 'warning');
                return;
            }

            try {
                const res = await fetch('api_admission_enquiry.php?action=add_followup', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                    body: JSON.stringify({ enquiry_id: currentEnquiryId, note, follow_up_date: date })
                });
                const data = await res.json();
                if (data.success) {
                    showToast('Follow-up logged successfully.', 'success');
                    document.getElementById('inpFollowupNote').value = '';
                    document.getElementById('inpFollowupDate').value = '';
                    openEnquiryTimeline(currentEnquiryId);
                } else {
                    showToast(data.error || 'Failed to log follow-up.', 'error');
                }
            } catch (err) {
                showToast('Error logging follow-up.', 'error');
            }
        });

        // ── Configurable Duplicate Matching Rules ─────────────────────────────
        const btnRules = document.getElementById('btnOpenRulesModal');
        if (btnRules) {
            btnRules.addEventListener('click', async () => {
                try {
                    const res = await fetch('api_admission_enquiry.php?action=get_rules');
                    const data = await res.json();
                    if (!data.success) return;

                    const container = document.getElementById('rulesContainer');
                    container.innerHTML = data.data.map(r => `
                        <div style="background:#f8fafc;border:1px solid var(--border);border-radius:8px;padding:12px 16px;display:flex;align-items:center;justify-content:space-between;gap:12px;">
                            <div style="flex:1;">
                                <div style="font-weight:700;font-size:0.86rem;color:var(--ink);">${escapeHtml(r.rule_name)}</div>
                                <div style="font-size:0.75rem;color:var(--text-2);margin-top:2px;">${escapeHtml(r.description || '')}</div>
                            </div>
                            <div style="display:flex;align-items:center;gap:12px;">
                                ${r.rule_key === 'duplicate_window_days' ? `
                                    <input type="number" class="rule-val-input" data-key="${r.rule_key}" value="${escapeHtml(r.rule_value || '90')}" style="width:70px;padding:4px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.82rem;"> days
                                ` : ''}
                                <select class="rule-action-select" data-key="${r.rule_key}" style="padding:4px 8px;border:1px solid var(--border);border-radius:4px;font-size:0.82rem;">
                                    <option value="warn" ${r.action==='warn'?'selected':''}>Warn Staff</option>
                                    <option value="block" ${r.action==='block'?'selected':''}>Block Creation</option>
                                </select>
                                <label style="display:inline-flex;align-items:center;gap:4px;font-size:0.82rem;font-weight:600;cursor:pointer;">
                                    <input type="checkbox" class="rule-enable-check" data-key="${r.rule_key}" ${r.is_enabled ? 'checked' : ''}> Active
                                </label>
                            </div>
                        </div>
                    `).join('');
                    openModal('modalRules');
                } catch (err) {}
            });

            document.getElementById('btnSaveRules')?.addEventListener('click', async () => {
                const checks = document.querySelectorAll('.rule-enable-check');
                for (const chk of checks) {
                    const key = chk.getAttribute('data-key');
                    const isEnabled = chk.checked ? 1 : 0;
                    const actionSelect = document.querySelector(`.rule-action-select[data-key="${key}"]`);
                    const act = actionSelect ? actionSelect.value : 'warn';
                    const valInput = document.querySelector(`.rule-val-input[data-key="${key}"]`);
                    const val = valInput ? valInput.value : null;

                    await fetch('api_admission_enquiry.php?action=update_rules', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF_TOKEN },
                        body: JSON.stringify({ rule_key: key, is_enabled: isEnabled, action: act, rule_value: val })
                    });
                }
                showToast('Matching rules updated successfully.', 'success');
                closeModal('modalRules');
            });
        }

        // Export CSV
        document.getElementById('btnExportCsv').addEventListener('click', () => {
            const year = document.getElementById('filterYear').value;
            const cls = document.getElementById('filterClass').value;
            window.location.href = `api_admission_enquiry.php?action=export_csv&status=${encodeURIComponent(activeStatusFilter)}&academic_year=${encodeURIComponent(year)}&interested_class=${encodeURIComponent(cls)}`;
        });

        // ── Real-Time Background Polling for WhatsApp Inbound Messages ──────
        let lastKnownMaxId = 0;
        let lastKnownTotal = 0;
        let lastKnownSig = '';
        let lastTimelineCount = 0;

        async function refreshWaInboundStatus() {
            const el = document.getElementById('waInboundStatus');
            if (!el) return;
            try {
                const res = await fetch('api_whatsapp_session.php?action=status', { cache: 'no-store' });
                const data = await res.json();
                const sess = data && data.session ? data.session : null;
                const running = !!(data && (data.bridge_running || (sess && sess.bridge_running)));
                const syncing = !!(data && (data.is_syncing || (sess && sess.is_syncing)));
                const logoutBtn = document.getElementById('waLogoutBtn');
                const showLogout = (on) => {
                    if (!logoutBtn || logoutBtn.dataset.busy === '1') return;
                    logoutBtn.style.display = on ? 'inline-flex' : 'none';
                };
                if (syncing) {
                    el.innerHTML = '<span style="color:#0284c7;font-weight:600;"><i class="fa-solid fa-spinner fa-spin"></i> WhatsApp QR scanned! Linking & syncing chats with phone…</span>';
                    showLogout(false);
                    return;
                }
                if (!running) {
                    el.textContent = 'WhatsApp bridge is not running.';
                    showLogout(false);
                    return;
                }
                if (!sess || !sess.is_connected) {
                    el.textContent = 'WhatsApp is not linked. Scan the QR on the WhatsApp page.';
                    showLogout(false);
                    return;
                }
                showLogout(true);
                const stored = sess.last_ingest_at ? ('Last parent message stored at ' + sess.last_ingest_at + '.') : '';
                if (sess.last_skip_note) {
                    el.textContent = sess.last_skip_note + (stored ? ' ' + stored : '');
                    return;
                }
                el.textContent = stored || 'WhatsApp is linked. No new parent message has been stored.';
            } catch (e) {
                el.textContent = 'WhatsApp link status is unavailable.';
            }
        }

        async function pollEnquiriesBackground() {
            refreshWaInboundStatus();
            try {
                const activeEl = document.activeElement;
                const isTyping = activeEl && (activeEl.tagName === 'INPUT' || activeEl.tagName === 'TEXTAREA') && activeEl.id && activeEl.id.startsWith('inp');

                const year = document.getElementById('filterYear').value;
                const classVal = document.getElementById('filterClass').value;
                const staffId = document.getElementById('filterStaff').value;
                const search = document.getElementById('filterSearch').value;

                const url = `api_admission_enquiry.php?action=list&page=1&status=${encodeURIComponent(activeStatusFilter)}&academic_year=${encodeURIComponent(year)}&interested_class=${encodeURIComponent(classVal)}&assigned_staff_id=${staffId}&search=${encodeURIComponent(search)}`;
                const res = await fetch(url);
                const data = await res.json();
                if (!data || !data.success) return;

                const items = data.data || [];
                const highestId = items.length ? Math.max(...items.map(i => parseInt(i.id, 10) || 0)) : 0;
                const totalCount = data.pagination?.total || 0;
                const sumMsgs = items.reduce((acc, i) => acc + (parseInt(i.message_count, 10) || 0), 0);
                const topActive = items.length ? `${items[0].id}:${items[0].last_interaction_at || items[0].updated_at || ''}` : '';
                const currentSig = `${totalCount}-${highestId}-${sumMsgs}-${topActive}`;

                if (lastKnownSig === '') {
                    lastKnownSig = currentSig;
                    lastKnownMaxId = highestId;
                    lastKnownTotal = totalCount;
                } else if (currentSig !== lastKnownSig) {
                    const isNewEnquiry = highestId > lastKnownMaxId;
                    const newItems = isNewEnquiry ? items.filter(i => (parseInt(i.id, 10) || 0) > lastKnownMaxId) : [];
                    lastKnownSig = currentSig;
                    lastKnownMaxId = Math.max(lastKnownMaxId, highestId);
                    lastKnownTotal = totalCount;
                    if (!isTyping) {
                        loadEnquiries(currentPage);
                    }
                    if (isNewEnquiry && newItems.length) {
                        const topNew = newItems[0];
                        showToast(`New WhatsApp Enquiry from ${topNew.parent_name || 'Parent'} (${topNew.interested_class})`, 'info');
                    }
                }

                // If conversation modal is open, poll latest messages for this enquiry
                const modalConv = document.getElementById('modalConversationView');
                if (modalConv && modalConv.style.display === 'flex' && currentEnquiryId) {
                    const gRes = await fetch(`api_admission_enquiry.php?action=get&id=${currentEnquiryId}`);
                    const gData = await gRes.json();
                    if (gData && gData.success && gData.timeline) {
                        const tSig = `${gData.timeline.length}:${gData.timeline.map(t => t.id + ':' + (t.created_at || '')).join(',')}`;
                        if (tSig !== currentTimelineSig) {
                            currentTimelineSig = tSig;
                            renderTimelineStream(gData.timeline, gData.data);
                        }
                    }
                }
            } catch (ignored) {}
        }

        setInterval(pollEnquiriesBackground, 4000);
        refreshWaInboundStatus();

        // Initial Load
        loadEnquiries(1);
    })();
    </script>
    <script src="../assets/js/admission-ai-review.js?v=<?= filemtime(__DIR__ . '/../assets/js/admission-ai-review.js') ?>"></script>
</body>
</html>
