<?php
/**
 * GodOfPanel Average Time Scraper & Real-Time SWR Module
 * 
 * Extracts 'average_time' for ALL services from GodOfPanel (godofpanel.com).
 * Uses a SINGLE JSON cache file (`cache/godofpanel_average_times.json`) with 'last_updated' at the top.
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

    if (preg_match('/<input[^>]+name=["\']?([^"\'>]*csrf[^"\'>]*)["\']?[^>]+value=["\']([^"\']+)["\']/i', $html, $m)) {
        return [$m[2], $m[1]];
    }
    if (preg_match('/<input[^>]+value=["\']([^"\']+)["\'][^>]+name=["\']?([^"\'>]*csrf[^"\'>]*)["\']/i', $html, $m)) {
        return [$m[1], $m[2]];
    }

    if (preg_match('/<meta[^>]+name=["\']csrf-token["\'][^>]+content=["\']([^"\']+)["\']/i', $html, $m) ||
        preg_match('/<meta[^>]+content=["\']([^"\']+)["\'][^>]+name=["\']csrf-token["\']/i', $html, $m)) {
        return [$m[1], '_csrf'];
    }

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
function scrapeGopAverageTimes($debug = false) {
    $username = getenv('GOP_USERNAME') ?: 'yohannes21';
    $password = getenv('GOP_PASSWORD') ?: 'wNtECw6cQfX3G@H';
    $baseUrl  = 'https://godofpanel.com';

    $tempCookieFile = tempnam(sys_get_temp_dir(), 'gop_cookie_');
    $debugLog = [];

    try {
        // [1/3] Fetching login page + CSRF token...
        $landingHtml = fetchGopPage($baseUrl . '/', null, $tempCookieFile);
        list($csrf, $csrfParam) = findGopCsrf($landingHtml);

        if (!$csrf) {
            $loginHtml = fetchGopPage($baseUrl . '/login', null, $tempCookieFile);
            list($csrf, $csrfParam) = findGopCsrf($loginHtml);
            if ($csrf) $landingHtml = $loginHtml;
        }

        $debugLog['csrf_found'] = !empty($csrf);

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
        $debugLog['post_login_html_len'] = strlen($body);

        $serviceMap = extractGopAvgTimes($body);
        $debugLog['map_after_login_count'] = count($serviceMap);

        // [3/3] Fetch authenticated dashboard or /services page
        $servicesHtml = fetchGopPage($baseUrl . '/services', null, $tempCookieFile);
        $debugLog['services_html_len'] = strlen($servicesHtml);

        $targetIds = ['7821', '2720', '3982', '7820'];
        $snippets = [];
        foreach ($targetIds as $tid) {
            $pos = strpos($servicesHtml, $tid);
            if ($pos !== false) {
                $snippets[$tid] = substr($servicesHtml, max(0, $pos - 150), 600);
            } else {
                $snippets[$tid] = 'NOT_FOUND';
            }
        }
        $debugLog['target_snippets'] = $snippets;

        // Try extracting from /services HTML
        $mapFromServices = extractGopAvgTimes($servicesHtml);
        $debugLog['map_from_services_count'] = count($mapFromServices);

        if (!empty($mapFromServices)) {
            $serviceMap = array_merge($serviceMap, $mapFromServices);
        }

        @unlink($tempCookieFile);

        if ($debug) {
            return [
                'debug_log' => $debugLog,
                'extracted_targets' => [
                    '7821' => $serviceMap['7821'] ?? 'NOT_IN_MAP',
                    '2720' => $serviceMap['2720'] ?? 'NOT_IN_MAP',
                    '3982' => $serviceMap['3982'] ?? 'NOT_IN_MAP',
                    '7820' => $serviceMap['7820'] ?? 'NOT_IN_MAP',
                ],
                'sample_map' => array_slice($serviceMap, 0, 10, true)
            ];
        }

        return $serviceMap;

    } catch (Exception $e) {
        error_log("[GodOfPanel Scraper] Exception: " . $e->getMessage());
        if (file_exists($tempCookieFile)) {
            @unlink($tempCookieFile);
        }
        if ($debug) return ['error' => $e->getMessage(), 'debug_log' => $debugLog];
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
 * Get GodOfPanel Average Times using a SINGLE JSON cache file.
 * Formatted with 'last_updated' at top.
 */
function getGodofpanelAverageTimes($forceRefresh = false) {
    if (isset($_GET['debug']) && $_GET['debug'] === '1') {
        return scrapeGopAverageTimes(true);
    }

    $cacheDir = __DIR__ . '/cache';
    if (!file_exists($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    $cacheFile = $cacheDir . '/godofpanel_average_times.json';

    $existingCachePayload = null;
    $existingDataMap = [];

    if (file_exists($cacheFile)) {
        $content = file_get_contents($cacheFile);
        $json = json_decode($content, true);
        if (is_array($json)) {
            $existingCachePayload = $json;
            if (isset($json['data']) && is_array($json['data'])) {
                $existingDataMap = $json['data'];
            } else {
                // Legacy flat format fallback
                $existingDataMap = $json;
            }
        }
    }

    // Serve existing cache instantly (0ms) and trigger background revalidation
    if (!$forceRefresh && !empty($existingCachePayload)) {
        triggerGopAsyncRevalidation();
        // Return existing JSON payload directly (ensuring last_updated is at top)
        if (isset($existingCachePayload['last_updated'])) {
            return $existingCachePayload;
        }
        return [
            'last_updated'   => date('c', filemtime($cacheFile)),
            'total_services' => count($existingDataMap),
            'provider'       => 'godofpanel.com',
            'data'           => $existingDataMap
        ];
    }

    // Synchronous scrape (force refresh or empty cache)
    $freshMap = scrapeGopAverageTimes();

    if (!empty($freshMap)) {
        $mergedMap = array_merge($existingDataMap, $freshMap);
        
        // Build single JSON structure with last_updated at top
        $newPayload = [
            'last_updated'   => date('c'),
            'total_services' => count($mergedMap),
            'provider'       => 'godofpanel.com',
            'data'           => $mergedMap
        ];

        // Replace current JSON file directly
        file_put_contents($cacheFile, json_encode($newPayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        return $newPayload;
    }

    // Fallback: If live scrape failed, return existing cached payload
    if (!empty($existingCachePayload)) {
        if (isset($existingCachePayload['last_updated'])) {
            return $existingCachePayload;
        }
        return [
            'last_updated'   => date('c', file_exists($cacheFile) ? filemtime($cacheFile) : time()),
            'total_services' => count($existingDataMap),
            'provider'       => 'godofpanel.com',
            'data'           => $existingDataMap
        ];
    }

    return [
        'last_updated'   => date('c'),
        'total_services' => 0,
        'provider'       => 'godofpanel.com',
        'data'           => []
    ];
}

// CLI Execution Support
if (php_sapi_name() === 'cli' || (isset($argv[1]) && $argv[1] === 'refresh')) {
    $res = getGodofpanelAverageTimes(true);
    echo "Scraped & Replaced JSON with " . ($res['total_services'] ?? 0) . " services at " . ($res['last_updated'] ?? '') . "\n";
}

