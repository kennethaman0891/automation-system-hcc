<?php
/**
 * HCC Automation System — My Account, Security & Recovery Settings
 * Combines profile photo, text size, password management, recovery email,
 * outgoing Gmail SMTP relay, and Google OAuth / Drive configuration.
 */
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/google_drive.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../includes/user_account.php';

$adminPage  = 'account';
$settings   = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church High School';
$schoolLogoRel = trim((string)($settings['logo'] ?? ''));
if ($schoolLogoRel === '' || !is_file(__DIR__ . '/../' . ltrim($schoolLogoRel, '/'))) {
    $schoolLogoRel = 'assets/images/hcclogo.jpg';
}
$schoolLogoUrl = BASE_URL . ltrim($schoolLogoRel, '/');
$adminUser  = $_SESSION['admin_name'] ?? 'Admin';
$adminId    = (int)($_SESSION['admin_id'] ?? 0);
$adminRole  = $_SESSION['admin_role'] ?? 'editor';
$isAdmin    = ($adminRole === 'admin');

$db = getDB();
$stmt = $db->prepare("SELECT * FROM users WHERE id = ? LIMIT 1");
$stmt->execute([$adminId]);
$currentUser = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$currentUser) {
    header('Location: logout.php');
    exit;
}

function hccAccountRedirect(string $type, string $text): void
{
    $_SESSION['hcc_account_flash'] = ['type' => $type === 'ok' ? 'ok' : 'err', 'text' => $text];
    header('Location: account.php');
    exit;
}

$pwdError      = '';
$pwdSuccess    = '';
$emailError    = '';
$emailSuccess  = '';
$smtpError     = '';
$smtpSuccess   = '';
$googleError   = '';
$googleSuccess = '';
$action        = '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $postedLen = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($postedLen > 0 && empty($_POST) && empty($_FILES)) {
        hccAccountRedirect('err', 'That photo is too large. Use a JPG, PNG, or WebP under 2 MB.');
    }

    if (isset($_POST['account_action'])) {
        if (!verifyCSRFToken()) {
            hccAccountRedirect('err', 'Security check failed. Refresh the page and try again.');
        }

        $accountAction = (string)($_POST['account_action'] ?? '');
        $acctNow = hccCurrentAccount();

        if ($accountAction === 'font') {
            $step = (string)($_POST['font_step'] ?? '');
            $steps = hccFontSteps();
            $current = (int)$acctNow['font_percent'];
            $idx = array_search($current, $steps, true);
            if ($idx === false) {
                $idx = array_search(100, $steps, true);
            }
            if ($step === 'up') {
                if ($idx >= count($steps) - 1) {
                    hccAccountRedirect('err', 'Text size is already at the largest setting.');
                }
                $next = $steps[$idx + 1];
            } elseif ($step === 'down') {
                if ($idx <= 0) {
                    hccAccountRedirect('err', 'Text size is already at the smallest setting.');
                }
                $next = $steps[$idx - 1];
            } elseif ($step === 'reset') {
                $next = 100;
            } else {
                hccAccountRedirect('err', 'Choose increase, decrease, or reset.');
            }
            $err = hccSaveFontPercent($adminId, $next);
            if ($err !== '') {
                hccAccountRedirect('err', $err);
            }
            $label = hccFontPercentLabel($next);
            hccAccountRedirect('ok', 'Text size is now ' . $next . '% (' . $label . '). It applies on every admin page for your account.');
        }

        if ($accountAction === 'photo') {
            if ((string)($_POST['photo_step'] ?? '') === 'remove') {
                $err = hccRemoveProfilePhoto($adminId);
                hccAccountRedirect($err === '' ? 'ok' : 'err', $err === '' ? 'Profile photo removed.' : $err);
            }
            $err = hccSaveUploadedProfilePhoto($adminId, $_FILES['photo'] ?? []);
            hccAccountRedirect($err === '' ? 'ok' : 'err', $err === '' ? 'Profile photo saved. It now shows on your account and in the admin header.' : $err);
        }

        hccAccountRedirect('err', 'That account action is not available.');
    }

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
            if (!$isAdmin) {
                http_response_code(403);
                $smtpError = 'Forbidden: Only administrators can modify global SMTP mail settings.';
                $pwdError  = $smtpError;
                rcLogAudit('unauthorized_settings_change', 'user', 'Unauthorized save_smtp attempt by user: ' . ($currentUser['username'] ?? 'id:' . $adminId));
            } else {
                $smtpHost   = trim($_POST['smtp_host'] ?? 'smtp.gmail.com');
                $smtpPort   = (int)($_POST['smtp_port'] ?? 587);
                $smtpUser   = trim($_POST['smtp_user'] ?? '');
                $smtpPass   = trim($_POST['smtp_pass'] ?? '');
                $smtpSecure = trim($_POST['smtp_secure'] ?? 'tls');
                $syncEmail  = !empty($_POST['sync_recovery_email']);

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
            }

        // ── 5. Save Google OAuth & Drive Settings Action ──────────────────────
        } elseif ($action === 'save_google_oauth') {
            if (!$isAdmin) {
                http_response_code(403);
                $googleError = 'Forbidden: Only administrators can modify global Google OAuth settings.';
                $pwdError    = $googleError;
                rcLogAudit('unauthorized_settings_change', 'user', 'Unauthorized save_google_oauth attempt by user: ' . ($currentUser['username'] ?? 'id:' . $adminId));
            } else {
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
}

$flash = $_SESSION['hcc_account_flash'] ?? null;
unset($_SESSION['hcc_account_flash']);
$flashType = is_array($flash) && (($flash['type'] ?? '') === 'ok') ? 'ok' : 'err';
$flashText = is_array($flash) ? trim((string)($flash['text'] ?? '')) : '';
if ($flashText === '') {
    $flashType = '';
}

$acct = hccCurrentAccount(true);
$fontPercent = (int)$acct['font_percent'];
$fontLabel = hccFontPercentLabel($fontPercent);
$steps = hccFontSteps();
$stepIndex = array_search($fontPercent, $steps, true);
$atMin = $stepIndex === 0;
$atMax = $stepIndex === count($steps) - 1;
$username = trim((string)($acct['username'] ?? ''));
$email = trim((string)$acct['email']);
// Local placeholder addresses are not real contact emails — treat them as unset.
$emailIsLocal = ($email === '' || str_ends_with(strtolower($email), '.local'));

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
    <?php require __DIR__ . '/includes/account_head.php'; ?>
    <title>My Account &amp; Security | <?= sanitize($schoolName) ?></title>
    <link rel="icon" href="<?= BASE_URL ?>assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin.css?v=<?= (is_file(__DIR__ . '/../assets/css/admin.css') ? filemtime(__DIR__ . '/../assets/css/admin.css') : 0) ?>">
    <style>
        :root {
            --brand-maroon: #7a2238;
            --brand-dark: #5a1a2a;
            --brand-deep: #3d0f1c;
            --gold-accent: #c9a227;
        }

        .account-wrap {
            max-width: 1120px;
            margin: 0 auto 40px;
            display: grid;
            gap: 20px;
        }

        .account-banner {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 12px 16px; border-radius: 10px; font-size: 0.9rem; font-weight: 600; line-height: 1.45;
        }
        .account-banner.ok { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .account-banner.err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }

        /* ── Institutional Identity Strip ── */
        .account-school-bar {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            flex-wrap: wrap;
            background: var(--paper, #ffffff);
            border: 1px solid var(--border, #e2e8f0);
            border-left: 4px solid var(--brand-maroon);
            border-radius: 12px;
            padding: 16px 22px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
        }
        .account-school-brand {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
        }
        .account-school-logo {
            width: 48px;
            height: 48px;
            border-radius: 10px;
            object-fit: cover;
            border: 1px solid var(--border, #e2e8f0);
            background: #fff;
            flex-shrink: 0;
        }
        .account-school-brand h2 {
            margin: 0;
            font-size: 1.05rem;
            font-weight: 700;
            color: var(--ink, #0f172a);
            letter-spacing: -0.01em;
        }
        .account-school-brand p {
            margin: 2px 0 0;
            font-size: 0.82rem;
            color: var(--text-2, #64748b);
        }
        .account-role-pill {
            display: inline-flex;
            align-items: center;
            gap: 7px;
            padding: 6px 12px;
            border-radius: 999px;
            background: #f8fafc;
            border: 1px solid var(--border, #e2e8f0);
            font-size: 0.78rem;
            font-weight: 700;
            color: var(--ink, #0f172a);
        }
        .account-role-pill i { color: var(--brand-maroon); }

        /* ── Wide Stacked Card Grid ── */
        .account-grid {
            display: grid;
            grid-template-columns: 1fr;
            gap: 20px;
        }

        .account-card,
        .security-card {
            width: 100%;
            background: var(--paper, #ffffff);
            border: 1px solid var(--border, #e2e8f0);
            border-radius: 12px;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.04);
            padding: 24px 28px;
        }

        /* ── Unified Card Header ── */
        .card-header-flex {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 16px;
            padding-bottom: 16px;
            border-bottom: 1px solid var(--border, #e2e8f0);
            margin-bottom: 20px;
        }
        .card-header-main {
            display: flex;
            align-items: center;
            gap: 14px;
            min-width: 0;
        }
        .card-icon-badge {
            width: 42px;
            height: 42px;
            border-radius: 10px;
            background: #f8fafc;
            color: var(--brand-maroon);
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.05rem;
            flex-shrink: 0;
            border: 1px solid var(--border, #e2e8f0);
        }
        .card-icon-badge.gold {
            background: #fffbeb;
            color: #92400e;
            border-color: #fde68a;
        }
        .card-header-flex h2 {
            margin: 0;
            font-size: 1.08rem;
            font-weight: 700;
            color: var(--ink, #0f172a);
            letter-spacing: -0.01em;
        }
        .card-header-flex p {
            margin: 2px 0 0;
            font-size: 0.83rem;
            color: var(--text-2, #64748b);
            line-height: 1.4;
        }

        /* ── Profile Card Wide Layout ── */
        .account-profile-layout {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 32px;
            align-items: center;
        }
        .account-photo-col {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 10px;
        }
        .account-photo {
            width: 152px; height: 152px; border-radius: 50%;
            display: grid; place-items: center; overflow: hidden;
            background: var(--brand-maroon);
            color: #fff; font-size: 2.75rem; font-weight: 700; letter-spacing: 0.03em;
            border: 4px solid #f1f5f9;
            box-shadow: 0 2px 10px rgba(15, 23, 42, 0.08), 0 0 0 1px var(--border, #e2e8f0);
        }
        .account-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .account-profile-body { min-width: 0; }
        .account-identity-head {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px;
            margin-bottom: 14px;
        }
        .account-identity-head h3 {
            margin: 0;
            font-size: 1.35rem;
            font-weight: 700;
            color: var(--ink, #0f172a);
            letter-spacing: -0.015em;
        }
        .account-identity-badge {
            display: inline-flex;
            align-items: center;
            gap: 5px;
            padding: 3px 10px;
            border-radius: 999px;
            background: rgba(122, 34, 56, 0.08);
            color: var(--brand-maroon);
            font-size: 0.74rem;
            font-weight: 700;
            letter-spacing: 0.03em;
            text-transform: uppercase;
        }
        .account-id {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 12px 24px;
            margin: 0 0 18px;
            padding: 14px 18px;
            background: #f8fafc;
            border: 1px solid var(--border, #e2e8f0);
            border-radius: 10px;
        }
        .account-id-item dt {
            display: flex;
            align-items: center;
            gap: 6px;
            margin: 0 0 3px;
            color: var(--text-3, #64748b);
            font-size: 0.72rem;
            font-weight: 700;
            letter-spacing: 0.06em;
            text-transform: uppercase;
        }
        .account-id-item dt i { color: var(--brand-maroon); font-size: 0.72rem; }
        .account-id-item dd {
            margin: 0;
            color: var(--ink, #0f172a);
            font-size: 0.94rem;
            font-weight: 600;
            overflow-wrap: anywhere;
        }
        .account-photo-controls {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
            padding-top: 14px;
            border-top: 1px solid var(--border, #e2e8f0);
        }
        .account-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin: 0; }
        .account-file {
            flex: 1 1 240px; min-width: 0; font: inherit; font-size: 0.86rem; color: var(--text-2);
            padding: 7px 10px; border: 1px solid var(--border, #cbd5e1); border-radius: 8px; background: #fff;
        }
        .account-btn, .account-btn-quiet, .account-btn-danger {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            height: 40px; padding: 0 16px; border-radius: 8px; font: inherit; font-size: 0.86rem; font-weight: 700;
            cursor: pointer; text-decoration: none; white-space: nowrap;
            transition: background 0.15s, border-color 0.15s, color 0.15s;
        }
        .account-btn {
            border: 1px solid var(--brand-maroon); color: #fff;
            background: var(--brand-maroon);
        }
        .account-btn:hover { background: var(--brand-dark); border-color: var(--brand-dark); color: #fff; }
        .account-btn:disabled, .account-step:disabled, .account-btn-quiet:disabled {
            opacity: 0.45; cursor: not-allowed;
        }
        .account-btn-quiet, .account-btn-danger {
            background: #fff; border: 1px solid var(--border, #cbd5e1); color: var(--ink);
        }
        .account-btn-quiet:hover { border-color: var(--brand-maroon); color: var(--brand-maroon); }
        .account-btn-danger { color: #991b1b; border-color: #fecaca; background: #fff; }
        .account-btn-danger:hover { background: #fef2f2; }
        .account-hint { margin: 10px 0 0; color: var(--text-3); font-size: 0.79rem; line-height: 1.45; }

        /* ── Text Size Wide Layout ── */
        .account-font-layout {
            display: grid;
            grid-template-columns: auto 1fr;
            gap: 24px;
            align-items: center;
        }
        .account-font {
            display: flex; flex-wrap: wrap; align-items: center; gap: 10px;
        }
        .account-step {
            width: 44px; height: 44px; border-radius: 8px; border: 1px solid var(--border, #cbd5e1);
            background: #fff; color: var(--brand-maroon); font: inherit; font-weight: 800; cursor: pointer;
        }
        .account-step:hover:not(:disabled) { border-color: var(--brand-maroon); background: #fdf2f4; }
        .account-step.down { font-size: 0.9rem; }
        .account-step.up { font-size: 1.15rem; }
        .account-readout { min-width: 110px; text-align: center; margin: 0; }
        .account-readout strong { display: block; font-size: 1.35rem; color: var(--ink); letter-spacing: -0.02em; line-height: 1.1; }
        .account-readout span { color: var(--text-2, #64748b); font-size: 0.72rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; }
        .account-sample {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 14px 16px;
            border-radius: 10px;
            background: #f8fafc;
            border: 1px solid var(--border, #e2e8f0);
        }
        .account-sample-logo {
            width: 34px;
            height: 34px;
            border-radius: 7px;
            object-fit: cover;
            flex-shrink: 0;
            border: 1px solid var(--border, #e2e8f0);
        }
        .account-sample p { margin: 0; color: var(--text); font-weight: 600; }
        .account-sample p + p { margin-top: 4px; color: var(--text-2); font-size: 0.88rem; font-weight: 400; }

        /* ── Security & Recovery Wide Form Layouts ── */
        .form-grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px 20px;
        }
        .form-group { margin-bottom: 16px; }
        .form-grid-2 .form-group { margin-bottom: 0; }
        .form-group label {
            display: block;
            font-size: 0.81rem;
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
            height: 42px;
            padding: 0 40px 0 12px;
            font-size: 0.9rem;
            border: 1px solid var(--border, #cbd5e1);
            border-radius: 8px;
            outline: none;
            transition: border-color 0.15s, box-shadow 0.15s;
            font-family: inherit;
            color: #0f172a;
            background: #ffffff;
        }
        .pwd-input:focus {
            border-color: var(--brand-maroon);
            box-shadow: 0 0 0 3px rgba(122, 34, 56, 0.1);
        }
        .pwd-input-simple { padding-right: 12px; }
        .toggle-pwd-btn {
            position: absolute;
            right: 8px;
            background: none;
            border: none;
            color: #94a3b8;
            cursor: pointer;
            font-size: 0.92rem;
            padding: 6px;
            border-radius: 6px;
        }
        .toggle-pwd-btn:hover { color: var(--brand-maroon); }

        .strength-meter {
            height: 4px;
            background: #e2e8f0;
            border-radius: 4px;
            margin-top: 7px;
            overflow: hidden;
        }
        .strength-bar {
            height: 100%;
            width: 0%;
            transition: width 0.25s, background-color 0.25s;
        }
        .strength-label {
            font-size: 0.73rem;
            font-weight: 600;
            margin-top: 4px;
            display: block;
            color: #64748b;
        }

        .pwd-requirements {
            background: #f8fafc;
            border: 1px solid var(--border, #e2e8f0);
            border-radius: 8px;
            padding: 12px 14px;
            font-size: 0.78rem;
            color: #475569;
            margin: 16px 0 18px;
        }
        .pwd-requirements ul {
            margin: 6px 0 0;
            padding-left: 18px;
            display: flex;
            flex-wrap: wrap;
            gap: 4px 24px;
        }
        .pwd-requirements li { margin-bottom: 2px; }

        .btn-row {
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }
        .btn-submit-pwd {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            height: 42px;
            padding: 0 20px;
            background: var(--brand-maroon);
            color: #fff;
            border: 1px solid var(--brand-maroon);
            border-radius: 8px;
            font-size: 0.88rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.15s, border-color 0.15s;
        }
        .btn-submit-pwd:hover {
            background: var(--brand-dark);
            border-color: var(--brand-dark);
            color: #fff;
        }
        .btn-test-mail {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            height: 42px;
            padding: 0 18px;
            background: #ffffff;
            color: var(--brand-maroon);
            border: 1px solid var(--brand-maroon);
            border-radius: 8px;
            font-size: 0.86rem;
            font-weight: 700;
            cursor: pointer;
            transition: background 0.15s, color 0.15s;
        }
        .btn-test-mail:hover { background: rgba(122, 34, 56, 0.06); }

        .status-badge-box {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 8px;
            font-size: 0.83rem;
            margin-bottom: 18px;
            line-height: 1.45;
        }
        .status-badge-box.active { background: #f0fdf4; border: 1px solid #bbf7d0; color: #166534; }
        .status-badge-box.warn { background: #fffbeb; border: 1px solid #fde68a; color: #92400e; }
        .status-badge-box i { font-size: 1.05rem; margin-top: 2px; flex-shrink: 0; }

        .alert-box {
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 0.84rem;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: flex-start;
            gap: 10px;
            line-height: 1.4;
        }
        .alert-error { background: #fef2f2; color: #991b1b; border: 1px solid #fee2e2; }
        .alert-success { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }

        .how-it-works-box {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px 14px;
            margin-top: 18px;
            font-size: 0.8rem;
            color: #475569;
        }
        .how-it-works-box strong { color: #0f172a; display: block; margin-bottom: 3px; }

        /* ── Collapsible settings drawers ── */
        .smtp-accordion {
            margin-top: 18px;
            border: 1px solid var(--border, #e2e8f0);
            border-radius: 10px;
            overflow: hidden;
            background: #ffffff;
        }
        .smtp-accordion + .smtp-accordion { margin-top: 12px; }

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
            width: 32px; height: 32px; border-radius: 8px; flex-shrink: 0;
            display: inline-flex; align-items: center; justify-content: center;
            background: #fff;
            color: var(--brand-maroon);
            font-size: 0.84rem;
            border: 1px solid var(--border, #e2e8f0);
        }
        .smtp-acc-title { display: flex; flex-direction: column; gap: 1px; min-width: 0; }
        .smtp-acc-title strong { font-size: 0.87rem; font-weight: 700; color: #0f172a; }
        .smtp-acc-title span { font-size: 0.74rem; font-weight: 500; color: #64748b; }
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

        .smtp-status {
            display: flex;
            align-items: flex-start;
            gap: 12px;
            padding: 12px 14px;
            border-radius: 8px;
            border: 1px solid;
            margin-bottom: 4px;
        }
        .smtp-status.is-on { background: #f0fdf4; border-color: #bbf7d0; }
        .smtp-status.is-off { background: #fffbeb; border-color: #fde68a; }
        .smtp-status-ic {
            width: 28px; height: 28px; border-radius: 50%; flex-shrink: 0;
            display: flex; align-items: center; justify-content: center; font-size: 0.82rem;
        }
        .smtp-status.is-on .smtp-status-ic { background: #dcfce7; color: #15803d; }
        .smtp-status.is-off .smtp-status-ic { background: #fef3c7; color: #b45309; }
        .smtp-status-txt { min-width: 0; line-height: 1.45; }
        .smtp-status-txt strong { display: block; font-size: 0.84rem; font-weight: 700; color: #0f172a; }
        .smtp-status-txt span { font-size: 0.78rem; color: #475569; }
        .smtp-status-txt code {
            background: rgba(15, 23, 42, 0.06);
            padding: 1px 5px;
            border-radius: 4px;
            font-size: 0.76rem;
            color: #0f172a;
        }

        .smtp-section { padding: 16px 0; border-top: 1px dashed #e2e8f0; }
        .smtp-section.first { padding-top: 16px; border-top: none; }
        .smtp-section.last { padding-bottom: 4px; }
        .smtp-section-head { display: flex; align-items: flex-start; gap: 10px; margin-bottom: 13px; }
        .smtp-section-num {
            width: 22px; height: 22px; border-radius: 50%; flex-shrink: 0; margin-top: 1px;
            display: inline-flex; align-items: center; justify-content: center;
            background: var(--brand-maroon);
            color: #fff; font-size: 0.68rem; font-weight: 800;
        }
        .smtp-section-head strong { display: block; font-size: 0.84rem; font-weight: 700; color: #0f172a; }
        .smtp-section-sub { display: block; font-size: 0.75rem; color: #64748b; margin-top: 1px; line-height: 1.4; }

        .smtp-body .form-group { margin-bottom: 14px; }
        .smtp-body .form-group:last-child { margin-bottom: 0; }
        .smtp-body .form-group label { font-size: 0.78rem; }
        .smtp-hint { display: block; margin-top: 6px; font-size: 0.74rem; color: #64748b; line-height: 1.45; }
        .smtp-hint code { background: #f1f5f9; padding: 1px 5px; border-radius: 4px; color: #334155; }

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

        .smtp-server { display: flex; flex-wrap: wrap; gap: 8px; }
        .smtp-chip {
            display: inline-flex; align-items: center; gap: 6px;
            background: #f8fafc; border: 1px solid #e2e8f0; border-radius: 8px;
            padding: 6px 10px; font-size: 0.74rem; color: #64748b; font-weight: 600;
        }
        .smtp-chip i { color: #94a3b8; font-size: 0.68rem; }
        .smtp-chip b { color: #0f172a; font-weight: 700; }

        .smtp-check {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 12px 14px; margin: 0 0 16px;
            border: 1px solid #e2e8f0; border-radius: 8px;
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

        .smtp-save {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            height: 42px; padding: 0 20px;
            background: var(--brand-maroon);
            color: #fff; border: 1px solid var(--brand-maroon); border-radius: 8px;
            font-family: inherit; font-size: 0.88rem; font-weight: 700;
            cursor: pointer;
            transition: background 0.15s, border-color 0.15s;
        }
        .smtp-save:hover {
            background: var(--brand-dark);
            border-color: var(--brand-dark);
            color: #fff;
        }

        .copy-row { display: flex; gap: 8px; align-items: stretch; }
        .copy-row .pwd-input { min-width: 0; }
        .copy-btn {
            flex-shrink: 0; width: 42px; border-radius: 8px;
            border: 1px solid var(--border, #cbd5e1); background: #ffffff;
            color: #64748b; cursor: pointer; font-size: 0.82rem;
            transition: border-color 0.15s, color 0.15s, background 0.15s;
        }
        .copy-btn:hover { border-color: var(--brand-maroon); color: var(--brand-maroon); background: rgba(122, 34, 56, 0.05); }
        .copy-btn.copied { border-color: #16a34a; color: #16a34a; background: #f0fdf4; }

        @media (max-width: 820px) {
            .account-profile-layout,
            .account-font-layout,
            .form-grid-2,
            .account-id {
                grid-template-columns: 1fr;
            }
            .account-card, .security-card { padding: 18px; }
            .account-photo { width: 116px; height: 116px; font-size: 2.1rem; }
        }
        @media (max-width: 575.98px) {
            .smtp-body { padding: 14px; }
            .smtp-acc-title span { display: none; }
        }
    </style>
</head>
<body>
    <div class="admin-layout">
        <?php require __DIR__ . '/includes/sidebar.php'; ?>
        <main class="admin-main">
            <header class="admin-header">
                <button class="sidebar-toggle" id="sidebarToggle" type="button" aria-label="Toggle navigation" aria-controls="sidebar" aria-expanded="false">
                    <i class="fas fa-bars" aria-hidden="true"></i>
                </button>
                <div class="header-titles">
                    <h1 class="page-title">My Account &amp; Security</h1>
                    <p class="page-sub">Profile photo, text size, password &amp; recovery settings for <?= sanitize($schoolName) ?>.</p>
                </div>
                <div class="header-actions">
                    <?php require __DIR__ . '/includes/notif_bell.php'; ?>
                    <div class="admin-user">
                        <span class="user-avatar" aria-hidden="true"><?= sanitize($acct['initials']) ?></span>
                        <span class="user-meta">
                            <strong><?= sanitize($acct['full_name']) ?></strong>
                            <small><?= sanitize(ucfirst((string)$acct['role'])) ?></small>
                        </span>
                    </div>
                </div>
            </header>

            <div class="admin-content">
                <div class="account-wrap">
                    <?php if ($flashText !== ''): ?>
                    <div class="account-banner <?= $flashType === 'ok' ? 'ok' : 'err' ?>" role="status">
                        <i class="fa-solid <?= $flashType === 'ok' ? 'fa-circle-check' : 'fa-circle-exclamation' ?>" aria-hidden="true"></i>
                        <span><?= sanitize($flashText) ?></span>
                    </div>
                    <?php endif; ?>

                    <!-- ════════ INSTITUTIONAL HEADER BAR ════════ -->
                    <div class="account-school-bar">
                        <div class="account-school-brand">
                            <img class="account-school-logo" src="<?= htmlspecialchars($schoolLogoUrl, ENT_QUOTES, 'UTF-8') ?>" width="48" height="48" alt="<?= sanitize($schoolName) ?> crest">
                            <div>
                                <h2><?= sanitize($schoolName) ?></h2>
                                <p>Signed in as <strong><?= sanitize($acct['full_name']) ?></strong><?= $username !== '' ? ' &middot; @' . sanitize($username) : '' ?></p>
                            </div>
                        </div>
                        <span class="account-role-pill">
                            <i class="fa-solid fa-user-shield" aria-hidden="true"></i>
                            <?= sanitize(ucfirst((string)$acct['role'])) ?> Account
                        </span>
                    </div>

                    <!-- ════════ WIDE CARDS: PROFILE & TEXT SIZE ════════ -->
                    <div class="account-grid">
                        <section class="account-card" aria-labelledby="accountProfileHeading">
                            <div class="card-header-flex">
                                <div class="card-header-main">
                                    <div class="card-icon-badge">
                                        <i class="fa-solid fa-id-badge" aria-hidden="true"></i>
                                    </div>
                                    <div>
                                        <h2 id="accountProfileHeading">Profile</h2>
                                    </div>
                                </div>
                            </div>

                            <div class="account-profile-layout">
                                <div class="account-photo-col">
                                    <div class="account-photo" id="accountPhotoFrame">
                                        <?php if ($acct['has_photo']): ?>
                                        <img id="accountPhotoPreview" src="<?= htmlspecialchars($acct['photo_url'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                                        <span id="accountPhotoFallback" hidden><?= sanitize($acct['initials']) ?></span>
                                        <?php else: ?>
                                        <img id="accountPhotoPreview" alt="" hidden>
                                        <span id="accountPhotoFallback"><?= sanitize($acct['initials']) ?></span>
                                        <?php endif; ?>
                                    </div>
                                </div>

                                <div class="account-profile-body">
                                    <div class="account-identity-head">
                                        <h3><?= sanitize($acct['full_name']) ?></h3>
                                        <span class="account-identity-badge"><?= sanitize(ucfirst((string)$acct['role'])) ?></span>
                                    </div>

                                    <?php if ($username !== '' || !$emailIsLocal): ?>
                                    <dl class="account-id">
                                        <?php if ($username !== ''): ?>
                                        <div class="account-id-item">
                                            <dt><i class="fa-solid fa-at" aria-hidden="true"></i> Username</dt>
                                            <dd><?= sanitize($username) ?></dd>
                                        </div>
                                        <?php endif; ?>
                                        <?php if (!$emailIsLocal): ?>
                                        <div class="account-id-item">
                                            <dt><i class="fa-solid fa-envelope" aria-hidden="true"></i> Recovery Email</dt>
                                            <dd><?= sanitize($email) ?></dd>
                                        </div>
                                        <?php endif; ?>
                                    </dl>
                                    <?php endif; ?>

                                    <div class="account-photo-controls">
                                        <form method="post" action="account.php" enctype="multipart/form-data" class="account-actions" style="flex:1 1 320px;">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="account_action" value="photo">
                                            <input type="hidden" name="MAX_FILE_SIZE" value="2097152">
                                            <input class="account-file" id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" required aria-describedby="photoHint">
                                            <button class="account-btn" type="submit"><i class="fa-solid fa-upload" aria-hidden="true"></i> Save photo</button>
                                        </form>
                                        <?php if ($acct['has_photo']): ?>
                                        <form method="post" action="account.php" id="removePhotoForm" class="account-actions">
                                            <?= csrfField() ?>
                                            <input type="hidden" name="account_action" value="photo">
                                            <input type="hidden" name="photo_step" value="remove">
                                            <button class="account-btn-danger" type="submit"><i class="fa-solid fa-trash-can" aria-hidden="true"></i> Remove photo</button>
                                        </form>
                                        <?php endif; ?>
                                    </div>
                                    <p class="account-hint" id="photoHint">JPG, PNG, or WebP. Maximum 2 MB. The photo is cropped to a square and shown in the admin header.</p>
                                </div>
                            </div>
                        </section>

                        <section class="account-card" aria-labelledby="accountFontHeading">
                            <div class="card-header-flex">
                                <div class="card-header-main">
                                    <div class="card-icon-badge">
                                        <i class="fa-solid fa-text-height" aria-hidden="true"></i>
                                    </div>
                                    <div>
                                        <h2 id="accountFontHeading">Text Size</h2>
                                        <p>Saved on your account and used on the admin pages. Report cards, slips, and other print sheets stay at paper size.</p>
                                    </div>
                                </div>
                            </div>

                            <div class="account-font-layout">
                                <form method="post" action="account.php">
                                    <?= csrfField() ?>
                                    <input type="hidden" name="account_action" value="font">
                                    <div class="account-font" role="group" aria-label="Text size">
                                        <button class="account-step down" type="submit" name="font_step" value="down" aria-label="Decrease text size"<?= $atMin ? ' disabled' : '' ?>><span aria-hidden="true">A−</span></button>
                                        <p class="account-readout" aria-live="polite">
                                            <strong><?= (int)$fontPercent ?>%</strong>
                                            <span><?= sanitize($fontLabel) ?></span>
                                        </p>
                                        <button class="account-step up" type="submit" name="font_step" value="up" aria-label="Increase text size"<?= $atMax ? ' disabled' : '' ?>><span aria-hidden="true">A+</span></button>
                                        <button class="account-btn-quiet" type="submit" name="font_step" value="reset"<?= $fontPercent === 100 ? ' disabled' : '' ?>>Reset to standard</button>
                                    </div>
                                </form>

                                <div class="account-sample">
                                    <img class="account-sample-logo" src="<?= htmlspecialchars($schoolLogoUrl, ENT_QUOTES, 'UTF-8') ?>" width="34" height="34" alt="">
                                    <div>
                                        <p><?= sanitize($schoolName) ?></p>
                                        <p>Results, timetables, admission screens, and the rest of the admin use this text size for your login.</p>
                                    </div>
                                </div>
                            </div>
                        </section>
                    </div>

                    <!-- ════════ WIDE CARDS: PASSWORD & RECOVERY SETTINGS ════════ -->
                    <div class="account-grid">

                        <!-- CARD 3: UPDATE PASSWORD -->
                        <div class="security-card" id="password">
                            <div class="card-header-flex">
                                <div class="card-header-main">
                                    <div class="card-icon-badge">
                                        <i class="fas fa-key"></i>
                                    </div>
                                    <div>
                                        <h2>Account Password</h2>
                                        <p>Update your personal administrator login password.</p>
                                    </div>
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

                            <form method="POST" action="account.php">
                                <input type="hidden" name="csrf_token" value="<?= generateCSRFToken() ?>">
                                <input type="hidden" name="action" value="change_password">

                                <div class="form-group">
                                    <label for="current_password">Current Password</label>
                                    <div class="input-eye-wrap">
                                        <input type="password" name="current_password" id="current_password" class="pwd-input" required autocomplete="current-password" placeholder="Enter current password">
                                        <button type="button" class="toggle-pwd-btn" onclick="toggleVisibility('current_password', this)" title="Show / hide password" tabindex="-1"><i class="fas fa-eye"></i></button>
                                    </div>
                                </div>

                                <div class="form-grid-2">
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
                                </div>

                                <div class="pwd-requirements">
                                    <strong><i class="fas fa-circle-info"></i> Security Policy:</strong>
                                    <ul>
                                        <li>Minimum 8 characters in length.</li>
                                        <li>Must contain at least 1 number or symbol (e.g. <code>@</code>, <code>#</code>, <code>$</code>).</li>
                                        <li>Avoid common words, pet names, or sequential digits.</li>
                                    </ul>
                                </div>

                                <div class="btn-row">
                                    <button type="submit" class="btn-submit-pwd">
                                        <i class="fas fa-lock"></i> Save New Password
                                    </button>
                                </div>
                            </form>
                        </div>

                        <!-- CARD 4: RECOVERY EMAIL & RESET SETTINGS -->
                        <div class="security-card" id="recovery">
                            <div class="card-header-flex">
                                <div class="card-header-main">
                                    <div class="card-icon-badge gold">
                                        <i class="fas fa-envelope-shield"></i>
                                    </div>
                                    <div>
                                        <h2>Password Reset Recovery Email</h2>
                                        <p>One-time reset codes requested from the login screen are sent to this email.</p>
                                    </div>
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

                            <form method="POST" action="account.php" id="recoveryEmailForm">
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

                                <div class="btn-row">
                                    <button type="submit" onclick="document.getElementById('recoveryFormAction').value='update_email';" class="btn-submit-pwd">
                                        <i class="fas fa-floppy-disk"></i> Save Recovery Email
                                    </button>

                                    <button type="submit" onclick="document.getElementById('recoveryFormAction').value='test_email';" class="btn-test-mail" title="Sends a verification email to ensure messages reach your inbox">
                                        <i class="fas fa-paper-plane"></i> Send Test Verification Email
                                    </button>
                                </div>
                            </form>

                            <div class="how-it-works-box">
                                <strong><i class="fas fa-shield-alt"></i> How Account Recovery Works:</strong>
                                <div>When you click <em>"Forgot password?"</em> on the sign-in page, a 6-digit code is sent to this address. It expires in 10 minutes. Enter the code, then choose a new password.</div>
                            </div>

                            <?php if ($isAdmin): ?>
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

                                    <form method="POST" action="account.php">
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

                                    <form method="POST" action="account.php">
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
                            <?php endif; ?>

                        </div>

                    </div>
                </div>
            </div>
        </main>
    </div>

    <script>
    (function () {
        var input = document.getElementById('photo');
        var preview = document.getElementById('accountPhotoPreview');
        var fallback = document.getElementById('accountPhotoFallback');
        if (input && preview) {
            input.addEventListener('change', function () {
                var file = input.files && input.files[0];
                if (!file || !/^image\/(jpeg|png|webp)$/.test(file.type)) return;
                var reader = new FileReader();
                reader.onload = function () {
                    preview.src = String(reader.result || '');
                    preview.hidden = false;
                    if (fallback) fallback.hidden = true;
                };
                reader.readAsDataURL(file);
            });
        }
        var removeForm = document.getElementById('removePhotoForm');
        if (removeForm) {
            removeForm.addEventListener('submit', function (event) {
                if (!window.confirm('Remove your profile photo?')) event.preventDefault();
            });
        }
    })();

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

    <?php if ($isAdmin && ($smtpSuccess || $smtpError || ($action === 'save_smtp'))): ?>
    document.addEventListener('DOMContentLoaded', function() {
        toggleSmtpDrawer();
    });
    <?php endif; ?>

    <?php if ($isAdmin && ($googleSuccess || $googleError || ($action === 'save_google_oauth'))): ?>
    document.addEventListener('DOMContentLoaded', function() {
        toggleGoogleDrawer();
    });
    <?php endif; ?>
    </script>
    <script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= (is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 0) ?>"></script>
</body>
</html>
