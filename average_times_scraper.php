<?php
/**
 * JustAnotherPanel Average Time Scraper & Real-Time SWR Module
 * 
 * 1-to-1 PHP port of extract_services.py Python scraper.
 * Logs into JustAnotherPanel and extracts 'average_time' for ALL services.
 */

require_once __DIR__ . '/config.php';

function fetchJapPage($url, $postData = null, $cookieJar = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 120);
    
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
        'Accept-Encoding: identity',
    ];

    if ($cookieJar) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieJar);
    }

    if ($postData !== null) {
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, is_array($postData) ? http_build_query($postData) : $postData);
        $headers[] = 'Content-Type: application/x-www-form-urlencoded';
        $headers[] = 'Origin: https://justanotherpanel.com';
        $headers[] = 'Referer: https://justanotherpanel.com/';
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("[JAP Scraper] cURL error fetching {$url}: " . $error);
        return '';
    }

    return $response;
}

/**
 * Extract { service_id => average_time } from authenticated page HTML (1-to-1 from extract_services.py)
 */
function extractAvgTimes($html) {
    $serviceMap = [];
    if (empty($html)) return $serviceMap;

    // Primary: match service ID key -> object containing average_time value
    // Pattern: "DIGITS":{"id":"DIGITS",...,"average_time":"TIMESTRING",...}
    if (preg_match_all('/"(\d{2,8})"\s*:\s*\{[^{}]*?"average_time"\s*:\s*"([^"]+)"/s', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $serviceMap[(string)$m[1]] = trim($m[2]);
        }
    }

    if (!empty($serviceMap)) {
        return $serviceMap;
    }

    // Fallback: match "id":"DIGITS" near "average_time":"VALUE" within same object
    if (preg_match_all('/"id"\s*:\s*"(\d+)"(?:(?!"average_time")[^}])*"average_time"\s*:\s*"([^"]+)"/s', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $serviceMap[(string)$m[1]] = trim($m[2]);
        }
    }

    return $serviceMap;
}

/**
 * Log in to JustAnotherPanel and fetch authenticated dashboard payload (1-to-1 from extract_services.py)
 */
function scrapeJapAverageTimes() {
    $username = getenv('JAP_USERNAME') ?: 'Primora';
    $password = getenv('JAP_PASSWORD') ?: 'Abelabate123@#';
    $baseUrl  = 'https://justanotherpanel.com';

    $tempCookieFile = tempnam(sys_get_temp_dir(), 'jap_cookie_');

    try {
        // [1/2] Getting login page + CSRF token...
        $landingHtml = fetchJapPage($baseUrl . '/', null, $tempCookieFile);
        $csrf = null;
        if (preg_match('/"csrftoken"\s*:\s*"([^"]+)"/', $landingHtml, $m)) {
            $csrf = $m[1];
        }

        // [2/2] Logging in and fetching authenticated dashboard...
        $payload = [
            'LoginForm[username]'   => $username,
            'LoginForm[password]'   => $password,
            'LoginForm[rememberMe]' => '0',
        ];
        if ($csrf) {
            $payload['_csrf'] = $csrf;
        }

        $body = fetchJapPage($baseUrl . '/', $payload, $tempCookieFile);

        if (empty($body)) {
            @unlink($tempCookieFile);
            return [];
        }

        // Extract average times from the authenticated body
        $serviceMap = extractAvgTimes($body);

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

/**
 * Trigger async background revalidation non-blockingly
 */
function triggerAsyncRevalidation() {
    $apiUrl = (getenv('SITE_URL') ? 'https://' . getenv('SITE_URL') : 'http://localhost') . '/average-times?refresh=1';
    
    // Quick non-blocking cURL call (timeout 1s)
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1);
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Get Average Times with Stale-While-Revalidate (SWR) logic
 */
function getAverageTimes($forceRefresh = false) {
    $cacheDir = __DIR__ . '/cache';
    if (!file_exists($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    $cacheFile = $cacheDir . '/average_times.json';
    $rootJson  = __DIR__ . '/../services_average_time.json';

    // Seed cache from root services_average_time.json if cacheFile doesn't exist or root is newer
    if (file_exists($rootJson)) {
        if (!file_exists($cacheFile) || filemtime($rootJson) > filemtime($cacheFile)) {
            $rootMap = json_decode(file_get_contents($rootJson), true);
            if (is_array($rootMap)) {
                file_put_contents($cacheFile, json_encode($rootMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
                touch($cacheFile);
            }
        }
    }

    $existingCachedMap = [];
    if (file_exists($cacheFile)) {
        $content = file_get_contents($cacheFile);
        $json = json_decode($content, true);
        if (is_array($json)) {
            $existingCachedMap = $json;
        }
    }

    // Always serve existing cache instantly (0ms) and trigger background revalidation to JAP
    if (!$forceRefresh && file_exists($cacheFile) && !empty($existingCachedMap)) {
        triggerAsyncRevalidation();
        return $existingCachedMap;
    }

    // Synchronous scrape (force refresh or empty cache)
    $freshMap = scrapeJapAverageTimes();

    if (!empty($freshMap)) {
        $changes = [];
        $timestamp = date('c');

        foreach ($freshMap as $id => $newTime) {
            $oldTime = isset($existingCachedMap[$id]) ? $existingCachedMap[$id] : null;
            if ($oldTime === null) {
                $changes[] = [
                    'service_id' => (string)$id,
                    'status'     => 'NEW_SERVICE',
                    'old_time'   => null,
                    'new_time'   => $newTime,
                    'changed_at' => $timestamp
                ];
            } else if ($oldTime !== $newTime) {
                $changes[] = [
                    'service_id' => (string)$id,
                    'status'     => 'TIME_UPDATED',
                    'old_time'   => $oldTime,
                    'new_time'   => $newTime,
                    'changed_at' => $timestamp
                ];
            }
        }

        // Merge fresh data onto existing map to guarantee 0 data loss
        $mergedMap = array_merge($existingCachedMap, $freshMap);

        file_put_contents($cacheFile, json_encode($mergedMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        // Maintain a rolling history of recent changes (top 50)
        $changesFile = $cacheDir . '/recent_changes.json';
        $history = [];
        if (file_exists($changesFile)) {
            $hJson = json_decode(file_get_contents($changesFile), true);
            if (isset($hJson['changes']) && is_array($hJson['changes'])) {
                $history = $hJson['changes'];
            }
        }
        $updatedHistory = !empty($changes) ? array_slice(array_merge($changes, $history), 0, 50) : $history;

        file_put_contents($changesFile, json_encode([
            'last_scrape_at' => $timestamp,
            'total_changed'  => count($updatedHistory),
            'changes'        => $updatedHistory
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $mergedMap;
    }

    // Fallback: If live scrape failed, return existing cached data
    return $existingCachedMap;
}

// CLI Execution Support
if (php_sapi_name() === 'cli' || (isset($argv[1]) && $argv[1] === 'refresh')) {
    $data = getAverageTimes(true);
    echo "Scraped " . count($data) . " average times from JAP.\n";
}

