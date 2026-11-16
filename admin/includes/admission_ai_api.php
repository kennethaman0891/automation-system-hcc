<?php
/**
 * Admission AI — API engine settings, encrypted key storage, SSRF-safe OpenAI-compatible client.
 * Keys never leave the server. No silent local↔API fallback.
 */

if (!function_exists('airSecretsDir')) {
    function airSecretsDir(): string {
        $dir = dirname(__DIR__, 2) . '/data/private';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir;
    }
}

if (!function_exists('airApiKeyPath')) {
    function airApiKeyPath(): string {
        return airSecretsDir() . '/admission_ai_api_key.enc';
    }
}

if (!function_exists('airMasterKey')) {
    /** 32-byte key derived from JWT_SECRET (env.php / gitignored). */
    function airMasterKey(): string {
        if (!defined('JWT_SECRET') || JWT_SECRET === '') {
            throw new RuntimeException('JWT_SECRET required to protect API keys');
        }
        return hash('sha256', JWT_SECRET . '|hcc_admission_ai_api_v1', true);
    }
}

if (!function_exists('airEncryptSecret')) {
    function airEncryptSecret(string $plain): string {
        $key = airMasterKey();
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipher === false) {
            throw new RuntimeException('Encryption failed');
        }
        return base64_encode($iv . $tag . $cipher);
    }
}

if (!function_exists('airDecryptSecret')) {
    function airDecryptSecret(string $blob): string {
        $raw = base64_decode($blob, true);
        if ($raw === false || strlen($raw) < 29) {
            throw new RuntimeException('Invalid ciphertext');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', airMasterKey(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($plain === false) {
            throw new RuntimeException('Decryption failed');
        }
        return $plain;
    }
}

if (!function_exists('airSaveApiKey')) {
    function airSaveApiKey(string $plainKey): void {
        $plainKey = trim($plainKey);
        if ($plainKey === '') {
            throw new InvalidArgumentException('API key empty');
        }
        if (strlen($plainKey) > 512) {
            throw new InvalidArgumentException('API key too long');
        }
        $enc = airEncryptSecret($plainKey);
        $path = airApiKeyPath();
        $tmp = $path . '.tmp.' . bin2hex(random_bytes(4));
        if (file_put_contents($tmp, $enc, LOCK_EX) === false) {
            throw new RuntimeException('Could not write key file');
        }
        @chmod($tmp, 0600);
        if (!@rename($tmp, $path)) {
            @unlink($tmp);
            throw new RuntimeException('Could not install key file');
        }
        @chmod($path, 0600);
        saveSetting('admission_ai_key_configured', '1');
    }
}

if (!function_exists('airRemoveApiKey')) {
    function airRemoveApiKey(): void {
        $path = airApiKeyPath();
        if (is_file($path)) {
            @unlink($path);
        }
        saveSetting('admission_ai_key_configured', '0');
    }
}

if (!function_exists('airHasApiKey')) {
    function airHasApiKey(): bool {
        $path = airApiKeyPath();
        return is_file($path) && filesize($path) > 20;
    }
}

if (!function_exists('airLoadApiKey')) {
    /** @return string|null plaintext key or null if missing */
    function airLoadApiKey(): ?string {
        $path = airApiKeyPath();
        if (!is_file($path)) return null;
        $blob = file_get_contents($path);
        if ($blob === false || $blob === '') return null;
        return airDecryptSecret(trim($blob));
    }
}

if (!function_exists('airRedactSecrets')) {
    function airRedactSecrets(string $text, ?string $key = null): string {
        $out = $text;
        if ($key && $key !== '') {
            $out = str_replace($key, '[REDACTED]', $out);
        }
        $out = preg_replace('/Bearer\s+[A-Za-z0-9\-_\.=]+/i', 'Bearer [REDACTED]', $out) ?? $out;
        $out = preg_replace('/sk-[A-Za-z0-9]{10,}/', '[REDACTED]', $out) ?? $out;
        $out = preg_replace('/nvapi-[A-Za-z0-9_\-]{10,}/', '[REDACTED]', $out) ?? $out;
        return $out;
    }
}

if (!function_exists('airNvidiaDefaultBaseUrl')) {
    /** Verified from NVIDIA docs: https://build.nvidia.com/llms.txt / docs.api.nvidia.com */
    function airNvidiaDefaultBaseUrl(): string {
        return 'https://integrate.api.nvidia.com/v1';
    }
}

if (!function_exists('airGetEngineSettings')) {
    /** Public settings — never includes the key. */
    function airGetEngineSettings(): array {
        try {
            $s = getAllSettings();
        } catch (Throwable $e) {
            $s = [];
        }
        $provider = (string)($s['admission_ai_provider'] ?? 'nvidia');
        if (!in_array($provider, ['nvidia', 'openai_compatible'], true)) $provider = 'nvidia';
        $engine = (string)($s['admission_ai_engine'] ?? 'local');
        if (!in_array($engine, ['local', 'api'], true)) $engine = 'local';
        $base = trim((string)($s['admission_ai_base_url'] ?? ''));
        if ($base === '' && $provider === 'nvidia') {
            $base = airNvidiaDefaultBaseUrl();
        }
        $model = trim((string)($s['admission_ai_model'] ?? ''));
        $hasKey = airHasApiKey();
        return [
            'engine' => $engine,
            'provider' => $provider,
            'base_url' => $base,
            'model' => $model,
            'key_configured' => $hasKey,
            'key_status' => $hasKey ? 'Configured' : 'Not configured',
            'nvidia_default_base_url' => airNvidiaDefaultBaseUrl(),
            'disclosure' => 'Selecting API and clicking Analyze will transmit ONLY the selected chat text/context to the chosen provider over HTTPS and may incur usage charges. There is no silent fallback between Local and API.',
        ];
    }
}

if (!function_exists('airSaveEngineSettings')) {
    /**
     * @param array{engine?:string,provider?:string,base_url?:string,model?:string,api_key?:string,remove_key?:bool} $in
     * @return array{ok:bool,error?:string,settings?:array}
     */
    function airSaveEngineSettings(array $in): array {
        $engine = (string)($in['engine'] ?? 'local');
        if (!in_array($engine, ['local', 'api'], true)) {
            return ['ok' => false, 'error' => 'Invalid engine'];
        }
        $provider = (string)($in['provider'] ?? 'nvidia');
        if (!in_array($provider, ['nvidia', 'openai_compatible'], true)) {
            return ['ok' => false, 'error' => 'Invalid provider'];
        }
        $base = trim((string)($in['base_url'] ?? ''));
        if ($base === '' && $provider === 'nvidia') {
            $base = airNvidiaDefaultBaseUrl();
        }
        if ($engine === 'api' || $base !== '') {
            $urlCheck = airValidateHttpsEndpoint($base);
            if (!$urlCheck['ok']) {
                return ['ok' => false, 'error' => $urlCheck['error'] ?? 'Invalid base URL'];
            }
            $base = $urlCheck['normalized'];
        }
        $model = trim((string)($in['model'] ?? ''));
        if (strlen($model) > 120) {
            return ['ok' => false, 'error' => 'Model ID too long'];
        }
        // Do not invent NVIDIA model defaults — user must enter model ID.

        saveSetting('admission_ai_engine', $engine);
        saveSetting('admission_ai_provider', $provider);
        saveSetting('admission_ai_base_url', $base);
        saveSetting('admission_ai_model', $model);

        if (!empty($in['remove_key'])) {
            airRemoveApiKey();
        } elseif (isset($in['api_key']) && is_string($in['api_key']) && trim($in['api_key']) !== '') {
            airSaveApiKey(trim($in['api_key']));
        }

        return ['ok' => true, 'settings' => airGetEngineSettings()];
    }
}

if (!function_exists('airIpInCidrV6')) {
    function airIpInCidrV6(string $binIp, string $cidrNet, int $prefixBits): bool {
        $binNet = @inet_pton($cidrNet);
        if ($binNet === false || strlen($binIp) !== 16 || strlen($binNet) !== 16) {
            return true; // fail closed
        }
        $fullBytes = intdiv($prefixBits, 8);
        $remBits   = $prefixBits % 8;
        if ($fullBytes > 0 && substr($binIp, 0, $fullBytes) !== substr($binNet, 0, $fullBytes)) {
            return false;
        }
        if ($remBits > 0) {
            $mask = (0xFF << (8 - $remBits)) & 0xFF;
            if ((ord($binIp[$fullBytes]) & $mask) !== (ord($binNet[$fullBytes]) & $mask)) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('airIsBlockedIp')) {
    function airIsBlockedIp(string $ip): bool {
        $ip = trim($ip, '[]');
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return true;
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $long = ip2long($ip);
            if ($long === false) return true;
            // 0.0.0.0/8, 127/8, 10/8, 172.16/12, 192.168/16, 169.254/16, 100.64/10
            $cidrs = [
                ['0.0.0.0', 8], ['10.0.0.0', 8], ['127.0.0.0', 8],
                ['169.254.0.0', 16], ['172.16.0.0', 12], ['192.168.0.0', 16],
                ['100.64.0.0', 10], ['192.0.0.0', 24], ['192.0.2.0', 24],
                ['198.18.0.0', 15], ['198.51.100.0', 24], ['203.0.113.0', 24],
                ['224.0.0.0', 4], ['240.0.0.0', 4],
            ];
            foreach ($cidrs as [$net, $bits]) {
                $mask = -1 << (32 - $bits);
                if ((ip2long($net) & $mask) === ($long & $mask)) return true;
            }
            if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
                return true;
            }
            return false;
        }
        // IPv6: validate via inet_pton() against loopback, unspecified, IPv4-compatible (::/96 -> covers ::127.0.0.1),
        // link-local (fe80::/10), ULA (fc00::/7), IPv4-mapped (::ffff:0:0/96), NAT64 (64:ff9b::/96, 64:ff9b:1::/48),
        // discard (100::/64), documentation (2001:db8::/32), and multicast (ff00::/8).
        $bin = @inet_pton($ip);
        if ($bin === false || strlen($bin) !== 16) {
            return true;
        }
        $v6Cidrs = [
            ['::1', 128],
            ['::', 96],
            ['fe80::', 10],
            ['fc00::', 7],
            ['::ffff:0:0', 96],
            ['64:ff9b::', 96],
            ['64:ff9b:1::', 48],
            ['100::', 64],
            ['2001:db8::', 32],
            ['ff00::', 8],
        ];
        foreach ($v6Cidrs as [$net, $bits]) {
            if (airIpInCidrV6($bin, $net, $bits)) {
                return true;
            }
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return true;
        }
        return false;
    }
}

if (!function_exists('airValidateHttpsEndpoint')) {
    /**
     * SSRF guard: HTTPS (port 443 only), no credentials in URL, no private/loopback/metadata hosts,
     * fail-closed DNS resolution with public IP capture for CURLOPT_RESOLVE pinning.
     * @return array{ok:bool,normalized?:string,host?:string,ips?:list<string>,error?:string}
     */
    function airValidateHttpsEndpoint(string $url): array {
        $url = trim($url);
        if ($url === '') return ['ok' => false, 'error' => 'Base URL required for API engine'];
        if (strlen($url) > 500) return ['ok' => false, 'error' => 'Base URL too long'];
        if (preg_match('/[\r\n\0]/', $url)) return ['ok' => false, 'error' => 'Invalid characters in base URL'];
        $parts = parse_url($url);
        if ($parts === false || empty($parts['scheme']) || empty($parts['host'])) {
            return ['ok' => false, 'error' => 'Could not parse base URL'];
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return ['ok' => false, 'error' => 'Only HTTPS base URLs are allowed'];
        }
        if (isset($parts['user']) || isset($parts['pass'])) {
            return ['ok' => false, 'error' => 'Credentials must not appear in the URL'];
        }
        if (isset($parts['port']) && (int)$parts['port'] !== 443) {
            return ['ok' => false, 'error' => 'Only HTTPS port 443 is allowed'];
        }
        if (isset($parts['query']) && $parts['query'] !== '') {
            // Allow empty path query-less; block query with potential secrets
            if (preg_match('/(key|token|secret|auth)=/i', $parts['query'])) {
                return ['ok' => false, 'error' => 'Secrets must not appear in the URL query'];
            }
        }
        $host = rtrim(strtolower($parts['host']), '.');
        $hostIp = trim($host, '[]');
        $blockedHosts = [
            'localhost',
            'localhost.localdomain',
            'metadata.google.internal',
            'metadata',
            'kubernetes',
            'kubernetes.default',
            'kubernetes.default.svc',
        ];
        if (in_array($host, $blockedHosts, true)
            || str_ends_with($host, '.local')
            || str_ends_with($host, '.internal')
            || str_ends_with($host, '.localhost')
            || str_ends_with($host, '.localdomain')
            || str_ends_with($host, '.arpa')
        ) {
            return ['ok' => false, 'error' => 'Host is not allowed'];
        }
        if (filter_var($hostIp, FILTER_VALIDATE_IP)) {
            if (airIsBlockedIp($hostIp)) {
                return ['ok' => false, 'error' => 'Private or reserved IP hosts are blocked'];
            }
            $resolved = [$hostIp];
        } else {
            // Resolve DNS (or test mock hook) and fail closed if zero valid public IPs resolve
            $resolved = [];
            $mockDns = $GLOBALS['AIR_MOCK_DNS'] ?? null;
            if (is_array($mockDns) && array_key_exists($host, $mockDns)) {
                foreach ((array)$mockDns[$host] as $mockIp) {
                    if (is_string($mockIp) && $mockIp !== '') {
                        $resolved[] = $mockIp;
                    }
                }
            } else {
                $a = @dns_get_record($host, DNS_A);
                if (is_array($a)) {
                    foreach ($a as $rec) {
                        if (!empty($rec['ip'])) $resolved[] = $rec['ip'];
                    }
                }
                $aaaa = @dns_get_record($host, DNS_AAAA);
                if (is_array($aaaa)) {
                    foreach ($aaaa as $rec) {
                        if (!empty($rec['ipv6'])) $resolved[] = $rec['ipv6'];
                    }
                }
                // Offline CLI test runner (AIR_MOCK_HTTP=1): if sandbox lacks DNS, permit valid non-reserved FQDNs
                if (!$resolved
                    && in_array(PHP_SAPI, ['cli', 'cli-server'], true)
                    && in_array(strtolower((string)getenv('AIR_MOCK_HTTP')), ['1', 'true', 'yes'], true)
                    && !str_ends_with($host, '.invalid')
                    && !str_ends_with($host, '.test')
                    && !str_ends_with($host, '.example')
                    && preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/', $host)
                ) {
                    $resolved[] = '93.184.216.34';
                }
            }
        }

        $publicIps = [];
        foreach ($resolved as $ip) {
            if (airIsBlockedIp((string)$ip)) {
                return ['ok' => false, 'error' => 'Resolved address is private/reserved — blocked'];
            }
            $publicIps[] = trim((string)$ip, '[]');
        }
        if (empty($publicIps)) {
            return ['ok' => false, 'error' => 'Could not resolve host to a valid public IP address'];
        }

        $path = $parts['path'] ?? '';
        // Normalize: strip trailing slash for base (port is strictly 443)
        $normalized = 'https://' . $host . ($path !== '' ? rtrim($path, '/') : '');
        return ['ok' => true, 'normalized' => $normalized, 'host' => $host, 'ips' => array_values(array_unique($publicIps))];
    }
}

if (!function_exists('airValidateEndpointUrl')) {
    /** Alias for SSRF endpoint validation. */
    function airValidateEndpointUrl(string $url): array {
        return airValidateHttpsEndpoint($url);
    }
}

if (!function_exists('airChatCompletionsUrl')) {
    function airChatCompletionsUrl(string $baseUrl): string {
        $base = rtrim($baseUrl, '/');
        if (str_ends_with($base, '/chat/completions')) return $base;
        return $base . '/chat/completions';
    }
}

if (!function_exists('airCallOpenAiCompatible')) {
    /**
     * Server-side OpenAI-compatible chat.completions call.
     * @return array{ok:bool,raw?:string,model?:string,ms?:int,host?:string,error?:string,http_status?:int}
     */
    function airCallOpenAiCompatible(string $baseUrl, string $model, string $apiKey, string $userPrompt, int $timeoutSec = 90): array {
        // Mock transport for offline tests only
        if (in_array(PHP_SAPI, ['cli', 'cli-server'], true) && in_array(strtolower((string)getenv('AIR_MOCK_HTTP')), ['1','true','yes'], true)) {
            return airMockHttpGenerate($model, $userPrompt);
        }

        $urlCheck = airValidateHttpsEndpoint($baseUrl);
        if (!$urlCheck['ok']) {
            return ['ok' => false, 'error' => $urlCheck['error'] ?? 'Invalid endpoint'];
        }
        $endpoint = airChatCompletionsUrl($urlCheck['normalized']);
        // Re-validate full endpoint URL
        $epCheck = airValidateHttpsEndpoint(preg_replace('#/chat/completions$#', '', $endpoint) ?: $urlCheck['normalized']);
        if (!$epCheck['ok']) {
            return ['ok' => false, 'error' => $epCheck['error'] ?? 'Invalid endpoint'];
        }
        if ($model === '') {
            return ['ok' => false, 'error' => 'Model ID is required for API analysis'];
        }
        if ($apiKey === '') {
            return ['ok' => false, 'error' => 'API key is not configured'];
        }

        $payload = json_encode([
            'model' => $model,
            'temperature' => 0.1,
            'max_tokens' => 900,
            'messages' => [
                [
                    'role' => 'system',
                    'content' => 'You are a careful JSON-only assistant. Return a single JSON object. Never include API keys or secrets.',
                ],
                [
                    'role' => 'user',
                    'content' => $userPrompt,
                ],
            ],
            // Some providers support response_format
            'response_format' => ['type' => 'json_object'],
        ], JSON_UNESCAPED_UNICODE);

        $resolvedIps = $epCheck['ips'] ?? ($urlCheck['ips'] ?? []);
        if (empty($resolvedIps)) {
            return ['ok' => false, 'error' => 'Could not resolve host to a valid public IP address'];
        }
        $pinnedIp = $resolvedIps[0];
        foreach ($resolvedIps as $candIp) {
            if (filter_var($candIp, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                $pinnedIp = $candIp;
                break;
            }
        }

        $t0 = (int)(microtime(true) * 1000);
        $ch = curl_init($endpoint);
        $curlOpts = [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
                'Accept: application/json',
            ],
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_PORT => 443,
            CURLOPT_FOLLOWLOCATION => false, // no redirects (SSRF)
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXREDIRS => 0,
        ];
        if (!filter_var(trim($epCheck['host'], '[]'), FILTER_VALIDATE_IP)) {
            $curlOpts[CURLOPT_RESOLVE] = ["{$epCheck['host']}:443:{$pinnedIp}"];
        }
        curl_setopt_array($ch, $curlOpts);
        $resp = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $ms = (int)(microtime(true) * 1000) - $t0;

        if ($errno) {
            $msg = airRedactSecrets('API request failed: ' . $err, $apiKey);
            if ($errno === CURLE_OPERATION_TIMEDOUT) {
                $msg = 'API request timed out';
            }
            return ['ok' => false, 'error' => $msg, 'ms' => $ms, 'host' => $urlCheck['host'], 'http_status' => $code];
        }
        if ($code === 401 || $code === 403) {
            return ['ok' => false, 'error' => 'API authentication failed (HTTP ' . $code . '). Check the key and provider.', 'ms' => $ms, 'host' => $urlCheck['host'], 'http_status' => $code];
        }
        if ($code === 429) {
            return ['ok' => false, 'error' => 'API rate limit exceeded (HTTP 429). Try again later.', 'ms' => $ms, 'host' => $urlCheck['host'], 'http_status' => $code];
        }
        if ($code === 404) {
            return ['ok' => false, 'error' => 'API model or endpoint not found (HTTP 404). Check base URL and model ID.', 'ms' => $ms, 'host' => $urlCheck['host'], 'http_status' => $code];
        }
        if ($code < 200 || $code >= 300 || $resp === false) {
            return ['ok' => false, 'error' => airRedactSecrets('API HTTP ' . $code, $apiKey), 'ms' => $ms, 'host' => $urlCheck['host'], 'http_status' => $code];
        }
        if (strlen((string)$resp) > 524288) { // 512 KiB hard cap
            return ['ok' => false, 'error' => 'API response too large', 'ms' => $ms, 'host' => $urlCheck['host'], 'http_status' => $code];
        }
        $j = json_decode($resp, true);
        if (!is_array($j)) {
            return ['ok' => false, 'error' => 'API returned non-JSON body', 'ms' => $ms, 'host' => $urlCheck['host'], 'http_status' => $code];
        }
        $text = '';
        if (!empty($j['choices'][0]['message']['content'])) {
            $text = (string)$j['choices'][0]['message']['content'];
        } elseif (!empty($j['choices'][0]['text'])) {
            $text = (string)$j['choices'][0]['text'];
        }
        if ($text === '') {
            return ['ok' => false, 'error' => 'API returned empty content', 'ms' => $ms, 'host' => $urlCheck['host'], 'http_status' => $code];
        }
        return [
            'ok' => true,
            'raw' => $text,
            'model' => $model,
            'ms' => $ms,
            'host' => $urlCheck['host'],
            'http_status' => $code,
        ];
    }
}

if (!function_exists('airMockHttpGenerate')) {
    function airMockHttpGenerate(string $model, string $prompt): array {
        $fixture = getenv('AIR_MOCK_HTTP_FIXTURE') ?: 'admission';
        if ($fixture === '401') {
            return ['ok' => false, 'error' => 'API authentication failed (HTTP 401). Check the key and provider.', 'ms' => 1, 'host' => 'api.example.test', 'http_status' => 401];
        }
        if ($fixture === '429') {
            return ['ok' => false, 'error' => 'API rate limit exceeded (HTTP 429). Try again later.', 'ms' => 1, 'host' => 'api.example.test', 'http_status' => 429];
        }
        if ($fixture === 'timeout') {
            return ['ok' => false, 'error' => 'API request timed out', 'ms' => 1, 'host' => 'api.example.test', 'http_status' => 0];
        }
        if ($fixture === 'bad_json') {
            return ['ok' => true, 'raw' => 'NOT JSON', 'model' => $model, 'ms' => 1, 'host' => 'api.example.test', 'http_status' => 200];
        }
        $raw = json_encode([
            'classification' => 'admission',
            'explanation' => 'Parent asks about admission: "I want admission for STD 1".',
            'evidence_quotes' => ['I want admission for STD 1'],
            'requires_review' => true,
            'parent_name' => 'API Parent',
            'student_name' => null,
            'interested_class' => 'STD I',
            'academic_year' => '2026-27',
            'age_or_dob' => null,
            'contact_number' => null,
            'contact_number_source' => 'unknown',
            'question_summary' => 'Admission enquiry via API engine',
            'missing_details' => ['child name'],
            'suggested_reply' => 'Namaste, thank you for your interest in HCC High School for STD I. Please share the child name and age/DOB.',
        ]);
        return ['ok' => true, 'raw' => $raw, 'model' => $model . ' (http-mock)', 'ms' => 1, 'host' => 'api.example.test', 'http_status' => 200];
    }
}
