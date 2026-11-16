<?php
/**
 * HCC Automation System — Google Drive Auto-Filing Upload API
 * Automatically receives generated PDFs, marksheets, and report cards,
 * and files them into the teacher's respective Google Drive subfolder.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/google_drive.php';
require_once __DIR__ . '/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!verifyCSRFToken()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'CSRF verification failed.']);
    exit;
}

$db = getDB();
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$adminId]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'User not found.']);
    exit;
}

$rootFolderId = $currentUser['google_drive_folder_id'] ?? ($_SESSION['google_drive_folder_id'] ?? '');
if (!$rootFolderId) {
    echo json_encode(['success' => false, 'reason' => 'not_connected', 'message' => 'Google Drive workspace is not connected for this account.']);
    exit;
}

$filename      = basename(str_replace(["\r", "\n", "\0"], '', trim((string)($_POST['filename'] ?? ''))));
$contentBase64 = trim((string)($_POST['content_base64'] ?? ''));
$category      = str_replace(["\r", "\n", "\0"], '', trim((string)($_POST['category'] ?? 'analytics')));
$mimeType      = strtolower(str_replace(["\r", "\n", "\0"], '', trim((string)($_POST['mime_type'] ?? 'application/pdf'))));

if ($filename === '' || $contentBase64 === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'File name and content are required.']);
    exit;
}

if (!HCCGoogleDrive::isAllowedUploadMimeType($mimeType)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unsupported MIME type.']);
    exit;
}

$maxBytes = 25 * 1024 * 1024; // 25MB
if (strlen($contentBase64) > (int)ceil($maxBytes * 4 / 3) + 1024) {
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'File exceeds maximum upload size (25MB).']);
    exit;
}

$fileContent = base64_decode($contentBase64, true);
if ($fileContent === false || strlen($fileContent) === 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid file payload.']);
    exit;
}
if (strlen($fileContent) > $maxBytes) {
    http_response_code(413);
    echo json_encode(['success' => false, 'error' => 'File exceeds maximum upload size (25MB).']);
    exit;
}

// Determine target subfolder
$subfolders = [];
if (!empty($currentUser['google_drive_subfolders'])) {
    $subfolders = json_decode($currentUser['google_drive_subfolders'], true) ?: [];
} elseif (!empty($_SESSION['google_drive_subfolders'])) {
    $subfolders = $_SESSION['google_drive_subfolders'];
}

$targetFolderId = (string)($subfolders[$category]['id'] ?? $rootFolderId);
if (!HCCGoogleDrive::isValidDriveId($targetFolderId)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid target Google Drive folder ID.']);
    exit;
}

// Initialize Google Drive client & ensure fresh access token
$client = new HCCGoogleDrive();
$accessToken = $_SESSION['google_access_token'] ?? null;

if (!$accessToken && !empty($currentUser['google_refresh_token'])) {
    $accessToken = $client->refreshAccessToken($currentUser['google_refresh_token']);
    if ($accessToken) {
        $_SESSION['google_access_token'] = $accessToken;
    }
}

if (!$accessToken) {
    echo json_encode(['success' => false, 'reason' => 'token_expired', 'error' => 'Google Drive authorization expired.']);
    exit;
}

$uploadResult = $client->uploadFile($accessToken, $targetFolderId, $filename, $fileContent, $mimeType);

// If upload failed, try refreshing token once and retrying
if (!$uploadResult && !empty($currentUser['google_refresh_token'])) {
    $accessToken = $client->refreshAccessToken($currentUser['google_refresh_token']);
    if ($accessToken) {
        $_SESSION['google_access_token'] = $accessToken;
        $uploadResult = $client->uploadFile($accessToken, $targetFolderId, $filename, $fileContent, $mimeType);
    }
}

if ($uploadResult && !empty($uploadResult['id'])) {
    rcLogAudit('drive_file_auto_filed', 'workspace', "Auto-filed file '{$filename}' to Drive folder {$targetFolderId} for user {$currentUser['username']}");
    echo json_encode([
        'success'   => true,
        'file_id'   => $uploadResult['id'],
        'file_name' => $filename,
        'drive_url' => $uploadResult['webViewLink'] ?? ("https://drive.google.com/file/d/" . $uploadResult['id'] . "/view")
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error'   => 'Could not upload file to Google Drive.'
    ]);
}
