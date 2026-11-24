<?php
/**
 * HCC Automation System — Google Drive Teacher Workspace & Staff Collaboration Hub
 * Allows teachers to view their personal Drive folder, access auto-filed academic work,
 * link shared Google Drive folders directly (with or without OAuth), and collaborate
 * with other registered teachers in the school.
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

/**
 * Validate Google Drive / Docs URLs: only https:// and host in ['drive.google.com', 'docs.google.com'].
 */
function hccValidateDriveUrl(?string $url): ?string {
    $url = trim((string)$url);
    if ($url === '' || preg_match('/[\r\n\0]/', $url)) {
        return null;
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return null;
    }
    $scheme = strtolower((string)($parts['scheme'] ?? ''));
    $host   = strtolower((string)($parts['host'] ?? ''));
    if ($scheme !== 'https' || !in_array($host, ['drive.google.com', 'docs.google.com'], true)) {
        return null;
    }
    if (isset($parts['user']) || isset($parts['pass'])) {
        return null;
    }
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return null;
    }
    return $url;
}

$workspaceCategories = [
    'marks'      => '01. Semester Marksheets & Results',
    'unit_tests' => '02. Unit Tests & Exams',
    'analytics'  => '03. Class Analytics Reports',
    'reports'    => '04. Student Report Cards & Slips',
    'general'    => '05. General Workspace Documents',
];

// Authenticated download proxy for uploaded workspace files (strictly confined to storage/workspace/)
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['action'] ?? '') === 'download_file') {
    $dlCategory = trim((string)($_GET['category'] ?? ''));
    $dlFile     = (string)($_GET['file'] ?? '');

    if (!array_key_exists($dlCategory, $workspaceCategories)) {
        http_response_code(400);
        exit('Invalid workspace category.');
    }
    if ($dlFile === '' || strpos($dlFile, "\0") !== false || $dlFile === '.' || $dlFile === '..' || basename($dlFile) !== $dlFile) {
        http_response_code(400);
        exit('Invalid file parameter.');
    }

    $workspaceBase = realpath(__DIR__ . '/../storage/workspace');
    $catBase       = $workspaceBase !== false ? realpath($workspaceBase . DIRECTORY_SEPARATOR . $dlCategory) : false;
    if ($workspaceBase === false || $catBase === false || !str_starts_with($catBase, $workspaceBase . DIRECTORY_SEPARATOR)) {
        http_response_code(404);
        exit('Workspace file not found.');
    }

    $targetFile = realpath($catBase . DIRECTORY_SEPARATOR . $dlFile);
    if ($targetFile === false) {
        http_response_code(404);
        exit('Workspace file not found.');
    }
    if (!str_starts_with($targetFile, $catBase . DIRECTORY_SEPARATOR) || !is_file($targetFile) || !is_readable($targetFile)) {
        http_response_code(403);
        exit('Forbidden.');
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $detectedMime = $finfo ? (string)finfo_file($finfo, $targetFile) : 'application/octet-stream';
    if ($finfo) {
        finfo_close($finfo);
    }
    $cleanMime = strtolower(trim(explode(';', str_replace(["\r", "\n", "\0"], '', $detectedMime), 2)[0]));
    if ($cleanMime === '' || in_array($cleanMime, ['text/html', 'application/xhtml+xml', 'image/svg+xml'], true)) {
        $cleanMime = 'application/octet-stream';
    }
    $safeDownloadName = preg_replace('/[^A-Za-z0-9._-]/', '_', basename($targetFile)) ?: 'document';

    header('X-Content-Type-Options: nosniff');
    header('Content-Type: ' . $cleanMime);
    header('Content-Disposition: attachment; filename="' . $safeDownloadName . '"');
    header('Content-Length: ' . filesize($targetFile));
    header('Cache-Control: private, no-store');
    readfile($targetFile);
    exit;
}

// -------------------------------------------------------------------------
// 1. HANDLE POST ACTIONS (Manual Link, Unlink, File Upload)
// -------------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        $_SESSION['flash_error'] = 'Security validation failed. Please try again.';
        header('Location: shared_workspace.php');
        exit;
    }

    $action = trim($_POST['action'] ?? '');

    // Action A: Save or Update Google Drive Folder URL manually
    if ($action === 'save_folder') {
        $folderUrlInput = trim($_POST['google_drive_folder_url'] ?? '');

        if ($folderUrlInput === '') {
            $_SESSION['flash_error'] = 'Please provide a valid Google Drive folder URL or ID.';
            header('Location: shared_workspace.php');
            exit;
        }

        // Accept either a bare Drive folder ID OR an explicit https://(drive|docs).google.com URL
        $extractedId = '';
        $cleanUrl = null;
        if (preg_match('/^[a-zA-Z0-9_-]{15,128}$/', $folderUrlInput)) {
            $extractedId = $folderUrlInput;
            $cleanUrl = "https://drive.google.com/drive/folders/{$extractedId}";
        } else {
            $validatedUrl = hccValidateDriveUrl($folderUrlInput);
            if ($validatedUrl === null) {
                $_SESSION['flash_error'] = 'Invalid Google Drive URL. Only https://drive.google.com or https://docs.google.com links (or valid Drive folder IDs) are permitted.';
                header('Location: shared_workspace.php');
                exit;
            }
            if (preg_match('/folders\/([a-zA-Z0-9_-]+)/', $validatedUrl, $matches)) {
                $extractedId = $matches[1];
                $cleanUrl = "https://drive.google.com/drive/folders/{$extractedId}";
            } elseif (preg_match('/id=([a-zA-Z0-9_-]+)/', $validatedUrl, $matches)) {
                $extractedId = $matches[1];
                $cleanUrl = "https://drive.google.com/drive/folders/{$extractedId}";
            } else {
                $cleanUrl = $validatedUrl;
            }
        }

        $folderId = $extractedId ?: substr(md5($cleanUrl), 0, 24);

        // Validate optional subfolder URLs
        $subInputs = [
            'marks'      => ['label' => 'Semester Marksheets', 'name' => '01_Semester_Marksheets_and_Results', 'raw' => trim((string)($_POST['subfolder_marks'] ?? ''))],
            'unit_tests' => ['label' => 'Unit Tests & Exams', 'name' => '02_Unit_Tests_and_Exams', 'raw' => trim((string)($_POST['subfolder_unit_tests'] ?? ''))],
            'analytics'  => ['label' => 'Class Analytics Reports', 'name' => '03_Class_Analytics_Reports', 'raw' => trim((string)($_POST['subfolder_analytics'] ?? ''))],
            'reports'    => ['label' => 'Student Report Cards', 'name' => '04_Student_Report_Cards', 'raw' => trim((string)($_POST['subfolder_reports'] ?? ''))],
        ];
        $subfolders = [];
        foreach ($subInputs as $k => $info) {
            if ($info['raw'] !== '') {
                $subUrl = hccValidateDriveUrl($info['raw']);
                if ($subUrl === null) {
                    $_SESSION['flash_error'] = "Invalid URL for {$info['label']}. Only https://drive.google.com or https://docs.google.com URLs are permitted.";
                    header('Location: shared_workspace.php');
                    exit;
                }
            } else {
                $subUrl = $cleanUrl;
            }
            $subfolders[$k] = [
                'name' => $info['name'],
                'url'  => $subUrl,
            ];
        }

        try {
            $upd = $db->prepare("UPDATE users SET google_drive_folder_id = ?, google_drive_folder_url = ?, google_drive_subfolders = ? WHERE id = ?");
            $upd->execute([$folderId, $cleanUrl, json_encode($subfolders), $adminId]);

            $_SESSION['google_drive_folder_id']  = $folderId;
            $_SESSION['google_drive_folder_url'] = $cleanUrl;
            $_SESSION['google_drive_subfolders'] = $subfolders;

            rcLogAudit('workspace_drive_linked', 'workspace', "User {$currentUser['username']} linked Google Drive folder ({$cleanUrl})");
            $_SESSION['flash_success'] = 'Google Drive workspace folder successfully updated and ready!';
        } catch (\Throwable $e) {
            error_log('Error updating Drive folder: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Could not save Google Drive folder link to database.';
        }

        header('Location: shared_workspace.php');
        exit;
    }

    // Action B: Unlink / Disconnect Workspace
    if ($action === 'unlink_folder') {
        try {
            $upd = $db->prepare("UPDATE users SET google_drive_folder_id = NULL, google_drive_folder_url = NULL, google_drive_subfolders = NULL WHERE id = ?");
            $upd->execute([$adminId]);

            unset($_SESSION['google_drive_folder_id'], $_SESSION['google_drive_folder_url'], $_SESSION['google_drive_subfolders']);

            // Optionally clear OAuth credentials if requested
            if (!empty($_POST['clear_oauth'])) {
                $db->prepare("UPDATE users SET google_id = NULL, google_avatar = NULL, google_refresh_token = NULL, auth_provider = 'local' WHERE id = ?")->execute([$adminId]);
                unset($_SESSION['google_logged_in'], $_SESSION['google_access_token'], $_SESSION['google_avatar']);
            }

            rcLogAudit('workspace_drive_unlinked', 'workspace', "User {$currentUser['username']} unlinked Google Drive workspace.");
            $_SESSION['flash_success'] = 'Google Drive workspace unlinked successfully.';
        } catch (\Throwable $e) {
            error_log('Error unlinking Drive folder: ' . $e->getMessage());
            $_SESSION['flash_error'] = 'Failed to unlink workspace folder.';
        }

        header('Location: shared_workspace.php');
        exit;
    }

    // Action C: Direct File Upload to Workspace
    if ($action === 'upload_file') {
        if (empty($_FILES['workspace_file']) || $_FILES['workspace_file']['error'] !== UPLOAD_ERR_OK) {
            $_SESSION['flash_error'] = 'Please choose a valid file to upload.';
            header('Location: shared_workspace.php');
            exit;
        }

        $fileTmp  = $_FILES['workspace_file']['tmp_name'];
        $origName = basename($_FILES['workspace_file']['name']);
        $fileSize = $_FILES['workspace_file']['size'];
        $category = in_array($_POST['file_category'] ?? '', ['marks', 'unit_tests', 'analytics', 'reports', 'general'], true) ? $_POST['file_category'] : 'general';

        if ($fileSize > 25 * 1024 * 1024) {
            $_SESSION['flash_error'] = 'File exceeds maximum upload size (25MB).';
            header('Location: shared_workspace.php');
            exit;
        }

        // Allowed extensions + MIME validation via finfo_file()
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));
        $allowedMimeMap = [
            'pdf'  => ['application/pdf'],
            'png'  => ['image/png'],
            'jpg'  => ['image/jpeg'],
            'jpeg' => ['image/jpeg'],
            'zip'  => ['application/zip', 'application/x-zip-compressed'],
            'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip', 'application/x-zip-compressed'],
            'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip', 'application/x-zip-compressed'],
            'xls'  => ['application/vnd.ms-excel', 'application/x-ole-storage', 'application/cdfv2', 'application/msword'],
            'doc'  => ['application/msword', 'application/x-ole-storage', 'application/cdfv2'],
            'csv'  => ['text/csv', 'text/plain', 'application/csv'],
        ];
        if (!array_key_exists($ext, $allowedMimeMap)) {
            $_SESSION['flash_error'] = 'File type not supported. Allowed: PDF, Excel, Word, CSV, ZIP, Images.';
            header('Location: shared_workspace.php');
            exit;
        }

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo ? strtolower(trim((string)finfo_file($finfo, $fileTmp))) : '';
        if ($finfo) {
            finfo_close($finfo);
        }
        $detectedMime = explode(';', $detectedMime, 2)[0];
        if ($detectedMime === '' || !in_array($detectedMime, $allowedMimeMap[$ext], true)) {
            $_SESSION['flash_error'] = 'Uploaded file content does not match the allowed file type.';
            header('Location: shared_workspace.php');
            exit;
        }

        $safeName = preg_replace('/[^a-zA-Z0-9_\-\.]/', '_', $origName);
        $safeName = time() . '_' . $safeName;

        // Ensure storage directory
        $targetDir = __DIR__ . '/../storage/workspace/' . $category;
        if (!is_dir($targetDir)) {
            @mkdir($targetDir, 0755, true);
        }

        $destPath = $targetDir . '/' . $safeName;
        if (move_uploaded_file($fileTmp, $destPath)) {
            rcLogAudit('workspace_file_uploaded', 'workspace', "User {$currentUser['username']} uploaded '{$origName}' to category {$category}");
            $_SESSION['flash_success'] = "File '{$origName}' uploaded successfully to {$category} archive!";
        } else {
            $_SESSION['flash_error'] = 'Failed to save uploaded file to workspace storage.';
        }

        header('Location: shared_workspace.php');
        exit;
    }
}

// -------------------------------------------------------------------------
// 2. RETRIEVE WORKSPACE & STAFF DATA
// -------------------------------------------------------------------------
$googleClient = new HCCGoogleDrive();
$googleConfigured = $googleClient->isConfigured();

// Fetch all colleagues for the directory & sharing dropdown
$staffStmt = $db->prepare("SELECT id, username, full_name, email, role, google_avatar, google_drive_folder_url, google_drive_folder_id FROM users WHERE id != ? ORDER BY full_name ASC");
$staffStmt->execute([$adminId]);
$colleagues = $staffStmt->fetchAll(PDO::FETCH_ASSOC);

$isGoogleOAuthConnected = !empty($currentUser['google_id']) || !empty($_SESSION['google_logged_in']);
$driveFolderId          = $currentUser['google_drive_folder_id'] ?? ($_SESSION['google_drive_folder_id'] ?? '');
$rawDriveFolderUrl      = $currentUser['google_drive_folder_url'] ?? ($_SESSION['google_drive_folder_url'] ?? '');
$driveFolderUrl         = hccValidateDriveUrl($rawDriveFolderUrl) ?? '';

if ($driveFolderId && !$driveFolderUrl && HCCGoogleDrive::isValidDriveId((string)$driveFolderId)) {
    $driveFolderUrl = "https://drive.google.com/drive/folders/{$driveFolderId}";
}

$isWorkspaceConnected = ($driveFolderUrl !== '' || ($driveFolderId !== '' && HCCGoogleDrive::isValidDriveId((string)$driveFolderId)));

$subfolders = [];
if (!empty($currentUser['google_drive_subfolders'])) {
    $subfolders = json_decode($currentUser['google_drive_subfolders'], true) ?: [];
} elseif (!empty($_SESSION['google_drive_subfolders'])) {
    $subfolders = $_SESSION['google_drive_subfolders'];
}
foreach ($subfolders as $sk => $sv) {
    if (is_array($sv)) {
        $subfolders[$sk]['url'] = hccValidateDriveUrl($sv['url'] ?? '') ?? $driveFolderUrl;
    }
}

// Gather locally uploaded workspace files safely
$workspaceFiles = [];
$workspaceStorageRoot = realpath(__DIR__ . '/../storage/workspace');
if ($workspaceStorageRoot !== false) {
    foreach ($workspaceCategories as $catKey => $catLabel) {
        $catDir = realpath($workspaceStorageRoot . DIRECTORY_SEPARATOR . $catKey);
        if ($catDir === false || !str_starts_with($catDir, $workspaceStorageRoot . DIRECTORY_SEPARATOR) || !is_dir($catDir)) {
            continue;
        }
        $entries = @scandir($catDir);
        if (!is_array($entries)) {
            continue;
        }
        foreach ($entries as $entry) {
            if ($entry === '.' || $entry === '..' || str_starts_with($entry, '.')) {
                continue;
            }
            $full = realpath($catDir . DIRECTORY_SEPARATOR . $entry);
            if ($full !== false && str_starts_with($full, $catDir . DIRECTORY_SEPARATOR) && is_file($full)) {
                $mtime = (int)@filemtime($full);
                $workspaceFiles[] = [
                    'category'  => $catKey,
                    'cat_label' => $catLabel,
                    'filename'  => $entry,
                    'size'      => (int)@filesize($full),
                    'mtime'     => $mtime,
                ];
            }
        }
    }
    usort($workspaceFiles, fn($a, $b) => $b['mtime'] <=> $a['mtime']);
}

$safeGoogleAvatar = '';
if (!empty($currentUser['google_avatar'])) {
    $av = trim((string)$currentUser['google_avatar']);
    if (preg_match('#^https://#i', $av) && filter_var($av, FILTER_VALIDATE_URL)) {
        $safeGoogleAvatar = $av;
    }
}

// Generate connect Google URL if OAuth is configured
$connectGoogleUrl = '';
$googleClientId   = $settings['google_client_id'] ?? '';
if ($googleConfigured) {
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    $_SESSION['oauth_state']    = bin2hex(random_bytes(16));
    $_SESSION['oauth_redirect'] = 'shared_workspace.php?connected=1';
    $connectGoogleUrl = $googleClient->getAuthUrl($_SESSION['oauth_state']);
}

// Flash messages
$flashSuccess = $_SESSION['flash_success'] ?? null;
$flashError   = $_SESSION['flash_error'] ?? null;
unset($_SESSION['flash_success'], $_SESSION['flash_error']);

// Count connected colleagues
$connectedColleagueCount = count(array_filter($colleagues, fn($c) => !empty($c['google_drive_folder_url'])));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php require __DIR__ . '/includes/account_head.php'; ?>
    <title>Google Drive Workspace &amp; Staff Collaboration | <?= sanitize($schoolName) ?></title>
    <link rel="icon" href="<?= BASE_URL ?>assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin.css?v=<?= (is_file(__DIR__ . '/../assets/css/admin.css') ? filemtime(__DIR__ . '/../assets/css/admin.css') : 0) ?>">
    <style>
        /* Modern Workspace Palette (Deep Slate + Brand Accent) */
        .workspace-hero {
            background: radial-gradient(1000px 400px at 10% 0%, rgba(37,99,235,0.18), transparent 60%),
                        linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
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
            border: 1px solid rgba(255, 255, 255, 0.08);
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
            flex-shrink: 0;
            box-shadow: 0 4px 14px rgba(0,0,0,0.3);
        }
        .hero-title {
            margin: 0 0 6px 0;
            font-size: 1.45rem;
            font-weight: 800;
            letter-spacing: -0.02em;
            display: flex;
            align-items: center;
            gap: 10px;
            flex-wrap: wrap;
        }
        .hero-sub {
            margin: 0;
            color: #94a3b8;
            font-size: 0.88rem;
            display: flex;
            align-items: center;
            gap: 16px;
            flex-wrap: wrap;
        }
        .hero-sub span {
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .hero-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
            align-items: center;
        }
        .btn-drive-main {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 20px;
            background: #2563eb;
            color: #ffffff;
            border-radius: 10px;
            font-weight: 700;
            font-size: 0.9rem;
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
        .btn-drive-action {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 11px 16px;
            background: rgba(255, 255, 255, 0.1);
            color: #ffffff;
            border-radius: 10px;
            font-weight: 600;
            font-size: 0.88rem;
            text-decoration: none;
            border: 1px solid rgba(255, 255, 255, 0.2);
            transition: all 0.2s ease;
            cursor: pointer;
        }
        .btn-drive-action:hover {
            background: rgba(255, 255, 255, 0.2);
            color: #ffffff;
            transform: translateY(-1px);
        }
        .btn-drive-danger {
            background: rgba(220, 38, 38, 0.18);
            border-color: rgba(220, 38, 38, 0.4);
            color: #fca5a5;
        }
        .btn-drive-danger:hover {
            background: rgba(220, 38, 38, 0.35);
            color: #ffffff;
        }

        /* Subfolder Grid */
        .folder-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(260px, 1fr));
            gap: 18px;
            margin-bottom: 28px;
        }
        .folder-card {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 14px;
            padding: 20px;
            transition: all 0.22s ease;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
            text-decoration: none;
            color: inherit;
            box-shadow: 0 2px 6px rgba(0,0,0,0.03);
            position: relative;
        }
        .folder-card:hover {
            border-color: #3b82f6;
            transform: translateY(-3px);
            box-shadow: 0 10px 24px rgba(59, 130, 246, 0.14);
        }
        .folder-icon {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: #eff6ff;
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.35rem;
            margin-bottom: 14px;
        }
        .folder-icon.ut { background: #fef3c7; color: #d97706; }
        .folder-icon.an { background: #f0fdf4; color: #16a34a; }
        .folder-icon.rc { background: #fdf2f8; color: #db2777; }
        .folder-title {
            font-size: 1rem;
            font-weight: 800;
            color: #0f172a;
            margin: 0 0 5px 0;
        }
        .folder-sub {
            font-size: 0.82rem;
            color: #64748b;
            margin: 0 0 16px 0;
            line-height: 1.45;
        }
        .folder-footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-size: 0.82rem;
            font-weight: 700;
            color: #2563eb;
            padding-top: 12px;
            border-top: 1px solid #f1f5f9;
        }

        /* 2-Column Collaboration Section */
        .collab-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-bottom: 28px;
        }
        @media (max-width: 992px) {
            .collab-grid { grid-template-columns: 1fr; }
        }

        .panel-card {
            background: #ffffff;
            border: 1.5px solid #e2e8f0;
            border-radius: 16px;
            padding: 24px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.03);
            display: flex;
            flex-direction: column;
        }
        .panel-header {
            display: flex;
            align-items: center;
            gap: 14px;
            margin-bottom: 18px;
        }
        .panel-icon {
            width: 44px;
            height: 44px;
            border-radius: 12px;
            background: rgba(37, 99, 235, 0.1);
            color: #2563eb;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
        }
        .panel-icon.purple { background: rgba(147, 51, 234, 0.1); color: #9333ea; }
        .panel-icon.emerald { background: rgba(16, 185, 129, 0.1); color: #10b981; }
        .panel-header h3 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 800;
            color: #0f172a;
        }
        .panel-header p {
            margin: 3px 0 0;
            font-size: 0.82rem;
            color: #64748b;
        }

        /* Forms inside panels */
        .input-label {
            display: block;
            font-size: 0.82rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 6px;
        }
        .form-control-custom {
            width: 100%;
            height: 44px;
            padding: 0 14px;
            font-size: 0.88rem;
            border: 1.5px solid #cbd5e1;
            border-radius: 9px;
            background: #ffffff;
            color: #0f172a;
            outline: none;
            transition: border-color 0.18s, box-shadow 0.18s;
            font-family: inherit;
        }
        .form-control-custom:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.12);
        }

        /* Colleague directory table */
        .staff-directory-wrap {
            max-height: 380px;
            overflow-y: auto;
            border: 1px solid #f1f5f9;
            border-radius: 12px;
        }
        .staff-item {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 12px 14px;
            border-bottom: 1px solid #f1f5f9;
            gap: 12px;
            transition: background 0.15s;
        }
        .staff-item:last-child { border-bottom: none; }
        .staff-item:hover { background: #f8fafc; }
        .staff-user-meta {
            display: flex;
            align-items: center;
            gap: 12px;
            min-width: 0;
            flex: 1;
        }
        .staff-avatar-mini {
            width: 36px;
            height: 36px;
            border-radius: 50%;
            background: #e2e8f0;
            color: #334155;
            display: flex;
            align-items: center;
            justify-content: center;
            font-weight: 700;
            font-size: 0.82rem;
            flex-shrink: 0;
        }
        .staff-names {
            min-width: 0;
            line-height: 1.3;
        }
        .staff-names strong {
            display: block;
            font-size: 0.88rem;
            color: #0f172a;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .staff-names span {
            font-size: 0.74rem;
            color: #64748b;
        }
        .btn-staff-open {
            padding: 6px 12px;
            border-radius: 8px;
            font-size: 0.78rem;
            font-weight: 700;
            color: #2563eb;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 5px;
            transition: all 0.15s;
            white-space: nowrap;
        }
        .btn-staff-open:hover {
            background: #2563eb;
            color: #ffffff;
            border-color: #2563eb;
        }

        /* Unconnected Setup Box */
        .setup-dual-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            margin-top: 18px;
        }
        @media (max-width: 768px) {
            .setup-dual-grid { grid-template-columns: 1fr; }
        }
        .setup-card {
            background: #ffffff;
            border: 2px dashed #cbd5e1;
            border-radius: 16px;
            padding: 28px;
            text-align: center;
            display: flex;
            flex-direction: column;
            justify-content: space-between;
        }
        .setup-card.primary-choice {
            border-style: solid;
            border-color: #93c5fd;
            background: linear-gradient(180deg, #f0f7ff 0%, #ffffff 100%);
        }

        /* Flash message banner */
        .workspace-alert {
            padding: 14px 18px;
            border-radius: 10px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 0.9rem;
            font-weight: 600;
        }
        .workspace-alert.success {
            background: #ecfdf5;
            color: #065f46;
            border: 1px solid #a7f3d0;
        }
        .workspace-alert.error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fecaca;
        }

        /* Toast notification */
        #shareToast {
            position: fixed;
            bottom: 24px;
            right: 24px;
            background: #0f172a;
            color: #ffffff;
            padding: 12px 20px;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.25);
            display: none;
            align-items: center;
            gap: 10px;
            font-size: 0.88rem;
            font-weight: 600;
            z-index: 99999;
            border: 1px solid rgba(255,255,255,0.15);
        }

        /* Edit Modal */
        .workspace-modal-backdrop {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(15, 23, 42, 0.65);
            backdrop-filter: blur(4px);
            z-index: 99990;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .workspace-modal-backdrop.is-open {
            display: flex;
        }
        .workspace-modal-card {
            background: #ffffff;
            border-radius: 16px;
            max-width: 620px;
            width: 100%;
            box-shadow: 0 20px 45px rgba(0,0,0,0.3);
            overflow: hidden;
            animation: modalPop 0.2s ease-out;
        }
        @keyframes modalPop {
            from { transform: scale(0.95); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
        .modal-head {
            padding: 20px 24px;
            background: #f8fafc;
            border-bottom: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
        }
        .modal-head h3 {
            margin: 0;
            font-size: 1.15rem;
            font-weight: 800;
            color: #0f172a;
        }
        .modal-body {
            padding: 24px;
            max-height: 75vh;
            overflow-y: auto;
        }
        .modal-foot {
            padding: 16px 24px;
            background: #f8fafc;
            border-top: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: flex-end;
            gap: 12px;
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
                    <h1 class="page-title"><i class="fa-brands fa-google-drive" style="color:#4285F4; margin-right:8px;"></i>Google Drive Workspace</h1>
                    <p class="page-sub"><?= sanitize($schoolName) ?> &bull; Automated Cloud Archive &amp; Staff Collaboration</p>
                </div>
                <div class="header-actions">
                    <div class="admin-user">
                        <div class="user-avatar"><?= htmlspecialchars(mb_strtoupper(mb_substr(trim($currentUser['full_name'] ?: $adminUser), 0, 1))) ?></div>
                        <div class="user-meta">
                            <strong><?= htmlspecialchars($currentUser['full_name'] ?: $adminUser) ?></strong>
                            <small><?= htmlspecialchars(ucfirst($adminRole)) ?></small>
                        </div>
                    </div>
                </div>
            </header>

            <div class="admin-content">
                <!-- Flash messages -->
                <?php if ($flashSuccess): ?>
                    <div class="workspace-alert success">
                        <i class="fa-solid fa-circle-check"></i>
                        <span><?= htmlspecialchars($flashSuccess) ?></span>
                    </div>
                <?php endif; ?>
                <?php if ($flashError): ?>
                    <div class="workspace-alert error">
                        <i class="fa-solid fa-triangle-exclamation"></i>
                        <span><?= htmlspecialchars($flashError) ?></span>
                    </div>
                <?php endif; ?>

                <?php if ($isWorkspaceConnected): ?>
                    <!-- Connected Workspace Hero Banner -->
                    <div class="workspace-hero">
                        <div class="hero-info">
                            <?php if ($safeGoogleAvatar !== ''): ?>
                                <img src="<?= htmlspecialchars($safeGoogleAvatar) ?>" alt="Avatar" class="hero-avatar">
                            <?php else: ?>
                                <div class="hero-avatar">
                                    <?= htmlspecialchars(mb_strtoupper(mb_substr(trim($currentUser['full_name'] ?: $adminUser), 0, 1))) ?>
                                </div>
                            <?php endif; ?>
                            <div>
                                <h2 class="hero-title">
                                    <?= htmlspecialchars($currentUser['full_name'] ?: $adminUser) ?>'s Academic Workspace
                                    <?php if ($isGoogleOAuthConnected): ?>
                                        <span class="badge" style="background:#10b981; font-size:0.68rem; padding:4px 9px; border-radius:12px; font-weight:700;">
                                            <i class="fa-solid fa-bolt"></i> Google OAuth Active
                                        </span>
                                    <?php else: ?>
                                        <span class="badge" style="background:#0284c7; font-size:0.68rem; padding:4px 9px; border-radius:12px; font-weight:700;">
                                            <i class="fa-solid fa-link"></i> Shared Drive Linked
                                        </span>
                                    <?php endif; ?>
                                </h2>
                                <p class="hero-sub">
                                    <span><i class="fa-solid fa-envelope"></i> <?= htmlspecialchars($currentUser['email']) ?></span>
                                    <span><i class="fa-solid fa-folder-tree"></i> Academic Year <?= htmlspecialchars(rcGetActiveAcademicYear()) ?></span>
                                    <span><i class="fa-solid fa-users"></i> <?= (int)$connectedColleagueCount ?> Colleagues Active</span>
                                </p>
                            </div>
                        </div>

                        <div class="hero-actions">
                            <a href="<?= htmlspecialchars($driveFolderUrl ?: '#') ?>" target="_blank" rel="noopener noreferrer" class="btn-drive-main">
                                <i class="fa-solid fa-arrow-up-right-from-square"></i> Open in Google Drive
                            </a>
                            <button type="button" class="btn-drive-action" onclick="copyDriveLink(<?= htmlspecialchars(json_encode($driveFolderUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>)">
                                <i class="fa-solid fa-link"></i> Copy Link
                            </button>
                            <button type="button" class="btn-drive-action" onclick="openEditModal()">
                                <i class="fa-solid fa-pen-to-square"></i> Edit Links
                            </button>
                            <button type="button" class="btn-drive-action btn-drive-danger" onclick="openUnlinkModal()">
                                <i class="fa-solid fa-link-slash"></i> Unlink
                            </button>
                        </div>
                    </div>

                    <!-- Organized Subfolders Grid -->
                    <div style="display:flex; align-items:center; justify-content:space-between; margin-bottom:14px; flex-wrap:wrap; gap:10px;">
                        <h3 style="font-size:1.15rem; font-weight:800; color:#0f172a; margin:0;">
                            <i class="fa-solid fa-folder-open" style="color:#2563eb; margin-right:8px;"></i>Academic Workspace Subfolders
                        </h3>
                        <span style="font-size:0.82rem; color:#64748b;">Click any card to open folder in Google Drive</span>
                    </div>

                    <div class="folder-grid">
                        <!-- 1. Semester Marksheets -->
                        <a href="<?= htmlspecialchars(($subfolders['marks']['url'] ?? '') ?: ($driveFolderUrl ?: '#')) ?>" target="_blank" rel="noopener noreferrer" class="folder-card">
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

                        <!-- 2. Unit Tests -->
                        <a href="<?= htmlspecialchars(($subfolders['unit_tests']['url'] ?? '') ?: ($driveFolderUrl ?: '#')) ?>" target="_blank" rel="noopener noreferrer" class="folder-card">
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

                        <!-- 3. Class Analytics -->
                        <a href="<?= htmlspecialchars(($subfolders['analytics']['url'] ?? '') ?: ($driveFolderUrl ?: '#')) ?>" target="_blank" rel="noopener noreferrer" class="folder-card">
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

                        <!-- 4. Student Report Cards -->
                        <a href="<?= htmlspecialchars(($subfolders['reports']['url'] ?? '') ?: ($driveFolderUrl ?: '#')) ?>" target="_blank" rel="noopener noreferrer" class="folder-card">
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

                    <!-- 2-Column: Upload Document + Staff Directory & Sharing -->
                    <div class="collab-grid">
                        <!-- Left Panel: Direct Document Upload to Workspace -->
                        <div class="panel-card">
                            <div class="panel-header">
                                <div class="panel-icon emerald"><i class="fa-solid fa-cloud-arrow-up"></i></div>
                                <div>
                                    <h3>Upload Document to Workspace</h3>
                                    <p>File exams, marksheets, or syllabus documents straight into your workspace archive.</p>
                                </div>
                            </div>

                            <form method="POST" action="shared_workspace.php" enctype="multipart/form-data" style="display:flex; flex-direction:column; gap:16px;">
                                <?= csrfField() ?>
                                <input type="hidden" name="action" value="upload_file">

                                <div>
                                    <label class="input-label" for="file_category">Target Folder Category</label>
                                    <select name="file_category" id="file_category" class="form-control-custom" required>
                                        <option value="marks">01. Semester Marksheets &amp; Results</option>
                                        <option value="unit_tests">02. Unit Tests &amp; Exams</option>
                                        <option value="analytics">03. Class Analytics Reports</option>
                                        <option value="reports">04. Student Report Cards &amp; Slips</option>
                                        <option value="general">05. General Workspace Documents</option>
                                    </select>
                                </div>

                                <div>
                                    <label class="input-label" for="workspace_file">Choose Document File (PDF, Excel, Word, CSV, ZIP)</label>
                                    <input type="file" name="workspace_file" id="workspace_file" class="form-control-custom" style="padding-top:8px;" required>
                                </div>

                                <button type="submit" class="btn-drive-main" style="justify-content:center; margin-top:4px;">
                                    <i class="fa-solid fa-arrow-up-from-bracket"></i> Upload to Archive
                                </button>
                            </form>
                        </div>

                        <!-- Right Panel: Share Workspace with Fellow Teachers -->
                        <div class="panel-card">
                            <div class="panel-header">
                                <div class="panel-icon purple"><i class="fa-solid fa-user-plus"></i></div>
                                <div>
                                    <h3>Share Workspace with Fellow Teachers</h3>
                                    <p>Grant folder access to a colleague so they can review or co-grade marks.</p>
                                </div>
                            </div>

                            <form id="shareForm" onsubmit="handleShareSubmit(event)" style="display:flex; flex-direction:column; gap:16px;">
                                <?= csrfField() ?>
                                <input type="hidden" name="folder_id" value="<?= htmlspecialchars($driveFolderId) ?>">

                                <div>
                                    <label class="input-label" for="colleague_select">Select Staff Member</label>
                                    <select class="form-control-custom" id="colleague_select" required onchange="handleColleagueChange(this)">
                                        <option value="">-- Choose a teacher or administrator --</option>
                                        <?php foreach ($colleagues as $staff): ?>
                                            <option value="<?= htmlspecialchars($staff['email']) ?>">
                                                <?= htmlspecialchars($staff['full_name']) ?> (<?= htmlspecialchars($staff['email']) ?>) - <?= strtoupper($staff['role']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <option value="custom">Enter another Google Email...</option>
                                    </select>
                                </div>

                                <div id="customEmailGroup" style="display:none;">
                                    <label class="input-label" for="custom_email">Colleague Google Email</label>
                                    <input type="email" id="custom_email" class="form-control-custom" placeholder="e.g. teacher@gmail.com">
                                </div>

                                <div>
                                    <label class="input-label" for="share_role">Access Level</label>
                                    <select class="form-control-custom" id="share_role" name="role">
                                        <option value="reader">Viewer (Read Only)</option>
                                        <option value="writer">Editor (Can Co-Grade &amp; Upload)</option>
                                    </select>
                                </div>

                                <button type="submit" class="btn-drive-main" id="btnShareSubmit" style="background:#0f172a; justify-content:center;">
                                    <i class="fa-solid fa-paper-plane"></i> Send Share Invitation
                                </button>
                            </form>
                        </div>
                    </div>

                    <!-- Uploaded Workspace Files Archive -->
                    <div class="panel-card" style="margin-bottom:28px;">
                        <div class="panel-header" style="justify-content:space-between; flex-wrap:wrap; gap:10px;">
                            <div style="display:flex; align-items:center; gap:14px;">
                                <div class="panel-icon emerald"><i class="fa-solid fa-box-archive"></i></div>
                                <div>
                                    <h3>Uploaded Workspace Files Archive</h3>
                                    <p>Files uploaded directly to portal workspace storage (authenticated download only).</p>
                                </div>
                            </div>
                            <span style="font-size:0.82rem; color:#64748b; font-weight:600;">
                                <?= count($workspaceFiles) ?> file<?= count($workspaceFiles) === 1 ? '' : 's' ?> stored
                            </span>
                        </div>

                        <div class="staff-directory-wrap">
                            <?php if (empty($workspaceFiles)): ?>
                                <p style="padding:20px; color:#64748b; text-align:center; margin:0;">No workspace files uploaded yet.</p>
                            <?php else: ?>
                                <?php foreach ($workspaceFiles as $wf):
                                    $kb = $wf['size'] >= 1048576
                                        ? number_format($wf['size'] / 1048576, 2) . ' MB'
                                        : max(1, (int)round($wf['size'] / 1024)) . ' KB';
                                    $dlHref = 'shared_workspace.php?action=download_file&category=' . urlencode($wf['category']) . '&file=' . urlencode($wf['filename']);
                                ?>
                                    <div class="staff-item">
                                        <div class="staff-user-meta">
                                            <div class="staff-avatar-mini" style="background:#ecfdf5; color:#059669;">
                                                <i class="fa-solid fa-file-lines"></i>
                                            </div>
                                            <div class="staff-names">
                                                <strong><?= htmlspecialchars($wf['filename']) ?></strong>
                                                <span><?= htmlspecialchars($wf['cat_label']) ?> &bull; <?= htmlspecialchars($kb) ?> &bull; <?= htmlspecialchars(date('d M Y, h:i A', $wf['mtime'])) ?></span>
                                            </div>
                                        </div>
                                        <div>
                                            <a href="<?= htmlspecialchars($dlHref) ?>" class="btn-staff-open">
                                                <i class="fa-solid fa-download"></i> <span>Download</span>
                                            </a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>

                    <!-- Staff Collaboration Directory -->
                    <div class="panel-card">
                        <div class="panel-header" style="justify-content:space-between; flex-wrap:wrap; gap:10px;">
                            <div style="display:flex; align-items:center; gap:14px;">
                                <div class="panel-icon"><i class="fa-solid fa-address-book"></i></div>
                                <div>
                                    <h3>Staff Shared Workspace Directory</h3>
                                    <p>Browse active Google Drive workspaces shared by other teachers and faculty.</p>
                                </div>
                            </div>
                            <div style="width:260px;">
                                <input type="text" id="staffSearchInput" class="form-control-custom" placeholder="Search teacher by name..." oninput="filterStaffDirectory(this.value)">
                            </div>
                        </div>

                        <div class="staff-directory-wrap">
                            <?php if (empty($colleagues)): ?>
                                <p style="padding:20px; color:#64748b; text-align:center; margin:0;">No other staff members found.</p>
                            <?php else: ?>
                                <div id="staffList">
                                    <?php foreach ($colleagues as $staff): 
                                        $safeStaffUrl = hccValidateDriveUrl($staff['google_drive_folder_url'] ?? '');
                                        $hasDrive = ($safeStaffUrl !== null);
                                    ?>
                                        <div class="staff-item" data-search="<?= strtolower(htmlspecialchars($staff['full_name'] . ' ' . $staff['email'] . ' ' . $staff['role'])) ?>">
                                            <div class="staff-user-meta">
                                                <div class="staff-avatar-mini">
                                                    <?= htmlspecialchars(mb_strtoupper(mb_substr(trim($staff['full_name']), 0, 1))) ?>
                                                </div>
                                                <div class="staff-names">
                                                    <strong><?= htmlspecialchars($staff['full_name']) ?></strong>
                                                    <span><?= htmlspecialchars($staff['email']) ?> &bull; <?= strtoupper($staff['role']) ?></span>
                                                </div>
                                            </div>
                                            <div style="display:flex; align-items:center; gap:8px;">
                                                <?php if ($hasDrive): ?>
                                                    <span class="badge" style="background:#ecfdf5; color:#059669; border:1px solid #a7f3d0; font-size:0.72rem; padding:4px 8px; border-radius:6px;">
                                                        <i class="fa-solid fa-circle-check"></i> Drive Active
                                                    </span>
                                                    <a href="<?= htmlspecialchars($safeStaffUrl) ?>" target="_blank" rel="noopener noreferrer" class="btn-staff-open" title="Open Teacher's Google Drive">
                                                        <span>Open</span> <i class="fa-solid fa-arrow-up-right-from-square"></i>
                                                    </a>
                                                    <button type="button" class="btn-staff-open" style="background:#f8fafc; color:#475569; border-color:#cbd5e1;" onclick="copyDriveLink(<?= htmlspecialchars(json_encode($safeStaffUrl, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8') ?>)" title="Copy Link">
                                                        <i class="fa-solid fa-link"></i>
                                                    </button>
                                                <?php else: ?>
                                                    <span class="badge" style="background:#f1f5f9; color:#94a3b8; font-size:0.72rem; padding:4px 8px; border-radius:6px;">
                                                        Not Linked Yet
                                                    </span>
                                                <?php endif; ?>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>

                <?php else: ?>
                    <!-- Unconnected / Welcome Setup Screen -->
                    <div style="text-align:center; max-width:860px; margin:20px auto 40px auto;">
                        <div style="width:70px; height:70px; border-radius:20px; background:#eff6ff; color:#2563eb; display:inline-flex; align-items:center; justify-content:center; font-size:2.2rem; margin-bottom:16px; box-shadow:0 8px 20px rgba(37,99,235,0.15);">
                            <i class="fa-brands fa-google-drive"></i>
                        </div>
                        <h2 style="font-size:1.75rem; font-weight:800; color:#0f172a; margin:0 0 8px 0; letter-spacing:-0.02em;">
                            Setup Your Academic Drive Workspace
                        </h2>
                        <p style="font-size:0.95rem; color:#64748b; line-height:1.6; margin:0 0 28px 0;">
                            Connect your Google Drive to collaborate with other teachers and organize marks, exams, and analytics into structured cloud folders. Choose either instant link or OAuth sign-in below.
                        </p>

                        <div class="setup-dual-grid">
                            <!-- Choice 1: Direct Link (Works immediately without OAuth setup) -->
                            <div class="setup-card primary-choice">
                                <div>
                                    <div style="display:inline-flex; align-items:center; gap:6px; background:#dbeafe; color:#1d4ed8; font-size:0.72rem; font-weight:800; padding:4px 10px; border-radius:999px; text-transform:uppercase; margin-bottom:14px;">
                                        <i class="fa-solid fa-bolt"></i> Instant &bull; No API Credentials Needed
                                    </div>
                                    <h3 style="font-size:1.2rem; font-weight:800; color:#0f172a; margin:0 0 8px 0;">
                                        Paste Google Drive Link
                                    </h3>
                                    <p style="font-size:0.86rem; color:#64748b; line-height:1.5; margin:0 0 20px 0;">
                                        Paste any Google Drive Shared Folder or Personal Folder link. Your workspace and subfolders will activate immediately.
                                    </p>
                                </div>

                                <form method="POST" action="shared_workspace.php">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="action" value="save_folder">
                                    <div style="margin-bottom:14px; text-align:left;">
                                        <label class="input-label" for="quick_folder_url">Google Drive Folder URL / ID</label>
                                        <input type="text" name="google_drive_folder_url" id="quick_folder_url" class="form-control-custom" placeholder="https://drive.google.com/drive/folders/..." required>
                                    </div>
                                    <button type="submit" class="btn-drive-main" style="width:100%; justify-content:center;">
                                        <i class="fa-solid fa-link"></i> Link &amp; Activate Workspace
                                    </button>
                                </form>
                            </div>

                            <!-- Choice 2: Google OAuth 2.0 Sign-In -->
                            <div class="setup-card">
                                <div>
                                    <div style="display:inline-flex; align-items:center; gap:6px; background:#f1f5f9; color:#475569; font-size:0.72rem; font-weight:800; padding:4px 10px; border-radius:999px; text-transform:uppercase; margin-bottom:14px;">
                                        <i class="fa-brands fa-google"></i> Automated Sync
                                    </div>
                                    <h3 style="font-size:1.2rem; font-weight:800; color:#0f172a; margin:0 0 8px 0;">
                                        Sign in with Google OAuth
                                    </h3>
                                    <p style="font-size:0.86rem; color:#64748b; line-height:1.5; margin:0 0 20px 0;">
                                        Uses your school Google Cloud Client ID to automatically provision organized folders and live permission syncing.
                                    </p>
                                </div>

                                <div>
                                    <?php if ($googleConfigured): ?>
                                        <a href="<?= htmlspecialchars($connectGoogleUrl) ?>" class="btn-drive-main" style="width:100%; justify-content:center; background:#0f172a;">
                                            <i class="fa-brands fa-google"></i> Connect Google Account
                                        </a>
                                    <?php else: ?>
                                        <div style="background:#fffbeb; border:1px solid #fde68a; color:#92400e; padding:12px; border-radius:10px; font-size:0.82rem; text-align:left;">
                                            <i class="fa-solid fa-circle-info"></i> Google OAuth API credentials are not yet configured on this server. You can paste your folder link on the left or add Client ID in <a href="account.php#recovery" style="color:#7a2238; font-weight:700;">Account &amp; Security</a>.
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </main>
    </div>

    <!-- Edit Folder Links Modal -->
    <div class="workspace-modal-backdrop" id="editLinksModal">
        <div class="workspace-modal-card">
            <div class="modal-head">
                <h3><i class="fa-solid fa-pen-to-square text-primary me-2"></i>Configure Workspace Folder Links</h3>
                <button type="button" onclick="closeEditModal()" style="background:none; border:none; font-size:1.3rem; color:#64748b; cursor:pointer;">&times;</button>
            </div>
            <form method="POST" action="shared_workspace.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="save_folder">
                <div class="modal-body">
                    <div style="margin-bottom:16px;">
                        <label class="input-label" for="modal_root_url">Main Google Drive Folder URL</label>
                        <input type="text" name="google_drive_folder_url" id="modal_root_url" class="form-control-custom" value="<?= htmlspecialchars($driveFolderUrl) ?>" required>
                        <span style="font-size:0.75rem; color:#64748b; margin-top:4px; display:block;">Parent workspace folder in Google Drive.</span>
                    </div>

                    <div style="border-top:1px solid #f1f5f9; padding-top:14px; margin-bottom:14px;">
                        <strong style="font-size:0.86rem; color:#0f172a; display:block; margin-bottom:10px;">Optional: Custom Subfolder Links</strong>
                        
                        <div style="margin-bottom:12px;">
                            <label class="input-label" for="sub_marks">Semester Marksheets Folder</label>
                            <input type="text" name="subfolder_marks" id="sub_marks" class="form-control-custom" value="<?= htmlspecialchars($subfolders['marks']['url'] ?? '') ?>" placeholder="Leave blank to use main folder">
                        </div>

                        <div style="margin-bottom:12px;">
                            <label class="input-label" for="sub_ut">Unit Tests &amp; Exams Folder</label>
                            <input type="text" name="subfolder_unit_tests" id="sub_ut" class="form-control-custom" value="<?= htmlspecialchars($subfolders['unit_tests']['url'] ?? '') ?>" placeholder="Leave blank to use main folder">
                        </div>

                        <div style="margin-bottom:12px;">
                            <label class="input-label" for="sub_an">Class Analytics Reports Folder</label>
                            <input type="text" name="subfolder_analytics" id="sub_an" class="form-control-custom" value="<?= htmlspecialchars($subfolders['analytics']['url'] ?? '') ?>" placeholder="Leave blank to use main folder">
                        </div>

                        <div style="margin-bottom:12px;">
                            <label class="input-label" for="sub_rc">Student Report Cards Folder</label>
                            <input type="text" name="subfolder_reports" id="sub_rc" class="form-control-custom" value="<?= htmlspecialchars($subfolders['reports']['url'] ?? '') ?>" placeholder="Leave blank to use main folder">
                        </div>
                    </div>
                </div>
                <div class="modal-foot">
                    <button type="button" onclick="closeEditModal()" class="btn-drive-action" style="background:#f1f5f9; color:#475569; border-color:#cbd5e1;">Cancel</button>
                    <button type="submit" class="btn-drive-main">Save Changes</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Confirm Unlink Modal -->
    <div class="workspace-modal-backdrop" id="unlinkModal">
        <div class="workspace-modal-card" style="max-width:480px;">
            <div class="modal-head">
                <h3 style="color:#dc2626;"><i class="fa-solid fa-triangle-exclamation me-2"></i>Unlink Google Drive</h3>
                <button type="button" onclick="closeUnlinkModal()" style="background:none; border:none; font-size:1.3rem; color:#64748b; cursor:pointer;">&times;</button>
            </div>
            <form method="POST" action="shared_workspace.php">
                <?= csrfField() ?>
                <input type="hidden" name="action" value="unlink_folder">
                <div class="modal-body">
                    <p style="font-size:0.92rem; color:#475569; line-height:1.5; margin:0 0 16px 0;">
                        Are you sure you want to unlink this Google Drive workspace? Your files in Google Drive will remain completely untouched, but your account links in this portal will be disconnected.
                    </p>
                    <?php if ($isGoogleOAuthConnected): ?>
                        <label style="display:flex; align-items:center; gap:8px; font-size:0.84rem; color:#334155; cursor:pointer;">
                            <input type="checkbox" name="clear_oauth" value="1" checked>
                            <span>Also disconnect Google OAuth login from my account</span>
                        </label>
                    <?php endif; ?>
                </div>
                <div class="modal-foot">
                    <button type="button" onclick="closeUnlinkModal()" class="btn-drive-action" style="background:#f1f5f9; color:#475569; border-color:#cbd5e1;">Cancel</button>
                    <button type="submit" class="btn-drive-main" style="background:#dc2626;">Yes, Unlink Workspace</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Toast Notification -->
    <div id="shareToast">
        <i class="fa-solid fa-circle-check" style="color:#4ade80;"></i>
        <span id="toastMsg"></span>
    </div>

    <script>
    function copyDriveLink(url) {
        if (!url) return;
        if (navigator.clipboard && navigator.clipboard.writeText) {
            navigator.clipboard.writeText(url).then(() => {
                showToast('Google Drive link copied to clipboard!');
            }).catch(() => {
                fallbackCopy(url);
            });
        } else {
            fallbackCopy(url);
        }
    }

    function fallbackCopy(url) {
        const temp = document.createElement('textarea');
        temp.value = url;
        document.body.appendChild(temp);
        temp.select();
        try {
            document.execCommand('copy');
            showToast('Google Drive link copied to clipboard!');
        } catch (e) {
            prompt('Copy link:', url);
        }
        document.body.removeChild(temp);
    }

    function openEditModal() {
        document.getElementById('editLinksModal').classList.add('is-open');
    }
    function closeEditModal() {
        document.getElementById('editLinksModal').classList.remove('is-open');
    }

    function openUnlinkModal() {
        document.getElementById('unlinkModal').classList.add('is-open');
    }
    function closeUnlinkModal() {
        document.getElementById('unlinkModal').classList.remove('is-open');
    }

    function handleColleagueChange(sel) {
        const customGroup = document.getElementById('customEmailGroup');
        const customInput = document.getElementById('custom_email');
        if (sel.value === 'custom') {
            customGroup.style.display = 'block';
            customInput.required = true;
            customInput.focus();
        } else {
            customGroup.style.display = 'none';
            customInput.required = false;
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
        btn.innerHTML = '<i class="fa-solid fa-spinner fa-spin"></i> Sending...';

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
                alert(data.error || 'Could not share folder via Google API. You can still copy the folder link and send it directly.');
            }
        } catch (err) {
            alert('Network error communicating with Google Drive share service.');
        } finally {
            btn.disabled = false;
            btn.innerHTML = origText;
        }
    }

    function filterStaffDirectory(q) {
        const query = q.trim().toLowerCase();
        const items = document.querySelectorAll('.staff-item');
        items.forEach(el => {
            const searchData = el.getAttribute('data-search') || '';
            if (query === '' || searchData.includes(query)) {
                el.style.display = 'flex';
            } else {
                el.style.display = 'none';
            }
        });
    }

    function showToast(msg) {
        const toast = document.getElementById('shareToast');
        const text = document.getElementById('toastMsg');
        text.textContent = msg;
        toast.style.display = 'flex';
        setTimeout(() => {
            toast.style.display = 'none';
        }, 4000);
    }
    </script>
    <script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= (is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 0) ?>"></script>
</body>
</html>
