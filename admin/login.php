<?php
// Security headers — must run before ANY output (login page is unauthenticated, so
// it needs its own headers; auth_check.php is NOT included here).
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/google_drive.php';

if (!empty($_SESSION['admin_id'])) {
    header('Location: dashboard.php');
    exit;
}

/* ── OAuth error codes: whitelisted (never reflect free text from the URL) ── */
$oauthErrorMessages = [
    'cancelled'      => 'Google sign-in was cancelled.',
    'state'          => 'Security verification failed. Please try signing in again.',
    'code'           => 'Authorization code missing from Google response.',
    'token'          => 'Could not exchange authorization code with Google. Please check client credentials.',
    'profile'        => 'Could not retrieve verified profile information from Google.',
    'google'         => 'Google authorization failed. Please try again.',
    'not_configured' => 'Google Sign-In requires Client ID & Secret configuration. Please enter your Google Cloud OAuth credentials in system settings.',
];

/* ── Page-level notices (whitelisted redirect codes from auth_check.php / config) ──
   Before this, ?err=session_timeout and ?err=forbidden were silently dropped, so a
   timed-out admin landed on a blank-looking form with no explanation.            */
$pageMessages = [
    'session_timeout' => [
        'title' => 'Session expired',
        'text'  => 'You were signed out automatically after 30 minutes of inactivity. Please sign in again to continue where you left off.',
        'tone'  => 'info',
        'icon'  => 'fa-clock',
    ],
    'forbidden' => [
        'title' => 'Access restricted',
        'text'  => 'Your account does not have permission to open that page. Please sign in with an administrator account.',
        'tone'  => 'warning',
        'icon'  => 'fa-user-shield',
    ],
];

/* Success notices stay on a separate whitelist so a reset never signs the user in. */
$successMessages = [
    'password_reset' => [
        'title' => 'Password updated',
        'text'  => 'Your password has been reset. Sign in with your new credentials to open the dashboard.',
        'icon'  => 'fa-circle-check',
    ],
];

$error      = '';
$errorTitle = '';
$errorIcon  = 'fa-circle-exclamation';
$errorTone  = 'danger';
$success      = '';
$successTitle = '';
$successIcon  = 'fa-circle-check';

if (!empty($_GET['notice']) && isset($successMessages[$_GET['notice']])) {
    $flash          = $successMessages[$_GET['notice']];
    $success        = $flash['text'];
    $successTitle   = $flash['title'];
    $successIcon    = $flash['icon'];
}

if (!empty($_GET['oauth_code'])) {
    $error = $oauthErrorMessages[$_GET['oauth_code']] ?? '';
} elseif (isset($_GET['google_not_configured'])) {
    $error = $oauthErrorMessages['not_configured'];
} elseif (!empty($_GET['err']) && isset($pageMessages[$_GET['err']])) {
    $notice     = $pageMessages[$_GET['err']];
    $error      = $notice['text'];
    $errorTitle = $notice['title'];
    $errorTone  = $notice['tone'];
    $errorIcon  = $notice['icon'];
}

$locked = false;
$usernameVal = '';

// Setup Google Sign-in state and URL (session already started by config/database.php).
$googleClient = new HCCGoogleDrive();
$googleConfigured = $googleClient->isConfigured();
if ($googleConfigured) {
    $_SESSION['oauth_state'] = bin2hex(random_bytes(16));
    $googleAuthUrl = $googleClient->getAuthUrl($_SESSION['oauth_state']);
} else {
    $googleAuthUrl = 'login.php?google_not_configured=1';
}

/* ── Rate limiting: DB-backed, atomic, per-IP + per-username ──────────────────
   Replaces the old JSON-file throttle, which had a read-modify-write race (the
   lock covered only the write), failed open on corrupt JSON, created one file
   per IP (IPv6 ⇒ disk exhaustion) and could only key on IP.                   */
const LOGIN_MAX_ATTEMPTS      = 5;   // per IP + per session
const LOGIN_MAX_ATTEMPTS_USER = 10;  // per username (higher: limits lockout-DoS)
const LOGIN_WINDOW_SECONDS    = 900;

function rcThrottleGet(string $scope, string $keyHash): array {
    try {
        $stmt = getDB()->prepare(
            "SELECT attempts, UNIX_TIMESTAMP(window_start) AS ws
             FROM login_attempts WHERE scope = ? AND key_hash = ? LIMIT 1"
        );
        $stmt->execute([$scope, $keyHash]);
        $row = $stmt->fetch();
        if (!$row) return ['attempts' => 0, 'ws' => 0];
        $attempts = (int)$row['attempts'];
        $ws = (int)$row['ws'];
        if ((time() - $ws) >= LOGIN_WINDOW_SECONDS) return ['attempts' => 0, 'ws' => 0];
        return ['attempts' => $attempts, 'ws' => $ws];
    } catch (\Throwable $e) {
        error_log('Login throttle read failed: ' . $e->getMessage());
        return ['attempts' => 0, 'ws' => 0];
    }
}

function rcThrottleHit(string $scope, string $keyHash): void {
    try {
        $db = getDB();
        // One atomic statement: no read-modify-write race under parallel requests.
        $db->prepare(
            "INSERT INTO login_attempts (scope, key_hash, attempts, window_start)
             VALUES (?, ?, 1, NOW())
             ON DUPLICATE KEY UPDATE
                 attempts = IF(window_start <= NOW() - INTERVAL " . LOGIN_WINDOW_SECONDS . " SECOND, 1, attempts + 1),
                 window_start = IF(window_start <= NOW() - INTERVAL " . LOGIN_WINDOW_SECONDS . " SECOND, NOW(), window_start)"
        )->execute([$scope, $keyHash]);
        // Opportunistic pruning keeps the table tiny.
        if (random_int(1, 50) === 1) {
            $db->exec("DELETE FROM login_attempts WHERE window_start < NOW() - INTERVAL 1 DAY");
        }
    } catch (\Throwable $e) {
        error_log('Login throttle write failed: ' . $e->getMessage());
    }
}

function rcThrottleClear(string $scope, string $keyHash): void {
    try {
        getDB()->prepare("DELETE FROM login_attempts WHERE scope = ? AND key_hash = ?")
              ->execute([$scope, $keyHash]);
    } catch (\Throwable $e) {
        error_log('Login throttle clear failed: ' . $e->getMessage());
    }
}

rcEnsureLoginAttemptsTable();

$clientIp   = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
$ipKey      = hash('sha256', 'ip:' . $clientIp);
$ipState    = rcThrottleGet('ip', $ipKey);
$ipAttempts = $ipState['attempts'];
$ipLast     = $ipState['ws'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $username = trim((string)($_POST['username'] ?? ''));
        $password = (string)($_POST['password'] ?? '');
        $usernameVal = $username;

        $loginAttempts = (int)($_SESSION['login_attempts'] ?? 0);
        $lastAttempt   = (int)($_SESSION['last_attempt_time'] ?? 0);
        $sessionLocked = ($loginAttempts >= LOGIN_MAX_ATTEMPTS && (time() - $lastAttempt) < LOGIN_WINDOW_SECONDS);

        $userKey   = ($username !== '') ? hash('sha256', 'user:' . strtolower($username)) : '';
        $userState = ($userKey !== '') ? rcThrottleGet('user', $userKey) : ['attempts' => 0, 'ws' => 0];

        $ipLocked   = ($ipAttempts >= LOGIN_MAX_ATTEMPTS && (time() - $ipLast) < LOGIN_WINDOW_SECONDS);
        $userLocked = ($userState['attempts'] >= LOGIN_MAX_ATTEMPTS_USER && (time() - $userState['ws']) < LOGIN_WINDOW_SECONDS);

        if ($sessionLocked || $ipLocked || $userLocked) {
            $locked = true;
            // Use longest remaining window across session + IP + username throttles.
            $remaining = 0;
            if ($sessionLocked) $remaining = max($remaining, LOGIN_WINDOW_SECONDS - (time() - $lastAttempt));
            if ($ipLocked)      $remaining = max($remaining, LOGIN_WINDOW_SECONDS - (time() - $ipLast));
            if ($userLocked)    $remaining = max($remaining, LOGIN_WINDOW_SECONDS - (time() - $userState['ws']));
            $mins = (int) ceil(max(1, $remaining) / 60);
            $error = 'Too many failed attempts. Please try again in ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . '.';
            $errorTitle = 'Temporarily locked';
            $errorTone  = 'warning';
            $errorIcon  = 'fa-lock';
        } else {
            if ($loginAttempts >= LOGIN_MAX_ATTEMPTS && (time() - $lastAttempt) >= LOGIN_WINDOW_SECONDS) {
                $_SESSION['login_attempts'] = 0;
                $loginAttempts = 0;
            }

            // Cap input before any hashing/DB work (bcrypt silently truncates at 72 bytes).
            if ($username === '' || strlen($username) > 191 || strlen($password) > 72) {
                $_SESSION['login_attempts'] = $loginAttempts + 1;
                $_SESSION['last_attempt_time'] = time();
                rcThrottleHit('ip', $ipKey);
                if ($userKey !== '') rcThrottleHit('user', $userKey);
                $ipAttempts = min(LOGIN_MAX_ATTEMPTS, $ipAttempts + 1);
                rcLogAudit('login_failed', 'auth', 'Rejected login input (empty/oversized) from IP: ' . $clientIp);
                $error = 'Invalid username or password.';
            } else {
                $db = getDB();
                $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
                $stmt->execute([$username]);
                $user = $stmt->fetch();

                // Timing equalisation: always run a full-cost bcrypt verify, even for
                // unknown usernames (blocks account enumeration by response timing).
                $dummyHash = '$2y$12$VFqlP/qx903PjvAOv22Wi.6Fs.k.Av3HlPDNIDR1vEIDG0JVpEKLG';
                $verified  = password_verify($password, $user ? $user['password'] : $dummyHash);

                if ($user && $verified) {
                    session_regenerate_id(true);
                    // New privilege level ⇒ rotate the CSRF token as well.
                    unset($_SESSION['csrf_token']);
                    generateCSRFToken();

                    $_SESSION['admin_id'] = $user['id'];
                    $_SESSION['admin_name'] = $user['full_name'];
                    $_SESSION['admin_role'] = $user['role'];
                    $_SESSION['login_attempts'] = 0;
                    $_SESSION['last_attempt_time'] = 0;
                    // Harden session: mark initiated, stamp activity + rotation immediately on login.
                    $_SESSION['initiated'] = true;
                    $_SESSION['last_activity'] = time();
                    $_SESSION['last_regeneration'] = time();

                    // Transparently upgrade stale hashes (seeded admin is cost 10; app writes cost 12).
                    if (password_needs_rehash($user['password'], PASSWORD_BCRYPT, ['cost' => 12])) {
                        try {
                            $db->prepare("UPDATE users SET password = ? WHERE id = ?")
                               ->execute([password_hash($password, PASSWORD_BCRYPT, ['cost' => 12]), $user['id']]);
                        } catch (\Throwable $e) {
                            error_log('Password rehash failed: ' . $e->getMessage());
                        }
                    }

                    // Clear both throttle scopes on success.
                    rcThrottleClear('ip', $ipKey);
                    if ($userKey !== '') rcThrottleClear('user', $userKey);

                    $db->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")->execute([$user['id']]);
                    rcLogAudit('login_success', 'auth', 'Successful login for user: ' . $user['username']);

                    header('Location: dashboard.php');
                    exit;
                } else {
                    $_SESSION['login_attempts'] = $loginAttempts + 1;
                    $_SESSION['last_attempt_time'] = time();
                    rcThrottleHit('ip', $ipKey);
                    if ($userKey !== '') rcThrottleHit('user', $userKey);
                    $ipAttempts = min(LOGIN_MAX_ATTEMPTS, $ipAttempts + 1);
                    // Never store unbounded attacker input in the audit trail.
                    rcLogAudit('login_failed', 'auth', 'Failed login attempt for username: ' . substr($username, 0, 100));
                    // Progressive delay: slow automation, negligible for a human retry.
                    usleep(random_int(150000, 400000));
                    // Generic message: no remaining-attempts oracle.
                    $error = 'Invalid username or password.';
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Hindustani Covenant Church High School — Admin Panel Login">
    <meta name="robots" content="noindex, nofollow">
    <title>Admin Login | Hindustani Covenant Church High School</title>
    <link rel="icon" href="<?= BASE_URL ?>assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap@5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin-login.css?v=<?= (is_file(__DIR__ . '/../assets/css/admin-login.css') ? filemtime(__DIR__ . '/../assets/css/admin-login.css') : 0) ?>">
</head>
<body class="auth-body">
    <div class="container-fluid p-0">
        <div class="row g-0 auth-shell">
            <!-- Brand side (desktop only — mobile gets a compact brand mark above the form) -->
            <aside class="col-lg-6 brand-panel d-none d-lg-flex flex-column justify-content-between p-4 p-xl-5">
                <span class="brand-orb brand-orb--gold" aria-hidden="true"></span>
                <span class="brand-orb brand-orb--rose" aria-hidden="true"></span>
                <span class="brand-dots" aria-hidden="true"></span>

                <header class="brand-head d-flex align-items-center gap-3 position-relative">
                    <img class="brand-logo" src="<?= BASE_URL ?>assets/images/hcclogo.jpg" alt="Hindustani Covenant Church High School logo">
                    <div>
                        <p class="brand-school-name mb-0">Hindustani Covenant Church High School</p>
                        <p class="brand-school-sub mb-0">Solapur &bull; Est. Excellence in Education</p>
                    </div>
                </header>

                <div class="brand-body d-flex flex-column position-relative my-auto py-4">
                    <div class="brand-badge-pill mb-3">
                        <i class="fa-solid fa-graduation-cap" aria-hidden="true"></i> Administrative Portal
                    </div>
                    <h2 class="brand-heading mb-3">Campus Automation &amp; <span class="brand-accent">Academic Records</span></h2>
                    <p class="brand-lead mb-4">Streamlined management of student marks, automated progress reports, instant WhatsApp communication, and administration.</p>

                    <div class="brand-features-list">
                        <div class="brand-feature-item">
                            <div class="feature-icon"><i class="fa-solid fa-file-invoice" aria-hidden="true"></i></div>
                            <div>
                                <strong>Automated Progress Reports</strong>
                                <span>Instant marksheets, grading calculations &amp; unit tests</span>
                            </div>
                        </div>
                        <div class="brand-feature-item">
                            <div class="feature-icon"><i class="fa-brands fa-whatsapp" aria-hidden="true"></i></div>
                            <div>
                                <strong>Direct Parent WhatsApp Dispatch</strong>
                                <span>One-click delivery of PDF report cards and official notices</span>
                            </div>
                        </div>
                        <div class="brand-feature-item">
                            <div class="feature-icon"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i></div>
                            <div>
                                <strong>Secure Role-Based Access</strong>
                                <span>Protected sessions, audit trails, and multi-tier admin control</span>
                            </div>
                        </div>
                    </div>
                </div>

                <footer class="brand-footer d-flex align-items-center justify-content-between pt-3 position-relative">
                    <span class="brand-footer-text">&copy; <?= date('Y') ?> HCC High School</span>
                    <span class="brand-status-pill"><span class="status-indicator"></span> System Online</span>
                </footer>
            </aside>

            <!-- Form side -->
            <main class="col-lg-6 auth-panel d-flex flex-column align-items-center justify-content-center p-3 p-sm-4 p-lg-5" aria-label="Admin login">
                <div class="auth-mobile-brand d-lg-none">
                    <img src="<?= BASE_URL ?>assets/images/hcclogo.jpg" alt="">
                    <div>
                        <strong>Hindustani Covenant Church High School</strong>
                        <span>Admin Portal · Solapur</span>
                    </div>
                </div>

                <div class="auth-stage">
                <div class="auth-card">
                    <div class="auth-card-header mb-4">
                        <div class="auth-pill-badge mb-2">
                            <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                            <span>Admin Portal</span>
                        </div>
                        <h1 class="auth-title">Welcome back</h1>
                        <p class="auth-subtitle mb-0">Sign in with your administrator credentials to continue.</p>
                    </div>

                    <?php if ($success !== ''): ?>
                        <div class="alert auth-alert auth-alert--success d-flex" role="status">
                            <span class="auth-alert-icon" aria-hidden="true">
                                <i class="fa-solid <?= htmlspecialchars($successIcon, ENT_QUOTES, 'UTF-8') ?>"></i>
                            </span>
                            <div class="auth-alert-body">
                                <strong class="auth-alert-title"><?= htmlspecialchars($successTitle, ENT_QUOTES, 'UTF-8') ?></strong>
                                <div><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert auth-alert auth-alert--<?= htmlspecialchars($errorTone, ENT_QUOTES, 'UTF-8') ?> d-flex" role="alert">
                            <span class="auth-alert-icon" aria-hidden="true">
                                <i class="fa-solid <?= htmlspecialchars($errorIcon, ENT_QUOTES, 'UTF-8') ?>"></i>
                            </span>
                            <div class="auth-alert-body">
                                <?php if ($errorTitle !== ''): ?>
                                    <strong class="auth-alert-title"><?= htmlspecialchars($errorTitle, ENT_QUOTES, 'UTF-8') ?></strong>
                                <?php endif; ?>
                                <div><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                        </div>
                    <?php endif; ?>

                    <?php 
                        $attemptsCount = max((int)($_SESSION['login_attempts'] ?? 0), (int)($ipAttempts ?? 0));
                        if (!$locked && $attemptsCount >= 3): 
                    ?>
                        <div class="alert alert-warning auth-alert auth-alert--notice" role="alert">
                            <i class="fa-solid fa-triangle-exclamation flex-shrink-0" aria-hidden="true"></i>
                            <span>Notice: <?= max(0, 5 - $attemptsCount) ?> attempt(s) remaining before a 15-minute lockout.</span>
                        </div>
                    <?php endif; ?>

                    <!-- Google Sign-In Option -->
                    <div class="google-auth-box">
                        <a href="<?= htmlspecialchars($googleAuthUrl) ?>" class="btn-google-signin" id="googleSignInBtn" title="Sign in with your Google Account">
                            <svg class="google-icon" viewBox="0 0 48 48" width="20" height="20" aria-hidden="true">
                                <path fill="#EA4335" d="M24 9.5c3.54 0 6.71 1.22 9.21 3.6l6.85-6.85C35.9 2.38 30.47 0 24 0 14.62 0 6.51 5.38 2.56 13.22l7.98 6.19C12.43 13.72 17.74 9.5 24 9.5z"/>
                                <path fill="#4285F4" d="M46.98 24.55c0-1.57-.15-3.09-.38-4.55H24v9.02h12.94c-.58 2.96-2.26 5.48-4.78 7.18l7.73 6c4.51-4.18 7.09-10.36 7.09-17.65z"/>
                                <path fill="#FBBC05" d="M10.53 28.59c-.48-1.45-.76-2.99-.76-4.59s.27-3.14.76-4.59l-7.98-6.19C.92 16.46 0 20.12 0 24c0 3.88.92 7.54 2.56 10.79l7.97-6.2z"/>
                                <path fill="#34A853" d="M24 48c6.48 0 11.93-2.13 15.89-5.81l-7.73-6c-2.15 1.45-4.92 2.3-8.16 2.3-6.26 0-11.57-4.22-13.47-9.91l-7.98 6.19C6.51 42.62 14.62 48 24 48z"/>
                            </svg>
                            <span>Sign in with Google</span>
                        </a>

                        <div class="auth-divider">
                            <span>or sign in with username</span>
                        </div>
                    </div>

                    <form method="POST" action="login.php" id="loginForm" class="auth-form" autocomplete="on">
                        <?= csrfField() ?>
                        <div class="auth-field-group mb-3">
                            <label for="username" class="auth-field-label">Username</label>
                            <div class="auth-input-wrap">
                                <i class="fa-solid fa-user auth-input-icon" aria-hidden="true"></i>
                                <input type="text" class="auth-input" id="username" name="username" required
                                    placeholder="Enter your username" autofocus autocomplete="username"
                                    autocapitalize="none" spellcheck="false"
                                    value="<?= htmlspecialchars($usernameVal, ENT_QUOTES, 'UTF-8') ?>"
                                    <?= $locked ? 'disabled' : '' ?>>
                            </div>
                        </div>

                        <div class="auth-field-group mb-4">
                            <div class="auth-label-row">
                                <label for="password" class="auth-field-label mb-0">Password</label>
                                <a href="forgot_password.php" class="auth-forgot-link">Forgot password?</a>
                            </div>
                            <div class="auth-input-wrap">
                                <i class="fa-solid fa-lock auth-input-icon" aria-hidden="true"></i>
                                <input type="password" class="auth-input auth-input--pw" id="password" name="password" required
                                    placeholder="Enter your password" autocomplete="current-password"
                                    <?= $locked ? 'disabled' : '' ?>>
                                <button type="button" class="auth-pw-toggle" id="pwToggle" aria-label="Show password" title="Show / hide password">
                                    <i class="fa-solid fa-eye" id="pwIcon" aria-hidden="true"></i>
                                </button>
                            </div>
                            <div class="caps-note mt-2" id="capsNote" hidden>
                                <i class="fa-solid fa-circle-info" aria-hidden="true"></i> Caps Lock is active
                            </div>
                        </div>

                        <button type="submit" class="btn btn-login w-100" id="loginBtn" <?= $locked ? 'disabled' : '' ?>>
                            <i class="fa-solid fa-arrow-right-to-bracket btn-ico" aria-hidden="true"></i>
                            <i class="fa-solid fa-spinner fa-spin btn-spin" aria-hidden="true"></i>
                            <span><?= $locked ? 'Temporarily locked' : 'Sign In to Dashboard' ?></span>
                        </button>
                    </form>

                    <div class="auth-card-footer mt-4">
                        <div class="auth-security-badge">
                            <i class="fa-solid fa-shield-halved" aria-hidden="true"></i>
                            <span>Authorized personnel only</span>
                        </div>
                        <span class="auth-help-note">Contact the school office</span>
                    </div>
                </div>

                <p class="auth-copy text-center text-muted small mt-4 mb-0">&copy; <?= date('Y') ?> Hindustani Covenant Church High School, Solapur. All rights reserved.</p>
                </div>
            </main>
        </div>
    </div>
    <script src="<?= BASE_URL ?>assets/js/admin-login.js?v=<?= (is_file(__DIR__ . '/../assets/js/admin-login.js') ? filemtime(__DIR__ . '/../assets/js/admin-login.js') : 0) ?>" defer></script>
</body>
</html>
