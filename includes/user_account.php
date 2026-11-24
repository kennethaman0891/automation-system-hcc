<?php
// Direct-access guard: helpers must only be loaded via require/include.
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

require_once __DIR__ . '/../config/database.php';

function hccFontSteps(): array
{
    return [80, 90, 100, 110, 125, 140, 160];
}

function hccSnapFontPercent(int $percent): int
{
    $steps = hccFontSteps();
    if (in_array($percent, $steps, true)) {
        return $percent;
    }
    $best = 100;
    $bestDist = PHP_INT_MAX;
    foreach ($steps as $step) {
        $dist = abs($step - $percent);
        if ($dist < $bestDist) {
            $bestDist = $dist;
            $best = $step;
        }
    }
    return $best;
}

function hccFontPercentLabel(int $percent): string
{
    $labels = [
        80 => 'Smaller',
        90 => 'Small',
        100 => 'Standard',
        110 => 'Large',
        125 => 'Larger',
        140 => 'Extra large',
        160 => 'Largest',
    ];
    return $labels[hccSnapFontPercent($percent)] ?? 'Standard';
}

function hccFontZoomValue(int $percent): string
{
    $value = number_format($percent / 100, 2, '.', '');
    $value = rtrim(rtrim($value, '0'), '.');
    return $value === '' ? '1' : $value;
}

function hccAdminInitials(?string $name): string
{
    $name = trim((string)$name);
    if ($name === '') {
        return 'A';
    }
    $out = '';
    foreach (preg_split('/\s+/', $name) as $part) {
        if ($part === '') {
            continue;
        }
        $out .= mb_strtoupper(mb_substr($part, 0, 1));
        if (mb_strlen($out) >= 2) {
            break;
        }
    }
    return $out !== '' ? $out : 'A';
}

function hccAvatarDir(): string
{
    return dirname(__DIR__) . '/storage/avatars';
}

function hccEnsureUserAccountColumns(?PDO $db = null): bool
{
    static $ok = null;
    if ($ok !== null) {
        return $ok;
    }
    try {
        $db = $db ?: getDB();
        $names = [];
        foreach ($db->query('SHOW COLUMNS FROM users') as $row) {
            $names[] = (string)$row['Field'];
        }
        if (!in_array('ui_font_percent', $names, true)) {
            $db->exec('ALTER TABLE users ADD COLUMN ui_font_percent SMALLINT UNSIGNED NOT NULL DEFAULT 100');
        }
        if (!in_array('profile_photo', $names, true)) {
            $db->exec('ALTER TABLE users ADD COLUMN profile_photo VARCHAR(255) NULL DEFAULT NULL');
        }
        $ok = true;
    } catch (Throwable $e) {
        error_log('HCC account columns: ' . $e->getMessage());
        $ok = false;
    }
    return $ok;
}

function hccAccountDefaults(): array
{
    $name = trim((string)($_SESSION['admin_name'] ?? 'Administrator'));
    $role = (string)($_SESSION['admin_role'] ?? 'admin');
    return [
        'id' => (int)($_SESSION['admin_id'] ?? 0),
        'username' => '',
        'full_name' => $name,
        'email' => '',
        'role' => $role,
        'auth_provider' => 'local',
        'font_percent' => 100,
        'has_photo' => false,
        'photo_url' => '',
        'initials' => hccAdminInitials($name),
    ];
}

function hccAvatarAbsolutePath(int $userId): ?string
{
    if ($userId < 1) {
        return null;
    }
    $dir = hccAvatarDir();
    $path = $dir . '/u' . $userId . '.jpg';
    if (!is_file($path)) {
        return null;
    }
    $realFile = realpath($path);
    $realDir = realpath($dir);
    if ($realFile === false || $realDir === false) {
        return null;
    }
    $prefix = rtrim($realDir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
    if (strncmp($realFile, $prefix, strlen($prefix)) !== 0) {
        return null;
    }
    return $realFile;
}

function hccCurrentAccount(bool $refresh = false): array
{
    static $cached = null;
    if ($refresh) {
        $cached = null;
    }
    if ($cached !== null) {
        return $cached;
    }
    $cached = hccAccountDefaults();
    $id = (int)$cached['id'];
    if ($id < 1 || !hccEnsureUserAccountColumns()) {
        return $cached;
    }
    try {
        $stmt = getDB()->prepare(
            'SELECT id, username, full_name, email, role, auth_provider, ui_font_percent, profile_photo
             FROM users WHERE id = ? LIMIT 1'
        );
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return $cached;
        }
        $name = trim((string)($row['full_name'] ?? ''));
        if ($name === '') {
            $name = $cached['full_name'];
        }
        $percent = hccSnapFontPercent((int)($row['ui_font_percent'] ?? 100));
        $photoPath = hccAvatarAbsolutePath($id);
        $hasPhoto = $photoPath !== null && trim((string)($row['profile_photo'] ?? '')) !== '';
        $photoUrl = '';
        if ($hasPhoto) {
            $base = defined('BASE_URL') ? BASE_URL : '/hcc/';
            $photoUrl = $base . 'admin/avatar.php?v=' . (int)filemtime($photoPath);
        }
        $cached = [
            'id' => $id,
            'username' => (string)($row['username'] ?? ''),
            'full_name' => $name,
            'email' => trim((string)($row['email'] ?? '')),
            'role' => (string)($row['role'] ?? $cached['role']),
            'auth_provider' => (string)($row['auth_provider'] ?? 'local'),
            'font_percent' => $percent,
            'has_photo' => $hasPhoto,
            'photo_url' => $photoUrl,
            'initials' => hccAdminInitials($name),
        ];
    } catch (Throwable $e) {
        error_log('HCC account load: ' . $e->getMessage());
    }
    return $cached;
}

function hccAccountHead(): void
{
    if (empty($_SESSION['admin_id'])) {
        return;
    }
    $acct = hccCurrentAccount();
    $css = '';
    $percent = (int)$acct['font_percent'];
    if ($percent !== 100) {
        $zoom = hccFontZoomValue($percent);
        $px = (int)round(16 * ($percent / 100));
        $css .= '@supports (zoom:1){html{zoom:' . $zoom . '}}';
        $css .= '@supports not (zoom:1){html{font-size:' . $px . 'px}}';
        $css .= '@media print{html{zoom:1!important;font-size:16px!important}}';
    }
    $photoUrl = (string)($acct['photo_url'] ?? '');
    if (!empty($acct['has_photo']) && $photoUrl !== '' && !preg_match('/[^A-Za-z0-9_\\/?=&.:-]/', $photoUrl)) {
        $css .= 'html body .user-avatar,html body .logout-modal-avatar{background-image:url("' . $photoUrl . '")!important;background-size:cover!important;background-position:center!important;background-repeat:no-repeat!important;color:transparent!important}';
        $css .= 'html body .admin-user>i.fa-user-circle{width:34px;height:34px;border-radius:50%;display:inline-block;overflow:hidden;font-size:0!important;line-height:34px;background-image:url("' . $photoUrl . '")!important;background-size:cover!important;background-position:center!important;background-repeat:no-repeat!important;color:transparent!important}';
        $css .= 'html body .admin-user>i.fa-user-circle::before{content:none!important}';
    }
    if ($css === '') {
        return;
    }
    echo '<style id="hcc-account-prefs">' . $css . '</style>' . "\n";
}

function hccSaveFontPercent(int $userId, int $percent): string
{
    if ($userId < 1) {
        return 'Your session has expired. Sign in again.';
    }
    if (!hccEnsureUserAccountColumns()) {
        return 'Text size could not be saved. Try again in a moment.';
    }
    $percent = hccSnapFontPercent($percent);
    try {
        $stmt = getDB()->prepare('UPDATE users SET ui_font_percent = ? WHERE id = ?');
        $stmt->execute([$percent, $userId]);
        hccCurrentAccount(true);
        if (function_exists('rcLogAudit')) {
            rcLogAudit('account_font_changed', 'user', 'Text size set to ' . $percent . '% for user id ' . $userId);
        }
        return '';
    } catch (Throwable $e) {
        error_log('HCC font save: ' . $e->getMessage());
        return 'Text size could not be saved. Try again in a moment.';
    }
}

function hccPrepareAvatarDir(): ?string
{
    $dir = hccAvatarDir();
    if (!is_dir($dir) && !@mkdir($dir, 0777, true) && !is_dir($dir)) {
        return null;
    }
    // Apache on XAMPP often runs as a different user than the folder owner.
    // storage/.htaccess denies HTTP access to this directory.
    @chmod($dir, 0777);
    return is_writable($dir) ? $dir : null;
}

function hccApplyExifOrientation($image, string $path)
{
    if (!function_exists('exif_read_data')) {
        return $image;
    }
    $exif = @exif_read_data($path);
    $orientation = (int)($exif['Orientation'] ?? 1);
    if ($orientation === 2 && function_exists('imageflip')) {
        imageflip($image, IMG_FLIP_HORIZONTAL);
        return $image;
    }
    $angle = null;
    if ($orientation === 3) {
        $angle = 180;
    } elseif ($orientation === 6) {
        $angle = -90;
    } elseif ($orientation === 8) {
        $angle = 90;
    }
    if ($angle === null) {
        return $image;
    }
    $rotated = imagerotate($image, $angle, 0);
    if ($rotated === false) {
        return $image;
    }
    imagedestroy($image);
    return $rotated;
}

function hccWriteAvatarFile(int $userId, string $tmpPath): string
{
    if ($userId < 1) {
        return 'Your session has expired. Sign in again.';
    }
    if (!is_file($tmpPath) || !is_readable($tmpPath)) {
        return 'The photo could not be read. Please try again.';
    }
    if (!function_exists('imagecreatefromstring') || !function_exists('imagejpeg')) {
        return 'Photo upload is unavailable on this server.';
    }
    if (!hccEnsureUserAccountColumns()) {
        return 'The photo could not be saved. Try again in a moment.';
    }
    $info = @getimagesize($tmpPath);
    $allowed = ['image/jpeg' => true, 'image/png' => true, 'image/webp' => true];
    $mime = is_array($info) ? (string)($info['mime'] ?? '') : '';
    if ($mime === '' || !isset($allowed[$mime])) {
        return 'Use a JPG, PNG, or WebP photo.';
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detected = (string)$finfo->file($tmpPath);
    if (!isset($allowed[$detected])) {
        return 'Use a JPG, PNG, or WebP photo.';
    }
    $width = (int)($info[0] ?? 0);
    $height = (int)($info[1] ?? 0);
    if ($width < 32 || $height < 32) {
        return 'That photo is too small. Use an image at least 32 pixels wide.';
    }
    if ($width > 8000 || $height > 8000 || ($width * $height) > 24000000) {
        return 'That photo is too large to process. Use a smaller image.';
    }
    $bytes = @file_get_contents($tmpPath);
    if ($bytes === false || $bytes === '') {
        return 'The photo could not be read. Please try again.';
    }
    $src = @imagecreatefromstring($bytes);
    if ($src === false) {
        return 'That file is not a usable JPG, PNG, or WebP photo.';
    }
    if ($mime === 'image/jpeg') {
        $src = hccApplyExifOrientation($src, $tmpPath);
    }
    $width = imagesx($src);
    $height = imagesy($src);
    if ($width < 32 || $height < 32) {
        imagedestroy($src);
        return 'That photo is too small. Use an image at least 32 pixels wide.';
    }
    $side = min($width, $height);
    $sx = (int)(($width - $side) / 2);
    $sy = (int)(($height - $side) / 2);
    $out = $side >= 400 ? 400 : $side;
    $dst = imagecreatetruecolor($out, $out);
    if ($dst === false) {
        imagedestroy($src);
        return 'The photo could not be saved. Try again in a moment.';
    }
    $white = imagecolorallocate($dst, 255, 255, 255);
    imagefill($dst, 0, 0, $white);
    imagecopyresampled($dst, $src, 0, 0, $sx, $sy, $out, $out, $side, $side);
    imagedestroy($src);

    $dir = hccPrepareAvatarDir();
    if ($dir === null) {
        imagedestroy($dst);
        return 'The photo folder is not writable. Ask the administrator to allow writes to storage/avatars.';
    }
    $final = $dir . '/u' . $userId . '.jpg';
    $temp = $final . '.uploading';
    $written = imagejpeg($dst, $temp, 86);
    imagedestroy($dst);
    if (!$written || !is_file($temp)) {
        @unlink($temp);
        return 'The photo could not be saved. Try again in a moment.';
    }
    @chmod($temp, 0644);
    if (!@rename($temp, $final)) {
        @unlink($temp);
        return 'The photo could not be saved. Try again in a moment.';
    }
    @chmod($final, 0644);
    try {
        $stmt = getDB()->prepare('UPDATE users SET profile_photo = ? WHERE id = ?');
        $stmt->execute(['u' . $userId . '.jpg', $userId]);
        hccCurrentAccount(true);
        if (function_exists('rcLogAudit')) {
            rcLogAudit('account_photo_updated', 'user', 'Profile photo updated for user id ' . $userId);
        }
        return '';
    } catch (Throwable $e) {
        error_log('HCC photo save: ' . $e->getMessage());
        return 'The photo could not be saved. Try again in a moment.';
    }
}

function hccSaveUploadedProfilePhoto(int $userId, array $file): string
{
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) {
        return 'Choose a photo first.';
    }
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return 'That photo is too large. Use a JPG, PNG, or WebP under 2 MB.';
    }
    if ($err !== UPLOAD_ERR_OK) {
        return 'The photo could not be uploaded. Please try again.';
    }
    $tmp = (string)($file['tmp_name'] ?? '');
    $size = (int)($file['size'] ?? 0);
    if ($tmp === '' || !is_uploaded_file($tmp)) {
        return 'The photo could not be uploaded. Please try again.';
    }
    if ($size < 1 || $size > (2 * 1024 * 1024)) {
        return 'That photo is too large. Use a JPG, PNG, or WebP under 2 MB.';
    }
    return hccWriteAvatarFile($userId, $tmp);
}

function hccRemoveProfilePhoto(int $userId): string
{
    if ($userId < 1) {
        return 'Your session has expired. Sign in again.';
    }
    if (!hccEnsureUserAccountColumns()) {
        return 'The photo could not be removed. Try again in a moment.';
    }
    $path = hccAvatarDir() . '/u' . $userId . '.jpg';
    if (is_file($path) && !@unlink($path)) {
        return 'The photo could not be removed. Try again in a moment.';
    }
    $temp = $path . '.uploading';
    if (is_file($temp)) {
        @unlink($temp);
    }
    try {
        $stmt = getDB()->prepare('UPDATE users SET profile_photo = NULL WHERE id = ?');
        $stmt->execute([$userId]);
        hccCurrentAccount(true);
        if (function_exists('rcLogAudit')) {
            rcLogAudit('account_photo_removed', 'user', 'Profile photo removed for user id ' . $userId);
        }
        return '';
    } catch (Throwable $e) {
        error_log('HCC photo remove: ' . $e->getMessage());
        return 'The photo could not be removed. Try again in a moment.';
    }
}
