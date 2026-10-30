<?php
/**
 * HCC Automation System — Google OAuth & Google Drive Workspace Client
 * 
 * Lightweight, zero-dependency pure PHP client using native cURL.
 * Handles:
 *  - Google OAuth 2.0 Authorization & Token Exchange
 *  - Token Refreshing
 *  - Personal Google Drive Workspace Folder Creation & Hierarchy Management
 *  - Direct File Upload (PDFs, ZIPs, Marksheets)
 *  - Inter-Teacher Google Drive Permission Sharing
 */

require_once __DIR__ . '/functions.php';

class HCCGoogleDrive {
    private string $clientId;
    private string $clientSecret;
    private string $redirectUri;

    public function __construct(?string $clientId = null, ?string $clientSecret = null, ?string $redirectUri = null) {
        $settings = getAllSettings();
        $this->clientId     = trim($clientId ?? ($settings['google_client_id'] ?? ''));
        $this->clientSecret = trim($clientSecret ?? ($settings['google_client_secret'] ?? ''));
        
        $defaultUri = $this->detectDefaultRedirectUri();
        $this->redirectUri  = trim($redirectUri ?? (!empty($settings['google_redirect_uri']) ? $settings['google_redirect_uri'] : $defaultUri));
    }

    public function isConfigured(): bool {
        return ($this->clientId !== '' && $this->clientSecret !== '');
    }

    public function getClientId(): string {
        return $this->clientId;
    }

    public function getRedirectUri(): string {
        return $this->redirectUri;
    }

    private function detectDefaultRedirectUri(): string {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
        // Base URL path
        $base = defined('BASE_URL') ? BASE_URL : '/hcc/';
        $base = rtrim($base, '/') . '/';
        return "{$scheme}://{$host}{$base}admin/google_callback.php";
    }

    /**
     * Generate Google OAuth 2.0 authorization URL
     */
    public function getAuthUrl(string $state): string {
        $scopes = [
            'openid',
            'email',
            'profile',
            'https://www.googleapis.com/auth/drive.file'
        ];

        $params = [
            'client_id'             => $this->clientId,
            'redirect_uri'          => $this->redirectUri,
            'response_type'         => 'code',
            'scope'                 => implode(' ', $scopes),
            'access_type'           => 'offline',
            'prompt'                => 'select_account consent',
            'state'                 => $state,
            'include_granted_scopes'=> 'true'
        ];

        return 'https://accounts.google.com/o/oauth2/v2/auth?' . http_build_query($params);
    }

    /**
     * Exchange authorization code for access & refresh tokens
     */
    public function exchangeCode(string $code): ?array {
        $postData = [
            'code'          => $code,
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'redirect_uri'  => $this->redirectUri,
            'grant_type'    => 'authorization_code'
        ];

        $resp = $this->httpPost('https://oauth2.googleapis.com/token', $postData);
        if (!$resp || empty($resp['access_token'])) {
            error_log('HCC [Google OAuth] Token exchange failed: ' . json_encode($resp));
            return null;
        }

        // Fetch User profile info
        $userInfo = $this->getUserInfo($resp['access_token']);
        $resp['user'] = $userInfo;

        return $resp;
    }

    /**
     * Refresh an expired access token using the stored refresh_token
     */
    public function refreshAccessToken(string $refreshToken): ?string {
        $postData = [
            'client_id'     => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $refreshToken,
            'grant_type'    => 'refresh_token'
        ];

        $resp = $this->httpPost('https://oauth2.googleapis.com/token', $postData);
        if ($resp && !empty($resp['access_token'])) {
            return $resp['access_token'];
        }

        error_log('HCC [Google OAuth] Token refresh failed: ' . json_encode($resp));
        return null;
    }

    /**
     * Fetch user profile from Google Identity
     */
    public function getUserInfo(string $accessToken): ?array {
        $resp = $this->httpGet('https://www.googleapis.com/oauth2/v3/userinfo', $accessToken);
        return is_array($resp) ? $resp : null;
    }

    /**
     * Find a folder by name inside an optional parent
     */
    public function findFolder(string $accessToken, string $folderName, ?string $parentId = null): ?array {
        $q = "name = '" . addslashes($folderName) . "' and mimeType = 'application/vnd.google-apps.folder' and trashed = false";
        if ($parentId) {
            $q .= " and '{$parentId}' in parents";
        }

        $url = 'https://www.googleapis.com/drive/v3/files?' . http_build_query([
            'q'      => $q,
            'fields' => 'files(id, name, webViewLink)',
            'spaces' => 'drive'
        ]);

        $resp = $this->httpGet($url, $accessToken);
        if (!empty($resp['files']) && is_array($resp['files'])) {
            return $resp['files'][0];
        }

        return null;
    }

    /**
     * Create a Google Drive folder
     */
    public function createFolder(string $accessToken, string $folderName, ?string $parentId = null): ?array {
        $body = [
            'name'     => $folderName,
            'mimeType' => 'application/vnd.google-apps.folder',
        ];
        if ($parentId) {
            $body['parents'] = [$parentId];
        }

        $url = 'https://www.googleapis.com/drive/v3/files?fields=id,name,webViewLink';
        return $this->httpJsonPost($url, $body, $accessToken);
    }

    /**
     * Ensure a folder exists: finds existing or creates new
     */
    public function ensureFolder(string $accessToken, string $folderName, ?string $parentId = null): ?array {
        $existing = $this->findFolder($accessToken, $folderName, $parentId);
        if ($existing && !empty($existing['id'])) {
            return $existing;
        }
        return $this->createFolder($accessToken, $folderName, $parentId);
    }

    /**
     * Ensure complete organized workspace structure for a teacher:
     * 📁 HCC High School Portal
     *   └── 📁 2024-25
     *       └── 📁 [Teacher Name] - Academic Workspace
     *           ├── 📁 01_Semester_Marksheets_and_Results
     *           ├── 📁 02_Unit_Tests_and_Exams
     *           ├── 📁 03_Class_Analytics_Reports
     *           └── 📁 04_Student_Report_Cards
     */
    public function ensureUserWorkspace(string $accessToken, string $teacherName, string $academicYear = '2024-25'): array {
        $teacherNameClean = trim($teacherName) ?: 'Teacher';
        
        // 1. Root school folder
        $rootFolder = $this->ensureFolder($accessToken, 'HCC High School Portal');
        $rootId = $rootFolder['id'] ?? null;

        // 2. Academic year folder
        $yearFolder = $this->ensureFolder($accessToken, $academicYear, $rootId);
        $yearId = $yearFolder['id'] ?? $rootId;

        // 3. Teacher Personal Workspace folder
        $teacherWorkspace = $this->ensureFolder($accessToken, "{$teacherNameClean} - Academic Workspace", $yearId);
        $teacherFolderId  = $teacherWorkspace['id'] ?? null;
        $teacherFolderUrl = $teacherWorkspace['webViewLink'] ?? ("https://drive.google.com/drive/folders/" . ($teacherFolderId ?? ''));

        // 4. Subfolders
        $subfolderNames = [
            'marks'     => '01_Semester_Marksheets_and_Results',
            'unit_tests'=> '02_Unit_Tests_and_Exams',
            'analytics' => '03_Class_Analytics_Reports',
            'reports'   => '04_Student_Report_Cards',
        ];

        $subfolders = [];
        if ($teacherFolderId) {
            foreach ($subfolderNames as $key => $subName) {
                $sub = $this->ensureFolder($accessToken, $subName, $teacherFolderId);
                if ($sub) {
                    $subfolders[$key] = [
                        'id'   => $sub['id'],
                        'name' => $sub['name'],
                        'url'  => $sub['webViewLink'] ?? ("https://drive.google.com/drive/folders/" . $sub['id'])
                    ];
                }
            }
        }

        return [
            'root_folder_id'     => $rootId,
            'year_folder_id'     => $yearId,
            'teacher_folder_id'  => $teacherFolderId,
            'teacher_folder_url' => $teacherFolderUrl,
            'subfolders'         => $subfolders
        ];
    }

    /**
     * Upload a file (PDF, ZIP, CSV, etc.) directly into a specified Drive folder.
     * Uses Google Drive API v3 multipart upload.
     */
    public function uploadFile(string $accessToken, string $folderId, string $filename, string $fileContent, string $mimeType = 'application/pdf'): ?array {
        $metadata = [
            'name'    => $filename,
            'parents' => [$folderId]
        ];

        $boundary = '-------314159265358979323846';
        $delimiter = "\r\n--" . $boundary . "\r\n";
        $closeDelim = "\r\n--" . $boundary . "--";

        $multipartBody = $delimiter
            . "Content-Type: application/json; charset=UTF-8\r\n\r\n"
            . json_encode($metadata)
            . $delimiter
            . "Content-Type: " . $mimeType . "\r\n"
            . "Content-Transfer-Encoding: base64\r\n\r\n"
            . chunk_split(base64_encode($fileContent))
            . $closeDelim;

        $url = 'https://www.googleapis.com/upload/drive/v3/files?uploadType=multipart&fields=id,name,webViewLink,webContentLink';

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $multipartBody,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$accessToken}",
                "Content-Type: multipart/related; boundary={$boundary}",
                "Content-Length: " . strlen($multipartBody)
            ],
            CURLOPT_TIMEOUT        => 45
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            $data = json_decode($response, true);
            if (!empty($data['id'])) {
                return $data;
            }
        }

        error_log("HCC [Google Drive] File upload failed (HTTP {$httpCode}): " . $response);
        return null;
    }

    /**
     * Share a Drive folder or file with a colleague's Google Account.
     * Role: 'reader' (Viewer) or 'writer' (Editor)
     */
    public function shareFolderOrFile(string $accessToken, string $fileOrFolderId, string $colleagueEmail, string $role = 'reader', bool $sendNotification = true): ?array {
        $body = [
            'role'         => in_array($role, ['reader', 'writer', 'commenter'], true) ? $role : 'reader',
            'type'         => 'user',
            'emailAddress' => trim($colleagueEmail)
        ];

        $url = "https://www.googleapis.com/drive/v3/files/{$fileOrFolderId}/permissions?sendNotificationEmail=" . ($sendNotification ? 'true' : 'false') . '&fields=id,role,type,emailAddress';

        $result = $this->httpJsonPost($url, $body, $accessToken);
        if ($result && !empty($result['id'])) {
            return $result;
        }

        error_log("HCC [Google Drive] Share failed for {$colleagueEmail}: " . json_encode($result));
        return null;
    }

    /* ── HTTP Helpers ─────────────────────────────────────────────────── */

    private function httpGet(string $url, ?string $bearerToken = null): ?array {
        $ch = curl_init($url);
        $headers = ['Accept: application/json'];
        if ($bearerToken) {
            $headers[] = "Authorization: Bearer {$bearerToken}";
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => 20
        ]);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($res, true);
        }
        return null;
    }

    private function httpPost(string $url, array $params): ?array {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($params),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/x-www-form-urlencoded',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT        => 20
        ]);

        $res = curl_exec($ch);
        curl_close($ch);
        return json_decode((string)$res, true);
    }

    private function httpJsonPost(string $url, array $body, string $bearerToken): ?array {
        $ch = curl_init($url);
        $json = json_encode($body);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $json,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer {$bearerToken}",
                'Content-Type: application/json; charset=UTF-8',
                'Accept: application/json'
            ],
            CURLOPT_TIMEOUT        => 25
        ]);

        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode >= 200 && $httpCode < 300) {
            return json_decode($res, true);
        }
        return json_decode((string)$res, true);
    }
}
