<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/functions.php';

// Security headers — must run before ANY output
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}

// If user is already logged out, send straight to login
$isLoggedIn = !empty($_SESSION['admin_id']) || !empty($_SESSION['admin_logged_in']);
if (!$isLoggedIn) {
    header('Location: login.php');
    exit;
}

$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
$isConfirmed = false;

if ($method === 'POST') {
    $sent = $_POST['csrf_token'] ?? '';
    $sess = $_SESSION['csrf_token'] ?? '';
    if ($sent !== '' || $sess !== '') {
        if ($sent === '' || $sess === '' || !hash_equals((string)$sess, (string)$sent)) {
            http_response_code(403);
            exit('Invalid logout request (CSRF mismatch).');
        }
    }
    if (!empty($_POST['confirm']) || ($_POST['action'] ?? '') === 'logout') {
        $isConfirmed = true;
    }
} elseif ($method === 'GET' && !empty($_GET['confirm'])) {
    $sentGet = $_GET['csrf_token'] ?? '';
    $sessGet = $_SESSION['csrf_token'] ?? '';
    if ($sentGet !== '' || $sessGet !== '') {
        if ($sentGet === '' || $sessGet === '' || !hash_equals((string)$sessGet, (string)$sentGet)) {
            http_response_code(403);
            exit('Invalid logout request (CSRF mismatch).');
        }
    }
    $isConfirmed = true;
}

// If confirmed, proceed with full destruction of session and cookies
if ($isConfirmed) {
    $_SESSION = [];
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        if (PHP_VERSION_ID >= 70300) {
            setcookie(session_name(), '', [
                'expires' => time() - 42000,
                'path' => $params["path"] ?: '/',
                'domain' => $params["domain"],
                'secure' => $params["secure"],
                'httponly' => $params["httponly"],
                'samesite' => 'Strict',
            ]);
        } else {
            // PHP < 7.3 fallback: append samesite to path (no array options support).
            setcookie(session_name(), '', time() - 42000,
                ($params["path"] ?: '/') . '; samesite=Strict',
                $params["domain"],
                $params["secure"],
                $params["httponly"]
            );
        }
        unset($_COOKIE[session_name()]);
    }
    session_destroy();
    // Rotate ID after destroy on a fresh session (prevents fixation on shared browsers).
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_start();
        session_regenerate_id(true);
    }
    $_SESSION = [];
    header('Location: login.php?notice=logged_out');
    exit;
}

// User details for confirmation popup
$adminName = trim($_SESSION['admin_name'] ?? 'Administrator');
$adminRole = ucfirst($_SESSION['admin_role'] ?? 'admin');
$adminUsername = $_SESSION['admin_username'] ?? '';
$initial = mb_strtoupper(mb_substr($adminName !== '' ? $adminName : 'A', 0, 1));
$csrfToken = generateCSRFToken();
$schoolName = 'Hindustani Covenant Church High School';

// Safe return URL calculation
$returnUrl = 'dashboard.php';
$referer = $_SERVER['HTTP_REFERER'] ?? '';
if (!empty($referer)) {
    $refParts = parse_url($referer);
    $currentHost = $_SERVER['HTTP_HOST'] ?? '';
    if (empty($refParts['host']) || $refParts['host'] === $currentHost) {
        $refPath = $refParts['path'] ?? '';
        if (strpos($refPath, 'logout.php') === false && strpos($refPath, 'login.php') === false) {
            $returnUrl = htmlspecialchars($referer, ENT_QUOTES, 'UTF-8');
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Logout | <?= htmlspecialchars($schoolName, ENT_QUOTES, 'UTF-8') ?></title>
    <link rel="icon" href="<?= BASE_URL ?>assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <style>
        :root {
            --brand: #7a2238;
            --brand-dark: #5a1a2a;
            --brand-deep: #3d0f1c;
            --gold: #c9a227;
            --gold-light: #e0be53;
            --danger: #dc2626;
            --danger-dark: #b91c1c;
        }
        *, *::before, *::after {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }
        body {
            font-family: 'Inter', -apple-system, BlinkMacSystemFont, system-ui, sans-serif;
            background: radial-gradient(circle at 50% 25%, #2a1420 0%, #170d13 50%, #0d070b 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            color: #1f2937;
            overflow: hidden;
            position: relative;
        }
        /* Background ambient glowing orbs */
        .ambient-orb {
            position: absolute;
            border-radius: 50%;
            filter: blur(80px);
            pointer-events: none;
            opacity: 0.45;
        }
        .orb-1 {
            width: 380px;
            height: 380px;
            background: #7a2238;
            top: 10%;
            left: 20%;
        }
        .orb-2 {
            width: 320px;
            height: 320px;
            background: #c9a227;
            bottom: 15%;
            right: 20%;
            opacity: 0.25;
        }
        /* Modal Backdrop */
        .logout-modal-backdrop {
            position: fixed;
            inset: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
            background: rgba(10, 5, 8, 0.72);
            backdrop-filter: blur(12px);
            -webkit-backdrop-filter: blur(12px);
            z-index: 100;
        }
        /* Modal Card */
        .logout-modal-card {
            background: #ffffff;
            width: 100%;
            max-width: 440px;
            border-radius: 20px;
            box-shadow: 0 25px 60px -15px rgba(0, 0, 0, 0.5), 0 0 0 1px rgba(255, 255, 255, 0.15);
            position: relative;
            overflow: hidden;
            animation: logoutModalPop 0.32s cubic-bezier(0.16, 1, 0.3, 1) forwards;
        }
        .logout-modal-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--brand) 0%, var(--danger) 50%, var(--gold) 100%);
        }
        /* Close button */
        .logout-modal-close {
            position: absolute;
            top: 16px;
            right: 16px;
            width: 34px;
            height: 34px;
            border-radius: 50%;
            background: #f3f4f6;
            border: none;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            color: #6b7280;
            font-size: 18px;
            line-height: 1;
            transition: all 0.2s ease;
            text-decoration: none;
        }
        .logout-modal-close:hover {
            background: #fee2e2;
            color: #dc2626;
            transform: rotate(90deg);
        }
        /* Modal Body */
        .logout-modal-body {
            padding: 34px 28px 26px;
            text-align: center;
        }
        /* Icon Wrap & Pulse */
        .logout-modal-icon-wrap {
            width: 76px;
            height: 76px;
            margin: 0 auto 20px;
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .logout-modal-icon {
            width: 66px;
            height: 66px;
            border-radius: 50%;
            background: linear-gradient(135deg, #fee2e2 0%, #fecaca 100%);
            color: #dc2626;
            font-size: 26px;
            display: flex;
            align-items: center;
            justify-content: center;
            box-shadow: 0 6px 18px rgba(220, 38, 38, 0.22);
            position: relative;
            z-index: 2;
        }
        .logout-modal-icon-wrap::after {
            content: '';
            position: absolute;
            inset: -4px;
            border-radius: 50%;
            background: rgba(220, 38, 38, 0.18);
            animation: logoutPulse 2.2s infinite ease-out;
            z-index: 1;
        }
        @keyframes logoutPulse {
            0% { transform: scale(0.92); opacity: 0.8; }
            50% { transform: scale(1.18); opacity: 0.2; }
            100% { transform: scale(0.92); opacity: 0.8; }
        }
        @keyframes logoutModalPop {
            from {
                opacity: 0;
                transform: scale(0.9) translateY(14px);
            }
            to {
                opacity: 1;
                transform: scale(1) translateY(0);
            }
        }
        /* Typography */
        .logout-modal-title {
            font-size: 1.38rem;
            font-weight: 700;
            color: #111827;
            margin-bottom: 8px;
            letter-spacing: -0.01em;
        }
        .logout-modal-desc {
            font-size: 0.94rem;
            color: #4b5563;
            line-height: 1.55;
            margin-bottom: 22px;
        }
        /* User Badge */
        .logout-modal-user {
            display: flex;
            align-items: center;
            gap: 12px;
            background: #f9fafb;
            border: 1px solid #e5e7eb;
            border-radius: 12px;
            padding: 11px 15px;
            margin-bottom: 24px;
            text-align: left;
        }
        .logout-modal-avatar {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            background: linear-gradient(135deg, var(--brand) 0%, var(--brand-dark) 100%);
            color: #ffffff;
            font-weight: 700;
            font-size: 15px;
            display: flex;
            align-items: center;
            justify-content: center;
            border: 2px solid var(--gold-light);
            flex-shrink: 0;
        }
        .logout-modal-user-info {
            min-width: 0;
            flex: 1;
        }
        .logout-modal-user-info strong {
            display: block;
            font-size: 0.92rem;
            font-weight: 600;
            color: #1f2937;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }
        .logout-modal-user-info span {
            display: inline-block;
            font-size: 0.72rem;
            font-weight: 600;
            background: #f1f5f9;
            color: #475569;
            padding: 2px 7px;
            border-radius: 5px;
            margin-top: 2px;
            text-transform: uppercase;
            letter-spacing: 0.03em;
        }
        /* Buttons */
        .logout-modal-actions {
            display: flex;
            gap: 12px;
            align-items: center;
        }
        .logout-btn-cancel {
            flex: 1;
            padding: 12px 18px;
            border-radius: 10px;
            background: #f3f4f6;
            color: #374151;
            font-weight: 600;
            font-size: 0.94rem;
            text-decoration: none;
            text-align: center;
            border: 1px solid #d1d5db;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-family: inherit;
        }
        .logout-btn-cancel:hover, .logout-btn-cancel:focus-visible {
            background: #e5e7eb;
            color: #111827;
            border-color: #9ca3af;
        }
        .logout-btn-confirm {
            flex: 1;
            padding: 12px 18px;
            border-radius: 10px;
            background: linear-gradient(135deg, var(--danger) 0%, var(--danger-dark) 100%);
            color: #ffffff;
            font-weight: 600;
            font-size: 0.94rem;
            border: 1px solid transparent;
            cursor: pointer;
            transition: all 0.2s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            box-shadow: 0 4px 14px rgba(220, 38, 38, 0.35);
            font-family: inherit;
        }
        .logout-btn-confirm:hover, .logout-btn-confirm:focus-visible {
            background: linear-gradient(135deg, var(--danger-dark) 0%, #991b1b 100%);
            box-shadow: 0 6px 18px rgba(220, 38, 38, 0.45);
            transform: translateY(-1px);
        }
        .logout-btn-confirm:active {
            transform: translateY(0);
        }
        @media (max-width: 480px) {
            .logout-modal-card {
                border-radius: 16px;
            }
            .logout-modal-body {
                padding: 26px 20px 22px;
            }
            .logout-modal-actions {
                flex-direction: column-reverse;
            }
            .logout-btn-cancel, .logout-btn-confirm {
                width: 100%;
            }
        }
    </style>
</head>
<body>
    <div class="ambient-orb orb-1"></div>
    <div class="ambient-orb orb-2"></div>

    <div class="logout-modal-backdrop" id="logoutModalBackdrop" role="dialog" aria-modal="true" aria-labelledby="logoutTitle">
        <div class="logout-modal-card">
            <a href="<?= $returnUrl ?>" class="logout-modal-close" id="logoutModalClose" aria-label="Cancel and return" title="Cancel">&times;</a>
            <div class="logout-modal-body">
                <div class="logout-modal-icon-wrap">
                    <div class="logout-modal-icon">
                        <i class="fa-solid fa-arrow-right-from-bracket"></i>
                    </div>
                </div>

                <h1 class="logout-modal-title" id="logoutTitle">Confirm Logout</h1>
                <p class="logout-modal-desc">
                    Are you sure you want to end your session? You will be signed out of the HCC Admin Portal.
                </p>

                <div class="logout-modal-user">
                    <div class="logout-modal-avatar">
                        <?= htmlspecialchars($initial, ENT_QUOTES, 'UTF-8') ?>
                    </div>
                    <div class="logout-modal-user-info">
                        <strong><?= htmlspecialchars($adminName, ENT_QUOTES, 'UTF-8') ?></strong>
                        <span><?= htmlspecialchars($adminRole, ENT_QUOTES, 'UTF-8') ?></span>
                    </div>
                </div>

                <form method="POST" action="logout.php">
                    <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="hidden" name="confirm" value="1">
                    <div class="logout-modal-actions">
                        <a href="<?= $returnUrl ?>" class="logout-btn-cancel" id="cancelLogoutBtn">
                            <i class="fa-solid fa-xmark"></i>
                            <span>Cancel</span>
                        </a>
                        <button type="submit" class="logout-btn-confirm" id="confirmLogoutBtn">
                            <i class="fa-solid fa-arrow-right-from-bracket"></i>
                            <span>Yes, Log Out</span>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <script>
        document.addEventListener('DOMContentLoaded', function () {
            var cancelBtn = document.getElementById('cancelLogoutBtn');
            if (cancelBtn) {
                cancelBtn.focus();
            }
            document.addEventListener('keydown', function (e) {
                if (e.key === 'Escape') {
                    window.location.href = <?= json_encode($returnUrl) ?>;
                }
            });
            var backdrop = document.getElementById('logoutModalBackdrop');
            if (backdrop) {
                backdrop.addEventListener('click', function (e) {
                    if (e.target === backdrop) {
                        window.location.href = <?= json_encode($returnUrl) ?>;
                    }
                });
            }
        });
    </script>
</body>
</html>
