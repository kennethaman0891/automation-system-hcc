<?php
/**
 * HCC Automation System — Reset Password
 * Opens only after a one-time code was verified on the forgot-password page.
 * The proof lives in the server session for 10 minutes. Old email links are retired.
 *
 * SECURITY NOTES:
 *  - The code is never accepted from the URL.
 *  - The new password follows the same rules as Account Security.
 *  - The session proof is cleared as soon as the password is saved.
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

$error = '';
$retiredLink = trim((string)($_GET['token'] ?? '')) !== '';

$uid = (int)($_SESSION['hcc_pwd_reset_uid'] ?? 0);
$until = (int)($_SESSION['hcc_pwd_reset_until'] ?? 0);
$proofOk = $uid > 0 && $until >= time();
if (!$proofOk) {
    unset($_SESSION['hcc_pwd_reset_uid'], $_SESSION['hcc_pwd_reset_until']);
    $uid = 0;
}

$db = getDB();
$user = null;
if ($proofOk) {
    $stmt = $db->prepare('SELECT id, username, full_name, role FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$uid]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    if (!$user) {
        unset($_SESSION['hcc_pwd_reset_uid'], $_SESSION['hcc_pwd_reset_until']);
        $proofOk = false;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        $error = 'Security check failed. Please refresh and try again.';
    } elseif (!$proofOk || !$user) {
        $error = 'Your reset session has expired. Request a new code.';
    } else {
        $newPass = $_POST['new_password'] ?? '';
        $confirmPass = $_POST['confirm_password'] ?? '';

        if (trim((string)$newPass) === '' || trim((string)$confirmPass) === '') {
            $error = 'Both password fields are required.';
        } elseif ($newPass !== $confirmPass) {
            $error = 'The new password and confirmation password do not match.';
        } elseif (strlen((string)$newPass) < 8) {
            $error = 'New password must be at least 8 characters in length.';
        } elseif (!preg_match('/[0-9]/', (string)$newPass) && !preg_match('/[^a-zA-Z0-9]/', (string)$newPass)) {
            $error = 'New password must include at least one number or special character.';
        } else {
            $hashed = password_hash((string)$newPass, PASSWORD_BCRYPT, ['cost' => 12]);
            $updatePass = $db->prepare('UPDATE users SET password = ?, password_reset_token = NULL, password_reset_expires = NULL WHERE id = ?');
            if ($updatePass->execute([$hashed, $user['id']])) {
                $username = (string)$user['username'];
                rcLogAudit('password_reset_success', 'auth', 'Password reset successfully for user: ' . $username);
                session_regenerate_id(true);
                $_SESSION = [];
                $_SESSION['initiated'] = true;
                $_SESSION['last_regeneration'] = time();
                $_SESSION['last_activity'] = time();
                $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
                header('Location: login.php?notice=password_reset');
                exit;
            } else {
                $error = 'Failed to update password. Please try again or contact support.';
                rcLogAudit('password_reset_db_error', 'auth', 'DB update failed during password reset for user: ' . $user['username']);
            }
        }
    }
}

if (!$proofOk && $error === '') {
    $error = $retiredLink
        ? 'This reset link is no longer used. Request a one-time code from the forgot-password page.'
        : 'Request a one-time code before choosing a new password.';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Hindustani Covenant Church High School — Admin Panel Reset Password">
    <meta name="robots" content="noindex, nofollow">
    <title>Reset Password | Hindustani Covenant Church High School</title>
    <link rel="icon" href="<?= BASE_URL ?>assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap@5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin-login.css?v=<?= (is_file(__DIR__ . '/../assets/css/admin-login.css') ? filemtime(__DIR__ . '/../assets/css/admin-login.css') : 0) ?>">
    <style>
        .auth-card.reset-card { padding: 28px 30px 26px; }
        .reset-back {
            display: inline-flex; align-items: center; gap: 6px;
            font-size: .82rem; font-weight: 600; color: var(--brand);
            text-decoration: none; margin-bottom: 1.1rem;
        }
        .reset-back:hover { color: var(--brand-dark); text-decoration: underline; }
        .acct-head {
            display: flex; align-items: flex-start; gap: 14px;
            padding-bottom: 16px; margin-bottom: 18px;
            border-bottom: 1px solid #e2e8f0;
        }
        .acct-badge {
            width: 48px; height: 48px; border-radius: 12px; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center;
            background: rgba(122, 34, 56, 0.08); color: #7a2238;
            border: 1px solid rgba(122, 34, 56, 0.12); font-size: 1.15rem;
        }
        .acct-head h2 { margin: 0; font-size: 1.2rem; font-weight: 800; color: #1e1020; letter-spacing: -0.01em; }
        .acct-head p { margin: 3px 0 0; font-size: .82rem; color: #64748b; }
        .pwd-field { margin-bottom: 16px; }
        .pwd-field label { display: block; font-size: .82rem; font-weight: 700; color: #1e1020; margin-bottom: 6px; }
        .pwd-wrap { position: relative; }
        .pwd-input {
            width: 100%; height: 44px; padding: 0 42px 0 14px;
            font-size: .92rem; border: 1.5px solid #cbd5e1; border-radius: 9px;
            outline: none; color: #0f172a; background: #fff; font-family: inherit;
        }
        .pwd-input:focus { border-color: #7a2238; box-shadow: 0 0 0 3.5px rgba(122, 34, 56, 0.12); }
        .toggle-pwd-btn {
            position: absolute; right: 8px; top: 50%; transform: translateY(-50%);
            background: none; border: none; color: #94a3b8; cursor: pointer; padding: 6px;
        }
        .toggle-pwd-btn:hover { color: #7a2238; }
        .strength-meter { height: 5px; background: #e2e8f0; border-radius: 4px; margin-top: 8px; overflow: hidden; }
        .strength-bar { height: 100%; width: 0%; transition: width .25s, background-color .25s; }
        .strength-label { font-size: .74rem; font-weight: 700; margin-top: 5px; display: block; color: #64748b; }
        .pwd-requirements {
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 9px;
            padding: 12px 14px; font-size: .77rem; color: #475569; margin-bottom: 18px;
        }
        .pwd-requirements ul { margin: 6px 0 0; padding-left: 18px; }
        .pwd-requirements li { margin-bottom: 2px; }
        .btn-save-pwd {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; height: 46px; border: none; border-radius: 9px;
            background: linear-gradient(135deg, #7a2238 0%, #5a1a2a 100%);
            color: #fff; font-size: .92rem; font-weight: 700; cursor: pointer;
            box-shadow: 0 4px 14px rgba(122, 34, 56, 0.28);
        }
        .btn-save-pwd:hover { color: #fff; transform: translateY(-1px); }
        .btn-save-pwd .btn-spin { display: none; }
        .btn-save-pwd.loading .btn-spin { display: inline-block; }
        .btn-save-pwd.loading .btn-ico { display: none; }
        @media (max-width: 575.98px) {
            .auth-card.reset-card { padding: 24px 20px 20px; }
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

            <main class="col-lg-6 auth-panel d-flex flex-column align-items-center justify-content-center p-3 p-sm-4 p-lg-5" aria-label="Reset password">
                <div class="auth-mobile-brand d-lg-none" aria-hidden="true">
                    <img src="<?= BASE_URL ?>assets/images/hcclogo.jpg" alt="HCC Logo">
                    <div>
                        <strong>Hindustani Covenant Church High School</strong>
                        <span>Admin Portal</span>
                    </div>
                </div>

                <div class="auth-card reset-card w-100">
                    <a class="reset-back" href="login.php">
                        <i class="fa-solid fa-arrow-left" aria-hidden="true"></i> Back to sign in
                    </a>

                        <div class="acct-head">
                            <div class="acct-badge" aria-hidden="true"><i class="fa-solid fa-key"></i></div>
                            <div>
                                <h2>Account Password</h2>
                                <p>Update your administrator login password.</p>
                            </div>
                        </div>

                        <?php if ($error): ?>
                            <div class="alert alert-danger d-flex align-items-start gap-2 py-2 px-3" role="alert">
                                <i class="fa-solid fa-circle-exclamation flex-shrink-0 mt-1" aria-hidden="true"></i>
                                <span><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></span>
                            </div>
                        <?php endif; ?>

                        <?php if ($proofOk && $user): ?>
                            <form method="POST" id="resetForm" autocomplete="off">
                                <?= csrfField() ?>
                                <div class="pwd-field">
                                    <label for="new_password">New Password</label>
                                    <div class="pwd-wrap">
                                        <input type="password" class="pwd-input" id="new_password" name="new_password" required autocomplete="new-password" placeholder="Enter new password (min. 8 chars)">
                                        <button type="button" class="toggle-pwd-btn" data-toggle-password="new_password" aria-label="Show password">
                                            <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                    <div class="strength-meter"><div class="strength-bar" id="strengthBar"></div></div>
                                    <span class="strength-label" id="strengthLabel">Password strength: Empty</span>
                                </div>

                                <div class="pwd-field">
                                    <label for="confirm_password">Confirm New Password</label>
                                    <div class="pwd-wrap">
                                        <input type="password" class="pwd-input" id="confirm_password" name="confirm_password" required autocomplete="new-password" placeholder="Re-enter new password">
                                        <button type="button" class="toggle-pwd-btn" data-toggle-password="confirm_password" aria-label="Show password">
                                            <i class="fa-solid fa-eye" aria-hidden="true"></i>
                                        </button>
                                    </div>
                                </div>

                                <div class="pwd-requirements">
                                    <strong><i class="fa-solid fa-circle-info" aria-hidden="true"></i> Security Policy:</strong>
                                    <ul>
                                        <li>Minimum 8 characters in length.</li>
                                        <li>Must contain at least 1 number or symbol (e.g. @, #, $).</li>
                                        <li>Avoid common words, pet names, or sequential digits.</li>
                                    </ul>
                                </div>

                                <button type="submit" class="btn-save-pwd" id="resetBtn">
                                    <i class="fa-solid fa-lock btn-ico" aria-hidden="true"></i>
                                    <i class="fa-solid fa-spinner fa-spin btn-spin" aria-hidden="true"></i>
                                    <span>Save New Password</span>
                                </button>
                            </form>
                        <?php else: ?>
                            <a class="btn btn-primary btn-lg w-100 btn-login" href="forgot_password.php">
                                <i class="fa-solid fa-paper-plane btn-ico" aria-hidden="true"></i>
                                <span>Request a code</span>
                            </a>
                        <?php endif; ?>
                </div>
                <p class="auth-copy text-center text-muted small mt-4 mb-0 position-relative">&copy; <?= date('Y') ?> Hindustani Covenant Church High School, Solapur. All rights reserved.</p>
            </main>
        </div>
    </div>
    <script src="<?= BASE_URL ?>assets/js/admin-login.js?v=<?= (is_file(__DIR__ . '/../assets/js/admin-login.js') ? filemtime(__DIR__ . '/../assets/js/admin-login.js') : 0) ?>" defer></script>
</body>
</html>
