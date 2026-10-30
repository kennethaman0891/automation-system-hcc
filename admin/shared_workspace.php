<?php
/**
 * HCC Automation System — Google Drive Teacher Workspace & Staff Collaboration Hub
 * Allows teachers to view their personal Drive folder, access auto-filed academic work,
 * and share folders directly with other registered teachers in the school.
 */

$adminPage = 'shared_workspace';

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/google_drive.php';
require_once __DIR__ . '/auth_check.php';

$settings   = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church High School & Junior College';
$schoolLogo = $settings['logo'] ?? 'assets/images/hcclogo.jpg';
$adminUser  = $_SESSION['admin_name'] ?? 'Admin';
$adminId    = (int)($_SESSION['admin_id'] ?? 0);
$adminRole  = $_SESSION['admin_role'] ?? 'admin';

$db = getDB();
$stmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$adminId]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser) {
    header('Location: logout.php');
    exit;
}

$googleClient = new HCCGoogleDrive();
$googleConfigured = $googleClient->isConfigured();

// Fetch all other staff members for the sharing dropdown
$staffStmt = $db->prepare("SELECT id, username, full_name, email, role, google_avatar, google_drive_folder_url FROM users WHERE id != ? ORDER BY full_name ASC");
$staffStmt->execute([$adminId]);
$colleagues = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

$isGoogleConnected = !empty($currentUser['google_id']) || !empty($_SESSION['google_logged_in']);
$driveFolderId     = $currentUser['google_drive_folder_id'] ?? ($_SESSION['google_drive_folder_id'] ?? '');
$driveFolderUrl    = $currentUser['google_drive_folder_url'] ?? ($_SESSION['google_drive_folder_url'] ?? '');
if ($driveFolderId && !$driveFolderUrl) {
    $driveFolderUrl = "https://drive.google.com/drive/folders/{$driveFolderId}";
}

$subfolders = [];
if (!empty($currentUser['google_drive_subfolders'])) {
    $subfolders = json_decode($currentUser['google_drive_subfolders'], true) ?: [];
} elseif (!empty($_SESSION['google_drive_subfolders'])) {
    $subfolders = $_SESSION['google_drive_subfolders'];
}

// Generate connect Google URL if not connected
$connectGoogleUrl = '';
$googleClientId = $settings['google_client_id'] ?? '';
if ($googleConfigured) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
    $connectGoogleUrl = $googleClient->getAuthUrl($_SESSION['oauth_state']);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Google Drive Workspace &amp; Staff Collaboration | <?= sanitize($schoolName) ?></title>
    <link rel="icon" href="../assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="../assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
    <style>
        .workspace-hero {
            background: linear-gradient(135deg, #1e293b 0%, #0f172a 100%);
            border-radius: 16px;
            padding: 30px;
            color: #ffffff;
            margin-bottom: 24px;
            box-shadow: 0 10px 25px -5px rgba(15, 23, 42, 0.25);
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 20px;
            flex-wrap: wrap;
        }
        .hero-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .hero-avatar {
            width: 72px;
            height: 72px;
            border-radius: 50%;
            border: 3px solid rgba(255, 255, 255, 0.2);
            object-fit: cover;
            background: #334155;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 2rem;
            color: #f8fafc;
        }
        .hero-title {
            margin: 0 0 6px 0;
            font-size: 1.5rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        .hero-sub {
            margin: 0;
            color: #94a3b8;
            font-size: 0.9rem;
            display: flex;
            align-items: center;
            gap: 14px;
            flex-wrap: wrap;
        }
        .hero-sub span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .hero-actions {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
        }
        .btn-drive-main {
            display: inline-flex;
            align-items: center;
            gap: 10px;
            padding: 12px 22px;
            background: #2563eb;
            color: #ffffff;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.92rem;
            text-decoration: none;
            transition: all 0.2s ease;
            box-shadow: 0 4px 14px rgba(37, 99, 235, 0.4);
            border: none;
            cursor: pointer;
        }
        .btn-drive-main:hover {
            background: #1d4ed8;
            color: #ffffff;
            transform: translateY(-2px);
            box-shadow: 0 6px 20px rgba(37, 99, 235, 0.5);
        }
        .btn-copy-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 12px 18px;
            background: rgba(255, 255, 255, 0.1);
            color: #ffffff;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.92rem;
            text-decoration: none;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .btn-copy-link:hover {
            background: rgba(255, 255, 255, 0.2);
            color: #ffffff;
        }

        /* Subfolder Grid */
        .folder-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }
        .folder-card {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            padding: 18px;
            transition: all 0.2s ease;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            text-decoration: none;
            color: inherit;
        }
        .folder-card:hover {
            border-color: #3b82f6;
            transform: translateY(-3px);
            box-shadow: 0 8px 20px rgba(59, 130, 246, 0.12);
        }
        .folder-icon {
            width: 44px;
            height: 44px;
            border-radius: 10px;
            background: #eff6ff;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.3rem;
            margin-bottom: 14px;
        }
        .folder-icon.ut { background: #fef3c7; color: #d97706; }
        .folder-icon.an { background: #f0fdf4; color: #16a34a; }
        .folder-icon.rc { background: #fdf2f8; color: #db2777; }
        .folder-title {
            font-size: 0.96rem;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 4px 0;
        }
        .folder-sub {
            font-size: 0.78rem;
            color: #64748b;
            margin: 0 0 14px 0;
            line-height: 1.4;
        }
        .folder-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.78rem;
            font-weight: 700;
            color: #2563eb;
            padding-top: 10px;
            border-top: 1px solid #f1f5f9;
        }

        /* Sharing Section */
        .share-card {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 16px;
            padding: 26px;
            margin-bottom: 28px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
        }
        .share-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 18px;
        }
        .share-header-icon {
            width: 46px;
            height: 46px;
            border-radius: 12px;
            background: rgba(37, 99, 235, 0.1);
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
        }
        .share-header h3 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 800;
            color: #0f172a;
        }
        .share-header p {
            margin: 3px 0 0;
            font-size: 0.84rem;
            color: #64748b;
        }
        .share-form-flex {
            display: flex;
            gap: 12px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        .share-input-group {
            flex: 1;
            min-width: 260px;
        }
        .share-input-group label {
            display: block;
            font-size: 0.82rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
        }
        .share-select {
            width: 100%;
            height: 46px;
            padding: 0 14px;
            font-size: 0.9rem;
            border: 1.5px solid #cbd5e1;
            border-radius: 9px;
            background: #ffffff;
            color: #0f172a;
            outline: none;
        }
        .share-select:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }
        .btn-share-submit {
            height: 46px;
            padding: 0 24px;
            background: #0f172a;
            color: #ffffff;
            border: none;
            border-radius: 9px;
            font-weight: 700;
            font-size: 0.92rem;
            cursor: pointer;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.2s ease;
        }
        .btn-share-submit:hover {
            background: #1e293b;
            transform: translateY(-1px);
        }

        /* Not Connected State */
        .connect-cta-box {
            background: #ffffff;
            border: 2px dashed #cbd5e1;
            border-radius: 16px;
            padding: 44px 24px;
            text-align: center;
            max-width: 600px;
            margin: 40px auto;
        }
        .connect-cta-icon {
            font-size: 3.5rem;
            color: #4285f4;
            margin-bottom: 16px;
        }
        .connect-cta-title {
            font-size: 1.4rem;
            font-weight: 800;
            color: #0f172a;
            margin-bottom: 8px;
        }
        .connect-cta-sub {
            font-size: 0.9rem;
            color: #64748b;
            line-height: 1.5;
            margin-bottom: 24px;
        }

        /* Toast notification */
        #shareToast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #0f172a;
            color: #ffffff;
            padding: 14px 20px;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.25);
            display: none;
            align-items: center;
            gap: 10px;
            font-size: 0.88rem;
            font-weight: 600;
            z-index: 9999;
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php require_once __DIR__ . '/includes/sidebar.php'; ?>

        <main class="admin-main">
            <!-- Header -->
            <header class="admin-header">
                <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle sidebar"><i class="fas fa-bars"></i></button>
                <div class="header-titles">
                    <h1 class="page-title"><i class="fa-brands fa-google-drive text-primary me-2"></i>Google Drive Workspace</h1>
                    <p class="page-sub"><?= sanitize($schoolName) ?> &bull; Automated Cloud Archive &amp; Staff Collaboration</p>
                </div>
                <div class="header-actions">
                    <div class="admin-user">
                        <i class="fas fa-shield-alt"></i>
                        <span><?= sanitize($adminUser) ?> (<?= strtoupper(sanitize($adminRole)) ?>)</span>
                    </div>
                </div>
            </header>

            <div class="admin-content">
                <?php if ($isGoogleConnected && $driveFolderId): ?>
                    <!-- Connected Workspace Hero -->
                    <div class="workspace-hero">
                        <div class="hero-info">
                            <?php if (!empty($currentUser['google_avatar'])): ?>
                                <img src="<?= htmlspecialchars($currentUser['google_avatar']) ?>" alt="Avatar" class="hero-avatar">
                            <?php else: ?>
                                <div class="hero-avatar"><i class="fa-solid fa-user"></i></div>
                            <?php endif; ?>
                            <div>
                                <h2 class="hero-title">
                                    <?= htmlspecialchars($currentUser['full_name']) ?>'s Academic Workspace
                                    <span class="badge" style="background:#10b981; font-size:0.65rem; padding:4px 8px; border-radius:12px; font-weight:700;">Active &bull; Auto-Sync</span>
                                </h2>
                                <p class="hero-sub">
                                    <span><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($currentUser['email']) ?></span>
                                    <span><i class="fa-solid fa-folder-tree"></i> Academic Year 2024-25</span>
                                    <span><i class="fa-brands fa-google-drive"></i> Google Drive Cloud Connected</span>
                                </p>
                            </div>
                        </div>

                        <div class="hero-actions">
                            <a href="<?= htmlspecialchars($driveFolderUrl) ?>" target="_blank" rel="noopener" class="btn-drive-main">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i> Open in Google Drive
                            </a>
                            <button type="button" class="btn-copy-link" onclick="copyDriveLink('<?= htmlspecialchars($driveFolderUrl) ?>')">
                                <i class="fa-solid fa-link"></i> Copy Link
                            </button>
                        </div>
                    </div>

                    <!-- Organized Subfolders Grid -->
                    <h3 style="font-size:1.1rem; font-weight:800; color:#0f172a; margin-bottom:14px;">
                        <i class="fa-solid fa-folder-open text-primary me-2"></i>Personal Workspace Subfolders
                    </h3>
                    <div class="folder-grid">
                        <a href="<?= htmlspecialchars($subfolders['marks']['url'] ?? $driveFolderUrl) ?>" target="_blank" class="folder-card">
                            <div>
                                <div class="folder-icon"><i class="fa-solid fa-table-list"></i></div>
                                <h4 class="folder-title">Semester Marksheets</h4>
                                <p class="folder-sub">Official examination mark sheets, grading summaries &amp; tabulated semester results.</p>
                            </div>
                            <div class="folder-footer">
                                <span>Open Folder</span>
                                <i class="fa-solid fa-arrow-right"></i>
                            </div>
                        </a>

                        <a href="<?= htmlspecialchars($subfolders['unit_tests']['url'] ?? $driveFolderUrl) ?>" target="_blank" class="folder-card">
                            <div>
                                <div class="folder-icon ut"><i class="fa-solid fa-table-cells"></i></div>
                                <h4 class="folder-title">Unit Tests &amp; Exams</h4>
                                <p class="folder-sub">Unit Tests I &ndash; IV marks tabulation, student question results &amp; test archives.</p>
                            </div>
                            <div class="folder-footer">
                                <span>Open Folder</span>
                                <i class="fa-solid fa-arrow-right"></i>
                            </div>
                        </a>

                        <a href="<?= htmlspecialchars($subfolders['analytics']['url'] ?? $driveFolderUrl) ?>" target="_blank" class="folder-card">
                            <div>
                                <div class="folder-icon an"><i class="fa-solid fa-chart-line"></i></div>
                                <h4 class="folder-title">Class Analytics Reports</h4>
                                <p class="folder-sub">Generated 4-section comprehensive PDF reports (Overview, Subjects, Trends, Comparative).</p>
                            </div>
                            <div class="folder-footer">
                                <span>Open Folder</span>
                                <i class="fa-solid fa-arrow-right"></i>
                            </div>
                        </a>

                        <a href="<?= htmlspecialchars($subfolders['reports']['url'] ?? $driveFolderUrl) ?>" target="_blank" class="folder-card">
                            <div>
                                <div class="folder-icon rc"><i class="fa-solid fa-file-invoice"></i></div>
                                <h4 class="folder-title">Student Report Cards</h4>
                                <p class="folder-sub">A4 printable student progress cards, batch class ZIP bundles &amp; bonafide certificates.</p>
                            </div>
                            <div class="folder-footer">
                                <span>Open Folder</span>
                                <i class="fa-solid fa-arrow-right"></i>
                            </div>
                        </a>
                    </div>

                    <!-- Inter-Staff Collaboration & Sharing Section -->
                    <div class="share-card">
                        <div class="share-header">
                            <div class="share-header-icon"><i class="fa-solid fa-user-group"></i></div>
                            <div>
                                <h3>Share Workspace with Fellow Teachers</h3>
                                <p>Grant access to a colleague directly inside Google Drive. The folder will immediately appear under their <em>"Shared with me"</em> section.</p>
                            </div>
                        </div>

                        <form id="shareForm" onsubmit="handleShareSubmit(event)">
                            <?= csrfField() ?>
                            <input type="hidden" name="folder_id" value="<?= htmlspecialchars($driveFolderId) ?>">

                            <div class="share-form-flex">
                                <div class="share-input-group">
                                    <label for="colleague_select">Select Staff Member</label>
                                    <select class="share-select" id="colleague_select" required onchange="handleColleagueChange(this)">
                                        <option value="">-- Choose a teacher or administrator --</option>
                                        <?php foreach ($colleagues as $staff): ?>
                                            <option value="<?= htmlspecialchars($staff['email']) ?>">
                                                <?= htmlspecialchars($staff['full_name']) ?> (<?= htmlspecialchars($staff['email']) ?>) - <?= strtoupper($staff['role']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="custom">Enter another Google Email...</option>
                                    </select>
                                </div>

                                <div class="share-input-group" id="customEmailGroup" style="display:none;">
                                    <label for="custom_email">Colleague Google Email</label>
                                    <input type="email" id="custom_email" class="share-select" placeholder="e.g. teacher@gmail.com">
                                </div>

                                <div class="share-input-group" style="flex:0 0 180px;">
                                    <label for="share_role">Access Level</label>
                                    <select class="share-select" id="share_role" name="role">
                                        <option value="reader">Viewer (Read Only)</option>
                                        <option value="writer">Editor (Can Co-Grade)</option>
                                    </select>
                                </div>

                                <button type="submit" class="btn-share-submit" id="btnShareSubmit">
                                    <i class="fa-solid fa-paper-plane"></i> Share Now
                                </button>
                            </div>
                        </form>
                    </div>

                <?php else: ?>
                    <!-- Unconnected CTA -->
                    <div class="connect-cta-box">
                        <div class="connect-cta-icon"><i class="fa-brands fa-google-drive"></i></div>
                        <h2 class="connect-cta-title">Connect Your Google Account</h2>
                        <p class="connect-cta-sub">
                            Connect your Google Account to automatically provision your personal Google Drive workspace.
                            Whenever you enter marks, generate unit tests, or download analytics PDFs, everything will be auto-filed into your Drive and instantly shareable with other teachers.
                        </p>
                        <?php if ($googleConfigured): ?>
                            <!-- Google Sign-In Button (using Google Identity Services) -->
                            <div id="googleSignInButton" style="margin-bottom: 16px;"></div>
                            <p style="font-size: 0.8rem; color: #64748b;">or</p>
                            <a href="<?= htmlspecialchars($connectGoogleUrl) ?>" class="btn-drive-main" style="padding:14px 28px; font-size:1rem; display:inline-block;">
                                <i class="fa-brands fa-google"></i> Connect Google Account Now
                            </a>
                            <script src="https://accounts.google.com/gsi/client" async defer></script>
                            <script>
                                window.onload = function() {
                                    if (typeof google !== 'undefined' && google.accounts) {
                                        google.accounts.id.initialize({
                                            client_id: "<?= htmlspecialchars($googleClientId) ?>",
                                            callback: handleGoogleCredentialResponse,
                                            ux_mode: 'popup',
                                            auto_select: false,
                                            cancel_on_tap_outside: true
                                        });
                                        google.accounts.id.renderButton(
                                            document.getElementById('googleSignInButton'),
                                            { theme: 'outline', size: 'large', width: '100%', text: 'signin_with', shape: 'rectangular', logo_alignment: 'left' }
                                        );
                                    }
                                };
                                function handleGoogleCredentialResponse(response) {
                                    // Send the ID token to the backend for session creation
                                    fetch('api_google_login.php', {
                                        method: 'POST',
                                        headers: { 'Content-Type': 'application/json' },
                                        body: JSON.stringify({ credential: response.credential })
                                    }).then(r => r.json()).then(data => {
                                        if (data.success) {
                                            window.location.reload();
                                        } else {
                                            console.error('Google login failed:', data.error);
                                        }
                                    });
                                }
                            </script>
                        <?php else: ?>
                            <div class="alert alert-warning text-start py-2 px-3 small" style="background:#fffbeb; border:1px solid #fde68a; color:#92400e; border-radius:8px;">
                                <i class="fa-solid fa-circle-exclamation me-1"></i>
                                Google OAuth is not yet configured on this server. Please enter your Google Cloud Client ID & Secret in <a href="change_password.php" style="color:#7a2238; font-weight:700;">Account Security</a>.
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>        </div>
        </main>
    </div>

    <!-- Toast -->
    <div id="shareToast">
        <i class="fa-solid fa-circle-check text-success"></i>
        <span id="toastMsg"></span>
    </div>

    <script>
    function copyDriveLink(url) {
        if (!url) return;
        navigator.clipboard.writeText(url).then(() => {
            showToast('Google Drive link copied to clipboard!');
        }).catch(() => {
            prompt('Copy your Google Drive link:', url);
        });
    }

    function handleColleagueChange(sel) {
        const customGroup = document.getElementById('customEmailGroup');
        if (sel.value === 'custom') {
            customGroup.style.display = 'block';
            document.getElementById('custom_email').required = true;
            document.getElementById('custom_email').focus();
        } else {
            customGroup.style.display = 'none';
            document.getElementById('custom_email').required = false;
        }
    }

    async function handleShareSubmit(e) {
        e.preventDefault();
        const sel = document.getElementById('colleague_select');
        let email = sel.value;
        if (email === 'custom') {
            email = document.getElementById('custom_email').value.trim();
        }
        if (!email) {
            alert('Please select or enter a colleague Google email address.');
            return;
        }

        const role = document.getElementById('share_role').value;
        const folderId = document.querySelector('input[name="folder_id"]').value;
        const csrfToken = document.querySelector('input[name="csrf_token"]').value;

        const btn = document.getElementById('btnShareSubmit');
        const origText = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sharing...';

        try {
            const formData = new FormData();
            formData.append('csrf_token', csrfToken);
            formData.append('colleague_email', email);
            formData.append('role', role);
            formData.append('folder_id', folderId);

            const res = await fetch('api_drive_share.php', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                showToast(data.message || 'Workspace shared successfully!');
                sel.value = '';
                document.getElementById('customEmailGroup').style.display = 'none';
            } else {
                alert(data.error || 'Could not share folder.');
            }
        } catch (err) {
            alert('Network error while communicating with Google Drive.');
        } finally {
            btn.disabled = false;
            btn.innerHTML = origText;
        }
    }

    function showToast(msg) {
        const toast = document.getElementById('shareToast');
        const text = document.getElementById('toastMsg');
        text.textContent = msg;
        toast.style.display = 'flex';
        setTimeout(() => {
            toast.style.display = 'none';
        }, 4500);
    }
    </script>
</body>
</html>
