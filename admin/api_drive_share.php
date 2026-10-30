<?php
/**
 * HCC Automation System — Google Drive Share API
 * Grants folder permissions to a colleague's Google Account via Google Drive API v3.
 */

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/google_drive.php';
require_once __DIR__ . '/auth_check.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit;
}

if (!verifyCSRFToken()) {
    echo json_encode(['success' => false, 'error' => 'Security token invalid or expired. Please refresh the page.']);
    exit;
}

$db = getDB();
$adminId = (int)($_SESSION['admin_id'] ?? 0);
$stmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$adminId]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser) {
    echo json_encode(['success' => false, 'error' => 'User account not found.']);
    exit;
}

$colleagueEmail = trim($_POST['colleague_email'] ?? '');
$role           = in_array($_POST['role'] ?? '', ['reader', 'writer'], true) ? $_POST['role'] : 'reader';
$folderId       = trim($_POST['folder_id'] ?? ($currentUser['google_drive_folder_id'] ?? ''));

if ($colleagueEmail === '' || !filter_var($colleagueEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['success' => false, 'error' => 'Please provide a valid colleague email address.']);
    exit;
}

if ($folderId === '') {
    echo json_encode(['success' => false, 'error' => 'No Google Drive workspace folder found for your account. Please re-authenticate with Google.']);
    exit;
}

$client = new HCCGoogleDrive();
$accessToken = $_SESSION['google_access_token'] ?? null;

// If access token is missing or expired, attempt refresh
if (!$accessToken && !empty($currentUser['google_refresh_token'])) {
    $accessToken = $client->refreshAccessToken($currentUser['google_refresh_token']);
    if ($accessToken) {
        $_SESSION['google_access_token'] = $accessToken;
    }
}

if (!$accessToken) {
    echo json_encode(['success' => false, 'error' => 'Google authorization expired. Please sign in with Google again to refresh your session.']);
    exit;
}

$shareResult = $client->shareFolderOrFile($accessToken, $folderId, $colleagueEmail, $role, true);

// If token expired during request, refresh and retry once
if (!$shareResult && !empty($currentUser['google_refresh_token'])) {
    $accessToken = $client->refreshAccessToken($currentUser['google_refresh_token']);
    if ($accessToken) {
        $_SESSION['google_access_token'] = $accessToken;
        $shareResult = $client->shareFolderOrFile($accessToken, $folderId, $colleagueEmail, $role, true);
    }
}

if ($shareResult && !empty($shareResult['id'])) {
    rcLogAudit('drive_folder_shared', 'workspace', "User {$currentUser['username']} shared Drive folder {$folderId} with {$colleagueEmail} as {$role}");
    echo json_encode([
        'success' => true,
        'message' => "Successfully shared workspace with {$colleagueEmail}! They can now access it in Google Drive under 'Shared with me'.",
        'permission_id' => $shareResult['id']
    ]);
} else {
    echo json_encode([
        'success' => false,
        'error' => "Google Drive was unable to share the folder with {$colleagueEmail}. Ensure the email address is associated with a valid Google Account."
    ]);
}
