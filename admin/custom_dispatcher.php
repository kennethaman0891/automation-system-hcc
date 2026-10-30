<?php
/**
 * HCC Automation System — Dedicated Custom Dispatch Hub
 * Reusable 2-Pane WhatsApp Dispatch Cockpit with live message preview,
 * sequential stepper, phone auto-saving, and batch automation.
 *
 * NOTE: The data source, fields, and message format in this file are modular
 * and can be customized with your exact database tables and content.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';

$adminPage = 'custom_dispatcher';
$settings  = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church High School';
$adminUser  = sanitize($_SESSION['admin_name'] ?? 'Admin');
$adminInitials = strtoupper(implode('', array_map(
    function ($part) { return mb_substr($part, 0, 1); },
    array_slice(array_filter(preg_split('/\s+/', trim($_SESSION['admin_name'] ?? 'A'))), 0, 2)
))) ?: 'AD';
$notifData  = rcGetRecentNotifications(8);

// =========================================================================
// 1. AJAX ENDPOINTS (Inline Phone Updating & Notifications)
// =========================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim($_POST['action'] ?? '');

    if ($action === 'mark_notifs_read') {
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

    if ($action === 'update_phone') {
        if (!verifyCSRFToken()) {
            header('Content-Type: application/json');
            echo json_encode(['ok' => false, 'error' => 'Security token invalid. Please refresh.']);
            exit;
        }
        $itemId = trim($_POST['item_id'] ?? '');
        $phone  = trim($_POST['phone'] ?? '');
        $category = trim($_POST['category'] ?? '');

        $db = getDB();
        $updated = false;

        // Custom update logic — updates phone for this recipient
        if ($itemId !== '') {
            try {
                // Default update against report_card (can be customized to your target table)
                $uStmt = $db->prepare("UPDATE report_card SET parent_phone = ? WHERE roll_no = ?");
                $uStmt->execute([$phone, $itemId]);
                $updated = true;
            } catch (Exception $e) {
                $updated = false;
            }
        }
        header('Content-Type: application/json');
        echo json_encode(['ok' => $updated, 'item_id' => $itemId, 'phone' => $phone]);
        exit;
    }
}

// =========================================================================
// 2. CONFIGURATION & DATA SOURCE DEFINITION
// (Replace / customize this section with your exact table & fields)
// =========================================================================
$pageTitle       = 'Custom WhatsApp Dispatcher';
$pageSubtitle    = $schoolName . ' • Targeted Communication & Broadcast Portal';
$categoryLabel   = 'Target Group';

// Available categories / classes
$availableClasses = rcClassOptions();
$defaultClassKey = '';
if (!empty($availableClasses)) {
    $first = $availableClasses[0];
    $defaultClassKey = rcBuildClassKey($first['academic_year'], $first['std'], $first['division'], $first['semester']);
}

$categoryKey = trim($_GET['category'] ?? $defaultClassKey);
$docType     = trim($_GET['doc_type'] ?? 'notice');
$search      = trim($_GET['search'] ?? '');

$c = rcParseClassKey(urldecode($categoryKey));
$year     = $c['academic_year'] ?: '2025-26';
$std      = $c['std'] ?: '1';
$division = $c['division'] ?: 'A';
$semester = $c['semester'] ?: 'Sem 1';

$romanMap = ['1'=>'I','2'=>'II','3'=>'III','4'=>'IV','5'=>'V','6'=>'VI','7'=>'VII','8'=>'VIII','9'=>'IX','10'=>'X'];
$romanStd = $romanMap[(string)$std] ?? $std;
$activeGroupLabel = "STD {$romanStd} {$division} ({$year})";

// Fetch recipient roster from data source
$filters = ['class_key' => $categoryKey];
if ($search !== '') $filters['search'] = $search;
$rawRecords = rcAllStudents($filters);

$recipientRoster = [];
foreach ($rawRecords as $rec) {
    $roll = (string)($rec['roll_no'] ?? '');
    $name = (string)($rec['student_name'] ?? '');
    $phone = (string)($rec['parent_phone'] ?? '');

    $recipientRoster[] = [
        'id'          => $roll,
        'name'        => $name,
        'subtitle'    => 'Roll ' . $roll . ' • ' . $activeGroupLabel,
        'badge_label' => isset($rec['percentage']) ? ($rec['percentage'] . '% • ' . ($rec['grade'] ?? '—')) : 'Active',
        'phone'       => $phone,
        'category'    => $categoryKey,
        'group_label' => $activeGroupLabel,
        'doc_name'    => 'Official_Notice_' . $roll . '.pdf',
        'public_url'  => function_exists('rcGetPublicCardUrl') ? rcGetPublicCardUrl($categoryKey, $roll) : '',
        'custom_fields' => [
            'roll'       => $roll,
            'name'       => $name,
            'class'      => $activeGroupLabel,
            'score'      => ($rec['obtained'] ?? '0') . ' / ' . ($rec['maxtotal'] ?? '0'),
            'percentage' => $rec['percentage'] ?? '',
            'grade'      => $rec['grade'] ?? '',
            'remark'     => $rec['remark'] ?? '',
            'attendance' => $rec['attendance'] ?? ''
        ]
    ];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= sanitize($pageTitle) ?> — <?= sanitize($schoolName) ?></title>
    <link rel="icon" href="../assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="../assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="../assets/vendor/merriweather/merriweather.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
    <link rel="stylesheet" href="../assets/css/whatsapp-share.css?v=<?= filemtime(__DIR__ . '/../assets/css/whatsapp-share.css') ?>">
    <style>
        .wa-hub-stats { display: grid; grid-template-columns: repeat(4, 1fr); gap: 14px; margin-bottom: 20px; }
        .wa-stat-card { background: #ffffff; border: 1px solid var(--border); border-radius: var(--radius-sm); padding: 14px 18px; display: flex; align-items: center; gap: 14px; box-shadow: var(--shadow-sm); }
        .wa-stat-icon { width: 40px; height: 40px; border-radius: 8px; display: flex; align-items: center; justify-content: center; font-size: 1.15rem; }
        .wa-stat-num { font-size: 1.35rem; font-weight: 800; color: var(--ink); line-height: 1; }
        .wa-stat-label { font-size: 0.76rem; color: var(--text-2); font-weight: 600; margin-top: 4px; text-transform: uppercase; }
        @media (max-width: 768px) { .wa-hub-stats { grid-template-columns: repeat(2, 1fr); } }

        /* Notification Dropdown */
        .notif-container { position: relative; display: inline-block; }
        .notif-trigger { background: var(--paper); border: 1px solid var(--border); border-radius: var(--radius-sm); width: 40px; height: 40px; display: inline-flex; align-items: center; justify-content: center; cursor: pointer; color: var(--text-2); font-size: 0.95rem; position: relative; transition: background 0.18s ease, border-color 0.18s ease, color 0.18s ease; }
        .notif-trigger:hover { background: var(--brand-soft); border-color: var(--brand); color: var(--brand); }
        .notif-badge { position: absolute; top: -6px; right: -6px; background: var(--danger); color: #fff; font-size: 0.65rem; font-weight: 700; border-radius: 999px; padding: 2px 6px; border: 2px solid var(--paper); min-width: 18px; text-align: center; }
        .notif-dropdown { display: none; position: absolute; right: 0; top: 48px; width: 360px; max-width: calc(100vw - 32px); background: var(--paper); border: 1px solid var(--border); border-radius: var(--radius); box-shadow: var(--shadow-lg); z-index: 1000; overflow: hidden; }
        .notif-dropdown.show { display: block; }
        .notif-dropdown-header { padding: 12px 16px; border-bottom: 1px solid var(--border); background: var(--surface); display: flex; align-items: center; justify-content: space-between; font-size: 0.85rem; font-weight: 700; color: var(--ink); }
        .notif-dropdown-list { max-height: 380px; overflow-y: auto; }
        .notif-item { padding: 12px 16px; border-bottom: 1px solid var(--border); display: flex; gap: 12px; font-size: 0.82rem; line-height: 1.45; text-decoration: none; color: inherit; }
        .notif-item:hover { background: var(--surface); }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <main class="admin-main">
            <!-- Topbar Header -->
            <header class="admin-header">
                <button class="sidebar-toggle js-sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <div class="header-titles">
                    <h1 class="page-title"><i class="fa-brands fa-whatsapp" style="color:var(--wa-green);margin-right:8px;"></i> <?= sanitize($pageTitle) ?></h1>
                    <p class="page-sub"><?= sanitize($pageSubtitle) ?></p>
                </div>

                <div class="header-actions" style="display:flex;align-items:center;gap:12px;">
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
                                <span><i class="fas fa-bell"></i> Activity Notifications</span>
                                <span style="font-size:0.75rem;font-weight:600;color:var(--text-3);"><?= count($notifData['items']) ?> events</span>
                            </div>
                            <div class="notif-dropdown-list">
                                <?php if (empty($notifData['items'])): ?>
                                    <div style="padding:26px 20px;text-align:center;color:var(--text-3);font-size:0.84rem;"><i class="fas fa-bell-slash" style="font-size:1.5rem;display:block;margin-bottom:8px;opacity:0.5;"></i>No recent notifications.</div>
                                <?php else: ?>
                                    <?php foreach ($notifData['items'] as $nt): ?>
                                        <a href="<?= !empty($nt['link']) ? sanitize($nt['link']) : '#' ?>" class="notif-item">
                                            <div style="width:32px;height:32px;flex:0 0 32px;border-radius:8px;background:var(--gold-glow);color:var(--gold-ink);display:flex;align-items:center;justify-content:center;"><i class="fas fa-bullhorn"></i></div>
                                            <div style="flex:1;min-width:0;">
                                                <div style="font-weight:700;color:var(--ink);margin-bottom:2px;"><?= sanitize($nt['title']) ?></div>
                                                <div style="color:var(--text-2);font-size:0.78rem;margin-bottom:4px;"><?= sanitize($nt['message']) ?></div>
                                                <div style="color:var(--text-3);font-size:0.72rem;"><i class="far fa-clock"></i> <?= date('M d, Y • h:i A', strtotime($nt['created_at'])) ?></div>
                                            </div>
                                        </a>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>

                    <div class="admin-user">
                        <span class="user-avatar"><?= sanitize($adminInitials) ?></span>
                        <span class="user-meta">
                            <strong><?= $adminUser ?></strong>
                            <small>Administrator</small>
                        </span>
                    </div>
                </div>
            </header>

            <div class="admin-content">
                <input type="hidden" name="csrf_token" id="csrfToken" value="<?= generateCSRFToken() ?>">

                <!-- Filter & Control Bar Strip -->
                <div class="wa-hub-control-bar" style="display:flex;align-items:center;justify-content:space-between;gap:12px;margin-bottom:14px;background:#ffffff;padding:12px 18px;border-radius:10px;border:1px solid var(--border);box-shadow:var(--shadow-sm);flex-wrap:wrap;">
                    <form method="GET" action="custom_dispatcher.php" id="controlFilterForm" style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin:0;">
                        <div style="display:flex;align-items:center;gap:6px;">
                            <label for="categorySelect" style="font-size:0.8rem;font-weight:700;color:var(--ink);"><i class="fas fa-layer-group" style="color:var(--wa-green);margin-right:4px;"></i><?= sanitize($categoryLabel) ?>:</label>
                            <select name="category" id="categorySelect" class="form-select" onchange="this.form.submit()" style="padding:6px 10px;border-radius:6px;border:1px solid #cbd5e1;font-size:0.83rem;font-weight:600;min-width:180px;">
                                <?php foreach ($availableClasses as $ac):
                                    $ck = rcBuildClassKey($ac['academic_year'], $ac['std'], $ac['division'], $ac['semester']);
                                    $rStd = $romanMap[(string)$ac['std']] ?? $ac['std'];
                                ?>
                                <option value="<?= e_attr($ck) ?>" <?= $ck === $categoryKey ? 'selected' : '' ?>>STD <?= $rStd ?> <?= $ac['division'] ?> (<?= $ac['academic_year'] ?>) &bull; <?= $ac['students'] ?> items</option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div style="display:flex;align-items:center;gap:6px;">
                            <label for="docTypeSelectTop" style="font-size:0.8rem;font-weight:700;color:var(--ink);"><i class="fas fa-file-lines" style="color:var(--wa-green);margin-right:4px;"></i>Type:</label>
                            <select name="doc_type" id="docTypeSelectTop" class="form-select" onchange="this.form.submit()" style="padding:6px 10px;border-radius:6px;border:1px solid #cbd5e1;font-size:0.83rem;font-weight:600;">
                                <option value="notice" <?= $docType==='notice' ? 'selected' : '' ?>>Official Notice</option>
                                <option value="circular" <?= $docType==='circular' ? 'selected' : '' ?>>Circular / Announcement</option>
                                <option value="custom" <?= $docType==='custom' ? 'selected' : '' ?>>Custom Broadcast</option>
                            </select>
                        </div>
                    </form>

                    <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
                        <button type="button" class="btn btn-outline" id="btnRefreshRoster" title="Reload recipient roster" style="display:inline-flex;align-items:center;gap:6px;padding:7px 12px;border-radius:8px;font-size:0.82rem;font-weight:600;border:1px solid #cbd5e1;background:#fff;cursor:pointer;">
                            <i class="fas fa-rotate"></i> Refresh List
                        </button>
                    </div>
                </div>

                <!-- ══════════════ 2-PANE WHATSAPP DISPATCH COCKPIT ══════════════ -->
                <div class="wa-app" id="customWaApp">
                    <!-- ══════════════ LEFT PANE: CONTACTS & ROSTER ══════════════ -->
                    <aside class="wa-chatlist">
                        <div class="wa-cl-header">
                            <div class="wa-cl-avatar"><?= sanitize($adminInitials) ?></div>
                            <div class="wa-cl-title">
                                <b><?= sanitize($pageTitle) ?></b>
                                <span><?= sanitize($activeGroupLabel) ?></span>
                            </div>
                            <div class="wa-batch-wrap">
                                <button type="button" class="wa-icon-btn" id="batchMenuBtn" title="Batch actions" aria-label="Batch actions" aria-expanded="false">
                                    <i class="fas fa-ellipsis-vertical"></i>
                                </button>
                                <div class="wa-menu" id="batchMenu" hidden>
                                    <div class="wa-menu-head">Batch Operations</div>
                                    <button type="button" class="wa-menu-item" id="startSeqBtn"><i class="fas fa-play"></i> Start Sequential Dispatch</button>
                                    <button type="button" class="wa-menu-item" id="autoSendBtn" title="Automate dispatch via Puppeteer"><i class="fas fa-robot"></i> Auto Send All</button>
                                    <button type="button" class="wa-menu-item" id="scanQrBrowserBtn" title="Scan WhatsApp Web QR code"><i class="fa-brands fa-whatsapp"></i> Scan QR in Browser</button>
                                    <div class="wa-menu-sep"></div>
                                    <button type="button" class="wa-menu-item" id="downloadBatchZipBtn"><i class="fas fa-file-zipper"></i> Download All (ZIP)</button>
                                    <button type="button" class="wa-menu-item" id="copyAllPhonesBtn"><i class="fas fa-copy"></i> Copy All Phone Numbers</button>
                                    <div class="wa-menu-sep"></div>
                                    <button type="button" class="wa-menu-item is-danger" id="resetSentStatusBtn"><i class="fas fa-rotate-left"></i> Reset Sent Badges</button>
                                </div>
                            </div>
                        </div>

                        <!-- Search Bar -->
                        <form method="GET" action="custom_dispatcher.php" id="hubSearchForm" class="wa-sidebar-form">
                            <div class="wa-search">
                                <i class="fas fa-search"></i>
                                <input type="text" name="search" id="searchInput" placeholder="Search contact or ID…" value="<?= e_attr($search) ?>">
                                <button type="submit" class="wa-search-submit" title="Apply search" aria-label="Apply search"><i class="fas fa-arrow-right"></i></button>
                            </div>
                            <input type="hidden" name="category" value="<?= e_attr($categoryKey) ?>">
                            <input type="hidden" name="doc_type" value="<?= e_attr($docType) ?>">
                        </form>

                        <!-- Custom Note / Broadcast Note Box -->
                        <div class="wa-note-row">
                            <i class="fas fa-bullhorn"></i>
                            <input type="text" id="customNoteInput" placeholder="Broadcast note (appended to every message)…">
                        </div>

                        <!-- Filter Pills -->
                        <div class="wa-filter-tabs" id="rosterFilterPills">
                            <button type="button" class="wa-pill is-active" data-filter="all"><i class="fas fa-users"></i> All <span class="wa-pill-badge" id="pillCountAll"><?= count($recipientRoster) ?></span></button>
                            <button type="button" class="wa-pill" data-filter="pending"><i class="fas fa-clock"></i> Pending <span class="wa-pill-badge" id="pillCountPending"><?= count($recipientRoster) ?></span></button>
                            <button type="button" class="wa-pill" data-filter="sent"><i class="fas fa-check-double"></i> Sent <span class="wa-pill-badge" id="pillCountSent">0</span></button>
                            <button type="button" class="wa-pill" data-filter="missing"><i class="fas fa-phone-slash"></i> No phone <span class="wa-pill-badge" id="pillCountMissing">0</span></button>
                        </div>

                        <!-- Recipient Items Stream -->
                        <div class="wa-cl-scroll">
                            <div class="wa-cl-section">Recipients · <?= count($recipientRoster) ?> Contacts</div>
                            <?php if (empty($recipientRoster)): ?>
                                <div class="wa-cl-empty">
                                    <i class="fas fa-folder-open"></i>
                                    <p>No contacts found for this selection.</p>
                                </div>
                            <?php else: ?>
                                <?php foreach ($recipientRoster as $idx => $item):
                                    $nameParts = preg_split('/\s+/', trim((string)$item['name']));
                                    $initials = '';
                                    foreach (array_slice(array_filter($nameParts), 0, 2) as $np) {
                                        $initials .= mb_substr($np, 0, 1);
                                    }
                                    $initials = strtoupper($initials) ?: 'CT';
                                ?>
                                <div class="wa-chat-item" data-idx="<?= $idx ?>" data-id="<?= e_attr($item['id']) ?>" data-name="<?= e_attr($item['name']) ?>">
                                    <div class="wa-ci-avatar"><?= sanitize($initials) ?></div>
                                    <div class="wa-ci-body">
                                        <div class="wa-ci-top">
                                            <span class="wa-ci-name"><?= sanitize($item['name']) ?></span>
                                            <span class="wa-status-pill wa-status-pending" id="statusPill_<?= e_attr($item['id']) ?>">⚪ Pending</span>
                                        </div>
                                        <div class="wa-ci-bottom">
                                            <span class="wa-ci-preview"><?= sanitize($item['subtitle']) ?></span>
                                        </div>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>

                        <!-- Footer Counter -->
                        <div class="wa-cl-footer">
                            <div><strong id="sentCountEl">0</strong> / <strong id="totalCountEl"><?= count($recipientRoster) ?></strong> sent</div>
                            <div><span id="pendingCountEl"><?= count($recipientRoster) ?></span> pending · <span id="progressPctEl">0%</span></div>
                        </div>
                    </aside>

                    <!-- ══════════════ RIGHT PANE: CONVERSATION & PREVIEW ══════════════ -->
                    <section class="wa-chatpane">
                        <div class="wa-cp-header">
                            <button type="button" class="wa-icon-btn wa-back" id="chatBackBtn" title="Back to list" aria-label="Back to list">
                                <i class="fas fa-arrow-left"></i>
                            </button>
                            <div class="wa-cp-avatar" id="mockAvatar">CT</div>
                            <div class="wa-cp-meta">
                                <div class="wa-cp-name" id="mockRecipientName">Select a recipient</div>
                                <div class="wa-cp-sub">
                                    <span id="mockRecipientSubtitle">ID —</span>
                                    <span class="wa-cp-dot">·</span>
                                    <span id="mockPhoneStatus"><i class="fa-brands fa-whatsapp"></i> WhatsApp Contact</span>
                                </div>
                            </div>
                            <div class="wa-cp-actions">
                                <button type="button" class="wa-btn wa-btn-ghost wa-btn-sm" id="openCompanionBtn" title="Open WhatsApp Web prefilled">
                                    <i class="fa-brands fa-whatsapp"></i> Open
                                </button>
                                <button type="button" class="wa-btn wa-btn-send wa-btn-sm" id="paneSendBtn" title="Send message to this contact">
                                    <i class="fas fa-paper-plane"></i> Send
                                </button>
                            </div>
                        </div>

                        <!-- Conversation Area -->
                        <div class="wa-messages" id="waMessages">
                            <div class="wa-date-pill">TODAY</div>
                            <div class="wa-empty-pane" id="waEmptyPane">
                                <i class="fa-brands fa-whatsapp"></i>
                                <p>Select a contact from the roster to preview and dispatch their message.</p>
                            </div>
                            <div class="wa-phone-bubble is-editable" id="mockBubbleText" contenteditable="true" spellcheck="false" role="textbox" aria-multiline="true" aria-label="Editable WhatsApp message">
                                Select a contact from the list to preview their personalized WhatsApp message here.
                            </div>

                            <div class="wa-bubble-edit-bar">
                                <span class="wa-bubble-edit-hint" id="bubbleEditHint"><i class="fas fa-pen"></i> Click bubble to edit — modifications apply to this send.</span>
                                <button type="button" class="wa-bubble-reset-btn" id="bubbleResetBtn" style="display:none;"><i class="fas fa-rotate-left"></i> Reset to template</button>
                            </div>
                        </div>

                        <!-- Pinned Document Attachment Tile -->
                        <div class="wa-attach" id="waDragTile" title="Download or attach official PDF">
                            <div class="wa-attach-ico"><i class="fas fa-file-pdf"></i></div>
                            <div class="wa-attach-body">
                                <div class="wa-attach-name" id="mockPdfFilename">Official_Document.pdf</div>
                                <div class="wa-attach-sub" id="attachMeta">Official Document &bull; Save it or attach in WhatsApp Web</div>
                            </div>
                            <div class="wa-attach-actions">
                                <button type="button" class="wa-attach-act" id="attachCustomBtn" title="Choose file from computer"><i class="fas fa-folder-open"></i></button>
                                <button type="button" class="wa-attach-act" id="mockDownloadPdfBtn" title="Save this PDF"><i class="fas fa-download"></i></button>
                                <button type="button" class="wa-attach-act is-danger" id="attachRemoveBtn" title="Remove attachment" hidden><i class="fas fa-xmark"></i></button>
                            </div>
                        </div>

                        <!-- Composer Strip -->
                        <div class="wa-composer">
                            <div class="wa-steps">
                                <span class="wa-step"><span class="wa-step-num">1</span> Open window</span>
                                <i class="fas fa-chevron-right" style="font-size:.6rem;color:#b1b8bd"></i>
                                <span class="wa-step"><span class="wa-step-num">2</span> Attach file</span>
                                <i class="fas fa-chevron-right" style="font-size:.6rem;color:#b1b8bd"></i>
                                <span class="wa-step"><span class="wa-step-num">3</span> Send</span>
                            </div>
                            <div class="wa-composer-phone">
                                <i class="fas fa-mobile-screen"></i>
                                <input type="tel" id="panePhoneInput" class="wa-pane-phone" placeholder="WhatsApp mobile number (auto-saves)" maxlength="14" autocomplete="tel">
                            </div>
                            <div class="wa-composer-row">
                                <button type="button" class="wa-btn wa-btn-attach wa-btn-sm" id="attachPickBtn" title="Attach file from disk">
                                    <i class="fas fa-paperclip"></i> Attach File
                                </button>
                                <button type="button" class="wa-btn wa-btn-ghost wa-btn-sm" id="mockCopyDirectBtn"><i class="fas fa-copy"></i> Copy Message</button>
                                <div style="flex:1"></div>
                                <button type="button" class="wa-btn wa-btn-prev wa-btn-sm" id="prevRecipientBtn" title="Previous (Alt + Left)"><i class="fas fa-arrow-left"></i> Prev</button>
                                <button type="button" class="wa-btn wa-btn-send wa-btn-sm" id="sendAndNextBtn" title="Send & Next (Alt + Right or Alt + Enter)"><span>Send &amp; Next</span> <i class="fas fa-arrow-right"></i></button>
                            </div>
                            <input type="file" id="pdfAttachInput" accept="application/pdf,.pdf,image/*" hidden>
                        </div>
                    </section>
                </div>
            </div>
        </main>
    </div>

    <!-- SEQUENTIAL DISPATCH FLOATING BANNER -->
    <div class="wa-seq-bar is-hidden" id="seqBar">
        <div class="wa-seq-info">
            <span class="wa-seq-count" id="seqCountPill">1 / <?= count($recipientRoster) ?></span>
            <span>Sending: <strong id="seqRecipientName">—</strong></span>
        </div>
        <button type="button" class="btn-whatsapp" id="seqSendNextBtn" style="padding:6px 16px;font-size:0.85rem;">
            <i class="fa-brands fa-whatsapp"></i> Send & Next <i class="fas fa-arrow-right"></i>
        </button>
        <button type="button" class="btn-outline-whatsapp" id="seqStopBtn" style="padding:6px 12px;font-size:0.82rem;background:#334155;color:#fff;border-color:#475569;">
            <i class="fas fa-stop"></i> Exit
        </button>
    </div>

    <!-- VENDOR & ENGINE ASSETS -->
    <script src="../assets/vendor/html2canvas@1.4.1/html2canvas.min.js"></script>
    <script src="../assets/vendor/jspdf@2.5.1/jspdf.umd.min.js"></script>
    <script src="../assets/vendor/jszip@3.10.1/jszip.min.js"></script>
    <script src="../assets/js/hcc-logo-base64.js?v=<?= filemtime(__DIR__ . '/../assets/js/hcc-logo-base64.js') ?>"></script>
    <script src="../assets/js/whatsapp-audio-notifications.js?v=<?= filemtime(__DIR__ . '/../assets/js/whatsapp-audio-notifications.js') ?>"></script>
    <script src="../assets/js/custom-dispatch-hub.js?v=<?= time() ?>"></script>

    <!-- INJECTED DATA PAYLOAD -->
    <script type="application/json" id="customHubData">
        <?= json_encode([
            "recipients"    => $recipientRoster,
            "schoolName"    => $schoolName,
            "categoryKey"   => $categoryKey,
            "groupLabel"    => $activeGroupLabel,
            "docType"       => $docType,
            "principalName" => $settings["principal_name"] ?? "PRINCIPAL"
        ], JSON_UNESCAPED_UNICODE) ?>
    </script>
    <script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= (is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 0) ?>"></script>
</body>
</html>
