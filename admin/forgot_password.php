<?php
/**
 * HCC Automation System — Forgot Password
 * Sends a 6-digit code to the saved recovery mailbox. The code is entered
 * on this page. A correct code opens the new-password screen.
 *
 * SECURITY NOTES:
 *  - The code is generated with random_int and only its SHA-256 hash is stored.
 *  - Codes expire after 10 minutes. A new code replaces the previous one.
 *  - Five wrong entries burn the code.
 *  - Rate-limited per IP (3 sends / hour).
 *  - The code is never placed in the URL and never printed on this page.
 */
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

/**
 * The administrator account and the mailbox that receives reset codes.
 *
 * @return array{user: ?array, email: string}
 */
function hccRecoveryTarget(PDO $db): array {
    $stmt = $db->query("SELECT id, username, full_name, email, role FROM users WHERE role = 'admin' ORDER BY id ASC LIMIT 1");
    $user = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    if (!$user) {
        $stmt = $db->query("SELECT id, username, full_name, email, role FROM users ORDER BY id ASC LIMIT 1");
        $user = $stmt ? $stmt->fetch(PDO::FETCH_ASSOC) : false;
    }
    if (!$user) {
        return ['user' => null, 'email' => ''];
    }
    $email = trim((string)($user['email'] ?? ''));
    $usable = $email !== '' && !str_ends_with(strtolower($email), '.local') && (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
    if (!$usable) {
        $settings = getAllSettings();
        $smtpUser = trim((string)($settings['smtp_user'] ?? ''));
        $email = ($smtpUser !== '' && filter_var($smtpUser, FILTER_VALIDATE_EMAIL)) ? $smtpUser : '';
    }
    return ['user' => $user, 'email' => $email];
}

function hccOtpStillLive(PDO $db, int $userId): bool {
    $stmt = $db->prepare("SELECT password_reset_token FROM users WHERE id = ? AND password_reset_token IS NOT NULL AND password_reset_expires > NOW() LIMIT 1");
    $stmt->execute([$userId]);
    $token = $stmt->fetchColumn();
    return is_string($token) && $token !== '';
}

function hccOtpFailFile(string $storedHash): string {
    return __DIR__ . '/../logs/otp_fails_' . substr(hash('sha256', $storedHash), 0, 32) . '.json';
}

function hccOtpFailCount(string $storedHash): int {
    $file = hccOtpFailFile($storedHash);
    if (!is_readable($file)) {
        return 0;
    }
    $data = json_decode((string)@file_get_contents($file), true);
    return is_array($data) ? (int)($data['fails'] ?? 0) : 0;
}

function hccOtpFailSet(string $storedHash, int $fails): void {
    @file_put_contents(hccOtpFailFile($storedHash), json_encode(['fails' => $fails]), LOCK_EX);
}

function hccOtpFailClear(string $storedHash): void {
    if ($storedHash !== '') {
        @unlink(hccOtpFailFile($storedHash));
    }
}

$error = '';
$success = '';
$stage = 'request';

$clientIp = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$throttleDir = __DIR__ . '/../logs';
if (!is_dir($throttleDir)) {
    @mkdir($throttleDir, 0755, true);
}
$throttleFile = $throttleDir . '/forgot_pw_throttle_' . preg_replace('/[^a-zA-Z0-9_.-]/', '_', (string)$clientIp) . '.json';
$ipAttempts = 0;
$ipLast = 0;
if (is_readable($throttleFile)) {
    $ipData = json_decode((string)@file_get_contents($throttleFile), true);
    if (is_array($ipData)) {
        $ipAttempts = (int)($ipData['attempts'] ?? 0);
        $ipLast = (int)($ipData['last'] ?? 0);
    }
    if ($ipAttempts >= 3 && (time() - $ipLast) >= 3600) {
        $ipAttempts = 0;
        $ipLast = 0;
        @unlink($throttleFile);
    }
}

$db = getDB();
$target = hccRecoveryTarget($db);
$user = $target['user'];
$recoveryEmail = $target['email'];
$maskedEmail = $recoveryEmail !== '' ? rcMaskEmail($recoveryEmail) : '';

if (!empty($_SESSION['hcc_otp_pending']) && $user && hccOtpStillLive($db, (int)$user['id'])) {
    $stage = 'code';
} elseif (!empty($_SESSION['hcc_otp_pending'])) {
    unset($_SESSION['hcc_otp_pending']);
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $error = 'That code has expired. Send a new one.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        $error = 'Security check failed. Please refresh and try again.';
    } else {
        $action = trim((string)($_POST['action'] ?? ''));
        $ipThrottled = ($ipAttempts >= 3 && (time() - $ipLast) < 3600);

        if ($action === 'send_otp') {
            if ($ipThrottled) {
                $remaining = 3600 - (time() - $ipLast);
                $mins = (int)ceil($remaining / 60);
                $error = 'Too many requests. Please try again in ' . $mins . ' minute' . ($mins === 1 ? '' : 's') . '.';
            } elseif (!$user || $recoveryEmail === '') {
                $error = 'No recovery email is saved yet. Sign in and set one on Account Security, or ask the school office.';
                $stage = 'request';
                unset($_SESSION['hcc_otp_pending']);
                rcLogAudit('password_reset_no_recovery_email', 'auth', 'Password reset requested with no recovery mailbox.');
            } else {
                $storedEmail = trim((string)($user['email'] ?? ''));
                if (strcasecmp($storedEmail, $recoveryEmail) !== 0) {
                    $sync = $db->prepare('UPDATE users SET email = ? WHERE id = ?');
                    $sync->execute([$recoveryEmail, $user['id']]);
                }

                $prev = $db->prepare('SELECT password_reset_token FROM users WHERE id = ?');
                $prev->execute([(int)$user['id']]);
                hccOtpFailClear((string)$prev->fetchColumn());

                $otp = str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
                $hash = hash('sha256', $otp);
                $ins = $db->prepare('UPDATE users SET password_reset_token = ?, password_reset_expires = DATE_ADD(NOW(), INTERVAL 10 MINUTE) WHERE id = ?');
                $ins->execute([$hash, $user['id']]);

                $sent = rcSendPasswordOtpEmail($recoveryEmail, (string)($user['full_name'] ?? 'Administrator'), $otp);
                if (!$sent) {
                    error_log('HCC [forgot_password] Mail delivery failed for ' . $recoveryEmail);
                }

                unset($_SESSION['hcc_pwd_reset_uid'], $_SESSION['hcc_pwd_reset_until']);
                $_SESSION['hcc_otp_pending'] = 1;
                $stage = 'code';
                $success = 'A 6-digit code has been sent to ' . $maskedEmail . '. It expires in 10 minutes.';
                $ipAttempts++;
                @file_put_contents($throttleFile, json_encode(['attempts' => $ipAttempts, 'last' => time()]), LOCK_EX);
                rcLogAudit('password_reset_requested', 'auth', 'Password reset code sent for: ' . ($user['username'] ?? 'admin'));
            }
        } elseif ($action === 'verify_otp') {
            $otp = preg_replace('/\s+/', '', trim((string)($_POST['otp'] ?? '')));
            $stage = 'code';
            if (!preg_match('/^\d{6}$/', (string)$otp)) {
                $error = 'Enter the 6-digit code from the email.';
            } elseif (!$user) {
                $error = 'No recovery email is saved yet. Sign in and set one on Account Security, or ask the school office.';
                $stage = 'request';
            } else {
                $stmt = $db->prepare('SELECT id, username, password_reset_token, (password_reset_expires > NOW()) AS otp_live FROM users WHERE id = ? LIMIT 1');
                $stmt->execute([(int)$user['id']]);
                $row = $stmt->fetch(PDO::FETCH_ASSOC);
                $stored = is_array($row) ? (string)($row['password_reset_token'] ?? '') : '';
                $live = is_array($row) && (int)($row['otp_live'] ?? 0) === 1 && $stored !== '';
                $given = hash('sha256', (string)$otp);
                $dummy = hash('sha256', 'hcc-otp-dummy');
                $matches = hash_equals($stored !== '' ? $stored : $dummy, $given) && $stored !== '';

                if (!$live) {
                    unset($_SESSION['hcc_otp_pending']);
                    $stage = 'request';
                    $error = 'That code has expired. Send a new one.';
                } elseif (!$matches) {
                    $fails = hccOtpFailCount($stored) + 1;
                    if ($fails >= 5) {
                        $burn = $db->prepare('UPDATE users SET password_reset_token = NULL, password_reset_expires = NULL WHERE id = ?');
                        $burn->execute([(int)$user['id']]);
                        hccOtpFailClear($stored);
                        unset($_SESSION['hcc_otp_pending']);
                        $stage = 'request';
                        $error = 'Too many incorrect codes. Send a new one.';
                        rcLogAudit('password_reset_otp_locked', 'auth', 'Reset code locked after repeated failures for: ' . ($user['username'] ?? 'admin'));
                    } else {
                        hccOtpFailSet($stored, $fails);
                        $left = 5 - $fails;
                        $error = 'That code is incorrect. ' . $left . ' attempt' . ($left === 1 ? '' : 's') . ' left.';
                        rcLogAudit('password_reset_otp_failed', 'auth', 'Incorrect reset code for: ' . ($user['username'] ?? 'admin'));
                    }
                } else {
                    $clear = $db->prepare('UPDATE users SET password_reset_token = NULL, password_reset_expires = NULL WHERE id = ? AND password_reset_token = ?');
                    $clear->execute([(int)$user['id'], $stored]);
                    if ($clear->rowCount() !== 1) {
                        unset($_SESSION['hcc_otp_pending']);
                        $stage = 'request';
                        $error = 'That code is no longer valid. Send a new one.';
                    } else {
                        hccOtpFailClear($stored);
                        session_regenerate_id(true);
                        $_SESSION['last_regeneration'] = time();
                        $_SESSION['hcc_pwd_reset_uid'] = (int)$user['id'];
                        $_SESSION['hcc_pwd_reset_until'] = time() + 600;
                        unset($_SESSION['hcc_otp_pending']);
                        rcLogAudit('password_reset_otp_verified', 'auth', 'Reset code verified for: ' . ($user['username'] ?? 'admin'));
                        header('Location: reset_password.php');
                        exit;
                    }
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
    <meta name="description" content="Hindustani Covenant Church High School — Admin Panel Forgot Password">
    <meta name="robots" content="noindex, nofollow">
    <title>Forgot Password | Hindustani Covenant Church High School</title>
    <link rel="icon" href="<?= BASE_URL ?>assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap@5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin-login.css?v=<?= (is_file(__DIR__ . '/../assets/css/admin-login.css') ? filemtime(__DIR__ . '/../assets/css/admin-login.css') : 0) ?>">
    <style>
        .auth-card.forgot-card { padding: 40px 56px 36px; }
        .forgot-back {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: .82rem; font-weight: 600; color: var(--brand);
            text-decoration: none; margin-bottom: 1.25rem;
        }
        .forgot-back:hover { color: var(--brand-dark); text-decoration: underline; }
        .reset-hint { font-size: .8rem; color: #64748b; margin-top: .5rem; }
        .masked-mail {
            display: flex; align-items: center; gap: 10px;
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 10px;
            padding: 10px 12px; margin-bottom: 16px; color: #0f172a; font-weight: 700;
        }
        .masked-mail i { color: var(--brand); }
        .otp-input {
            text-align: center; font-size: 1.35rem; font-weight: 700;
            letter-spacing: 0.28em; font-variant-numeric: tabular-nums;
        }
        .btn-resend {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; height: 44px; margin-top: 10px;
            background: #fff; color: var(--brand); border: 1.5px solid var(--brand);
            border-radius: 10px; font-weight: 700; font-size: .92rem;
        }
        .btn-resend:hover { background: rgba(122, 34, 56, 0.06); color: var(--brand); }
        @media (max-width: 575.98px) {
            .auth-card.forgot-card { padding: 28px 24px 24px; }
        }
    </style>
</head>
<body class="auth-body">
    <div class="container-fluid p-0">
        <div class="row g-0 min-vh-100">
            <aside class="col-lg-6 brand-panel d-none d-lg-flex flex-column justify-content-between p-4 p-xl-5">
                <span class="brand-orb brand-orb--gold" aria-hidden="true"></span>
                <span class="brand-orb brand-orb--rose" aria-hidden="true"></span>
                <span class="brand-dots" aria-hidden="true"></span>

                <header class="brand-head d-flex align-items-center gap-3 position-relative">
                    <img class="brand-logo" src="<?= BASE_URL ?>assets/images/hcclogo.jpg" alt="Hindustani Covenant Church High School logo">
                    <div>
                        <h1 class="brand-school-name mb-0">Hindustani Covenant Church High School</h1>
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

            <main class="col-lg-6 auth-panel d-flex flex-column align-items-center justify-content-center p-3 p-sm-4 p-lg-5" aria-label="Forgot password">
                <div class="auth-mobile-brand d-lg-none" aria-hidden="true">
                    <img src="<?= BASE_URL ?>assets/images/hcclogo.jpg" alt="HCC Logo">
                    <div>
                        <strong>Hindustani Covenant Church High School</strong>
                        <span>Admin Portal</span>
                    </div>
                </div>

                <div class="auth-card forgot-card w-100">
                    <a class="forgot-back" href="login.php">
                        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to sign in
                    </a>

                    <span class="eyebrow badge rounded-pill d-inline-flex align-items-center gap-1 mb-2">
                        <i class="fa-solid fa-key" aria-hidden="true"></i> Account Recovery
                    </span>

                    <?php if ($stage === 'code'): ?>
                        <h2 class="auth-title fw-bold mb-1">Enter the code</h2>
                        <p class="text-muted mb-4">Type the 6-digit code from your recovery email. Then you can choose a new password.</p>
                    <?php else: ?>
                        <h2 class="auth-title fw-bold mb-1">Forgot password?</h2>
                        <p class="text-muted mb-4">A 6-digit code will be sent to your recovery email. Enter that code here to choose a new password.</p>
                    <?php endif; ?>

                    <?php if ($error): ?>
                        <div class="alert alert-danger d-flex align-items-start gap-2 py-2 px-3" role="alert">
                            <i class="fa-solid fa-circle-exclamation flex-shrink-0 mt-1" aria-hidden="true"></i>
                            <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($success): ?>
                        <div class="alert alert-success d-flex align-items-start gap-2 py-2 px-3" role="alert">
                            <i class="fa-solid fa-circle-check flex-shrink-0 mt-1" aria-hidden="true"></i>
                            <span><?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($maskedEmail !== ''): ?>
                        <div class="masked-mail">
                            <i class="fa-solid fa-envelope" aria-hidden="true"></i>
                            <span><?= htmlspecialchars($maskedEmail, ENT_QUOTES, 'UTF-8') ?></span>
                        </div>
                    <?php endif; ?>

                    <?php if ($stage === 'code'): ?>
                        <form method="POST" id="verifyForm" autocomplete="one-time-code">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="verify_otp">
                            <div class="mb-3">
                                <label for="otp" class="form-label">One-time code</label>
                                <input type="text" class="form-control otp-input" id="otp" name="otp" inputmode="numeric" pattern="[0-9]{6}" maxlength="6" minlength="6" required autofocus autocomplete="one-time-code" placeholder="------" spellcheck="false">
                                <p class="reset-hint">Check the inbox and the spam folder. The code expires in 10 minutes.</p>
                            </div>
                            <button type="submit" class="btn btn-primary btn-lg w-100 btn-login" id="verifyBtn">
                                <i class="fa-solid fa-shield-halved btn-ico" aria-hidden="true"></i>
                                <i class="fa-solid fa-spinner fa-spin btn-spin" aria-hidden="true"></i>
                                <span>Verify code</span>
                            </button>
                        </form>
                        <form method="POST" id="resendForm">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="send_otp">
                            <button type="submit" class="btn-resend" id="resendBtn">
                                <i class="fa-solid fa-paper-plane" aria-hidden="true"></i>
                                <span>Resend code</span>
                            </button>
                        </form>
                    <?php elseif ($recoveryEmail !== ''): ?>
                        <form method="POST" id="forgotForm" autocomplete="off">
                            <?= csrfField() ?>
                            <input type="hidden" name="action" value="send_otp">
                            <button type="submit" class="btn btn-primary btn-lg w-100 btn-login" id="forgotBtn">
                                <i class="fa-solid fa-paper-plane btn-ico" aria-hidden="true"></i>
                                <i class="fa-solid fa-spinner fa-spin btn-spin" aria-hidden="true"></i>
                                <span>Send OTP</span>
                            </button>
                        </form>
                    <?php endif; ?>
                </div>
                <p class="auth-copy text-center text-muted small mt-4 mb-0 position-relative">&copy; <?= date('Y') ?> Hindustani Covenant Church High School, Solapur. All rights reserved.</p>
            </main>
        </div>
    </div>
    <script src="<?= BASE_URL ?>assets/js/admin-login.js?v=<?= (is_file(__DIR__ . '/../assets/js/admin-login.js') ? filemtime(__DIR__ . '/../assets/js/admin-login.js') : 0) ?>" defer></script>
</body>
</html>
