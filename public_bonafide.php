<?php
/**
 * Public Parent & Verification Portal — Official Bonafide Certificate
 * Hindustani Covenant Church High School & Junior College
 */
if (!headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; font-src 'self'; img-src 'self' data:; connect-src 'self'; frame-ancestors 'self'; base-uri 'self'; form-action 'self'");
}
require_once __DIR__ . '/includes/functions.php';

$grNo       = trim($_GET['gr'] ?? '');
$certSerial = trim($_GET['sr'] ?? '');
$token      = trim($_GET['token'] ?? '');

if ($grNo === '' || $certSerial === '' || $token === '') {
    http_response_code(400);
    die('Invalid certificate link. Required parameters are missing.');
}

// ── Verify tamper-proof HMAC signature ──
if (!rcVerifyParentToken('bonafide', [$grNo, $certSerial], $token)) {
    http_response_code(403);
    die('Access denied: Invalid or expired security token. Please request a fresh certificate link from the school administration.');
}

// ── Retrieve Certificate Record from Archive Vault ──
$db = getDB();
rcEnsureArchiveTables();
$stmt = $db->prepare("SELECT * FROM bonafide_issued_log WHERE gr_no = ? AND cert_serial = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$grNo, $certSerial]);
$cert = $stmt->fetch(PDO::FETCH_ASSOC);

$settings = getAllSettings();
$schoolName = $settings['school_name'] ?? 'HINDUSTANI COVENANT CHURCH HIGH SCHOOL & JUNIOR COLLEGE';
if (trim($schoolName) === 'Hindustani Covenant Church High School') {
    $schoolName = 'HINDUSTANI COVENANT CHURCH HIGH SCHOOL & JUNIOR COLLEGE';
}
$schoolTagline = $settings['school_tagline'] ?? '';
$schoolLogo    = $settings['logo'] ?? 'assets/images/hcclogo.jpg';
$contactAddr   = $settings['contact_address'] ?? 'Solapur - 413002';

if (!$cert) {
    // Fallback: If not logged yet, verify against default settings
    $studentName = 'Student';
    $std = '';
    $division = '';
    $ay = '2024-25';
    $dob = '';
    $dobWords = '';
    $purpose = 'Educational Purpose';
    $issuedDate = date('d-m-Y');
} else {
    $studentName = $cert['student_name'];
    $std         = $cert['std'] ?? '';
    $division    = $cert['division'] ?? '';
    $ay          = $cert['academic_year'] ?? '2024-25';
    $dob         = !empty($cert['date_of_birth']) ? date('d-m-Y', strtotime($cert['date_of_birth'])) : '';
    $dobWords    = dobToWords($cert['date_of_birth'] ?? '');
    $purpose     = $cert['purpose'] ?? 'Educational Purpose';
    $issuedDate  = !empty($cert['issued_date']) ? date('d-m-Y', strtotime($cert['issued_date'])) : date('d-m-Y');
}

$romanMap = ['1'=>'I','2'=>'II','3'=>'III','4'=>'IV','5'=>'V','6'=>'VI','7'=>'VII','8'=>'VIII','9'=>'IX','10'=>'X'];
$romanStd = $romanMap[(string)$std] ?? $std;
$classFormatted = trim($romanStd . ($division ? ' ' . $division : ''));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Bonafide Certificate — <?= sanitize($studentName) ?> (Sr No: <?= sanitize($certSerial) ?>)</title>
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/font-awesome@6.5.0/css/all.min.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/inter-fonts.css">
    <link rel="stylesheet" href="<?= BASE_URL ?>assets/vendor/merriweather/merriweather.css">
    <style>
        :root {
            --bf-brand: #1a237e;
            --bf-brand-2: #283593;
            --bf-gold: #c9943e;
            --bf-border: #cbd5e1;
            --bf-bg: #f8fafc;
        }
        * { box-sizing: border-box; }
        body {
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            background: var(--bf-bg);
            color: #0f172a;
            margin: 0;
            padding: 16px;
        }
        .portal-bar {
            max-width: 820px;
            margin: 0 auto 16px;
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 12px;
            flex-wrap: wrap;
        }
        .verified-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            font-size: 0.82rem;
            font-weight: 700;
            color: #166534;
            background: #f0fdf4;
            padding: 6px 14px;
            border-radius: 999px;
            border: 1px solid #bbf7d0;
        }
        .verified-badge i { color: #16a34a; font-size: 0.95rem; }
        .action-btns {
            display: flex;
            gap: 8px;
        }
        .btn-print {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 8px 16px;
            font-size: 0.85rem;
            font-weight: 700;
            color: #fff;
            background: var(--bf-brand);
            border: none;
            border-radius: 8px;
            cursor: pointer;
            text-decoration: none;
            box-shadow: 0 4px 12px rgba(26,35,126,0.18);
        }
        .btn-print:hover { background: var(--bf-brand-2); }

        /* Certificate Sheet */
        .cert-container {
            max-width: 820px;
            margin: 0 auto;
            background: #fff;
            border: 2.5px solid var(--bf-brand);
            border-radius: 4px;
            padding: 36px 40px 32px;
            position: relative;
            box-shadow: 0 8px 24px rgba(0,0,0,0.06);
            font-family: 'Merriweather', Georgia, serif;
        }
        .cert-container::before {
            content: '';
            position: absolute;
            top: 0; left: 0; right: 0;
            height: 5px;
            background: linear-gradient(90deg, var(--bf-brand), var(--bf-gold), var(--bf-brand));
        }
        .cert-top {
            display: flex;
            align-items: center;
            gap: 16px;
            border-bottom: 2px solid var(--bf-brand);
            padding-bottom: 14px;
            margin-bottom: 18px;
        }
        .cert-top img {
            width: 70px;
            height: 70px;
            object-fit: contain;
            flex-shrink: 0;
        }
        .cert-top-text {
            flex: 1;
        }
        .cert-top-text h1 {
            font-size: 1.15rem;
            font-weight: 900;
            color: var(--bf-brand);
            margin: 0;
            line-height: 1.3;
            letter-spacing: 0.01em;
            text-transform: uppercase;
        }
        .cert-top-text p {
            font-family: 'Inter', sans-serif;
            font-size: 0.78rem;
            color: #475569;
            margin: 4px 0 0;
            font-weight: 600;
        }
        .cert-meta-row {
            display: flex;
            justify-content: space-between;
            font-family: 'Inter', sans-serif;
            font-size: 0.85rem;
            font-weight: 700;
            color: #334155;
            margin-bottom: 20px;
        }
        .cert-meta-row span strong {
            color: var(--bf-brand);
        }
        .cert-title-block {
            text-align: center;
            margin: 18px 0 28px;
        }
        .cert-title-block h2 {
            font-size: 1.35rem;
            font-weight: 900;
            color: #b91c1c;
            letter-spacing: 0.25em;
            text-transform: uppercase;
            margin: 0;
        }
        .cert-title-block .line {
            width: 80px;
            height: 3px;
            background: var(--bf-gold);
            margin: 8px auto 0;
            border-radius: 2px;
        }
        .cert-content {
            font-size: 1.02rem;
            line-height: 2.2;
            color: #1e293b;
            text-align: justify;
            margin-bottom: 36px;
        }
        .cert-fill {
            font-weight: 800;
            color: #0f172a;
            text-decoration: underline;
            text-underline-offset: 4px;
            text-decoration-thickness: 1.5px;
        }
        .cert-photo-stamp {
            float: right;
            width: 120px;
            height: 145px;
            border: 1.5px dashed #94a3b8;
            border-radius: 4px;
            margin: 0 0 16px 24px;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            background: #f8fafc;
            font-family: 'Inter', sans-serif;
            font-size: 0.7rem;
            font-weight: 700;
            color: #94a3b8;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .cert-photo-stamp i { font-size: 1.4rem; margin-bottom: 6px; opacity: 0.7; }
        .cert-footer {
            display: flex;
            justify-content: space-between;
            align-items: flex-end;
            margin-top: 40px;
            padding-top: 20px;
            font-family: 'Inter', sans-serif;
        }
        .cert-date-col {
            font-size: 0.9rem;
            font-weight: 700;
            color: #334155;
        }
        .cert-sig-col {
            text-align: center;
            min-width: 200px;
        }
        .sig-space {
            height: 48px;
            border-bottom: 1.5px solid var(--bf-brand);
            margin-bottom: 8px;
        }
        .sig-label {
            font-size: 0.88rem;
            font-weight: 800;
            color: var(--bf-brand);
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }
        .cert-verification-seal {
            margin-top: 32px;
            padding-top: 14px;
            border-top: 1px solid #e2e8f0;
            display: flex;
            align-items: center;
            justify-content: space-between;
            font-family: 'Inter', sans-serif;
            font-size: 0.72rem;
            color: #64748b;
        }
        .cert-verification-seal strong { color: var(--bf-brand); }

        @media print {
            body { background: #fff; padding: 0; }
            .portal-bar { display: none; }
            .cert-container { box-shadow: none; border-color: #000; padding: 25px 30px; }
            .cert-top-text h1 { color: #000; }
            .cert-title-block h2 { color: #000; }
            .sig-label { color: #000; }
        }
    </style>
</head>
<body>

    <div class="portal-bar">
        <div class="verified-badge">
            <i class="fa-solid fa-circle-check"></i>
            <span>Official Digitally Signed Bonafide Certificate</span>
        </div>
        <div class="action-btns">
            <button class="btn-print" onclick="window.print()">
                <i class="fa-solid fa-print"></i> Print / Save as PDF
            </button>
        </div>
    </div>

    <div class="cert-container">
        <div class="cert-top">
            <img src="<?= BASE_URL . sanitize($schoolLogo) ?>" alt="School Logo">
            <div class="cert-top-text">
                <h1><?= sanitize($schoolName) ?></h1>
                <?php if ($schoolTagline): ?>
                    <p style="font-style: italic;"><?= sanitize($schoolTagline) ?></p>
                <?php endif; ?>
                <p><?= sanitize($contactAddr) ?></p>
            </div>
        </div>

        <div class="cert-meta-row">
            <span>Sr. No.: <strong class="cert-fill"><?= sanitize($certSerial) ?></strong></span>
            <span>G.R. No.: <strong class="cert-fill"><?= sanitize($grNo) ?></strong></span>
        </div>

        <div class="cert-title-block">
            <h2>BONAFIDE CERTIFICATE</h2>
            <div class="line"></div>
        </div>

        <div class="cert-photo-stamp">
            <i class="fa-solid fa-user"></i>
            <span>Affix Student<br>Photograph</span>
        </div>

        <div class="cert-content">
            This is to certify that Kum. / Kumar 
            <span class="cert-fill"><?= sanitize(strtoupper($studentName)) ?></span> 
            is / was a bonafide student of this institution studying in 
            Standard <span class="cert-fill"><?= sanitize($classFormatted ?: '—') ?></span> 
            during the Academic Year <span class="cert-fill"><?= sanitize($ay) ?></span>.
            <br><br>
            His / Her Date of Birth according to the General Register is 
            <span class="cert-fill"><?= sanitize($dob ?: '—') ?></span>
            <?php if ($dobWords): ?>
                (in words: <span class="cert-fill"><?= sanitize($dobWords) ?></span>)
            <?php endif; ?>.
            <br><br>
            As per school records, his / her general conduct and moral character are 
            <span class="cert-fill">GOOD</span>.
            This certificate is issued for <span class="cert-fill"><?= sanitize($purpose) ?></span>.
        </div>

        <div class="cert-footer">
            <div class="cert-date-col">
                Date: <span class="cert-fill"><?= sanitize($issuedDate) ?></span>
            </div>
            <div class="cert-sig-col">
                <div class="sig-space"></div>
                <div class="sig-label">Principal / Headmaster</div>
                <div style="font-size:0.75rem; color:#64748b; margin-top:2px; font-family:'Inter',sans-serif;">H.C.C. High School & Jr. College</div>
            </div>
        </div>

        <div class="cert-verification-seal">
            <div>
                <strong>Digital Verification:</strong> Verified against General Register #<?= sanitize($grNo) ?>
            </div>
            <div>
                Token: <code><?= sanitize(substr($token, 0, 10)) ?>...</code> • Hindustani Covenant Church High School
            </div>
        </div>
    </div>

</body>
</html>
