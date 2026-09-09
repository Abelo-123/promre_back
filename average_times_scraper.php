<?php
/**
 * JustAnotherPanel Average Time Scraper & Real-Time SWR Module
 * 
 * Independently fetches, caches, and serves live service average_time metrics
 * from JustAnotherPanel (JAP.com) with key-preserving multi-strategy parsing and non-blocking SWR background sync.
 */

require_once __DIR__ . '/config.php';

/**
 * Key-preserving associative merge helper for service maps.
 * Guarantees numeric service IDs (e.g. "8651") are preserved without PHP array_merge key reindexing.
 */
function mergeServiceMaps(...$maps) {
    $result = [];
    foreach ($maps as $map) {
        if (is_array($map)) {
            foreach ($map as $serviceId => $avgTime) {
                if ($serviceId !== null && $avgTime !== null && $avgTime !== '') {
                    $key = (string)$serviceId;
                    $val = trim((string)$avgTime);
                    if ($val !== '' && $val !== 'Not specified' && $val !== 'N/A') {
                        $result[$key] = $val;
                    }
                }
            }
        }
    }
    return $result;
}

function fetchJapPage($url, $postData = null, $cookieJar = null) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    
    $headers = [
        'User-Agent: Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36',
        'Accept: text/html,application/xhtml+xml,application/json,*/*;q=0.8',
        'Accept-Language: en-US,en;q=0.9',
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
 * Extract { service_id => average_time } map from HTML/JS payload using multiple resilient strategies
 */
function parseAverageTimesFromContent($htmlContent) {
    $serviceMap = [];
    if (empty($htmlContent)) return $serviceMap;

    // Strategy 1: Nested-brace tolerant JSON object regex
    // Matches "8651": { ... "average_time": "5 hours 14 minutes" ... } handling up to 2 levels of sub-objects
    if (preg_match_all('/"(\d{2,8})"\s*:\s*\{(?:[^{}]|\{[^{}]*\}|\{[^{}]*\{[^{}]*\}*\})*?"average_time"\s*:\s*"([^"]+)"/s', $htmlContent, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $serviceMap[(string)$m[1]] = trim($m[2]);
        }
    }

    // Strategy 2: Proximity backward-search parsing
    // Finds all "average_time": "VALUE" and looks backwards for the nearest preceding service ID
    if (preg_match_all('/"average_time"\s*:\s*"([^"]+)"/s', $htmlContent, $timeMatches, PREG_OFFSET_CAPTURE)) {
        foreach ($timeMatches as $tm) {
            $timeVal = trim($tm[0][0]);
            if (preg_match('/"average_time"\s*:\s*"([^"]+)"/', $timeVal, $valMatch)) {
                $timeVal = trim($valMatch[1]);
            }
            $offset  = $tm[0][1];
            $chunkStart = max(0, $offset - 1200);
            $chunk = substr($htmlContent, $chunkStart, $offset - $chunkStart);

            // Search backward for service ID patterns
            if (preg_match_all('/(?:"id"\s*:\s*"?(\d{2,8})"?|"service"\s*:\s*"?(\d{2,8})"?|"(\d{2,8})"\s*:\s*\{|data-id="(\d{2,8})"|service-(\d{2,8}))/s', $chunk, $idMatches, PREG_SET_ORDER)) {
                $lastMatch = end($idMatches);
                $id = !empty($lastMatch[1]) ? $lastMatch[1] :
                     (!empty($lastMatch[2]) ? $lastMatch[2] :
                     (!empty($lastMatch[3]) ? $lastMatch[3] :
                     (!empty($lastMatch[4]) ? $lastMatch[4] :
                     (!empty($lastMatch[5]) ? $lastMatch[5] : null))));
                if ($id && !isset($serviceMap[(string)$id])) {
                    $serviceMap[(string)$id] = $timeVal;
                }
            }
        }
    }

    // Strategy 3: HTML Table & Data Attributes Parser
    if (preg_match_all('/<tr[^>]*?(?:data-id="(\d+)"|id="service-(\d+)")[^>]*?>.*?average_time[^>]*?>([^<]+)</si', $htmlContent, $trMatches, PREG_SET_ORDER)) {
        foreach ($trMatches as $m) {
            $id = !empty($m[1]) ? $m[1] : $m[2];
            $val = trim($m[3]);
            if ($id && $val && !isset($serviceMap[(string)$id])) {
                $serviceMap[(string)$id] = $val;
            }
        }
    }

    return $serviceMap;
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

        $authHtml     = fetchJapPage($baseUrl . '/', $payload, $tempCookieFile);
        $newOrderHtml = fetchJapPage($baseUrl . '/neworder', null, $tempCookieFile);
        $servicesHtml = fetchJapPage($baseUrl . '/services', null, $tempCookieFile);

        // Step 3: Extract maps from all targets
        $mapLanding  = parseAverageTimesFromContent($landingHtml);
        $mapServices = parseAverageTimesFromContent($servicesHtml);
        $mapAuth     = parseAverageTimesFromContent($authHtml);
        $mapNewOrder = parseAverageTimesFromContent($newOrderHtml);

        // Step 4: Merge using key-preserving helper (authenticated targets have highest precedence)
        $mergedMap = mergeServiceMaps($mapLanding, $mapServices, $mapAuth, $mapNewOrder);

        @unlink($tempCookieFile);
        return $mergedMap;

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
 * Get Average Times with Stale-While-Revalidate (SWR) logic & key preservation
 */
function getAverageTimes($forceRefresh = false) {
    $cacheDir = __DIR__ . '/cache';
    if (!file_exists($cacheDir)) {
        @mkdir($cacheDir, 0755, true);
    }

    $cacheFile = $cacheDir . '/average_times.json';
    $swrTtl = (int)(getenv('JAP_SWR_TTL') ?: 30); // 30 seconds SWR stale window

    $existingCachedMap = [];
    if (file_exists($cacheFile)) {
        $content = file_get_contents($cacheFile);
        $json = json_decode($content, true);
        if (is_array($json)) {
            $existingCachedMap = $json;
        }
    }

    // Fast Path: Return cached data immediately if fresh (< 30s) and not forced
    if (!$forceRefresh && file_exists($cacheFile)) {
        $mtime = filemtime($cacheFile);
        $age = time() - $mtime;

        if ($age < $swrTtl) {
            return $existingCachedMap;
        }

        // SWR Revalidation: Serve existing cache instantly while triggering background refresh if age > 30s
        if (!empty($existingCachedMap)) {
            triggerAsyncRevalidation();
            return $existingCachedMap;
        }
    }

    // Synchronous scrape (force refresh or empty cache)
    $freshMap = scrapeJapAverageTimes();

    if (!empty($freshMap)) {
        // Key-preserving incremental merge
        $finalMap = mergeServiceMaps($existingCachedMap, $freshMap);
        file_put_contents($cacheFile, json_encode($finalMap, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $finalMap;
    }

    // Fallback: If live scrape failed, return existing cached data
    return $existingCachedMap;
}

// CLI Execution Support
if (php_sapi_name() === 'cli' || (isset($argv[1]) && $argv[1] === 'refresh')) {
    $data = getAverageTimes(true);
    echo "Scraped " . count($data) . " average times from JAP.\n";
}
