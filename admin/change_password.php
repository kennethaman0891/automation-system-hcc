<?php
/**
 * HCC Automation System — Admin Security & Password Management
 * Allows authenticated administrators to update their login credentials and
 * configure the dedicated recovery email where password reset codes are sent.
 */
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

$pwdError     = '';
$pwdSuccess   = '';
$emailError   = '';
$emailSuccess = '';
$smtpError    = '';
$smtpSuccess  = '';
$googleError  = '';
$googleSuccess= '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!verifyCSRFToken()) {
        $pwdError = 'Security check failed. Please refresh the page and try again.';
    } else {
        // Smart action inference in case form submitted via Enter key without button value
        $action = trim($_POST['action'] ?? '');
        if ($action === '') {
            if (isset($_POST['recovery_email'])) {
                $action = 'update_email';
            } elseif (isset($_POST['smtp_user'])) {
                $action = 'save_smtp';
            } elseif (isset($_POST['google_client_id'])) {
                $action = 'save_google_oauth';
            } elseif (isset($_POST['current_password']) || isset($_POST['new_password'])) {
                $action = 'change_password';
            }
        }

        // ── 1. Change Password Action ─────────────────────────────────────────
        if ($action === 'change_password') {
            $currentPass = $_POST['current_password'] ?? '';
            $newPass     = $_POST['new_password'] ?? '';
            $confirmPass = $_POST['confirm_password'] ?? '';

            if (trim($currentPass) === '' || trim($newPass) === '' || trim($confirmPass) === '') {
                $pwdError = 'All password fields are required.';
            } elseif ($newPass !== $confirmPass) {
                $pwdError = 'The new password and confirmation password do not match.';
            } elseif (strlen($newPass) < 8) {
                $pwdError = 'New password must be at least 8 characters in length.';
            } elseif (!preg_match('/[0-9]/', $newPass) && !preg_match('/[^a-zA-Z0-9]/', $newPass)) {
                $pwdError = 'New password must include at least one number or special character.';
            } else {
                if (!password_verify($currentPass, $currentUser['password'])) {
                    $pwdError = 'Incorrect current password. Please try again.';
                    rcLogAudit('password_change_failed', 'user', 'Failed password change attempt for user: ' . ($currentUser['username'] ?? 'id:' . $adminId));
                } else {
                    $hashed = password_hash($newPass, PASSWORD_BCRYPT, ['cost' => 12]);
                    $upd = $db->prepare("UPDATE users SET password = ? WHERE id = ?");
                    if ($upd->execute([$hashed, $adminId])) {
                        $pwdSuccess = 'Password successfully updated! Your new credentials are now active.';
                        $currentUser['password'] = $hashed;
                        rcLogAudit('password_change_success', 'user', 'Password changed successfully for user: ' . $currentUser['username']);
                    } else {
                        $pwdError = 'Failed to update password due to a database error.';
                    }
                }
            }

        // ── 2. Update Recovery Email Action ───────────────────────────────────
        } elseif ($action === 'update_email') {
            $newEmail = trim($_POST['recovery_email'] ?? '');
            if ($newEmail === '') {
                $emailError = 'Please enter a valid recovery email address.';
            } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $emailError = 'Invalid email format. Please enter a valid address (e.g. principal@gmail.com).';
            } else {
                // Ensure no conflicting user has this email
                $chk = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
                $chk->execute([$newEmail, $adminId]);
                if ($chk->fetch()) {
                    $emailError = 'This email address is already assigned to another user account.';
                } else {
                    $upd = $db->prepare("UPDATE users SET email = ? WHERE id = ?");
                    if ($upd->execute([$newEmail, $adminId])) {
                        $currentUser['email'] = $newEmail;
                        $emailSuccess = 'Recovery email successfully saved to "' . htmlspecialchars($newEmail) . '". All password reset codes will now be sent strictly to this address.';
                        rcLogAudit('recovery_email_updated', 'user', 'Recovery email updated to: ' . $newEmail . ' for user: ' . $currentUser['username']);
                    } else {
                        $emailError = 'Database error while saving recovery email.';
                    }
                }
            }

        // ── 3. Test Reset Email Action ───────────────────────────────────────
        } elseif ($action === 'test_email') {
            $inputEmail = trim($_POST['recovery_email'] ?? '');

            // If the user entered an email in the field, validate and save it first
            if ($inputEmail !== '' && filter_var($inputEmail, FILTER_VALIDATE_EMAIL)) {
                $chk = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
                $chk->execute([$inputEmail, $adminId]);
                if (!$chk->fetch()) {
                    $upd = $db->prepare("UPDATE users SET email = ? WHERE id = ?");
                    $upd->execute([$inputEmail, $adminId]);
                    $currentUser['email'] = $inputEmail;
                }
            }

            $targetEmail = trim($currentUser['email'] ?? '');
            if ($targetEmail === '' || str_ends_with(strtolower($targetEmail), '.local')) {
                // Fallback to configured SMTP user if present
                $smtpFallback = trim($settings['smtp_user'] ?? '');
                if ($smtpFallback !== '' && filter_var($smtpFallback, FILTER_VALIDATE_EMAIL)) {
                    $targetEmail = $smtpFallback;
                    $upd = $db->prepare("UPDATE users SET email = ? WHERE id = ?");
                    $upd->execute([$targetEmail, $adminId]);
                    $currentUser['email'] = $targetEmail;
                }
            }

            if ($targetEmail === '' || str_ends_with(strtolower($targetEmail), '.local')) {
                $emailError = 'Please enter an active email address (e.g. your Gmail) in the field above before sending a verification test.';
            } else {
                $sent = rcSendRecoveryConnectedEmail($targetEmail, $currentUser['full_name'] ?? 'Administrator');
                if ($sent) {
                    $emailSuccess = 'Verification email sent successfully to ' . htmlspecialchars($targetEmail) . '! Please check your inbox (and spam folder).';
                } else {
                    $lastErr = rcGetLastMailError();
                    $emailError = 'Direct delivery could not be completed to ' . htmlspecialchars($targetEmail) . ($lastErr ? ': ' . htmlspecialchars($lastErr) : '. A debug record has been logged in logs/forgot_password_debug.txt.');
                }
            }

        // ── 4. Save Outgoing SMTP Settings Action ─────────────────────────────
        } elseif ($action === 'save_smtp') {
            $smtpHost   = trim($_POST['smtp_host'] ?? 'smtp.gmail.com');
            $smtpPort   = (int)($_POST['smtp_port'] ?? 587);
            $smtpUser   = trim($_POST['smtp_user'] ?? '');
            $smtpPass   = trim($_POST['smtp_pass'] ?? '');
            $smtpSecure = trim($_POST['smtp_secure'] ?? 'tls');
            $syncEmail  = !empty($_POST['sync_recovery_email']);

            // Validate before persisting so a typo cannot silently break mail delivery.
            if ($smtpUser !== '' && !filter_var($smtpUser, FILTER_VALIDATE_EMAIL)) {
                $smtpError = 'Enter a valid sender address, for example hccschool@gmail.com.';
            }
            if ($smtpError === '' && $smtpPass !== '') {
                $compactPass = preg_replace('/\s+/', '', $smtpPass);
                if (strlen($compactPass) !== 16) {
                    $smtpError = 'A Google app password is exactly 16 characters — you entered '
                        . strlen($compactPass)
                        . '. Copy it again from myaccount.google.com/apppasswords (spaces are fine).';
                }
            }

            if ($smtpError === '') {
            saveSetting('smtp_host', $smtpHost);
            saveSetting('smtp_port', (string)$smtpPort);
            saveSetting('smtp_user', $smtpUser);
            if ($smtpPass !== '') {
                saveSetting('smtp_pass', preg_replace('/\s+/', '', $smtpPass));
            }
            saveSetting('smtp_secure', $smtpSecure);

            $settings = getAllSettings();

            // Auto-sync recovery email if current email is placeholder or sync requested
            $currEmail = trim($currentUser['email'] ?? '');
            $isLocal = ($currEmail === '' || str_ends_with(strtolower($currEmail), '.local'));
            if ($smtpUser !== '' && filter_var($smtpUser, FILTER_VALIDATE_EMAIL) && ($syncEmail || $isLocal)) {
                $chk = $db->prepare("SELECT id FROM users WHERE email = ? AND id != ? LIMIT 1");
                $chk->execute([$smtpUser, $adminId]);
                if (!$chk->fetch()) {
                    $upd = $db->prepare("UPDATE users SET email = ? WHERE id = ?");
                    $upd->execute([$smtpUser, $adminId]);
                    $currentUser['email'] = $smtpUser;
                }
                $smtpSuccess = 'Outgoing SMTP mail server settings saved successfully! Also connected ' . htmlspecialchars($smtpUser) . ' as your account recovery email.';
            } else {
                $smtpSuccess = 'Outgoing SMTP mail server settings saved successfully!';
            }
            rcLogAudit('smtp_settings_updated', 'user', 'Updated SMTP outgoing server settings.');
            }

        // ── 5. Save Google OAuth & Drive Settings Action ──────────────────────
        } elseif ($action === 'save_google_oauth') {
            $gClientId     = trim($_POST['google_client_id'] ?? '');
            $gClientSecret = trim($_POST['google_client_secret'] ?? '');
            $gRedirectUri  = trim($_POST['google_redirect_uri'] ?? '');

            saveSetting('google_client_id', $gClientId);
            if ($gClientSecret !== '') {
                saveSetting('google_client_secret', $gClientSecret);
            }
            saveSetting('google_redirect_uri', $gRedirectUri);

            $settings = getAllSettings();
            $googleSuccess = 'Google Sign-In & Drive Workspace configuration saved successfully!';
            rcLogAudit('google_oauth_settings_updated', 'user', 'Updated Google Cloud OAuth client configuration.');
        }
    }
}

$userEmail = trim($currentUser['email'] ?? '');
$isLocalEmail = ($userEmail === '' || str_ends_with(strtolower($userEmail), '.local'));
$smtpConfigured = !empty($settings['smtp_user']) && !empty($settings['smtp_pass']);
$googleConfigured = !empty($settings['google_client_id']) && !empty($settings['google_client_secret']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Account Security &amp; Password | <?= sanitize($schoolName) ?></title>
    <link rel="icon" href="../assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="../assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="../assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="../assets/css/admin.css?v=<?= filemtime(__DIR__ . '/../assets/css/admin.css') ?>">
    <style>
        :root {
            --brand-maroon: #7a2238;
            --brand-dark: #5a1a2a;
            --brand-deep: #3d0f1c;
            --gold-accent: #c9a227;
        }

        .security-container {
            max-width: 1180px;
            margin: 20px auto 40px;
            padding: 0 16px;
        }

        /* ── 2-Column Responsive Layout ── */
        .security-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 24px;
            align-items: start;
        }
        @media (max-width: 991.98px) {
            .security-grid {
                grid-template-columns: 1fr;
            }
        }

        .security-card {
            background: #ffffff;
            border: 1px solid var(--border, #e2e8f0);
            border-radius: 16px;
            padding: 28px 30px;
            box-shadow: 0 4px 20px -4px rgba(15, 23, 42, 0.06);
            position: relative;
            overflow: hidden;
        }
        .security-card::before {
            content: "";
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 4px;
            background: linear-gradient(90deg, var(--brand-maroon), var(--gold-accent));
        }

        .card-header-flex {
            display: flex;
            align-items: flex-start;
            gap: 16px;
            padding-bottom: 18px;
            border-bottom: 1px solid var(--border, #e2e8f0);
            margin-bottom: 22px;
        }
        .card-icon-badge {
            width: 48px;
            height: 48px;
            border-radius: 12px;
            background: rgba(122, 34, 56, 0.08);
            color: var(--brand-maroon);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.25rem;
            flex-shrink: 0;
            border: 1px solid rgba(122, 34, 56, 0.12);
        }
        .card-icon-badge.gold {
            background: rgba(201, 162, 39, 0.12);
            color: #92400e;
            border-color: rgba(201, 162, 39, 0.25);
        }
        .card-header-flex h2 {
            margin: 0;
            font-size: 1.2rem;
            font-weight: 800;
            color: var(--ink, #0f172a);
            letter-spacing: -0.01em;
        }
        .card-header-flex p {
            margin: 3px 0 0;
            font-size: 0.82rem;
            color: var(--text-2, #64748b);
            line-height: 1.4;
        }

        .form-group {
            margin-bottom: 18px;
        }
        .form-group label {
            display: block;
            font-size: 0.82rem;
            font-weight: 700;
            color: var(--ink, #0f172a);
            margin-bottom: 6px;
        }
        .input-eye-wrap {
            position: relative;
            display: flex;
            align-items: center;
        }
        .pwd-input {
            width: 100%;
            height: 44px;
            padding: 0 42px 0 14px;
            font-size: 0.92rem;
            border: 1.5px solid var(--border, #cbd5e1);
            border-radius: 9px;
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
            font-family: inherit;
            color: #0f172a;
            background: #ffffff;
        }
        .pwd-input:focus {
            border-color: var(--brand-maroon);
            box-shadow: 0 0 0 3.5px rgba(122, 34, 56, 0.12);
        }
        .pwd-input-simple {
            padding-right: 14px;
        }
        .toggle-pwd-btn {
            position: absolute;
            right: 10px;
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 0.95rem;
            padding: 6px;
            border-radius: 6px;
        }
        .toggle-pwd-btn:hover { color: var(--brand-maroon); }

        /* Strength Meter */
        .strength-meter {
            height: 5px;
            background: #e2e8f0;
            border-radius: 4px;
            margin-top: 8px;
            overflow: hidden;
        }
        .strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.25s, background-color 0.25s;
        }
        .strength-label {
            font-size: 0.74rem;
            font-weight: 700;
            margin-top: 5px;
            display: block;
            color: #64748b;
        }

        .pwd-requirements {
            background: #f8fafc;
            border: 1px solid var(--border, #e2e8f0);
            border-radius: 9px;
            padding: 12px 14px;
            font-size: 0.77rem;
            color: #475569;
            margin-bottom: 20px;
        }
        .pwd-requirements ul {
            margin: 6px 0 0;
            padding-left: 18px;
        }
        .pwd-requirements li {
            margin-bottom: 2px;
        }

        /* Buttons */
        .btn-submit-pwd {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            height: 46px;
            padding: 0 16px;
            background: linear-gradient(135deg, var(--brand-maroon) 0%, var(--brand-dark) 100%);
            color: #fff;
            border: none;
            border-radius: 9px;
            font-size: 0.92rem;
            font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(122, 34, 56, 0.28);
            transition: transform 0.15s, box-shadow 0.15s, filter 0.15s;
        }
        .btn-submit-pwd:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(122, 34, 56, 0.35);
            color: #fff;
        }
        .btn-test-mail {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            width: 100%;
            height: 40px;
            margin-top: 10px;
            padding: 0 16px;
            background: #ffffff;
            color: var(--brand-maroon);
            border: 1.5px solid var(--brand-maroon);
            border-radius: 9px;
            font-size: 0.85rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.15s, color 0.15s;
        }
        .btn-test-mail:hover {
            background: rgba(122, 34, 56, 0.06);
        }

        /* Status Pills & Alerts */
        .status-badge-box {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 10px;
            font-size: 0.82rem;
            margin-bottom: 20px;
            line-height: 1.45;
        }
        .status-badge-box.active {
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            color: #166534;
        }
        .status-badge-box.warn {
            background: #fffbeb;
            border: 1px solid #fde68a;
            color: #92400e;
        }
        .status-badge-box i {
            font-size: 1.1rem;
            margin-top: 2px;
            flex-shrink: 0;
        }

        .alert-box {
            padding: 10px 14px;
            border-radius: 9px;
            font-size: 0.84rem;
            font-weight: 600;
            margin-bottom: 18px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            line-height: 1.4;
        }
        .alert-error {
            background: #fef2f2;
            color: #991b1b;
            border: 1px solid #fee2e2;
        }
        .alert-success {
            background: #f0fdf4;
            color: #166534;
            border: 1px solid #bbf7d0;
        }

        .how-it-works-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 14px;
            margin-top: 20px;
            font-size: 0.8rem;
            color: #475569;
        }
        .how-it-works-box strong {
            color: #0f172a;
            display: block;
            margin-bottom: 4px;
        }

        /* ── Collapsible settings drawers ── */
        .smtp-accordion {
            margin-top: 22px;
            border: 1px solid var(--border, #e2e8f0);
            border-radius: 12px;
            overflow: hidden;
            background: #ffffff;
        }
        .smtp-accordion + .smtp-accordion { margin-top: 14px; }

        .smtp-toggle-btn {
            width: 100%;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            padding: 13px 16px;
            background: #f8fafc;
            border: none;
            font-family: inherit;
            color: #0f172a;
            cursor: pointer;
            text-align: left;
            transition: background 0.15s ease;
        }
        .smtp-toggle-btn:hover { background: #f1f5f9; }
        .smtp-toggle-btn:focus-visible { outline: 2px solid var(--brand-maroon); outline-offset: -2px; }

        .smtp-acc-left { display: flex; align-items: center; gap: 11px; min-width: 0; }
        .smtp-acc-ico {
            width: 32px; height: 32px; border-radius: 9px; flex-shrink: 0;
            display: inline-flex; align-items: center; justify-content: center;
            background: rgba(122, 34, 56, 0.08);
            color: var(--brand-maroon);
            font-size: 0.84rem;
            border: 1px solid rgba(122, 34, 56, 0.12);
        }
        .smtp-acc-title { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
        .smtp-acc-title strong { font-size: 0.87rem; font-weight: 800; color: #0f172a; letter-spacing: -0.01em; }
        .smtp-acc-title span { font-size: 0.73rem; font-weight: 500; color: #64748b; }
        .smtp-acc-right { display: flex; align-items: center; gap: 9px; flex-shrink: 0; }
        .smtp-acc-right .fa-chevron-down,
        .smtp-acc-right .fa-chevron-up { color: #94a3b8; font-size: 0.72rem; }

        .smtp-badge-pill {
            font-size: 0.63rem;
            font-weight: 800;
            letter-spacing: 0.045em;
            padding: 3px 9px;
            border-radius: 999px;
            text-transform: uppercase;
            white-space: nowrap;
        }
        .smtp-badge-pill.on { background: #dcfce7; color: #15803d; }
        .smtp-badge-pill.off { background: #f1f5f9; color: #64748b; border: 1px solid #e2e8f0; }

        .smtp-body {
            display: none;
            background: #ffffff;
            border-top: 1px solid #e2e8f0;
            padding: 18px;
        }
        .smtp-body.show { display: block; }

        /* Connection status strip */
        .smtp-status {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 10px;
            border: 1px solid;
            margin-bottom: 4px;
        }
        .smtp-status.is-on { background: #f0fdf4; border-color: #bbf7d0; }
        .smtp-status.is-off { background: #fffbeb; border-color: #fde68a; }
        .smtp-status-ic {
            width: 30px; height: 30px; border-radius: 50%; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; font-size: 0.84rem;
        }
        .smtp-status.is-on .smtp-status-ic { background: #dcfce7; color: #15803d; }
        .smtp-status.is-off .smtp-status-ic { background: #fef3c7; color: #b45309; }
        .smtp-status-txt { min-width: 0; line-height: 1.45; }
        .smtp-status-txt strong { display: block; font-size: 0.84rem; font-weight: 800; color: #0f172a; }
        .smtp-status-txt span { font-size: 0.78rem; color: #475569; }
        .smtp-status-txt code {
            background: rgba(15, 23, 42, 0.06);
            padding: 1px 5px;
            border-radius: 4px;
            font-size: 0.76rem;
            color: #0f172a;
        }

        /* Numbered form sections */
        .smtp-section { padding: 16px 0; border-top: 1px dashed #e2e8f0; }
        .smtp-section.first { padding-top: 16px; border-top: none; }
        .smtp-section.last { padding-bottom: 4px; }
        .smtp-section-head { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 13px; }
        .smtp-section-num {
            width: 22px; height: 22px; border-radius: 50%; flex-shrink: 0; margin-top: 1px;
            display: inline-flex; align-items: center; justify-content: center;
            background: linear-gradient(135deg, var(--brand-maroon), var(--brand-dark));
            color: #fff; font-size: 0.68rem; font-weight: 800;
        }
        .smtp-section-head strong { display: block; font-size: 0.84rem; font-weight: 800; color: #0f172a; }
        .smtp-section-sub { display: block; font-size: 0.75rem; color: #64748b; margin-top: 1px; line-height: 1.4; }

        .smtp-body .form-group { margin-bottom: 14px; }
        .smtp-body .form-group:last-child { margin-bottom: 0; }
        .smtp-body .form-group label { font-size: 0.78rem; }
        .smtp-hint { display: block; margin-top: 6px; font-size: 0.74rem; color: #64748b; line-height: 1.45; }
        .smtp-hint code { background: #f1f5f9; padding: 1px 5px; border-radius: 4px; color: #334155; }

        /* Numbered instruction list */
        .smtp-steps { list-style: none; margin: 0; padding: 0; display: flex; flex-direction: column; gap: 8px; }
        .smtp-steps li { display: flex; gap: 10px; font-size: 0.78rem; color: #475569; line-height: 1.45; }
        .smtp-steps li > span {
            width: 18px; height: 18px; border-radius: 50%; flex-shrink: 0; margin-top: 1px;
            display: inline-flex; align-items: center; justify-content: center;
            background: #f1f5f9; color: #475569; font-size: 0.62rem; font-weight: 800;
            border: 1px solid #e2e8f0;
        }
        .smtp-steps a {
            color: var(--brand-maroon);
            font-weight: 700;
            text-decoration: none;
            border-bottom: 1px solid rgba(122, 34, 56, 0.3);
        }
        .smtp-steps a:hover { border-bottom-color: var(--brand-maroon); }
        .smtp-steps kbd {
            background: #0f172a; color: #fff; font-size: 0.66rem;
            padding: 1px 5px; border-radius: 4px; font-family: inherit; font-weight: 700;
        }

        /* Fixed server details */
        .smtp-server { display: flex; flex-wrap: wrap; gap: 8px; }
        .smtp-chip {
            display: inline-flex; align-items: center; gap: 6px;
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;
            padding: 6px 10px; font-size: 0.74rem; color: #64748b; font-weight: 600;
        }
        .smtp-chip i { color: #94a3b8; font-size: 0.68rem; }
        .smtp-chip b { color: #0f172a; font-weight: 800; }

        /* Labelled checkbox card */
        .smtp-check {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 12px 14px; margin: 0 0 16px;
            border: 1px solid #e2e8f0; border-radius: 10px;
            background: #f8fafc; cursor: pointer;
            transition: border-color 0.15s ease, background 0.15s ease;
        }
        .smtp-check:hover { border-color: #cbd5e1; background: #f1f5f9; }
        .smtp-check input {
            width: 17px; height: 17px; margin: 1px 0 0; flex-shrink: 0;
            accent-color: var(--brand-maroon); cursor: pointer;
        }
        .smtp-check-txt { font-size: 0.78rem; color: #334155; line-height: 1.45; font-weight: 600; }
        .smtp-check-txt small { display: block; color: #64748b; font-size: 0.73rem; font-weight: 500; margin-top: 1px; }

        /* Branded save button */
        .smtp-save {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            width: 100%; height: 44px; padding: 0 16px;
            background: linear-gradient(135deg, var(--brand-maroon) 0%, var(--brand-dark) 100%);
            color: #fff; border: none; border-radius: 9px;
            font-family: inherit; font-size: 0.88rem; font-weight: 700;
            cursor: pointer;
            box-shadow: 0 4px 14px rgba(122, 34, 56, 0.24);
            transition: transform 0.15s, box-shadow 0.15s;
        }
        .smtp-save:hover {
            transform: translateY(-1px);
            box-shadow: 0 8px 18px rgba(122, 34, 56, 0.32);
            color: #fff;
        }
        .smtp-save:active { transform: translateY(0); }

        /* Inline copy-to-clipboard control */
        .copy-row { display: flex; gap: 8px; align-items: stretch; }
        .copy-row .pwd-input { min-width: 0; }
        .copy-btn {
            flex-shrink: 0; width: 44px; border-radius: 9px;
            border: 1.5px solid var(--border, #cbd5e1); background: #ffffff;
            color: #64748b; cursor: pointer; font-size: 0.82rem;
            transition: border-color 0.15s, color 0.15s, background 0.15s;
        }
        .copy-btn:hover { border-color: var(--brand-maroon); color: var(--brand-maroon); background: rgba(122, 34, 56, 0.05); }
        .copy-btn.copied { border-color: #16a34a; color: #16a34a; background: #f0fdf4; }

        @media (max-width: 575.98px) {
            .smtp-body { padding: 14px; }
            .smtp-acc-title span { display: none; }
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
                    <h1 class="page-title">Account Security &amp; Password</h1>
                    <p class="page-sub"><?= sanitize($schoolName) ?> &bull; Credentials &amp; Recovery Management</p>
                </div>
                <div class="header-actions">
                    <div class="admin-user">
                        <i class="fas fa-shield-alt"></i>
                        <span><?= sanitize($adminUser) ?> (<?= strtoupper(sanitize($adminRole)) ?>)</span>
                    </div>
                </div>
            </header>

            <div class="admin-content">
                <div class="security-container">

                    <div class="security-grid">

                        <!-- ════════ COLUMN 1: UPDATE PASSWORD ════════ -->
                        <div class="security-card">
                            <div class="card-header-flex">
                                <div class="card-icon-badge">
                                    <i class="fas fa-key"></i>
                                </div>
                                <div>
                                    <h2>Account Password</h2>
                                    <p>Update your personal administrator login password.</p>
                                </div>
                            </div>

                            <?php if ($pwdError): ?>
                            <div class="alert-box alert-error">
                                <i class="fas fa-circle-exclamation flex-shrink-0 mt-1"></i>
                                <span><?= sanitize($pwdError) ?></span>
                            </div>
                            <?php endif; ?>

                            <?php if ($pwdSuccess): ?>
                            <div class="alert-box alert-success">
                                <i class="fas fa-circle-check flex-shrink-0 mt-1"></i>
                                <span><?= sanitize($pwdSuccess) ?></span>
                            </div>
                            <?php endif; ?>

                            <form method="POST" action="change_password.php">
                                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                <input type="hidden" name="action" value="change_password">

                                <div class="form-group">
                                    <label for="current_password">Current Password</label>
                                    <div class="input-eye-wrap">
                                        <input type="password" name="current_password" id="current_password" class="pwd-input" required autocomplete="current-password" placeholder="Enter current password">
                                        <button type="button" class="toggle-pwd-btn" onclick="toggleVisibility('current_password', this)" title="Show / hide password" tabindex="-1"><i class="fas fa-eye"></i></button>
                                    </div>
                                </div>

                                <div class="form-group">
                                    <label for="new_password">New Password</label>
                                    <div class="input-eye-wrap">
                                        <input type="password" name="new_password" id="new_password" class="pwd-input" required autocomplete="new-password" placeholder="Enter new password (min. 8 chars)" oninput="checkStrength(this.value)">
                                        <button type="button" class="toggle-pwd-btn" onclick="toggleVisibility('new_password', this)" title="Show / hide password" tabindex="-1"><i class="fas fa-eye"></i></button>
                                    </div>
                                    <div class="strength-meter">
                                        <div class="strength-bar" id="strengthBar"></div>
                                    </div>
                                    <span class="strength-label" id="strengthLabel">Password strength: Empty</span>
                                </div>

                                <div class="form-group">
                                    <label for="confirm_password">Confirm New Password</label>
                                    <div class="input-eye-wrap">
                                        <input type="password" name="confirm_password" id="confirm_password" class="pwd-input" required autocomplete="new-password" placeholder="Re-enter new password">
                                        <button type="button" class="toggle-pwd-btn" onclick="toggleVisibility('confirm_password', this)" title="Show / hide password" tabindex="-1"><i class="fas fa-eye"></i></button>
                                    </div>
                                </div>

                                <div class="pwd-requirements">
                                    <strong><i class="fas fa-circle-info"></i> Security Policy:</strong>
                                    <ul>
                                        <li>Minimum 8 characters in length.</li>
                                        <li>Must contain at least 1 number or symbol (e.g. <code>@</code>, <code>#</code>, <code>$</code>).</li>
                                        <li>Avoid common words, pet names, or sequential digits.</li>
                                    </ul>
                                </div>

                                <button type="submit" class="btn-submit-pwd">
                                    <i class="fas fa-lock"></i> Save New Password
                                </button>
                            </form>
                        </div>

                        <!-- ════════ COLUMN 2: RECOVERY EMAIL & RESET SETTINGS ════════ -->
                        <div class="security-card">
                            <div class="card-header-flex">
                                <div class="card-icon-badge gold">
                                    <i class="fas fa-envelope-shield"></i>
                                </div>
                                <div>
                                    <h2>Password Reset Recovery Email</h2>
                                    <p>One-time reset codes requested from the login screen are sent to this email.</p>
                                </div>
                            </div>

                            <?php if ($emailError): ?>
                            <div class="alert-box alert-error">
                                <i class="fas fa-circle-exclamation flex-shrink-0 mt-1"></i>
                                <span><?= $emailError ?></span>
                            </div>
                            <?php endif; ?>

                            <?php if ($emailSuccess): ?>
                            <div class="alert-box alert-success">
                                <i class="fas fa-circle-check flex-shrink-0 mt-1"></i>
                                <span><?= $emailSuccess ?></span>
                            </div>
                            <?php endif; ?>

                            <!-- Current Recovery Email Status Badge -->
                            <?php if ($isLocalEmail): ?>
                            <div class="status-badge-box warn">
                                <i class="fas fa-triangle-exclamation"></i>
                                <div>
                                    <strong>Local Placeholder Active: <code><?= htmlspecialchars($userEmail ?: 'None') ?></code></strong>
                                    <div>Enter your active email (e.g. Gmail) below so you can safely recover your password if you ever forget it.</div>
                                </div>
                            </div>
                            <?php else: ?>
                            <div class="status-badge-box active">
                                <i class="fas fa-circle-check"></i>
                                <div>
                                    <strong>Connected Recovery Address: <code><?= htmlspecialchars($userEmail) ?></code></strong>
                                    <div>All password reset codes and security alerts are routed directly to this inbox.</div>
                                </div>
                            </div>
                            <?php endif; ?>

                            <form method="POST" action="change_password.php" id="recoveryEmailForm">
                                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                <input type="hidden" name="action" id="recoveryFormAction" value="update_email">

                                <div class="form-group">
                                    <label for="recovery_email">Password Reset Recovery Email Address</label>
                                    <div class="input-eye-wrap">
                                        <input type="email" name="recovery_email" id="recovery_email" class="pwd-input pwd-input-simple" required placeholder="e.g. principal@gmail.com" value="<?= htmlspecialchars($userEmail) ?>">
                                    </div>
                                    <small class="text-muted d-block mt-1" style="font-size:0.76rem; color:#64748b;">
                                        Enter your official school or administrator Gmail address.
                                    </small>
                                </div>

                                <button type="submit" onclick="document.getElementById('recoveryFormAction').value='update_email';" class="btn-submit-pwd" style="background:#7a2238;">
                                    <i class="fas fa-floppy-disk"></i> Save Recovery Email
                                </button>

                                <button type="submit" onclick="document.getElementById('recoveryFormAction').value='test_email';" class="btn-test-mail" title="Sends a verification email to ensure messages reach your inbox">
                                    <i class="fas fa-paper-plane"></i> Send Test Verification Email
                                </button>
                            </form>

                            <div class="how-it-works-box">
                                <strong><i class="fas fa-shield-alt"></i> How Account Recovery Works:</strong>
                                <div>When you click <em>"Forgot password?"</em> on the sign-in page, a 6-digit code is sent to this address. It expires in 10 minutes. Enter the code, then choose a new password.</div>
                            </div>

                            <!-- Optional: Outgoing Mail Server (Free Gmail SMTP) Configuration Drawer -->
                            <div class="smtp-accordion">
                                <button type="button" class="smtp-toggle-btn" onclick="toggleSmtpDrawer()">
                                    <span class="smtp-acc-left">
                                        <span class="smtp-acc-ico"><i class="fa-brands fa-google"></i></span>
                                        <span class="smtp-acc-title">
                                            <strong>Outgoing Mail Relay</strong>
                                            <span>Gmail SMTP &middot; delivers reset codes to any inbox</span>
                                        </span>
                                    </span>
                                    <span class="smtp-acc-right">
                                        <span class="smtp-badge-pill <?= $smtpConfigured ? 'on' : 'off' ?>">
                                            <?= $smtpConfigured ? 'Configured' : 'Not configured' ?>
                                        </span>
                                        <i class="fas fa-chevron-down" id="smtpChevron"></i>
                                    </span>
                                </button>

                                <div class="smtp-body" id="smtpBody">
                                    <!-- Live connection status -->
                                    <div class="smtp-status <?= $smtpConfigured ? 'is-on' : 'is-off' ?>">
                                        <div class="smtp-status-ic">
                                            <i class="fas <?= $smtpConfigured ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
                                        </div>
                                        <div class="smtp-status-txt">
                                            <strong><?= $smtpConfigured ? 'Mail relay is ready' : 'Mail relay not configured' ?></strong>
                                            <span>
                                                <?php if ($smtpConfigured): ?>
                                                    Sending as <code><?= htmlspecialchars($settings['smtp_user']) ?></code> through
                                                    <code>smtp.gmail.com</code> on port <code>587</code> with TLS.
                                                <?php else: ?>
                                                    Password reset emails cannot be delivered until a Gmail sender and its app password are saved below.
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>

                                    <?php if ($smtpError): ?>
                                    <div class="alert-box alert-error" style="margin-top:16px;">
                                        <i class="fas fa-circle-exclamation flex-shrink-0 mt-1"></i>
                                        <span><?= $smtpError ?></span>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($smtpSuccess): ?>
                                    <div class="alert-box alert-success" style="margin-top:16px;">
                                        <i class="fas fa-circle-check flex-shrink-0 mt-1"></i>
                                        <span><?= $smtpSuccess ?></span>
                                    </div>
                                    <?php endif; ?>

                                    <form method="POST" action="change_password.php">
                                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                        <input type="hidden" name="action" value="save_smtp">
                                        <input type="hidden" name="smtp_host" value="smtp.gmail.com">
                                        <input type="hidden" name="smtp_port" value="587">
                                        <input type="hidden" name="smtp_secure" value="tls">

                                        <!-- 1 · Sender credentials -->
                                        <div class="smtp-section first">
                                            <div class="smtp-section-head">
                                                <span class="smtp-section-num">1</span>
                                                <div>
                                                    <strong>Sender account</strong>
                                                    <span class="smtp-section-sub">The Gmail account the school sends email from.</span>
                                                </div>
                                            </div>

                                            <div class="form-group">
                                                <label for="smtp_user">Sender Gmail address</label>
                                                <input type="email" name="smtp_user" id="smtp_user" class="pwd-input pwd-input-simple"
                                                       placeholder="e.g. hccschool@gmail.com"
                                                       value="<?= htmlspecialchars($settings['smtp_user'] ?? '') ?>">
                                            </div>

                                            <div class="form-group">
                                                <label for="smtp_pass">Google app password</label>
                                                <div class="input-eye-wrap">
                                                    <input type="password" name="smtp_pass" id="smtp_pass" class="pwd-input"
                                                           autocomplete="new-password"
                                                           placeholder="<?= $smtpConfigured ? 'Leave blank to keep current' : 'abcd efgh ijkl mnop' ?>">
                                                    <button type="button" class="toggle-pwd-btn" onclick="toggleVisibility('smtp_pass', this)" title="Show / hide" tabindex="-1"><i class="fas fa-eye"></i></button>
                                                </div>
                                                <small class="smtp-hint">
                                                    Not your normal Gmail password — use a 16-character <strong>app password</strong> created in step 2.
                                                </small>
                                            </div>
                                        </div>

                                        <!-- 2 · How to generate the app password -->
                                        <div class="smtp-section">
                                            <div class="smtp-section-head">
                                                <span class="smtp-section-num">2</span>
                                                <div>
                                                    <strong>Get an app password</strong>
                                                    <span class="smtp-section-sub">Takes about a minute. Requires 2-Step Verification.</span>
                                                </div>
                                            </div>

                                            <ol class="smtp-steps">
                                                <li>
                                                    <span>1</span>
                                                    <div>Turn on <strong>2-Step Verification</strong> in your <a href="https://myaccount.google.com/security" target="_blank" rel="noopener">Google Account &rarr; Security</a>.</div>
                                                </li>
                                                <li>
                                                    <span>2</span>
                                                    <div>Open <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">App Passwords</a> and create one named <kbd>HCC School Portal</kbd>.</div>
                                                </li>
                                                <li>
                                                    <span>3</span>
                                                    <div>Copy the 16-character code Google shows and paste it above — spaces are ignored.</div>
                                                </li>
                                            </ol>
                                        </div>

                                        <!-- 3 · Fixed server settings -->
                                        <div class="smtp-section last">
                                            <div class="smtp-section-head">
                                                <span class="smtp-section-num">3</span>
                                                <div>
                                                    <strong>Server settings</strong>
                                                    <span class="smtp-section-sub">Fixed for Gmail — nothing to change here.</span>
                                                </div>
                                            </div>
                                            <div class="smtp-server">
                                                <span class="smtp-chip"><i class="fas fa-server"></i> Host <b>smtp.gmail.com</b></span>
                                                <span class="smtp-chip"><i class="fas fa-plug"></i> Port <b>587</b></span>
                                                <span class="smtp-chip"><i class="fas fa-lock"></i> Encryption <b>TLS</b></span>
                                            </div>
                                        </div>

                                        <label class="smtp-check" for="syncRecoveryEmail">
                                            <input class="form-check-input" type="checkbox" name="sync_recovery_email" id="syncRecoveryEmail" value="1" <?= ($isLocalEmail || strcasecmp($userEmail, $settings['smtp_user'] ?? '') === 0) ? 'checked' : '' ?>>
                                            <span class="smtp-check-txt">
                                                Link this Gmail address as my recovery email
                                                <small>Password reset codes will be delivered to this inbox.</small>
                                            </span>
                                        </label>

                                        <button type="submit" class="smtp-save">
                                            <i class="fas fa-floppy-disk"></i> Save Mail Relay Settings
                                        </button>
                                    </form>
                                </div>
                            </div>

                            <!-- Google Sign-In & Drive Workspace Configuration Drawer -->
                            <div class="smtp-accordion">
                                <button type="button" class="smtp-toggle-btn" onclick="toggleGoogleDrawer()">
                                    <span class="smtp-acc-left">
                                        <span class="smtp-acc-ico"><i class="fab fa-google-drive"></i></span>
                                        <span class="smtp-acc-title">
                                            <strong>Google Sign-In &amp; Drive</strong>
                                            <span>One-click login &middot; Drive workspace sync</span>
                                        </span>
                                    </span>
                                    <span class="smtp-acc-right">
                                        <span class="smtp-badge-pill <?= $googleConfigured ? 'on' : 'off' ?>">
                                            <?= $googleConfigured ? 'Configured' : 'Setup required' ?>
                                        </span>
                                        <i class="fas fa-chevron-down" id="googleChevron"></i>
                                    </span>
                                </button>

                                <div class="smtp-body" id="googleBody">
                                    <!-- Live connection status -->
                                    <div class="smtp-status <?= $googleConfigured ? 'is-on' : 'is-off' ?>">
                                        <div class="smtp-status-ic">
                                            <i class="fas <?= $googleConfigured ? 'fa-circle-check' : 'fa-triangle-exclamation' ?>"></i>
                                        </div>
                                        <div class="smtp-status-txt">
                                            <strong><?= $googleConfigured ? 'Google integration is active' : 'Google integration not configured' ?></strong>
                                            <span>
                                                <?php if ($googleConfigured): ?>
                                                    OAuth client <code><?= htmlspecialchars(substr($settings['google_client_id'], 0, 22)) ?>…</code> is ready for teacher sign-in and Drive workspace sync.
                                                <?php else: ?>
                                                    Google Sign-In and automated Drive workspaces stay disabled until a Cloud OAuth client is saved below.
                                                <?php endif; ?>
                                            </span>
                                        </div>
                                    </div>

                                    <?php if ($googleError): ?>
                                    <div class="alert-box alert-error" style="margin-top:16px;">
                                        <i class="fas fa-circle-exclamation flex-shrink-0 mt-1"></i>
                                        <span><?= $googleError ?></span>
                                    </div>
                                    <?php endif; ?>

                                    <?php if ($googleSuccess): ?>
                                    <div class="alert-box alert-success" style="margin-top:16px;">
                                        <i class="fas fa-circle-check flex-shrink-0 mt-1"></i>
                                        <span><?= $googleSuccess ?></span>
                                    </div>
                                    <?php endif; ?>

                                    <form method="POST" action="change_password.php">
                                        <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                        <input type="hidden" name="action" value="save_google_oauth">

                                        <!-- 1 · OAuth client -->
                                        <div class="smtp-section first">
                                            <div class="smtp-section-head">
                                                <span class="smtp-section-num">1</span>
                                                <div>
                                                    <strong>OAuth client</strong>
                                                    <span class="smtp-section-sub">From Google Cloud Console &rarr; APIs &amp; Services &rarr; Credentials.</span>
                                                </div>
                                            </div>

                                            <div class="form-group">
                                                <label for="google_client_id">Client ID</label>
                                                <input type="text" name="google_client_id" id="google_client_id" class="pwd-input pwd-input-simple"
                                                       placeholder="e.g. 123456789-xxxx.apps.googleusercontent.com"
                                                       value="<?= htmlspecialchars($settings['google_client_id'] ?? '') ?>">
                                            </div>

                                            <div class="form-group">
                                                <label for="google_client_secret">Client secret</label>
                                                <div class="input-eye-wrap">
                                                    <input type="password" name="google_client_secret" id="google_client_secret" class="pwd-input"
                                                           autocomplete="new-password"
                                                           placeholder="<?= $googleConfigured ? 'Leave blank to keep current' : 'e.g. GOCSPX-xxxx' ?>">
                                                    <button type="button" class="toggle-pwd-btn" onclick="toggleVisibility('google_client_secret', this)" title="Show / hide" tabindex="-1"><i class="fas fa-eye"></i></button>
                                                </div>
                                            </div>
                                        </div>

                                        <!-- 2 · Redirect URI -->
                                        <div class="smtp-section last">
                                            <div class="smtp-section-head">
                                                <span class="smtp-section-num">2</span>
                                                <div>
                                                    <strong>Authorized redirect URI</strong>
                                                    <span class="smtp-section-sub">Paste this exact value into Google Cloud Console.</span>
                                                </div>
                                            </div>

                                            <div class="form-group">
                                                <div class="copy-row">
                                                    <input type="text" name="google_redirect_uri" id="google_redirect_uri" class="pwd-input pwd-input-simple"
                                                           value="<?= htmlspecialchars($settings['google_redirect_uri'] ?: (new HCCGoogleDrive())->getRedirectUri()) ?>"
                                                           readonly onclick="this.select()">
                                                    <button type="button" class="copy-btn" id="copyRedirectBtn" onclick="copyRedirectUri(this)" title="Copy to clipboard"><i class="fas fa-copy"></i></button>
                                                </div>
                                                <small class="smtp-hint">
                                                    Add it under <strong>Credentials &rarr; OAuth 2.0 Client IDs &rarr; Authorized redirect URIs</strong>.
                                                    A mismatch here causes <code>redirect_uri_mismatch</code> on sign-in.
                                                </small>
                                            </div>
                                        </div>

                                        <button type="submit" class="smtp-save">
                                            <i class="fas fa-floppy-disk"></i> Save Google Integration
                                        </button>
                                    </form>
                                </div>
                            </div>

                        </div>

                    </div>

                </div>
            </div>
        </main>
    </div>

    <script>
    function toggleVisibility(inputId, btn) {
        const inp = document.getElementById(inputId);
        if (!inp) return;
        const icon = btn.querySelector('i');
        if (inp.type === 'password') {
            inp.type = 'text';
            icon.classList.remove('fa-eye');
            icon.classList.add('fa-eye-slash');
        } else {
            inp.type = 'password';
            icon.classList.remove('fa-eye-slash');
            icon.classList.add('fa-eye');
        }
    }

    function checkStrength(pwd) {
        const bar = document.getElementById('strengthBar');
        const label = document.getElementById('strengthLabel');
        if (!pwd) {
            bar.style.width = '0%';
            bar.style.backgroundColor = '#e2e8f0';
            label.textContent = 'Password strength: Empty';
            label.style.color = '#64748b';
            return;
        }

        let score = 0;
        if (pwd.length >= 8) score++;
        if (pwd.length >= 12) score++;
        if (/[0-9]/.test(pwd)) score++;
        if (/[^a-zA-Z0-9]/.test(pwd)) score++;
        if (/[A-Z]/.test(pwd) && /[a-z]/.test(pwd)) score++;

        if (score <= 1) {
            bar.style.width = '25%';
            bar.style.backgroundColor = '#ef4444';
            label.textContent = 'Password strength: Weak';
            label.style.color = '#ef4444';
        } else if (score <= 3) {
            bar.style.width = '60%';
            bar.style.backgroundColor = '#f59e0b';
            label.textContent = 'Password strength: Medium';
            label.style.color = '#f59e0b';
        } else {
            bar.style.width = '100%';
            bar.style.backgroundColor = '#10b981';
            label.textContent = 'Password strength: Strong';
            label.style.color = '#10b981';
        }
    }

    function copyRedirectUri(btn) {
        const input = document.getElementById('google_redirect_uri');
        if (!input) return;
        const value = input.value;
        const done = () => {
            const icon = btn.querySelector('i');
            btn.classList.add('copied');
            if (icon) { icon.classList.remove('fa-copy'); icon.classList.add('fa-check'); }
            setTimeout(() => {
                btn.classList.remove('copied');
                if (icon) { icon.classList.remove('fa-check'); icon.classList.add('fa-copy'); }
            }, 1800);
        };
        if (navigator.clipboard && window.isSecureContext) {
            navigator.clipboard.writeText(value).then(done).catch(() => {
                input.select(); document.execCommand('copy'); done();
            });
        } else {
            input.select();
            try { document.execCommand('copy'); } catch (e) {}
            done();
        }
    }

    function toggleSmtpDrawer() {
        const body = document.getElementById('smtpBody');
        const chevron = document.getElementById('smtpChevron');
        if (!body) return;
        body.classList.toggle('show');
        if (chevron) {
            chevron.classList.toggle('fa-chevron-up');
            chevron.classList.toggle('fa-chevron-down');
        }
    }

    function toggleGoogleDrawer() {
        const body = document.getElementById('googleBody');
        const chevron = document.getElementById('googleChevron');
        if (!body) return;
        body.classList.toggle('show');
        if (chevron) {
            chevron.classList.toggle('fa-chevron-up');
            chevron.classList.toggle('fa-chevron-down');
        }
    }

    <?php if ($smtpSuccess || $smtpError || (($action ?? '') === 'save_smtp')): ?>
    document.addEventListener('DOMContentLoaded', function() {
        toggleSmtpDrawer();
    });
    <?php endif; ?>

    <?php if ($googleSuccess || $googleError || (($action ?? '') === 'save_google_oauth')): ?>
    document.addEventListener('DOMContentLoaded', function() {
        toggleGoogleDrawer();
    });
    <?php endif; ?>
    </script>
    <script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= (is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 0) ?>"></script>
</body>
</html>
