<?php
/**
 * GodOfPanel Average Time Scraper & Real-Time SWR Module
 * 
 * Extracts 'average_time' for ALL services from GodOfPanel (godofpanel.com).
 * Uses credentials from GOP_USERNAME & GOP_PASSWORD environment variables.
 */

require_once __DIR__ . '/config.php';

function fetchGopPage($url, $postData = null, $cookieJar = null) {
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
        $headers[] = 'Origin: https://godofpanel.com';
        $headers[] = 'Referer: https://godofpanel.com/';
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        error_log("[GodOfPanel Scraper] cURL error fetching {$url}: " . $error);
        return '';
    }

    return $response;
}

/**
 * Extract CSRF token from page HTML
 */
function findGopCsrf($html) {
    if (empty($html)) return [null, '_csrf'];

    // Pattern 1: <input ... name="_csrf..." value="...">
    if (preg_match('/<input[^>]+name=["\']?([^"\'>]*csrf[^"\'>]*)["\']?[^>]+value=["\']([^"\']+)["\']/i', $html, $m)) {
        return [$m[2], $m[1]];
    }
    if (preg_match('/<input[^>]+value=["\']([^"\']+)["\'][^>]+name=["\']?([^"\'>]*csrf[^"\'>]*)["\']/i', $html, $m)) {
        return [$m[1], $m[2]];
    }

    // Pattern 2: Meta tags
    if (preg_match('/<meta[^>]+name=["\']csrf-token["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) ||
        preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']csrf-token["\']/i', $html, $m)) {
        return [$m[1], '_csrf'];
    }

    // Pattern 3: JS objects
    if (preg_match('/["\']?_?csrf[-_]?token["\']?\s*[:=]\s*["\']([^"\']+)["\']/i', $html, $m) ||
        preg_match('/["\']?_csrf["\']?\s*[:=]\s*["\']([^"\']+)["\']/i', $html, $m)) {
        return [$m[1], '_csrf'];
    }

    return [null, '_csrf'];
}

/**
 * Extract { service_id => average_time } from authenticated page HTML
 */
function extractGopAvgTimes($html) {
    $serviceMap = [];
    if (empty($html)) return $serviceMap;

    // Strategy 1: "DIGITS":{"id":"DIGITS",...,"average_time":"TIMESTRING",...}
    if (preg_match_all('/"(\d{1,8})"\s*:\s*\{[^{}]*?"average_time"\s*:\s*"([^"]+)"/s', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $serviceMap[(string)$m[1]] = trim($m[2]);
        }
    }

    if (!empty($serviceMap)) {
        return $serviceMap;
    }

    // Strategy 2: "id":"DIGITS" near "average_time":"VALUE" within same object
    if (preg_match_all('/"id"\s*:\s*"(\d+)"(?:(?!"average_time")[^}])*"average_time"\s*:\s*"([^"]+)"/s', $html, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $serviceMap[(string)$m[1]] = trim($m[2]);
        }
    }

    if (!empty($serviceMap)) {
        return $serviceMap;
    }

    // Strategy 3: Backwards context search for closest preceding ID
    if (preg_match_all('/"average_time"\s*:\s*"([^"]+)"/s', $html, $matches, PREG_OFFSET_CAPTURE)) {
        foreach ($matches as $m) {
            $avgTime = trim($m[1][0]);
            $offset = $m[0][1];
            $startPos = max(0, $offset - 3000);
            $snippet = substr($html, $startPos, 3000);

            if (preg_match_all('/"(?:id|service)"\s*:\s*"?"?(\d+)["\']?/i', $snippet, $idMatches) ||
                preg_match_all('/"(\d{1,8})"\s*:\s*\{/', $snippet, $idMatches)) {
                $lastId = end($idMatches[1]);
                if ($lastId) {
                    $serviceMap[(string)$lastId] = $avgTime;
                }
            }
        }
    }

    return $serviceMap;
}

/**
 * Log in to GodOfPanel and fetch authenticated dashboard payload
 */
function scrapeGopAverageTimes() {
    $username = getenv('GOP_USERNAME') ?: 'yohannes21';
    $password = getenv('GOP_PASSWORD') ?: 'wNtECw6cQfX3G@H';
    $baseUrl  = 'https://godofpanel.com';

    $tempCookieFile = tempnam(sys_get_temp_dir(), 'gop_cookie_');

    try {
        // [1/3] Fetching login page + CSRF token...
        $landingHtml = fetchGopPage($baseUrl . '/', null, $tempCookieFile);
        list($csrf, $csrfParam) = findGopCsrf($landingHtml);

        if (!$csrf) {
            $loginHtml = fetchGopPage($baseUrl . '/login', null, $tempCookieFile);
            list($csrf, $csrfParam) = findGopCsrf($loginHtml);
            if ($csrf) $landingHtml = $loginHtml;
        }

        // [2/3] Logging in as GOP_USERNAME...
        $payload = [
            'LoginForm[username]'   => $username,
            'LoginForm[password]'   => $password,
            'LoginForm[rememberMe]' => '1',
        ];
        if ($csrf) {
            $payload[$csrfParam] = $csrf;
        }

        $body = fetchGopPage($baseUrl . '/', $payload, $tempCookieFile);
        if (empty($body)) {
            @unlink($tempCookieFile);
            return [];
        }

        $serviceMap = extractGopAvgTimes($body);

        // [3/3] If not found in POST body, fetch authenticated dashboard or /services page
        if (empty($serviceMap)) {
            $body = fetchGopPage($baseUrl . '/', null, $tempCookieFile);
            $serviceMap = extractGopAvgTimes($body);
        }

        if (empty($serviceMap)) {
            $body = fetchGopPage($baseUrl . '/services', null, $tempCookieFile);
            $serviceMap = extractGopAvgTimes($body);
        }

        @unlink($tempCookieFile);
        return $serviceMap;

    } catch (Exception $e) {
        error_log("[GodOfPanel Scraper] Exception: " . $e->getMessage());
        if (file_exists($tempCookieFile)) {
            @unlink($tempCookieFile);
        }
        return [];
    }
}

/**
 * Trigger async background revalidation non-blockingly
 */
function triggerGopAsyncRevalidation() {
    $apiUrl = (getenv('SITE_URL') ? 'https://' . getenv('SITE_URL') : 'http://localhost') . '/godofpanel-average-times?refresh=1';
    
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $apiUrl);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 1);
    curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
    curl_exec($ch);
    curl_close($ch);
}

/**
 * Get GodOfPanel Average Times with Stale-While-Revalidate (SWR) logic
 */
function getGodofpanelAverageTimes($forceRefresh = false) {
    $cacheDir = __DIR__ . '/cache';
    if (!file_exists($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    $cacheFile = $cacheDir . '/godofpanel_average_times.json';

    $existingCachedMap = [];

    if (file_exists($cacheFile)) {
        $content = file_get_contents($cacheFile);
        $json = json_decode($content, true);
        if (is_array($json)) {
            $existingCachedMap = $json;
        }
    }

    // Serve existing cache instantly (0ms) and trigger background revalidation
    if (!$forceRefresh && file_exists($cacheFile) && !empty($existingCachedMap)) {
        triggerGopAsyncRevalidation();
        return $existingCachedMap;
    }

    // Synchronous scrape (force refresh or empty cache)
    $freshMap = scrapeGopAverageTimes();

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
        $changesFile = $cacheDir . '/gop_recent_changes.json';
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
    $data = getGodofpanelAverageTimes(true);
    echo "Scraped " . count($data) . " average times from GodOfPanel.\n";
}
