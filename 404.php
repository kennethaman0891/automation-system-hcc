<?php
// HCC — 404 (Not Found) page
// Serves a branded, accessible 404 page. Does NOT require auth — it's an
// unauthenticated error page, like login.php. Sends its own security headers.
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}

// Send a real 404 signal so crawlers / monitoring know the page is missing.
http_response_code(404);

require_once __DIR__ . '/includes/functions.php';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="description" content="Page not found — Hindustani Covenant Church High School">
    <meta name="robots" content="noindex, nofollow">
    <title>404 — Page Not Found | Hindustani Covenant Church High School</title>
    <link rel="icon" href="<?= BASE_URL ?>assets/images/hcclogo.jpg">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/bootstrap@5.3.3/css/bootstrap.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <style>
        :root {
            --brand: #7a2238;
            --brand-dark: #5a1a2a;
            --gold: #c9a227;
            --gold-soft: #f0d98c;
            --ink: #2a1018;
            --text-2: #5a4a4f;
            --text-3: #96878c;
            --surface: #f6f4f3;
            --paper: #ffffff;
            --shadow: 0 4px 14px rgba(42, 16, 24, 0.08);
            --shadow-lg: 0 16px 40px rgba(42, 16, 24, 0.14);
            --radius: 12px;
        }
        * { margin: 0; padding: 0; box-sizing: border-box; }
        html, body { height: 100%; }
        body {
            font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, sans-serif;
            background: var(--surface);
            color: var(--ink);
            -webkit-font-smoothing: antialiased;
        }
        img { max-width: 100%; display: block; }
        .not-found-layout {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 2rem 1rem;
        }
        .not-found-card {
            max-width: 520px;
            width: 100%;
            background: var(--paper);
            border-radius: var(--radius);
            box-shadow: var(--shadow-lg);
            overflow: hidden;
            position: relative;
        }
        .not-found-card::before {
            content: "";
            position: absolute;
            top: -120px;
            right: -120px;
            width: 320px;
            height: 320px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(122, 34, 56, 0.08), rgba(122, 34, 56, 0) 70%);
            pointer-events: none;
            z-index: 0;
        }
        .not-found-card::after {
            content: "";
            position: absolute;
            bottom: -100px;
            left: -100px;
            width: 280px;
            height: 280px;
            border-radius: 50%;
            background: radial-gradient(circle, rgba(201, 162, 39, 0.10), rgba(201, 162, 39, 0) 70%);
            pointer-events: none;
            z-index: 0;
        }
        .not-found-body {
            position: relative;
            z-index: 1;
            padding: 3rem 2rem;
            text-align: center;
        }
        .error-code {
            font-size: 4rem;
            font-weight: 800;
            line-height: 1;
            background: linear-gradient(92deg, var(--gold), var(--gold-soft));
            -webkit-background-clip: text;
            background-clip: text;
            -webkit-text-fill-color: transparent;
            color: transparent;
            margin-bottom: 1rem;
        }
        .error-title {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--brand-dark);
            margin-bottom: .75rem;
        }
        .error-text {
            color: var(--text-2);
            font-size: .95rem;
            line-height: 1.6;
            max-width: 38ch;
            margin: 0 auto 2rem;
        }
        .btn-back {
            display: inline-flex;
            align-items: center;
            gap: .5rem;
            padding: .7rem 1.5rem;
            font-weight: 600;
            font-size: .9rem;
            border: none;
            border-radius: 8px;
            background: var(--brand);
            color: #fff;
            text-decoration: none;
            transition: background-color .15s ease, transform .1s ease;
        }
        .btn-back:hover {
            background: var(--brand-dark);
        }
        .btn-back:active {
            transform: scale(.98);
        }
        .school-logo {
            width: 52px;
            height: 52px;
            object-fit: cover;
            border-radius: 12px;
            border: 2px solid rgba(122, 34, 56, .4);
            box-shadow: 0 6px 16px rgba(42, 16, 24, .12);
            margin: 0 auto 1.5rem;
        }
        .footer-text {
            margin-top: 2rem;
            font-size: .8rem;
            color: var(--text-3);
        }
    </style>
</head>
<body>
    <div class="not-found-layout">
        <div class="not-found-card">
            <div class="not-found-body">
                <img class="school-logo" src="<?= BASE_URL ?>assets/images/hcclogo.jpg" alt="HCC logo">
                <div class="error-code">404</div>
                <h1 class="error-title">Page Not Found</h1>
                <p class="error-text">The page you're looking for doesn't exist or may have been moved. Please check the URL or return to the dashboard.</p>
                <a href="<?= BASE_URL ?>admin/login.php" class="btn-back">
                    <i class="fa-solid fa-arrow-left"></i> Go to Login
                </a>
                <p class="footer-text">&copy; <?= date('Y') ?> Hindustani Covenant Church High School, Solapur.</p>
            </div>
        </div>
    </div>
</body>
</html>
