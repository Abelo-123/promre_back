<?php
/**
 * JustAnotherPanel Average Time Scraper Module
 * 
 * Replicates extract_services.py mechanism directly in PHP to independently
 * fetch, cache, and serve live service average_time metrics from JustAnotherPanel.
 */

require_once __DIR__ . '/config.php';

function fetchJapPage($url, $postData = null, $cookieJar = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 60);
    
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
    ];

    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }

    if ($postData !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($postData));
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Origin: https://justanotherpanel.com';
        $headers[] = 'Referer: https://justanotherpanel.com/';
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("[JAP Scraper] cURL error: " . $error);
        return '';
    }

    return $response;
}

function scrapeJapAverageTimes() {
    $username = getenv('JAP_USERNAME') ?: 'Primora';
    $password = getenv('JAP_PASSWORD') ?: 'Abelabate123@#';
    $baseUrl  = 'https://justanotherpanel.com';

    $tempCookieFile = tempnam(sys_get_temp_dir(), 'jap_cookie_');

    try {
        // Step 1: GET homepage to obtain CSRF token & session cookie
        $landingHtml = fetchJapPage($baseUrl . '/', null, $tempCookieFile);
        $csrf = null;
        if (preg_match('/"csrftoken"\s*:\s*"([^"]+)"/', $landingHtml, $m)) {
            $csrf = $m[1];
        }

        // Step 2: POST login credentials
        $payload = [
            'LoginForm[username]'   => $username,
            'LoginForm[password]'   => $password,
            'LoginForm[rememberMe]' => '0',
        ];
        if ($csrf) {
            $payload['_csrf'] = $csrf;
        }

        $authHtml = fetchJapPage($baseUrl . '/', $payload, $tempCookieFile);
        if (empty($authHtml)) {
            @unlink($tempCookieFile);
            return [];
        }

        // Step 3: Extract { service_id => average_time } map
        $serviceMap = [];

        // Primary regex
        if (preg_match_all('/"(\d{2,8})"\s*:\s*\{[^{}]*?"average_time"\s*:\s*"([^"]+)"/s', $authHtml, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $serviceMap[$m[1]] = trim($m[2]);
            }
        }

        // Fallback regex if primary found nothing
        if (empty($serviceMap)) {
            if (preg_match_all('/"id"\s*:\s*"(\d+)"(?:(?!"average_time")[^}])*"average_time"\s*:\s*"([^"]+)"/s', $authHtml, $matches, PREG_SET_ORDER)) {
                foreach ($matches as $m) {
                    $serviceMap[$m[1]] = trim($m[2]);
                }
            }
        }

        @unlink($tempCookieFile);
        return $serviceMap;

    } catch (Exception $e) {
        error_log("[JAP Scraper] Exception: " . $e->getMessage());
        if (file_exists($tempCookieFile)) {
            @unlink($tempCookieFile);
        }
        return [];
    }
}

function getAverageTimes($forceRefresh = false) {
    $cacheDir = __DIR__ . '/cache';
    if (!file_exists($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    $cacheFile = $cacheDir . '/average_times.json';
    $ttl = (int)(getenv('JAP_CACHE_TTL') ?: 900); // Default 15 minutes

    // If cache is fresh and forceRefresh is false, serve from cache
    if (!$forceRefresh && file_exists($cacheFile)) {
        $mtime = filemtime($cacheFile);
        if ((time() - $mtime) < $ttl) {
            $content = file_get_contents($cacheFile);
            $json = json_decode($content, true);
            if (is_array($json) && !empty($json)) {
                return $json;
            }
        }
    }

    // Scrape live data
    $freshMap = scrapeJapAverageTimes();

    if (!empty($freshMap)) {
        file_put_contents($cacheFile, json_encode($freshMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $freshMap;
    }

    // Fallback: If live scrape failed, return existing cached data regardless of age (up to 1 year)
    if (file_exists($cacheFile)) {
        $content = file_get_contents($cacheFile);
        $json = json_decode($content, true);
        if (is_array($json)) {
            return $json;
        }
    }

    return [];
}
