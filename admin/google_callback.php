<?php
/**
 * HCC Automation System — Google OAuth 2.0 Callback Controller
 * Handles token exchange, automatic user account provisioning,
 * automated Google Drive workspace creation, and session initialization.
 */

require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/google_drive.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// 1. Check for OAuth errors or user cancellation
if (!empty($_GET['error'])) {
    $err = trim($_GET['error']);
    $desc = trim($_GET['error_description'] ?? '');
    error_log("HCC [Google OAuth] Google returned error: {$err} - {$desc}");
    
    if ($err === 'access_denied') {
        $msg = 'Google sign-in was cancelled.';
    } else {
        $msg = 'Google authorization failed: ' . ($desc ?: $err);
    }
    // Whitelisted code only — never reflect free text through the URL.
    header('Location: login.php?oauth_code=' . ($err === 'access_denied' ? 'cancelled' : 'google'));
    exit;
}

// 2. Validate CSRF state parameter
$state = $_GET['state'] ?? '';
$savedState = $_SESSION['oauth_state'] ?? '';
unset($_SESSION['oauth_state']);

if ($state === '' || $savedState === '' || !hash_equals($savedState, $state)) {
    error_log('HCC [Google OAuth] State token mismatch or expired.');
    header('Location: login.php?oauth_code=state');
    exit;
}

// 3. Ensure authorization code is present
$code = trim($_GET['code'] ?? '');
if ($code === '') {
    header('Location: login.php?oauth_code=code');
    exit;
}

// 4. Exchange code for tokens
$client = new HCCGoogleDrive();
$tokenData = $client->exchangeCode($code);

if (!$tokenData || empty($tokenData['access_token'])) {
    header('Location: login.php?oauth_code=token');
    exit;
}

$accessToken  = $tokenData['access_token'];
$refreshToken = $tokenData['refresh_token'] ?? null;
$userInfo     = $tokenData['user'] ?? null;

if (!$userInfo || empty($userInfo['email'])) {
    header('Location: login.php?oauth_code=profile');
    exit;
}

$googleEmail  = strtolower(trim($userInfo['email']));
$googleId     = trim($userInfo['sub'] ?? '');
$googleName   = trim($userInfo['name'] ?? ($userInfo['given_name'] ?? 'Teacher'));
$googleAvatar = trim($userInfo['picture'] ?? '');

$db = getDB();

// 5. Match or Auto-Provision User in database
// Priority 1: Match by google_id
$user = null;
if ($googleId !== '') {
    $stmt = $db->prepare("SELECT * FROM users WHERE google_id = ? LIMIT 1");
    $stmt->execute([$googleId]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Priority 2: Match by verified email address
if (!$user) {
    $stmt = $db->prepare("SELECT * FROM users WHERE LOWER(email) = ? LIMIT 1");
    $stmt->execute([$googleEmail]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
}

if ($user) {
    // Existing user: Link Google ID, avatar, and refresh token
    $updateFields = [
        'google_id'     => $googleId ?: ($user['google_id'] ?? null),
        'google_avatar' => $googleAvatar ?: ($user['google_avatar'] ?? null),
        'auth_provider' => 'google'
    ];
    if ($refreshToken) {
        $updateFields['google_refresh_token'] = $refreshToken;
    }
    // Update user record
    $sql = "UPDATE users SET google_id = ?, google_avatar = ?, auth_provider = 'google', last_login = NOW()";
    $params = [$updateFields['google_id'], $updateFields['google_avatar']];
    if ($refreshToken) {
        $sql .= ", google_refresh_token = ?";
        $params[] = $refreshToken;
    }
    $sql .= " WHERE id = ?";
    $params[] = $user['id'];
    $db->prepare($sql)->execute($params);

    $userId = (int)$user['id'];
    $userRole = $user['role'] ?? 'editor';
    $userName = $user['full_name'] ?: $googleName;
    $loginUsername = $user['username'] ?? '';
} else {
    // New teacher: Auto-register with editor privileges
    $baseUsername = strtolower(preg_replace('/[^a-zA-Z0-9_]/', '', explode('@', $googleEmail)[0]));
    if ($baseUsername === '') $baseUsername = 'teacher';
    
    // Ensure unique username
    $finalUsername = $baseUsername;
    $counter = 1;
    while (true) {
        $chk = $db->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
        $chk->execute([$finalUsername]);
        if (!$chk->fetch()) break;
        $counter++;
        $finalUsername = "{$baseUsername}{$counter}";
    }

    $randomPass = bin2hex(random_bytes(16));
    $hashedPass = password_hash($randomPass, PASSWORD_BCRYPT, ['cost' => 12]);

    $ins = $db->prepare("
        INSERT INTO users (username, password, full_name, email, google_id, google_avatar, google_refresh_token, auth_provider, role, last_login, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, 'google', 'editor', NOW(), NOW())
    ");
    $ins->execute([
        $finalUsername,
        $hashedPass,
        $googleName,
        $googleEmail,
        $googleId,
        $googleAvatar,
        $refreshToken
    ]);
    $userId = (int)$db->lastInsertId();
    $userRole = 'editor';
    $userName = $googleName;
    $loginUsername = $finalUsername;
}

// 6. Automated Google Drive Workspace Provisioning
// Create or verify personal workspace folder inside Google Drive
$folderId = null;
$folderUrl = null;
$subfolders = [];

try {
    $workspace = $client->ensureUserWorkspace($accessToken, $userName, '2024-25');
    if (!empty($workspace['teacher_folder_id'])) {
        $folderId  = $workspace['teacher_folder_id'];
        $folderUrl = $workspace['teacher_folder_url'];
        $subfolders = $workspace['subfolders'] ?? [];

        // Save folder references to user record
        $updDrive = $db->prepare("UPDATE users SET google_drive_folder_id = ?, google_drive_folder_url = ?, google_drive_subfolders = ? WHERE id = ?");
        $updDrive->execute([$folderId, $folderUrl, json_encode($subfolders), $userId]);
    }
} catch (Throwable $e) {
    error_log("HCC [Google Drive] Workspace provisioning error: " . $e->getMessage());
}

// 7. Establish Authenticated Session
session_regenerate_id(true);
$_SESSION['admin_id']                = $userId;
$_SESSION['admin_name']              = $userName;
$_SESSION['admin_role']              = $userRole;
$_SESSION['admin_email']             = $googleEmail;
$_SESSION['login_attempts']          = 0;
$_SESSION['last_attempt_time']       = 0;
$_SESSION['initiated']               = true;
$_SESSION['last_activity']           = time();
$_SESSION['last_regeneration']       = time();

// Google specific session variables
$_SESSION['google_logged_in']        = true;
$_SESSION['google_access_token']     = $accessToken;
$_SESSION['google_avatar']           = $googleAvatar;
$_SESSION['google_drive_folder_id']  = $folderId;
$_SESSION['google_drive_folder_url'] = $folderUrl;
$_SESSION['google_drive_subfolders'] = $subfolders;

// Clear DB-backed login throttle rows for this IP + account (was a JSON file).
$clientIp = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
try {
    $db->prepare("DELETE FROM login_attempts WHERE scope = 'ip' AND key_hash = ?")
       ->execute([hash('sha256', 'ip:' . $clientIp)]);
    if (!empty($loginUsername)) {
        $db->prepare("DELETE FROM login_attempts WHERE scope = 'user' AND key_hash = ?")
           ->execute([hash('sha256', 'user:' . strtolower($loginUsername))]);
    }
} catch (\Throwable $e) {
    error_log('Login throttle clear (Google) failed: ' . $e->getMessage());
}

rcLogAudit('login_google_success', 'auth', "Google login success for: {$googleEmail} (User ID: {$userId})");

// 8. Redirect to Dashboard with active workspace
header('Location: dashboard.php?google_signin=1');
exit;
