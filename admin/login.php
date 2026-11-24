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
    'logged_out' => [
        'title' => 'Signed out successfully',
        'text'  => 'You have safely logged out of the administrator panel.',
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
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin-login-bootstrap.css?v=<?= (is_file(__DIR__ . '/../assets/css/admin-login-bootstrap.css') ? filemtime(__DIR__ . '/../assets/css/admin-login-bootstrap.css') : 0) ?>">
</head>
<body class="bg-body-tertiary login-page">
    <div class="container-fluid px-0">
        <div class="row g-0 min-vh-100 login-shell">
            <aside class="login-aside col-lg-6 d-none d-lg-flex flex-column justify-content-between p-4 p-xl-5 text-white">
                <header class="d-flex align-items-center gap-3">
                    <img class="login-mark rounded-3 bg-white p-1" src="<?= BASE_URL ?>assets/images/hcclogo.jpg" alt="">
                    <div>
                        <p class="fw-semibold mb-0">Hindustani Covenant Church High School</p>
                        <p class="small mb-0 opacity-75">Solapur &bull; Est. Excellence in Education</p>
                    </div>
                </header>

                <div class="login-pitch my-auto py-4">
                    <p class="mb-2">
                        <span class="badge rounded-pill text-bg-light text-primary">
                            <i class="fa-solid fa-graduation-cap me-1" aria-hidden="true"></i>Administrative portal
                        </span>
                    </p>
                    <p class="login-heading h2 fw-semibold">Academic records &amp; <span class="login-accent">exam timetables</span></p>
                    <p class="login-lead opacity-75">Manage student marks, unit tests, report cards, and examination schedules in one place.</p>
                    <ul class="login-features list-unstyled d-grid gap-3 mb-0">
                        <li class="d-flex align-items-start gap-3">
                            <span class="login-icon rounded-3 d-inline-flex align-items-center justify-content-center flex-shrink-0">
                                <i class="fa-solid fa-file-invoice" aria-hidden="true"></i>
                            </span>
                            <span>
                                <span class="fw-semibold d-block">Results &amp; Report Cards</span>
                                <span class="small opacity-75">Semester marksheets, unit tests, and automated grade calculations</span>
                            </span>
                        </li>
                        <li class="d-flex align-items-start gap-3">
                            <span class="login-icon rounded-3 d-inline-flex align-items-center justify-content-center flex-shrink-0">
                                <i class="fa-solid fa-calendar-days" aria-hidden="true"></i>
                            </span>
                            <span>
                                <span class="fw-semibold d-block">Exam Timetable &amp; Seating</span>
                                <span class="small opacity-75">Class schedules, subject time slots, and exam seating plans</span>
                            </span>
                        </li>
                        <li class="d-flex align-items-start gap-3">
                            <span class="login-icon rounded-3 d-inline-flex align-items-center justify-content-center flex-shrink-0">
                                <i class="fa-solid fa-user-graduate" aria-hidden="true"></i>
                            </span>
                            <span>
                                <span class="fw-semibold d-block">Student Records &amp; Promotion</span>
                                <span class="small opacity-75">Class promotions, bonafide certificates, and archived records</span>
                            </span>
                        </li>
                    </ul>
                </div>

                <footer class="d-flex align-items-center justify-content-between small border-top border-light border-opacity-25 pt-3">
                    <span class="opacity-75">&copy; <?= date('Y') ?> HCC High School</span>
                    <span class="badge rounded-pill text-bg-success">Online</span>
                </footer>
            </aside>

            <main class="login-main col-12 col-lg-6 d-flex flex-column align-items-center px-3 py-4 p-sm-4 p-xl-5" aria-label="Admin login">
                <div class="login-wrap w-100 mx-auto">
                    <div class="login-mobile-brand d-flex d-lg-none align-items-center gap-3 p-3 mb-3 rounded-4 text-white">
                        <img class="login-mark rounded-3 border bg-white p-1 flex-shrink-0" src="<?= BASE_URL ?>assets/images/hcclogo.jpg" alt="Hindustani Covenant Church High School logo">
                        <div class="login-min-w-0">
                            <p class="fw-semibold mb-0 lh-sm">Hindustani Covenant Church High School</p>
                            <p class="small mb-0 opacity-75">Admin portal &middot; Solapur</p>
                        </div>
                    </div>

                    <div class="login-card-meta d-none d-lg-flex align-items-center justify-content-between mb-3 px-1 small text-body-secondary">
                        <span class="d-inline-flex align-items-center gap-2 fw-semibold">
                            <i class="fa-solid fa-building-columns text-primary" aria-hidden="true"></i>
                            <span>HCC High School &middot; Solapur Campus</span>
                        </span>
                        <span class="badge rounded-pill text-bg-light border text-body-emphasis d-inline-flex align-items-center gap-2 px-3 py-2 fw-semibold">
                            <span class="login-status-dot" aria-hidden="true"></span>
                            <span>Encrypted session</span>
                        </span>
                    </div>

                    <div class="card border shadow-sm login-card">
                        <div class="card-body p-3 p-sm-4 p-md-5 p-lg-4 p-xl-5">
                            <header class="pb-3 mb-4 border-bottom">
                                <div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-3">
                                    <span class="badge rounded-pill login-kicker d-inline-flex align-items-center gap-1">
                                        <i class="fa-solid fa-user-shield" aria-hidden="true"></i>
                                        <span>Administrator access</span>
                                    </span>
                                    <span class="badge rounded-pill login-portal-tag">Staff Portal</span>
                                </div>
                                <div class="d-flex align-items-center gap-3">
                                    <span class="login-head-seal d-none d-sm-inline-flex flex-shrink-0" aria-hidden="true">
                                        <img src="<?= BASE_URL ?>assets/images/hcclogo.jpg" alt="">
                                    </span>
                                    <div class="login-min-w-0">
                                        <h1 class="h3 fw-bold mb-1 login-title" id="loginFormHeading">Sign in to your account</h1>
                                        <p class="small text-body-secondary mb-0">Enter your administrator credentials to continue.</p>
                                    </div>
                                </div>
                            </header>

                            <?php if ($success !== ''): ?>
                                <div class="alert alert-success login-alert d-flex align-items-start gap-2 mb-3" role="status">
                                    <i class="fa-solid <?= htmlspecialchars($successIcon, ENT_QUOTES, 'UTF-8') ?> mt-1 flex-shrink-0" aria-hidden="true"></i>
                                    <div class="login-min-w-0">
                                        <div class="fw-semibold"><?= htmlspecialchars($successTitle, ENT_QUOTES, 'UTF-8') ?></div>
                                        <div><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php if ($error): ?>
                                <div class="alert alert-<?= htmlspecialchars($errorTone, ENT_QUOTES, 'UTF-8') ?> login-alert d-flex align-items-start gap-2 mb-3" role="alert">
                                    <i class="fa-solid <?= htmlspecialchars($errorIcon, ENT_QUOTES, 'UTF-8') ?> mt-1 flex-shrink-0" aria-hidden="true"></i>
                                    <div class="login-min-w-0">
                                        <?php if ($errorTitle !== ''): ?>
                                            <div class="fw-semibold"><?= htmlspecialchars($errorTitle, ENT_QUOTES, 'UTF-8') ?></div>
                                        <?php endif; ?>
                                        <div><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <?php
                                $attemptsCount = max((int)($_SESSION['login_attempts'] ?? 0), (int)($ipAttempts ?? 0));
                                if (!$locked && $attemptsCount >= 3):
                            ?>
                                <div class="alert alert-warning login-alert d-flex align-items-center gap-2 mb-3" role="alert">
                                    <i class="fa-solid fa-triangle-exclamation flex-shrink-0" aria-hidden="true"></i>
                                    <?php $attemptsLeft = max(0, LOGIN_MAX_ATTEMPTS - $attemptsCount); ?>
                                    <div class="login-min-w-0">
                                        Notice: <strong><?= $attemptsLeft ?> attempt<?= $attemptsLeft === 1 ? '' : 's' ?></strong> remaining before a <?= (int) (LOGIN_WINDOW_SECONDS / 60) ?>-minute lockout.
                                    </div>
                                </div>
                            <?php endif; ?>

                            <form method="POST" action="login.php" id="loginForm" autocomplete="on" aria-labelledby="loginFormHeading">
                                <?= csrfField() ?>
                                <div class="mb-3">
                                    <label for="username" class="form-label fw-semibold small text-uppercase">Username</label>
                                    <div class="input-group input-group-lg login-input-group">
                                        <span class="input-group-text" aria-hidden="true"><i class="fa-solid fa-user"></i></span>
                                        <input type="text" class="form-control" id="username" name="username" required
                                            placeholder="Enter username" autofocus autocomplete="username"
                                            autocorrect="off" autocapitalize="none" spellcheck="false" enterkeyhint="next"
                                            value="<?= htmlspecialchars($usernameVal, ENT_QUOTES, 'UTF-8') ?>"
                                            <?= $locked ? 'disabled' : '' ?>>
                                    </div>
                                </div>

                                <div class="mb-4">
                                    <div class="d-flex justify-content-between align-items-baseline gap-2 mb-2">
                                        <label for="password" class="form-label fw-semibold small text-uppercase mb-0">Password</label>
                                        <a href="forgot_password.php" class="small fw-semibold text-decoration-none login-forgot">Forgot password?</a>
                                    </div>
                                    <div class="input-group input-group-lg login-input-group">
                                        <span class="input-group-text" aria-hidden="true"><i class="fa-solid fa-lock"></i></span>
                                        <input type="password" class="form-control" id="password" name="password" required
                                            placeholder="Enter password" autocomplete="current-password" enterkeyhint="done"
                                            aria-describedby="capsNote"
                                            <?= $locked ? 'disabled' : '' ?>>
                                        <button type="button" class="btn login-pw-toggle" id="pwToggle"
                                            aria-label="Show password" aria-pressed="false" aria-controls="password"
                                            title="Show / hide password" <?= $locked ? 'disabled' : '' ?>>
                                            <i class="fa-solid fa-eye" id="pwIcon" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                    <div class="form-text text-warning-emphasis mt-2" id="capsNote" role="status" aria-live="polite" hidden>
                                        <i class="fa-solid fa-keyboard me-1" aria-hidden="true"></i>Caps Lock is turned on
                                    </div>
                                </div>

                                <button type="submit" class="btn btn-primary btn-lg w-100 d-inline-flex align-items-center justify-content-center gap-2 fw-semibold" id="loginBtn" <?= $locked ? 'disabled' : '' ?>>
                                    <i class="fa-solid fa-arrow-right-to-bracket btn-ico" aria-hidden="true"></i>
                                    <i class="fa-solid fa-spinner fa-spin btn-spin" aria-hidden="true"></i>
                                    <span><?= $locked ? 'Temporarily locked' : 'Sign in to Dashboard' ?></span>
                                </button>
                            </form>

                            <footer class="d-flex flex-column flex-sm-row align-items-start align-items-sm-center justify-content-between gap-2 pt-3 mt-4 border-top small text-body-secondary">
                                <span class="d-inline-flex align-items-center gap-2">
                                    <i class="fa-solid fa-shield-halved text-primary" aria-hidden="true"></i>
                                    <span>CSRF &amp; brute-force protected</span>
                                </span>
                                <span class="d-inline-flex align-items-center gap-2">
                                    <i class="fa-regular fa-clock text-primary" aria-hidden="true"></i>
                                    <span>30-min idle auto-logout</span>
                                </span>
                            </footer>
                        </div>
                    </div>

                    <p class="login-copy text-center small text-body-secondary mt-3 mb-0">
                        Authorized school personnel only &bull; Activity is logged for security auditing
                    </p>
                </div>
            </main>
        </div>
    </div>
    <script src="<?= BASE_URL ?>assets/js/admin-login.js?v=<?= (is_file(__DIR__ . '/../assets/js/admin-login.js') ? filemtime(__DIR__ . '/../assets/js/admin-login.js') : 0) ?>" defer></script>
</body>
</html>
