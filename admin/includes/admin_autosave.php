<?php
/**
 * HCC Common Admin Auto-Save Connector
 * ------------------------------------
 * Single shared connector included across ALL admin screens (via account_head.php and header.php).
 * - Exposes CSRF token and autosave endpoint metadata in a CSP-safe <meta> tag (no inline script).
 * - Loads shared autosave CSS and JS engine (`assets/js/hcc-autosave.js`) on every admin page.
 * - Provides `hccEnsureAutosaveTable()` so DB persistence works automatically even on fresh setups.
 */
if (basename($_SERVER['SCRIPT_NAME'] ?? '') === basename(__FILE__)) {
    http_response_code(403);
    exit('Forbidden');
}

if (!function_exists('hccEnsureAutosaveTable')) {
    function hccEnsureAutosaveTable(?PDO $db = null): bool
    {
        static $ensured = null;
        if ($ensured !== null) {
            return $ensured;
        }
        try {
            $db = $db ?: getDB();
            $db->exec("
                CREATE TABLE IF NOT EXISTS admin_autosave_drafts (
                    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
                    admin_id INT NOT NULL,
                    page_key VARCHAR(80) NOT NULL,
                    form_key VARCHAR(120) NOT NULL,
                    context_key VARCHAR(255) NOT NULL DEFAULT '',
                    page_url VARCHAR(500) NOT NULL DEFAULT '',
                    payload_json MEDIUMTEXT NOT NULL,
                    field_count INT UNSIGNED NOT NULL DEFAULT 0,
                    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    PRIMARY KEY (id),
                    UNIQUE KEY uq_admin_autosave_scope (admin_id, page_key, form_key, context_key),
                    KEY idx_admin_autosave_page (admin_id, page_key, updated_at)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
            $ensured = true;
        } catch (Throwable $e) {
            error_log('HCC autosave table ensure failed: ' . $e->getMessage());
            $ensured = false;
        }
        return $ensured;
    }
}

if (!function_exists('hccRenderAdminAutosaveAssets')) {
    function hccRenderAdminAutosaveAssets(): void
    {
        static $rendered = false;
        if ($rendered) {
            return;
        }
        if (empty($_SESSION['admin_id'])) {
            return;
        }
        $rendered = true;

        $baseUrl = defined('BASE_URL') ? BASE_URL : '/hcc/';
        $scriptBase = basename((string)($_SERVER['SCRIPT_NAME'] ?? ($_SERVER['PHP_SELF'] ?? '')));
        if (strtolower(substr($scriptBase, -4)) === '.php') {
            $scriptBase = substr($scriptBase, 0, -4);
        }
        $pageKey = strtolower(preg_replace('/[^a-z0-9_-]/i', '', $scriptBase));
        if ($pageKey === '') {
            $pageKey = 'admin';
        }

        $csrf = function_exists('generateCSRFToken') ? generateCSRFToken() : (string)($_SESSION['csrf_token'] ?? '');
        $adminId = (int)($_SESSION['admin_id'] ?? 0);

        $cssPath = __DIR__ . '/../../assets/css/hcc-autosave.css';
        $jsPath  = __DIR__ . '/../../assets/js/hcc-autosave.js';
        $cssVer  = is_file($cssPath) ? (string)filemtime($cssPath) : '1';
        $jsVer   = is_file($jsPath) ? (string)filemtime($jsPath) : '1';

        $cfg = json_encode([
            'pageKey'  => $pageKey,
            'adminId'  => $adminId,
            'endpoint' => $baseUrl . 'admin/api_autosave.php',
            'csrf'     => $csrf,
        ], JSON_UNESCAPED_SLASHES);

        echo '<meta name="hcc-autosave-config" content="' . htmlspecialchars((string)$cfg, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        echo '<link rel="stylesheet" href="' . htmlspecialchars($baseUrl . 'assets/css/hcc-autosave.css?v=' . $cssVer, ENT_QUOTES, 'UTF-8') . '">' . "\n";
        echo '<script defer src="' . htmlspecialchars($baseUrl . 'assets/js/hcc-autosave.js?v=' . $jsVer, ENT_QUOTES, 'UTF-8') . '"></script>' . "\n";
    }
}
