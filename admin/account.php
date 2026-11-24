<?php
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/auth_check.php';
require_once __DIR__ . '/../includes/user_account.php';

$adminPage = 'account';
$settings = getAllSettings();
$schoolName = $settings['school_name'] ?? 'Hindustani Covenant Church High School';

function hccAccountRedirect(string $type, string $text): void
{
    $_SESSION['hcc_account_flash'] = ['type' => $type === 'ok' ? 'ok' : 'err', 'text' => $text];
    header('Location: account.php');
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $postedLen = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($postedLen > 0 && empty($_POST) && empty($_FILES)) {
        hccAccountRedirect('err', 'That photo is too large. Use a JPG, PNG, or WebP under 2 MB.');
    }
    if (!verifyCSRFToken()) {
        hccAccountRedirect('err', 'Security check failed. Refresh the page and try again.');
    }

    $userId = (int)($_SESSION['admin_id'] ?? 0);
    $action = (string)($_POST['account_action'] ?? '');
    $acctNow = hccCurrentAccount();

    if ($action === 'font') {
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
        $err = hccSaveFontPercent($userId, $next);
        if ($err !== '') {
            hccAccountRedirect('err', $err);
        }
        $label = hccFontPercentLabel($next);
        hccAccountRedirect('ok', 'Text size is now ' . $next . '% (' . $label . '). It applies on every admin page for your account.');
    }

    if ($action === 'photo') {
        if ((string)($_POST['photo_step'] ?? '') === 'remove') {
            $err = hccRemoveProfilePhoto($userId);
            hccAccountRedirect($err === '' ? 'ok' : 'err', $err === '' ? 'Profile photo removed.' : $err);
        }
        $err = hccSaveUploadedProfilePhoto($userId, $_FILES['photo'] ?? []);
        hccAccountRedirect($err === '' ? 'ok' : 'err', $err === '' ? 'Profile photo saved. It now shows on your account and in the admin header.' : $err);
    }

    hccAccountRedirect('err', 'That account action is not available.');
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
$email = trim((string)$acct['email']);
$emailIsLocal = ($email === '' || str_ends_with(strtolower($email), '.local'));
$signInLabel = ($acct['auth_provider'] ?? '') === 'google' ? 'Google' : 'Password';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php require __DIR__ . '/includes/account_head.php'; ?>
    <title>My account | <?= sanitize($schoolName) ?></title>
    <link rel="icon" href="<?= BASE_URL ?>assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="<?= htmlspecialchars(generateCSRFToken(), ENT_QUOTES, 'UTF-8') ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/css/admin.css?v=<?= (is_file(__DIR__ . '/../assets/css/admin.css') ? filemtime(__DIR__ . '/../assets/css/admin.css') : 0) ?>">
    <style>
        .account-wrap { max-width: 920px; margin: 0 auto; display: grid; gap: 20px; }
        .account-banner {
            display: flex; align-items: flex-start; gap: 10px;
            padding: 12px 14px; border-radius: 10px; font-size: 0.92rem; font-weight: 600; line-height: 1.45;
        }
        .account-banner.ok { background: #f0fdf4; color: #166534; border: 1px solid #bbf7d0; }
        .account-banner.err { background: #fef2f2; color: #991b1b; border: 1px solid #fecaca; }
        .account-card {
            background: var(--paper); border: 1px solid var(--border); border-radius: 16px;
            box-shadow: var(--shadow-sm); padding: 24px; position: relative; overflow: hidden;
        }
        .account-card::before {
            content: ""; position: absolute; top: 0; left: 0; right: 0; height: 4px;
            background: linear-gradient(90deg, var(--brand), var(--gold));
        }
        .account-card h2 { margin: 0; font-size: 1.15rem; font-weight: 700; color: var(--ink); letter-spacing: -0.01em; }
        .account-card .lead { margin: 4px 0 0; color: var(--text-2); font-size: 0.86rem; line-height: 1.45; }
        .account-profile { display: grid; grid-template-columns: 112px 1fr; gap: 22px; align-items: start; margin-top: 20px; }
        .account-photo {
            width: 112px; height: 112px; border-radius: 50%;
            display: grid; place-items: center; overflow: hidden;
            background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
            color: #fff; font-size: 1.7rem; font-weight: 700; letter-spacing: 0.03em;
            box-shadow: inset 0 0 0 2px rgba(201,162,39,0.55);
        }
        .account-photo img { width: 100%; height: 100%; object-fit: cover; display: block; }
        .account-id { display: grid; grid-template-columns: 140px 1fr; gap: 8px 16px; margin: 0; }
        .account-id dt { margin: 0; color: var(--text-3); font-size: 0.75rem; font-weight: 700; letter-spacing: 0.06em; text-transform: uppercase; padding-top: 3px; }
        .account-id dd { margin: 0; color: var(--ink); font-size: 0.95rem; font-weight: 600; min-width: 0; overflow-wrap: anywhere; }
        .account-actions { display: flex; flex-wrap: wrap; gap: 10px; align-items: center; margin-top: 18px; }
        .account-file {
            flex: 1 1 240px; min-width: 0; font: inherit; font-size: 0.88rem; color: var(--text-2);
        }
        .account-btn, .account-btn-quiet, .account-btn-danger {
            display: inline-flex; align-items: center; justify-content: center; gap: 8px;
            min-height: 44px; padding: 0 16px; border-radius: 9px; font: inherit; font-size: 0.9rem; font-weight: 700;
            cursor: pointer; text-decoration: none;
        }
        .account-btn {
            border: none; color: #fff;
            background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
            box-shadow: 0 4px 14px rgba(122, 34, 56, 0.22);
        }
        .account-btn:hover { color: #fff; }
        .account-btn:disabled, .account-step:disabled, .account-btn-quiet:disabled {
            opacity: 0.45; cursor: not-allowed; box-shadow: none;
        }
        .account-btn-quiet, .account-btn-danger {
            background: #fff; border: 1.5px solid var(--border); color: var(--ink);
        }
        .account-btn-quiet:hover { border-color: var(--brand); color: var(--brand); }
        .account-btn-danger { color: #991b1b; border-color: #fecaca; background: #fff; }
        .account-btn-danger:hover { background: #fef2f2; }
        .account-hint { margin: 10px 0 0; color: var(--text-3); font-size: 0.8rem; line-height: 1.45; }
        .account-font {
            display: flex; flex-wrap: wrap; align-items: center; gap: 12px; margin-top: 18px;
        }
        .account-step {
            width: 52px; height: 52px; border-radius: 12px; border: 1.5px solid var(--border);
            background: #fff; color: var(--brand); font: inherit; font-weight: 800; cursor: pointer;
        }
        .account-step:hover:not(:disabled) { border-color: var(--brand); background: var(--brand-soft); }
        .account-step.down { font-size: 0.95rem; }
        .account-step.up { font-size: 1.25rem; }
        .account-readout { min-width: 120px; text-align: center; }
        .account-readout strong { display: block; font-size: 1.45rem; color: var(--ink); letter-spacing: -0.03em; line-height: 1.1; }
        .account-readout span { color: var(--gold-ink); font-size: 0.75rem; font-weight: 700; letter-spacing: 0.08em; text-transform: uppercase; }
        .account-sample {
            margin-top: 18px; padding: 14px 16px; border-radius: 12px;
            background: var(--surface); border: 1px solid var(--border);
        }
        .account-sample p { margin: 0; color: var(--text); }
        .account-sample p + p { margin-top: 6px; color: var(--text-2); font-size: 0.92rem; }
        .account-links { margin-top: 14px; font-size: 0.88rem; }
        .account-links a { color: var(--brand); font-weight: 700; }
        @media (max-width: 720px) {
            .account-card { padding: 18px; }
            .account-profile, .account-id { grid-template-columns: 1fr; }
            .account-photo { width: 88px; height: 88px; }
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
                    <h1 class="page-title">My account</h1>
                    <p class="page-sub">Your profile photo and text size for <?= sanitize($schoolName) ?>.</p>
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

                    <section class="account-card" aria-labelledby="accountProfileHeading">
                        <h2 id="accountProfileHeading">Profile</h2>
                        <p class="lead">This login keeps its own photo. Other staff do not share it.</p>
                        <div class="account-profile">
                            <div class="account-photo" id="accountPhotoFrame">
                                <?php if ($acct['has_photo']): ?>
                                <img id="accountPhotoPreview" src="<?= htmlspecialchars($acct['photo_url'], ENT_QUOTES, 'UTF-8') ?>" alt="">
                                <span id="accountPhotoFallback" hidden><?= sanitize($acct['initials']) ?></span>
                                <?php else: ?>
                                <img id="accountPhotoPreview" alt="" hidden>
                                <span id="accountPhotoFallback"><?= sanitize($acct['initials']) ?></span>
                                <?php endif; ?>
                            </div>
                            <dl class="account-id">
                                <dt>Name</dt>
                                <dd><?= sanitize($acct['full_name']) ?></dd>
                                <dt>Username</dt>
                                <dd><?= sanitize($acct['username'] !== '' ? $acct['username'] : '—') ?></dd>
                                <dt>Role</dt>
                                <dd><?= sanitize(ucfirst((string)$acct['role'])) ?></dd>
                                <dt>Email</dt>
                                <dd><?= $emailIsLocal ? 'Not set' : sanitize($email) ?></dd>
                                <dt>Sign-in</dt>
                                <dd><?= sanitize($signInLabel) ?></dd>
                            </dl>
                        </div>

                        <form method="post" action="account.php" enctype="multipart/form-data">
                            <?= csrfField() ?>
                            <input type="hidden" name="account_action" value="photo">
                            <input type="hidden" name="MAX_FILE_SIZE" value="2097152">
                            <div class="account-actions">
                                <input class="account-file" id="photo" name="photo" type="file" accept="image/jpeg,image/png,image/webp,.jpg,.jpeg,.png,.webp" required aria-describedby="photoHint">
                                <button class="account-btn" type="submit">Save photo</button>
                            </div>
                            <p class="account-hint" id="photoHint">JPG, PNG, or WebP. Maximum 2 MB. The photo is cropped to a square.</p>
                        </form>
                        <?php if ($acct['has_photo']): ?>
                        <form method="post" action="account.php" id="removePhotoForm" class="account-actions">
                            <?= csrfField() ?>
                            <input type="hidden" name="account_action" value="photo">
                            <input type="hidden" name="photo_step" value="remove">
                            <button class="account-btn-danger" type="submit">Remove photo</button>
                        </form>
                        <?php endif; ?>
                        <p class="account-links"><a href="change_password.php">Password and recovery email</a> stay on Security.</p>
                    </section>

                    <section class="account-card" aria-labelledby="accountFontHeading">
                        <h2 id="accountFontHeading">Text size</h2>
                        <p class="lead">Saved on your account and used on the admin pages. Report cards, slips, and other print sheets stay at paper size.</p>
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
                            <p><?= sanitize($schoolName) ?></p>
                            <p>Results, timetables, admission screens, and the rest of the admin use this text size for your login.</p>
                        </div>
                    </section>
                </div>
            </div>
        </main>
    </div>
    <script src="<?= BASE_URL ?>assets/js/admin.js?v=<?= (is_file(__DIR__ . '/../assets/js/admin.js') ? filemtime(__DIR__ . '/../assets/js/admin.js') : 0) ?>"></script>
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
    </script>
</body>
</html>
